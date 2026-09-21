<?php

declare(strict_types=1);

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

// 关闭 tokenizer 扩展时，PHP 的三个开放/关闭标签令牌不会出现在
// token_name() 或 PHP-Parser 的常量表中。它们是 PHP 8.4/8.5 的稳定
// 内置编号；优先保留运行时已经提供的值，再补齐缺失项。
$specialTokenIds = [
    'T_OPEN_TAG' => 393,
    'T_OPEN_TAG_WITH_ECHO' => 394,
    'T_CLOSE_TAG' => 395,
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
