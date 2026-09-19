# 实时阈值告警

## 新应用客户权限（）

当前入口为 `/customer/tenants/{tenant}/alarm-rules`、`/alarms` 与 `/notifications`；`X-Tenant-Id` 必须与路径一致。固定节点为 `customer.alarms.read/manage/acknowledge` 和 `customer.notifications.read`，分别负责告警与规则查询、规则发布/停用、人员确认和通知读取。角色名称不参与授权，模拟会话完全采用目标客户的当前租户权限，旧支持头和伪造身份头均拒绝。菜单按查询节点显示；写节点和通知读取不隐式要求告警查询节点。

新安装直接复用 `Schema::alarms()` 的唯一完整表声明与组件 Outbox 声明，不建设旧数据转换。规则发布、版本边界、三次触发/恢复、独立消费者完成事实和通知可靠链由原服务继续负责。写入先取得现有安装授权锁，再取得规则/设备或告警锁，最后重验会话、模拟来源和权限；MySQL 的一致性快照在业务锁之后建立，不遗漏等待期间已经提交的样本。告警操作不推进租户资料版本。

成功业务与 `customer_audit` 同事务保存；审计同时保留有效客户和真实管理来源。确认保持首次客户及时间，重复请求仍检查当前授权；审计失败不能留下确认、规则新版本或通知成功。规则输入和确认均采用严格对象白名单，不接受跨租户批量参数。通知 GET 只读取持久投影，不在查询过程中投递、重试或确认。

沿用既有三个页面的表格、查询、抽屉、主题与失败恢复；通知只有相应告警读取节点时才显示详情跳转。三库装置使用 `tests/iot-identity-databases.php <产物或--php> <MySQL工具根> <PostgreSQL工具根> --app --devices --alarms`，原生部署加 `--no-source`；Redis 使用 `TYPE_REDIS_SERVER` 指定的隔离实例。浏览器沿用 `tests/iot-history-browser-fixture.php <产物> <端口> --alarms --notices` 和 `tests/iot-alarms-browser.mjs`。同源标准项目直接采用主仓业务，生成及独立分发单独验收，不另复制告警实现。

## 入口与范围

显式迁移 `014_iot_threshold_alarms` 建立规则、不可变条件版本、每版本连续状态及告警四张表。沿用现有租户权限、冻结物模型、设备写锁、审计和 `IngestionService::pending/consume`。Web 使用已有 Vben 布局、筛选网格、表格列宽预算、行操作和公共抽屉，新增告警规则与告警中心两个业务页面。

| 入口 | 行为 |
| --- | --- |
| `GET /customer/tenants/{tenant}/alarm-rules` | 当前规则；按名称、设备标识、属性标识筛选。 |
| `POST /customer/tenants/{tenant}/alarm-rules` | 创建单设备、单数值属性规则；绑定当时产品、物模型和归属。 |
| `PATCH /customer/tenants/{tenant}/alarm-rules/{rule}` | 带当前 `version` 发布新版本；编辑条件或停用均不覆盖旧条件。 |
| `GET /customer/tenants/{tenant}/alarm-rules/{rule}/versions` | 按版本倒序查询原条件、发布时间、版本结束原因及连续状态。 |
| `GET /customer/tenants/{tenant}/alarms` | 按设备、规则、状态、触发接收时间筛选，默认20、最多100条。 |
| `GET /customer/tenants/{tenant}/alarms/{alarm}` | 当时规则及单位、触发和恢复样本、采样/接收双时间、结束原因。 |
| `iot:alarm [batch]` | 固定独立消费者 `alarm`，一次处理1至100条待办，默认100；返回处理数与 `has_more`。 |
| `POST /customer/tenants/{tenant}/alarms/{alarm}/acknowledge` | 空JSON对象；管理员/操作员首次确认，重复请求返回原人员与时间。 |
| `GET /customer/tenants/{tenant}/notifications` | 当前租户站内通知，按 `kind=triggered/ended` 筛选，默认20、最多100条。 |
| `iot:notices [batch]` | 一次恢复、投递与消费最多100条；返回补齐/重放/发布/处理计数及Worker统计。 |
| `iot:notices-clean [batch]` | 告警、通知及已消费Outbox分别处理最多100条，默认100。 |

HTTP 请求沿用 Bearer 身份及 `X-Tenant-Id` 与路由一致的要求。管理员有 `alarm.manage`，合法租户成员有 `alarm.read`；平台身份不自动越过成员权限。写入在租户锁内重新授权并记录成功或失败审计，版本冲突返回409 `stale_version`。跨租户资源与不存在资源均不返回其条件或样本。告警历史按其原租户读取，不以设备当前归属改写历史访问范围。

创建字段为 `name/device_id/field/lower/upper/hysteresis/enabled`，编辑携带 `version` 并固定设备和属性范围。数值上下限至少一项存在，回差默认0、有限且非负，`lower + hysteresis <= upper - hysteresis`；只有单侧阈值时恢复区间另一侧开放。整数和数值属性可选，其他物模型类型拒绝。每设备归属最多保留64条规则定义，已停用规则继续占用定义额度，编辑通过新版本复用定义。此为处理预算，不代表整体容量已达标。

## 连续性与事务

无活动告警时，连续三次严格 `< lower` 或 `> upper` 触发；等于阈值不触发。活动期间，连续三次进入闭恢复区间结束，回差灰区中断恢复计数；持续越界不复制活动告警。数值零有效，缺测不伪装为零。相邻有效样本的采样或首次接收间隔超过30秒，或时间倒退时重新计数；活动告警仍保持活动，不因断流或工作进程重启结束。

