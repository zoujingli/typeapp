<?php

declare(strict_types=1);

namespace Type\Mqtt;

use Swoole\Coroutine\Socket;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\Deadline;
use Type\Runtime\ExecutionOwner;

/**
 * MQTT 5 服务消费者；只产生 QoS 1，接收 QoS 0/1，业务明确确认前不发送入站 PUBACK。
 * 一个实例拥有一个连接；协程模式由建连执行者串行使用，等待持续保活，空闲时须持续调用 receive。
 * 不保存客户端持久数据库；重启后由 Broker 恢复未确认入站，业务按稳定身份去重。
 */
final class Client
{
    private mixed $socket = null;
    private ?ExecutionOwner $owner = null;
    private ?ExecutionOwner $threadOwner = null;
    private bool $connected = false;
    private bool $stopped = false;
    private string $input = '';
    private ?Deadline $partial = null;
    private ?Deadline $ping = null;
    private float $lastRead = 0.0;
    private float $lastWrite = 0.0;
    private int $effectiveKeepAlive = 30;
    private int $serverMaximum = 268435455;
    private int $serverQos = 2;
    private bool $serverRetain = true;
    private bool $serverWildcards = true;
    private bool $serverIdentifiers = true;
    private bool $serverShared = false;
    private int $nextIdentifier = 1;
    private int $waitingIdentifier = 0;
    private int $waitingType = 0;
    private ?int $response = null;
    /** @var list<array{message: Message, receipt: string, duplicate: bool, subscription_identifiers: list<int>}> */
    private array $messages = [];
    /** @var array<string,array{identifier:int,fingerprint:string}> 网络代次内的显式确认凭据。 */
    private array $incoming = [];
    /** @var array<int,string> 标识映射到仍未确认的凭据，防止活跃连接内重复交付。 */
    private array $identifiers = [];
    private int $queuedBytes = 0;
    /** @var array{message:Message,identifier:int,fingerprint:string}|null 未知出站仅在同一实例内保留。 */
    private ?array $publication = null;

    /**
     * host为明确IP，避免同步DNS解析越过等待预算；peerName可指定证书中的DNS名称。
     * TLS默认校验证书链和主机名，caFile为空时使用系统信任；allowPlaintext仅供显式调试。
     * 不预分配报文槽；排队上限为receiveMaximum条和maximumPacketBytes×receiveMaximum字节。
     * coroutine显式选择原生Socket，必须在同一协程内建连及使用；不满足能力要求时不回退。
     * @throws \InvalidArgumentException 身份、网络位置、TLS或预算非法。
     */
    public function __construct(
        private string $host,
        private int    $port,
        private string $clientId,
        private string $username,
        private string $password,
        private string $caFile = '',
        private string $peerName = '',
        private int    $keepAlive = 30,
        private int    $sessionExpiry = 86400,
        private int    $maximumPacketBytes = 1048576,
        private int    $receiveMaximum = 32,
        private bool   $allowPlaintext = false,
        private bool   $coroutine = false
    ) {
        if (@inet_pton($host) === false || $port < 1 || $port > 65535 || strlen($peerName) > 253 || str_contains($peerName, "\0")
            || $clientId === '' || $keepAlive < 1 || $keepAlive > 65535 || $sessionExpiry < 0 || $sessionExpiry > 4294967295
            || $maximumPacketBytes < 128 || $maximumPacketBytes > 1048576 || $receiveMaximum < 1 || $receiveMaximum > 32
            || ($caFile !== '' && (!is_file($caFile) || !is_readable($caFile))) || ($allowPlaintext && ($caFile !== '' || $peerName !== ''))) {
            throw new \InvalidArgumentException('MQTT 客户端身份、TLS或预算无效');
        }
        self::field($clientId);
        self::field($username);
        if (strlen($password) > 65535) {
            throw new \InvalidArgumentException('MQTT 客户端密码超限');
        }
    }

