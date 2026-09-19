<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;
use Type\Runtime\ProcessSignals;

/** 开发模式的完整进程重载；不在长驻进程内重新声明业务类，也不用于生产运行。 */
final class DevelopmentWatcher
{
    private mixed $child = null;
    private ?array $exit = null;
    private bool $stopping = false;

    /** 直到停止信号；无效输入保持旧进程，已退出子进程只在下一次修改后重试。 */
    public function run(string $configuration, array $arguments = ['serve']): void
    {
        if ($this->child !== null || !array_is_list($arguments) || count($arguments) > 128) {
            throw new RuntimeException('watch实例或命令参数无效');
        }
        foreach ($arguments as $argument) {
            if (!is_string($argument) || str_contains($argument, "\0")) {
                throw new RuntimeException('watch参数必须是不含NUL的字符串');
            }
        }
        $this->stopping = false;
        $project = (new BuildProject())->read($configuration);
        $root = $project['root'];
        $type = $root . '/vendor/bin/type';
        if (!is_file($type)) {
            throw new RuntimeException('watch需要项目内已安装的type命令');
        }
        if (PHP_OS_FAMILY !== 'Windows' && (!function_exists('posix_setsid') || !function_exists('pcntl_exec') || !function_exists('posix_kill'))) {
            throw new RuntimeException('Unix watch需要POSIX/PCNTL来监督完整子进程组');
        }
        $signals = new ProcessSignals();
        $signals->attach(function (): void {
            $this->stopping = true;
        });
        $attempt = '';
        $observed = '';
        $changedAt = hrtime(true) / 1e9 - 1;
        $lastError = '';
        try {
            while (!$this->stopping) {
                $signals->dispatch();
                if ($this->child !== null && !$this->running()) {
                    $code = $this->exit['exitcode'] ?? -1;
                    $this->close();
                    fwrite(STDERR, '[type-dev] child exited (' . $code . "); waiting for a new change\n");
                }
                try {
                    // 先读取当前声明，才能从已删除/改名的旧输入路径恢复。
                    $next = (new BuildProject())->read($configuration);
                    if ($next['root'] !== $root) {
                        throw new RuntimeException('watch运行期间不能改变项目根，请重新启动');
                    }
                    $fingerprint = $this->fingerprint($next);
                    if ($fingerprint !== $observed) {
                        $observed = $fingerprint;
                        $changedAt = hrtime(true) / 1e9;
                    }
                    if ($fingerprint !== $attempt && hrtime(true) / 1e9 - $changedAt >= 0.3) {
                        $attempt = $fingerprint;
                        $before = $fingerprint;
                        // 每次使用新PHP进程加载生成器，避免给新工具源码标记旧实现的代次。
                        $prepared = (new BuildEnvironment())->run([PHP_BINARY, $type, 'prepare', $configuration], $root, (new BuildPlatform())->phpEnvironment(), 30, fn (): bool => $this->stopping);
                        $generation = json_decode($prepared, true, 512, JSON_THROW_ON_ERROR);
                        if (!is_string($generation['generation'] ?? null)) {
                            throw new RuntimeException('开发准备没有返回完整代次');
                        }
                        if ((new BuildProject())->read($configuration) !== $next || $this->fingerprint($next) !== $before) {
                            $attempt = '';
                            continue;
                        }
                        $check = $next['settings']['development']['check'] ?? null;
                        if ($check !== null) {
                            if (!is_array($check) || !array_is_list($check) || $check === [] || count($check) > 32) {
                                throw new RuntimeException('development.check必须是明确的参数列表');
                            }
                            foreach ($check as $argument) {
                                if (!is_string($argument) || str_contains($argument, "\0")) {
                                    throw new RuntimeException('开发检查参数无效');
                                }
                            }
                            try {
                                (new BuildEnvironment())->run([PHP_BINARY, $type, 'dev', $configuration, ...$check], $root, getenv(), 15, fn (): bool => $this->stopping);
                            } catch (\Throwable $error) {
                                throw new RuntimeException('开发应用检查失败；旧进程保持运行，请单独执行type dev检查命令定位', 0, $error);
                            }
                        }
                        if ((new BuildProject())->read($configuration) !== $next || $this->fingerprint($next) !== $before) {
                            $attempt = '';
                            continue;
                        }
                        $this->stopChild();
                        $attempt = $before;
                        $observed = $before;
                        if ($this->stopping) {
                            break;
                        }
                        $command = [PHP_BINARY, $type, 'dev', $configuration, ...$arguments];
                        if (PHP_OS_FAMILY !== 'Windows') {
                            $launcher = 'if (posix_setsid() === -1) { exit(126); } pcntl_exec($argv[1], array_slice($argv, 2)); exit(127);';
                            $command = [PHP_BINARY, '-r', $launcher, ...$command];
                        }
                        $this->child = proc_open(
                            $command,
                            [0 => ['file', (new BuildPlatform())->nullDevice(), 'r'], 1 => STDOUT, 2 => STDERR],
                            $pipes,
                            $root,
                            null,
                            ['bypass_shell' => true, 'create_process_group' => PHP_OS_FAMILY === 'Windows']
                        );
                        if (!is_resource($this->child)) {
                            $this->child = null;
                            throw new RuntimeException('无法启动开发子进程');
                        }
                        $this->exit = null;
                        fwrite(STDOUT, '[type-dev] started generation=' . $generation['generation'] . "\n");
                    }
                    $lastError = '';
                } catch (\Throwable $error) {
                    if ($this->stopping) {
                        break;
                    }
                    $message = $error->getMessage();
                    if ($message !== $lastError) {
                        fwrite(STDERR, '[type-dev] input rejected: ' . $message . "\n");
                        $lastError = $message;
                    }
                    if (str_contains($message, '无法正常停止')) {
                        throw $error;
                    }
                }
                usleep(200000);
            }
        } finally {
            try {
                $this->stopChild();
            } finally {
                $signals->close();
            }
        }
    }

