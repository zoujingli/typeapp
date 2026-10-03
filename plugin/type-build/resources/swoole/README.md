# 内置 Swoole 构建输入

此目录保存固定 Swoole 6.3 开发快照的预编译扩展，源码为 `4aff74a9ac086458d1c5251e71ac6e080f68b390`，运行时字符串为 `6.3.0RC1`。该提交包含 RC1 后续修复，不是正式 6.3.0。安装完整的 `zoujingli/type-build` 组件后，匹配的构建直接校验、复用本地文件，不再下载或编译 Swoole；文件不是 Git LFS 指针。固定 PHP 为 **8.5.10、ZTS、非 debug、64 位**，其他 ABI 不自动混用。

| 目录 | 平台与边界 |
| --- | --- |
| `linux-x64/php-8.5.10-zts/swoole.so` | Linux x86-64，Debian 12 / glibc 2.36 构建基线 |
| `linux-arm64/php-8.5.10-zts/swoole.so` | Linux ARM64，Debian 12 / glibc 2.36 构建基线 |
| `macos-arm64/php-8.5.10-zts/swoole.so` | macOS ARM64，部署目标 15；在 macOS 15 原生 ARM64 runner 构建并校验 |
| `windows-x64/php-8.5.10-zts/php_swoole.dll` | Windows x64，PHP 官方 VS17 ZTS ABI |

Linux 文件不适用于 Alpine/musl。macOS Intel、Windows ARM64、NTS 或其他 PHP 版本未提供预编译文件。模块存在和能够加载均不等于完整应用的平台验收。

macOS ARM64 还要求配套 PHP 启用 Zend signals；关闭该选项的 Homebrew `php-zts` 不兼容。主仓 CI 通过 `tools/install-locked-macos-php.sh` 准备匹配的锁定 SDK。共享 PDO 驱动由构建组件安排在 Swoole 之前加载，先完成原生驱动注册，再启用对应 hook。

## 选择与校验

运行库的选择仍由 `Type\Build\RuntimeProfile` 负责：

1. 真实 embed 已内置的 Swoole 不重复加载。
2. 显式 `runtime.modules` 声明优先，其次为有效的 `TYPE_SWOOLE_MODULE`。
3. 默认读取本组件的 [manifest.json](manifest.json)，按操作系统、架构和 PHP ABI 选择本目录文件，校验 SHA-256 及 Thread、HTTP、Socket 适配类的身份，Windows 另验 Windows 适配类；清单缺失或不匹配时失败。

实际 embed 仍须通过模块加载、版本、依赖和警告检查，清单和选中模块一同进入构建身份。独立应用直接使用已安装组件的 `resources/swoole`，路径不依赖应用根目录或当前工作目录。其他扩展继续按 SDK 或显式候选解析；本目录是构建输入，不声明为应用资源，不会把四个平台的文件全部复制到应用产物。

