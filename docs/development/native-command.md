# 原生命令开发与验证

## 实际实现

- type-runtime 提供显式命令参数读取，检查未知、重复、缺失值和整数范围。
- type-build 从应用的已安装生产依赖读取协议 1 的源码声明，收集声明的生产包；缺少声明时明确失败。
- 示例命令输出中文问候，支持名称、重复次数与帮助；错误参数输出中文错误并返回退出码 64。
- 独立消费项目分别复制安装 runtime 和 build，使用自己的 Composer 命令代理和自定义依赖目录构建，不依赖主仓生产源码软链接。

构建器使用 Composer 生成的 TypePHP 命令代理，避免绕过其依赖加载。内部候选文件名使用合法标识符，按目标平台验证 ELF、Mach-O 或 PE 后才替换最终产物；失败不会把旧二进制报告为本次成功结果。基础命令使用 `docs/build-config/type-foundation.json`，完整应用使用 `docs/build-config/type-app.json`，两者不能互相代替验收。

## 锁定与环境

Linux/macOS 需要对应平台的 PHP embed SDK、C++17 编译器、CMake、GMP 与 MPFR。`PHP_HOME` 必须指向包含 php-config 和 libphp 的同一安装，`PHPX_HOME` 指向当前已安装并构建的 PHPX。Windows 使用匹配的 ZTS SDK 与 MSVC；准备入口见 `.github/scripts/prepare-windows-native.ps1`。不要混用不同 PHP 版本、ZTS/NTS 或机器架构的库，版本与引用以 `toolchain.lock.json` 为准。

GitHub Actions 分别提供 Linux x64、Linux ARM64、macOS ARM64 和 Windows x64 验收入口。工作流存在不代表该平台完整通过；PHP 行为、组件 AOT、完整应用、实际通信、无源码部署和性能分别记录结果。Linux 容器或虚拟机验证还须记录实际架构与是否使用模拟器，本机其他项目的工具链镜像不是本项目公开分发依赖。

Linux x64 主验收、组件批次、应用模板和运行库分发共用 `.github/scripts/prepare-linux-swoole.sh`，在切换到锁定 PHP 后同时核验 embed 与 CLI 的受控 Swoole。模块声明写入工作区的独立扫描目录，不写回可缓存的 SDK 前缀；公开消费前运行 Composer 平台依赖检查，确认执行安装的 PHP CLI 也已加载所需扩展。

macOS ARM64 的内置 Swoole 要求 PHP SDK 启用 Zend signals。CI 使用 `tools/install-locked-macos-php.sh` 从固定摘要的 PHP 源码准备 ZTS/CLI/embed，缓存按安装脚本和本机依赖身份复核；Swoole 仍直接取自构建组件，无需在每次应用构建时重编。Homebrew 的 `php-zts` 关闭该选项，不能仅凭 PHP 版本号相同直接替换。SDK 通过 Xcode Command Line Tools 提供的声明链接系统 iconv，避免在发布包中引入与系统库同名但符号不同的 GNU libiconv。启动产物时使用该次构建报告 `runtime-profile.ini` 指向的配置，避免加载开发控制器中另一份同名扩展。

构建组件在 [plugin/type-build/resources/swoole](../../plugin/type-build/resources/swoole/README.md) 保存这四个平台的 Swoole 模块、固定来源、SHA-256 和第三方许可证。匹配 PHP 8.5.10 ZTS 的构建默认直接读取本地文件，不下载或重新编译 Swoole。选择顺序为真实 embed 已内置、显式 `runtime.modules`、有效的 `TYPE_SWOOLE_MODULE`、构建组件内置清单。清单缺失、没有匹配 ABI、摘要不符或源码适配已变更时明确失败；独立应用通过 Composer 安装完整的 `type-build` 后使用相同规则，其他扩展继续按 SDK 或显式候选解析。

`tools/prepare-swoole-module.sh` 和 Windows SDK 准备脚本默认复用这些文件。维护者显式设置 `TYPE_SWOOLE_BUILD_FROM_SOURCE=1` 才下载固定 Swoole 源码并应用当前适配；Unix 重建还需提供 `RUNNER_TEMP` 和 `SWOOLE_CONFIGURE_OPTS`。PHP SDK、PHPX 及其他依赖的准备不因此变为离线。内置共享模块是构建输入，最终程序静态链接和启动不释放运行库仍按[静态验证记录](static-runtime-feasibility.md)推进。

## 安装和快速检查

```bash
composer install --no-interaction --no-scripts --no-plugins --prefer-dist
composer validate --strict
composer check
```

快速检查覆盖 PHP 语法及 Arguments 公共接口，不代替 AOT 验证。没有目标平台 SDK 的 PHP 环境可以执行这一组检查，但不能声明原生构建通过。

