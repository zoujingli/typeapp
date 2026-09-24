<?php

declare(strict_types=1);

namespace Type\Build;

use PhpParser\ParserFactory;
use RuntimeException;

/** 在构建目录生成版本限定的完整源码快照，不修改 Composer 安装内容。 */
final class SourceRewriter
{
    /**
     * 校验固定源码摘要和替换次数后生成完整适配副本，原 Composer 文件保持不变。
     * @param list<string> $sources 审计后的完整生产源码。
     * @param array<string, array<string, mixed>> $sourceSets 版本限定的组件源码声明。
     * @return array{sources: list<string>, originals: list<string>, mapping: list<array<string, string>>}
     */
    public function apply(array $sources, array $sourceSets, string $directory): array
    {
        $mapping = [];
        foreach ($sourceSets as $set) {
            foreach ($set['rewrites'] ?? [] as $rewrite) {
                $original = $rewrite['source'];
                if (isset($mapping[$original]) || !in_array($original, $sources, true) || hash_file('sha256', $original) !== $rewrite['sha256']) {
                    throw new RuntimeException('源码适配摘要不符或输入重复，拒绝套用旧版本补丁：' . $original);
                }
                $source = file_get_contents($original);
                foreach (token_get_all($source) as $token) {
                    if (is_array($token) && in_array($token[0], [T_FILE, T_DIR], true)) {
                        throw new RuntimeException('当前源码快照适配不接受依赖原文件位置的魔术常量');
                    }
                }
                foreach ($rewrite['replacements'] as $replacement) {
                    if (substr_count($source, $replacement['from']) !== $replacement['count']) {
                        throw new RuntimeException('源码适配匹配次数不符：' . $original);
                    }
                    $source = str_replace($replacement['from'], $replacement['to'], $source);
                }
                (new ParserFactory())->createForNewestSupportedVersion()->parse($source);
                $hash = hash('sha256', $source);
                $target = $directory . '/' . hash('sha256', $original . $rewrite['sha256'] . $hash) . '/' . basename($original);
                BuildLock::path($target);
                if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true)) {
                    throw new RuntimeException('无法创建完整适配源码目录');
                }
                if (is_file($target)) {
                    if (hash_file('sha256', $target) !== $hash) {
                        throw new RuntimeException('已有适配源码被修改');
                    }
                } elseif (file_put_contents($target, $source) !== strlen($source)) {
                    throw new RuntimeException('无法保存适配源码');
                }
                $mapping[$original] = ['package' => $rewrite['package'], 'source' => $original, 'generated' => $target,
                    'original-sha256' => $rewrite['sha256'], 'generated-sha256' => $hash, 'reason' => $rewrite['reason']];
            }
        }
        return ['sources' => array_map(static fn (string $source): string => $mapping[$source]['generated'] ?? $source, $sources),
            'originals' => array_keys($mapping), 'mapping' => array_values($mapping)];
    }
}
