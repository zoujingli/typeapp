# 普通 embed 的运行依赖与测试配置

[Windows 应用复验](https://github.com/zoujingli/typeapp/actions/runs/38844063772)在 HTTP 消费者编译后报 `Cannot redefine class constant PDO::PGSQL_ATTR_DISABLE_PREPARES`。程序只选择了 HTTP 所需扩展，测试却通过 `TYPE_NATIVE_PHP_INI` 注入控制器的全量三库配置。原程序及构建报告身份见[路径验收记录](windows-http-assembly-path-20261016.md)。

## 同一 Windows 程序的对照

[原程序对照](https://github.com/zoujingli/typeapp/actions/runs/38845664262)固定诊断源码 `b73c62c5263ccfca04200abe783b96df7e694809`，下载原 EXE 并核对 Swoole、curl、sockets 三个 DLL 与原构建报告中的摘要。两种配置均由现有 `RuntimeProfile` 生成，通过普通 embed 探针及 PHP 控制器启动检查，INI 扫描目录均为空。

| 原 EXE 的配置 | 两轮实际结果 |
| --- | --- |
| 全量三库运行配置 | 均约 0.04 秒内报告相同 PDO 常量重复定义，非超时 |
| 该程序实际依赖配置 | 均约 0.06 秒内退出 0、输出 `application`，标准错误为空 |

自身配置下的 `scopes` 命令也通过。对照过程中 EXE 字节不变；此项证明启动配置的影响，不代替完整 HTTP 或数据库回归。Artifact `12023650182` 共 14649 字节，ZIP SHA-256 为 `febc789a2421830538621fe4c4fc3de061057a5dfb618310e7c43601f0918a5e`，已回读摘要、CRC 和原始结果。

## 构建入口的相关缺口

锁定 PHP 8.5.10 的 `php_module_startup()` 先登记应用附加模块，再加载 INI 扩展；Zend 随后根据模块依赖重排启动顺序。普通 embed 已使用正常请求生命周期，但构建器仅为静态或显式线程构建写入完整扩展依赖。只按类型引用推断时，显式声明但未引用驱动类的 PDO 扩展可能晚于 Swoole 注册。

本机锁定 SDK 的最小独立消费者仅声明 Swoole 和 PostgreSQL 扩展，入口调用 `Swoole\Coroutine::getCid()`。原构建器即使使用自身生成的运行配置，仍在启动时复现相同错误，退出 254，未超时。原程序 SHA-256 为 `0ae8136b7438d58d19a69a2e56f30e641b3cec836417c0faa911515b129416c8`。

修复让普通、线程与静态 embed 共同使用真实探针确认的扩展依赖顺序。HTTP 与 SQLite 事件消费者分别使用各自构建报告中的运行配置，不改变部署 profile 或加入宿主多余驱动。现有 `tests/build-platform-native.php` 增加显式 PostgreSQL 扩展而不引用驱动类的场景，保留正常启动、搬迁包和缓存断言。

修复后的本机同输入消费者已实际启动成功，退出 0、输出 `cid=-1`、标准错误为空，扩展清单不变；新程序 SHA-256 为 `e10d708fbc24be9b69f03183c5c8d4e89d74aadbdeac822676af3196a7e1166e`。生成的模块依赖从仅推断 Swoole 等引用，变为在 Swoole 之前显式登记 `pdo_pgsql`；未修改 SDK、驱动或 Swoole 字节。

本机红绿程序、配置、构建身份和依赖注册源码共 39 份材料已归档并逐文件回读，ZIP SHA-256 为 `c9c8f1ffe6ef4f0a76d0f866eb79d3d10873cae0e3ad8e4e23e20210a65d531b`。两份临时消费者及编译中间文件已回收约 188 MiB，保留原始诊断程序及重现所需输入。

本机 `tests/build-platform-native.php` 的完整回归通过：显式扩展实际加载、部署身份审计、正常启动、错误库路径与等长篡改拒绝、二次构建缓存命中且程序摘要不变。启动探针约 0.07 秒、退出 0，低于原有三秒预算。此结果是 macOS ARM64 共享 embed 验收，不代替 Windows 或正式单程序发布矩阵。

该平台程序 SHA-256 为 `dcb37b43fbb63b606c78cf9d3472e8bf6f98047e35f1e4001786ad10f53df00a`，证据 ZIP 摘要为 `a5ff3fad5e145e4a739397050b50bcf2c8c234f073b3fcdaf74fb001a3bcfed8`。归档逐项回读后已回收本轮消费者约 48 MiB。

修改文件格式与语法、79 项相关契约测试和 678 个断言，以及 942 份 PHP 基础检查、1965 处文档引用与站点发布边界检查均通过。当时 RC17 尚未创建标签或公开；后续 Windows 应用复验见下节。一次性 Windows 诊断入口已移除，原结果保留。

## 快捷接口的同类调用点

`tests/helpers-build.php` 同样把控制器的三库配置交给仅使用 SQLite 的程序，现改为向该子进程传递构建报告中的运行配置。四组件全量 AOT、真实 SQLite、输入校验、受限查询和白名单排序的 PHP/原生对照通过。

同一个新程序再次经公开 `tests/helpers.php` 验证：旧全量配置复现相同 PostgreSQL 常量错误，自身配置退出 0、业务断言通过。程序 SHA-256 为 `1f0e4f5af84d031f568fe753509ef9bb7624660837c9851dd7af825456ce026a`；此项是 macOS ARM64 共享 embed 结果。

## Windows 应用复验

[应用复验运行](https://github.com/zoujingli/typeapp/actions/runs/38847727655)固定源码 `f41b84ad594ae7c96e66fb8b2199bd2b4a10c97c`，完整通过 HTTP/CLI 共同装配、业务事件、快捷接口、内嵌资源、物联中心三库开发与原生入口，以及三库独立模板和可搬迁包验收。SQLite、MySQL、PostgreSQL 的原生 HTTP 结果均属于同一个主应用程序，SHA-256 为 `9be290d63513ab92be5690f7ceb862c2b25f80f3e146cdb3d232dd4605e5efb7`，各自执行 527、526、526 次 HTTP 检查并正常退出。

原始 Artifact `12026965544` 共 23941024 字节，ZIP SHA-256 为 `2f990570ec1d836a92101e13d9cb66379f143b22c1840576a014b5b94589b2a8`。已回读 ZIP CRC、六份开发/原生业务报告、三份模板程序摘要、HTTP/事件构建报告身份、快捷接口程序摘要，以及两个外部数据库的进程清理回执。

本轮使用 `scope=application`，属于 Windows 共享 embed 的应用复验，未执行完整公开契约、独立 ORM 或静态单程序发布门禁。RC17 标签随后固定到同一源码，由[正式发布运行](https://github.com/zoujingli/typeapp/actions/runs/38854215726)独立执行完整范围；上述定向结果不能替代该发布结论。
