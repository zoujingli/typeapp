<?php

declare(strict_types=1);

namespace Type\Mqtt;

/** 一次持久操作的最终观察；unknown 必须保留事实，不得据本地主库可见性自动重试或确认。 */
final class CommitResult
{
    /**
     * @param string $state committed、rejected 或 unknown；只有 committed 允许成功协议确认。
     * @param int $reason MQTT 5 原因码；0、0x83、0x87、0x88、0x8e或0x97；连接阶段自行映射不适用于CONNACK的接管原因。
     * @param array{wal_lsn?:string,replicas?:list<array<string,mixed>>} $proof 同一主库观察到的同步备库及 WAL 位置。
     * @param bool $released 当前调用的数据库租约和作用域是否已释放。
     * @param array<string,mixed> $value 同一已证明事务读取的有界结果；包含消息时不得完整记录日志。
     */
    public function __construct(
        public readonly string $operationId,
        public readonly string $state,
        public readonly int $reason,
        public readonly array $proof = [],
        public readonly bool $released = true,
        public readonly array $value = []
    ) {
        if (!in_array($state, ['committed', 'rejected', 'unknown'], true) || !in_array($reason, [0, 0x83, 0x87, 0x88, 0x8e, 0x97], true)
            || ($state === 'committed' && ($reason !== 0 || ($proof['wal_lsn'] ?? '') === '' || ($proof['replicas'] ?? []) === []))) {
            throw new \InvalidArgumentException('MQTT 持久提交结果无效');
        }
    }

    /** @return array<string,mixed> 可通过 JSON IPC 传递的结果；value 可能含消息原件，不能作为普通日志。 */
    public function data(): array
    {
        return ['state' => $this->state, 'operation_id' => $this->operationId, 'reason' => $this->reason,
            'proof' => $this->proof, 'released' => $this->released, 'value' => $this->value];
    }
}
