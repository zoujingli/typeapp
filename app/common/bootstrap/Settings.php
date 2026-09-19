<?php

declare(strict_types=1);

namespace app\common\bootstrap;

use app\generated\ProjectConfig;
use app\common\database\DatabaseFactory;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Type\Core\Config\Environment;
use Type\Core\Config\Repository;
use Type\Orm\DatabaseManager;
use Type\Redis\RedisConfiguration;
use Type\Runtime\DeploymentBudget;

/**
 * 选择应用数据根并建立本次启动的配置快照。
 *
 * 配置结构来自构建期生成类，运行期只把 .env 当作数据读取，不 require 配置源码。
 */
final class Settings
{
    /**
     * APP_BASE_PATH 由进程环境提供，不能依赖尚未定位的 .env 自己改变所在目录。
     *
     * @throws InvalidArgumentException 指定的应用根不是已存在的绝对目录。
     */
    public static function basePath(string $entry = '', bool $development = false): string
    {
        $configured = getenv('APP_BASE_PATH');
        $runtime = getenv('TYPE_APP_RUNTIME_ROOT');
        $base = $configured !== false ? $configured : $runtime;
        if ($base === false) {
            $program = realpath($entry);
            if ($program === false && $entry !== '' && !str_contains($entry, '/') && !str_contains($entry, '\\')) {
                foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
                    if ($directory !== '' && is_file($directory . '/' . $entry) && is_executable($directory . '/' . $entry)) {
                        $program = realpath($directory . '/' . $entry);
                        break;
                    }
                }
            }
            if ($program === false || !is_file($program)) {
                throw new InvalidArgumentException('无法定位软件目录，请显式设置 APP_BASE_PATH');
            }
            $base = $development ? dirname($program, 2) : dirname($program);
        }
        if (!is_string($base) || !self::absolutePath($base)) {
            throw new InvalidArgumentException('APP_BASE_PATH 必须为已存在的绝对目录');
        }
        $resolved = realpath($base);
        if ($resolved === false || !is_dir($resolved)) {
            throw new InvalidArgumentException('APP_BASE_PATH 必须为已存在的绝对目录');
        }

