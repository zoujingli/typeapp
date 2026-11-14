<?php

declare(strict_types=1);

namespace app\platform\controller;

use app\platform\service\ImpersonationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;

/**
 * 交接码仅经 JSON 传输，由目标入口兑换独立的 System 会话。
 */
final class ImpersonationController
{
    /**
     * 保存响应工厂和交接服务。构造不签发交接码。
     */
    public function __construct(private Factory $messages, private ImpersonationService $service) {}

    #[Route('/platform/tenants/{id}/impersonate', methods: ['POST'], name: 'platform.tenants.impersonate', middleware: ['platform.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    /**
     * 平台身份为指定客户账号签发一次性交接码。
     */
    public function issue(string $id, ServerRequestInterface $request): ResponseInterface
    {
        $identity = $request->getAttribute('type.identity');
        if (!$identity instanceof Identity) {
            throw new HttpError(401, 'unauthenticated');
        }
        $data = \_vali([
            ..._version(),
            'user_id.required' => '账号不能为空',
            'user_id.matches:/^[a-f0-9]{32}$/D' => '账号标识不正确',
            ..._version('user_version', 1, '账号版本'),
            'entry.required' => '入口不能为空',
            'entry.in:path,subdomain' => '入口不正确',
        ], 'body');
        return $this->response($this->service->issue($identity, $id, $data));
    }

    #[Route('/system/auth/handoff', methods: ['POST'], name: 'system.handoff')]
    /**
     * 目标入口用交接码兑换独立的系统会话，不接受平台令牌。
     */
    public function consume(ServerRequestInterface $request): ResponseInterface
    {
        $data = \_vali([
            'code.required' => '交接码不能为空',
            'code.matches:/^[a-f0-9]{64}$/D' => '交接码不正确',
        ], 'body');
        return $this->response($this->service->consume($request, $data['code']));
    }

    private function response(array $data): ResponseInterface
    {
        return $this->messages->createResponse(200)->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')->withBody($this->messages->createStream(json_encode(['data' => $data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
