<?php

declare(strict_types=1);

namespace TypeApp\OrmSuite {
    use Type\Orm\Attribute\BelongsToMany;
    use Type\Orm\Attribute\Column;
    use Type\Orm\Attribute\HasMany;
    use Type\Orm\Attribute\HasOne;
    use Type\Orm\Attribute\Table;
    use Type\Orm\Model;

    /** 首版冻结 Schema 应用后的业务模型。 */
    #[Table('type_suite_catalog')]
    final class CatalogRecord extends Model
    {
        public int $id;

        public string $code;

        public string $title;

        public bool $active;

        public int $age;

        #[Column(type: 'decimal', precision: 30, scale: 2)]
        public string $money;

        public \DateTimeImmutable $recorded_at;

        public ?string $notes;
    }

    /** 演进后保持原数据，仅显式调整表及属性映射。 */
    #[Table('type_suite_catalog_entries')]
    final class CatalogEntry extends Model
    {
        public int $id;

        public string $code;

        public string $heading;

        #[Column(name: 'state')]
        public string $status;

        public bool $active;

        public int $age;

        #[Column(type: 'decimal', precision: 30, scale: 2)]
        public string $money;

        public \DateTimeImmutable $recorded_at;

        public ?string $notes;
    }

    /** 模型声明非空不能替代数据库可空、部分或表达式索引的真实事实。 */
    #[Table('type_suite_unsafe_unique', generatedPrimary: false)]
    final class UnsafeUniqueRecord extends Model
    {
        public int $id;

        public string $code;
    }

    /** 所有唯一键均包含租户的集合冲突模型，可安全接受 MySQL 任意唯一键语义。 */
    #[Table('type_suite_upserts', generatedPrimary: false, version: 'version', createdAt: 'created_at', updatedAt: 'updated_at')]
    final class UpsertRecord extends Model
    {
        public int $id;

        public string $tenant_id;

        public string $code;

        public string $title;

        public int $created_at;

        public int $updated_at;

        public int $version;
    }

    /** 无生命周期的冲突模型保留数据库原始未变化影响行数。 */
    #[Table('type_suite_upsert_labels', generatedPrimary: false)]
    final class UpsertLabel extends Model
    {
        public int $id;

        public string $code;

        public string $title;
    }

    /** 获取或创建与冲突写入使用真实双唯一约束及受管生命周期。 */
    #[Table('type_suite_unique_records', generatedPrimary: false, softDelete: 'deleted_at', version: 'version', createdAt: 'created_at', updatedAt: 'updated_at')]
    final class UniqueRecord extends Model
    {
        public int $id;

        public string $tenant_id;

        public string $code;

        public string $alias;

        public ?string $optional_code;

        public int $created_at;

        public int $updated_at;

        public ?\DateTimeImmutable $deleted_at;

        public int $version;
    }

    /** 受管 Unix 秒时间与版本、租户和软删除共用真实写入路径。 */
    #[Table('type_suite_second_records', generatedPrimary: false, softDelete: 'deleted_at', version: 'version', createdAt: 'created_at', updatedAt: 'updated_at')]
    final class SecondRecord extends Model
    {
        public int $id;

        public string $tenant_id;

        public string $title;

        public int $value;

        public int $created_at;

        public int $updated_at;

        public ?\DateTimeImmutable $deleted_at;

        public int $version;
    }

    /** UTC 微秒时间必须由真实列精度与实例、集合写入共同保证。 */
    #[Table('type_suite_microsecond_records', generatedPrimary: false, softDelete: 'deleted_at', version: 'version', createdAt: 'created_at', updatedAt: 'updated_at')]
    final class MicrosecondRecord extends Model
    {
        public int $id;

        public string $tenant_id;

        public string $title;

        public int $value;

        public \DateTimeImmutable $created_at;

        public \DateTimeImmutable $updated_at;

        public ?\DateTimeImmutable $deleted_at;

        public int $version;
    }

    /** 不声明版本字段的集合算术同样需要完整回滚。 */
    #[Table('type_suite_counters', generatedPrimary: false)]
    final class CounterRecord extends Model
    {
        public int $id;

        public string $tenant_id;

        public int $value;
    }

    /** 集合写入回归使用显式主键，覆盖大集合的整批回滚。 */
    #[Table('type_suite_mutations', generatedPrimary: false, softDelete: 'deleted_at', version: 'version')]
    final class MutationRecord extends Model
    {
        public int $id;

        public string $tenant_id;

        public string $title;

        public int $value;

        #[Column(fillable: false, required: false)]
        public ?\DateTimeImmutable $deleted_at;

