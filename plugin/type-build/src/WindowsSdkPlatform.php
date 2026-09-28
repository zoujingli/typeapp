<?php

declare(strict_types=1);

namespace Type\Build;

use TypePhp\Platform\Windows;

/** 锁定 Windows SDK 布局；静态目标验证归档，开发动态目标保留上游 DLL 检查。 */
final class WindowsSdkPlatform extends Windows
{
    /**
     * 沿用上游编译检查，再按官方 SDK 布局核验 PHP/PHPX 的真实 PE 运行库。
     * @return list<array<string, string>> 构建警告与需要补齐的运行库说明。
     */
    public function getBuildLibraryWarnings(string $phpDir, string $phpxDir, string $buildMode, bool $checkPhpxRuntime = true): array
    {
        if (StaticRuntimeSdk::selected() !== null) {
            if ($buildMode !== 'bin') {
                return [['error' => 'Windows 静态 SDK 只用于完整主程序', 'info' => '扩展或共享库继续使用对应动态 SDK']];
            }
            return [];
        }
        $messages = parent::getBuildLibraryWarnings($phpDir, $phpxDir, $buildMode, false);
        if ($checkPhpxRuntime) {
            try {
                foreach ((new BuildPlatform('Windows'))->runtimeLibraries($phpDir, $phpxDir) as $library) {
                    if (BuildPlatform::format($library) !== 'PE') {
                        throw new \RuntimeException('SDK运行库不是Windows PE');
                    }
                }
            } catch (\RuntimeException $error) {
                $messages[] = ['error' => $error->getMessage(), 'info' => '需要匹配的PHP/PHPX运行库与导入库；官方发行包允许phpx.dll位于PHP_HOME顶层'];
            }
        }
        return $messages;
    }
}
