# 新旧版本共存与回滚窗口

复用迁移、模型查询、Outbox、队列、缓存及停止协议，由 runtime 的 ReleaseCompatibility 表达应用版本与数据库、消息、缓存协议的独立声明。`bf28c8b` 的 Linux x64 共存回滚与封装回滚组、Linux ARM64 和 macOS 回滚组均通过，使用各平台自己的双版本产物执行三库路径；实际环境与身份见[发布验收](../evidence/native-release-20260925.md)。这不代表所有业务版本或全部集群升级均已验收。

`ReleaseCompatibility(version, capabilities)` 与构建清单采用同样的 schema/messages/cache 分类。`assertSchema` 拒绝当前数据库协议不在应用窗口内的启动；`assertConsumer` 在改变生产者或恢复积压前核对消息版本；`acceptsCache` 表达缓存可理解范围，实际 TypedCache 仍在解码前核对格式并对不兼容内容返回未命中。它不会自行猜测数据库、队列或其他部署实例的真实状态，调用者须从可信状态或实际实例声明取得这些值。

演练使用两个固定入口：旧版 1.0.0 支持数据库 1/2、消息 1、缓存 1；新版 1.1.0 支持数据库 2/3、消息 1/2、缓存 2。原生模式同时核对嵌入产物的版本和能力与实际注册相符。数据库阶段从 Migrator 的公开历史读取，不修改内部迁移记录伪造成功。

| 顺序 | 实际行为与检查 |
| --- | --- |
| 初始应用 | 旧版写入业务和同事务 Outbox，旧 worker 消费即时任务，旧延迟任务保留 |
| 兼容扩展 | 新迁移在新增字段后因前置表缺失失败；旧版强一致读取仍成功，新版拒绝启动 |
| 恢复迁移 | PostgreSQL/SQLite 使用已回滚的 DDL，MySQL 显式核对并补偿未完成的字段扩展，再以不变 checksum 重试 |
| 消费者先行 | 新旧 worker 同时就绪，旧消费者明确拒绝新消息版本；旧 worker 排空后才切换新生产者 |
| 新旧应用共存 | 新版双写扩展字段，兼容期仍以旧字段为共同读取来源，旧版继续读写；不同缓存格式互相未命中并回源 |
| 二进制回滚窗口 | 停止新版 HTTP，重新启动旧版入口，在数据库 2 上恢复写入；新版 worker 继续消费旧延迟与新版本消息 |
| 未知协议 | 未来消息版本 3 被真实 worker 隔离，不产生业务副作用；四个稳定 Outbox ID 各只留下一个消费效果 |
| 结构收缩 | 相关 HTTP 与 worker 全部排空后回填新字段并删除旧字段；旧版启动被拒绝，新版读取保留了回滚期间写入 |

兼容扩展期间不会移动发布标签；回滚选择已有二进制及兼容启动配置。收缩演练安排明确的排空窗口，重启支持数据库 3 的应用，不承诺任意字段重命名都可无停机自动完成。收缩后的旧版不再适用，应从经过验证的备份恢复数据及迁移记录，或执行前向修复，而不是强行绕过版本检查。

`tools/test-rollout.sh mysql|pgsql|sqlite` 使用专属、带唯一标签的 Redis 容器：任务实例 noeviction/AOF always，缓存实例单独淘汰。它只清理自己创建的容器及精确应用命名空间；网络和既有数据库服务保持原状。AOF 位于临时测试卷，本演练验证进程和版本切换，存储重启与写满的可靠性证据通过专属故障测试验证。

各驱动创建独立数据库或文件，只有创建成功才执行数据库清理。用户、Outbox 意图和通知效果同事务处理，缓存仅保存 DTO 字符串，格式变化不能触发任意对象反序列化。发布重放与消费幂等沿用 Store/Worker 公共契约。

集中原生检查先编译 docs/build-config/type-rollout-old.json 与 docs/build-config/type-rollout-new.json，然后设置 TYPE_ROLLOUT_NATIVE=1、TYPE_PHPX_SDK 并运行同一脚本。每次演练把驱动、执行方式、场景与实际消费效果保存到 build/rollout-check-*/verification.json。

## 无源码双版本发布增量

镜像构造复用`tests/native-package-image.php`，同一检查也供普通干净部署/恢复测试使用。每个scratch镜像检查全部75个文件、实际入口字节与受信发布摘要，不含业务PHP源码、PHP CLI、Composer、SDK或编译器；入口为真实ELF及其发布运行库，非root、只读根、独立PID，只挂载本轮数据目录。测试控制器需要PHP/PDO/Redis/Phar/POSIX和Docker；这些控制端工具不进入部署镜像，Docker是本演练的隔离设施，不是生产应用运行的强制依赖。

