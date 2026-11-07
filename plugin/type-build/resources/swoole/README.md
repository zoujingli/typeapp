# 内置 Swoole 构建输入

当前源码基线为官方 Swoole 6.3 开发快照 `c9745c443265db59d0a9f32310e1f27e1873739f`，运行时字符串仍为 `6.3.0RC1`，不是正式 6.3.0。2026-11-07 核对的最新稳定发行版为 [6.2.5](https://github.com/swoole/swoole-src/releases/tag/v6.2.5)；当前快照位于 `v6.3.0-rc1` 后 159 个提交，官方 6.2 稳定线与它分叉，不能直接替换。项目沿既有 6.3 路线通过统一的 Linux、macOS、Windows 重建入口固定归档和适配摘要，不追随浮动分支。固定 PHP 为 **8.5.10、ZTS、非 debug、64 位**，其他 ABI 不自动混用。

| 目录 | 平台与边界 |
| --- | --- |
| `linux-x64/php-8.5.10-zts/swoole.so` | Linux x86-64，Debian 12 / glibc 2.36；重建运行 41367743137 |
| `linux-arm64/php-8.5.10-zts/swoole.so` | Linux ARM64，Debian 12 / glibc 2.36；重建运行 41367743137 |
| `macos-arm64/php-8.5.10-zts/swoole.so` | macOS ARM64，部署目标 15；重建运行 41367743137 |
| `windows-x64/php-8.5.10-zts/php_swoole.dll` | Windows x64，PHP 官方 VS17 ZTS ABI；重建运行 41367743137 |

本轮四个平台模块均由 [重建运行 41367743137](https://github.com/zoujingli/typeapp/actions/runs/41367743137) 从同一源码提交构建并通过模块加载、协程 SQLite、PostgreSQL 连接拒绝和 ABI 校验；Windows 另通过中文环境、DNS、TLS 和 curl 回归。清单已回填各 Artifact、源码、依赖和模块摘要，选择器不再拒绝这些新模块。安装完整且模块匹配的 `zoujingli/type-build` 后，构建从组件安装位置校验、复用本地文件，不依赖主仓布局；文件不是 Git LFS 指针。

模块替换后的应用、真实三库与工具链结果统一见[当前平台状态](https://iots.top/#/guide/platforms?id=当前平台状态)，逐项保留源码提交、运行链接和失败后的复验身份。本轮共享模块重建不包含十二个静态单程序重建或跨平台性能对照。

Linux 文件不适用于 Alpine/musl。macOS Intel、Windows ARM64、NTS 或其他 PHP 版本未提供预编译文件。模块存在和能够加载均不等于完整应用的平台验收。

macOS ARM64 还要求配套 PHP 启用 Zend signals；关闭该选项的 Homebrew `php-zts` 不兼容。主仓 CI 通过 `tools/install-locked-macos-php.sh` 准备匹配的锁定 SDK。共享 PDO 驱动由构建组件安排在 Swoole 之前加载，先完成原生驱动注册，再启用对应 hook。

## 选择与校验

运行库的选择仍由 `Type\Build\RuntimeProfile` 负责：

1. 真实 embed 已内置的 Swoole 不重复加载。
2. 显式 `runtime.modules` 声明优先，其次为有效的 `TYPE_SWOOLE_MODULE`。
3. 默认读取本组件的 [manifest.json](manifest.json)，按操作系统、架构和 PHP ABI 选择本目录文件，校验 SHA-256、固定源码和运行版本，以及 Thread、HTTP、Socket 适配类的身份，Windows 另验 Windows 适配类；清单缺失或不匹配时失败。运行时字符串相同不能替代源码提交核验。

实际 embed 仍须通过模块加载、版本、依赖和警告检查，清单和选中模块一同进入构建身份。独立应用直接使用已安装组件的 `resources/swoole`，路径不依赖应用根目录或当前工作目录。其他扩展继续按 SDK 或显式候选解析；本目录是构建输入，不声明为应用资源，不会把四个平台的文件全部复制到应用产物。

以下维护脚本位于 [TypeApp 开发主仓](https://github.com/zoujingli/typeapp)，从主仓执行；独立应用正常构建无需运行这些脚本。

```bash
# 使用 PHP_HOME 指向的锁定 SDK；可从任意工作目录运行该脚本。
bash tools/prepare-swoole-module.sh
# 仅查询并核验本地模块，不加载 Composer，不访问网络。
"$PHP_HOME/bin/php" -n tools/select-swoole-module.php
```

Windows 的 SDK 准备入口为 `.github/scripts/prepare-windows-native.ps1`，默认将校验后的本地 DLL 放入 SDK 的 `ext` 目录，跳过 Swoole 源码和 phpize 工具下载。

Windows 共享模块已启用 `--enable-swoole-curl`，构建输入固定官方 libcurl 8.22.0 与 libssh2 1.11.1 的归档并校验原始许可及 CRT。重建门禁实际验证独立主线程、两轮双工作线程及最后主线程的请求归属、等待期间事件循环运行、长连接复用、`curl_reset()` 和超时恢复；每个角色完成 36 次成功响应。PHP curl 扩展另报告其自身版本，不能将该字符串或 hook 标志当作 Swoole 原生 curl 的构建身份。生产静态 SDK 的构建及完整应用验收单独记录。

## 来源与依赖

新基线来自官方 [swoole-src](https://github.com/swoole/swoole-src/tree/c9745c443265db59d0a9f32310e1f27e1873739f)。固定归档摘要、模块摘要、适配身份、构建参数和逐平台来源记录在清单中。TypeApp 保留原生线程入口 ABI 2、共享 HTTP 监听和连接/输入准入；删除官方已覆盖的空 UDP 来源与重复构造补丁。TCP、HTTP、WebSocket 客户端开启官方 `ssl_verify_host`；标准 stream hook 尚未映射此选项，仍需最小 IP SAN 兼容适配以保持协程 Redis/mysqlnd 行为，实证和撤除条件见[TCP 说明](https://github.com/zoujingli/typeapp/blob/main/docs/development/tcp-native-io.md#数字-ip-的-tls-校验修正)。上游继续承担线程标准流回收。

Linux 两种架构使用官方 `php:8.5.10-zts-bookworm` 镜像构建，启用线程、sockets、mysqlnd、c-ares、PostgreSQL/SQLite hook、OpenSSL 和原生 cURL。运行仍需要相容的 libpq、SQLite、c-ares、OpenSSL、libcurl、C++ 与系统库，现有原生依赖收集器负责纳入发布清单。

macOS 模块将 libpq（含配套的 libpgcommon_shlib、libpgport_shlib）、SQLite、OpenSSL、c-ares、Brotli 的静态归档链接进扩展，并隐藏这些库的符号，避免与 PHP 已加载的同名库混用。扩展保留 PHP API 的动态绑定以及系统库依赖；没有开发电脑的依赖路径，不需要伴随 dylib。静态子依赖及其来源记录在清单中。

升级前的 Unix 和 Windows 模块身份继续保留在历史记录；当前四平台模块统一来自 [重建运行 41367743137](https://github.com/zoujingli/typeapp/actions/runs/41367743137)，源码提交为 `41a6739`，并分别记录 Linux glibc、macOS ARM64 和 Windows VS17 ABI 的真实 Artifact。Windows 仍保留 IPv6 地址初始化与独占绑定适配、TLS 正常 EOF 修复；IOCP 关联缓存由 c-ares 和 libcurl 的真实关闭生命周期回收，使存活连接可重复登记事件、关闭后的数值句柄可安全复用。模块静态链接固定 c-ares 1.34.8，使指定 DNS 配置在主线程和重建的工作线程生效。共享模块使用与开发 PHP 一致的 `/MD` CRT，生产静态 SDK 独立使用 `/MT`；开发 SDK 的配套 DLL 不属于生产单程序的部署文件。

### Windows 环境编码修复

RC14 的 Windows DLL 保留 `37134319268` 的历史构建身份。后续诊断确认，加载该 DLL 后，Windows 的窄字节 `putenv()` 会把 UTF-8 环境值按 ANSI 路径写坏；故障发生在函数返回前。固定源码适配改用 `php_win32_cp_any_to_w()` 与 `SetEnvironmentVariableW()`，继续使用 Swoole 的 ZTS 环境锁。

当前内置 DLL 来自 [run 41367743137](https://github.com/zoujingli/typeapp/actions/runs/41367743137)、主仓源码 `41a67392bd792658361c2c985a47f815ef22ad5c`，SHA-256 为 `d1a400008f0077e8307f58758e3e59bcda4c8c43dae3d8c477384079edef0093`。同一模块通过中文环境的主进程、PHP/宽字符子进程、两轮工作线程、零字符串和删除恢复验证，以及 DNS、句柄复用、TLS、curl 回归；原始 Artifact 为 `13107259689`。后续应用矩阵的结果见上文平台状态入口，静态程序另行验收。

各模块的加载、协程 SQLite 和 PostgreSQL 连接拒绝检查通过，Windows 另通过主线程及两次重建工作线程的指定 DNS、TLS 写半关闭、读取超时恢复及连接重置检查，以及各 16 次的原生 UDP / DNS 后 UDP 对照；清单保存各 Artifact、依赖和适配摘要。Windows 还包含 config.w32、IOCP、DNS 和 PHP 头文件的受控适配。这些模块检查不替代完整通信及十二组合静态程序验收；上述模块与 RC14 的历史验收身份见[历史升级记录](https://github.com/zoujingli/typeapp/blob/main/docs/evidence/typephp-upgrade-0.9.4.md)，当前 0.9.5 / 2.9.4 的迁移范围见[当前升级记录](https://github.com/zoujingli/typeapp/blob/main/docs/evidence/typephp-upgrade-0.9.5.md)。

Swoole 和其所含第三方材料保留 [LICENSES](LICENSES) 中的原始许可证，第一方适配按仓库 Apache-2.0 提供。分发这些二进制时应随附该目录；其中也保存 macOS 静态子依赖以及 Windows 静态链接的 c-ares 1.34.8、zlib 1.3.2、Zstandard 1.5.7 的许可。zlib 许可取自[固定版本](https://github.com/madler/zlib/blob/v1.3.2/LICENSE)，Zstandard 按[该版本的 BSD 许可](https://github.com/facebook/zstd/blob/v1.5.7/LICENSE)分发。

## 维护与重建

正常构建不需要下列步骤。维护者更新 Swoole、PHP ABI 或源码适配时，必须重新生成对应模块、清单及验证证据，不能只修改摘要以掩盖版本不匹配。

```bash
export TYPE_SWOOLE_BUILD_FROM_SOURCE=1
export RUNNER_TEMP="$PWD/build/swoole-rebuild"
mkdir -p "$RUNNER_TEMP"
export SWOOLE_CONFIGURE_OPTS='--enable-swoole-thread --enable-sockets --enable-mysqlnd --enable-cares --enable-swoole-pgsql --enable-swoole-sqlite --with-openssl-dir=/usr --enable-swoole-curl'
bash tools/prepare-swoole-module.sh
```

上述开关对应 Linux。可移植四平台模块统一通过主仓的 `rebuild-swoole.yml` 手动工作流重建；Unix 实现为 `tools/rebuild-bundled-swoole.sh`，Windows 使用 `TYPE_SWOOLE_BUILD_FROM_SOURCE=1` 调用既有 SDK 准备脚本。macOS 固定 `MACOSX_DEPLOYMENT_TARGET=15.0`，以 `-Wl,-load_hidden,<归档文件>` 隐藏链接非系统库，保留系统 curl、zlib、C++ 与平台库，最后 strip 并重新 ad-hoc 签名。不得复用系统要求高于目标平台的归档。

验收包括准确机器类型和 SHA-256、真实 PHP 加载、原生线程 ABI、协程和 PDO hook，以及真实 embed 和应用 AOT 的对应回归。macOS 必须确认没有未解析的 PostgreSQL 私有符号，并实际验证 PDO 连接失败返回异常；仅加载扩展不能发现遗漏静态依赖导致的延迟绑定崩溃。本轮四平台模块重建均使用原生 runner，旧模块的模拟加载结果保留在历史证据中。

**这些文件是共享扩展构建输入。** PHP SDK、PHPX 和其他依赖仍须准备；本目录不代表整个构建离线。生产 Release 另由静态 SDK 生成一个主程序，非系统运行库在构建期链接，启动不释放 `.so` 或 `.dll`；各版本的实际范围见[平台与验收](https://iots.top/#/guide/platforms)。

模块首次迁移、链接修复与当时未验证范围见[迁移记录](https://github.com/zoujingli/typeapp/blob/main/docs/evidence/swoole-bundle.md)；后续四平台 CI 与发布范围见[平台与验收](https://iots.top/#/guide/platforms)。
