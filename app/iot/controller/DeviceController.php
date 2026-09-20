<?php

declare(strict_types=1);

namespace app\iot\controller;

use app\iot\service\DeviceService;
use app\iot\service\CommandService;
use app\iot\service\IngestionService;
use app\iot\service\HistoryService;
use app\iot\service\AggregateService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\RequestBody;
use Type\Orm\Connection;
use Type\Orm\DatabaseManager;
use Type\Runtime\ExecutionScope;
use Type\Validate\Field;
use Type\Validate\Input;

/** 双端设备入口共用状态机；身份域由编译登记的路由决定，不接受请求指定域或归属。 */
final class DeviceController
{
    /** 复用请求资源、消息工厂与设备持久所有者，构造不连接数据库。 */
    public function __construct(private DatabaseManager $database, private Factory $messages, private DeviceService $devices)
    {
    }

    /** 客户查询当前租户，平台可按归属筛选全局资产；列表不附带模型内容或遥测。 */
    #[Route('/customer/tenants/{tenant}/devices', name: 'customer.devices', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
    #[Route('/admin/devices', name: 'admin.devices', middleware: ['admin.auth'])]
    public function devices(ServerRequestInterface $request): ResponseInterface
    {
        $realm = $this->realm($request);
        $tenantId = $this->tenant($request, $realm);
        $fields = ['name' => Field::text()->length(0, 100), 'product_id' => Field::text()->matches('/^([a-f0-9]{32})?$/D'),
            'lifecycle' => Field::text()->oneOf(['', 'inactive', 'enabled', 'disabled', 'retired'])];
        if ($realm === 'admin') {
            $fields['tenant_id'] = Field::text()->matches('/^([a-f0-9]{32})?$/D');
        }
        $input = (new Input([]))->withQuery($request->getUri()->getQuery());
        $fields += ['page' => Field::integer()->cast()->range(1, 100000), 'per_page' => Field::integer()->cast()->range(1, 100)];
        if (array_diff(array_keys($input->source('query')), array_keys($fields)) !== []) {
            throw new HttpError(422, 'device_query_invalid');
        }
        $declarations = [];
        foreach ($fields as $name => $field) {
            $declarations[$name] = $field->from('query');
        }
        $query = \_vali($declarations, $input) + ['page' => 1, 'per_page' => 20];
        return $this->response(200, $this->devices->devices($this->connection($request), $this->identity($request), $tenantId, $query['page'], $query['per_page'], $query, $realm));
    }

    /** 登记仅由客户身份完成，按精确已发布模型生成仅本次返回的凭据。 */
    #[Route('/customer/tenants/{tenant}/devices', methods: ['POST'], name: 'customer.device-create', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
    public function register(ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = $this->tenant($request, $this->realm($request));
        $data = $this->payload($request, ['name' => Field::text()->required()->trim()->length(1, 100),
            'product_id' => Field::text()->required()->matches('/^[a-f0-9]{32}$/D'), 'model_version' => Field::integer()->required()->range(1, 2147483646)]);
        return $this->response(201, ['data' => $this->devices->register($this->connection($request), $this->identity($request), $tenantId, $data['product_id'], $data['model_version'], $data['name'])]);
    }

    /** 详情采用当前身份域投影；资料修改不允许更换归属、产品、模型或生命周期。 */
    #[Route('/customer/tenants/{tenant}/devices/{device}', methods: ['GET', 'PATCH'], name: 'customer.device', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'device' => '[a-f0-9]{32}'])]
    #[Route('/admin/devices/{device}', methods: ['GET', 'PATCH'], name: 'admin.device', middleware: ['admin.auth'], constraints: ['device' => '[a-f0-9]{32}'])]
    public function device(ServerRequestInterface $request): ResponseInterface
    {
        $realm = $this->realm($request);
        $tenantId = $this->tenant($request, $realm);
        $parameters = $request->getAttribute('type.route.params', []);
        $connection = $this->connection($request);
        $identity = $this->identity($request);
        if ($request->getMethod() === 'GET') {
            return $this->response(200, ['data' => $this->devices->device($connection, $identity, $tenantId, $parameters['device'], $realm)]);
        }
        $data = $this->payload($request, ['version' => Field::integer()->required()->range(1, 2147483646), 'name' => Field::text()->required()->trim()->length(1, 100)]);
        return $this->response(200, ['data' => $this->devices->change($connection, $identity, $tenantId, $parameters['device'], 'update', $data['version'], '', $realm, $data['name'])]);
    }

