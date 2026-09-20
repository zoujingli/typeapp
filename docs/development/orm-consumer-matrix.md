# 三驱动独立业务消费矩阵

## 同一套业务源码

`examples/orm-suite` 包含共同的模型声明、业务流程、文章观察器、迁移计划和应用入口。三个消费者只选择各自的 `DriverFactory` 文件。业务不包含私有 PDO 补丁或临时替代 ORM；字段存储的真实差异集中声明在迁移计划中：

| 行为或存储 | MySQL | PostgreSQL | SQLite |
| --- | --- | --- | --- |
| 主键存储 | AUTO_INCREMENT | IDENTITY | INTEGER PRIMARY KEY |
| 模型返回生成主键 | 同连接 lastInsertId | INSERT RETURNING | INSERT RETURNING |
| 大整数与十进制 | DECIMAL，按字符串返回 | DECIMAL，按字符串返回 | TEXT，避免数值亲和性丢失精度 |
| UTC 微秒 | DATETIME(6) | TIMESTAMP(6) | TEXT |
| JSON 对象与布尔/整数类型 | JSON | JSONB | 合法 JSON 文本 |
| 用户简介、用户文章、文章作者 | 公共关系预加载 | 公共关系预加载 | 公共关系预加载 |
| 标签挂载、同步、pivot 字段 | 唯一约束及父行锁 | 唯一约束及父行锁 | 唯一约束及 IMMEDIATE 事务 |
| 软删除、恢复、事件、查询范围 | 已验证 | 已验证 | 已验证 |
| 普通/游标分页、有界关系批次 | 已验证 | 已验证 | 已验证 |
| 乐观锁及两个独立进程竞争 | 一个更新、一个冲突 | 一个更新、一个冲突 | 一个更新、一个冲突 |
| 正式迁移、历史校验、重复运行 | 非事务 DDL，显式记录 | 事务 DDL | 事务 DDL |
| 直接行锁、SKIP LOCKED | 支持，需要活动事务 | 支持，需要活动事务 | 明确拒绝 |
| 显式 INSERT RETURNING | 明确拒绝 | 支持 | 支持 |
| 指定冲突目标 upsert | 明确拒绝 | 支持 | 支持 |
| 任意唯一键 upsert | 显式独立入口 | 明确拒绝 | 明确拒绝 |
| 未实现的 serializable 事务模式 | 明确拒绝 | 明确拒绝 | 明确拒绝 |

各平台的实际数据库版本记录在消费者的 `verification.json` 中，不能将一个平台的版本当作所有平台的运行基线。JSON 对象的属性顺序不作跨库保证；读取后分别断言字段内容和类型，数组次序仍由 JSON 数组本身表达。JSON 标量比较区分整数与字符串；复合对象/数组比较在共同接口处拒绝。模型的主键回读是已声明的数据库等价实现，不要求 MySQL 提供不存在的 INSERT RETURNING。

## 公共行锁接口

```php
Db::transaction(static function (): void {
    $article = Article::query()->where('id', '=', 42)->lockForUpdate()->first();
    // 同一事务内完成依赖该行状态的工作。
});

Db::transaction(static function (): void {
    $available = Article::query()->orderBy('id')->limit(20)->lockForUpdate(true)->get();
});
```

`Query::capabilities()` 新增 `row-lock` 和 `skip-locked`。`lockForUpdate(true)` 使用数据库原生 SKIP LOCKED；SQLite 不把行锁静默替换成另一种隔离语义。普通行锁会按数据库超时策略等待，SKIP LOCKED 跳过被其他连接锁定的行。没有活动事务、聚合、直接将锁定查询转为写入或确定分页都明确拒绝，防止锁在语句结束立即失效或被组合忽略。行锁测试实际持有第一条连接的锁，由第二条连接证明跳过，提交后再证明可以读取。

## 消费环境的隔离证据

每次验证创建 `build/orm-suite-驱动-随机标识`，通过 Composer 的 path repository 复制生产包，禁止符号链接回主仓。生产依赖严格只有：

