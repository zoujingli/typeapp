<?php

declare(strict_types=1);

namespace TypeApp\Integration;

use DateTimeImmutable;
use Type\Cache\JsonCodec;
use Type\Cache\NamespaceStore;
use Type\Cache\SignedSerializer;
use Type\Cache\SimpleCache;
use Type\Cache\TypedCache;
use Type\Log\Channel;
use Type\Log\LogManager;
use Type\Log\Output;
use Type\Orm\Connection;
use Type\Orm\DatabaseManager;
use Type\Orm\Db;
use Type\Orm\Driver;
use Type\Orm\Migration\Migration;
use Type\Orm\Migration\Migrator;
use Type\Orm\ModelQuery;
use Type\Orm\Outbox\Relay;
use Type\Orm\Outbox\Store;
use Type\Orm\Relation;
use Type\Queue\JobContext;
use Type\Queue\Queue;
use Type\Queue\Registry;
use Type\Queue\Worker;
use Type\Redis\Purpose;
use Type\Redis\RedisConfiguration;
use Type\Redis\RedisManager;
use Type\Redis\StoragePolicy;
use Type\Runtime\ExecutionScope;
use Type\Scheduler\CronSchedule;
use Type\Scheduler\Definition;
use Type\Scheduler\RedisStateStore;
use Type\Scheduler\Scheduler;
use Type\Scheduler\SystemClock;
use Type\Scheduler\TaskContext;
use Type\Validate\Field;
use Type\Validate\Input;
use Type\Validate\Schema;
use Type\Validate\ValidationException;
use TypeApp\Coordination\QueueDispatchTask;
use TypeApp\OrmSuite\Article;
use TypeApp\OrmSuite\Tag;
use TypeApp\OrmSuite\User;
use TypeApp\OutboxExample\QueuePublisher;

