<?php

declare(strict_types=1);

namespace TypeApp\Integration;

use Type\Orm\Connection;
use Type\Orm\ModelQuery;
use Type\Orm\Relation;
use TypeApp\OrmSuite\Article;
use TypeApp\OrmSuite\Tag;
use TypeApp\OrmSuite\User;

/** 通过模型关系批量读取文章 DTO，供 HTTP、缓存与测量共用。 */
final class Reader
{
    /**
     * 读取文章、作者和标签并转换为业务字段，不直接泄漏模型内部状态。
     *
     * @return array<string, mixed> 显式文章 DTO。
     */
    public static function article(int $id): array
    {
        $tags = Relation::belongsToMany(static fn (Connection $target): ModelQuery => Tag::query()->onConnection($target), 'type_suite_article_tags', 'article_id', 'tag_id', 'id', 'id', ['weight']);
        $author = Relation::belongsTo(static fn (Connection $target): ModelQuery => User::query()->onConnection($target), 'user_id');
        $article = Article::query()->with('tags', $tags)->with('author', $author)->find($id);
        if ($article === null) {
            throw new \RuntimeException('集成文章不存在');
        }
        return $article->project(['id', 'title', 'status', 'views', 'version'], ['author' => ['id', 'name', 'credit', 'external_id'], 'tags' => ['id', 'label']]);
    }
}
