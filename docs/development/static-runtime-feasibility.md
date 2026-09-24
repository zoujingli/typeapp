# 不释放运行库的单程序交付可行性

核对日期：2026-09-24。本文记录源码研究与实验边界，不表示默认构建已经完成单程序交付。部署目标是一个程序文件加外置配置；启动时不向磁盘释放 PHP、PHPX、Swoole 或其他非系统原生库，允许使用目标操作系统自带的库。应用数据、日志与外置配置仍由应用管理。原有边界见[单程序交付约定](../guide/deployment.md)与[架构决策](../adr/0021-required-swoole-and-single-program.md)。

## 结论

Linux 与 macOS 有可复用的上游静态构建机制，适合继续做原生验证。Windows 的现成 PHP SDK 和锁定 TypePHP Windows 链接路径仍依赖 DLL；需要先解决 PHP 核心静态构建，不能把现成 `php8embed.lib` 当成完整静态 PHP。三个平台都不能仅把 `.so`／`.dll` 放入程序资源后，就达到“不释放、无外部非系统库”的目标。[S1][S2][S5][S6]

本次同时核对升级前的 TypePHP 0.9.0／PHPX 2.9.0 与已安装的 TypePHP 0.9.3／PHPX 2.9.2。0.9.3 新增的 `php-builder` 加 `sapi: embed` 比仅移植 PR #16 更接近本目标：它可生成静态 PHP embed 和 PHPX，并继续链接应用 AOT 目标；但它不会自动保证所有第三方库都静态，也未支持 Windows 的私有 SAPI 构建。[S1][S2][S3][S4]

| 平台 | 一手源码已经提供的能力 | 本任务仍需证明的内容 |
| --- | --- | --- |
| Linux x64／ARM64 | TypePHP `--full-static` 使用 musl SDK 的 `libphp.a`、`libphpx.a` 与启动目标；0.9.3 另提供私有 PHP embed 构建。[S1][S2][S12] | SDK 的 PHP 版本、ZTS、Swoole 配置与本仓补丁身份一致；完整依赖闭包、线程、三库和无源码运行通过。 |
| macOS ARM64 | PHP Unix 构建支持 `--enable-embed=static`；PHPX 可构建 `.a`；0.9.3 私有 SAPI 构建允许 Darwin。[S2][S4][S5] | 非系统依赖都使用静态目标，最终 Mach-O 只依赖允许的系统库；本仓线程接缝与完整应用通过。 |
| Windows x64 | Swoole 有官方 Windows 源码构建和线程开关；PHP 提供 embed 接口库。[S6][S8] | PHP 核心、PHPX、扩展与第三方依赖的完整静态 SDK；导入／导出宏、TLS 与 CRT 统一；真实 Windows 原生验收。当前未验证。 |

这里的“静态”限定于非系统运行库。Apple 官方区分“把静态库链接进程序”和“不使用任何系统动态库”：前者受支持，后者不属于 macOS 支持的部署方式。因此 macOS 允许保留系统 `libSystem`、系统 C++ 库和实际需要的系统框架，不要求将操作系统一起静态链接。[S7]

## 版本与研究来源

| 对象 | 本次核对的固定身份 |
| --- | --- |
| PHP | `php-8.5.10`，ZTS；生产基线仍以根 `toolchain.lock.json` 为准。 |
| 升级前 TypePHP | `0.9.0`，`f127dadf5dc6e554ff5182fd35a6c499fea47242`。 |
| 升级前 PHPX | `2.9.0`，`6f2089379cbc7ae22dacf0faa65dd05e40d72c20`。 |
| 已安装 TypePHP | `0.9.3`，`8b33cad5c4f9cd2be2980425f522496e9ba0bfce`；上游 Composer 要求 `swoole/phpx: ~2.9.2`。[S13] |
| 已安装 PHPX | `2.9.2`，`0dfa613d2057dcd4aa319ec9b6816f68df2403e4`。[S13] |
| 本仓 Swoole 源码 | `0f3bee2f0ed8704ce33a336e7feabb0115411dd7`；`tools/prepare-swoole-module.sh` 还固定源码归档摘要，并应用本仓适配。[S8][S9] |
| PR #16 | `8ac169c8404a2c3cc462e75cfacbe9986aed7a4e`；只作为已有实现参考，未合并。[S10] |

