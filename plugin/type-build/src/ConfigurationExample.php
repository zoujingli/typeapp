<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 创建和发布共用的纯数据配置示例检查，不执行或插值配置。 */
final class ConfigurationExample
{
    /**
     * 调用方须提供可读的普通.env.example（不超过64KiB）；检查发布内容并返回原文，不求值或插值。
     *
     * @throws RuntimeException 文件名、链接、大小、语法、秘密值或默认调试设置不符合发布约束。
     */
    public function read(string $file): string
    {
        if (basename($file) !== '.env.example' || !is_file($file) || is_link($file) || filesize($file) > 65536) {
            throw new RuntimeException('只接受明确的.env.example配置示例');
        }
        $text = (string) file_get_contents($file);
        if (str_contains($text, "\0") || str_contains($text, '<?') || str_contains($text, '${')) {
            throw new RuntimeException('配置示例不是纯数据');
        }
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^([A-Z_][A-Z0-9_]*)=(.*)$/D', $line, $match) !== 1) {
                throw new RuntimeException('配置示例声明无效');
            }
            $value = trim($match[2]);
            if (preg_match('/(?:PASSWORD|PASSWD|TOKEN|SECRET|PRIVATE_KEY|CREDENTIAL|API_KEY|AUTHORIZATION|(?:^|_)KEY$)/', $match[1])
                && !in_array($value, ['', '""', "''"], true)) {
                throw new RuntimeException('配置示例不得包含秘密值');
            }
            if ($match[1] === 'APP_DEBUG' && !in_array(strtolower($value), ['false', '0', 'off', 'no'], true)) {
                throw new RuntimeException('发布默认配置不能开启调试');
            }
        }
        return $text;
    }
}
