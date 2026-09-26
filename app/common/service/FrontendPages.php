<?php

declare(strict_types=1);

namespace app\common\service;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Message\Factory;

/** 在既有HTTP策略之后提供清单内页面；未匹配的路径继续进入业务路由。 */
final class FrontendPages
{
    private array $files;

    public function __construct(private FrontendAssets $assets)
    {
        $assets->verifyInstalled();
        $this->files = $assets->manifest();
    }

    /** Hash路由只需要根入口；不存在的资源不回退为HTML，也不覆盖API错误响应。 */
    public function respond(ServerRequestInterface $request, Factory $messages): ?ResponseInterface
    {
        $uri = $request->getUri()->getPath();
        $path = $uri === '/' ? 'index.html' : substr($uri, 1);
        if (!isset($this->files[$path]) || str_contains($uri, '%') || str_contains($uri, '\\')) {
            return null;
        }
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $messages->createResponse(405)->withHeader('Allow', 'GET, HEAD');
        }
        $entry = $this->files[$path];
        $etag = '"' . $entry['sha256'] . '"';
        $cache = str_starts_with($path, 'assets/') ? 'public, max-age=31536000, immutable' : 'no-cache';
        if ($request->getHeaderLine('If-None-Match') === $etag) {
            return $messages->createResponse(304)->withHeader('ETag', $etag)->withHeader('Cache-Control', $cache);
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'html' => 'text/html; charset=utf-8',
            'js', 'mjs' => 'text/javascript; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'json', 'map' => 'application/json',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'ico' => 'image/x-icon',
            'webp' => 'image/webp',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'txt', 'md' => 'text/plain; charset=utf-8',
            default => 'application/octet-stream',
        };
        return $messages->createResponse(200)->withHeader('Content-Type', $mime)
            ->withHeader('X-Content-Type-Options', 'nosniff')->withHeader('ETag', $etag)
            ->withHeader('Cache-Control', $cache)->withHeader('Content-Length', (string) $entry['bytes'])
            ->withBody($request->getMethod() === 'HEAD' ? $messages->createStream('') : $messages->createStreamFromFile($this->assets->file($path)));
    }
}
