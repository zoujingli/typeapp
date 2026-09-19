# UDP

UDP 以独立数据报交换信息，接收时同时得到来源地址与端口。它适合短遥测、轻量探测和可容忍丢失的数据交互。TypeApp 使用 `type-core` 的 `Type\Core\UdpSocket`，底层为 Swoole 协程 Socket；Plugins 管理端点归属与资源上限，业务与组件交给 TypePHP 编译。总体关系见[通信导读](../communications.md)。

## 数据报模型与应用选择

一次 `sendTo()` 发送一个数据报，一次成功 `receive()` 返回一个完整数据报及来源。UDP 没有 TCP 的连接与半关闭语义，也不保证送达、顺序或不重复。发送成功只表示已提交本地网络层，不证明接收方在线或已处理。

| 场景 | 使用建议 |
| --- | --- |
| 周期遥测 | 包含设备 ID、序号与采样时间，允许丢失过时样本 |
| 请求与响应 | 定义请求 ID、总期限、最大重试数，接收方去重 |
| 发现与探测 | 明确单播、广播或组播要求；当前封装示例只覆盖单播 |
| 必须可靠交付的指令 | 优先评估 TCP、HTTP 或 MQTT，不把 UDP 回显当可靠消息队列 |

空载荷是有效数据报；它与“没有收到数据”不同。IP 分片会放大丢包影响，65507 字节是 API 允许的上界，不是推荐业务包长。跨网段设计应按实际路径 MTU 选择更小报文。

## 公开接口与配置

安装 `type-core` 与 `type-runtime`，显式创建端点：`new UdpSocket($budget, $address, $port, $maxDatagramBytes, $options)`。构造器不自动加载环境变量。

| 配置或方法 | 默认值与含义 |
| --- | --- |
| `$address` | 必填本机数字 IPv4/IPv6 地址；不接受域名 |
| `$port` | 默认 0，由系统分配；监听服务应指定稳定端口 |
| `$maxDatagramBytes` | 默认 8192 字节，范围 1–65507 |
| `options.socket_buffer_size` | 默认 65536 字节，范围 65536–1048576 |
| `options.write_timeout` | 默认 1.0 秒，范围 `(0, 60]` |
| `start()` | 在已有 Swoole 协程中取得预算并绑定本机地址 |
| `sendTo($address, $port, $data)` | 数字目标 IP，端口 1–65535；返回提交字节数 |
| `receive($timeout)` | 默认 1.0 秒，范围 `(0, 60]`；返回 `data/address/port` |
| `localAddress()` | 返回实际绑定地址与端口，可用于端口 0 |
| `stop()` / `statistics()` | 关闭与状态、额度、字节、在途统计 |

本地与目标地址必须属于同一 IP 地址族。`0.0.0.0` 是绑定所有本机 IPv4 接口，不是客户端应连接的远端地址。原生缓冲上限与单数据报上限分别生效；未知选项会被拒绝，当前接口未开放任意广播、组播或 DTLS 配置。

## 完整实例：单播回显

在独立练习应用中保存 `app/main.php`。服务端绑定指定端口，客户端绑定回环地址和随机端口；服务端按实际来源回送，双方各处理一报后退出。

