<?php

declare(strict_types=1);

namespace Type\Core\Http;

use InvalidArgumentException;

/** 构建器输出的路由模型；按段解码一次，匹配与反向 URL 共用约束。 */
final class RouteDefinition
{
    private array $methods;
    private string $path;
    private array $segments;
    private ?string $name;
    private string $priority = '';

    /**
     * 验证编译器生成的路径段，静态段优先级高于参数段。
     * @param list<string> $methods 不重复的大写 HTTP 方法。
     * @param list<array{literal: string}|array{parameter: string, pattern: string}> $segments 完整路径段声明。
     * @throws InvalidArgumentException 名称、方法或路径段声明非法。
     */
    public function __construct(array $methods, string $path, array $segments, ?string $name = null)
    {
        if ($methods === [] || !array_is_list($methods)
            || !str_starts_with($path, '/') || str_starts_with($path, '//') || !array_is_list($segments)
            || ($name !== null && !preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $name))) {
            throw new InvalidArgumentException('路由声明无效');
        }
        $normalizedMethods = [];
        foreach ($methods as $method) {
            if (!is_string($method) || !preg_match('/^[!#$%&\x27*+.^_`|~0-9A-Z-]+$/D', $method)) {
                throw new InvalidArgumentException('路由 HTTP 方法无效');
            }
            $normalizedMethods[] = (string) $method;
        }
        if (count(array_unique($methods)) !== count($methods)) {
            throw new InvalidArgumentException('路由 HTTP 方法重复');
        }
        $parameters = [];
        $normalizedSegments = [];
        foreach ($segments as $index => $segment) {
            if (!is_array($segment)) {
                throw new InvalidArgumentException('路由段必须是声明');
            }
            if (array_key_exists('literal', $segment)) {
                if (count($segment) !== 1 || !is_string($segment['literal']) || !self::validSegment($segment['literal']) || ($index === 0 && $segment['literal'] === '')) {
                    throw new InvalidArgumentException('路由静态段无效');
                }
                $normalizedSegments[] = ['literal' => (string) $segment['literal']];
                $this->priority .= '1';
            } else {
                $parameter = $segment['parameter'] ?? null;
                $pattern = $segment['pattern'] ?? null;
                if (count($segment) !== 2 || !is_string($parameter) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $parameter)
                    || isset($parameters[$parameter]) || !is_string($pattern) || @preg_match($pattern, '') === false) {
                    throw new InvalidArgumentException('路由参数或约束无效');
                }
                $parameters[$parameter] = true;
                $normalizedSegments[] = ['parameter' => (string) $parameter, 'pattern' => (string) $pattern];
                $this->priority .= '0';
            }
        }
        $this->methods = $normalizedMethods;
        $this->path = $path;
        $this->segments = $normalizedSegments;
        $this->name = $name;
    }

    /** @return list<string> 当前路由明确接受的 HTTP 方法。 */
    public function methods(): array
    {
        return $this->methods;
    }
    /** 返回声明路径，用于诊断与构建身份，不替代参数匹配。 */
    public function path(): string
    {
        return $this->path;
    }
    /** 返回反向 URL 使用的路由名；未命名时为 null。 */
    public function name(): ?string
    {
        return $this->name;
    }
    /** @internal 按段返回静态 1、参数 0 的排序键，避免登记顺序决定路由。 */
    public function priority(): string
    {
        return $this->priority;
    }

    /**
     * 解码路径并匹配各段约束，无效或不匹配时返回 null。
     * @return array<string, string>|null 匹配成功的参数；静态路由为空数组。
     */
    public function match(string $path): ?array
    {
        $pieces = self::decodePath($path);
        return $pieces === null ? null : $this->matchSegments($pieces);
    }

    /** @internal Router 对请求路径统一解码后复用，避免每条路由重复处理原始字节。 */
    public function matchSegments(array $pieces): ?array
    {
        if (count($pieces) !== count($this->segments)) {
            return null;
        }
        $parameters = [];
        foreach ($this->segments as $index => $segment) {
            $value = $pieces[$index];
            if (array_key_exists('literal', $segment)) {
                if ($segment['literal'] !== $value) {
                    return null;
                }
            } elseif ($value === '' || preg_match($segment['pattern'], $value) !== 1) {
                return null;
            } else {
                $parameters[$segment['parameter']] = $value;
            }
        }
        return $parameters;
    }

    /**
     * 按同一参数约束生成编码后的相对 URL，拒绝缺失或多余路径参数。
     * @param array<string, string|int> $parameters 路径参数。
     * @param array<string, mixed> $query 交给 RFC 3986 查询编码器的参数。
     * @throws InvalidArgumentException 路径参数缺失、非法或未使用。
     */
    public function url(array $parameters = [], array $query = [], string $fragment = ''): string
    {
        $pieces = [];
        $used = [];
        foreach ($this->segments as $segment) {
            if (array_key_exists('literal', $segment)) {
                $value = $segment['literal'];
            } else {
                $parameter = $segment['parameter'];
                $value = $parameters[$parameter] ?? null;
                if (!is_string($value) && !is_int($value)) {
                    throw new InvalidArgumentException('路由缺少字符串或整数参数：' . $parameter);
                }
                $value = (string) $value;
                if ($value === '' || !self::validSegment($value) || preg_match($segment['pattern'], $value) !== 1) {
                    throw new InvalidArgumentException('路由参数不符合约束：' . $parameter);
                }
                $used[$parameter] = true;
            }
            $pieces[] = rawurlencode($value);
        }
        if (array_diff_key($parameters, $used) !== []) {
            throw new InvalidArgumentException('反向 URL 包含未知路径参数');
        }
        $suffix = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return '/' . implode('/', $pieces) . ($suffix === '' ? '' : '?' . $suffix) . ($fragment === '' ? '' : '#' . rawurlencode($fragment));
    }

    /** 同形参数约束不以注册顺序决胜；任意 PCRE 的交集按保守规则拒绝。 */
    public function ambiguousWith(RouteDefinition $other): bool
    {
        if ($this->priority !== $other->priority || array_intersect($this->methods, $other->methods) === []) {
            return false;
        }
        foreach ($this->segments as $index => $segment) {
            if (isset($segment['literal']) && $segment['literal'] !== $other->segments[$index]['literal']) {
                return false;
            }
        }
        return true;
    }

    /**
     * 按段解码一次，拒绝斜线编码、点段、控制字符或非法 UTF-8。
     * @return list<string>|null 根路径为空列表，非法路径返回 null。
     */
    public static function decodePath(string $path): ?array
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('/%(?![A-Fa-f0-9]{2})/', $path)) {
            return null;
        }
        if ($path === '/') {
            return [];
        }
        $segments = explode('/', substr($path, 1));
        foreach ($segments as $index => $value) {
            $decoded = rawurldecode($value);
            if (!self::validSegment($decoded)) {
                return null;
            }
            $segments[$index] = $decoded;
        }
        return $segments;
    }

    private static function validSegment(string $value): bool
    {
        return $value !== '.' && $value !== '..' && !preg_match('/[\\\\\/\x00-\x1f\x7f]/', $value) && preg_match('//u', $value) === 1;
    }
}
