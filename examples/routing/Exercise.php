<?php

declare(strict_types=1);

namespace TypeApp\RoutingExample;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use InvalidArgumentException;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\RouteDefinition;
use Type\Core\Http\Router;

/** 回显已匹配路由名和参数，供离线 HTTP 消息验证。 */
final class EchoHandler implements RequestHandlerInterface
{
    /** 将路由属性编码为 JSON，不重新解析或猜测路径参数。 */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $messages = new Factory();
        return $messages->createResponse()->withBody($messages->createStream((string) json_encode([
            'name' => $request->getAttribute('type.route'), 'parameters' => $request->getAttribute('type.route.params'),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
    }
}

/** 在进程内验证路由匹配、URL 生成与拒绝路径，不启动网络监听。 */
final class Exercise
{
    /** 构造固定路由并覆盖编码、约束与冲突行为，失败直接抛出。 */
    public static function run(): void
    {
        $messages = new Factory();
        $router = new Router($messages, $messages);
        $router->register(new RouteDefinition(['GET'], '/documents/{title}', [
            ['literal' => 'documents'], ['parameter' => 'title', 'pattern' => '~\\A(?:[^/]+)\\z~uD'],
        ], 'documents.show'), static fn (): EchoHandler => new EchoHandler());
        $url = $router->url('documents.show', ['title' => '甲 乙+%2F'], ['q' => 'a b+'], '片段');
        self::check($url === '/documents/%E7%94%B2%20%E4%B9%99%2B%252F?q=a%20b%2B#%E7%89%87%E6%AE%B5', '命名 URL 没有逐段编码');
        $response = $router->handle($messages->createServerRequest('GET', $url));
        self::check($response->getStatusCode() === 200 && json_decode((string) $response->getBody(), true) === [
            'name' => 'documents.show', 'parameters' => ['title' => '甲 乙+%2F'],
        ], '路由参数与 URL 往返不一致');
        self::check($router->handle($messages->createServerRequest('GET', '/missing'))->getStatusCode() === 404, '不存在路径没有返回 404');
        $method = $router->handle($messages->createServerRequest('POST', '/documents/value'));
        self::check($method->getStatusCode() === 405 && $method->getHeaderLine('Allow') === 'GET, HEAD', '405 允许方法错误');
        self::check($router->handle($messages->createServerRequest('GET', '/documents/a%2Fb'))->getStatusCode() === 404, '编码斜线不能跨越参数段');
        foreach ([[], ['title' => null], ['title' => ''], ['title' => '.'], ['title' => '..'], ['title' => 'a/b'], ['title' => 'a\\b'],
            ['title' => "a\0b"], ['title' => 'valid', 'typo' => 'extra']] as $invalid) {
            $rejected = false;
            try {
                $router->url('documents.show', $invalid);
            } catch (InvalidArgumentException $error) {
                $rejected = true;
            }
            self::check($rejected, '命名 URL 没有拒绝缺失、未知或不安全的参数');
        }
        $unknown = false;
        try {
            $router->url('unknown');
        } catch (InvalidArgumentException $error) {
            $unknown = true;
        }
        self::check($unknown, '未知命名路由没有报错');
        $frozen = false;
        try {
            $router->add('GET', '/late', static fn (): EchoHandler => new EchoHandler());
        } catch (RuntimeException $error) {
            $frozen = true;
        }
        self::check($frozen, '开始处理请求后仍允许修改路由表');
        $priority = new Router($messages, $messages);
        $priority->register(new RouteDefinition(['POST'], '/static/{value}', [
            ['literal' => 'static'], ['parameter' => 'value', 'pattern' => '~\\A(?:[^/]+)\\z~uD'],
        ], 'dynamic'), static fn (): EchoHandler => new EchoHandler());
        $priority->add('GET', '/static/new', static fn (): EchoHandler => new EchoHandler(), 'literal');
        $priority->add('HEAD', '/static/new', static fn (): EchoHandler => new EchoHandler(), 'head');
        $priority->add('M-SEARCH', '/discovery', static fn (): EchoHandler => new EchoHandler(), 'discovery');
        $duplicate = false;
        try {
            $priority->add('GET', '/other', static fn (): EchoHandler => new EchoHandler(), 'literal');
        } catch (InvalidArgumentException $error) {
            $duplicate = true;
        }
        self::check($duplicate, '运行注册没有拒绝重复名称');
        self::check($priority->handle($messages->createServerRequest('POST', '/static/new'))->getStatusCode() === 405, '静态路径方法不匹配时退回了参数路由');
        $head = $priority->handle($messages->createServerRequest('HEAD', '/static/new'));
        self::check(json_decode((string) $head->getBody(), true)['name'] === 'head', '显式 HEAD 没有优先于 GET 回退');
        self::check($priority->handle($messages->createServerRequest('M-SEARCH', '/discovery'))->getStatusCode() === 200, '合法扩展 HTTP 方法没有匹配');
        $shadowed = false;
        try {
            $priority->url('dynamic', ['value' => 'new']);
        } catch (InvalidArgumentException $error) {
            $shadowed = true;
        }
        self::check($shadowed, '反向 URL 生成了会落到其他静态路由的地址');
        $literal = \std::any('safe');
        $immutable = new RouteDefinition(['GET'], '/safe', [['literal' => &$literal]], 'safe');
        $literal = 'changed';
        self::check($immutable->url() === '/safe', '编译路由模型保留了外部可变引用');
        $authority = false;
        try {
            $priority = new Router($messages, $messages);
            $priority->add('GET', '//outside.test', static fn (): EchoHandler => new EchoHandler(), 'outside');
        } catch (InvalidArgumentException $error) {
            $authority = true;
        }
        self::check($authority, '静态路由允许生成外部 authority URL');
    }

    /**
     * 把匹配断言失败转为明确异常。
     *
     * @throws RuntimeException 断言条件不成立。
     */
    public static function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
