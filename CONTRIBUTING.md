# Contributing to TypeApp

感谢参与 TypeApp（官方网站：https://iots.top）。仓库中的第一方代码、文档、示例、测试、配置、模板和
Web 应用按 Apache License 2.0 发布；第三方复制内容必须保留其原始来源、
版权和许可证，不得把第三方代码标记为 TypeApp 代码。

## 贡献授权

提交贡献前，请确认你有权提交这些内容。除非另有书面协议，你提交的贡献
按仓库的 Apache License 2.0 条款提供；你保留适用法律允许的版权权益。
项目不要求 CLA，但所有贡献必须遵守 Developer Certificate of Origin 1.1
（https://developercertificate.org/）。

每个提交都必须包含签署行，姓名和邮箱应与提交身份一致：

```text
Signed-off-by: Your Name <your.email@example.com>
```

可使用以下命令创建带签署行的提交：

```sh
git commit -s
```

## 第三方材料

引入 Vben Admin、SmartAdminDeveloper、Docsify、PrismJS、Swoole、TypePHP
或其他依赖时，先记录来源、版本、许可证和原始材料。不要删除或改写上游
版权和许可证文本。构建依赖 `swoole/typephp` 的 GPL-3.0-only 声明必须保持，
不能因为 TypeApp 第一方代码使用 Apache-2.0 而改写。

## 验证

主仓与所有组件、模板仓库统一使用 `main`。框架开发在主仓完成，组件和模板由相应目录分发；不要直接在子仓维护一套不同的实现。当前发布开发分支，稳定版本需完成[实现规划](docs/guide/roadmap.md)中对应的验收。

提交按完整功能组织，相关源码、测试、文档与前端一并提交。使用 Conventional Commits 前缀和准确的中文说明，例如 `fix(mqtt): 修复连接释放时的状态判断`，并通过 `git commit -s` 签署。

提交前至少运行：

```sh
composer validate --strict
composer test:license
composer test:source-rewrites
git diff --check
```

需要原生构建、数据库、Redis 或前端验证时，按对应开发文档准备独立资源，
并在提交说明中区分静态检查、AOT 构建和真实平台验收。
