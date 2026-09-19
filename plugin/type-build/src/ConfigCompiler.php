<?php

declare(strict_types=1);

namespace Type\Build;

use PhpParser\Node;
use PhpParser\ParserFactory;
use RuntimeException;

/** 解析受限配置 DSL；不 include 配置，不读取环境，不执行任何应用表达式。 */
final class ConfigCompiler
{
    /**
     * @param array{class: string, files: list<string>} $configuration
     * @return array{class: string, files: list<string>, code: string}
     * @throws RuntimeException 声明、路径、符号或 DSL 不合法；诊断不回显表达式内容。
     */
    public function generate(string $root, array $configuration): array
    {
        if (array_diff(array_keys($configuration), ['class', 'files']) !== []) {
            throw new RuntimeException('configuration 只接受 class 和 files');
        }
        $projectRoot = realpath($root);
        if ($projectRoot === false || !is_dir($projectRoot)) {
            throw new RuntimeException('配置编译项目根目录不存在');
        }
        $class = $configuration['class'] ?? null;
        if (!is_string($class) || strlen($class) > 240 || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$/D', $class) !== 1) {
            throw new RuntimeException('configuration.class 必须是带命名空间的生成类名');
        }
        if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false) || enum_exists($class, false)) {
            throw new RuntimeException('生成配置类与已有声明重名：' . $class);
        }
        $selected = $configuration['files'] ?? null;
        if (!is_array($selected) || !array_is_list($selected) || $selected === [] || count($selected) > 256) {
            throw new RuntimeException('configuration.files 必须是 1 至 256 项显式文件列表');
        }
        $files = [];
        $sections = [];
        $assignments = [];
        foreach ($selected as $relativeFile) {
            if (!is_string($relativeFile)) {
                throw new RuntimeException('配置输入必须是相对 PHP 文件路径');
            }
            $file = $this->localFile($projectRoot, $relativeFile);
            $parts = explode('/', substr($relativeFile, 0, -4));
            array_shift($parts);
            $section = implode('.', $parts);
            foreach ($sections as $existingSection) {
                if ($existingSection === $section || str_starts_with($existingSection, $section . '.') || str_starts_with($section, $existingSection . '.')) {
                    throw new RuntimeException('配置文件的 section 重复或前缀冲突：' . $section);
                }
            }
            if (in_array($file, $files, true)) {
                throw new RuntimeException('配置文件重复');
            }
            $contents = @file_get_contents($file, false, null, 0, 1048577);
            if ($contents === false || strlen($contents) > 1048576) {
                throw new RuntimeException('配置文件不可读或超过 1 MiB：' . $relativeFile);
            }
            try {
                $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($contents);
            } catch (\PhpParser\Error $error) {
                throw new RuntimeException('配置文件 PHP 语法无效：' . $relativeFile . '，行 ' . $error->getStartLine());
            }
            if (!is_array($statements) || count($statements) !== 2 || !$statements[0] instanceof Node\Stmt\Declare_
                || $statements[0]->stmts !== null || count($statements[0]->declares) !== 1
                || $statements[0]->declares[0]->key->toString() !== 'strict_types'
                || !$statements[0]->declares[0]->value instanceof Node\Scalar\Int_ || $statements[0]->declares[0]->value->value !== 1
                || !$statements[1] instanceof Node\Stmt\Return_ || !$statements[1]->expr instanceof Node\Expr\Array_) {
                throw new RuntimeException('配置文件只能包含 declare(strict_types=1) 和一次 return 数组：' . $relativeFile);
            }
            $access = '$values';
            foreach ($parts as $sectionPart) {
                $access .= '[' . var_export($sectionPart, true) . ']';
            }
            $assignments[] = '        ' . $access . ' = ' . $this->expression($statements[1]->expr, $relativeFile, 0) . ';';
            $files[] = $file;
            $sections[] = $section;
        }
        $separator = strrpos($class, '\\');
        $namespace = substr($class, 0, $separator);
        $shortName = substr($class, $separator + 1);
        $code = "<?php\n\ndeclare(strict_types=1);\n\nnamespace " . $namespace . ";\n\n"
            . "/** 由显式配置声明生成；环境值只在启动时读取。 */\nfinal class " . $shortName . "\n{\n"
            . "    public static function load(\\Type\\Core\\Config\\Environment \$environment): \\Type\\Core\\Config\\Repository\n    {\n"
            . "        \$values = [];\n" . implode("\n", $assignments) . "\n\n        return new \\Type\\Core\\Config\\Repository(\$values);\n    }\n}\n";
        try {
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        } catch (\PhpParser\Error) {
            throw new RuntimeException('configuration.class 不能生成有效 PHP 类声明');
        }

        return ['class' => $class, 'files' => $files, 'code' => $code];
    }

    private function localFile(string $root, string $relative): string
    {
        if ($relative === '' || strlen($relative) > 1024 || str_contains($relative, '\\') || str_contains($relative, ':')
            || str_contains($relative, "\0") || !str_ends_with($relative, '.php')) {
            throw new RuntimeException('配置文件必须是项目内显式相对 PHP 路径');
        }
        $segments = explode('/', $relative);
        if (count($segments) < 2 || count($segments) > 32) {
            throw new RuntimeException('配置文件必须位于一个显式目录内，且不超过 32 层');
        }
        $cursor = $root;
        foreach ($segments as $partIndex => $partName) {
            $component = $partIndex === count($segments) - 1 ? substr($partName, 0, -4) : $partName;
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $component) !== 1) {
                throw new RuntimeException('配置路径段必须使用字母、数字、下划线或连字符');
            }
            $cursor .= '/' . $partName;
            if (is_link($cursor)) {
                throw new RuntimeException('配置输入不允许符号链接');
            }
        }
        $file = realpath($cursor);
        if ($file === false || !BuildPlatform::contains($root, $file) || !is_file($file) || !is_readable($file)) {
            throw new RuntimeException('配置文件不存在、不可读或超出项目目录');
        }

        return $file;
    }

    private function expression(Node\Expr $expression, string $file, int $depth): string
    {
        if ($depth >= 32) {
            throw new RuntimeException('配置表达式超过 32 层：' . $file);
        }
        if ($expression instanceof Node\Expr\Array_) {
            $items = [];
            $keys = [];
            foreach ($expression->items as $item) {
                if ($item === null || $item->byRef || $item->unpack) {
                    throw new RuntimeException('配置数组不支持空项、引用或展开：' . $file);
                }
                if ($item->key === null) {
                    $keys[] = true;
                    $key = array_key_last($keys);
                } else {
                    $declaredKey = $this->literal($item->key, $file, 0);
                    if ((!is_int($declaredKey) && !is_string($declaredKey)) || (is_string($declaredKey) && ($declaredKey === '' || str_contains($declaredKey, '.') || str_contains($declaredKey, "\0")))) {
                        throw new RuntimeException('配置数组键必须是整数或不含点的非空字符串：' . $file);
                    }
                    $normalizedKey = [$declaredKey => true];
                    $key = array_key_first($normalizedKey);
                    if ($key === PHP_INT_MAX) {
                        throw new RuntimeException('配置数组数字键超出可追加范围：' . $file);
                    }
                    if (array_key_exists($key, $keys)) {
                        throw new RuntimeException('配置数组存在重复键：' . $file . '，行 ' . $item->getStartLine());
                    }
                    $keys[$key] = true;
                }
                $items[] = var_export($key, true) . ' => ' . $this->expression($item->value, $file, $depth + 1);
            }

            return '[' . implode(', ', $items) . ']';
        }
        if ($expression instanceof Node\Expr\FuncCall) {
            return $this->environment($expression, $file, null);
        }
        if ($expression instanceof Node\Expr\Cast\Int_ || $expression instanceof Node\Expr\Cast\Double
            || $expression instanceof Node\Expr\Cast\Bool_ || $expression instanceof Node\Expr\Cast\String_) {
            $cast = match (true) {
                $expression instanceof Node\Expr\Cast\Int_ => 'int',
                $expression instanceof Node\Expr\Cast\Double => 'float',
                $expression instanceof Node\Expr\Cast\Bool_ => 'bool',
                default => 'string',
            };
            if ($expression->expr instanceof Node\Expr\FuncCall) {
                return $this->environment($expression->expr, $file, $cast);
            }

            return var_export($this->cast($this->literal($expression->expr, $file, 0), $cast, $file), true);
        }

        return var_export($this->literal($expression, $file, 0), true);
    }

    private function environment(Node\Expr\FuncCall $call, string $file, ?string $cast): string
    {
        if (!$call->name instanceof Node\Name || $call->name->toString() !== 'env' || $call->name->isFullyQualified()
            || $call->name->isRelative() || count($call->args) < 1 || count($call->args) > 2) {
            throw new RuntimeException('配置只允许 env(静态键, 标量默认值) 调用：' . $file . '，行 ' . $call->getStartLine());
        }
        foreach ($call->args as $argument) {
            if (!$argument instanceof Node\Arg || $argument->byRef || $argument->unpack || $argument->name !== null) {
                throw new RuntimeException('env 参数不能引用、展开或使用命名参数：' . $file);
            }
        }
        $key = $this->literal($call->args[0]->value, $file, 0);
        if (!is_string($key) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) !== 1) {
            throw new RuntimeException('env 键必须是静态环境变量名：' . $file);
        }
        $default = count($call->args) === 2 ? $this->literal($call->args[1]->value, $file, 0) : null;
        if ($cast !== null) {
            $default = $this->cast($default, $cast, $file);
        }

        return '$environment->get(' . var_export($key, true) . ', ' . var_export($default, true) . ')';
    }

    private function literal(Node\Expr $expression, string $file, int $depth): mixed
    {
        if ($depth > 4) {
            throw new RuntimeException('配置标量表达式嵌套过深：' . $file);
        }
        if ($expression instanceof Node\Scalar\String_ || $expression instanceof Node\Scalar\Int_) {
            return $expression->value;
        }
        if ($expression instanceof Node\Scalar\Float_) {
            if (!is_finite($expression->value)) {
                throw new RuntimeException('配置浮点字面量必须有限：' . $file);
            }

            return $expression->value;
        }
        if ($expression instanceof Node\Expr\ConstFetch) {
            $name = strtolower($expression->name->toString());
            if (in_array($name, ['true', 'false', 'null'], true)) {
                return match ($name) {
                    'true' => true, 'false' => false, default => null
                };
            }
        }
        if ($expression instanceof Node\Expr\UnaryMinus || $expression instanceof Node\Expr\UnaryPlus) {
            $number = $this->literal($expression->expr, $file, $depth + 1);
            if (is_int($number) || is_float($number)) {
                return $expression instanceof Node\Expr\UnaryMinus ? -$number : +$number;
            }
        }

        throw new RuntimeException('配置只接受标量字面量、数组和 env 声明：' . $file . '，行 ' . $expression->getStartLine());
    }

    private function cast(#[\SensitiveParameter] mixed $value, string $cast, string $file): mixed
    {
        if ($cast === 'string') {
            return (string) $value;
        }
        if ($cast === 'bool') {
            if (is_string($value)) {
                $normalized = strtolower($value);
                if (in_array($normalized, ['true', 'yes', 'on', '1'], true)) {
                    return true;
                }
                if (in_array($normalized, ['false', 'no', 'off', '0', ''], true)) {
                    return false;
                }
                throw new RuntimeException('配置布尔转换的字面量无效：' . $file);
            }

            return (bool) $value;
        }
        if ($cast === 'int') {
            if ($value === null || is_bool($value) || is_int($value)) {
                return (int) $value;
            }
            if (is_string($value) && preg_match('/^[+-]?(?:0|[1-9][0-9]*)$/D', $value) === 1) {
                $integer = filter_var($value, FILTER_VALIDATE_INT);
                if ($integer !== false) {
                    return (int) $integer;
                }
            }
            if (is_float($value) && floor($value) === $value && $value >= PHP_INT_MIN && $value < (float) PHP_INT_MAX) {
                return (int) $value;
            }
            throw new RuntimeException('配置整数转换的字面量无效或溢出：' . $file);
        }
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)
            || (is_string($value) && preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/D', $value) === 1)) {
            $float = (float) $value;
            if (is_finite($float)) {
                return $float;
            }
        }

        throw new RuntimeException('配置浮点转换的字面量无效或溢出：' . $file);
    }
}
