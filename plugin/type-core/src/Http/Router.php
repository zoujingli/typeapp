<?php

declare(strict_types=1);

namespace Type\Core\Http;

use Closure;
use InvalidArgumentException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/** 显式路由及处理链容器；首次处理请求后冻结声明，防止运行中修改路由表。 */
final class Router implements RequestHandlerInterface
{
    private array $routes = [];
    private array $staticRoutes = [];
    private array $parameterRoutes = [];
    private array $middleware = [];
    private array $named = [];
    private bool $started = false;
    private ResponseFactoryInterface $responses;
    private StreamFactoryInterface $streams;

    /** 注入错误响应与正文工厂；路由定义在首次 handle() 前完成登记。 */
    public function __construct(ResponseFactoryInterface $responses, StreamFactoryInterface $streams)
    {
        $this->responses = $responses;
        $this->streams = $streams;
    }

    /** @param Closure(): RequestHandlerInterface $handler 零参数处理器工厂。 */
    public function add(string $method, string $path, Closure $handler, ?string $name = null): void
    {
        $method = strtoupper($method);
        if (!preg_match('/^[!#$%&\x27*+.^_`|~0-9A-Z-]+$/D', $method) || !str_starts_with($path, '/') || str_contains($path, '?') || str_contains($path, '#')) {
            throw new InvalidArgumentException('路由方法或路径无效');
        }
        $pieces = RouteDefinition::decodePath($path);
        if ($pieces === null || str_contains($path, '{') || str_contains($path, '}')) {
            throw new InvalidArgumentException('add 需要完整静态路径；参数路由请使用构建声明');
        }
        $segments = [];
        foreach ($pieces as $piece) {
            $segments[] = ['literal' => $piece];
        }
        $this->register(new RouteDefinition([$method], $path, $segments, $name), $handler);
    }

    /**
     * @param Closure(): RequestHandlerInterface $handler 零参数处理器工厂。
     * @param list<Closure(): \Psr\Http\Server\MiddlewareInterface> $middleware 按声明顺序调用的零参数中间件工厂。
     */
    public function register(RouteDefinition $definition, Closure $handler, array $middleware = []): void
    {
        $this->assertConfigurable();
        foreach ($middleware as $factory) {
            if (!$factory instanceof Closure) {
                throw new InvalidArgumentException('路由中间件需要工厂闭包');
            }
        }
        $name = $definition->name();
        if ($name !== null && isset($this->named[$name])) {
            throw new InvalidArgumentException('路由名称重复：' . $name);
        }
        foreach ($this->routes as $route) {
            if ($definition->ambiguousWith($route['definition'])) {
                throw new InvalidArgumentException('路由重复或同形参数歧义：' . $definition->path() . ' / ' . $route['definition']->path());
            }
        }
        $route = ['definition' => $definition, 'handler' => $handler, 'middleware' => array_values($middleware)];
        $this->routes[] = $route;
        if (!str_contains($definition->priority(), '0')) {
            $this->staticRoutes[$definition->url()][] = $route;
        } else {
            $this->parameterRoutes[strlen($definition->priority())][] = $route;
        }
        if ($name !== null) {
            $this->named[$name] = $definition;
        }
    }

    /**
     * 从命名路由生成 URL，并拒绝被更具体静态路由覆盖的结果。
     * @param array<string, string|int> $parameters 必须完整且仅包含该路由路径参数。
     * @param array<string, mixed> $query RFC 3986 编码的查询参数。
     * @throws InvalidArgumentException 路由未知、参数不符或生成目标有歧义。
     */
    public function url(string $name, array $parameters = [], array $query = [], string $fragment = ''): string
    {
        if (!isset($this->named[$name])) {
            throw new InvalidArgumentException('未知路由名称：' . $name);
        }
        $definition = $this->named[$name];
        $path = $definition->url($parameters);
        foreach ($this->routes as $route) {
            if (strcmp($route['definition']->priority(), $definition->priority()) > 0 && $route['definition']->match($path) !== null) {
                throw new InvalidArgumentException('生成 URL 被更具体的静态路由覆盖：' . $name);
            }
        }
        return $definition->url($parameters, $query, $fragment);
    }

    /** @param Closure(): \Psr\Http\Server\MiddlewareInterface $factory 零参数中间件工厂。 */
    public function middleware(Closure $factory): void
    {
        $this->assertConfigurable();
        $this->middleware[] = $factory;
    }

    /**
     * 按规范路径和静态优先级匹配，依次执行全局及路由中间件和处理器。
     * @throws HttpError 已校验的规范请求 URI 被中间步骤改写。
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->started = true;
        $path = $request->getUri()->getPath();
        $canonicalRequest = $request->getAttribute('type.request');
        if ($canonicalRequest instanceof CanonicalRequest && $canonicalRequest->uri() !== (string) $request->getUri()) {
            throw new HttpError(400, 'canonical_request_changed');
        }
        $pieces = $canonicalRequest instanceof CanonicalRequest ? $canonicalRequest->segments() : RouteDefinition::decodePath($path);
        if ($pieces === null) {
            return $this->error(404, 'not_found');
        }
        $encoded = [];
        foreach ($pieces as $piece) {
            $encoded[] = rawurlencode($piece);
        }
        $canonical = '/' . implode('/', $encoded);
        $possible = $this->staticRoutes[$canonical] ?? ($this->parameterRoutes[count($pieces)] ?? []);
        $candidates = [];
        $priority = null;
        foreach ($possible as $route) {
            $definition = $route['definition'];
            $parameters = $definition->matchSegments($pieces);
            if ($parameters === null) {
                continue;
            }
            $order = $definition->priority();
            if ($priority === null || strcmp($order, $priority) > 0) {
                $priority = $order;
                $candidates = [];
            }
            if ($order === $priority) {
                $candidates[] = $route + ['parameters' => $parameters];
            }
        }
        if ($candidates === []) {
            return $this->error(404, 'not_found');
        }
        $method = strtoupper($request->getMethod());
        $selected = null;
        $fallback = null;
        $allowed = [];
        foreach ($candidates as $route) {
            $methods = $route['definition']->methods();
            $allowed = array_merge($allowed, $methods);
            if (in_array($method, $methods, true)) {
                $selected = $route;
            }
            if (in_array('GET', $methods, true)) {
                $allowed[] = 'HEAD';
                $fallback = $route;
            }
        }
        if ($selected === null && $method === 'HEAD') {
            $selected = $fallback;
        }
        if ($selected === null) {
            $allowed = array_values(array_unique($allowed));
            sort($allowed);
            return $this->error(405, 'method_not_allowed')->withHeader('Allow', implode(', ', $allowed));
        }
        $handler = ($selected['handler'])();
        if (!$handler instanceof RequestHandlerInterface) {
            throw new RuntimeException('路由工厂没有返回请求处理器');
        }

        $request = $request->withAttribute('type.route', $selected['definition']->name())->withAttribute('type.route.params', $selected['parameters']);
        return (new Pipeline(array_merge($this->middleware, $selected['middleware']), $handler))->handle($request);
    }

    private function assertConfigurable(): void
    {
        if ($this->started) {
            throw new RuntimeException('路由开始处理请求后不能改变注册表');
        }
    }

    private function error(int $status, string $code): ResponseInterface
    {
        return $this->responses->createResponse($status)->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream((string) json_encode(['error' => $code], JSON_THROW_ON_ERROR)));
    }
}
