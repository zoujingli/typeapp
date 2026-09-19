<?php

declare(strict_types=1);

/** 捕获两个输出流，避免子进程因管道写满而互相等待。 */
function execute(array $command, ?string $directory = null): array
{
    $stdout = tmpfile();
    $stderr = tmpfile();
    if ($stdout === false || $stderr === false) {
        throw new RuntimeException('无法创建进程输出缓冲');
    }
    try {
        $process = proc_open($command, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => $stdout, 2 => $stderr], $pipes, $directory);
        if (!is_resource($process)) {
            throw new RuntimeException('无法运行验证进程');
        }
        $status = proc_close($process);
        rewind($stdout);
        rewind($stderr);

        return [$status, stream_get_contents($stdout), stream_get_contents($stderr)];
    } finally {
        fclose($stdout);
        fclose($stderr);
    }
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** 仅删除调用者本轮创建的目录；Windows Git 对象先解除只读属性，不跟随链接。 */
function removeTestDirectory(string $directory): void
{
    expect(is_dir($directory) && !is_link($directory), '测试清理需要已拥有的真实目录');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $path = $entry->getPathname();
        if ($entry->isDir() && !$entry->isLink()) {
            expect(rmdir($path), '无法清理测试子目录');
        } else {
            if (PHP_OS_FAMILY === 'Windows' && !$entry->isLink()) {
                expect(chmod($path, 0600), '无法解除测试文件只读属性');
            }
            expect(unlink($path), '无法清理测试文件');
        }
    }
    expect(rmdir($directory), '无法清理测试目录');
}

/** 为隔离外部服务的测试哨兵生成当前平台可执行入口；PHP 正文不含开始标签。 */
function writeTestPhpCommand(string $path, string $code): void
{
    if (PHP_OS_FAMILY === 'Windows') {
        static $launcher = null;
        if ($launcher === null) {
            $compiler = getenv('SystemRoot') . '/Microsoft.NET/Framework64/v4.0.30319/csc.exe';
            expect(is_file($compiler), 'Windows 测试命令需要系统 .NET Framework C# 编译器');
            $build = dirname(__DIR__) . '/build/test-launcher-' . bin2hex(random_bytes(6));
            expect(mkdir($build, 0700), '无法创建测试入口编译目录');
            try {
                successful([realpath($compiler), '/nologo', '/target:exe', '/optimize+', '/out:command.exe', realpath(__DIR__ . '/fixtures/PhpCommand.cs')], $build);
                $launcher = file_get_contents($build . '/command.exe');
                expect(is_string($launcher) && $launcher !== '', '无法读取测试入口');
            } finally {
                removeTestDirectory($build);
            }
        }
        expect(file_put_contents($path . '.php', "<?php\n" . $code) !== false, '无法写入测试命令');
        expect(file_put_contents($path . '.php-binary', PHP_BINARY) !== false, '无法写入测试 PHP 路径');
        expect(file_put_contents($path . '.exe', $launcher) !== false, '无法写入 Windows 测试入口');
    } else {
        expect(file_put_contents($path, "#!/usr/bin/env php\n<?php\n" . $code) !== false && chmod($path, 0755), '无法写入测试入口');
    }
}

/** 前置本轮哨兵目录，保留 Windows 不区分大小写的 Path，并避免重复环境键。 */
function testCommandEnvironment(string $directory, array $environment): array
{
    $original = '';
    foreach ($environment as $key => $value) {
        if ($key === 'PATH' || (PHP_OS_FAMILY === 'Windows' && strcasecmp($key, 'PATH') === 0)) {
            $original = $value;
            unset($environment[$key]);
        }
    }
    $environment['PATH'] = $directory . PATH_SEPARATOR . $original;
    return $environment;
}

/** 本地 Git 夹具 URL；Windows 盘符属于路径，不能被解释为远端主机。 */
function testGitFileUrl(string $path): string
{
    $path = str_replace('\\', '/', $path);
    return 'file://' . (preg_match('/^[A-Za-z]:\//D', $path) === 1 ? '/' : '') . $path;
}

/** @param list<array<string, mixed>> $menus @return list<string> */
function menuPaths(array $menus): array
{
    $paths = [];
    foreach ($menus as $menu) {
        if (isset($menu['path']) && is_string($menu['path'])) {
            $paths[] = $menu['path'];
        }
        if (isset($menu['children']) && is_array($menu['children'])) {
            $paths = [...$paths, ...menuPaths($menu['children'])];
        }
    }
    return $paths;
}

