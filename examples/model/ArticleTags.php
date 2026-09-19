<?php

declare(strict_types=1);

namespace TypeApp\ModelExample;

use Type\Orm\Connection;
use Type\Orm\ManyToMany;
use Type\Orm\ModelQuery;
use Type\Orm\Relation;

final class ArticleTags
{
    public static function relation(int $batchSize = 250): ManyToMany
    {
        return Relation::belongsToMany(
            static fn (Connection $connection): ModelQuery => Tag::query($connection)->select(['label']),
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
