<?php

declare(strict_types=1);

namespace Type\Orm;

use InvalidArgumentException;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/** 字段映射只接受明确类型；数据库解码与业务赋值使用不同规则。 */
final class ModelField
{
    private string $column;
    private string $type;
    private bool $nullable;
    private bool $fillable;
    private bool $visible;
    private bool $required;
    private int $precision;
    private int $scale;

    /** 声明字段类型及赋值、输出策略；精确数值限制总位数与小数位数，拒绝隐式舍入。 */
    public function __construct(
        string $column,
        string $type = 'string',
        bool $nullable = false,
        bool $fillable = true,
        bool $visible = true,
        bool $required = true,
        int $precision = 65,
        int $scale = 2,
        private bool $arrayOnly = false
    ) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column)
            || !in_array($type, ['string', 'integer', 'boolean', 'json', 'decimal', 'bigint', 'datetime'], true)
            || $precision < 1 || $precision > 65 || $scale < 0 || $scale > min(30, $precision)) {
            throw new InvalidArgumentException('模型字段名称或类型无效');
        }
        $this->column = $column;
        $this->type = $type;
        $this->nullable = $nullable;
        $this->fillable = $fillable;
        $this->visible = $visible;
        $this->required = $required;
        $this->precision = $precision;
        $this->scale = $scale;
    }

    /** 返回真实数据库列名，业务赋值仍使用模型属性名。 */
    public function column(): string
    {
        return $this->column;
    }
    /** 表示业务赋值白名单许可，不等同于对外输出许可。 */
    public function fillable(): bool
    {
        return $this->fillable;
    }
    /** 表示该字段可进入公开投影，不改变数据库读取权限。 */
    public function visible(): bool
    {
        return $this->visible;
    }
    /** 表示创建模型时必须显式提供值；已声明的自动主键另行处理。 */
    public function required(): bool
    {
        return $this->required;
    }
    /** 返回规范化策略名称，供查询与真实列校验使用。 */
    public function typeName(): string
    {
        return $this->type;
    }
    /** 表示显式 null 是否为合法值，与字段是否已加载无关。 */
    public function allowsNull(): bool
    {
        return $this->nullable;
    }
    /** 返回精确数值允许的总位数，包含整数和小数位。 */
    public function precision(): int
    {
        return $this->precision;
    }
    /** 返回 decimal 的小数位数，其他字段为零。 */
    public function scale(): int
    {
        return $this->type === 'decimal' ? (int) $this->scale : 0;
    }

    /** @internal 比较声明身份，不执行值转换或行为回调。 */
    public function sameMapping(ModelField $other): bool
    {
        return $this->column === $other->column && $this->type === $other->type && $this->nullable === $other->nullable
            && $this->fillable === $other->fillable && $this->visible === $other->visible && $this->required === $other->required
            && $this->precision === $other->precision && $this->scale === $other->scale && $this->arrayOnly === $other->arrayOnly;
    }

    /**
     * 按模型规则规范化值；database=true 仅供水合，允许已知 PDO 类型转换。
     *
     * @throws ModelException 类型、精度、时区或值域不符合声明。
     */
    public function normalize(mixed $value, bool $database = false): mixed
    {
        if ($value === null && $this->nullable) {
            return null;
        }
        if ($this->type === 'decimal' || $this->type === 'bigint') {
            return $this->exactNumber($value);
        }
        if ($this->type === 'datetime') {
            return $this->dateTime($value, $database);
        }
        if ($database && $this->type === 'integer' && is_string($value)) {
            $number = filter_var($value, FILTER_VALIDATE_INT);
            if ($number !== false) {
                $value = $number;
            }
        } elseif ($database && $this->type === 'boolean') {
            if (in_array($value, [0, '0', 'f'], true)) {
                $value = false;
            } elseif (in_array($value, [1, '1', 't'], true)) {
                $value = true;
            }
        } elseif ($database && $this->type === 'json' && is_string($value)) {
            $value = json_decode($value, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        }
        $valid = match ($this->type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'json' => is_array($value) || is_string($value) || is_bool($value) || is_int($value) || (is_float($value) && is_finite($value)),
        };
        if (!$valid || ($this->type === 'json' && $this->arrayOnly && !is_array($value))) {
            throw new ModelException('invalid_field_type', '模型字段类型错误：' . $this->column);
        }
        if ($this->type === 'json') {
            // 规范化复制，防止外部数组引用改变模型快照。
            return json_decode(json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        }
        return $value;
    }

    /** 将规范化值转为数据库绑定值，时间保存 UTC 微秒文本，JSON 显式编码。 */
    public function encode(mixed $value): mixed
    {
        if ($value instanceof DateTimeImmutable && $this->type === 'datetime') {
            return $value->format('Y-m-d H:i:s.u');
        }
        return $value !== null && $this->type === 'json' ? json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) : $value;
    }

    /** 转换公开输出格式；datetime 使用 UTC ISO 时间，不改变内部值。 */
    public function output(mixed $value): mixed
    {
        return $value instanceof DateTimeImmutable && $this->type === 'datetime' ? $value->format('Y-m-d\TH:i:s.u\Z') : $value;
    }

    /** 比较数据库编码后的值，避免等价日期或结构造成虚假脏字段。 */
    public function equivalent(mixed $left, mixed $right): bool
    {
        return $this->encode($left) === $this->encode($right);
    }

    private function exactNumber(mixed $value): string
    {
        if ((!is_int($value) && !is_string($value)) || strlen((string) $value) > 256) {
            throw new ModelException('invalid_field_type', '精确数值只接受整数或十进制字符串：' . $this->column);
        }
        $matches = [];
        $pattern = $this->type === 'bigint' ? '/^([+-]?)([0-9]+)$/D' : '/^([+-]?)([0-9]+)(?:\.([0-9]+))?$/D';
        if (!preg_match($pattern, (string) $value, $matches)) {
            throw new ModelException('invalid_field_type', '精确数值格式无效：' . $this->column);
        }
        $whole = ltrim($matches[2], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = $matches[3] ?? '';
        $scale = $this->type === 'bigint' ? 0 : (int) $this->scale;
        if (strlen($fraction) > $scale && trim(substr($fraction, $scale), '0') !== '') {
            throw new ModelException('scale_exceeded', '小数位超出声明，不能隐式舍入：' . $this->column);
        }
        $fraction = str_pad(substr($fraction, 0, $scale), $scale, '0');
        $digits = $whole === '0' ? 0 : strlen($whole);
        if ($digits > $this->precision - $scale) {
            throw new ModelException('precision_exceeded', '数值精度超出声明：' . $this->column);
        }
        $negative = $matches[1] === '-' && ($whole !== '0' || trim($fraction, '0') !== '');
        return ($negative ? '-' : '') . $whole . ($scale === 0 ? '' : '.' . $fraction);
    }

    private function dateTime(mixed $value, bool $database): DateTimeImmutable
    {
        try {
            if ($value instanceof DateTimeInterface) {
                $date = DateTimeImmutable::createFromInterface($value);
            } elseif (is_string($value)) {
                $pattern = $database
                    ? '/^[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:[Zz]|[+-][0-9]{2}(?::?[0-9]{2})?)?$/D'
                    : '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:[Zz]|[+-][0-9]{2}:[0-9]{2})$/D';
                if (!preg_match($pattern, $value)) {
                    throw new InvalidArgumentException('日期格式无效');
                }
                $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
                $errors = DateTimeImmutable::getLastErrors();
                if (($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || abs($date->getOffset()) > 50400) {
                    throw new InvalidArgumentException('日期或偏移无效');
                }
            } else {
                throw new InvalidArgumentException('日期类型无效');
            }
            $date = $date->setTimezone(new DateTimeZone('UTC'));
            if ((int) $date->format('Y') < 1 || (int) $date->format('Y') > 9999) {
                throw new InvalidArgumentException('日期范围无效');
            }
            return $date;
        } catch (Throwable $error) {
            throw new ModelException('invalid_datetime', '日期需要有效时区且最多保留微秒：' . $this->column);
        }
    }
}
