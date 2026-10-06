<?php

declare(strict_types=1);

namespace app\catalog\input;

use Type\Validate\Data;
use Type\Validate\Field;
use Type\Validate\Schema;
use Type\Validate\ValidatedInput;

/** 搜索词只来自 query；列名与排序方向在 Service 固定。 */
final class SearchInput implements ValidatedInput
{
    /** 保存验证后的白名单数据，保留字段缺失状态。 */
    public function __construct(public Data $data)
    {
    }

    /** 查询只接受明确声明的名称搜索值。 */
    public static function schema(): Schema
    {
        return new Schema(['name' => Field::text()->from('query')->length(1, 100)]);
    }

    /** 验证管线显式构造输入，不从任意类名反射生成。 */
    public static function fromData(Data $data): SearchInput
    {
        return new SearchInput($data);
    }
}
