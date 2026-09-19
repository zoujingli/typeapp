<?php

declare(strict_types=1);

namespace Type\Redis;

use Throwable;
use Type\Runtime\ReusableResource;

/** @internal 原生客户端只在受管租约内操作。 */
final class RedisSession implements ReusableResource
{
    private \Redis $client;
    private RedisConfiguration $configuration;
    private bool $failed = false;
    private bool $reusable = true;
    private string $queued = '';

    public function __construct(RedisConfiguration $configuration)
    {
        $this->configuration = $configuration;
        $this->client = $configuration->connect();
    }

    public function command(string $command, array $arguments): mixed
    {
        $this->healthy();
        $this->client->clearLastError();
        try {
            $result = $this->client->rawCommand($command, ...$arguments);
            $this->checkError('REJECTED');
            return $result;
        } catch (\RedisException $error) {
            $this->failed = true;
            throw new RedisException('io_failed', 'UNKNOWN', 'Redis 操作未得到可靠结果，不自动重试', $error);
        }
    }

    public function pipeline(array $commands): array
    {
        $this->healthy();
        if ($commands === []) {
            return [];
        }
        $this->client->clearLastError();
        try {
            $this->queued = 'pipeline';
            if ($this->client->multi(\Redis::PIPELINE) === false) {
                $this->failed = true;
                throw new RedisException('pipeline_failed', 'NOT_STARTED', 'Redis pipeline 未能开始');
            }
            foreach ($commands as $command) {
                $this->client->rawCommand($command[0], ...$command[1]);
            }
            $result = $this->client->exec();
            $this->queued = '';
            $this->checkError('MAY_HAVE_APPLIED');
            if (!is_array($result)) {
                $this->failed = true;
                throw new RedisException('pipeline_failed', 'UNKNOWN', 'Redis pipeline 结果不完整');
            }
            return $result;
        } catch (\RedisException $error) {
            $this->failed = true;
            throw new RedisException('pipeline_failed', 'UNKNOWN', 'Redis pipeline 可能已执行部分写入，不自动重试', $error);
        }
    }

    public function watch(array $keys): void
    {
        $this->healthy();
        $this->client->clearLastError();
        try {
            if ($keys !== [] && !$this->client->watch(...$keys)) {
                $this->failed = true;
                throw new RedisException('watch_failed', 'NOT_STARTED', 'Redis WATCH 未能建立');
            }
            $this->checkError('REJECTED');
        } catch (\RedisException $error) {
            $this->failed = true;
            throw new RedisException('watch_failed', 'NOT_STARTED', 'Redis WATCH 连接失败', $error);
        }
    }

    public function transaction(array $commands): TransactionResult
    {
        $this->healthy();
        $this->client->clearLastError();
        try {
            $this->queued = 'transaction';
            if ($this->client->multi(\Redis::MULTI) === false) {
                $this->failed = true;
                throw new RedisException('transaction_failed', 'NOT_STARTED', 'Redis MULTI 未能开始');
            }
            foreach ($commands as $command) {
                $this->client->rawCommand($command[0], ...$command[1]);
            }
            $result = $this->client->exec();
            $this->queued = '';
            $this->checkError('MAY_HAVE_APPLIED');
            if ($result === false) {
                return new TransactionResult(false, []);
            }
            if (!is_array($result)) {
                $this->failed = true;
                throw new RedisException('transaction_failed', 'UNKNOWN', 'Redis EXEC 没有返回完整结果');
            }
            return new TransactionResult(true, $result);
        } catch (\RedisException $error) {
            $this->failed = true;
            throw new RedisException('transaction_failed', 'UNKNOWN', 'Redis EXEC 结果未知，不自动重试', $error);
        }
    }

    public function unwatch(): void
    {
        if ($this->failed || $this->queued !== '') {
            return;
        }
        try {
            if (!$this->client->unwatch()) {
                $this->failed = true;
            }
        } catch (Throwable $error) {
            $this->failed = true;
        }
    }

    public function script(string $script, array $keys, array $arguments): mixed
    {
        $this->healthy();
        // 脚本会话保持独占并在归还时销毁，不猜测脚本的连接副作用。
        $this->reusable = false;
        $this->client->clearLastError();
        try {
            $this->client->select($this->configuration->database());
            $result = $this->client->eval($script, array_merge($keys, $arguments), count($keys));
            $this->checkError('MAY_HAVE_APPLIED');
            return $result;
        } catch (\RedisException $error) {
            $this->failed = true;
            throw new RedisException('script_failed', 'UNKNOWN', 'Redis 脚本结果未知，不自动重试', $error);
        }
    }

    public function reset(): bool
    {
        if ($this->failed || !$this->reusable || $this->queued !== '') {
            $this->close();
            return false;
        }
        try {
            $this->client->clearLastError();
            if (!$this->client->isConnected() || !$this->client->unwatch() || !$this->client->select($this->configuration->database())) {
                $this->close();
                return false;
            }
            $this->client->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);
            $this->client->setOption(\Redis::OPT_PREFIX, '');
            $this->client->setOption(\Redis::OPT_MAX_RETRIES, 0);
            return $this->client->getLastError() === null;
        } catch (Throwable $error) {
            $this->close();
            return false;
        }
    }

    private function healthy(): void
    {
        if ($this->failed) {
            throw new RedisException('session_failed', 'NOT_STARTED', 'Redis 会话已经失效，请重新借用');
        }
    }

    private function checkError(string $outcome): void
    {
        if ($this->client->getLastError() !== null) {
            $this->failed = true;
            throw new RedisException('server_error', $outcome, 'Redis 服务器拒绝命令');
        }
    }

    /** 池丢弃会话时确认关闭；异常交给池保留隔离额度，不能静默当作成功。 */
    public function close(): void
    {
        if ($this->client->isConnected()) {
            $this->client->close();
        }
    }
}
