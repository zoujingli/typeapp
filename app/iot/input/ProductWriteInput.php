<?php

declare(strict_types=1);

namespace app\iot\input;

use app\common\input\InputFields;
use Type\Validate\Data;
use Type\Validate\Field;
use Type\Validate\Input;
use Type\Validate\Schema;
use Type\Validate\ValidatedInput;
use Type\Validate\ValidationException;

/** 产品创建、部分修改与删除的分场景输入；PATCH 始终保留缺失字段。 */
final class ProductWriteInput implements ValidatedInput
{
    /** 已校验 Data 仅存在于本次请求，不水合活动 Model 或连接。 */
    public function __construct(public Data $data)
    {
    }

    /** 版本只用于修改/删除；创建不能携带管理字段，正文未知键明确拒绝。 */
    public static function schema(): Schema
    {
        return new Schema([
            'version' => Field::integer()->required()->range(1, 2147483646)->inScenarios(['patch', 'delete'])
                ->when(static fn (Input $input, string $scenario): bool => self::fields($input, $scenario)),
            'name' => Field::text()->required()->trim()->length(1, 100)->inScenarios(['create', 'patch'])
                ->when(static fn (Input $input, string $scenario): bool => self::fields($input, $scenario)),
            'description' => Field::text()->length(0, 1000)->inScenarios(['create', 'patch']),
        ]);
    }

    /** 不为 PATCH 的缺失名称或描述制造空值。 */
    public static function fromData(Data $data): ProductWriteInput
    {
        return new ProductWriteInput($data);
    }

    /** 乐观锁身份即使是 PATCH 也不可省略，与可选业务字段的缺失语义分开。 */
    public function version(): int
    {
        if (!$this->data->has('version')) {
            throw new ValidationException(['version' => ['required']]);
        }
        return $this->data->get('version');
    }

    private static function fields(Input $input, string $scenario): bool
    {
        $allowed = $scenario === 'delete' ? ['version'] : ($scenario === 'create' ? ['name', 'description'] : ['version', 'name', 'description']);
        return InputFields::only($input, 'body', $allowed, 'unexpected_field');
    }
}