    /**
     * 建立一个明确的新网络连接，返回 CONNACK Session Present；不自动重连或重发业务。
     * 同实例已有未知QoS1时，恢复会话后只能用相同消息再次publish；Clean Start或会话不存在则重新开始协议交换。
     * @throws ProtocolError 服务端拒绝或响应非法。
     * @throws \RuntimeException TLS、网络、截止或取消失败；错误不表示先前发布未被接收。
     * @throws \LogicException 已持有连接，或所选协程模式没有协程上下文。
     */
    public function connect(bool $cleanStart = false, float $timeout = 5.0): bool
    {
        $this->owner?->assertCurrent();
        if ($this->socket !== null) {
            throw new \LogicException('MQTT 客户端已经持有连接');
        }
        $deadline = self::deadline($timeout);
        if ($this->coroutine) {
            CoroutineRuntime::assertAvailable();
            if (\Swoole\Coroutine::getCid() < 0) {
                throw new \LogicException('mqtt_client_coroutine_required');
            }
            $this->owner = new ExecutionOwner();
            $this->threadOwner = new ExecutionOwner(false);
        }
        $this->stopped = false;
        $this->resetNetwork();
        try {
            if ($this->coroutine) {
                $this->connectSocket($deadline);
            } else {
                $settings = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true,
                                       'peer_name' => $this->peerName === '' ? $this->host : $this->peerName,
                                       'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT]];
                if ($this->caFile !== '') {
                    $settings['ssl']['cafile'] = $this->caFile;
                }
                $address = str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host;
                $errorNumber = 0;
                $errorMessage = '';
                $this->socket = @stream_socket_client(
                    'tcp://' . $address . ':' . $this->port,
                    $errorNumber,
                    $errorMessage,
                    0.0,
                    STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
                    stream_context_create($settings)
                );
                if (!is_resource($this->socket) || !stream_set_blocking($this->socket, false)) {
                    throw new \RuntimeException('mqtt_client_connect_failed');
                }
                $this->waitIo($deadline, true);
                if (!$this->allowPlaintext) {
                    do {
                        $secured = @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
                        if ($secured === false) {
                            throw new \RuntimeException('mqtt_client_tls_failed');
                        }
                        if ($secured !== true) {
                            $this->waitIo($deadline, false);
                        }
                    } while ($secured !== true);
                }
            }
            $connectProperties = "\x11" . pack('N', $this->sessionExpiry) . "\x21" . pack('n', $this->receiveMaximum)
                . "\x27" . pack('N', $this->maximumPacketBytes) . "\x22\0\0";
            $body = self::field('MQTT') . "\x05" . chr($cleanStart ? 0xc2 : 0xc0) . pack('n', $this->keepAlive)
                . PacketReader::encodeVariable(strlen($connectProperties)) . $connectProperties . self::field($this->clientId)
                . self::field($this->username) . pack('n', strlen($this->password)) . $this->password;
            $this->write(self::packet(0x10, $body), $deadline);
            $frame = $this->frame($deadline, true);
            if ($frame === null || $frame['header'] !== 0x20) {
                throw new ProtocolError(0x82);
            }
            $reader = new PacketReader($frame['body']);
            $flags = $reader->integer(1);
            $reason = $reader->integer(1);
            $properties = $this->properties($reader, 'connack');
            if ($reader->remaining() !== 0 || $flags > 1 || ($flags !== 0 && ($cleanStart || $reason !== 0))) {
                throw new ProtocolError(0x82);
            }
            if (!in_array($reason, [0, 0x80, 0x81, 0x82, 0x83, 0x84, 0x85, 0x86, 0x87, 0x88, 0x89, 0x8a, 0x8c, 0x90, 0x95, 0x97, 0x99, 0x9a, 0x9b, 0x9c, 0x9d, 0x9f], true)) {
                throw new ProtocolError(0x82);
            }
            if ($reason !== 0) {
                throw new ProtocolError($reason);
            }
            $this->serverMaximum = $properties['values'][0x27] ?? 268435455;
            $this->serverQos = $properties['values'][0x24] ?? 2;
            $this->serverRetain = ($properties['values'][0x25] ?? 1) === 1;
            $this->serverWildcards = ($properties['values'][0x28] ?? 1) === 1;
            $this->serverIdentifiers = ($properties['values'][0x29] ?? 1) === 1;
            $this->serverShared = ($properties['values'][0x2a] ?? 1) === 1;
            $this->effectiveKeepAlive = $properties['values'][0x13] ?? $this->keepAlive;
            $this->connected = true;
            if ($flags === 0) {
                $this->publication = null;
            }
            return $flags === 1;
        } catch (\Throwable $failure) {
            $this->close(false);
            throw $failure;
        }
    }

