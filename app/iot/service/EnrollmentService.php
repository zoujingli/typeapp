<?php

declare(strict_types=1);

namespace app\iot\service;

use app\common\model\Tenant;
use app\common\service\AuditLog;
use app\common\service\RoleService;
use app\iot\model\DeviceEnrollment;
use Closure;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Orm\Db;
use Type\Runtime\ExecutionScope;

/** 平台签发注册资格的所有者；注册码、设备凭据和人员权限分别管理。 */
final class EnrollmentService
{
    private const PUBLIC_FIELDS = ['id', 'tenant_id', 'name', 'capacity', 'registered_count', 'register_expires_at', 'enabled', 'recovery_verified', 'version', 'created_at', 'updated_at'];

    /**
     * 控制端可查询授权目录，客户端只查询当前已授权客户；任何查询均不返回验证值。
     * @return array<string, mixed> 稳定分页及当前人员权限。
     */
    public function listing(Identity $identity, string $realm, string $tenantId, int $page, int $perPage): array
    {
        $context = RoleService::readContext($identity, $realm, $realm === 'admin' ? '' : $tenantId, 'enrollments.read');
        $context['menus'] = RoleService::menus($realm, $context['permissions']);
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) {
            throw new HttpError(422, 'invalid_pagination');
        }
        if ($realm === 'customer') {
            return ExecutionScope::current()->run(static function (ExecutionScope $scope) use ($page, $perPage, $context): array {
                $result = DeviceEnrollment::query()->master()->orderBy('created_at', 'DESC')->orderBy('id')->paginate($page, $perPage);
                $items = [];
                foreach ($result->items() as $enrollment) {
                    $items[] = self::project($enrollment->project(self::PUBLIC_FIELDS));
                }
                return ['items' => $items, 'total' => $result->total(), 'page' => $page, 'per_page' => $perPage, 'context' => $context];
            }, ['tenant_id' => $tenantId]);
        }
        // 平台目录跨客户，使用明确白名单投影；不能用无租户上下文绕过模型隔离。
        $rows = Db::connection('default', true)->table('iot_device_enrollments')->select(self::PUBLIC_FIELDS);
        if ($tenantId !== '') {
            $rows = $rows->where('tenant_id', '=', $tenantId);
        }
        $total = (int) $rows->aggregate('COUNT');
        $items = $rows->orderBy('created_at', 'DESC')->orderBy('id')->limit($perPage, ($page - 1) * $perPage)->get();
        foreach ($items as $index => $item) {
            $items[$index] = self::project($item);
        }
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'context' => $context];
    }

    /**
     * 详情返回真实注册绑定的有界分页；历史使用关系仍归原资格，不随设备重新分配消失。
     * @return array<string, mixed> 公开授权、绑定分页和本次人员上下文，不含任何验证值。
     */
    public function detail(Identity $identity, string $realm, string $tenantId, string $id, int $page, int $perPage): array
    {
        $context = RoleService::readContext($identity, $realm, $realm === 'admin' ? '' : $tenantId, 'enrollments.read');
        $context['menus'] = RoleService::menus($realm, $context['permissions']);
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) {
            throw new HttpError(422, 'invalid_pagination');
        }
        $rows = Db::connection('default', true)->table('iot_device_enrollments')->select(self::PUBLIC_FIELDS)->where('id', '=', $id);
        $row = ($realm === 'customer' ? $rows->where('tenant_id', '=', $tenantId) : $rows)->first();
        if ($row === null) {
            throw new HttpError(404, 'enrollment_not_found');
        }
        $bindings = Db::connection('default', true)->table('iot_device_enrollment_bindings')
            ->select(['ownership_id', 'device_id', 'registered_at'])->where('enrollment_id', '=', $id);
        $total = (int) $bindings->aggregate('COUNT');
        $items = $bindings->orderBy('registered_at', 'DESC')->orderBy('ownership_id')->limit($perPage, ($page - 1) * $perPage)->get();
        foreach ($items as $index => $item) {
            $items[$index]['registered_at'] = (int) $item['registered_at'];
        }
        return ['authorization' => self::project($row), 'bindings' => ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage], 'context' => $context];
    }

    /**
     * 请求 ID 由调用者保留；同一意图重试不再签发秘密或增加额度，异意图复用 ID 拒绝。
     * @param array{id: string, tenant_id: string, name: string, capacity: int, register_expires_at: int} $data
     * @return array{authorization: array<string, mixed>, code: ?string, replayed: bool}
     * @throws HttpError 客户停用、注册期限已过、请求 ID 冲突或没有签发权限。
     */
    public function issue(Identity $identity, array $data): array
    {
        return $this->write($identity, 'issue', $data['tenant_id'], $data['id'], function (Identity $current) use ($data): array {
            $requestHash = hash('sha256', json_encode([$data['tenant_id'], $data['name'], $data['capacity'], $data['register_expires_at']], JSON_THROW_ON_ERROR));
            $existing = DeviceEnrollment::query()->master()->find($data['id']);
            if ($existing !== null) {
                if (!hash_equals($existing->getRequestHash(), $requestHash)) {
                    throw new HttpError(409, 'enrollment_request_conflict');
                }
                return ['authorization' => self::project($existing->project(self::PUBLIC_FIELDS)), 'code' => null, 'replayed' => true];
            }
            // 同一全局 ID 不能被另一客户重新使用；现有授权写锁序列化并发签发。
            if (Db::connection('default', true)->table('iot_device_enrollments')->where('id', '=', $data['id'])->first() !== null) {
                throw new HttpError(409, 'enrollment_request_conflict');
            }
            if ($data['register_expires_at'] <= time()) {
                throw new HttpError(422, 'enrollment_expired');
            }
            $secret = bin2hex(random_bytes(32));
            $model = DeviceEnrollment::create(['id' => $data['id'], 'name' => $data['name'], 'capacity' => $data['capacity'],
                'registered_count' => 0, 'register_expires_at' => $data['register_expires_at'], 'enabled' => true,
                'secret_hash' => hash('sha256', $secret), 'request_hash' => $requestHash, 'recovery_verified' => true]);
            AuditLog::append(Db::connection('default', true), $data['tenant_id'], $current, 'enrollment.issued', $model->getId(), 'success', ['version' => $model->getVersion()], 'admin');
            return ['authorization' => self::project($model->project(self::PUBLIC_FIELDS)), 'code' => $model->getId() . '.' . $secret, 'replayed' => false];
        });
    }

    /**
     * 停用只禁止新的注册；重置只替换注册码，不重新启用、增加额度或清空已绑定设备。
     * 已注册设备的撤权属于设备生命周期，不把注册期限当作设备使用期限。
     * @return array{authorization: array<string, mixed>, code: ?string}
     */
    public function change(Identity $identity, string $tenantId, string $id, int $version, string $action): array
    {
        if (!in_array($action, ['disable', 'reset'], true)) {
            throw new HttpError(422, 'enrollment_action_invalid');
        }
        return $this->write($identity, $action, $tenantId, $id, static function (Identity $current) use ($tenantId, $id, $version, $action): array {
            $model = DeviceEnrollment::query()->master()->find($id);
            if ($model === null) {
                throw new HttpError(404, 'enrollment_not_found');
            }
            if ($model->getVersion() !== $version || $version >= 2147483646) {
                throw new HttpError(409, 'stale_version');
            }
            $code = null;
            if ($action === 'reset') {
                if (!$model->getRecoveryVerified()) {
                    throw new HttpError(409, 'recovery_reconciliation_required');
                }
                if (!$model->getEnabled() || $model->getRegisterExpiresAt() <= time() || $model->getRegisteredCount() >= $model->getCapacity()) {
                    throw new HttpError(409, 'enrollment_unavailable');
                }
                $secret = bin2hex(random_bytes(32));
                $model->setSecretHash(hash('sha256', $secret));
                $code = $id . '.' . $secret;
            } else {
                $model->setEnabled(false);
            }
            $model->dirty() === [] ? $model->touch() : $model->save();
            AuditLog::append(Db::connection('default', true), $tenantId, $current, 'enrollment.' . $action, $id, 'success', ['version' => $model->getVersion()], 'admin');
            return ['authorization' => self::project($model->project(self::PUBLIC_FIELDS)), 'code' => $code];
        });
    }

    /**
     * 平台权限验证在前，随后在同一事务中绑定目标客户；查询参数不能授予跨客户写权限。
     * @param Closure(Identity): array<string, mixed> $operation 已授权且具备目标租户绑定的操作。
     */
    private function write(Identity $identity, string $action, string $tenantId, string $id, Closure $operation): array
    {
        try {
            return RoleService::authorized($identity, null, 'admin.enrollments.' . $action, static function (Identity $current, array $permissions) use ($tenantId, $action, $operation): array {
                $tenant = Tenant::query()->master()->find($tenantId);
                if ($tenant === null) {
                    throw new HttpError(404, 'tenant_not_found');
                }
                if (!$tenant->getEnabled() && $action !== 'disable') {
                    throw new HttpError(409, 'enrollment_customer_disabled');
                }
                return ExecutionScope::current()->run(static fn (ExecutionScope $scope): array => $operation($current), ['tenant_id' => $tenantId]);
            }, 'admin');
        } catch (HttpError $failure) {
            AuditLog::append(
                Db::connection('default', true),
                $tenantId,
                $identity,
                'enrollment.' . $action,
                $id,
                in_array($failure->status(), [401, 403, 404], true) ? 'denied' : 'failed',
                ['reason' => $failure->errorCode()],
                'admin'
            );
            throw $failure;
        }
    }

    /** 注册资格状态由当前期限推导，不通过 GET 写回数据库或修改设备状态。 */
    private static function project(array $row): array
    {
        foreach (['capacity', 'registered_count', 'register_expires_at', 'version', 'created_at', 'updated_at'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        $row['enabled'] = (bool) $row['enabled'];
        $row['recovery_verified'] = (bool) $row['recovery_verified'];
        $row['remaining'] = $row['capacity'] - $row['registered_count'];
        $row['status'] = !$row['recovery_verified'] ? 'unverified' : (!$row['enabled'] ? 'disabled' : ($row['register_expires_at'] <= time() ? 'expired' : ($row['remaining'] === 0 ? 'exhausted' : 'available')));
        return $row;
    }
}
