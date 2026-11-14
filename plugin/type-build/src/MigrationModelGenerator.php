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
     * @return array{class: string, path: string}|null
     */
    public static function locate(string $root, string $table): ?array
    {
        $root = rtrim($root, '/');
        $rules = [
            'platform_mqtt_' => ['app\\platform\\mqtt\\model\\', $root . '/app/platform/mqtt/model/'],
            'system_mqtt_' => ['app\\system\\model\\', $root . '/app/system/model/'],
            'platform_' => ['app\\platform\\model\\', $root . '/app/platform/model/'],
            'system_' => ['app\\system\\model\\', $root . '/app/system/model/'],
            'center_' => ['app\\center\\model\\', $root . '/app/center/model/'],
        ];
        foreach ($rules as $prefix => [$namespace, $directory]) {
            if (!str_starts_with($table, $prefix)) {
                continue;
            }
            $class = self::studly(substr($table, strlen($prefix)));
            if ($prefix === 'system_mqtt_') {
                $class = 'Mqtt' . $class;
            }
            return ['class' => $namespace . $class, 'path' => $directory . $class . '.php'];
        }
        return null;
    }

    private static function studly(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
    }
}
