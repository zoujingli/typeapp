<?php

declare(strict_types=1);

namespace app\common\service;

use Closure;

/** 前端资源安装事务；只修改清单托管路径，恢复记录始终位于公开目录之外。 */
final class FrontendAssets
{
    private string $control;
    private string $generation;
    private array $files;
    private Closure $reader;
    private mixed $lock = null;
    private ?array $transaction = null;
    private array $report = [];

    /**
     * @param array<string,array{bytes:int,sha256:string}> $files 公开目录相对路径及原文摘要。
     * @param Closure(string,int,int):string $reader 有界读取指定资源的原文字节。
     */
    public function __construct(private string $base, array $files, Closure $reader)
    {
        if ($files === [] || !isset($files['index.html']) || count($files) > 1000) {
            throw new FrontendException('前端资源不完整，请先构建前端再生成应用');
        }
        foreach ($files as $path => $file) {
            self::path($path);
            if (!is_array($file) || !is_int($file['bytes'] ?? null) || $file['bytes'] < 0
                || !is_string($file['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $file['sha256'])) {
                throw new FrontendException('前端资源身份无效');
            }
        }
        if (array_sum(array_column($files, 'bytes')) > 67108864
            || count(array_unique(array_map('strtolower', array_keys($files)))) !== count($files)) {
            throw new FrontendException('前端资源超出预算或存在大小写冲突');
        }
        ksort($files);
        $this->files = $files;
        $this->reader = $reader;
        $this->generation = hash('sha256', json_encode($files, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $this->control = $base . '/var/web-install';
    }

    /** 原生应用只读取编译资源；开发入口从本项目已构建的dist取得相同安装契约。 */
    public static function application(string $base, bool $development): self
    {
        $files = [];
        if (!$development) {
            foreach (\Type\Generated\EmbeddedResources::manifest() as $path => $entry) {
                if (str_starts_with($path, 'web/')) {
                    $files[substr($path, 4)] = $entry;
                }
            }
            return new self($base, $files, static fn (string $path, int $offset, int $length): string => \Type\Generated\EmbeddedResources::read('web/' . $path, $offset, $length));
        }
        $source = dirname(__DIR__, 3) . '/web/dist';
        if (!is_dir($source) || is_link($source)) {
            throw new FrontendException('缺少web/dist，请先执行composer web:build');
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isLink() || (!$entry->isDir() && !$entry->isFile())) {
                throw new FrontendException('开发前端资源必须是普通文件');
            }
            if ($entry->isFile()) {
                $path = str_replace('\\', '/', substr($entry->getPathname(), strlen($source) + 1));
                self::path($path);
                $files[$path] = ['bytes' => $entry->getSize(), 'sha256' => (string) hash_file('sha256', $entry->getPathname())];
            }
        }
        return new self($base, $files, static function (string $path, int $offset, int $length) use ($source): string {
            if ($length === 0) {
                return '';
            }
            $bytes = file_get_contents($source . '/' . $path, false, null, $offset, $length);
            if ($bytes === false) {
                throw new FrontendException('无法读取开发前端资源');
            }
            return $bytes;
        });
    }

    /**
     * 只读访问静态资源清单，安装器与HTTP入口共享同一身份。
     * @return array<string,array{bytes:int,sha256:string}> 公开目录相对路径到资源身份。
     */
    public function manifest(): array
    {
        return $this->files;
    }

    /**
     * 安装或预览；失败后尝试恢复旧版本，无法恢复时保留事务记录。
     * @return array<string,mixed> 代次、文件数量、变更明细、冲突和完成状态。
     * @throws FrontendException 资源、权限或并发冲突；调用者可在修复原因后重试。
     */
    public function install(bool $force = false, bool $dryRun = false): array
    {
        try {
            $report = $this->prepare($force, $dryRun);
            return $dryRun ? $report : $this->commit();
        } finally {
            $this->close();
        }
    }

    /**
     * 暂存并复核全部新文件，再允许调用者初始化数据库；互斥锁持续到commit/close。
     * dry-run不创建目录、锁文件或恢复记录。
     * @return array<string,mixed> 预览报告；prepare成功不表示已安装完成。
     * @throws FrontendException 资源不完整、目标冲突、其他安装占用锁或暂存失败。
     */
    public function prepare(bool $force = false, bool $dryRun = false): array
    {
        if ($this->lock !== null) {
            throw new FrontendException('前端安装事务已经打开');
        }
        $this->safe('var/web-install', false);
        $this->safe('public', false);
        if (!$dryRun) {
            $this->directory($this->control);
            $this->safe('var/web-install/install.lock', true);
            $this->lock = fopen($this->control . '/install.lock', 'c+b');
            if ($this->lock === false || !flock($this->lock, LOCK_EX | LOCK_NB)) {
                if (is_resource($this->lock)) {
                    fclose($this->lock);
                }
                $this->lock = null;
                throw new FrontendException('前端安装正在进行，请稍后重试');
            }
            $this->recover();
            $this->cleanStaging();
        } elseif (is_file($this->control . '/transaction.json')) {
            throw new FrontendException('前端安装有待恢复事务，请先运行web:install');
        }
        $installed = $this->installed();
        $paths = array_unique(array_merge(array_keys($this->files), array_keys($installed['files'] ?? [])));
        sort($paths);
        $changes = [];
        $conflicts = [];
        $actions = ['add' => [], 'replace' => [], 'delete' => []];
        foreach ($paths as $path) {
            self::path($path);
            $target = $this->safe('public/' . $path, true);
            if (!$dryRun) {
                $parent = dirname($target);
                while (!is_dir($parent)) {
                    $parent = dirname($parent);
                }
                $targetDevice = stat($parent);
                $stagingDevice = stat($this->control);
                if ($targetDevice === false || $stagingDevice === false || $targetDevice['dev'] !== $stagingDevice['dev']) {
                    throw new FrontendException('前端公开目录与暂存目录必须位于同一文件系统');
                }
            }
            $old = is_file($target) ? (string) hash_file('sha256', $target) : null;
            $new = $this->files[$path]['sha256'] ?? null;
            if ($old === $new) {
                continue;
            }
            if ($old !== null && !$force) {
                $conflicts[] = $path;
            }
            $changes[$path] = ['old' => $old, 'new' => $new];
            $actions[$old === null ? 'add' : ($new === null ? 'delete' : 'replace')][] = $path;
        }
        $this->report = ['generation' => $this->generation, 'dry-run' => $dryRun, 'force' => $force, 'files' => count($this->files), 'changes' => $changes, 'conflicts' => $conflicts, 'actions' => $actions];
        if ($dryRun) {
            return $this->report;
        }
        if ($conflicts !== []) {
            throw new FrontendException('前端文件已存在且内容不同，请使用web:install --dry-run或--force');
        }
        $id = bin2hex(random_bytes(12));
        $work = $this->control . '/' . $id;
        $this->directory($work);
        $this->transaction = ['id' => $id, 'generation' => $this->generation, 'changes' => $changes, 'files' => $this->files];
        foreach ($changes as $path => $change) {
            if ($change['old'] !== null) {
                $backup = $work . '/old/' . $path;
                $this->directory(dirname($backup));
                if (!copy($this->safe('public/' . $path, true), $backup) || hash_file('sha256', $backup) !== $change['old']) {
                    throw new FrontendException('无法保全旧前端资源');
                }
            }
            if ($change['new'] !== null) {
                $destination = $work . '/new/' . $path;
                $this->directory(dirname($destination));
                $stream = fopen($destination, 'xb');
                if ($stream === false) {
                    throw new FrontendException('无法暂存前端资源');
                }
                try {
                    $offset = 0;
                    $bytes = $this->files[$path]['bytes'];
                    while ($offset < $bytes) {
                        $chunk = ($this->reader)($path, $offset, min(65536, $bytes - $offset));
                        if ($chunk === '' || strlen($chunk) > $bytes - $offset || fwrite($stream, $chunk) !== strlen($chunk)) {
                            throw new FrontendException('前端资源读取或写入不完整');
                        }
                        $offset += strlen($chunk);
                    }
                    if (!fflush($stream)) {
                        throw new FrontendException('无法刷新前端暂存资源');
                    }
                } finally {
                    fclose($stream);
                }
                if (hash_file('sha256', $destination) !== $change['new']) {
                    throw new FrontendException('内嵌前端资源摘要不一致');
                }
            }
        }
        return $this->report;
    }

    /**
     * 提交已准备的资源；installed.json是完成边界，后续清理失败不覆盖已完成结果。
     * @return array<string,mixed> 安装报告，ready为true表示已写入完成标记。
     * @throws FrontendException 未准备、外部写入冲突或文件提交失败，调用者仍须close。
     */
    public function commit(): array
    {
        $transaction = $this->transaction;
        if ($transaction === null || $this->lock === null) {
            throw new FrontendException('前端安装尚未准备');
        }
        $work = $this->control . '/' . $transaction['id'];
        $this->writeJson($this->control . '/transaction.json', $transaction);
        foreach ($transaction['changes'] as $path => $change) {
            $target = $this->safe('public/' . $path, true);
            $actual = is_file($target) ? (string) hash_file('sha256', $target) : null;
            if ($actual !== $change['old']) {
                throw new FrontendException('安装期间前端文件被其他写入者改变');
            }
            $this->directory(dirname($target));
            if ($change['new'] === null) {
                if (is_file($target) && !unlink($target)) {
                    throw new FrontendException('无法清理过期前端资源');
                }
            } elseif (!rename($work . '/new/' . $path, $target)) {
                throw new FrontendException('无法替换前端资源');
            }
        }
        $this->writeJson($this->control . '/installed.json', ['generation' => $this->generation, 'transaction' => $transaction['id'], 'files' => $this->files]);
        $this->recover();
        $this->transaction = null;
        return $this->report + ['ready' => true];
    }

    /** 释放安装锁；未提交暂存回收，提交中断按持久记录恢复。 */
    public function close(): void
    {
        try {
            if (is_resource($this->lock)) {
                if (is_file($this->control . '/transaction.json')) {
                    $this->recover();
                } elseif ($this->transaction !== null) {
                    $this->remove($this->control . '/' . $this->transaction['id']);
                }
            }
        } finally {
            $this->transaction = null;
            if (is_resource($this->lock)) {
                flock($this->lock, LOCK_UN);
                fclose($this->lock);
            }
            $this->lock = null;
        }
    }

    /** 普通服务启动只核对，不修复或释放任何资源。 */
    public function verifyInstalled(): void
    {
        if (is_file($this->control . '/transaction.json')) {
            throw new FrontendException('前端安装未完成，请运行web:install恢复');
        }
        $installed = $this->installed();
        if (($installed['generation'] ?? '') !== $this->generation || ($installed['files'] ?? []) !== $this->files) {
            throw new FrontendException('前端尚未安装或版本不匹配，请运行web:install --force');
        }
        foreach ($this->files as $path => $file) {
            $target = $this->safe('public/' . $path, true);
            if (!is_file($target) || hash_file('sha256', $target) !== $file['sha256']) {
                throw new FrontendException('前端资源缺失或已修改，请运行web:install --force');
            }
        }
    }

    /** 返回受清单控制的普通文件；未登记路径始终不可通过静态服务读取。 */
    public function file(string $path): string
    {
        if (!isset($this->files[$path])) {
            throw new FrontendException('未声明的前端文件');
        }
        return $this->safe('public/' . $path, true);
    }

    /** 恢复只触及事务登记路径；遇到未知内容停止，保留原始恢复材料。 */
    private function recover(): void
    {
        $journal = $this->control . '/transaction.json';
        if (!is_file($journal)) {
            return;
        }
        $this->safe('var/web-install/transaction.json', true);
        if (filesize($journal) > 1048576) {
            throw new FrontendException('前端恢复记录过大');
        }
        $transaction = json_decode((string) file_get_contents($journal), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($transaction) || !preg_match('/^[a-f0-9]{24}$/D', $transaction['id'] ?? '')
            || !is_array($transaction['changes'] ?? null) || count($transaction['changes']) > 2000) {
            throw new FrontendException('前端恢复记录无效');
        }
        foreach ($transaction['changes'] as $path => $change) {
            self::path($path);
            if (!is_array($change) || !array_key_exists('old', $change) || !array_key_exists('new', $change)) {
                throw new FrontendException('前端恢复条目无效');
            }
            foreach ([$change['old'], $change['new']] as $digest) {
                if ($digest !== null && (!is_string($digest) || !preg_match('/^[a-f0-9]{64}$/D', $digest))) {
                    throw new FrontendException('前端恢复摘要无效');
                }
            }
        }
        $work = $this->safe('var/web-install/' . $transaction['id'], false);
        $installed = $this->installed();
        if (($installed['transaction'] ?? '') !== $transaction['id']) {
            foreach (array_reverse($transaction['changes'], true) as $path => $change) {
                self::path($path);
                $target = $this->safe('public/' . $path, true);
                $actual = is_file($target) ? (string) hash_file('sha256', $target) : null;
                if (!in_array($actual, [$change['old'], $change['new']], true)) {
                    throw new FrontendException('前端恢复发现未登记的文件变动');
                }
                if ($change['old'] !== null) {
                    $backup = $this->safe('var/web-install/' . $transaction['id'] . '/old/' . $path, true);
                    $restore = $work . '/restore';
                    if (!is_file($backup) || hash_file('sha256', $backup) !== $change['old'] || !copy($backup, $restore)
                        || hash_file('sha256', $restore) !== $change['old'] || !rename($restore, $target)) {
                        throw new FrontendException('前端旧版本恢复失败');
                    }
                } elseif (is_file($target) && !unlink($target)) {
                    throw new FrontendException('无法回收未完成前端安装');
                }
            }
        }
        // 先删除日志：回收目录中断只留下无引用暂存，不会再次尝试从不完整备份恢复。
        if (!unlink($journal)) {
            throw new FrontendException('无法完成前端恢复记录');
        }
        $this->remove($work);
    }

    /** @return array<string,mixed> 已安装清单；不存在时表示首次安装。 */
    private function installed(): array
    {
        $path = $this->safe('var/web-install/installed.json', true);
        if (!is_file($path)) {
            return [];
        }
        if (filesize($path) > 1048576) {
            throw new FrontendException('已安装前端清单过大');
        }
        $state = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($state) || !is_array($state['files'] ?? null) || count($state['files']) > 1000) {
            throw new FrontendException('已安装前端清单无效');
        }
        foreach (array_keys($state['files']) as $file) {
            self::path($file);
        }
        return $state;
    }

    /** 原子更新私有JSON文件，不将半份清单作为安装完成证据。 */
    private function writeJson(string $path, array $value): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(6));
        $bytes = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        try {
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || !rename($temporary, $path)) {
                throw new FrontendException('无法写入前端安装记录');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** 核对每级目录，拒绝链接、设备文件及把目录当作文件覆盖。 */
    private function safe(string $relative, bool $file): string
    {
        $parts = explode('/', $relative);
        $path = $this->base;
        foreach ($parts as $index => $part) {
            $path .= '/' . $part;
            if (is_link($path) || (file_exists($path) && (($index === count($parts) - 1 && $file) ? !is_file($path) : !is_dir($path)))) {
                throw new FrontendException('前端目标路径存在链接或类型冲突');
            }
        }
        return $path;
    }

    /** 创建本次安装需要的目录；调用者已验证其父路径。 */
    private function directory(string $path): void
    {
        $mode = $path === $this->control || str_starts_with($path, $this->control . '/') ? 0700 : 0755;
        if (!is_dir($path) && !mkdir($path, $mode, true) && !is_dir($path)) {
            throw new FrontendException('无法创建前端安装目录');
        }
    }

    /** 持有安装锁且恢复完成后，回收准备阶段中断留下的无引用事务目录。 */
    private function cleanStaging(): void
    {
        foreach (new \FilesystemIterator($this->control, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if (preg_match('/^[a-f0-9]{24}$/D', $entry->getFilename()) && $entry->isDir() && !$entry->isLink()) {
                $this->remove($entry->getPathname());
            }
        }
    }

    /** 只回收专用事务目录，不跟随任何符号链接。 */
    private function remove(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                $this->remove($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }

    /** 前端路径为跨平台安全的相对普通文件名，私有文件不能进入托管范围。 */
    private static function path(string $path): void
    {
        if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9._/-]*$~D', $path)
            || preg_match('/\.(?:php[0-9]?|phtml|phar|inc|so|dylib|dll|pem|key)$/iD', $path)
            || preg_match('~(?:^|/)(?:auth\.json|id_(?:rsa|ed25519|ecdsa|dsa))$~iD', $path)) {
            throw new FrontendException('前端路径无效');
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || str_starts_with($part, '.') || str_ends_with($part, '.')
                || preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/iD', $part)) {
                throw new FrontendException('前端路径包含不允许的片段');
            }
        }
    }
}
