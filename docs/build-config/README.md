# 集中构建配置

本目录统一收纳开发主仓的 `type-*.json`。物联中心直接读取自己的完整配置；组件验证通过主仓测试入口创建独立消费者，再由同一 TypePHP 构建器读取配置。框架插件源码仍位于 `plugin/`，每个应用的生产自动加载入口、声明源码及生产依赖都须完整纳入编译。

优先在仓库根目录使用 `composer.json` 中已有的 `build:*` 脚本，例如：

```sh
composer build:native
composer build:mysql
composer build:pgsql
composer build:sqlite
```

物联中心与组件验证分别使用以下入口：

```sh
php vendor/bin/type docs/build-config/type-app.json
php tests/build-scenario.php docs/build-config/type-app.json
php tests/build-scenario.php --stage docs/build-config/type-app.json "$PWD/build/isolated-inputs"
```

## 路径规则

本目录配置统一声明 `"project-root": "../.."`。只有该字段相对配置文件所在目录解析；应用侧的 `entry`、`sources`、`routing` 指向的 PHP 文件及 `native-inputs` 继续相对项目根目录解析，`output`、`build-directory` 和缓存目录仍受项目 `build/` 范围约束。插件自身的源码和资源继续遵循各包声明。因此迁移配置不会把业务源码或产物移到 `docs/`。

`build:*` 是组件和基础能力的测试工作流：从当前受控源码创建独立消费者并实际安装依赖，其生产映射来自该场景的完整输入，不能借用物联中心映射而遗漏业务。物联中心使用 `typeapp:build`；独立业务项目直接调用自己的 `type` 入口，不需要主仓测试工具。每次测试消费者有独立身份，缓存复用须在同一消费者中验证。

构建器要求配置文件位于其声明的项目根目录内；隔离输入保留配置的相对目录结构，重新构建时继续使用同一路径。变更配置及构建工具会参与构建身份，旧产物不能仅因输出路径相同而跳过验证。

未设置 `project-root` 的配置沿用“配置所在目录为项目根目录”的既有规则。独立应用模板与消费者可以继续使用根目录的 `type-app.json`；主仓基础构建夹具是 `type-foundation.json`。从本目录复制配置到独立项目根目录时，应移除 `project-root` 或按新位置重新设置，不能原样继承 `../..`。

## 插件分发边界

此归整不改变 Composer 包名、`plugin/*` 的本地 path 加载或 GitHub Actions 的插件拆分前缀。插件子仓仍只接收映射中对应的插件目录；主仓业务源码、这些构建配置不会因移动而进入插件分发内容。完整应用模板沿用自己的独立目录与分发流程。
