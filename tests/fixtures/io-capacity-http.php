<?php

declare(strict_types=1);

use app\common\middleware\ApiErrors;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swoole\Coroutine;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\SwooleServer;
use Type\Runtime\CapacityException;
use Type\Runtime\CoroutineRuntime;

/** 真实文件超过原生缓冲预算；同步兼容入口也保留原异常的类型与消息。 */
final class IoCapacityFailure
{
    public static ?Throwable $failure = null;

    public static function raise(): void
    {
        if (Coroutine::getCid() >= 0) {
            Swoole\Coroutine\System::readFile((string) getenv('TYPE_CAPACITY_FILE'));
            throw new RuntimeException('原生文件预算没有拒绝');
        }
        self::$failure = null;
        Coroutine::create(static function (): void {
            try {
                self::raise();
            } catch (Throwable $error) {
                self::$failure = $error;
            }
        });
        Swoole\Event::wait();
        throw self::$failure ?? new RuntimeException('没有取得原生容量拒绝');
    }
}

/** 在真实 PSR 响应流的首块前或已发送一块后触发原生拒绝。 */
final class IoCapacityStream implements StreamInterface
{
    private StreamInterface $stream;
    private string $mode;
    private int $reads = 0;

    public function __construct(string $mode)
    {
        $this->stream = (new Factory())->createStreamFromFile((string) getenv('TYPE_CAPACITY_FILE'));
        $this->mode = $mode;
    }
    public function __toString(): string
    {
        return '';
    }
    public function close(): void
    {
        $this->stream->close();
    }
    public function detach(): mixed
    {
        return $this->stream->detach();
    }
    public function getSize(): ?int
    {
        if ($this->mode === '/metadata') {
            IoCapacityFailure::raise();
        }
        return $this->stream->getSize();
    }
    public function tell(): int
    {
        return $this->stream->tell();
    }
    public function eof(): bool
    {
        return $this->stream->eof();
    }
    public function isSeekable(): bool
    {
        return $this->stream->isSeekable();
    }
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->stream->seek($offset, $whence);
    }
    public function rewind(): void
    {
        $this->stream->rewind();
    }
    public function isWritable(): bool
    {
        return false;
    }
    public function write(string $string): int
    {
        throw new RuntimeException('只读响应');
    }
    public function isReadable(): bool
    {
        return true;
    }
    public function read(int $length): string
    {
        if ($this->mode === '/first' || $this->reads > 0) {
            IoCapacityFailure::raise();
        }
        $this->reads++;
        return $this->stream->read($length);
    }
    public function getContents(): string
    {
        throw new RuntimeException('验收禁止整流缓冲');
    }
    public function getMetadata(?string $key = null): mixed
    {
        return $this->stream->getMetadata($key);
    }
}

/** 从 HTTP 服务和应用中间件的公开入口核对拒绝、脱敏与后续恢复。 */
final class IoCapacityEndpoint implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $factory = new Factory();
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/api/')) {
            return (new ApiErrors($factory))->process(
                $request->withUri($request->getUri()->withPath(substr($path, 4))),
                $this
            );
        }
        if ($path === '/native') {
            IoCapacityFailure::raise();
        }
        if ($path === '/framework') {
            throw new CapacityException('private-capacity-details');
        }
        if ($path === '/unrelated-runtime') {
            throw new RuntimeException('aio_capacity_exceeded');
        }
        if ($path === '/unrelated-swoole') {
            throw new Swoole\Exception('private-unrelated-native-failure');
        }
        if (in_array($path, ['/metadata', '/first', '/later'], true)) {
            return $factory->createResponse()->withBody(new IoCapacityStream($path));
        }
        return $factory->createResponse()->withBody($factory->createStream('healthy'));
    }
}

function main(int $argc, array $argv): void
{
    CoroutineRuntime::assertAvailable();
    if (!defined('SWOOLE_FILE_IO_ABI') || constant('SWOOLE_FILE_IO_ABI') !== 1) {
        throw new RuntimeException('此验收需要显式文件候选');
    }
    swoole_async_set(['aio_core_worker_num' => 2, 'aio_worker_num' => 4, 'aio_max_pending' => 32,
        'aio_max_bytes' => 16777216, 'aio_max_task_time' => 10]);
    // 同步兼容引擎保留自己的网络等待；原生服务在 serve 中启用网络 hook。
    Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_FILE);
    $factory = new Factory();
    $handler = new IoCapacityEndpoint();
    $server = new SwooleServer($handler, $factory, $factory, $factory);
    $server->serve('127.0.0.1', (int) getenv('TYPE_HTTP_PORT'));
}
