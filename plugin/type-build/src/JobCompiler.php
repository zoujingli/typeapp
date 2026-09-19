<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

final class JobCompiler
{
    public function generate(array $configuration): string
    {
        $class = $configuration['class'] ?? '';
        $jobs = $configuration['jobs'] ?? [];
        $pattern = '/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$/D';
        if (!is_string($class) || !preg_match($pattern, $class) || !is_array($jobs) || !array_is_list($jobs) || $jobs === []
            || count($jobs) > 1000 || array_diff(array_keys($configuration), ['class', 'jobs']) !== []) {
            throw new RuntimeException('任务注册声明无效');
        }
        $parts = explode('\\', $class);
        $name = array_pop($parts);
        $namespace = implode('\\', $parts);
        $code = "<?php\ndeclare(strict_types=1);\nnamespace {$namespace};\nfinal class {$name}\n{\n"
            . "    /** @param array<class-string, \\Closure(\\Type\\Queue\\JobContext): \\Type\\Queue\\Job> \$factories 任务工厂始终接收本次消息上下文。 */\n"
            . "    public static function create(array \$factories): \\Type\\Queue\\Registry\n    {\n        \$registry = new \\Type\\Queue\\Registry();\n";
        $seen = [];
        foreach ($jobs as $job) {
            if (!is_array($job) || !is_string($job['type'] ?? null) || !preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $job['type'])
                || !is_int($job['version'] ?? null) || $job['version'] < 1 || !is_string($job['handler'] ?? null) || !preg_match($pattern, $job['handler'])
                || array_diff(array_keys($job), ['type', 'version', 'handler']) !== []) {
                throw new RuntimeException('任务类型、版本或处理器声明无效');
            }
            $key = $job['type'] . ':' . $job['version'];
            if (isset($seen[$key])) {
                throw new RuntimeException('任务类型与版本重复：' . $key);
            }
            $seen[$key] = true;
            $type = var_export($job['type'], true);
            $version = $job['version'];
            $handler = var_export($job['handler'], true);
            $code .= "        if (!isset(\$factories[{$handler}]) || !\$factories[{$handler}] instanceof \\Closure) { throw new \\RuntimeException('任务构造工厂缺失'); }\n";
            $code .= "        \$registry->register({$type}, {$version}, static fn (\\Type\\Queue\\JobContext \$context): \\Type\\Queue\\Job => (\$factories[{$handler}])(\$context));\n";
        }
        return $code . "        return \$registry;\n    }\n}\n";
    }
}
