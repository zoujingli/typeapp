<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;
use TypePhp\Backend\Msvc;

/** 锁定 TypePHP MSVC 后端的静态 CRT 适配，编译与链接仍复用上游命令生成器。 */
final class WindowsStaticBackend extends Msvc
{
    /** C++、预编译头和应用入口使用相同 CRT；上游命令变化时明确停止适配。 */
    public function buildCompileOptions(array $config = []): string
    {
        $config['section_gc'] = empty($config['debug']);
        return $this->staticCrt(parent::buildCompileOptions($config));
    }

    /** 原生 C 桥接同样使用静态 CRT，避免与 C++ 目标产生运行库冲突。 */
    public function buildCCompileCommand(string $sourceFile, string $outputFile, array $options = []): string
    {
        $options['section_gc'] = empty($options['debug']);
        return $this->staticCrt(parent::buildCCompileCommand($sourceFile, $outputFile, $options));
    }

    /** 静态目标使用 LIBCMT；发布链接清除未使用代码并禁用增量链接和 PDB。 */
    public function buildLinkOptions(array $config = []): string
    {
        $config['section_gc'] = empty($config['debug']);
        $flags = parent::buildLinkOptions($config);
        if (substr_count($flags, ' /NODEFAULTLIB:LIBCMT') !== 1) {
            throw new RuntimeException('TypePHP MSVC 链接运行库规则需要重新核对');
        }
        $flags = str_replace(' /NODEFAULTLIB:LIBCMT', ' /NODEFAULTLIB:MSVCRT', $flags);
        return empty($config['debug']) ? $flags . ' /DEBUG:NONE /INCREMENTAL:NO' : $flags;
    }

    /** 固定上游为每个翻译单元生成唯一 /MD；拒绝混入另一 CRT，避免参数顺序决定 ABI。 */
    private function staticCrt(string $command): string
    {
        if (preg_match_all('/ (?<crt>\/(?:MDd?|MTd?))(?=\s|$)/', $command, $matches) !== 1
            || $matches['crt'][0] !== '/MD') {
            throw new RuntimeException('TypePHP MSVC CRT 参数需要重新核对');
        }
        return str_replace(' /MD', ' /MT', $command);
    }
}
