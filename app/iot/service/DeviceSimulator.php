<?php

declare(strict_types=1);

namespace app\iot\service;

use Closure;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use stdClass;
use Type\Mqtt\Client;
use Type\Mqtt\Message;
use Type\Runtime\Deadline;
use Type\Runtime\ProcessSignals;

/**
 * 一台设备的有界TLS补传循环；只用持久原件发送，业务回执本地事务完成后才确认下行MQTT。
 * 独占内部Client，借用调用者的DeviceBuffer；连接前后发送有界缓存观察，采样调度和指令执行由应用角色负责。
 */
final class DeviceSimulator
{
    private Client $client;
    /** @var array{publish:string,subscribe:string} 当前归属的两个精确Topic。 */
    private array $topics;
    private bool $running = false;
    private bool $stopped = false;
    private bool $closed = false;
    private string $clockNonce = '';
    private float $clockRequested = 0.0;
    private float $clockMonotonic = 0.0;
    private int $clockUpper = 0;
    private int $clockLower = 0;

    /**
     * 设备和归属来自已绑定的缓存；模型只用于新采样，旧缓存的模型和原字节不改。
     * host为明确IP，caFile与peerName沿用Client的证书链/主机名验证；没有明文开关。
     * 调用者负责独占设备身份和缓存，并从预注册结果传入租户、凭据及明确的模型版本。
     * @throws InvalidArgumentException 身份、秘密、模型或TLS配置非法。
     */
    public function __construct(
        private DeviceBuffer $buffer,
        private string $tenantId,
        private int $modelVersion,
        string $credentialId,
        string $secret,
        string $host,
        int $port = 8883,
        string $caFile = '',
        string $peerName = '',
        private ?Closure $executeCommand = null,
        private array $supportedModels = []
    ) {
        if (!preg_match('/^[a-f0-9]{32}$/D', $tenantId) || !preg_match('/^[a-f0-9]{32}$/D', $credentialId)
            || !preg_match('/^[a-f0-9]{64}$/D', $secret) || $modelVersion < 1) {
            throw new InvalidArgumentException('device_simulator_configuration_invalid');
        }
        $state = $buffer->statistics();
        if (count($supportedModels) > 1000) {
            throw new InvalidArgumentException('device_supported_models_invalid');
        }
        foreach ($supportedModels as $version => $hash) {
            if (!is_int($version) || $version < 1 || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new InvalidArgumentException('device_supported_models_invalid');
            }
        }
        $this->modelVersion = $buffer->modelVersion($modelVersion);
        $this->topics = DeviceService::topics(['id' => $state['device_id'], 'tenant_id' => $tenantId, 'ownership_id' => $state['ownership_id']]);
        // 最大业务JSON16KiB之外为Topic和协议头留空间；最多接收8条待确认下行，总网络队列有界。
        $this->client = new Client(
            $host,
            $port,
            $state['device_id'],
            $state['device_id'] . ':' . $credentialId,
            $secret,
            $caFile,
            $peerName,
            30,
            86400,
            32768,
            8
        );
    }

    /**
     * 只同步写入本地缓存，断网时同样可调用；null表示额度拒绝，持久计数由缓存拥有。
     * @return array{sequence:string,model_version:int,payload:string,payload_bytes:int,content_hash:string}|null
     * @throws LogicException 模拟器已关闭；其余入队异常和未知本地提交沿用DeviceBuffer。
     */
    public function enqueue(string $type, int $sampledAt, stdClass $values, string $identifier = ''): ?array
    {
        $this->assertOpen();
        return $this->buffer->enqueue($this->modelVersion, $type, $sampledAt, $values, $identifier);
    }

