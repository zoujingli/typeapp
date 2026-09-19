# 许可证与归属

TypeApp 由 Anyon 维护，源码按 Apache License 2.0 发布。

你现在阅读的 [iots.top](https://iots.top) 是项目文档站。完整授权文本见 [`LICENSE`](../LICENSE)，第三方来源见 [`NOTICE`](../NOTICE)。业务接口、管理后台和设备接入需要在你自己的部署里配置。

## 项目源码

[TypeApp 主仓](https://github.com/zoujingli/typeapp)、[15 个 Plugins](components.md#组件一览)及 [type-project 应用模板](https://github.com/zoujingli/type-project)统一采用 Apache-2.0。第一方源码、文档、示例、测试、配置、模板和 Web 应用均在授权范围内。

Apache-2.0 允许在满足许可证、版权、修改说明和 NOTICE 要求的前提下使用、修改和再分发，不授予 TypeApp、TypePHP、Swoole、Vben Admin 或其他名称的商标权。

每个可独立安装的 `plugin/type-*` 组件和 `templates/type-project` 都带有自己的 `LICENSE` 与 `NOTICE`，安装、拆出或再分发时一并保留完整许可证和适用的版权通知。

## 第三方边界

第三方代码不会因为被 TypeApp 使用而改成 Apache-2.0。发布物中的依赖材料必须保留实际版本、原始许可证和版权声明：

| 材料 | 处理方式 |
| --- | --- |
| `swoole/phpx` | 保留其 Apache-2.0 声明和上游材料 |
| `swoole/typephp` | 保留 GPL-3.0-only，仅作为独立构建工具披露 |
| Swoole 扩展、PHP、系统库和原生 SDK | 按实际平台、版本和分发材料提供 |
| Vben Admin、SmartAdminDeveloper | 保留上游来源和许可证，不重新授权 |
| Docsify、PrismJS、Mermaid | 保留各自 MIT 许可证原文和版权声明 |

`swoole/typephp` 的 GPL-3.0-only 不会把 TypeApp 第一方代码改成 GPL，也不能把构建工具误写成生产运行时许可证。原生发布包另行生成 `NOTICES.md`，其中记录实际构建输入和第三方材料摘要。

## 分发检查

源码仓、组件包、模板和原生发布包都应携带适用的许可证材料。发布前应确认第一方内容的授权链、上游复制范围、依赖原始许可证和构建工具与运行时的边界；法律结论由实际权利人或法律顾问确认。

许可证文件只说明授权边界，不代表某个组件已经完成全部平台、容量或协议验收。功能状态仍以对应的公开指南和实际验证记录为准。

[返回文档首页](../README.md) · [文档站发布](documentation.md) · [组件参考](components.md)
