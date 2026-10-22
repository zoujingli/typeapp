<?php

declare(strict_types=1);

namespace app\iot\database;

use Type\Orm\Migration\Migration;

/** 注册资格纳入恢复核对；旧备份不能重新发放已经停用、换码或消耗的资格。 */
final class AuthorizationRecoverySchema
{
    /** @return list<Migration> 只追加核对标记，不重置注册码、绑定记录或额度。 */
    public static function migrations(string $driver): array
    {
        return [new Migration('109_iot_authorization_recovery', '将注册资格纳入恢复后的授权核对', [
            'ALTER TABLE iot_device_enrollments ADD COLUMN recovery_verified INTEGER NOT NULL DEFAULT 1',
        ], $driver !== 'mysql')];
    }
}
