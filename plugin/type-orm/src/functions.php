<?php

declare(strict_types=1);

use Type\Orm\Helper\QueryHelper;
use Type\Orm\ModelQuery;
use Type\Orm\Query;

/**
 * 为已绑定连接和范围约束的查询构造不可变筛选助手。
 *
 * @param array<string, mixed> $input 明确查询输入，不读取全局请求或自动选择数据库。
 */
function _query(Query|ModelQuery $query, array $input = []): QueryHelper
{
    return new QueryHelper($query, $input);
}