    /** 202只表示撤权已提交；网络隔离的真实完成由Broker回写，不能用HTTP成功代替。 */
    #[Route('/customer/tenants/{tenant}/devices/{device}/{action}', methods: ['POST'], name: 'customer.device-change', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'device' => '[a-f0-9]{32}', 'action' => 'rotate|revoke|disable|enable|retire'])]
    #[Route('/admin/devices/{device}/{action}', methods: ['POST'], name: 'admin.device-change', middleware: ['admin.auth'], constraints: ['device' => '[a-f0-9]{32}', 'action' => 'rotate|revoke|disable|enable|retire'])]
    public function change(ServerRequestInterface $request): ResponseInterface
    {
        $realm = $this->realm($request);
        $tenantId = $this->tenant($request, $realm);
        $parameters = $request->getAttribute('type.route.params', []);
        $data = $this->payload($request, ['version' => Field::integer()->required()->range(1, 2147483646), 'confirm_device_id' => Field::text()->required()->matches('/^[a-f0-9]{32}$/D')]);
        $result = $this->devices->change($this->connection($request), $this->identity($request), $tenantId, $parameters['device'], $parameters['action'], $data['version'], $data['confirm_device_id'], $realm);
        return $this->response($result['device']['authorization']['status'] === 'pending' ? 202 : 200, ['data' => $result]);
    }

    /** 模型切换使用当前客户独立权限和设备版本；GET只读，响应丢失使用原ID确认，retry明确重发同一意图。 */
    #[Route('/customer/tenants/{tenant}/devices/{device}/model-switches', methods: ['GET', 'POST'], name: 'customer.model-switches', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'device' => '[a-f0-9]{32}'])]
    public function modelSwitches(ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = $this->tenant($request, 'customer');
        $parameters = $request->getAttribute('type.route.params', []);
        if ($request->getMethod() === 'GET') {
            $query = $this->query($request, []);
            return $this->response(200, $this->devices->modelSwitches($this->connection($request), $this->identity($request), $tenantId, $parameters['device'], $query['page'], $query['per_page']));
        }
        $data = $this->payload($request, ['switch_id' => Field::text()->required()->matches('/^[a-f0-9]{32}$/D'),
            'model_version' => Field::integer()->required()->range(1, 2147483646), 'version' => Field::integer()->required()->range(1, 2147483646), 'retry' => Field::boolean()->defaultValue(false)]);
        return $this->response(202, ['data' => $this->devices->switchModel(
            $this->connection($request),
            $this->identity($request),
            $tenantId,
            $parameters['device'],
            $data['switch_id'],
            $data['model_version'],
            $data['version'],
            $data['retry']
        )]);
    }

    /** 指令列表只读取已有事实，发起操作独立授权并返回受理状态。 */
    #[Route('/customer/tenants/{tenant}/devices/{device}/commands', methods: ['GET', 'POST'], name: 'customer.commands', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'device' => '[a-f0-9]{32}'])]
    public function commands(ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = $this->tenant($request, 'customer');
        $parameters = $request->getAttribute('type.route.params', []);
        if ($request->getMethod() === 'GET') {
            $query = $this->query($request, []);
            return $this->response(200, CommandService::history($this->connection($request), $this->identity($request), $tenantId, $parameters['device'], $query['page'], $query['per_page']));
        }
        $data = $this->payload($request, ['version' => Field::integer()->required()->range(1, 2147483645), 'command_id' => Field::text()->required()->matches('/^[a-f0-9]{32}$/D'), 'identifier' => Field::text()->required()->matches('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/D'), 'values' => (new Field('object'))->required()], 16384, 16);
        return $this->response(201, ['data' => CommandService::create($this->connection($request), $this->identity($request), $tenantId, $parameters['device'], $data['identifier'], $data['values'], $data['command_id'], $data['version'])]);
    }

