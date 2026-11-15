<?php

declare(strict_types=1);

namespace app\common\bootstrap;

use app\center\service\RecoveryService;
use app\common\database\DatabaseFactory;
use app\common\database\Schema;
use app\common\middleware\ApiErrors;
use app\common\middleware\RequestLog;
use app\common\middleware\TenantContext;
use app\common\service\FrontendAssets;
use app\common\service\FrontendPages;
use app\platform\mqtt\service\CompatService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swoole\Coroutine\Socket;
use Swoole\Thread;
use Swoole\Thread\Map;
use Type\Core\Config\Repository;
use Type\Core\Http\ActionHandler;
use Type\Core\Http\HttpServerInterface;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\Pipeline;
use Type\Core\Http\ReadOnlyPolicy;
use Type\Core\Http\RequestLimits;
use Type\Core\Http\RequestPolicy;
use Type\Core\Http\Router;
use Type\Core\Http\SwooleServer;
use Type\Generated\BuildIdentity;
use Type\Generated\CommandApplication;
use Type\Orm\DatabaseManager;
use Type\Orm\Db;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\Deadline;
use Type\Runtime\ExecutionScope;
use Type\Runtime\TaskException;
use Type\Runtime\ThreadSupervisor;

/**
 * 同一应用声明的启动及 HTTP 所有者；命令实现由生成服务图按需构造。
 */
final class Application
{
    /**
     * 原生生成 main 调用此明确入口，保留主程序的退出状态桥接。
     */
    public static function bootstrap(array $arguments, bool $development): void
    {
        $GLOBALS['type_app_exit_status'] = self::run($arguments, $development);
    }

