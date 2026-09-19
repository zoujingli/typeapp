<?php

declare(strict_types=1);

namespace Type\Validate\Helper;

use InvalidArgumentException;
use Type\Validate\Data;
use Type\Validate\Field;
use Type\Validate\Input;
use Type\Validate\Schema;
use Type\Validate\ValidationException;

/** 将明确输入交给既有 Schema，保留场景、PATCH 与字段存在性。 */
final class ValidateHelper
{
    /**
     * 普通数组仅代表 body；多来源输入使用 Input，不读取全局请求。
     *
     * @param array<string, Field>|Schema $rules 已声明字段，不支持隐式字符串规则。
     * @param array<string, mixed>|Input $input 待校验数据或分源输入。
     * @return Data 有效字段包含已应用的显式默认值；has()区分结果缺失与null，不代表原始请求提供状态。
     * @throws InvalidArgumentException 规则不是 Field 映射或场景为空。
     * @throws ValidationException 输入不符合明确规则；错误只包含字段与稳定错误码。
     */
    public static function data(array|Schema $rules, array|Input $input, string $scenario = 'default', bool $patch = false): Data
    {
        if ($scenario === '') {
            throw new InvalidArgumentException('校验场景不能为空');
        }
        if (is_array($rules)) {
            foreach ($rules as $rule) {
                if (!$rule instanceof Field) {
                    throw new InvalidArgumentException('快捷校验只接受 Field 映射，不隐式解析字符串规则');
                }
            }
            $schema = new Schema($rules);
        } else {
            $schema = $rules;
        }
        $source = is_array($input) ? new Input(['body' => $input]) : $input;
        return $schema->validate($source, $scenario, $patch);
    }
}