同一事实的状态更新、告警发生/结束与消费者完成事实在一个事务内提交；设备锁覆盖状态，`consume` 同时保护单事实幂等。工作进程异常、中断或提交失败不会把未完成效果标为完成。平台接收路径不等待本消费者，也不占用 `aggregate` 与 `current` 的完成记录。命令使用已有受管数据库作用域，正常或异常退出都关闭连接池。

规则发布在同一设备锁内捕获已接收当前序号。新版本只对该边界之后的事实生效；已接收积压仍按原版本条件处理。旧版本边界内有效事实全部完成后，剩余活动告警记录 `rule_changed` 或 `rule_disabled`，时间采用该版本退役时间。数值恢复记录 `value_recovered` 和真实恢复样本。既有已结束告警不被新版本改写。规则结束和数值恢复各自有明确原因；旧版本尚有积压时，页面明确显示等待已接收样本完成。

发布先检查当前权限，事务内按租户、规则、设备顺序取得写锁，再重新授权并建立一致性读快照，避免MySQL默认隔离级别在等待设备锁之前固定旧快照。三库回归在真实接收事务尚未提交时启动HTTP发布，随后提交并释放设备锁，验证新版本边界包括最新序号。MySQL/PostgreSQL还在同一等待窗口内提交活动告警；SQLite因单写者事务模型，在发布后处理旧版本积压，均验证原版本告警正确结束。

## 人工确认、通知与清理

迁移 `017_iot_alarm_notifications` 为原告警增加 `acknowledged_by/acknowledged_at`，建立 `iot_notifications` 读模型，并直接采用 `Outbox\Store::migration()` 的专用 `iot_notice_outbox` 存储。人工确认锁定租户后锁告警，再重新授权；只更新确认字段，活动、恢复样本与结束原因始终由告警规则所有者写入。确认可发生在数值恢复前后；只写一次成功审计，撤权后的重复请求也必须拒绝。审计沿用原有独立保留和查询规则。

`NoticeService` 直接实现既有 Publisher 和 Job 契约，拥有通知的投递与投影行为。每次真实触发及结束都在同一告警事务写入稳定的 `<alarm-id>.triggered/ended` Outbox ID，通知快照保留原租户、规则版本、名称、设备和发生时间。数值恢复、规则变更与停用均发送准确的结束类型；人工确认不伪装为数值恢复。通知创建时间在持久意图首次提交时固定，不随后台延迟或重复交付延长。迁移前的旧告警由带索引的有界恢复批次补齐，持久标记防止Outbox回收后重新生成意图。

通知采用独立的 `notices` 队列，容量10000，队列租约60秒、单次执行30秒、最多10次重试，重试等待1至60秒。后台角色先有界回收已过默认七天保留期的隔离消息、消费队列积压，再领取最多10条Outbox，避免满队列时无法释放容量；返回 `quarantine_collected` 供观察。Queue确认删除自己的条目，不影响历史、聚合或告警独立完成事实。数据库投影及消费凭据同事务，进程中断整笔回滚；重复消息通过锁定Outbox的消费事实跳过副作用。已发布却60秒仍没有消费凭据的意图，由公开 `Store::replay()` 恢复同一身份；重试耗尽或Redis丢失不会丢弃数据库意图。通知已经到期时登记过期消费事实，不再重建读模型。已经完成的Outbox采用组件默认七天回放保留清理，未完成效果继续保留对账。

告警从 `ended_at` 保留180天，仍活动的旧告警以及刚结束的旧告警都不按触发时间清理。通知从自己的 `created_at` 保留180天，不外键级联依赖告警；关联告警先过期时仍显示原通知版本和结束原因，并注明告警保留期已过。每类清理最多100条，按到期时间和身份选取，逐项条件删除，中断后继续调用即可恢复。查询立即排除已到期通知，旧重复队列消息不能恢复已清理通知。规则版本与审计不由本清理命令删除。

Web告警中心和详情分别显示人工确认与活动/结束状态，确认动作等待Promise，保存中禁止重复和关闭，失败保留详情并允许重试。通知入口采用相同筛选、列宽、分页及主题，跳转已有告警详情。读取通知、跳转详情和确认分别重新校验当前租户权限，原租户历史不跟随设备当前归属移动。

## 验证与边界

`tests/iot-alarms.php` 挂接既有身份/租户真实 HTTP 装置，使用真正接收服务准备事实、实际 `iot:alarm` 子进程消费。三数据库测试覆盖阈值等号、回差灰区和退化零点区间、三次触发与恢复、30/31秒间隔、缺测、实时窗口内外及端点、同秒顺序、38位序号、首次规则生效边界、版本变更积压、停用、跨租户/当前权限、分页、重启与两个消费者争用后杀死其中一个再恢复。

```sh
php tests/iot-identity-databases.php --php "$TYPE_MYSQL_TOOLS" "$TYPE_PGSQL_TOOLS" --alarms
php vendor/bin/type docs/build-config/type-app.json
php tests/iot-identity-databases.php build/app/type-app "$TYPE_MYSQL_TOOLS" "$TYPE_PGSQL_TOOLS" --alarms --no-source
pnpm --dir web --filter @vben/web-antd typecheck
pnpm --dir web --filter @vben/web-antd build
php tests/iot-history-browser-fixture.php build/app/type-app 18191 --alarms --notices
node tests/iot-alarms-browser.mjs <本轮fixture.json> http://127.0.0.1:18192
```

设备转移、切换物模型、退役的统一结束策略由相应生命周期任务集成；当前规则范围固定，不能通过编辑重绑定历史规则。未运行 Linux/Windows 原生验证、真实 MQTT 网络链路、同步持久集群故障切换或万台设备容量压测；本机功能通过不等于这些门槛通过。
