<?php

declare(strict_types=1);

namespace Type\Core\Http;

use Psr\Http\Message\ResponseInterface;
use Swoole\Http\Response;
use Throwable;
use Type\Runtime\CapacityException;
use Type\Runtime\ExecutionScope;

/** 分块发送并限制背压等待；第一块发出后失败只断开，不产生第二份 HTTP 响应。 */
final class ResponseEmitter
{
    /** 沿用请求剩余预算唤醒发送协程；定时器不跨协程关闭作用域持有的资源。 */
    public function emit(ResponseInterface $message, Response $output, bool $head, ?ExecutionScope $scope = null): void
    {
        $sent = false;
        $sendTimer = 0;
        try {
            if ($scope !== null) {
                $scope->assertActive();
                $remaining = $scope->deadline()->remaining();
                if ($remaining !== null) {
                    $coroutine = \Swoole\Coroutine::getCid();
                    // Swoole的send_yield默认无限等待；只唤醒本次发送协程，资源仍由原所有者清理。
                    $sendTimer = (int) \Swoole\Timer::after(max(1, (int) ceil($remaining * 1000)), static function () use ($coroutine): void {
                        \Swoole\Coroutine::cancel($coroutine);
                    });
                    if ($sendTimer < 1) {
                        throw new \RuntimeException('无法设置HTTP响应发送截止');
                    }
                }
            }
            $stream = $message->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $size = $stream->getSize();
            $status = $message->getStatusCode();
            $noBody = $head || $status < 200 || in_array($status, [204, 304], true);
            $chunk = $noBody || $stream->eof() ? '' : $stream->read(16384);
            if (!$noBody && (($size !== null && strlen($chunk) > $size) || ($chunk === '' && (!$stream->eof() || ($size !== null && $size !== 0))))) {
                throw new \RuntimeException('响应流长度或读取进度无效');
            }
            $output->status($status);
            foreach ($message->getHeaders() as $name => $values) {
                if (in_array(strtolower($name), ['content-length', 'transfer-encoding', 'connection'], true)) {
                    continue;
                }
                $output->header($name, $values);
            }
            if ($head && $size !== null && !in_array($status, [204, 304], true) && $status >= 200) {
                $output->header('Content-Length', (string) $size);
            }
            if ($noBody) {
                $sent = true;
                $output->end();
                return;
            }
            $written = 0;
            while ($chunk !== '') {
                if ($scope !== null) {
                    $scope->assertActive();
                }
                $written += strlen($chunk);
                if ($size !== null && $written > $size) {
                    throw new \RuntimeException('响应流长度超过声明');
                }
                $sent = true;
                if (!$output->write($chunk)) {
                    $output->close();
                    return;
                }
                $chunk = $stream->eof() ? '' : $stream->read(16384);
            }
            if (!$stream->eof() || ($size !== null && $written !== $size)) {
                throw new \RuntimeException('响应流提前结束');
            }
            $sent = true;
            $output->end();
        } catch (Throwable $error) {
            if ($sent) {
                $output->close();
            } else {
                $timeout = $error instanceof \Type\Runtime\TaskException && $error->errorCode() === 'deadline_exceeded';
                $capacity = CapacityException::matches($error);
                $body = $timeout ? '{"error":"deadline_exceeded"}' : ($capacity ? '{"error":"resource_capacity_exceeded"}' : '{"error":"internal_error"}');
                $output->status($timeout ? 504 : ($capacity ? 503 : 500));
                if ($capacity) {
                    $output->header('Retry-After', '1');
                }
                $output->header('Content-Type', 'application/json');
                $output->header('Content-Length', (string) strlen($body));
                $output->end($head ? '' : $body);
            }
        } finally {
            if ($sendTimer > 0 && \Swoole\Timer::exists($sendTimer)) {
                \Swoole\Timer::clear($sendTimer);
            }
            try {
                $message->getBody()->close();
            } catch (Throwable) {
                fwrite(STDERR, "HTTP 响应流关闭失败。\n");
            }
        }
    }
}