升级稳定版不能隐含升级 PHP SDK，也不能让旧 PHPX 二进制冒充新源码。依赖声明、锁文件、头文件、补丁摘要、原生库和实际编译身份须一起核对。本研究没有使用其他 PHP 版本的预编译发行包证明 PHP 8.5.10 可用。

## TypePHP 0.9.3 可以复用什么

`SapiPhpBuilder::prepare()` 只接受 Linux 或 Darwin，构建私有 PHP 并调用 PHPX `sapi-static/CMakeLists.txt` 生成 `libphpx.a`。`PhpBuildConfiguration::derivePhpBuilder()` 对 embed 使用 `--enable-embed=static`，按配置启用 ZTS，并移除扩展配置中的 `=shared`。这可以避免依靠宿主扩展文件，但第三方 OpenSSL、curl、数据库库与 GMP／MPFR 的链接方式仍需单独控制和审计。[S2][S3][S4]

此能力可用于全量 AOT：`CompilerBase::isSapiBuild()` 只有目标包含 `cli` 或 `fpm` 时为真；单独 `sapi: embed` 继续生成原生 `main()`，`Translator::build()` 将编译所得目标交给 `linkNativeTarget()`。`SapiApplicationLinker` 则用于 CLI／FPM 的入口适配，不是本项目静态 embed 的必要入口。[S3]

上游还提供 `embedded-files` 和 OPcache 字节嵌入，`SourcePipelineTrait` 明确将部分文件标记为“未翻译为原生代码”。这与本仓全量 AOT 门槛不同；采用静态运行库构建时，仍须保留当前生产源码清单与覆盖审计，不能通过嵌入 opcode 或业务 PHP 文本代替生产实现的编译。[S11][S14]

新入口还存在以下接入缺口：

- `php-builder` 公开配置只有 `zts` 与 `extensions`。Swoole 的线程、PDO 钩子和受控原生入口需要保留本仓实际开关及补丁；声明 `extensions: [swoole]` 本身不足以表达这些能力。[S2][S8][S9]
- `PhpBuilderSource` 为未随 PHP 提供的扩展调用 `PeclExtensionSource`；后者读取 PECL `stable.txt`，按当时稳定版下载。该默认行为不能替代本仓固定 Swoole 源码提交、归档摘要和 `startNative` 适配的身份。[S2][S9]
- 普通 embed 链接仍按名称选择 `gmp`、`gmpxx`、`mpfr`，并加入 PHP Makefile 的 `EXTRA_LIBS`。扩展已内置、存在 `libphp.a`，都不等于这些库已经静态。[S1][S3]
- 本仓 `RuntimeProfile`、`BuildPlatform`、原生库指纹和发布校验目前以共享 embed／共享库闭包为基础；静态 SDK 接入时须同时更新探针与身份校验，不能只改变最后一次链接命令。[S9]

## Swoole、线程和模块初始化

固定 Swoole 源码的 `config.m4` 使用 PHP 的扩展构建机制，可在 PHP 源码树中作为内置扩展；Windows `config.w32` 也把共享状态传给官方 `EXTENSION()`。`ext-src/php_swoole_private.h` 明确要求 `SW_THREAD` 配合 ZTS，并拒绝 ZTS 配合 `SW_USE_THREAD_CONTEXT`。`--enable-swoole-thread` 与 `--enable-thread-context` 不是可以互换的两个选项。[S8]

本仓应用还要求受控 `Swoole\Thread::startNative`、ABI 2 和 `swoole.enable_fiber_mock`，并依赖已经适配的 PHPX 线程 SDK。将 Swoole 编为静态时仍须保证 curl、sockets、PDO 与驱动的模块初始化顺序、请求生命周期和线程局部状态；“能链接”不能替代线程与真实 I/O 验证。[S9]

PR #16 可以提供 Swoole 目标生成、按能力选择开关和注册 `swoole_module_entry` 的参考，但不满足完整目标：其 `SwooleStaticModule::sharedModuleLinkTokens()` 继续链接共享模块；注册器通过官方 `php_load_extension()` 加载 sockets／PDO／curl；PHPX 与 libphp 仍按原有共享运行库携带。Windows 缺少预生成 `.obj` 清单时直接拒绝。PR 正文也明确单文件交付尚未完成。[S10]

