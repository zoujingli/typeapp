<?php

declare(strict_types=1);

namespace TypeApp\TenantExample;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Type\Cache\JsonCodec;
use Type\Cache\NamespaceStore;
use Type\Cache\TypedCache;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\Tenant;
use Type\Orm\Connection;
use Type\Orm\DatabaseManager;
use Type\Orm\DatabaseException;
use Type\Orm\Model;
use Type\Orm\ModelDefinition;
use Type\Orm\ModelField;
use Type\Orm\ModelQuery;
use Type\Log\LogManager;
use Type\Redis\Purpose;
use Type\Redis\RedisManager;
use Type\Runtime\ExecutionScope;
use Type\Validate\Field;
use Type\Validate\Input;
use Type\Validate\Schema;

final class Endpoint implements RequestHandlerInterface
{
    private DatabaseManager $databases;
    private array $replacements;
    private RedisManager $redis;
    private LogManager $logs;

    /** 池与日志归应用生命周期所有，处理器仅持有当前请求的租约。 */
    public function __construct(DatabaseManager $databases, array $replacements, RedisManager $redis, LogManager $logs)
    {
        $this->databases = $databases;
        $this->replacements = $replacements;
        $this->redis = $redis;
        $this->logs = $logs;
    }
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tenant = $request->getAttribute('type.tenant');
        $scope = $request->getAttribute('type.scope');
        if (!$tenant instanceof Tenant || !$scope instanceof ExecutionScope || !isset($this->replacements[$tenant->connection()])) {
            throw new \RuntimeException('租户资源未授权');
        }
        $input = (new Schema(['probe' => Field::boolean()->from('query')->cast(), 'fail' => Field::boolean()->from('query')->cast(),
            'rotate' => Field::boolean()->from('query')->cast(), 'poison' => Field::boolean()->from('query')->cast()]))->validate((new Input([]))->with('query', $request->getQueryParams()));
        if ($input->has('rotate') && $input->get('rotate')) {
            $this->databases->rotate($tenant->connection(), $this->replacements[$tenant->connection()]);
        }
        $before = $this->databases->statistics();
        $reused = ($before['active'][$tenant->connection()]['idle'] ?? 0) > 0;
        $marker = $request->getHeaderLine('X-Test-Marker');
        $marker = preg_match('/^[a-z0-9-]{1,64}$/D', $marker) ? $marker : 'unmarked';
        $logger = $this->logs->logger($scope, ['request_id' => $marker, 'tenant_id' => $tenant->id()]);
        $connection = null;
        $generation = 0;
        $cacheIdentity = '';
        $failed = false;
        try {
            $connection = $this->databases->connect($scope, $tenant->connection());
            $identity = $connection->identity();
            $generation = (int) $identity['credential-generation'];
            $currentOwner = $connection->query('SELECT owner FROM tenant_values WHERE id = ?', [1])[0]['owner'];
            $baseline = match ($identity['driver']) {
                'mysql' => $connection->query('SELECT @@SESSION.time_zone AS value')[0]['value'] === '+00:00',
                'pgsql' => $connection->query('SELECT current_schema() AS value')[0]['value'] === $identity['session']['schema'],
                default => (int) $connection->query('PRAGMA foreign_keys')[0]['foreign_keys'] === 1,
            };
            if ($currentOwner !== $tenant->id() || !$baseline) {
                throw new \RuntimeException('租户会话基线或当前资源不一致');
            }
            $factory = new Factory();
            $store = new NamespaceStore(
                $this->redis->connection($scope, 'default', Purpose::SCRIPT),
                json_encode([$tenant->cacheNamespace(), $identity], JSON_THROW_ON_ERROR),
                'test',
                'tenant-v1'
            );
            $cacheIdentity = $store->identity();
            $cache = new TypedCache($store, JsonCodec::data(), 30000);
            $models = new ModelQuery($connection, TenantValue::mapping(), static fn (array $values): TenantValue => new TenantValue($values));
            if ($input->has('fail') && $input->get('fail')) {
                $entity = $models->find(1);
                $connection->transaction(static function (Connection $transaction) use ($entity): void {
                    $entity->set('visits', 99);
                    $entity->save($transaction);
                    throw new \RuntimeException('tenant failure sentinel');
                });
            }
            if ($input->has('probe') && $input->get('probe')) {
                $denied = null;
                if ($identity['driver'] !== 'sqlite') {
                    $otherIdentity = $this->databases->identity($tenant->id() === 'alpha' ? 'beta-db' : 'alpha-db');
                    $other = $identity['driver'] === 'mysql' ? $otherIdentity['database'] : $otherIdentity['session']['schema'];
                    if (!is_string($other) || !preg_match('/^[a-z0-9_]+$/D', $other)) {
                        throw new \RuntimeException('测试资源标识无效');
                    }
                    $denied = false;
                    try {
                        $quote = $identity['driver'] === 'mysql' ? '`' : '"';
                        $connection->rawQuery('SELECT * FROM ' . $quote . $other . $quote . '.tenant_values');
                    } catch (DatabaseException $error) {
                        $cause = $error->getPrevious();
                        $denied = $cause instanceof \PDOException && ($identity['driver'] === 'mysql'
                            ? in_array((int) ($cause->errorInfo[1] ?? 0), [1044, 1142], true) : ($cause->errorInfo[0] ?? '') === '42501');
                    }
                }
                $value = ['tenant' => $tenant->id(), 'cross_database_denied' => $denied,
                    'isolation' => $identity['driver'] === 'sqlite' ? 'trusted-file-mapping' : 'database-permissions'];
            } else {
                $value = $cache->remember('same-key', static function () use ($connection, $tenant, $models): array {
                    $entity = $models->find(1);
                    if (!$entity instanceof TenantValue) {
                        throw new \RuntimeException('租户模型不存在');
                    }
                    $rows = $connection->table('tenant_values', 'v')->select(['owner' => 'v.owner', 'label' => 'l.label'])
                        ->join('tenant_labels', 'v.id', '=', 'l.id', 'l')->get();
                    $entity->set('visits', 1);
                    $entity->save($connection);
                    $raw = $connection->rawQuery('SELECT owner FROM tenant_values WHERE id = ?', [1])[0]['owner'];
                    if ($entity->get('owner') !== $rows[0]['owner']) {
                        throw new \RuntimeException('租户模型与Join结果不一致');
                    }
                    return ['tenant' => $tenant->id(), 'owner' => $entity->get('owner'), 'label' => $rows[0]['label'], 'raw' => $raw];
                });
            }
            if ($input->has('poison') && $input->get('poison')) {
                $connection->execute(match ($identity['driver']) {
                    'mysql' => "SET time_zone = '+08:00'",
                    'pgsql' => 'SET search_path TO pg_catalog',
                    default => 'PRAGMA foreign_keys = OFF',
                });
            }
            if ((getenv('TYPE_HTTP_DRIVER') ?: 'swoole') === 'stream') {
                usleep(5000);
            } else {
                \Swoole\Coroutine::sleep(0.005);
            }
            return $factory->createResponse()->withHeader('Content-Type', 'application/json')->withBody($factory->createStream((string) json_encode($value, JSON_THROW_ON_ERROR)));
        } catch (\Throwable $failure) {
            $failed = true;
            throw $failure;
        } finally {
            $outcome = $connection?->transactionOutcome();
            $connection?->close();
            $logger->log($failed ? 'warning' : 'info', 'tenant-request', ['generation' => $generation, 'namespace' => $cacheIdentity,
                'reused_idle' => $reused, 'failed' => $failed, 'outcome' => $outcome]);
        }
    }
}

/** 所有租户复用同一模型映射，由已授权连接决定物理资源。 */
final class TenantValue extends Model
{
    /** @param array<string,mixed> $values 当前租户查询得到的字段。 */
    public function __construct(array $values)
    {
        parent::__construct(self::mapping(), $values, true);
    }

    /** 不使用全局where模拟租户隔离，映射在每个已授权资源内保持一致。 */
    public static function mapping(): ModelDefinition
    {
        return new ModelDefinition('tenant_values', 'id', ['id' => new ModelField('id', 'integer'),
            'owner' => new ModelField('owner', 'string'), 'visits' => new ModelField('visits', 'integer')]);
    }
}
