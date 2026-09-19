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
