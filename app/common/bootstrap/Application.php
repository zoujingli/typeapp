<?php

declare(strict_types=1);

namespace app\common\bootstrap;

use InvalidArgumentException;
use Throwable;
use app\common\database\DatabaseFactory;
use app\common\middleware\ApiErrors;
use app\common\middleware\RequestLog;
use app\broker\service\CompatService;
use Psr\Http\Server\RequestHandlerInterface;
use Type\Core\Config\Repository;
use Type\Core\Http\HttpServerInterface;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\Pipeline;
use Type\Core\Http\RequestLimits;
use Type\Core\Http\RequestPolicy;
use Type\Core\Http\Router;
use Type\Core\Http\SwooleServer;
use Type\Orm\DatabaseManager;
use Type\Mqtt\Broker;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\Deadline;
use Type\Runtime\ExecutionScope;

/** 同一应用声明的启动及 HTTP 所有者；命令实现由生成服务图按需构造。 */
final class Application
{
    /** 原生生成 main 调用此明确入口，保留主程序的退出状态桥接。 */
    public static function bootstrap(array $arguments, bool $development): void
    {
        $GLOBALS['type_app_exit_status'] = self::run($arguments, $development);
    }

    /** 开发与原生共用命令装配；离线帮助不要求配置、Composer 或数据库。 */
    public static function run(array $arguments, bool $development = false): int
    {
        $command = $arguments[1] ?? 'help';
        try {
            if ($command !== 'verify-runtime' && class_exists(\Type\Generated\BuildIdentity::class, false)) {
                \Type\Generated\BuildIdentity::verifyRuntime();
            }
            if ($command === 'help' || $command === '--help') {
                if (count($arguments) > 2) {
                    fwrite(STDERR, "help 不接受额外参数\n");
                    return 1;
                }
                echo "TypeApp 物联中心：help、check、verify-runtime、serve、app:install <管理账号> <管理姓名> <客户账号> <客户姓名> <租户名>、migrate <status|history|recover>。初始化口令由 APP_ADMIN_PASSWORD、APP_CUSTOMER_PASSWORD 的受控进程环境提供。\n";
                echo "运行配置：config:check [--connect] [--remember]；config:restore 恢复最后通过连接检查的文件，成功退出4表示仍须运维重启。\n";
                echo "已有应用升级：app:upgrade --check；停写并核对备份后 app:upgrade --offline --backup <备份文件> --sha256 <SHA256>；页面另用 web:install --force。\n";
                echo "内嵌许可：licenses 查看索引，licenses <notices/资源路径> 查看对应原文。\n";
                echo "审计保留：app:audit-clean <admin|customer> [batch]，单批最多1000条，清理满180天事件并保留恢复与撤销依据。\n";
                echo "应用维护调度：app:schedule once|history|work <次数> <间隔毫秒>；固定任务与执行历史通过 Redis 协调。\n";
                echo "物联业务角色：iot:command-clean、iot:history-clean、iot:aggregate、iot:aggregate-clean、iot:alarm、iot:mqtt-install、iot:mqtt-statistics、iot:mqtt、iot:ingest、iot:device、iot:exports、iot:exports-work、iot:exports-clean；持久 MQTT 接收需要 PostgreSQL 同步后端。\n";
                echo "独立 Broker：broker:install、broker:migrate <status|history|recover>、broker:user <login> <name>、broker:serve、broker:run、broker:store-install；初始化密码由 BROKER_ADMIN_PASSWORD 提供。升级后启动会核对兼容代次，不能用更旧二进制维持新的占用与吊销语义。\n";
                echo "独立持久恢复：broker:nodes；broker:node-fence <node-id> <node-run> <observation-run> <actor> <proof-ref> [operation-id]，只登记已经完成的基础设施硬隔离；broker:node-fence-result <operation-id> 仅对账；broker:audit-clean [batch] 清理满180天审计。\n";
                echo "站内通知：iot:notices [batch]、iot:notices-clean [batch]；默认100、最多100。\n";
                echo "集群管理：iot:mqtt-nodes、iot:mqtt-fence <node-id> <run-id> <actor> <proof-ref> [operation-id]；fence仅登记已完成的基础设施硬隔离；iot:mqtt-fence-result <operation-id> 仅对账。\n";
                echo "WAL维护：iot:wal <archive|restore|verify> <私有归档目录> <源文件|WAL名称> [WAL名称|新目标文件]。\n";
                echo "备份保留：iot:backup register <私有归档目录> <备份ID> <PG17工具根>；clean <私有归档目录> <PG17工具根> [批次1至100]；status <私有归档目录>；pin|unpin <私有归档目录> <备份ID> <保护ID>。\n";
                echo "恢复核对：iot:recovery 与独立 broker:recovery <snapshot|status|begin|isolate|review|restore>；snapshot输出敏感身份摘要，begin需恢复ID、操作人和已完成隔离的依据，review需恢复ID、私有清单、已核对SHA256和偏移量。旧备份恢复后须核对证书与调试授权再开放。\n";
                echo "DB_DRIVER 必须与程序的数据库 profile 一致；空库执行 app:install 后 serve，生产默认使用 Swoole 线程与协程。客户端 /，管理端 /admin；业务接口按固定账号域和租户权限开放。\n";
                echo "前端安装：web:install [--force] [--dry-run]。首次app:install同时安装页面；强制更新只处理内置页面文件，不修改数据库、上传或配置。页面入口 /#/login 和 /#/admin/login。\n";

                return 0;
            }
            if ($command === 'migrate' && (count($arguments) === 2 || (count($arguments) === 3 && $arguments[2] === 'help'))) {
                echo "迁移查询：status、history；异常记录核对：recover <版本> <retry|applied> <恢复说明>。全新空库仅通过 app:install 初始化，run 不开放。\n";
                return 0;
            }
            $basePath = Settings::basePath($arguments[0] ?? '', $development);
            $configuration = ApplicationContext::configuration($basePath, $arguments[0] ?? '', $development);
            // HTTP/MQTT 宿主拥有原生事件循环；业务 scope 在请求/消息处理内建立。
            if (in_array($command, ['serve', 'broker:serve', 'iot:mqtt', 'broker:run'], true)) {
                $context = new ApplicationContext($basePath, $arguments[0] ?? '', $development ? '1' : '0', '');
                return (new ManagementCommand($context, $command))->run($configuration, array_slice($arguments, 2));
            }
            $application = new \Type\Generated\CommandApplication($configuration);
            return $application->run($command === 'check' ? 'app:check' : $command, array_slice($arguments, 2));
        } catch (Throwable $error) {
            $message = $error instanceof InvalidArgumentException ? $error->getMessage() : 'internal_error';
            if ($message === '未知命令：' . $command) {
                $message = '未知应用命令：' . $command;
            }
            fwrite(STDERR, 'TypeApp 物联中心启动或命令失败：' . $message . "\n");
            return 1;
        }
    }

