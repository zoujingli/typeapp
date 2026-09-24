<?php

declare(strict_types=1);

use Type\Log\Channel;
use Type\Log\Formatter;
use Type\Log\LogManager;
use Type\Log\Output;
use Type\Runtime\ExecutionScope;

/** 故意拒绝字符串转换和 JSON 序列化的上下文值，检验日志不会执行任意对象方法。 */
final class UnsafeLogValue implements Stringable, JsonSerializable
{
    /**
     * 故意拒绝隐式转文本，供日志消息失败和上下文隔离路径验证。
     *
     * @throws RuntimeException 每次调用均失败。
     */
    public function __toString(): string
    {
        throw new RuntimeException('不能转换');
    }
    /**
     * 故意拒绝对象序列化，验证格式化器只记录未知对象类型。
     *
     * @throws RuntimeException 每次调用均失败。
     */
    public function jsonSerialize(): mixed
    {
        throw new RuntimeException('不能序列化');
    }
}

/**
 * 将当前示例的行为断言转为明确失败，避免只输出成功文字而忽略实际状态。
 *
 * @throws \RuntimeException 条件不成立。
 */
function behaviorExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * 验证脱敏、任意上下文、共享格式化预算与日志作用域隔离。
 *
 * @param list<string> $argv 程序路径与该示例的显式参数。
 */
function main(int $argc, array $argv): void
{
    $bounded = new Formatter(maxItems: 3);
    behaviorExpect($bounded->context(['one' => ['first' => 1, 'second' => 2], 'two' => 3])
        === ['one' => ['first' => 1, '__truncated' => true], '__truncated' => true], '嵌套日志必须共享同一截断预算');
    behaviorExpect($bounded->context(['one' => 1, 'two' => 2]) === ['one' => 1, 'two' => 2], '新记录没有重置独立预算');
    behaviorExpect((new Formatter(maxItems: 2))->context(['password' => 'budget-secret', 'public' => 1])
        === ['password' => '[REDACTED]', '__truncated' => true], '敏感字段未消耗共享预算或泄漏原值');
    behaviorExpect((new Formatter(maxDepth: 1, maxItems: 4))->context(['nested' => array_fill(0, 1000, ['value' => 'not-visited'])])
        === ['nested' => [0 => '[TRUNCATED]', 1 => '[TRUNCATED]', '__truncated' => true]], '深度截断的占位值也必须消耗条目预算');
    $file = tempnam(sys_get_temp_dir(), 'type_log_file_');
    behaviorExpect($file !== false, '无法创建日志文件验证路径');
    $output = Output::file((string) $file, maxRecordBytes: 16384);
    $logs = new LogManager(
        'build-one',
        ['app' => new Channel($output), 'security' => new Channel($output, 'warning')],
        formatter: new Formatter(['account'], ['known-secret'], maxStringBytes: 1024)
    );
    $scope = new ExecutionScope(context: ['command_id' => 'one']);
    try {
        $id = \std::any('request-original');
        $context = ['request_id' => &$id, 'nested' => ['value' => &$id], 'password' => 'scope-secret', 'command_id' => 'forged'];
        $logger = $logs->logger($scope, $context);
        $id = 'request-changed';
        $recursive = \std::any([]);
        $recursive['self'] = &$recursive;
        $closed = fopen(PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r');
        fclose($closed);
        $logger->notice('registered known-secret {account}', ['account' => 'account-secret', 'arbitrary' => new UnsafeLogValue(),
            'recursive' => $recursive, 'invalid' => "\xff", 'number' => INF, 'closed' => $closed]);
        $logger->warning(new UnsafeLogValue());
        $security = $logger->channel('security');
        $security->info('不输出');
        $security->error('second-channel');
        $invalidLevel = false;
        try {
            $logger->log('invalid', 'bad');
        } catch (Psr\Log\InvalidArgumentException $error) {
            $invalidLevel = true;
        }
        behaviorExpect($invalidLevel, '非法级别必须抛 PSR-3 异常');
        $foreign = \std::any(false);
        (new Fiber(function () use ($logger, &$foreign): void {
            try {
                $logger->info('不能跨 Fiber');
            } catch (RuntimeException $error) {
                $foreign = true;
            }
        }))->start();
        behaviorExpect($foreign, '日志上下文跨执行者共享');
        $scope->close();
        $expired = false;
        try {
            $logger->info('已退出');
        } catch (RuntimeException $error) {
            $expired = true;
        }
        behaviorExpect($expired, '退出的作用域仍可写日志');
        $next = new ExecutionScope(context: ['command_id' => 'two']);
        try {
            $logs->logger($next)->info('new-context', ['build_id' => 'forged']);
        } finally {
            $next->close();
        }
        $logs->stop();
        $wire = (string) file_get_contents((string) $file);
        $rows = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), explode("\n", rtrim($wire, "\n")));
        behaviorExpect(count($rows) === 4 && $rows[0]['correlation']['request_id'] === 'request-original'
            && $rows[0]['correlation']['nested']['value'] === 'request-original' && $rows[0]['correlation']['command_id'] === 'one', '关联上下文没有生成不可变快照或覆盖了 Scope 标识');
        behaviorExpect($rows[1]['message'] === '[message_unavailable]' && $rows[2]['channel'] === 'security', 'PSR 任意值或多通道错误');
        behaviorExpect($rows[3]['correlation'] === ['command_id' => 'two'] && $rows[3]['build_id'] === 'build-one', '上一执行的关联标识泄漏或构建标识被覆盖');
        foreach (['known-secret', 'account-secret', 'scope-secret', 'request-changed'] as $secret) {
            behaviorExpect(!str_contains($wire, $secret), '敏感值或可变上下文泄漏');
        }
        behaviorExpect($logs->stats()['security']['filtered'] === 1 && $output->stats()['written'] === 4, '通道或共享输出计数错误');
    } finally {
        $scope->close();
        $logs->stop();
        if (is_file((string) $file)) {
            unlink((string) $file);
        }
    }
    echo "日志文件、多通道、作用域隔离、任意上下文与脱敏通过。\n";
}
