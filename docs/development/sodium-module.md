# 隔离的 sodium 开发模块

当 Composer 依赖声明 `ext-sodium` 时，开发 PHP 必须实际加载匹配的扩展。`tools/prepare-sodium-module.sh` 在新目录编译模块及其静态 libsodium，不修改输入 PHP SDK、全局 INI 或应用 vendor。它提供 Unix 开发与共享 embed 构建输入，不是生产静态 SDK，也不生成 Windows DLL。

## 固定输入与构建

当前入口固定 PHP 8.5.10 ZTS、非调试 ABI 和 libsodium 1.0.22，下载前后核对脚本中的 SHA-256。需要同一 SDK 的 `php/phpize/php-config`，以及 C 编译器、make、autoconf、pkg-config、tar、curl 和平台库检查工具。PHP 版本、ZTS、debug、CPU 架构任一不匹配，都不能混用模块。

在 TypeApp 主仓执行，`PHP_HOME` 指向已准备的匹配 SDK。输出目录必须尚不存在，其父目录已存在：

```bash
mkdir -p build/modules
task_sodium="$PWD/build/modules/sodium-development"
bash tools/prepare-sodium-module.sh "${PHP_HOME:?需要匹配的 PHP SDK}" "$task_sodium"
```

可通过 `TYPE_SODIUM_PHP_ARCHIVE`、`TYPE_SODIUM_ARCHIVE` 提供已有源码归档，仍验证固定摘要；显式路径不存在时直接失败，不向该路径下载。上游 libtool 要求编译目录不含空白，因此主仓检出路径不能含空白；最终输出目录允许空格。编译临时目录位于主仓 `build/`，成功、失败或中断后清理，诊断日志保留在本次输出目录。失败后使用新输出目录重试，不覆盖已有候选。

脚本执行 libsodium 自测，再构建 `modules/sodium.so`；私有 libsodium 只生成 `.a`，PHP 扩展通过 `-L/-l` 静态链接它。载入前核对没有未解析的 sodium 符号，随后执行真实签名、验签和哈希探针，并拒绝仍依赖外部 libsodium 共享库的模块。输出包含模块、静态归档、头文件、许可证、原始日志和 `manifest.json`；清单记录源码、配方、SDK 与模块摘要。

## Composer 与 doctor

模块必须加载到**实际执行 Composer 和 doctor 的 PHP 进程**。在 `type-app.json` 声明模块不会自动改变 CLI，也不能使用 `--ignore-platform-reqs` 或伪造 `config.platform.ext-sodium` 绕过安装。

在需要 sodium 的独立应用根运行，`COMPOSER_BINARY` 指向 Composer PHP 入口文件；已有 Swoole 模块通过本次命令显式加载：

```bash
"$PHP_HOME/bin/php" -n \
  -d "extension=${TYPE_SWOOLE_MODULE:?需要匹配的 Swoole 模块}" \
  -d "extension=$task_sodium/modules/sodium.so" \
  "${COMPOSER_BINARY:?需要 Composer PHP 入口}" install --no-interaction --no-scripts --no-plugins

"$PHP_HOME/bin/php" -n \
  -d "extension=$TYPE_SWOOLE_MODULE" \
  -d "extension=$task_sodium/modules/sodium.so" \
  "$COMPOSER_BINARY" check-platform-reqs

"$PHP_HOME/bin/php" -n \
  -d "extension=$TYPE_SWOOLE_MODULE" \
  -d "extension=$task_sodium/modules/sodium.so" \
  vendor/bin/type doctor type-app.json
```

其他包要求的扩展同样需要满足。doctor 的 `development.extensions.sodium=true` 表示该 CLI 已加载扩展；`development.ready`、构建前提和最终程序的真实 embed 验证是不同结论。

## 共享 embed 与生产边界

将已经核验的模块复制到应用自己的资源目录，在对应平台的 `runtime.extensions` 加入 `sodium`，并在 `runtime.modules.sodium` 声明 `file` 和完整 `sha256`。`file` 相对应用根解析，摘要来自模块实际字节；合并到已有声明，保留 Swoole 及其他实际依赖。共享 embed 构建应重新探测 sodium，并运行应用实际使用的加密行为，不能只看 CLI 扩展列表。

该 `.so` 静态包含 libsodium，但仍是 PHP 共享扩展，不能直接称为完整静态应用。生产单程序必须在隔离的目标 SDK 中把 PHP sodium 扩展与 libsodium 一并静态编入，扩展、归档、许可证和摘要进入 SDK 清单，并完成全量 AOT、真实 embed 及无源码同产物验收。当前发布 SDK 尚无这项 sodium 静态验收；Windows DLL 和 Linux 制备也必须在各自目标环境执行，macOS 的结果不能代替。

例如 `lcobucci/jwt` 的 Composer 安装成功，只证明版本和平台依赖可解析；不证明整个 JWT 包满足 TypePHP 语法、生产源码声明或原生加密行为。应用需要该包时仍须单独完成这些验收。