    /**
     * 开发与原生共用命令装配；离线帮助不要求配置、Composer 或数据库。
     */
    public static function run(array $arguments, bool $development = false): int
    {
        $command = $arguments[1] ?? 'help';
        try {
            if ($command !== 'verify-runtime' && class_exists(BuildIdentity::class, false)) {
                BuildIdentity::verifyRuntime();
            }
            if ($command === 'help' || $command === '--help') {
                if (count($arguments) > 2) {
                    fwrite(STDERR, "help 不接受额外参数\n");
                    return 1;
                }
                echo "TypeApp 物联中心：help、check、verify-runtime、serve、app:install <平台账号> <姓名>、app:upgrade [--force|--dry-run]、migrate <status|run|up|generate|rollback|down|history|recover>。平台初始口令由 APP_PLATFORM_PASSWORD 的受控进程环境提供；客户由平台开通并一次性交付随机口令。\n";
                echo "运行配置：config:check [--connect] [--remember]；config:restore 恢复最后通过连接检查的文件，成功退出4表示仍须运维重启。\n";
                echo "安装边界：仅支持全新空库；旧安装明确拒绝，不自动转换或清库。\n";
                echo "内嵌许可：licenses 查看索引，licenses <notices/资源路径> 查看对应原文。\n";
                echo "审计保留：app:audit-clean <platform|system> [batch]，单批最多1000条，清理满180天事件并保留恢复与撤销依据。\n";
                echo "应用维护调度：app:schedule once|history|work <次数> <间隔毫秒>；固定任务与执行历史通过 Redis 协调。\n";
                echo "设备接入：center:mqtt-install、center:mqtt-statistics、center:mqtt；原始通信需要 PostgreSQL 同步持久后端，Demo 拒绝启动通信写入角色。\n";
                echo "MQTT 协议节点：broker:run、broker:store-install，使用同一应用安装和平台运维身份；broker:store 是受控持久工作角色。\n";
                echo "协议节点持久恢复：broker:nodes；broker:node-fence <node-id> <node-run> <observation-run> <actor> <proof-ref> [operation-id]，只登记已经完成的基础设施硬隔离；broker:node-fence-result <operation-id> 仅对账。\n";
                echo "集群管理：center:mqtt-nodes、center:mqtt-fence <node-id> <run-id> <actor> <proof-ref> [operation-id]；fence仅登记已完成的基础设施硬隔离；center:mqtt-fence-result <operation-id> 仅对账。\n";
                echo "WAL维护：center:wal <archive|restore|verify> <私有归档目录> <源文件|WAL名称> [WAL名称|新目标文件]。\n";
                echo "备份保留：center:backup register <私有归档目录> <备份ID> <PG17工具根>；clean <私有归档目录> <PG17工具根> [批次1至100]；status <私有归档目录>；pin|unpin <私有归档目录> <备份ID> <保护ID>。\n";
                echo "恢复核对：center:recovery <snapshot|status|begin|isolate|review|restore>；snapshot输出敏感身份摘要，begin需恢复ID、操作人和已完成隔离的依据，review需恢复ID、私有清单、已核对SHA256和偏移量。备份恢复后须核对当前账号、角色、证书与调试授权再开放。\n";
                echo "DB_DRIVER 必须与程序的数据库 profile 一致；空库执行 app:install 后 serve，生产默认使用 Swoole 线程与协程。System 与 Center 共用当前 Tenant 的 System 会话，Platform 身份独立。\n";
                echo "前端安装：web:install [--force] [--dry-run]；升级：app:upgrade [--force] [--dry-run]，先处理内置数据库迁移，再提交内置页面文件。首次app:install同时安装页面；强制更新只处理内置页面文件，不修改数据库、上传或配置。主域名默认 /#/system/login；平台 /#/platform/login；客户固定路径 /t/{tenant_id}/ 或登记子域名。\n";

                return 0;
            }
            if ($command === 'migrate' && (count($arguments) === 2 || (count($arguments) === 3 && $arguments[2] === 'help'))) {
                echo "迁移查询：status、history；异常记录核对：recover <版本> <retry|applied> <恢复说明>。全新空库仅通过 app:install 初始化，run 不开放。\n";
                return 0;
            }
            $basePath = Settings::basePath($arguments[0] ?? '', $development);
            $configuration = ApplicationContext::configuration($basePath, $arguments[0] ?? '', $development);
            // HTTP/MQTT 宿主拥有原生事件循环；业务 scope 在请求/消息处理内建立。
            if (in_array($command, ['serve', 'center:mqtt', 'broker:run'], true)) {
                $context = new ApplicationContext($basePath, $arguments[0] ?? '', $development ? '1' : '0', '');
                return (new ManagementCommand($context, $command))->run($configuration, array_slice($arguments, 2));
            }
            $application = new CommandApplication($configuration);
            return $application->run($command === 'check' ? 'app:check' : $command, array_slice($arguments, 2));
        } catch (\Throwable $error) {
            $message = $error instanceof \InvalidArgumentException ? $error->getMessage() : 'internal_error';
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
     * @param ?DatabaseManager $database 宿主可注入供请求与就绪共用的管理器，宿主退出时负责关闭
     * @throws \InvalidArgumentException 模式、令牌、Host、预算或 SQLite 初始化条件无效
     * @throws \RuntimeException 存储不兼容、恢复门禁未就绪或预检失败
     */
    public static function handler(Repository $settings, string $basePath, bool $development = false, ?DatabaseManager $database = null): RequestHandlerInterface
    {
        Settings::validateRuntimeConfiguration($settings, $basePath, 'http');
        $environment = Settings::environment($settings, $development);
        $debug = Settings::debug($settings, $development);
        $probeToken = $settings->text('app.broker.probe_token');
        if ($probeToken !== '' && (strlen($probeToken) < 32 || strlen($probeToken) > 256 || preg_match('/^[A-Za-z0-9._~+\/-]+=*$/D', $probeToken) !== 1)) {
            throw new \InvalidArgumentException('BROKER_PROBE_TOKEN必须为32至256字符的独立Bearer令牌');
        }
        $policy = Settings::requestPolicy($settings);
        DatabaseFactory::requireExisting($settings, $basePath);
        // PDO hook 已在主线程安装；业务线程的启动预检也须在协程内创建和释放连接。
        CoroutineRuntime::run(static function () use ($settings, $basePath): void {
            $compat = Settings::database($settings, $basePath, 1);
            $compatScope = new ExecutionScope(new Deadline(2.0));
            try {
                Db::configure($compat);
                $compatScope->run(static function (): void {
                    $connection = Db::connection('default', true);
                    Schema::ready($connection);
                    CompatService::assertRuntime($connection);
                    RecoveryService::ready($connection);
                });
            } finally {
                $compatScope->close();
                $compat->close();
            }
        });
        $database ??= Settings::database($settings, $basePath);
        Db::configure($database);
        $messages = new Factory();
        $router = new Router($messages, $messages);
        $application = new CommandApplication(ApplicationContext::configuration($basePath, '', $development, $settings));
        $application->registerRoutes($router);
        if ($environment === 'demo') {
            $router->middleware(static fn (): ReadOnlyPolicy => new ReadOnlyPolicy([
                'platform.login', 'system.login', 'platform.logout', 'system.logout',
            ]));
        }

        $pages = new FrontendPages(FrontendAssets::application($basePath, $development));
        return new Pipeline([
            static fn (): RequestLog => new RequestLog($settings->text('app.name')),
            static fn (): ApiErrors => new ApiErrors($messages, $debug, dirname(__DIR__, 3)),
            static fn (): RequestPolicy => $policy,
            static fn (): TenantContext => new TenantContext(
                new ApplicationContext($basePath, '', $development ? '1' : '0', json_encode(['app' => $settings->array('app'), 'database' => $settings->array('database'), 'cache' => $settings->array('cache')], JSON_THROW_ON_ERROR))
            ),
        ], new ActionHandler(static function (ServerRequestInterface $request) use ($router, $messages, $pages): ResponseInterface {
            $response = $pages->respond($request, $messages);
            if ($response !== null) {
                return $response;
            }
            return $router->handle($request);
        }));
    }

    /**
     * 返回服务器可复用的有界输入声明，不打开临时文件或创建目录。
     */
    public static function requestLimits(Repository $settings): RequestLimits
    {
        return Settings::requestLimits($settings);
    }

    /**
     * 创建 Swoole HTTP 服务器。传输引擎固定复用 Swoole 原生 Server 与协程，不提供并行实现。
     *
     * 创建对象不监听端口；返回的服务器由调用方负责 serve/stop 生命周期。
     */
    public static function server(Repository $settings, string $basePath, bool $development = false): HttpServerInterface
    {
        $database = Settings::database($settings, $basePath);
        try {
            $handler = self::handler($settings, $basePath, $development, $database);
        } catch (\Throwable $error) {
            $database->close();
            throw $error;
        }
        $readiness = new HttpReadiness($database);
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

    /**
     * 运行已选择的服务器，并在正常退出或异常后关闭；不会自动迁移或生成生产源码。
     */
    public static function serve(Repository $settings, string $basePath, bool $development = false): void
    {
        Settings::validateRuntimeConfiguration($settings, $basePath, 'http');
        RuntimeCapabilities::requireFeature('web');
        if (!$development) {
            CoroutineRuntime::enableIo();
            $listen = $settings->text('app.http.listen');
            $port = Settings::integer($settings, 'app.http.port', 1, 65535);
            $payload = json_encode(['base' => $basePath, 'settings' => ['app' => $settings->array('app'), 'database' => $settings->array('database'), 'cache' => $settings->array('cache')], 'listen' => $listen, 'port' => $port], JSON_THROW_ON_ERROR);
            // Windows IOCP 共享监听尚未验收；单业务线程自行绑定，主控仍走 ThreadSupervisor 停止/join。
            if (PHP_OS_FAMILY === 'Windows') {
                (new ThreadSupervisor(1))->run('http', [$payload], null);
                return;
            }
            $count = Settings::httpThreads($settings);
            $listener = new Socket(AF_INET, SOCK_STREAM, 0);
            try {
                if (!$listener->bind($listen, $port) || !$listener->listen(128)) {
                    throw new \RuntimeException('HTTP 监听失败');
                }
                (new ThreadSupervisor($count))->run('http', array_fill(0, $count, $payload), $listener);
            } finally {
                $listener->close();
            }
            return;
        }
        $server = self::server($settings, $basePath, $development);
        try {
            $server->serve($settings->text('app.http.listen'), Settings::integer($settings, 'app.http.port', 1, 65535));
        } finally {
            $server->stop();
        }
    }

    /**
     * 编译期登记的 HTTP 线程入口；每线程从同一启动数据建立自己的配置与资源。
     */
    public static function httpThread(string $payload): int
    {
        $data = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
        $arguments = Thread::getArguments();
        $listener = $arguments[1] ?? null;
        $state = $arguments[2] ?? null;
        if (!$state instanceof Map) {
            throw new \RuntimeException('HTTP 线程缺少受管停止状态');
        }
        $server = self::server(new Repository($data['settings']), (string)$data['base']);
        if (!$server instanceof SwooleServer) {
            throw new \RuntimeException('HTTP 线程只接受 Swoole');
        }
        try {
            if ($listener instanceof Socket) {
                $server->serveThread($listener, $state);
            } elseif ($listener === null && isset($data['listen'], $data['port']) && is_string($data['listen']) && is_int($data['port'])) {
                $server->serveThreadOwned($data['listen'], $data['port'], $state);
            } else {
                throw new \RuntimeException('HTTP 线程缺少受管监听或自绑定地址');
            }
        } catch (\Throwable $error) {
            // Windows 等平台线程异常退出时，主控只看到 thread_exit_unexpected；此处保留可观测的稳定码与类型。
            $code = $error instanceof TaskException ? $error->errorCode() : $error::class;
            fwrite(STDERR, 'HTTP 业务线程失败：' . $code . ' ' . $error->getMessage() . "\n");
            throw $error;
        } finally {
            $server->stop();
        }
        return 0;
    }
}
