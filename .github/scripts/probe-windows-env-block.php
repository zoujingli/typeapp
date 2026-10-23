<?php

declare(strict_types=1);

// 只传递本轮合成数据，报告长度、摘要和哨兵，不读取或打印真实凭据。
$root = dirname(__DIR__, 2);
require $root . '/plugin/type-runtime/src/Deadline.php';
require $root . '/plugin/type-testing/src/ProcessResult.php';
require $root . '/plugin/type-testing/src/Process.php';

$node = $argv[1] ?? '';
if (!is_file($node)) {
    throw new RuntimeException('需要明确的 Node 可执行文件');
}
$base = $root . '/build/windows-env-block-' . bin2hex(random_bytes(6));
if (!mkdir($base, 0700)) {
    throw new RuntimeException('无法创建环境诊断目录');
}
$common = ['SystemRoot' => (string) getenv('SystemRoot'), 'TEMP' => sys_get_temp_dir()];
$php = '$v=getenv("TYPE_DEVICE_FIXTURE"); echo json_encode(["fixture_bytes"=>strlen((string)$v),"http"=>getenv("TYPE_DEVICE_HTTP")?:null,"ca"=>getenv("TYPE_DEVICE_CA")?:null],JSON_THROW_ON_ERROR);';
$javascript = 'const v=process.env.TYPE_DEVICE_FIXTURE; console.log(JSON.stringify({fixture_bytes:v?.length??0,http:process.env.TYPE_DEVICE_HTTP??null,ca:process.env.TYPE_DEVICE_CA??null}));';
$commands = ['php' => [PHP_BINARY, '-n', '-r', $php], 'node' => [$node, '-e', $javascript]];
$report = ['platform' => PHP_OS_FAMILY, 'php' => PHP_VERSION, 'php_sha256' => hash_file('sha256', PHP_BINARY), 'cases' => []];
foreach ([64, 20000] as $bytes) {
    $environment = $common + ['TYPE_DEVICE_FIXTURE' => str_repeat('x', $bytes),
        'TYPE_DEVICE_HTTP' => 'http-sentinel', 'TYPE_DEVICE_CA' => 'ca-sentinel'];
    foreach ($commands as $name => $command) {
        $child = new Type\Testing\Process($command, $root, $environment);
        try {
            $result = $child->wait(5);
            if (!$result->successful() || $result->stderr !== '') {
                throw new RuntimeException('环境诊断子进程失败：' . $name);
            }
            $sample = json_decode($result->stdout, true, 8, JSON_THROW_ON_ERROR);
            $report['cases'][$name . '-' . $bytes] = ['input_bytes' => $bytes, 'sample' => $sample,
                'complete' => $sample === ['fixture_bytes' => $bytes, 'http' => 'http-sentinel', 'ca' => 'ca-sentinel']];
        } finally {
            $child->stop();
        }
    }
}
$report['red_reproduced'] = $report['cases']['php-64']['complete'] && $report['cases']['node-64']['complete']
    && !$report['cases']['php-20000']['complete'] && !$report['cases']['node-20000']['complete'];
file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
echo json_encode($report, JSON_THROW_ON_ERROR), "\n";
exit(PHP_OS_FAMILY === 'Windows' ? ($report['red_reproduced'] ? 0 : 1) : 0);
