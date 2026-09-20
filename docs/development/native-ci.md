# 原生平台CI入口与当前覆盖

工作流存在、静态检查通过与对应runner实际执行通过是三种不同状态。各平台证据独立，不以本机或其他架构结果替代最终同提交验收。

开发和功能提交直接在`main`进行。原有Linux x64、Windows和macOS原生工作流的push触发范围均为`main`，不再使用临时验证分支；新增Linux ARM64入口只接受`workflow_dispatch`。远端推送和手动调度沿用会话授权。

macOS与Linux ARM64均保存CLI主INI和扫描INI的完整内容，并使用独立空扫描目录启动控制器，避免独立消费者关闭扫描后丢失PDO、Redis或Swoole。embed信号探测另生成原生运行INI；三库与Linux专项控制器逐个读取实际构建报告中的运行INI，并核对产物平台、架构及身份。两套工作流均安装Swoole6.2.2并启用官方内置库；实际原生产物仍按场景声明和探测扩展。安装成功仍须经过真实embed探针，不能靠CLI模块列表证明原生兼容。

HTTP 组复用协议、消息、显式及 Attribute 路由、校验、日志的 PHP/AOT 公开用例；文件、信任边界和请求日志统一在 Swoole 入口验证，随后验证真实小容量卷的写满失败与清理。受管任务、慢数据库背压和取消使用专用 MySQL 慢 SQL 场景，不声称另外两库已运行同一种数据库取消机制。标准应用在 Swoole 入口执行三库 PHP/AOT 及缓存对照；ORM、事务、迁移和组合场景按各自实际驱动语义运行。独立调度消费者和调度/队列组合消费者分别安装并全量编译生产依赖。TLS 覆盖 MySQL、PostgreSQL 与 Redis，可靠性使用本轮专属的原生 Redis 进程。

Linux ARM64的`run-linux-regression.sh`只接受非root Linux账号及显式原生工具位置；各控制器建立新的私有数据目录和回环端口，恢复使用本机维护工具与bubblewrap受限视图，不以Docker代替平台验收。该脚本的`reuse`仅跳过已有场景或标准应用的构建；rollout及独立消费者仍建立自己的完整输入和产物，不能将其当作整组无构建开关。

