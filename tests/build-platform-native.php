<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\ArtifactManifest;
use Type\Build\BuildPlatform;
use Type\Testing\Process;

$root = dirname(__DIR__);
expect($argc === 1 || ($argc === 2 && $argv[1] === '--sdk-change'), '用法：php tests/build-platform-native.php [--sdk-change]');
$consumer = $root . '/build/platform-consumer-' . bin2hex(random_bytes(6));
expect(mkdir($consumer, 0700), '无法创建独立平台构建验证项目');
$sdkChange = ($argv[1] ?? '') === '--sdk-change';
$originalSdk = getenv('PHPX_HOME');
$originalHeader = null;
if ($sdkChange) {
    expect(in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true) && is_string($originalSdk), 'SDK内容变化验收需要明确的Unix PHPX SDK');
    $originalHeader = hash_file('sha256', $originalSdk . '/include/phpx.h');
    expect(mkdir($consumer . '/sdk', 0700), '无法创建本轮专用SDK副本');
    foreach (['include', 'lib', 'src', 'thirdparty'] as $part) {
        successful(['cp', '-R', $originalSdk . '/' . $part, $consumer . '/sdk/' . $part]);
    }
    putenv('PHPX_HOME=' . $consumer . '/sdk');
}
$composer = ['name' => 'type-app/platform-consumer', 'require' => ['zoujingli/type-runtime' => '1.0.x-dev'],
    'require-dev' => ['zoujingli/type-build' => '1.0.x-dev'], 'config' => ['vendor-dir' => '../../vendor', 'allow-plugins' => false]];
