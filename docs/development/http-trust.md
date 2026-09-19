# HTTP 代理信任、鉴权与正文

外层 Pipeline 先执行 `RequestPolicy`，再执行 CORS、认证与业务路由。应用明确配置允许的 Host（含需要允许的端口）及受信任代理 IP/CIDR；没有通配 Host 默认值。

未受信任连接的转发头被忽略并移除，无法改变外部 URL 或客户端身份。受信任代理使用 X-Forwarded-Host/Proto 成对声明外部地址，X-Forwarded-For 从右侧逐跳检查，在最近不可信地址停止；最多 32 跳。该约定要求边缘代理覆盖客户端传入的 Host/Proto 转发值。标准 Forwarded 与本入口的 X-Forwarded 策略不混用，受信任连接出现未声明 Forwarded 时拒绝。

Swoole 接口保留原始 request target 属性，策略在 URI 自动编码前据此检查路径。每段只解码一次，拒绝非法百分号、编码分隔符、点段、控制字符和双斜线；保留尾斜线和大小写区别。QueryString 在 runtime 内统一解析，DTO 校验器复用同一规则，重复标量和歧义括号拒绝。规范化后产生 `CanonicalRequest`，路由直接复用其 segments，签名和授权读取同一 URI；中间件后来改变 URI 会被拒绝。

`Authentication` 接受显式 Bearer 认证器和授权器，身份通过 `Identity` 表示。认证器返回 null 为 401，授权失败为 403，输入规则错误仍由校验器返回 422；请求作用域不共享身份对象。认证实现和实际凭据由应用提供，框架示例中的 token 只用于本地测试。

认证器完整签名为 `(string $token): ?Identity`，授权器为 `(Identity $identity, CanonicalRequest $request, string $method): bool`，租户授权为 `(Identity $identity, Tenant $tenant): bool`。即使暂不使用请求或方法参数也须声明，确保源码遵守 TypePHP 的严格参数数量规则。

`Cors` 使用精确 origin、方法和消息头列表，带凭据时禁止 `*`。预检不要求 Bearer 身份；允许的实际请求和认证失败响应均携带 CORS 和 Vary。来源拒绝不能代替业务鉴权或 CSRF 策略。

`RequestBody::read()` 有大小上限，默认恢复流的原指针；不可定位流需要显式选择消费模式。`RequestSignature` 将规范化 URI、方法、正文哈希和到期时间绑定到 HMAC，验证到期和允许的未来窗口。它不提供业务幂等或一次性 nonce 存储，需要一次性处理的接口由业务另行实现。

`HttpError` 提供稳定状态与错误码。请求解码阶段的无效参数返回 400，处理器内部未分类异常统一 500，不暴露堆栈或配置秘密。

PHP 真实 HTTP 验证覆盖可信/不可信代理、代理链停止点、默认端口、Host、编码、重复参数、签名一致性、身份、CORS 和正文重放，且原校验测试继续通过；原生入口为 `docs/build-config/type-trust-http.json`，验收待统一完成。
