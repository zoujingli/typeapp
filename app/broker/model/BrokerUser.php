<?php

declare(strict_types=1);

namespace app\broker\model;

use Type\Orm\Attribute\Column;
use Type\Orm\Attribute\Table;
use Type\Orm\Model;

/** Broker 运行账号；保留独立的 platform_admin 运行权限字段。 */
#[Table('broker_users', generatedPrimary: false)]
final class BrokerUser extends Model
{
    public string $id;
    public string $login;
    public string $name;

    #[Column(visible: false)]
    public string $password_hash;

    public bool $platform_admin;
    public bool $enabled;
    public bool $recovery_verified;
    public int $failures;
    public int $locked_until;
    public int $created_at;
}