file_put_contents($consumer . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
copy($root . '/composer.lock', $consumer . '/composer.lock');
copy($root . '/toolchain.lock.json', $consumer . '/toolchain.lock.json');
$digestCode = PHP_OS_FAMILY === 'Darwin' ? <<<'PHP'
    if (($argv[1] ?? '') === 'digest' || ($argv[1] ?? '') === 'digest-nul') {
        $path = (string) ($argv[2] ?? '');
        if ($argv[1] === 'digest-nul') { $path .= "\0suffix"; }
        echo type_app_native_file_sha256($path) . "\n";
        return;
    }
PHP : '';
file_put_contents($consumer . '/main.php', str_replace('    // PLATFORM_DIGEST_TEST', $digestCode, <<<'PHP'
<?php

declare(strict_types=1);

function main(int $argc, array $argv): void
{
    // PLATFORM_DIGEST_TEST
    if (($argv[1] ?? '') === 'verify-library') {
        try {
            \Type\Generated\BuildIdentity::verifyRuntime([(string) $argv[2] => (string) $argv[3]]);
        } catch (\RuntimeException $error) {
            fwrite(STDERR, $error->getMessage() . "\n");
            exit(23);
        }
        return;
    }
    if (($argv[1] ?? '') === 'verify-deployment') {
        \Type\Generated\BuildIdentity::verifyDeployment();
        echo "deployment-ok\n";
        return;
    }
    \Type\Generated\BuildIdentity::verifyRuntime();
    echo "本机AOT与实际加载运行库身份通过。\n";
}
PHP));
$configuration = ['name' => 'type-platform', 'entry' => 'main.php', 'output' => 'build/native/type-app', 'build-directory' => 'build/native/compiler',
    'compiler' => ['optimize' => 2, 'debug' => false, 'jobs' => 2]];
file_put_contents($consumer . '/build.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
$command = [PHP_BINARY, $root . '/vendor/bin/type', $consumer . '/build.json'];
[$status, $stdout, $stderr] = execute($command, $consumer);
file_put_contents($consumer . '/compile.log', $stdout . $stderr);
expect($status === 0, '本机构建失败，完整日志保留：' . $consumer . '/compile.log' . "\n" . substr($stderr, -4000));
$artifact = (new BuildPlatform())->output($consumer . '/build/native/type-app');
$buildReport = json_decode(file_get_contents($artifact . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
$runtimeIni = $buildReport['runtime-profile']['ini'];
expect(is_file($runtimeIni) && is_dir(dirname($runtimeIni) . '/php.d'), '产物自己的运行配置缺失');
// 所有原生探针使用同一构建选出的模块；控制器和重复编译保留自己的完整 PHP 配置。
$runtimeEnvironment = getenv();
$runtimeEnvironment['PHPRC'] = $runtimeIni;
$runtimeEnvironment['PHP_INI_SCAN_DIR'] = dirname($runtimeIni) . '/php.d';
$reader = new ArtifactManifest();
$manifest = $reader->read($artifact);
expect(($manifest['runtime']['os'] ?? '') === PHP_OS_FAMILY, '平台构建记录了错误OS');
$deployment = (new Process([$artifact, 'verify-deployment'], null, $runtimeEnvironment))->wait(60);
$deploymentPassed = $deployment->successful() && $deployment->stdout === "deployment-ok\n" && $deployment->stderr === '';
$deploymentEvidence = ['scope' => 'deployment-audit', 'passed' => $deploymentPassed, 'artifact-sha256' => hash_file('sha256', $artifact),
    'exit-code' => $deployment->exitCode, 'timed-out' => $deployment->timedOut, 'output-exceeded' => $deployment->outputExceeded,
    'signal' => $deployment->signal, 'stdout-hex' => bin2hex($deployment->stdout), 'stderr-hex' => bin2hex($deployment->stderr)];
$deploymentJson = json_encode($deploymentEvidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
file_put_contents($consumer . '/verification.json', $deploymentJson . "\n");
expect($deploymentPassed, '显式部署完整审计失败：' . $deploymentJson);
if (PHP_OS_FAMILY === 'Darwin') {
    $fixture = $consumer . '/digest-数据.bin';
    foreach (['', 'abc', str_repeat("\0\xff\x80hash\n", 16385)] as $payload) {
        file_put_contents($fixture, $payload);
        expect(successful([$artifact, 'digest', $fixture], null, $runtimeEnvironment) === hash_file('sha256', $fixture) . "\n", '系统SHA-256与PHP字节摘要不一致');
    }
    expect(successful([$artifact, 'digest', $consumer . '/missing'], null, $runtimeEnvironment) === "\n", '读取失败未显式返回无效摘要');
    expect(successful([$artifact, 'digest', $consumer], null, $runtimeEnvironment) === "\n", '目录不能被当成空文件校验');
    expect(successful([$artifact, 'digest-nul', $fixture], null, $runtimeEnvironment) === "\n", 'NUL路径不能被截断后校验');
    $fifo = $consumer . '/digest.fifo';
    expect(posix_mkfifo($fifo, 0600), '无法创建非普通文件校验夹具');
    try {
        $fifoResult = (new Process([$artifact, 'digest', $fifo], null, $runtimeEnvironment))->wait(2.0);
        expect($fifoResult->successful() && $fifoResult->stdout === "\n" && $fifoResult->stderr === '', 'FIFO摘要必须立即拒绝，不能阻塞启动');
    } finally {
        unlink($fifo);
    }
    echo successful([PHP_BINARY, $root . '/tests/native-startup.php', $artifact], null, $runtimeEnvironment);
}
[$nativeStatus, $nativeOutput, $nativeError] = execute([$artifact], null, $runtimeEnvironment);
expect(
    $nativeStatus === 0 && $nativeOutput === "本机AOT与实际加载运行库身份通过。\n" && $nativeError === '',
    '真实原生运行或加载身份失败：' . $nativeOutput . $nativeError
);
$library = $manifest['native-libraries'][0];
$copyDirectory = $consumer . '/library-copy';
expect(mkdir($copyDirectory, 0700), '无法创建本轮运行库身份夹具目录');
$copy = $copyDirectory . '/' . basename($library['path']);
expect(copy($library['path'], $copy), '无法复制本轮运行库身份夹具');
try {
    if (PHP_OS_FAMILY !== 'Linux') {
        $wrongPath = (new Process([$artifact, 'verify-library', $library['name'], $copy], null, $runtimeEnvironment))->wait(10);
        expect($wrongPath->exitCode === 23 && $wrongPath->stdout === ''
            && str_contains($wrongPath->stderr, '运行库未按声明路径实际加载'), '相同字节但未实际加载的运行库路径没有拒绝');
    }
    $stream = fopen($copy, 'r+b');
    expect(is_resource($stream), '无法准备运行库的等长字节篡改');
    $firstByte = fread($stream, 1);
    expect(strlen($firstByte) === 1 && fseek($stream, 0) === 0 && fwrite($stream, chr(ord($firstByte) ^ 1)) === 1, '无法修改运行库夹具字节');
    fclose($stream);
    $wrongBytes = (new Process([$artifact, 'verify-library', $library['name'], $copy], null, $runtimeEnvironment))->wait(10);
    expect($wrongBytes->exitCode === 23 && $wrongBytes->stdout === ''
        && str_contains($wrongBytes->stderr, '运行库身份不一致'), '等长但摘要不匹配的运行库没有拒绝');
} finally {
    unlink($copy);
    rmdir($copyDirectory);
}
$first = json_decode(file_get_contents($artifact . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
expect($first['cache']['hit'] === false, '独立本机构建不能靠旧缓存代替编译');
successful($command, $consumer);
$again = json_decode(file_get_contents($artifact . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
expect($again['cache']['hit'] === true && $again['sha256'] === $first['sha256'], '相同平台构建没有按完整输入身份复用');
if ($sdkChange) {
    $header = $consumer . '/sdk/include/phpx.h';
    expect(isset($first['identity']['description']['inputs']['native'][realpath($header)]), '实际SDK头文件没有进入编译身份');
    $bytes = file_get_contents($header);
    $mtime = filemtime($header);
    $records = [];
    try {
        foreach (['changed' => $bytes . "\n// typeapp controlled SDK content probe\n", 'restored' => $bytes] as $step => $contents) {
            expect(file_put_contents($header, $contents) === strlen($contents) && touch($header, $mtime), '无法准备保持mtime的SDK内容变化');
            successful($command, $consumer);
            expect(successful([$artifact], null, $runtimeEnvironment) === "本机AOT与实际加载运行库身份通过。\n", 'SDK变化后的产物没有实际运行');
            $built = json_decode(file_get_contents($artifact . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
            $records[$step] = ['build_id' => $built['build-id'], 'artifact_sha256' => $built['sha256'], 'cache_hit' => $built['cache']['hit']];
        }
        expect(!$records['changed']['cache_hit'] && $records['changed']['build_id'] !== $first['build-id']
            && $records['restored']['cache_hit'] && $records['restored']['build_id'] === $first['build-id']
            && $records['restored']['artifact_sha256'] === $first['sha256'], 'SDK内容变化或恢复没有控制真实编译缓存');
        $children = [new Process($command, $consumer), new Process($command, $consumer)];
        $completed = 0;
        $rejected = 0;
        try {
            foreach ($children as $index => $child) {
                $result = $child->wait(300);
                file_put_contents($consumer . '/sdk-concurrent-' . $index . '.log', $result->stdout . $result->stderr);
                if ($result->successful()) {
                    $completed++;
                } else {
                    expect($result->exitCode === 1 && !$result->timedOut && !$result->outputExceeded
                        && $result->stdout === '' && trim($result->stderr) === '构建失败：构建锁等待超时', '并发候选并非成功或明确的有界锁拒绝');
                    $rejected++;
                }
            }
        } finally {
            foreach ($children as $child) {
                $child->stop();
            }
        }
        expect($completed >= 1 && $completed + $rejected === 2, '所有并发候选都未完成');
        expect(hash_file('sha256', $artifact) === $first['sha256'], '并发构建破坏了原生产物');
        $records['concurrent_writers'] = 2;
        $records['concurrent_completed'] = $completed;
        $records['concurrent_lock_rejected'] = $rejected;
        $records['original_sdk_unchanged'] = hash_file('sha256', $originalSdk . '/include/phpx.h') === $originalHeader;
        expect($records['original_sdk_unchanged'], '原SDK输入发生变化');
        file_put_contents($consumer . '/sdk-cache-verification.json', json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    } finally {
        file_put_contents($header, $bytes);
        touch($header, $mtime);
        putenv('PHPX_HOME=' . $originalSdk);
    }
}
echo '本机构建、运行库实际加载和二次缓存通过：' . $artifact . "\n";