## Linux 原生验证

在已经安装对应 PHP SDK 和原生依赖的 Linux 环境运行：

```bash
export PHP_HOME="$(php-config --prefix)"
export PHPX_HOME="$PWD/vendor/swoole/phpx"
export LD_LIBRARY_PATH="$PHPX_HOME/lib:$PHP_HOME/lib"

bash tools/prepare-toolchain.sh
composer build:native
composer test:native
composer test:build-errors
composer test:consumer
```

准备脚本只针对锁定 SDK 应用官方 TypePHP 使用的 PHP 头文件兼容修正，并构建 PHPX。若 SDK 不可写，脚本需要当前隔离构建环境中已有的 sudo 能力；不要把这一步对准未经确认的其他项目 SDK。

构建报告记录输出哈希、Composer 和工具链锁定哈希、PHP/ZTS/架构、构建环境扩展、编译器与 PHPX 引用以及两个主要运行库哈希。构建环境加载的扩展不等于应用产物必须携带的扩展；应用是否可运行仍通过实际产物验证。

## 没有业务源码的执行验证

```bash
task_sandbox="$(bash tools/make-native-sandbox.sh build/native/type-app)"
php tests/native.php --chroot "$task_sandbox"
```

隔离目录复制 ELF、ldd 解析出的动态运行库，以及对应 PHP SDK 可能需要的系统时区数据；不复制 PHP CLI、业务源码或 Composer 自动加载文件。动态库同时进入加载器默认目录，因此不依赖 sudo 保留环境变量。验证器从外部启动 chroot 中的命令，检查默认参数、中文/空格参数、帮助、重复选项、缺失值、未知选项和整数越界，共 9 个行为用例。

隔离执行需要 Linux root 或可用于 chroot 的非交互 sudo。该步骤仅验证命令运行，不部署服务，也不启动或替换本地前端。

完整 Linux 目录包使用 `tests/native-package-clean.php` 验证只读空白镜像中的安装、迁移、HTTP 业务与正常停止；`--recover` 进一步验证备份和独立恢复。测试主动设置 `typeapp-validation.invalid` 搜索域，并用 `DB_HOST=database.` 连接隔离网络的数据库别名，以覆盖 CI 宿主机携带搜索域的情况。MySQL 错误口令必须返回认证错误码 `1045`，DNS 或连接失败不能代替认证拒绝。测试数据库使用已经存在的镜像，应用与恢复进程共用同一发布包。

