# Platform / System / Center Tenant 重构验收记录

日期：2026-11-14。源码基线为当前 `main`，工作区结束时无未提交修改。本记录只描述本轮实际执行的检查，不替代三库、AOT、无源码和真实 MQTT 验收。

## 已通过

- `pnpm --dir web typecheck`：通过。
- `pnpm --dir web exec vitest run apps/web-antd/src/preferences.test.ts apps/web-antd/src/screen-lock.test.ts`：2 个文件、3 个测试通过。
- `pnpm --dir web build`：Vite 8.3.8 生产构建通过；保留大分块提示，未将其当作性能验收通过。
- 活动源码、前端、文档、CI 和验收入口扫描：未发现 `SaaS`、`saas_id`、`public/saas`、`/s/`、旧双账号安装变量、旧客户成员模型、旧 `iot:*` 恢复命令或未加模块前缀的 `broker_nodes` 表名。
- `docs/build-config/type-app.json` JSON 解析通过；活动 GitHub 工作流 YAML 解析通过。
- 当前工作区 `git diff --check` 通过；提交按功能拆分并保留中文背景、影响和验证说明。

## 当前实现范围

Platform、System、Center 的职责、Tenant 入口、主域名客户选择、子域名和 `/t/{tenant_id}/` 固定路径、双身份会话、RBAC、菜单分组与图标、模拟登录、Vben 5.7.0 页面、模型优先 CRUD、表字段注释、MySQL `model:sync` 和发布包安装说明均已同步到当前源码。

核心表使用 `platform_*`、`system_*`、`center_*` 和 `platform_mqtt_*` 前缀。Platform 跨 Tenant 的接入应用目录使用不带 `tenantKey` 的 `ApplicationDirectory` 只读 Model，写入仍使用带 Tenant 范围的 `ClientApplication`。MQTT 协议持久状态使用组件自身的 `type_mqtt_*` 表；审计、恢复、运行观测和协议认证热路径保留受控基础设施查询，并在数据库指南中说明边界。

## 未验证及阻塞

当前执行环境没有 `php` 和 `composer`，Docker daemon 也不可用；无法执行 PHP lint、CS-Fixer、PHPUnit、`composer test:tenant*`、真实 MySQL/PostgreSQL/SQLite 管理闭环、`model:sync`、TypePHP AOT、无源码运行或真实 TLS MQTT/持久会话/撤权/转配验收。

仓库目标工具链记录在 `toolchain.lock.json`：PHP 8.5.12 ZTS、TypePHP `c5599c5c`、PHPX 2.9.8；本机实际 Node.js 为 26.13.1，pnpm 为 11.15.1，而前端工作区声明 pnpm 12.18.0。前端检查结果因此只证明在当前可用 Node/pnpm 环境下的静态与生产构建行为。

补齐匹配 PHP/Swoole PDO hook、Composer、MySQL/PostgreSQL 工具和 TypePHP SDK 后，必须按 `docs/development/test-entrypoints.md` 顺序重跑全部门禁，并以同一源码、锁文件和原生产物记录最终结果。
