<?php

declare(strict_types=1);

namespace Type\Mqtt;

use Type\Runtime\Deadline;

/** @internal 一条连接的所有者；明文/TLS/WebSocket、帧缓冲和发送预算均随连接回收。 */
final class Connection
{
    /** 连接表键：PHP 流资源 ID 或原生 Server fd。 */
    public readonly int $id;
    public string $input = '';
    public string $output = '';
    public bool $secure = false;
    /** 专用 mTLS 入口上的连接；证书指纹、序列号与 notAfter 在握手时写入，不保存 PEM。 */
    public bool $mutualTls = false;
    public string $clientCertificateFingerprint = '';
    public string $clientCertificateSerial = '';
    /** 握手时记下的 notAfter Unix 秒；0 表示未取得，不按到期断开。 */
    public int $clientCertificateNotAfter = 0;
    public bool $connected = false;
    /** 成功认证形成的身份；与客户端协议字段、连接 owner 及持久会话代次分开。 */
    public ?AccessIdentity $identity = null;
    /** 真实认证确定的管理归属，独立于协议及接入身份。 */
    public ?string $resourceScope = null;
    /** 认证后由消费者分类，不采用客户端属性或标识前缀自报身份。 */
    public string $capacityClass = '';
    /** 原生就绪事件由本连接在关闭socket前注销；零表示没有注册。 */
    public int $eventMask = 0;
    /** 原生接纳序号；Swoole close 只回收同一序号，避免 fd 复用后误伤新连接。 */
    public int $nativeInstance = 0;
    /** @var null|\Closure(int, int): void */
    public mixed $noteNativeClosing = null;
    public bool $readPending = false;
    public int $connackRemaining = 0;
    public bool $connackSent = false;
    public bool $observed = false;
    public int $closeReason = 0x80;
    public bool $closing = false;
    public Deadline $handshake;
    public ?Deadline $partial = null;
    public ?Deadline $keepAlive = null;
    public ?Deadline $flush = null;
    public ConnectPacket $connect;
    public string $sessionId;
    public readonly string $ownerId;
    public bool $sessionPending = false;
    public bool $sessionOpening = false;
    public bool $sessionOpened = false;
    public bool $sessionManaged = false;
    public bool $sessionPresent = false;
    /** 接管隔离未完成时只重试保存屏障，不能重新打开并制造另一代所有者。 */
    public float $sessionRetryAt = 0.0;
    public int $sessionGeneration = 0;
    public int $sessionExpiry = 0;
    /** 只有合法客户端 DISCONNECT（非 0x04）取消遗嘱；服务端管理断开 0x98 仍按失联处理遗嘱并保留会话。 */
    public string $endReason = 'network_lost';
    /** 首次观察到网络结束的 Unix 秒；关闭工作等待不能刷新遗嘱期限。 */
    public float $endedAt = 0.0;
    public float $closeRetryAt = 0.0;
    public bool $replaying = false;
    public bool $draining = true;
    public float $replayAt = 0.0;
    public float $sharedAt = 0.0;
    /** 普通与共享读取轮换优先权；慢同步回调不能让空轮询持续抢占同一工作槽。 */
    public bool $sharedTurn = false;
    public string $sharedCursor = '';
    /** @var array<int,string> 尚未返回 PUBACK 的接收交换；确认后删除，不能形成永久去重缓存。 */
    public array $incoming = [];
    /** @var array<int,array{id:string,state:string,responses:int}> QoS 2 接收交换，发送 PUBCOMP 后归还标识。 */
    public array $incomingQos2 = [];
    /** @var array<int,int> 各接收交换的 PUBACK 末尾距离当前发送缓冲起点的字节数。 */
    private array $acknowledgements = [];
    /** @var array<int,array{id:string,state:string,qos:int,responses:int}> 发往本会话且未持久终结的 Packet Identifier。 */
    public array $outgoing = [];
    /** @var array<int,bool> 已持久预留但尚未装入重放窗口的标识。 */
    public array $durableIdentifiers = [];
    public bool $hadDeliveries = false;
    private int $nextIdentifier = 1;
    /** @var array<string,array{options:int,identifier:int}> 过滤器选项及标识；前缀避免数字 Topic 转为整数键。 */
    public array $subscriptions = [];
    /** @var array<int,string> 当前网络连接的入站别名，独立于会话及出站映射。 */
    private array $incomingAliases = [];
    /** @var array<string,int> 已成功排入本连接输出的出站 Topic 映射，最多 32 项。 */
    private array $outgoingAliases = [];
    /** @var list<array{snapshot_id:string,topic:string,subscription:array{options:int,identifier:int},cursor:string}> 有界保留游标；每会话最多100项。 */
    public array $retainedTopics = [];
    public bool $retainedPending = false;
    /** @var array{snapshot_id?:string,snapshot_cursor?:string,snapshot_done?:bool} 原件接管或明确丢弃后才推进的持久游标。 */
    public array $retainedAdvance = [];
    /** 真实读取后等待发送窗口的单条原件；没有为不存在的保留消息预留标识。 */
    public ?Message $retainedMessage = null;
    public string $retainedPublisher = '';
    public int $retainedOptions = 0;
    public int $retainedIdentifier = 0;
    public string $retainedFilter = '';

