# TCP

TCP 在两个端点之间提供可靠、有序的字节流，适合自定义设备协议、网关和服务连接。TypeApp 的 `Type\Core\TcpSocket` 复用 Swoole 协程 Socket，Plugins 负责资源归属与有界操作，应用负责报文格式、身份和业务回执；业务与组件源码由 TypePHP 编译，见[通信导读](../communications.md)。

## 字节流与消息边界

TCP 不保留发送调用的边界。发送两次 `hello`，接收端可能一次读到 `hellohello`，也可能分几次读到。`receive()` 返回片段，不能把一次读取当成一条消息。

| 分帧方式 | 适用情况 | 必须定义的规则 |
| --- | --- | --- |
| 定长 | 固定布局的设备报文 | 长度、字节序、版本与非法字段 |
| 分隔符 | 文本行协议 | 编码、转义、单条最大长度 |
| 长度前缀 | 可变长度消息 | 头长度、字节序、载荷上限、接收总期限 |
| EOF 结束 | 一次请求后结束发送 | 半关闭与响应完成；不用于连续多次请求 |

TCP 保证连接中的字节顺序；连接失败时，应用可能无法确定对端处理了多少内容。用业务消息 ID、去重记录和回执解决重复提交，不能以 TCP ACK 推断数据库已提交。

## 公开接口与配置

安装 `type-core` 与 `type-runtime`，方法中的 `$budget` 使用同一连接域共享的 `ResourceBudget`。配置显式传给工厂，不自动读取环境变量。

| 入口 | 默认值与语义 |
| --- | --- |
| `TcpSocket::listener($budget, $address, $port, $maxChunkBytes, $options)` | 数字 IP；端口默认 0，由系统分配；单片段默认 65536 字节 |
| `TcpSocket::client($budget, $host, $port, $maxChunkBytes, $options)` | 目标主机与端口必填；端口 1–65535 |
| `start($timeout)` | 默认 5.0 秒；客户端期限覆盖 DNS、连接与 TLS |
| `accept($timeout)` | 默认 5.0 秒；预留额度后接入，返回尚未启动的连接 |
| `receive($timeout)` | 默认 5.0 秒；返回片段，空字符串表示 EOF |
| `send($data, $timeout)` | 默认 5.0 秒；返回发送字节数，失败可能已部分提交 |
| `shutdownWrite()` | 结束本端发送，仍可接收响应 |
| `stop()` / `awaitClosed($timeout)` | 请求取消与等待实际关闭，关闭等待默认 5.0 秒 |
| `addresses()` / `statistics()` | 实际端点地址与资源状态 |

单次期限范围 `(0, 60]` 秒；单片段上限 1–1048576 字节，不等于业务总消息上限。监听不支持域名；客户端域名走原生 IPv4 DNS，显式 IPv6 走 IPv6 Socket。绑定 IPv6 不表示同时监听 IPv4。

| `options` 键 | 默认值 | 说明 |
| --- | --- | --- |
| `socket_buffer_size` | 65536 字节 | 范围 65536–1048576，与业务消息上限分别设置 |
| `backlog` | 128 | 1–4096，仅监听端可配置，表示待接入队列 |
| `open_ssl` | `false` | 显式布尔 TLS 开关 |
| `ssl_cert_file` / `ssl_key_file` | 无 | TLS 服务端必填且成对 |
| `ssl_cafile` | 原生可信根配置 | 私有 CA 时显式指定 |
| `ssl_host_name` | 客户端目标主机 | 验证证书身份，监听端不可设置 |
| `ssl_verify_peer` | 客户端 `true`、监听端 `false` | 客户端不允许关闭校验 |
| `ssl_protocols` | TLS 1.2 与 1.3 | 仅接受这两种协议的有效位组合 |

仅支持表中选项；未开启 TLS 时提供 `ssl_*` 会被拒绝。外置配置经校验后映射到参数，私钥与密码不写入源码。

## 完整实例：有界回显

示例以 EOF 作为本次请求结束：客户端发送后半关闭，服务端逐片回显，读到 EOF 后结束自己的发送。它只处理一个连接，演示完整关闭流程，不是长期监听服务模板。

本例的 PHP 开发态需要 PHP 8.5 CLI、Swoole 和 sockets 扩展。当前 `shutdownWrite()` 使用 PHP 8.5 的 `SHUT_WR` 常量；虽然组件包约束允许 PHP 8.4，该半关闭路径在 8.4 下仍有兼容缺口，不能只靠 Composer 安装成功判断可运行。先检查 `php -r 'var_export(defined("SHUT_WR"));'` 应输出 `true`。

在独立练习应用的 `app/main.php` 保存：

