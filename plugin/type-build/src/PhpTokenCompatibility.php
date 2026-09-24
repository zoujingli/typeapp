<?php

declare(strict_types=1);

// 某些受控 PHP 运行配置会保留 tokenizer 函数却没有注册 PHP 8 的
// PhpToken 类。PHP-Parser 的兼容层在 PHP 8+ 会直接继承该类，因此先
// 提供等价的最小实现，保持解析器的 Token 接口可用；正常运行时已有
// 原生类则完全复用原生实现。
if (!class_exists('PhpToken', false)) {
    /** 仅在受控构建 PHP 缺失原生 PhpToken 类时启用的解析器接口适配。 */
    class PhpToken
    {
        public int $id;
        public string $text;
        public int $line;
        public int $pos;

        /** 保存词法 token 编号、原始文本、起始行与字节偏移；未知位置用 -1。 */
        public function __construct(int $id, string $text, int $line = -1, int $pos = -1)
        {
            $this->id = $id;
            $this->text = $text;
            $this->line = $line;
            $this->pos = $pos;
        }

        /** @return list<static> */
        public static function tokenize(string $code, int $flags = 0): array
        {
            if (!function_exists('token_get_all')) {
                throw new RuntimeException('PHP tokenizer 扩展未加载，无法解析构建输入。');
            }
            $tokens = [];
            $line = 1;
            $position = 0;
            foreach (token_get_all($code, $flags) as $token) {
                if (is_array($token)) {
                    $id = $token[0];
                    $text = $token[1];
                    $tokenLine = $token[2] ?? $line;
                } else {
                    $id = ord($token);
                    $text = $token;
                    $tokenLine = $line;
                }
                $tokens[] = new static($id, $text, $tokenLine, $position);
                $line += substr_count($text, "\n");
                $position += strlen($text);
            }
            return $tokens;
        }

        /** 返回当前运行时的 token 名称，单字节 token 返回字符，无法识别时返回 null。 */
        public function getTokenName(): ?string
        {
            if ($this->id < 256) {
                return chr($this->id);
            }
            if (!function_exists('token_name')) {
                return null;
            }
            $name = token_name($this->id);
            return $name === 'UNKNOWN' ? null : $name;
        }

        /** @param int|string|array<int|string> $kind */
        public function is($kind): bool
        {
            if (is_int($kind)) {
                return $this->id === $kind;
            }
            if (is_string($kind)) {
                return $this->text === $kind;
            }
            foreach ($kind as $entry) {
                if (is_int($entry) && $this->id === $entry) {
                    return true;
                }
                if (is_string($entry) && $this->text === $entry) {
                    return true;
                }
            }
            return false;
        }

        /** 判定空白、注释和开标签等可忽略 token，沿用当前运行时常量编号。 */
        public function isIgnorable(): bool
        {
            foreach (['T_WHITESPACE', 'T_COMMENT', 'T_DOC_COMMENT', 'T_OPEN_TAG'] as $name) {
                if (defined($name) && $this->id === constant($name)) {
                    return true;
                }
            }
            return false;
        }

        /** 返回原始 token 文本，保留空白及注释字节供格式保留输出使用。 */
        public function __toString(): string
        {
            return $this->text;
        }
    }
}

// PHP 8.5 可能不再导出完整的 T_* 常量；PHP-Parser 仍通过常量名建立
// 令牌映射。优先从当前运行时的 token_name() 反查实际编号，再以解析器
// 生成的稳定编号兜底。这样 CLI、AOT 子进程和关闭额外 INI 扫描的运行时
// 使用同一套映射，不依赖某一个扩展配置或 token_get_all() 示例。
$runtimeTokenIds = [];
if (function_exists('token_name')) {
    for ($tokenId = 256; $tokenId < 1024; $tokenId++) {
        $tokenName = token_name($tokenId);
        if (is_string($tokenName) && str_starts_with($tokenName, 'T_')) {
            $runtimeTokenIds[$tokenName] = $tokenId;
        }
    }
}

foreach ($runtimeTokenIds as $tokenName => $tokenId) {
    if (!defined($tokenName)) {
        define($tokenName, $tokenId);
    }
}

// 关闭 tokenizer 扩展时，PHP-Parser 使用的词法令牌不会全部出现在
// token_name() 或其解析器常量表中。以下是 PHP 8.4/8.5 的稳定内置编号；
// 优先保留运行时已经提供的值，再补齐缺失项。
$specialTokenIds = [
    'T_COMMENT' => 391,
    'T_DOC_COMMENT' => 392,
    'T_OPEN_TAG' => 393,
    'T_OPEN_TAG_WITH_ECHO' => 394,
    'T_CLOSE_TAG' => 395,
    'T_WHITESPACE' => 396,
    'T_BAD_CHARACTER' => 409,
];
foreach ($specialTokenIds as $tokenName => $tokenId) {
    if (defined($tokenName)) {
        continue;
    }
    define($tokenName, $runtimeTokenIds[$tokenName] ?? $tokenId);
}

if (class_exists(\PhpParser\Parser\Php8::class)) {
    foreach ((new ReflectionClass(\PhpParser\Parser\Php8::class))->getConstants() as $tokenName => $tokenId) {
        if (!str_starts_with($tokenName, 'T_') || defined($tokenName)) {
            continue;
        }
        $runtimeTokenId = $runtimeTokenIds[$tokenName] ?? $tokenId;
        if (is_int($runtimeTokenId)) {
            define($tokenName, $runtimeTokenId);
        }
    }
}