    /** 只读单条指令的受理与执行事实，旧未知结果不因列表翻页丢失观察入口。 */
    #[Route('/customer/tenants/{tenant}/devices/{device}/commands/{command}', methods: ['GET'], name: 'customer.command-detail', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'device' => '[a-f0-9]{32}', 'command' => '[a-f0-9]{32}'])]
    public function commandDetail(ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = $this->tenant($request, 'customer');
        $parameters = $request->getAttribute('type.route.params', []);
        return $this->response(200, ['data' => CommandService::detail($this->connection($request), $this->identity($request), $tenantId, $parameters['device'], $parameters['command'])]);
    }

    /** GET只读传输证据；POST主动查询独立授权，受理不等于设备结果。 */
    #[Route('/customer/tenants/{tenant}/devices/{device}/commands/{command}/queries', methods: ['GET', 'POST'], name: 'customer.command-queries', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'device' => '[a-f0-9]{32}', 'command' => '[a-f0-9]{32}'])]
    public function commandQueries(ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = $this->tenant($request, 'customer');
        $parameters = $request->getAttribute('type.route.params', []);
        if ($request->getMethod() === 'GET') {
            $query = $this->query($request, []);
            return $this->response(200, CommandService::timeline($this->connection($request), $this->identity($request), $tenantId, $parameters['device'], $parameters['command'], $query['page'], $query['per_page']));
        }
        $data = $this->payload($request, ['query_id' => Field::text()->required()->matches('/^[a-f0-9]{32}$/D')]);
        return $this->response(202, ['data' => CommandService::query($this->connection($request), $this->identity($request), $tenantId, $parameters['device'], $parameters['command'], $data['query_id'])]);
    }


    /** 原始历史和显示曲线共用严格筛选声明；未知参数、跨租户和越界页不降级为宽泛查询。 */
    #[Route('/customer/tenants/{tenant}/devices/{device}/history', name: 'customer.history', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'device' => '[a-f0-9]{32}'])]
    public function history(ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = $this->tenant($request, 'customer');
        $fields = ['view' => Field::text()->oneOf(['records', 'curve', 'minutes', 'minute_curve']),
            'stat' => Field::text()->oneOf(['count', 'min', 'max', 'sum', 'avg', 'last']),
            'from' => Field::integer()->cast()->range(1, 253402300799), 'to' => Field::integer()->cast()->range(1, 253402300799),
            'product_id' => Field::text()->matches('/^([a-f0-9]{32})?$/D'), 'ownership_id' => Field::text()->matches('/^([a-f0-9]{32})?$/D'),
            'model_version' => Field::integer()->cast()->range(1, 2147483646), 'field' => Field::text()->matches('/^([a-zA-Z_][a-zA-Z0-9_]{0,63})?$/D'),
            'sort' => Field::text()->oneOf(['sampled_desc', 'sampled_asc', 'received_desc', 'received_asc']), 'cursor' => Field::text()->length(0, 1024)];
        $raw = (new Input([]))->withQuery($request->getUri()->getQuery())->source('query');
        if (array_diff(array_keys($raw), [...array_keys($fields), 'page', 'per_page']) !== []) {
            throw new HttpError(422, 'history_filter_invalid');
        }
        $query = $this->query($request, $fields);
        $view = $query['view'] ?? 'records';
        unset($query['view']);
        if ($view !== 'minute_curve' && isset($query['stat'])) {
            throw new HttpError(422, 'history_filter_invalid');
        }
        $parameters = $request->getAttribute('type.route.params', []);
        $connection = $this->connection($request);
        $identity = $this->identity($request);
        if ($view === 'minutes' || $view === 'minute_curve') {
            return $this->response(200, $view === 'minute_curve'
                ? ['data' => AggregateService::curve($connection, $identity, $tenantId, $parameters['device'], $query)]
                : AggregateService::search($connection, $identity, $tenantId, $parameters['device'], $query));
        }
        return $this->response(200, $view === 'curve'
            ? ['data' => HistoryService::curve($connection, $identity, $tenantId, $parameters['device'], $query)]
            : HistoryService::search($connection, $identity, $tenantId, $parameters['device'], $query));
    }

    /** 当前遥测独立授权；资产查询权限不隐含业务数据读取。 */
    #[Route('/customer/tenants/{tenant}/devices/{device}/current', name: 'customer.device-current', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'device' => '[a-f0-9]{32}'])]
    public function current(ServerRequestInterface $request): ResponseInterface
    {
        $this->query($request, []);
        $parameters = $request->getAttribute('type.route.params', []);
        return $this->response(200, ['data' => IngestionService::current($this->connection($request), $this->identity($request), $this->tenant($request, 'customer'), $parameters['device'])]);
    }