/** @param list<array<string, mixed>> $menus @return list<string> */
function menuLeafPaths(array $menus): array
{
    $paths = [];
    foreach ($menus as $menu) {
        if (isset($menu['children']) && is_array($menu['children']) && $menu['children'] !== []) {
            $paths = [...$paths, ...menuLeafPaths($menu['children'])];
            continue;
        }
        if (isset($menu['path']) && is_string($menu['path'])) {
            $paths[] = $menu['path'];
        }
    }
    return $paths;
}

function successful(array $command, ?string $directory = null): string
{
    [$status, $stdout, $stderr] = execute($command, $directory);
    expect($status === 0, '验证进程失败：' . implode(' ', $command) . "\n" . $stdout . $stderr);

    return $stdout;
}

/** @return array<int,array{parent: int, state: string}> 保留僵尸状态；可按PPID筛选，观察失败不能等同于进程归零。 */
function unixProcessStates(?int $parent = null): array
{
    expect(($parent === null || $parent > 0) && in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true), '进程观察需要明确Unix进程身份');
    $process = new \Type\Testing\Process(['ps', '-A', '-o', 'pid=,ppid=,stat=']);
    try {
        $result = $process->wait(2);
        expect($result->successful() && trim($result->stdout) !== '', '不能确认子进程清单：进程观察失败');
        $children = [];
        foreach (explode("\n", trim($result->stdout)) as $line) {
            expect(preg_match('/^\s*([1-9][0-9]*)\s+([0-9]+)\s+(\S+)\s*$/D', $line, $match) === 1, '进程观察格式不符');
            if ($parent === null || (int) $match[2] === $parent) {
                $children[(int) $match[1]] = ['parent' => (int) $match[2], 'state' => $match[3]];
            }
        }
        ksort($children, SORT_NUMERIC);
        return $children;
    } finally {
        $process->stop();
    }
}

function nativeCommand(string $target, bool $isolated = false, array $environmentKeys = []): array
{
    $target = realpath($target);
    expect($target !== false, '原生产物或隔离目录不存在');
    if ($isolated) {
        expect(PHP_OS_FAMILY === 'Linux', 'chroot隔离验收仅适用于Linux，其他平台须运行各自的干净部署测试');
        expect(is_file($target . '/app/type-app'), '隔离目录缺少命令产物');
        expect(is_file($target . '/app/php.ini') && is_dir($target . '/app/php.d'), '隔离目录缺少明确运行配置');
        expect(!is_file($target . '/usr/local/bin/php') && !is_dir($target . '/app/vendor'), '隔离目录不应包含 PHP CLI 或业务源码');
        $prefix = [];
        if (posix_geteuid() !== 0) {
            $prefix = ['sudo', '-n'];
            if ($environmentKeys !== []) {
                $prefix[] = '--preserve-env=' . implode(',', $environmentKeys);
            }
        }

        return [...$prefix, 'env', 'PHPRC=/app/php.ini', 'PHP_INI_SCAN_DIR=/app/php.d', 'chroot', $target, '/app/type-app'];
    }
    if (!class_exists(\Type\Build\BuildPlatform::class)) {
        // 格式校验不应要求主仓未使用的数据库或Redis扩展；独立控制器复用同一轻量校验类。
        $verifier = dirname(__DIR__) . '/plugin/type-build/src/BuildPlatform.php';
        expect(is_file($verifier), '独立验收控制器缺少原生格式校验类');
        require_once $verifier;
    }
    (new \Type\Build\BuildPlatform())->assertArtifact($target);

    $nativeIni = getenv('TYPE_NATIVE_PHP_INI');
    if ($nativeIni !== false) {
        expect(is_file($nativeIni) && is_dir(dirname($nativeIni) . '/php.d'), '显式原生运行配置缺失');
        expect(PHP_OS_FAMILY !== 'Windows', 'Windows显式运行配置须由测试进程环境传入PHPRC和PHP_INI_SCAN_DIR，不能依赖Unix env命令');
        return ['env', 'PHPRC=' . $nativeIni, 'PHP_INI_SCAN_DIR=' . dirname($nativeIni) . '/php.d', $target];
    }

    return [$target];
}

/** 镜像仅由本项目的 scratch 打包脚本产生，测试不拉取外部镜像。 */
function cleanRuntimeCommand(string $image): array
{
    expect(preg_match('/^type-app-clean-test:[0-9-]+$/D', $image) === 1, '隔离镜像不属于本次验证');
    return ['docker', 'run', '--rm', '--pull=never', '--network=none', '--read-only', '--cap-drop=ALL', '--security-opt=no-new-privileges', '--tmpfs', '/tmp:rw,nosuid,nodev,size=16m', $image];
}
