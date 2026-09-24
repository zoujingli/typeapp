<?php

declare(strict_types=1);

namespace Type\Redis;

use Closure;
use InvalidArgumentException;
use Type\Runtime\ResourceLease;
use Type\Runtime\ReusableResource;

/** 绑定执行者和用途的 Redis 租约入口；不能跨请求、协程、Fiber 或进程复用。 */
final class RedisConnection
{
    private ResourceLease $lease;
    private string $purpose;
    private bool $watching = false;

    private const BLOCKING_COMMANDS = ['BLPOP', 'BRPOP', 'BRPOPLPUSH', 'BLMOVE', 'BLMPOP', 'BZPOPMIN', 'BZPOPMAX', 'BZMPOP', 'WAIT', 'WAITAOF'];
    private const STATEFUL = ['AUTH', 'SELECT', 'HELLO', 'CLIENT', 'CONFIG', 'DEBUG', 'MONITOR', 'SUBSCRIBE', 'PSUBSCRIBE', 'SSUBSCRIBE',
        'UNSUBSCRIBE', 'PUNSUBSCRIBE', 'SUNSUBSCRIBE', 'READONLY', 'READWRITE', 'ASKING', 'MULTI', 'WATCH', 'EXEC', 'DISCARD', 'UNWATCH',
        'EVAL', 'EVALSHA', 'EVAL_RO', 'EVALSHA_RO', 'FCALL', 'FCALL_RO', 'SCRIPT', 'FUNCTION', 'QUIT', 'RESET', 'SHUTDOWN',
        'REPLICAOF', 'SLAVEOF', 'SWAPDB', 'MIGRATE', 'FLUSHDB', 'FLUSHALL'];
    private const READS = ['GET', 'MGET', 'EXISTS', 'TTL', 'PTTL', 'TYPE', 'STRLEN', 'GETRANGE', 'HGET', 'HMGET', 'HGETALL', 'HLEN', 'HEXISTS',
        'LINDEX', 'LLEN', 'LRANGE', 'SCARD', 'SISMEMBER', 'SMEMBERS', 'ZCARD', 'ZCOUNT', 'ZRANGE', 'ZREVRANGE', 'ZSCORE', 'ZRANK', 'PING', 'TIME'];

    /** @internal 绑定已借出的租约与用途；生命周期由借用作用域拥有。 */
    public function __construct(ResourceLease $lease, string $purpose)
    {
        $this->lease = $lease;
        $this->purpose = $purpose;
    }

    /**
     * 执行普通命令；WATCH 回调只允许读取，状态与阻塞命令需使用各自入口。
     *
     * @param list<string|int|float> $arguments 不做自动前缀或序列化的原始参数。
     * @throws RedisException 用途不符、会话失效或服务错误；写入结果需结合 outcome 判断。
     */
    public function command(string $command, array $arguments = []): mixed
    {
        if ($this->purpose !== Purpose::COMMAND && !($this->purpose === Purpose::TRANSACTION && $this->watching)) {
            throw new RedisException('wrong_purpose', 'NOT_STARTED', '该用途不能直接执行普通命令');
        }
        $command = $this->validateCommand($command, $arguments, false);
        if ($this->watching && !in_array($command, self::READS, true)) {
            throw new RedisException('watch_read_only', 'NOT_STARTED', 'WATCH 回调只读取状态，写入通过返回的命令列表登记');
        }
        return $this->session(static fn (RedisSession $session): mixed => $session->command($command, $arguments));
    }

    /**
     * 在 BLOCKING 专用连接中等待；Redis 命令自身的等待参数保持原生单位。
     *
     * @param list<string|int|float> $arguments 命令参数，如 BLPOP 的最后一项为等待秒数。
     */
    public function blocking(string $command, array $arguments): mixed
    {
        $this->purpose(Purpose::BLOCKING);
        $command = $this->validateCommand($command, $arguments, true);
        if (!in_array($command, array_merge(self::BLOCKING_COMMANDS, ['XREAD', 'XREADGROUP']), true)) {
            throw new RedisException('wrong_purpose', 'NOT_STARTED', '阻塞池只接受阻塞读取和等待命令');
        }
        return $this->session(static fn (RedisSession $session): mixed => $session->command($command, $arguments));
    }

    /**
     * 批量传输命令，不提供事务回滚，失败时可能已执行部分写入。
     *
     * @param list<array{0: string, 1: list<string|int|float>}> $commands 最多 1000 条普通命令。
     * @return list<mixed> 按命令顺序返回原生响应。
     */
    public function pipeline(array $commands): array
    {
        $this->purpose(Purpose::PIPELINE);
        $commands = $this->commands($commands);
        return $this->session(static fn (RedisSession $session): array => $session->pipeline($commands));
    }

