<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\BuildPlatform;
use Type\Build\NativePackage;
use Type\Build\ServiceDefinition;

$root = BuildPlatform::resolve(dirname(__DIR__));
$base = $root . '/build/service-definition-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建服务配置测试目录');
$package = (new NativePackage())->create($argv[1] ?? $root . '/build/app/type-app', $base . '/release', $root . '/.env.example');
$digest = $package['manifest-sha256'];
$name = 'typeapp' . bin2hex(random_bytes(6));
$settings = ['name' => $name, 'runtime-directory' => $base . '/private runtime & data', 'arguments' => ['serve']];
if (PHP_OS_FAMILY === 'Windows') {
    $settings['windows-wrapper'] = ['path' => getenv('TYPE_SERVICE_WINDOWS_WRAPPER'), 'sha256' => getenv('TYPE_SERVICE_WINDOWS_WRAPPER_SHA256')];
} else {
    $user = posix_getpwuid(posix_geteuid())['name'];
    $settings['user'] = $user === 'root' ? 'nobody' : $user;
}
$definition = new ServiceDefinition();
$created = $definition->create($package['directory'], $base . '/service', $digest, $settings);
$record = json_decode(file_get_contents($created['directory'] . '/service.json'), true, 512, JSON_THROW_ON_ERROR);
expect($record['installed'] === false && $record['runtime-verified'] === false, '配置生成被误报为部署通过');
expect(!file_exists($settings['runtime-directory']), '生成服务偷偷创建或读取了运行数据目录');
$descriptor = file_get_contents($created['descriptor']);
expect(!str_contains($descriptor, 'vendor/bin') && !str_contains($descriptor, PHP_BINARY), '服务依赖PHP或Composer');
expect(str_contains($descriptor, $digest) && str_contains($descriptor, 'APP_BASE_PATH') && str_contains($descriptor, 'production'), '服务遗漏运行身份或安全默认值');
if (PHP_OS_FAMILY === 'Darwin') {
    successful(['/usr/bin/plutil', '-lint', $created['descriptor']]);
    $parsed = json_decode(successful(['/usr/bin/plutil', '-convert', 'json', '-o', '-', $created['descriptor']]), true, 512, JSON_THROW_ON_ERROR);
    expect($parsed['ProgramArguments'] === [$package['directory'] . '/run', 'serve'], '服务启动没有保持参数数组');
    expect($parsed['EnvironmentVariables']['APP_BASE_PATH'] === $settings['runtime-directory'], 'XML特殊字符没有保持实际路径');
    expect($parsed['EnvironmentVariables']['APP_DEBUG'] === 'false' && $parsed['Umask'] === 63, '生产模式或私有日志权限不正确');
} elseif (PHP_OS_FAMILY === 'Linux') {
    expect(str_contains($descriptor, 'Type=exec') && str_contains($descriptor, 'KillMode=control-group') && str_contains($descriptor, 'Restart=on-failure'), 'systemd生命周期声明不完整');
    expect(str_contains($descriptor, 'ConditionUser=' . $settings['user']) && !str_contains($descriptor, "\nUser="), '用户服务错误地重设身份或缺少管理器身份约束');
    expect(str_contains($descriptor, 'ExecStart=:') && str_contains($descriptor, "\nWorkingDirectory=" . $package['directory']), 'systemd参数插值或原始路径规则不正确');
    $system = $definition->create($package['directory'], $base . '/system-service', $digest, $settings + ['scope' => 'system']);
    expect(str_contains(file_get_contents($system['descriptor']), "\nUser=" . $settings['user']), '系统服务没有显式降到非root用户');
} else {
    $document = new DOMDocument();
    expect($document->loadXML($descriptor, LIBXML_NONET), 'Windows服务XML无法解析');
    expect(str_contains($descriptor, 'LocalService') && !str_contains($descriptor, 'LocalSystem') && !str_contains($descriptor, 'cmd.exe'), 'Windows服务未采用最小账号与直接进程');
}
$again = $definition->create($package['directory'], $base . '/same-service', $digest, $settings);
expect($created['manifest-sha256'] === $again['manifest-sha256'], '相同发布与声明没有确定性服务输出');
$invalid = [
    $settings + ['environment' => ['APP_API_TOKEN' => 'not-for-descriptors']],
    array_replace($settings, ['name' => "bad\nname"]),
    array_replace($settings, ['runtime-directory' => $package['directory']]),
    array_replace($settings, ['runtime-directory' => $package['directory'] . '/var']),
    array_replace($settings, ['runtime-directory' => $base . '/../outside']),
    array_replace($settings, ['stop-seconds' => 0]),
    array_replace($settings, ['restart-seconds' => '1']),
    array_replace($settings, ['scope' => 'invalid']),
    array_replace($settings, ['arguments' => ['serve', "bad\0value"]]),
    array_replace($settings, ['user' => 'root']),
];
foreach ($invalid as $index => $configuration) {
    $target = $base . '/rejected-' . $index;
    $rejected = false;
    try {
        $definition->create($package['directory'], $target, $digest, $configuration);
    } catch (RuntimeException) {
        $rejected = true;
    }
    expect($rejected && !file_exists($target), '非法服务声明被写入：' . $index);
}
foreach ([$base . '/service', $package['directory'] . '/service'] as $target) {
    $rejected = false;
    try {
        $definition->create($package['directory'], $target, $digest, $settings);
    } catch (RuntimeException) {
        $rejected = true;
    }
    expect($rejected, '服务生成覆盖既有目录或改写不可变发布');
}
$rejected = false;
try {
    $definition->create($package['directory'], $base . '/bad-digest', str_repeat('0', 64), $settings);
} catch (RuntimeException) {
    $rejected = true;
}
expect($rejected && !file_exists($base . '/bad-digest'), '服务接受了错误发布摘要');
$specification = $base . '/service-spec.json';
file_put_contents($specification, json_encode($settings, JSON_THROW_ON_ERROR));
$cli = json_decode(successful([PHP_BINARY, $root . '/vendor/bin/type', 'service', $package['directory'], $specification, $base . '/cli-service', $digest]), true, 512, JSON_THROW_ON_ERROR);
expect($cli['manifest-sha256'] === $created['manifest-sha256'], '正式CLI没有使用同一服务生成规则');
(new NativePackage())->verify($package['directory'], $digest);
file_put_contents($base . '/verification.json', json_encode(['platform' => PHP_OS_FAMILY, 'manager' => $created['manager'],
    'release-sha256' => $digest, 'service-sha256' => $created['manifest-sha256'], 'native-service-verified' => false,
    'checks' => ['trusted-release', 'no-secret-read', 'no-php-runtime-tool', 'private-production-config', 'valid-format', 'deterministic', 'invalid-spec-rejected', 'no-overwrite', 'no-release-mutation', 'cli-parity']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
echo '服务定义的受信发布、参数、路径、非root、确定性与CLI校验通过：' . $base . "/verification.json\n";
