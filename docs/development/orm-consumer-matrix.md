# 三驱动独立业务消费矩阵

## 同一套业务源码

`examples/orm-suite` 包含共同的模型声明、业务流程、文章观察器、迁移计划和应用入口。三个消费者只选择各自的 `DriverFactory` 文件。业务不包含私有 PDO 补丁或临时替代 ORM；字段存储的真实差异集中声明在迁移计划中：

| 行为或存储 | MySQL 8.4.11 | PostgreSQL 17.11 | SQLite 3.40.1 |
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

JSON 对象的属性顺序不作跨库保证；读取后分别断言字段内容和类型，数组次序仍由 JSON 数组本身表达。JSON 标量比较区分整数与字符串；复合对象/数组比较在共同接口处拒绝。模型的主键回读是已声明的数据库等价实现，不要求 MySQL 提供不存在的 INSERT RETURNING。

## 公共行锁接口

```php
$connection->transaction(static function (Connection $transaction): void {
    $article = $transaction->table('articles')->where('id', '=', 42)->lockForUpdate()->first();
    // 同一事务内完成依赖该行状态的工作。
});

$connection->transaction(static function (Connection $transaction): void {
    $available = $transaction->table('articles')->orderBy('id')->limit(20)->lockForUpdate(true)->get();
});
```

`Query::capabilities()` 新增 `row-lock` 和 `skip-locked`。`lockForUpdate(true)` 使用数据库原生 SKIP LOCKED；SQLite 不把行锁静默替换成另一种隔离语义。普通行锁会按数据库超时策略等待，SKIP LOCKED 跳过被其他连接锁定的行。没有活动事务、聚合、直接将锁定查询转为写入或确定分页都明确拒绝，防止锁在语句结束立即失效或被组合忽略。行锁测试实际持有第一条连接的锁，由第二条连接证明跳过，提交后再证明可以读取。

## 消费环境的隔离证据

每次验证创建 `build/orm-suite-驱动-随机标识`，通过 Composer 的 path repository 复制生产包，禁止符号链接回主仓。生产依赖严格只有：

- `zoujingli/type-runtime`
- `zoujingli/type-orm`
- 对应的一个 `zoujingli/type-orm-mysql`、`type-orm-pgsql` 或 `type-orm-sqlite`

开发依赖只用于准备模型与原生构建；运行前执行 `composer install --no-dev`，检查安装清单中已经移除所有构建工具。业务实际包含的 PHP 文件必须全部位于消费者目录，未选驱动类不能存在。三个消费者共同使用相同业务文件，不通过删减场景让某个驱动通过。

Composer 的 `config.platform` 显式把未选 PDO 扩展标为不存在，确保依赖求解不会隐式要求它们。安装、模型生成、编译和移除开发包时保留完整构建环境；直到运行业务前才切换 PHPRC 与扫描目录。运行配置按原顺序复制已加载的主 ini 和扫描文件，只删除未选 PDO 驱动的 `extension=` 指令，保留 PDO 基础、Phar、mbstring 及其他标准／运行扩展，包括动态扩展的原路径与配置。新 PHP 子进程检查实际扩展清单后，再执行不受 platform 覆盖影响的 `composer check-platform-reqs --no-dev`；没有忽略平台检查。

原生环境显式设置 `TYPE_NATIVE_PHP_INI` 时，以该 embed 配置及其 `php.d` 为基线筛选，并把消费者的筛选结果交给 `nativeCommand()`，保留锁定的共享 pcntl 路径；不能让公共原生启动配置再次启用其他 PDO 驱动。验证结束恢复调用方的全部配置环境变量。

当前 Linux PHP 工具链静态内置 pdo_sqlite，不能由 ini 卸载，所以实际运行清单为 MySQL+SQLite、PG+SQLite、SQLite；MySQL 与 PG 相互未加载，SQLite 消费环境两者均未加载。每份报告记录实际 `pdo_extensions`、`runtime_extensions` 和 `static_extensions`，不把静态内置模块冒称已移除。未安装 SQLite 驱动包且 Composer 禁用该平台依赖的 MySQL/PG 路径已通过；完全不含 SQLite 模块、动态加载 PDO 基础与 Phar 的 PHP 构建仍应在对应 CI 工具链中复核。

三驱动都创建自己的专属测试数据库，SQLite 使用专属本地文件以支持跨迁移连接和双进程访问；普通内存库的连接隔离单独验证，不冒充跨进程共享。测试结束删除这些准确命名的测试资源，保留消费者源码、Composer 锁文件、生成的模型与结果报告，便于复现。迁移使用框架 Migrator，不通过测试脚本替代业务建表。SQL 数据差异由公开模型与关系接口验证。

强一致检查证明 reader/writer 身份约束、显式主库读取和写后粘滞；这里的两个用途指向同一测试服务，不把它冒充真实复制延迟演练。实际主从延迟和未知提交对账仍使用独立验证入口。

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

原生模式会先从消费者自己的包、模型与应用源码编译，再核对构建报告中的生产包和源码路径，随后移除构建依赖并运行 ELF 的 `run/race/verify` 三个行为。没有 PHP 源码回退；任一步失败直接使验证失败。三库配置分离与最终同提交 CI 均须执行，单项本地结果不能代替组合验收。

本任务没有修改根 Composer、CI 或公共完成状态文档；主仓集成时接入上述六条命令，并归档每个消费者的验证报告与原生构建身份即可。

## 本机原生数据库矩阵入口

已准备本机MySQL/PostgreSQL工具和匹配PHP_HOME/PHPX_HOME时，可用同一实例所有者管理专用数据库，再执行上方六个独立消费流程：

```sh
export TYPE_NATIVE_PHP_INI="$(bash tools/prepare-embed-runtime.sh)"
composer test:native-database-orm -- /MySQL工具根目录 /PostgreSQL工具根目录 /可信Composer程序
```

此入口只接受非root Linux/macOS及相应原生工具，不使用Docker、全局数据库服务或隐式业务连接。MySQL/PostgreSQL实例保持本轮私有目录、随机认证、回环端口、数据根/直接子进程身份检查，结束或失败时仅回收自身资源；SQLite使用专用文件。Composer配置独立保存，安装、移除开发包及真实平台要求检查仍由原消费者执行。

两种模式分别生成独立应用，生产包严格只有runtime、orm和所选驱动。新入口核对模式、驱动、生产包集合和实际双进程冲突结果；原生报告补充构建身份、源码数及二进制摘要，未删减原业务场景。

静态内置扩展不能被INI卸载；每份报告仍如实记录实际扩展。此入口证明独立业务消费矩阵，不等同于真实复制延迟、提交未知恢复、全部专项故障或完整框架/平台验收。
