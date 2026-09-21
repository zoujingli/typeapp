<?php

declare(strict_types=1);

use Type\Testing\Process;
use Type\Build\BuildPlatform;

/**
 * 返回通过正反探针验证的Unix发布启动命令；源码/SDK不可读，PHP/编译器不可执行。
 *
 * @param list<string> $dataDirectories 由调用者新建的build下专用运行数据目录。
 * @param string|null $processInfo Linux长运行应用使用本轮新文件接收实际进程身份，供准确停止。
 * @return list<string> 调用者追加业务参数并负责运行进程的停止。
 * @throws RuntimeException 平台、路径或真实隔离探针不满足要求。
 */
function sandboxPackageCommand(string $root, string $package, array $dataDirectories = [], ?string $processInfo = null): array
{
    if (PHP_OS_FAMILY === 'Linux') {
        return linuxPackageCommand($root, $package, $dataDirectories, $processInfo);
    }
    expect(PHP_OS_FAMILY === 'Darwin', '发布sandbox检查仅用于macOS');
    $root = realpath($root);
    $package = realpath($package);
    expect(is_string($root) && is_string($package) && str_starts_with($package, $root . '/build/'), '需要主仓build下的专用发布目录');
    $quote = static function (string $path): string {
        expect(preg_match('/[\x00-\x1f\x7f]/', $path) === 0, '隔离路径包含控制字符');
        return '"' . addcslashes($path, '\\"') . '"';
    };
    $readExceptions = '(require-not (subpath ' . $quote($package) . '))';
    foreach ($dataDirectories as $directory) {
        $resolved = realpath($directory);
        expect(is_string($resolved) && str_starts_with($resolved, $root . '/build/'), '运行数据必须属于专用build子目录');
        $readExceptions .= '(require-not (subpath ' . $quote($resolved) . '))';
    }
    $sdkRoot = getenv('PHP_HOME') ?: dirname(PHP_BINARY, 2);
    $account = posix_getpwuid(posix_geteuid());
    expect(is_array($account) && isset($account['dir']), '无法识别测试账号的工具目录');
    $dependencyFilters = '';
    foreach (['/opt/homebrew', '/usr/local', '/Library/Developer', $account['dir'] . '/Runtime', $sdkRoot] as $directory) {
        $dependencyFilters .= '(subpath ' . $quote($directory) . ')';
    }
    $profile = '(version 1)(allow default)'
        . '(deny file-read-data (require-all (subpath ' . $quote($root) . ')' . $readExceptions . '))'
        // 发布目录可能位于开发工具父目录内，例外仍只限已校验的包和显式数据目录。
        . '(deny file-read-data (require-all (require-any ' . $dependencyFilters . ')' . $readExceptions . '))'
        . '(deny process-exec (require-all (subpath ' . $quote($root) . ') (require-not (subpath ' . $quote($package) . '))))'
        . '(deny process-exec (require-all (require-any ' . $dependencyFilters . '(literal ' . $quote(PHP_BINARY) . '))'
        . '(require-not (subpath ' . $quote($package) . '))))';
    $prefix = ['/usr/bin/sandbox-exec', '-p', $profile];
    // 先证明同一策略可读发布文件，避免策略语法错误使所有负向探针假通过。
    $allowed = (new Process([...$prefix, '/bin/dd', 'if=' . $package . '/release.json', 'of=/dev/null', 'bs=1', 'count=1']))->wait(3);
    expect($allowed->successful(), '隔离策略未允许读取受信发布文件');
    $sdk = $sdkRoot . '/lib/libphp.dylib';
    foreach ([$root . '/app/main.php', $root . '/vendor/autoload.php', $sdk] as $source) {
        expect(is_file($source) && is_readable($source), '隔离负向探针的原文件不存在或控制端不可读');
        $denied = (new Process([...$prefix, '/bin/dd', 'if=' . $source, 'of=/dev/null', 'bs=1', 'count=1']))->wait(3);
        expect($denied->exitCode === 1 && !$denied->timedOut, '隔离未阻断源码/Composer/SDK读取');
    }
    $compiler = (new Process(['/usr/bin/clang', '--version']))->wait(3);
    expect($compiler->successful(), '控制端编译器不可用，不能证明执行被隔离拒绝');
    foreach ([[PHP_BINARY, '-v'], ['/usr/bin/clang', '--version']] as $tool) {
        $denied = (new Process([...$prefix, ...$tool]))->wait(3);
        expect(!$denied->successful() && !$denied->timedOut, '隔离环境仍可执行PHP/编译器');
    }
    return [...$prefix, $package . '/run'];
}

