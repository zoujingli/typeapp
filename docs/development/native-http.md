# 原生 HTTP 与消息协议

HTTP 位于 type-core，沿用 type-runtime 的执行作用域和 TypePHP 全量编译入口。`examples/http-command.php` 声明显式路由、处理器构造工厂和中间件工厂；运行时每个请求建立自己的可变处理器与中间件实例。

## 构建与验证

```sh
composer build:http-messages
composer test:http-messages
composer build:http
composer test:http
```

原生服务示例绑定回环地址，默认端口 19501，可通过 `TYPE_HTTP_PORT` 设置端口。`GET /health` 返回构造注入的问候、请求标识及中间件顺序；`GET /fail` 演示不泄露内部异常的 500 响应。它是集成示例，不能当作对外部署配置。

## 公共行为

- `Message\Factory` 实现 PSR-17 工厂，返回实现 PSR-7 的消息、URI、流及上传对象。消息修改返回副本；底层流按 PSR 约定具有自身可变读写位置。
- `Router::add()` 接收方法、完整路径与处理器工厂；重复方法路径拒绝，未知路径返回 404，已知路径的方法不匹配返回带 `Allow` 的 405。
- `Router::middleware()` 按登记顺序执行 PSR-15 中间件。工厂每次创建执行实例，避免共享请求可变状态。
- `SwooleServer` 接收标准处理器和消息工厂。请求属性 `type.scope` 是本次请求的 `ExecutionScope`；请求资源持续拥有到响应发送和清理结束。
- 异常转换为固定错误码，不把堆栈、异常文本或配置秘密放入响应。退出时清理请求资源与消息流。

参数与资源路由、反向 URL、鉴权、代理信任和文件处理见[HTTP 教程](../guide/communications/http.md)、[路由](routing.md)及[文件流](file-streams.md)。各入口按当前配置验证容量、失败响应与停止。

## 编译覆盖

标准 PSR 接口仍从原 Composer 包安装。type-core 自带准确版本的依赖适配，独立消费者不必手工重复配置；构建器审计每个生产包的自动加载源码，记录适配提供者，并拒绝冲突或版本不匹配。

消息实现由 type-core 编译。生产依赖完整进入编译范围，不通过忽略源码或运行 PHP 入口补齐能力。