    /**
     * 在协程内完成存储预检，再构建与服务器实现无关的 PSR-15 业务处理链。
     *
     * 每次实际请求必须由服务器创建执行作用域，响应结束后收回所有请求租约。
     *
     * @param ?DatabaseManager $database 宿主可注入供请求与就绪共用的管理器，宿主退出时负责关闭。
     * @throws InvalidArgumentException 模式、令牌、Host、预算或 SQLite 初始化条件无效。
     * @throws \RuntimeException 存储不兼容、恢复门禁未就绪或预检失败。
     */
    public static function handler(Repository $settings, string $basePath, bool $development = false, bool $broker = false, ?DatabaseManager $database = null): RequestHandlerInterface
    {
        Settings::validateRuntimeConfiguration($settings, $basePath, 'http');
        $environment = Settings::environment($settings, $development);
        $debug = Settings::debug($settings, $development);
        $probeToken = $settings->text('app.broker.probe_token');
        if ($probeToken !== '' && (strlen($probeToken) < 32 || strlen($probeToken) > 256 || preg_match('/^[A-Za-z0-9._~+\/-]+=*$/D', $probeToken) !== 1)) {
            throw new InvalidArgumentException('BROKER_PROBE_TOKEN必须为32至256字符的独立Bearer令牌');
        }
        $policy = Settings::requestPolicy($settings);
        DatabaseFactory::requireExisting($settings, $basePath);
        // PDO hook 已在主线程安装；业务线程的启动预检也须在协程内创建和释放连接。
        CoroutineRuntime::run(static function () use ($settings, $basePath, $broker): void {
            $compat = Settings::database($settings, $basePath, 1);
            $compatScope = new ExecutionScope(new Deadline(2.0));
            try {
                $connection = $compat->connect($compatScope);
                CompatService::assertRuntime($connection);
                \app\iot\service\RecoveryService::ready($connection, $broker ? 'broker' : 'app');
            } finally {
                $compatScope->close();
                $compat->close();
            }
        });
        $database ??= Settings::database($settings, $basePath);
        \Type\Orm\Db::configure($database);
        $messages = new Factory();
        $router = new Router($messages, $messages);
        $application = new \Type\Generated\CommandApplication(ApplicationContext::configuration($basePath, '', $development, $settings));
        $application->registerRoutes($router);

        $pages = $broker ? null : new \app\common\service\FrontendPages(\app\common\service\FrontendAssets::application($basePath, $development));
        return new Pipeline([
            static fn (): RequestLog => new RequestLog($settings->text('app.name')),
            static fn (): ApiErrors => new ApiErrors($messages, $debug, dirname(__DIR__, 3)),
            static fn (): RequestPolicy => $policy,
        ], new \Type\Core\Http\ActionHandler(static function (\Psr\Http\Message\ServerRequestInterface $request) use ($broker, $router, $messages, $pages): \Psr\Http\Message\ResponseInterface {
            if ($pages !== null) {
                $response = $pages->respond($request, $messages);
                if ($response !== null) {
                    return $response;
                }
            }
            if ($broker !== str_starts_with($request->getUri()->getPath(), '/broker/')) {
                return $messages->createResponse(404)->withHeader('Content-Type', 'application/json')->withBody($messages->createStream('{"error":"not_found"}'));
            }
            return $router->handle($request);
        }));
    }