/**
 * Linux仅挂载系统运行文件、已验证发布包和本轮数据，不挂载源码或SDK。
 *
 * @param list<string> $dataDirectories 调用方持有的独立运行数据目录。
 * @return list<string> 根namespace设置器在任何应用执行前切回原UID/GID并清空能力。
 */
function linuxPackageCommand(string $root, string $package, array $dataDirectories, ?string $processInfo = null): array
{
    expect(PHP_OS_FAMILY === 'Linux' && posix_geteuid() > 0, 'Linux隔离应用必须使用非root测试账号');
    $root = realpath($root);
    $package = realpath($package);
    $bwrap = realpath(getenv('TYPE_BWRAP_BINARY') ?: '');
    expect(is_string($root) && is_string($package) && str_starts_with($package, $root . '/build/')
        && is_string($bwrap) && is_executable($bwrap) && BuildPlatform::format($bwrap) === 'ELF', 'Linux发布隔离需要本轮包和明确原生bubblewrap');
    $preserved = ['APP_BASE_PATH', 'APP_ENV', 'APP_DEBUG', 'APP_CACHE_ENABLED', 'APP_NAME',
        'APP_LISTEN', 'APP_PORT', 'APP_ALLOWED_HOSTS', 'APP_API_TOKEN', 'DB_DRIVER', 'DB_HOST', 'DB_PORT', 'DB_DATABASE',
        'DB_USERNAME', 'DB_PASSWORD', 'DB_SQLITE_FILE', 'TYPE_APP_RELEASE_SHA256', 'TYPE_TEMPLATE_EXPECTED_MESSAGE',
        'TYPE_MODEL_DRIVER', 'TYPE_ROLLOUT_APP', 'TYPE_SQLITE_FILE', 'TYPE_REDIS_HOST', 'TYPE_REDIS_PORT', 'TYPE_ROLLOUT_CACHE_HOST', 'TYPE_ROLLOUT_CACHE_PORT',
        'TYPE_MYSQL_HOST', 'TYPE_MYSQL_PORT', 'TYPE_MYSQL_DATABASE', 'TYPE_MYSQL_USER', 'TYPE_MYSQL_PASSWORD',
        'TYPE_PGSQL_HOST', 'TYPE_PGSQL_PORT', 'TYPE_PGSQL_DATABASE', 'TYPE_PGSQL_USER', 'TYPE_PGSQL_PASSWORD',
        'TYPE_HTTP_LISTEN', 'TYPE_HTTP_PORT'];
    $prefix = ['sudo', '-n', '--preserve-env=' . implode(',', $preserved)];
    $privilege = $prefix;
    $prefix = [...$prefix, $bwrap, '--die-with-parent', '--new-session', '--unshare-pid', '--unshare-ipc', '--unshare-uts',
        '--cap-drop', 'ALL', '--cap-add', 'CAP_SETUID', '--cap-add', 'CAP_SETGID', '--cap-add', 'CAP_SETPCAP',
        '--ro-bind', '/usr', '/usr', '--symlink', 'usr/bin', '/bin', '--symlink', 'usr/sbin', '/sbin'];
    foreach (['/lib', '/lib64'] as $library) {
        if (is_dir($library)) {
            array_push($prefix, '--ro-bind', $library, $library);
        }
    }
    array_push($prefix, '--proc', '/proc', '--dev', '/dev', '--perms', '1777', '--tmpfs', '/tmp');
    // /usr 需要提供运行库，但不能把控制端 PHP、编译器和其他工具带入发布进程。
    // 将 /usr/bin 覆盖为临时目录后，只重新开放 namespace 设置器和探针所需的固定工具。
    // 这样 PHP_BINARY 位于 /usr/bin 时也能被真实阻断，而不是仅依赖 PATH 隐藏。
    array_push($prefix, '--tmpfs', '/usr/bin');
    foreach (['sh', 'setpriv', 'cat', 'id'] as $task_program) {
        $task_path = '/usr/bin/' . $task_program;
        expect(is_file($task_path) && is_executable($task_path), '隔离探针工具不存在：' . $task_path);
        array_push($prefix, '--ro-bind', $task_path, $task_path);
    }
    $mounts = [$package => true];
    foreach ($dataDirectories as $directory) {
        $resolved = realpath($directory);
        expect(is_string($resolved) && str_starts_with($resolved, $root . '/build/'), '隔离运行数据必须属于本轮build子目录');
        $mounts[$resolved] = false;
    }
    foreach ($mounts as $path => $readonly) {
        $cursor = '';
        foreach (explode('/', trim(dirname($path), '/')) as $part) {
            $cursor .= '/' . $part;
            if (!in_array($cursor, ['/usr', '/lib', '/lib64', '/proc', '/dev', '/tmp'], true)) {
                array_push($prefix, '--perms', '0755', '--dir', $cursor);
            }
        }
        array_push($prefix, $readonly ? '--ro-bind' : '--bind', $path, $path);
    }
    array_push($prefix, '--setenv', 'PATH', '/usr/bin:/bin', '--setenv', 'TMPDIR', '/tmp');
    foreach (['COMPOSER_AUTH', 'SSH_AUTH_SOCK', 'GH_TOKEN', 'GITHUB_TOKEN', 'PHP_HOME', 'PHPX_HOME', 'PHPRC', 'PHP_INI_SCAN_DIR', 'LD_PRELOAD', 'LD_LIBRARY_PATH'] as $name) {
        array_push($prefix, '--unsetenv', $name);
    }
    array_push(
        $prefix,
        '--chdir',
        '/',
        '--remount-ro',
        '/',
        '--',
        '/usr/bin/setpriv',
        '--reuid',
        (string) posix_geteuid(),
        '--regid',
        (string) posix_getegid(),
        '--clear-groups',
        '--bounding-set=-all',
        '--inh-caps=-all',
        '--ambient-caps=-all',
        '--no-new-privs'
    );
    $probe = (new Process([...$prefix, '/bin/sh', '-c', 'test -r "$1/release.json" && test ! -e "$2/app/main.php" && test ! -e "$2/vendor/autoload.php" && test ! -e "$3" && ! command -v php && ! command -v gcc && ! command -v g++ && id -u && cat /proc/self/status', 'probe', $package, $root, PHP_BINARY]))->wait(10);
    expect($probe->successful() && str_starts_with($probe->stdout, (string) posix_geteuid() . "\n"), 'Linux隔离未正确开放发布或阻断源码/工具：' . $probe->stderr . $probe->stdout);
    foreach (['CapInh', 'CapPrm', 'CapEff', 'CapBnd', 'CapAmb'] as $capability) {
        expect(preg_match('/^' . $capability . ':\s+0+$/m', $probe->stdout) === 1, '隔离应用仍持有Linux能力');
    }
    expect(preg_match('/^NoNewPrivs:\s+1$/m', $probe->stdout) === 1, '隔离应用没有禁用提权');
    $run = ['/bin/sh', '-c', 'cd "$1" && shift && exec "$@"', 'run', $package, $package . '/run'];
    if ($processInfo !== null) {
        expect((new BuildPlatform())->absolute($processInfo) && BuildPlatform::contains($root . '/build', $processInfo)
            && is_dir(dirname($processInfo)) && !file_exists($processInfo) && !is_link($processInfo), '进程记录必须使用本轮build下新文件');
        \Type\Build\BuildLock::path($processInfo);
        $controlled = array_slice($prefix, count($privilege));
        array_splice($controlled, 1, 0, ['--as-pid-1', '--info-fd', '3']);
        // FD在sudo之后打开，PID不混入应用输出；文件只含进程/namespace身份。
        return [...$privilege, '/bin/sh', '-c', 'set -C; umask 022; exec 3>"$1"; shift; exec "$@"', 'process-info', $processInfo, ...$controlled, ...$run];
    }
    return [...$prefix, ...$run];
}

