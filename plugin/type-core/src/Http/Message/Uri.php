<?php

declare(strict_types=1);

namespace Type\Core\Http\Message;

use InvalidArgumentException;
use Psr\Http\Message\UriInterface;

final class Uri implements UriInterface
{
    private string $scheme = '';
    private string $userInfo = '';
    private string $host = '';
    private ?int $port = null;
    private string $path = '';
    private string $query = '';
    private string $fragment = '';

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

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }
        $port = $this->getPort();
        return ($this->userInfo === '' ? '' : $this->userInfo . '@') . $this->host . ($port === null ? '' : ':' . $port);
    }

    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        if (($this->scheme === 'http' && $this->port === 80) || ($this->scheme === 'https' && $this->port === 443)) {
            return null;
        }
        return $this->port;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

    public function withScheme(string $scheme): UriInterface
    {
        $copy = clone $this;
        $copy->scheme = $this->normalizeScheme($scheme);
        return $copy;
    }

    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        $copy = clone $this;
        $copy->userInfo = $user === '' ? '' : $this->encode($user, '') . ($password === null ? '' : ':' . $this->encode($password, ':'));
        return $copy;
    }

    public function withHost(string $host): UriInterface
    {
        $copy = clone $this;
        $copy->host = $this->normalizeHost($host);
        return $copy;
    }

    public function withPort(?int $port): UriInterface
    {
        if ($port !== null && ($port < 0 || $port > 65535)) {
            throw new InvalidArgumentException('URI 端口超出范围');
        }
        $copy = clone $this;
        $copy->port = $port;
        return $copy;
    }

    public function withPath(string $path): UriInterface
    {
        $copy = clone $this;
        $copy->path = $this->encode($path, '/:@');
        return $copy;
    }

    public function withQuery(string $query): UriInterface
    {
        $copy = clone $this;
        $copy->query = $this->encode($query, '/?:@');
        return $copy;
    }

    public function withFragment(string $fragment): UriInterface
    {
        $copy = clone $this;
        $copy->fragment = $this->encode($fragment, '/?:@');
        return $copy;
    }

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