- `zoujingli/type-runtime`
- `zoujingli/type-orm`
- 对应的一个 `zoujingli/type-orm-mysql`、`type-orm-pgsql` 或 `type-orm-sqlite`

开发依赖只用于准备模型与原生构建；运行前执行 `composer install --no-dev`，检查安装清单中已经移除所有构建工具。业务实际包含的 PHP 文件必须全部位于消费者目录，未选驱动类不能存在。路径归属先解析真实位置，再按目标平台统一分隔符和大小写语义，并保留目录边界检查；源码归档采用统一的相对路径。三个消费者共同使用相同业务文件，不通过删减场景让某个驱动通过。

Composer 的 `config.platform` 显式把未选 PDO 扩展标为不存在，确保依赖求解不会隐式要求它们。安装、模型生成、编译和移除开发包时保留完整构建环境；直到运行业务前才切换 PHPRC 与扫描目录。运行配置按原顺序复制已加载的主 ini 和扫描文件，只删除未选 PDO 驱动的 `extension=` 指令，保留 PDO 基础、Phar、mbstring 及其他标准／运行扩展，包括动态扩展的原路径与配置。新 PHP 子进程检查实际扩展清单后，再执行不受 platform 覆盖影响的 `composer check-platform-reqs --no-dev`；没有忽略平台检查。

原生环境显式设置 `TYPE_NATIVE_PHP_INI` 时，以该 embed 配置及其 `php.d` 为基线筛选，并把消费者的筛选结果交给 `nativeCommand()`，保留锁定的共享 pcntl 路径；不能让公共原生启动配置再次启用其他 PDO 驱动。验证结束恢复调用方的全部配置环境变量。

当前 Linux PHP 工具链静态内置 pdo_sqlite，不能由 ini 卸载，所以实际运行清单为 MySQL+SQLite、PG+SQLite、SQLite；MySQL 与 PG 相互未加载，SQLite 消费环境两者均未加载。每份报告记录实际 `pdo_extensions`、`runtime_extensions` 和 `static_extensions`，不把静态内置模块冒称已移除。未安装 SQLite 驱动包且 Composer 禁用该平台依赖的 MySQL/PG 路径已通过；完全不含 SQLite 模块、动态加载 PDO 基础与 Phar 的 PHP 构建仍应在对应 CI 工具链中复核。

三驱动都创建自己的专属测试数据库，SQLite 使用专属本地文件以支持跨迁移连接和双进程访问；普通内存库的连接隔离单独验证，不冒充跨进程共享。测试结束删除这些准确命名的测试资源，保留消费者源码、Composer 锁文件、生成的模型与结果报告，便于复现。迁移使用框架 Migrator，不通过测试脚本替代业务建表。SQL 数据差异由公开模型与关系接口验证。

本矩阵验证未配置副本时读取落到主库，以及显式主读与事务边界。默认读从、写主、事务外写后不粘主及从库故障拒绝由 `tests/read-write.php` 的独立实例验证；实际复制延迟和未知提交对账使用相应专项入口。

主从验证器分别启动主库和只读端点，MySQL/PostgreSQL 需要本机数据库工具目录。可通过第四个命令参数指定，或使用 `TYPE_MYSQL_TOOLS`、`TYPE_PGSQL_TOOLS`；目录须包含对应的 `bin` 工具，验证器只清理自己创建的实例。SQLite 不需要服务工具。统一故障矩阵会传入已核验的工具目录，Composer 主从脚本读取上述环境变量。

## 执行入口

```bash
php tests/native-database-orm.php "$TYPE_MYSQL_TOOLS" "$TYPE_PGSQL_TOOLS" "$COMPOSER_BINARY" mysql
```

省略最后一个驱动参数则顺序运行三库。控制器与独立消费者支持构建器的 `embed-probe` JSON 协议，核对真实 embed、PHP/ZTS和核心库身份，再读取静态/配置后的实际扩展；原有独立 `probe --extensions` 入口继续按其明确协议消费。PHP开发配置显式传给子进程，不因清理环境而丢失所需扩展。

