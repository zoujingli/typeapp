<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/tools/distribution/Process.php';
require dirname(__DIR__) . '/tools/release/Plan.php';
require dirname(__DIR__) . '/tools/release/Candidate.php';

use Type\Build\ArtifactManifest;
use Type\Build\BuildPlatform;
use Type\Build\SingleProgram;
use Type\Testing\Process;
use TypeApp\Distribution\Process as Reports;
use TypeApp\Release\Candidate;

// 先保存最终可执行文件，再以该附件的同一字节执行三库行为；finish 不重新编译。
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
    $filename = Candidate::filename($version, $platform);
    $created = (new SingleProgram())->create($artifact, $work . '/attachments/' . $filename);
    Reports::report($stateFile, ['protocol' => 2, 'source' => Reports::output(['git', 'rev-parse', 'HEAD'], $root),
        'delivery' => 'single-executable', 'version' => $version, 'platform' => $platform,
        'file' => $filename, 'sha256' => $created['sha256'], 'bytes' => $created['bytes'], 'build-id' => $created['build-id'],
        'system-libraries' => $created['system-libraries'],
        'sdk-manifest-sha256' => hash_file('sha256', (string) getenv('TYPE_STATIC_RUNTIME')),
        'artifact-sha256' => hash_file('sha256', $artifact), 'embedded-resources' => $identity['embedded-resources'],
        'frontend-manifest-sha256' => hash_file('sha256', (string) getenv('TYPE_FRONTEND_MANIFEST'))]);
} elseif ($operation === 'test') {
    $driver = $argv[2] ?? '';
    expect(in_array($driver, ['mysql', 'pgsql', 'sqlite'], true), '候选测试需要明确数据库');
    $record = json_decode((string) file_get_contents($stateFile), true, 64, JSON_THROW_ON_ERROR);
    expect(($record['protocol'] ?? null) === 2 && $record['file'] === Candidate::filename($version, $platform), '不能把历史目录包作为单程序候选');
    $candidate = $work . '/attachments/' . $record['file'];
    (new SingleProgram())->verify($candidate, $record['sha256']);
    $environment = getenv();
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
        $result = (new Process([PHP_BINARY, $root . '/tests/native-single-program.php', $candidate], $root, $environment))->wait(600);
        file_put_contents($work . '/' . $driver . '.log', $result->stdout . $result->stderr);
        // PHP致命错误可能写入stdout；失败时同时保留退出状态和内层原因。
        $status = ['exit-code' => $result->exitCode, 'timed-out' => $result->timedOut,
            'output-exceeded' => $result->outputExceeded, 'signal' => $result->signal];
        Reports::report($work . '/' . $driver . '-process.json', $status);
        expect($result->successful(), '最终单程序的三库部署验收失败 ' . json_encode($status, JSON_THROW_ON_ERROR)
            . '，见build/release-candidate/' . $driver . ".log：\n" . $result->stdout . $result->stderr);
    } finally {
        try {
            $database?->close();
        } finally {
            expect(!file_exists($dist) && rename($hiddenDist, $dist), '无法恢复本轮前端构建资源');
        }
    }
    expect(hash_file('sha256', $candidate) === $record['sha256'], '三库验收期间候选字节改变');
    Reports::report($work . '/' . $driver . '.json', ['status' => 'passed', 'driver' => $driver,
        'artifact-sha256' => $record['sha256'], 'frontend-source-removed' => true, 'single-executable-only' => true,
        'log-sha256' => hash_file('sha256', $work . '/' . $driver . '.log')]);
} elseif ($operation === 'materials') {
    $record = json_decode((string) file_get_contents($stateFile), true, 64, JSON_THROW_ON_ERROR);
    $sdk = (string) getenv('TYPE_STATIC_RUNTIME');
    expect(hash_file('sha256', $sdk) === $record['sdk-manifest-sha256'], '重建材料必须来自候选构建使用的同一 SDK');
    $name = 'typeapp-rebuild-' . substr($version, 1) . '-' . $platform . '.zip';
    $command = [PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3', $root . '/tools/release/rebuild-materials.py',
        '--sdk', dirname($sdk), '--output', $work . '/attachments/' . $name, '--version', $version, '--platform', $platform];
    if (PHP_OS_FAMILY === 'Windows') {
        $command[] = '--dependencies';
        $command[] = (string) getenv('TYPE_REBUILD_DEPENDENCIES');
    }
    $result = (new Process($command, $root))->wait(900);
    file_put_contents($work . '/materials.log', $result->stdout . $result->stderr);
    expect($result->successful(), '重建材料封存失败：' . $result->stdout . $result->stderr);
    $record['rebuild'] = json_decode((string) file_get_contents($work . '/attachments/' . substr($name, 0, -4) . '.json'), true, 64, JSON_THROW_ON_ERROR);
    Reports::report($stateFile, $record);
} elseif ($operation === 'finish') {
    $record = json_decode((string) file_get_contents($stateFile), true, 64, JSON_THROW_ON_ERROR);
    foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
        $check = json_decode((string) file_get_contents($work . '/' . $driver . '.json'), true, 32, JSON_THROW_ON_ERROR);
        $record['acceptance'][$driver] = $check;
    }
    $record['status'] = 'passed';
    Candidate::verify($record, $work . '/attachments', Reports::output(['git', 'rev-parse', 'HEAD'], $root), $version, $platform);
    (new SingleProgram())->verify($work . '/attachments/' . $record['file'], $record['sha256']);
    Reports::report($work . '/attachments/' . $platform . '.json', $record);
} else {
    throw new InvalidArgumentException('用法：php tests/release-candidate.php <prepare|test 驱动 [MySQL目录 PostgreSQL目录]|materials|finish>');
}
echo '发布候选' . $operation . '通过：' . $platform . "\n";
