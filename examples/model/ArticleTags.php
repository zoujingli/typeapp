<?php

declare(strict_types=1);

namespace TypeApp\ModelExample;

use Type\Orm\Connection;
use Type\Orm\ManyToMany;
use Type\Orm\ModelQuery;
use Type\Orm\Relation;

/** 集中声明文章与标签的中间表关系，保留可批量加载的查询入口。 */
final class ArticleTags
{
    /** 返回带中间表字段的多对多关系，批次大小只限制加载查询的批量。 */
    public static function relation(int $batchSize = 250): ManyToMany
    {
        return Relation::belongsToMany(
            static fn (Connection $connection): ModelQuery => Tag::query()->onConnection($connection)->select(['label']),
            'type_model_article_tag',
            'article_id',
            'tag_id',
            'id',
            'id',
            ['position'],
            $batchSize
        );
    }
}
