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
