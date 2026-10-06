<?php

declare(strict_types=1);

namespace app\catalog\input;

use Type\Validate\Data;
use Type\Validate\Field;
use Type\Validate\Schema;
use Type\Validate\ValidatedInput;

/** JSON 的业务字段；身份、租户、版本和自动时间不在输入规则内。 */
final class ProductInput implements ValidatedInput
{
    /** 保存已经验证的字段，PATCH 缺失与显式 null 保持不同。 */
    public function __construct(public Data $data)
    {
    }

    /** 写入仅接受业务字段；租户、主键、版本和自动时间不在输入中。 */
    public static function schema(): Schema
    {
        return new Schema(['code' => Field::text()->required()->length(1, 50),
            'name' => Field::text()->required()->trim()->length(1, 100), 'note' => Field::text()->nullable()->length(0, 200)]);
    }

    /** 由共同动作绑定在验证成功后构造。 */
    public static function fromData(Data $data): ProductInput
    {
        return new ProductInput($data);
    }
}