    /**
     * 回调只读取 WATCH 状态并返回待提交命令；冲突不会自动重跑，EXEC 错误不保证回滚。
     *
     * @param list<string> $keys 最多 1000 个需要监视的非空键。
     * @param Closure(RedisConnection): list<array{0: string, 1: list<string|int|float>}> $operation 接收 WATCH 所在连接。
     */
    public function transaction(array $keys, Closure $operation): TransactionResult
    {
        $this->purpose(Purpose::TRANSACTION);
        if ($this->watching) {
            throw new RedisException('transaction_reentry', 'NOT_STARTED', 'Redis 事务不能重入');
        }
        if (!array_is_list($keys) || count($keys) > 1000) {
            throw new InvalidArgumentException('WATCH 键必须是有界列表');
        }
        foreach ($keys as $key) {
            if (!is_string($key) || $key === '') {
                throw new InvalidArgumentException('WATCH 键必须是非空字符串');
            }
        }
        return $this->session(function (RedisSession $session) use ($keys, $operation): TransactionResult {
            $session->watch($keys);
            $this->watching = true;
            try {
                $commands = $operation($this);
                if (!is_array($commands)) {
                    throw new InvalidArgumentException('Redis 事务回调必须返回命令列表');
                }
                $this->lease->resource();
                $this->watching = false;
                return $session->transaction($this->commands($commands));
            } finally {
                $this->watching = false;
                $session->unwatch();
            }
        });
    }

    /**
     * 执行应用可信 Lua；服务错误不保证回滚脚本已做出的写入。
     *
     * @param list<string> $keys 脚本访问的非空键。
     * @param list<string|int|float> $arguments 传入 ARGV 的数据，不接受外部提供的脚本源码。
     */
    public function script(string $script, array $keys = [], array $arguments = []): mixed
    {
        $this->purpose(Purpose::SCRIPT);
        if ($script === '' || strlen($script) > 1048576 || !array_is_list($keys) || !array_is_list($arguments)) {
            throw new InvalidArgumentException('Redis 脚本或参数格式无效');
        }
        foreach ($keys as $key) {
            if (!is_string($key) || $key === '') {
                throw new InvalidArgumentException('脚本键必须是非空字符串');
            }
        }
        $this->arguments($arguments);
        return $this->session(static fn (RedisSession $session): mixed => $session->script($script, $keys, $arguments));
    }

    /** 读取服务端 CLIENT ID，供实际物理连接身份核验；不是应用账号身份。 */
    public function identity(): int
    {
        return (int) $this->session(static fn (RedisSession $session): mixed => $session->command('CLIENT', ['ID']));
    }

    /** 停止当前租约；作用域关闭也会收尾，归还后不能继续使用本连接。 */
    public function close(): void
    {
        $this->lease->stop();
    }

    private function commands(array $commands): array
    {
        if (!array_is_list($commands) || count($commands) > 1000) {
            throw new InvalidArgumentException('命令批次必须是至多 1000 项的列表');
        }
        $validated = [];
        foreach ($commands as $command) {
            if (!is_array($command) || !array_is_list($command) || count($command) !== 2 || !is_string($command[0]) || !is_array($command[1])) {
                throw new InvalidArgumentException('批次命令需要名称与参数列表');
            }
            $validated[] = [$this->validateCommand($command[0], $command[1], false), $command[1]];
        }
        return $validated;
    }

    private function validateCommand(string $command, array $arguments, bool $blocking): string
    {
        $command = strtoupper($command);
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/D', $command) || in_array($command, self::STATEFUL, true)) {
            throw new RedisException('unmanaged_command', 'NOT_STARTED', '连接状态、脚本和管理命令必须使用对应的受管入口');
        }
        $this->arguments($arguments);
        if (!$blocking && (in_array($command, self::BLOCKING_COMMANDS, true)
            || (in_array($command, ['XREAD', 'XREADGROUP'], true) && $this->hasBlockOption($command, $arguments)))) {
            throw new RedisException('blocking_command', 'NOT_STARTED', '阻塞命令必须使用独立用途池');
        }
        return $command;
    }

    private function hasBlockOption(string $command, array $arguments): bool
    {
        $index = $command === 'XREADGROUP' ? 3 : 0;
        for (; $index < count($arguments); $index++) {
            $option = strtoupper((string) $arguments[$index]);
            if ($option === 'STREAMS') {
                return false;
            }
            if ($option === 'BLOCK') {
                return true;
            }
            if ($option === 'COUNT') {
                $index++;
            }
        }
        return false;
    }

    private function arguments(array $arguments): void
    {
        if (!array_is_list($arguments)) {
            throw new InvalidArgumentException('Redis 参数必须是列表');
        }
        foreach ($arguments as $argument) {
            if ((!is_string($argument) && !is_int($argument) && !is_float($argument)) || (is_float($argument) && !is_finite($argument))) {
                throw new InvalidArgumentException('Redis 参数只接受字符串、整数和有限浮点数');
            }
        }
    }

    private function purpose(string $purpose): void
    {
        if ($this->purpose !== $purpose) {
            throw new RedisException('wrong_purpose', 'NOT_STARTED', 'Redis 操作与连接用途不符');
        }
    }

    private function session(Closure $operation): mixed
    {
        return $this->lease->hold(static function (ReusableResource $resource) use ($operation): mixed {
            if (!$resource instanceof RedisSession) {
                throw new RedisException('invalid_session', 'NOT_STARTED', '租约中没有 Redis 会话');
            }
            return $operation($resource);
        });
    }
}