## Windows 的主要阻点

PHP 8.5.10 的 `sapi/embed/config.w32` 确实生成 `php8embed.lib`。但 `win32/build/confutils.js` 的 `SAPI()` 将 `PHPLIB` 加入该库，`PHPLIB` 在 ZTS 构建中是 `php8ts.lib`；核心 Makefile 先构建 `php8ts.dll`，其 `.lib` 是对应导入库。`main/php.h` 的 `PHPAPI` 在消费者侧为 `__declspec(dllimport)`。这条官方路径提供了可链接的 embed 接口，并没有交付脱离 PHP DLL 的静态核心。[S6]

PHPX 2.9.2 的普通 Windows 构建仍查找 PHP 导入库，并要求 mpdecimal DLL；TypePHP 0.9.3 的 Windows `getLibraries()` 仍列出 `php8ts.lib`、`php8embed.lib` 与 `libmpdec-4.0.1.dll.lib`。新 `SapiPhpBuilder` 明确拒绝 Windows，因此升级本身不会消除这些依赖。[S1][S2][S4]

下一步需先做 Windows 最小静态 PHP embed 实验，核对 PHP／Zend／TSRM／扩展／PHPX 的导入导出定义与 TLS，再统一全部目标的 CRT。Microsoft 文档要求一次链接中的目标使用兼容的运行库选项；`/MT` 使用静态 CRT，`/MD` 使用 DLL 导入库。不能只把应用入口改为 `/MT`，同时保留原来的 DLL SDK。[S6][S15]

这说明当前缺少已验证的构建路径，不等于 Windows 在原理上无法实现。本文没有执行 Windows 编译，也没有把“链接 `.lib`”或 Windows 共享库 CI 成功记为静态成功。

## 构建输入与验收方向

项目内可以保存目标平台的原生构建输入，但静态链接需要真正的 `.a`／`.lib`／`.o`／`.obj`；`.lib` 须区分静态归档与 DLL 导入库。`.so`／`.dll` 可以继续用于构建期反射和 ABI 探针，不作为“不释放”产物的运行依赖。目录布局可以按操作系统、架构和 PHP ABI 隔离；来源、许可证、开关、版本和 SHA-256 应进入构建身份。[S1][S6][S9]

建议按一个可观察闭环推进：

1. 先完成 TypePHP／PHPX 升级并跑原有全量 AOT，确保静态实验基于真实、兼容的工具链。
2. 在 Linux／macOS 为同一 PHP 8.5.10 ZTS 构建静态 PHP、PHPX、Swoole 与所需扩展；复用上游私有 embed 构建职责，固定扩展源码和本仓适配。
3. 将所有非系统传递依赖纳入静态闭包，检查 ELF 的动态依赖、Mach-O 的加载命令或 PE 导入表；任何仍指向 PHP、PHPX、Swoole、Homebrew 库或其他待分发非系统库的依赖都算未完成。
4. 完整应用全量 AOT 后，移走 SDK、构建资源及源 PHP，在隔离目录只放程序和外置配置运行；同时观测文件写入与实际加载库，证明启动没有释放运行库或依赖隐含缓存。
5. 验证 Swoole 线程／协程、HTTP、TLS、DNS，以及 MySQL、PostgreSQL、SQLite 的实际行为；检查所有原有需要随包交付的资源，单独解决程序仍依赖侧边资源目录的问题。
6. Windows 并列记录 PHP 静态 embed 探索结果；仅在真实 Windows 产物完成相同检查后声明该平台支持。

静态依赖审计与运行观测必须同时成立；只运行一次 `--help` 或只看链接行，不能证明完整应用不会在后续路径加载扩展。

## 本地实验

2026-09-24 在 macOS ARM64 完成新版 PHPX 线程库构建、线程消费者全量 AOT 与禁读源码运行。完整应用也完成 255 个生产源码输入、21 个生产包的 AOT；这些成功产物仍使用共享运行库，不计为静态交付。

静态实验复用同一 PHP 8.5.10 ZTS 的核心与 embed 目标、新版 PHPX 目标、受控 Swoole 6.2.1 目标和真实线程消费者 AOT 目标。实验只在任务目录生成替代的内置模块登记表，将 PDO PostgreSQL、Redis、Swoole 纳入启动登记，并为三个动态扩展入口改名；没有修改原 SDK。链接显式使用 GMP、MPFR、mpdecimal、wren-gc、libpq、OpenSSL、SQLite、c-ares 和 Brotli 静态归档，系统 curl、XML、zlib 与系统框架保留为操作系统依赖。

