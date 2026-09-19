<?php

declare(strict_types=1);

namespace app\iot\service;

use RuntimeException;

/** PostgreSQL归档端的不可变WAL与摘要；原生维护入口不加载业务配置或连接数据库。 */
final class RecoveryArchive
{
    private string $directory;
    private array $verifiedTools = [];
    private int $backupDeadline = 0;

    /** 目录由维护者预先建立并限制访问；摘要证明字节完整性，不替代独立备份域或来源信任。 */
    public function __construct(string $directory)
    {
        if (!in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true)) {
            throw new RuntimeException('recovery_archive_platform_unsupported');
        }
        $resolved = realpath($directory);
        if ($resolved === false || !is_dir($resolved) || is_link($directory) || (fileperms($resolved) & 0077) !== 0) {
            throw new RuntimeException('recovery_archive_directory_invalid');
        }
        $this->directory = $resolved;
    }

    /**
     * 只有原件、SHA256及目录项同步落盘后返回；重试只接受字节相同的原件，永不覆盖不同WAL。
     * @return array{name: string, bytes: int, sha256: string}
     * @throws RuntimeException 名称、文件类型、额度、冲突、锁或落盘失败；PostgreSQL须保留源WAL重试。
     */
    public function archive(string $source, string $name): array
    {
        self::name($name);
        $target = $this->directory . '/' . $name;
        $lock = $this->lock();
        try {
            $record = $this->copy($source, $target, true);
            $checksum = $target . '.sha256';
            $text = $record['sha256'] . ' ' . $record['bytes'] . "\n";
            if (file_exists($checksum) || is_link($checksum)) {
                self::regular($checksum);
                if (filesize($checksum) > 96 || file_get_contents($checksum) !== $text) {
                    throw new RuntimeException('recovery_archive_checksum_conflict');
                }
            } else {
                $temporaryChecksum = $this->directory . '/.checksum-' . bin2hex(random_bytes(16));
                $stream = @fopen($temporaryChecksum, 'x+b');
                if ($stream === false) {
                    throw new RuntimeException('recovery_archive_checksum_create_failed');
                }
                try {
                    if (!chmod($temporaryChecksum, 0600) || fwrite($stream, $text) !== strlen($text) || !fflush($stream) || !fsync($stream)
                        || !link($temporaryChecksum, $checksum)) {
                        throw new RuntimeException('recovery_archive_checksum_sync_failed');
                    }
                } finally {
                    fclose($stream);
                    unlink($temporaryChecksum);
                }
            }
            self::syncDirectory($this->directory);
            return ['name' => $name] + $record;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * restore_command使用的新临时目标；先核对完整归档摘要，只有成功退出才允许PostgreSQL重放。
     * @return array{name: string, bytes: int, sha256: string}
     * @throws RuntimeException 归档缺失/损坏、目标存在或复制落盘失败。
     */
    public function restore(string $name, string $target): array
    {
        self::name($name);
        $source = $this->directory . '/' . $name;
        $lock = $this->lock();
        try {
            $record = $this->verify($name);
            if (file_exists($target) || is_link($target)) {
                throw new RuntimeException('recovery_restore_target_exists');
            }
            $copied = $this->copy($source, $target, false, $record);
            return ['name' => $name] + $copied;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{bytes: int, sha256: string} 校验单个不可变归档，不将文件存在当作可恢复证明。 */
    public function verify(string $name): array
    {
        self::name($name);
        $source = $this->directory . '/' . $name;
        $checksum = $source . '.sha256';
        self::regular($source);
        self::regular($checksum);
        if (filesize($checksum) > 96 || preg_match('/^([a-f0-9]{64}) ([1-9][0-9]{0,9})\n$/D', (string) file_get_contents($checksum), $matches) !== 1) {
            throw new RuntimeException('recovery_archive_checksum_invalid');
        }
        $bytes = (int) $matches[2];
        if ($bytes > 1073741824 || filesize($source) !== $bytes || hash_file('sha256', $source) !== $matches[1]) {
            throw new RuntimeException('recovery_archive_corrupt');
        }
        return ['bytes' => $bytes, 'sha256' => $matches[1]];
    }

    /**
     * 接管incoming/<id>中已完成的plain备份；官方校验通过后登记并原子移入backups。
     * 增量源备份在生成期间须先pin；登记时间是备份完成的保守上界，重试不延长保留期。
     * @return array<string,mixed> 不包含机器路径的备份身份与已确认状态。
     */
    public function registerBackup(string $id, string $tools): array
    {
        self::backupId($id);
        $this->backupDeadline = (int) hrtime(true) + 600000000000;
        $lock = $this->lock('.backup.lock');
        try {
            $catalog = $this->catalog();
            $this->backupDirectories();
            $existing = $catalog['backups'][$id] ?? null;
            if (is_array($existing) && $existing['state'] === 'retiring') {
                throw new RuntimeException('recovery_backup_retiring');
            }
            $staged = $this->directory . '/incoming/' . $id;
            $stored = $this->directory . '/backups/' . $id;
            $source = is_dir($stored) ? $stored : $staged;
            if ((is_dir($stored) && is_dir($staged)) || (!is_array($existing) && is_dir($stored))) {
                throw new RuntimeException('recovery_backup_target_conflict');
            }
            $record = $this->inspectBackup($source, $tools, true);
            if ($catalog['system_id'] !== '' && ($record['system_id'] !== $catalog['system_id'] || $record['segment_bytes'] !== $catalog['segment_bytes'])) {
                throw new RuntimeException('recovery_backup_system_conflict');
            }
            if ($catalog['wal_floor'] !== '' && strcmp($record['wal_floor'], $catalog['wal_floor']) < 0) {
                throw new RuntimeException('recovery_backup_before_retained_wal');
            }
            $parent = '';
            if ($record['previous_timeline'] !== 0) {
                foreach ($catalog['backups'] as $candidateId => $candidate) {
                    if ($candidate['state'] === 'active' && $candidate['start_timeline'] === $record['previous_timeline']
                        && $candidate['start_lsn'] === $record['previous_lsn']) {
                        if ($parent !== '') {
                            throw new RuntimeException('recovery_backup_parent_ambiguous');
                        }
                        $parent = (string) $candidateId;
                    }
                }
                if ($parent === '') {
                    throw new RuntimeException('recovery_backup_parent_missing');
                }
            }
            $record['parent'] = $parent;
            if (is_array($existing)) {
                foreach ($record as $field => $value) {
                    if (!array_key_exists($field, $existing) || $existing[$field] !== $value) {
                        throw new RuntimeException('recovery_backup_content_conflict');
                    }
                }
                $record = $existing;
            } else {
                if (count($catalog['backups']) >= 4096) {
                    throw new RuntimeException('recovery_backup_catalog_limit');
                }
                $record += ['registered_at' => time(), 'state' => 'registering'];
            }
            $chain = $parent === '' ? [] : $this->chain($catalog['backups'], $parent);
            foreach ($chain as $ancestor) {
                $this->verifyBackupRecord($ancestor, $catalog['backups'][$ancestor], $tools);
            }
            $paths = [];
            foreach ($chain as $ancestor) {
                $paths[] = $this->directory . '/backups/' . $ancestor;
            }
            $paths[] = $source;
            $this->postgres($tools, 'pg_combinebackup', ['--dry-run', '--output=' . $this->directory . '/.combine-check', ...$paths]);
            if ($record['state'] === 'active') {
                return ['id' => $id] + $record;
            }
            $catalog['system_id'] = $record['system_id'];
            $catalog['segment_bytes'] = $record['segment_bytes'];
            $catalog['backups'][$id] = $record;
            $this->saveCatalog($catalog);
            if ($source === $staged) {
                if (!rename($staged, $stored)) {
                    throw new RuntimeException('recovery_backup_publish_failed');
                }
                self::syncDirectory($this->directory . '/incoming');
                self::syncDirectory($this->directory . '/backups');
            }
            $record['state'] = 'active';
            $catalog['backups'][$id] = $record;
            $this->saveCatalog($catalog);
            return ['id' => $id] + $record;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** 持久pin覆盖备份及父链，直到明确释放；进程崩溃不自动取消正在进行的备份或恢复保护。 */
    public function pinBackup(string $id, string $pin, bool $release = false): array
    {
        self::backupId($id);
        self::backupId($pin);
        $lock = $this->lock('.backup.lock');
        try {
            $catalog = $this->catalog();
            if ($release) {
                if (isset($catalog['pins'][$pin]) && $catalog['pins'][$pin] !== $id) {
                    throw new RuntimeException('recovery_backup_pin_conflict');
                }
                $pins = $catalog['pins'];
                unset($pins[$pin]);
                $catalog['pins'] = $pins;
            } else {
                $this->chain($catalog['backups'], $id);
                if ((isset($catalog['pins'][$pin]) && $catalog['pins'][$pin] !== $id) || (!isset($catalog['pins'][$pin]) && count($catalog['pins']) >= 4096)) {
                    throw new RuntimeException('recovery_backup_pin_conflict');
                }
                $catalog['pins'][$pin] = $id;
            }
            $this->saveCatalog($catalog);
            return ['id' => $id, 'pin' => $pin, 'pinned' => !$release];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** 返回持久登记/退役进度；不把登记数量或模拟日期当作真实七天窗口证明。 */
    public function backupStatus(): array
    {
        $lock = $this->lock('.backup.lock');
        try {
            return $this->catalog();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * 七天窗口保留最近边界锚点、窗口内备份、pin与全部增量父链；每批最多100个文件/归档对象。
     * 新退役意图必须通过官方全链及连续归档校验；已持久提交的退役意图在崩溃后继续，不重新启用旧备份。
     * @return array<string,mixed> 删除进度及精确WAL校验边界；不证明独立故障域或当前RPO。
     */
    public function cleanBackups(string $tools, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new RuntimeException('recovery_backup_batch_invalid');
        }
        $this->backupDeadline = (int) hrtime(true) + 600000000000;
        $lock = $this->lock('.backup.lock');
        try {
            $catalog = $this->catalog();
            $this->backupDirectories();
            $pending = $catalog['wal_retiring'] !== [];
            $registeredTimeline = 0;
            foreach ($catalog['backups'] as $record) {
                if ($record['state'] === 'registering') {
                    throw new RuntimeException('recovery_backup_registration_pending');
                }
                $pending = $pending || $record['state'] === 'retiring';
                if ($record['state'] === 'active') {
                    foreach ($record['ranges'] as $timeline => $range) {
                        $registeredTimeline = max($registeredTimeline, (int) $timeline);
                    }
                }
            }
            // 新timeline登记后重新核对仍保留的链，已开始退役的旧项绝不恢复为active。
            $planned = !$pending || $registeredTimeline > ($catalog['last_plan']['validated_timeline'] ?? 0);
            if ($planned) {
                $catalog = $this->retentionPlan($catalog, $tools);
            }
            $archiveLock = $this->lock();
            try {
                // 与归档发布共用锁：复查之后到本批删除结束不能再插入未知timeline。
                foreach ($this->walNames() as $name) {
                    if ((int) hexdec(substr($name, 0, 8)) > $catalog['last_plan']['validated_timeline']) {
                        throw new RuntimeException('recovery_backup_unregistered_timeline');
                    }
                }
                if ($planned) {
                    $this->saveCatalog($catalog);
                }
                return $this->sweepBackups($catalog, $limit);
            } finally {
                flock($archiveLock, LOCK_UN);
                fclose($archiveLock);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function retentionPlan(array $catalog, string $tools): array
    {
        $now = time();
        $cutoff = $now - 604800;
        $latestTimeline = 0;
        foreach ($catalog['backups'] as $id => $record) {
            if ($record['state'] === 'retiring') {
                continue;
            }
            if ($record['registered_at'] > $now) {
                throw new RuntimeException('recovery_backup_clock_reversed');
            }
            $this->verifyBackupRecord((string) $id, $record, $tools);
            $paths = [];
            foreach ($this->chain($catalog['backups'], (string) $id) as $ancestor) {
                $paths[] = $this->directory . '/backups/' . $ancestor;
            }
            $this->postgres($tools, 'pg_combinebackup', ['--dry-run', '--output=' . $this->directory . '/.combine-check', ...$paths]);
            foreach ($record['ranges'] as $timeline => $range) {
                $latestTimeline = max($latestTimeline, (int) $timeline);
            }
        }
        if ($latestTimeline === 0) {
            throw new RuntimeException('recovery_backup_window_unavailable');
        }
        $archiveLock = $this->lock();
        try {
            $lineage = $this->timeline($latestTimeline);
            $names = $this->walNames();
            $latestWal = '';
            foreach ($names as $name) {
                $timeline = (int) hexdec(substr($name, 0, 8));
                if ($timeline > $latestTimeline) {
                    throw new RuntimeException('recovery_backup_unregistered_timeline');
                }
                if (preg_match('/^[A-F0-9]{24}$/D', $name) === 1) {
                    if ($timeline === $latestTimeline && strcmp($name, $latestWal) > 0) {
                        $latestWal = $name;
                    }
                }
            }
        } finally {
            flock($archiveLock, LOCK_UN);
            fclose($archiveLock);
        }
        if ($latestWal === '') {
            throw new RuntimeException('recovery_backup_wal_unavailable');
        }
        $anchor = '';
        $anchorTime = 0;
        $anchorLsn = '';
        foreach ($catalog['backups'] as $id => $record) {
            if ($record['state'] === 'retiring') {
                continue;
            }
            $endTimeline = 0;
            $endLsn = '';
            foreach ($record['ranges'] as $timeline => $range) {
                if ((int) $timeline > $endTimeline) {
                    $endTimeline = (int) $timeline;
                    $endLsn = $range['end'];
                }
            }
            if ($record['registered_at'] <= $cutoff && array_key_exists((string) $endTimeline, $lineage)
                && ($lineage[(string) $endTimeline] === '' || strcmp($endLsn, $lineage[(string) $endTimeline]) <= 0)
                && ($record['registered_at'] > $anchorTime || ($record['registered_at'] === $anchorTime && strcmp($endLsn, $anchorLsn) > 0))) {
                $anchor = (string) $id;
                $anchorTime = $record['registered_at'];
                $anchorLsn = $endLsn;
            }
        }
        if ($anchor === '') {
            throw new RuntimeException('recovery_backup_window_unavailable');
        }
        $keep = [];
        $roots = [$anchor];
        foreach ($catalog['backups'] as $id => $record) {
            if ($record['state'] === 'active' && $record['registered_at'] >= $cutoff) {
                $roots[] = (string) $id;
            }
        }
        foreach ($catalog['pins'] as $id) {
            $roots[] = $id;
        }
        foreach ($roots as $id) {
            foreach ($this->chain($catalog['backups'], $id) as $ancestor) {
                $keep[$ancestor] = true;
            }
        }
        $floor = 'FFFFFFFFFFFFFFFF';
        foreach ($keep as $id => $unused) {
            $record = $catalog['backups'][$id];
            $this->verifyRanges($record['ranges'], $this->directory, $tools);
            if (strcmp($record['wal_floor'], $floor) < 0) {
                $floor = $record['wal_floor'];
            }
        }
        if ($catalog['wal_floor'] !== '' && strcmp($floor, $catalog['wal_floor']) < 0) {
            throw new RuntimeException('recovery_backup_floor_reversed');
        }
        foreach ($names as $name) {
            if (hrtime(true) >= $this->backupDeadline) {
                throw new RuntimeException('recovery_backup_deadline');
            }
            if (preg_match('/^[A-F0-9]{24}$/D', $name) === 1 && strcmp(substr($name, 8, 16), $floor) >= 0) {
                $verified = $this->verify($name);
                if ($verified['bytes'] !== $catalog['segment_bytes']) {
                    throw new RuntimeException('recovery_backup_wal_invalid');
                }
            }
        }
        // 从锚点最早full的真实起点沿history切点验证至当前已归档完整段末端；不把文件名连续当作WAL有效。
        $anchorChain = $this->chain($catalog['backups'], $anchor);
        $first = $catalog['backups'][$anchorChain[0]];
        $cursor = $first['start_lsn'];
        $startTimeline = $first['start_timeline'];
        if (!array_key_exists((string) $startTimeline, $lineage)) {
            throw new RuntimeException('recovery_backup_timeline_invalid');
        }
        $lastLog = (int) hexdec(substr($latestWal, 8, 8));
        $lastSegment = (int) hexdec(substr($latestWal, 16, 8));
        $segmentsPerLog = intdiv(4294967296, $catalog['segment_bytes']);
        if ($lastSegment >= $segmentsPerLog) {
            throw new RuntimeException('recovery_backup_wal_invalid');
        }
        $endLog = $lastLog + intdiv($lastSegment + 1, $segmentsPerLog);
        if ($endLog > 4294967295) {
            throw new RuntimeException('recovery_backup_wal_invalid');
        }
        $end = sprintf('%08X%08X', $endLog, (($lastSegment + 1) % $segmentsPerLog) * $catalog['segment_bytes']);
        foreach ($lineage as $timeline => $switch) {
            if ((int) $timeline < $startTimeline) {
                continue;
            }
            $stop = $switch === '' ? $end : $switch;
            if (strcmp($cursor, $stop) > 0) {
                throw new RuntimeException('recovery_backup_timeline_invalid');
            }
            if ($cursor !== $stop) {
                $this->postgres($tools, 'pg_waldump', ['--quiet', '--path=' . $this->directory, '--timeline=' . $timeline,
                    '--start=' . substr($cursor, 0, 8) . '/' . substr($cursor, 8, 8), '--end=' . substr($stop, 0, 8) . '/' . substr($stop, 8, 8)]);
            }
            $cursor = $stop;
        }
        foreach ($catalog['backups'] as $id => $record) {
            if (!isset($keep[$id])) {
                $catalog['backups'][$id]['state'] = 'retiring';
            }
        }
        $archiveLock = $this->lock();
        try {
            foreach ($names as $name) {
                if (preg_match('/^[A-F0-9]{24}(?:\.[A-F0-9]{8}\.backup|\.partial)?$/D', $name) === 1
                    && strcmp(substr($name, 8, 16), $floor) < 0 && count($catalog['wal_retiring']) < 100) {
                    $catalog['wal_retiring'][$name] = $this->verify($name);
                }
            }
        } finally {
            flock($archiveLock, LOCK_UN);
            fclose($archiveLock);
        }
        $catalog['wal_floor'] = $floor;
        $catalog['last_plan'] = ['planned_at' => $now, 'window_start' => $cutoff, 'anchor' => $anchor, 'anchor_registered_at' => $anchorTime,
            'kept_backups' => array_keys($keep), 'wal_floor' => $floor, 'validated_timeline' => $latestTimeline, 'validated_through_lsn' => $end, 'validated_through_wal' => $latestWal];
        return $catalog;
    }

    /** @return array<string,string> timeline=>切换LSN，当前timeline末端为空；history本身永久保留。 */
    private function timeline(int $latest): array
    {
        $lineage = [];
        if ($latest > 1) {
            $name = sprintf('%08X.history', $latest);
            $record = $this->verify($name);
            if ($record['bytes'] > 1048576) {
                throw new RuntimeException('recovery_backup_timeline_invalid');
            }
            $text = (string) file_get_contents($this->directory . '/' . $name);
            $previousTimeline = 0;
            $previousLsn = '';
            foreach (explode("\n", $text) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (preg_match('/^([1-9][0-9]{0,9})\s+([A-F0-9]{1,8}\/[A-F0-9]{1,8})\s+.+$/D', $line, $parts) !== 1) {
                    throw new RuntimeException('recovery_backup_timeline_invalid');
                }
                $timeline = (int) $parts[1];
                $lsn = self::lsn($parts[2]);
                if ($timeline <= $previousTimeline || $timeline >= $latest || strcmp($lsn, $previousLsn) <= 0 || count($lineage) >= 128) {
                    throw new RuntimeException('recovery_backup_timeline_invalid');
                }
                $lineage[(string) $timeline] = $lsn;
                $previousTimeline = $timeline;
                $previousLsn = $lsn;
            }
            if ($lineage === []) {
                throw new RuntimeException('recovery_backup_timeline_invalid');
            }
        }
        $lineage[(string) $latest] = '';
        return $lineage;
    }

    /** @return list<string> 标准WAL与history供timeline复查；删除调用者另外限定WAL名称。 */
    private function walNames(): array
    {
        $directory = opendir($this->directory);
        if ($directory === false) {
            throw new RuntimeException('recovery_archive_directory_open_failed');
        }
        $names = [];
        $examined = 0;
        try {
            while (($name = readdir($directory)) !== false) {
                $examined++;
                if ($examined > 1000000 || hrtime(true) >= $this->backupDeadline) {
                    throw new RuntimeException('recovery_backup_wal_limit');
                }
                if (preg_match('/^(?:[A-F0-9]{24}(?:\.[A-F0-9]{8}\.backup|\.partial)?|[A-F0-9]{8}\.history)$/D', $name) === 1) {
                    self::regular($this->directory . '/' . $name);
                    $names[] = $name;
                }
            }
        } finally {
            closedir($directory);
        }
        sort($names, SORT_STRING);
        return $names;
    }

    private function sweepBackups(array $catalog, int $limit): array
    {
        $removed = 0;
        $completed = [];
        foreach ($catalog['backups'] as $id => $record) {
            if ($record['state'] !== 'retiring' || $removed >= $limit) {
                continue;
            }
            $source = $this->directory . '/backups/' . $id;
            $target = $this->directory . '/retiring/' . $id;
            if (is_link($source) || is_link($target) || (file_exists($source) && file_exists($target))) {
                throw new RuntimeException('recovery_backup_retirement_conflict');
            }
            if (file_exists($source)) {
                if (!is_dir($source) || !rename($source, $target)) {
                    throw new RuntimeException('recovery_backup_retirement_failed');
                }
                self::syncDirectory($this->directory . '/backups');
                self::syncDirectory($this->directory . '/retiring');
                $removed++;
            }
            if (is_dir($target) && $removed < $limit) {
                $removed += $this->deleteBackupBatch($target, $limit - $removed);
            }
            if (!file_exists($target)) {
                $backups = $catalog['backups'];
                unset($backups[$id]);
                $catalog['backups'] = $backups;
                $completed[] = (string) $id;
                $this->saveCatalog($catalog);
            }
        }
        // 调用者同时持有backup与archive锁，本批全部删除共享同一次timeline检查。
        foreach ($catalog['wal_retiring'] as $name => $record) {
            if ($removed >= $limit) {
                break;
            }
            self::name((string) $name);
            if (preg_match('/^[A-F0-9]{24}(?:\.[A-F0-9]{8}\.backup|\.partial)?$/D', (string) $name) !== 1
                || strcmp(substr((string) $name, 8, 16), $catalog['wal_floor']) >= 0) {
                throw new RuntimeException('recovery_backup_retirement_conflict');
            }
            $path = $this->directory . '/' . $name;
            $checksum = $path . '.sha256';
            if (file_exists($path) || is_link($path)) {
                self::regular($path);
                if (filesize($path) !== $record['bytes'] || hash_file('sha256', $path) !== $record['sha256'] || !unlink($path)) {
                    throw new RuntimeException('recovery_backup_retirement_conflict');
                }
            }
            if (file_exists($checksum) || is_link($checksum)) {
                self::regular($checksum);
                if (file_get_contents($checksum) !== $record['sha256'] . ' ' . $record['bytes'] . "\n" || !unlink($checksum)) {
                    throw new RuntimeException('recovery_backup_retirement_conflict');
                }
            }
            self::syncDirectory($this->directory);
            $retiringWal = $catalog['wal_retiring'];
            unset($retiringWal[$name]);
            $catalog['wal_retiring'] = $retiringWal;
            $removed++;
            $this->saveCatalog($catalog);
        }
        $hasMore = $catalog['wal_retiring'] !== [];
        foreach ($catalog['backups'] as $record) {
            $hasMore = $hasMore || $record['state'] === 'retiring';
        }
        if (!$hasMore) {
            foreach ($this->walNames() as $name) {
                $hasMore = $hasMore || (preg_match('/^[A-F0-9]{24}(?:\.[A-F0-9]{8}\.backup|\.partial)?$/D', $name) === 1
                    && strcmp(substr($name, 8, 16), $catalog['wal_floor']) < 0);
            }
        }
        return ['removed_objects' => $removed, 'completed_backups' => $completed, 'has_more' => $hasMore, 'plan' => $catalog['last_plan'] ?? null];
    }

    /** 只删除已退役私有树；目录项同步后才计入进度，重启按仍存在的文件继续。 */
    private function deleteBackupBatch(string $root, int $limit): int
    {
        $pending = [[$root, false]];
        $removed = 0;
        $examined = 0;
        $deadline = hrtime(true) + 60000000000;
        while ($pending !== [] && $removed < $limit) {
            $entry = array_pop($pending);
            $path = $entry[0];
            if (strlen($path) - strlen($root) > 4096 || hrtime(true) > $deadline) {
                throw new RuntimeException('recovery_backup_tree_limit');
            }
            if (is_link($path)) {
                throw new RuntimeException('recovery_backup_file_invalid');
            }
            if (is_dir($path)) {
                if ($entry[1]) {
                    if (!rmdir($path)) {
                        throw new RuntimeException('recovery_backup_retirement_failed');
                    }
                    self::syncDirectory(dirname($path));
                    $removed++;
                    continue;
                }
                $directory = opendir($path);
                if ($directory === false) {
                    throw new RuntimeException('recovery_backup_directory_invalid');
                }
                $pending[] = [$path, true];
                try {
                    while (($name = readdir($directory)) !== false) {
                        if ($name !== '.' && $name !== '..') {
                            $examined++;
                            if ($examined > 1000000) {
                                throw new RuntimeException('recovery_backup_tree_limit');
                            }
                            $pending[] = [$path . '/' . $name, false];
                        }
                    }
                } finally {
                    closedir($directory);
                }
            } else {
                self::regular($path);
                if (!unlink($path)) {
                    throw new RuntimeException('recovery_backup_retirement_failed');
                }
                self::syncDirectory(dirname($path));
                $removed++;
            }
        }
        return $removed;
    }

    /** @return array<string,mixed> 官方manifest/pg_control/文件/WAL校验后的稳定身份。 */
    private function inspectBackup(string $source, string $tools, bool $sync = false): array
    {
        $this->backupTree($source, $sync);
        $manifestPath = $source . '/backup_manifest';
        $labelPath = $source . '/backup_label';
        self::regular($manifestPath);
        self::regular($labelPath);
        if (!is_file($source . '/PG_VERSION') || trim((string) file_get_contents($source . '/PG_VERSION')) !== '17') {
            throw new RuntimeException('recovery_backup_version_invalid');
        }
        if (filesize($manifestPath) > 67108864 || filesize($labelPath) > 16384) {
            throw new RuntimeException('recovery_backup_metadata_limit');
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 32, JSON_BIGINT_AS_STRING);
        if (!is_array($manifest) || ($manifest['PostgreSQL-Backup-Manifest-Version'] ?? null) !== 2
            || !preg_match('/^[0-9]{1,20}$/D', (string) ($manifest['System-Identifier'] ?? ''))
            || !is_array($manifest['WAL-Ranges'] ?? null) || $manifest['WAL-Ranges'] === [] || count($manifest['WAL-Ranges']) > 128) {
            throw new RuntimeException('recovery_backup_manifest_invalid');
        }
        $label = (string) file_get_contents($labelPath);
        $start = [];
        $timeline = [];
        $previousLsn = [];
        $previousTimeline = [];
        if (preg_match('/^START WAL LOCATION: ([A-F0-9]{1,8}\/[A-F0-9]{1,8}) \(file ([A-F0-9]{24})\)$/m', $label, $start) !== 1
            || preg_match('/^START TIMELINE: ([1-9][0-9]{0,9})$/m', $label, $timeline) !== 1) {
            throw new RuntimeException('recovery_backup_label_invalid');
        }
        $hasPreviousLsn = preg_match('/^INCREMENTAL FROM LSN: ([A-F0-9]{1,8}\/[A-F0-9]{1,8})$/m', $label, $previousLsn) === 1;
        $hasPreviousTimeline = preg_match('/^INCREMENTAL FROM TLI: ([1-9][0-9]{0,9})$/m', $label, $previousTimeline) === 1;
        if ($hasPreviousLsn !== $hasPreviousTimeline) {
            throw new RuntimeException('recovery_backup_label_invalid');
        }
        // 单独调用pg_waldump核对WAL，避免pg_verifybackup另起不受本进程超时管理的子进程。
        $this->postgres($tools, 'pg_verifybackup', ['--quiet', '--exit-on-error', '--no-parse-wal', $source]);
        $control = $this->postgres($tools, 'pg_controldata', [$source]);
        $segmentMatch = [];
        if (preg_match('/^Bytes per WAL segment:\s+([0-9]+)$/m', $control, $segmentMatch) !== 1) {
            throw new RuntimeException('recovery_backup_control_invalid');
        }
        $segmentBytes = (int) $segmentMatch[1];
        if ($segmentBytes < 1048576 || $segmentBytes > 1073741824 || ($segmentBytes & ($segmentBytes - 1)) !== 0) {
            throw new RuntimeException('recovery_backup_control_invalid');
        }
        $ranges = [];
        $floor = 'FFFFFFFFFFFFFFFF';
        foreach ($manifest['WAL-Ranges'] as $range) {
            if (!is_array($range) || !is_int($range['Timeline'] ?? null) || $range['Timeline'] < 1 || $range['Timeline'] > 4294967295
                || !is_string($range['Start-LSN'] ?? null) || !is_string($range['End-LSN'] ?? null)) {
                throw new RuntimeException('recovery_backup_manifest_invalid');
            }
            $startLsn = self::lsn($range['Start-LSN']);
            $endLsn = self::lsn($range['End-LSN']);
            if (strcmp($startLsn, $endLsn) >= 0 || isset($ranges[(string) $range['Timeline']])) {
                throw new RuntimeException('recovery_backup_manifest_invalid');
            }
            $ranges[(string) $range['Timeline']] = ['start' => $startLsn, 'end' => $endLsn];
            $segment = self::segment($startLsn, $segmentBytes);
            if (strcmp($segment, $floor) < 0) {
                $floor = $segment;
            }
        }
        $startTimeline = (int) $timeline[1];
        $startLsn = self::lsn($start[1]);
        if (!isset($ranges[(string) $startTimeline]) || $ranges[(string) $startTimeline]['start'] !== $startLsn
            || sprintf('%08X', $startTimeline) . self::segment($startLsn, $segmentBytes) !== $start[2]) {
            throw new RuntimeException('recovery_backup_label_invalid');
        }
        $this->verifyRanges($ranges, $source . '/pg_wal', $tools);
        $ignored = [];
        foreach (['postgresql.auto.conf', 'standby.signal', 'recovery.signal'] as $file) {
            $ignored[$file] = is_file($source . '/' . $file) ? hash_file('sha256', $source . '/' . $file) : null;
        }
        return ['manifest_sha256' => hash_file('sha256', $manifestPath), 'label_sha256' => hash_file('sha256', $labelPath),
            'system_id' => (string) $manifest['System-Identifier'], 'segment_bytes' => $segmentBytes,
            'start_timeline' => $startTimeline, 'start_lsn' => $startLsn, 'ranges' => $ranges, 'wal_floor' => $floor,
            'previous_timeline' => $hasPreviousTimeline ? (int) $previousTimeline[1] : 0,
            'previous_lsn' => $hasPreviousLsn ? self::lsn($previousLsn[1]) : '', 'ignored_sha256' => $ignored];
    }

    /** 官方WAL解析与manifest文件校验分开执行，超时责任始终属于当前维护进程。 */
    private function verifyRanges(array $ranges, string $directory, string $tools): void
    {
        foreach ($ranges as $timeline => $range) {
            $this->postgres($tools, 'pg_waldump', ['--quiet', '--path=' . $directory, '--timeline=' . $timeline,
                '--start=' . substr($range['start'], 0, 8) . '/' . substr($range['start'], 8, 8),
                '--end=' . substr($range['end'], 0, 8) . '/' . substr($range['end'], 8, 8)]);
        }
    }

    private function verifyBackupRecord(string $id, array $record, string $tools): void
    {
        $observed = $this->inspectBackup($this->directory . '/backups/' . $id, $tools);
        foreach ($observed as $field => $value) {
            if (!array_key_exists($field, $record) || $record[$field] !== $value) {
                throw new RuntimeException('recovery_backup_content_conflict');
            }
        }
    }

    /** @return list<string> 从full到指定增量的唯一链；缺失、环或未完成登记均拒绝。 */
    private function chain(array $backups, string $id): array
    {
        $chain = [];
        $seen = [];
        $current = $id;
        while ($current !== '') {
            if (isset($seen[$current]) || !isset($backups[$current]) || $backups[$current]['state'] !== 'active') {
                throw new RuntimeException('recovery_backup_chain_invalid');
            }
            $seen[$current] = true;
            $chain[] = $current;
            $current = $backups[$current]['parent'];
        }
        return array_reverse($chain);
    }

    private function backupDirectories(): void
    {
        foreach (['incoming', 'backups', 'retiring'] as $name) {
            $path = $this->directory . '/' . $name;
            if (!file_exists($path) && !is_link($path) && !mkdir($path, 0700)) {
                throw new RuntimeException('recovery_backup_directory_failed');
            }
            if (!is_dir($path) || is_link($path) || (fileperms($path) & 0077) !== 0) {
                throw new RuntimeException('recovery_backup_directory_invalid');
            }
        }
        self::syncDirectory($this->directory);
    }

    /** @return array<string,mixed> 有界私有持久状态，任何未知/损坏状态都拒绝清理。 */
    private function catalog(): array
    {
        $path = $this->directory . '/backup-catalog.json';
        if (!file_exists($path) && !is_link($path)) {
            return ['version' => 1, 'system_id' => '', 'segment_bytes' => 0, 'wal_floor' => '', 'backups' => [], 'pins' => [], 'wal_retiring' => []];
        }
        self::regular($path);
        if (filesize($path) > 8388608 || (fileperms($path) & 0077) !== 0 || (int) stat($path)['nlink'] !== 1) {
            throw new RuntimeException('recovery_backup_catalog_invalid');
        }
        $catalog = json_decode((string) file_get_contents($path), true, 32);
        if (!is_array($catalog) || ($catalog['version'] ?? null) !== 1 || !is_string($catalog['system_id'] ?? null)
            || !is_int($catalog['segment_bytes'] ?? null) || !is_string($catalog['wal_floor'] ?? null)
            || !is_array($catalog['backups'] ?? null) || !is_array($catalog['pins'] ?? null) || !is_array($catalog['wal_retiring'] ?? null)
            || count($catalog['backups']) > 4096 || count($catalog['pins']) > 4096 || count($catalog['wal_retiring']) > 100) {
            throw new RuntimeException('recovery_backup_catalog_invalid');
        }
        $segmentBytes = $catalog['segment_bytes'];
        if (($catalog['system_id'] === '' && ($segmentBytes !== 0 || $catalog['backups'] !== []))
            || ($catalog['system_id'] !== '' && (preg_match('/^[0-9]{1,20}$/D', $catalog['system_id']) !== 1
                || $segmentBytes < 1048576 || $segmentBytes > 1073741824 || ($segmentBytes & ($segmentBytes - 1)) !== 0))
            || ($catalog['wal_floor'] !== '' && preg_match('/^[A-F0-9]{16}$/D', $catalog['wal_floor']) !== 1)) {
            throw new RuntimeException('recovery_backup_catalog_invalid');
        }
        foreach ($catalog['backups'] as $id => $record) {
            self::backupId((string) $id);
            if (!is_array($record) || !in_array($record['state'] ?? '', ['registering', 'active', 'retiring'], true)
                || !is_string($record['parent'] ?? null) || !is_int($record['registered_at'] ?? null) || $record['registered_at'] < 1
                || !is_int($record['start_timeline'] ?? null) || $record['start_timeline'] < 1 || $record['start_timeline'] > 4294967295
                || !is_int($record['previous_timeline'] ?? null) || $record['previous_timeline'] < 0 || $record['previous_timeline'] > 4294967295
                || !is_string($record['previous_lsn'] ?? null) || !is_string($record['start_lsn'] ?? null)
                || preg_match('/^[A-F0-9]{16}$/D', $record['start_lsn']) !== 1
                || !is_array($record['ranges'] ?? null) || count($record['ranges']) < 1 || count($record['ranges']) > 128
                || !is_string($record['wal_floor'] ?? null) || preg_match('/^[A-F0-9]{16}$/D', $record['wal_floor']) !== 1
                || ($record['system_id'] ?? null) !== $catalog['system_id'] || ($record['segment_bytes'] ?? null) !== $segmentBytes
                || !is_array($record['ignored_sha256'] ?? null)) {
                throw new RuntimeException('recovery_backup_catalog_invalid');
            }
            foreach (['manifest_sha256', 'label_sha256'] as $field) {
                if (!is_string($record[$field] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $record[$field]) !== 1) {
                    throw new RuntimeException('recovery_backup_catalog_invalid');
                }
            }
            foreach (['postgresql.auto.conf', 'standby.signal', 'recovery.signal'] as $file) {
                if (!array_key_exists($file, $record['ignored_sha256']) || ($record['ignored_sha256'][$file] !== null
                    && (!is_string($record['ignored_sha256'][$file]) || preg_match('/^[a-f0-9]{64}$/D', $record['ignored_sha256'][$file]) !== 1))) {
                    throw new RuntimeException('recovery_backup_catalog_invalid');
                }
            }
            $floor = 'FFFFFFFFFFFFFFFF';
            foreach ($record['ranges'] as $timeline => $range) {
                if (!is_int($timeline) || $timeline < 1 || $timeline > 4294967295 || !is_array($range)
                    || !is_string($range['start'] ?? null) || !is_string($range['end'] ?? null)
                    || preg_match('/^[A-F0-9]{16}$/D', $range['start']) !== 1 || preg_match('/^[A-F0-9]{16}$/D', $range['end']) !== 1
                    || strcmp($range['start'], $range['end']) >= 0) {
                    throw new RuntimeException('recovery_backup_catalog_invalid');
                }
                $segment = self::segment($range['start'], $segmentBytes);
                if (strcmp($segment, $floor) < 0) {
                    $floor = $segment;
                }
            }
            if (($record['ranges'][$record['start_timeline']]['start'] ?? null) !== $record['start_lsn'] || $floor !== $record['wal_floor']
                || ($record['parent'] === '' && ($record['previous_timeline'] !== 0 || $record['previous_lsn'] !== ''))
                || ($record['parent'] !== '' && ($record['previous_timeline'] === 0 || preg_match('/^[A-F0-9]{16}$/D', $record['previous_lsn']) !== 1))
                || ($record['state'] !== 'retiring' && $catalog['wal_floor'] !== '' && strcmp($floor, $catalog['wal_floor']) < 0)) {
                throw new RuntimeException('recovery_backup_catalog_invalid');
            }
            if ($record['parent'] !== '') {
                self::backupId($record['parent']);
                if ($record['state'] !== 'retiring') {
                    $parent = $catalog['backups'][$record['parent']] ?? [];
                    if (($parent['state'] ?? '') !== 'active' || ($parent['start_timeline'] ?? null) !== $record['previous_timeline']
                        || ($parent['start_lsn'] ?? null) !== $record['previous_lsn']) {
                        throw new RuntimeException('recovery_backup_catalog_invalid');
                    }
                }
            }
        }
        foreach ($catalog['pins'] as $pin => $id) {
            self::backupId((string) $pin);
            if (!is_string($id) || !isset($catalog['backups'][$id]) || $catalog['backups'][$id]['state'] !== 'active') {
                throw new RuntimeException('recovery_backup_catalog_invalid');
            }
            $this->chain($catalog['backups'], $id);
        }
        foreach ($catalog['wal_retiring'] as $name => $record) {
            if (!is_string($name) || preg_match('/^[A-F0-9]{24}(?:\.[A-F0-9]{8}\.backup|\.partial)?$/D', $name) !== 1
                || $catalog['wal_floor'] === '' || strcmp(substr($name, 8, 16), $catalog['wal_floor']) >= 0
                || !is_array($record) || !is_int($record['bytes'] ?? null) || $record['bytes'] < 1 || $record['bytes'] > 1073741824
                || !is_string($record['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $record['sha256']) !== 1) {
                throw new RuntimeException('recovery_backup_catalog_invalid');
            }
        }
        if ($catalog['wal_floor'] !== '') {
            $plan = $catalog['last_plan'] ?? [];
            if (!is_array($plan) || !is_string($plan['anchor'] ?? null) || ($catalog['backups'][$plan['anchor']]['state'] ?? '') !== 'active'
                || !is_int($plan['validated_timeline'] ?? null) || $plan['validated_timeline'] < 1 || $plan['validated_timeline'] > 4294967295
                || !is_int($plan['planned_at'] ?? null) || !is_int($plan['window_start'] ?? null)
                || $plan['planned_at'] - $plan['window_start'] !== 604800 || ($plan['wal_floor'] ?? null) !== $catalog['wal_floor']) {
                throw new RuntimeException('recovery_backup_catalog_invalid');
            }
        }
        return $catalog;
    }

    private function saveCatalog(array $catalog): void
    {
        $bytes = json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (strlen($bytes) > 8388608) {
            throw new RuntimeException('recovery_backup_catalog_limit');
        }
        // .backup.lock内只有一个写者；固定未发布槽在SIGKILL后可安全重写，不累积随机临时文件。
        $temporary = $this->directory . '/.retention-next';
        if (is_link($temporary) || (file_exists($temporary) && (!is_file($temporary) || (int) stat($temporary)['nlink'] !== 1))) {
            throw new RuntimeException('recovery_backup_catalog_write_failed');
        }
        $output = @fopen($temporary, 'c+b');
        if ($output === false) {
            throw new RuntimeException('recovery_backup_catalog_write_failed');
        }
        try {
            if (!chmod($temporary, 0600) || !ftruncate($output, 0) || fwrite($output, $bytes) !== strlen($bytes) || !fflush($output) || !fsync($output)
                || !rename($temporary, $this->directory . '/backup-catalog.json')) {
                throw new RuntimeException('recovery_backup_catalog_write_failed');
            }
            self::syncDirectory($this->directory);
        } finally {
            fclose($output);
            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }

    private static function backupId(string $id): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $id) !== 1) {
            throw new RuntimeException('recovery_backup_id_invalid');
        }
    }

    private static function lsn(string $lsn): string
    {
        if (preg_match('/^([0-9A-F]{1,8})\/([0-9A-F]{1,8})$/D', $lsn, $parts) !== 1) {
            throw new RuntimeException('recovery_backup_lsn_invalid');
        }
        return str_pad($parts[1], 8, '0', STR_PAD_LEFT) . str_pad($parts[2], 8, '0', STR_PAD_LEFT);
    }

    private static function segment(string $lsn, int $bytes): string
    {
        return substr($lsn, 0, 8) . sprintf('%08X', intdiv((int) hexdec(substr($lsn, 8, 8)), $bytes));
    }

    /** 归档所有者只接管自包含私有目录，拒绝tablespace符号链接、硬链接和特殊文件。 */
    private function backupTree(string $root, bool $sync): void
    {
        $pending = [[$root, 0, false]];
        $examined = 0;
        $deadline = $this->backupDeadline;
        while ($pending !== []) {
            $entry = array_pop($pending);
            $path = $entry[0];
            if ($entry[1] > 64 || hrtime(true) > $deadline) {
                throw new RuntimeException('recovery_backup_tree_limit');
            }
            if ($entry[2]) {
                self::syncDirectory($path);
                continue;
            }
            if (is_link($path) || !is_dir($path) || (fileperms($path) & 0077) !== 0) {
                throw new RuntimeException('recovery_backup_directory_invalid');
            }
            $directory = opendir($path);
            if ($directory === false) {
                throw new RuntimeException('recovery_backup_directory_invalid');
            }
            if ($sync) {
                $pending[] = [$path, $entry[1], true];
            }
            try {
                while (($name = readdir($directory)) !== false) {
                    if ($name === '.' || $name === '..') {
                        continue;
                    }
                    $examined++;
                    $child = $path . '/' . $name;
                    if ($examined > 1000000 || hrtime(true) > $deadline) {
                        throw new RuntimeException('recovery_backup_tree_limit');
                    }
                    if (is_link($child) || (fileperms($child) & 0077) !== 0) {
                        throw new RuntimeException('recovery_backup_file_invalid');
                    }
                    if (is_dir($child)) {
                        $pending[] = [$child, $entry[1] + 1, false];
                    } elseif (!is_file($child) || (int) stat($child)['nlink'] !== 1) {
                        throw new RuntimeException('recovery_backup_file_invalid');
                    } elseif ($sync) {
                        $file = @fopen($child, 'rb');
                        if ($file === false) {
                            throw new RuntimeException('recovery_backup_file_sync_failed');
                        }
                        try {
                            if (!fsync($file)) {
                                throw new RuntimeException('recovery_backup_file_sync_failed');
                            }
                        } finally {
                            fclose($file);
                        }
                    }
                }
            } finally {
                closedir($directory);
            }
        }
    }

    /** 复用数组proc_open与有界双流排空；仅允许PG17固定维护程序，失败不继续删除。 */
    private function postgres(string $tools, string $name, array $arguments): string
    {
        if (hrtime(true) >= $this->backupDeadline) {
            throw new RuntimeException('recovery_backup_deadline');
        }
        $root = realpath($tools);
        if ($root === false || !in_array($name, ['pg_verifybackup', 'pg_combinebackup', 'pg_controldata', 'pg_waldump'], true)) {
            throw new RuntimeException('recovery_backup_tools_invalid');
        }
        $program = $root . '/bin/' . $name;
        if (!is_file($program) || !is_executable($program)) {
            throw new RuntimeException('recovery_backup_tools_invalid');
        }
        if ($arguments !== ['--version'] && !isset($this->verifiedTools[$program])) {
            $version = $this->postgres($tools, $name, ['--version']);
            if (preg_match('/^' . preg_quote($name, '/') . ' \(PostgreSQL\) 17\.[0-9]+\s*$/D', $version) !== 1) {
                throw new RuntimeException('recovery_backup_tools_invalid');
            }
            $this->verifiedTools[$program] = true;
        }
        $environment = getenv();
        $environment['LC_ALL'] = 'C';
        $pipes = [];
        $process = proc_open([$program, ...$arguments], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->directory, $environment, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('recovery_backup_tool_failed');
        }
        $output = '';
        $errors = '';
        $complete = false;
        $deadline = $this->backupDeadline;
        try {
            foreach ($pipes as $pipe) {
                stream_set_blocking($pipe, false);
            }
            do {
                $output .= (string) fread($pipes[1], 65536);
                $errors .= (string) fread($pipes[2], 65536);
                if (strlen($output) + strlen($errors) > 1048576) {
                    break;
                }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $output .= (string) stream_get_contents($pipes[1], 1048577);
                    $errors .= (string) stream_get_contents($pipes[2], 1048577);
                    $complete = $status['exitcode'] === 0 && strlen($output) + strlen($errors) <= 1048576;
                    break;
                }
                usleep(10000);
            } while (hrtime(true) < $deadline);
            if (!$complete) {
                throw new RuntimeException('recovery_backup_tool_failed');
            }
            return $output;
        } finally {
            if (!$complete) {
                proc_terminate($process, 9);
            }
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }
    }

    /** @return array{bytes: int, sha256: string} 64KiB流缓冲；最后用硬链接原子发布且不替换既有目录项。 */
    private function copy(string $source, string $target, bool $retry, array $expected = []): array
    {
        self::regular($source);
        $bytes = filesize($source);
        $parent = realpath(dirname($target));
        if ($bytes === false || $bytes < 1 || $bytes > 1073741824 || $parent === false || !is_dir($parent)) {
            throw new RuntimeException('recovery_archive_file_limit');
        }
        $temporary = $parent . '/.wal-' . bin2hex(random_bytes(16));
        $input = @fopen($source, 'rb');
        $output = @fopen($temporary, 'x+b');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
                unlink($temporary);
            }
            throw new RuntimeException('recovery_archive_stream_failed');
        }
        $copied = 0;
        $hash = hash_init('sha256');
        $deadline = hrtime(true) + 60000000000;
        $problem = null;
        try {
            if (!chmod($temporary, 0600)) {
                throw new RuntimeException('recovery_archive_permissions_failed');
            }
            while (!feof($input)) {
                $buffer = fread($input, 65536);
                if ($buffer === false || ($buffer === '' && !feof($input)) || hrtime(true) > $deadline) {
                    throw new RuntimeException('recovery_archive_read_failed');
                }
                $copied += strlen($buffer);
                if ($copied > $bytes || ($buffer !== '' && fwrite($output, $buffer) !== strlen($buffer))) {
                    throw new RuntimeException('recovery_archive_write_failed');
                }
                hash_update($hash, $buffer);
            }
            $record = ['bytes' => $copied, 'sha256' => hash_final($hash)];
            if ($copied !== $bytes || ($expected !== [] && $expected !== $record) || !fflush($output) || !fsync($output)) {
                throw new RuntimeException('recovery_archive_copy_unconfirmed');
            }
            if (!@link($temporary, $target)) {
                self::regular($target);
                if (!$retry || filesize($target) !== $bytes || hash_file('sha256', $target) !== $record['sha256']) {
                    throw new RuntimeException('recovery_archive_content_conflict');
                }
            }
            self::syncDirectory($parent);
            return $record;
        } catch (\Throwable $failure) {
            $problem = $failure;
            throw $failure;
        } finally {
            fclose($input);
            fclose($output);
            if (!unlink($temporary)) {
                throw new RuntimeException('recovery_archive_temporary_cleanup_failed', 0, $problem);
            }
        }
    }

    private static function name(string $name): void
    {
        if (preg_match('/^(?:[A-F0-9]{24}(?:\.[A-F0-9]{8}\.backup|\.partial)?|[A-F0-9]{8}\.history)$/D', $name) !== 1) {
            throw new RuntimeException('recovery_archive_name_invalid');
        }
    }

    private static function regular(string $path): void
    {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('recovery_archive_regular_file_required');
        }
    }

    private static function syncDirectory(string $path): void
    {
        $directory = @fopen($path, 'r');
        if ($directory === false) {
            throw new RuntimeException('recovery_archive_directory_open_failed');
        }
        try {
            if (!fsync($directory)) {
                throw new RuntimeException('recovery_archive_directory_sync_failed');
            }
        } finally {
            fclose($directory);
        }
    }

    /** @return resource 私有归档目录共用一个有界锁，避免归档与恢复看到未完成摘要。 */
    private function lock(string $name = '.archive.lock'): mixed
    {
        $path = $this->directory . '/' . $name;
        if (is_link($path) || (file_exists($path) && (!is_file($path) || filesize($path) !== 0 || (int) stat($path)['nlink'] !== 1))) {
            throw new RuntimeException('recovery_archive_lock_invalid');
        }
        $lock = @fopen($path, 'c+b');
        if ($lock === false) {
            throw new RuntimeException('recovery_archive_lock_unavailable');
        }
        if (!chmod($path, 0600)) {
            fclose($lock);
            throw new RuntimeException('recovery_archive_lock_permissions');
        }
        $deadline = hrtime(true) + 5000000000;
        while (!flock($lock, LOCK_EX | LOCK_NB)) {
            if (hrtime(true) >= $deadline) {
                fclose($lock);
                throw new RuntimeException('recovery_archive_lock_timeout');
            }
            usleep(10000);
        }
        return $lock;
    }
}
