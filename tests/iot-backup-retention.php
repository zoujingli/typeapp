<?php

declare(strict_types=1);

require_once __DIR__ . '/iot-recovery.php';

use Type\Testing\Process;

/** 真实备份链的私有破坏性资源；不修改共享物理恢复/授权验收装置。 */
function iotBackupRetention(array $command, array $environment, string $base, array &$report): void
{
    $root = dirname(__DIR__);
    $archive = $base . '/archive';
    expect(mkdir($archive, 0700), '不能创建本轮归档目录');
    $tools = NativeDatabase::tools('pgsql', (string) getenv('TYPE_PGSQL_TOOLS'));
    $report['tools'] = [];
    foreach (['pg_basebackup', 'pg_verifybackup', 'pg_combinebackup', 'pg_controldata', 'pg_waldump'] as $name) {
        $program = $tools['root'] . '/bin/' . $name;
        $report['tools'][$name] = ['version' => trim(successful([$program, '--version'], $root)), 'sha256' => hash_file('sha256', $program)];
    }
    $checks = [];
    $run = static function (array $arguments, string $failure = '') use ($command, $environment, $archive, &$checks): array {
        $checks[] = $arguments[0] . (in_array($arguments[0], ['register', 'pin', 'unpin'], true) ? ' ' . $arguments[1] : '') . ($failure === '' ? '' : ':' . $failure);
        return iotRecoveryRun([...$command, 'iot:backup', $arguments[0], $archive, ...array_slice($arguments, 1)], $environment, $failure === '', 120, $failure);
    };
    $clean = ['clean', $tools['root'], '100'];
    $hook = iotRecoveryHook(
        [...$command, 'iot:wal', 'archive', $archive],
        $environment,
        [escapeshellarg(str_replace('%', '%%', $base . '/primary/data') . '/%p'), "'%f'"]
    );
    $database = null;
    $foreignDatabase = null;
    $recovered = null;
    $source = null;
    $restored = null;
    $passwordFile = $base . '/pgpass';
    $password = '';
    try {
        $run(['status']);
        $run(['clean', $tools['root'], '101'], 'recovery_backup_batch_invalid');
        $run(['register', '../escape', $tools['root']], 'recovery_backup_id_invalid');
        $run($clean, 'recovery_backup_window_unavailable');
        $database = new NativeDatabase($base . '/primary', 'pgsql', $tools, [], [
            'archive_mode' => 'on', 'archive_command' => $hook, 'summarize_wal' => 'on', 'wal_summary_keep_time' => '10d']);
        $dbEnvironment = array_replace(getenv(), $database->environment());
        $password = $dbEnvironment['TYPE_PGSQL_PASSWORD'];
        $pass = '127.0.0.1:' . $dbEnvironment['TYPE_PGSQL_PORT'] . ':*:' . $dbEnvironment['TYPE_PGSQL_USER'] . ':' . $password . "\n";
        expect(file_put_contents($passwordFile, $pass) === strlen($pass) && chmod($passwordFile, 0600), '不能建立本轮备份凭据');
        $dbEnvironment['PGPASSFILE'] = $passwordFile;
        $source = new PDO(
            'pgsql:host=127.0.0.1;port=' . $dbEnvironment['TYPE_PGSQL_PORT'] . ';dbname=type_app_test',
            $dbEnvironment['TYPE_PGSQL_USER'],
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $source->exec('CREATE TABLE retention_probe (phase TEXT PRIMARY KEY)');
        $backupCommand = [$tools['root'] . '/bin/pg_basebackup', '-h', '127.0.0.1', '-p', $dbEnvironment['TYPE_PGSQL_PORT'],
            '-U', $dbEnvironment['TYPE_PGSQL_USER'], '-X', 'stream', '-c', 'fast', '--no-password', '--no-clean', '--manifest-checksums=SHA256', '--no-sync'];
        $records = [];
        foreach (['obsolete', 'base', 'incremental', 'boundary', 'latest'] as $id) {
            $source->exec('INSERT INTO retention_probe VALUES (' . $source->quote($id) . ')');
            $arguments = [...$backupCommand, '-D', $archive . '/incoming/' . $id];
            if ($id === 'incremental') {
                $run(['pin', 'base', 'incremental-job']);
                $arguments[] = '--incremental=' . $archive . '/backups/base/backup_manifest';
            }
            nativeDatabaseCommand($arguments, $dbEnvironment, [$password], $base . '/backup-' . $id . '.log', 120);
            if ($id === 'obsolete') {
                expect(symlink($archive . '/incoming/obsolete/PG_VERSION', $archive . '/incoming/obsolete/unsafe'), '不能创建符号链接反例');
                $run(['register', $id, $tools['root']], 'recovery_backup_file_invalid');
                expect(unlink($archive . '/incoming/obsolete/unsafe'), '不能移除本轮链接反例');
            }
            $records[$id] = $run(['register', $id, $tools['root']]);
            expect($records[$id] === $run(['register', $id, $tools['root']]), '登记重试改写时间或身份');
            expect(!is_dir($archive . '/incoming/' . $id) && is_dir($archive . '/backups/' . $id), '登记没有接管私有备份');
            if ($id === 'incremental') {
                expect($records[$id]['parent'] === 'base', '增量父链没有按官方身份关联');
                $run(['unpin', 'base', 'incremental-job']);
                expect(!isset($run(['status'])['pins']['incremental-job']), '解除保护没有持久移除保护项');
            }
        }
        $source->exec("INSERT INTO retention_probe VALUES ('wal-only')");
        $restoreLsn = (string) $source->query("SELECT pg_create_restore_point('retention_target')")->fetchColumn();
        $lastWal = (string) $source->query('SELECT pg_walfile_name(' . $source->quote($restoreLsn) . '::pg_lsn)')->fetchColumn();
        $source->exec("INSERT INTO retention_probe VALUES ('after-target')");
        $source->query('SELECT pg_switch_wal()')->fetchColumn();
        $deadline = microtime(true) + 90;
        do {
            clearstatcache();
            if (is_file($archive . '/' . $lastWal . '.sha256')) {
                break;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        expect(is_file($archive . '/' . $lastWal . '.sha256'), '最后WAL没有完成真实归档');
        $run($clean, 'recovery_backup_window_unavailable');
        $foreignDatabase = new NativeDatabase($base . '/foreign-primary', 'pgsql', $tools);
        $foreignEnvironment = array_replace(getenv(), $foreignDatabase->environment());
        $foreignEnvironment['PGPASSWORD'] = $foreignEnvironment['TYPE_PGSQL_PASSWORD'];
        nativeDatabaseCommand(
            [$tools['root'] . '/bin/pg_basebackup', '-h', '127.0.0.1', '-p', $foreignEnvironment['TYPE_PGSQL_PORT'],
            '-U', $foreignEnvironment['TYPE_PGSQL_USER'], '-X', 'stream', '-c', 'fast', '--no-password', '-D', $archive . '/incoming/foreign'],
            $foreignEnvironment,
            [$foreignEnvironment['TYPE_PGSQL_PASSWORD']],
            $base . '/foreign-backup.log',
            120
        );
        $run(['register', 'foreign', $tools['root']], 'recovery_backup_system_conflict');
        $foreignDatabase->close();
        $report['foreign_system_refused'] = $foreignDatabase->evidence()['owned-server-stopped'];
        $source = null;
        $database->close();
        $report['source_stopped_before_cleanup'] = $database->evidence()['owned-server-stopped'];
        $catalogPath = $archive . '/backup-catalog.json';
        $catalog = $run(['status']);
        $writeCatalog = static function (array $state) use ($catalogPath): void {
            $bytes = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            expect(file_put_contents($catalogPath, $bytes) === strlen($bytes), '不能建立本轮受控状态反例');
        };
        // 仅改变本轮私有catalog的算法输入；生产CLI没有时钟注入，也不声称真实保存七天。
        $now = time();
        foreach (['obsolete' => -10, 'base' => -9, 'incremental' => -8, 'boundary' => -7, 'latest' => -1] as $id => $days) {
            $catalog['backups'][$id]['registered_at'] = $now + $days * 86400;
        }
        $writeCatalog($catalog);
        $report['date_fixture'] = ['kind' => 'controlled-catalog-registration-times', 'real_seven_days_elapsed' => false,
            'offset_days' => ['obsolete' => -10, 'base' => -9, 'incremental' => -8, 'boundary' => -7, 'latest' => -1]];
        $run(['pin', 'incremental', 'restore-job']);
        $run(['pin', 'obsolete', 'hold-obsolete']);
        $catalog = $run(['status']);
        $run(['pin', 'base', 'hold-obsolete'], 'recovery_backup_pin_conflict');
        $run(['unpin', 'base', 'hold-obsolete'], 'recovery_backup_pin_conflict');
        $lock = fopen($archive . '/.backup.lock', 'r+b');
        expect(is_resource($lock) && flock($lock, LOCK_EX | LOCK_NB), '不能建立维护锁反例');
        try {
            $run(['status'], 'recovery_archive_lock_timeout');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $changed = $catalog;
        $changed['backups']['latest']['registered_at'] = time() + 86400;
        $writeCatalog($changed);
        $run($clean, 'recovery_backup_clock_reversed');
        $writeCatalog($catalog);
        $changed = $catalog;
        unset($changed['backups']['base']);
        $writeCatalog($changed);
        $run($clean, 'recovery_backup_catalog_invalid');
        $writeCatalog($catalog);
        $changed = $catalog;
        $changed['wal_retiring']['../../escape'] = ['bytes' => 1, 'sha256' => str_repeat('0', 64)];
        $writeCatalog($changed);
        $run($clean, 'recovery_backup_catalog_invalid');
        $writeCatalog($catalog);
        $control = $archive . '/backups/latest/global/pg_control';
        $controlBytes = file_get_contents($control);
        $corrupt = $controlBytes;
        $corrupt[100] = chr(ord($corrupt[100]) ^ 1);
        file_put_contents($control, $corrupt);
        $run($clean, 'recovery_backup_tool_failed');
        file_put_contents($control, $controlBytes);
        $manifestPath = $archive . '/backups/latest/backup_manifest';
        $manifestBytes = file_get_contents($manifestPath);
        file_put_contents($manifestPath, str_replace('PostgreSQL-Backup-Manifest-Version', 'PostgreSQL-Broken-Manifest-Version', $manifestBytes));
        $run($clean, 'recovery_backup_manifest_invalid');
        file_put_contents($manifestPath, $manifestBytes);
        $ignoredPath = $archive . '/backups/latest/postgresql.auto.conf';
        $ignoredBytes = file_get_contents($ignoredPath);
        file_put_contents($ignoredPath, $ignoredBytes . "\n# changed after registration\n");
        $run($clean, 'recovery_backup_content_conflict');
        file_put_contents($ignoredPath, $ignoredBytes);
        $requiredWal = sprintf('%08X', $records['base']['start_timeline']) . $records['base']['wal_floor'];
        expect(rename($archive . '/' . $requiredWal, $base . '/missing-required-wal'), '不能建立WAL缺失反例');
        $run($clean, 'recovery_backup_tool_failed');
        expect(rename($base . '/missing-required-wal', $archive . '/' . $requiredWal), '不能恢复本轮WAL反例');
        $walPath = $archive . '/' . $lastWal;
        $stream = fopen($walPath, 'r+b');
        $firstByte = fread($stream, 1);
        rewind($stream);
        fwrite($stream, chr(ord($firstByte) ^ 1));
        fclose($stream);
        $run($clean, 'recovery_archive_corrupt');
        $stream = fopen($walPath, 'r+b');
        fwrite($stream, $firstByte);
        fclose($stream);
        expect($run(['status']) === $catalog, '失败校验提交了删除意图');
        // history可先于新timeline的完整WAL到达；真实归档入口接管的未知身份必须先阻止清理。
        $unknownHistory = static function () use ($command, $environment, $archive, $base, $run, $clean): void {
            $history = $base . '/unknown-timeline.history';
            file_put_contents($history, "1\t0/1000000\tcontrolled unknown timeline\n");
            chmod($history, 0600);
            iotRecoveryRun([...$command, 'iot:wal', 'archive', $archive, $history, '00000002.history'], $environment);
            $before = $run(['status']);
            $entries = [];
            foreach (['backups', 'retiring'] as $directory) {
                $entries[$directory] = scandir($archive . '/' . $directory);
            }
            $run($clean, 'recovery_backup_unregistered_timeline');
            expect($run(['status']) === $before, '未知history期间改写了删除意图');
            foreach ($entries as $directory => $names) {
                expect(scandir($archive . '/' . $directory) === $names, '未知history期间推进了备份转移或删除');
            }
            expect(unlink($archive . '/00000002.history') && unlink($archive . '/00000002.history.sha256'), '不能移除本轮受控history反例');
        };
        $unknownHistory();
        // 未登记新timeline只能保守拒绝；不把改名的WAL宣称为真实跨timeline恢复。
        $foreignWal = '00000002' . substr($lastWal, 8);
        iotRecoveryRun([...$command, 'iot:wal', 'archive', $archive, $walPath, $foreignWal], $environment);
        $run($clean, 'recovery_backup_unregistered_timeline');
        expect(unlink($archive . '/' . $foreignWal) && unlink($archive . '/' . $foreignWal . '.sha256'), '不能清除本轮错误timeline反例');
        // 登记意图在rename前/后中断均重试原ID；不是对真实SIGKILL窗口的替代证据。
        $changed = $catalog;
        $changed['backups']['latest']['state'] = 'registering';
        $writeCatalog($changed);
        $run($clean, 'recovery_backup_registration_pending');
        expect(rename($archive . '/backups/latest', $archive . '/incoming/latest'), '不能建立登记前置意图');
        $registered = $run(['register', 'latest', $tools['root']]);
        expect($registered['registered_at'] === $catalog['backups']['latest']['registered_at'], '登记恢复刷新保留日期');
        $writeCatalog($changed);
        expect($run(['register', 'latest', $tools['root']]) === $registered, 'rename后登记恢复不幂等');
        // 与备份内容无关的mtime既不能保护过期项，也不能提前删除窗口内项。
        touch($archive . '/backups/obsolete', time() + 86400);
        touch($archive . '/backups/latest', time() - 30 * 86400);
        file_put_contents($archive . '/.retention-next', 'interrupted unpublished catalog');
        chmod($archive . '/.retention-next', 0600);
        $held = $run($clean);
        expect(!$held['has_more'] && $held['plan']['anchor'] === 'boundary' && in_array('obsolete', $held['plan']['kept_backups'], true), '七天边界或显式pin没有保留');
        expect(in_array('base', $held['plan']['kept_backups'], true) && in_array('incremental', $held['plan']['kept_backups'], true), 'pin没有覆盖增量父链');
        expect(!is_file($archive . '/.retention-next'), '中断未发布状态没有安全复用');
        $run(['unpin', 'obsolete', 'hold-obsolete']);
        // 真正杀死正在删除的公开维护进程；观察已持久retiring意图和目录转移后发送SIGKILL。
        $process = new Process([...$command, 'iot:backup', 'clean', $archive, $tools['root'], '100'], $root, $environment, 1048576);
        $killed = false;
        try {
            $deadline = microtime(true) + 120;
            do {
                clearstatcache();
                if (is_dir($archive . '/retiring/obsolete') && $process->running()) {
                    $killed = posix_kill($process->pid(), SIGKILL);
                    break;
                }
                usleep(100);
            } while ($process->running() && microtime(true) < $deadline);
            $result = $process->wait(10);
            expect($killed && !$result->successful() && !$result->timedOut, '未观察到真实退役清理SIGKILL窗口');
        } finally {
            $process->stop();
        }
        $pending = $run(['status']);
        expect($pending['backups']['obsolete']['state'] === 'retiring' && $pending['backups']['base']['state'] === 'active', '崩溃后退役意图不正确');
        $unknownHistory();
        $retiringNames = array_keys($pending['wal_retiring']);
        expect(count($retiringNames) >= 2, '没有足够的真实到期WAL覆盖成对中断');
        // 已真实提交删除意图后，分别构造原件已删/摘要已删的崩溃状态；不冒称这里执行了SIGKILL。
        expect(unlink($archive . '/' . $retiringNames[0]) && unlink($archive . '/' . $retiringNames[1] . '.sha256'), '不能建立本轮WAL成对中断状态');
        $run(['pin', 'obsolete', 'too-late'], 'recovery_backup_chain_invalid');
        $removed = 0;
        for ($batch = 0; $batch < 100; $batch++) {
            $progress = $run($clean);
            expect($progress['removed_objects'] <= 100, '删除批次越界');
            $removed += $progress['removed_objects'];
            if (!$progress['has_more']) {
                break;
            }
        }
        expect(!$progress['has_more'] && !is_dir($archive . '/backups/obsolete') && !is_dir($archive . '/retiring/obsolete'), '退役进度没有收敛');
        $current = $run(['status']);
        expect(!isset($current['backups']['obsolete']) && $current['wal_floor'] === $records['base']['wal_floor'], '过期非依赖full未删除或WAL越过保留父链');
        foreach (array_slice($retiringNames, 0, 2) as $name) {
            expect(!file_exists($archive . '/' . $name) && !file_exists($archive . '/' . $name . '.sha256'), 'WAL成对中断状态未收敛');
        }
        $parallel = [];
        try {
            for ($index = 0; $index < 2; $index++) {
                $parallel[] = new Process([...$command, 'iot:backup', 'clean', $archive, $tools['root'], '100'], $root, $environment, 1048576);
            }
            foreach ($parallel as $process) {
                expect($process->wait(120)->successful(), '并发清理未在同一锁下幂等完成');
            }
        } finally {
            foreach ($parallel as $process) {
                $process->stop();
            }
        }
        $report['cleanup'] = ['sigkill_after_retirement_rename' => true, 'bounded_batches' => $batch + 1, 'removed_objects_after_restart' => $removed,
            'concurrent_cleanup' => true, 'pin_protected_incremental_and_parent' => true, 'mtime_ignored' => true,
            'controlled_wal_pair_interruption_recovered' => true, 'plan' => $current['last_plan']];
        // 清理后的原full/incremental重新合成并恢复到备份之后的真实WAL目标；原源库已退出。
        $combined = $base . '/combined';
        nativeDatabaseCommand([$tools['root'] . '/bin/pg_combinebackup', '--manifest-checksums=SHA256', '-o', $combined,
            $archive . '/backups/base', $archive . '/backups/incremental'], $dbEnvironment, [$password], $base . '/combine.log', 120);
        nativeDatabaseCommand([$tools['root'] . '/bin/pg_verifybackup', $combined], $dbEnvironment, [$password], $base . '/verify-combined.log');
        file_put_contents($combined . '/recovery.signal', '');
        $listener = stream_socket_server('tcp://127.0.0.1:0', $number, $message);
        expect(is_resource($listener), '不能分配本轮恢复端口');
        $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        fclose($listener);
        $restoreHook = iotRecoveryHook(
            [...$command, 'iot:wal', 'restore', $archive],
            $environment,
            ["'%f'", escapeshellarg(str_replace('%', '%%', $combined) . '/%p')]
        );
        $promotedArchiveHook = iotRecoveryHook(
            [...$command, 'iot:wal', 'archive', $archive],
            $environment,
            [escapeshellarg(str_replace('%', '%%', $combined) . '/%p'), "'%f'"]
        );
        $recovered = new Process([$tools['postgres'], '-D', $combined, '-h', '127.0.0.1', '-p', (string) $port, '-k', '',
            '-c', 'archive_mode=on', '-c', 'archive_command=' . $promotedArchiveHook, '-c', 'restore_command=' . $restoreHook, '-c', 'recovery_target_name=retention_target',
            '-c', 'recovery_target_action=pause', '-c', 'hot_standby=on'], $base, $dbEnvironment, 16777216);
        $deadline = microtime(true) + 90;
        do {
            expect($recovered->running(), '隔离恢复库提前退出：' . $recovered->stderr());
            try {
                $restored = new PDO(
                    'pgsql:host=127.0.0.1;port=' . $port . ';dbname=type_app_test;connect_timeout=1',
                    $dbEnvironment['TYPE_PGSQL_USER'],
                    $password,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                if ($restored->query("SELECT pg_get_wal_replay_pause_state() = 'paused'")->fetchColumn() === true) {
                    break;
                }
                $restored = null;
            } catch (PDOException) {
                $restored = null;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        expect($restored !== null && realpath((string) $restored->query('SHOW data_directory')->fetchColumn()) === $combined, '恢复未在本轮数据根到达暂停点');
        $phases = $restored->query('SELECT phase FROM retention_probe ORDER BY phase')->fetchAll(PDO::FETCH_COLUMN);
        expect($phases === ['base', 'boundary', 'incremental', 'latest', 'obsolete', 'wal-only'], '清理后真实链没有恢复到WAL目标或越过目标');
        foreach (['base', 'incremental', 'boundary', 'latest'] as $id) {
            expect(hash_file('sha256', $archive . '/backups/' . $id . '/backup_manifest') === $records[$id]['manifest_sha256'], '清理/恢复改写了保留备份');
        }
        $report['recovery'] = ['phases' => $phases, 'restored_from_retained_full_incremental_and_wal' => true,
            'target_lsn' => $restoreLsn, 'target_wal' => $lastWal, 'original_manifests_preserved' => true];
        // 用既有日期装置创建真实的未完成删除计划，再让真实提升与新备份登记发生在批次间。
        $beforePromotion = $run(['status']);
        $beforePromotion['backups']['latest']['registered_at'] = time() - 8 * 86400;
        $writeCatalog($beforePromotion);
        $partial = $run(['clean', $tools['root'], '1']);
        expect($partial['removed_objects'] === 1 && is_dir($archive . '/retiring/latest')
            && $run(['status'])['backups']['latest']['state'] === 'retiring', '提升前没有留下真实未完成退役批次');
        // 真实提升产生timeline 2及官方history；不是改名文件或改写manifest的兼容证明。
        expect($restored->query('SELECT pg_promote(true, 60)')->fetchColumn() === true, '本轮隔离恢复库未提升');
        $promotedEnvironment = array_replace($dbEnvironment, ['PGPASSWORD' => $password]);
        nativeDatabaseCommand(
            [$tools['root'] . '/bin/pg_basebackup', '-h', '127.0.0.1', '-p', (string) $port,
            '-U', $dbEnvironment['TYPE_PGSQL_USER'], '-X', 'stream', '-c', 'fast', '--no-password',
            '--manifest-checksums=SHA256', '-D', $archive . '/incoming/promoted'],
            $promotedEnvironment,
            [$password],
            $base . '/promoted-backup.log',
            120
        );
        $restored->exec("INSERT INTO retention_probe VALUES ('promoted')");
        $promotedWal = (string) $restored->query('SELECT pg_walfile_name(pg_current_wal_insert_lsn())')->fetchColumn();
        $restored->query('SELECT pg_switch_wal()')->fetchColumn();
        $deadline = microtime(true) + 90;
        do {
            clearstatcache();
            if (is_file($archive . '/' . $promotedWal . '.sha256') && is_file($archive . '/00000002.history.sha256')) {
                break;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        expect(is_file($archive . '/' . $promotedWal . '.sha256') && is_file($archive . '/00000002.history.sha256'), '提升后没有完成连续归档');
        $run($clean, 'recovery_backup_unregistered_timeline');
        $promoted = $run(['register', 'promoted', $tools['root']]);
        expect($promoted['start_timeline'] === 2, '提升备份没有实际使用timeline 2');
        $historyHash = hash_file('sha256', $archive . '/00000002.history');
        $promotedPlan = $run($clean);
        expect($promotedPlan['plan']['validated_timeline'] === 2 && $promotedPlan['plan']['validated_through_wal'] === $promotedWal
            && hash_file('sha256', $archive . '/00000002.history') === $historyHash, '跨真实history切点的WAL校验未完成或删除了history');
        expect(($run(['status'])['backups']['latest']['state'] ?? 'removed') !== 'active', '新timeline重新校验复活了旧退役备份');
        for ($batch = 0; $promotedPlan['has_more'] && $batch < 100; $batch++) {
            $promotedPlan = $run($clean);
        }
        expect(!$promotedPlan['has_more'] && !isset($run(['status'])['backups']['latest']), '新timeline登记后旧退役进度没有收敛');
        $report['timeline'] = ['real_promotion' => true, 'timeline' => 2, 'history_sha256' => $historyHash,
            'plan' => $promotedPlan['plan'], 'history_preserved' => true, 'history_only_refused_before_plan_and_during_retirement' => true,
            'pending_retirement_paused_until_new_timeline_registration' => true, 'revalidated_without_reactivating_retired_backup' => true];
        $report['status'] = 'passed';
    } finally {
        $restored = null;
        $source = null;
        if ($recovered !== null) {
            $result = $recovered->stop(20);
            file_put_contents($base . '/recovered.log', str_replace($password, '<REDACTED>', $result->stdout . $result->stderr));
            $report['recovered_process_stopped'] = $result->successful();
            expect($result->successful(), '本轮恢复库未正常退出');
        }
        $database?->close();
        $foreignDatabase?->close();
        if (is_file($passwordFile)) {
            expect(unlink($passwordFile), '本轮备份凭据未清理');
        }
        $report['checks'] = $checks;
        $report['check_count'] = count($checks);
        $report['independent_failure_domain'] = false;
        $report['seven_day_window'] = 'not-verified-real-time';
        $report['timeline_scope'] = isset($report['timeline']) ? 'real-timeline-1-to-2-promotion-and-archive-validation' : 'timeline-1-only';
        $report['capacity_claim'] = false;
    }
}

$root = dirname(__DIR__);
$target = $argv[1] ?? '--php';
expect(array_diff(array_slice($argv, 2), ['--no-source']) === [], '用法：php tests/iot-backup-retention.php <--php|原生产物> [--no-source]');
$command = $target === '--php' ? [PHP_BINARY, $root . '/bin/typeapp'] : nativeCommand($target);
$base = $root . '/build/iot-backup-retention-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '不能覆盖本轮备份保留测试目录');
$noSource = in_array('--no-source', $argv, true);
if ($noSource) {
    expect($target !== '--php' && PHP_OS_FAMILY === 'Darwin', '当前无源码策略需要macOS原生应用');
    $runtime = $base . '/runtime';
    (new Type\Build\NativePackage())->create($target, $runtime);
    $built = json_decode(file_get_contents($target . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
    $policy = ['sandbox-exec', '-f', $root . '/tests/fixtures/iot-no-source.sb'];
    foreach (['APP' => $root . '/app', 'PLUGIN' => $root . '/plugin', 'VENDOR' => $root . '/vendor', 'CONFIG' => $root . '/config',
        'COMPILER' => dirname($built['runtime-profile']['ini'], 2), 'COMPOSER' => $root . '/composer.json'] as $role => $path) {
        array_push($policy, '-D', $role . '=' . $path);
    }
    expect(successful([...$policy, PHP_BINARY, '-n', '-r', 'foreach(array_slice($argv,1) as $path){if(@file_get_contents($path)!==false)exit(1);} echo "denied";',
        $root . '/app/main.php', $root . '/plugin/type-core/src/Application.php', $root . '/vendor/autoload.php', $root . '/config/app.php'], $runtime) === 'denied', '源码隔离未生效');
    $command = [...$policy, $runtime . '/run'];
}
$environment = array_replace(getenv(), ['APP_BASE_PATH' => $base, 'DB_DRIVER' => 'invalid-no-database-for-maintenance', 'APP_DEBUG' => 'false']);
$report = ['status' => 'running', 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'native' => $target !== '--php',
    'no_source' => $noSource ? 'kernel-denied-production-and-generated-source' : 'not-verified',
    'binary_sha256' => $target === '--php' ? null : hash_file('sha256', $target), 'scope' => 'backup-retention-and-post-cleanup-recovery'];
echo '本轮保留清理资源：' . $base . "\n";
try {
    iotBackupRetention($command, $environment, $base, $report);
} catch (Throwable $failure) {
    $report['status'] = 'failed';
    $report['failure'] = $failure->getMessage();
    throw $failure;
} finally {
    file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
echo '备份保留与清理后恢复通过：' . $base . "/verification.json\n";
