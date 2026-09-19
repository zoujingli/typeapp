# 物联网运行概览

双端运行观察复用采样、Broker 公开统计、接入观察及接收工作进程。Web菜单提供双端“运行概览”和“操作审计”，继续使用Vben公共卡片、表格、搜索和详情抽屉。

## 入口与权限

`GET /admin/operations` 要求当前平台身份具备 `admin.operations.read`，拒绝租户头和伪造支持头。返回 `scope=platform`、UTC查询时间、最多64项运行采样及有界持久状态，不包含设备标识、Topic、报文、人员令牌或数据库连接值。`coverage=observed_instances_only` 明确表示尚未上报的实例不属于已验证覆盖范围。

所有接口返回 `Cache-Control: no-store`。HTTP服务和MQTT角色使用同一 `IOT_MQTT_COMMAND` 与同步后端配置；该命令仍为当前编译应用的显式参数数组，不能配置生产源码回退。MySQL/SQLite继续支持租户观察，平台持久MQTT状态为 `unsupported`；PostgreSQL未配置工作命令、未安装或本次不能确认时为 `unavailable`，指标为null。

`health.http_alive=true` 仅表示当前HTTP请求成功；`health.durable_store_ready` 仅表达持久依赖的本次同步证明，unsupported为null，未确认为false，available为true。两者均不代表所有Broker或业务角色已经就绪，仍须分别查看节点采样与到期状态。旧 `/iot/operations` 和 `/iot/tenants/{tenant}/operations` 已移除。

## 双端审计与保留

`GET /admin/audit` 合并平台与客户的脱敏事件，需 `admin.audit.read`；详情路径为 `/admin/audit/{admin|customer}/{id}`。`GET /customer/tenants/{tenant}/audit` 及其 `/{id}` 详情只查询当前授权租户，需 `customer.audit.read`。真实操作人、有效客户、租户、执行会话、来源管理会话与模拟标识按事实展示，不从当前页面身份反推历史来源。

查询复用独立Broker的严格筛选、最多100条键集分页和1MiB响应预算。默认20条，支持 `actor_id/subject_id/action/result/from/to/limit/cursor`；阶段事件另支持 `operation_id/stage`。游标绑定当前身份、模拟来源、权限、筛选及页大小；每次列表和详情都重新授权。双表分别先限制到 `limit+1` 再合并，以来源作为相同时间、相同ID后的排序字段。`pending/unknown/failed` 保留原始事实，详情按需读取操作账本的当前状态。

`app:audit-clean <admin|customer> [batch]` 复用180天保留和事务批次，默认1000、最多1000；调用方依据 `has_more` 继续，不在进程内无限循环。中断后重试只清理已到期事件，不删除身份、会话、操作账本或恢复撤销依据。独立 `broker:audit-clean` 和其180天保留不变。旧租户审计HTTP与 `iot:audit-clean` 已移除，无旧应用兼容或数据转换。

## 采样含义与资源边界

迁移 `024_iot_runtime_metrics` 建立固定0–63槽位的观察表和 `(tenant_id,received_at)` 接收窗口索引。每个 `(kind,node_id)` 只保存当前和前一次采样，没有无界时间序列表。每份指标仅含白名单中的非负有限数值/布尔值，序列化最多4KiB；数据库约束保证并发插入不会超过64行。运行身份变化后清空前一采样，迟到旧运行的后续采样不能覆盖新所有者。稳定节点名须由部署者分配并重复使用，全部槽位占用时新身份明确失败，不删除其他运行证据。

Broker每五秒在既有 `DeviceAccess` 心跳工作中附带 `Broker::statistics()` 的快照；不会另开采样守护进程或查询Broker私表。业务接收每五秒在已有单个持久工作进程边界附带计数。计数按当前进程运行累计，间隔由单调时钟计算；第一份观察缺少前值时速率和区间延迟为null。

| 字段 | 含义 |
| --- | --- |
| `received_per_second` | 应用接收角色在前后两份采样间实际取得的MQTT交付数除以单调时间间隔。 |
| `receipt_latency_mean_ms` / `receipt_samples` | 区间内应用取得交付，到持久业务回执发布获得MQTT协议确认的均值及样本数；只计真实完成的回执发布。 |
| `receiptLatencyMaximumMs` | 当前运行中已完成回执处理的最大毫秒数，不是p95。 |
| `deviceConnections` / `serviceConnections` | Broker公开统计中的实际设备与服务连接数；不会用数据库会话所有者推导在线连接。 |
| `store.metrics.pendingMessages/pendingBytes` | 本次取得同步证明的持久待投递条数和逻辑字节，含已有分类统计和额度。 |
| `connectionQuotaRefusals` / `commitQuotaRefusals` | 本次Broker运行累计的连接配额和持久配额拒绝。 |
| `unknownCommits` / `quarantinedCommits` | 无法确认的提交和无法证明后端清理的累计观察，不代表业务成功。 |
| `processMemoryBytes/processPeakMemoryBytes` | PHP分配器的当前/峰值字节，不能当作操作系统RSS。 |

`latency_scope=application_receive_to_receipt_publish_ack` 不包含此前Broker排队时间，也不证明设备已经消费回执；该均值不能代替入口到设备回执的端到端p95或E1容量门槛。无样本时留空，不把0ms作为默认值。

持久状态复用现有 `PendingCommit → iot:mqtt-store → PostgresStore` 的 `session_statistics/node_statistics`。HTTP进程不直接执行同步MQTT事务；每个工作沿用五秒硬截止、结果未知时精确后端清理。只有两次读取均 `committed && released` 才返回 `available`。节点登记最多32项，保留运行身份、代次、隔离操作人及证据标识；登记状态与实时心跳分别判断。未证明后端清理时，当前HTTP进程保留隔离状态，后续刷新不再创建持久观察工作，API返回 `store.quarantined=true`；运维确认清理后才能重启该HTTP服务恢复观察。不会从本地主库可见记录伪造同步证明。

## 页面状态

服务器15秒未收到仍在运行的节点采样时返回 `unreachable`，明确观察到正常停止则保留 `stopped`。设备在线、已观察到离线和连接未知仍沿用设备服务；实时遥测60秒未推进显示数据陈旧，从未上报显示尚无遥测。

页面可见时每五秒刷新，未完成请求不叠加，隐藏、离开和切上下文会取消请求。读取失败保留上次数据并显示独立错误；旧页面采样到期显示“页面观察已过期”，不会仅因浏览器断网声称服务器节点故障。到期节点不进入当前指标合计，过期持久积压留空。权限拒绝清除已显示的数据和详情。详情保留被选中的具体采样时间和运行身份，过期时提示历史观察。

## 验证入口

```sh
php vendor/bin/type build docs/build-config/type-app.json
php tests/iot-identity-databases.php build/app/type-app "$TYPE_MYSQL_TOOLS" "$TYPE_PGSQL_TOOLS" --no-source --devices --audit --operations
php tests/iot-device-mqtt.php build/app/type-app --no-source --operations --capacity
php tests/iot-device-mqtt.php build/app/type-app --no-source --operations --ingestion --operations-browser
php tests/iot-device-mqtt.php build/app/type-app --no-source --ingestion --transfers-only --transfer-switch --transfer-faults --operations
php tests/iot-device-mqtt.php build/app/type-app --no-source --operations --operations-quarantine-only
pnpm --dir web typecheck
pnpm --dir web --filter @vben/web-antd build --outDir "$TYPE_OPERATIONS_DIST"
```
