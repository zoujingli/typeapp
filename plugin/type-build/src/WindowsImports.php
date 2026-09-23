<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 解析MSVC明确分区的导入表，不把延迟加载转换为启动必需能力。 */
final class WindowsImports
{
    /** @return array{required:list<string>, delayed:list<string>} */
    public function parse(string $output): array
    {
        $section = '';
        $imports = ['required' => [], 'delayed' => []];
        foreach (preg_split('/\r\n|\r|\n/', $output) as $line) {
            $line = trim($line);
            if ($line === 'Image has the following dependencies:') {
                $section = 'required';
            } elseif ($line === 'Image has the following delay load dependencies:') {
                $section = 'delayed';
            } elseif ($line === 'Summary') {
                $section = '';
            } elseif (preg_match('/^[A-Za-z0-9_.+-]+\.dll$/iD', $line)) {
                if ($section === '') {
                    throw new RuntimeException('Windows依赖表包含未分类DLL，不能推测加载阶段');
                }
                $imports[$section][strtolower($line)] = $line;
            }
        }
        return ['required' => array_values($imports['required']), 'delayed' => array_values($imports['delayed'])];
    }
}
