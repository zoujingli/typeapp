<?php

declare(strict_types=1);

namespace app\platform\service;

use app\common\bootstrap\ApplicationContext;
use app\common\middleware\TenantContext;
use app\common\service\AuditLog;
use app\common\service\IdentityService;
use app\common\service\RoleService;
use app\platform\model\PlatformHandoff;
use app\platform\model\Tenant;
use app\system\model\SystemUser;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Uri;
use Type\Orm\Db;
use Type\Runtime\ExecutionScope;

/** 平台到客户入口的短期一次性交接；原始平台令牌不离开平台工作区。 */
final class ImpersonationService
{
    public function __construct(private ApplicationContext $context) {}

    /** 目标由登记入口计算，不能提交任意跳转地址；同一来源最多十份待交接记录。 */
    public function issue(Identity $identity, string $tenantId, array $data): array
    {
        return RoleService::authorized($identity, null, 'platform.tenants.impersonate', function (Identity $actor, array $permissions) use ($tenantId, $data): array {
            $tenant = Tenant::query()->master()->find($tenantId);
            if ($tenant === null || !$tenant->enabled) {
                throw new HttpError(403, 'impersonation_target_invalid');
            }
            if ($tenant->version !== $data['version']) {
                throw new HttpError(409, 'stale_version');
            }
            ExecutionScope::current()->run(static function (ExecutionScope $scope) use ($tenantId, $data): void {
                $user = SystemUser::query()->master()->find($data['user_id']);
                if ($user === null || !TenantService::isAdministrator($tenantId, $data['user_id'])) {
                    throw new HttpError(403, 'impersonation_target_invalid');
                }
                if ($user->version !== $data['user_version']) {
                    throw new HttpError(409, 'stale_version');
                }
            }, TenantContext::bindings($tenantId));
            $base = $this->target($tenant, $data['entry']);
            $connection = Db::connection('default', true);
            $attributes = $actor->attributes();
            $now = time();
            $rows = PlatformHandoff::query()->master()->where('source_session_id', '=', $attributes['session_id'])
                ->orderBy('created_at')->orderBy('code_hash')->limit(10)->get();
            foreach ($rows as $index => $row) {
                if ((int)$row->expires_at <= $now || $row->consumed_at !== null || (count($rows) >= 10 && $index === 0)) {
                    $row->delete();
                }
            }
            $code = bin2hex(random_bytes(32));
            $expires = min($now + 60, (int)$attributes['expires_at']);
            PlatformHandoff::create(['code_hash' => hash('sha256', $code), 'target_tenant_id' => $tenantId,
                'user_id' => $data['user_id'], 'user_version' => $data['user_version'], 'tenant_version' => $data['version'],
                'actor_id' => $actor->subject(), 'source_session_id' => $attributes['session_id'], 'target_origin' => $base,
                'expires_at' => $expires, 'consumed_at' => null]);
            AuditLog::append($connection, $tenantId, $actor, 'identity.handoff_issued', $data['user_id'], 'success', ['expires_at' => $expires], 'platform');
            return ['code' => $code, 'target' => $base, 'expiresAt' => $expires];
        }, 'platform', 'platform');
    }

    /** 消费与创建会话原子提交；来源撤销、目标版本变化、地址错配及重放均拒绝。 */
    public function consume(ServerRequestInterface $request, string $code): array
    {
        $tenantId = TenantContext::id($request);
        $connection = Db::connection('default', true);
        return Db::transaction(static function () use ($request, $code, $tenantId): array {
            RoleService::lockAuthorization();
            $transaction = Db::connection('default', true);
            $handoff = PlatformHandoff::query()->master()->find(hash('sha256', $code));
            $uri = $request->getUri()->withPath('')->withQuery('')->withFragment('');
            $base = rtrim((string)$uri, '/') . $request->getAttribute('app.tenant_base', '');
            $tenant = Tenant::query()->master()->find($tenantId);
            $user = SystemUser::query()->master()->find($handoff === null ? '' : (string)$handoff->user_id);
            if ($handoff === null || $handoff->consumed_at !== null || (int)$handoff->expires_at <= time()
                || $handoff->target_tenant_id !== $tenantId || !hash_equals((string)$handoff->target_origin, $base)
                || $tenant === null || !$tenant->enabled || $tenant->version !== (int)$handoff->tenant_version
                || $user === null || $user->version !== (int)$handoff->user_version) {
                throw new HttpError(401, 'impersonation_handoff_invalid');
            }
            $identities = new IdentityService('platform');
            $source = $identities->refresh(new Identity((string)$handoff->actor_id, ['realm:platform'], ['realm' => 'platform', 'session_id' => $handoff->source_session_id]));
            if ($source === null || !in_array('platform.tenants.impersonate', RoleService::permissions($source, 'platform'), true)
                || !TenantService::isAdministrator($tenantId, (string)$handoff->user_id)) {
                throw new HttpError(401, 'impersonation_handoff_invalid');
            }
            $handoff->consumed_at = time();
            $handoff->save();
            return $identities->impersonate($source, $tenantId, (string)$handoff->user_id);
        }, 'default', $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /** 只使用部署配置的公共来源，固定路径或登记子域名二选一。 */
    private function target(Tenant $tenant, string $entry): string
    {
        $origin = rtrim($this->context->settings()->text('app.tenant.public_origin'), '/');
        $parts = parse_url($origin);
        if ($parts === false || !in_array($parts['scheme'] ?? '', ['https', 'http'], true) || ($parts['host'] ?? '') === ''
            || array_diff(array_keys($parts), ['scheme', 'host', 'port']) !== []) {
            throw new HttpError(503, 'public_origin_invalid');
        }
        $uri = new Uri($origin);
        if ($entry === 'path') {
            return (string)$uri->withPath('/s/' . $tenant->id);
        }
        $domain = $this->context->settings()->text('app.tenant.base_domain');
        if ($entry !== 'subdomain' || $domain === '' || $tenant->subdomain === null) {
            throw new HttpError(422, 'tenant_subdomain_unavailable');
        }
        return (string)$uri->withHost($tenant->subdomain . '.' . $domain);
    }
}
