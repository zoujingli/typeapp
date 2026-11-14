# 当前验收入口

本页只记录当前 Platform、System、Center 与 Tenant 架构的验收入口。每次结果必须绑定源码提交、锁文件、程序摘要、数据库驱动和实际参数；命令存在或静态检查通过不代表真实服务已经上线可用。

## 管理链路

| 范围 | 入口 | 说明 |
| --- | --- | --- |
| SQLite PHP 管理闭环 | `composer test:tenant` | 全新安装、主域名客户选择、子域名/固定路径、RBAC、菜单、品牌、模拟登录、设备、应用授权、节点观察和恢复核对 |
| SQLite 原生管理闭环 | `composer test:tenant-native` | 使用指定 `build/app/type-app`，验证同一 Tenant 场景和无源码边界 |
| MySQL PHP 管理闭环 | `composer test:tenant-mysql -- <MySQL工具根目录>` | 创建并回收专用 MySQL，验证真实表约束和 Tenant 隔离 |
| PostgreSQL PHP 管理闭环 | `composer test:tenant-pgsql -- <PostgreSQL工具根目录>` | 创建并回收专用 PostgreSQL，验证真实表约束和 Tenant 隔离 |
| MySQL 模型反向同步 | `composer test:model-sync -- <MySQL工具根目录>` | 预览、写入、重复同步、字段增删保护、注释、生成列、精确字段和 Tenant 模型编译 |

真实浏览器验收不依赖组件内部 mock：在锁定 PHP 8.5.12 ZTS、原生 Swoole runtime profile 和全新 SQLite 安装上启动应用，再使用 Playwright CLI 访问 `/#/system/login`、固定 Tenant 路径及当前三端路由。当前证据覆盖客户选择登录、平台登录、固定路径刷新、Platform 20 条和 System/Center 11 条业务路由首屏、375×812 窄屏、深色偏好，以及 Center 产品分类创建/编辑/删除；完整页面 CRUD、授权弹层、一次性秘密展示和子域名浏览器交互仍须独立补验，结果见[当前 Tenant 验收记录](../evidence/tenant-refactor-20261114.md#当前代码基线浏览器追加验收)。

`tests/tenant.php` 的参数为 `sqlite [原生程序]` 或 `mysql|pgsql <工具根目录> [原生程序]`。网络数据库工具必须是目标平台的真实二进制，测试不会使用容器或共享业务数据。

## 框架和前端门禁

| 范围 | 入口 |
| --- | --- |
| PHP 单元与模型编译 | `composer test:unit` |
| 代码格式 | `composer cs-check`；自动修正使用 `composer cs-fix` |
| 文档、路由、命令和 Composer 脚本 | `composer test:docs` |
| 前端类型检查与生产构建 | `pnpm --dir web typecheck`、`pnpm --dir web build` |
| 应用偏好与锁屏行为 | `pnpm --dir web exec vitest run apps/web-antd/src/preferences.test.ts apps/web-antd/src/screen-lock.test.ts` |
| 通用 MQTT 协议组件 | `composer test:mqtt`、`composer test:mqtt-websocket`、`composer test:mqtt-mtls`，按实际客户端、TLS 和数据库前置条件执行 |
| TypePHP 应用构建 | `composer typeapp:build`，完成后必须使用同一产物重跑 Tenant 原生入口 |

应用门禁只把当前 Tenant 管理入口作为业务通过依据；通用 MQTT 组件测试验证协议能力，不替代 Platform MQTT 运维、设备授权和跨 Tenant 转配的真实应用验收。

## 结果判定

- PHP 语法、单元测试、CS-Fixer、前端 typecheck 和生产构建分别记录；任一未执行项不能写成通过。
- SQLite 可以验证应用协议和模型边界，不能替代 MySQL/PostgreSQL 的真实锁、索引、字符集和同步语义。
- PHP 解释执行不能替代 TypePHP AOT；AOT 成功不能替代删除源码后的运行、异常、资源回收和数据库验收。
- 模拟 MQTT 节点上报不能替代真实 TLS MQTT、持久会话、retained、撤权和跨节点转配；这些范围必须使用真实节点、证书和 PostgreSQL 同步存储记录。
- 当前环境若缺少 Swoole PDO hook、数据库工具或 TypePHP SDK，验收必须记录稳定错误码和缺失能力，不能静默跳过。

历史运行报告保存在 `docs/evidence/`，只作为对应提交和产物的事实记录，不作为当前源码通过证据。
