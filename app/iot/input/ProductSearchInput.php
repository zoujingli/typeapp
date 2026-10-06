<?php

declare(strict_types=1);

namespace app\iot\input;

use app\common\input\InputFields;
use Type\Validate\Data;
use Type\Validate\Field;
use Type\Validate\Input;
use Type\Validate\Schema;
use Type\Validate\ValidatedInput;

/** 产品列表的查询来源输入，不混入租户路径、头或正文。 */
final class ProductSearchInput implements ValidatedInput
{
    /** 保存明确分页值和可选名称，默认值只作用于未提供的查询项。 */
    public function __construct(public int $page, public int $perPage, public string $name)
    {
    }

    /** 每页最多100条；多余查询键仍按既有应用规则拒绝。 */
    public static function schema(): Schema
    {
        return new Schema([
            'page' => Field::integer()->from('query')->cast()->range(1, 100000)->defaultValue(1)
                ->when(static fn (Input $input, string $scenario): bool => InputFields::only($input, 'query', ['page', 'per_page', 'name'], 'product_query_invalid')),
            'per_page' => Field::integer()->from('query')->cast()->range(1, 100)->defaultValue(20),
            'name' => Field::text()->from('query')->length(0, 100)->defaultValue(''),
        ]);
    }

    /** 工厂只消费有效 Data，不读取原始请求。 */
    public static function fromData(Data $data): ProductSearchInput
    {
        return new ProductSearchInput($data->get('page'), $data->get('per_page'), $data->get('name'));
    }
}