    /**
     * 订阅一个过滤器，返回授予QoS；支持0/1与MQTT5订阅选项及标识，拒绝结果抛异常。
     * @throws ProtocolError 过滤器、选项、协商能力或 SUBACK 拒绝。
     */
    public function subscribe(string $filter, int $options = 1, int $subscriptionIdentifier = 0, float $timeout = 5.0): int
    {
        TopicFilter::validate($filter);
        if (str_starts_with($filter, '$share/')) {
            $separator = strpos($filter, '/', 7);
            if ($separator === false || $separator === 7 || $separator === strlen($filter) - 1
                || strpbrk(substr($filter, 7, $separator - 7), '+#') !== false) {
                throw new ProtocolError(0x82);
            }
            TopicFilter::validate(substr($filter, $separator + 1));
        }
        if (($options & 3) > 1 || ($options & 0xc0) !== 0 || (($options >> 4) & 3) > 2
            || $options < 0 || $subscriptionIdentifier < 0 || $subscriptionIdentifier > 268435455
            || (!$this->serverWildcards && strpbrk($filter, '+#') !== false) || (!$this->serverIdentifiers && $subscriptionIdentifier > 0)
            || (str_starts_with($filter, '$share/') && (!$this->serverShared || ($options & 4) !== 0))) {
            throw new ProtocolError(0x82);
        }
        $identifier = $this->reserve();
        $properties = $subscriptionIdentifier === 0 ? '' : "\x0b" . PacketReader::encodeVariable($subscriptionIdentifier);
        $granted = $this->exchange(0x82, pack('n', $identifier) . PacketReader::encodeVariable(strlen($properties)) . $properties
            . self::field($filter) . chr($options), 0x90, $identifier, $timeout);
        if ($granted > ($options & 3)) {
            $this->close(false);
            throw new ProtocolError(0x82);
        }
        return $granted;
    }

    /** 取消一个订阅并等待独立 UNSUBACK；已不存在的订阅返回0x11。 */
    public function unsubscribe(string $filter, float $timeout = 5.0): int
    {
        $identifier = $this->reserve();
        return $this->exchange(0xa2, pack('n', $identifier) . "\0" . self::field($filter), 0xb0, $identifier, $timeout);
    }

    /**
     * 等待QoS1 PUBACK后返回0/0x10，负确认抛ProtocolError；网络/超时意味着结果未知。
     * 未知交换在同实例恢复会话后仅接受完全相同的Message，使用原标识与DUP重发；不会延长消息期限。
     */
    public function publish(Message $message, float $timeout = 5.0): int
    {
        if ($message->qos !== 1 || $this->serverQos < 1 || ($message->retain && !$this->serverRetain)) {
            throw new ProtocolError($message->retain && !$this->serverRetain ? 0x9a : 0x9b);
        }
        // 在摘要和完整报文分配前按实际编码长度检查，调用方的大载荷不能制造超预算复制。
        $bodyBytes = 4 + strlen($message->topic) + strlen(PacketReader::encodeVariable(strlen($message->properties)))
            + strlen($message->properties) + strlen($message->payload);
        if ($bodyBytes > 268435455 || 1 + strlen(PacketReader::encodeVariable($bodyBytes)) + $bodyBytes > min($this->serverMaximum, $this->maximumPacketBytes)) {
            throw new ProtocolError(0x95);
        }
        $fingerprint = hash('sha256', self::field($message->topic) . pack('N', strlen($message->properties)) . $message->properties . $message->payload . ($message->retain ? '1' : '0'));
        $duplicate = $this->publication !== null;
        if ($duplicate && !hash_equals($this->publication['fingerprint'], $fingerprint)) {
            throw new \LogicException('mqtt_client_publication_unresolved');
        }
        if (!$duplicate) {
            $this->publication = ['message' => $message, 'identifier' => $this->reserve(), 'fingerprint' => $fingerprint];
        }
        $packet = $this->publication['message']->packet(5, $this->publication['identifier'], $duplicate, 1, 0, false, $message->retain, $duplicate);
        if ($packet === '') {
            $this->publication = null;
            throw new ProtocolError(0x83);
        }
        if (strlen($packet) > min($this->serverMaximum, $this->maximumPacketBytes)) {
            if (!$duplicate) {
                $this->publication = null;
            }
            throw new ProtocolError(0x95);
        }
        return $this->exchange(0x32, $packet, 0x40, $this->publication['identifier'], $timeout, true);
    }

    /**
     * 等待一条消息；普通等待截止返回null，协议/网络失败抛异常并关闭连接。
     * receipt仅对本次连接有效，QoS0为空；保留原始属性顺序，订阅标识作为交付元数据分开返回。
     * @return array{message:Message,receipt:string,duplicate:bool,subscription_identifiers:list<int>}|null
     */
    public function receive(float $timeout = 1.0): ?array
    {
        $deadline = self::deadline($timeout);
        $this->assertConnected();
        try {
            while ($this->messages === []) {
                if (!$this->pump($deadline, false)) {
                    return null;
                }
            }
            $message = array_shift($this->messages);
            $this->queuedBytes -= strlen($message['message']->payload) + strlen($message['message']->properties) + strlen($message['message']->topic);
            return $message;
        } catch (\Throwable $failure) {
            $this->close(false);
            throw $failure;
        }
    }

