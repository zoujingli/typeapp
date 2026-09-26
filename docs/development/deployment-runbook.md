# 部署与恢复操作手册

完整操作手册由构建组件维护，参见[首次部署、升级、备份与回滚](../../plugin/type-build/docs/operations.md)。`type package`会把同一份内容复制为发布根的`OPERATIONS.md`并纳入完整性清单，维护端离线也可阅读，不依赖开发主仓目录。

三库演练入口为 `tests/native-package-clean.php <Linux发布目录> <受信清单SHA256> <sqlite|mysql|pgsql> --recover`。测试只创建专用资源，不可拿测试器直接操作业务数据库。

macOS入口为`tests/native-package-recovery.php <macOS发布目录> <受信清单SHA256> <sqlite|mysql|pgsql>`。它要求非root账号，在原有源码/SDK不可读、PHP/编译器不可执行的受限视图中运行应用，先正常停止写入，再备份并向全新的数据根/随机数据库恢复。文件摘要与禁止覆盖规则同Linux共用；目标空库检查和实际客户端参数由同一目标构造，不用DROP/CLEAN清空已有库。

维护端需要原生`sqlite3`、`mysqldump`/`mysql`或`pg_dump`/`pg_restore`。SQLite可通过`TYPE_SQLITE_BACKUP_TOOL`指定工具；其他工具从PATH解析并验证为本机原生文件。MySQL/PostgreSQL要求显式`TYPE_MYSQL_*`/`TYPE_PGSQL_*`的HOST、PORT、DATABASE、USER、PASSWORD；仅接受专用回环IP连接，不猜默认服务器。程序不会把测试器、维护客户端或PHP解释器加入发布包。

独立维护端验证可以通过`TYPE_RECOVERY_COMMANDS`提供`{"dump":["客户端命令前缀"],"restore":["客户端命令前缀"]}`，数据库参数仍由测试器追加。此接口是受信测试配置，不是安全沙箱；不要把秘密写入参数数组。报告会标记`caller-command-prefix`，外部Linux维护工具不算本机macOS客户端通过。MySQL恢复通过只读标准输入提供SQL，PostgreSQL提供二进制归档；不拼接shell重定向。

验收范围为标准应用数据库、显式`.env`和资源样本，覆盖损坏拒绝、既有文件/非空库拒绝、软删除及迁移历史、恢复点之后的数据差异、恢复后读写和原目标保留。不是完整数据库集群、账号、WAL/binlog或任意应用目录的备份，也不证明PITR。备份包含测试凭据，目录/文件保持私有，不上传至Actions附件。实际平台、数据库与维护客户端载体分别记录，不以入口存在代替执行证据。

物联中心恢复到新的应用根后，使用同一受信程序执行 `web:install` 重建页面，再启动 HTTP 服务。页面来自程序内嵌资源；数据库已经恢复时不能再次执行 `app:install`。如果保留了旧页面，先用 `web:install --dry-run --force` 查看差异，再显式更新。上传等业务文件仍由备份方案单独恢复。

## macOS本机原生数据库闭环

已有可信MySQL和PostgreSQL原生工具时，可以让独立测试入口管理自己的临时服务器，再依次执行上述三库恢复：

```sh
composer test:native-database-recovery -- "$TYPE_RELEASE_PREPARATION" "$TYPE_MYSQL_TOOLS" "$TYPE_PGSQL_TOOLS"
```

从项目根执行；三个环境变量分别指向已保存的发布准备JSON、MySQL工具根和PostgreSQL工具根，相对路径以当前项目根为基准。`preparation.json`是`type package`返回的JSON，包含发布目录和`manifest-sha256`；入口先验证发布包，再创建任何测试数据。MySQL根目录须有`bin/mysqld`、`mysql`和`mysqldump`；PostgreSQL根目录须有`bin/postgres`、`initdb`、`pg_dump`和`pg_restore`。仅接受非root macOS与实际Mach-O工具，不下载/安装软件、不调用Docker、Homebrew服务或launchd，也不连接已有数据库。

控制端PHP必须加载所选PDO驱动；缺少时在创建服务器前明确失败。`PHPRC`和`PHP_INI_SCAN_DIR`会传给恢复子进程，须指向同一匹配SDK的配置；给最外层PHP单独追加`-d extension=...`不会自动配置子进程。按末尾单个驱动并行运行时，每个控制器都有独立目录、端口和服务器，不能复用正在运行的测试实例。

每个实例使用新的私有`build/`目录、随机密码和回环TCP端口；通过已认证连接核对服务器数据根、PID文件及本控制器的直接子进程关系。MySQL在接受连接前执行私有初始化声明设置密码，关闭额外mysqlx端口；短Unix socket使用本轮`mktemp`目录，避免macOS路径长度限制。PostgreSQL只开回环TCP并使用SCRAM认证，不开Unix socket。原始业务环境不会隐式传入子进程，维护端也不会使用外部命令前缀。

测试复用`native-package-recovery.php`，不删减恢复/失败断言。完成或失败都会尝试停止自己持有的前台服务器并移除初始化凭据；异常初始化报告failed，不继续启动其他驱动。停止失败不得记为通过。服务器日志脱敏、数据目录和报告保留；短socket目录仅在为空时删除，不递归删除其他数据。SQLite没有独立服务器，生命周期字段为null而非伪造停止成功。
