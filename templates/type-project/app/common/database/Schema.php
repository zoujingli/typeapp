<?php

declare(strict_types=1);

namespace app\common\database;

use app\generated\Migrations;
use Type\Orm\Migration\Migration;

/**
 * 应用迁移目录。版本由 database/migrations 的文件名决定。
 */
final class Schema
{
    /** @return list<Migration> */
    public static function migrations(string $driver): array
    {
        if (!in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new \InvalidArgumentException('迁移不支持所选数据库驱动');
        }
        return Migrations::all($driver);
    }
}
