<?php

declare(strict_types=1);

namespace app\platform\model;

use Type\Orm\Model;

/**
 * 全局租户目录；租户内实体的归属由已验证执行上下文自动限定。
 *
 * @property string $id 客户不可变的 32 位小写十六进制标识。
 * @property string $name 业务显示名称。
 * @property null|string $subdomain 登记的客户子域名前缀；未配置时为空。
 * @property string $request_id 创建请求的幂等标识。
 * @property string $request_hash 创建请求内容摘要，用于拒绝幂等标识冲突。
 * @property bool $enabled 当前是否启用。
 * @property-read int $version 资料并发版本。
 * @property-read int $created_at 创建时间，UTC Unix 秒。
 * @method static \Type\Orm\ModelQuery<static> query(string $alias = '')
 * @method static static|null find(int|string $id)
 * @method static static create(array $values)
 * @method static static firstOrCreate(array $identity, array $values = [])
 */
final class Tenant extends Model
{
    protected string $table = 'platform_tenant';

    protected bool $autoIncrement = false;

    protected ?string $versionKey = 'version';

    protected ?string $createTime = 'created_at';

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '客户不可变的 32 位小写十六进制标识。'],
        'name' => ['type' => 'string', 'comment' => '业务显示名称。'],
        'subdomain' => ['type' => 'string', 'nullable' => true, 'comment' => '登记的客户子域名前缀；未配置时为空。'],
        'request_id' => ['type' => 'string', 'visible' => false, 'comment' => '创建请求的幂等标识。'],
        'request_hash' => ['type' => 'string', 'visible' => false, 'comment' => '创建请求内容摘要，用于拒绝幂等标识冲突。'],
        'enabled' => ['type' => 'boolean', 'comment' => '当前是否启用。'],
        'version' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '资料并发版本。'],
        'created_at' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '创建时间，UTC Unix 秒。'],
    ];
}
