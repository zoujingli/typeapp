<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Testing\Process;

$root = dirname(__DIR__);
$configuration = $root . '/docs/build-config/type-app.json';
$first = json_decode(successful([PHP_BINARY, $root . '/vendor/bin/type', 'prepare', $configuration]), true, 512, JSON_THROW_ON_ERROR);
$legacy = json_decode(successful([PHP_BINARY, $root . '/bin/typeapp-prepare', '--json']), true, 512, JSON_THROW_ON_ERROR);
expect($first === $legacy, '统一准备命令与旧入口没有使用相同代次');
$environment = getenv();
unset($environment['PHP_HOME'], $environment['PHPX_HOME']);
$environment['APP_API_TOKEN'] = 'doctor-secret-must-not-print';
$environment['APP_BASE_PATH'] = $root . '/build/doctor-does-not-exist';
$doctor = new Process([PHP_BINARY, $root . '/vendor/bin/type', 'doctor', $configuration], $root, $environment);
$result = $doctor->wait(10);
expect($result->successful() && !str_contains($result->stdout . $result->stderr, $environment['APP_API_TOKEN']), '诊断泄漏或读取业务配置');
$report = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
expect($report['development']['ready'] && !$report['build']['ready'] && !$report['runtime']['verified']
    && in_array('sdk_not_configured', $report['build']['issues'], true), '诊断混淆开发、构建与生产验收');
$buildDoctor = (new Process([PHP_BINARY, $root . '/vendor/bin/type', 'doctor', $configuration, 'build'], $root, $environment))->wait(10);
expect(!$buildDoctor->successful(), '缺失SDK的构建诊断错误返回成功');
expect(!is_dir($environment['APP_BASE_PATH']), '只读诊断创建了业务目录');
echo "统一开发准备、只读诊断、范围退出码与秘密隔离通过。\n";
