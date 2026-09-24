<?php

declare(strict_types=1);

namespace Type\Core\Http\Message;

use InvalidArgumentException;
use Psr\Http\Message\UriInterface;

/** PSR-7 URI 值对象，规范协议及主机大小写并保留正确百分号编码。 */
final class Uri implements UriInterface
{
    private string $scheme = '';
    private string $userInfo = '';
    private string $host = '';
    private ?int $port = null;
    private string $path = '';
    private string $query = '';
    private string $fragment = '';

    /** 解析 URI，拒绝控制字符或无效结构，并编码非 ASCII 和组件中的非法字节。 */
    public function __construct(string $uri = '')
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $uri)) {
            throw new InvalidArgumentException('URI 不允许控制字符');
        }
        $uri = (string) preg_replace_callback('/[^\x21-\x7e]/', static fn (array $match): string => rawurlencode((string) $match[0]), $uri);
        $parts = parse_url($uri);
        if ($parts === false) {
            throw new InvalidArgumentException('URI 无法解析');
        }
        $this->scheme = $this->normalizeScheme((string) ($parts['scheme'] ?? ''));
        $this->host = $this->normalizeHost((string) ($parts['host'] ?? ''));
        $this->port = isset($parts['port']) ? (int) $parts['port'] : null;
        $this->path = $this->encode((string) ($parts['path'] ?? ''), '/:@');
        $this->query = $this->encode((string) ($parts['query'] ?? ''), '/?:@');
        $this->fragment = $this->encode((string) ($parts['fragment'] ?? ''), '/?:@');
        $user = (string) ($parts['user'] ?? '');
        if ($user !== '') {
            $this->userInfo = $this->encode($user, '') . (isset($parts['pass']) ? ':' . $this->encode((string) $parts['pass'], ':') : '');
        }
    }

    /** 返回规范为小写的协议名，不含冒号。 */
    public function getScheme(): string
    {
        return $this->scheme;
    }

    /** 组合用户信息、主机和非默认端口；缺少主机时返回空文本。 */
    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }
        $port = $this->getPort();
        return ($this->userInfo === '' ? '' : $this->userInfo . '@') . $this->host . ($port === null ? '' : ':' . $port);
    }

    /** 返回已编码用户信息，不含尾部 @；可能包含秘密，不宜用于日志。 */
    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    /** 返回小写主机名或带方括号的 IP 字面值。 */
    public function getHost(): string
    {
        return $this->host;
    }

    /** 返回显式非默认端口；HTTP 80、HTTPS 443 或未声明端口返回 null。 */
    public function getPort(): ?int
    {
        if (($this->scheme === 'http' && $this->port === 80) || ($this->scheme === 'https' && $this->port === 443)) {
            return null;
        }
        return $this->port;
    }

    /** 返回已编码路径，不对点段进行自动解析。 */
    public function getPath(): string
    {
        return $this->path;
    }

    /** 返回已编码查询文本，不含前导问号。 */
    public function getQuery(): string
    {
        return $this->query;
    }

    /** 返回已编码片段文本，不含前导井号。 */
    public function getFragment(): string
    {
        return $this->fragment;
    }

    /** 校验协议名并在副本中保存小写形式，空文本移除协议。 */
    public function withScheme(string $scheme): UriInterface
    {
        $copy = clone $this;
        $copy->scheme = $this->normalizeScheme($scheme);
        return $copy;
    }

    /** 编码用户与可选密码并返回副本，空用户名会移除全部用户信息。 */
    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        $copy = clone $this;
        $copy->userInfo = $user === '' ? '' : $this->encode($user, '') . ($password === null ? '' : ':' . $this->encode($password, ':'));
        return $copy;
    }

    /** 校验主机名或 IP 字面值并返回小写副本，空文本移除主机。 */
    public function withHost(string $host): UriInterface
    {
        $copy = clone $this;
        $copy->host = $this->normalizeHost($host);
        return $copy;
    }

    /** 在副本中设置 0 至 65535 端口，null 移除端口；展示时隐藏默认端口。 */
    public function withPort(?int $port): UriInterface
    {
        if ($port !== null && ($port < 0 || $port > 65535)) {
            throw new InvalidArgumentException('URI 端口超出范围');
        }
        $copy = clone $this;
        $copy->port = $port;
        return $copy;
    }

    /** 编码路径中的非法字节并返回副本，已有效编码的百分号保持不变。 */
    public function withPath(string $path): UriInterface
    {
        $copy = clone $this;
        $copy->path = $this->encode($path, '/:@');
        return $copy;
    }

    /** 编码查询组件并返回副本，调用方不传前导问号。 */
    public function withQuery(string $query): UriInterface
    {
        $copy = clone $this;
        $copy->query = $this->encode($query, '/?:@');
        return $copy;
    }

    /** 编码片段组件并返回副本，调用方不传前导井号。 */
    public function withFragment(string $fragment): UriInterface
    {
        $copy = clone $this;
        $copy->fragment = $this->encode($fragment, '/?:@');
        return $copy;
    }

    /** 按 URI 组件组合文本，并处理空 authority 下容易误解为协议或主机的路径。 */
    public function __toString(): string
    {
        $authority = $this->getAuthority();
        $path = $this->path;
        if ($authority !== '' && $path !== '' && !str_starts_with($path, '/')) {
            $path = '/' . $path;
        } elseif ($authority === '' && str_starts_with($path, '//')) {
            $path = '/' . ltrim($path, '/');
        } elseif ($authority === '' && $this->scheme === '' && strpos(explode('/', $path)[0], ':') !== false) {
            $path = './' . $path;
        }
        return ($this->scheme === '' ? '' : $this->scheme . ':') . ($authority === '' ? '' : '//' . $authority)
            . $path . ($this->query === '' ? '' : '?' . $this->query) . ($this->fragment === '' ? '' : '#' . $this->fragment);
    }

    private function normalizeScheme(string $scheme): string
    {
        if ($scheme !== '' && !preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*$/D', $scheme)) {
            throw new InvalidArgumentException('URI 协议无效');
        }
        return strtolower($scheme);
    }

    private function normalizeHost(string $host): string
    {
        if ($host === '') {
            return '';
        }
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $address = substr($host, 1, -1);
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false
                && !preg_match('/^v[0-9a-f]+\.[a-z0-9._~!$&\x27()*+,;=:-]+$/iD', $address)) {
                throw new InvalidArgumentException('URI IPv6 地址无效');
            }
        } elseif (!preg_match('/^(?:[a-z0-9._~!$&\x27()*+,;=-]|%[a-f0-9]{2})+$/iD', $host)) {
            throw new InvalidArgumentException('URI 主机名无效');
        }
        return strtolower($host);
    }

    private function encode(string $value, string $extra): string
    {
        $pattern = '/(?:[^a-zA-Z0-9._~!$&\x27()*+,;=%' . preg_quote($extra, '/') . '-]|%(?![a-fA-F0-9]{2}))/';
        return (string) preg_replace_callback($pattern, static fn (array $match): string => rawurlencode((string) $match[0]), $value);
    }
}
