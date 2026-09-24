<?php

declare(strict_types=1);

namespace Type\Build;

use TypePhp\Platform\Windows;

/** 锁定Windows实现的发行布局适配：保留上游检查，核验实际SDK顶层运行DLL。 */
final class WindowsSdkPlatform extends Windows
{
    /**
     * 沿用上游编译检查，再按官方 SDK 布局核验 PHP/PHPX 的真实 PE 运行库。
     * @return list<array<string, string>> 构建警告与需要补齐的运行库说明。
     */
    public function getBuildLibraryWarnings(string $phpDir, string $phpxDir, string $buildMode, bool $checkPhpxRuntime = true): array
    {
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