**链接失败：28 个重复符号。** 冲突来自 PHP 原始 PDO PostgreSQL／SQLite 实现与 Swoole 随附的协程驱动副本，例如 `_pdo_pgsql_error`、`pdo_pgsql_scanner`、`zim_PDO_PGSql_Ext_pgsqlLOBCreate`、`_pdo_sqlite_error` 和 `pdo_sqlite_scanner`。共享库原有的符号边界消失后，不能直接将两组目标合并；仅解决扩展的 `get_module` 名称还不够。没有生成可运行静态程序，因此未执行静态产物的加载库审计、无库启动和“不释放”运行验证。

本地证据保留在 `build/static-runtime-probe.64N1dh`：`link.py`、`threads/commands.jsonl` 与 `threads/inputs.rsp` 记录实际构建命令，`failure-evidence.json` 记录 921 个链接输入的 SHA-256 和全部冲突符号。`link-threads.log` 的 SHA-256 为 `60268ec09217b37af0783858761a07e37a6542727fb9260a2ba6559043779656`。这些机器实验记录不作为可分发静态 SDK。

本轮结论是：已有可复用静态构建机制，但本仓完整运行时还未证明可直接静态交付。下一项具体工作是核对上游对静态 PDO hook 的处理，为两套驱动保留明确符号归属和原有注册／切换语义，再验证三库、线程与完整依赖闭包。不能通过删除 PDO 驱动、关闭协程钩子或忽略重复符号完成验收。Windows 仍需独立验证 PHP 核心静态构建；本次没有执行 Windows／Linux 静态编译。

## 一手来源

