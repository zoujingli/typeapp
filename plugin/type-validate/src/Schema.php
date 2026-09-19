<?php

declare(strict_types=1);

namespace Type\Validate;

use InvalidArgumentException;

/** 声明本身是普通 PHP，可与业务 DTO 工厂一起静态编译。 */
final class Schema
{
    private array $fields;

    /**
     * 仅登记显式字段；不扫描请求、不执行字符串规则。
     *
     * @param array<string, Field> $fields 输出字段名到不可变规则的映射。
     * @throws InvalidArgumentException 名称不是合法标识符，或值不是Field。
     */
    public function __construct(array $fields)
    {
        foreach ($fields as $name => $field) {
            if (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) || !$field instanceof Field) {
                throw new InvalidArgumentException('校验字段必须是有效名称与 Field 的映射');
            }
        }
        $this->fields = $fields;
    }

    /**
     * 按各字段的来源、场景和条件校验；未知字段不进入结果，原始Input不变。
     *
     * 非PATCH可填入显式可选默认值，PATCH只保留提供的字段。
     * @throws ValidationException 任意字段失败；包含全部收集到的字段路径及错误码。
     */
    public function validate(Input $input, string $scenario = 'default', bool $patch = false): Data
    {
        $values = [];
        $errors = [];
        foreach ($this->fields as $name => $field) {
            $source = $input->source($field->sourceName());
            $key = $field->inputKey($name);
            $provided = array_key_exists($key, $source);
            $result = $field->validate($provided ? $source[$key] : null, $provided, $input, $scenario, $patch, $name);
            if ($result['errors'] !== []) {
                $errors = array_merge($errors, $result['errors']);
            } elseif ($result['provided']) {
                $values[$name] = $result['value'];
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return new Data($values);
    }
}
