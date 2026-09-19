# 架构决策

本目录只描述现行架构及其取舍，实施状态见[实现规划](../guide/roadmap.md)。

- [聚合开发与公开组件分发](0001-development-monorepo.md)
- [应用级整体 AOT 与编译期装配](0002-application-aot.md)
- [显式编译声明与启动配置](0003-static-declarations-and-runtime-config.md)
- [Swoole 服务端与平台支持边界](0004-native-server-support-boundary.md)
- [单一源码与锁定 AOT 基线](0005-php-development-and-aot-baseline.md)
- [物联网租户与设备单一归属](0006-iot-tenant-device-ownership.md)
- [缺失设备回执保留未知结果](0008-iot-command-unknown-outcome.md)
- [标准兼容的 MQTT 服务](0009-self-developed-standard-mqtt-broker.md)
- [物联网生产持久状态采用 PostgreSQL 同步高可用](0010-iot-postgresql-durable-state.md)
- [可靠上报以平台持久接收回执完成](0011-iot-durable-ingestion-receipt.md)
- [业务线程与协程作用域](0013-business-threads-and-coroutine-scopes.md)
- [MQTT WebSocket 接入与证书身份](0014-mqtt-websocket-and-certificate-identity.md)
- [TypePHP 与 Swoole 统一底层](0015-typephp-and-swoole-foundation.md)
- [基于 Swoole 的多协议基础通信](0016-swoole-standard-communications.md)
- [双端身份与租户内 RBAC](0017-dual-realm-tenant-rbac.md)
- [模拟登录保留真实管理身份](0018-admin-customer-impersonation.md)
- [业务应用的数据与接口边界](0019-business-app-clean-rebuild.md)
- [标准项目与主仓业务保持单一来源](0020-standard-project-single-business-source.md)
- [Swoole 唯一底层与单程序交付](0021-required-swoole-and-single-program.md)
