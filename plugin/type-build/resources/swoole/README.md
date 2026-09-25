# 内置 Swoole 构建输入

此目录保存真实的 Swoole 6.2.1 预编译扩展。安装完整的 `zoujingli/type-build` 组件后，匹配的构建直接校验、复用本地文件，不再下载或编译 Swoole；文件不是 Git LFS 指针。固定 PHP 为 **8.5.10、ZTS、非 debug、64 位**，其他 ABI 不自动混用。

| 目录 | 平台与边界 |
| --- | --- |
| `linux-x64/php-8.5.10-zts/swoole.so` | Linux x86-64，Debian 12 / glibc 2.36 构建基线 |
| `linux-arm64/php-8.5.10-zts/swoole.so` | Linux ARM64，Debian 12 / glibc 2.36 构建基线 |
| `macos-arm64/php-8.5.10-zts/swoole.so` | macOS ARM64，部署目标 15；已通过 macOS 15 原生 ARM64 默认矩阵 |
| `windows-x64/php-8.5.10-zts/php_swoole.dll` | Windows x64，PHP 官方 VS17 ZTS ABI |

Linux 文件不适用于 Alpine/musl。macOS Intel、Windows ARM64、NTS 或其他 PHP 版本未提供预编译文件。模块存在和能够加载均不等于完整应用的平台验收。

macOS ARM64 还要求配套 PHP 启用 Zend signals；关闭该选项的 Homebrew `php-zts` 不兼容。主仓 CI 通过 `tools/install-locked-macos-php.sh` 准备匹配的锁定 SDK。共享 PDO 驱动由构建组件安排在 Swoole 之前加载，先完成原生驱动注册，再启用对应 hook。

## 选择与校验

运行库的选择仍由 `Type\Build\RuntimeProfile` 负责：

1. 真实 embed 已内置的 Swoole 不重复加载。
2. 显式 `runtime.modules` 声明优先，其次为有效的 `TYPE_SWOOLE_MODULE`。
3. 默认读取本组件的 [manifest.json](manifest.json)，按操作系统、架构和 PHP ABI 选择本目录文件，校验 SHA-256 及三个 `Swoole*Source` 适配类的身份；清单缺失或不匹配时失败。

实际 embed 仍须通过模块加载、版本、依赖和警告检查，清单和选中模块一同进入构建身份。独立应用直接使用已安装组件的 `resources/swoole`，路径不依赖应用根目录或当前工作目录。其他扩展继续按 SDK 或显式候选解析；本目录是构建输入，不声明为应用资源，不会把四个平台的文件全部复制到应用产物。