模型、精确字段、关系/pivot、事件生命周期和乐观锁的专项分别通过 `tests/native-database-failures.php` 的 `models` 与 `lifecycle` 分组执行。它们复用现有公开用例，真实验证查询次数、未加载/null状态、取消/异常和双进程冲突，不以模型字段存在代替业务行为。

配置现有隔离服务变量 `TYPE_MYSQL_*`、`TYPE_PGSQL_*`，并保证 Composer 在 PATH 或通过 `COMPOSER_BINARY` 指定后运行：

```bash
php tests/orm-suite-consumer.php mysql
php tests/orm-suite-consumer.php pgsql
php tests/orm-suite-consumer.php sqlite
```

统一应用配置位于 `examples/orm-suite/application.json`，验证器为每个消费者复制成自己的 `application.json`。集中原生验收时用同一入口：

```bash
php tests/orm-suite-consumer.php mysql --native
php tests/orm-suite-consumer.php pgsql --native
php tests/orm-suite-consumer.php sqlite --native
```

原生模式先从消费者自己的包、模型与应用源码编译，核对生产包、源码清单和二进制摘要，再逐文件归档并回读源码摘要，移除应用、生产包及编译缓存中的 PHP 输入。之后由同一原生产物执行 CRUD、并发竞争和最终状态查询。报告记录移除的 PHP 文件数量、源码归档摘要、实际 Swoole 版本与静态/动态加载方式；不同平台按实际可执行格式验收，任一步失败均使验证失败。

每份消费者报告同时保存主仓提交、工作区是否有修改及工具链锁摘要。开发中的成功结果单独成立；只有三个目标平台均来自同一提交且工作区干净，才计入同提交验收。

双进程竞争使用消费者自己的文件屏障：就绪标记在排他锁内追加并刷新，读取在共享锁内完成，避免 Windows 强制锁使无锁读取失败。加锁采用非阻塞尝试，等待总计最多十秒，等待对端时释放锁，成功和异常均关闭句柄。子进程输出必须精确符合预期，不过滤文件或运行时警告；最终数据版本和原子更新计数另外查询验证。

同一消费者还验证当前作用域恢复、可信上下文快照、子事务独立、跨协程连接拒绝、关闭作用域后的缓存连接拒绝、父取消/关闭/Deadline 传播，以及等待超时后租约继续占用至任务结束。`database-io-wait` 用两个真实连接竞争同一行：持锁期间子任务等待超时，连接与在途预算保持占用；释放锁后等待实际收尾，核对最终数据没有丢失或重复写入。超时后的任务仍报告取消，数据库写入可能已经完成，不能据此透明重试。会话报告分别记录 PostgreSQL 完整重置后的物理复用、MySQL/SQLite 的保守关闭及污染隔离；普通 CRUD 物理复用不能仅由池槽位计数证明。

网络数据库会话用例通过独立控制连接终止自身的测试会话，验证归还时断连会退役、PostgreSQL 重置失败有记录，以及空闲会话失效后明确报错且不透明重试。三库均验证凭据代次轮换：活动旧租约保持原身份并能收尾，新借用使用新代次，旧代排空后不再进入空闲集合。这里验证代次与租约生命周期，真实数据库密码变更另由 `tests/identity-credentials.php` 验证。该入口还通过所选 PHP 或原生身份命令验证 PostgreSQL 配置角色、schema 和读写用途的初始化、污染与恢复，并核对归还后复用同一物理连接；测试进程只负责创建及回收专用角色和 schema。

消费者启动前启用 `CoroutineRuntime::enableIo()`。MySQL 需要 mysqlnd 和网络 hook，PostgreSQL、SQLite 分别需要官方 `--enable-swoole-pgsql`、`--enable-swoole-sqlite` 构建选项；缺失时真实锁等待验收不能通过。报告的 `swoole_hook_flags` 记录实际启用值。Swoole 会在扩展初始化时注册其编入的 PDO 驱动，所以 `PDO::getAvailableDrivers()` 可能包含没有独立加载 `pdo_*` 模块的驱动；`swoole_pdo_drivers` 单独记录该来源，`runtime_extensions` 继续验证独立模块过滤，生产包清单仍只能包含所选 ORM 驱动。

