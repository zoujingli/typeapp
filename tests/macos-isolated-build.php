<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\BuildPlatform;
use Type\Testing\Process;

/** 只执行明确数组命令，保留本轮输出；错误不能被日志截断或超时掩盖。 */
function macIsolationRun(array $command, string $directory, array $environment, string $log, int $seconds = 60): string
{
    $process = new Process($command, $directory, $environment, 16777216);
    try {
        $result = $process->wait($seconds);
        file_put_contents($log, $result->stdout . $result->stderr);
        expect($result->successful(), 'macOS隔离验证失败，日志：' . basename($log));
        return $result->stdout;
    } finally {
        $process->stop();
    }
}

/** 策略仅存逻辑参数名，实际机器路径由参数传入，不能成为可移植文件的默认目录。 */
function macIsolationCommand(string $program, string $profile, array $parameters): array
{
    $command = [$program, '-f', $profile];
    foreach ($parameters as $name => $value) {
        array_push($command, '-D', $name . '=' . $value);
    }
    return $command;
}

$root = realpath(dirname(__DIR__));
expect(PHP_OS_FAMILY === 'Darwin', '该入口只验证macOS实际系统沙箱');
$platform = new BuildPlatform();
$phpHome = realpath(getenv('TYPE_ISOLATED_PHP_HOME') ?: getenv('PHP_HOME') ?: '');
$phpxHome = realpath(getenv('TYPE_ISOLATED_PHPX_HOME') ?: getenv('PHPX_HOME') ?: '');
expect(is_string($phpHome) && is_string($phpxHome), '需要明确的隔离构建SDK');
$php = $phpHome . '/bin/php';
$sandbox = realpath(trim(successful(['which', 'sandbox-exec'])));
expect(is_string($sandbox) && BuildPlatform::format($sandbox) === 'Mach-O' && is_executable($php), '需要真实macOS沙箱与PHP程序');
$roots = json_decode(getenv('TYPE_MAC_SANDBOX_ROOTS') ?: '{}', true, 32, JSON_THROW_ON_ERROR);
$required = ['SYSTEM', 'DYLD', 'TOOLS', 'BIN', 'DEVELOPER', 'PACKAGES', 'SSL_CONFIG', 'EXTRA0'];
expect(is_array($roots) && array_diff($required, array_keys($roots)) === [] && array_diff(array_keys($roots), $required) === [], 'TYPE_MAC_SANDBOX_ROOTS需要完整的系统依赖角色');
$userDirectory = posix_getpwuid(posix_geteuid())['dir'];
foreach ($roots as $name => $path) {
    expect(is_string($path) && !preg_match('/[\x00-\x1f\x7f]/', $path), '系统依赖路径无效');
    $resolved = realpath($path);
    expect(is_string($resolved) && ($name === 'SSL_CONFIG' ? is_file($resolved) : is_dir($resolved))
        && !BuildPlatform::contains($resolved, $root) && !BuildPlatform::contains($resolved, $userDirectory), '只允许明确系统工具根，不能放开原仓库或用户目录');
    $roots[$name] = $resolved;
}
$base = $root . '/build/macos-isolation-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700) && mkdir($base . '/scratch', 0700) && mkdir($base . '/composer-home', 0700), '无法创建本轮隔离目录');
$inputs = $base . '/inputs';
$scratch = $base . '/scratch';
$profile = $root . '/tests/fixtures/macos-build.sb';
$report = ['status' => 'running', 'path_base' => 'project-root', 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'),
    'sandbox_sha256' => hash_file('sha256', $sandbox), 'policy' => 'tests/fixtures/macos-build.sb', 'policy_sha256' => hash_file('sha256', $profile)];
