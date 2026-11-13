# 前端源码来源

公共框架从官方 [Vue Vben Admin v5.7.0](https://github.com/vbenjs/vue-vben-admin/releases/tag/v5.7.0) 同步，固定提交 `63a38dce49ba109f61607994e21ba921d8e970e9`，采用正式 Ant Design Vue 应用的适配方式。官方工作区保留各包作者、来源与 MIT 声明，完整许可证见 [VBEN-LICENSE](VBEN-LICENSE)，生产构建同时输出许可原文。TypeApp 自有实现继续采用 [Apache-2.0](LICENSE)。

同时同步官方 `internal/vite-config` 的 Tailwind reference 与 VXE 按需样式构建插件至 `scripts/{tailwind-reference,vxe-table}.ts`，供独立 Vite 配置使用。

必要闭包包含以下 28 个工作区：

- `packages/effects/{access,common-ui,hooks,layouts,plugins,request}`。
- `packages/@core/{composables,preferences}`。
- `packages/@core/base/{shared,icons,typings,design}`。
- `packages/@core/ui-kit/{form-ui,shadcn-ui,layout-ui,menu-ui,popup-ui,tabs-ui}`。
- `packages/{constants,preferences,stores,types,utils,icons,locales,styles}`。
- `internal/{tailwind-config,tsconfig}`。

上游同步后的项目适配保留在对应源码：Zod 4 的 vee-validate TypedSchema 桥接保留默认值、转换与字段描述；请求客户端保留原始 HTTP 错误以支持认证及授权处理；Vue 新版的组件模型使用明确类型与默认值工厂。适配通过公开接口回归，不修改安装后的依赖。历史 SmartAdmin 通用业务封装的来源归属见根 NOTICE；被 Vben 原生能力替代的封装随页面重构删除。

原生布局的项目适配还包括：转发搜索与全屏插槽；偏好按钮连接原生抽屉；偏好编辑策略限制当前应用支持的字段；控件补齐可访问名称及真实禁用状态。搜索历史使用调用方的身份域键，并在菜单变化后重新核对授权路径。偏好管理器使用 `toRaw` 合并以保留连续更新的响应性，支持关闭内置缓存及替换当前端默认值；TypeApp 在应用入口按账号保存差量偏好。

锁屏组件接受应用提供的口令验证与锁定回调，时钟支持当前账号时区。TypeApp 在当前标签页保存绑定真实会话的加盐摘要，刷新恢复锁屏，退出或身份变化清理；上游默认调用方式保留。锁屏设置按钮的中英文文案同步修正。标签页恢复仅保存已授权导航路径，业务组件在离开时释放，详情与一次性秘密不作为页面缓存保存。

`apps/web-antd` 的入口、业务路由、请求协议和物联网组件由 TypeApp 维护。实际依赖与工具版本以 `package.json`、工作区目录及 `pnpm-lock.yaml` 为准。TypeScript 主工具链为 7；当前正式 vue-tsc 和 Vue SFC 编译器仍通过官方 `@typescript/typescript6` 包提供的旧编译器 API 检查 Vue SFC，共享解析见 `scripts/typescript-bridge.mjs`。该检查结果属于 Vue 检查器桥接，不能等同于 TypeScript 7 原生 Vue 检查。
