<?php

declare(strict_types=1);

namespace Type\Orm\Migration;

/**
 * 从目录加载迁移脚本。
 *
 * 文件名就是版本，例如 `020_platform_system.php`。文件 return 匿名 SchemaMigration，不声明具名类，也不需要登记到清单。
 */
final class MigrationFiles
{
    /**
     * @return list<SchemaMigration>
     */
    public static function load(string $directory): array
    {
        $root = realpath($directory);
        if ($root === false || !is_dir($root)) {
            throw new MigrationException('TYPE_MIGRATION_INVALID：迁移目录不存在');
        }
        $paths = glob($root . '/*.php');
        if ($paths === false) {
            throw new MigrationException('TYPE_MIGRATION_INVALID：无法读取迁移目录');
        }
        sort($paths, SORT_STRING);
        $migrations = [];
        foreach ($paths as $path) {
            if (is_link($path)) {
                throw new MigrationException('TYPE_MIGRATION_INVALID：迁移脚本不能是符号链接');
            }
            $file = realpath($path);
            if ($file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
                throw new MigrationException('TYPE_MIGRATION_INVALID：迁移脚本越出目录');
            }
            $version = pathinfo($file, PATHINFO_FILENAME);
            $migration = self::read($file);
            if (!$migration instanceof SchemaMigration || !(new \ReflectionObject($migration))->isAnonymous()) {
                throw new MigrationException('TYPE_MIGRATION_INVALID：迁移脚本必须 return 匿名 SchemaMigration：' . $version);
            }
            $declared = (new \ReflectionObject($migration))->getMethod('version')->getDeclaringClass();
            if ($declared->getName() !== SchemaMigration::class) {
                throw new MigrationException('TYPE_MIGRATION_INVALID：迁移版本由文件名决定，脚本不要声明 version()：' . $version);
            }
            $migration->bindVersion($version);
            $migrations[] = $migration;
        }
        return $migrations;
    }

    private static function read(string $file): mixed
    {
        return (static function (string $script): mixed {
            return include $script;
        })($file);
    }
}
