<?php

declare(strict_types=1);

namespace app\center\controller;

use app\center\service\DeviceService;
use app\common\middleware\TenantContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\CanonicalRequest;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;

/**
 * 双端设备入口共用状态机；身份域由编译登记的路由决定，不接受请求指定域或归属。
 */
final class DeviceController
{
    /**
     * 复用请求资源、消息工厂与设备持久所有者，构造不连接数据库。
     */
    public function __construct(private Factory $messages, private DeviceService $devices) {}

    /**
     * 设备以独立注册码建立接入身份；不接受人员会话代替资格，幂等重试不回显旧凭据。
     */
    #[Route('/public/devices/register', methods: ['POST'], name: 'device.registration')]
    public function enroll(ServerRequestInterface $request): ResponseInterface
    {
        $data = $this->payload($request, [
            'id.required' => '请求标识不能为空',
            'id.matches:/^[a-f0-9]{32}$/D' => '请求标识不正确',
            'tenant_id.required' => '客户标识不能为空',
            'tenant_id.matches:/^[a-f0-9]{32}$/D' => '客户标识不正确',
            'device_id.required' => '设备标识不能为空',
            'device_id.matches:/^[a-f0-9]{32}$/D' => '设备标识不正确',
            'name.required' => '名称不能为空',
            'name.trim' => '名称需要去除空白',
            'name.length:1,100' => '名称长度不正确',
            'code.required' => '注册码不能为空',
            'code.matches:/^[a-f0-9]{32}\.[a-f0-9]{64}$/D' => '注册码不正确',
        ]);
        $canonical = $request->getAttribute('type.request');
        if (!$canonical instanceof CanonicalRequest) {
            throw new HttpError(400, 'invalid_peer');
        }
        $result = $this->devices->enroll($data, $canonical->clientIp());
        return $this->response($result['replayed'] ? 200 : 201, ['data' => $result]);
    }

    /**
     * 客户查询当前租户，平台可按归属筛选全局资产；列表不附带模型内容或遥测。
     */
    #[Route('/center/devices', name: 'center.devices', middleware: ['system.auth'])]
    #[Route('/platform/devices', name: 'platform.devices', middleware: ['platform.auth'])]
    public function devices(ServerRequestInterface $request): ResponseInterface
    {
        $realm = $this->realm($request);
        $tenantId = $this->tenant($request, $realm);
        $rules = [
            'name.length:0,100' => '名称长度不正确',
            'product_id.matches:/^([a-f0-9]{32})?$/D' => '分类标识不正确',
            'lifecycle.in:,inactive,enabled,disabled,retired' => '生命周期不正确',
            'page.default' => 1,
            'page.integer' => '页码不正确',
            'page.between:1,100000' => '页码超出范围',
            'per_page.default' => 20,
            'per_page.integer' => '每页数量不正确',
            'per_page.between:1,100' => '每页数量超出范围',
        ];
        if ($realm === 'platform') {
            $rules['tenant_id.matches:/^([a-f0-9]{32})?$/D'] = '客户标识不正确';
        }
        $query = \_vali($rules, 'query');
        return $this->response(200, $this->devices->devices($this->identity($request), $tenantId, $query['page'], $query['per_page'], $query, $realm));
    }

    /**
     * 详情采用当前身份域投影；资料修改只接受名称及同客户分类，不能更换归属或生命周期。
     */
    #[Route('/center/devices/{device}', methods: ['GET', 'PATCH'], name: 'center.device', middleware: ['system.auth'], constraints: ['device' => '[a-f0-9]{32}'])]
    #[Route('/platform/devices/{device}', methods: ['GET', 'PATCH'], name: 'platform.device', middleware: ['platform.auth'], constraints: ['device' => '[a-f0-9]{32}'])]
    public function device(ServerRequestInterface $request): ResponseInterface
    {
        $realm = $this->realm($request);
        $tenantId = $this->tenant($request, $realm);
        $parameters = $request->getAttribute('type.route.params', []);
        $identity = $this->identity($request);
        if ($request->getMethod() === 'GET') {
            return $this->response(200, ['data' => $this->devices->device($identity, $tenantId, $parameters['device'], $realm)]);
        }
        $data = $this->payload($request, [
            ..._version(),
            'name.required' => '名称不能为空',
            'name.trim' => '名称需要去除空白',
            'name.length:1,100' => '名称长度不正确',
            'product_id.nullable' => '分类可以为空',
            'product_id.matches:/^[a-f0-9]{32}$/D' => '分类标识不正确',
        ]);
        return $this->response(200, ['data' => $this->devices->change($identity, $tenantId, $parameters['device'], 'update', $data['version'], '', $realm, $data)]);
    }

    /**
     * 202只表示撤权已提交；网络隔离的真实完成由Broker回写，不能用HTTP成功代替。
     */
    #[Route('/center/devices/{device}/{action}', methods: ['POST'], name: 'center.device-change', middleware: ['system.auth'], constraints: ['device' => '[a-f0-9]{32}', 'action' => 'rotate|revoke|disable|enable|retire'])]
    #[Route('/platform/devices/{device}/{action}', methods: ['POST'], name: 'platform.device-change', middleware: ['platform.auth'], constraints: ['device' => '[a-f0-9]{32}', 'action' => 'rotate|revoke|disable|enable|retire'])]
    public function change(ServerRequestInterface $request): ResponseInterface
    {
        $realm = $this->realm($request);
        $tenantId = $this->tenant($request, $realm);
        $parameters = $request->getAttribute('type.route.params', []);
        $data = $this->payload($request, [
            ..._version(),
            'confirm_device_id.required' => '确认标识不能为空',
            'confirm_device_id.matches:/^[a-f0-9]{32}$/D' => '确认标识不正确',
        ]);
        $result = $this->devices->change($this->identity($request), $tenantId, $parameters['device'], $parameters['action'], $data['version'], $data['confirm_device_id'], $realm);
        return $this->response($result['device']['authorization']['status'] === 'pending' ? 202 : 200, ['data' => $result]);
    }

    private function realm(ServerRequestInterface $request): string
    {
        return match ((string)$request->getAttribute('type.route', '')) {
            'platform.devices', 'platform.device', 'platform.device-change' => 'platform',
            'center.devices', 'center.device', 'center.device-change' => 'system',
            default => throw new HttpError(404, 'not_found'),
        };
    }

    private function tenant(ServerRequestInterface $request, string $realm): string
    {
        if ($realm === 'platform') {
            if ($request->hasHeader('X-Tenant-Id')) {
                throw new HttpError(403, 'identity_context_invalid');
            }
            return '';
        }
        return TenantContext::id($request);
    }

    /**
     * @param array<string, mixed> $rules 字段规则；拒绝伪造归属及状态。
     */
    private function payload(ServerRequestInterface $request, array $rules): array
    {
        if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
            throw new HttpError(415, 'json_required');
        }
        return \_vali($rules, 'body');
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

    private function response(int $status, array $data): ResponseInterface
    {
        return $this->messages->createResponse($status)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store')
            ->withBody($this->messages->createStream(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
