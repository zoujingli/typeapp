<?php

declare(strict_types=1);

namespace TypeApp\ModelExample {
    use Type\Orm\Attribute\BelongsToMany;
    use Type\Orm\Attribute\Column;
    use Type\Orm\Attribute\Table;
    use Type\Orm\Model;

    /** 类型属性模型，构建器保留类名并生成状态访问钩子。 */
    #[Table('type_model_users', primary: 'id')]
    final class User extends Model
    {
        public int $id;

        #[Column(name: 'display_name')]
        public string $name;

        public int $age;

        public bool $active;

        #[Column(visible: false)]
        public string $secret;

        #[Column(required: false)]
        public ?string $note;

        #[BelongsToMany(ScopedLabel::class, 'type_model_scoped_links', 'user_id', 'label_id', pivotTenant: 'tenant_id')]
        public array $labels;

        #[BelongsToMany(ScopedLabel::class, 'type_model_scoped_links', 'user_id', 'label_id')]
        public array $unscopedLabels;
    }
}

namespace TypeApp\ModelExample {
    use Type\Orm\Attribute\BelongsTo;
    use Type\Orm\Attribute\BelongsToMany;
    use Type\Orm\Attribute\Table;
    use Type\Orm\Model;

    /** 类型属性模型，构建器保留类名并生成状态访问钩子。 */
    #[Table('type_model_articles')]
    final class Article extends Model
    {
        public int $id;

        public ?int $user_id;

        public string $title;

        #[BelongsToMany(Tag::class, 'type_model_article_tag', 'article_id', 'tag_id', pivotFields: ['position'])]
        public array $tags;

        #[BelongsTo(User::class, 'user_id')]
        public ?User $owner;
    }
}

namespace TypeApp\ModelExample {
    use Type\Orm\Attribute\Table;
    use Type\Orm\Model;

    /** 类型属性模型，构建器保留类名并生成状态访问钩子。 */
    #[Table('type_model_profiles')]
    final class Profile extends Model
    {
        public int $id;

        public int $user_id;

        public string $bio;
    }
}

namespace TypeApp\ModelExample {
    use Type\Orm\Attribute\Column;
    use Type\Orm\Attribute\Table;
    use Type\Orm\Model;

    /** 类型属性模型，构建器保留类名并生成状态访问钩子。 */
    #[Table('type_model_tags')]
    final class Tag extends Model
    {
        public int $id;

        public string $label;

        #[Column(visible: false, required: false)]
        public string $internal;
    }
}

namespace TypeApp\ModelExample {
    use Type\Orm\Attribute\Column;
    use Type\Orm\Attribute\Table;
    use Type\Orm\Model;

    /** 类型属性模型，构建器保留类名并生成状态访问钩子。 */
    #[Table('type_model_invoices')]
    final class Invoice extends Model
    {
        public int $id;

        #[Column(type: 'bigint', precision: 30)]
        public string $external_id;

        #[Column(type: 'decimal', precision: 30, scale: 2)]
        public string $amount;

        public \DateTimeImmutable $happened_at;

        #[Column(required: false)]
        public ?string $note;

        #[Column(visible: false, required: false)]
        public string $internal;
    }
}

namespace TypeApp\ModelExample {
    use Type\Orm\Attribute\Column;
    use Type\Orm\Attribute\Table;
    use Type\Orm\Model;

    /** 类型属性模型，构建器保留类名并生成状态访问钩子。 */
    #[Table('type_model_documents', softDelete: 'deleted_at', version: 'version')]
    final class Document extends Model
    {
        public int $id;

        public string $title;

        public string $status;

        #[Column(fillable: false, required: false)]
        public ?\DateTimeImmutable $deleted_at;

        #[Column(fillable: false, required: false)]
        public int $version;
    }
}

namespace TypeApp\ModelExample {
    use Type\Orm\Attribute\Column;
    use Type\Orm\Attribute\Table;
    use Type\Orm\Model;

    /** 类型属性模型，构建器保留类名并生成状态访问钩子。 */
    #[Table('type_model_counters', version: 'version')]
    final class Counter extends Model
    {
        public int $id;

        public int $value;

        #[Column(fillable: false, required: false)]
        public int $version;
    }
}

namespace TypeApp\ModelExample {
    use Type\Orm\Attribute\Column;
    use Type\Orm\Attribute\Table;
    use Type\Orm\Model;

    /** 按映射列 tenant_id 自动隔离，覆盖软删除、版本和部分字段读取。 */
    #[Table('type_model_scoped_records', softDelete: 'deleted_at', version: 'version')]
    final class ScopedRecord extends Model
    {
        public int $id;

        public string $tenant_id;

        public int $user_id;

        public string $title;

        public int $value;

        #[Column(fillable: false, required: false)]
        public ?\DateTimeImmutable $deleted_at;

        #[Column(fillable: false, required: false)]
        public int $version;
    }
}

namespace TypeApp\ModelExample {
    use Type\Orm\Attribute\Column;
    use Type\Orm\Attribute\HasMany;
    use Type\Orm\Attribute\Table;
    use Type\Orm\Model;

    /** 同名逻辑库的两个独立端点，用数据差异观察模型的实际选路。 */
    #[Table('type_rw_probe', generatedPrimary: false)]
    final class ReadWriteProbe extends Model
    {
        #[Column(fillable: true)]
        public int $id;

        public string $value;

        public int $parent_id;

        #[HasMany(ReadWriteProbe::class, 'parent_id')]
        public array $children;
    }
}

namespace TypeApp\ModelExample {
    use Type\Orm\Attribute\Column;
    use Type\Orm\Attribute\Table;
    use Type\Orm\Model;

    /** 非默认租户列通过 Table 声明属性名；普通 scope_id 不自动变成租户。 */
    #[Table('type_model_scoped_labels', tenant: 'workspace')]
    final class ScopedLabel extends Model
    {
        public int $id;

        #[Column(name: 'owner_ref')]
        public string $workspace;

        public string $scope_id;

        public string $label;
    }
}
