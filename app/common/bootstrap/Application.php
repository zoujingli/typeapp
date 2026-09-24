<?php

declare(strict_types=1);

namespace app\common\bootstrap;

use app\common\database\DatabaseFactory;
use app\common\database\Schema;
use app\common\controller\AuthController;
use app\common\controller\ObservationController;
use app\admin\controller\AdminController;
use app\common\middleware\ApiErrors;
use app\common\middleware\RequestLog;
use app\broker\controller\AccessController;
use app\broker\controller\CompatController;
use app\broker\controller\RecoveryController;
use app\broker\controller\DebugController;
use app\broker\controller\QuotaController;
use app\broker\controller\RuntimeController;
use app\broker\controller\BrokerController;
use app\broker\service\CompatService;
use app\broker\service\NodeAccess;
use app\broker\service\QuotaService;
use app\generated\Routes;
use app\iot\middleware\IotAuthentication;
use app\iot\service\AggregateService;
use app\iot\service\AlarmService;
use app\common\service\AuditLog;
use app\iot\service\CommandService;
use app\iot\service\DeviceAccess;
use app\iot\service\DeviceBuffer;
use app\iot\service\DeviceSimulator;
use app\iot\service\DeviceService;
use app\iot\controller\DeviceController;
use app\iot\controller\TransferController;
use app\iot\controller\AlarmController;
use app\iot\controller\ExportController;
use app\iot\service\ExportService;
use app\iot\service\ExportJob;
use app\iot\service\ExportPublisher;
use app\iot\service\HistoryService;
use app\common\service\IdentityService;
use app\iot\service\IngestionWorker;
use app\iot\service\ProductService;
use app\iot\controller\ProductController;
use InvalidArgumentException;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use Type\Core\Config\Repository;
use Type\Core\Http\Authentication;
use Type\Core\Http\CanonicalRequest;
use Type\Core\Http\HttpControl;
use Type\Core\Http\HttpServerInterface;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\Pipeline;
use Type\Core\Http\RequestLimits;
use Type\Core\Http\RequestPolicy;
use Type\Core\Http\Router;
use Type\Core\Http\SwooleServer;
use Type\Orm\DatabaseManager;
use Type\Mqtt\Broker;
use Type\Mqtt\BrokerOptions;
use Type\Mqtt\Client;
use Type\Mqtt\ConnectPacket;
use Type\Mqtt\PendingCommit;
use Type\Mqtt\PostgresStore;
use Type\Orm\Migration\MigrationConsole;
use Type\Orm\Migration\Migrator;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\Deadline;
use Type\Runtime\ExecutionScope;

/**
 * 组合标准应用的角色、声明与模式；HTTP 业务处理链独立于实际服务器适配器。
 *
 * 开发入口通过显式参数取得开发权限，原生入口不加载开发生成器或源码。
 */