    /** 只检测明确源码/配置；dotenv哈希只留在观察进程内，不进入生成或构建身份。 */
    private function fingerprint(array $project): string
    {
        $root = $project['root'];
        $settings = $project['settings'];
        $paths = [$project['file'], $root . '/composer.json', $root . '/composer.lock'];
        foreach (array_merge($settings['sources'] ?? [], $settings['development']['watch'] ?? []) as $source) {
            $paths[] = $root . '/' . $source;
        }
        foreach (['entry', 'models', 'routing'] as $key) {
            if (is_string($settings[$key] ?? null)) {
                $paths[] = $root . '/' . $settings[$key];
            }
        }
        foreach (['entry', 'prepare'] as $key) {
            if (is_string($settings['development'][$key] ?? null)) {
                $paths[] = $root . '/' . $settings['development'][$key];
            }
        }
        foreach ($settings['config']['files'] ?? [] as $file) {
            $paths[] = $root . '/' . $file;
        }
        $hashes = [];
        foreach (array_unique($paths) as $path) {
            $real = realpath($path);
            if ($real === false || !BuildPlatform::contains($root, $real) || is_link($path)) {
                throw new RuntimeException('watch输入缺失或越界');
            }
            $files = is_file($real) ? [$real] : new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $entry) {
                $file = is_string($entry) ? $entry : $entry->getPathname();
                if (!is_file($file) || (!is_file($real) && !in_array(pathinfo($file, PATHINFO_EXTENSION), ['php', 'json', 'cc', 'cpp', 'h'], true))) {
                    continue;
                }
                if (is_link($file) || !BuildPlatform::contains($root, (string) realpath($file))) {
                    throw new RuntimeException('watch源码不能越界或使用链接');
                }
                $hash = hash_file('sha256', $file);
                if ($hash === false) {
                    throw new RuntimeException('watch输入读取失败');
                }
                $hashes[$file] = $hash;
                if (count($hashes) > 10000) {
                    throw new RuntimeException('watch输入超过文件数量上限');
                }
            }
        }
        $runtimeRoot = getenv('APP_BASE_PATH') ?: $root;
        $dotenv = rtrim($runtimeRoot, '/\\') . '/.env';
        $hashes['runtime-env'] = is_file($dotenv) ? hash_file('sha256', $dotenv) : 'missing';
        ksort($hashes);
        return hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR));
    }

    private function running(): bool
    {
        if ($this->child === null || $this->exit !== null) {
            return false;
        }
        $state = proc_get_status($this->child);
        if (!$state['running']) {
            $this->exit = $state;
            return false;
        }
        return true;
    }

    private function stopChild(): void
    {
        if ($this->child === null) {
            return;
        }
        if ($this->running()) {
            if (PHP_OS_FAMILY === 'Windows') {
                if (!function_exists('sapi_windows_generate_ctrl_event') || !sapi_windows_generate_ctrl_event(PHP_WINDOWS_EVENT_CTRL_BREAK, (int) proc_get_status($this->child)['pid'])) {
                    $this->forceStop();
                    throw new RuntimeException('无法正常停止Windows开发进程，控制台停止能力不可用');
                }
            } else {
                proc_terminate($this->child, 15);
            }
            $until = hrtime(true) / 1e9 + 5;
            while ($this->running() && hrtime(true) / 1e9 < $until) {
                usleep(10000);
            }
            if ($this->running()) {
                $this->forceStop();
                throw new RuntimeException('无法正常停止开发进程，已强制收回；为避免重复工作，不自动启动下一代');
            }
            if (($this->exit['exitcode'] ?? -1) !== 0 || ($this->exit['signaled'] ?? false)) {
                $this->close();
                throw new RuntimeException('无法正常停止开发进程；停止返回非正常状态，不自动启动下一代');
            }
        }
        $this->close();
    }

    private function close(): void
    {
        if ($this->child !== null) {
            proc_close($this->child);
        }
        $this->child = null;
        $this->exit = null;
    }

    /** 强制清理只针对本模块创建的进程组，结果不能当成正常重载。 */
    private function forceStop(): void
    {
        if ($this->child === null) {
            return;
        }
        $pid = (int) proc_get_status($this->child)['pid'];
        if (PHP_OS_FAMILY === 'Windows') {
            $null = (new BuildPlatform())->nullDevice();
            $kill = proc_open(
                [(string) getenv('SystemRoot') . '/System32/taskkill.exe', '/PID', (string) $pid, '/T', '/F'],
                [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
                $pipes,
                null,
                null,
                ['bypass_shell' => true]
            );
            if (is_resource($kill)) {
                proc_close($kill);
            }
        } else {
            posix_kill(-$pid, 9);
        }
        proc_terminate($this->child, 9);
        $until = hrtime(true) / 1e9 + 2;
        while ($this->running() && hrtime(true) / 1e9 < $until) {
            usleep(10000);
        }
        if ($this->running()) {
            throw new RuntimeException('无法正常停止开发进程，强制清理也未确认退出');
        }
        $this->close();
    }
}
