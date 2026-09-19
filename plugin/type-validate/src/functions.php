<?php

declare(strict_types=1);

use Type\Validate\Field;
use Type\Validate\Helper\ValidateHelper;
use Type\Validate\Input;
use Type\Validate\Schema;
use Type\Validate\ValidationException;

/**
 * 以熟悉的快捷入口校验显式输入，只返回声明并通过规则的字段。
 *
 * @param array<string, Field>|Schema $rules 字段规则或已构造 Schema。
 * @param array<string, mixed>|Input $input body 数组或明确的分源输入。
 * @return array<string, mixed> 通过校验的有效字段（含非PATCH的显式默认值），未填充的缺失字段不出现。
 * @throws InvalidArgumentException 规则声明无效。
 * @throws ValidationException 输入不满足校验规则。
 */
function _vali(array|Schema $rules, array|Input $input, string $scenario = 'default', bool $patch = false): array
{
    return ValidateHelper::data($rules, $input, $scenario, $patch)->toArray();
}