/** 组合三库模型、独立 Redis、Outbox、队列与日志的完整示例装配。 */
final class Scenario
{
    /**
     * 根据驱动声明模型、消息意图和审查凭据表，不在运行中猜测迁移内容。
     *
     * @return list<\Type\Orm\Migration\Migration>
     */
    public static function migrations(Driver $driver): array
    {
        $engine = $driver->name() === 'mysql' ? ' ENGINE=InnoDB' : '';
        return array_merge(\TypeApp\OrmSuite\Schema::plan($driver->name()), [(new Store())->migration($driver->name(), '202609090003'),
            new Migration('202609090004', '保存文章审查任务幂等效果', ['CREATE TABLE integration_audits (id VARCHAR(128) PRIMARY KEY, article_id INTEGER NOT NULL, observed_views INTEGER NOT NULL)' . $engine], $driver->name() !== 'mysql')]);
    }
    /** 为文章 DTO 建立独立缓存命名空间，租约绑定外层作用域。 */
    public static function cache(RedisManager $redis, ExecutionScope $scope, string $application): TypedCache
    {
        return new TypedCache(new NamespaceStore($redis->connection($scope, 'cache', Purpose::SCRIPT), $application, 'integration', 'article-dto'), JsonCodec::data('article-dto-v1'), 3000);
    }
    /** 从显式环境建立可靠消息和可淘汰缓存两个连接名，调用方负责关闭。 */
    public static function redis(): RedisManager
    {
        $reliable = new RedisConfiguration((string) getenv('TYPE_REDIS_HOST'), (int) (getenv('TYPE_REDIS_PORT') ?: 6379));
        $cache = new RedisConfiguration((string) getenv('TYPE_INTEGRATION_CACHE_HOST'), (int) (getenv('TYPE_INTEGRATION_CACHE_PORT') ?: 6379));
        return new RedisManager(['queue' => $reliable, 'cache' => $cache], [Purpose::SCRIPT => 4]);
    }
    /**
     * 验证可靠存储隔离后运行业务闭环；返回实际观察结果，finally 收尾已打开资源。
     *
     * @return array<string, mixed> 业务与资源验证结果。
     */
    public static function run(Driver $driver, string $application): array
    {
        StoragePolicy::verify(
            new RedisConfiguration((string) getenv('TYPE_REDIS_HOST'), (int) (getenv('TYPE_REDIS_PORT') ?: 6379)),
            new RedisConfiguration((string) getenv('TYPE_INTEGRATION_CACHE_HOST'), (int) (getenv('TYPE_INTEGRATION_CACHE_PORT') ?: 6379))
        );
        (new Migrator($driver, 'type_integration_migrations'))->run(self::migrations($driver));
        $scope = new ExecutionScope(null, ['trace_id' => $application]);
        $manager = new DatabaseManager(['default' => $driver]);
        Db::configure($manager);
        $redis = self::redis();
        $outbox = new Store();
        $logs = new LogManager($application, ['app' => new Channel(Output::file((string) getenv('TYPE_INTEGRATION_LOG')))]);
        $scope->open($logs);
        $logger = $logs->logger($scope);
        try {
            return $scope->run(static function (ExecutionScope $current) use ($driver, $application, $scope, $manager, $redis, $outbox, $logger): array {
                $logger->info('集成业务开始');
                $schema = new Schema(['name' => Field::text()->required()->trim()->length(2, 40), 'title' => Field::text()->required()->trim()->length(2, 100)]);
                $failed = false;
                try {
                    $schema->validate(Input::json('{"name":"","title":""}'));
                } catch (ValidationException) {
                    $failed = true;
                }
                self::check($failed, '输入错误没有在写入前拒绝');
                $input = $schema->validate(Input::json('{"name":"集成作者","title":"框架联调文章"}'));
                $connection = Db::connection('default', true);
                $articleId = $connection->transaction(static function (Connection $transaction) use ($input, $outbox): int {
                    $user = new User(['name' => $input->get('name'), 'active' => true, 'external_id' => '123456789012345678901234567890',
                        'credit' => '12345678901234567890.12', 'profile' => ['level' => 'editor'], 'joined_at' => new DateTimeImmutable('2026-09-09T00:30:01.123456Z'), 'secret' => '内部字段']);
                    $user->save();
                    $article = new Article(['user_id' => $user->getId(), 'title' => $input->get('title'), 'status' => 'published', 'views' => 0]);
                    $article->save();
                    $tag = new Tag(['label' => '完整集成']);
                    $tag->save();
                    $relation = Relation::belongsToMany(static fn (Connection $target): ModelQuery => Tag::query()->onConnection($target), 'type_suite_article_tags', 'article_id', 'tag_id', 'id', 'id', ['weight']);
                    $relation->attach($article, $tag->getId(), ['weight' => 1]);
                    $outbox->enqueue($transaction, 'integration-published', 'article.published', 1, ['article_id' => $article->getId()]);
                    return $article->getId();
                });
                $cache = self::cache($redis, $scope, $application);
                $key = 'article:' . $articleId;
                $before = $cache->remember($key, static fn (): array => Reader::article($articleId));
                self::check($cache->get($key)->hit() && $before['views'] === 0 && $before['author']['credit'] === '12345678901234567890.12'
                    && !array_key_exists('secret', $before['author']) && count($before['tags']) === 1, '关系 DTO、精确值或受控缓存没有贯通');
                $queueConnection = $redis->connection($scope, 'queue', Purpose::SCRIPT);
                $queue = new Queue($queueConnection, $application, 'integration');
                $relay = new Relay($manager, $outbox, new QueuePublisher($queue));
                self::check($relay->runOnce() === 1, 'Outbox 没有投递文章任务');
                $registry = new Registry();
                $registry->register('article.published', 1, static fn (JobContext $context): ArticleJob => new ArticleJob($outbox, $redis, $application));
                $registry->register('article.audit', 1, static fn (JobContext $context): ArticleJob => new ArticleJob($outbox, $redis, $application, true));
                $worker = new Worker($queue, $registry, 'integration-worker');
                self::check($worker->runOnce(), '文章任务没有执行');
                $updated = $cache->remember($key, static fn (): array => Reader::article($articleId));
                self::check($updated['views'] === 1, '消费模型效果后仍返回旧缓存');
                $outbox->replay($connection, 'integration-published', '验证同一文章消息重复投递的幂等效果');
                self::check($relay->runOnce() === 1 && $worker->runOnce() && Article::query()->find($articleId)->getViews() === 1, '重复消息产生了第二次模型效果');
                $scheduler = new Scheduler(new SystemClock(), new RedisStateStore($queueConnection, $application, 'articles'), [
                    new Definition('article.audit', new CronSchedule('* * * * *'), static fn (TaskContext $context): QueueDispatchTask => new QueueDispatchTask($queue, 'article.audit', 1, ['article_id' => $articleId])),
                ]);
                self::check(count($scheduler->tick()) === 1 && $scheduler->tick() === [] && $worker->runOnce(), '调度到队列再到模型的路径不完整或重复');
                $audited = $cache->remember($key, static fn (): array => Reader::article($articleId));
                self::check($audited['status'] === 'audited' && (int) $connection->table('integration_audits')->aggregate('COUNT') === 1, '审查状态或任务幂等记录不正确');
                $psr = new SimpleCache(new NamespaceStore($redis->connection($scope, 'cache', Purpose::SCRIPT), $application, 'integration', 'psr'), new SignedSerializer((string) getenv('TYPE_INTEGRATION_HMAC'), [\stdClass::class]), 30);
                $object = new \stdClass();
                $object->article = $articleId;
                $object->data = [null, false, '1', 1];
                self::check($psr->set('result', $object) && $psr->get('result') == $object, 'PSR 缓存没有按原类型往返');
                $measurements = Measurements::run($manager, $articleId);
                $logger->info('集成业务完成', ['article_id' => $articleId, 'processed' => $worker->statistics()['completed']]);
                return ['driver' => $driver->name(), 'database_version' => $connection->serverVersion(), 'article_id' => $articleId, 'article' => $audited,
                    'checks' => ['validation', 'models', 'relations', 'exact-values', 'transaction', 'typed-cache', 'psr-cache', 'outbox', 'queue', 'idempotency', 'cron', 'distributed-scheduler', 'logging'],
                    'measurements' => $measurements,
                    'pool' => $manager->statistics(), 'queue' => $queue->statistics(), 'worker' => $worker->statistics(), 'scheduler' => $scheduler->statistics(),
                    'queue_identity' => $queue->identity(), 'cache_identity' => (new NamespaceStore($redis->connection($scope, 'cache', Purpose::SCRIPT), $application, 'integration', 'article-dto'))->identity()];
            });
        } finally {
            $scope->close();
            $manager->close();
            $redis->close();
        }
    }
    private static function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }
}
