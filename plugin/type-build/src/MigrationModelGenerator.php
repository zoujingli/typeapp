<?php

declare(strict_types=1);

namespace Type\Build;

/**
 * 按迁移收集到的表结构生成 ThinkORM 风格模型。
 *
 * 已有同表模型不覆盖。复合主键等不能安全映射的表记入 skipped，不写半个文件。
 */
final class MigrationModelGenerator
{
    /**
     * 业务表前缀与模型属性模块的唯一映射；最长前缀必须优先匹配。
     *
     * @var list<array{prefix:string,module:string,namespace:string,directory:string,classPrefix:string}>
     */
    private const TABLE_MODULES = [
        ['prefix' => 'platform_mqtt_', 'module' => 'platform.mqtt', 'namespace' => 'app\\platform\\mqtt\\model\\', 'directory' => 'app/platform/mqtt/model/', 'classPrefix' => ''],
        ['prefix' => 'system_mqtt_', 'module' => 'system.mqtt', 'namespace' => 'app\\system\\model\\', 'directory' => 'app/system/model/', 'classPrefix' => 'Mqtt'],
        ['prefix' => 'platform_', 'module' => 'platform', 'namespace' => 'app\\platform\\model\\', 'directory' => 'app/platform/model/', 'classPrefix' => ''],
        ['prefix' => 'system_', 'module' => 'system', 'namespace' => 'app\\system\\model\\', 'directory' => 'app/system/model/', 'classPrefix' => ''],
        ['prefix' => 'center_', 'module' => 'center', 'namespace' => 'app\\center\\model\\', 'directory' => 'app/center/model/', 'classPrefix' => ''],
    ];

    /**
     * @param list<array{table: string, comment: string, columns: list<array<string, mixed>>}> $structures
     * @param array<string, true> $occupied 已经有模型的表
     * @return array{files: list<array{table: string, path: string, code: string}>, skipped: array<string, string>}
     */
    public function render(string $root, array $structures, array $occupied): array
    {
        $files = [];
        $skipped = [];
        $synchronizer = new ModelSchemaSynchronizer();
        foreach ($structures as $structure) {
            $table = $structure['table'];
            if (isset($occupied[$table])) {
                continue;
            }
            $location = self::locate($root, $table);
            if ($location === null) {
                $skipped[$table] = '没有对应的模型目录';
                continue;
            }
            if (is_file($location['path'])) {
                $skipped[$table] = '目标文件已存在';
                continue;
            }
            try {
                $files[] = ['table' => $table, 'path' => $location['path'], 'code' => $synchronizer->synchronize($location['class'], $structure)['code']];
            } catch (\RuntimeException $error) {
                $skipped[$table] = $error->getMessage();
            }
        }
        return ['files' => $files, 'skipped' => $skipped];
    }

    /**
     * 扫描已有模型声明，避免为同一张表再生成一个类。
     *
     * @return array<string, true>
     */
    public function occupied(string $directory): array
    {
        $tables = [];
        if (!is_dir($directory)) {
            return $tables;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string)file_get_contents($file->getPathname());
            if (preg_match_all("/protected\\s+string\\s+\\\$table\\s*=\\s*'([a-z][a-z0-9_]*)'/", $source, $matches) !== false) {
                foreach ($matches[1] as $table) {
                    $tables[$table] = true;
                }
            }
        }
        return $tables;
    }

    /**
     * Platform、System、Center 与 MQTT 表落到现有模型命名空间。
     *
     * @return array{module:string,class:string,path:string}|null
     */
    public static function locate(string $root, string $table): ?array
    {
        $root = rtrim($root, '/');
        foreach (self::TABLE_MODULES as $rule) {
            if (!str_starts_with($table, $rule['prefix'])) {
                continue;
            }
            $class = $rule['classPrefix'] . self::studly(substr($table, strlen($rule['prefix'])));
            return ['module' => $rule['module'], 'class' => $rule['namespace'] . $class,
                'path' => $root . '/' . $rule['directory'] . $class . '.php'];
        }
        return null;
    }

    /** 返回表前缀对应的属性模块，未知业务表不猜测归属。 */
    public static function module(string $table): ?string
    {
        foreach (self::TABLE_MODULES as $rule) {
            if (str_starts_with($table, $rule['prefix'])) {
                return $rule['module'];
            }
        }
        return null;
    }

    private static function studly(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
    }
}
