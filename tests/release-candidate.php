<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/tools/distribution/Process.php';

use Type\Build\ArtifactManifest;
use Type\Build\BuildPlatform;
use Type\Build\NativePackage;
use Type\Build\PackageArchive;
use Type\Testing\Process;
use TypeApp\Distribution\Process as Reports;

// 先生成最终归档，再对解包后的同一载荷执行三库行为；finish不重新编译或归档。
$root = dirname(__DIR__);
$operation = $argv[1] ?? '';
$version = getenv('TYPE_RELEASE_VERSION') ?: '';
expect(preg_match('/^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-rc\.[1-9][0-9]*)?$/D', $version) === 1, '候选验收需要明确版本');
$platform = match (PHP_OS_FAMILY) {
    'Darwin' => 'macos-arm64', 'Windows' => 'windows-x64',
    'Linux' => in_array(php_uname('m'), ['aarch64', 'arm64'], true) ? 'linux-arm64' : 'linux-x64',
    default => throw new RuntimeException('候选平台不受支持'),
};
$work = $root . '/build/release-candidate';
$stateFile = $work . '/preparation.json';
$artifact = (new BuildPlatform())->output($root . '/build/app/type-app');
if ($operation === 'prepare') {
    expect(!file_exists($work), '候选目录已存在；重试须复用已封存候选，不能覆盖');
    mkdir($work, 0700);
    mkdir($work . '/attachments', 0700);
    $identity = (new ArtifactManifest())->read($artifact);
    expect($identity['version'] === substr($version, 1) && isset($identity['embedded-resources']['web/index.html']), '候选版本或内嵌前端缺失');
    $created = (new NativePackage())->create($artifact, $work . '/package', $root . '/.env.example');
    $filename = 'typeapp-iot-' . substr($version, 1) . '-' . $platform . (PHP_OS_FAMILY === 'Windows' ? '.zip' : '.tar.gz');
    $archive = (new PackageArchive())->create($created['directory'], $work . '/attachments/' . $filename, $created['manifest-sha256']);
    mkdir($work . '/unpacked release', 0700);
    if (PHP_OS_FAMILY === 'Windows') {
        (new PharData($archive['file']))->extractTo($work . '/unpacked release');
    } else {
        successful(['tar', '-xzf', $archive['file'], '-C', $work . '/unpacked release'], $root);
    }
    (new NativePackage())->verify($work . '/unpacked release', $created['manifest-sha256']);
    Reports::report($stateFile, ['protocol' => 1, 'source' => Reports::output(['git', 'rev-parse', 'HEAD'], $root),
        'version' => $version, 'platform' => $platform, 'archive' => $filename, 'sha256' => $archive['sha256'], 'bytes' => $archive['bytes'],
        'manifest-sha256' => $created['manifest-sha256'], 'build-id' => $created['build-id'],
        'artifact-sha256' => hash_file('sha256', $artifact), 'embedded-resources' => $identity['embedded-resources'],
        'frontend-manifest-sha256' => hash_file('sha256', (string) getenv('TYPE_FRONTEND_MANIFEST'))]);
} elseif ($operation === 'test') {
    $driver = $argv[2] ?? '';
    expect(in_array($driver, ['mysql', 'pgsql', 'sqlite'], true), '候选测试需要明确数据库');
    $record = json_decode((string) file_get_contents($stateFile), true, 64, JSON_THROW_ON_ERROR);
    $environment = getenv();
    $environment['TYPE_RELEASE_PACKAGE'] = $work . '/unpacked release';
    $environment['TYPE_RELEASE_PACKAGE_SHA256'] = $record['manifest-sha256'];
    $environment['TYPE_PACKAGE_DRIVER'] = $driver;
    $database = null;
    $dist = $root . '/web/dist';
    $hiddenDist = $work . '/unavailable-dist';
    expect(is_dir($dist) && !file_exists($hiddenDist) && rename($dist, $hiddenDist), '无法移走构建端dist以验证内嵌资源');
    try {
        if (count($argv) === 5) {
            require __DIR__ . '/native-database.php';
            $tools = $driver === 'sqlite' ? [] : NativeDatabase::tools($driver, $driver === 'mysql' ? $argv[3] : $argv[4]);
            $database = new NativeDatabase($work . '/database-' . $driver, $driver, $tools);
            $environment = array_replace($environment, $database->environment());
        }
        $result = (new Process([PHP_BINARY, $root . '/tests/native-package.php', $artifact], $root, $environment))->wait(600);
        file_put_contents($work . '/' . $driver . '.log', $result->stdout . $result->stderr);
        // PHP致命错误可能写入stdout；失败时同时保留退出状态和内层原因。
        $status = ['exit-code' => $result->exitCode, 'timed-out' => $result->timedOut,
            'output-exceeded' => $result->outputExceeded, 'signal' => $result->signal];
        Reports::report($work . '/' . $driver . '-process.json', $status);
        expect($result->successful(), '最终归档的三库部署验收失败 ' . json_encode($status, JSON_THROW_ON_ERROR)
            . '，见build/release-candidate/' . $driver . ".log：\n" . $result->stdout . $result->stderr);
    } finally {
        try {
            $database?->close();
        } finally {
            expect(!file_exists($dist) && rename($hiddenDist, $dist), '无法恢复本轮前端构建资源');
        }
    }
    Reports::report($work . '/' . $driver . '.json', ['status' => 'passed', 'driver' => $driver, 'archive-sha256' => $record['sha256'],
        'artifact-sha256' => $record['artifact-sha256'], 'frontend-source-removed' => true,
        'log-sha256' => hash_file('sha256', $work . '/' . $driver . '.log')]);
} elseif ($operation === 'finish') {
    $record = json_decode((string) file_get_contents($stateFile), true, 64, JSON_THROW_ON_ERROR);
    foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
        $check = json_decode((string) file_get_contents($work . '/' . $driver . '.json'), true, 32, JSON_THROW_ON_ERROR);
        expect($check['status'] === 'passed' && $check['driver'] === $driver && $check['archive-sha256'] === $record['sha256']
            && $check['artifact-sha256'] === $record['artifact-sha256'], '三库没有验收同一最终候选');
        $record['acceptance'][$driver] = $check;
    }
    expect(hash_file('sha256', $work . '/attachments/' . $record['archive']) === $record['sha256'], '验收后归档改变');
    (new NativePackage())->verify($work . '/unpacked release', $record['manifest-sha256']);
    $record['status'] = 'passed';
    Reports::report($work . '/attachments/' . $platform . '.json', $record);
} else {
    throw new InvalidArgumentException('用法：php tests/release-candidate.php <prepare|test 驱动 [MySQL目录 PostgreSQL目录]|finish>');
}
echo '发布候选' . $operation . '通过：' . $platform . "\n";
