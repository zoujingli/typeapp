<?php

declare(strict_types=1);

namespace app\platform\mqtt\controller;

use app\common\bootstrap\ApplicationContext;
use app\common\service\RoleService;
use app\platform\mqtt\service\ResourceQueries;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Type\Core\Http\Attribute\Group;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Mqtt\PendingCommit;
use Type\Orm\Connection;
use Type\Orm\Db;
use Type\Runtime\ExecutionScope;
use Type\Validate\Field;
use Type\Validate\Input;

/** 平台 MQTT 节点与接收就绪观察；人员复用平台 RBAC，探针使用独立受控凭据。 */
#[Group(prefix: '/platform/broker', namePrefix: 'platform.broker.')]
final class MqttController
{
    private static bool $storeQuarantined = false;

    /** @var list<string> 完整存储工作命令；空列表表示没有可靠接收路径。 */
    private array $worker;

    /** 网络输入不能覆盖宿主配置；请求连接归当前执行作用域持有。 */
    public function __construct(private Factory $messages, ApplicationContext $context)
    {
        $this->worker = ApplicationContext::centerStoreWorker($context->settings()->text('app.mqtt.command'));
    }

    /** 专用探针认证不依赖数据库；只证明管理HTTP进程仍能处理请求。 */
    #[Route('/health/live', name: 'live', middleware: ['mqtt.probe'])]
    public function live(ServerRequestInterface $request): ResponseInterface
    {
        $this->identity($request, false);
        return $this->response(['data' => ['alive' => true, 'scope' => 'management_http', 'observed_at' => time()]]);
    }

    /** 可靠接收就绪同时需要新鲜节点观察、已配置持久路径及当次同步存储证明。 */
    #[Route('/health/ready', name: 'ready', middleware: ['mqtt.probe'])]
    public function ready(ServerRequestInterface $request): ResponseInterface
    {
        $this->identity($request, false);
        $health = $this->health($request);
        return $this->response(['data' => $health], $health['ready'] ? 200 : 503);
    }

    /** 固定指标名与32个slot标签；客户端、Topic、运行ID和载荷不会进入时序标签。 */
    #[Route('/metrics', name: 'metrics', middleware: ['mqtt.probe'])]
    public function metrics(ServerRequestInterface $request): ResponseInterface
    {
        $this->identity($request, false);
        $health = $this->health($request);
        $lines = ['# TYPE typeapp_broker_ready gauge', 'typeapp_broker_ready ' . ($health['ready'] ? '1' : '0'),
            '# TYPE typeapp_broker_store_available gauge', 'typeapp_broker_store_available ' . ($health['store']['state'] === 'available' ? '1' : '0')];
        $allowed = ['connections', 'accepted', 'rejected', 'closed', 'authenticationRefusals', 'observationFailures', 'stopping', 'subscriptions', 'bufferedBytes',
            'incomingExchanges', 'outgoingExchanges', 'pendingCommits', 'durableCommits', 'rejectedCommits', 'unknownCommits', 'quarantinedCommits',
            'connectionQuotaRefusals', 'packetQuotaRefusals', 'subscriptionQuotaRefusals', 'commitQuotaRefusals', 'flushTimeouts', 'handshakeTimeouts',
            'deviceConnections', 'serviceConnections', 'maximumConnections', 'maximumDeviceConnections', 'maximumServiceConnections', 'processMemoryBytes', 'processPeakMemoryBytes'];
        $counters = ['accepted', 'rejected', 'closed', 'authenticationRefusals', 'observationFailures', 'durableCommits', 'rejectedCommits', 'unknownCommits',
            'connectionQuotaRefusals', 'packetQuotaRefusals', 'subscriptionQuotaRefusals', 'commitQuotaRefusals', 'flushTimeouts', 'handshakeTimeouts'];
        $lines[] = '# TYPE typeapp_broker_node_reporting gauge';
        $lines[] = '# TYPE typeapp_broker_node_observed_seconds gauge';
        foreach ($allowed as $key) {
            $counter = in_array($key, $counters, true);
            $name = strtolower((string)preg_replace('/([A-Z])/', '_$1', $key));
            $lines[] = '# TYPE typeapp_broker_' . $name . ($counter ? '_total counter' : ' gauge');
            // 节点加入、退出或观察过期都会改变求和成员，因此观察总和是gauge。
            $lines[] = '# TYPE typeapp_broker_observed_sum_' . $name . ' gauge';
        }
        $totals = [];
        foreach ($health['nodes'] as $node) {
            $labels = '{slot="' . $node['slot'] . '"}';
            $lines[] = 'typeapp_broker_node_reporting' . $labels . ' ' . ($node['reporting'] ? '1' : '0');
            $lines[] = 'typeapp_broker_node_observed_seconds' . $labels . ' ' . $node['observed_at'];
            if (!$node['reporting']) {
                continue;
            }
            foreach ($allowed as $key) {
                $value = $node['metrics'][$key] ?? null;
                if (is_int($value) && $value >= 0) {
                    $name = strtolower((string)preg_replace('/([A-Z])/', '_$1', $key));
                    $lines[] = 'typeapp_broker_' . $name . (in_array($key, $counters, true) ? '_total' : '') . $labels . ' ' . $value;
                    $totals[$name] = ($totals[$name] ?? 0) + $value;
                }
            }
        }
        foreach ($totals as $totalName => $totalValue) {
            $lines[] = 'typeapp_broker_observed_sum_' . $totalName . ' ' . $totalValue;
        }
        foreach ($health['store']['metrics'] ?? [] as $key => $value) {
            $name = strtolower((string)preg_replace('/([A-Z])/', '_$1', $key));
            $lines[] = '# TYPE typeapp_broker_store_' . $name . ' gauge';
            $lines[] = 'typeapp_broker_store_' . $name . ' ' . $value;
        }
        return $this->messages->createResponse($health['state'] === 'management_unavailable' ? 503 : 200)
            ->withHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')->withHeader('Cache-Control', 'no-store')
            ->withBody($this->messages->createStream(implode("\n", $lines) . "\n"));
    }