    /**
     * 仅显式业务确认发送PUBACK；写出完成才移除本次凭据。旧网络凭据或重复确认均拒绝。
     * @throws \LogicException 凭据不属于当前待确认交付。
     */
    public function acknowledge(string $receipt, int $reason = 0, float $timeout = 3.0): void
    {
        $this->assertConnected();
        if (!isset($this->incoming[$receipt])) {
            throw new \LogicException('mqtt_client_receipt_unknown');
        }
        if (!in_array($reason, [0, 0x10, 0x80, 0x83, 0x87, 0x90, 0x91, 0x97, 0x99], true)) {
            throw new \InvalidArgumentException('MQTT PUBACK原因码无效');
        }
        $identifier = $this->incoming[$receipt]['identifier'];
        try {
            $this->write(self::packet(0x40, pack('n', $identifier) . ($reason === 0 ? '' : chr($reason) . "\0")), self::deadline($timeout));
            unset($this->incoming[$receipt], $this->identifiers[$identifier]);
        } catch (\Throwable $failure) {
            $this->close(false);
            throw $failure;
        }
    }

    /**
     * 所属执行者有界尝试DISCONNECT后释放网络，不确认入站；false允许同线程控制方直接关闭。
     * @throws \RuntimeException 跨线程关闭，或其他执行者尝试正常协议关闭。
     */
    public function close(bool $graceful = true): void
    {
        $this->threadOwner?->assertCurrent();
        if ($graceful) {
            $this->owner?->assertCurrent();
        }
        if ($graceful && $this->connected && $this->socket !== null) {
            try {
                $this->write("\xe0\0", new Deadline(1.0));
            } catch (\Throwable) {
                // 关闭失败保持对端结果未知，仍须归还本地资源。
            }
        }
        // close可同步唤醒原生等待；先撤销本地持有，避免恢复后的失败清理重复关闭。
        $socket = $this->socket;
        $this->socket = null;
        $this->resetNetwork();
        if ($socket instanceof Socket) {
            $socket->close();
        } elseif (is_resource($socket)) {
            fclose($socket);
        }
    }

    /** 同线程信号处理器或控制协程取消当前等待；不发送协议或业务确认。 */
    public function stop(): void
    {
        $this->threadOwner?->assertCurrent();
        $this->stopped = true;
        $this->close(false);
    }

    /** @return array{connected:bool,buffered_bytes:int,queued:int,unacknowledged:int,publication_unknown:bool} 有界资源观察，不含凭据或载荷。 */
    public function statistics(): array
    {
        return ['connected' => $this->connected, 'buffered_bytes' => strlen($this->input) + $this->queuedBytes,
                'queued' => count($this->messages), 'unacknowledged' => count($this->incoming), 'publication_unknown' => $this->publication !== null];
    }

    /** 作用域退出同样释放连接；不会以析构替代业务确认。 */
    public function __destruct()
    {
        $this->close(false);
    }

    private function exchange(int $header, string $body, int $responseType, int $identifier, float $timeout, bool $encoded = false): int
    {
        $this->assertConnected();
        if ($this->waitingIdentifier !== 0) {
            throw new \LogicException('mqtt_client_exchange_active');
        }
        $deadline = self::deadline($timeout);
        $this->waitingIdentifier = $identifier;
        $this->waitingType = $responseType;
        $this->response = null;
        try {
            $this->write($encoded ? $body : self::packet($header, $body), $deadline);
            while ($this->response === null) {
                $this->pump($deadline, true);
            }
            $reason = $this->response;
            if ($responseType === 0x40) {
                $this->publication = null;
            }
            if ($reason >= 0x80) {
                throw new ProtocolError($reason);
            }
            return $reason;
        } catch (\Throwable $failure) {
            $this->close(false);
            throw $failure;
        } finally {
            $this->waitingIdentifier = 0;
            $this->waitingType = 0;
            $this->response = null;
        }
    }