Windows数据库来源固定于`.github/windows-databases.json`：MySQL 8.4.11和PostgreSQL 17.11的官方x64便携包，下载后校验SHA256，运行前再核对实际版本。不使用镜像预装的不同版本或既有数据目录；版本来源见[MySQL官方发布页](https://dev.mysql.com/downloads/mysql/8.4.html)、[EDB官方二进制页](https://www.enterprisedb.com/download-postgresql-binaries)，镜像预装范围见[GitHub Windows 2022说明](https://github.com/actions/runner-images/blob/main/images/windows/Windows2022-Readme.md)。

`.github/scripts/run-windows-database.ps1`只允许GitHub Windows runner，在新的RUNNER_TEMP目录设置当前账号私有ACL，使用回环端口及随机测试凭据，独立启动mysqld或pg_ctl管理的新集群；不安装Windows Service、不启动/修改预装数据库服务。通过真实PHP连接确认就绪，再复用三库物联中心标准项目和模板的开发/AOT/发布测试；退出时仅停止本轮实例、删除本轮临时凭据并记录结果。模板安装可显式传入`TYPE_COMPOSER_PHAR`，确保用锁定PHP执行Composer而非依赖系统批处理包装器。

Windows 的 `pg_ctl start` 会把标准句柄继承给常驻 CMD/PostgreSQL 进程，因此启动输出沿用 CI 控制台，服务日志写入专属文件并在停止后脱敏保存。启动是否成功由 `pg_ctl` 的有界等待和退出码、真实 PDO 就绪检查共同确认，不以常驻后代关闭输出管道作为启动条件；其他短生命周期命令继续完整收集输出并检查管道关闭。该行为依据固定版本的 [PostgreSQL 启动实现](https://github.com/postgres/postgres/blob/REL_17_11/src/bin/pg_ctl/pg_ctl.c)，不改变数据库协议或后台服务管理方式。

Windows便携包需要核对来源、摘要、ZIP结构和x64 PE。PowerShell解析、通用子进程参数、双输出、脱敏、截止逻辑以及非Windows拒绝使用对应测试入口验证；静态检查不能证明Windows ACL、数据库启动、PHP构建或业务已经通过。实际平台结果须由对应runner验证。

各平台最新范围与限制统一见[平台与验收](../guide/platforms.md)，准确运行与产物身份见[平台证据](platform-support.md#当前结果与证据)。每次更换 Swoole、PHPX、libphp 或目标架构后都必须重跑完整入口；组件、SQLite 或单项命令结果不能替代应用、通信、三库和无源码发布验收。

macOS选择GitHub标准`macos-15` ARM64标签，不使用Docker或WSL运行应用。准确平台标签见[GitHub runner说明](https://docs.github.com/en/actions/reference/runners/github-hosted-runners)。PHP安装Action固定为已核对提交`f3e473d116dcccaddc5834248c87452386958240`（v2.37.2），请求8.5.10 ZTS后仍按`toolchain.lock.json`校验实际PHP、TypePHP、PHPX；安装器或镜像漂移必须失败，不放宽版本。

`configure-toolchain.php`共用Linux/macOS显式SDK选择，分别核对libphp.so/libphp.dylib；包装器绑定准确二进制、头文件和embed库。`verify-toolchain.php`可显式选择linux/macos/windows，默认沿用锁文件的主要平台。`prepare-toolchain.sh`按本机Unix平台构建PHPX。CI将PHPX源码复制到新的build目录，排除旧目标文件，不覆盖既有SDK或其他平台产物。

SDK选择器也接受可选的`扩展名=模块绝对路径`参数，例如`pcntl=/专用构建目录/pcntl.so`。它生成独立扩展目录，保留原SDK的其他模块，将所有候选路径与SHA256纳入SDK身份；模块以无覆盖的完整副本保存，避免外部软链接越过构建身份边界。重复名称、无效原生格式、副本/链接篡改均拒绝。补充模块不会安装到原PHP目录，也不会修改业务构建JSON；真实ABI、版本和函数仍由`RuntimeProfile`的embed探针验证，文件存在不等于可用。

macOS契约组覆盖配置、快捷接口、操作生成、进程信号、真实watch及平台身份；HTTP专项使用Swoole；应用组覆盖三库开发/原生物联中心标准项目、缓存对照和空目录接入；部署组覆盖发布搬迁/校验、归档、launchd生命周期及SQLite接入发布。上述为工作流配置范围，实际通过项须引用对应运行结果。

macOS的rollout组用同一对全量AOT的1.0.0/1.1.0发布包执行三库升级与回滚。`tests/packaged-rollout.php ... --native-services`不调用Docker：数据库连接由调用者显式提供，原场景仅创建/删除自己的随机新库；`NativeRolloutRedis`集中管理两套前台原生Redis及其私有目录、回环端口、就绪和退出。可靠队列继续要求AOF always/noeviction，缓存采用独立allkeys-lru实例。程序仍在原有源码/SDK不可读、PHP/编译器不可执行的macOS受限视图中运行，迁移失败、消费者先行、延迟消息、四个稳定效果及收缩拒绝断言不变。CI数据库来自该runner新启动的原生MySQL/PostgreSQL；本地另用外部测试数据库时必须分别说明载体，不能混称全套原生服务已通过。

Linux保留原16组全部命令，在基础组增加真实watch，在app增加三库空目录接入、修改业务、AOT与发布闭环。新增delivery组复用正式打包/归档和服务定义检查，同一新发布包完成MySQL/PostgreSQL/SQLite的scratch无源码运行、原生备份工具、新目标恢复、损坏与覆盖拒绝；这里的服务检查不冒充systemd生命周期通过。新增packaged-rollout组只构建一次1.0.0/1.1.0，用同一对发布包执行三库真实升级与回滚；CI显式传入随机构建身份并读取对应preparation.json，不搜索或复用旧构建。保留原有四个稳定效果、旧延迟消息与兼容拒绝断言。

以上两组在Ubuntu宿主构建，Docker仅用于既有隔离数据库及额外的scratch运行视图，不替代宿主原生构建，也不证明其他平台部署。测试仅回收自身标记的容器、网络和镜像；不清理整台runner的Docker资源。备份包含测试凭据，不进入Actions附件；附件仅收集脱敏报告、构建身份和构建/场景日志。接入工作流不等于这些新任务已在GitHub通过。

`.github/scripts/run-macos-native.sh`仅允许在GitHub macOS runner执行。Homebrew安装发生在可丢弃runner；测试使用新建临时数据目录、回环端口及原生MySQL/PostgreSQL/Redis进程，不启用全局brew services。凭据在日志使用前屏蔽，临时凭据文件及时删除；退出时仅收回自己的进程和集群，失败现场留在runner临时目录。不要通过伪造CI环境变量在开发者机器运行该数据库准备脚本。

本地检查覆盖SDK选择、重复包装和身份篡改拒绝；独立PHPX构建与真实embed探针需要对应SDK。工作流使用actionlint、脚本语法与shellcheck检查，这些结果不能替代GitHub runner上的完整任务。PHP安装、checkout及产物上传下载Action均固定提交，升级时重新核对上游引用与实际行为；固定引用不保证账户计费或额度可用。

性能组仅在手动调度提供完整 `base_source` 时纳入矩阵，缺少基准时不运行性能比较，也不计为已验证。macOS 与 Linux ARM64 入口允许主仓公开后执行，实际运行仍按事件与会话授权控制。
