<?php

declare(strict_types=1);

namespace app\iot\service;

use RuntimeException;
use Type\Mqtt\Client;
use Type\Mqtt\CommitResult;
use Type\Mqtt\Message;
use Type\Mqtt\PendingCommit;
use Type\Mqtt\PostgresStore;
use Type\Orm\Connection;
use Type\Runtime\ProcessSignals;

/** 从标准MQTT共享订阅接收业务，取得同步持久证明后才发送回执并确认原交付。 */
final class IngestionWorker
{
    private bool $stopping = false;
    private bool $running = false;
    private bool $quarantined = false;
    private ?PendingCommit $pending = null;
    private int $received = 0;
    private int $accepted = 0;
    private int $rejected = 0;
    private int $discarded = 0;
    private int $acknowledged = 0;
    private int $observed = 0;
    private int $startedAt;
    private string $runId;
    private float $nextObservation = 0.0;
    private bool $initialObservation = true;
    private int $receiptCount = 0;
    private float $receiptLatencyTotalMs = 0.0;
    private float $receiptLatencyMaximumMs = 0.0;

    /**
     * 一个实例拥有一个客户端及最多一个有硬截止的数据库工作进程。
     * 客户端使用稳定Client ID、30秒保活、86400秒会话、1MiB最大报文与至多32个接收交换。
     * @param list<string> $command 当前编译应用的iot:ingest-store角色；子进程不经shell启动。
     */
    public function __construct(private Client $client, private array $command, private string $instance = '')
    {
        if ($command === [] || !array_is_list($command)) {
            throw new \InvalidArgumentException('ingestion_worker_command_invalid');
        }
        if ($instance !== '' && preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/D', $instance) !== 1) {
            throw new \InvalidArgumentException('ingestion_instance_invalid');
        }
        $this->startedAt = hrtime(true);
        $this->runId = bin2hex(random_bytes(16));
    }