    /** 返回服务器可复用的有界输入声明，不打开临时文件或创建目录。 */
    public static function requestLimits(Repository $settings): RequestLimits
    {
        return Settings::requestLimits($settings);
    }


    /**
     * 创建 Swoole HTTP 服务器。传输引擎固定复用 Swoole 原生 Server 与协程，不提供并行实现。
     *
     * 创建对象不监听端口；返回的服务器由调用方负责 serve/stop 生命周期。
     */
    public static function server(Repository $settings, string $basePath, bool $development = false, bool $broker = false): HttpServerInterface
    {
        $database = Settings::database($settings, $basePath);
        try {
            $handler = self::handler($settings, $basePath, $development, $broker, $database);
        } catch (Throwable $error) {
            $database->close();
            throw $error;
        }
        $readiness = new HttpReadiness($database, $broker);
        $messages = new Factory();
        return new SwooleServer(
            $handler,
            $messages,
            $messages,
            $messages,
            self::requestLimits($settings),
            Settings::httpControl($settings, static fn (): bool => $readiness->ready()),
            static function () use ($database): void {
                $database->close();
            },
            Settings::requestPolicy($settings)
        );
    }


    /** 运行已选择的服务器，并在正常退出或异常后关闭；不会自动迁移或生成生产源码。 */
    public static function serve(Repository $settings, string $basePath, bool $development = false, bool $broker = false): void
    {
        Settings::validateRuntimeConfiguration($settings, $basePath, 'http');
        if (!$broker) {
            RuntimeCapabilities::requireFeature('web');
        }
        if (!$broker && !$development) {
            CoroutineRuntime::enableIo();
            $listen = $settings->text('app.http.listen');
            $port = Settings::integer($settings, 'app.http.port', 1, 65535);
            $payload = json_encode(['base' => $basePath, 'settings' => ['app' => $settings->array('app'), 'database' => $settings->array('database'), 'cache' => $settings->array('cache')], 'listen' => $listen, 'port' => $port], JSON_THROW_ON_ERROR);
            // Windows IOCP 共享监听尚未验收；单业务线程自行绑定，主控仍走 ThreadSupervisor 停止/join。
            if (PHP_OS_FAMILY === 'Windows') {
                (new \Type\Runtime\ThreadSupervisor(1))->run('http', [$payload], null);
                return;
            }
            $count = Settings::httpThreads($settings);
            $listener = new \Swoole\Coroutine\Socket(AF_INET, SOCK_STREAM, 0);
            try {
                if (!$listener->bind($listen, $port) || !$listener->listen(128)) {
                    throw new \RuntimeException('HTTP 监听失败');
                }
                (new \Type\Runtime\ThreadSupervisor($count))->run('http', array_fill(0, $count, $payload), $listener);
            } finally {
                $listener->close();
            }
            return;
        }
        $server = self::server($settings, $basePath, $development, $broker);
        try {
            $server->serve($settings->text('app.http.listen'), Settings::integer($settings, 'app.http.port', 1, 65535));
        } finally {
            $server->stop();
        }
    }


    /** 编译期登记的 HTTP 线程入口；每线程从同一启动数据建立自己的配置与资源。 */
    public static function httpThread(string $payload): int
    {
        $data = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
        $arguments = \Swoole\Thread::getArguments();
        $listener = $arguments[1] ?? null;
        $state = $arguments[2] ?? null;
        if (!$state instanceof \Swoole\Thread\Map) {
            throw new \RuntimeException('HTTP 线程缺少受管停止状态');
        }
        $server = self::server(new Repository($data['settings']), (string) $data['base']);
        if (!$server instanceof SwooleServer) {
            throw new \RuntimeException('HTTP 线程只接受 Swoole');
        }
        try {
            if ($listener instanceof \Swoole\Coroutine\Socket) {
                $server->serveThread($listener, $state);
            } elseif ($listener === null && isset($data['listen'], $data['port']) && is_string($data['listen']) && is_int($data['port'])) {
                $server->serveThreadOwned($data['listen'], $data['port'], $state);
            } else {
                throw new \RuntimeException('HTTP 线程缺少受管监听或自绑定地址');
            }
        } catch (Throwable $error) {
            // Windows 等平台线程异常退出时，主控只看到 thread_exit_unexpected；此处保留可观测的稳定码与类型。
            $code = $error instanceof \Type\Runtime\TaskException ? $error->errorCode() : $error::class;
            fwrite(STDERR, 'HTTP 业务线程失败：' . $code . ' ' . $error->getMessage() . "\n");
            throw $error;
        } finally {
            $server->stop();
        }
        return 0;
    }

}
