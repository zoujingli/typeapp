<?php

declare(strict_types=1);

namespace Type\Validate;

use Closure;
use InvalidArgumentException;
use stdClass;

/** 不可变字段声明：先显式转换，再按登记顺序校验。 */
final class Field
{
    private string $type;
    private string $source = 'body';
    private ?string $key = null;
    private bool $required = false;
    private bool $nullable = false;
    private bool $cast = false;
    private bool $trim = false;
    private bool $hasDefault = false;
    private mixed $defaultValue = null;
    private array $rules = [];
    private array $scenarios = [];
    private ?Closure $condition = null;
    private ?Schema $schema = null;
    private ?Field $item = null;

    /**
     * 声明基础类型；对象与列表的递归规则优先通过object/listOf工厂提供。
     *
     * @throws InvalidArgumentException 类型不是string/integer/number/boolean/object/list。
     */
    public function __construct(string $type)
    {
        if (!in_array($type, ['string', 'integer', 'number', 'boolean', 'object', 'list'], true)) {
            throw new InvalidArgumentException('不支持的字段类型：' . $type);
        }
        $this->type = $type;
    }

    /** 接受有效UTF-8文本；默认不trim或隐式转换其他类型。 */
    public static function text(): Field
    {
        return new Field('string');
    }
    /** 接受PHP整数；文本需要显式cast，溢出仍为类型错误。 */
    public static function integer(): Field
    {
        return new Field('integer');
    }
    /** 接受整数或有限浮点数，拒绝INF和NAN。 */
    public static function number(): Field
    {
        return new Field('number');
    }
    /** 接受真正的布尔值；不将任意非空文本视为true。 */
    public static function boolean(): Field
    {
        return new Field('boolean');
    }

    /** 嵌套stdClass或关联数组按给定Schema校验，结果转为字段数组。 */
    public static function object(Schema $schema): Field
    {
        $field = new Field('object');
        $field->schema = $schema;
        return $field;
    }

    /** 接受连续索引列表；PATCH中提供的列表整体替换，元素仍完整校验。 */
    public static function listOf(Field $item): Field
    {
        $field = new Field('list');
        $field->item = $item;
        return $field;
    }

    /**
     * 返回显式选择输入来源及别名的新声明，不合并不同来源的同名字段。
     *
     * @throws InvalidArgumentException 来源非法，或别名为空/含控制字符。
     */
    public function from(string $source, ?string $key = null): Field
    {
        if (!in_array($source, ['body', 'query', 'route', 'header'], true)) {
            throw new InvalidArgumentException('字段来源无效');
        }
        if ($key !== null && ($key === '' || preg_match('/[\x00-\x1f\x7f]/', $key))) {
            throw new InvalidArgumentException('输入字段名无效');
        }
        $copy = clone $this;
        $copy->source = $source;
        $copy->key = $key;
        return $copy;
    }

    /** 返回body/query/route/header之一，供Schema选择唯一来源。 */
    public function sourceName(): string
    {
        return $this->source;
    }
    /** 优先使用显式输入别名；未声明别名时使用Schema字段名。 */
    public function inputKey(string $fallback): string
    {
        return $this->key ?? $fallback;
    }

    /** 非PATCH时必须实际提供字段；默认值不能代替必填，空串不等于缺失。 */
    public function required(): Field
    {
        $copy = clone $this;
        $copy->required = true;
        return $copy;
    }

    /**
     * 为非PATCH中的可选缺失字段声明默认值，不覆盖显式null、空串、0或false。
     *
     * required仍要求原始输入实际提供；场景或条件不适用时不填充默认值。
     * 默认值经过与输入相同的转换和规则，失败沿用ValidationException。
     * 声明和每次使用均复制数据，不调用对象序列化、克隆钩子或默认值工厂。
     *
     * @param mixed $value null、标量、数组或普通stdClass组成的数据树，容器最多128层。
     * @throws InvalidArgumentException 数据包含其他对象、资源或超过容器深度（包括循环引用）。
     */
    public function defaultValue(mixed $value): Field
    {
        $copy = clone $this;
        $copy->hasDefault = true;
        $copy->defaultValue = self::copyDefault($value, 0);
        return $copy;
    }

    /** 允许显式或默认null；null通过后不执行类型、嵌套及后续规则。 */
    public function nullable(): Field
    {
        $copy = clone $this;
        $copy->nullable = true;
        return $copy;
    }

    /** 显式转换规范整数/JSON数值及true/false/1/0文本；转换失败保留原值供类型拒绝。 */
    public function cast(): Field
    {
        $copy = clone $this;
        $copy->cast = true;
        return $copy;
    }