```php
<?php

declare(strict_types=1);

use Swoole\Coroutine;
use Type\Core\TcpSocket;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\DeploymentBudget;
use Type\Runtime\ExecutionScope;

function main(int $argc, array $argv): void
{
    if ($argc < 4 || !in_array($argv[1], ['server', 'client'], true)) {
        throw new InvalidArgumentException('用法：server|client <主机> <端口> [消息]');
    }
    CoroutineRuntime::assertAvailable();
    Coroutine::create(static function () use ($argv): void {
        $plan = new DeploymentBudget(16, 1, 0, 1, 0, 1);
        $scope = new ExecutionScope();
        $socket = $argv[1] === 'server'
            ? TcpSocket::listener($plan->poolBudget(), $argv[2], (int) $argv[3])
            : TcpSocket::client($plan->poolBudget(), $argv[2], (int) $argv[3]);
        try {
            $scope->open($socket);
            if ($argv[1] === 'server') {
                $connection = $socket->accept(60.0);
                $scope->open($connection);
            } else {
                $connection = $socket;
                $connection->send($argv[4] ?? 'hello');
                $connection->shutdownWrite();
            }
            $data = '';
            while (($part = $connection->receive(5.0)) !== '') {
                if (strlen($data) + strlen($part) > 65536) {
                    throw new RuntimeException('示例消息超过 64 KiB');
                }
                $data .= $part;
                if ($argv[1] === 'server') {
                    $connection->send($part);
                }
            }
            if ($argv[1] === 'server') {
                $connection->shutdownWrite();
            }
            echo json_encode(['data' => $data], JSON_THROW_ON_ERROR), "\n";
        } finally {
            $scope->close();
            $socket->awaitClosed();
        }
    });
    Swoole\Event::wait();
}
```

先按[开发启动器约定](../components.md#运行声明式示例)安装锁定的 TypePHP 开发工具，再在应用根保存 `dev.php`：

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/vendor/swoole/typephp/src/polyfills.php';
require __DIR__ . '/app/main.php';

main($argc, $argv);
```

终端 A 启动后，在 60 秒内从终端 B 连接：

```bash
# 终端 A
php dev.php server 127.0.0.1 9502
```

```bash
# 终端 B
php dev.php client 127.0.0.1 9502 hello
```

两端应输出 `{"data":"hello"}` 并退出。改变消息再次运行时，先重启服务端。示例收集的数据总长限制为 64 KiB；CLI 消息应保持短小，生产协议也要在发送前验证载荷长度。

## TLS 配置与验证

沿用上例，将工厂的 `options` 显式替换为对应端配置。以下是接续片段，需要实际证书和私钥：

```php
// 服务端 listener(..., options: $serverTls)
$serverTls = [
    'open_ssl' => true,
    'ssl_cert_file' => '/etc/typeapp/tls/server-chain.pem',
    'ssl_key_file' => '/etc/typeapp/tls/server-key.pem',
];
// 客户端 client(..., options: $clientTls)
$clientTls = [
    'open_ssl' => true,
    'ssl_cafile' => '/etc/typeapp/tls/ca.pem',
    'ssl_host_name' => 'gateway.example.com',
];
```

路径为部署示意，由外置配置提供。客户端即使连接 IP，也应按证书 SAN 校验服务身份。接入连接后，`scope->open($connection)` 才完成该连接的 TLS 启动。验证可信证书成功、错误 CA、错误主机名与握手超时，不关闭校验来处理证书错误。

## 应用设计：设备长连接

长期服务循环接入，并在同一 Swoole 线程内为连接安排协程和作用域。把 `accept()` 返回的连接交给处理作用域启动，交接失败立即 `stop()`。同一连接允许一读与一写并行，同方向并发会被拒绝。不可跨线程传递已打开连接。

连接只保留协议缓冲和必要会话；完整报文到达后再借用数据库租约，处理后归还。长度前缀协议先验证长度再分配内存，累计接收时保留总期限，避免零碎数据无限延长业务处理。

“命令已写出”和“设备已执行”使用不同状态。业务消息可包含版本、消息 ID、设备 ID、类型和载荷，设备回执带相同 ID，服务在事务中去重。这里定义的是业务协议，不新增底层传输引擎。

## 关闭、故障与验证

`stop()` 请求取消在途操作，`awaitClosed()` 等待原生描述符真正关闭，额度随后结算。正常和异常路径都在 `finally` 关闭作用域，不能把 stop 调用等同于关闭已完成。

| 现象或错误 | 含义与处理 |
| --- | --- |
| `tcp_invalid_configuration` | 地址、端口、大小或选项不符合公开契约 |
| `tcp_invalid_timeout` | 单次期限不在 `(0, 60]` 秒 |
| 空字符串 | 对端结束发送，不是一条空业务消息 |
| 报文分多次读取 | 正常 TCP 行为，按业务分帧累积 |
| 部分发送后失败 | 对端可能已收到前缀，核对业务结果，不自动重发整条 |
| 预算耗尽 | 检查实际连接与未完成关闭，不通过新建独立预算规避限制 |

上线前验证双端回显、半关闭、拆分与合并读取、总长度超限、超时、TLS 身份错误和异常清理。观察 `statistics()` 与 `addresses()`。一次本地成功不能证明目标容量和所有平台通过，生产全量编译与单程序交付约束见[构建与部署](../deployment.md)。

TCP 协议参考 [RFC 9293](https://www.rfc-editor.org/rfc/rfc9293.html)，其中的可靠字节流语义与应用消息确认需要分别理解。
