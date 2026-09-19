<?php

declare(strict_types=1);

namespace Type\Core\Http;

/**
 * HTTP 运行引擎；业务只依赖构造时注入的 PSR handler。
 *
 * 监听、协议和并发能力由具体引擎声明，不静默切换运行引擎。
 */
interface HttpServerInterface
{
    /** 阻塞运行至停止；绑定失败或缺少所选引擎依赖时抛出异常。 */
    public function serve(string $host, int $port): void;

    /** 停止接收新请求，并按已配置的排空预算结束当前执行。 */
    public function stop(): void;
}
