<?php

declare(strict_types=1);

use Type\Orm\Helper\QueryHelper;
use Type\Orm\ModelQuery;
use Type\Orm\Query;

/**
 * 为已绑定连接和范围约束的查询构造不可变筛选助手。
 *
 * 第三个参数收到当前助手，必须返回补充筛选后的助手。忽略返回值不会改变原查询。
 *
 * @param array<string, mixed> $input 明确查询输入，不读取全局请求或自动选择数据库
 * @param null|\Closure(QueryHelper): QueryHelper $refine 在同一输入上继续声明筛选
 */
function _query(ModelQuery|Query $query, array $input = [], ?\Closure $refine = null): QueryHelper
{
    $helper = new QueryHelper($query, $input);
    if ($refine === null) {
        return $helper;
    }
    $refined = $refine($helper);
    if (!$refined instanceof QueryHelper) {
        throw new \InvalidArgumentException('查询回调必须返回 QueryHelper');
    }
    return $refined;
}