    /**
     * 在独立进程中运行；网络失败或持久结果未知即退出，由监督者以相同身份恢复。
     * 不自动换Client ID，不重试未知数据库提交；原交付由Broker保留，业务账本提供去重。
     * @throws RuntimeException 无法取得同步证明、发送失败或远端清理不能确认。
     */
    public function run(): void
    {
        if ($this->running || $this->stopping) {
            throw new \LogicException('ingestion_worker_already_used');
        }
        $this->running = true;
        $signals = new ProcessSignals();
        try {
            $signals->attach(function (): void {
                $this->stop();
            });
            // 会话恢复含授权和同步持久确认，连接截止与应用Broker的十秒握手预算一致。
            $this->client->connect(false, 10.0);
            $this->client->subscribe('$share/ingestion/iot/+/devices/+/epochs/+/up', 1);
            $nextDispatch = 0.0;
            while (!$this->stopping) {
                $signals->dispatch();
                if (hrtime(true) / 1e9 >= $nextDispatch) {
                    $nextDispatch = hrtime(true) / 1e9 + 1.0;
                    $value = $this->persist(['action' => 'command_claim'], $signals);
                    $transfer = $value['transfer'] ?? null;
                    if ($transfer !== null && !$this->stopping) {
                        $this->client->publish(new Message($transfer['topic'], $transfer['payload'], "\x02" . pack('N', 30), 1));
                    }
                    $switch = $value['switch'] ?? null;
                    if ($switch !== null && !$this->stopping && $switch['deadline_at'] > time()) {
                        $this->client->publish(new Message($switch['topic'], $switch['payload'], "\x02" . pack('N', $switch['deadline_at'] - time()), 1));
                    }
                    $command = $value['command'] ?? null;
                    if ($command !== null && !$this->stopping) {
                        $state = 'deadline_elapsed';
                        $reason = 0;
                        if ($command['deadline_at'] > time()) {
                            // 每个节点的原子领取先取得同步证明；MQTT过期继续使用本次原期限。
                            $properties = "\x02" . pack('N', $command['deadline_at'] - time());
                            try {
                                $reason = $this->client->publish(new Message($command['topic'], $command['payload'], $properties, 1));
                                $state = 'mqtt_acknowledged';
                            } catch (\Throwable $transportFailure) {
                                $this->persist(['action' => 'command_transport', 'id' => $command['id'], 'attempt_id' => $command['attempt_id'], 'reason' => 0, 'state' => 'transport_unknown'], $signals);
                                throw $transportFailure;
                            }
                        }
                        $this->persist(['action' => 'command_transport', 'id' => $command['id'], 'attempt_id' => $command['attempt_id'], 'reason' => $reason, 'state' => $state], $signals);
                    }
                }
                $delivery = $this->client->receive(0.25);
                if ($delivery === null || $this->stopping) {
                    continue;
                }
                $this->received++;
                $receivedAt = hrtime(true);
                $message = $delivery['message'];
                if (strlen($message->payload) > 16384) {
                    $this->discarded++;
                    if ($delivery['receipt'] !== '') {
                        $this->client->acknowledge($delivery['receipt'], 0x99);
                    }
                    continue;
                }
                $value = $this->persist([
                    'action' => 'ingest', 'topic' => $message->topic,
                    'payload' => base64_encode($message->payload), 'qos' => $message->qos, 'received_at' => time(),
                ], $signals);
                if ($this->stopping) {
                    break;
                }
                $receipt = $value['receipt'] ?? null;
                $observation = in_array($value['code'] ?? '', ['device_status_observed', 'device_status_ignored', 'command_query_observed'], true);
                if ($observation) {
                    $this->observed++;
                } elseif ($receipt === null) {
                    $this->discarded++;
                } else {
                    $this->client->publish(new Message($value['topic'], json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), '', 1));
                    $latency = (hrtime(true) - $receivedAt) / 1e6;
                    $this->receiptCount++;
                    $this->receiptLatencyTotalMs += $latency;
                    $this->receiptLatencyMaximumMs = max($this->receiptLatencyMaximumMs, $latency);
                    if (($receipt['status'] ?? 'accepted') === 'accepted') {
                        $this->accepted++;
                    } else {
                        $this->rejected++;
                    }
                }
                if ($delivery['receipt'] !== '') {
                    $this->client->acknowledge($delivery['receipt'], $receipt === null && !$observation ? 0x99 : 0);
                    $this->acknowledged++;
                }
            }
        } catch (\Throwable $failure) {
            if (!$this->stopping || $this->quarantined) {
                throw $failure;
            }
        } finally {
            $this->client->close(false);
            if ($this->pending !== null) {
                $this->pending->cancel();
                do {
                    $cleanup = $this->pending->poll();
                    if ($cleanup === null) {
                        usleep(10000);
                    }
                } while ($cleanup === null);
                $this->quarantined = !$cleanup->released;
                $this->pending = null;
            }
            $this->running = false;
            if ($this->stopping && !$this->quarantined && !$this->initialObservation) {
                try {
                    $this->nextObservation = 0.0;
                    $this->persist(['action' => 'operations_observe'], $signals);
                } catch (\Throwable) {
                    // 最后采样无法确认时仍保留旧观察直至过期，不伪造正常退出或重试业务提交。
                }
            }
            $signals->close();
            if ($this->quarantined) {
                throw new RuntimeException('ingestion_backend_quarantined');
            }
        }
    }

    /** 同一持久工作进程边界服务接收和指令事实；未知提交继续精确清理并隔离。 */
    private function persist(array $request, ProcessSignals $signals): array
    {
        $sampled = $this->instance !== '' && hrtime(true) / 1e9 >= $this->nextObservation;
        if ($sampled) {
            $request['observation'] = ['node_id' => $this->instance, 'run_id' => $this->runId, 'initial' => $this->initialObservation, 'metrics' => $this->statistics()];
            // 客户端内部资源由客户端自己拥有；不传入采样记录或扩大内部IPC。
            unset($request['observation']['metrics']['client']);
        }
        $this->pending = new PendingCommit($this->command, ['operation_id' => bin2hex(random_bytes(16))] + $request);
        do {
            $signals->dispatch();
            $result = $this->pending->poll();
            if ($result === null) {
                usleep(10000);
            }
        } while ($result === null);
        $this->pending = null;
        $this->quarantined = !$result->released;
        if ($this->quarantined || (!$this->stopping && $result->state !== 'committed')) {
            throw new RuntimeException($this->quarantined ? 'ingestion_backend_quarantined' : 'ingestion_commit_unconfirmed');
        }
        if ($sampled && $result->state === 'committed') {
            $this->initialObservation = false;
            $this->nextObservation = hrtime(true) / 1e9 + 5.0;
        }
        return $result->value;
    }

    /** 停止不发送成功回执或入站确认；当前数据库工作仍完成精确后端清理。 */
    public function stop(): void
    {
        $this->stopping = true;
        $this->client->stop();
        $this->pending?->cancel();
    }

    /** @return array<string,mixed> 本实例计数及有界资源，不含身份秘密、报文或数据库连接值。 */
    public function statistics(): array
    {
        return ['received' => $this->received, 'accepted' => $this->accepted, 'rejected' => $this->rejected,
            'discarded' => $this->discarded, 'acknowledged' => $this->acknowledged, 'observed' => $this->observed, 'pending' => $this->pending !== null,
            'quarantined' => $this->quarantined, 'running' => $this->running, 'client' => $this->client->statistics(),
            'receiptCount' => $this->receiptCount, 'receiptLatencyTotalMs' => $this->receiptLatencyTotalMs,
            'receiptLatencyMaximumMs' => $this->receiptLatencyMaximumMs, 'uptimeMs' => (hrtime(true) - $this->startedAt) / 1e6];
    }

    /** 应用内部持久角色；沿用组件IPC、操作身份、同步证明及异常清理，无Broker私表访问。 */
    public static function work(PostgresStore $store, string $endpoint): void
    {
        PendingCommit::work($store, $endpoint, static function (array $request) use ($store): CommitResult {
            $operationId = is_string($request['operation_id'] ?? null) ? $request['operation_id'] : '';
            if (($request['action'] ?? '') === 'operations_observe') {
                return $store->transaction($operationId, static function (Connection $transaction) use ($request): array {
                    self::observe($transaction, $request);
                    return [];
                });
            }
            if (($request['action'] ?? '') === 'command_claim') {
                return $store->transaction($operationId, static function (Connection $transaction) use ($request): array {
                    \app\common\service\RoleService::lockAuthorization($transaction);
                    self::observe($transaction, $request);
                    return ['transfer' => TransferService::claim($transaction), 'switch' => DeviceService::claimModelSwitch($transaction), 'command' => CommandService::claim($transaction)];
                });
            }
            if (($request['action'] ?? '') === 'command_transport' && is_string($request['id'] ?? null) && is_int($request['reason'] ?? null)
                && is_string($request['attempt_id'] ?? null) && is_string($request['state'] ?? null)) {
                return $store->transaction($operationId, static function (Connection $transaction) use ($request): array {
                    self::observe($transaction, $request);
                    return CommandService::transport($transaction, $request['id'], $request['reason'], $request['attempt_id'], $request['state']);
                });
            }
            if (($request['action'] ?? '') !== 'ingest' || !is_string($request['topic'] ?? null) || strlen($request['topic']) > 256
                || !is_string($request['payload'] ?? null) || strlen($request['payload']) > 21848
                || !in_array($request['qos'] ?? null, [0, 1], true) || !is_int($request['received_at'] ?? null) || $request['received_at'] < 1) {
                return new CommitResult($operationId, 'rejected', 0x83);
            }
            $payload = base64_decode($request['payload'], true);
            if ($payload === false || base64_encode($payload) !== $request['payload'] || strlen($payload) > 16384) {
                return new CommitResult($operationId, 'rejected', 0x83);
            }
            return $store->transaction($operationId, static function (Connection $transaction) use ($request, $payload): array {
                self::observe($transaction, $request);
                return IngestionService::accept($transaction, $request['topic'], $payload, $request['qos'], $request['received_at']);
            });
        });
    }

    private static function observe(Connection $connection, array $request): void
    {
        $observation = $request['observation'] ?? null;
        if (is_array($observation)) {
            OperationsService::observe($connection, 'ingestion', $observation['node_id'], $observation['run_id'], $observation['metrics'], $observation['initial']);
        }
    }
}