    /**
     * 文本在类型和规则检查前使用PHP trim；不改变原始Input。
     *
     * @throws InvalidArgumentException 不是文本声明。
     */
    public function trim(): Field
    {
        if ($this->type !== 'string') {
            throw new InvalidArgumentException('只有文本字段可以 trim');
        }
        $copy = clone $this;
        $copy->trim = true;
        return $copy;
    }

    /**
     * 仅在列出的场景应用字段；不适用时也不填默认值，空列表表示不限制。
     *
     * @param list<string> $scenarios 非空场景名。
     * @throws InvalidArgumentException 存在空值或非字符串场景。
     */
    public function inScenarios(array $scenarios): Field
    {
        foreach ($scenarios as $scenario) {
            if (!is_string($scenario) || $scenario === '') {
                throw new InvalidArgumentException('校验场景必须是非空字符串');
            }
        }
        $copy = clone $this;
        $copy->scenarios = $scenarios;
        return $copy;
    }

    /**
     * 场景符合后按原始输入判断字段是否适用，异常向外传播。
     *
     * @param Closure(Input, string): bool $condition 依次接收分源输入和场景，不接收补默认值后的数据。
     */
    public function when(Closure $condition): Field
    {
        $copy = clone $this;
        $copy->condition = $condition;
        return $copy;
    }

    /**
     * 追加包含上下界的数值规则。
     *
     * @throws InvalidArgumentException 字段非数值、边界非有限值或下界大于上界。
     */
    public function range(int|float $minimum, int|float $maximum): Field
    {
        if (!in_array($this->type, ['integer', 'number'], true) || !is_finite((float) $minimum) || !is_finite((float) $maximum) || $minimum > $maximum) {
            throw new InvalidArgumentException('数值范围声明无效');
        }
        return $this->rule('range', static fn (mixed $value, Input $input, string $scenario): bool => $value >= $minimum && $value <= $maximum);
    }

    /**
     * 追加文本Unicode码点数或列表元素数的闭区间限制。
     *
     * @throws InvalidArgumentException 字段非文本/列表，或长度范围非法。
     */
    public function length(int $minimum, int $maximum): Field
    {
        if (!in_array($this->type, ['string', 'list'], true) || $minimum < 0 || $minimum > $maximum) {
            throw new InvalidArgumentException('长度声明无效');
        }
        return $this->rule('length', static function (mixed $value, Input $input, string $scenario) use ($minimum, $maximum): bool {
            if (is_array($value)) {
                $length = count($value);
            } else {
                $length = preg_match_all('/./us', $value);
                if ($length === false) {
                    return false;
                }
            }
            return $length >= $minimum && $length <= $maximum;
        });
    }

    /**
     * 使用严格比较，不把数值文本、整数和布尔枚举混同。
     *
     * @param list<mixed> $values 非空允许值集合。
     * @throws InvalidArgumentException 允许值集合为空。
     */
    public function oneOf(array $values): Field
    {
        if ($values === []) {
            throw new InvalidArgumentException('枚举不能为空');
        }
        return $this->rule('enum', static fn (mixed $value, Input $input, string $scenario): bool => in_array($value, $values, true));
    }

    /**
     * 追加完整PCRE表达式；是否锚定首尾由调用者明确指定。
     *
     * @throws InvalidArgumentException 字段非文本或正则表达式无法解析。
     */
    public function matches(string $pattern): Field
    {
        if ($this->type !== 'string' || @preg_match($pattern, '') === false) {
            throw new InvalidArgumentException('正则声明无效');
        }
        return $this->rule('format', static fn (mixed $value, Input $input, string $scenario): bool => preg_match($pattern, $value) === 1);
    }

    /**
     * 使用FILTER_VALIDATE_EMAIL验证格式，不查询域名或发信验证。
     *
     * @throws InvalidArgumentException 字段非文本。
     */
    public function email(): Field
    {
        if ($this->type !== 'string') {
            throw new InvalidArgumentException('邮件格式必须用于文本字段');
        }
        return $this->rule('email', static fn (mixed $value, Input $input, string $scenario): bool => filter_var($value, FILTER_VALIDATE_EMAIL) !== false);
    }