    private function pump(Deadline $deadline, bool $required): bool
    {
        $this->assertConnected();
        $frame = $this->frame($deadline, $required);
        if ($frame === null) {
            return false;
        }
        $header = $frame['header'];
        $reader = new PacketReader($frame['body']);
        if (($header >> 4) === 3) {
            $this->incomingMessage($header, $reader);
        } elseif (in_array($header, [0x40, 0x90, 0xb0], true)) {
            $identifier = $reader->integer(2);
            if ($identifier === 0 || $identifier !== $this->waitingIdentifier || $header !== $this->waitingType || $this->response !== null) {
                throw new ProtocolError(0x82);
            }
            $reason = 0;
            if ($header === 0x40) {
                $reason = $reader->remaining() > 0 ? $reader->integer(1) : 0;
                if ($reader->remaining() > 0) {
                    $this->properties($reader, 'ack');
                }
                if (!in_array($reason, [0, 0x10, 0x80, 0x83, 0x87, 0x90, 0x91, 0x97, 0x99], true)) {
                    throw new ProtocolError(0x82);
                }
            } else {
                $this->properties($reader, 'ack');
                $reason = $reader->integer(1);
                if (!in_array($reason, $header === 0x90 ? [0, 1, 0x80, 0x83, 0x87, 0x8f, 0x91, 0x97, 0x9e, 0xa1, 0xa2] : [0, 0x11, 0x80, 0x83, 0x87, 0x8f, 0x91], true)) {
                    throw new ProtocolError(0x82);
                }
            }
            if ($reader->remaining() !== 0) {
                throw new ProtocolError(0x81);
            }
            $this->response = $reason;
        } elseif ($header === 0xd0 && $reader->remaining() === 0 && $this->ping !== null) {
            $this->ping = null;
        } elseif ($header === 0xe0) {
            $reason = $reader->remaining() === 0 ? 0 : $reader->integer(1);
            if (!in_array($reason, [0, 0x80, 0x81, 0x82, 0x83, 0x87, 0x89, 0x8b, 0x8d, 0x8e, 0x8f, 0x90, 0x93, 0x94, 0x95, 0x96, 0x97, 0x98, 0x99, 0x9a, 0x9b, 0x9c, 0x9d, 0x9e, 0x9f, 0xa0, 0xa1, 0xa2], true)) {
                throw new ProtocolError(0x82);
            }
            if ($reader->remaining() > 0) {
                $this->properties($reader, 'disconnect');
            }
            if ($reader->remaining() !== 0) {
                throw new ProtocolError(0x81);
            }
            throw new ProtocolError($reason);
        } else {
            throw new ProtocolError(0x82);
        }
        return true;
    }

    private function incomingMessage(int $header, PacketReader $reader): void
    {
        $qos = ($header >> 1) & 3;
        if ($qos > 1 || ($qos === 0 && ($header & 8) !== 0)) {
            throw new ProtocolError(0x82);
        }
        $topic = $reader->text();
        $identifier = $qos === 1 ? $reader->integer(2) : 0;
        if ($qos === 1 && $identifier === 0) {
            throw new ProtocolError(0x82);
        }
        $properties = $this->properties($reader, 'publish');
        $message = new Message($topic, $reader->take($reader->remaining()), $properties['forward'], $qos, 0, ($header & 1) !== 0);
        $fingerprint = hash('sha256', self::field($topic) . $message->properties . $message->payload . ($message->retain ? '1' : '0'));
        if ($qos === 1 && isset($this->identifiers[$identifier])) {
            if (($header & 8) === 0 || !hash_equals($this->incoming[$this->identifiers[$identifier]]['fingerprint'], $fingerprint)) {
                throw new ProtocolError(0x91);
            }
            return;
        }
        $bytes = strlen($message->payload) + strlen($message->properties) + strlen($topic);
        if (($qos === 1 && count($this->incoming) >= $this->receiveMaximum) || count($this->messages) >= $this->receiveMaximum
            || $this->queuedBytes + $bytes > $this->maximumPacketBytes * $this->receiveMaximum) {
            throw new ProtocolError(0x93);
        }
        $receipt = $qos === 0 ? '' : bin2hex(random_bytes(16));
        if ($qos === 1) {
            $this->incoming[$receipt] = ['identifier' => $identifier, 'fingerprint' => $fingerprint];
            $this->identifiers[$identifier] = $receipt;
        }
        $this->queuedBytes += $bytes;
        $this->messages[] = ['message' => $message, 'receipt' => $receipt, 'duplicate' => ($header & 8) !== 0,
                             'subscription_identifiers' => $properties['identifiers']];
    }

