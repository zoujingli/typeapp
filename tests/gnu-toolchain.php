<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\BuildEnvironment;
use Type\Build\BuildIdentity;
use Type\Build\BuildPlatform;

expect(PHP_OS_FAMILY === 'Linux' && posix_geteuid() > 0 && ($argc === 2 || ($argc === 3 && $argv[2] === '--native')), '需要非root Linux和显式私有sysroot；可选--native验证实际编译缓存');
$sysroot = realpath($argv[1]);
$include = $sysroot === false ? false : realpath($sysroot . '/usr/include');
expect($sysroot !== false && $sysroot !== '/' && $include !== false && BuildPlatform::contains($sysroot, $include)
    && fileowner($sysroot) === posix_geteuid() && is_writable($include), '仅操作当前用户持有的私有sysroot头文件目录');
$phpHome = getenv('PHP_HOME') ?: '';
$phpxHome = getenv('PHPX_HOME') ?: '';
$environment = new BuildEnvironment();
$child = $environment->environment($phpHome, $phpxHome);
$actual = trim($environment->run(['g++', '-print-sysroot'], $phpHome, $child));
expect(realpath($actual) === $sysroot, '指定目录并非实际编译器sysroot');
$identity = bin2hex(random_bytes(6));
$base = dirname(__DIR__) . '/build/gnu-toolchain-' . $identity;
expect(mkdir($base, 0700), '无法创建本轮工具链身份验收目录');
$header = $include . '/type_build_probe_' . $identity . '.h';
$handle = fopen($header, 'x');
expect(is_resource($handle), '不能覆盖已有SDK头文件');
fwrite($handle, "#define TYPE_BUILD_PROBE 1\n");
fclose($handle);
try {
    $first = $environment->fingerprint($phpHome, $phpxHome, []);
    $firstIdentity = (new BuildIdentity())->create(['toolchain' => $first['files']], $first);
    $stable = $environment->fingerprint($phpHome, $phpxHome, []);
    expect((new BuildIdentity())->create(['toolchain' => $stable['files']], $stable)['id'] === $firstIdentity['id'], '相同GNU输入的身份不稳定');
    foreach (['gcc', 'g++'] as $compiler) {
        $driver = $first['gnu-compilers'][$compiler]['driver'] ?? '';
        expect($driver !== '' && BuildPlatform::format($driver) === 'ELF' && in_array($driver, $first['files'], true), '实际GNU驱动没有纳入身份');
        expect($first['gnu-compilers'][$compiler]['sysroot'] === $sysroot, '实际sysroot记录不匹配');
    }
    $mtime = filemtime($header);
    $modified = file_put_contents($header, "#define TYPE_BUILD_PROBE 2\n");
    expect($modified !== false, '无法修改本轮独有的探针头文件');
    touch($header, $mtime);
    $second = $environment->fingerprint($phpHome, $phpxHome, []);
    $secondIdentity = (new BuildIdentity())->create(['toolchain' => $second['files']], $second);
    $record = ['sysroot' => $sysroot, 'first' => $firstIdentity['id'], 'second' => $secondIdentity['id'],
        'header-tracked' => in_array(realpath($header), $first['files'], true),
        'stdlib-tracked' => in_array(realpath($include . '/stdlib.h'), $first['files'], true)];
    file_put_contents($base . '/verification.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    expect($firstIdentity['id'] !== $secondIdentity['id'], '实际sysroot头文件已变化，但构建身份未改变');
    expect($record['header-tracked'] && $record['stdlib-tracked'], '实际参与编译的头文件没有进入身份');
    $outside = $base . '/outside.h';
    file_put_contents($outside, '#define OUTSIDE_PROBE 1');
    $escape = $include . '/type_build_escape_' . $identity . '.h';
    expect(symlink($outside, $escape), '无法创建本轮越界链接探针');
    try {
        $rejected = false;
        try {
            $environment->fingerprint($phpHome, $phpxHome, []);
        } catch (RuntimeException $failure) {
            $rejected = str_contains($failure->getMessage(), '链接超出声明目录');
        }
        expect($rejected, '新增GCC目录发现放宽了原有链接越界检查');
    } finally {
        unlink($escape);
    }
    $record['checks'] = ['stable-identity', 'mtime-preserving-header-change', 'actual-C-and-C++-drivers', 'actual-sysroot', 'header-escape-rejected'];
    if ($argc === 3) {
        $root = dirname(__DIR__);
        $consumer = $base . '/consumer';
        expect(mkdir($consumer, 0700), '无法创建独立GNU缓存消费项目');
        file_put_contents($consumer . '/composer.json', json_encode(['name' => 'type-tests/gnu-cache', 'require' => ['zoujingli/type-runtime' => '1.0.x-dev'],
            'require-dev' => ['zoujingli/type-build' => '1.0.x-dev'], 'config' => ['vendor-dir' => $root . '/vendor', 'allow-plugins' => false]], JSON_THROW_ON_ERROR));
        copy($root . '/composer.lock', $consumer . '/composer.lock');
        copy($root . '/toolchain.lock.json', $consumer . '/toolchain.lock.json');
        file_put_contents($consumer . '/main.php', '<?php declare(strict_types=1); function main(): void { \\Type\\Generated\\BuildIdentity::verifyRuntime(); echo "gnu-cache-ok\\n"; }');
        file_put_contents($consumer . '/build.json', json_encode(['name' => 'gnu-cache-test', 'entry' => 'main.php', 'output' => 'build/app',
            'build-directory' => 'build/compiler', 'compiler' => ['optimize' => 2, 'debug' => false, 'jobs' => 2]], JSON_THROW_ON_ERROR));
        $command = [PHP_BINARY, $root . '/vendor/bin/type', 'build', $consumer . '/build.json'];
        $reports = [];
        foreach (['first' => 1, 'repeat' => 1, 'changed' => 2, 'restored' => 1] as $step => $value) {
            file_put_contents($header, '#define TYPE_BUILD_PROBE ' . $value . "\n");
            touch($header, $mtime);
            [$exit, $stdout, $stderr] = execute($command, $consumer);
            file_put_contents($base . '/' . $step . '.log', $stdout . $stderr);
            expect($exit === 0, '真实GNU构建失败，见：' . $base . '/' . $step . '.log');
            expect(successful([$consumer . '/build/app']) === "gnu-cache-ok\n", '真实GNU产物未执行或运行库身份错误');
            $build = json_decode((string) file_get_contents($consumer . '/build/app.build.json'), true, 512, JSON_THROW_ON_ERROR);
            $reports[$step] = ['build-id' => $build['build-id'], 'sha256' => $build['sha256'], 'cache-hit' => $build['cache']['hit']];
        }
        expect(!$reports['first']['cache-hit'] && $reports['repeat']['cache-hit'] && !$reports['changed']['cache-hit'] && $reports['restored']['cache-hit'], 'SDK变更后仍复用旧产物或相同输入不能复用');
        expect($reports['first']['build-id'] === $reports['repeat']['build-id'] && $reports['first']['build-id'] !== $reports['changed']['build-id']
            && $reports['first']['build-id'] === $reports['restored']['build-id'] && $reports['first']['sha256'] === $reports['restored']['sha256'], 'SDK恢复后的构建身份或真实二进制不一致');
        $record['native-builds'] = $reports;
    }
    file_put_contents($base . '/verification.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
} finally {
    expect(unlink($header), '无法回收本轮独有探针，不删除其他SDK文件');
}
echo '真实私有sysroot头文件变更会改变构建身份：' . $base . "/verification.json\n";