        return $resolved;
    }

    /**
     * 进程环境覆盖 .env；缺省值由编译过的 config 声明提供，不写回全局环境。
     *
     * @throws InvalidArgumentException 环境数据或配置类型不符合声明。
     */
    public static function load(string $basePath): Repository
    {
        return ProjectConfig::load(Environment::load($basePath . '/.env'));
    }

    /** 共用 HTTP 启动和离线检查的连接预算；构造时不借用连接、不初始化数据库。 */
    public static function database(Repository $settings, string $basePath): DatabaseManager
    {
        $budget = new DeploymentBudget(
            self::integer($settings, 'database.budget.server', 2, 1000000),
            self::integer($settings, 'database.budget.replicas', 1, 10000),
            self::integer($settings, 'database.budget.surge', 0, 10000),
            self::integer($settings, 'database.budget.processes', 1, 10000),
            self::integer($settings, 'database.budget.reserve', 0, 100000),
            self::integer($settings, 'database.budget.threads', 1, 256)
        );
        return new DatabaseManager(
            ['default' => DatabaseFactory::create($settings, $basePath)],
            4,
            0,
            $budget,
            self::integer($settings, 'database.pool.waiters', 0, 65536),
            self::integer($settings, 'database.pool.wait_ms', 0, 60000) / 1000.0
        );
    }

    /** 三个既有 Redis 用途共用原生连接配置，CA 相对软件目录；不连接网络。 */
    public static function redis(Repository $settings, string $basePath, string $purpose): RedisConfiguration
    {
        if (!in_array($purpose, ['cache', 'exports', 'notices'], true)) {
            throw new InvalidArgumentException('Redis 配置用途无效');
        }
        $prefix = $purpose === 'cache' ? 'cache.redis.' : 'app.' . $purpose . '.redis_';
        $password = $settings->text($prefix . 'password');
        $username = $settings->text($prefix . 'username');
        $ca = $settings->text($prefix . ($purpose === 'cache' ? 'tls_ca' : 'ca'));
        return new RedisConfiguration(
            $settings->text($prefix . 'host'),
            self::integer($settings, $prefix . 'port', 1, 65535),
            $purpose === 'cache' ? self::integer($settings, 'cache.redis.database', 0, 1024) : 0,
            $password === '' ? null : $password,
            $username === '' ? null : $username,
            1.0,
            1.0,
            $settings->boolean($prefix . 'tls'),
            $ca === '' ? null : (self::absolutePath($ca) ? $ca : $basePath . '/' . $ca)
        );
    }

    /**
     * Web 管理端可见的配置目录。敏感字段仍纳入结构目录，但只返回脱敏状态，不能通过 HTTP 读取其值。
     *
     * @return array<string, array{path:string, label:string, group:string, type:string, editable:bool, secret:bool, options?:list<string>}>
     */
    public static function configurationDefinitions(): array
    {
        $definitions = [
            'APP_NAME' => ['path' => 'app.name', 'label' => '应用名称', 'group' => '应用', 'type' => 'string', 'editable' => true, 'secret' => false],
            'APP_ENV' => ['path' => 'app.environment', 'label' => '运行环境', 'group' => '应用', 'type' => 'enum', 'editable' => true, 'secret' => false, 'options' => ['development', 'production']],
            'APP_DEBUG' => ['path' => 'app.debug', 'label' => '调试模式', 'group' => '应用', 'type' => 'boolean', 'editable' => true, 'secret' => false],
            'APP_LISTEN' => ['path' => 'app.http.listen', 'label' => '监听地址', 'group' => 'HTTP', 'type' => 'string', 'editable' => true, 'secret' => false],
            'APP_PORT' => ['path' => 'app.http.port', 'label' => '监听端口', 'group' => 'HTTP', 'type' => 'integer', 'editable' => true, 'secret' => false],
            'APP_ALLOWED_HOSTS' => ['path' => 'app.http.allowed_hosts', 'label' => '允许的 Host', 'group' => 'HTTP', 'type' => 'string', 'editable' => true, 'secret' => false],
            'APP_TRUSTED_PROXIES' => ['path' => 'app.http.trusted_proxies', 'label' => '可信代理', 'group' => 'HTTP', 'type' => 'string', 'editable' => true, 'secret' => false],
            'APP_UPLOAD_TEMP' => ['path' => 'app.http.upload_temp', 'label' => '上传临时目录', 'group' => 'HTTP', 'type' => 'string', 'editable' => true, 'secret' => false],
            'DB_DRIVER' => ['path' => 'database.driver', 'label' => '数据库驱动', 'group' => '数据库', 'type' => 'enum', 'editable' => true, 'secret' => false, 'options' => ['sqlite', 'mysql', 'pgsql']],
            'DB_HOST' => ['path' => 'database.host', 'label' => '数据库地址', 'group' => '数据库', 'type' => 'string', 'editable' => true, 'secret' => false],
            'DB_PORT' => ['path' => 'database.port', 'label' => '数据库端口', 'group' => '数据库', 'type' => 'integer', 'editable' => true, 'secret' => false],
            'DB_DATABASE' => ['path' => 'database.database', 'label' => '数据库名称', 'group' => '数据库', 'type' => 'string', 'editable' => true, 'secret' => false],
            'DB_USERNAME' => ['path' => 'database.username', 'label' => '数据库账号', 'group' => '数据库', 'type' => 'string', 'editable' => true, 'secret' => false],
            'DB_SQLITE_FILE' => ['path' => 'database.sqlite_file', 'label' => 'SQLite 文件', 'group' => '数据库', 'type' => 'string', 'editable' => true, 'secret' => false],
            'DB_TLS_CA' => ['path' => 'database.tls_ca', 'label' => '数据库 CA 文件', 'group' => '数据库', 'type' => 'string', 'editable' => true, 'secret' => false],
            'DB_SERVER_BUDGET' => ['path' => 'database.budget.server', 'label' => '连接预算', 'group' => '数据库', 'type' => 'integer', 'editable' => true, 'secret' => false],
            'DB_ADMIN_RESERVE' => ['path' => 'database.budget.reserve', 'label' => '管理预留连接', 'group' => '数据库', 'type' => 'integer', 'editable' => true, 'secret' => false],
            'APP_MAX_REPLICAS' => ['path' => 'database.budget.replicas', 'label' => '最大副本数', 'group' => '数据库', 'type' => 'integer', 'editable' => true, 'secret' => false],
            'APP_ROLLING_SURGE' => ['path' => 'database.budget.surge', 'label' => '滚动扩容数', 'group' => '数据库', 'type' => 'integer', 'editable' => true, 'secret' => false],
            'APP_DATABASE_PROCESSES' => ['path' => 'database.budget.processes', 'label' => '数据库进程数', 'group' => '数据库', 'type' => 'integer', 'editable' => true, 'secret' => false],
            'APP_DATABASE_THREADS' => ['path' => 'database.budget.threads', 'label' => '数据库线程数', 'group' => '数据库', 'type' => 'integer', 'editable' => true, 'secret' => false],
            'DB_POOL_WAITERS' => ['path' => 'database.pool.waiters', 'label' => '连接等待数', 'group' => '数据库', 'type' => 'integer', 'editable' => true, 'secret' => false],
            'DB_POOL_WAIT_MS' => ['path' => 'database.pool.wait_ms', 'label' => '连接等待超时（毫秒）', 'group' => '数据库', 'type' => 'integer', 'editable' => true, 'secret' => false],
            'APP_CACHE_ENABLED' => ['path' => 'cache.enabled', 'label' => '应用缓存', 'group' => '缓存', 'type' => 'boolean', 'editable' => true, 'secret' => false],
            'REDIS_HOST' => ['path' => 'cache.redis.host', 'label' => 'Redis 地址', 'group' => '缓存', 'type' => 'string', 'editable' => true, 'secret' => false],
            'REDIS_PORT' => ['path' => 'cache.redis.port', 'label' => 'Redis 端口', 'group' => '缓存', 'type' => 'integer', 'editable' => true, 'secret' => false],
            'REDIS_DATABASE' => ['path' => 'cache.redis.database', 'label' => 'Redis 数据库', 'group' => '缓存', 'type' => 'integer', 'editable' => true, 'secret' => false],
            'REDIS_USERNAME' => ['path' => 'cache.redis.username', 'label' => 'Redis 账号', 'group' => '缓存', 'type' => 'string', 'editable' => true, 'secret' => false],
            'REDIS_TLS' => ['path' => 'cache.redis.tls', 'label' => 'Redis TLS', 'group' => '缓存', 'type' => 'boolean', 'editable' => true, 'secret' => false],
            'REDIS_TLS_CA' => ['path' => 'cache.redis.tls_ca', 'label' => 'Redis CA 文件', 'group' => '缓存', 'type' => 'string', 'editable' => true, 'secret' => false],
            'BROKER_LISTEN' => ['path' => 'app.broker.listen', 'label' => 'Broker 监听地址', 'group' => 'Broker', 'type' => 'string', 'editable' => true, 'secret' => false],
            'BROKER_PORT' => ['path' => 'app.broker.port', 'label' => 'Broker 端口', 'group' => 'Broker', 'type' => 'integer', 'editable' => true, 'secret' => false],
            'BROKER_NODE_ID' => ['path' => 'app.broker.node_id', 'label' => 'Broker 节点 ID', 'group' => 'Broker', 'type' => 'string', 'editable' => true, 'secret' => false],
            'BROKER_IO_DRIVER' => ['path' => 'app.broker.io_driver', 'label' => 'Broker I/O 驱动', 'group' => 'Broker', 'type' => 'string', 'editable' => true, 'secret' => false],
            'BROKER_PLAINTEXT' => ['path' => 'app.broker.plaintext', 'label' => 'Broker 明文模式', 'group' => 'Broker', 'type' => 'boolean', 'editable' => true, 'secret' => false],
            'BROKER_WS_PORT' => ['path' => 'app.broker.ws_port', 'label' => 'Broker 明文 WebSocket 端口', 'group' => 'Broker', 'type' => 'integer', 'editable' => true, 'secret' => false],
            'BROKER_WSS_PORT' => ['path' => 'app.broker.wss_port', 'label' => 'Broker WSS 端口', 'group' => 'Broker', 'type' => 'integer', 'editable' => true, 'secret' => false],
            'BROKER_ALLOWED_ORIGINS' => ['path' => 'app.broker.allowed_origins', 'label' => 'Broker WebSocket Origin 白名单', 'group' => 'Broker', 'type' => 'string', 'editable' => true, 'secret' => false],
            'BROKER_MTLS_PORT' => ['path' => 'app.broker.mtls_port', 'label' => 'Broker mTLS 端口', 'group' => 'Broker', 'type' => 'integer', 'editable' => true, 'secret' => false],
            'BROKER_CERTIFICATE' => ['path' => 'app.broker.certificate', 'label' => 'Broker 服务端证书路径', 'group' => 'Broker', 'type' => 'string', 'editable' => true, 'secret' => false],
            'BROKER_CLIENT_CA' => ['path' => 'app.broker.client_ca', 'label' => 'Broker 握手 CA 路径', 'group' => 'Broker', 'type' => 'string', 'editable' => true, 'secret' => false],
            'IOT_MQTT_IO_DRIVER' => ['path' => 'app.mqtt.io_driver', 'label' => 'MQTT I/O 驱动', 'group' => 'MQTT', 'type' => 'string', 'editable' => true, 'secret' => false],
            'IOT_MQTT_LISTEN' => ['path' => 'app.mqtt.listen', 'label' => 'MQTT 监听地址', 'group' => 'MQTT', 'type' => 'string', 'editable' => true, 'secret' => false],
            'IOT_MQTT_PORT' => ['path' => 'app.mqtt.port', 'label' => 'MQTT 端口', 'group' => 'MQTT', 'type' => 'integer', 'editable' => true, 'secret' => false],
            'IOT_MQTT_WS_PORT' => ['path' => 'app.mqtt.ws_port', 'label' => 'MQTT 明文 WebSocket 端口', 'group' => 'MQTT', 'type' => 'integer', 'editable' => true, 'secret' => false],
            'IOT_MQTT_WSS_PORT' => ['path' => 'app.mqtt.wss_port', 'label' => 'MQTT WSS 端口', 'group' => 'MQTT', 'type' => 'integer', 'editable' => true, 'secret' => false],
            'IOT_MQTT_ALLOWED_ORIGINS' => ['path' => 'app.mqtt.allowed_origins', 'label' => 'MQTT WebSocket Origin 白名单', 'group' => 'MQTT', 'type' => 'string', 'editable' => true, 'secret' => false],
            'IOT_MQTT_NODE_ID' => ['path' => 'app.mqtt.node_id', 'label' => 'MQTT 节点 ID', 'group' => 'MQTT', 'type' => 'string', 'editable' => true, 'secret' => false],
            'IOT_MQTT_CLUSTERED' => ['path' => 'app.mqtt.clustered', 'label' => 'MQTT 集群模式', 'group' => 'MQTT', 'type' => 'boolean', 'editable' => true, 'secret' => false],
        ];

        return $definitions;
    }

    /**
     * 返回管理端配置投影。只返回非敏感运行值、来源和类型，不暴露 .env 内容或绝对路径。
     *
     * @return array{version:string, restart_required:bool, fields:list<array<string, mixed>>, undeclared:list<string>}
     */
    public static function configurationView(string $basePath): array
    {
        $contents = self::configurationRead($basePath . '/.env', false, true);
        $environment = Environment::load($basePath . '/.env');
        $settings = ProjectConfig::load($environment);
        $description = $environment->describe();
        $definitions = self::configurationDefinitions();
        $fields = [];
        foreach ($description['fields'] as $key => $meta) {
            $definition = $definitions[$key] ?? [
                'path' => '', 'label' => $key, 'group' => '其他', 'type' => 'string', 'editable' => false,
                'secret' => preg_match('/(?:PASSWORD|TOKEN|PRIVATE_KEY|PASSPHRASE|SECRET|CREDENTIAL|COMMAND)/', $key) === 1,
            ];
            $secret = (bool) $definition['secret'];
            $field = [
                'key' => $key, 'label' => $definition['label'], 'group' => $definition['group'], 'type' => $definition['type'],
                'editable' => (bool) $definition['editable'] && !$secret, 'secret' => $secret,
                'source' => (string) ($meta['source'] ?? 'unknown'), 'read_only' => (bool) ($meta['read_only'] ?? false),
                'reason' => $meta['reason'] ?? null, 'file_present' => (bool) ($meta['file_present'] ?? false), 'value' => null,
            ];
            if (isset($definition['options'])) {
                $field['options'] = $definition['options'];
            }
            if (!$secret && $definition['path'] !== '') {
                $field['value'] = $settings->get($definition['path']);
            }
            $fields[] = $field;
        }
        usort($fields, static fn (array $left, array $right): int => [$left['group'], $left['label']] <=> [$right['group'], $right['label']]);
        return ['version' => hash('sha256', $contents), 'restart_required' => false, 'fields' => $fields, 'undeclared' => $description['undeclared']];
    }

    /**
     * 校验并原子更新允许管理端维护的非敏感 .env 字段；进程环境覆盖字段必须由运维重启后修改。
     *
     * @param array<string, mixed> $changes
     * @return array{version:string, restart_required:bool, changed:list<string>, status:string}
     */
    public static function configurationUpdate(string $basePath, string $version, array $changes): array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $version) !== 1 || $changes === []) {
            throw new InvalidArgumentException('configuration_input_invalid');
        }
        $definitions = self::configurationDefinitions();
        foreach ($changes as $key => $value) {
            if (!is_string($key) || !isset($definitions[$key]) || !(bool) $definitions[$key]['editable'] || (bool) $definitions[$key]['secret']
                || getenv($key) !== false) {
                throw new InvalidArgumentException('configuration_field_read_only');
            }
            $definition = $definitions[$key];
            $valid = match ($definition['type']) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'boolean' => is_bool($value),
                'enum' => is_string($value) && in_array($value, $definition['options'] ?? [], true),
                default => false,
            };
            if (!$valid) {
                throw new InvalidArgumentException('configuration_value_invalid');
            }
        }
        $lock = self::configurationLock($basePath);
        try {
            $path = $basePath . '/.env';
            $contents = self::configurationRead($path, false, true);
            if (!hash_equals($version, hash('sha256', $contents))) {
                throw new RuntimeException('config_version_conflict');
            }
            $updated = self::replaceEnvironmentValues($contents, $changes);
            $fileEnvironment = Environment::parse($updated, false);
            self::validateRuntimeConfiguration(ProjectConfig::load($fileEnvironment), $basePath);
            self::configurationReplace($path, $updated, false);
            return ['version' => hash('sha256', $updated), 'restart_required' => true, 'changed' => array_keys($changes), 'status' => 'saved_restart_required'];
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** 将管理端的标量值编码为 Environment 解析器可接受的单行值。 */
    private static function encodeEnvironmentValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (!is_string($value) || strlen($value) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('configuration_value_invalid');
        }
        if ($value !== '' && preg_match('/^[A-Za-z0-9_\.\/:@%+,\-]+$/D', $value) === 1) {
            return $value;
        }
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    /** 只替换明确键的整行内容，保留其他注释和部署方未登记的键。 */
    private static function replaceEnvironmentValues(string $contents, array $changes): string
    {
        $parts = preg_split('/(\r\n|\r|\n)/', $contents, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            throw new RuntimeException('config_read_failed');
        }
        $seen = [];
        for ($index = 0; $index < count($parts); $index += 2) {
            $line = (string) ($parts[$index] ?? '');
            if (preg_match('/^([ \t]*)(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_]*)[ \t]*=/', $line, $matches) !== 1) {
                continue;
            }
            $key = (string) $matches[2];
            if (!array_key_exists($key, $changes)) {
                continue;
            }
            $parts[$index] = (string) $matches[1] . $key . '=' . self::encodeEnvironmentValue($changes[$key]);
            $seen[$key] = true;
        }
        $updated = implode('', $parts);
        foreach ($changes as $key => $value) {
            if (!isset($seen[$key])) {
                $updated .= (str_ends_with($updated, "\n") || str_ends_with($updated, "\r") || $updated === '' ? '' : "\n")
                    . $key . '=' . self::encodeEnvironmentValue($value) . "\n";
            }
        }
        return $updated;
    }

    /** 校验配置结构和驱动范围，但不连接数据库或 Redis。 */
    private static function validateRuntimeConfiguration(Repository $settings, string $basePath): void
    {
        self::environment($settings, false);
        foreach ([
            ['database.budget.server', 2, 1000000], ['database.budget.replicas', 1, 10000], ['database.budget.surge', 0, 10000],
            ['database.budget.processes', 1, 10000], ['database.budget.reserve', 0, 100000], ['database.budget.threads', 1, 256],
            ['database.pool.waiters', 0, 65536], ['database.pool.wait_ms', 0, 60000],
        ] as [$key, $minimum, $maximum]) {
            self::integer($settings, $key, $minimum, $maximum);
        }
        DatabaseFactory::validate($settings, $basePath);
        self::integer($settings, 'app.http.port', 1, 65535);
        foreach (['cache', 'exports', 'notices'] as $purpose) {
            self::redis($settings, $basePath, $purpose);
        }
    }

    /**
     * 离线维护同一 .env；只有显式 remember 在真实依赖检查成功后更新私有恢复副本。
     * 不加载业务数据库或恢复门，不向输出提供配置值；恢复不会改变任何运行进程。
     * @param list<string> $arguments 仅 check 接受 --connect、--remember；remember 隐含 connect。
     * @return array<string, mixed> exit 为 0 有效、2 无效、3 连接失败、4 文件恢复成功待重启、5 文件操作失败。
     */
    public static function configurationCommand(string $basePath, string $command, array $arguments): array
    {
        $lock = null;
        $result = ['file' => $basePath . '/.env', 'loaded_version' => null, 'restart_required' => false];
        try {
            if (!in_array($command, ['config:check', 'config:restore'], true)
                || ($command === 'config:restore' && $arguments !== [])
                || count(array_unique($arguments)) !== count($arguments)
                || array_diff($arguments, ['--connect', '--remember']) !== []) {
                throw new InvalidArgumentException('config:check [--connect] [--remember]；config:restore 不接受参数');
            }
            $remember = in_array('--remember', $arguments, true);
            $restore = $command === 'config:restore';
            if ($remember || $restore) {
                $lock = self::configurationLock($basePath);
            }
            $contents = $restore ? self::lastValid($basePath) : self::configurationRead($basePath . '/.env', false, true);
            $result['version'] = hash('sha256', $contents);
            if ($restore) {
                // 恢复只校验文件结构；错误的当前进程覆盖或失联依赖不能阻止修复磁盘配置。
                $fileEnvironment = Environment::parse($contents, false);
                ProjectConfig::load($fileEnvironment);
                self::configurationReplace($basePath . '/.env', $contents, false);
                $result['restart_required'] = true;
                $result['effective_configuration'] = 'valid';
                try {
                    $restoredEnvironment = Environment::parse($contents);
                    ProjectConfig::load($restoredEnvironment);
                } catch (Throwable) {
                    $result['effective_configuration'] = 'invalid_process_override';
                }
                $description = $fileEnvironment->describe();
                foreach ($description['fields'] as $key => $field) {
                    if (getenv((string) $key) !== false) {
                        $field['source'] = 'process';
                        $field['read_only'] = true;
                        $field['reason'] = 'process_environment_requires_operator_restart';
                    }
                    $description['fields'][$key] = $field;
                }
                $result += $description;
                return $result + ['exit' => 4, 'status' => 'restored_restart_required', 'dependencies' => 'not_checked'];
            }
            $environment = Environment::parse($contents);
            $settings = ProjectConfig::load($environment);
            $result += $environment->describe();
            // 单独检查被进程覆盖的文件值，防止恢复副本藏有错误类型；不执行文件内容。
            ProjectConfig::load(Environment::parse($contents, false));
            self::environment($settings, false);
            foreach ([
                ['database.budget.server', 2, 1000000],
                ['database.budget.replicas', 1, 10000],
                ['database.budget.surge', 0, 10000],
                ['database.budget.processes', 1, 10000],
                ['database.budget.reserve', 0, 100000],
                ['database.budget.threads', 1, 256],
                ['database.pool.waiters', 0, 65536],
                ['database.pool.wait_ms', 0, 60000],
            ] as [$key, $minimum, $maximum]) {
                self::integer($settings, $key, $minimum, $maximum);
            }
            DatabaseFactory::validate($settings, $basePath);
            self::integer($settings, 'app.http.port', 1, 65535);
            foreach (['cache', 'exports', 'notices'] as $purpose) {
                self::redis($settings, $basePath, $purpose);
            }
            $result['dependencies'] = 'not_checked';
            if ($remember || in_array('--connect', $arguments, true)) {
                $dependencies = self::checkDependencies($settings, $basePath);
                $result['dependencies'] = $dependencies;
                if (in_array('failed', $dependencies, true)) {
                    return $result + ['exit' => 3, 'status' => 'dependency_failed'];
                }
            }
            if ($remember) {
                if (self::configurationRead($basePath . '/.env', false, true) !== $contents) {
                    throw new RuntimeException('config_version_conflict');
                }
                $record = ['format' => 1, 'version' => $result['version'], 'contents' => base64_encode($contents),
                    'checked_at' => time(), 'process_keys' => array_keys(array_filter($result['fields'], static fn (array $field): bool => $field['read_only']))];
                self::configurationReplace($basePath . '/runtime/config/last-valid.json', json_encode($record, JSON_THROW_ON_ERROR) . "\n", true);
            }
            return $result + ['exit' => 0, 'status' => $remember ? 'valid_remembered' : 'valid'];
        } catch (Throwable $error) {
            $message = $error->getMessage();
            $fileFailure = preg_match('/^config_[a-z_]+$/D', $message) === 1;
            return $result + ['exit' => $fileFailure ? 5 : 2, 'status' => $fileFailure ? $message : 'config_invalid',
                'reason' => $error instanceof InvalidArgumentException || str_starts_with($message, 'dotenv ') ? $message : null];
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** @return array<string, string> 每个真实依赖独立返回结果；不输出驱动异常、DSN或凭据。 */
    private static function checkDependencies(Repository $settings, string $basePath): array
    {
        $results = [];
        try {
            DatabaseFactory::requireExisting($settings, $basePath);
            $connection = DatabaseFactory::create($settings, $basePath)->connect();
            $statement = $connection->query('SELECT 1');
            if ($statement === false || (int) $statement->fetchColumn() !== 1) {
                throw new RuntimeException('dependency_failed');
            }
            $statement->closeCursor();
            $results['database'] = 'ok';
        } catch (Throwable) {
            $results['database'] = 'failed';
        }
        foreach (['cache', 'exports', 'notices'] as $purpose) {
            if ($purpose === 'cache' && !$settings->boolean('cache.enabled')) {
                $results['redis_cache'] = 'disabled';
                continue;
            }
            try {
                $redis = self::redis($settings, $basePath, $purpose)->connect();
                try {
                    if ($redis->ping() === false) {
                        throw new RuntimeException('dependency_failed');
                    }
                } finally {
                    $redis->close();
                }
                $results['redis_' . $purpose] = 'ok';
            } catch (Throwable) {
                $results['redis_' . $purpose] = 'failed';
            }
        }
        return $results;
    }

    /** @return resource 原生文件锁；启动期同步等待至多五秒，无业务协程或数据库依赖。 */
    private static function configurationLock(string $basePath): mixed
    {
        foreach (['runtime', 'runtime/config'] as $relative) {
            $directory = $basePath . '/' . $relative;
            clearstatcache(true, $directory);
            if (!file_exists($directory) && !is_link($directory) && !@mkdir($directory, 0700)) {
                clearstatcache(true, $directory);
                if (!is_dir($directory)) {
                    throw new RuntimeException('config_directory_unavailable');
                }
            }
            $stat = @lstat($directory);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0040000
                || ($relative === 'runtime/config' && PHP_OS_FAMILY !== 'Windows' && ($stat['mode'] & 0077) !== 0)) {
                throw new RuntimeException('config_directory_unsafe');
            }
        }
        $path = $basePath . '/runtime/config/.lock';
        self::configurationFile($path, false, true);
        $lock = @fopen($path, 'c+b');
        if ($lock === false) {
            throw new RuntimeException('config_lock_unavailable');
        }
        try {
            if (!@chmod($path, 0600)) {
                throw new RuntimeException('config_permissions_failed');
            }
            self::configurationIdentity($path, $lock, true);
            $deadline = hrtime(true) + 5000000000;
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (hrtime(true) >= $deadline) {
                    throw new RuntimeException('config_lock_timeout');
                }
                usleep(10000);
            }
            self::configurationIdentity($path, $lock, true);
            return $lock;
        } catch (Throwable $error) {
            fclose($lock);
            throw $error;
        }
    }

    /** @return array<string, mixed> 拒绝软链、硬链、特殊文件；可选缺失仅用于首次写入。 */
    private static function configurationFile(string $path, bool $private, bool $optional): array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false && $optional && !file_exists($path) && !is_link($path)) {
            return [];
        }
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1
            || ($private && PHP_OS_FAMILY !== 'Windows' && ($stat['mode'] & 0077) !== 0)) {
            throw new RuntimeException('config_file_unsafe');
        }
        return $stat;
    }

    /** 打开后再次核对身份，避免在检查与打开之间接受被替换的路径。 */
    private static function configurationIdentity(string $path, mixed $stream, bool $private): void
    {
        $stat = self::configurationFile($path, $private, false);
        $opened = fstat($stream);
        if ($opened === false || $stat['dev'] !== $opened['dev'] || $stat['ino'] !== $opened['ino'] || $stat['mode'] !== $opened['mode']) {
            throw new RuntimeException('config_file_changed');
        }
    }

    private static function configurationRead(string $path, bool $private, bool $optional = false): string
    {
        $stat = self::configurationFile($path, $private, $optional);
        if ($stat === []) {
            return '';
        }
        $limit = $private ? 1500000 : 1048576;
        if ($stat['size'] > $limit) {
            throw new RuntimeException('config_file_too_large');
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('config_read_failed');
        }
        try {
            self::configurationIdentity($path, $stream, $private);
            $contents = stream_get_contents($stream, $limit + 1);
            if ($contents === false || strlen($contents) > $limit) {
                throw new RuntimeException('config_read_failed');
            }
            return $contents;
        } finally {
            fclose($stream);
        }
    }

    private static function lastValid(string $basePath): string
    {
        $record = json_decode(self::configurationRead($basePath . '/runtime/config/last-valid.json', true), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($record) || ($record['format'] ?? null) !== 1 || !is_string($record['version'] ?? null) || !is_string($record['contents'] ?? null)) {
            throw new RuntimeException('config_backup_invalid');
        }
        $contents = base64_decode($record['contents'], true);
        if ($contents === false || strlen($contents) > 1048576 || !hash_equals($record['version'], hash('sha256', $contents))) {
            throw new RuntimeException('config_backup_invalid');
        }
        return $contents;
    }

    /** 持锁写入同目录固定暂存槽；进程中断后可重写，绝不先截断当前文件或有效副本。 */
    private static function configurationReplace(string $path, #[\SensitiveParameter] string $contents, bool $private): void
    {
        self::configurationFile($path, $private, true);
        $temporary = $path . '.next';
        self::configurationFile($temporary, true, true);
        $stream = @fopen($temporary, 'c+b');
        if ($stream === false) {
            throw new RuntimeException('config_write_failed');
        }
        try {
            if (!@chmod($temporary, 0600)) {
                throw new RuntimeException('config_permissions_failed');
            }
            self::configurationIdentity($temporary, $stream, true);
            if (!ftruncate($stream, 0) || fwrite($stream, $contents) !== strlen($contents) || !fflush($stream) || !fsync($stream)) {
                throw new RuntimeException('config_write_failed');
            }
            fclose($stream);
            $stream = null;
            self::configurationFile($path, $private, true);
            if (!@rename($temporary, $path)) {
                throw new RuntimeException('config_replace_failed');
            }
            if (PHP_OS_FAMILY !== 'Windows') {
                $directory = @fopen(dirname($path), 'r');
                if ($directory === false) {
                    throw new RuntimeException('config_durability_unknown');
                }
                try {
                    if (!fsync($directory)) {
                        throw new RuntimeException('config_durability_unknown');
                    }
                } finally {
                    fclose($directory);
                }
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * 区分配置声明的环境与显式开发入口；开发启动器的标记不写回环境或秘密配置。
     *
     * @throws InvalidArgumentException APP_ENV 不是 development 或 production。
     */
    public static function environment(Repository $configuration, bool $developmentEntry): string
    {
        $environment = $configuration->text('app.environment');
        if (!in_array($environment, ['development', 'production'], true)) {
            throw new InvalidArgumentException('APP_ENV 只接受 development 或 production');
        }

        return $developmentEntry ? 'development' : $environment;
    }

    /** 只有开发启动器的显式标记可以开启调试，HTTP 头与生产环境误配不能打开它。 */
    public static function debug(Repository $configuration, bool $developmentEntry): bool
    {
        return $developmentEntry && $configuration->boolean('app.debug');
    }

    /**
     * 识别 Unix、Windows 盘符与 UNC 绝对路径，不把此语法判断当作路径存在或平台支持证明。
     *
     * Windows 的 C:relative 与单个反斜线不是完整绝对路径。
     */
    public static function absolutePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }

        return str_starts_with($path, '/')
            || preg_match('~^[A-Za-z]:[\\\\/]~D', $path) === 1
            || preg_match('~^\\\\\\\\[^\\\\/]+[\\\\/][^\\\\/]+(?:[\\\\/]|$)~D', $path) === 1;
    }

    /** @throws InvalidArgumentException 已取得的配置整数不在该用途的允许范围内。 */
    public static function integer(Repository $configuration, string $key, int $minimum, int $maximum): int
    {
        $value = $configuration->integer($key);
        if ($value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException('启动配置整数越界：' . $key);
        }

        return $value;
    }

    /**
     * 解析最多 64 项的配置列表；空字符串表示使用调用方的明确默认值。
     *
     * @return list<string>
     * @throws InvalidArgumentException 列表含空项或超出容量。
     */
    public static function list(string $value): array
    {
        if ($value === '') {
            return [];
        }
        $values = array_map('trim', explode(',', $value));
        if (count($values) > 64 || in_array('', $values, true)) {
            throw new InvalidArgumentException('启动配置列表无效');
        }

        return $values;
    }
}