固定 Swoole 的 Linux DNS 路径使用 c-ares；CI 的 c-ares 1.27 会将 `ndots:0` 归一为 `1`，使短名称优先追加搜索域。在封闭网络中，这可能先触发解析失败。末尾点明确指定绝对 DNS 名称，无需修改 Swoole 或给生产主机名统一补点；依赖搜索域的应用继续按自身网络配置。部署配置说明见[首次启动](../guide/deployment.md#首次启动)。

## 组件与通信验收

在匹配的 SDK 环境中，`php tests/build-platform-native.php` 验证真实产物、运行库身份和缓存；`php tests/helpers-build.php` 全量编译 SQLite、ORM、校验与运行组件，并对照 PHP 和原生业务结果。这些入口使用自身的测试目录，不等同于完整应用或无源码部署验收。

Windows 工作流默认执行完整检查。仅调整 SDK 准备或原生验收流程、且同一代码的契约套件已通过时，可手动选择 `scope=native` 定向复跑；报告须同时引用契约与原生运行的源码身份，不能把跳过项记为本次通过。`scope=template` 单独执行 SQLite 模板的开发、全量 AOT 与搬迁发布，用于缩短模板故障的复现路径；它使用独立并发组，不取消完整运行，也不能替代完整三库验收。模板 HTTP 提前退出时保留进程退出状态及脱敏输出，Actions 同时保存模板原生产物和构建身份。显式重建 Swoole 时，准备脚本固定并核验 PHP 官方 SDK 构建工具，提供 `phpize` 配置必需的 bison、re2c 等程序；默认复用 DLL 时跳过这组工具和 Swoole 源码下载。PHPX DLL 放入编译器要求的 `PHPX_HOME/build`，并统一加载路径；工作流分别记录构建身份、四组件和完整应用的结果，保存日志、清单与实际程序产物。

`scope=pgsql-app` 在独立并发组中只运行 PostgreSQL 应用开发入口，用于定位真实 HTTP 故障，不代表 AOT 或完整平台验收。应用测试保存最近请求与慢请求的耗时；失败时额外记录本轮 PostgreSQL 会话的等待状态和阻塞进程，省略 SQL、请求体和令牌。数据库清理失败时仍保留原请求故障报告，Actions 同时上传脱敏 HTTP 日志。

Windows 运行库核验会将运行配置明确声明的扩展文件纳入同一组 DLL 依赖解析。例如 Swoole 导入的 `php_sockets.dll` 使用已声明的 sockets 模块，不要求把扩展目录加入全局 PATH。DLL 名称按 Windows 的大小写无关规则匹配，同名不同内容仍拒绝；API-set 继续由受限系统加载器解析。每个编译工具独立核验自己的依赖，不能借用应用的模块映射。

锁定 TypePHP 的 Windows 链接清单还包含 mpdecimal 的 C 与 C++ 运行库。它们在编译前与 PHP、PHPX 一起进入身份、许可材料及发布清单；仅扫描 PHPX 的依赖不能覆盖应用直接使用的十进制运算。ORM 原生验收会对照最终 PE 导入表检查运行库清单，再执行独立发布包，避免开发环境的 PATH 掩盖漏包。

固定 Swoole 的 Windows 配置引用了未声明的 PostgreSQL 路径变量。准备脚本仅在原文摘要和替换位置匹配时，将库与头文件探测接入 PHP 官方 `--with-php-build` 依赖目录，并记录适配前后摘要；不改变 PDO 协议实现或关闭协程 hook。上游支持同一独立构建方式并通过三库验收后撤除此适配。Swoole 的 PHP 8.5 实现使用指定初始化，MSVC 构建时显式启用 C++20，结束后恢复原编译选项；该选项不改变 TypePHP 的 PHP 语言契约。

Windows IOCP 源码引用 PHP 文件辅助头前，还需要 Zend 的内联定义。准备脚本按固定原文摘要补齐头文件依赖，并单独保存适配身份；不修改 IOCP 的提交、等待或完成逻辑。上游补齐包含顺序并通过相同 Windows 编译与运行验收后撤除此适配。

PHP 官方 Windows 依赖包使用 `libsqlite3.lib`，固定 Swoole 配置的 SQLite 探测同时接受该库名。配置完成后必须在实际生成头文件中确认 MySQL、PostgreSQL、SQLite hook 均已启用，缺少任一项立即停止；不能将可加载的普通 PDO 扩展当作协程等待已实现。

同一 Windows 构建清单还需在启用 PostgreSQL、SQLite 时纳入各自的官方协程实现源文件，并按官方依赖包的实际名称链接 `libzstd_a.lib`。这些调整沿用配置原文摘要和唯一替换检查，不改变数据库协议、等待方式或压缩行为；上游构建清单完整且能通过相同链接和运行验收后撤除。

通信依赖 Swoole，启用其官方内置库。Unix HTTP 场景可用 `php tests/build-scenario.php --with-swoole docs/build-config/type-http.json` 构建，再运行 `php tests/http-native.php build/http/type-app`；此场景同时声明 sockets 与 Swoole，避免 CLI 已加载模块而 embed 缺少依赖。`php tests/websocket.php` 验证 PHP 模式的 HTTP 同端口、分片、WSS 和生命周期，不证明 WebSocket AOT 已通过。

完整应用还需满足[编译业务线程](compiled-business-threads.md)的 PHPX 与 Swoole ABI 校验。普通组件构建通过不能替代线程 SDK 验收，也不能绕过校验退回业务源码解释执行。当前边界与后续目标见[系统架构](../guide/architecture.md)和[实现规划](../guide/roadmap.md)。

线程产物在模块初始化阶段注册应用，构建器使用实际运行配置中的扩展清单声明依赖，保持 PDO 驱动先初始化、Swoole 再接管协程驱动。PHP CLI 的扩展加载成功不能代替最终产物启动检查；三库应用验收先执行原生命令，再为 PHP 和原生模式分别启动专用数据库服务，每轮从空库验证安装与业务行为。

运行扩展与 PHP SDK 的同名动态依赖必须具有明确、一致的实际解析结果；混用系统共享映像和另一份同名库，可能在目录包设置加载路径后改变绑定。当前内置 macOS Swoole 将 SQLite、libpq 等子依赖静态链接并隐藏符号，避免与 PHP 已加载的同名库混用，见[模块依赖说明](../../plugin/type-build/resources/swoole/README.md#来源与依赖)。自行构建时须统一共享依赖或采用已验证的符号隔离，再重建应用、核验实际加载身份、PDO 失败路径和无源码运行；不能删除库摘要或系统映像 UUID 校验来绕过冲突。

第一方代码与文档按 Apache-2.0 公开，第三方依赖保留自身许可证。验收完成后保留必要日志、源码与工具链身份、产物摘要，回收本轮安装副本、构建中间文件和测试资源；构建目录、依赖目录及原始验收记录不进入版本控制。
