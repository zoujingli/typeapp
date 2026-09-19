<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 构建、开发和诊断共用的显式项目归属，不向上猜测其他项目。 */
final class BuildProject
{
    /** @return array{file:string, root:string, settings:array} */
    public function read(string $configuration): array
    {
        $configuration = BuildPlatform::resolve($configuration);
        $settings = json_decode((string) file_get_contents($configuration), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($settings)) {
            throw new RuntimeException('应用构建配置必须是对象');
        }
        (new ModelCompiler())->assertConfiguration($settings);
        return ['file' => $configuration, 'root' => $this->root($configuration, $settings), 'settings' => $settings];
    }

    /** 按原有project-root约束解析配置所属的Composer项目。 */
    public function root(string $configuration, array $settings): string
    {
        if (!array_key_exists('project-root', $settings)) {
            return dirname($configuration);
        }
        $relative = $settings['project-root'];
        if (!is_string($relative) || $relative === '' || str_starts_with($relative, '/')
            || preg_match('/[\x00-\x1f\x7f\\\\]/', $relative) || str_contains($relative, '://')) {
            throw new RuntimeException('project-root 必须是相对配置文件的有效目录');
        }
        $root = realpath(dirname($configuration) . '/' . $relative);
        if ($root === false || !is_dir($root) || !BuildPlatform::contains($root, $configuration) || !is_file($root . '/composer.json')) {
            throw new RuntimeException('project-root 必须包含当前配置和所属项目的 composer.json');
        }
        return BuildPlatform::path($root);
    }
}
