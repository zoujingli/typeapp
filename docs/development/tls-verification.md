# 数据库与 Redis 的 TLS 验证

MySQL、PostgreSQL 和 Redis 分别使用真实 TLS 服务验证可信根、服务身份、有效证书、错误身份拒绝与清理；测试使用独立实例和测试证书，不能连接业务数据库。

测试入口为 `tests/tls.php` 和 `tests/native-database-tls.php`，参数与当前 Composer 脚本对应。原生验证须使用锁定 SDK、实际扩展和完整 AOT 产物，核对 TLS 失败后连接与资源仍按契约释放。

PHP CLI 与 embed 可能加载不同的 TLS 库，模块列表不能证明原生产物使用了正确符号。各目标平台分别核对加载库、证书行为及资源清理；完整验收要求见[平台与工具链](platform-support.md)。