macOS ARM64 和 Linux ARM64 已通过三库独立消费的 PHP、AOT 与移除源码运行，包含真实数据库锁等待、关闭作用域后的缓存连接拒绝、断连退役、PostgreSQL 重置失败和凭据代次专项；Linux 结果来自 Colima ARM64 虚拟机内的专用容器。Windows x64 的 Swoole SDK 构建、扩展加载和 PHPUnit 已通过，SQLite/MySQL 均通过独立 PHP、AOT 与移除源码运行，包含十项上下文与资源专项、双进程乐观锁和原子更新；PostgreSQL 尚未完成。工作流提供 `orm` 验收范围，复用专用数据库实例入口顺序执行三库，并保留运行包、编译清单、主场景结果及两个子进程的退出码和原始输出。分阶段证据不代表整套验收通过。主从选路另有三库 PHP/AOT 实测，不将两台具有受控数据差异的服务器称为复制集群。

Linux ARM64 另使用 QEMU 7.2.22 user-mode 显式执行 ARM64 动态加载器及程序，完成三库无源码运行、上下文与资源专项，以及两个独立模拟进程的乐观锁竞争和原子更新。仅设置容器的 `--platform linux/arm64` 不计为 CPU 指令模拟；报告保存模拟器版本和摘要、宿主环境、程序及运行库摘要、源码提交和各场景结果。该批补测使用源码提交 `5c3e19b` 的保留产物，运行前逐文件校验且没有业务 PHP 源码，不等同于后续提交重新编译或最终同提交平台验收。

验收工具支持用 `TYPE_SWOOLE_MODULE` 指定已核验的动态模块，将其复制到独立消费者并通过 `runtime.modules` 固定摘要。PHP、embed 探针与原生产物的扩展来源分别核对，不能仅改变 PHP 的 ini 后假定原生构建自动使用相同模块；原生构建仍以应用声明和实际 SDK 探测为准。

物理复用的性能对照在相同实现、SDK、数据库、容量和 CRUD 负载下，仅改变空闲连接上限。每轮先预热，再交替执行关闭与复用两种配置，分别记录 PHP 和原生的实际连接身份数、吞吐、p50/p95/p99、CPU 与进程峰值 RSS；保留逐次样本、测量程序和产物身份。macOS ARM64 的 PostgreSQL 已完成这组对照，结果只说明该负载的收益，不代表其他驱动、并发容量或全部平台的性能。三库故障隔离仍须独立通过，不能用延迟改善代替会话重置正确性。

## 本机原生数据库矩阵入口

已准备本机MySQL/PostgreSQL工具和匹配PHP_HOME/PHPX_HOME时，可用同一实例所有者管理专用数据库，再执行上方六个独立消费流程：

```sh
export TYPE_NATIVE_PHP_INI="$(bash tools/prepare-embed-runtime.sh)"
composer test:native-database-orm -- /MySQL工具根目录 /PostgreSQL工具根目录 /可信Composer程序
```

此入口只接受非root Linux/macOS及相应原生工具，不使用Docker、全局数据库服务或隐式业务连接。MySQL/PostgreSQL实例保持本轮私有目录、随机认证、回环端口、数据根/直接子进程身份检查，结束或失败时仅回收自身资源；SQLite使用专用文件。Composer配置独立保存，安装、移除开发包及真实平台要求检查仍由原消费者执行。

两种模式分别生成独立应用，生产包严格只有runtime、orm和所选驱动。新入口核对模式、驱动、生产包集合和实际双进程冲突结果；原生报告补充构建身份、源码数及二进制摘要，未删减原业务场景。

静态内置扩展不能被INI卸载；每份报告仍如实记录实际扩展。此入口证明独立业务消费矩阵，不等同于真实复制延迟、提交未知恢复、全部专项故障或完整框架/平台验收。
