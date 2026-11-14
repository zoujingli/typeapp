<?php

declare(strict_types=1);

namespace app\system\controller;

use app\common\input\InputFields;
use app\common\middleware\TenantContext;
use app\common\service\AuditLog;
use app\common\service\RoleService;
use app\common\service\SiteSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Orm\Db;
use Type\Validate\Field;

/** 当前客户品牌和默认界面配置独立维护；平台只能维护公共登录与新客户初始模板。 */
final class SystemController
{
    public function __construct(private Factory $messages) {}

    #[Route('/system/site', name: 'system.site.read', middleware: ['system.auth'])]
    #[Route('/platform/system-defaults', name: 'platform.system-defaults.read', middleware: ['platform.auth'])]
    public function read(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        $realm = (string)$identity->attributes()['realm'];
        RoleService::readContext($identity, $realm, $realm === 'system' ? TenantContext::id($request) : '', $realm === 'system' ? 'system.site.read' : 'platform.defaults.read');
        return $this->response(SiteSettings::managementView($realm === 'system' ? 'system' : 'system-default'));
    }

    #[Route('/system/site', methods: ['PUT'], name: 'system.site.manage', middleware: ['system.auth', 'body.json'])]
    #[Route('/platform/system-defaults', methods: ['PUT'], name: 'platform.system-defaults.manage', middleware: ['platform.auth', 'body.json'])]
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        $realm = (string)$identity->attributes()['realm'];
        $settingsRealm = $realm === 'system' ? 'system' : 'system-default';
        $input = InputFields::body($request, ['version' => Field::integer()->required()->range(1, PHP_INT_MAX), 'changes' => new Field('object')], 'site_settings_input_invalid');
        $changes = $input['changes'] instanceof \stdClass ? get_object_vars($input['changes']) : $input['changes'];
        $result = RoleService::authorized(
            $identity,
            substr($request->getHeaderLine('Authorization'), 7),
            $realm === 'system' ? 'system.site.manage' : 'platform.defaults.manage',
            static function (Identity $actor, array $permissions) use ($input, $changes, $realm, $settingsRealm): array {
                $result = SiteSettings::update($input['version'], $changes, $settingsRealm);
                AuditLog::append(
                    Db::connection('default', true),
                    null,
                    $actor,
                    $realm . '.site.update',
                    'system_setting',
                    'success',
                    ['changed_fields' => implode(',', $result['changed']), 'version' => $result['version'], 'context' => $settingsRealm],
                    $realm
                );
                return $result;
            },
            $realm,
            $realm === 'system' ? TenantContext::id($request) : 'platform'
        );
        return $this->response($result);
    }

    private function identity(ServerRequestInterface $request): Identity
    {
        $identity = $request->getAttribute('type.identity');
        if (!$identity instanceof Identity) {
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