    /**
     * @param resource|int|null $socket 已接收的非阻塞网络流，或原生 Server 连接 fd；遗嘱投递可为空。
     * @param string $transport `stream` 为 PHP 流；`tcp`/`websocket` 为同一 Swoole Server 上的监听。
     */
    public function __construct(
        public mixed $socket,
        public readonly string $peer,
        float $handshakeSeconds,
        public readonly string $transport = 'stream',
        public mixed $native = null
    ) {
        if (!in_array($transport, ['stream', 'tcp', 'websocket'], true)) {
            throw new \InvalidArgumentException('MQTT 连接传输无效');
        }
        if ($transport === 'stream') {
            $this->id = is_resource($socket) ? (int) $socket : 0;
        } else {
            if (!is_int($socket) || $socket < 1) {
                throw new \InvalidArgumentException('MQTT 原生连接需要有效 fd');
            }
            $this->id = $socket;
        }
        $this->handshake = new Deadline($handshakeSeconds);
        $this->connect = new ConnectPacket();
        $this->sessionId = bin2hex(random_bytes(16));
        $this->ownerId = bin2hex(random_bytes(16));
    }

    /** 网络仍可读写时为真；关闭后 socket 置空，不把已回收 fd 当作活连接。 */
    public function alive(): bool
    {
        return $this->transport === 'stream' ? is_resource($this->socket) : $this->socket !== null;
    }

    /** 观察与统计使用的传输名称；WebSocket 的 TLS 记为 wss。 */
    public function transportName(): string
    {
        if ($this->transport === 'websocket') {
            return $this->secure ? 'wss' : 'ws';
        }
        if ($this->mutualTls) {
            return 'mtls';
        }
        return $this->secure ? 'tls' : 'tcp';
    }

    /** 预留当前发送窗口中的标识；超过客户端 Receive Maximum 或本组件 32 条上限时拒绝接管。 */
    public function reserve(string $deliveryId, int $qos = 1): int
    {
        if (count($this->outgoing) >= min(32, (int) ($this->connect->properties[0x21] ?? 65535))) {
            throw new ProtocolError(0x97);
        }
        for ($attempt = 0; $attempt < 65535; $attempt++) {
            $identifier = $this->nextIdentifier;
            $this->nextIdentifier = $identifier === 65535 ? 1 : $identifier + 1;
            if (!isset($this->outgoing[$identifier]) && !isset($this->durableIdentifiers[$identifier])) {
                $this->outgoing[$identifier] = ['id' => $deliveryId, 'state' => 'reserved', 'qos' => $qos, 'responses' => 1];
                return $identifier;
            }
        }
        throw new ProtocolError(0x97);
    }

    /** 解析入站别名；客户端 CONNECT 的 Topic Alias Maximum 不限制服务端接收方向。 */
    public function resolveTopic(string $topic, ?int $alias): string
    {
        if ($alias === null) {
            return $topic;
        }
        if ($alias < 1 || $alias > 32 || ($topic === '' && !isset($this->incomingAliases[$alias]))) {
            throw new ProtocolError(0x94);
        }
        if ($topic !== '') {
            $this->incomingAliases[$alias] = $topic;
        }
        return $this->incomingAliases[$alias];
    }

    /**
     * 预览或排队完整 PUBLISH；只有成功排队才建立出站别名，预览不产生连接副作用。
     * 空串表示已到期、超过接收方/服务端上限或排队失败；大小拒绝不关闭连接。
     * finishExchange 只用于持久证据表明已经开始的协议交换，仍遵守本连接别名和报文预算。
     * @param list<int> $subscriptionIdentifiers 本次交付标识，包含在别名回退及完整报文大小核验中。
     */
    public function publication(Message $message, int $identifier, int $qos, int $maximumBytes, bool $enqueue = false, bool $duplicate = false, bool $retain = false, bool $finishExchange = false, array $subscriptionIdentifiers = []): string
    {
        $maximum = min($maximumBytes, (int) ($this->connect->properties[0x27] ?? 268435455));
        $key = 't:' . $message->topic;
        $aliasMaximum = $this->connect->version === 5 ? min(32, (int) ($this->connect->properties[0x22] ?? 0)) : 0;
        $known = isset($this->outgoingAliases[$key]);
        $alias = $known ? $this->outgoingAliases[$key] : (count($this->outgoingAliases) < $aliasMaximum ? count($this->outgoingAliases) + 1 : 0);
        $packet = $message->packet($this->connect->version, $identifier, $duplicate, $qos, $alias, $known, $retain, $finishExchange, $subscriptionIdentifiers);
        if ($alias > 0 && !$known && strlen($packet) > $maximum) {
            $alias = 0;
            $packet = $message->packet($this->connect->version, $identifier, $duplicate, $qos, 0, false, $retain, $finishExchange, $subscriptionIdentifiers);
        }
        if ($packet === '' || strlen($packet) > $maximum) {
            return '';
        }
        if ($enqueue) {
            if (!$this->send($packet)) {
                return '';
            }
            if ($alias > 0 && !$known) {
                $this->outgoingAliases[$key] = $alias;
            }
        }
        return $packet;
    }

