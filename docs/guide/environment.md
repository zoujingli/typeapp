# 环境与依赖

部署已验证的 TypeApp 单程序时，不需要另行安装 PHP、Swoole、Composer、Node.js 或编译工具。非系统原生运行库由构建流程校验并静态链接进程序；部署者准备匹配的操作系统与系统库基线、配置及应用实际使用的业务服务。

公开 RC13 已提供四平台 × `sqlite`、`mysql`、`pgsql` 三个 profile，共 12 个单文件程序；RC14 正在用新版工具链重新执行同一矩阵。部署时只选择其中一个匹配平台和数据库的程序，配置可由环境变量或外置 `.env` 提供。普通启动不释放运行库；物联中心的页面由显式安装命令写入 `public/`。文件形态、系统基线和边界见[构建与部署](deployment.md#当前构建状态)。

## 数据库 profile

每个生产程序在编译时固定一个数据库 profile，运行时不能切换。`sqlite` 使用本地文件；`mysql` 和 `pgsql` 连接外部数据库服务。配置中的 `DB_DRIVER` 与程序不一致时返回 `runtime_profile_database_mismatch` 并拒绝继续；关闭的可选能力返回 `feature_unavailable`。Redis 只在队列、调度、告警或导出等启用能力的 profile 中进入依赖闭包，Redis 服务始终由部署环境提供。

物联中心默认三个 profile 都保留告警、导出、队列与调度，因此仍需为这些角色准备 Redis。数据库 profile 只裁剪原生依赖，不扩大既有业务语义：HTTP 管理、告警和导出支持三库；设备 MQTT 的可靠持久接入仍要求 PostgreSQL 同步后端，应选择 `pgsql`。`sqlite`、`mysql` 不承诺同等持久 MQTT 或集群恢复能力。

设备模拟器 `iot:device` 的本地离线缓冲使用 SQLite，设备端应选择 `sqlite` 程序；它可连接使用 `pgsql` 的平台 MQTT 入口。`mysql`、`pgsql` 程序调用这个设备端角色时会在创建缓冲前返回 `runtime_profile_database_mismatch`，不会另行加载 SQLite 扩展。

Redis 地址分别使用 `IOT_EXPORT_REDIS_*`、`IOT_NOTICES_REDIS_*` 和 `APP_SCHEDULER_REDIS_*`，命名空间在同一部署内保持稳定。维护调度通过显式 `app:schedule` 命令执行，不会随 HTTP 启动自动清理数据。通用缓存默认关闭，只有构建 profile 包含 `cache` 且运行配置开启时才使用 `REDIS_*`。

## 按使用阶段准备环境

开发机用于修改源码和快速调试；构建机负责生成目标平台产物；部署机只运行已验证的应用。这三种环境可以在同一台机器上准备，也可以分开管理。

| 阶段 | 需要准备 | 由项目或构建处理 |
| --- | --- | --- |
| 源码开发 | PHP CLI、Composer、匹配的 Swoole 与所选数据库的 PDO 扩展；测试所需业务服务 | Composer 安装组件，开发入口生成配置、路由和模型代码 |
| 原生构建 | 目标平台编译工具、PHP ZTS/embed 静态 SDK、PHPX、实际扩展与锁定依赖；物联中心另需 Node.js/pnpm 构建页面 | TypePHP 全量编译，`type-build` 校验真实 embed 环境，将运行库静态链接并内嵌前端资源 |
| 生产部署 | 匹配的操作系统与架构、已验收的程序、外置配置、数据目录及所用业务服务 | 执行已编译应用；无需安装 PHP、Swoole、Composer、TypePHP 或编译 SDK |

开发 PHP 的版本范围是 `>=8.4 <8.6`，Swoole 范围是 `>=6.2 <7`；这不代表任意组合都能使用内置模块。当前源码的原生构建锁定 PHP 8.5.10 ZTS、TypePHP 0.9.4、PHPX 2.9.3，准确输入取自项目的 `toolchain.lock.json` 与 `composer.lock`。已发布版本使用各自封存的工具链，不能将当前源码的版本套用到旧 Release。

通用模板的 HTTP `serve()` 按平台选择执行方式：Unix 使用经典 worker，Windows 使用协程 HTTP 与控制台停止事件。Windows PHP CLI 使用控制台处理器，原生产物使用编译的控制事件桥；入口需要可用控制台，不能把关闭窗口等同于正常排空。主仓生产 HTTP 采用业务线程内协程。具体入口已实现不等于完整平台验收通过，选择环境时按[平台与验收](platforms.md)核对实际场景。

## 构建机如何复用内置运行库

构建组件的 `resources/swoole/` 保存四平台 Swoole 模块、清单和原始许可证。当前源码固定 6.3 开发快照 `4aff74a`（运行时字符串 `6.3.0RC1`），包含 RC1 后续修复，不标为正式 6.3.0。独立应用安装包含这些资源的 `type-build` 版本后，可直接从组件安装位置复用，无需复制主仓目录或另行下载、编译 Swoole。生产单程序则在构建期静态链接匹配的运行库，部署机无需人工安装这些扩展。

| 内置模块 | 构建匹配条件 |
| --- | --- |
| Linux x64 / ARM64 | PHP 8.5.10 ZTS、非 debug、64 位；Debian 12 / glibc 2.36 构建基线，不适用于 Alpine/musl |
| macOS ARM64 | 同一 PHP ABI；部署目标 15.0，已在 macOS 15 原生 ARM64 runner 通过默认矩阵 |
| Windows x64 | 同一 PHP ABI；PHP 官方 VS17 ZTS SDK |

共享库开发构建自动选择当前平台模块，校验摘要、ABI 与源码适配，再通过真实 embed 探针验证加载。只收集选中的模块及其实际依赖，应用不必声明整目录资源。已有 embed 内置扩展或显式模块配置仍有更高优先级，见[选择与失败处理](plugins/type-build.md#选择与失败处理)。生产单程序使用静态 SDK 中的 Swoole，不加载这些共享模块。

PHP 版本号和 ZTS 一致仍不足以保证二进制兼容：SDK 的编译选项、导出符号和系统基线也必须匹配。例如加载时报 `zend_signal_globals_offset` 符号缺失，说明 PHP 与模块的 Zend 信号构建配置不同；应使用配套 SDK，或通过组件提供的显式源码重建入口生成匹配模块。不能跳过真实加载检查继续构建。

这里的内置模块是构建输入，目前为共享库。Composer 安装不会修改本机 PHP CLI 的 ini，开发入口仍须加载匹配扩展。模块选择可以禁网执行，首次安装依赖和 SDK 准备仍有各自的网络要求。

RC14 公开后，对应 `type-build:1.0.0-rc.14` 将包含这些资源；当前已在主仓核对模块、许可及 Git 属性的字节一致性。旧锁文件指向不含 `resources/swoole/manifest.json` 的版本时，须受控更新后重新构建；日常构建仍以应用锁定提交为准。维护者的源码重建和覆盖入口见[type-build](plugins/type-build.md#内置-swoole-与运行依赖)。

## 部署者需要管理什么

部署重点是业务配置与持久数据。下载与目标平台匹配的程序并核对摘要，然后提供外置配置。页面、数据、上传和日志各有自己的生命周期，更新程序不应覆盖这些内容。

| 项目 | 何时需要 | 部署责任 |
| --- | --- | --- |
| 外置配置 | 所有应用 | 设置监听地址、身份凭据、Host/代理和数据路径；升级保留配置 |
| `public/` 页面 | 物联中心 | 由 `app:install` 从程序写出；升级显式执行 `web:install --force`，普通启动只校验，不释放资源 |
| SQLite | 选择 SQLite 驱动 | 提供可写数据目录和备份；无需单独数据库服务 |
| MySQL / PostgreSQL | 选择对应驱动 | 提供数据库服务、账号、迁移及所需 TLS；客户端能力随构建处理，服务和数据独立管理 |
| Redis | 安装并启用依赖它的能力 | 为缓存、队列、调度或业务角色提供相应服务与命名空间；普通 HTTP + SQLite 应用不必引入 |
| TLS 证书与 CA | 对外 TLS、受保护数据库或设备接入 | 配置证书、信任链和续期；秘密不编入程序 |
| 数据、上传与日志 | 按应用需要 | 指向持久目录，设置权限、容量和备份；不随程序升级覆盖 |

物联中心的设备持久接入要求 PostgreSQL 严格同步主备；通知和导出另有 Redis 依赖。这些是案例业务要求，其他应用按所选组件准备，见[物联网中心](iot-center.md)。Web 管理端的 Node.js 与 pnpm 用于前端开发和构建，不是原生后端的运行依赖。

## 检查与定位

在独立应用根检查开发 CLI 和构建环境：

```bash
php -v
php --ri swoole
php vendor/bin/type doctor type-app.json development
php vendor/bin/type doctor type-app.json build
```

静态单程序在部署目录执行 `./app verify-runtime`，Windows 使用 `app.exe verify-runtime`；命令中的名称以实际程序为准。历史 RC7 目录包执行 `./run verify-runtime`，Windows 使用 `run.cmd verify-runtime`。检查失败时先核对平台、版本和交付文件摘要；原生加载检查与真实业务验收分别进行。SDK 与扩展错误在构建阶段处理，数据库地址、权限、证书和数据目录问题按部署配置处理。

macOS 历史共享库目录包还会核对构建时记录的系统 dyld 共享缓存摘要。更换 macOS 版本或系统更新可能使旧包检查失败，即使能够启动，也不能视为完整部署审计通过。静态单程序按最低系统版本与实际加载映像核验，只允许系统原生库；两种审计结果分别记录，不能通过跳过检查扩大支持范围。各平台的实际范围见[平台与验收](platforms.md#当前平台状态)。

[开始开发](quickstart.md) · [构建与部署](deployment.md) · [性能与调优](performance.md)