以下维护脚本位于 [TypeApp 开发主仓](https://github.com/zoujingli/typeapp)，从主仓执行；独立应用正常构建无需运行这些脚本。

```bash
# 使用 PHP_HOME 指向的锁定 SDK；可从任意工作目录运行该脚本。
bash tools/prepare-swoole-module.sh
# 仅查询并核验本地模块，不加载 Composer，不访问网络。
"$PHP_HOME/bin/php" -n tools/select-swoole-module.php
```

Windows 的 SDK 准备入口为 `.github/scripts/prepare-windows-native.ps1`，默认将校验后的本地 DLL 放入 SDK 的 `ext` 目录，跳过 Swoole 源码和 phpize 工具下载。

## 来源与依赖

全部模块基于官方 [swoole-src](https://github.com/swoole/swoole-src) 提交 `0f3bee2f0ed8704ce33a336e7feabb0115411dd7`。固定归档摘要、模块摘要、补丁身份、构建参数和来源记录在清单中。它们包含 TypeApp 的原生线程入口 ABI 2、HTTP、Socket 与 TLS 适配，不是未经修改的上游发行文件。

Linux 两种架构使用官方 `php:8.5.10-zts-bookworm` 镜像构建，启用线程、sockets、mysqlnd、c-ares、PostgreSQL/SQLite hook、OpenSSL 和原生 cURL。运行仍需要相容的 libpq、SQLite、c-ares、OpenSSL、libcurl、C++ 与系统库，现有原生依赖收集器负责纳入发布清单。

macOS 模块将 libpq（含配套的 libpgcommon_shlib、libpgport_shlib）、SQLite、OpenSSL、c-ares、Brotli 的静态归档链接进扩展，并隐藏这些库的符号，避免与 PHP 已加载的同名库混用。扩展保留 PHP API 的动态绑定以及系统库依赖；没有开发电脑的依赖路径，不需要伴随 dylib。静态子依赖及其来源记录在清单中。

Windows DLL 来自 [原生验收 run 35967392758](https://github.com/zoujingli/typeapp/actions/runs/35967392758) 的 `windows-native-evidence`，源码为 `82285b3e96e6d171dceebfbb2d8fd8b95e688752`。三个数据库发布目录中的 DLL 摘要一致；该来源工作流整体失败的历史事实保持不变。后续使用同一内置模块的 SDK、完整应用及模板回归已通过，见[四平台验收记录](https://github.com/zoujingli/typeapp/blob/main/docs/evidence/native-release-20260925.md)。模块的额外 Windows 构建适配和依赖版本仍取自清单。

Swoole 和其所含第三方材料保留 [LICENSES](LICENSES) 中的原始许可证，第一方适配按仓库 Apache-2.0 提供。分发这些二进制时应随附该目录；其中也保存 macOS 静态子依赖以及 Windows 静态链接的 zlib 1.3.2、Zstandard 1.5.7 的许可。zlib 许可取自[固定版本](https://github.com/madler/zlib/blob/v1.3.2/LICENSE)，Zstandard 按[该版本的 BSD 许可](https://github.com/facebook/zstd/blob/v1.5.7/LICENSE)分发。

## 维护与重建

正常构建不需要下列步骤。维护者更新 Swoole、PHP ABI 或源码适配时，必须重新生成对应模块、清单及验证证据，不能只修改摘要以掩盖版本不匹配。

```bash
export TYPE_SWOOLE_BUILD_FROM_SOURCE=1
export RUNNER_TEMP="$PWD/build/swoole-rebuild"
mkdir -p "$RUNNER_TEMP"
export SWOOLE_CONFIGURE_OPTS='--enable-swoole-thread --enable-sockets --enable-mysqlnd --enable-cares --enable-swoole-pgsql --enable-swoole-sqlite --with-openssl-dir=/usr --enable-swoole-curl'
bash tools/prepare-swoole-module.sh
```

上述开关对应 Linux。Windows 使用相同的 `TYPE_SWOOLE_BUILD_FROM_SOURCE=1` 调用既有 SDK 准备脚本，保留 config.w32 与 IOCP 的受控适配。macOS 重建需固定 `MACOSX_DEPLOYMENT_TARGET=15.0`，使用同一部署目标的第三方静态归档；配置后将 `SWOOLE_SHARED_LIBADD` 中各非系统库改为 `-Wl,-load_hidden,<归档文件>`，保留系统 `-lz -lpthread`，最后 strip 并重新 ad-hoc 签名。不得复用系统要求高于目标平台的归档。

验收包括准确机器类型和 SHA-256、真实 PHP 加载、原生线程 ABI、协程和 PDO hook，以及真实 embed 和应用 AOT 的对应回归。macOS 必须确认没有未解析的 PostgreSQL 私有符号，并实际验证 PDO 连接失败返回异常；仅加载扩展不能发现遗漏静态依赖导致的延迟绑定崩溃。Linux x64 模块首次构建/加载使用 ARM64 主机的 x64 模拟环境；后续已在原生 Linux x64 CI 验证应用，历史模拟结果不作为原生性能测量。

**这些文件是共享扩展构建输入。** PHP SDK、PHPX 和其他依赖仍须准备；本目录不代表整个构建离线，也不代表应用已经静态链接成单文件。“单程序加配置、启动不释放运行库”的目标见[静态链接验证](https://github.com/zoujingli/typeapp/blob/main/docs/development/static-runtime-feasibility.md)。

模块首次迁移、链接修复与当时未验证范围见[迁移记录](https://github.com/zoujingli/typeapp/blob/main/docs/evidence/swoole-bundle.md)；后续四平台 CI 与发布范围见[平台与验收](https://iots.top/#/guide/platforms)。