- [S1] TypePHP 0.9.3：[NativeBuildConfigurationTrait](https://github.com/swoole/typephp/blob/8b33cad5c4f9cd2be2980425f522496e9ba0bfce/src/Build/NativeBuildConfigurationTrait.php)、[NativeCommandOptionsTrait](https://github.com/swoole/typephp/blob/8b33cad5c4f9cd2be2980425f522496e9ba0bfce/src/Build/NativeCommandOptionsTrait.php)。升级前[同名构建实现](https://github.com/swoole/typephp/blob/f127dadf5dc6e554ff5182fd35a6c499fea47242/src/Build/NativeBuildConfigurationTrait.php)已有 Linux musl `--full-static`。
- [S2] TypePHP 0.9.3：[SapiPhpBuilder](https://github.com/swoole/typephp/blob/8b33cad5c4f9cd2be2980425f522496e9ba0bfce/src/Build/SapiPhpBuilder.php)、[PhpBuilderConfiguration](https://github.com/swoole/typephp/blob/8b33cad5c4f9cd2be2980425f522496e9ba0bfce/src/Build/PhpBuilderConfiguration.php)、[PhpBuilderSource](https://github.com/swoole/typephp/blob/8b33cad5c4f9cd2be2980425f522496e9ba0bfce/src/Build/PhpBuilderSource.php)、[PeclExtensionSource](https://github.com/swoole/typephp/blob/8b33cad5c4f9cd2be2980425f522496e9ba0bfce/src/Build/PeclExtensionSource.php)。
- [S3] TypePHP 0.9.3：[PhpBuildConfiguration](https://github.com/swoole/typephp/blob/8b33cad5c4f9cd2be2980425f522496e9ba0bfce/src/Installer/PhpBuildConfiguration.php)、[CompilerBase](https://github.com/swoole/typephp/blob/8b33cad5c4f9cd2be2980425f522496e9ba0bfce/src/CompilerBase.php)、[Translator](https://github.com/swoole/typephp/blob/8b33cad5c4f9cd2be2980425f522496e9ba0bfce/src/Translator.php)、[SapiApplicationLinker](https://github.com/swoole/typephp/blob/8b33cad5c4f9cd2be2980425f522496e9ba0bfce/src/Build/SapiApplicationLinker.php)。
- [S4] PHPX 2.9.2：[sapi-static/CMakeLists.txt](https://github.com/swoole/phpx/blob/0dfa613d2057dcd4aa319ec9b6816f68df2403e4/sapi-static/CMakeLists.txt)、[full-static/CMakeLists.txt](https://github.com/swoole/phpx/blob/0dfa613d2057dcd4aa319ec9b6816f68df2403e4/full-static/CMakeLists.txt)、[普通 CMake 构建](https://github.com/swoole/phpx/blob/0dfa613d2057dcd4aa319ec9b6816f68df2403e4/CMakeLists.txt)。
- [S5] PHP 8.5.10：[Unix embed 构建配置](https://github.com/php/php-src/blob/php-8.5.10/sapi/embed/config.m4)。
- [S6] PHP 8.5.10：[Windows embed 配置](https://github.com/php/php-src/blob/php-8.5.10/sapi/embed/config.w32)、[Windows SAPI／DLL 构建函数](https://github.com/php/php-src/blob/php-8.5.10/win32/build/confutils.js)、[核心 Makefile](https://github.com/php/php-src/blob/php-8.5.10/win32/build/Makefile)、[PHPAPI 定义](https://github.com/php/php-src/blob/php-8.5.10/main/php.h)。
- [S7] Apple：[Statically linked binaries on Mac OS X，QA1118](https://developer.apple.com/library/archive/qa/qa1118/_index.html)。该文是归档说明，本文仅引用其静态库与系统库边界，不把它作为当前应用测试结果。
- [S8] 固定 Swoole：[config.m4](https://github.com/swoole/swoole-src/blob/0f3bee2f0ed8704ce33a336e7feabb0115411dd7/config.m4)、[config.w32](https://github.com/swoole/swoole-src/blob/0f3bee2f0ed8704ce33a336e7feabb0115411dd7/config.w32)、[线程与 ZTS 编译限制](https://github.com/swoole/swoole-src/blob/0f3bee2f0ed8704ce33a336e7feabb0115411dd7/ext-src/php_swoole_private.h)。
- [S9] 本仓实现：[Swoole 准备脚本](../../tools/prepare-swoole-module.sh)、[NativeBuilder](../../plugin/type-build/src/NativeBuilder.php)、[RuntimeProfile](../../plugin/type-build/src/RuntimeProfile.php)、[BuildPlatform](../../plugin/type-build/src/BuildPlatform.php)、[真实 embed 说明](runtime-profiles.md)。
- [S10] [PR #16](https://github.com/zoujingli/typeapp/pull/16) 及其固定版本 [SwooleStaticModule](https://github.com/zoujingli/typeapp/blob/8ac169c8404a2c3cc462e75cfacbe9986aed7a4e/plugin/type-build/src/SwooleStaticModule.php)。
- [S11] TypePHP 0.9.3：[SourcePipelineTrait](https://github.com/swoole/typephp/blob/8b33cad5c4f9cd2be2980425f522496e9ba0bfce/src/Build/SourcePipelineTrait.php)。
- [S12] Swoole CLI 固定研究快照 `e357d75ac9a8751703fe23089b2c18c6989259e0`：[PHP Runtime Layer](https://github.com/swoole/swoole-cli/blob/e357d75ac9a8751703fe23089b2c18c6989259e0/docs/sdk.md)、[打包脚本](https://github.com/swoole/swoole-cli/blob/e357d75ac9a8751703fe23089b2c18c6989259e0/sapi/scripts/package-php-runtime-layer.sh)、[Swoole 构建声明](https://github.com/swoole/swoole-cli/blob/e357d75ac9a8751703fe23089b2c18c6989259e0/sapi/src/builder/extension/swoole.php)。此快照只证明上游机制，尚未成为 TypeApp 锁定 SDK。
- [S13] [TypePHP v0.9.3 发布](https://github.com/swoole/typephp/releases/tag/v0.9.3)、[该版本 composer.json](https://github.com/swoole/typephp/blob/8b33cad5c4f9cd2be2980425f522496e9ba0bfce/composer.json)、[PHPX v2.9.2 发布](https://github.com/swoole/phpx/releases/tag/v2.9.2)。
- [S14] 本仓[全量编译门槛](../standards/project.md#100-全量编译门槛)。
- [S15] Microsoft：[/MD、/MT、/LD 与运行库选择](https://learn.microsoft.com/en-us/cpp/build/reference/md-mt-ld-use-run-time-library?view=msvc-170)。
