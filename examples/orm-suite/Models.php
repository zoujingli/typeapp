<?php

declare(strict_types=1);

namespace TypeApp\OrmSuite {

    /** 类型属性模型，构建器保留类名并生成状态访问钩子。 */
    #[\Type\Orm\Attribute\Table('type_suite_users')]
    final class User extends \Type\Orm\Model
    {
        public int $id;

        #[\Type\Orm\Attribute\Column(name: 'display_name')]
        public string $name;

        public bool $active;

        #[\Type\Orm\Attribute\Column(type: 'bigint', precision: 30)]
        public string $external_id;

        #[\Type\Orm\Attribute\Column(type: 'decimal', precision: 30, scale: 2)]
        public string $credit;

        public array $profile;

        public \DateTimeImmutable $joined_at;

        #[\Type\Orm\Attribute\Column(visible: false)]
        public string $secret;

        #[\Type\Orm\Attribute\HasMany(Article::class, 'user_id')]
        public array $articles;

        #[\Type\Orm\Attribute\HasOne(Details::class, 'user_id')]
        public ?Details $details;

        /** 业务方法在开发与 AOT 转换后保留。 */
        public function displayLabel(): string
        {
            return '用户：' . $this->name;
        }

    }
}

namespace TypeApp\OrmSuite {

    /** 类型属性模型，构建器保留类名并生成状态访问钩子。 */
    #[\Type\Orm\Attribute\Table('type_suite_articles', softDelete: 'deleted_at', version: 'version')]
    final class Article extends \Type\Orm\Model
    {
        public int $id;

        public int $user_id;

        public string $title;

        public string $status;

        public int $views;

        #[\Type\Orm\Attribute\Column(fillable: false, required: false)]
        public ?\DateTimeImmutable $deleted_at;

        #[\Type\Orm\Attribute\Column(fillable: false, required: false)]
        public int $version;

        #[\Type\Orm\Attribute\BelongsTo(User::class, 'user_id')]
        public ?User $author;

        #[\Type\Orm\Attribute\BelongsToMany(Tag::class, 'type_suite_article_tags', 'article_id', 'tag_id', pivotFields: ['weight'])]
        public array $tags;

    }
}

namespace TypeApp\OrmSuite {

    /** 类型属性模型，构建器保留类名并生成状态访问钩子。 */
    #[\Type\Orm\Attribute\Table('type_suite_tags')]
    final class Tag extends \Type\Orm\Model
    {
        public int $id;

        public string $label;

        #[\Type\Orm\Attribute\BelongsToMany(Article::class, 'type_suite_article_tags', 'tag_id', 'article_id', pivotFields: ['weight'])]
        public array $articles;

    }
}

namespace TypeApp\OrmSuite {

    /** 类型属性模型，构建器保留类名并生成状态访问钩子。 */
    #[\Type\Orm\Attribute\Table('type_suite_details')]
    final class Details extends \Type\Orm\Model
    {
        public int $id;

        public int $user_id;

        public string $bio;

        #[\Type\Orm\Attribute\BelongsTo(User::class, 'user_id')]
        public ?User $user;

    }
}
