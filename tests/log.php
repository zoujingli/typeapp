<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$launcher = 'require ' . var_export($root . '/tests/log-bootstrap.php', true) . '; require '
    . var_export($root . '/examples/log-command.php', true) . '; main($argc, $argv);';
$command = isset($argv[1]) && $argv[1] !== '--php' ? nativeCommand($argv[1]) : [PHP_BINARY, '-r', $launcher];
[$status, $stdout, $stderr] = execute($command);
expect($status === 0 && $stderr === '', '日志命令失败：' . $stdout . $stderr);
$records = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), explode("\n", rtrim($stdout, "\n")));
expect(count($records) === 2, '级别过滤没有控制输出记录数量');
expect($records[0]['level'] === 'info' && $records[0]['channel'] === 'app' && $records[0]['message'] === '你好，开发者'
    && $records[0]['build_id'] === 'build-test-2026' && $records[0]['correlation'] === ['command_id' => 'command-one']
    && $records[0]['context'] === ['name' => '开发者', 'count' => 1], '日志消息、结构或关联信息错误');
expect((bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $records[0]['time']), '日志时间必须是 UTC 微秒格式');
foreach (['credential-secret', 'nested-secret', 'exception-secret', 'raw-bearer-secret'] as $secret) {
    expect(!str_contains($stdout, $secret), '敏感值没有脱敏');
}
expect($records[1]['context']['password'] === '[REDACTED]' && $records[1]['context']['nested']['ACCESS_TOKEN'] === '[REDACTED]'
    && $records[1]['context']['nested']['safe'] === 'kept', '嵌套敏感字段脱敏错误');
expect($records[1]['context']['exception']['type'] === 'RuntimeException' && !isset($records[1]['context']['exception']['trace'][0]['args']), '异常类型缺失或堆栈暴露参数');
echo "日志命令 PSR-3、级别、结构化 stdout 与执行关联通过。\n";
