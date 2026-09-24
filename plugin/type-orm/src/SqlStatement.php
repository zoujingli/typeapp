<?php

declare(strict_types=1);

namespace Type\Orm;

/** @internal 迁移和事务共用的单语句检查，值应当使用 PDO 参数绑定。 */
final class SqlStatement
{
    /** 识别单语句首操作词，拒绝注释、多语句和歧义引用；不是完整 SQL 权限分析器。 */
    public static function operation(string $sql): string
    {
        $quote = '';
        $ended = false;
        $length = strlen($sql);
        for ($index = 0; $index < $length; $index++) {
            $character = $sql[$index];
            if ($quote !== '') {
                if ($character === '\\') {
                    throw new DatabaseException('引用内反斜杠含义依赖数据库模式，请使用参数或双写引号');
                }
                if ($character === $quote) {
                    if ($index + 1 < $length && $sql[$index + 1] === $quote) {
                        $index++;
                    } else {
                        $quote = '';
                    }
                }
                continue;
            }
            if ($ended && trim($character) !== '') {
                throw new DatabaseException('每次只接受一条 SQL 语句');
            }
            if ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;
            } elseif ($character === ';') {
                $ended = true;
            } elseif ($character === '$' || $character === "\0" || $character === '#'
                || substr($sql, $index, 2) === '--' || substr($sql, $index, 2) === '/*') {
                throw new DatabaseException('受管 SQL 不接受注释、美元引用或空字节');
            }
        }
        if ($quote !== '' || !preg_match('/^\s*([A-Za-z]+)\b/', $sql, $match)) {
            throw new DatabaseException('SQL 语句无效或引用未结束');
        }
        return strtoupper($match[1]);
    }
}
