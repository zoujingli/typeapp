<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use IoInventory;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/io-inventory.php';

/** 检查通信候选不会在审计中遗漏；解析源码不执行网络操作。 */
final class IoInventoryTest extends TestCase
{
    /** 加载审计器不执行控制器，直接运行仍在缺少构建身份时失败。 */
    public function testCommandStillRequiresAnArtifactReport(): void
    {
        [$status, $stdout, $stderr] = \execute([PHP_BINARY, dirname(__DIR__) . '/io-inventory.php']);
        self::assertNotSame(0, $status);
        self::assertStringContainsString('需要 --report 构建报告、--output 新报告', $stdout . $stderr);
    }

    /** 真实 HTTP 发送结束和停止调用必须可被筛选，闭包仍保留实际所有者。 */
    public function testExistingHttpCompletionAndShutdownRemainVisible(): void
    {
        $root = dirname(__DIR__, 2);
        $emitter = 'plugin/type-core/src/Http/ResponseEmitter.php';
        $server = 'plugin/type-core/src/Http/SwooleServer.php';
        $inventory = new IoInventory();
        $emission = $inventory->inspect((string) file_get_contents($root . '/' . $emitter), $emitter);
        $lifecycle = $inventory->inspect((string) file_get_contents($root . '/' . $server), $server);
        $end = array_values(array_filter($emission['calls'], static fn (array $call): bool => $call['receiver'] === '$output' && $call['target'] === 'end'));
        $shutdown = array_values(array_filter($lifecycle['calls'], static fn (array $call): bool => $call['receiver'] === '$server' && $call['target'] === 'shutdown'));

        self::assertNotEmpty($end);
        self::assertNotEmpty($shutdown);
        foreach (array_merge($end, $shutdown) as $call) {
            self::assertSame('method-needs-receiver-resolution', $call['io_candidate']);
            self::assertSame('requires-review', $call['resolution']);
            self::assertSame('requires-call-chain-review', $call['phase']);
            self::assertArrayNotHasKey('tasks', $call);
            self::assertGreaterThan(0, $call['line']);
        }
        self::assertStringContainsString('Type\\Core\\Http\\SwooleServer::serve/closure@', $shutdown[0]['owner']);
    }

    /** 原生双端常用方法进入候选，但同名应用方法和动态调用不能被误判为已解析。 */
    public function testProtocolCallsKeepReceiverUncertaintyAndDynamicTargets(): void
    {
        $source = <<<'PHP'
<?php
namespace Audit;
use Swoole\Coroutine\Http\Client as HttpClient;
use Swoole\Coroutine\Socket;
use Swoole\Http\Response;

function communicate(HttpClient $client, Socket $socket, Response $response, object $collection, string $method): void
{
    if ($client->upgrade('/events')) {
        $client->push('hello');
        $client->disconnect();
    }
    $response->upgrade();
    $response?->push('reply');
    $socket->sslHandshake();
    $socket->sendAll('request');
    $socket->recvPacket();
    $socket->sendto('127.0.0.1', 9000, 'probe');
    $socket->recvfrom($peer);
    $socket->shutdown();
    $collection->push('local value');
    $socket->{$method}();
}
PHP;
        $scan = (new IoInventory())->inspect($source, 'application/communications.php');
        self::assertCount(13, $scan['calls']);
        foreach ($scan['calls'] as $call) {
            self::assertSame('Audit\\communicate', $call['owner']);
            self::assertSame('requires-review', $call['resolution']);
            self::assertSame('requires-call-chain-review', $call['phase']);
            self::assertSame($call['target'] === '$method' ? null : 'method-needs-receiver-resolution', $call['io_candidate']);
        }
        self::assertSame('nullsafe-method', $scan['calls'][4]['kind']);
        self::assertSame('$collection', $scan['calls'][11]['receiver']);
        self::assertSame('$method', $scan['calls'][12]['target']);
    }

    /** 导入的原生别名可识别，应用同名类与显式命名空间函数保留原身份。 */
    public function testNativeAliasesDoNotEraseApplicationNames(): void
    {
        $source = <<<'PHP'
<?php
namespace Audit;
use Swoole\Coroutine\Http\Server as HttpServer;
use Swoole\Coroutine\Socket as NetworkSocket;
use Swoole\Coroutine as Co;
use function stream_socket_client as openConnection;

function connect(): void
{
    new HttpServer('127.0.0.1', 9000);
    new NetworkSocket(AF_INET, SOCK_DGRAM, IPPROTO_UDP);
    new Socket();
    Co::sleep(0.01);
    openConnection('tcp://127.0.0.1:9000');
    namespace\stream_socket_client();
}
PHP;
        $calls = (new IoInventory())->inspect($source, 'application/aliases.php')['calls'];
        self::assertCount(6, $calls);
        self::assertSame('Swoole\\Coroutine\\Http\\Server', $calls[0]['target']);
        self::assertSame('extension-resource', $calls[0]['io_candidate']);
        self::assertSame('Swoole\\Coroutine\\Socket', $calls[1]['target']);
        self::assertSame('extension-resource', $calls[1]['io_candidate']);
        self::assertSame('Audit\\Socket', $calls[2]['target']);
        self::assertNull($calls[2]['io_candidate']);
        self::assertSame('swoole', $calls[3]['io_candidate']);
        self::assertSame('stream', $calls[4]['io_candidate']);
        self::assertSame('Audit\\stream_socket_client', $calls[5]['target']);
        self::assertNull($calls[5]['io_candidate']);
    }
}
