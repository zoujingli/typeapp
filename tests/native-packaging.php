<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

/** 打包回归使用真正 TypePHP ELF；只改变外部资源清单，不伪造原生执行。 */
function packagingArtifact(string $source, string $directory, array $resources): string
{
    $reader = new Type\Build\ArtifactManifest();
    $manifest = $reader->read($source);
    $target = $directory . '/type-app';
    $input = fopen($source, 'rb');
    $output = fopen($target, 'wb');
    try {
        expect(stream_copy_to_stream($input, $output, $manifest['elf-bytes']) === $manifest['elf-bytes'], '无法复制真实 ELF');
    } finally {
        fclose($input);
        fclose($output);
    }
    chmod($target, 0700);
    $manifest['resource-generation'] = str_repeat('a', 64);
    $manifest['resources'] = $resources;
    $reader->seal($target, $manifest);
    return $target;
}

function packagingRemove(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
}

$root = dirname(__DIR__);
$source = realpath($argv[1] ?? $root . '/build/native/type-app');
expect($source !== false, '需要现有的 TypePHP 原生产物');
$work = $root . '/build/packaging-check-' . bin2hex(random_bytes(6));
expect(mkdir($work, 0700, true), '无法创建独立打包回归目录');
$nativeIni = getenv('TYPE_NATIVE_PHP_INI') ?: $root . '/.cache/embed-runtime/8.5.10/php.ini';
try {
    $artifact = packagingArtifact($source, $work, [['target' => 'message.txt', 'sha256' => hash('sha256', 'approved')]]);
    $generation = $artifact . '.resources/' . str_repeat('a', 64);
    mkdir($generation, 0700, true);
    file_put_contents($generation . '/message.txt', 'tampered');
    [$status, $stdout, $stderr] = execute(['env', 'TYPE_NATIVE_PHP_INI=' . $nativeIni, 'bash', $root . '/tools/make-native-sandbox.sh', $artifact]);
    expect($status !== 0 && $stdout === '' && str_contains($stderr, '部署资源与构建清单不一致'), '打包必须在执行原生产物或探测器前拒绝资源篡改：' . $stdout . $stderr);
    echo "真实 ELF 的资源篡改拒绝验证通过。\n";
    file_put_contents($generation . '/message.txt', 'approved');
    $unsafeIni = $work . '/php.ini';
    file_put_contents($unsafeIni, 'auto_prepend_file="injected.php"' . "\n");
    [$status, $stdout, $stderr] = execute(['env', 'TYPE_NATIVE_PHP_INI=' . $unsafeIni, 'bash', $root . '/tools/make-native-sandbox.sh', $artifact]);
    expect($status !== 0 && $stdout === '' && str_contains($stderr, '运行配置不允许源码注入'), '部署入口必须在 probe 前拒绝源码注入设置：' . $stdout . $stderr);
    echo "部署配置的源码注入拒绝验证通过。\n";
    file_put_contents($unsafeIni, 'mysqli.default_pw="packaging-test-sentinel"' . "\n");
    [$status, $stdout, $stderr] = execute(['env', 'TYPE_NATIVE_PHP_INI=' . $unsafeIni, 'bash', $root . '/tools/make-native-sandbox.sh', $artifact]);
    expect($status !== 0 && $stdout === '' && str_contains($stderr, '运行配置不允许携带凭据') && !str_contains($stderr, 'packaging-test-sentinel'), '部署入口必须拒绝并隐藏配置凭据：' . $stdout . $stderr);
    echo "部署配置的凭据拒绝与错误脱敏验证通过。\n";
    $lock = fopen($artifact . '.lock', 'c+b');
    expect($lock !== false && flock($lock, LOCK_EX), '无法占用原生产物的真实构建锁');
    $out = tmpfile();
    $err = tmpfile();
    $child = null;
    try {
        $child = proc_open(
            ['env', 'TYPE_NATIVE_PHP_INI=' . $unsafeIni, 'bash', $root . '/tools/make-native-sandbox.sh', $artifact],
            [0 => ['file', '/dev/null', 'r'], 1 => $out, 2 => $err],
            $pipes
        );
        expect(is_resource($child), '无法启动并行打包调用');
        usleep(500000);
        expect(proc_get_status($child)['running'], '打包快照必须等待已有构建锁，不能读取正在替换的原生产物');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
        if (is_resource($child)) {
            proc_close($child);
        }
        fclose($out);
        fclose($err);
    }
    echo "打包快照与原生产物构建锁的互斥验证通过。\n";
    $iniSandbox = $work . '/safe-ini';
    expect(mkdir($iniSandbox . '/app', 0700, true), '无法准备中文配置回归');
    successful(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', $work . '/ca.key',
        '-out', $work . '/ca.pem', '-subj', '/CN=Type-Packaging-Test', '-days', '1']);
    file_put_contents($unsafeIni, "; 共享信号模块只在原生运行中加载\nmemory_limit=256M\ndate.timezone=UTC\nopenssl.cafile=\"" . $work . "/ca.pem\"\n");
    [$status, $stdout, $stderr] = execute([PHP_BINARY, '-n', $root . '/tools/package-native-runtime.php', 'ini', $unsafeIni, $iniSandbox]);
    expect($status === 0 && $stdout === '' && $stderr === '' && str_contains(file_get_contents($iniSandbox . '/app/php.ini'), 'memory_limit="256M"'), 'UTF-8 中文注释不能拆出伪造的配置行：' . $stdout . $stderr);
    echo "中文注释与物理换行的部署配置回归通过。\n";
    if (PHP_OS_FAMILY !== 'Linux') {
        echo "当前主机不是 Linux；未执行扩展别名、准确资源集合与运行库装配验收。\n";
    } else {
        $inputIni = file_get_contents($nativeIni);
        expect(is_string($inputIni), 'Linux 打包回归需要已准备的运行 ini');
        $extensionDirectory = (string) ini_get('extension_dir');
        $aliasDirectory = $work . '/extension-alias';
        expect(symlink($extensionDirectory, $aliasDirectory), '无法建立扩展目录别名');
        $inputIni = preg_replace('/^\s*extension_dir\s*=.*$/mi', '', $inputIni);
        file_put_contents($unsafeIni, $inputIni . "\nextension_dir=\"" . $aliasDirectory . "\"\n; packaging-comment-sentinel\nunknown_application_setting=packaging-value-sentinel\n");
        mkdir($work . '/php.d');
        expect(symlink(dirname($nativeIni) . '/probe', $work . '/probe'), '无法复用真实 embed 探测程序');
        file_put_contents($generation . '/not-declared.txt', 'unapproved');
        $old = $artifact . '.resources/' . str_repeat('b', 64);
        mkdir($old);
        file_put_contents($old . '/historical.txt', 'old');
        $sandbox = trim(successful(['env', 'TYPE_NATIVE_PHP_INI=' . $unsafeIni, 'bash', $root . '/tools/make-native-sandbox.sh', $artifact]));
        try {
            expect(file_get_contents($sandbox . '/app/type-app.resources/' . str_repeat('a', 64) . '/message.txt') === 'approved'
                && !file_exists($sandbox . '/app/type-app.resources/' . str_repeat('a', 64) . '/not-declared.txt')
                && !file_exists($sandbox . '/app/type-app.resources/' . str_repeat('b', 64)), '部署包必须只有当前清单的准确资源集合');
            $ini = file_get_contents($sandbox . '/app/php.ini');
            expect(!str_contains($ini, $aliasDirectory) && !str_contains($ini, 'packaging-comment-sentinel') && !str_contains($ini, 'packaging-value-sentinel'), '部署 ini 未规范化扩展或泄露开发配置');
            preg_match_all('/^extension="([^"]+)"$/m', $ini, $extensions);
            expect(count($extensions[1]) > 0, '扩展别名回归没有实际装配共享扩展');
            foreach ($extensions[1] as $extensionPath) {
                expect(is_file($sandbox . $extensionPath) && realpath($extensionPath) === $extensionPath, '部署扩展没有使用存在的真实绝对路径');
            }
            $reader = new Type\Build\ArtifactManifest();
            foreach ($reader->read($artifact)['native-libraries'] as $library) {
                expect(is_file($sandbox . $library['path']) && hash_file('sha256', $sandbox . $library['path']) === $library['sha256'], '部署包丢失了原始 ABI 校验路径');
            }
            echo "真实 ELF 的准确资源集合、开发配置剥离、共享扩展目录别名及运行库原路径装配验证通过。\n";
            foreach ($extensions[1] as $extensionPath) {
                $dependencies = successful(['ldd', $extensionPath]);
                preg_match_all('~=>\s+(/[^\s]+)~', $dependencies, $shared);
                foreach ($shared[1] as $libraryPath) {
                    expect(is_file($sandbox . $libraryPath), '共享扩展的传递依赖 SONAME 路径遗漏：' . basename($extensionPath) . ' -> ' . basename($libraryPath));
                }
            }
            echo "真实共享扩展的传递依赖 SONAME 路径验证通过。\n";
            [$startupStatus, $startupOutput, $startupError] = execute([...nativeCommand($sandbox, true), '--help']);
            expect(
                $startupStatus === 0 && $startupOutput === "用法：type-app [--name 名称] [--repeat 次数] [--help]\n" && $startupError === '',
                '无源码部署的原生启动必须保持公开输出且没有扩展初始化警告：' . $startupOutput . $startupError
            );
            echo "真实 embed 扩展在无源码目录内的零警告启动验证通过。\n";
        } finally {
            packagingRemove($sandbox);
        }
    }
} finally {
    packagingRemove($work);
}