/** 向已核验的Linux隔离应用发SIGTERM，再等待设置器返回真实退出码；超时仍计为失败。 */
function stopPackageProcess(Process $process, string $package, ?string $processInfo = null, float $seconds = 5.0): \Type\Testing\ProcessResult
{
    if (PHP_OS_FAMILY !== 'Linux' || $processInfo === null || !$process->running()) {
        return $process->stop($seconds);
    }
    expect(is_file($processInfo) && !is_link($processInfo), '隔离设置器没有提供进程身份');
    $information = json_decode(file_get_contents($processInfo), true, 32, JSON_THROW_ON_ERROR);
    $pid = $information['child-pid'] ?? null;
    expect(is_int($pid) && $pid > 1, '隔离应用PID无效');
    $status = file_get_contents('/proc/' . $pid . '/status');
    $command = explode("\0", file_get_contents('/proc/' . $pid . '/cmdline'));
    expect(preg_match('/^Uid:\s+([0-9]+)/m', $status, $matches) === 1 && (int) $matches[1] === posix_geteuid()
        && in_array(realpath($package) . '/bin/app', $command, true), '不能向身份不符的进程发送停止信号');
    expect(posix_kill($pid, SIGTERM), '无法停止本轮原生应用');
    return $process->wait($seconds);
}
