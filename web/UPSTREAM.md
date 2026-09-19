# 前端源码来源

Vben 基线为 Vue Vben Admin 5.6.0，项目 `vbenjs/vue-vben-admin`，应用形态 `@vben/web-antd`。本次从已核对的 `zoujingli/SmartAdminDeveloper` 提交 `a61c041a1c3d9f6a44d5c4da4ef55b1f18a5f39d` 提取公共依赖闭包。保留 [Apache-2.0 许可证](LICENSE)和各工作区包内的作者、仓库及版本字段。

以下25个公共工作区包按参考提交复制，未引入参考业务页面、后端契约或插件扫描机制：

- `packages/effects/{layouts,hooks,request}`。
- `packages/@core/{composables,preferences}`。
- `packages/@core/base/{shared,icons,typings,design}`。
- `packages/@core/ui-kit/{form-ui,shadcn-ui,layout-ui,menu-ui,popup-ui,tabs-ui}`。
- `packages/{constants,preferences,stores,types,utils,icons,locales,styles}`。
- `internal/{tailwind-config,tsconfig}`。

共享应用组件来自同一提交的 `web/apps/web-antd/src/components/{crud-search-field.vue,crud-table-actions.vue}`、`web/apps/web-antd/src/utils/{table.ts,async-action.ts}`。`components/app-drawer` 来自该提交 `web/packages/effects/common-ui/src/components/app-drawer`，保留其完整实现，在本应用直接消费，避免引入未使用的富文本等公共包依赖。

`apps/web-antd` 的入口、布局装配、请求适配、物联网页面和样式由本项目维护。依赖固定在 `pnpm-lock.yaml`，其中 Vue 为3.5.35、Ant Design Vue为4.2.6，包管理器为pnpm10.28.2。`pnpm-workspace.yaml` 保留参考依赖目录；实际安装范围由本仓库工作区和锁文件确定，不依赖参考检出目录。