final class Application
{
    /**
     * 帮助不要求配置或生成代码；其余角色读取一次配置，失败以非零进程状态退出。
     *
     * @param list<string> $arguments 包含程序名的完整 CLI 参数。
     */
    public static function run(array $arguments, bool $development = false): int
    {
        $debug = false;
        try {
            $command = $arguments[1] ?? 'help';
            if ($command === 'verify-runtime') {
                if (count($arguments) !== 2 || !class_exists(\Type\Generated\BuildIdentity::class, false)) {
                    throw new InvalidArgumentException('verify-runtime 只接受原生产物且不接受额外参数');
                }
                \Type\Generated\BuildIdentity::verifyDeployment();
                echo "运行环境完整性校验通过。\n";
                return 0;
            }
            if (class_exists(\Type\Generated\BuildIdentity::class, false)) {
                \Type\Generated\BuildIdentity::verifyRuntime();
            }
            CoroutineRuntime::enableIo();
            if ($command === 'help' || $command === '--help') {
                if (count($arguments) > 2) {
                    throw new InvalidArgumentException('help 不接受额外参数');
                }
                echo "TypeApp 物联中心：help、check、verify-runtime、serve、app:install <管理账号> <管理姓名> <客户账号> <客户姓名> <租户名>、migrate <status|history|recover>。初始化口令由 APP_ADMIN_PASSWORD、APP_CUSTOMER_PASSWORD 的受控进程环境提供。\n";
                echo "运行配置：config:check [--connect] [--remember]；config:restore 恢复最后通过连接检查的文件，成功退出4表示仍须运维重启。\n";
                echo "审计保留：app:audit-clean <admin|customer> [batch]，单批最多1000条，清理满180天事件并保留恢复与撤销依据。\n";
                echo "历史业务维护角色：iot:command-clean、iot:history-clean、iot:aggregate、iot:aggregate-clean、iot:alarm、iot:mqtt-install、iot:mqtt-statistics、iot:mqtt、iot:ingest、iot:device、iot:exports、iot:exports-work、iot:exports-clean；尚未转换的业务不向新应用公开。\n";
                echo "独立 Broker：broker:install、broker:migrate <status|history|recover>、broker:user <login> <name>、broker:serve、broker:run、broker:store-install；初始化密码由 BROKER_ADMIN_PASSWORD 提供。升级后启动会核对兼容代次，不能用更旧二进制维持新的占用与吊销语义。\n";
                echo "独立持久恢复：broker:nodes；broker:node-fence <node-id> <node-run> <observation-run> <actor> <proof-ref> [operation-id]，只登记已经完成的基础设施硬隔离；broker:node-fence-result <operation-id> 仅对账；broker:audit-clean [batch] 清理满180天审计。\n";
                echo "站内通知：iot:notices [batch]、iot:notices-clean [batch]；默认100、最多100。\n";
                echo "集群管理：iot:mqtt-nodes、iot:mqtt-fence <node-id> <run-id> <actor> <proof-ref> [operation-id]；fence仅登记已完成的基础设施硬隔离；iot:mqtt-fence-result <operation-id> 仅对账。\n";
                echo "WAL维护：iot:wal <archive|restore|verify> <私有归档目录> <源文件|WAL名称> [WAL名称|新目标文件]。\n";
                echo "备份保留：iot:backup register <私有归档目录> <备份ID> <PG17工具根>；clean <私有归档目录> <PG17工具根> [批次1至100]；status <私有归档目录>；pin|unpin <私有归档目录> <备份ID> <保护ID>。\n";
                echo "恢复核对：iot:recovery 与独立 broker:recovery <snapshot|status|begin|isolate|review|restore>；snapshot输出敏感身份摘要，begin需恢复ID、操作人和已完成隔离的依据，review需恢复ID、私有清单、已核对SHA256和偏移量。旧备份恢复后须核对证书与调试授权再开放。\n";
                echo "默认使用 SQLite；空库执行 app:install 后 serve，生产默认使用 Swoole 线程与协程。客户端 /，管理端 /admin；业务接口按固定账号域和租户权限开放。\n";

                return 0;
            }
            if ($command === 'iot:backup') {
                $operation = $arguments[2] ?? '';
                if (!in_array($operation, ['register', 'clean', 'status', 'pin', 'unpin'], true)
                    || ($operation === 'status' && count($arguments) !== 4)
                    || (in_array($operation, ['register', 'pin', 'unpin'], true) && count($arguments) !== 6)
                    || ($operation === 'clean' && (count($arguments) < 5 || count($arguments) > 6))) {
                    throw new InvalidArgumentException('iot:backup参数无效，请使用help');
                }
                $archive = new \app\iot\service\RecoveryArchive($arguments[3]);
                $record = match ($operation) {
                    'register' => $archive->registerBackup($arguments[4], $arguments[5]),
                    'clean' => $archive->cleanBackups($arguments[4], isset($arguments[5]) && ctype_digit($arguments[5]) ? (int) $arguments[5] : (isset($arguments[5]) ? 0 : 100)),
                    'pin' => $archive->pinBackup($arguments[4], $arguments[5]),
                    'unpin' => $archive->pinBackup($arguments[4], $arguments[5], true),
                    default => $archive->backupStatus(),
                };
                echo json_encode($record, JSON_THROW_ON_ERROR) . "\n";
                return 0;
            }
            if ($command === 'iot:wal') {
                $operation = $arguments[2] ?? '';
                if (!in_array($operation, ['archive', 'restore', 'verify'], true) || count($arguments) !== ($operation === 'verify' ? 5 : 6)) {
                    throw new InvalidArgumentException('iot:wal参数无效，请使用help');
                }
                $archive = new \app\iot\service\RecoveryArchive($arguments[3]);
                $record = match ($operation) {
                    'archive' => $archive->archive($arguments[4], $arguments[5]),
                    'restore' => $archive->restore($arguments[4], $arguments[5]),
                    default => $archive->verify($arguments[4]),
                };
                echo json_encode($record, JSON_THROW_ON_ERROR) . "\n";
                return 0;
            }
            if (in_array($command, ['config:check', 'config:restore'], true)) {
                $result = Settings::configurationCommand(Settings::basePath($arguments[0] ?? '', $development), $command, array_slice($arguments, 2));
                echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
                return (int) $result['exit'];
            }
            if (str_starts_with($command, 'broker:')) {
                $basePath = Settings::basePath($arguments[0] ?? '', $development);
                $settings = Settings::load($basePath);
                $debug = Settings::debug($settings, $development);
                return self::standalone($settings, $basePath, $command, array_slice($arguments, 2), $development);
            }
            if ($command === 'app:install') {
                $basePath = Settings::basePath($arguments[0] ?? '', $development);
                $settings = Settings::load($basePath);
                $adminPassword = getenv('APP_ADMIN_PASSWORD');
                $customerPassword = getenv('APP_CUSTOMER_PASSWORD');
                if (count($arguments) !== 7 || !is_string($adminPassword) || !is_string($customerPassword)) {
                    throw new InvalidArgumentException('app:install 需要管理账号、姓名、客户账号、姓名、租户名；APP_ADMIN_PASSWORD 和 APP_CUSTOMER_PASSWORD 由受控进程环境提供');
                }
                IdentityService::validateAccount($arguments[2], $arguments[3], $adminPassword);
                IdentityService::validateAccount($arguments[4], $arguments[5], $customerPassword);
                DatabaseFactory::prepareMigration($settings, $basePath);
                echo json_encode(['data' => Schema::install(DatabaseFactory::create($settings, $basePath), array_slice($arguments, 2), $adminPassword, $customerPassword)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
                return 0;
            }
            if (!in_array($command, ['check', 'serve', 'migrate', 'iot:recovery', 'app:audit-clean', 'iot:command-clean', 'iot:history-clean', 'iot:aggregate', 'iot:aggregate-clean', 'iot:alarm', 'iot:notices', 'iot:notices-clean', 'iot:mqtt', 'iot:mqtt-install', 'iot:mqtt-statistics', 'iot:mqtt-nodes', 'iot:mqtt-fence', 'iot:mqtt-fence-result', 'iot:mqtt-store', 'iot:mqtt-access', 'iot:ingest', 'iot:ingest-store', 'iot:device', 'iot:exports', 'iot:exports-work', 'iot:exports-clean'], true)) {
                throw new InvalidArgumentException('未知应用命令，请使用 help');
            }
            if ($command === 'migrate' && (count($arguments) === 2 || (count($arguments) === 3 && $arguments[2] === 'help'))) {
                echo "迁移查询：status、history；异常记录核对：recover <版本> <retry|applied> <恢复说明>。全新空库仅通过 app:install 初始化，run 不开放。\n";

                return 0;
            }
            $basePath = Settings::basePath($arguments[0] ?? '', $development);
            $settings = Settings::load($basePath);
            $environment = Settings::environment($settings, $development);
            $debug = Settings::debug($settings, $development);
            if ($command === 'iot:recovery') {
                self::recovery($settings, $basePath, array_slice($arguments, 2));
                return 0;
            }
            // 内部持久子进程由已通过启动门的父角色拥有；设备端与离线检查不连接业务库。
            if (!in_array($command, ['check', 'migrate', 'iot:device', 'iot:mqtt-store', 'iot:mqtt-access', 'iot:ingest-store'], true)) {
                self::recovery($settings, $basePath, ['gate']);
            }
            if (str_starts_with($command, 'iot:exports')) {
                self::exports($settings, $basePath, $command, array_slice($arguments, 2));
                return 0;
            }
            if ($command === 'iot:device') {
                self::device($settings, $basePath, array_slice($arguments, 2));
                return 0;
            }
            if (str_starts_with($command, 'iot:mqtt') || str_starts_with($command, 'iot:ingest')) {
                self::mqtt($settings, $basePath, $command, array_slice($arguments, 2));
                return 0;
            }
            if ($command === 'check') {
                if (count($arguments) !== 2) {
                    throw new InvalidArgumentException('check 不接受额外参数');
                }
                echo '应用声明有效，数据库驱动：' . DatabaseFactory::name($settings)
                    . '；运行模式：' . $environment . '；调试：' . ($debug ? 'on' : 'off')
                    . "；配置、模型、路由与调用包装已静态装配，未连接数据库。\n";

                return 0;
            }
            if ($command === 'migrate') {
                return self::migrate($settings, $basePath, array_slice($arguments, 2));
            }
            if (in_array($command, ['app:audit-clean', 'iot:command-clean'], true)) {
                self::pruneRecords($settings, $basePath, array_slice($arguments, 2), $command);
                return 0;
            }
            if ($command === 'iot:history-clean') {
                self::pruneHistory($settings, $basePath, array_slice($arguments, 2));
                return 0;
            }
            if ($command === 'iot:alarm') {
                self::alarm($settings, $basePath, array_slice($arguments, 2));
                return 0;
            }
            if ($command === 'iot:notices' || $command === 'iot:notices-clean') {
                self::notices($settings, $basePath, $command === 'iot:notices-clean', array_slice($arguments, 2));
                return 0;
            }
            if ($command === 'iot:aggregate' || $command === 'iot:aggregate-clean') {
                self::aggregate($settings, $basePath, $command === 'iot:aggregate-clean', array_slice($arguments, 2));
                return 0;
            }
            if (count($arguments) !== 2) {
                throw new InvalidArgumentException('serve 不接受额外参数，启动值从配置读取');
            }
            self::serve($settings, $basePath, $development);
            return 0;
        } catch (Throwable $error) {
            $message = $error->getMessage();
            $publicMessage = $error instanceof InvalidArgumentException || str_starts_with($message, 'dotenv ')
                || (in_array($command, ['iot:wal', 'iot:backup', 'iot:recovery', 'broker:recovery'], true) && preg_match('/^recovery_[a-z_]+$/D', $message) === 1)
                || $message === 'recovery_isolated'
                ? $message : 'internal_error';
            if ($command === 'app:install') {
                if ($error instanceof \Type\Orm\Migration\MigrationException && preg_match('/^TYPE_MIGRATION_[A-Z_]+/', $message, $matched) === 1) {
                    $publicMessage = $matched[0];
                } elseif ($error instanceof \Type\Core\Http\HttpError) {
                    $publicMessage = $error->errorCode();
                }
            }
            if ($command === 'serve' && $error instanceof \Type\Runtime\TaskException) {
                $publicMessage = $error->errorCode();
            }
            fwrite(STDERR, 'TypeApp 物联中心启动或命令失败：' . $publicMessage . "\n");
            if (getenv('TYPE_APP_TRACE') === '1' && $publicMessage === 'internal_error') {
                fwrite(STDERR, $error::class . ': ' . $message . "\n");
            }
            if ($debug) {
                fwrite(STDERR, json_encode([
                    'error' => 'internal_error',
                    'exception_type' => get_class($error),
                    'mqtt_reason' => $error instanceof \Type\Mqtt\ProtocolError ? $error->reason : null,
                    'file' => basename(str_replace('\\', '/', $error->getFile())),
                    'line' => max(0, $error->getLine()),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
            }
            return 1;
        }
    }

    /**
     * 受控维护入口；旧系统及当前授权来源须已停止写入，恢复库只能供维护角色访问。
     *
     * @param list<string> $arguments 子命令及参数。
     */
    private static function recovery(Repository $settings, string $basePath, array $arguments, string $host = 'app'): void
    {
        $operation = $arguments[0] ?? '';
        $expected = match ($operation) {
            'snapshot', 'status', 'gate' => 1, 'begin' => 4, 'isolate', 'restore' => 2, 'review' => 5, default => 0,
        };
        $prefix = $host === 'broker' ? 'broker:recovery' : 'iot:recovery';
        if ($expected === 0 || count($arguments) !== $expected) {
            throw new InvalidArgumentException($prefix . '参数无效，请使用help；每次isolate/review/restore最多处理100个主体');
        }
        DatabaseFactory::requireExisting($settings, $basePath);
        CoroutineRuntime::run(static function () use ($settings, $basePath, $arguments, $host, $operation): void {
            $database = new DatabaseManager(['default' => DatabaseFactory::create($settings, $basePath)], 1, 0);
            \Type\Orm\Db::configure($database);
            $scope = new ExecutionScope();
            try {
                $scope->run(static function (ExecutionScope $current) use ($basePath, $arguments, $host, $operation): void {
                    $connection = \Type\Orm\Db::connection('default', true);
                    if ($operation === 'gate') {
                        \app\iot\service\RecoveryService::ready($connection, $host);
                        return;
                    }
                    if ($operation === 'review') {
                        $offset = filter_var($arguments[4], FILTER_VALIDATE_INT);
                        if ($offset === false || $offset < 0) {
                            throw new InvalidArgumentException('核对偏移量必须为非负整数');
                        }
                        $path = Settings::absolutePath($arguments[2]) ? $arguments[2] : $basePath . '/' . $arguments[2];
                        $metadata = @lstat($path);
                        if ($metadata === false || ($metadata['mode'] & 0170000) !== 0100000 || ($metadata['mode'] & 0077) !== 0 || $metadata['size'] > 67108864) {
                            throw new InvalidArgumentException('恢复清单必须是至多64MiB且仅属主可访问的普通文件');
                        }
                        $stream = @fopen($path, 'rb');
                        if (!is_resource($stream)) {
                            throw new InvalidArgumentException('恢复清单不可读');
                        }
                        try {
                            $opened = fstat($stream);
                            if ($opened === false || $opened['dev'] !== $metadata['dev'] || $opened['ino'] !== $metadata['ino'] || $opened['mode'] !== $metadata['mode']) {
                                throw new InvalidArgumentException('恢复清单文件身份已改变');
                            }
                            $contents = stream_get_contents($stream, 67108865);
                            if ($contents === false) {
                                throw new InvalidArgumentException('恢复清单读取失败');
                            }
                        } finally {
                            fclose($stream);
                        }
                        $record = \app\iot\service\RecoveryService::review($connection, $arguments[1], $contents, $arguments[3], $offset, $host);
                    } else {
                        $record = match ($operation) {
                            'snapshot' => \app\iot\service\RecoveryService::snapshot($connection, $host),
                            'status' => \app\iot\service\RecoveryService::status($connection, $host),
                            'begin' => \app\iot\service\RecoveryService::begin($connection, $arguments[1], $arguments[2], $arguments[3], $host),
                            'isolate' => \app\iot\service\RecoveryService::isolate($connection, $arguments[1], $host),
                            default => \app\iot\service\RecoveryService::restore($connection, $arguments[1], $host),
                        };
                    }
                    echo json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
                });
            } finally {
                $scope->close();
                $database->close();
            }
        });
    }

    /** 相对私有文件目录以APP_BASE_PATH为基准，HTTP和后台角色采用同一启动配置。 */
    private static function exportService(Repository $settings, string $basePath): ExportService
    {
        $directory = $settings->text('app.exports.directory');
        return new ExportService(Settings::absolutePath($directory) ? $directory : $basePath . '/' . $directory);
    }

    /** 有界后台角色复用Outbox、队列和Worker；只有工作角色连接Redis，清理可在队列故障时独立执行。 */
    private static function exports(Repository $settings, string $basePath, string $command, array $arguments): void
    {
        \Type\Runtime\CoroutineRuntime::run(static function () use ($settings, $basePath, $command, $arguments): void {
            $limit = filter_var($arguments[0] ?? '100', FILTER_VALIDATE_INT);
            if (count($arguments) > 1 || $limit === false || $limit < 1 || $limit > ($command === 'iot:exports-clean' ? 100 : ($command === 'iot:exports-work' ? 3600 : 10000))) {
                throw new InvalidArgumentException('导出参数需为有界批次，work参数为1至3600秒');
            }
            DatabaseFactory::requireExisting($settings, $basePath);
            $database = new DatabaseManager(['default' => DatabaseFactory::create($settings, $basePath)], 2, 0);
            \Type\Orm\Db::configure($database);
            $scope = new ExecutionScope();
            $exports = self::exportService($settings, $basePath);
            $redis = null;
            $signals = new \Type\Runtime\ProcessSignals();
            try {
                $scope->run(static function (ExecutionScope $current) use ($settings, $basePath, $command, $limit, $database, $scope, $exports, &$redis, $signals): void {
                    if ($command === 'iot:exports-clean') {
                        echo json_encode(['data' => $exports->clean(\Type\Orm\Db::connection('default', true), $limit)], JSON_THROW_ON_ERROR) . "\n";
                        return;
                    }
                    $redis = new \Type\Redis\RedisManager(['exports' => Settings::redis($settings, $basePath, 'exports')]);
                    $queue = new \Type\Queue\Queue($redis->connection($scope, 'exports', \Type\Redis\Purpose::SCRIPT), $settings->text('app.exports.namespace'), 'exports', 60000, 2000);
                    $registry = new \Type\Queue\Registry();
                    $registry->register('iot.export', 1, static fn (\Type\Queue\JobContext $context): ExportJob => new ExportJob($exports));
                    $worker = new \Type\Queue\Worker($queue, $registry, 'exports-' . getmypid(), new \Type\Queue\RetryPolicy(100, 1000, 60000, 30000));
                    $signals->attach(static function () use ($worker): void {
                        $worker->stop(5.0);
                    });
                    $relay = new \Type\Orm\Outbox\Relay($database, new \Type\Orm\Outbox\Store('iot_export_outbox'), new ExportPublisher($queue));
                    $processed = 0;
                    $published = 0;
                    $deadline = microtime(true) + ($command === 'iot:exports-work' ? $limit : 3600);
                    while ($worker->ready() && microtime(true) < $deadline && ($command === 'iot:exports-work' || $processed < $limit)) {
                        $signals->dispatch();
                        $published += $relay->runOnce(10);
                        if ($worker->runOnce()) {
                            $processed++;
                        } elseif ($command !== 'iot:exports-work') {
                            break;
                        } else {
                            usleep(50000);
                        }
                    }
                    $worker->stop(5.0);
                    echo json_encode(['data' => ['published' => $published, 'processed' => $processed, 'worker' => $worker->statistics()]], JSON_THROW_ON_ERROR) . "\n";
                });
            } finally {
                $signals->close();
                $scope->close();
                $database->close();
                $redis?->close();
            }
        });
    }

    /** 设备本地SQLite缓存与平台数据库装配独立；网络发送复用标准客户端及受管信号。 */
    private static function device(Repository $settings, string $basePath, array $arguments): void
    {
        if (count($arguments) !== 1 || !in_array($arguments[0], ['state', 'enqueue', 'send', 'listen', 'transfer'], true)) {
            throw new InvalidArgumentException('iot:device需要state、enqueue、send、listen或transfer；enqueue和transfer从标准输入读取JSON');
        }
        $cache = $settings->text('app.device.cache');
        if ($cache === '') {
            throw new InvalidArgumentException('设备角色需要IOT_DEVICE_CACHE和已存在的父目录');
        }
        $provision = [];
        $supportedModels = json_decode($settings->text('app.device.supported_models'), true, 8, JSON_THROW_ON_ERROR);
        if ($arguments[0] === 'transfer') {
            $input = stream_get_contents(STDIN, 16385);
            $provision = is_string($input) && strlen($input) <= 16384 ? json_decode($input, true, 16, JSON_THROW_ON_ERROR) : null;
            if (!is_array($provision)) {
                throw new InvalidArgumentException('device_transfer_provision_invalid');
            }
        }
        $buffer = new DeviceBuffer(
            Settings::absolutePath($cache) ? $cache : $basePath . '/' . $cache,
            $settings->text('app.device.id'),
            $settings->text('app.device.ownership_id'),
            Settings::integer($settings, 'app.device.maximum_records', 1, 86400),
            Settings::integer($settings, 'app.device.maximum_bytes', 128, 1073741824),
            128,
            1048576,
            $provision,
            $supportedModels
        );
        $simulator = null;
        $signals = new \Type\Runtime\ProcessSignals();
        try {
            $model = $buffer->modelVersion(Settings::integer($settings, 'app.device.model_version', 1, 2147483646));
            if (in_array($arguments[0], ['state', 'transfer'], true)) {
                echo json_encode($buffer->statistics(), JSON_THROW_ON_ERROR) . "\n";
                return;
            }
            if ($arguments[0] === 'enqueue') {
                $input = stream_get_contents(STDIN, 16385);
                $sample = is_string($input) && strlen($input) <= 16384 ? json_decode($input, false, 32, JSON_THROW_ON_ERROR) : null;
                if (!$sample instanceof \stdClass || !is_string($sample->type ?? null) || !is_int($sample->sampled_at ?? null)
                    || !($sample->values ?? null) instanceof \stdClass || !is_string($sample->identifier ?? '')
                    || array_diff(array_keys(get_object_vars($sample)), ['type', 'sampled_at', 'values', 'identifier']) !== []) {
                    throw new InvalidArgumentException('device_sample_invalid');
                }
                $record = $buffer->enqueue($model, $sample->type, $sample->sampled_at, $sample->values, $sample->identifier ?? '');
                echo json_encode(['status' => $record === null ? 'not_admitted' : 'buffered', 'sequence' => $record['sequence'] ?? null,
                    'buffer' => $buffer->statistics()], JSON_THROW_ON_ERROR) . "\n";
                return;
            }
            $ca = $settings->text('app.device.ca');
            if ($ca === '') {
                throw new InvalidArgumentException('设备发送需要TLS CA');
            }
            $simulator = new DeviceSimulator(
                $buffer,
                $settings->text('app.device.tenant_id'),
                $model,
                $settings->text('app.device.credential_id'),
                $settings->text('app.device.password'),
                $settings->text('app.device.host'),
                Settings::integer($settings, 'app.device.port', 1, 65535),
                Settings::absolutePath($ca) ? $ca : $basePath . '/' . $ca,
                $settings->text('app.device.peer_name'),
                $arguments[0] === 'listen' ? static fn (string $identifier, \stdClass $values): \stdClass => (object) ['simulated' => true, 'identifier' => $identifier] : null,
                $supportedModels
            );
            $signals->attach(static function () use ($simulator): void {
                $simulator->stop();
            });
            try {
                $result = $simulator->run(60.0, 15.0, $signals, $arguments[0] === 'listen');
                echo json_encode(['status' => $result['stopped'] ? 'stopped' : 'finished', 'delivery' => $result,
                    'buffer' => $buffer->statistics()], JSON_THROW_ON_ERROR) . "\n";
            } catch (\RuntimeException $unconfirmed) {
                echo json_encode(['status' => 'retry_required', 'code' => 'device_delivery_unconfirmed', 'buffer' => $buffer->statistics()], JSON_THROW_ON_ERROR) . "\n";
                throw $unconfirmed;
            }
        } finally {
            $signals->close();
            $simulator?->close();
            $buffer->close();
        }
    }

    /**
     * 应用设备接入角色；HTTP及注册继续支持三库，持久MQTT设备会话只用约定的PostgreSQL同步后端。
     * @param list<string> $arguments 持久worker通过 Swoole PROC hook 管理的进程管道接入；节点硬隔离登记接受精确运行身份与已完成的隔离证明。
     */
    private static function mqtt(Repository $settings, string $basePath, string $role, array $arguments): void
    {
        if (DatabaseFactory::name($settings) !== 'pgsql') {
            throw new InvalidArgumentException('设备MQTT接入需要PostgreSQL同步持久后端');
        }
        $driver = DatabaseFactory::create($settings, $basePath);
        if ($role === 'iot:mqtt-store' || $role === 'iot:ingest-store') {
            if ($arguments !== ['--store-worker-pipe']) {
                throw new InvalidArgumentException('持久worker需要 Swoole PROC hook 管理的进程管道');
            }
            $store = new PostgresStore(
                $driver,
                $settings->text('app.mqtt.standby'),
                maximumPendingMessages: Settings::integer($settings, 'app.mqtt.maximum_pending_messages', 1, 2000000),
                maximumPendingBytes: Settings::integer($settings, 'app.mqtt.maximum_pending_bytes', 1, 4294967296),
                maximumSharedMessages: Settings::integer($settings, 'app.mqtt.maximum_shared_messages', 1, 1000000),
                maximumSharedBytes: Settings::integer($settings, 'app.mqtt.maximum_shared_bytes', 1, 2147483648),
                maximumSessions: Settings::integer($settings, 'app.mqtt.maximum_sessions', 1, 20000),
                maximumDeviceMessages: Settings::integer($settings, 'app.mqtt.maximum_device_messages', 1, 10000),
                maximumDeviceBytes: Settings::integer($settings, 'app.mqtt.maximum_device_bytes', 1, 16777216),
                maximumApplicationMessages: Settings::integer($settings, 'app.mqtt.maximum_application_messages', 1, 1000000),
                maximumApplicationBytes: Settings::integer($settings, 'app.mqtt.maximum_application_bytes', 1, 2147483648)
            );
            if ($role === 'iot:ingest-store') {
                IngestionWorker::work($store, 'pipe');
            } else {
                PendingCommit::work($store, 'pipe');
            }
            return;
        }
        if ($arguments !== [] && !in_array($role, ['iot:mqtt-fence', 'iot:mqtt-fence-result'], true)) {
            throw new InvalidArgumentException('设备MQTT角色不接受额外参数');
        }
        if ($role === 'iot:mqtt-access') {
            DeviceAccess::work(new DatabaseManager(['default' => $driver], 1, 0), $settings->text('app.ingestion.credential_id'), $settings->text('app.ingestion.secret_hash'));
            return;
        }
        $command = json_decode($settings->text('app.mqtt.command'), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($command) || !array_is_list($command) || $command === []) {
            throw new InvalidArgumentException('IOT_MQTT_COMMAND必须为当前应用的显式命令参数数组');
        }
        foreach ($command as $argument) {
            if (!is_string($argument) || $argument === '' || str_contains($argument, "\0")) {
                throw new InvalidArgumentException('IOT_MQTT_COMMAND参数无效');
            }
        }
        if ($role === 'iot:ingest') {
            $instance = $settings->text('app.ingestion.instance');
            $credential = $settings->text('app.ingestion.credential_id');
            $password = $settings->text('app.ingestion.password');
            $ca = $settings->text('app.ingestion.ca');
            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/D', $instance) !== 1 || preg_match('/^[a-f0-9]{32}$/D', $credential) !== 1
                || preg_match('/^[a-f0-9]{64}$/D', $password) !== 1 || $ca === '') {
                throw new InvalidArgumentException('接收进程需要有效实例、独立服务凭据及TLS CA');
            }
            $client = new Client(
                $settings->text('app.ingestion.host'),
                Settings::integer($settings, 'app.ingestion.port', 1, 65535),
                'iot-ingestion-' . $instance,
                'service:ingestion:' . $credential,
                $password,
                Settings::absolutePath($ca) ? $ca : $basePath . '/' . $ca,
                $settings->text('app.ingestion.peer_name')
            );
            $ingestion = new IngestionWorker($client, [...$command, 'iot:ingest-store'], $instance);
            $ingestion->run();
            echo json_encode($ingestion->statistics(), JSON_THROW_ON_ERROR) . "\n";
            return;
        }
        $worker = [...$command, 'iot:mqtt-store'];
        if (in_array($role, ['iot:mqtt-fence', 'iot:mqtt-fence-result'], true)) {
            self::fenceNode($settings, $basePath, $worker, $arguments, 'admin', $role === 'iot:mqtt-fence-result');
            return;
        }
        if (in_array($role, ['iot:mqtt-install', 'iot:mqtt-statistics', 'iot:mqtt-nodes'], true)) {
            $operation = ['operation_id' => bin2hex(random_bytes(16)), 'action' => match ($role) {
                'iot:mqtt-install' => 'install', 'iot:mqtt-nodes' => 'node_statistics', default => 'session_statistics'
            }];
            $pending = new PendingCommit($worker, $operation);
            do {
                $result = $pending->poll();
                if ($result === null) {
                    usleep(10000);
                }
            } while ($result === null);
            if ($result->state !== 'committed' || !$result->released) {
                throw new \RuntimeException($role === 'iot:mqtt-install' ? 'device_mqtt_install_unconfirmed' : 'device_mqtt_statistics_unconfirmed');
            }
            echo $role === 'iot:mqtt-install' ? "设备MQTT持久存储已取得同步提交证明。\n" : json_encode($result->value, JSON_THROW_ON_ERROR) . "\n";
            return;
        }
        $limits = QuotaService::defaults();
        $limits['maximumConnections'] = Settings::integer($settings, 'app.mqtt.maximum_connections', 1, 10100);
        $limits['maximumDeviceConnections'] = Settings::integer($settings, 'app.mqtt.maximum_device_connections', 1, 10000);
        $limits['maximumServiceConnections'] = Settings::integer($settings, 'app.mqtt.maximum_service_connections', 0, 100);
        $access = new DeviceAccess($command, $settings->text('app.mqtt.node_id'), $limits);
        $certificate = $settings->text('app.mqtt.certificate');
        $privateKey = $settings->text('app.mqtt.private_key');
        /** 握手 CA 文件交给 Broker 做 TLS 校验；节点心跳按当前受信 CA 公钥重写该文件。 */
        $clientCa = $settings->text('app.mqtt.client_ca');
        $broker = new Broker(
            $access,
            new BrokerOptions(
                certificate: Settings::absolutePath($certificate) ? $certificate : $basePath . '/' . $certificate,
                privateKey: Settings::absolutePath($privateKey) ? $privateKey : $basePath . '/' . $privateKey,
                privateKeyPassphrase: $settings->text('app.mqtt.private_key_passphrase'),
                maximumConnections: Settings::integer($settings, 'app.mqtt.maximum_connections', 1, 10100),
                maximumDeviceConnections: Settings::integer($settings, 'app.mqtt.maximum_device_connections', 1, 10000),
                maximumServiceConnections: Settings::integer($settings, 'app.mqtt.maximum_service_connections', 0, 100),
                clustered: $settings->boolean('app.mqtt.clustered'),
                wsPort: Settings::integer($settings, 'app.mqtt.ws_port', 0, 65535),
                wssPort: Settings::integer($settings, 'app.mqtt.wss_port', 0, 65535),
                allowedOrigins: array_map('strtolower', Settings::list($settings->text('app.mqtt.allowed_origins'))),
                mtlsPort: Settings::integer($settings, 'app.mqtt.mtls_port', 0, 65535),
                clientCa: $clientCa === '' || Settings::absolutePath($clientCa) ? $clientCa : $basePath . '/' . $clientCa
            ),
            $worker,
            $settings->text('app.mqtt.node_id'),
            $access,
            // Broker只在DeviceAccess完成TLS、Client ID和独立服务凭据认证后调用，不采信客户端自报分类属性。
            static fn (ConnectPacket $connect): string => str_starts_with($connect->username ?? '', 'service:ingestion:') || str_starts_with($connect->username ?? '', 'debug:') ? 'application' : 'device',
            $access,
            $access,
            $access
        );
        $access->monitor(static fn (): array => $broker->statistics());
        try {
            $broker->serve($settings->text('app.mqtt.listen'), Settings::integer($settings, 'app.mqtt.port', 1, 65535));
        } finally {
            $access->monitor(null);
        }
        $statistics = $broker->statistics();
        echo json_encode($statistics, JSON_THROW_ON_ERROR) . "\n";
        if ($statistics['observationFailures'] > 0 || $statistics['invalidationFailures'] > 0 || $statistics['nodeFailures'] > 0) {
            throw new \RuntimeException('device_connection_observation_unavailable');
        }
    }

    /**
     * 构建与服务器实现无关的 PSR-15 业务处理链；构造驱动但不借用真实数据库连接。
     *
     * 每次实际请求必须由服务器创建执行作用域，响应结束后收回所有请求租约。
     *
     * @throws InvalidArgumentException 模式、令牌、Host、预算或 SQLite 初始化条件无效。
     */
    public static function handler(Repository $settings, string $basePath, bool $development = false, bool $broker = false): RequestHandlerInterface
    {
        $environment = Settings::environment($settings, $development);
        $debug = Settings::debug($settings, $development);
        $probeToken = $settings->text('app.broker.probe_token');
        if ($probeToken !== '' && (strlen($probeToken) < 32 || strlen($probeToken) > 256 || preg_match('/^[A-Za-z0-9._~+\/-]+=*$/D', $probeToken) !== 1)) {
            throw new InvalidArgumentException('BROKER_PROBE_TOKEN必须为32至256字符的独立Bearer令牌');
        }
        $port = Settings::integer($settings, 'app.http.port', 1, 65535);
        $hosts = Settings::list($settings->text('app.http.allowed_hosts'));
        if ($hosts === []) {
            $hosts = ['127.0.0.1:' . $port, 'localhost:' . $port];
        }
        $policy = new RequestPolicy($hosts, Settings::list($settings->text('app.http.trusted_proxies')));
        DatabaseFactory::requireExisting($settings, $basePath);
        $compat = new DatabaseManager(['default' => DatabaseFactory::create($settings, $basePath)], 1, 0);
        $compatScope = new ExecutionScope(new Deadline(2.0));
        try {
            $connection = $compat->connect($compatScope);
            CompatService::assertRuntime($connection);
            \app\iot\service\RecoveryService::ready($connection, $broker ? 'broker' : 'app');
        } finally {
            $compatScope->close();
            $compat->close();
        }
        $database = Settings::database($settings, $basePath);
        \Type\Orm\Db::configure($database);
        $messages = new Factory();
        $router = new Router($messages, $messages);
        $adminIdentities = new IdentityService('admin');
        $customerIdentities = new IdentityService('customer');
        $brokerIdentities = new IdentityService('broker');
        Routes::register($router, [
            BrokerController::class => static fn (): BrokerController => new BrokerController($database, $brokerIdentities, $messages, self::brokerWorker($settings)),
            AccessController::class => static fn (): AccessController => new AccessController($database, $brokerIdentities, $messages),
            QuotaController::class => static fn (): QuotaController => new QuotaController($database, $brokerIdentities, $messages, self::quotaWorker($settings)),
            RuntimeController::class => static fn (): RuntimeController => new RuntimeController($database, $brokerIdentities, $messages),
            DebugController::class => static fn (): DebugController => new DebugController($database, $brokerIdentities, $messages),
            CompatController::class => static fn (): CompatController => new CompatController($database, $brokerIdentities, $messages),
            RecoveryController::class => static fn (): RecoveryController => new RecoveryController($database, $brokerIdentities, $messages),
            AuthController::class => static fn (): AuthController => new AuthController($database, $messages),
            ObservationController::class => static fn (): ObservationController => new ObservationController($database, $messages, $settings->text('app.mqtt.command')),
            AdminController::class => static fn (): AdminController => new AdminController($database, $messages, $basePath),
            ProductController::class => static fn (): ProductController => new ProductController($database, $messages, new ProductService()),
            DeviceController::class => static fn (): DeviceController => new DeviceController($database, $messages, new DeviceService()),
            TransferController::class => static fn (): TransferController => new TransferController($database, $messages),
            AlarmController::class => static fn (): AlarmController => new AlarmController($database, $messages),
            ExportController::class => static fn (): ExportController => new ExportController($database, $messages, self::exportService($settings, $basePath)),
        ], [
            'admin.auth' => static fn (): IotAuthentication => new IotAuthentication($adminIdentities, $messages),
            'customer.auth' => static fn (): IotAuthentication => new IotAuthentication($customerIdentities, $messages),
            'broker.auth' => static fn (): IotAuthentication => new IotAuthentication($brokerIdentities, $messages),
            'broker.probe' => static fn (): Authentication => new Authentication(
                static fn (string $provided): ?Identity => $probeToken !== '' && hash_equals($probeToken, $provided) ? new Identity('broker-probe', ['broker_probe']) : null,
                static fn (Identity $identity, CanonicalRequest $request, string $method): bool => in_array('broker_probe', $identity->roles(), true),
                $messages,
                $messages
            ),
        ]);

        return new Pipeline([
            static fn (): RequestLog => new RequestLog($settings->text('app.name')),
            static fn (): ApiErrors => new ApiErrors($messages, $debug, dirname(__DIR__, 3)),
            static fn (): RequestPolicy => $policy,
        ], new \Type\Core\Http\ActionHandler(static function (\Psr\Http\Message\ServerRequestInterface $request) use ($broker, $router, $messages): \Psr\Http\Message\ResponseInterface {
            if ($broker !== str_starts_with($request->getUri()->getPath(), '/broker/')) {
                return $messages->createResponse(404)->withHeader('Content-Type', 'application/json')->withBody($messages->createStream('{"error":"not_found"}'));
            }
            return $router->handle($request);
        }));
    }

    /**
     * 一次只执行一批审计、指令或支持授权清理；调度者依据has_more重复调用，不在进程中无限循环。
     * @param list<string> $arguments 应用审计须先指定admin/customer；其余为可选批次，默认1000，最大1000。
     */
    private static function pruneRecords(Repository $settings, string $basePath, array $arguments, string $kind): void
    {
        $realm = 'broker';
        if ($kind === 'app:audit-clean') {
            $realm = $arguments[0] ?? '';
            if (!in_array($realm, ['admin', 'customer'], true)) {
                throw new InvalidArgumentException('应用审计清理须指定admin或customer');
            }
            $arguments = array_slice($arguments, 1);
        }
        if (count($arguments) > 1 || (isset($arguments[0]) && !preg_match('/^[1-9][0-9]{0,3}$/D', $arguments[0]))) {
            throw new InvalidArgumentException('清理角色只接受一个1至1000的可选批次');
        }
        $batch = isset($arguments[0]) ? (int) $arguments[0] : 1000;
        if ($batch > 1000) {
            throw new InvalidArgumentException('清理批次最大1000');
        }
        DatabaseFactory::requireExisting($settings, $basePath);
        CoroutineRuntime::run(static function () use ($settings, $basePath, $kind, $batch, $realm): void {
            $database = new DatabaseManager(['default' => DatabaseFactory::create($settings, $basePath)]);
            \Type\Orm\Db::configure($database);
            $scope = new ExecutionScope();
            try {
                $scope->run(static function (ExecutionScope $current) use ($kind, $batch, $realm): void {
                    $connection = \Type\Orm\Db::connection('default', true);
                    $result = match ($kind) {
                        'iot:command-clean' => CommandService::prune($connection, $batch),
                        default => AuditLog::prune($connection, $batch, $realm),
                    };
                    echo json_encode(['data' => $result], JSON_THROW_ON_ERROR) . "\n";
                });
            } finally {
                $scope->close();
                $database->close();
            }
        });
    }

    /** 告警角色有界处理独立待办，命令结束时释放受管资源。 */
    private static function alarm(Repository $settings, string $basePath, array $arguments): void
    {
        \Type\Runtime\CoroutineRuntime::run(static function () use ($settings, $basePath, $arguments): void {
            if (count($arguments) > 1 || (isset($arguments[0]) && (!preg_match('/^[1-9][0-9]{0,2}$/D', $arguments[0]) || (int) $arguments[0] > 100))) {
                throw new InvalidArgumentException('iot:alarm 只接受一个1至100的可选批次');
            }
            DatabaseFactory::requireExisting($settings, $basePath);
            $database = new DatabaseManager(['default' => DatabaseFactory::create($settings, $basePath)]);
            \Type\Orm\Db::configure($database);
            $scope = new ExecutionScope();
            try {
                $scope->run(static function (ExecutionScope $current) use ($arguments): void {
                    echo json_encode(['data' => AlarmService::run(\Type\Orm\Db::connection('default', true), isset($arguments[0]) ? (int) $arguments[0] : 100)], JSON_THROW_ON_ERROR) . "\n";
                });
            } finally {
                $scope->close();
                $database->close();
            }
        });
    }

    /** 通知角色执行有界恢复、relay及worker；清理和HTTP不依赖Redis可用。 */
    private static function notices(Repository $settings, string $basePath, bool $cleanup, array $arguments): void
    {
        \Type\Runtime\CoroutineRuntime::run(static function () use ($settings, $basePath, $cleanup, $arguments): void {
            $batch = filter_var($arguments[0] ?? '100', FILTER_VALIDATE_INT);
            if (count($arguments) > 1 || $batch === false || $batch < 1 || $batch > 100) {
                throw new InvalidArgumentException('通知角色只接受1至100的可选批次');
            }
            DatabaseFactory::requireExisting($settings, $basePath);
            $database = new DatabaseManager(['default' => DatabaseFactory::create($settings, $basePath)], 2, 0);
            \Type\Orm\Db::configure($database);
            $scope = new ExecutionScope();
            $redis = null;
            $signals = new \Type\Runtime\ProcessSignals();
            try {
                $scope->run(static function (ExecutionScope $current) use ($settings, $basePath, $cleanup, $batch, $database, $scope, &$redis, $signals): void {
                    if ($cleanup) {
                        echo json_encode(['data' => \app\iot\service\NoticeService::clean(\Type\Orm\Db::connection('default', true), $batch)], JSON_THROW_ON_ERROR) . "\n";
                        return;
                    }
                    $recoveryConnection = \Type\Orm\Db::connection('default', true);
                    $recovery = \app\iot\service\NoticeService::recover($recoveryConnection, $batch);
                    $recoveryConnection->close();
                    $redis = new \Type\Redis\RedisManager(['notices' => Settings::redis($settings, $basePath, 'notices')]);
                    $queue = new \Type\Queue\Queue($redis->connection($scope, 'notices', \Type\Redis\Purpose::SCRIPT), $settings->text('app.notices.namespace'), 'notices', 60000, 10000);
                    $collected = $queue->collect($batch);
                    $service = new \app\iot\service\NoticeService($queue);
                    $registry = new \Type\Queue\Registry();
                    $registry->register('iot.notice', 1, static fn (\Type\Queue\JobContext $context): \app\iot\service\NoticeService => $service);
                    $worker = new \Type\Queue\Worker($queue, $registry, 'notices-' . getmypid(), new \Type\Queue\RetryPolicy(10, 1000, 60000, 30000));
                    $signals->attach(static function () use ($worker): void {
                        $worker->stop(5.0);
                    });
                    $relay = new \Type\Orm\Outbox\Relay($database, new \Type\Orm\Outbox\Store('iot_notice_outbox'), $service);
                    $processed = 0;
                    $published = 0;
                    while ($processed < $batch && $worker->ready()) {
                        $signals->dispatch();
                        // 先消费已有任务，队列满额时仍能释放容量。
                        if ($worker->runOnce()) {
                            $processed++;
                            continue;
                        }
                        $sent = $relay->runOnce(min(10, $batch - $processed));
                        $published += $sent;
                        if ($sent === 0) {
                            break;
                        }
                    }
                    $worker->stop(5.0);
                    echo json_encode(['data' => $recovery + ['published' => $published, 'processed' => $processed, 'quarantine_collected' => $collected, 'worker' => $worker->statistics()]], JSON_THROW_ON_ERROR) . "\n";
                });
            } finally {
                $signals->close();
                $scope->close();
                $database->close();
                $redis?->close();
            }
        });
    }

    /** 聚合和回收共享已有数据库作用域；角色只运行一批，不启动HTTP或持有空闲连接。 */
    private static function aggregate(Repository $settings, string $basePath, bool $cleanup, array $arguments): void
    {
        $maximum = $cleanup ? 1000 : 100;
        if (count($arguments) > 1 || (isset($arguments[0]) && (!preg_match('/^[1-9][0-9]{0,3}$/D', $arguments[0]) || (int) $arguments[0] > $maximum))) {
            throw new InvalidArgumentException('聚合角色只接受1至' . $maximum . '的可选批次');
        }
        $batch = isset($arguments[0]) ? (int) $arguments[0] : $maximum;
        DatabaseFactory::requireExisting($settings, $basePath);
        CoroutineRuntime::run(static function () use ($settings, $basePath, $cleanup, $batch): void {
            $database = new DatabaseManager(['default' => DatabaseFactory::create($settings, $basePath)]);
            \Type\Orm\Db::configure($database);
            $scope = new ExecutionScope();
            try {
                $scope->run(static function (ExecutionScope $current) use ($cleanup, $batch): void {
                    $connection = \Type\Orm\Db::connection('default', true);
                    echo json_encode(['data' => $cleanup ? AggregateService::prune($connection, $batch) : AggregateService::run($connection, $batch)], JSON_THROW_ON_ERROR) . "\n";
                });
            } finally {
                $scope->close();
                $database->close();
            }
        });
    }

    /** 一次只回收一批到期历史；续扫游标跳过未完成消费者，下一轮从头重查阻塞项。 */
    private static function pruneHistory(Repository $settings, string $basePath, array $arguments): void
    {
        if (count($arguments) > 2 || (isset($arguments[0]) && !preg_match('/^[1-9][0-9]{0,3}$/D', $arguments[0]))) {
            throw new InvalidArgumentException('iot:history-clean 接受1至1000的可选批次和续扫游标');
        }
        $batch = isset($arguments[0]) ? (int) $arguments[0] : 1000;
        if ($batch > 1000) {
            throw new InvalidArgumentException('历史清理批次最大1000');
        }
        DatabaseFactory::requireExisting($settings, $basePath);
        CoroutineRuntime::run(static function () use ($settings, $basePath, $batch, $arguments): void {
            $database = new DatabaseManager(['default' => DatabaseFactory::create($settings, $basePath)]);
            \Type\Orm\Db::configure($database);
            $scope = new ExecutionScope();
            try {
                $scope->run(static function (ExecutionScope $current) use ($batch, $arguments): void {
                    echo json_encode(['data' => HistoryService::prune(\Type\Orm\Db::connection('default', true), $batch, $arguments[1] ?? '')], JSON_THROW_ON_ERROR) . "\n";
                });
            } finally {
                $scope->close();
                $database->close();
            }
        });
    }

    /**
     * 独立迁移角色只查询或核对记录，不创建 HTTP、缓存资源或初始化数据库。
     *
     * @param list<string> $arguments 不含程序名和 migrate 的动作参数。
     * @throws InvalidArgumentException 参数无效或非初始化角色缺少 SQLite 文件。
     */
    public static function migrate(Repository $settings, string $basePath, array $arguments): int
    {
        if (!((count($arguments) === 1 && in_array($arguments[0], ['run', 'status', 'history'], true))
            || (count($arguments) === 4 && $arguments[0] === 'recover'))) {
            throw new InvalidArgumentException('迁移命令参数无效，请使用 migrate help');
        }
        if ($arguments === ['run']) {
            throw new InvalidArgumentException('新应用请使用 app:install 初始化空库；不通过迁移入口升级旧模式');
        }
        DatabaseFactory::requireExisting($settings, $basePath);

        return CoroutineRuntime::run(static function () use ($settings, $basePath, $arguments): int {
            $driver = DatabaseFactory::create($settings, $basePath);

            return (new MigrationConsole(new Migrator($driver), Schema::migrations($driver->name())))->run($arguments);
        });
    }

    /** 独立管理角色只连接自己的账号与采样表；恢复核对走 broker:recovery，不经过 IoT 人员表或隐式迁移。 */
    private static function standalone(Repository $settings, string $basePath, string $command, array $arguments, bool $development): int
    {
        if (!in_array($command, ['broker:install', 'broker:migrate', 'broker:recovery', 'broker:user', 'broker:serve', 'broker:run', 'broker:store-install', 'broker:store', 'broker:nodes', 'broker:node-fence', 'broker:node-fence-result', 'broker:audit-clean'], true)
            || !match ($command) {
                'broker:node-fence' => count($arguments) === 5 || count($arguments) === 6,
                'broker:node-fence-result', 'broker:store' => count($arguments) === 1,
                'broker:audit-clean' => count($arguments) <= 1,
                'broker:user' => count($arguments) === 2,
                'broker:migrate' => (count($arguments) === 1 && in_array($arguments[0], ['status', 'history'], true))
                    || (count($arguments) === 4 && $arguments[0] === 'recover' && in_array($arguments[2], ['retry', 'applied'], true) && $arguments[3] !== ''),
                'broker:recovery' => in_array($arguments[0] ?? '', ['snapshot', 'status'], true) && count($arguments) === 1
                    || ($arguments[0] ?? '') === 'begin' && count($arguments) === 4
                    || in_array($arguments[0] ?? '', ['isolate', 'restore'], true) && count($arguments) === 2
                    || ($arguments[0] ?? '') === 'review' && count($arguments) === 5,
                default => $arguments === [],
            }) {
            throw new InvalidArgumentException('独立 Broker 命令参数无效，请使用 help');
        }
        if ($command === 'broker:install') {
            DatabaseFactory::prepareMigration($settings, $basePath);
            $driver = DatabaseFactory::create($settings, $basePath);
            $result = CoroutineRuntime::run(static function () use ($driver): int {
                return (new MigrationConsole(new Migrator($driver), \app\broker\database\Schema::migrations($driver->name())))->run(['run']);
            });
            if ($result !== 0) {
                return $result;
            }
            return CoroutineRuntime::run(static function () use ($driver): int {
                $database = new DatabaseManager(['default' => $driver], 1, 0);
                $scope = new ExecutionScope();
                try {
                    CompatService::recordUpgrade($database->connect($scope));
                } finally {
                    $scope->close();
                    $database->close();
                }
                return 0;
            });
        }
        DatabaseFactory::requireExisting($settings, $basePath);
        if ($command === 'broker:migrate') {
            return CoroutineRuntime::run(static function () use ($settings, $basePath, $arguments): int {
                $driver = DatabaseFactory::create($settings, $basePath);
                return (new MigrationConsole(new Migrator($driver), \app\broker\database\Schema::migrations($driver->name())))->run($arguments);
            });
        }
        if ($command === 'broker:recovery') {
            self::recovery($settings, $basePath, $arguments, 'broker');
            return 0;
        }
        if ($command !== 'broker:store') {
            self::recovery($settings, $basePath, ['gate'], 'broker');
        }
        if ($command === 'broker:audit-clean') {
            self::pruneRecords($settings, $basePath, $arguments, $command);
            return 0;
        }
        if ($command === 'broker:store') {
            if (DatabaseFactory::name($settings) !== 'pgsql' || $arguments !== ['--store-worker-pipe']) {
                throw new InvalidArgumentException('独立持久worker需要PostgreSQL和 Swoole PROC hook 管理的进程管道');
            }
            PendingCommit::work(new PostgresStore(DatabaseFactory::create($settings, $basePath), $settings->text('app.broker.standby')), 'pipe');
            return 0;
        }
        $worker = self::brokerWorker($settings);
        if (in_array($command, ['broker:node-fence', 'broker:node-fence-result'], true)) {
            self::fenceNode($settings, $basePath, $worker, $arguments, 'broker', $command === 'broker:node-fence-result');
            return 0;
        }
        if ($command === 'broker:nodes') {
            self::brokerNodes($worker);
            return 0;
        }
        if ($command === 'broker:store-install') {
            if ($worker === [] || DatabaseFactory::name($settings) !== 'pgsql') {
                throw new InvalidArgumentException('独立持久存储需要BROKER_COMMAND与PostgreSQL同步后端');
            }
            $pending = new PendingCommit($worker, ['action' => 'install', 'operation_id' => bin2hex(random_bytes(16))]);
            do {
                $result = $pending->poll();
                if ($result === null) {
                    usleep(10000);
                }
            } while ($result === null);
            if ($result->state !== 'committed' || !$result->released) {
                throw new \RuntimeException('broker_store_install_unconfirmed');
            }
            return CoroutineRuntime::run(static function () use ($settings, $basePath): int {
                $database = new DatabaseManager(['default' => DatabaseFactory::create($settings, $basePath)], 1, 0);
                $scope = new ExecutionScope();
                try {
                    CompatService::recordStore($database->connect($scope));
                } finally {
                    $scope->close();
                    $database->close();
                }
                echo "独立Broker持久存储已取得同步提交证明。\n";
                return 0;
            });
        }
        if ($command === 'broker:serve') {
            self::serve($settings, $basePath, $development, true);
            return 0;
        }
        if ($command === 'broker:user') {
            return \Type\Runtime\CoroutineRuntime::run(static function () use ($settings, $basePath, $arguments): int {
                $password = getenv('BROKER_ADMIN_PASSWORD');
                if (!is_string($password)) {
                    throw new InvalidArgumentException('请通过 BROKER_ADMIN_PASSWORD 提供独立管理员初始化密码');
                }
                $manager = new DatabaseManager(['default' => DatabaseFactory::create($settings, $basePath)], 1, 0);
                \Type\Orm\Db::configure($manager);
                $work = new ExecutionScope();
                try {
                    return $work->run(static function (ExecutionScope $current) use ($arguments, $password): int {
                        $user = (new IdentityService('broker'))->provision($arguments[0], $arguments[1], $password, true);
                        echo json_encode(['data' => $user], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
                        return 0;
                    });
                } finally {
                    $work->close();
                    $manager->close();
                }
            });
        }
        $database = new DatabaseManager(['default' => DatabaseFactory::create($settings, $basePath)], 1, 0);
        $scope = new ExecutionScope();
        try {
            $host = $settings->text('app.broker.listen');
            $port = Settings::integer($settings, 'app.broker.port', 1, 65535);
            $plaintext = $settings->boolean('app.broker.plaintext');
            $certificate = $settings->text('app.broker.certificate');
            $privateKey = $settings->text('app.broker.private_key');
            /** 相对路径按应用根解析；空值表示本节点未开 mTLS。节点心跳按当前受信 CA 重写该文件。 */
            $clientCa = $settings->text('app.broker.client_ca');
            $options = new BrokerOptions(
                certificate: $certificate === '' || Settings::absolutePath($certificate) ? $certificate : $basePath . '/' . $certificate,
                privateKey: $privateKey === '' || Settings::absolutePath($privateKey) ? $privateKey : $basePath . '/' . $privateKey,
                allowPlaintext: $plaintext,
                clustered: $worker !== [],
                wsPort: Settings::integer($settings, 'app.broker.ws_port', 0, 65535),
                wssPort: Settings::integer($settings, 'app.broker.wss_port', 0, 65535),
                allowedOrigins: array_map('strtolower', Settings::list($settings->text('app.broker.allowed_origins'))),
                mtlsPort: Settings::integer($settings, 'app.broker.mtls_port', 0, 65535),
                clientCa: $clientCa === '' || Settings::absolutePath($clientCa) ? $clientCa : $basePath . '/' . $clientCa
            );
            $limits = QuotaService::defaults();
            $limits['maximumConnections'] = $options->maximumConnections;
            $limits['maximumDeviceConnections'] = $options->maximumDeviceConnections;
            $limits['maximumServiceConnections'] = $options->maximumServiceConnections;
            $access = new NodeAccess(
                $database,
                $settings->text('app.broker.node_id'),
                $settings->text('app.broker.username'),
                $settings->text('app.broker.password'),
                $settings->text('app.broker.topic_prefix'),
                [
                    'host' => $host, 'port' => $port, 'transport' => $plaintext ? 'tcp' : 'tls',
                    'ws_port' => $options->wsPort, 'wss_port' => $options->wssPort, 'mtls_port' => $options->mtlsPort,
                    'plaintext' => $plaintext ? 1 : 0,
                    'allowed_origins' => implode(',', $options->allowedOrigins),
                ],
                $worker !== [],
                $limits,
                $options->clientCa,
                $options->certificate
            );
            $broker = new Broker(
                $access,
                $options,
                workerCommand: $worker,
                nodeId: $settings->text('app.broker.node_id'),
                observer: $access,
                classify: static fn (ConnectPacket $connect): string => str_starts_with($connect->username ?? '', 'debug:') || str_starts_with($connect->username ?? '', 'service:') ? 'application' : 'device',
                invalidations: $access,
                disconnects: $access,
                quotas: $access
            );
            $access->observe(static fn (): array => $broker->statistics());
            try {
                $broker->serve($host, $port);
            } finally {
                $broker->stop();
            }
        } finally {
            $scope->close();
            $database->close();
        }
        return 0;
    }

    /**
     * 独立角色共用一个受控原生worker命令；未配置时保留QoS0开发入口，不宣称可靠接收就绪。
     * @return list<string> 完整存储角色命令；空列表表示未配置。
     */
    private static function brokerWorker(Repository $settings): array
    {
        $encoded = $settings->text('app.broker.command');
        if ($encoded === '') {
            return [];
        }
        if (DatabaseFactory::name($settings) !== 'pgsql' || trim($settings->text('app.broker.standby')) === '') {
            throw new InvalidArgumentException('BROKER_COMMAND需要PostgreSQL及明确的BROKER_STANDBY_NAMES同步后端');
        }
        $command = json_decode($encoded, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($command) || !array_is_list($command) || $command === [] || count($command) > 16) {
            throw new InvalidArgumentException('BROKER_COMMAND必须为当前应用的显式命令参数数组');
        }
        foreach ($command as $argument) {
            if (!is_string($argument) || $argument === '' || strlen($argument) > 4096 || str_contains($argument, "\0")) {
                throw new InvalidArgumentException('BROKER_COMMAND参数无效');
            }
        }
        return [...$command, 'broker:store'];
    }

    /**
     * 管理额度预览读取持久用量；独立宿主用 BROKER_COMMAND，双端宿主用 MQTT 存储命令。
     *
     * @return list<string>
     */
    private static function quotaWorker(Repository $settings): array
    {
        $worker = self::brokerWorker($settings);
        if ($worker !== []) {
            return $worker;
        }
        $encoded = $settings->text('app.mqtt.command');
        if ($encoded === '' || $encoded === '[]') {
            return [];
        }
        $command = json_decode($encoded, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($command) || !array_is_list($command) || $command === [] || count($command) > 16) {
            return [];
        }
        foreach ($command as $argument) {
            if (!is_string($argument) || $argument === '' || str_contains($argument, "\0")) {
                return [];
            }
        }
        return $command;
    }

    /** @param list<string> $worker 显式同步存储命令；查询只返回有界节点元数据。 */
    private static function brokerNodes(array $worker): void
    {
        if ($worker === []) {
            throw new InvalidArgumentException('独立节点恢复需要明确同步持久配置');
        }
        $pending = new PendingCommit($worker, ['action' => 'node_statistics', 'operation_id' => bin2hex(random_bytes(16))]);
        do {
            $result = $pending->poll();
            if ($result === null) {
                usleep(10000);
            }
        } while ($result === null);
        if ($result->state !== 'committed' || !$result->released) {
            throw new \RuntimeException('broker_nodes_unconfirmed');
        }
        echo json_encode($result->value, JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * 受控命令登记已经完成的基础设施硬隔离；不根据心跳失联自动隔离。
     * 原动作只派发一次；重试与对账只查询原目标的同步事实，不再隔离同名节点的新运行。
     * @param list<string> $worker 当前宿主的显式同步存储命令。
     * @param list<string> $arguments 独立宿主五参、IoT四参，可追加操作ID；对账仅接受原操作ID。
     * @throws InvalidArgumentException 参数或原操作上下文冲突；尚未派发外部动作。
     * @throws \RuntimeException 结果或资源释放未知；标准输出保留可对账的操作ID。
     */
    private static function fenceNode(Repository $settings, string $basePath, array $worker, array $arguments, string $realm, bool $reconcile): void
    {
        $required = $realm === 'broker' ? 5 : 4;
        if ($worker === [] || ($reconcile ? count($arguments) !== 1 : !in_array(count($arguments), [$required, $required + 1], true))) {
            throw new InvalidArgumentException('节点隔离或对账参数无效，请使用help');
        }
        $operationId = $reconcile ? $arguments[0] : ($arguments[$required] ?? bin2hex(random_bytes(16)));
        if (preg_match('/^[a-f0-9]{32}$/D', $operationId) !== 1) {
            throw new InvalidArgumentException('节点隔离操作ID必须为32位小写十六进制');
        }
        $actor = $reconcile ? '' : $arguments[$required - 2];
        $proof = $reconcile ? '' : $arguments[$required - 1];
        $observation = !$reconcile && $realm === 'broker' ? $arguments[2] : '';
        if (!$reconcile && (preg_match('/^[a-zA-Z0-9_.:@-]{1,32}$/D', $actor) !== 1
            || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $arguments[0]) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $arguments[1]) !== 1
            || ($realm === 'broker' && preg_match('/^[a-f0-9]{32}$/D', $observation) !== 1)
            || $proof === '' || strlen($proof) > 256 || preg_match('/[\x00-\x1f\x7f]/', $proof) === 1)) {
            throw new InvalidArgumentException('节点运行、操作人或已完成隔离的依据标识无效');
        }
        $requestId = bin2hex(random_bytes(16));
        $database = new DatabaseManager(['default' => DatabaseFactory::create($settings, $basePath)], 1, 0);
        $scope = new ExecutionScope();
        $connection = null;
        $operation = null;
        $confirmed = false;
        $accepted = false;
        $receipt = [];
        try {
            $connection = $database->connect($scope);
            $saved = AuditLog::brokerOperation($connection, $realm, $operationId);
            if ($saved === null && $reconcile) {
                throw new InvalidArgumentException('broker_operation_not_found');
            }
            if ($saved !== null) {
                $operation = $saved;
                unset($operation['current_stage'], $operation['current_result'], $operation['version']);
                if ($operation['action'] !== 'broker.node_fence' || $operation['authorization']['source'] !== 'operator-command'
                    || (!$reconcile && ($operation['actor_id'] !== $actor || $operation['target']['node_id'] !== $arguments[0]
                        || $operation['target']['node_run_id'] !== $arguments[1] || $operation['target']['observation_run'] !== $observation
                        || !hash_equals($operation['impact']['proof_hash'], hash('sha256', $proof))))) {
                    throw new InvalidArgumentException('broker_operation_conflict');
                }
                $accepted = true;
            } else {
                $probe = new PendingCommit($worker, ['action' => 'node_statistics', 'operation_id' => bin2hex(random_bytes(16))]);
                do {
                    $statistics = $probe->poll();
                    if ($statistics === null) {
                        usleep(10000);
                    }
                } while ($statistics === null);
                if ($statistics->state !== 'committed' || !$statistics->released) {
                    throw new \RuntimeException('broker_nodes_unconfirmed');
                }
                $generation = 0;
                foreach ($statistics->value['nodes'] as $registered) {
                    if ($registered['node_id'] === $arguments[0] && $registered['run_id'] === $arguments[1]) {
                        $generation = (int) $registered['generation'];
                    }
                }
                $row = $realm === 'broker' ? $connection->table('broker_nodes')->where('node_id', '=', $arguments[0])->where('run_id', '=', $observation)->first() : null;
                if ($generation < 1 || ($realm === 'broker' && ($row === null
                    || (json_decode($row['metrics_json'], true, 8, JSON_THROW_ON_ERROR)['nodeGeneration'] ?? 0) !== $generation))) {
                    throw new InvalidArgumentException('节点运行与采样代次不匹配');
                }
                // 同ID首次并发受理共享冻结身份；只有取得执行回执的一方使用它派发动作。
                $requestId = $operationId;
                $operation = [
                    'operation_id' => $operationId, 'mode' => 'async', 'origin_request_id' => $requestId,
                    'actor_id' => $actor, 'tenant_id' => null, 'action' => 'broker.node_fence',
                    'subject_id' => $realm === 'broker' ? $observation : $arguments[1],
                    'authorization' => ['source' => 'operator-command', 'role' => 'operator', 'permissions' => ['broker.node_fence'],
                        'required_action' => 'broker.node_fence', 'decision' => 'allowed', 'support_id' => null, 'support_version' => null, 'support_expires_at' => null],
                    'target' => ['kind' => 'node', 'node_id' => $arguments[0], 'node_run_id' => $arguments[1], 'observation_run' => $observation, 'generation' => $generation],
                    'impact' => ['confirmed' => true, 'effect' => 'register_infrastructure_fence', 'target_count' => 1, 'proof_hash' => hash('sha256', $proof)],
                ];
                // 从首次受理写入开始保守保留未知提交；此前的探测失败确定没有派发隔离动作。
                $accepted = true;
                AuditLog::recordBroker($connection, $realm, $operation, 'accepted', [
                    'request_id' => $requestId, 'stage' => 'accepted', 'result' => 'pending', 'facts' => [],
                ]);
            }
            // 同动作的后续命令只读取原事实；不会用已过期审计重建请求或再次启动外部动作。
            $lookup = $saved !== null;
            if (!$lookup) {
                $executing = AuditLog::recordBroker($connection, $realm, $operation, 'executing', [
                    'request_id' => $requestId, 'stage' => 'executing', 'result' => 'pending', 'facts' => [],
                ]);
                $lookup = $executing['duplicate'];
            }
            if ($lookup) {
                // 每次对账拥有不同后端，不能沿用原执行身份而影响仍在退出的原请求。
                $requestId = bin2hex(random_bytes(16));
            }
            $target = $operation['target'];
            $pending = new PendingCommit($worker, [
                'action' => $lookup ? 'node_fence_result' : 'node_fence', 'operation_id' => $requestId,
                'action_operation_id' => $operationId, 'node_id' => $target['node_id'], 'node_run_id' => $target['node_run_id'],
                'generation' => $target['generation'], 'observation_run' => $target['observation_run'],
                'actor' => $operation['actor_id'], 'proof_ref' => 'sha256:' . $operation['impact']['proof_hash'],
                ...($lookup ? ['origin_request_id' => $operation['origin_request_id']] : []),
            ]);
            do {
                $result = $pending->poll();
                if ($result === null) {
                    usleep(10000);
                }
            } while ($result === null);
            if ($result->state !== 'committed' || !$result->released || ($result->value['fenced'] ?? false) !== true
                || ($lookup && ($result->value['origin_released'] ?? false) !== true)
                || ($result->value['operation_id'] ?? '') !== $operationId || ($result->value['node_id'] ?? '') !== $target['node_id']
                || ($result->value['node_run_id'] ?? '') !== $target['node_run_id'] || ($result->value['generation'] ?? 0) !== $target['generation']
                || ($result->value['observation_run'] ?? '') !== $target['observation_run']) {
                throw new \RuntimeException('broker_node_fence_unconfirmed');
            }
            $receipt = $connection->transaction(static function (\Type\Orm\Connection $transaction) use ($operation, $realm, $requestId): array {
                $current = AuditLog::brokerOperation($transaction, $realm, $operation['operation_id']);
                if ($realm === 'broker' && ($current['current_stage'] ?? '') !== 'completed') {
                    $observed = $transaction->table('broker_nodes')->where('node_id', '=', $operation['target']['node_id'])
                        ->where('run_id', '=', $operation['target']['observation_run'])->lockForUpdate()->first();
                    if ($observed === null || (json_decode($observed['metrics_json'], true, 8, JSON_THROW_ON_ERROR)['nodeGeneration'] ?? 0) !== $operation['target']['generation']) {
                        throw new \RuntimeException('broker_observation_owner_lost');
                    }
                    $transaction->table('broker_nodes')->where('node_id', '=', $operation['target']['node_id'])
                        ->where('run_id', '=', $operation['target']['observation_run'])->update(['stopped' => 2]);
                }
                return AuditLog::recordBroker($transaction, $realm, $operation, 'completed', [
                    'request_id' => $requestId, 'stage' => 'completed', 'result' => 'success',
                    'facts' => ['store_confirmed' => true, 'resources_released' => true, 'observation_isolated' => $realm === 'broker'],
                ]);
            });
            $confirmed = true;
        } catch (Throwable $failure) {
            if (!$accepted && $failure instanceof InvalidArgumentException) {
                throw $failure;
            }
            if ($accepted && $operation !== null && $connection !== null) {
                try {
                    $receipt = AuditLog::recordBroker($connection, $realm, $operation, 'unknown', [
                        'request_id' => $requestId, 'stage' => 'unknown', 'result' => 'unknown', 'facts' => ['reason' => 'result_unconfirmed'],
                    ]);
                } catch (Throwable $auditFailure) {
                    // 原受理事实和操作ID仍可恢复；不能因审计写入失败丢掉未知结果。
                }
            }
        } finally {
            try {
                $scope->close();
            } catch (Throwable $scopeFailure) {
                $confirmed = false;
            }
            try {
                $database->close();
            } catch (Throwable $databaseFailure) {
                $confirmed = false;
            }
        }
        echo json_encode(['operation_id' => $operationId, 'stage' => $confirmed ? 'completed' : ($accepted ? 'unknown' : 'failed'),
            'result' => $confirmed ? 'success' : ($accepted ? 'unknown' : 'failed'), 'fenced' => $confirmed, 'observation_isolated' => $confirmed && $realm === 'broker',
            'event_id' => $receipt['event_id'] ?? null, 'expired' => $receipt['expired'] ?? false], JSON_THROW_ON_ERROR) . "\n";
        if (!$confirmed) {
            throw new \RuntimeException('broker_node_fence_unconfirmed');
        }
    }

    /** 返回服务器可复用的有界输入声明，不打开临时文件或创建目录。 */
    public static function requestLimits(Repository $settings): RequestLimits
    {
        $temporary = $settings->text('app.http.upload_temp');

        return new RequestLimits(1048576, 2048, 16, 8, 1048576, 65536, $temporary === '' ? null : $temporary);
    }

    /**
     * 创建 Swoole HTTP 服务器。传输引擎固定复用 Swoole 原生 Server 与协程，不提供并行实现。
     *
     * 创建对象不监听端口；返回的服务器由调用方负责 serve/stop 生命周期。
     */
    public static function server(Repository $settings, string $basePath, bool $development = false, bool $broker = false): HttpServerInterface
    {
        $handler = self::handler($settings, $basePath, $development, $broker);
        $messages = new Factory();
        return new SwooleServer(
            $handler,
            $messages,
            $messages,
            $messages,
            self::requestLimits($settings),
            new HttpControl(probes: true)
        );
    }

    /** 运行已选择的服务器，并在正常退出或异常后关闭；不会自动迁移或生成生产源码。 */
    public static function serve(Repository $settings, string $basePath, bool $development = false, bool $broker = false): void
    {
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
            $count = Settings::integer($settings, 'database.budget.threads', 1, 256);
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
