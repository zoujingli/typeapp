<?php

declare(strict_types=1);

namespace Type\Core\Http;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Type\Runtime\QueryString;
use Type\Runtime\QueryStringException;

final class RequestPolicy implements MiddlewareInterface
{
    private array $hosts;
    private array $proxies;

    public function __construct(array $allowedAuthorities, array $trustedProxies = [])
    {
        if ($allowedAuthorities === []) {
            throw new InvalidArgumentException('必须明确 HTTP Host 白名单');
        }
        $this->hosts = [];
        foreach ($allowedAuthorities as $host) {
            if (!is_string($host)) {
                throw new InvalidArgumentException('HTTP Host 必须是字符串');
            }
            $this->hosts[] = $this->authority($host);
        }
        $this->proxies = [];
        foreach ($trustedProxies as $proxy) {
            if (!is_string($proxy)) {
                throw new InvalidArgumentException('代理必须是 IP/CIDR');
            }
            $parts = explode('/', $proxy, 2);
            $packed = @inet_pton($parts[0]);
            $bits = isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : (isset($parts[1]) ? -1 : ($packed === false ? -1 : strlen($packed) * 8));
            if ($packed === false || $bits < 0 || $bits > strlen($packed) * 8) {
                throw new InvalidArgumentException('代理 IP/CIDR 无效');
            }
            $this->proxies[] = [$packed, $bits];
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $peer = (string) ($request->getServerParams()['remote_addr'] ?? $request->getServerParams()['REMOTE_ADDR'] ?? '');
        if (filter_var($peer, FILTER_VALIDATE_IP) === false) {
            throw new HttpError(400, 'invalid_peer');
        }
        $host = $this->authority($request->getHeaderLine('Host'));
        $scheme = strtolower($request->getUri()->getScheme());
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new HttpError(400, 'invalid_scheme');
        }
        $client = $peer;
        if ($this->trusted($peer)) {
            // 明确采用单套 X-Forwarded 头，代理应覆盖外来值；多头列表拒绝。
            if ($request->hasHeader('Forwarded')) {
                throw new HttpError(400, 'forwarded_conflict');
            }
            $forwardedHost = $request->getHeaderLine('X-Forwarded-Host');
            $forwardedProto = strtolower($request->getHeaderLine('X-Forwarded-Proto'));
            if (($forwardedHost === '') !== ($forwardedProto === '')) {
                throw new HttpError(400, 'forwarded_incomplete');
            }
            if ($forwardedHost !== '') {
                $host = $this->authority($forwardedHost);
                if (!in_array($forwardedProto, ['http', 'https'], true)) {
                    throw new HttpError(400, 'invalid_forwarded_proto');
                }
                $scheme = $forwardedProto;
            }
            if ($request->hasHeader('X-Forwarded-For')) {
                $chain = array_map('trim', explode(',', $request->getHeaderLine('X-Forwarded-For')));
                if (count($chain) > 32) {
                    throw new HttpError(400, 'proxy_chain_too_long');
                }
                foreach ($chain as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                        throw new HttpError(400, 'invalid_forwarded_for');
                    }
                }
                for ($index = count($chain) - 1; $index >= 0 && $this->trusted($client); $index--) {
                    $client = $chain[$index];
                }
            }
        }
        if (!in_array($host, $this->hosts, true)) {
            throw new HttpError(400, 'untrusted_host');
        }
        $target = $request->getAttribute('type.raw-target', $request->getRequestTarget());
        if (!is_string($target) || str_contains($target, '#') || strlen($target) > 32768) {
            throw new HttpError(400, 'invalid_target');
        }
        $parts = explode('?', $target, 2);
        $path = $parts[0];
        $query = $parts[1] ?? '';
        $segments = RouteDefinition::decodePath($path);
        if ($segments === null || str_contains($path, '//')) {
            throw new HttpError(400, 'ambiguous_path');
        }
        try {
            $parameters = QueryString::parse($query);
        } catch (QueryStringException $error) {
            throw new HttpError($error->status(), 'invalid_query');
        }
        $path = '/' . implode('/', array_map('rawurlencode', $segments));
        $query = QueryString::encode($parameters);
        $parsed = parse_url($scheme . '://' . $host);
        $psrUri = $request->getUri()->withScheme($scheme)->withHost($parsed['host'])->withPort($parsed['port'] ?? null)
            ->withUserInfo('')->withPath($path)->withQuery($query)->withFragment('');
        $canonical = new CanonicalRequest((string) $psrUri, $path, $query, $segments, $client);
        foreach (['Forwarded', 'X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Forwarded-For', 'X-Forwarded-Port'] as $header) {
            $request = $request->withoutHeader($header);
        }
        $request = $request->withUri($psrUri)->withHeader('Host', $host)->withRequestTarget($path . ($query === '' ? '' : '?' . $query))
            ->withQueryParams($parameters)->withAttribute('type.request', $canonical);
        return $handler->handle($request);
    }

    private function authority(string $authority): string
    {
        if ($authority === '' || preg_match('/[\s\x00-\x1f\x7f,@\/\\\\?#%]/', $authority)) {
            throw new HttpError(400, 'invalid_host');
        }
        $uri = parse_url('http://' . $authority);
        if (!is_array($uri) || !isset($uri['host']) || isset($uri['user']) || isset($uri['pass']) || isset($uri['path']) || isset($uri['query'])) {
            throw new HttpError(400, 'invalid_host');
        }
        $host = strtolower($uri['host']);
        $ip = trim($host, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            if (strlen($host) > 253 || str_ends_with($host, '.')) {
                throw new HttpError(400, 'invalid_host');
            }
            foreach (explode('.', $host) as $label) {
                if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label)) {
                    throw new HttpError(400, 'invalid_host');
                }
            }
        }
        return $host . (isset($uri['port']) ? ':' . $uri['port'] : '');
    }

    private function trusted(string $ip): bool
    {
        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        foreach ($this->proxies as [$network, $bits]) {
            if (strlen($network) !== strlen($packed)) {
                continue;
            }
            $bytes = intdiv($bits, 8);
            $remaining = $bits % 8;
            if (substr($network, 0, $bytes) !== substr($packed, 0, $bytes)) {
                continue;
            }
            if ($remaining === 0 || ((ord($packed[$bytes]) ^ ord($network[$bytes])) & (255 << (8 - $remaining))) === 0) {
                return true;
            }
        }
        return false;
    }
}
