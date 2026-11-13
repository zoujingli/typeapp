<?php

declare(strict_types=1);

namespace app\center\model;

use Type\Orm\Connection;
use Type\Orm\Model;

/**
 * MQTT 节点运行观察投影；只保存最近一次有界采样和上一代运行值。
 *
 * 该表不是协议握手状态，也不是业务历史；写入必须保留 64 槽位、运行代次
 * 和 PostgreSQL 原子条件更新，因此原子 SQL 只封装在本模型的观察入口内。
 *
 * @property int $slot 观察槽位，范围为 0 至 63。
 * @property string $kind 观察类型，目前固定为 broker。
 * @property string $node_id MQTT 节点标识。
 * @property string $run_id 节点运行代次。
 * @property int $observed_at 最近一次采样时间，UTC Unix 秒。
 * @property string $metrics_json 当前采样的 JSON 数值对象。
 * @property null|string $previous_json 同一运行代次的上一份采样，换代时为空。
 * @method static \Type\Orm\ModelQuery<static> query(string $alias = '')
 * @method static static|null find(int|string $id)
 * @method static static create(array $values)
 */
final class CenterRuntimeMetric extends Model
{
    protected string $table = 'center_runtime_metric';

    protected bool $autoIncrement = false;

    protected string $pk = 'slot';

    protected array $schema = [
        'slot' => ['type' => 'integer', 'comment' => '观察槽位，范围为 0 至 63。'],
        'kind' => ['type' => 'string', 'comment' => '观察类型，目前固定为 broker。'],
        'node_id' => ['type' => 'string', 'comment' => 'MQTT 节点标识。'],
        'run_id' => ['type' => 'string', 'comment' => '节点运行代次。'],
        'observed_at' => ['type' => 'integer', 'comment' => '最近一次采样时间，UTC Unix 秒。'],
        'metrics_json' => ['type' => 'string', 'visible' => false, 'comment' => '当前采样的 JSON 数值对象。'],
        'previous_json' => ['type' => 'string', 'nullable' => true, 'visible' => false, 'comment' => '同一运行代次的上一份采样，换代时为空。'],
    ];

    /**
     * 在同一写连接内记录一份节点采样，保留槽位容量和运行代次 CAS 语义。
     *
     * @throws \RuntimeException 槽位耗尽、旧运行代次覆盖或原子更新失败
     */
    public static function recordObservation(Connection $connection, string $kind, string $nodeId, string $runId, int $observedAt, string $metricsJson, bool $initial): void
    {
        $current = self::query()->master()->onConnection($connection)
            ->where('kind', '=', $kind)->where('node_id', '=', $nodeId)->first();
        if ($current === null) {
            for ($slot = 0; $slot < 64; ++$slot) {
                $inserted = $connection->execute(
                    'INSERT INTO center_runtime_metric (slot, kind, node_id, run_id, observed_at, metrics_json, previous_json) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, NULL) ON CONFLICT DO NOTHING',
                    [$slot, $kind, $nodeId, $runId, $observedAt, $metricsJson]
                );
                if ($inserted === 1) {
                    return;
                }
                if (self::query()->master()->onConnection($connection)->where('kind', '=', $kind)->where('node_id', '=', $nodeId)->first() !== null) {
                    break;
                }
            }
        }
        $parameters = $initial
            ? [$runId, $runId, $observedAt, $metricsJson, $kind, $nodeId]
            : [$runId, $runId, $observedAt, $metricsJson, $kind, $nodeId, $runId];
        $updated = $connection->execute(
            'UPDATE center_runtime_metric SET previous_json = CASE WHEN run_id = ? THEN metrics_json ELSE NULL END, '
            . 'run_id = ?, observed_at = ?, metrics_json = ? WHERE kind = ? AND node_id = ?' . ($initial ? '' : ' AND run_id = ?'),
            $parameters
        );
        if ($updated !== 1) {
            throw new \RuntimeException('operations_observation_owner_or_capacity');
        }
    }
}
