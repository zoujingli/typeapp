<?php

declare(strict_types=1);

namespace Type\Validate;

/** HTTP 动作的已校验输入契约；构建器生成具体工厂调用，不反射或合并请求来源。 */
interface ValidatedInput
{
    /** 返回当前输入的显式字段规则；不得为声明规则访问外部服务。 */
    public static function schema(): Schema;

    /** 从有效字段构造输入，具体实现返回自身类型；部分更新须保留 Data::has() 语义。 */
    public static function fromData(Data $data): ValidatedInput;
}
