# TypeApp 物联管理端

这是 TypeApp 的单一 Web 工程，按 Platform、System、Center 三个运行角色装配页面和接口。前端使用 Vue Vben Admin 5.7.0 的 Ant Design Vue 应用能力，业务页面由本仓库维护；上游来源、许可证和同步提交见 [UPSTREAM.md](UPSTREAM.md)。

## 目录

- `apps/web-antd/src/router/routes/platform.ts`：Platform 平台配置、客户、平台 RBAC、MQTT 运维和模拟登录入口。
- `apps/web-antd/src/router/routes/system.ts`：当前客户的用户、角色、系统品牌和审计。
- `apps/web-antd/src/router/routes/center.ts`：当前客户的分类、设备、接入应用、注册资格、通信观察和转配。
- `apps/web-antd/src/api`：按运行角色拆分的请求契约和可信客户上下文。
- `packages`、`internal`：Vben 工作区公共能力和构建工具。

所有业务 CRUD 优先使用后端模型服务；跨客户目录、MQTT 运行观测、审计和恢复投影等基础设施查询保留明确的基础查询边界。

## 入口与身份

| 入口 | 未登录页 | 登录字段 |
| --- | --- | --- |
| 主域名 | `/#/system/login` | 客户选择、账号、密码 |
| 客户子域名 | 自动读取客户公开配置 | 账号、密码 |
| `/s/{saas_id}/` | 固定客户并读取公开配置 | 账号、密码 |
| 平台 | `/#/platform/login` | 平台账号、密码 |

客户选择使用 `/public/saas` 分页接口；客户公开配置使用 `/public/context` 或 `/s/{saas_id}/public/context`。固定路径和子域名只定位客户，身份资格仍由后端会话和 RBAC 校验。System 与 Center 共用客户 System 会话，Platform 会话独立。

## 开发与构建

工具链由 `package.json` 和 `pnpm-lock.yaml` 锁定：Node.js 26.13.1、pnpm 12.18.0、Vue 3.5.45、Vite 8.3.8、TypeScript 7.0.2。使用 `web` 目录作为工作目录：

```sh
pnpm install --frozen-lockfile
pnpm typecheck
pnpm build
IOT_API_ORIGIN=http://127.0.0.1:9501 pnpm dev
```

`IOT_API_ORIGIN` 只影响开发服务器代理。生产构建输出到 `web/dist`，由后端安装流程编入应用；构建同时携带第一方许可证、第三方许可证和来源说明。

页面沿用 Vben 的认证布局、应用布局、表单、VXE 表格、抽屉、弹窗、用户入口、主题和偏好面板。个人偏好按端、客户、账号和模拟操作者保存，只记录显式覆盖；恢复默认后重新使用当前端配置。页面标准见 [物联网中心 Web 页面标准](../docs/standards/iot-web.md)。
