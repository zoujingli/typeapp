<?php

declare(strict_types=1);

namespace app\center\model;

use Type\Orm\Model;

/**
 * 平台发起的使用关系终止或转配；双方可见性由服务按来源及目标客户显式限制。
 *
 * @property string $id 记录的稳定标识。
 * @property string $device_id 设备的稳定标识。
 * @property string $device_name 发起流程时保留的设备名称。
 * @property string $source_tenant_id 流程来源 Tenant 标识。
 * @property null|string $target_tenant_id 本次操作的目标 Tenant 标识。
 * @property string $ownership_id 授权或操作绑定的设备使用关系。
 * @property null|string $new_ownership_id 目标客户确认后建立的新使用关系标识。
 * @property string $kind 业务记录类型。
 * @property string $status 流程的实际处理阶段。
 * @property int $request_version 发起流程时核对的预期版本。
 * @property string $source_context 发起操作的身份与授权快照。
 * @property bool $requires_store 是否必须取得持久存储隔离证明。
 * @property null|string $isolation_id 关联的通信隔离操作标识。
 * @property null|int $access_version 通信授权发布代次。
 * @property string $retained_json 旧归属阶段保留消息核验结果的 JSON。
 * @property null|string $accept_id 目标接收请求的幂等标识。
 * @property null|int $accept_version 接收请求核对的预期版本。
 * @property null|string $accept_context 目标客户接收操作的身份快照。
 * @property-read int $version 资料并发版本。
 * @property-read int $created_at 创建时间，UTC Unix 秒。
 * @property-read int $updated_at 最后更新时间，UTC Unix 秒。
 * @method static \Type\Orm\ModelQuery<static> query(string $alias = '')
 * @method static static|null find(int|string $id)
 * @method static static create(array $values)
 * @method static static firstOrCreate(array $identity, array $values = [])
 */
final class DeviceReassignment extends Model
{
    protected string $table = 'center_device_reassignment';

    protected bool $autoIncrement = false;

    protected ?string $versionKey = 'version';

    protected ?string $createTime = 'created_at';

    protected ?string $updateTime = 'updated_at';

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '记录的稳定标识。'],
        'device_id' => ['type' => 'string', 'comment' => '设备的稳定标识。'],
        'device_name' => ['type' => 'string', 'comment' => '发起流程时保留的设备名称。'],
        'source_tenant_id' => ['type' => 'string', 'comment' => '流程来源 Tenant 标识。'],
        'target_tenant_id' => ['type' => 'string', 'nullable' => true, 'comment' => '本次操作的目标 Tenant 标识。'],
        'ownership_id' => ['type' => 'string', 'comment' => '授权或操作绑定的设备使用关系。'],
        'new_ownership_id' => ['type' => 'string', 'nullable' => true, 'comment' => '目标客户确认后建立的新使用关系标识。'],
        'kind' => ['type' => 'string', 'comment' => '业务记录类型。'],
        'status' => ['type' => 'string', 'comment' => '流程的实际处理阶段。'],
        'request_version' => ['type' => 'integer', 'comment' => '发起流程时核对的预期版本。'],
        'source_context' => ['type' => 'string', 'visible' => false, 'comment' => '发起操作的身份与授权快照。'],
        'requires_store' => ['type' => 'boolean', 'comment' => '是否必须取得持久存储隔离证明。'],
        'isolation_id' => ['type' => 'string', 'nullable' => true, 'comment' => '关联的通信隔离操作标识。'],
        'access_version' => ['type' => 'integer', 'nullable' => true, 'comment' => '通信授权发布代次。'],
        'retained_json' => ['type' => 'string', 'visible' => false, 'comment' => '旧归属阶段保留消息核验结果的 JSON。'],
        'accept_id' => ['type' => 'string', 'nullable' => true, 'comment' => '目标接收请求的幂等标识。'],
        'accept_version' => ['type' => 'integer', 'nullable' => true, 'comment' => '接收请求核对的预期版本。'],
        'accept_context' => ['type' => 'string', 'nullable' => true, 'visible' => false, 'comment' => '目标客户接收操作的身份快照。'],
        'version' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '资料并发版本。'],
        'created_at' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '创建时间，UTC Unix 秒。'],
        'updated_at' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '最后更新时间，UTC Unix 秒。'],
    ];
}
