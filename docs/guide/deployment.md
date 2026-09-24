# 构建与部署

TypeApp 的生产交付约定是**一个程序文件加外置配置**。生产 PHP 实现由 TypePHP 全量编译，通信与基础并发必须使用 Swoole。当前打包命令仍生成目录包，以下分别说明交付目标与已实现操作。

开发工具使用 PHP 进行快速准备与调试，两条路径共用业务源码，生产执行已编译入口。本仓库根上的 Composer 脚本面向成品案例物联中心；其他业务用 `type-project` 创建独立应用后，在该应用根执行对应命令。

```mermaid
flowchart LR
  Src["同一套业务源码"] --> Dev["PHP prepare / dev"]
  Src --> Aot["TypePHP build"]
  Aot --> Pkg["运行包 package"]
  Pkg --> Run["原生入口运行"]
```

## 单程序交付约定

每个目标平台交付自己的原生程序，外置 `.env` 保存部署配置。PHPX、libphp、Swoole 及其他非系统原生依赖在构建期静态链接进程序，启动时不向磁盘释放运行库；允许使用目标操作系统自带的库。部署人员无需手动安装、拆分或配置这些运行库。该要求尚在[静态链接可行性验证](https://github.com/zoujingli/typeapp/blob/main/docs/development/static-runtime-feasibility.md)阶段。

| 项目 | 交付和维护方式 |
| --- | --- |
| 程序文件 | 包含已编译应用、生产组件、资源及所需原生运行库；Windows 使用 `.exe` |
| 外置配置 | 与程序分开维护，保存部署参数和秘密；升级不覆盖 |
| 数据、上传和日志 | 运行时按需创建，路径以应用目录或明确的数据根为准；切换工作目录不改变目标 |
| 内部运行库 | 构建期核对版本、ABI、许可证和摘要后静态链接；启动不释放 `.so`、`.dylib` 或 `.dll` |

一个程序可按入口运行 HTTP、MQTT 或后台角色，角色间通信与进程/线程/协程仍采用 Swoole。程序文件数量与运行角色数量是不同概念，进程不可用时的执行选择见[系统架构](architecture.md#运行方式与平台)。

当前打包工具已提供原生依赖收集、目录包校验与归档，尚未组成可直接执行的单文件程序。静态产物需核对完整依赖闭包，并验证无源码、无外部非系统运行库、启动不释放库、含空格目录、配置与数据保留。项目内可保存真实静态归档或目标文件作为构建输入，现有 `.so`／`.dll` 不能直接替代它们。**下文目录包操作是当前实现，不代表上述交付目标已经完成。**

## 检查构建环境

工具链版本以当前项目的 `toolchain.lock.json` 为准，生产依赖以 `composer.lock` 为准。准备与目标 OS、架构一致的 SDK 和扩展，再检查构建环境。

构建组件已内置四个平台的 [Swoole 共享模块](plugins/type-build.md#内置-swoole-与运行依赖)，随 Composer 包安装，构建时默认校验并复用，无需另行下载 Swoole。它们固定匹配 PHP 8.5.10 ZTS、非 debug、64 位 ABI；只收集所选模块与实际依赖，不要求应用声明整目录资源。PHP SDK、PHPX 和其他原生依赖仍需准备，完整静态单程序目标继续待完成。

独立应用根执行：

```bash
php vendor/bin/type doctor type-app.json development
php vendor/bin/type doctor type-app.json build
```

本仓库使用 `docs/build-config/type-app.json` 作为构建配置。doctor 检查所选范围的前置条件，不连接业务服务；检测通过不等于应用已编译或运行验收通过。

**已验证平台：Linux x64 / ARM64、macOS ARM64、Windows x64。** Linux x64 已通过基础命令 AOT 与运行；其余三者已通过三库独立 ORM 的 PHP、AOT 和无源码运行，macOS ARM64 另有完整应用 AOT、三库身份 HTTP 和通信专项结果。具体范围和证据归属见[平台支持表](platforms.md#当前平台状态)。构建、运行库、数据库与停止语义都需要在实际目标环境验证；Docker 或 WSL 中的 Linux 结果不能替代 Windows/macOS 原生结果。

实际状态见[平台与验收](platforms.md)。每个平台都必须提供匹配的 Swoole、PHPX、libphp 和生产扩展，再以同一产物完成完整应用 AOT、通信、数据库和无源码部署验收。以下命令描述工具已有入口，执行前仍须满足所选应用和平台的全部前置条件。

## 全量编译

物联中心成品案例在本仓库根执行：

```bash
composer typeapp:build
build/app/type-app check
```

独立模板在应用根执行：

```bash
composer build
```

构建将框架、业务、生成配置/路由/模型/操作组合类及实际生产依赖一起交给 TypePHP。新增路由、声明或生产代码都需要重新构建。100% 指完整生产实现的编译覆盖，不等于全部平台已验证。

## 创建当前目录包

物联中心成品案例提供以下入口，目标目录必须尚不存在：

```bash
composer typeapp:package
build/release/run verify-runtime
build/release/run help
```

独立模板用 `composer package`。Windows 使用对应发布目录中的 `run.cmd`。

运行包包含原生应用、资源、所需运行库、身份清单、配置示例及操作手册。没有业务 PHP 源码回退，但仍需要匹配的 PHPX、libphp、PDO 等实际原生依赖；不应将其描述为零运行库依赖。

| 文件或目录 | 用途 |
| --- | --- |
| `release.json` | 目标、构建身份、依赖与文件摘要 |
| `run` / `run.cmd` | 对应平台启动入口 |
| `bin/`、`lib/`、`runtime/` | 应用、实际运行库与受控配置 |
| `config/env.example` | 无秘密的环境示例 |
| `DEPLOY.md`、`OPERATIONS.md` | 发布目录说明与部署、备份、恢复手册 |

## 首次启动

在运行环境明确应用数据根、数据库、令牌和 Host/代理配置，然后在发布目录执行：

```bash
./run verify-runtime
./run migrate run
./run migrate status
./run serve
```

`verify-runtime` 检查运行身份与实际依赖，不替代业务验收。服务运行前显式完成迁移；数据库、日志、上传与秘密配置放在部署环境维护的数据位置。

对外提供服务时配置对应的系统服务或监督进程，保留正常排空和资源回收时间，并设置停止的总截止。TLS 可在前置反向代理终止，只信任真实受控代理。

## 物联网角色部署

本仓库的物联网中心标准项目共用完整应用构建，但运行角色分别管理：HTTP、MQTT Broker、数据接收、统计、告警、通知和导出不能用单个 `serve` 命令替代。具体角色、环境键和管理端构建见[物联网中心](iot-center.md)。`type-project` 模板不默认包含这些业务与 Web 资源。

设备 MQTT 与接收角色要求 PostgreSQL 严格同步主备和真实 TLS。先完成业务迁移及 `iot:mqtt-install`，再由角色宿主启动相应入口；`IOT_MQTT_COMMAND` 明确指向同一已验证应用产物。运行包必须携带匹配的 Swoole 原生扩展。管理 API 三库通过不构成 MQTT 三种存储后端或高可用通过证明。

Broker 接入、持久工作和设备授权均使用 Swoole 官方 Process、Thread 或 Coroutine 管道与网络能力；平台按实际构建能力选择执行方式，不按操作系统名称拒绝通信入口。完整角色隔离、停止和原生验收仍以对应平台产物证据为准，详见[平台与执行方式](communications.md#平台与执行方式)。

每个 Broker 配置唯一稳定节点名，并保留本次运行身份。`iot:mqtt-fence` 只登记精确旧实例已完成的基础设施硬隔离，不能代替断开网络或停止旧主。私有导出目录、WAL、备份、秘密及设备缓存与 Web 静态目录分开管理；所有角色保留有界停止、失败日志与未知结果。

## 升级与恢复

升级生成新的发布目录，保留旧版本及明确的备份。先核对版本、依赖、数据库兼容和迁移计划，再切换服务；切回旧二进制不会自动回滚数据库。

运行包内的 `OPERATIONS.md` 是该产物随附的操作手册，涵盖首次部署、升级、三库备份与恢复，以及不能自动回滚的情况。恢复演练使用新目标并保留原数据，按实际数据库语义验证。

物联网恢复另使用 `iot:recovery snapshot/status/begin/isolate/review/restore`，先隔离旧系统，再依据受信的当前授权快照核对恢复后的身份。恢复门限制新角色启动，不会代替维护人员停止旧进程。管理端仅显示相关状态，不提供绕过核对的一键恢复；旧身份、设备归属和未完成指令不能随数据库回滚自动重新授权。

发布文件摘要需从受信渠道取得。`type verify-package` 接收受信的 `release.json` SHA-256；程序和同一不受信来源的摘要不能互相证明来源可信。

## 验收自己的应用

先执行对应项目的开发检查，再用准确的原生产物运行相同业务行为。核对 HTTP、输入校验、事务、错误与资源清理，并在所选数据库和目标平台完成无源码部署验证。

独立模板提供 `composer test`；它会创建测试用户，须在专用测试数据库运行。开发检查、编译成功和原生业务验收分别记录，不能互相替代。

[返回快速开始](quickstart.md) · [查看组件参考](components.md)。