```php
<?php

declare(strict_types=1);

use Swoole\Coroutine;
use Type\Core\UdpSocket;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\DeploymentBudget;
use Type\Runtime\ExecutionScope;

function main(int $argc, array $argv): void
{
    if ($argc < 3 || !in_array($argv[1], ['server', 'client'], true)) {
        throw new InvalidArgumentException('用法：server|client <端口> [消息]');
    }
    CoroutineRuntime::assertAvailable();
    Coroutine::create(static function () use ($argv): void {
        $plan = new DeploymentBudget(16, 1, 0, 1, 0, 1);
        $scope = new ExecutionScope();
        $server = $argv[1] === 'server';
        $port = (int) $argv[2];
        $socket = new UdpSocket($plan->poolBudget(), '127.0.0.1', $server ? $port : 0);
        try {
            $scope->open($socket);
            if (!$server) {
                $socket->sendTo('127.0.0.1', $port, $argv[3] ?? 'hello');
            }
            $packet = $socket->receive($server ? 60.0 : 5.0);
            if ($server) {
                $socket->sendTo($packet['address'], $packet['port'], $packet['data']);
            } elseif ($packet['address'] !== '127.0.0.1' || $packet['port'] !== $port) {
                throw new RuntimeException('收到非预期端点的数据报');
            }
            echo json_encode($packet, JSON_THROW_ON_ERROR), "\n";
        } finally {
            $scope->close();
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

在终端 A 启动后，60 秒内从终端 B 发送：

```bash
# 终端 A
php dev.php server 9503
```

```bash
# 终端 B
php dev.php client 9503 hello
```

两端 JSON 的 `data` 均为 `hello`，`address` 为 `127.0.0.1`。服务端输出的 `port` 是客户端随机端口，客户端输出的 `port` 为 `9503`，随后双方退出。要测试空报文，重新启动服务端后执行 `php dev.php client 9503 ''`，期望 `data` 为空但来源仍准确；该行为需要匹配的 Swoole 空数据报来源能力。

此例只监听回环地址。远程部署时，构造器仍绑定本机地址，`sendTo()` 才填写远端地址；不能把远端 IP 当作客户端本地绑定地址。源地址检查只限制响应来源，不能代替密码学身份验证。

## 应用设计：遥测与请求响应

遥测报文可包含版本、设备 ID、递增序号、采样时间与载荷。接收方按设备保存有限的去重或顺序窗口，过旧样本可丢弃，窗口需有数量与过期限制。处理数据时短暂借用数据库连接，不让端点整个生命周期占用数据库租约。

请求响应协议要带请求 ID；超时后有限次重试，并使用退避和总期限。接收方按 ID 去重，回复先前结果；如果业务无法接受重复执行，应选用成熟的可靠交付协议并保留业务幂等性。

NAT 映射和防火墙可能让回程失败，不能因本地发送成功就把设备标为在线。UDP 来源可以被伪造；公网交互需要适用的消息认证、防重放和限速，避免无认证的大响应被用于反射。当前 `UdpSocket` 不提供 DTLS 封装，不能把 `open_ssl` 从 TCP 配置直接复制过来。

## 并发、超长报文与关闭

端点在启动它的协程内使用，不能跨线程或切换业务执行者。同一端点只允许一个在途收发操作；不要安排两个协程同时 `receive()`。需要并行业务处理时，在收到完整数据报后把业务数据交给有界处理流程，端点仍由原所有者管理。

超过 `maxDatagramBytes` 的数据报会整报消费并抛出 `udp_datagram_too_large`，不会把截断前缀当作成功报文。没有框架后台无限队列，`queued_datagrams` 为 0；这不代表操作系统网络缓冲为空。没有及时接收时，内核仍可能丢弃数据报。

退出使用 `finally` 关闭作用域。`statistics()` 应反映实际关闭、无在途操作及额度归还；框架不会自动补发未收到的数据报。

## 排障与上线验证

| 现象或错误 | 含义与处理 |
| --- | --- |
| `udp_invalid_configuration` | 检查数字 IP、地址族、端口、大小、缓冲与期限 |
| `udp_datagram_too_large` | 整报超限，调整业务报文设计，不消费截断内容 |
| `udp_peer_unavailable` | 当前原生能力未返回可靠来源；核对匹配的 Swoole 构建 |
| 接收超时 | 可能丢包、服务未绑定、端口错误或防火墙阻断，不等于对端已拒绝业务 |
| 顺序变化或重复 | UDP 允许出现，业务按 ID、序号和期限处理 |

上线前验证正常与空报文、超长报文、来源准确性、无响应超时、重复与乱序，以及异常后的关闭。IPv6、真实路径 MTU、网络丢包与压力需要在目标环境单独验收。全量编译与交付要求见[构建与部署](../deployment.md)，开发态回显通过不代替原生验收。

UDP 协议参考 [RFC 768](https://www.rfc-editor.org/rfc/rfc768.html)。
