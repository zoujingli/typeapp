# 三库原生迁移

`type-orm` 提供迁移内容、执行器、状态与恢复入口，复用已有 `Database`、`Connection`、`ExecutionScope`。每次操作只连接选择的数据库，使用独立会话并在结束时销毁；没有 Redis、HTTP 或 core 的运行依赖。

## 公共入口

`Migration($version, $description, $statements, $transactional)` 定义一份不可变的 SQL 迁移；应用将定义与框架一起交给 TypePHP 编译。版本必须唯一，执行器按版本字符串排序。版本、描述、SQL 顺序与事务声明共同生成 SHA-256 校验值。历史定义被修改或从计划遗漏都会拒绝执行；后续变化使用新版本。

`Migrator($driver, $table = 'type_migrations')` 的公共方法为：

- `status($migrations)`：只读当前状态，可在其他迁移运行中查看；新数据库返回 `pending`，不提前建立记录表。
- `run($migrations)`：取得互斥锁后建立记录表，跳过 `applied`，按顺序执行待处理版本。发现任何 `running/failed` 时拒绝自动重试。
- `history()`：读取开始、失败、完成和恢复记录，含执行次数、时间及说明。
- `recover($migrations, $version, 'retry'|'applied', $reason)`：在同一个互斥边界内恢复中断或失败版本，必须提供非空说明。`retry` 重新允许执行同一内容；`applied` 登记维护者已经人工完成并核对的结果。

`MigrationConsole` 为这四个接口提供 JSON 命令输出和退出码。独立 ORM 原生命令与 core 的 `migrate` 命令适配器调用同一个对象，没有复制执行逻辑。`help` 不连接数据库；core 的总入口 `help/check` 继续保持离线行为。

```bash
composer build:migrations
build/migrations/type-app status
build/migrations/type-app run
build/migrations/type-app history
build/migrations/type-app recover 202609080001 retry '已核对并清理部分 DDL，允许重试'

# 同样的迁移行为，经 core 的声明式统一命令入口：
build/migrations-core/type-app migrate status
```

示例通过 `TYPE_MIGRATION_DRIVER=mysql|pgsql|sqlite`、已有数据库环境变量以及 `TYPE_MIGRATION_PREFIX` 选择目标。SQLite 文件由 `TYPE_SQLITE_FILE` 指定。独立消费应用只需安装 `type-orm-<driver>`，构建时安装 `type-build`；示例的故障场景变量只用于集成演练，业务应用应直接编译自己的迁移计划。

## 数据库语义与互斥

| 数据库 | 互斥范围 | DDL 与失败语义 |
| --- | --- | --- |
| MySQL | 同一数据库和记录表的 `GET_LOCK` 专属会话锁，竞争立即拒绝 | 迁移必须显式声明 `transactional=false`。DDL 可能隐式提交，失败前已生效的表与数据保留；不承诺整体回滚。记录表使用 InnoDB，开始、失败、完成和审计更新分别原子提交。 |
| PostgreSQL | 当前数据库、schema 和记录表的会话 advisory lock，竞争立即拒绝 | 普通表和索引 DDL 可与成功记录放入同一个事务，失败整体回滚。`CREATE/DROP INDEX CONCURRENTLY`、`VACUUM` 必须显式非事务执行。 |
| SQLite | 同一规范数据库路径的独立 `.type-migration.lock`；Linux 额外持有旧数据库 `flock` 以兼容旧执行者，竞争立即拒绝 | 普通 DDL 与成功记录放入同一事务，失败回滚。操作系统在进程退出后释放文件锁，但稳定锁文件不删除；WAL 的写锁与 busy 上限继续由驱动管理。 |

SQLite 文件迁移要求已有驱动约定的本地文件系统，所有迁移执行者采用本执行器。独立锁不阻止应用普通写入，普通写入竞争仍按数据库的 busy 超时处理；macOS/Windows 不在数据文件上施加可能干扰 SQLite 自身的 `flock`。禁止迁移期间替换数据库或锁文件，Unix 明确拒绝数据库及锁的硬链接别名。执行账户需要数据库目录、数据文件与稳定锁文件的相应读写权限；锁文件生命周期、旧版本兼容与 Windows 身份限制见[迁移跨平台约定](migrations-portability.md)。普通 `:memory:` 属于当前专属连接，单次 `run` 可执行但关闭后不会持久化，不能用于跨命令的部署记录。

同一应用的执行者必须配置相同的记录表和数据库身份。互斥锁只约束迁移协作者，不是对任意业务 SQL 或数据库管理员操作的安全沙箱。PostgreSQL、MySQL 服务端有时在客户端被杀后先结束当前 SQL，锁尚未释放时恢复命令继续拒绝，不强行窃取锁。

## SQL 与恢复边界

一个 SQL 元素只包含一条普通表、索引 DDL 或数据写入语句；支持单引号、双引号与反引号中的双写引号。直接事务控制、过程定义、注释、美元引用、引用内反斜杠与复合语句明确拒绝，避免数据库词法模式差异破坏事务边界。当前迁移不是任意 SQL 脚本解释器；需要复杂过程的应用应先扩展受验证的迁移能力。

`running` 先独立持久化。对于事务迁移，DDL 和 `applied` 同时提交；SIGKILL 后仍为 `running` 说明该事务未提交。对于非事务迁移，中断可能发生在任意已生效操作之后，维护者需先停止竞争部署、检查实际表和数据，清理或补齐操作，再使用 `recover`。失败不会自动改写 checksum、删除历史或猜测数据库结果。执行器没有自动 `down`/破坏性回滚，兼容扩展后优先新版本前向修复。

竞争命令返回 75，其他迁移失败返回 70。SQL 执行错误记录既有 ORM 的 SQLSTATE 分类，不把 SQL 文本、密码或 PDO 连接细节写到控制台。退出后的专属会话不会回到业务连接池。

## 验证

`composer test:migrations` 对两种原生入口分别运行真实 MySQL、PostgreSQL、SQLite。覆盖成功与幂等、数据库实际变化、唯一版本、内容修改与缺失拒绝、DDL 能力拒绝、失败状态、事务与非事务 DDL 差异、显式重试及人工补齐记录、竞争立即拒绝、真实 SIGKILL、中断后的锁释放与恢复。

`composer test:migrations-consumer` 为每种驱动创建真实独立 Composer 安装，仅编译 ORM、该驱动及 runtime，运行相同原生验收；构建报告检查无 core、HTTP、Redis 或其他驱动的生产依赖。测试将 Redis/HTTP 指向不可用端口，迁移仍独立完成。GitHub Actions 将两个产物、构建身份与工具链锁作为验收附件保存。