$listener = null;
try {
    $prepare = $platform->environment(getenv('PHP_HOME'), getenv('PHPX_HOME'));
    $prepare['PATH'] = getenv('PATH');
    $prepare['PHPRC'] = getenv('PHPRC') ?: '';
    $prepare['PHP_INI_SCAN_DIR'] = '';
    $prepare['COMPOSER_HOME'] = $base . '/composer-home';
    $prepare['COMPOSER_CACHE_DIR'] = $root . '/.cache/composer';
    $prepare['COMPOSER_BINARY'] = getenv('COMPOSER_BINARY') ?: 'composer';
    $stage = macIsolationRun([PHP_BINARY, $root . '/tests/build-scenario.php', '--stage', $root . '/docs/build-config/type-app.json', $inputs], $root, $prepare, $base . '/stage.log', 1800);
    file_put_contents($base . '/stage.json', $stage);
    $snapshot = json_decode(macIsolationRun([PHP_BINARY, $root . '/tests/isolated-build.php', 'snapshot', $inputs, $base . '/stage.json'], $root, $prepare, $base . '/snapshot.log'), true, 32, JSON_THROW_ON_ERROR);
    $report['snapshot'] = $snapshot;
    $manifest = json_decode(file_get_contents($inputs . '/build-inputs.json'), true, 512, JSON_THROW_ON_ERROR);
    expect(is_dir($inputs . '/build') || mkdir($inputs . '/build', 0700), '无法准备编译输出目录');
    $environment = $platform->environment($phpHome, $phpxHome);
    $environment['PHPRC'] = '';
    $environment['PHP_INI_SCAN_DIR'] = '';
    $environment['TMPDIR'] = $scratch;
    $parameters = $roots + ['ROOT' => dirname($platform->nullDevice(), 2), 'NULL' => $platform->nullDevice(),
        'PHP' => $phpHome, 'PHPX' => $phpxHome, 'INPUT' => $inputs, 'BUILD' => $inputs . '/build', 'SCRATCH' => $scratch];
    $command = macIsolationCommand($sandbox, $profile, $parameters);
    $canary = $base . '/original-canary';
    file_put_contents($canary, 'typeapp-test-only');
    $listener = stream_socket_server('tcp://127.0.0.1:0', $number, $message);
    expect(is_resource($listener), '无法建立可达的本轮网络对照');
    $address = stream_socket_get_name($listener, false);
    $control = stream_socket_client('tcp://' . $address, $number, $message, 1);
    expect(is_resource($control), '沙箱外的本轮网络对照不可达');
    fclose($control);
    $probe = <<<'PHP'
$paths = [$argv[1] . '/examples/isolation-write-probe', $argv[2] . '/isolation-write-probe', $argv[3] . '/isolation-write-probe'];
foreach ($paths as $file) {
    if (@file_put_contents($file, 'test-only') !== false) { unlink($file); throw new RuntimeException('保护目录可写'); }
}
foreach ([$argv[4], $argv[5]] as $file) {
    if (@file_get_contents($file) !== false) { throw new RuntimeException('原始工作区仍可读'); }
}
foreach (['COMPOSER_AUTH','SSH_AUTH_SOCK','GH_TOKEN','GITHUB_TOKEN','AWS_ACCESS_KEY_ID','AWS_SECRET_ACCESS_KEY','TYPE_ISOLATION_CANARY'] as $name) {
    if (getenv($name) !== false) { throw new RuntimeException('认证环境被继承'); }
}
$client = @stream_socket_client('tcp://' . $argv[6], $number, $message, 0.2);
if (is_resource($client)) { fclose($client); throw new RuntimeException('沙箱仍能访问已证明可达的网络端点'); }
if (!in_array($number, [1,13], true)) { throw new RuntimeException('连接并非被权限拒绝：' . $number); }
$test = $argv[7] . '/allowed-write';
if (file_put_contents($test, 'scratch') !== 7 || file_get_contents($test) !== 'scratch') { throw new RuntimeException('私有临时目录不可用'); }
unlink($test);
echo json_encode(['network'=>'permission-denied','sources'=>'readonly','sdk'=>'readonly','original-source'=>'unreadable','credentials'=>'absent','scratch'=>'writable']), "\n";
PHP;
    $boundary = macIsolationRun([...$command, $php, '-n', '-r', $probe, $inputs, $phpHome, $phpxHome, $canary, $root . '/composer.json', $address, $scratch], $inputs, $environment, $base . '/boundary.log');
    $report['boundary'] = json_decode($boundary, true, 32, JSON_THROW_ON_ERROR);
    macIsolationRun([...$command, $php, '-d', 'auto_prepend_file=', '-d', 'auto_append_file=', '-d', 'opcache.enable_cli=0', 'vendor/bin/type', 'docs/build-config/type-app.json'], $inputs, $environment, $base . '/compile.log', 1800);
    $artifact = $inputs . '/build/native/type-app';
    $platform->assertArtifact($artifact);
    $built = json_decode(file_get_contents($artifact . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
    expect(!$built['cache']['hit'] && $built['sha256'] === hash_file('sha256', $artifact)
        && array_keys($built['production-packages']) === ['zoujingli/type-runtime'], '没有实际编译完整独立命令');
    $checked = 0;
    foreach ($manifest['files'] as $relative => $digest) {
        // build是明确可写的生成/产物目录，其余输入必须保持原始字节。
        if (!str_starts_with($relative, 'build/')) {
            expect(hash_file('sha256', $inputs . '/' . $relative) === $digest, '只读输入变化：' . $relative);
            $checked++;
        }
    }
    $runtime = $base . '/runtime';
    expect(mkdir($runtime . '/tests', 0700, true) && mkdir($runtime . '/plugin/type-build/src', 0700, true), '无法准备独立运行验证器');
    expect(copy($artifact, $runtime . '/type-app') && chmod($runtime . '/type-app', 0755), '无法搬迁原生命令');
    expect(copy($built['runtime-profile']['ini'], $runtime . '/php.ini'), '无法复制经实际embed探测的运行配置');
    foreach (['tests/native.php', 'tests/support.php', 'plugin/type-build/src/BuildPlatform.php'] as $file) {
        expect(copy($root . '/' . $file, $runtime . '/' . $file), '无法复制公共行为验证器');
    }
    if (is_dir($artifact . '.resources')) {
        successful(['cp', '-R', $artifact . '.resources', $runtime . '/type-app.resources']);
    }
    $parameters['INPUT'] = $runtime;
    $parameters['BUILD'] = $scratch;
    $environment['PHPRC'] = $runtime . '/php.ini';
    $runtimeCommand = macIsolationCommand($sandbox, $profile, $parameters);
    macIsolationRun([...$runtimeCommand, $php, '-n', '-r', $probe, $inputs, $phpHome, $phpxHome, $inputs . '/examples/native-command.php', $inputs . '/vendor/autoload.php', $address, $scratch], $runtime, $environment, $base . '/runtime-boundary.log');
    $native = macIsolationRun([...$runtimeCommand, $php, '-n', 'tests/native.php', $runtime . '/type-app'], $runtime, $environment, $base . '/native.log');
    expect(trim($native) === '原生命令验证通过，共 9 个输出和错误行为用例。', '隔离后的九项公共行为未完成');
    $report += ['build_id' => $built['build-id'], 'artifact_sha256' => $built['sha256'], 'source_inputs' => count($built['sources']),
        'protected_inputs_checked' => $checked, 'build_report_sha256' => hash_file('sha256', $artifact . '.build.json'),
        'compile_log_sha256' => hash_file('sha256', $base . '/compile.log'), 'native_cases' => 9,
        'limits' => ['生成目录可写；文件元数据可见，原始源码内容不可读。', '运行环境仅额外提供PHP测试控制器；不冒称本项已验证发布包或移除PHP CLI。']];
    $report['status'] = 'passed';
} finally {
    if (is_resource($listener)) {
        fclose($listener);
    }
    if ($report['status'] !== 'passed') {
        $report['status'] = 'failed';
    }
    file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
}
echo 'macOS内核隔离、断网只读全量构建与九项原生行为通过：' . substr($base, strlen($root) + 1) . "/verification.json\n";