    /**
     * 在类型通过后按登记顺序调用规则；false记录错误码，异常直接向外传播。
     *
     * @param Closure(mixed, Input, string): bool $predicate 依次接收已转换字段值、原始分源输入和场景。
     * @throws InvalidArgumentException 错误码不是小写字母开头的小写字母/数字/下划线标识。
     */
    public function rule(string $code, Closure $predicate): Field
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/D', $code)) {
            throw new InvalidArgumentException('校验错误码无效');
        }
        $copy = clone $this;
        $copy->rules[] = ['code' => $code, 'predicate' => $predicate];
        return $copy;
    }

    /**
     * 供Schema和列表递归校验，调用方负责聚合错误并禁止持久化失败结果。
     *
     * @internal
     * @param bool $provided 原始输入是否实际含有该字段，不能用isset混淆null。
     * @param string $path 完整字段路径，嵌套错误在其后追加字段或列表下标。
     * @return array{provided: bool, value: mixed, errors: array<string, list<string>>} provided表示有效输入或已应用默认值；errors非空时value不能作为已校验数据。
     */
    public function validate(mixed $value, bool $provided, Input $input, string $scenario, bool $patch, string $path): array
    {
        if (($this->scenarios !== [] && !in_array($scenario, $this->scenarios, true))
            || ($this->condition !== null && !($this->condition)($input, $scenario))) {
            return ['provided' => false, 'value' => null, 'errors' => []];
        }
        if (!$provided) {
            if ($patch || $this->required || !$this->hasDefault) {
                return ['provided' => false, 'value' => null, 'errors' => $this->required && !$patch ? [$path => ['required']] : []];
            }
            $value = self::copyDefault($this->defaultValue, 0);
        }
        if ($value === null) {
            return ['provided' => true, 'value' => null, 'errors' => $this->nullable ? [] : [$path => ['null_not_allowed']]];
        }
        if ($this->trim && is_string($value)) {
            $value = trim($value);
        }
        if ($this->cast) {
            $value = $this->convert($value);
        }
        $valid = match ($this->type) {
            'string' => is_string($value) && preg_match('//u', $value) === 1,
            'integer' => is_int($value),
            'number' => (is_int($value) || is_float($value)) && is_finite((float) $value),
            'boolean' => is_bool($value),
            'object' => $value instanceof stdClass || (is_array($value) && !array_is_list($value)),
            'list' => is_array($value) && array_is_list($value),
        };
        if (!$valid) {
            return ['provided' => true, 'value' => null, 'errors' => [$path => ['type_' . $this->type]]];
        }
        $errors = [];
        foreach ($this->rules as $rule) {
            if (($rule['predicate'])($value, $input, $scenario) !== true) {
                $errors[$path][] = $rule['code'];
            }
        }
        if ($this->schema !== null) {
            try {
                $value = $this->schema->validate(new Input(['body' => $value instanceof stdClass ? get_object_vars($value) : $value]), $scenario, $patch)->toArray();
            } catch (ValidationException $error) {
                foreach ($error->errors() as $nested => $codes) {
                    $errors[$path . '.' . $nested] = $codes;
                }
            }
        } elseif ($this->item !== null) {
            $items = [];
            foreach ($value as $index => $entry) {
                // PATCH 中提供的数组替换整个数组，其新元素仍须完整。
                $result = $this->item->validate($entry, true, $input, $scenario, false, $path . '.' . $index);
                $errors = array_merge($errors, $result['errors']);
                $items[] = $result['value'];
            }
            $value = $items;
        }
        return ['provided' => true, 'value' => $value, 'errors' => $errors];
    }

    /** 复制值而不是保留数组引用或共享对象；有界递归同时拒绝循环数据。 */
    private static function copyDefault(mixed $value, int $depth): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if ($depth >= 128) {
            throw new InvalidArgumentException('默认值容器不能超过128层或包含循环引用');
        }
        if (is_array($value)) {
            $arrayCopy = [];
            foreach ($value as $arrayKey => $arrayValue) {
                $arrayCopy[$arrayKey] = self::copyDefault($arrayValue, $depth + 1);
            }
            return $arrayCopy;
        }
        if ($value instanceof stdClass && get_class($value) === stdClass::class) {
            $objectCopy = new stdClass();
            foreach (get_object_vars($value) as $propertyName => $propertyValue) {
                $objectCopy->{$propertyName} = self::copyDefault($propertyValue, $depth + 1);
            }
            return $objectCopy;
        }
        throw new InvalidArgumentException('默认值只接受标量、null、数组与普通stdClass数据');
    }

    private function convert(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        if ($this->type === 'integer' && preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value)) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            return $integer === false ? $value : $integer;
        }
        if ($this->type === 'number' && preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?$/D', $value)) {
            return (float) $value;
        }
        if ($this->type === 'boolean' && in_array($value, ['true', 'false', '1', '0'], true)) {
            return $value === 'true' || $value === '1';
        }
        return $value;
    }
}