    /** 服务端属性上下文复用严格字节游标；仅交付订阅标识可重复，别名已明确协商为0。 */
    private function properties(PacketReader $reader, string $context): array
    {
        $raw = $reader->take($reader->variable());
        $properties = new PacketReader($raw);
        $values = [];
        $identifiers = [];
        $forward = '';
        $allowed = match ($context) {
            'connack' => [0x11, 0x21, 0x24, 0x25, 0x27, 0x12, 0x22, 0x1f, 0x26, 0x28, 0x29, 0x2a, 0x13, 0x1a, 0x1c],
            'publish' => [0x01, 0x02, 0x03, 0x08, 0x09, 0x0b, 0x26],
            'disconnect' => [0x1f, 0x26, 0x1c],
            default => [0x1f, 0x26],
        };
        while ($properties->remaining() > 0) {
            $start = strlen($raw) - $properties->remaining();
            $property = $properties->variable();
            if (!in_array($property, $allowed, true) || ($property !== 0x26 && $property !== 0x0b && array_key_exists($property, $values))) {
                throw new ProtocolError(0x82);
            }
            if ($property === 0x26) {
                // 客户端协商不消费User Property；逐项校验原字节，PUBLISH由Message保留其完整语义。
                $properties->text();
                $properties->text();
            } elseif ($property === 0x0b) {
                $subscription = $properties->variable();
                if ($subscription === 0 || count($identifiers) >= 100) {
                    throw new ProtocolError(0x82);
                }
                $identifiers[] = $subscription;
            } elseif (in_array($property, [0x01, 0x24, 0x25, 0x28, 0x29, 0x2a], true)) {
                $byte = $properties->integer(1);
                if ($byte > 1) {
                    throw new ProtocolError(0x82);
                }
                $values[$property] = $byte;
            } elseif (in_array($property, [0x13, 0x21, 0x22], true)) {
                $short = $properties->integer(2);
                if ($property === 0x21 && $short === 0) {
                    throw new ProtocolError(0x82);
                }
                $values[$property] = $short;
            } elseif (in_array($property, [0x02, 0x11, 0x27], true)) {
                $long = $properties->integer(4);
                if ($property === 0x27 && $long === 0) {
                    throw new ProtocolError(0x82);
                }
                $values[$property] = $long;
            } elseif ($property === 0x09) {
                $values[$property] = $properties->binary();
            } else {
                $values[$property] = $properties->text();
            }
            if ($context === 'publish' && $property !== 0x0b) {
                $forward .= substr($raw, $start, strlen($raw) - $properties->remaining() - $start);
            }
        }
        return ['values' => $values, 'identifiers' => $identifiers, 'forward' => $forward];
    }

    /** 输入总量始终不超过报文预算；完整缓冲帧可等待业务处理，未完成尾包保留从收字节起的五秒期限。 */
    private function frame(Deadline $deadline, bool $required): ?array
    {
        while (true) {
            $this->assertSocket();
            if (strlen($this->input) >= 2) {
                $length = 0;
                $multiplier = 1;
                $headerLength = 0;
                for ($index = 1; $index < min(strlen($this->input), 5); $index++) {
                    $byte = ord($this->input[$index]);
                    $length += ($byte & 127) * $multiplier;
                    if (($byte & 128) === 0) {
                        if ($index > 1 && $byte === 0) {
                            throw new ProtocolError(0x81);
                        }
                        $headerLength = $index + 1;
                        break;
                    }
                    if ($index === 4) {
                        throw new ProtocolError(0x81);
                    }
                    $multiplier *= 128;
                }
                if ($headerLength > 0) {
                    if ($headerLength + $length > $this->maximumPacketBytes) {
                        throw new ProtocolError(0x95);
                    }
                    if (strlen($this->input) >= $headerLength + $length) {
                        $frame = ['header' => ord($this->input[0]), 'body' => substr($this->input, $headerLength, $length)];
                        $this->input = substr($this->input, $headerLength + $length);
                        // 只有当前帧不完整才会再次读取；其后所有缓冲字节因此来自最近一次read。
                        // 处理完整帧不重新给已收到的尾包五秒，也不把业务持久处理时间当作网络半包超时。
                        $this->partial = $this->input === '' ? null : new Deadline(max(0.0, 5.0 - (self::now() - $this->lastRead)));
                        return $frame;
                    }
                }
            }
            if ($this->partial?->expired()) {
                throw new \RuntimeException('mqtt_client_partial_timeout');
            }
            if ($this->ping?->expired()) {
                throw new \RuntimeException('mqtt_client_ping_timeout');
            }
            if ($deadline->expired()) {
                if ($required) {
                    throw new \RuntimeException('mqtt_client_timeout');
                }
                return null;
            }
            if ($this->connected && $this->effectiveKeepAlive > 0 && $this->ping === null
                && ($deadline->remaining() ?? 0.0) >= 0.01
                && self::now() - $this->lastWrite >= max(0.25, (float)$this->effectiveKeepAlive / 2.0)) {
                $this->write("\xc0\0", $deadline);
                $this->ping = new Deadline((float)$this->effectiveKeepAlive);
            }
            if ($this->socket instanceof Socket) {
                $bytes = $this->socket->recv(min(16384, $this->maximumPacketBytes - strlen($this->input)), $this->readTimeout($deadline));
                $this->assertSocket();
                if ($bytes === false && $this->socket->errCode === SOCKET_ETIMEDOUT) {
                    continue;
                }
                if ($bytes === false || $bytes === '') {
                    $this->socketFailure($deadline, 'mqtt_client_disconnected');
                }
            } else {
                $bytes = @fread($this->socket, min(16384, $this->maximumPacketBytes - strlen($this->input)));
                if ($bytes === false || ($bytes === '' && feof($this->socket))) {
                    throw new \RuntimeException('mqtt_client_disconnected');
                }
            }
            if ($bytes !== '') {
                $this->lastRead = self::now();
                if ($this->input === '') {
                    $this->partial = new Deadline(5.0);
                }
                $this->input .= $bytes;
            } else {
                $this->waitIo($deadline, false, !$required);
            }
        }
    }

