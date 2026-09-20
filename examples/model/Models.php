<?php

declare(strict_types=1);

namespace TypeApp\ModelExample {

    /** 类型属性模型，构建器保留类名并生成状态访问钩子。 */
    #[\Type\Orm\Attribute\Table('type_model_users', primary: 'id')]
    final class User extends \Type\Orm\Model
    {
        public int $id;

        #[\Type\Orm\Attribute\Column(name: 'display_name')]
        public string $name;

        public int $age;

        public bool $active;

        #[\Type\Orm\Attribute\Column(visible: false)]
        public string $secret;

        #[\Type\Orm\Attribute\Column(required: false)]
        public ?string $note;

        #[\Type\Orm\Attribute\BelongsToMany(ScopedLabel::class, 'type_model_scoped_links', 'user_id', 'label_id', pivotTenant: 'tenant_id')]
        public array $labels;

    }
}

namespace TypeApp\ModelExample {

    /** 类型属性模型，构建器保留类名并生成状态访问钩子。 */
    #[\Type\Orm\Attribute\Table('type_model_articles')]
    final class Article extends \Type\Orm\Model
    {
        public int $id;

        public ?int $user_id;

        public string $title;

    }
}

namespace TypeApp\ModelExample {

    /** 类型属性模型，构建器保留类名并生成状态访问钩子。 */
    #[\Type\Orm\Attribute\Table('type_model_profiles')]
    final class Profile extends \Type\Orm\Model
    {
        public int $id;

        public int $user_id;

        public string $bio;

    }
}

namespace TypeApp\ModelExample {

    /** 类型属性模型，构建器保留类名并生成状态访问钩子。 */
    #[\Type\Orm\Attribute\Table('type_model_tags')]
    final class Tag extends \Type\Orm\Model
    {
        public int $id;

        public string $label;

        #[\Type\Orm\Attribute\Column(visible: false, required: false)]
        public string $internal;

    }
}

namespace TypeApp\ModelExample {

    /** 类型属性模型，构建器保留类名并生成状态访问钩子。 */
    #[\Type\Orm\Attribute\Table('type_model_invoices')]
    final class Invoice extends \Type\Orm\Model
    {
        public int $id;

        #[\Type\Orm\Attribute\Column(type: 'bigint', precision: 30)]
        public string $external_id;

        #[\Type\Orm\Attribute\Column(type: 'decimal', precision: 30, scale: 2)]
        public string $amount;

        public \DateTimeImmutable $happened_at;

        #[\Type\Orm\Attribute\Column(required: false)]
        public ?string $note;

        #[\Type\Orm\Attribute\Column(visible: false, required: false)]
        public string $internal;

    }
}

namespace TypeApp\ModelExample {

    /** 类型属性模型，构建器保留类名并生成状态访问钩子。 */
    #[\Type\Orm\Attribute\Table('type_model_documents', softDelete: 'deleted_at', version: 'version')]
    final class Document extends \Type\Orm\Model
    {
        public int $id;

        public string $title;

        public string $status;

        #[\Type\Orm\Attribute\Column(fillable: false, required: false)]
        public ?\DateTimeImmutable $deleted_at;

        #[\Type\Orm\Attribute\Column(fillable: false, required: false)]
        public int $version;

    }
}

namespace TypeApp\ModelExample {

    /** 类型属性模型，构建器保留类名并生成状态访问钩子。 */
    #[\Type\Orm\Attribute\Table('type_model_counters', version: 'version')]
    final class Counter extends \Type\Orm\Model
    {
        public int $id;

        public int $value;

        #[\Type\Orm\Attribute\Column(fillable: false, required: false)]
        public int $version;

    }
}

namespace TypeApp\ModelExample {

    /** 按映射列 tenant_id 自动隔离，覆盖软删除、版本和部分字段读取。 */
    #[\Type\Orm\Attribute\Table('type_model_scoped_records', softDelete: 'deleted_at', version: 'version')]
    final class ScopedRecord extends \Type\Orm\Model
    {
        public int $id;
        public string $tenant_id;
        public int $user_id;
        public string $title;
        public int $value;

        #[\Type\Orm\Attribute\Column(fillable: false, required: false)]
        public ?\DateTimeImmutable $deleted_at;

        #[\Type\Orm\Attribute\Column(fillable: false, required: false)]
        public int $version;
    }
}

namespace TypeApp\ModelExample {

    /** 同名逻辑库的两个独立端点，用数据差异观察模型的实际选路。 */
    #[\Type\Orm\Attribute\Table('type_rw_probe', generatedPrimary: false)]
    final class ReadWriteProbe extends \Type\Orm\Model
    {
        #[\Type\Orm\Attribute\Column(fillable: true)]
        public int $id;
        public string $value;
        public int $parent_id;

        #[\Type\Orm\Attribute\HasMany(ReadWriteProbe::class, 'parent_id')]
        public array $children;
    }
}

namespace TypeApp\ModelExample {

    /** 非默认租户列通过 Table 声明属性名；普通 scope_id 不自动变成租户。 */
    #[\Type\Orm\Attribute\Table('type_model_scoped_labels', tenant: 'workspace')]
    final class ScopedLabel extends \Type\Orm\Model
    {
        public int $id;
        #[\Type\Orm\Attribute\Column(name: 'owner_ref')]
        public string $workspace;
        public string $scope_id;
        public string $label;
    }
}
