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

// 先保存最终可执行文件，再以该附件的同一字节执行所选数据库和业务行为；finish 不重新编译。
$root = dirname(__DIR__);
$operation = $argv[1] ?? '';
$version = getenv('TYPE_RELEASE_VERSION') ?: '';
expect(preg_match('/^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-rc\.[1-9][0-9]*)?$/D', $version) === 1, '候选验收需要明确版本');
$platform = match (PHP_OS_FAMILY) {
    'Darwin' => 'macos-arm64', 'Windows' => 'windows-x64',
    'Linux' => in_array(php_uname('m'), ['aarch64', 'arm64'], true) ? 'linux-arm64' : 'linux-x64',
    default => throw new RuntimeException('候选平台不受支持'),
};
$profile = getenv('TYPEAPP_BUILD_PROFILE');
$profile = is_string($profile) && $profile !== '' ? $profile : null;
$work = $root . '/build/release-candidate';
$stateFile = $work . '/preparation.json';
$artifact = (new BuildPlatform())->output($root . '/build/app/type-app');
if ($operation === 'prepare') {
    expect(!file_exists($work), '候选目录已存在；重试须复用已封存候选，不能覆盖');
    mkdir($work, 0700);
    mkdir($work . '/attachments', 0700);
    $identity = (new ArtifactManifest())->read($artifact);
    expect($identity['version'] === substr($version, 1) && isset($identity['embedded-resources']['web/index.html']), '候选版本或内嵌前端缺失');
    $buildReportFile = $artifact . '.build.json';
    expect(is_file($buildReportFile) && !is_link($buildReportFile), '候选缺少对应的构建报告');
    $buildReport = json_decode((string) file_get_contents($buildReportFile), true, 512, JSON_THROW_ON_ERROR);
    expect(is_array($buildReport) && ($buildReport['sha256'] ?? null) === hash_file('sha256', $artifact)
        && is_array($buildReport['size-breakdown'] ?? null), '构建报告与候选程序不一致');
    $identityProfile = $identity['profile'] ?? [];
    expect($profile !== null && is_array($identityProfile) && ($identityProfile['name'] ?? null) === $profile
        && ($identityProfile['database'] ?? null) === $profile, '产物身份与候选 profile 不一致');
    $filename = Candidate::filename($version, $platform, $profile);
    $created = (new SingleProgram())->create($artifact, $work . '/attachments/' . $filename);
    Reports::report($stateFile, ['protocol' => 2, 'source' => Reports::output(['git', 'rev-parse', 'HEAD'], $root),
        'delivery' => 'single-executable', 'version' => $version, 'platform' => $platform, 'profile' => $profile,
        'database' => $identityProfile['database'],
        'profile-facts' => $identityProfile,
        'features' => $identity['features'] ?? $identityProfile['features'] ?? [],
        'runtime-extensions' => $identity['runtime-extensions'] ?? [],
        'static-archives' => $identity['static-archives'] ?? [],
        // ELF 的清单尾部和 Mach-O 的签名段都在程序封存时确定；体积分解统一取
        // NativeBuilder 对最终 strip 后程序生成的报告，避免平台间清单协议差异。
        'size-breakdown' => $buildReport['size-breakdown'],
        'file' => $filename, 'sha256' => $created['sha256'], 'bytes' => $created['bytes'], 'build-id' => $created['build-id'],
        'system-libraries' => $created['system-libraries'],
        'sdk-manifest-sha256' => hash_file('sha256', (string) getenv('TYPE_STATIC_RUNTIME')),
        'artifact-sha256' => hash_file('sha256', $artifact), 'embedded-resources' => $identity['embedded-resources'],
        'frontend-manifest-sha256' => hash_file('sha256', (string) getenv('TYPE_FRONTEND_MANIFEST'))]);
} elseif ($operation === 'test') {
    $driver = $argv[2] ?? '';
    expect(in_array($driver, ['mysql', 'pgsql', 'sqlite'], true), '候选测试需要明确数据库');
    expect($profile === null || $driver === $profile, 'profile 候选只能验收对应数据库');
    $record = json_decode((string) file_get_contents($stateFile), true, 64, JSON_THROW_ON_ERROR);
    expect(($record['protocol'] ?? null) === 2 && $record['file'] === Candidate::filename($version, $platform, $profile), '不能把历史目录包作为单程序候选');
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
        expect($result->successful(), '最终单程序的 ' . $driver . ' 部署验收失败 ' . json_encode($status, JSON_THROW_ON_ERROR)
            . '，见build/release-candidate/' . $driver . ".log：\n" . $result->stdout . $result->stderr);
        // 测试控制端读取构建报告；程序仍执行候选附件本身，不重新编译或加载 PHP 源码。
        expect(copy($artifact . '.build.json', $candidate . '.build.json'), '无法向控制端提供候选构建报告');
        $business = [];
        try {
            $features = $record['features'];
            $checks = [];
            if (in_array('mqtt', $features, true)) {
                $checks['mqtt'] = ['tests/broker-access.php', $candidate, $driver];
            }
            foreach (['alerts' => '--alarms', 'exports' => '--exports'] as $feature => $flag) {
                if (in_array($feature, $features, true)) {
                    $checks[$feature] = ['tests/iot-identity.php', $candidate, $driver, '--devices', $flag];
                }
            }
            if (in_array('scheduler', $features, true)) {
                $checks['scheduler'] = ['tests/iot-identity.php', $candidate, $driver, '--app', '--scheduler'];
            }
            foreach ($checks as $feature => $arguments) {
                $businessResult = (new Process([PHP_BINARY, ...$arguments], $root, $environment))->wait(900);
                $log = $work . '/' . $driver . '-' . $feature . '.log';
                file_put_contents($log, $businessResult->stdout . $businessResult->stderr);
                expect($businessResult->successful(), '最终候选业务验收失败：' . $feature . "\n" . $businessResult->stdout . $businessResult->stderr);
                expect(hash_file('sha256', $candidate) === $record['sha256'], '业务验收期间候选字节改变');
                $business[$feature] = ['status' => 'passed', 'artifact-sha256' => $record['sha256'], 'log-sha256' => hash_file('sha256', $log)];
            }
        } finally {
            unlink($candidate . '.build.json');
        }
    } finally {
        try {
            $database?->close();
        } finally {
            expect(!file_exists($dist) && rename($hiddenDist, $dist), '无法恢复本轮前端构建资源');
        }
    }
    expect(hash_file('sha256', $candidate) === $record['sha256'], '对应数据库验收期间候选字节改变');
    Reports::report($work . '/' . $driver . '.json', ['status' => 'passed', 'driver' => $driver,
        'artifact-sha256' => $record['sha256'], 'frontend-source-removed' => true, 'single-executable-only' => true,
        'runtime-profile-enforced' => $profile !== null, 'business' => $business,
        'log-sha256' => hash_file('sha256', $work . '/' . $driver . '.log')]);
} elseif ($operation === 'service') {
    expect(PHP_OS_FAMILY !== 'Windows' && $profile !== null && count($argv) === 4, '服务验收需要 profile 和两个数据库工具目录');
    require __DIR__ . '/native-database.php';
    $record = json_decode((string) file_get_contents($stateFile), true, 64, JSON_THROW_ON_ERROR);
    $candidate = $work . '/attachments/' . $record['file'];
    (new SingleProgram())->verify($candidate, $record['sha256']);
    $tools = $profile === 'sqlite' ? [] : NativeDatabase::tools($profile, $profile === 'mysql' ? $argv[2] : $argv[3]);
    $database = new NativeDatabase($work . '/service-database', $profile, $tools);
    try {
        $environment = array_replace(getenv(), $database->environment(), ['TYPE_SERVICE_DRIVER' => $profile, 'TYPE_SERVICE_ARTIFACT' => $candidate]);
        $command = PHP_OS_FAMILY === 'Darwin' ? [PHP_BINARY, $root . '/tests/native-service.php', $candidate]
            : ['bash', $root . '/.github/scripts/run-linux-service.sh'];
        $result = (new Process($command, $root, $environment))->wait(600);
        file_put_contents($work . '/service.log', $result->stdout . $result->stderr);
        expect($result->successful(), '最终程序服务生命周期失败：' . $result->stdout . $result->stderr);
        expect(hash_file('sha256', $candidate) === $record['sha256'], '服务验收改变了候选程序');
        Reports::report($work . '/service.json', ['status' => 'passed', 'driver' => $profile,
            'artifact-sha256' => $record['sha256'], 'log-sha256' => hash_file('sha256', $work . '/service.log')]);
    } finally {
        $database->close();
    }
} elseif ($operation === 'materials') {
    $record = json_decode((string) file_get_contents($stateFile), true, 64, JSON_THROW_ON_ERROR);
    $sdk = (string) getenv('TYPE_STATIC_RUNTIME');
    expect(hash_file('sha256', $sdk) === $record['sdk-manifest-sha256'], '重建材料必须来自候选构建使用的同一 SDK');
    $name = 'typeapp-rebuild-' . substr($version, 1) . '-' . $platform . ($profile === null ? '' : '-' . $profile) . '.zip';
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
    foreach ($profile === null ? ['mysql', 'pgsql', 'sqlite'] : [$profile] as $driver) {
        $check = json_decode((string) file_get_contents($work . '/' . $driver . '.json'), true, 32, JSON_THROW_ON_ERROR);
        $record['acceptance'][$driver] = $check;
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        $record['service'] = json_decode((string) file_get_contents($work . '/service.json'), true, 32, JSON_THROW_ON_ERROR);
    }
    $record['status'] = 'passed';
    Candidate::verify($record, $work . '/attachments', Reports::output(['git', 'rev-parse', 'HEAD'], $root), $version, $platform, $profile);
    (new SingleProgram())->verify($work . '/attachments/' . $record['file'], $record['sha256']);
    Reports::report($work . '/attachments/' . $platform . ($profile === null ? '' : '-' . $profile) . '.json', $record);
} else {
    throw new InvalidArgumentException('用法：php tests/release-candidate.php <prepare|test 驱动 [MySQL目录 PostgreSQL目录]|materials|finish>');
}
echo '发布候选' . $operation . '通过：' . $platform . "\n";
