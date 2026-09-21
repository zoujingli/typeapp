<?php

declare(strict_types=1);

// PHP 8.5 不再导出 T_THROW 常量；PHP-Parser 仍通过常量名建立令牌映射，
// 因此在加载解析器前恢复该稳定映射。构建器可能关闭 tokenizer，不能依赖
// token_get_all() 作为唯一来源。
if (!defined('T_THROW')) {
    $tokenId = null;
    if (function_exists('token_get_all')) {
        foreach (token_get_all('<?php throw 0;') as $token) {
            if (is_array($token) && $token[1] === 'throw') {
                $tokenId = $token[0];
                break;
            }
        }
    }
    if (!is_int($tokenId) && class_exists(\PhpParser\Parser\Php8::class)) {
        $tokenId = \PhpParser\Parser\Php8::T_THROW;
    }
    define('T_THROW', is_int($tokenId) ? $tokenId : 258);
}
