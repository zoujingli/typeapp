# 历史 CSV 导出

导出接入双端身份，并复用队列、Outbox 和文件 I/O。客户及模拟客户从历史页按筛选创建服务端任务，创建、查看、取消、下载分别使用 `customer.exports.create/read/cancel/download` 节点；恢复使用创建节点。仅有创建节点也能填写筛选提交，不隐含遥测读取或任务列表权限。任务固定创建时的全部匹配记录，不使用当前页游标，也不在浏览器生成 CSV。

## 复用与接口

原始与分钟范围直接复用 `HistoryService`、`AggregateService` 的筛选和历史归属语义；身份及当前权限复用 `IdentityService`、`RoleService`，审计复用 `AuditLog`。`ExportService` 继续拥有任务快照、进度、额度和文件生命周期；`ExportJob`、`ExportPublisher` 分别实现已有 Queue Job 和 Outbox Publisher 协议。`ExportController` 接管旧控制器的导出方法，负责严格客户输入和私有流响应；旧 `/iot` 导出路由和固定角色节点已删除。全新安装统一包含导出、快照、额度和原 Outbox 声明，不提供旧数据迁移。

| 入口 | 行为 |
| --- | --- |
| `POST /customer/tenants/{tenant}/devices/{device}/exports` | 携带32位小写十六进制请求 `id`、`kind`（records/minutes）、历史筛选、IANA `timezone`；202返回任务。同来源同请求重试返回原事实，内容或来源冲突409。未知字段及分页参数拒绝。 |
| `GET /customer/tenants/{tenant}/exports` | 当前准确会话来源的有界分页，默认20、最多100条。 |
| `POST /customer/tenants/{tenant}/exports/{export}/{action}` | `action` 为 `cancel` 时取消排队或运行任务并回收文件，重复取消返回原事实；为 `resume` 时恢复超过60秒未推进且仍未到期的排队/运行任务，保留快照和已提交偏移。 |
| `GET /customer/tenants/{tenant}/exports/{export}/download` | 当前权限、准确来源、原租户快照、成功状态、到期时间和文件长度/摘要均通过后返回私有文件流；摘要检查后再重验当前身份。不接受查询参数。 |
| `iot:exports [steps]` | 默认100、最多10000个工作步骤；每步先发布最多10条Outbox，再消费1条队列消息，无待办时退出。 |
| `iot:exports-work [seconds]` | 1至3600秒有界运行，支持受管退出信号。 |
| `iot:exports-clean [batch]` | 默认100、最多100个任务；不依赖Redis即可回收。 |

页面复用 Vben 布局、历史筛选、公共抽屉、列宽预算、行操作与请求层。创建/取消经过确认，异步防重复，抽屉内每5秒刷新任务，隐藏页面暂停刷新，离开页面释放定时器。当前权限失效由既有403行为返回租户选择；服务端逐请求授权，不以隐藏按钮代替权限检查。

浏览器按当前身份键保存尚未确认的请求及冻结筛选；网络失败或响应丢失后，刷新页面仍可重试原请求。成功或明确的业务拒绝才清除此记录，切换身份不能使用另一来源的待确认请求。

## 快照、预算与恢复

单任务最多100000行、100MiB。创建先作保守字节估算，再插入至多100001行并复查，任一超限整个事务拒绝，不截断成功。估算按每行两倍UTF-8值与物模型字节加640字节、另加4096字节表头预算，包含CSV转义余量；它可能先于实际文件上限拒绝较宽记录，错误提示要求缩小范围。生成时再次校验实际行数和字节，触限记为失败，不提供部分文件。

每个Job步骤最多100行。先取得双端授权共用的安装行锁，再锁任务行并读取准确会话及当前成员角色；验证步骤和终态后创建/锁定文件。授权锁持有至当前分块事务结束，撤权提交后下一块停止；慢文件行为与性能须单独验收。取消或清理后的旧消息不会重新创建文件。文件从已提交偏移截断后追加、刷新并同步，随后在一个数据库事务中提交偏移、进度、消费事实和下一步Outbox意图。数据库未知提交、租约丢失沿既有队列重投与显式恢复处理；确定文件错误才写业务失败。恢复增加步骤号，使旧消息失效，不重新查询源数据或延长任务期限。

MySQL等待授权锁和任务行锁之后才读取来源权限，避免沿用等待前的一致性快照。清理取得任务行锁后再次判断到期，避免删除候选选择后刚成功、已获得新保留期限的文件。文件确定删除后才删除最后的任务元数据，清理失败可继续定位和重试。

任务保存租户、有效客户、准确会话及模拟时的真实管理来源；普通平台令牌不能调用客户导出。队列领取、重投及实际输出前重验持久来源，不回退同账号的其他会话。同账号重新登录、普通和模拟登录、不同模拟会话均不能接管原任务或下载链接。退出模拟、撤销来源或收紧创建权限后，下一块失败为 `export_permission_revoked` 并回收部分文件，保留已提交进度和来源审计。已传出的文件字节不能撤回。

## CSV 与资源生命周期

CSV为UTF-8 BOM，每个单元格使用显式文本前缀和标准双引号转义，保留大序号、前导零和公式原文。每条原始事实或分钟窗口对应一行，共14列：类型、记录/设备/产品/模型/归属、双时间及分钟结束、序号、当前值推进状态、值或六项统计、冻结物模型和时区。分钟平均值由浮点sum/count计算，单位和属性定义保存在同一行的冻结物模型JSON；时间使用创建者时区并带偏移，与页面选择一致。

成功文件从完成起保留24小时；排队/生成任务从创建起24小时到期。取消/失败先删文件，有界清理随后删除快照。每轮最多100任务、每任务1000条快照，记录 `cleaned_at` 避免已经清空的任务阻塞后续工作；到期元数据额外保留一天后删除。已消费Outbox沿组件默认7天保留回收，未知效果不清除。运行者应持续运行工作角色并定期调用清理命令，文件不提供静态公开路径。

## 验证

```sh
php tests/iot-identity-databases.php --php "$TYPE_MYSQL_TOOLS" "$TYPE_PGSQL_TOOLS" --devices --exports
composer typeapp:build
php tests/iot-identity-databases.php build/app/type-app "$TYPE_MYSQL_TOOLS" "$TYPE_PGSQL_TOOLS" --devices --exports --no-source
pnpm --dir web typecheck
pnpm --dir web build
php tests/iot-history-browser-fixture.php build/app/type-app 18847 --exports
node tests/iot-export-browser.mjs <本轮fixture.json> http://127.0.0.1:15184
```

外部数据库工具和 `TYPE_REDIS_SERVER` 由已校验环境提供；浏览器使用隔离Web服务，其 `IOT_API_ORIGIN` 指向同一测试API。新增导出验证扩展现有身份/租户和浏览器数据装置，以真实HTTP、Redis、后台命令与文件系统观察行为；PDO仅准备行数、到期、容量与锁竞争装置，不代替后台执行。
