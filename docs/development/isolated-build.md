# 真实断网、只读输入的全量 AOT 构建验收

测试接口是已经公开的 `type --stage`、普通 `type <配置>` 与真实 ELF 命令，不直接调用构建类、不使用 `--dry`，也不以 PHP 解析成功或预制 ELF 代替编译。

## 可观察的隔离边界

1. 准备阶段只读挂载主仓，单独允许其 `build` 输出可写；调用公开 `--stage` 生成全量声明输入与 `build-inputs.json`。这一阶段没有执行 TypePHP 编译。
2. 编译阶段仅挂载输入快照为 `/input`（只读）、该快照的独立 `build` 工作副本（可写）、固定 PHPX SDK 为 `/opt/phpx`（只读）。原始仓库、宿主 home、Docker socket、数据库目录和凭据目录均不挂载。
3. 容器设置 `--network=none --read-only --cap-drop=ALL --security-opt=no-new-privileges`；只给临时 `/tmp` 和独立输出写入权限。进程环境经 `env -i` 后只加入固定工具链、区域设置与时区。
4. 真实探针检查网络只有回环接口、外连失败、有效 capabilities 为空、NoNewPrivs 生效、系统根/输入/SDK 写入失败，且原始仓库及认证环境不存在。
5. 全新输出不携带产物缓存。完整构建必须报告 `cache.hit=false`，ELF 摘要与构建报告一致，实际生产包集合必须覆盖 Composer 生产依赖的传递闭包。
6. 单独执行容器只挂最终 ELF 与固定 SDK，复用 `tests/native.php` 的九项中文输出、参数与退出码用例。这里只证明同工具链下的源输入脱离运行；不宣称该工具链镜像没有 PHP CLI，无 PHP CLI 的部署验收由独立的 clean-runtime 测试负责。

```text
mkdir(): Read-only file system
Failed to resolve build path: /input/vendor/composer/../swoole/typephp/build
TypephpCompatibility.php:27 → Translator->__construct()
```

这证明“只读重新解析过”不能代替实际编译：兼容适配层在 TypePHP 读取显式 `--build-dir` 前就尝试使用 vendor 内默认构建目录。修正后先核对锁定编译器路径，再让 Translator 的默认构建根位于当前应用工作目录；显式 `--build-dir` 仍由原有 CLI 生效，不需要把 vendor 或原始仓库改成可写。

测试传输阶段曾因零 capabilities 无法恢复宿主 tar 归属而报错，现已使用 `--no-same-owner` 并启用内层严格 shell；任何前置传输错误都必须使验收失败。上表来自修正后重新完成整条构建链路的记录，不采纳曾被末尾命令掩盖的错误报告。

## CI 准备要求

Linux CI 使用同一脚本的 `host` 模式，不需要开发者本地镜像，也不允许失败后回退到普通宿主编译：

```bash
sudo apt-get update
sudo apt-get install --yes bubblewrap util-linux
# 沿用工作流前置步骤已经验证的 PHP_HOME、PHPX_HOME、TYPE_NATIVE_PHP_INI。
TYPE_TEST_EXECUTION=host TYPE_PHPX_SDK="$PHPX_HOME" bash tools/test-isolated-build.sh
```

host 模式仅 `--stage` 在宿主运行；实际构建与九项命令均在独立 bubblewrap 挂载/PID/IPC/UTS/网络命名空间。系统输入限定为只读 `/usr`、`/lib`、`/lib64`、必要的 `/etc/php`、`/etc/alternatives`、动态加载器与地区设置；不绑定整个 `/etc` 或 `/home`。PHP SDK 若位于工作区缓存下，只绑定已经验证的精确 SDK 目录到原路径，父目录只是空挂载点；PHPX 单独绑定到 `/opt/phpx`。源码仍只有只读 `/input`，另有可写 `/input/build` 与 `/tmp`。

部分 SDK 静态预置 SNMP，缺少默认 MIB 或状态目录会污染启动输出。host 命名空间复用部署打包器生成的最小无凭据配置与空状态目录，仅把这些自生成路径只读挂入；不绑定宿主 `/etc/snmp`，不复制认证信息或隐式下载 MIB。是否可以启动仍由真实探针和严格输出门禁决定。

`sudo -n bwrap` 仅用于创建命名空间和挂载；内层 `setpriv` 在执行任何 PHP/编译器前切回原 UID/GID、清除附加组和全部 capabilities/能力边界，启用 NoNewPrivs。`/tmp` 明确设为 `1777` 与 256 MiB，避免默认 root-owned `0755` 导致非 root 编译器无法创建临时文件。真实探针必须再次检查这些属性和临时文件可写性，不依靠参数列表自证。最终运行若设置 `TYPE_NATIVE_PHP_INI`，仅绑定已经准备的 `php.ini`、`php.d` 和需要时的单个 `pcntl.so`，不会绑定整个运行缓存；构建阶段不挂载这些运行文件。

非 Linux 调用 `host` 会明确失败，不回退普通宿主编译。CI 该组失败不得放行分发；普通 `env -i`、只读重新解析或源码可见的宿主构建均不满足本验收要求。
