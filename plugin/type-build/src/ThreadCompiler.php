<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 将线程入口标识编译为确定的函数调用，运行时只传字符串数据。 */
final class ThreadCompiler
{
    /**
     * @param array<string, string> $entries 入口标识到完整函数名或 Class::method。
     * @throws RuntimeException 标识或静态调用声明无效。
     */
    public function generate(array $entries): string
    {
        if ($entries === [] || count($entries) > 128) {
            throw new RuntimeException('threads 需要 1–128 个显式编译入口');
        }
        $checks = [];
        $calls = [];
        foreach ($entries as $id => $call) {
            if (!is_string($id) || preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $id) !== 1
                || !is_string($call) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*(?:::[A-Za-z_][A-Za-z0-9_]*)?$/D', $call) !== 1) {
                throw new RuntimeException('threads 入口需要稳定标识及完整函数名或 Class::method');
            }
            $literal = var_export($id, true);
            $checks[] = '$entry === ' . $literal;
            $calls[] = '    if ($entry === ' . $literal . ') { return \\' . $call . '($payload); }';
        }
        return "<?php\n\ndeclare(strict_types=1);\n\n"
            . "/** @internal 构建期登记的线程入口查询，不解析运行时 callable。 */\n"
            . "function type_app_compiled_thread_has_entry(string \$entry): bool\n{\n    return " . implode(' || ', $checks) . ";\n}\n\n"
            . "/** @internal Swoole 在新线程中调用此已编译函数；返回角色退出码。 */\n"
            . "function type_app_compiled_thread_run(string \$message): int\n{\n"
            . "    \$data = json_decode(\$message, true, 4, JSON_THROW_ON_ERROR);\n"
            . "    if (!is_array(\$data) || count(\$data) !== 2 || !is_string(\$data[0] ?? null) || !is_string(\$data[1] ?? null)) {\n"
            . "        throw new \\InvalidArgumentException('编译线程消息格式无效');\n    }\n"
            . "    \$entry = \$data[0];\n    \$payload = \$data[1];\n"
            . implode("\n", $calls) . "\n    throw new \\InvalidArgumentException('编译线程入口未登记');\n}\n";
    }
}
