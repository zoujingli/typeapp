<?php

declare(strict_types=1);

namespace app\common\model;

use Type\Orm\Attribute\Column;
use Type\Orm\Attribute\HasMany;
use Type\Orm\Attribute\Table;
use Type\Orm\Model;

/** 客户全局账号；租户内成员资料由 CustomerMember 承载。 */
#[Table('customer_users', generatedPrimary: false)]
final class CustomerUser extends Model
{
    public string $id;
    public string $login;
    public string $name;

    #[Column(visible: false)]
    public string $password_hash;

    public bool $enabled;

    /** 资料版本由身份服务在授权锁内维护，登录计数不属于资料变更。 */
    public int $version;

    public int $failures;
    public int $locked_until;
    public bool $recovery_verified;
    public int $created_at;

    /** @var list<CustomerMember> */
    #[HasMany(CustomerMember::class, foreignKey: 'user_id')]
    public array $memberships;

    /** @var list<CustomerSession> */
    #[HasMany(CustomerSession::class, foreignKey: 'user_id')]
    public array $sessions;
}