    /**
     * 建立一次TLS连接，订阅精确下行，顺序补传到排空、总截止或停止；不自动重连。
     * PUBACK仅增加published；每条最多等待receiptSeconds业务回执，超时退出且原缓存保留。
     * cleanStart固定false；正常结束最多再用一秒尝试DISCONNECT，失败/停止立即释放网络。
     * signals由应用拥有；有则在循环内分发Windows原生控制事件，本对象不注册或关闭信号所有权。
     * @return array{published:int,accepted:int,rejected:int,stopped:bool,pending:int} 本次观察，不将协议发布当业务成功。
     * @throws InvalidArgumentException 预算或下行业务回执非法；非法回执不确认、不清原件。
     * @throws RuntimeException 网络、TLS或业务回执截止失败；结果可能未知，调用者保留缓存并决定下次运行。
     * @throws LogicException 同实例重入或已经关闭。
     */
    public function run(float $maximumSeconds = 30.0, float $receiptSeconds = 5.0, ?ProcessSignals $signals = null, bool $listenForCommands = false): array
    {
        $this->assertOpen();
        if ($this->running) {
            throw new LogicException('device_simulator_already_running');
        }
        if (!is_finite($maximumSeconds) || $maximumSeconds <= 0 || $maximumSeconds > 60
            || !is_finite($receiptSeconds) || $receiptSeconds <= 0 || $receiptSeconds > 60) {
            throw new InvalidArgumentException('device_simulator_budget_invalid');
        }
        $this->running = true;
        $this->stopped = false;
        $deadline = new Deadline($maximumSeconds);
        $published = 0;
        $accepted = 0;
        $rejected = 0;
        $completed = false;
        try {
            // TLS、认证与原持久会话恢复共用15秒预算，仍受本轮60秒总截止约束。
            $this->client->connect(false, self::remaining($deadline, 15.0));
            // RH=2不重放保留消息，RAP=1保留实时RETAIN标志，便于拒绝不合法的回执传输。
            $this->client->subscribe($this->topics['subscribe'], 0x29, 0, self::remaining($deadline, 5.0));
            $this->reportStatus($deadline);
            $this->clockUpper = 0;
            if ($listenForCommands) {
                $this->requestTime($deadline);
            }
            $sentReceipts = [];
            $sentModels = [];
            $nextTransferStatus = 0.0;
            while (!$deadline->expired() && !$this->stopped) {
                $signals?->dispatch();
                if (microtime(true) >= $nextTransferStatus) {
                    $this->reportTransfer($deadline);
                    $nextTransferStatus = microtime(true) + 10.0;
                }
                if ($this->clockNonce !== '' && hrtime(true) / 1e9 - $this->clockRequested > 15.0) {
                    $this->clockUpper = 0;
                    $this->clockNonce = '';
                }
                if ($listenForCommands && hrtime(true) / 1e9 - $this->clockRequested >= 20) {
                    $this->requestTime($deadline);
                }
                foreach ($this->buffer->commandReceipts(100) as $commandReceipt) {
                    if (($sentReceipts[$commandReceipt['id']] ?? '') !== $commandReceipt['result_hash']) {
                        $this->client->publish(new Message($this->topics['publish'], $commandReceipt['receipt'], '', 1), self::remaining($deadline, 5.0));
                        $sentReceipts[$commandReceipt['id']] = $commandReceipt['result_hash'];
                    }
                }
                $modelReceipts = $this->buffer->modelReceipts(100);
                foreach ($modelReceipts as $modelReceipt) {
                    if (!isset($sentModels[$modelReceipt['id']]) || microtime(true) - $sentModels[$modelReceipt['id']] >= 10.0) {
                        $this->client->publish(new Message($this->topics['publish'], $modelReceipt['receipt'], '', 1), self::remaining($deadline, 5.0));
                        $sentModels[$modelReceipt['id']] = microtime(true);
                    }
                }
                // 设备已持久切换但平台ACK未到时，新版本原件仍留本地；避免平台尚未确认而永久拒绝合法采样。
                $pending = $modelReceipts === [] ? $this->buffer->pending() : [];
                if ($pending === []) {
                    // 本地可能已提交accepted而上次下行PUBACK丢失；即使缓存排空也消费其恢复重放。
                    $replayed = $this->client->receive(self::remaining($deadline, $listenForCommands ? 0.25 : 0.1));
                    if ($replayed === null) {
                        if ($listenForCommands || $this->buffer->commandReceipts() !== [] || $modelReceipts !== [] || (int) $this->buffer->statistics()['transfer_activation_pending'] === 1) {
                            continue;
                        }
                        break;
                    }
                    $replay = $this->applyDelivery($replayed, $deadline);
                    $accepted += $replay['accepted'];
                    $rejected += $replay['rejected'];
                    continue;
                }
                $record = $pending[0];
                $this->client->publish(new Message($this->topics['publish'], $record['payload'], '', 1), self::remaining($deadline, 5.0));
                $published++;
                $receiptDeadline = new Deadline(min($receiptSeconds, self::remaining($deadline, 60.0)));
                $resolved = false;
                while (!$resolved && !$this->stopped) {
                    $signals?->dispatch();
                    if ($deadline->expired() || $receiptDeadline->expired()) {
                        throw new RuntimeException('device_simulator_receipt_timeout');
                    }
                    $delivery = $this->client->receive(min(0.25, self::remaining($deadline, 60.0), self::remaining($receiptDeadline, 60.0)));
                    if ($delivery === null) {
                        continue;
                    }
                    $receipt = $this->applyDelivery($delivery, $deadline);
                    $accepted += $receipt['accepted'];
                    $rejected += $receipt['rejected'];
                    $resolved = $receipt['sequence'] === $record['sequence'];
                }
            }
            if (!$this->stopped && !$deadline->expired()) {
                $this->reportStatus($deadline);
                $this->reportTransfer($deadline);
            }
            $completed = !$this->stopped;
        } catch (\Throwable $failure) {
            if (!$this->stopped || !$failure instanceof RuntimeException || $failure->getMessage() !== 'mqtt_client_stopped') {
                throw $failure;
            }
        } finally {
            $this->clockUpper = 0;
            $this->clockNonce = '';
            $this->client->close($completed);
            $this->running = false;
        }
        return ['published' => $published, 'accepted' => $accepted, 'rejected' => $rejected, 'stopped' => $this->stopped,
            'pending' => (int) $this->buffer->statistics()['pending_count']];
    }

