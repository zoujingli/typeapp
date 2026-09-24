<?php

declare(strict_types=1);

namespace TypeApp\OrmSuite {

    /** 不声明版本字段的集合算术同样需要完整回滚。 */
    #[\Type\Orm\Attribute\Table('type_suite_counters', generatedPrimary: false)]
    final class CounterRecord extends \Type\Orm\Model
    {
        public int $id;
        public string $tenant_id;
        public int $value;
    }

    /** 集合写入回归使用显式主键，覆盖大集合的整批回滚。 */
    #[\Type\Orm\Attribute\Table('type_suite_mutations', generatedPrimary: false, softDelete: 'deleted_at', version: 'version')]
    final class MutationRecord extends \Type\Orm\Model
    {
        public int $id;
        public string $tenant_id;
        public string $title;
        public int $value;
        #[\Type\Orm\Attribute\Column(fillable: false, required: false)]
        public ?\DateTimeImmutable $deleted_at;
        #[\Type\Orm\Attribute\Column(fillable: false, required: false)]
        public int $version;
    }

    /** 使用 archive 逻辑库的集合写入模型，验证同表不同连接身份不能混合关系加载。 */
    #[\Type\Orm\Attribute\Table('type_suite_mutations', generatedPrimary: false, softDelete: 'deleted_at', version: 'version', database: 'archive')]
    final class ArchiveMutationRecord extends \Type\Orm\Model
    {
        public int $id;
        public string $tenant_id;
        public string $title;
        public int $value;
        #[\Type\Orm\Attribute\Column(fillable: false, required: false)]
        public ?\DateTimeImmutable $deleted_at;
        #[\Type\Orm\Attribute\Column(fillable: false, required: false)]
        public int $version;
    }

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

        #[\Type\Orm\Attribute\BelongsToMany(ScopedRecord::class, 'type_suite_scoped_links', 'user_id', 'record_id', pivotTenant: 'tenant_id')]
        public array $records;

        /** 业务方法在开发与 AOT 转换后保留。 */
        public function displayLabel(): string
        {
            return '用户：' . $this->name;
        }

    }
}

namespace TypeApp\OrmSuite {

    /** 消费者自己的租户实体；不依赖应用账号或权限。 */
    #[\Type\Orm\Attribute\Table('type_suite_scoped_records', softDelete: 'deleted_at', version: 'version')]
    final class ScopedRecord extends \Type\Orm\Model
    {
        public int $id;
        public string $tenant_id;
        public string $title;
        public int $value;

        #[\Type\Orm\Attribute\Column(fillable: false, required: false)]
        public ?\DateTimeImmutable $deleted_at;

        #[\Type\Orm\Attribute\Column(fillable: false, required: false)]
        public int $version;
    }

    /** 通过 workspace 属性映射 owner_ref，验证租户字段采用声明映射而非固定列名。 */
    #[\Type\Orm\Attribute\Table('type_suite_scoped_labels', tenant: 'workspace')]
    final class ScopedLabel extends \Type\Orm\Model
    {
        public int $id;
        #[\Type\Orm\Attribute\Column(name: 'owner_ref')]
        public string $workspace;
        public string $scope_id;
        public string $label;
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
