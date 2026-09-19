# 首次部署、升级、备份与回滚

本手册沿用现有原生发布包、外部配置、迁移器、服务管理与`ReleaseCompatibility`。维护端可以安装数据库客户端或构建工具，应用运行端仍不需要PHP CLI、Composer、业务源码或编译器。以下步骤不授权修改现有生产数据，也不会由框架自动执行。

当前主仓构建基线为 PHP `8.5.10 ZTS`、TypePHP `0.9.0`、PHPX `2.9.0`；本手册不扩大平台支持范围。当前仅 macOS ARM64 完成这条基线的本地原生验收，Linux、Windows 及其他架构必须使用各自匹配的 SDK 和原生产物重新验证。

## 1. 固定部署对象

记录应用版本、目标OS/CPU、构建身份、程序和发布清单SHA256、配置版本以及实际数据库/消息/缓存协议。清单摘要应来自独立受信渠道，不用同一不受信任下载中的两个文件互相证明来源。

发布程序放到新的版本目录，数据、日志、上传资源和秘密配置放在独立运行根。不要覆盖正在使用的版本，不把`.env`或数据库加入发布归档。先核对对应平台真实验收范围、原生库/扩展要求、服务账号权限与依赖材料缺失项。[发布布局](https://github.com/zoujingli/typeapp/blob/main/docs/development/native-packages.md)、[服务管理](https://github.com/zoujingli/typeapp/blob/main/docs/development/native-services.md)、[依赖材料](https://github.com/zoujingli/typeapp/blob/main/docs/development/dependency-notices.md)

运行端显式固定`TYPE_APP_RELEASE_SHA256`，使用发布目录的`run`或Windows的`run.cmd`：

```sh
./run help
./run verify-runtime
```

完整审计成功后再访问数据库。`check`是配置/装配检查，不等于数据库可用；管理器active也不等于业务就绪。

## 2. 首次部署

1. 为所选驱动准备独立数据库及受限业务账号。SQLite准备私有、可写的数据父目录；其他驱动由数据库管理员创建库和账号。
2. 在外部运行根配置`.env`或受控进程环境，设置`APP_BASE_PATH`、数据库连接、监听/Host白名单和令牌。生产保持`APP_ENV=production`、`APP_DEBUG=false`；密钥不写入构建或发布物。
3. 执行`run migrate status`和`run migrate history`了解现状；首次SQLite文件尚未初始化时，status失败是明确状态，不应通过serve暗建数据库。
4. 由一个明确的迁移操作者执行`run migrate run`。迁移器的锁和checksum是必要约束，但不代替部署协调。
5. 再次检查迁移状态/历史，启动指定作用域的服务，验证`/healthz`、`/readyz`及鉴权和实际业务读写。检查退出码、日志及数据目录权限。
6. 记录验收结果后才接入流量；不要只凭进程存在或一次200响应宣布部署完成。

## 3. 升级前的一致性准备

先列出所有写入者：HTTP实例、队列worker、调度器、批处理、数据库事件及外部集成。确认发布范围、迁移负责人、维护窗口、回滚条件和可接受的数据恢复点/恢复时间。

备份演练采用停止应用写入的维护窗口。停止调度/新任务与入口流量，正常排空所有相关进程，确认没有剩余写入者或DDL；不要用强杀伪装排空成功。应用数据库与外部文件需要同一业务一致性边界。单独给数据库做事务快照，并不能自动保证上传文件、队列和对象存储与之同一时刻。

保留旧发布目录及受信摘要、旧配置版本、当前迁移历史和已验证备份。缓存可按协议重建，持久队列/Outbox不能当作可随意清空的缓存。使用这些能力的应用还须单独协调其持久化和恢复策略。

## 4. 数据库备份

所有备份都可能含业务秘密。维护目录0700、文件0600仅是本机访问限制，不等于加密；生产应使用已批准的加密、密钥管理、异地保存与保留策略。不要把密码放在命令行、Git或验收报告中。

### MySQL

使用匹配服务器的客户端，对明确的应用数据库备份。例如凭据放在私有客户端配置文件，`--defaults-extra-file`必须位于其他选项之前：

```sh
mysqldump --defaults-extra-file=/secure/source-client.cnf \
  --single-transaction --routines --triggers --events --hex-blob \
  --no-tablespaces --set-gtid-purged=OFF \
  --result-file=/secure/backups/application.sql application_db
```

检查退出码和输出文件，再记录摘要。`--single-transaction`的快照保证针对事务表，并不允许同时进行会破坏一致性的DDL；非事务表或不同一致性要求需要管理员选择合适方案。本手册采用写入和DDL均已暂停的演练条件。Windows不要用会重编码SQL文本的PowerShell重定向，使用`--result-file`。[MySQL官方说明](https://dev.mysql.com/doc/refman/8.4/en/mysqldump.html)

这不是整个服务器、账号/授权、复制拓扑或binlog恢复点的备份。需要时间点恢复或集群级恢复时，另行保存并验证相应日志、全局对象与配置。不能把一次逻辑dump扩大为完整服务器恢复保证。

### PostgreSQL

使用匹配的`pg_dump`，通过私有密码文件或既有安全凭据通道认证：

```sh
pg_dump --format=custom --file=/secure/backups/application.dump application_db
```

custom格式使用`pg_restore`恢复；它保留数据库对象和数据，但一个数据库的dump不包含全部集群角色、表空间或WAL时间线。全局对象、扩展、账号和权限需按实际部署另行准备/备份，不能通过随意忽略owner/ACL把缺口掩盖掉。[pg_dump说明](https://www.postgresql.org/docs/17/app-pgdump.html)

### SQLite

使用SQLite的备份API或CLI的`.backup`，不要在仍有WAL/写入活动时只复制主数据库文件：

```sh
sqlite3 /data/application.sqlite ".backup '/secure/backups/application.sqlite'"
sqlite3 /secure/backups/application.sqlite "PRAGMA integrity_check;"
```

检查结果为`ok`并保存摘要。SQLite备份API生成一致数据库副本；外部配置、上传文件和附加数据库仍须分别纳入一致性方案。[SQLite备份API](https://www.sqlite.org/backup.html)

## 5. 备份清单与恢复演练

清单至少记录时间、数据库驱动与工具版本、对应应用构建/发布摘要、迁移历史摘要，以及每个数据库/配置/资源文件的大小和SHA256。明确备份范围，不扫描整个用户目录后声称“全部数据”。清单摘要另存于受信部署记录；摘要不是加密或独立来源证明。

恢复默认只允许全新目标：新实例/空数据库及不存在的数据目录。先验证清单和所有文件摘要，并将驱动、发布摘要、构建身份与当前受信恢复上下文逐项比对；不支持的清单协议或身份不匹配必须在调用维护工具、创建目标或复制配置之前拒绝。不能仅因清单自身摘要正确便接受另一应用或版本的备份；损坏、缺失或来源不明时停止，不能为了恢复成功更新摘要。

MySQL应先准备新实例及相同逻辑数据库名、账号和必要配置，再用原生mysql客户端导入。避免直接指向原库，也不要依靠备份中可能存在的DROP语句来清理未知目标。演练在恢复前检查表、视图、例程和事件，非空即拒绝。

PostgreSQL在新目标准备所需角色和扩展后使用：

```sh
pg_restore --dbname=application_db --exit-on-error --single-transaction /secure/backups/application.dump
```

目标连接必须明确指向新实例/空库。默认不用`--clean`覆盖既有对象；失败时保留原目标并隔离失败的新目标，先分析原因，不自动再次覆盖。`--single-transaction`与退出错误策略不代表所有外部状态都能回滚。[pg_restore说明](https://www.postgresql.org/docs/17/app-pgrestore.html)

SQLite恢复到新的数据库路径，再检查完整性。配置和文件也恢复到新的数据根，不覆盖原根。随后使用受信原生产物连接恢复目标，核对迁移历史、重复迁移、鉴权/读写和实际业务状态，再正常停止。

必须明确恢复点之后的写入如何处理：逻辑备份不会凭空包含后续数据。保留原目标以便核对和补偿；是否切换、丢弃或合并后续写入属于明确的业务决策，不由工具自动决定。本演练是快照恢复，不声称实现PITR。

## 6. 发布切换与回滚窗口

应用版本、数据库协议、消息协议和缓存格式是不同维度。复用`ReleaseCompatibility`与构建capabilities，且由调用者提供实际、可信的外部版本；类名、目录或SemVer数字不能推导协议兼容性。[既有双版本演练](https://github.com/zoujingli/typeapp/blob/main/docs/development/rollout-compatibility.md)

| 当前变化 | 允许的处理 |
| --- | --- |
| 代码变化，数据协议仍在旧程序窗口内 | 正常停止新版，切回已验证旧发布/配置，保留当前数据并重新验收 |
| 向后兼容的schema扩展 | 按已验证的扩展/双写/消费者先行顺序推进；回滚前再次核对旧程序窗口及积压消息 |
| 已收缩schema或执行不可逆转换 | 不能仅切回旧二进制；选择前向修复，或恢复到新目标并明确处理恢复点之后的数据 |
| 迁移失败或提交结果未知 | 停止切换，核对实际数据库与迁移历史，不自动重试或伪造成功记录 |

MySQL DDL可能部分提交。只有核对实际状态并完成所需补偿后，才使用现有`migrate recover <版本> <retry|applied> <说明>`；不能把`applied`当作跳过失败的开关。PostgreSQL/SQLite的事务行为也须依据具体迁移与驱动验证。

新版本应放在新目录，预先校验运行库、材料状态和配置。兼容迁移后按消费者/生产者依赖顺序切流；不可兼容变化使用明确维护窗口。切换服务定义前确认旧进程已排空，切换后验证健康、授权、读写与错误路径。保持旧版本和恢复点直到回滚窗口正式关闭。

## 7. 当前可重复验收与范围

```sh
php tests/native-package-clean.php <Linux发布目录> <受信清单SHA256> sqlite --recover
php tests/native-package-clean.php <Linux发布目录> <受信清单SHA256> mysql --recover
php tests/native-package-clean.php <Linux发布目录> <受信清单SHA256> pgsql --recover
```

控制器只创建自己的测试数据库/网络/容器，原生应用始终在无源码镜像中运行。它停止应用后使用sqlite3/mysqldump/pg_dump备份，验证损坏与既有目标拒绝，恢复到新目标，核对迁移历史/配置/资源，确认原生读写及原目标数据保留，再回收测试资源。

演练数据很小，控制器输出有固定预算；不能拿测试脚本直接备份真实大库。生产应使用原生工具直接写受控文件和容量/时限策略。测试备份只覆盖示例数据库及明确配置/资源，不覆盖全部集群账号、WAL/binlog、任意应用文件、Redis或对象存储。

本轮备份恢复证据不代替所有平台的完整升级/切流验收。旧双版本协议演练与本次新发布/恢复演练分别记录；最终同一源码快照、所有平台和外部依赖组合仍须完成后才能宣布整个目标完成。
