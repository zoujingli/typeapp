# SQLite 迁移锁的跨平台约定

## 为什么不能锁住数据库本身

旧执行器在打开 PDO 连接后，对 `PRAGMA database_list` 返回的数据库文件再次 `fopen()` 并持有排他 `flock`。macOS 上，该锁与 SQLite 使用的 `fcntl` 字节区锁会相互阻塞，即使两个句柄来自同一进程，建表也会得到 `SQLSTATE HY000 / SQLite 5 / database is locked`。

Windows 也不能把框架互斥锁放在 SQLite 数据文件上：PHP 官方说明 `flock` 在 Windows 使用强制锁，持锁进程通过第二个句柄访问同一文件也会受限制。PHP 8.5.10 的实现使用 `LockFileEx`，非阻塞方式设置 `LOCKFILE_FAIL_IMMEDIATELY`。[官方手册](https://www.php.net/manual/en/function.flock.php)；[锁定 PHP 实现](https://github.com/php/php-src/blob/php-8.5.10/ext/standard/flock_compat.c)。

## 新锁路径与旧版本兼容

文件库先从 `PRAGMA database_list` 得到实际文件，再用 `realpath()` 取得本地规范路径。独立锁位置固定为：

```text
/应用数据/app.sqlite
/应用数据/app.sqlite.type-migration.lock
```

同一数据库文件的所有迁移记录表共用该锁，不因不同的 `Migrator` 表名创建多个互斥域。普通 `:memory:` 不产生文件锁，其单连接生命周期保持原有约定。

迁移器在驱动建立专属连接后检查文件身份；硬链接拒绝保证不会继续创建迁移互斥域或执行迁移元数据/DDL，但不代表此前的驱动连接和 WAL 初始化从未发生。需要在打开数据库前就拒绝非法部署路径的应用，还应沿用驱动或部署阶段的前置校验。

| 平台 | 新执行器取得的锁 | 目的 |
| --- | --- | --- |
| Linux | 先独立锁，再数据库旧 `flock`，均非阻塞 | 新执行者之间互斥，同时兼容已发布的旧执行者 |
| macOS | 仅独立锁 | 避免阻塞 SQLite 的 `fcntl`；旧文件锁执行器自身无法成功完成文件迁移 |
| Windows | 仅独立锁 | 避免强制锁阻塞 SQLite 通过自己的句柄读写数据库 |

Linux 的双锁次序固定：旧锁已被持有时，新执行者释放刚取得的独立锁并返回 `TYPE_MIGRATION_LOCKED`；新执行者正在迁移时，旧执行者也会被数据库 `flock` 拒绝。不能在运行迁移时把两个版本分别指向不同文件名、硬链接或不同还原副本，也不能把数据库替换/重命名当作在线升级手段。

竞争保持立即拒绝，不在迁移执行器内轮询等待。普通 SQLite 应用写入仍使用数据库自己的事务和 busy timeout；独立锁只协调本执行器的迁移，不会把任意 SQL 写入自动变成迁移持锁操作。

## 文件生命周期与权限

锁文件第一次通过 `x+b` 独占创建，之后通过 `r+b` 打开；两种方式都不截断文件。执行器不写锁内容、不依据 PID 或文件时间判断锁主、不删除锁文件。权限沿用创建账户的 umask 和目录 ACL；迁移执行账户需要创建文件以及以后以读写方式重新打开的权限。[PHP fopen 模式定义](https://www.php.net/manual/en/function.fopen.php)。

锁属于当前打开句柄，由正常 `finally` 或进程退出释放。文件保留不代表仍有迁移在运行；删除所谓“过期锁文件”会让新执行者打开不同 inode，可能同时存在两组持锁者，因此禁止运行期 `unlink`、替换或清空锁文件。SQL 失败后先释放锁，失败迁移本身仍必须通过原有 `recover()` 协议恢复。

打开前拒绝已存在的符号链接、目录和其他非普通文件；取得锁前后检查真实路径和文件类型。在 Linux/macOS 还比较已打开句柄与当前目录项的 `dev/ino`，并要求数据库及锁的 `nlink` 为 1；真实硬链接和未知链接身份均明确拒绝，避免同一数据库由不同别名形成多把迁移锁。Windows 的 PHP `stat/fstat` 不提供可普遍依赖的稳定文件身份，本实现**不把零 inode 当作已完成安全证明**；Windows 只做路径、符号链接与普通文件检查，并要求父目录由受信任账户控制。此约束同样适用于 Unix：通用 PHP 文件接口没有跨平台 `O_NOFOLLOW`/文件 ID 打开契约，不能允许非受信任进程在打开窗口里更换目录项。

数据库和锁必须位于支持相应文件锁语义的本地文件系统。Windows 建议使用正常 ACL 管理的 NTFS；PHP 明确不支持 FAT 及其衍生文件系统的 `flock`。NFS、SMB、网络映射盘、跨机器共享锁、数据库硬链接别名以及并发重命名/恢复均不在本协议支持范围内，不能把本地通过推论为网络文件系统通过。

`.type-migration.lock` 不是 SQLite 数据文件，也不是迁移历史，业务备份以 SQLite 正确备份流程和迁移记录表为准。停机还原时可以在确认没有迁移执行者后创建新的空锁文件，但必须恢复数据目录与锁文件的账户/组/ACL 访问权限；在线还原不能替换现有锁 inode。备份工具不应把锁文件误当作可运行中的数据库副本，也不应通过删除它“解锁”。

## 验收入口与当前证据

在开发主仓执行：

```sh
php tests/sqlite-migration-lock.php
php tests/migrations-native.php --php sqlite
```

新增测试使用实际 PDO、`Migrator` 和独立 `proc_open` 子进程；不依赖 `pcntl_fork`、Unix 信号状态或 `/dev/null`。覆盖实际建表提交、稳定锁身份、重复迁移、第二执行者及时拒绝、正常结束后重取、SQL 失败释放及恢复、符号链接与 Unix 数据库/锁硬链接拒绝，并在 Linux 验证新旧执行者双向互斥及第二把锁取得失败时的资源释放。测试结束后只清理本轮已停止执行者的临时数据库和锁，这与生产运行期不删除锁文件并不冲突。

已实际通过：macOS PHP 8.4.14 的新增测试和原有完整 SQLite 迁移套件；Linux PHP 8.5.10 的新增测试以及 SQLite、MySQL、PostgreSQL 三套原有迁移套件，包括竞争互斥、DDL 恢复和真实 SIGKILL 恢复。MySQL、PostgreSQL 的会话锁实现未更改。
