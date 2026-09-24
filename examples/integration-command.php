<?php

declare(strict_types=1);

use Type\Core\Http\Authentication;
use Type\Core\Http\CanonicalRequest;
use Type\Core\Http\HttpControl;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\Pipeline;
use Type\Core\Http\RequestPolicy;
use Type\Core\Http\Router;
use Type\Core\Http\SwooleServer;
use Type\Orm\Migration\MigrationConsole;
use Type\Orm\Migration\Migrator;
use Type\Runtime\CoroutineRuntime;
use TypeApp\Integration\Endpoint;
use TypeApp\Integration\Scenario;
use TypeApp\ModelExample\Drivers;

/**
 * 在 Swoole 协程内运行 ORM、缓存、Outbox、队列和日志的组合示例。
 *
 * @param list<string> $argv 程序路径与该示例的显式参数。
 */
function main(int $argc, array $argv): void
{
    if (($argv[1] ?? '') === 'serve') {
        integrationScenario($argc, $argv);
        return;
    }
    \Type\Runtime\CoroutineRuntime::run(static function () use ($argc, $argv): void {
        integrationScenario($argc, $argv);
    });
}

/**
 * 按显式驱动和角色运行组合业务，输出验证结果并关闭本轮管理器。
 *
 * @param list<string> $argv 程序路径及组合业务参数。
 */
function integrationScenario(int $argc, array $argv): void
{
    try {
        $mode = $argv[1] ?? 'help';
        if ($mode === 'help') {
            echo "完整集成应用：scenario、serve、migrate。\n";
            return;
        }
        $driver = Drivers::create((string) (getenv('TYPE_MODEL_DRIVER') ?: 'sqlite'));
        $application = (string) getenv('TYPE_INTEGRATION_APP');
        if ($application === '') {
            throw new InvalidArgumentException('集成应用必须有独立命名空间');
        }
        if ($mode === 'migrate') {
            $exit = (new MigrationConsole(new Migrator($driver, 'type_integration_migrations'), Scenario::migrations($driver)))->run(['run']);
            if ($exit !== 0) {
                exit($exit);
            } return;
        }
        if ($mode === 'scenario') {
            echo json_encode(Scenario::run($driver, $application), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
            return;
        }
        if ($mode === 'serve') {
            $manager = new \Type\Orm\DatabaseManager(['default' => $driver]);
            \Type\Orm\Db::configure($manager);
            $port = filter_var(getenv('TYPE_HTTP_PORT'), FILTER_VALIDATE_INT);
            $token = (string) getenv('TYPE_INTEGRATION_TOKEN');
            if (!is_int($port) || $port < 1 || $port > 65535 || strlen($token) < 32) {
                throw new InvalidArgumentException('集成 HTTP 启动配置无效');
            }
            $messages = new Factory();
            $router = new Router($messages, $messages);
            $router->add('GET', '/article', static fn (): Endpoint => new Endpoint($application));
            $pipeline = new Pipeline([static fn (): RequestPolicy => new RequestPolicy(['127.0.0.1:' . $port]),
                static fn (): Authentication => new Authentication(
                    static fn (string $input): ?Identity => hash_equals($token, $input) ? new Identity('integration') : null,
                    static fn (Identity $identity, CanonicalRequest $request, string $method): bool => $identity->subject() === 'integration',
                    $messages,
                    $messages
                )], $router);
            CoroutineRuntime::enableIo();
            try {
                (new SwooleServer($pipeline, $messages, $messages, $messages, null, new HttpControl(probes: true)))->serve('127.0.0.1', $port);
            } finally {
                $manager->close();
            }
            return;
        }
        throw new InvalidArgumentException('未知集成应用角色');
    } catch (Throwable $error) {
        fwrite(STDERR, '集成应用失败：' . $error->getMessage() . "\n");
        exit(1);
    }
}