    /** 有界稳定分页；十五秒未采样标为不可达，缺失指标维持 null。 */
    #[Route('/nodes', name: 'nodes', middleware: ['platform.auth'])]
    public function nodes(ServerRequestInterface $request): ResponseInterface
    {
        $this->identity($request);
        $query = \_vali(['node_id' => Field::text()->length(0, 64)->from('query'), 'page' => Field::integer()->cast()->range(1, 100000)->from('query'),
            'per_page' => Field::integer()->cast()->range(1, 100)->from('query')], (new Input([]))->withQuery($request->getUri()->getQuery()));
        $connection = $this->connection($request);
        try {
            $rows = $connection->table('platform_mqtt_node');
            if (($query['node_id'] ?? '') !== '') {
                $rows = $rows->where('node_id', '=', $query['node_id']);
            }
            $page = $rows->orderBy('node_id')->paginate($query['page'] ?? 1, $query['per_page'] ?? 20, 'slot');
        } finally {
            // 页数据已经读取；同步worker等待期间不占住多余的管理库租约。
            $connection->close();
        }
        $now = time();
        $items = [];
        foreach ($page->items() as $row) {
            $items[] = ['node_id' => (string)$row['node_id'], 'run_id' => (string)$row['run_id'],
                'state' => (int)$row['stopped'] === 2 ? 'isolated' : ((int)$row['stopped'] === 1 ? 'stopped' : ($now - (int)$row['observed_at'] >= 15 ? 'unreachable' : 'reporting')),
                'observed_at' => (int)$row['observed_at'], 'expires_at' => (int)$row['observed_at'] + 15,
                'listener' => json_decode((string)$row['listener_json'], true, 8, JSON_THROW_ON_ERROR),
                'metrics' => json_decode((string)$row['metrics_json'], true, 8, JSON_THROW_ON_ERROR)];
        }
        return $this->response(['items' => $items, 'total' => $page->total(), 'page' => $page->number(), 'per_page' => $page->perPage(),
            'generated_at' => $now, 'freshness_seconds' => 15, 'coverage' => 'observed_instances_only', 'health' => $this->health($request)]);
    }

