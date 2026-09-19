# 许可证与分发边界

## 第一方内容

TypeApp 主仓、`app/`、15 个 `plugin/type-*` 组件、`templates/type-project/`、
`web/`、文档、示例、测试和配置中的第一方内容按 Apache License 2.0 发布，
版权主体为 Anyon，官方网站为 `https://iots.top`。Composer 元数据使用 SPDX 标识
`Apache-2.0`，根目录和每个可独立分发组件目录都保留 `LICENSE`。

Apache-2.0 授予版权和专利许可，但不授予 TypeApp、TypePHP、Swoole、Vben
Admin 或其他名称的商标权。修改文件和衍生分发必须遵守 Apache-2.0 的通知、
许可证和 NOTICE 条件。

## 第三方依赖

第三方代码不因被 TypeApp 使用而改用 Apache-2.0。当前重要边界如下：

构建工具 `DependencyNotices` 保留 Composer 的原始许可证字段和原始文本，
并把实际生产依赖、原生运行库和材料摘要写入 `NOTICES.md` 及其索引。它不
判断许可证兼容性，也不替任何依赖选择许可证。

## 发布物

源码仓、组件子仓和模板各自携带 `LICENSE`。原生发布目录携带第一方
`LICENSE`、`NOTICE` 和生成的 `NOTICES.md`；其中 `NOTICES.md` 只记录实际
构建输入的第三方声明和材料，不把构建工具误标为运行时第一方代码。

公开发布前必须确认 Anyon 拥有第一方内容的发布权，核对
上游复制范围，并由权利人或法律顾问确认 GPL 构建工具与 Apache-2.0 源码、
原生运行库和软件包的分发边界。本文件不是法律意见。
