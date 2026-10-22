<?php

declare(strict_types=1);

namespace app\iot\model;

use Type\Orm\Attribute\Column;
use Type\Orm\Attribute\Table;
use Type\Orm\Model;

/** 客户外部系统的独立身份；人员会话不参与密钥有效性，当前凭据代次由应用单独约束。 */
#[Table('iot_applications', generatedPrimary: false, version: 'version', createdAt: 'created_at', updatedAt: 'updated_at')]
final class ClientApplication extends Model
{
    public string $id;
    public string $tenant_id;
    public string $name;
    public bool $enabled;
    public int $key_version;
    public int $access_version;

    #[Column(visible: false)]
    public string $credential_id;

    #[Column(visible: false)]
    public string $request_hash;

    /** 已发出有效认证结论，不表示连接在线；防止遗留持久会话被误判为从未使用。 */
    #[Column(visible: false)]
    public bool $access_started;

    public bool $recovery_verified;

    #[Column(fillable: false, required: false)]
    public int $version;

    public int $created_at;
    public int $updated_at;
}