    /** 同步存储读取仍经有截止worker；清理未知后该HTTP进程不再创建新worker。 */
    private function health(ServerRequestInterface $request): array
    {
        self::$storeQuarantined = self::$storeQuarantined || ResourceQueries::quarantined();
        $now = time();
        $health = ['ready' => false, 'state' => 'no_reporting_nodes', 'observed_at' => $now, 'scope' => 'observed_instances_only', 'nodes' => [],
            'store' => ['state' => 'unconfigured', 'quarantined' => self::$storeQuarantined, 'metrics' => null]];
        $stage = 'management';
        try {
            $active = 0;
            $unavailable = false;
            $connection = $this->connection($request);
            try {
                $observedRows = $connection->table('platform_mqtt_node')->orderBy('slot')->limit(32)->get();
            } finally {
                $connection->close();
            }
            foreach ($observedRows as $row) {
                $metrics = json_decode((string)$row['metrics_json'], true, 8, JSON_THROW_ON_ERROR);
                $reporting = (int)$row['stopped'] === 0 && (int)$row['observed_at'] <= $now && $now - (int)$row['observed_at'] < 15;
                $health['nodes'][] = ['slot' => (int)$row['slot'], 'observed_at' => (int)$row['observed_at'], 'reporting' => $reporting, 'metrics' => $metrics];
                if ((int)$row['stopped'] === 0) {
                    ++$active;
                    $unavailable = $unavailable || !$reporting || ($metrics['durableConfigured'] ?? 0) !== 1
                        || ($metrics['stopping'] ?? 1) !== 0 || ($metrics['quarantinedCommits'] ?? 1) !== 0;
                }
            }
            $stage = 'store';
            if ($this->worker !== [] && !self::$storeQuarantined) {
                $pending = new PendingCommit($this->worker, ['action' => 'session_statistics', 'operation_id' => bin2hex(random_bytes(16))]);
                do {
                    $result = $pending->poll();
                    if ($result === null) {
                        usleep(10000);
                    }
                } while ($result === null);
                // 迟到的成功结果只能证明自身已释放，不能解除另一并发工作的未知资源隔离。
                if (!$result->released) {
                    self::$storeQuarantined = true;
                    ResourceQueries::quarantine();
                }
                $values = null;
                if ($result->state === 'committed' && $result->released) {
                    $values = [];
                    foreach (['sessions', 'persistentSessions', 'deviceSessions', 'applicationSessions', 'subscriptions', 'pendingMessages', 'pendingBytes',
                        'devicePendingMessages', 'devicePendingBytes', 'applicationPendingMessages', 'applicationPendingBytes', 'sharedPendingMessages', 'sharedPendingBytes',
                        'retainedMessages', 'retainedBytes', 'willsMessages', 'willsBytes', 'maximumSessions', 'maximumPendingMessages', 'maximumPendingBytes'] as $key) {
                        $value = $result->value[$key] ?? null;
                        if (!is_int($value) || $value < 0) {
                            throw new \RuntimeException('broker_store_metrics_invalid');
                        }
                        $values[$key] = $value;
                    }
                }
                $health['store'] = ['state' => $values !== null ? 'available' : $result->state, 'quarantined' => self::$storeQuarantined, 'metrics' => $values];
            }
            self::$storeQuarantined = self::$storeQuarantined || ResourceQueries::quarantined();
            if (self::$storeQuarantined) {
                $health['store'] = ['state' => 'quarantined', 'quarantined' => true, 'metrics' => null];
            }
            $capacity = $health['store']['metrics'];
            // worker可能等待同步证明；按响应形成时刻重新判断节点新鲜度，不能延长旧采样寿命。
            $health['observed_at'] = time();
            foreach ($health['nodes'] as $index => $node) {
                if ($node['reporting'] && $health['observed_at'] - $node['observed_at'] >= 15) {
                    $health['nodes'][$index]['reporting'] = false;
                    $unavailable = true;
                }
            }
            $full = $capacity !== null && ($capacity['pendingMessages'] >= $capacity['maximumPendingMessages'] || $capacity['pendingBytes'] >= $capacity['maximumPendingBytes']);
            $health['state'] = $active === 0 ? 'no_reporting_nodes' : ($unavailable ? 'node_unavailable' : ($health['store']['state'] !== 'available' ? 'store_unavailable' : ($full ? 'capacity_exhausted' : 'ready')));
            $health['ready'] = $health['state'] === 'ready';
        } catch (\Throwable) {
            $health['state'] = $stage === 'management' ? 'management_unavailable' : 'store_unavailable';
            $health['observed_at'] = time();
            $health['store'] = ['state' => self::$storeQuarantined ? 'quarantined' : 'unavailable', 'quarantined' => self::$storeQuarantined, 'metrics' => null];
            if ($stage === 'management') {
                $health['nodes'] = [];
            }
        }
        foreach ($health['nodes'] as $index => $node) {
            if ($health['observed_at'] - $node['observed_at'] >= 15) {
                $health['nodes'][$index]['reporting'] = false;
            }
        }
        if (!$health['ready']) {
            $logger = $request->getAttribute('app.logger');
            if ($logger instanceof LoggerInterface) {
                $logger->warning('Broker 接收尚未就绪', ['state' => $health['state'], 'store_state' => $health['store']['state']]);
            }
        }
        return $health;
    }

    private function identity(ServerRequestInterface $request, bool $administrator = true): Identity
    {
        $identity = $request->getAttribute('type.identity');
        if (!$identity instanceof Identity) {
            throw new HttpError(401, 'unauthenticated');
        }
        if ($administrator) {
            RoleService::readContext($identity, 'platform', '', 'broker.read');
        }
        return $identity;
    }

    private function connection(ServerRequestInterface $request): Connection
    {
        $scope = $request->getAttribute('type.scope');
        if (!$scope instanceof ExecutionScope) {
            throw new \RuntimeException('broker_request_scope_required');
        }
        return Db::connection('default', true);
    }

    private function response(array $data, int $status = 200): ResponseInterface
    {
        return $this->messages->createResponse($status)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store')
            ->withBody($this->messages->createStream(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