    /** 仅完整 PUBACK/PUBCOMP 已写入 socket 后归还接收标识；排队期间仍属于同一交换。 */
    public function acknowledge(int $identifier, int $header = 0x40): void
    {
        if ($this->send(chr($header) . "\x02" . pack('n', $identifier))) {
            $this->acknowledgements[$identifier] = strlen($this->output);
        }
    }

    /** 仅完整合法的客户端控制报文重置 Keep Alive；半包字节不能延长连接。 */
    public function activity(): void
    {
        $this->keepAlive = $this->connect->keepAlive === 0 ? null : new Deadline($this->connect->keepAlive * 1.5);
    }

    /** 排队完整控制响应并设定单调发送预算；受客户端声明的最大报文长度约束。 */
    public function send(string $packet): bool
    {
        if (!$this->alive() || strlen($packet) > (int) ($this->connect->properties[0x27] ?? 268435455) || strlen($this->output) + strlen($packet) > 2097152) {
            $this->close();
            return false;
        }
        if ($this->output === '') {
            $this->flush = new Deadline(1.0);
        }
        $this->output .= $packet;
        return true;
    }

    /** 只写未发送后缀；不会因短写重复 CONNACK/PINGRESP。 */
    public function flush(): void
    {
        if ($this->output === '' || !$this->alive()) {
            return;
        }
        $chunk = substr($this->output, 0, 16384);
        if ($this->transport === 'stream') {
            $written = @fwrite($this->socket, $this->output, strlen($chunk));
        } else {
            $written = $this->flushNative($chunk);
        }
        if ($written === false) {
            $this->close();
            return;
        }
        $this->output = substr($this->output, $written);
        if ($this->connackRemaining > 0) {
            $this->connackRemaining = max(0, $this->connackRemaining - $written);
            $this->connackSent = $this->connackRemaining === 0;
        }
        foreach ($this->acknowledgements as $identifier => $remaining) {
            if ($remaining <= $written) {
                unset($this->acknowledgements[$identifier], $this->incoming[$identifier], $this->incomingQos2[$identifier]);
            } else {
                $this->acknowledgements[$identifier] = $remaining - $written;
            }
        }
        if ($this->output === '') {
            $this->flush = null;
            if ($this->closing) {
                $this->close();
            }
        }
    }

    /** 幂等关闭并清除包含密码的连接事实及全部缓冲。 */
    public function close(): void
    {
        if ($this->endedAt === 0.0) {
            $this->endedAt = microtime(true);
        }
        if ($this->transport === 'stream' && is_resource($this->socket)) {
            if ($this->eventMask !== 0) {
                \Swoole\Event::del($this->socket);
                $this->eventMask = 0;
            }
            fclose($this->socket);
        } elseif ($this->transport !== 'stream' && is_int($this->socket) && $this->native !== null) {
            $fd = $this->socket;
            $native = $this->native;
            $instance = $this->nativeInstance;
            $this->socket = null;
            $this->native = null;
            if ($this->noteNativeClosing instanceof \Closure) {
                ($this->noteNativeClosing)($fd, $instance);
            }
            if ($this->transport === 'websocket') {
                @$native->disconnect($fd, 1000, '');
            } else {
                @$native->close($fd, false);
            }
        }
        $this->socket = null;
        $this->native = null;
        $this->readPending = false;
        $this->input = '';
        $this->output = '';
        $this->subscriptions = [];
        $this->incoming = [];
        $this->incomingQos2 = [];
        $this->acknowledgements = [];
        $this->outgoing = [];
        $this->incomingAliases = [];
        $this->outgoingAliases = [];
        $this->retainedTopics = [];
        $this->retainedPending = false;
        $this->retainedAdvance = [];
        $this->retainedMessage = null;
        $this->retainedPublisher = '';
        $this->retainedFilter = '';
        $this->retainedIdentifier = 0;
        $this->durableIdentifiers = [];
        $this->partial = null;
        $this->keepAlive = null;
        $this->flush = null;
        $this->connect->password = null;
        $this->clientCertificateFingerprint = '';
        $this->clientCertificateSerial = '';
        $this->clientCertificateNotAfter = 0;
        $this->mutualTls = false;
        $this->closing = true;
    }

    /** 把至多 16 KiB 交给原生发送缓冲；成功视为整段已入队，失败由调用方关闭。 */
    private function flushNative(string $chunk): int|false
    {
        if ($this->native === null || !is_int($this->socket)) {
            return false;
        }
        $ok = $this->transport === 'websocket'
            ? $this->native->push($this->socket, $chunk, WEBSOCKET_OPCODE_BINARY)
            : $this->native->send($this->socket, $chunk);
        return $ok === true ? strlen($chunk) : false;
    }
}
