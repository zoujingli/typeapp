# 受信任租户与资源隔离

core 提供不可变 Tenant 和 TenantResolver，位于 RequestPolicy、Authentication 之后。X-Tenant 仅是从固定映射中选择租户的输入；解析器必须使用已认证 Identity 再次授权，成功后移除原始头并提供 `type.tenant`。

每份映射至多 64 个租户，租户 ID、命名连接和缓存命名空间分别唯一。未知选择、未授权租户和动态拼接路径拒绝，运行时不会修改共享数据库配置或根据 header 创建无限连接池。

应用组合 Tenant 的命名连接和缓存命名空间，core 不依赖 ORM/cache。示例为每个租户使用独立 MySQL 数据库账号，只授予其数据库的业务权限；原生 SQL、Join 和批量写入都在已授权连接上执行，不能靠一个自动 where scope 代替完整权限隔离。

实际 HTTP 验证为两个租户各建数据库和账号，交错请求使用相同业务缓存键，响应的 tenant/owner/Join 字段和原生读取一致。缓存命中后仍保持租户键空间；伪造、未知与跨租户选择拒绝，固定跨库 SQL 探针被数据库权限拒绝。所有测试只清理本次创建的随机数据库和账号。

PHP 真实 MySQL/Redis 结果已通过，原生入口为 `docs/build-config/type-tenant-http.json`，最终集中验收继续记录。任意租户数据源的自动发现、动态账号创建和无限租户池不在该接口能力内。

## TypePHP 三库与 Swoole

同一 `TenantValue` 模型、Join与缓存入口分别在 MySQL独立库/账号、PostgreSQL独立schema/角色、SQLite独立文件运行。资源配置仅在启动时从固定映射构造，外部header不能进入schema或连接字符串。MySQL探针核对实际权限错误码，PG核对42501；SQLite没有数据库用户ACL，探针明确返回文件映射边界，不把语法错误冒充权限拒绝。需要执行不受信任SQL的SQLite应用还须独立进程及文件权限隔离，这不由同进程租户映射提供。

应用持有两个有界命名连接池，请求只归还自己的租约。测试包括交错请求、实际模型读写、会话污染后的基线恢复、事务失败后下一请求、凭据代次轮换与空闲租约复用。缓存命名空间组合已授权租户与实际连接身份，代次变化后使用新键空间，不从未验证输入推测资源。

日志复用 type-log，以请求标识与租户业务 ID 关联，只记录代次、缓存身份摘要和资源计数；实际用户名、密码、库/schema 名称及 SQLite 路径不进入日志。Swoole 通过正常 worker 停止回调关闭实际拥有的连接池，记录关闭前无在途租约、关闭后创建数为零，不用主进程的空池替代 worker 清理证据。

构建后运行 `php tests/native-database-failures.php build "$TYPE_MYSQL_TOOLS" "$TYPE_PGSQL_TOOLS" tenant mysql`。配置 `TYPE_REDIS_SERVER` 为已核验的原生 Redis；HTTP 入口统一使用 Swoole。省略最后的驱动则顺序运行三库。控制器只创建自己的数据库、角色、文件和 Redis，PHP 与 AOT 分别运行相同请求。