    private function write(string $packet, Deadline $deadline): void
    {
        if (strlen($packet) > min($this->serverMaximum, $this->maximumPacketBytes)) {
            throw new ProtocolError(0x95);
        }
        $this->assertSocket();
        if ($this->socket instanceof Socket) {
            if ($deadline->expired()) {
                throw new \RuntimeException('mqtt_client_timeout');
            }
            if ($this->socket->sendAll($packet, max(0.000001, $deadline->remaining() ?? 0.001)) !== strlen($packet)) {
                $this->socketFailure($deadline, 'mqtt_client_disconnected');
            }
            $this->assertSocket();
            $this->lastWrite = self::now();
            return;
        }
        $offset = 0;
        while ($offset < strlen($packet)) {
            $this->assertSocket();
            if ($deadline->expired()) {
                throw new \RuntimeException('mqtt_client_timeout');
            }
            $written = @fwrite($this->socket, substr($packet, $offset, 16384));
            if ($written === false || ($written === 0 && feof($this->socket))) {
                throw new \RuntimeException('mqtt_client_disconnected');
            }
            $offset += $written;
            if ($written === 0) {
                $this->waitIo($deadline, true);
            }
        }
        $this->lastWrite = self::now();
    }

    private function waitIo(Deadline $deadline, bool $write, bool $allowTimeout = false): void
    {
        $this->assertSocket();
        if ($deadline->expired()) {
            if ($allowTimeout) {
                return;
            }
            throw new \RuntimeException('mqtt_client_timeout');
        }
        $read = $write ? [] : [$this->socket];
        $writes = $write ? [$this->socket] : [];
        $except = [$this->socket];
        $selected = @stream_select($read, $writes, $except, 0, max(1, (int)(min(0.05, $deadline->remaining() ?? 0.05) * 1000000.0)));
        $this->assertSocket();
        if (($selected === false && !$this->stopped) || $except !== []) {
            throw new \RuntimeException('mqtt_client_network_failed');
        }
    }

    private function reserve(): int
    {
        $this->assertConnected();
        $identifier = $this->nextIdentifier;
        if ($this->publication !== null && $identifier === $this->publication['identifier']) {
            $identifier = $identifier === 65535 ? 1 : $identifier + 1;
        }
        $this->nextIdentifier = $identifier === 65535 ? 1 : $identifier + 1;
        return $identifier;
    }

    private function assertSocket(): void
    {
        $this->owner?->assertCurrent();
        if ($this->stopped || $this->socket === null) {
            throw new \RuntimeException($this->stopped ? 'mqtt_client_stopped' : 'mqtt_client_disconnected');
        }
    }

    /** 原生等待只在业务、半包、PING或下次保活期限唤醒，不周期查询网络就绪。 */
    private function readTimeout(Deadline $deadline): float
    {
        $seconds = $deadline->remaining() ?? 60.0;
        if ($this->partial !== null) {
            $seconds = min($seconds, $this->partial->remaining() ?? 5.0);
        }
        if ($this->ping !== null) {
            $seconds = min($seconds, $this->ping->remaining() ?? (float)$this->effectiveKeepAlive);
        } elseif ($this->connected && $this->effectiveKeepAlive > 0) {
            $untilPing = max(0.25, (float)$this->effectiveKeepAlive / 2.0) - (self::now() - $this->lastWrite);
            // 剩余不足10ms时不再启动PING，直接等待本次业务截止。
            if ($seconds >= 0.01) {
                $seconds = min($seconds, $untilPing);
            }
        }
        return max(0.000001, $seconds);
    }