    /** 取消本次网络等待；不关闭借用的缓存、不确认消息，也不阻止以后显式run。 */
    public function stop(): void
    {
        $this->stopped = true;
        $this->client->stop();
    }

    /** @return array{tenant_id:string,model_version:int,running:bool,stopped:bool,closed:bool,network:array,buffer:array} 不含凭据、Topic或原载荷。 */
    public function statistics(): array
    {
        return ['tenant_id' => $this->tenantId, 'model_version' => $this->modelVersion, 'running' => $this->running,
            'stopped' => $this->stopped, 'closed' => $this->closed, 'network' => $this->client->statistics(), 'buffer' => $this->buffer->statistics()];
    }

    /** 幂等永久关闭本模拟器；缓存仍归构造调用者负责close，可直接检查或继续离线采样。 */
    public function close(): void
    {
        $this->closed = true;
        $this->stop();
    }

    /** 完整校验与本地事务先于网络确认；重复终局回执不增加业务累计。 */
    private function applyDelivery(array $delivery, Deadline $deadline): array
    {
        $message = $delivery['message'];
        if ($message->topic !== $this->topics['subscribe'] || $message->qos !== 1 || $message->retain || strlen($message->payload) > 16384) {
            throw new InvalidArgumentException('device_simulator_receipt_transport_invalid');
        }
        // 共享JSON解析额外拒绝重复键，避免后一个同名字段覆盖前面的伪造身份。
        IngestionService::contentHash($message->payload);
        $receipt = json_decode($message->payload, true, 32, JSON_THROW_ON_ERROR);
        if (($receipt['type'] ?? '') === 'time_response') {
            $state = $this->buffer->statistics();
            $elapsed = hrtime(true) / 1e9 - $this->clockRequested;
            if (count($receipt) !== 6 || ($receipt['app_version'] ?? null) !== 1 || ($receipt['device_id'] ?? '') !== $state['device_id']
                || ($receipt['ownership_id'] ?? '') !== $state['ownership_id'] || ($receipt['nonce'] ?? '') !== $this->clockNonce
                || $this->clockNonce === '' || !is_int($receipt['server_time'] ?? null) || $receipt['server_time'] < 1 || $elapsed < 0 || $elapsed > 15.0) {
                $this->clockUpper = 0;
            } else {
                // 使用服务时间的保守上界（服务器秒粒度+完整往返），宁可提前拒绝也不越过截止启动。
                $this->clockUpper = $receipt['server_time'] + (int) ceil($elapsed) + 1;
                $this->clockLower = $receipt['server_time'];
                $this->clockMonotonic = (float) hrtime(true) / 1e9;
            }
            $this->clockNonce = '';
        } elseif (($receipt['type'] ?? '') === 'command') {
            $started = $this->buffer->beginCommand($message->payload, $this->modelVersion, $this->trustedTime(), $this->trustedTime(false));
            if ($started['execute']) {
                $finished = $this->trustedTime();
                if ($this->executeCommand !== null && $finished !== null && $finished < $receipt['deadline_at']) {
                    // 回调代表实际动作边界；抛异常可能已经产生动作，原unknown继续保存，不伪造失败。
                    $actionStarted = (float) hrtime(true) / 1e9;
                    $result = ($this->executeCommand)($receipt['identifier'], (object) $receipt['values']);
                    if (!$result instanceof stdClass) {
                        throw new RuntimeException('device_command_executor_result_invalid');
                    }
                    // 长动作完成时可信观察可能已过期；单调持续时间仍可推进开始时的保守上界，不能伪造为瞬间完成。
                    $finished += (int) ceil(max(0.0, (float) hrtime(true) / 1e9 - $actionStarted));
                    $this->buffer->completeCommand($receipt['command_id'], 'succeeded', 'executed', $result, $finished);
                }
            }
        } elseif (($receipt['type'] ?? '') === 'command_query') {
            $result = $this->buffer->queryCommand($receipt);
            $this->client->publish(new Message($this->topics['publish'], json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), '', 1), self::remaining($deadline, 5.0));
        } elseif (($receipt['type'] ?? '') === 'command_receipt_ack') {
            $this->buffer->acknowledgeCommand($receipt);
        } elseif (($receipt['type'] ?? '') === 'model_switch') {
            $this->buffer->switchModel($message->payload, $this->supportedModels);
            $this->modelVersion = $this->buffer->modelVersion($this->modelVersion);
        } elseif (($receipt['type'] ?? '') === 'model_switch_ack') {
            $this->buffer->acknowledgeModel($receipt);
        } elseif (($receipt['type'] ?? '') === 'transfer_freeze') {
            $this->buffer->freezeTransfer($message->payload);
            $this->reportTransfer($deadline);
        } elseif (($receipt['type'] ?? '') === 'transfer_status_ack') {
            // 状态确认不删除缓存，也不代表已经切换；持久冻结仅由正式归属协议解除。
        } elseif (($receipt['type'] ?? '') === 'transfer_activated_ack') {
            $this->buffer->acknowledgeTransfer($receipt);
        } else {
            $changed = $this->buffer->applyReceipt($receipt);
            $this->client->acknowledge($delivery['receipt'], 0, self::remaining($deadline, 3.0));
            return ['sequence' => $receipt['sequence'], 'accepted' => $changed && $receipt['status'] === 'accepted' ? 1 : 0,
                'rejected' => $changed && $receipt['status'] === 'rejected' ? 1 : 0];
        }
        $this->client->acknowledge($delivery['receipt'], 0, self::remaining($deadline, 3.0));
        return ['sequence' => '', 'accepted' => 0, 'rejected' => 0];
    }

    private function requestTime(Deadline $deadline): void
    {
        $state = $this->buffer->statistics();
        $this->clockNonce = bin2hex(random_bytes(16));
        $this->clockRequested = (float) hrtime(true) / 1e9;
        $payload = json_encode(['app_version' => 1, 'type' => 'time_request', 'device_id' => $state['device_id'],
            'ownership_id' => $state['ownership_id'], 'nonce' => $this->clockNonce], JSON_THROW_ON_ERROR);
        $this->client->publish(new Message($this->topics['publish'], $payload, '', 1), self::remaining($deadline, 5.0));
    }

    /** 可信观察只在本连接内使用30秒；进程重启、连接释放和单调时间异常立即失效。 */
    private function trustedTime(bool $upper = true): ?int
    {
        $elapsed = hrtime(true) / 1e9 - $this->clockMonotonic;
        if ($this->clockUpper <= 0 || $elapsed < 0 || $elapsed >= 30.0) {
            return null;
        }
        return $upper ? $this->clockUpper + (int) ceil($elapsed) : $this->clockLower + (int) floor($elapsed);
    }

    /** QoS1观察只占一个至多2KiB报文，协议确认不证明平台已观察；原采样的确认规则不变。 */
    private function reportTransfer(Deadline $deadline): void
    {
        $status = $this->buffer->transferStatus($this->supportedModels);
        if ($status !== null) {
            $this->client->publish(new Message($this->topics['publish'], json_encode($status, JSON_THROW_ON_ERROR), '', 1), self::remaining($deadline, 5.0));
        }
    }

    private function reportStatus(Deadline $deadline): void
    {
        $payload = json_encode($this->buffer->snapshot($this->modelVersion), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->client->publish(new Message($this->topics['publish'], $payload, '', 1), self::remaining($deadline, 5.0));
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new LogicException('device_simulator_closed');
        }
    }

    private static function remaining(Deadline $deadline, float $maximum): float
    {
        $remaining = $deadline->remaining() ?? 0.0;
        if ($remaining <= 0) {
            throw new RuntimeException('device_simulator_deadline');
        }
        return min($maximum, $remaining);
    }
}
