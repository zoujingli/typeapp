# Platform / System / Center Tenant 重构验收记录

日期：2026-11-14。源码基线为当前 `main`，本记录只描述本轮实际执行的检查，不替代 MySQL/PostgreSQL、AOT、无源码和真实 MQTT 验收。

## 已通过

- `pnpm --dir web typecheck`：通过。
- `pnpm --dir web exec vitest run apps/web-antd/src/preferences.test.ts apps/web-antd/src/screen-lock.test.ts`：2 个文件、3 个测试通过。
- `pnpm --dir web build`：Vite 8.3.8 生产构建通过；保留大分块提示，未将其当作性能验收通过。
- PHP 8.5.12 ZTS（Swoole 6.3.0RC1、PDO MySQL/PostgreSQL/SQLite）下 `composer validate --strict`：通过。
- PHP CS-Fixer 3.95.30：915 个文件检查通过，0 个待修正。
- PHPUnit 13.4.6：306 tests、18,005 assertions 通过。
- `php tests/check.php`：962 个文件、3 个分发拒绝用例、文档站边界检查通过。
- `php tests/docs-consistency.php`：1,504 处引用、131 条路由、292 个命令、247 个 Composer 脚本通过。
- `php tests/tenant.php sqlite`：空库安装、模块表前缀、主域/子域/固定路径、RBAC、独立品牌、模拟交接、MQTT 模拟观测、恢复核对和真实 HTTP 隔离通过。
- `php tests/tenant.php mysql <MySQL工具根目录>`：MySQL 8.4.11 原生隔离实例上的同一 Tenant HTTP 闭环通过。
- `php tests/tenant.php pgsql <PostgreSQL工具根目录>`：PostgreSQL 17.11 原生隔离实例上的同一 Tenant HTTP 闭环通过。
- `php tests/model-sync.php <MySQL工具根目录>`：真实 MySQL 8.4.11 结构反向同步、字段注释、生成列、增删保护、非法输入拒绝及生成模型 Tenant CRUD 通过。
- 活动源码、前端、文档、CI 和验收入口扫描：未发现 `SaaS`、`saas_id`、`public/saas`、`/s/`、旧双账号安装变量、旧客户成员模型、旧 `iot:*` 恢复命令或未加模块前缀的 `broker_nodes` 表名。
- `docs/build-config/type-app.json` JSON 解析通过；活动 GitHub 工作流 YAML 解析通过。
- 当前工作区 `git diff --check` 通过；提交按功能拆分并保留中文背景、影响和验证说明。

## 当前实现范围

Platform、System、Center 的职责、Tenant 入口、主域名客户选择、子域名和 `/t/{tenant_id}/` 固定路径、双身份会话、RBAC、菜单分组与图标、模拟登录、Vben 5.7.0 页面、模型优先 CRUD、表字段注释、MySQL `model:sync` 和发布包安装说明均已同步到当前源码。

核心表使用 `platform_*`、`system_*`、`center_*` 和 `platform_mqtt_*` 前缀。Platform 跨 Tenant 的接入应用目录使用不带 `tenantKey` 的 `ApplicationDirectory` 只读 Model，写入仍使用带 Tenant 范围的 `ClientApplication`。MQTT 协议持久状态使用组件自身的 `type_mqtt_*` 表；审计、恢复、运行观测和协议认证热路径保留受控基础设施查询，并在数据库指南中说明边界。

## 未验证及阻塞

当前使用工作区已有的 PHP 8.5.12 ZTS SDK 与临时 Composer 2.10.3 phar 完成 PHP 静态检查、单元测试和三库管理闭环；未把临时工具写入仓库。MySQL 8.4.11、PostgreSQL 17.11 实例由本机测试控制器独占创建并清理。Docker daemon 仍不可用，因此无法用容器替代正式平台验收。

设备注册首次 SQLite 闭环发现 `DeviceOwnership` 创建时遗漏可空字段，已补齐 `ended_at`、`expires_at`、`expiry_context` 的显式 `null` 并复验通过。

仓库目标工具链记录在 `toolchain.lock.json`：PHP 8.5.12 ZTS、TypePHP `c5599c5c`、PHPX 2.9.8；本机实际 Node.js 为 26.13.1，pnpm 为 11.15.1，而前端工作区声明 pnpm 12.18.0。前端检查结果仍只证明在当前可用 Node/pnpm 环境下的静态与生产构建行为。

仍未验证：当前源码的 TypePHP 全量 AOT、删除源码后的无源码运行、正式平台/profile 矩阵、真实 TLS MQTT 持久会话/retained/撤权/跨 Tenant 转配，以及 Windows SDK。补齐匹配的静态 SDK 和真实 MQTT 设备环境后，按 `docs/development/test-entrypoints.md` 顺序重跑剩余门禁，并以同一源码、锁文件和原生产物记录最终结果。
