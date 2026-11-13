<?php

declare(strict_types=1);

namespace app\platform\controller;

use app\common\input\InputFields;
use app\platform\service\TenantService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Group;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Validate\Field;
use Type\Validate\Input;

/** 客户目录、开通与系统超管管理，平台身份不能直接变成客户身份。 */
#[Group(prefix: '/platform', namePrefix: 'platform.tenants.', middleware: ['platform.auth'])]
final class TenantController
{
    public function __construct(private Factory $messages) {}

    #[Route('/tenants', name: 'read')]
    #[Route('/tenants/{id}', name: 'detail', constraints: ['id' => '[a-f0-9]{32}'])]
    public function listing(ServerRequestInterface $request): ResponseInterface
    {
        $input = (new Input([]))->withQuery($request->getUri()->getQuery());
        InputFields::only($input, 'query', ['search', 'enabled', 'page', 'per_page'], 'tenant_query_invalid');
        $filters = \_vali(['search' => Field::text()->length(0, 100)->defaultValue('')->from('query'),
            'enabled' => Field::integer()->cast()->range(-1, 1)->defaultValue(-1)->from('query'),
            'page' => Field::integer()->cast()->range(1, 100000)->defaultValue(1)->from('query'),
            'per_page' => Field::integer()->cast()->range(1, 100)->defaultValue(20)->from('query')], $input);
        return $this->response(TenantService::listing($this->identity($request), $filters, $request->getAttribute('type.route.params', [])['id'] ?? ''));
    }

    #[Route('/tenants', methods: ['POST'], name: 'create')]
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $data = InputFields::body($request, ['request_id' => Field::text()->required()->matches('/^[a-f0-9]{32}$/D'),
            'name' => Field::text()->required()->trim()->length(1, 100), 'subdomain' => Field::text()->nullable()->length(1, 63)->defaultValue(null),
            'login' => Field::text()->required()->matches('/^[a-z0-9][a-z0-9_.@-]{2,99}$/D'),
            'owner_name' => Field::text()->required()->trim()->length(1, 100)]);
        return $this->response(['data' => TenantService::create($this->identity($request), $data)]);
    }

    #[Route('/tenants/{id}', methods: ['PATCH'], name: 'update', constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/tenants/{id}/status', methods: ['POST'], name: 'status', constraints: ['id' => '[a-f0-9]{32}'])]
    public function change(string $id, ServerRequestInterface $request): ResponseInterface
    {
        $status = $request->getAttribute('type.route') === 'platform.tenants.status';
        $fields = ['version' => Field::integer()->required()->range(1, 2147483646)];
        $fields += $status ? ['enabled' => Field::boolean()->required()]
            : ['name' => Field::text()->required()->trim()->length(1, 100), 'subdomain' => Field::text()->nullable()->length(1, 63)->required()];
        return $this->response(['data' => TenantService::change($this->identity($request), $id, $status ? 'status' : 'update', InputFields::body($request, $fields))]);
    }

    #[Route('/tenants/{id}/administrators', methods: ['POST'], name: 'administrators.add', constraints: ['id' => '[a-f0-9]{32}'])]
    #[Route('/tenants/{id}/administrators/{user}/{action}', methods: ['POST'], name: 'administrators.change', constraints: ['id' => '[a-f0-9]{32}', 'user' => '[a-f0-9]{32}', 'action' => 'password|replace|remove|status'])]
    public function administrator(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getAttribute('type.route.params', []);
        $action = $params['action'] ?? 'add';
        $fields = ['version' => Field::integer()->required()->range(1, 2147483646)];
        if ($action !== 'add') {
            $fields['user_version'] = Field::integer()->required()->range(1, 2147483646);
        }
        if ($action === 'add' || $action === 'replace') {
            $fields += ['login' => Field::text()->required()->matches('/^[a-z0-9][a-z0-9_.@-]{2,99}$/D'), 'name' => Field::text()->required()->trim()->length(1, 100)];
        }
        if ($action === 'status') {
            $fields['enabled'] = Field::boolean()->required();
        }
        return $this->response(['data' => TenantService::administrator($this->identity($request), $params['id'], $action, $params['user'] ?? '', InputFields::body($request, $fields))]);
    }

    #[Route('/tenants/{id}/administrators', name: 'administrators.read', constraints: ['id' => '[a-f0-9]{32}'])]
    public function administrators(string $id, ServerRequestInterface $request): ResponseInterface
    {
        $input = (new Input([]))->withQuery($request->getUri()->getQuery());
        InputFields::only($input, 'query', ['page', 'per_page'], 'tenant_query_invalid');
        $data = \_vali(['page' => Field::integer()->cast()->range(1, 100000)->defaultValue(1)->from('query'),
            'per_page' => Field::integer()->cast()->range(1, 100)->defaultValue(20)->from('query')], $input);
        return $this->response(TenantService::administratorDirectory($this->identity($request), $id, $data['page'], $data['per_page']));
    }

    private function identity(ServerRequestInterface $request): Identity
    {
        $identity = $request->getAttribute('type.identity');
        if (!$identity instanceof Identity || ($identity->attributes()['realm'] ?? '') !== 'platform') {
            throw new HttpError(401, 'unauthenticated');
        }
        return $identity;
    }

    private function response(array $data): ResponseInterface
    {
        return $this->messages->createResponse(200)->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')->withBody($this->messages->createStream(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
