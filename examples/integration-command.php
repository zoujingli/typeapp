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

function main(int $argc, array $argv): void
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
            $port = filter_var(getenv('TYPE_HTTP_PORT'), FILTER_VALIDATE_INT);
            $token = (string) getenv('TYPE_INTEGRATION_TOKEN');
            if (!is_int($port) || $port < 1 || $port > 65535 || strlen($token) < 32) {
                throw new InvalidArgumentException('集成 HTTP 启动配置无效');
            }
            $messages = new Factory();
            $router = new Router($messages, $messages);
            $router->add('GET', '/article', static fn (): Endpoint => new Endpoint($driver, $application));
            $pipeline = new Pipeline([static fn (): RequestPolicy => new RequestPolicy(['127.0.0.1:' . $port]),
                static fn (): Authentication => new Authentication(
                    static fn (string $input): ?Identity => hash_equals($token, $input) ? new Identity('integration') : null,
                    static fn (Identity $identity, CanonicalRequest $request, string $method): bool => $identity->subject() === 'integration',
                    $messages,
                    $messages
                )], $router);
            CoroutineRuntime::enableIo();
            (new SwooleServer($pipeline, $messages, $messages, $messages, null, new HttpControl(probes: true)))->serve('127.0.0.1', $port);
            return;
        }
        throw new InvalidArgumentException('未知集成应用角色');
    } catch (Throwable $error) {
        fwrite(STDERR, '集成应用失败：' . $error->getMessage() . "\n");
        exit(1);
    }
}