        #[Column(fillable: false, required: false)]
        public int $version;
    }

    /** 使用 archive 逻辑库的集合写入模型，验证同表不同连接身份不能混合关系加载。 */
    #[Table('type_suite_mutations', generatedPrimary: false, softDelete: 'deleted_at', version: 'version', database: 'archive')]
    final class ArchiveMutationRecord extends Model
    {
        public int $id;

        public string $tenant_id;

        public string $title;

        public int $value;

        #[Column(fillable: false, required: false)]
        public ?\DateTimeImmutable $deleted_at;

        #[Column(fillable: false, required: false)]
        public int $version;
    }

    /** 类型属性模型，构建器保留类名并生成状态访问钩子。 */
    #[Table('type_suite_users')]
    final class User extends Model
    {
        public int $id;

        #[Column(name: 'display_name')]
        public string $name;

        public bool $active;

        #[Column(type: 'bigint', precision: 30)]
        public string $external_id;

        #[Column(type: 'decimal', precision: 30, scale: 2)]
        public string $credit;

        public array $profile;

        public \DateTimeImmutable $joined_at;

        #[Column(visible: false)]
        public string $secret;

        #[HasMany(Article::class, 'user_id')]
        public array $articles;

        #[HasOne(Details::class, 'user_id')]
        public ?Details $details;

        #[BelongsToMany(ScopedRecord::class, 'type_suite_scoped_links', 'user_id', 'record_id', pivotTenant: 'tenant_id')]
        public array $records;

        /** 业务方法在开发与 AOT 转换后保留。 */
        public function displayLabel(): string
        {
            return '用户：' . $this->name;
        }
    }
}

namespace TypeApp\OrmSuite {
    use Type\Orm\Attribute\Column;
    use Type\Orm\Attribute\Table;
    use Type\Orm\Model;

    /** 消费者自己的租户实体；不依赖应用账号或权限。 */
    #[Table('type_suite_scoped_records', softDelete: 'deleted_at', version: 'version')]
    final class ScopedRecord extends Model
    {
        public int $id;

        public string $tenant_id;

        public string $title;

        public int $value;

        #[Column(fillable: false, required: false)]
        public ?\DateTimeImmutable $deleted_at;

        #[Column(fillable: false, required: false)]
        public int $version;
    }

    /** 通过 workspace 属性映射 owner_ref，验证租户字段采用声明映射而非固定列名。 */
    #[Table('type_suite_scoped_labels', tenant: 'workspace')]
    final class ScopedLabel extends Model
    {
        public int $id;

        #[Column(name: 'owner_ref')]
        public string $workspace;

        public string $scope_id;

        public string $label;
    }
}

namespace TypeApp\OrmSuite {
    use Type\Orm\Attribute\BelongsTo;
    use Type\Orm\Attribute\BelongsToMany;
    use Type\Orm\Attribute\Column;
    use Type\Orm\Attribute\Table;
    use Type\Orm\Model;

    /** 类型属性模型，构建器保留类名并生成状态访问钩子。 */
    #[Table('type_suite_articles', softDelete: 'deleted_at', version: 'version')]
    final class Article extends Model
    {
        public int $id;

        public int $user_id;

        public string $title;

        public string $status;

        public int $views;

        #[Column(fillable: false, required: false)]
        public ?\DateTimeImmutable $deleted_at;

        #[Column(fillable: false, required: false)]
        public int $version;

        #[BelongsTo(User::class, 'user_id')]
        public ?User $author;

        #[BelongsToMany(Tag::class, 'type_suite_article_tags', 'article_id', 'tag_id', pivotFields: ['weight'])]
        public array $tags;
    }
}

namespace TypeApp\OrmSuite {
    use Type\Orm\Attribute\BelongsToMany;
    use Type\Orm\Attribute\Table;
    use Type\Orm\Model;

    /** 类型属性模型，构建器保留类名并生成状态访问钩子。 */
    #[Table('type_suite_tags')]
    final class Tag extends Model
    {
        public int $id;

        public string $label;

        #[BelongsToMany(Article::class, 'type_suite_article_tags', 'tag_id', 'article_id', pivotFields: ['weight'])]
        public array $articles;
    }
}

namespace TypeApp\OrmSuite {
    use Type\Orm\Attribute\BelongsTo;
    use Type\Orm\Attribute\Table;
    use Type\Orm\Model;

    /** 类型属性模型，构建器保留类名并生成状态访问钩子。 */
    #[Table('type_suite_details')]
    final class Details extends Model
    {
        public int $id;

        public int $user_id;

        public string $bio;

        #[BelongsTo(User::class, 'user_id')]
        public ?User $user;
    }
}
