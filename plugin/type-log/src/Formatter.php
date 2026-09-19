<?php

declare(strict_types=1);

namespace Type\Log;

use InvalidArgumentException;
use Stringable;
use Throwable;

/** 不调用上下文对象的序列化方法；在边界内复制、脱敏并生成 JSON Lines。 */
final class Formatter
{
    private array $fields = [];
    private array $secrets = [];
    private int $maxDepth;
    private int $maxItems;
    private int $maxStringBytes;

    public function __construct(array $sensitiveFields = [], array $secretValues = [], int $maxDepth = 6, int $maxItems = 128, int $maxStringBytes = 2048)
    {
        if ($maxDepth < 1 || $maxDepth > 16 || $maxItems < 1 || $maxItems > 4096 || $maxStringBytes < 64 || $maxStringBytes > 16384
            || count($sensitiveFields) > 128 || count($secretValues) > 128) {
            throw new InvalidArgumentException('日志格式化边界无效');
        }
        foreach (array_merge(['password', 'passwd', 'pwd', 'token', 'secret', 'authorization', 'cookie', 'apikey', 'privatekey', 'sessionid'], $sensitiveFields) as $field) {
            if (!is_string($field) || $field === '' || strlen($field) > 128) {
                throw new InvalidArgumentException('敏感字段名称无效');
            }
            $normalized = (string) preg_replace('/[^a-z0-9]/', '', strtolower($field));
            if ($normalized === '') {
                throw new InvalidArgumentException('敏感字段需要可规范化的 ASCII 标识');
            }
            $this->fields[] = $normalized;
        }
        foreach ($secretValues as $secret) {
            if (!is_string($secret) || $secret === '' || strlen($secret) > 4096) {
                throw new InvalidArgumentException('敏感值必须是 1 到 4096 字节的字符串');
            }
            $this->secrets[] = (string) $secret;
        }
        $this->maxDepth = $maxDepth;
        $this->maxItems = $maxItems;
        $this->maxStringBytes = $maxStringBytes;
    }

    public function context(array $context): array
    {
        $budget = $this->maxItems;
        return $this->normalize($context, 0, $budget);
    }

    public function record(string $build, string $channel, string $level, string|Stringable $message, array $context, array $correlation): string
    {
        $safe = $this->context($context);
        try {
            $text = (string) $message;
        } catch (Throwable $error) {
            $text = '[message_unavailable]';
        }
        $replacements = [];
        foreach ($safe as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }
        $record = ['time' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s.u\\Z'),
            'level' => $level, 'channel' => $channel, 'build_id' => $this->text($build), 'process_id' => (int) getmypid(),
            'message' => $this->text(strtr($this->text($text), $replacements)), 'correlation' => $correlation, 'context' => $safe];
        return json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
    }

    private function normalize(mixed $value, int $depth, int &$budget): mixed
    {
        // 锁定PHPX的Reference比较在Clang下存在重载歧义；显式读整数，不复制共享预算。
        if ((int) $budget <= 0) {
            return '[TRUNCATED]';
        }
        $budget--;
        // 占位值也是一条输出；超深节点不递归，但仍须消耗同一条目预算。
        if ($depth > $this->maxDepth) {
            return '[TRUNCATED]';
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $entry) {
                if ((int) $budget <= 0) {
                    $result['__truncated'] = true;
                    break;
                }
                $safeKey = is_int($key) ? $key : $this->text((string) $key);
                if (is_string($key) && $this->sensitive($key)) {
                    $result[$safeKey] = '[REDACTED]';
                    $budget--;
                } else {
                    $result[$safeKey] = $this->normalize($entry, $depth + 1, $budget);
                }
            }
            return $result;
        }
        if ($value instanceof Throwable) {
            $trace = [];
            foreach (array_slice($value->getTrace(), 0, 12) as $frame) {
                $trace[] = ['file' => $this->text((string) ($frame['file'] ?? '')), 'line' => (int) ($frame['line'] ?? 0),
                    'class' => $this->text((string) ($frame['class'] ?? '')), 'function' => $this->text((string) ($frame['function'] ?? ''))];
            }
            return ['type' => get_class($value), 'message' => $this->text($value->getMessage()), 'code' => $value->getCode(),
                'file' => $this->text($value->getFile()), 'line' => $value->getLine(), 'trace' => $trace];
        }
        if (is_object($value)) {
            return '[object ' . get_class($value) . ']';
        }
        if (is_resource($value)) {
            return '[resource ' . get_resource_type($value) . ']';
        }
        if (gettype($value) === 'resource (closed)') {
            return '[resource closed]';
        }
        if (is_string($value)) {
            return $this->text($value);
        }
        if (is_float($value) && !is_finite($value)) {
            return '[non-finite]';
        }
        return $value;
    }

    private function sensitive(string $key): bool
    {
        $key = (string) preg_replace('/[^a-z0-9]/', '', strtolower($key));
        foreach ($this->fields as $field) {
            if (str_contains($key, $field)) {
                return true;
            }
        }
        return false;
    }

    private function text(string $value): string
    {
        // 保留额外窗口，避免截断已登记敏感值时只替换一部分。
        $value = substr($value, 0, $this->maxStringBytes + 4096);
        if ($this->secrets !== []) {
            $value = str_replace($this->secrets, '[REDACTED]', $value);
        }
        $value = (string) preg_replace('/(\b(?:proxy-)?authorization\s*[:=]\s*)(?:Bearer|Basic)\s+[^\s,;]+/i', '$1[REDACTED]', $value);
        $value = (string) preg_replace('/(\b(?:password|passwd|pwd|[a-z_-]*token|[a-z_-]*secret|api[_-]?key|cookie|authorization)["\x27]?\s*[:=]\s*)(?:"[^"\r\n]*"|\x27[^\x27\r\n]*\x27|[^\s,;]+)/i', '$1[REDACTED]', $value);
        $value = (string) preg_replace('~([a-z][a-z0-9+.-]*://)[^/\s:@]+:[^/\s@]*@~i', '$1[REDACTED]@', $value);
        return strlen($value) > $this->maxStringBytes ? substr($value, 0, $this->maxStringBytes) . '[TRUNCATED]' : $value;
    }
}
