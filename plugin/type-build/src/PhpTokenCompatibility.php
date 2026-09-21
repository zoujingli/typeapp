<?php

declare(strict_types=1);

// PHP 8.5 仍返回 throw 的词法标记，但不再导出 T_THROW 常量；PHP-Parser
// 通过常量名建立令牌映射，因此在加载解析器前恢复该稳定映射。
if (!defined('T_THROW')) {
    foreach (token_get_all('<?php throw 0;') as $token) {
        if (is_array($token) && $token[1] === 'throw') {
            define('T_THROW', $token[0]);
            break;
        }
    }
}
