<?php

declare(strict_types=1);

namespace app\catalog\runtime;

use app\common\bootstrap\Application;
use app\common\bootstrap\Settings;
use app\common\database\DatabaseFactory;
use Type\Cache\JsonCodec;
use Type\Cache\NamespaceStore;
use Type\Cache\TypedCache;
use Type\Core\Config\Repository;
use Type\Orm\DatabaseManager;
use Type\Orm\Db;
use Type\Queue\Queue;
use Type\Redis\Purpose;
use Type\Redis\RedisConfiguration;
use Type\Redis\RedisManager;
use Type\Runtime\ExecutionScope;
use Type\Runtime\ManagedResource;

/** 单次命令的基础设施所有者；先登记后借用，作用域逆序关闭租约与池。 */
final class Infrastructure implements ManagedResource
{
    /** 配置仅来自启动文件和环境，不来自任务载荷或 HTTP 输入。 */
    public function __construct(public Repository $settings, public string $basePath, public DatabaseManager $database, public RedisManager $redis)
    {
    }

    /** 静态工厂只解析配置和声明池；尚未连接数据库或 Redis。 */
    public static function create(): Infrastructure
    {
        $base = Settings::basePath();
        $settings = Settings::load($base);
        Application::assertDatabase($settings);
        $driver = DatabaseFactory::create($settings, $base);
        return new self($settings, $base, new DatabaseManager(['default' => $driver, 'catalog' => $driver], 4, 0),
            new RedisManager(['default' => new RedisConfiguration($settings->text('catalog.redis_host'),
                Settings::integer($settings, 'catalog.redis_port', 1, 65535))], [Purpose::SCRIPT => 4]));
    }

    /** 在业务及任务开始前固定命名数据源；活动作用域不能切换管理器。 */
    public function start(): void
    {
        Db::configure($this->database);
    }

    /** 调用方只借用缓存；连接由当前作用域回收。 */
    public function cache(): TypedCache
    {
        return new TypedCache(new NamespaceStore($this->redis->connection(ExecutionScope::current(), 'default', Purpose::SCRIPT),
            $this->settings->text('catalog.namespace'), 'tutorial', 'json-data-v1'), JsonCodec::data(), 60000);
    }

    /** 命名空间隔离每个应用；租约与容量均有明确上限。 */
    public function queue(): Queue
    {
        return new Queue($this->redis->connection(ExecutionScope::current(), 'default', Purpose::SCRIPT),
            $this->settings->text('catalog.namespace'), 'catalog', 1000, 100);
    }

    /** 先由 scope 收回业务会话，最后关闭池；正常和异常路径共同执行。 */
    public function stop(): void
    {
        $this->redis->close();
        $this->database->close();
    }
}
