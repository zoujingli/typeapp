<?php

declare(strict_types=1);

namespace Type\Build;

use Type\Orm\Migration\MigrationFiles;

/**
 * 构建期读取迁移脚本并生成静态三库 Migration 工厂。
 * 生产代码只加载生成结果，不执行迁移目录内的 PHP 文件。
 */
final class MigrationCompiler
{
    /**
     * @return array{code:string,files:list<string>,versions:list<string>}
     */
    public function compile(string $root, string $directory): array
    {
        $path = (new BuildPlatform())->absolute($directory) ? $directory : $root . '/' . ltrim($directory, '/');
        $resolved = BuildPlatform::resolve($path);
        $migrations = MigrationFiles::load($resolved);
        if ($migrations === []) {
            throw new \RuntimeException('TYPE_MIGRATION_INVALID：迁移目录不能为空');
        }
        $drivers = ['mysql', 'pgsql', 'sqlite'];
        $plans = array_fill_keys($drivers, []);
        foreach ($migrations as $migration) {
            foreach ($drivers as $driver) {
                $compiled = $migration->compile($driver);
                $plans[$driver][] = [
                    'version' => $compiled->version(), 'description' => $compiled->description(),
                    'statements' => $compiled->statements(), 'transactional' => $compiled->transactional(),
                    'down' => $compiled->downStatements(),
                ];
            }
        }
        $code = "<?php\n\ndeclare(strict_types=1);\n\nnamespace app\\generated;\n\nuse Type\\Orm\\Migration\\Migration;\n\n/** 构建期冻结的应用迁移计划；运行时不读取迁移 PHP 文件。 */\nfinal class Migrations\n{\n    public static function all(string \$driver): array\n    {\n        return match (\$driver) {\n";
        foreach ($plans as $driver => $plan) {
            $code .= '            ' . var_export($driver, true) . " => [\n";
            foreach ($plan as $migration) {
                $code .= '                new Migration(' . var_export($migration['version'], true) . ', '
                    . var_export($migration['description'], true) . ', ' . var_export($migration['statements'], true) . ', '
                    . ($migration['transactional'] ? 'true' : 'false') . ', ' . var_export($migration['down'], true) . "),\n";
            }
            $code .= "            ],\n";
        }
        $code .= "            default => throw new \\InvalidArgumentException('TYPE_MIGRATION_UNSUPPORTED：未知迁移驱动'),\n        };\n    }\n}\n";
        return ['code' => $code, 'files' => array_keys((new BuildIdentity())->files([$resolved], ['php'])),
            'versions' => array_map(static fn (array $migration): string => $migration['version'], $plans['sqlite'])];
    }
}