    /** 尚未取得发送领取才可取消；已下发保留未知和真实回执。 */
    #[Route('/customer/tenants/{tenant}/devices/{device}/commands/{command}/cancel', methods: ['POST'], name: 'customer.command-cancel', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'device' => '[a-f0-9]{32}', 'command' => '[a-f0-9]{32}'])]
    public function cancelCommand(ServerRequestInterface $request): ResponseInterface
    {
        $parameters = $request->getAttribute('type.route.params', []);
        $data = $this->payload($request, ['cancel_id' => Field::text()->required()->matches('/^[a-f0-9]{32}$/D')]);
        return $this->response(200, ['data' => CommandService::cancel($this->connection($request), $this->identity($request), $this->tenant($request, 'customer'), $parameters['device'], $parameters['command'], $data['cancel_id'])]);
    }

    /** @param array<string, Field> $fields 有界查询；未知参数不能放宽范围。 */
    private function query(ServerRequestInterface $request, array $fields): array
    {
        $fields += ['page' => Field::integer()->cast()->range(1, 100000), 'per_page' => Field::integer()->cast()->range(1, 100)];
        $input = (new Input([]))->withQuery($request->getUri()->getQuery());
        if (array_diff(array_keys($input->source('query')), array_keys($fields)) !== []) {
            throw new HttpError(422, 'unexpected_field');
        }
        $declarations = [];
        foreach ($fields as $name => $field) {
            $declarations[$name] = $field->from('query');
        }
        return \_vali($declarations, $input) + ['page' => 1, 'per_page' => 20];
    }

    private function realm(ServerRequestInterface $request): string
    {
        return match ((string) $request->getAttribute('type.route', '')) {
            'admin.devices', 'admin.device', 'admin.device-change' => 'admin',
            'customer.devices', 'customer.device', 'customer.device-create', 'customer.device-change' => 'customer',
            default => throw new HttpError(404, 'not_found'),
        };
    }

    private function tenant(ServerRequestInterface $request, string $realm): string
    {
        if ($realm === 'admin') {
            if ($request->hasHeader('X-Tenant-Id')) {
                throw new HttpError(403, 'identity_context_invalid');
            }
            return '';
        }
        $headers = $request->getHeader('X-Tenant-Id');
        $parameters = $request->getAttribute('type.route.params', []);
        if (count($headers) !== 1 || !preg_match('/^[a-f0-9]{32}$/D', $headers[0]) || ($parameters['tenant'] ?? '') !== $headers[0]) {
            throw new HttpError(403, 'tenant_context_mismatch');
        }
        return $headers[0];
    }

    /** @param array<string, Field> $fields 有界对象白名单；拒绝伪造归属及状态。 */
    private function payload(ServerRequestInterface $request, array $fields, int $maximumBytes = 4096, int $maximumDepth = 4): array
    {
        if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
            throw new HttpError(415, 'json_required');
        }
        $input = Input::json(RequestBody::read($request->getBody(), $maximumBytes), $maximumBytes, $maximumDepth);
        if (array_diff(array_keys($input->source('body')), array_keys($fields)) !== []) {
            throw new HttpError(422, 'unexpected_field');
        }
        return \_vali($fields, $input);
    }

    private function identity(ServerRequestInterface $request): Identity
    {
        foreach (['X-Support-Id', 'X-Impersonation-Id', 'X-Identity-Realm'] as $header) {
            if ($request->hasHeader($header)) {
                throw new HttpError(403, 'identity_context_invalid');
            }
        }
        $identity = $request->getAttribute('type.identity');
        if (!$identity instanceof Identity) {
            throw new HttpError(401, 'unauthenticated');
        }
        return $identity;
    }

    private function connection(ServerRequestInterface $request): Connection
    {
        $scope = $request->getAttribute('type.scope');
        if (!$scope instanceof ExecutionScope) {
            throw new \RuntimeException('设备接口需要受管请求作用域');
        }
        return \Type\Orm\Db::connection('default', true);
    }

    private function response(int $status, array $data): ResponseInterface
    {
        return $this->messages->createResponse($status)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store')
            ->withBody($this->messages->createStream(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