    /** 连接与TLS共用一次截止；原生握手的分段读超时另以单次Timer约束总预算。 */
    private function connectSocket(Deadline $deadline): void
    {
        $socket = new Socket(str_contains($this->host, ':') ? AF_INET6 : AF_INET, SOCK_STREAM);
        $this->socket = $socket;
        if ($deadline->expired()) {
            throw new \RuntimeException('mqtt_client_timeout');
        }
        if (!$socket->connect($this->host, $this->port, max(0.000001, $deadline->remaining() ?? 0.001))) {
            $this->socketFailure($deadline, 'mqtt_client_connect_failed');
        }
        $this->assertSocket();
        if ($this->allowPlaintext) {
            return;
        }
        $settings = ['open_ssl' => true, 'ssl_verify_peer' => true, 'ssl_allow_self_signed' => false,
                     'ssl_host_name' => $this->peerName === '' ? $this->host : $this->peerName,
                     'ssl_protocols' => SWOOLE_SSL_TLSv1_2 | SWOOLE_SSL_TLSv1_3];
        if ($this->caFile !== '') {
            $settings['ssl_cafile'] = $this->caFile;
        }
        if (!$socket->setProtocol($settings)) {
            throw new \RuntimeException('mqtt_client_tls_failed');
        }
        if ($deadline->expired()) {
            throw new \RuntimeException('mqtt_client_timeout');
        }
        $timer = \Swoole\Timer::after(max(1, (int)ceil(($deadline->remaining() ?? 0.001) * 1000.0)), static function () use ($socket): void {
            $socket->cancel(SWOOLE_EVENT_READ);
        });
        if ($timer === false) {
            throw new \RuntimeException('mqtt_client_timer_failed');
        }
        try {
            if (!$socket->sslHandshake()) {
                $this->socketFailure($deadline, 'mqtt_client_tls_failed');
            }
            $this->assertSocket();
            if ($deadline->expired()) {
                throw new \RuntimeException('mqtt_client_timeout');
            }
        } finally {
            if (\Swoole\Timer::exists($timer)) {
                \Swoole\Timer::clear($timer);
            }
        }
    }

    /** 取消与超时均不代表远端写入失败；调用者沿原清理路径保留未知发布。 */
    private function socketFailure(Deadline $deadline, string $fallback): void
    {
        $this->assertSocket();
        if ($deadline->expired() || $this->socket->errCode === SOCKET_ETIMEDOUT) {
            throw new \RuntimeException('mqtt_client_timeout');
        }
        if ($this->socket->errCode === SOCKET_ECANCELED) {
            throw new \RuntimeException('mqtt_client_cancelled');
        }
        throw new \RuntimeException($fallback);
    }

    private function assertConnected(): void
    {
        $this->assertSocket();
        if (!$this->connected) {
            throw new \LogicException('mqtt_client_not_connected');
        }
    }

    private function resetNetwork(): void
    {
        $this->connected = false;
        $this->input = '';
        $this->partial = null;
        $this->lastRead = 0.0;
        $this->ping = null;
        $this->messages = [];
        $this->incoming = [];
        $this->identifiers = [];
        $this->queuedBytes = 0;
        $this->serverMaximum = 268435455;
        $this->effectiveKeepAlive = $this->keepAlive;
    }

    private static function deadline(float $seconds): Deadline
    {
        if (!is_finite($seconds) || $seconds <= 0 || $seconds > 60) {
            throw new \InvalidArgumentException('MQTT 客户端等待预算必须大于0且不超过60秒');
        }
        return new Deadline($seconds);
    }

    private static function field(string $value): string
    {
        if (strlen($value) > 65535 || str_contains($value, "\0") || preg_match('//u', $value) !== 1) {
            throw new \InvalidArgumentException('MQTT UTF-8字段无效');
        }
        return pack('n', strlen($value)) . $value;
    }

    private static function packet(int $header, string $body): string
    {
        return chr($header) . PacketReader::encodeVariable(strlen($body)) . $body;
    }

    private static function now(): float
    {
        return (float)hrtime(true) / 1000000000.0;
    }
}
