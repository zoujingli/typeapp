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
$php = '$f=getenv("TYPE_DEVICE_FIXTURE_FILE");$v=$f?file_get_contents($f):getenv("TYPE_DEVICE_FIXTURE"); echo json_encode(["fixture_bytes"=>strlen((string)$v),"fixture_sha256"=>hash("sha256",(string)$v),"http"=>getenv("TYPE_DEVICE_HTTP")?:null,"ca"=>getenv("TYPE_DEVICE_CA")?:null],JSON_THROW_ON_ERROR);';
$javascript = 'const f=process.env.TYPE_DEVICE_FIXTURE_FILE;const v=f?require("node:fs").readFileSync(f,"utf8"):process.env.TYPE_DEVICE_FIXTURE; console.log(JSON.stringify({fixture_bytes:v?.length??0,fixture_sha256:require("node:crypto").createHash("sha256").update(v??"").digest("hex"),http:process.env.TYPE_DEVICE_HTTP??null,ca:process.env.TYPE_DEVICE_CA??null}));';
$commands = ['php' => [PHP_BINARY, '-n', '-r', $php], 'node' => [$node, '-e', $javascript]];
$report = ['platform' => PHP_OS_FAMILY, 'php' => PHP_VERSION, 'php_sha256' => hash_file('sha256', PHP_BINARY), 'cases' => []];
$fixtureFile = $base . '/fixture 空格.txt';
try {
    foreach (['inline-small' => 64, 'inline-large' => 20000, 'file-large' => 20000] as $mode => $bytes) {
        $payload = str_repeat('x', $bytes);
        $input = ['TYPE_DEVICE_FIXTURE' => $payload];
        if ($mode === 'file-large') {
            file_put_contents($fixtureFile, $payload);
            $input = ['TYPE_DEVICE_FIXTURE_FILE' => $fixtureFile];
        }
        $environment = $common + $input + [
            'TYPE_DEVICE_HTTP' => 'http-sentinel', 'TYPE_DEVICE_CA' => 'ca-sentinel'];
        foreach ($commands as $name => $command) {
            $child = new Type\Testing\Process($command, $root, $environment);
            try {
                $result = $child->wait(5);
                if (!$result->successful() || $result->stderr !== '') {
                    throw new RuntimeException('环境诊断子进程失败：' . $name);
                }
                $sample = json_decode($result->stdout, true, 8, JSON_THROW_ON_ERROR);
                $report['cases'][$name . '-' . $mode] = ['input_bytes' => $bytes, 'sample' => $sample,
                    'complete' => $sample === ['fixture_bytes' => $bytes, 'fixture_sha256' => hash('sha256', $payload),
                        'http' => 'http-sentinel', 'ca' => 'ca-sentinel']];
            } finally {
                $child->stop();
            }
        }
    }
} finally {
    if (is_file($fixtureFile)) {
        unlink($fixtureFile);
    }
}
$report['red_reproduced'] = $report['cases']['php-inline-small']['complete'] && $report['cases']['node-inline-small']['complete']
    && !$report['cases']['php-inline-large']['complete'] && !$report['cases']['node-inline-large']['complete'];
$report['file_complete'] = $report['cases']['php-file-large']['complete'] && $report['cases']['node-file-large']['complete'];
file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
echo json_encode($report, JSON_THROW_ON_ERROR), "\n";
exit($report['file_complete'] && (PHP_OS_FAMILY !== 'Windows' || $report['red_reproduced']) ? 0 : 1);
