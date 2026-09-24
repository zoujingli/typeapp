<?php

declare(strict_types=1);

use Type\Log\Channel;
use Type\Log\LogManager;
use Type\Log\Output;
use Type\Runtime\ExecutionScope;

/**
 * 将当前示例的行为断言转为明确失败，避免只输出成功文字而忽略实际状态。
 *
 * @throws \RuntimeException 条件不成立。
 */
function logExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * 以命令作用域验证 PSR-3、级别过滤与关联身份，结束时关闭日志资源。
 *
 * @param list<string> $argv 程序路径与该示例的显式参数。
 */
function main(int $argc, array $argv): void
{
    $output = Output::stdout();
    $logs = new LogManager('build-test-2026', ['app' => new Channel($output, 'info')]);
    $scope = new ExecutionScope(context: ['command_id' => 'command-one']);
    try {
        $logger = $logs->logger($scope);
        logExpect($logger instanceof Psr\Log\LoggerInterface, '日志对象没有实现 PSR-3');
        $logger->debug('不输出的调试信息');
        $logger->info('你好，{name}', ['name' => '开发者', 'count' => 1]);
        $logger->error('password={password} Authorization: Bearer raw-bearer-secret', [
            'password' => 'credential-secret', 'nested' => ['ACCESS_TOKEN' => 'nested-secret', 'safe' => 'kept'],
            'exception' => new RuntimeException('连接失败 password=exception-secret'),
        ]);
    } finally {
        $scope->close();
        $logs->stop();
    }
    logExpect($logs->stats()['app']['filtered'] === 1 && $output->stats()['written'] === 2, '级别过滤或写入计数错误');
}
