<?php

declare(strict_types=1);

namespace TypeApp\ValidationExample;

use Type\Validate\Field;
use Type\Validate\Input;
use Type\Validate\Schema;

/** 应用显式声明字段来源，校验后只输出许可字段。 */
final class UserInput
{
    /** 声明分源字段、嵌套对象与场景，供命令和 HTTP 复用同一输入规则。 */
    public static function schema(): Schema
    {
        return new Schema([
            'name' => Field::text()->required()->trim()->length(2, 40),
            'age' => Field::integer()->required()->range(0, 150),
            'email' => Field::text()->nullable()->email(),
            'role' => Field::text()->oneOf(['reader', 'editor']),
            'profile' => Field::object(new Schema(['city' => Field::text()->required()->length(1, 40)])),
            'tags' => Field::listOf(Field::text()->length(1, 20))->length(0, 8),
            'code' => Field::text()->required()->when(static fn (Input $input, string $scenario): bool => ($input->source('body')['role'] ?? '') === 'editor')
                ->matches('/^[A-Z]{3}$/D'),
            'page' => Field::integer()->from('query')->cast()->range(1, 100),
            'newsletter' => Field::boolean()->from('query')->cast(),
            'password' => Field::text()->required()->length(8, 100)->inScenarios(['register']),
            'slug' => Field::text()->rule('reserved', static fn (mixed $value, Input $input, string $scenario): bool => $value !== 'admin'),
        ]);
    }
}
