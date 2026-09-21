# type-build · 构建工具

[返回组件总览](../components.md)

在开发和构建环境中生成配置、路由、模型、任务与操作包装，审计全部生产源码，调用锁定的 TypePHP 编译，并形成可校验的原生产物与运行包。

## 安装与依赖

使用 `require-dev` 安装。PHP 范围为 `>=8.4 <8.6`；原生构建还需要项目锁定的 ZTS PHP、PHPX 与 TypePHP SDK。以应用 lock 和工具链声明为准，不能仅凭 PHP CLI 可以运行就认定 embed 环境完整。

源码位于本仓库对应 plugin 目录。在消费应用根声明依赖后执行：

```bash
composer config minimum-stability dev
composer config prefer-stable true
composer config repositories.type-build vcs https://github.com/zoujingli/type-build.git
composer config repositories.type-runtime vcs https://github.com/zoujingli/type-runtime.git
composer require --dev zoujingli/type-build:dev-main
```

依赖包的 repositories 不会传递给根应用，因此上述命令包含组件的全部传递依赖，使用公开 HTTPS 地址，无需 SSH 密钥。提交应用的 `composer.lock`；`dev-main` 是开发版本，不能等同稳定发布。公共安装约定见[组件总览](../components.md#安装组件)。

## 最小使用示例

在独立应用的 `app/main.php` 中放入以下声明式入口，全局只保留声明：

```php
<?php

declare(strict_types=1);

/** 声明式业务入口，编译后不需要源码加载器。 */
function main(): void
{
    echo "Type 应用已启动。\n";
}
```

## 准备构建配置

应用根目录的 `composer.json` 应声明实际生产入口，例如将 `app` 放入 `autoload.classmap`，然后执行 `composer dump-autoload`。根 `type-app.json`：

```json
{
  "name": "type-example",
  "version": "1.0.0-dev",
  "entry": "app/main.php",
  "sources": ["app"],
  "output": "build/type-example",
  "build-directory": "build/compiler"
}
```

在该应用根执行：

```bash
vendor/bin/type doctor type-app.json build
vendor/bin/type prepare type-app.json
vendor/bin/type type-app.json
vendor/bin/type --inspect build/type-example
```

成功构建后运行当前平台生成的可执行文件，应输出 `Type 应用已启动。`。可执行后缀和依赖布局以实际产物为准。本仓库使用 `docs/build-config/` 下的配置；独立应用仍可使用根 `type-app.json`。

| 配置 | 含义 |
| --- | --- |
| `project-root` | 相对配置文件目录解析；未填时项目根就是配置文件所在目录 |
| `entry` | 声明式应用入口 |
| `sources` | 应用生产源码目录/文件，必须覆盖 Composer 生产入口 |
| `output` | 项目 build 目录内的产物路径 |
| `build-directory` | 项目 build 目录内的编译工作目录 |
| `imports` | 第三方包的精确版本编译适配 |
| `runtime` | 分平台声明真实 embed 运行依赖 |

除 `project-root` 外，上述源码与产物路径按选定项目根解析。不要在应用配置写入本机 SDK 的固定安装路径，SDK 在构建环境显式提供。

## 开发与编译入口

| 命令 | 用途 |
| --- | --- |
| `create` | 从 `type-project` 创建独立业务应用，不复制物联中心；参数与模板来源通过帮助确认 |
| `doctor` | 检查配置与工具链环境 |
| `prepare` | 生成 PHP 开发所需声明结果 |
| `dev` | 使用同一业务源码执行 PHP 开发入口 |
| `watch` | 观察源码变化并重建开发入口 |
| `test` | 执行配置声明的测试流程 |
| `build` 或直接传配置文件 | 全量原生编译 |
| `--inspect` / `--verify` | 读取或校验构建身份 |

先运行 `vendor/bin/type help` 查看安装版本的准确参数。PHP 开发执行与 AOT 是两个验证结果；prepare/dev 成功不等于原生验收通过。

### 接通 PHP 开发命令

最小构建 JSON 只定义原生入口。要使用 `type dev`，先创建[组件示例的开发启动器](../components.md#运行声明式示例)，再将以下字段合入根 `type-app.json`：

```json
{
  "development": {
    "entry": "dev.php",
    "output": "build/development"
  }
}
```

这是补充字段，不是替换整个构建文件。在应用根运行 `php vendor/bin/type dev type-app.json`，最小示例应输出 `Type 应用已启动。`。命令后的参数原样交给开发启动器；带参数 main 要用 `main($argc, $argv)` 调用。

使用生成声明时，由开发启动器先调用 `DevelopmentBuilder::prepareConfiguration()`，加载返回的准确代次文件，再加载业务入口，具体代码见[模型生成加载](type-orm.md#models-relations-output)。单独运行 prepare 只生成文件，不会自动为任意 dev.php 加载类。watch 自动 prepare 后重启开发入口，可通过 `development.check` 声明启动前检查参数；可参考标准模板，最小入口若没有 serve 命令，则不会因使用 watch 而成为 HTTP 服务。

首次生成会静态解析完整生产源码。标准应用本轮在 CLI `memory_limit=256M` 下通过，128 MiB 不足；可使用 `php -d memory_limit=256M vendor/bin/type prepare type-app.json`。watch/test 的子进程也要使用相应 CLI 配置，父进程的单次 `-d` 不会自动传给子进程。此限制属于开发生成，生产运行内存按实际角色另行测量。

## 声明生成与显式调用

`config`、`routing`、`queue` 和 `operations` 配置分别交给对应生成器；模型从生产 `sources` 的 PHP 类型属性与 Attribute 静态识别。生成结果必须进入编译输入，业务必须调用生成入口：

- 路由：构建期从控制器注解或 `config/route.php` 生成 `register()`。`type-app.json` 只写 PHP 路径，旧 JSON 路由表明确报迁移错误；生产请求不扫描 Attribute。
- 模型：保留业务类名和方法，生成字段映射、水合、查询入口与属性钩子，变更后重新 prepare/build。旧 `models` 键明确报迁移错误，原源码、转换结果和生成器身份共同使旧缓存失效。
- 队列：按 type/version/handler 生成 Registry，工厂接收一个 `JobContext`。
- 事务与缓存：生成组合类；原服务方法没有运行时拦截。

当前 PHP `prepare` 生成配置、模型、路由和操作包装，尚不生成 queue 注册表。PHP 开发的队列示例使用显式 `Registry::register()`；配置中的 queue 由原生 build 调用 JobCompiler 生成。不能把 prepare 成功当作生成队列类已经可用。

配置文件与 `config/route.php` 都只解析允许的声明语法，构建时不执行任意 PHP，不读取运行秘密。完整例子见[配置](../configuration.md)、[路由](../routing.md)、[模型](../database.md)和[缓存](type-cache.md)。

## 第三方依赖与源码完整性

生产包通过协议 1 的 `extra.type.sources` 声明输入；没有协议的依赖由应用 `imports` 绑定精确版本。已知源码适配须注明文件摘要、有限替换次数和原因。版本或摘要改变时拒绝旧适配。

根应用的 PSR-4、PSR-0、classmap 和 files 也与源码清单交叉核对。禁用模块不注册命令，但生产源码仍须编译。不能通过排除难编译文件或把生产实现移入 require-dev 绕开完整性检查。

## 产物、运行包与服务配置

`type package <产物> <新目录> [.env.example]` 生成运行目录，包含依赖清单、资源与操作手册。`type verify-package <目录> <受信清单SHA256>` 校验运行包；可信摘要应来自已验证的交付渠道。

例如最小示例在构建成功后运行 `php vendor/bin/type package build/type-example build/release`，目标目录必须尚不存在。命令返回的发布清单摘要应通过受信交付记录保存。校验或归档时，在应用根将 `TYPE_RELEASE_SHA256` 设置为该受信 SHA-256，然后执行：

```bash
php vendor/bin/type verify-package build/release "$TYPE_RELEASE_SHA256"
php vendor/bin/type archive build/release build/type-example.tar.gz "$TYPE_RELEASE_SHA256"
```

归档目标同样必须尚不存在，支持 `.zip` 与 `.tar.gz`；归档前会校验运行包。收到产物后，不能仅在同一不受信目录重新计算摘要便认定来源可信。Windows 的可执行文件路径与归档格式按实际平台选择。

`type service <发布目录> <服务声明.json> <新服务目录> <受信清单SHA256>` 生成 launchd、systemd 或 WinSW 配置，不自动安装服务或修改权限。部署使用独立的数据和环境文件路径，不能将真实 `.env` 纳入构建。

运行包包含匹配 PHPX/libphp 和实际原生扩展，不包含 Composer、编译 SDK 或业务 PHP 回退入口。生产资源与开发工具分开，平台可用性以该版本实际验收为准。

已验证的构建与运行场景包括 Linux x64 基础命令，以及 Linux ARM64、macOS ARM64、Windows x64 三库独立 ORM 消费者的 PHP、AOT 和无源码运行；macOS ARM64 另有完整应用 AOT 和三库身份 HTTP 结果。Windows x64 已完成匹配 Swoole 模块的构建与加载，运行库部署审计与缓存也有已记录结果。各场景的提交、SDK 与完整应用限制见[平台与验收](../platforms.md)；目录包与归档不等于单程序封装完成。

## 常见问题

| 现象 | 处理 |
| --- | --- |
| 生产源码遗漏 | 对照 Composer autoload 和 sources 补齐输入 |
| imports 摘要不匹配 | 核对锁定依赖，更新真实适配并重新验证 |
| CLI 有扩展但构建缺失 | 以真实 embed 探针为准，补齐 runtime 声明与 SDK 模块 |
| 缓存未复用 | 检查源码、锁文件、工具链、参数和资源身份变化 |
| 构建失败但旧文件还在 | 失败不会把旧产物认定为本次成功；检查本次退出码与报告 |

## 验证与继续阅读

本仓库验证入口：`composer test:build-errors`、`composer test:build-source-collection`、`composer test:build-cache`、`composer test:imports`。原生发布和无源码运行另做对应产物验收。

继续阅读：[构建与部署](../deployment.md)、[测试工具](type-testing.md)、[组件安装](../components.md)。

本文以本仓库当前公开接口为依据；安装版本请同时核对包内 README。[对应源码与包说明](https://github.com/zoujingli/type-build)。
