<?php

declare(strict_types=1);

namespace app\platform\controller;

use app\common\input\InputFields;
use app\platform\service\ImpersonationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Validate\Field;

/** 交接码仅经 JSON 传输，由目标入口兑换独立的 System 会话。 */
final class ImpersonationController
{
    public function __construct(private Factory $messages, private ImpersonationService $service) {}

    #[Route('/platform/tenants/{id}/impersonate', methods: ['POST'], name: 'platform.tenants.impersonate', middleware: ['platform.auth'], constraints: ['id' => '[a-f0-9]{32}'])]
    public function issue(string $id, ServerRequestInterface $request): ResponseInterface
    {
        $identity = $request->getAttribute('type.identity');
        if (!$identity instanceof Identity) {
            throw new HttpError(401, 'unauthenticated');
        }
        $data = InputFields::body($request, ['version' => Field::integer()->required()->range(1, 2147483646),
            'user_id' => Field::text()->required()->matches('/^[a-f0-9]{32}$/D'),
            'user_version' => Field::integer()->required()->range(1, 2147483646),
            'entry' => Field::text()->required()->oneOf(['path', 'subdomain'])]);
        return $this->response($this->service->issue($identity, $id, $data));
    }

    #[Route('/system/auth/handoff', methods: ['POST'], name: 'system.handoff')]
    public function consume(ServerRequestInterface $request): ResponseInterface
    {
        $data = InputFields::body($request, ['code' => Field::text()->required()->matches('/^[a-f0-9]{64}$/D')]);
        return $this->response($this->service->consume($request, $data['code']));
    }

    private function response(array $data): ResponseInterface
    {
        return $this->messages->createResponse(200)->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')->withBody($this->messages->createStream(json_encode(['data' => $data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
