# type-build · 构建工具

[返回组件总览](../components.md)

在开发和构建环境中生成配置、路由、模型、任务与操作包装，审计全部生产源码，调用锁定的 TypePHP 编译，并形成可校验的原生产物与运行包。

单程序构建使用经过校验的静态 SDK，将 PHP、PHPX、Swoole 与非系统库链接进同一个文件；配置独立维护，普通启动不释放运行库。组件保留的四平台 Swoole 共享模块用于开发及历史目录包回归。静态交付的实际平台范围见[构建与部署](../deployment.md#当前构建状态)，部署者无需安装本构建工具。

## 构建链路与职责

`type-build` 负责确认“这次编译用了什么”，TypePHP 负责把完整生产实现编译为原生代码。构建工具还要确认真实 embed 能加载需要的原生库，再把选中的运行依赖纳入产物身份；这些步骤不能用开发 PHP 的扩展列表替代。

```mermaid
flowchart TB
    App[应用、组件与生产依赖] --> Audit[审计源码与锁定身份]
    Audit --> Generate[生成配置、路由、模型与任务]
    Generate --> Compile[TypePHP 全量 AOT]
    SDK[匹配 SDK 与内置原生库] --> Probe[embed 加载校验]
    Probe --> Compile
    Assets[声明的内嵌资源] --> Resource[生成资源常量与摘要]
    Resource --> Compile
    Compile --> Artifact[原生产物与身份清单]
    Artifact --> Package[SingleProgram 单文件输出]
    Package --> Verify[校验后运行与无源码验收]
    style Compile fill:#147d64,color:#fff,stroke:#147d64
```

构建通过 `TYPE_STATIC_RUNTIME` 选择目标 SDK，真实 embed 缺少内置扩展时立即拒绝，不回退加载 `.so`。未选择静态 SDK 的底层开发构建仍可供旧场景测试，但不能通过 `package`。运行配置在启动时从外部读取，真实 `.env` 不进入编译输入。

## 内嵌静态资源

构建配置可显式声明项目根内的资源目录与目标前缀：

```json
{"embedded-resources": [{"source": "web/dist", "target": "web"}]}
```

构建器排序收集普通文件，记录相对路径、大小和 SHA-256，拒绝越界、符号链接、秘密文件、PHP 源码及大小写冲突；每次构建最多 1000 个文件、总计 64 MiB。资源生成 C++ 常量数据并链接进 ELF、Mach-O 或 PE，内容变化使构建缓存失效。已有 `resources` 仍表示随产物管理的外置资源，两者不会互相替代。

生成的 `Type\Generated\EmbeddedResources::manifest()` 返回资源清单，`read(string $path, int $offset, int $length)` 按块读取，单次最多 65536 字节。应用负责决定何时安装及如何服务资源；组件不会在普通启动时自动解包。物联中心采用[显式安装流程](../deployment.md#前端安装与更新)，通用模板不默认携带页面。

## 数据库 profile 与依赖裁剪

```mermaid
flowchart TB
  Profile["应用声明：数据库与功能"] --> Closure["计算依赖闭包"]
  Closure --> Driver["只选择一个 PDO 驱动"]
  Closure --> Optional["按需选择 Redis 等扩展"]
  Base["Swoole、PDO、TLS 等基础库"] --> Link
  Driver --> Link["TypePHP 全量 AOT · 静态链接"]
  Optional --> Link
  Link --> Seal["清理符号 → 封存能力、摘要与体积"]
  Seal --> Program["一个主程序 + 外置配置"]
  Program --> Verify["启动前核对数据库与功能边界"]
```

应用在 `type-app.json` 中声明 `build-profiles`，构建时通过 `TYPEAPP_BUILD_PROFILE` 或配置项 `build-profile` 选择一个 profile，例如 `TYPEAPP_BUILD_PROFILE=sqlite`。构建器把 profile、数据库、功能闭包、扩展、静态归档写入编译身份，并在链接后报告最终系统库：

```json
{
  "build-profiles": {
    "sqlite": {"database": "sqlite", "features": ["web", "mqtt", "iot", "alerts", "exports", "queue", "scheduler"]},
    "mysql": {"database": "mysql", "features": ["web", "mqtt", "iot", "alerts", "exports", "queue", "scheduler"]},
    "pgsql": {"database": "pgsql", "features": ["web", "mqtt", "iot", "alerts", "exports", "queue", "scheduler"]}
  }
}
```

每个程序只链接自己的 pdo_* 驱动；`alerts`、`exports` 会闭包启用 `queue`，`queue` 和 `scheduler` 会闭包启用 `redis`。因此物联中心默认 profile 会包含 phpredis；自定义 profile 关闭这些能力时，清单会记录 `rejected-capabilities`，运行到关闭能力会返回 `feature_unavailable`。未知 profile、数据库配置不匹配或关闭能力都会在构建/启动边界返回稳定错误。RC14 正在用新版工具链逐项执行四平台 × 三 profile 验收；在同一轮次全部完成前，不能把候选写成已通过。

候选清单中的 `size-breakdown` 读取最终封存文件的真实区段：`total` 与下载字节数一致，`code` 是可执行区段，`data` 是其余字节，`frontend` 是内嵌 `web/` 原文字节，`native` 是静态归档输入大小。后两者不能与 code/data 重复相加。Linux 不保留调试或非必要符号区段，Windows 不保留 PDB 调试目录，macOS 执行 `strip -x` 后保留必需外部符号。体积增长门禁及测量边界见[构建身份](https://github.com/zoujingli/typeapp/blob/main/docs/development/build-identity.md)。

静态 SDK 按发布参数编译，并关闭未使用的 PHP JIT；PHP 8.5 自带的 OPcache 核心仍可能出现在扩展表中。程序启动配置已固定关闭 OPcache，应用和组件继续由 TypePHP 全量 AOT。源码裁剪、TLS 关闭或 PHP 源码回退都不能用来通过体积门禁。

物联中心的 profile 不包含独立 `sqlite3`、`mysqli`、`pgsql` 扩展，也不包含 PHP `session` 和 `tokenizer`。数据库由所选 PDO 驱动访问，账号会话持久化在业务数据库，源码解析在构建机完成；SQLite profile 仍链接 PDO 与 Swoole 协程桥接所需的 SQLite 原生库。`all` 开发 SDK 保留历史扩展，不能作为这些 profile 的发布 SDK 使用。

`cache` 是可选的通用 Redis 缓存，显式声明后才加入其能力；默认队列等功能虽已依赖 Redis，也不会自动开启缓存。内置 SDK 制备脚本当前支持三种数据库名称和 `all` 开发入口；自定义应用可通过 `TYPEAPP_BUILD_CONFIGURATION` 指定含同名 profile 的配置。额外 DOM/XML/intl/zip 能力需要另行准备并验证匹配的静态 SDK，内置制备脚本会明确拒绝，不会静默忽略声明。

## 安装与依赖

使用 `require-dev` 安装。PHP 范围为 `>=8.4 <8.6`；原生构建还需要项目锁定的 ZTS PHP、PHPX 与 TypePHP SDK。以应用 lock 和工具链声明为准，不能仅凭 PHP CLI 可以运行就认定 embed 环境完整。

在消费应用根执行以下命令，源码与完整 API 说明也随包安装：

```bash
composer config minimum-stability RC
composer config prefer-stable true
composer require --dev zoujingli/type-build:1.0.0-rc.14
```

以上固定该组件的候选版本 `1.0.0-rc.14`，RC 不代表稳定版本；执行前按[版本安装说明](../releases.md#composer-按版本安装)核对公开状态。Composer 从默认 Packagist 解析传递依赖，无需配置 VCS 仓库；提交应用的 `composer.lock` 固定实际版本。开发分支与版本安装的区别见[组件总览](../components.md#安装组件)。

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

按顺序观察每一步，而不只检查最终目录是否出现：

| 步骤 | 应观察的结果 | 失败后处理 |
| --- | --- | --- |
| `doctor … build` | 配置归属、锁定 SDK 与构建要求得到检查 | 按实际诊断补齐构建环境 |
| `prepare` | 生成当前输入对应的开发代次 | 修改声明后重跑，不手改生成文件 |
| 全量构建 | 当前命令成功退出，并返回本次产物身份 | 保留错误报告，旧二进制不能算本次成功 |
| `--inspect` | 能读取当前产物的构建 ID、平台和清单 | 清单缺失或损坏时停止打包 |
| 运行产物 | 输出与 PHP 入口一致 | 分别检查原生加载和业务失败 |

首次构建前还要准备与应用匹配的 `toolchain.lock.json`；标准模板已携带该声明。自行创建最小项目时应沿用[工具链约定](../typephp.md)，不能只复制上面的 JSON 便假定 SDK 已具备。

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

## 内置 Swoole 与运行依赖

`type-build` 的 Composer 分发内容包含四平台 Swoole 共享模块、清单和原始许可证。当前源码固定 6.3 开发快照 `4aff74a`（运行时字符串 `6.3.0RC1`），不标为正式 6.3.0。主仓位置是 `plugin/type-build/resources/swoole/`，独立应用通常安装在 `vendor/zoujingli/type-build/resources/swoole/`；构建组件按自身安装位置查找，不依赖应用根目录或当前工作目录。应用无需复制主仓目录，也无需增加 `resources` 声明；根目录 `build/` 继续只保存不入仓的生成产物。

公共 `type-build` 开发分支已包含上述资源，并完成独立安装与字节核对。应用提交 `composer.lock` 固定实际版本；旧锁文件指向不含清单的分发版本时，须先受控更新再使用默认内置模块。

预编译模块固定使用 **PHP 8.5.10、ZTS、非 debug、64 位 ABI**。当前主仓源码工具链为 TypePHP 0.9.4、PHPX 2.9.3，准确版本以应用的工具链锁和 Composer 锁文件为准。源码升级进度与已公开 Release 分开记录，见[本次升级验收](https://github.com/zoujingli/typeapp/blob/main/docs/evidence/typephp-upgrade-0.9.4.md)。

| 资源子目录 | 平台与限制 |
| --- | --- |
| `linux-x64/php-8.5.10-zts/swoole.so` | Linux x86-64，Debian 12 / glibc 2.36 构建基线 |
| `linux-arm64/php-8.5.10-zts/swoole.so` | Linux ARM64，Debian 12 / glibc 2.36 构建基线 |
| `macos-arm64/php-8.5.10-zts/swoole.so` | macOS ARM64，部署目标 15.0；新版模块加载与 PDO hook 已验收，完整应用范围见升级记录 |
| `windows-x64/php-8.5.10-zts/php_swoole.dll` | Windows x64，PHP 官方 VS17 ZTS ABI |

Linux 模块不适用于 Alpine/musl；NTS、其他 PHP 版本、macOS Intel 和 Windows ARM64 未提供内置文件。模块包含项目的编译线程、HTTP、Socket 与 TLS 适配，普通官方 Thread 可用不代表满足项目的编译入口 ABI。

### 选择与失败处理

运行依赖按以下顺序选择：

1. 真实 embed 已内置 Swoole 时，不重复加载共享模块。
2. 使用显式 `runtime.modules` 声明的候选。
3. 未显式声明时，使用有效的 `TYPE_SWOOLE_MODULE` 文件候选。
4. 否则从组件的 `manifest.json` 选择匹配模块，校验 SHA-256 和源码适配身份。

默认内置清单缺失、ABI 不匹配、文件越界、摘要不符或源码适配过期时，构建明确失败，不自动回退到 SDK 中的 Swoole。修复方式是安装完整且匹配的组件，或明确提供已验证的模块覆盖；不要只修改清单摘要。其他扩展仍按 SDK 或显式候选解析。最终还要通过真实 embed 的加载、版本、必需函数与警告检查，PHP CLI 加载成功不能替代这些检查。

```mermaid
sequenceDiagram
    participant Build as 构建器
    participant Embed as 真实 embed
    participant Select as 模块选择
    participant Package as 构建身份与运行包
    Build->>Embed: 检查内置扩展
    alt embed 已有 Swoole
        Embed-->>Build: 内置能力
    else 需要共享模块
        Build->>Select: 显式模块 → 环境候选 → 组件资源
        Select->>Select: 校验 ABI、路径、摘要、适配身份
        Select-->>Build: 当前平台的模块与清单
        Build->>Embed: 加载并检查版本、函数与警告
    end
    Embed-->>Build: 运行依赖检查结果
    Build->>Package: 记录实际选中的模块和依赖
```

覆盖模块适用于已验证的自建 SDK，不会改变全量编译要求。检查失败时修复输入或适配，不能把失败候选作为可分发产物。

匹配模块的选择无需联网，也无需另行下载或编译 Swoole；PHP SDK、PHPX 和其他依赖仍需准备，Composer 安装及整个构建不因此自动离线。组件安装也不会自动修改开发 PHP 的 ini；`type dev` 所用 CLI 仍需加载匹配的扩展。

所选清单和模块进入构建身份，应用产物只收集当前平台选中的模块及实际依赖，不会携带全部四平台模块。再分发须保留适用的原始许可证，见[许可证与归属](../licensing.md#第三方边界)。源码、摘要、依赖和维护者的 `TYPE_SWOOLE_BUILD_FROM_SOURCE=1` 重建入口见[资源说明](https://github.com/zoujingli/typeapp/blob/main/plugin/type-build/resources/swoole/README.md)。

这些 `.so/.dll` 是共享扩展构建输入，不能用于静态链接。单程序构建使用按 profile 生成并校验的静态 SDK 归档；RC14 的 12 个 profile 程序仍需使用新版工具链逐项完成同一产物验收。实际可下载版本与产物形态见[构建与部署](../deployment.md#单程序交付约定)。

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

`type package <静态产物> <新程序文件>` 原样交付一个文件。`type verify-package <程序文件> <受信SHA256>` 离线校验程序字节、身份、内嵌许可索引与系统加载项；可信摘要应来自已验证的交付渠道。

应用的 `LICENSE`、`NOTICE` 和实际依赖原文在构建时内嵌，应用未提供的材料不会用框架许可补位。单程序交付必须具有完整的已声明依赖许可材料，不接受仅记录缺失项的产物。物联中心通过 `licenses` 命令读取索引及登记原文；独立应用可使用生成的 `EmbeddedResources` 接口提供自己的读取入口。

例如最小示例完成静态构建后运行 `php vendor/bin/type package build/type-example build/example-release`，目标文件必须尚不存在。命令返回程序 SHA-256，通过受信交付记录保存后执行：

```bash
php vendor/bin/type verify-package build/example-release "$TYPE_RELEASE_SHA256"
```

收到产物后，不能仅在同一不受信目录重新计算摘要便认定来源可信。Windows 单程序须使用 `.exe`；静态 SDK、隔离部署和数据库行为按三个独立 profile 分别验收，实际发布版本和支持范围以[平台与验收](../platforms.md)为准。

服务配置使用 `type service <程序文件> <服务声明.json> <新服务目录> <受信程序SHA256>`，直接启动经过校验的单程序。生成器也接受历史发布目录及其清单摘要；`package-directory`和`archive`用于该历史交付。生成配置不代表安装或系统服务验收通过，详见[原生服务管理](https://github.com/zoujingli/typeapp/blob/main/docs/development/native-services.md)。运行数据与程序目录分离，不将真实 `.env` 纳入构建。

运行包包含匹配 PHPX/libphp 和实际原生扩展，不包含 Composer、编译 SDK 或业务 PHP 回退入口。生产资源与开发工具分开，平台可用性以该版本实际验收为准。

RC14 正在同一源码基线上执行四平台默认原生 CI、12 个数据库 profile 静态程序隔离部署、公共组件与模板分发。各平台最终程序禁止读取构建源码与 SDK、执行开发工具；公开下载摘要必须与候选一致。独立组件与模板仍记录各自入口和产物。准确提交、SDK 与限制见[平台与验收](../platforms.md)；历史目录包维护入口不进入新的单程序候选。

## 常见问题

| 现象 | 处理 |
| --- | --- |
| 生产源码遗漏 | 对照 Composer autoload 和 sources 补齐输入 |
| imports 摘要不匹配 | 核对锁定依赖，更新真实适配并重新验证 |
| CLI 有扩展但构建缺失 | 以真实 embed 探针为准，核对内置 Swoole 的 ABI、显式覆盖及其他 SDK 扩展 |
| 内置 Swoole 校验失败 | 核对完整安装、清单、模块及源码适配身份；更新组件或提供已验证覆盖，不绕过摘要校验 |
| 缓存未复用 | 检查源码、锁文件、工具链、参数和资源身份变化 |
| 构建失败但旧文件还在 | 失败不会把旧产物认定为本次成功；检查本次退出码与报告 |

## 验证与继续阅读

本仓库验证入口：`composer test:build-errors`、`composer test:build-source-collection`、`composer test:build-cache`、`composer test:imports`。`composer test:bundled-swoole-consumer` 验证组件真实拆分和 Composer 独立安装后的本地选择、字节及许可材料；原生发布和无源码运行另做对应产物验收。

继续阅读：[构建与部署](../deployment.md)、[测试工具](type-testing.md)、[组件安装](../components.md)。

本文以本仓库当前公开接口为依据；安装版本请同时核对包内 README。[对应源码与包说明](https://github.com/zoujingli/type-build)。