以下维护脚本位于 [TypeApp 开发主仓](https://github.com/zoujingli/typeapp)，从主仓执行；独立应用正常构建无需运行这些脚本。

```bash
# 使用 PHP_HOME 指向的锁定 SDK；可从任意工作目录运行该脚本。
bash tools/prepare-swoole-module.sh
# 仅查询并核验本地模块，不加载 Composer，不访问网络。
"$PHP_HOME/bin/php" -n tools/select-swoole-module.php
```

Windows 的 SDK 准备入口为 `.github/scripts/prepare-windows-native.ps1`，默认将校验后的本地 DLL 放入 SDK 的 `ext` 目录，跳过 Swoole 源码和 phpize 工具下载。

当前 Windows 共享模块的原生 curl 尚待重建验收：源码准备入口已增加 `--enable-swoole-curl`，固定官方 libcurl 8.22.0 与 libssh2 1.11.1 的归档并校验原始许可及 CRT。仅加载 PHP curl 扩展或读取 hook 标志不能证明协程等待生效；重建门禁验证双线程请求归属、等待期间事件循环运行及超时后的句柄复用。生产静态 SDK 已启用该开关，其验收身份单独记录。

## 来源与依赖

全部模块基于官方 [swoole-src](https://github.com/swoole/swoole-src/tree/4aff74a9ac086458d1c5251e71ac6e080f68b390) 固定提交。固定归档摘要、模块摘要、补丁身份、构建参数和来源记录在清单中。它们包含 TypeApp 的原生线程入口 ABI 2、HTTP、Socket 与 TLS 适配，不是未经修改的上游发行文件。上游已承担线程标准流回收，项目不再重复实现该生命周期。

Linux 两种架构使用官方 `php:8.5.10-zts-bookworm` 镜像构建，启用线程、sockets、mysqlnd、c-ares、PostgreSQL/SQLite hook、OpenSSL 和原生 cURL。运行仍需要相容的 libpq、SQLite、c-ares、OpenSSL、libcurl、C++ 与系统库，现有原生依赖收集器负责纳入发布清单。

macOS 模块将 libpq（含配套的 libpgcommon_shlib、libpgport_shlib）、SQLite、OpenSSL、c-ares、Brotli 的静态归档链接进扩展，并隐藏这些库的符号，避免与 PHP 已加载的同名库混用。扩展保留 PHP API 的动态绑定以及系统库依赖；没有开发电脑的依赖路径，不需要伴随 dylib。静态子依赖及其来源记录在清单中。

Unix 三个平台的模块来自 [重建 run 37075789749](https://github.com/zoujingli/typeapp/actions/runs/37075789749)，主仓源码为 `f6c092f436f2a341bb6d588d333d761c2ab64732`。Windows 模块来自 [run 37128837651](https://github.com/zoujingli/typeapp/actions/runs/37128837651)、源码 `eaf1a3da2cc191f39dd3885b23e3e9c1298e941f`，保留 IPv6 地址初始化与独占绑定适配、TLS 正常 EOF 修复，并修正外部库关闭及复用 Socket 后的 IOCP 关联缓存。模块静态链接固定 c-ares 1.34.8，使指定 DNS 配置在主线程和重建的工作线程生效。共享模块使用与开发 PHP 一致的 `/MD` CRT，生产静态 SDK 独立使用 `/MT`；不增加 c-ares DLL 部署文件。

各模块的加载、协程 SQLite 和 PostgreSQL 连接拒绝检查通过，Windows 另通过主线程及两次重建工作线程的指定 DNS、TLS 写半关闭、读取超时恢复及连接重置检查，以及各 16 次的原生 UDP / DNS 后 UDP 对照；清单保存各 Artifact、依赖和适配摘要。Windows 还包含 config.w32、IOCP、DNS 和 PHP 头文件的受控适配。这些模块检查不替代完整通信及十二组合静态程序验收，进展见[新版升级记录](https://github.com/zoujingli/typeapp/blob/main/docs/evidence/typephp-upgrade-0.9.4.md)。

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

验收包括准确机器类型和 SHA-256、真实 PHP 加载、原生线程 ABI、协程和 PDO hook，以及真实 embed 和应用 AOT 的对应回归。macOS 必须确认没有未解析的 PostgreSQL 私有符号，并实际验证 PDO 连接失败返回异常；仅加载扩展不能发现遗漏静态依赖导致的延迟绑定崩溃。本批四个平台均使用原生 runner，旧模块的模拟加载结果保留在历史证据中。

**这些文件是共享扩展构建输入。** PHP SDK、PHPX 和其他依赖仍须准备；本目录不代表整个构建离线。生产 Release 另由静态 SDK 生成一个主程序，非系统运行库在构建期链接，启动不释放 `.so` 或 `.dll`；各版本的实际范围见[平台与验收](https://iots.top/#/guide/platforms)。

模块首次迁移、链接修复与当时未验证范围见[迁移记录](https://github.com/zoujingli/typeapp/blob/main/docs/evidence/swoole-bundle.md)；后续四平台 CI 与发布范围见[平台与验收](https://iots.top/#/guide/platforms)。
