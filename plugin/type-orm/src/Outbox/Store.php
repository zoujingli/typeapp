<?php

declare(strict_types=1);

namespace Type\Orm\Outbox;

use Type\Orm\Connection;
use Type\Orm\DatabaseException;
use Type\Orm\Migration\Migration;
use Type\Orm\SqlDialect;

/** 业务事务中的消息意图及投递凭据；未知效果保留对账，不承诺跨系统原子事务。 */
final class Store
{
    private string $table;
    private int $leaseMilliseconds;
    private int $retentionSeconds;
    /**
     * 声明 Outbox 表与保留策略，构造不执行建表。
     *
     * @param int $leaseMilliseconds 领取租约毫秒数，范围 10 至 3600000。
     * @param int $retentionSeconds 发布后重放窗口秒数，范围 1 至 31536000。
     */
    public function __construct(string $table = 'type_outbox', int $leaseMilliseconds = 30000, int $retentionSeconds = 604800)
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,47}$/D', $table) || $leaseMilliseconds < 10 || $leaseMilliseconds > 3600000
            || $retentionSeconds < 1 || $retentionSeconds > 31536000) {
            throw new DatabaseException('Outbox 表、租约或保留窗口无效');
        }
        $this->table = $table;
        $this->leaseMilliseconds = $leaseMilliseconds;
        $this->retentionSeconds = $retentionSeconds;
    }

    /** 返回建表迁移供应用加入完整计划，不直接修改数据库。 */
    public function migration(string $driver, string $version): Migration
    {
        if (!in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new DatabaseException('Outbox 驱动不支持');
        }
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB' : '';
        return new Migration($version, '创建事务消息意图与消费凭据', [
            'CREATE TABLE ' . $this->table . ' (id VARCHAR(128) PRIMARY KEY, topic VARCHAR(128) NOT NULL, version INTEGER NOT NULL, payload TEXT NOT NULL, context TEXT NOT NULL, '
                . 'fingerprint VARCHAR(64) NOT NULL, state VARCHAR(16) NOT NULL, token VARCHAR(64) NOT NULL, lease_until BIGINT NOT NULL, attempts INTEGER NOT NULL, '
                . 'created_at BIGINT NOT NULL, published_at BIGINT NULL, accepted_receipt TEXT NULL, consumed_receipt TEXT NULL, consumed_at BIGINT NULL, replay_reason TEXT NULL)' . $engine,
            'CREATE INDEX ' . $this->table . '_pending ON ' . $this->table . ' (state, lease_until, created_at)',
        ], $driver !== 'mysql');
    }

    /**
     * 在业务同一事务登记意图；同 ID 同内容返回 false，不同内容拒绝。
     *
     * @param array<array-key, mixed> $payload 仅允许有限 JSON 数据。
     * @param array<string, string> $context 显式上下文，连同载荷最多 60000 字节。
     */
    public function enqueue(Connection $connection, string $id, string $topic, int $version, array $payload, array $context = []): bool
    {
        if ($connection->transactionDepth() === 0) {
            throw new DatabaseException('Outbox 意图必须与业务写入处于同一事务');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $id) || !preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $topic) || $version < 1) {
            throw new DatabaseException('Outbox 消息身份无效');
        }
        self::data($payload, 0);
        foreach ($context as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new DatabaseException('Outbox 上下文只接受明确字符串');
            }
        }
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
        $metadata = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($encoded) + strlen($metadata) > 60000) {
            throw new DatabaseException('Outbox 载荷超限');
        }
        $fingerprint = hash('sha256', json_encode([$topic, $version, $encoded, $metadata], JSON_THROW_ON_ERROR));
        $existing = $connection->table($this->table)->where('id', '=', $id)->first();
        if ($existing !== null) {
            if (!hash_equals($existing['fingerprint'], $fingerprint)) {
                throw new DatabaseException('相同 Outbox ID 对应不同消息');
            }
            return false;
        }
        $connection->table($this->table)->insert(['id' => $id, 'topic' => $topic, 'version' => $version, 'payload' => $encoded, 'context' => $metadata,
            'fingerprint' => $fingerprint, 'state' => 'pending', 'token' => '', 'lease_until' => 0, 'attempts' => 0, 'created_at' => $this->now($connection)]);
        return true;
    }

    /**
     * 在专属短事务领取待投递或租约过期记录，递增次数并生成新 token。
     *
     * @param int $limit 1 至 1000 条。
     * @return list<Record>
     */
    public function claim(Connection $connection, int $limit = 100): array
    {
        $this->limit($limit);
        if ($connection->transactionDepth() !== 0) {
            throw new DatabaseException('Outbox relay 必须独立使用短事务领取');
        }
        return $connection->transaction(function (Connection $transaction) use ($limit): array {
            $now = $this->now($transaction);
            $dialect = new SqlDialect($transaction->driverName(), $transaction->serverVersion());
            $sql = 'SELECT * FROM ' . $dialect->identifier($this->table) . ' WHERE (state = ? OR (state = ? AND lease_until <= ?)) ORDER BY created_at, id LIMIT ' . $limit;
            if ($transaction->driverName() !== 'sqlite') {
                $sql .= ' FOR UPDATE SKIP LOCKED';
            }
            $records = [];
            foreach ($transaction->query($sql, ['pending', 'claimed', $now]) as $row) {
                $token = bin2hex(random_bytes(16));
                $transaction->table($this->table)->where('id', '=', $row['id'])->update(['state' => 'claimed', 'token' => $token, 'lease_until' => $now + $this->leaseMilliseconds,
                    'attempts' => (int) $row['attempts'] + 1]);
                $row['token'] = $token;
                $row['attempts'] = (int) $row['attempts'] + 1;
                $records[] = new Record($row);
            }
            return $records;
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /** 在事务外以有效 token 和未过期租约登记实际接受凭据，失去领取权返回 false。 */
    public function accepted(Connection $connection, Record $record, string $receipt): bool
    {
        if ($connection->transactionDepth() !== 0 || $receipt === '' || strlen($receipt) > 2000) {
            throw new DatabaseException('Outbox 发布凭据必须独立登记且有界');
        }
        $now = $this->now($connection);
        return $connection->table($this->table)->where('id', '=', $record->id())->where('state', '=', 'claimed')->where('token', '=', $record->token())->where('lease_until', '>', $now)
            ->update(['state' => 'published', 'accepted_receipt' => $receipt, 'published_at' => $now, 'lease_until' => 0, 'token' => '']) === 1;
    }

    /** 把消费凭据与业务效果放在同一活动事务登记，已有凭据返回 false。 */
    public function consumed(Connection $connection, string $id, string $receipt): bool
    {
        if ($connection->transactionDepth() === 0 || $receipt === '' || strlen($receipt) > 2000) {
            throw new DatabaseException('消费凭据必须与业务副作用同事务登记');
        }
        return $connection->table($this->table)->where('id', '=', $id)->whereNull('consumed_receipt')->update(['consumed_receipt' => $receipt, 'consumed_at' => $this->now($connection)]) === 1;
    }

    /**
     * 按稳定消息 ID 读取现状及凭据；缺失返回 null。
     *
     * @return array<string, mixed>|null
     */
    public function status(Connection $connection, string $id): ?array
    {
        return $connection->table($this->table)->where('id', '=', $id)->first();
    }

    /** 凭人工说明重放保留期内的已发布记录，保留原消息 ID 供目标幂等。 */
    public function replay(Connection $connection, string $id, string $reason): bool
    {
        if (trim($reason) === '' || strlen($reason) > 2000) {
            throw new DatabaseException('Outbox 重放需要有界人工说明');
        }
        $now = $this->now($connection);
        return $connection->table($this->table)->where('id', '=', $id)->where('state', '=', 'published')->where('published_at', '>', $now - $this->retentionSeconds * 1000)
            ->update(['state' => 'pending', 'replay_reason' => $reason, 'lease_until' => 0]) === 1;
    }

    /** 只删除已发布、已消费且超过重放窗口的记录；未知效果保留对账。 */
    public function collect(Connection $connection, int $limit = 100): int
    {
        $this->limit($limit);
        $before = $this->now($connection) - $this->retentionSeconds * 1000;
        $rows = $connection->table($this->table)->where('state', '=', 'published')->whereNull('consumed_receipt', true)->where('published_at', '<=', $before)->orderBy('id')->limit($limit)->get();
        $count = 0;
        foreach ($rows as $row) {
            $count += $connection->table($this->table)->where('id', '=', $row['id'])->where('state', '=', 'published')->whereNull('consumed_receipt', true)->where('published_at', '<=', $before)->delete();
        }
        return $count;
    }

    private function now(Connection $connection): int
    {
        $sql = match ($connection->driverName()) {
            'mysql' => 'SELECT CAST(UNIX_TIMESTAMP(CURRENT_TIMESTAMP(3)) * 1000 AS SIGNED) AS value',
            'pgsql' => 'SELECT CAST(EXTRACT(EPOCH FROM clock_timestamp()) * 1000 AS BIGINT) AS value',
            'sqlite' => "SELECT CAST((julianday('now') - 2440587.5) * 86400000 AS INTEGER) AS value",
        };
        return (int) $connection->query($sql)[0]['value'];
    }
    private function limit(int $limit): void
    {
        if ($limit < 1 || $limit > 1000) {
            throw new DatabaseException('Outbox 批次必须为 1 至 1000');
        }
    }
    private static function data(mixed $value, int $depth): void
    {
        if ($depth > 24) {
            throw new DatabaseException('Outbox 数据深度超限');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                self::data($item, $depth + 1);
            } return;
        }
        if ($value !== null && !is_string($value) && !is_int($value) && !is_bool($value) && (!is_float($value) || !is_finite($value))) {
            throw new DatabaseException('Outbox 只接受明确 JSON 数据');
        }
    }
}
