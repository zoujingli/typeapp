<?php

declare(strict_types=1);

namespace app\iot\controller;

use app\iot\service\DeviceService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\RequestBody;
use Type\Orm\Connection;
use Type\Runtime\ExecutionScope;
use Type\Validate\Field;
use Type\Validate\Input;

/** 双端设备入口共用状态机；身份域由编译登记的路由决定，不接受请求指定域或归属。 */
final class DeviceController
{
    /** 复用请求资源、消息工厂与设备持久所有者，构造不连接数据库。 */
    public function __construct(private Factory $messages, private DeviceService $devices)
    {
    }

    /** 设备以独立注册码建立接入身份；不接受人员会话代替资格，幂等重试不回显旧凭据。 */
    #[Route('/public/devices/register', methods: ['POST'], name: 'device.registration')]
    public function enroll(ServerRequestInterface $request): ResponseInterface
    {
        $data = $this->payload($request, ['id' => Field::text()->required()->matches('/^[a-f0-9]{32}$/D'),
            'tenant_id' => Field::text()->required()->matches('/^[a-f0-9]{32}$/D'), 'device_id' => Field::text()->required()->matches('/^[a-f0-9]{32}$/D'),
            'name' => Field::text()->required()->trim()->length(1, 100), 'code' => Field::text()->required()->matches('/^[a-f0-9]{32}\.[a-f0-9]{64}$/D')]);
        $canonical = $request->getAttribute('type.request');
        if (!$canonical instanceof \Type\Core\Http\CanonicalRequest) {
            throw new HttpError(400, 'invalid_peer');
        }
        $result = $this->devices->enroll($data, $canonical->clientIp());
        return $this->response($result['replayed'] ? 200 : 201, ['data' => $result]);
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

    /** 详情采用当前身份域投影；资料修改只接受名称及同客户分类，不能更换归属或生命周期。 */
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
        $data = $this->payload($request, ['version' => Field::integer()->required()->range(1, 2147483646), 'name' => Field::text()->required()->trim()->length(1, 100),
            'product_id' => Field::text()->nullable()->matches('/^[a-f0-9]{32}$/D')]);
        return $this->response(200, ['data' => $this->devices->change($connection, $identity, $tenantId, $parameters['device'], 'update', $data['version'], '', $realm, $data)]);
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

    private function realm(ServerRequestInterface $request): string
    {
        return match ((string) $request->getAttribute('type.route', '')) {
            'admin.devices', 'admin.device', 'admin.device-change' => 'admin',
            'customer.devices', 'customer.device', 'customer.device-change' => 'customer',
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