在配置好锁定Linux工具链的构建端运行：

```sh
php tests/prepare-packaged-rollout.php
```

将输出的实际`preparation.json`路径作为参数，在可访问发布包及Docker的控制端分别执行：

```sh
php tests/packaged-rollout.php "$TYPE_ROLLOUT_PREPARATION" sqlite
php tests/packaged-rollout.php "$TYPE_ROLLOUT_PREPARATION" mysql
php tests/packaged-rollout.php "$TYPE_ROLLOUT_PREPARATION" pgsql
```

## macOS原生双版本增量

同一准备入口现已在macOS ARM64锁定工具链构建1.0.0/1.1.0两份Mach-O发布包，各20生产包、216源码输入、零排除。相同`tests/packaged-rollout.php`按准备清单的平台选择启动方式：Linux继续使用scratch；Darwin仅允许在macOS本机运行真实发布启动器，业务与原有场景断言不分叉。

`tests/native-package-sandbox.php`集中发布启动命令及实际隔离探针，普通`tests/native-package.php`和双版本演练共用。先验证受信发布文件可读，再确认应用源码、Composer自动加载和实际PHP SDK不可读，PHP/编译器不可执行；控制端还确认原文件和编译器真实可用，避免以文件缺失或策略语法错误作为隔离证据。SDK与账号工具目录按当前环境识别，不写死开发者用户名。专用SQLite数据子目录仅获得读取例外，不允许执行。

这个结果是同一macOS主机上的受限访问验收，不是完全移除全机工具后的独立机器证明，也不替代Windows、其他CPU架构或最终同提交完整CI。暂未发布或更新本地前端。

## macOS原生测试服务入口

`tests/packaged-rollout.php <preparation.json> sqlite|mysql|pgsql --native-services`保留同一发布和场景验收，不调用Docker。通过`TYPE_REDIS_SERVER`显式指定已安装的原生redis-server；控制器创建独立的可靠队列/缓存进程、私有数据根与回环端口，实际验证存储策略，结束后仅停止自身进程。`tests/native-rollout-redis-test.php <redis-server>`验证这一所有权接口，包括幂等关闭、其他所有者不受影响、非法执行文件及覆盖拒绝。

MySQL/PostgreSQL模式要求显式提供对应的`TYPE_MYSQL_*`或`TYPE_PGSQL_*`测试连接参数（HOST/PORT/DATABASE/USER/PASSWORD），不使用默认连接猜测。原`rollout.php`仅在该服务上创建、使用和删除本轮随机新库，不改动初始连接库的业务表。macOS CI提供本轮原生数据库进程；本地用外部服务时仍须明确数据库载体，不能以原生应用/Redis证明数据库服务本身也运行于macOS。SQLite只使用本轮新建文件。原Docker模式保持不变，仍需现有镜像和专用测试资源。

## 本机原生三库与Redis双版本闭环

已准备匹配的原生MySQL/PostgreSQL工具与redis-server时，可使用统一测试入口管理所有临时服务：

```sh
php tests/prepare-packaged-rollout.php
composer test:native-database-rollout -- /上一步输出的/preparation.json /MySQL工具根目录 /PostgreSQL工具根目录 /原生路径/redis-server
```

构建步骤仍要求匹配的PHP_HOME/PHPX_HOME。演练前先核对两份发布的1.0.0/1.1.0版本、Darwin平台与实际二进制摘要，再启动测试数据库。三库共用同一对发布包，不为不同驱动另行改写应用或回滚场景。

新入口复用`NativeDatabase`管理本机数据库的私有目录、认证、数据根/进程身份与关闭，调用既有`packaged-rollout.php --native-services`及`NativeRolloutRedis`管理独立队列/缓存。所有进程只用本轮数据、回环端口和明确工具；子进程PATH不含Docker，不使用全局服务或隐式业务连接。

原有迁移失败与修复、错误配置拒绝、新旧消费者共存、消费者先行、旧延迟消息保留、缓存版本、旧二进制恢复写入、未知消息隔离及收缩拒绝均继续执行。每库仍要求`delayed-old`、`new-ready`、`old-ready`、`rollback-ready`四个稳定ID各一个效果；失败不能仅靠文件存在或效果总数判为通过。

失败时外层仍关闭自己持有的数据库，Redis及应用进程由原场景清理；任何清理错误都不计为通过。凭据文件和空短socket目录回收，私有数据、脱敏日志与证据保留。本机受限访问视图不等于独立干净机器或全集群/PITR恢复，也不替代Windows及最终同提交完整矩阵。
