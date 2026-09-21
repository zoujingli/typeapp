# 业务接收与设备当前数据

本文描述接收事实、当前投影、设备详情读取及实时新鲜度。设备注册、连接观察及 Topic 约定沿用[设备说明](iot-devices.md)。

真实 MQTT 上报到客户页面专项入口和观察边界见[历史查询验证](iot-history.md#可复现验证)。该专项分别观察协议确认、持久接收回执和获准页面数据，不替代原补传、容量和跨故障域任务。

## 接收事实的边界

转移冻结期间旧阶段缓存仍沿用本入口和原业务回执。设备确认固定冻结序号边界后，更新序号的新增采样永久拒绝为 `transfer_frozen`；设备排空观察的接收、排序和同步确认见[转移说明](iot-transfers.md)。

正式切换进入旧授权隔离后，旧阶段所有采样均拒绝；新归属激活但设备尚未确认时也不接受新采样。`transfer_activated` 经精确新阶段Topic和持久转移记录核对后同步保存完成事实，再发业务确认；随后才接受新阶段新数据。原始/聚合和导出数据始终使用原租户与归属快照。

`IngestionService::accept` 接受已认证、已完成 Topic 授权的 MQTT 5 设备报文，调用者必须先开启数据库事务。它校验 QoS 1、16 KiB 业务包上限、当前租户与归属阶段、生命周期、绑定模型、类型和值域、48 小时补报窗口及最多 5 秒未来时钟偏移。

业务版本为 `app_version: 1`，类型为 `telemetry` 或 `event`。稳定业务身份由设备、归属阶段和 `sequence` 组成。`sequence` 是 1–38 位正十进制字符串，不能经浮点或 JavaScript 数字转换；`sampled_at` 为 UTC Unix 秒。`values` 是按绑定模型解释的对象，事件另带 `identifier`。规范化 JSON 的键顺序和等价数值形式不改变内容身份；重复对象键会被拒绝。

实时事实由同一个 `advance` 在接收事务内维护。只有首次接受、推进当前遥测序号，且采样时间在首次接收前 30 秒至后 5 秒闭区间内，才更新 `realtime_sequence`、`realtime_sampled_at` 和 `realtime_received_at`。较大的旧补传序号仍可推进当前字段，但保留同归属阶段及模型此前的实时事实；重复、事件和乱序不刷新实时事实。切换归属或模型后，首条旧补传建立当前值时将三个实时字段设为 `null`，不能继承上一阶段或模型的状态。实时事实与接收账本、当前字段同事务回滚。

新应用通过 `app:install` 初始化完整结构，包含三个可空实时字段；不转换旧账号或业务数据。首次符合条件的新遥测到来前，不依据接收时间追认实时事实。

`pending` 和 `consume` 为后续历史、告警等消费者提供独立进度。消费者的数据库效果与完成事实同事务提交；失败回滚，另一消费者不受影响。需要跨系统效果时先在同事务保存 Outbox，不能把网络发送放进 `consume` 回调。

`accept` 返回业务回执候选，不是网络发送或同步持久成功证明。每次可回执候选写入新的 `receipt_proof_nonce`；MQTT 调用者必须获取覆盖该写入的同步持久证明，之后才可发送业务回执。本文的 HTTP/Web 验收不替代同步复制、故障切换及真实 MQTT 网络回执验收。

## 真实接收角色

`iot:ingest` 是独立长驻消费者；`iot:ingest-store --store-worker-pipe` 是仅由应用启动、通过 Swoole PROC hook 管理的进程管道接入的有界持久工作角色。先按[设备说明](iot-devices.md)完成应用迁移、同步PostgreSQL配置和 `iot:mqtt-install`，再运行Broker及消费者。两者使用同一版本编译产物，`IOT_MQTT_COMMAND` 仍是应用入口的JSON参数数组；生产不能改用PHP源码入口。

消费者复用组件 `Client`，通过MQTT 5/TLS订阅 `$share/ingestion/iot/+/devices/+/epochs/+/up`，QoS 1、Keep Alive 30秒、会话期限86400秒。实例名由 `IOT_INGESTION_INSTANCE` 指定，形成稳定Client ID `iot-ingestion-{instance}`；同一实例重启沿用原名，并发实例各用不同名字。连接位置、CA及证书名分别由 `IOT_INGESTION_HOST/PORT/CA/PEER_NAME` 配置，CA相对 `APP_BASE_PATH` 解析，强制校验证书。

服务凭据独立于设备：用户名为 `service:ingestion:{credential_id}`。部署时安全生成32位小写hex凭据ID和64位小写hex密码，ID注入双方的 `IOT_INGESTION_CREDENTIAL_ID`；密码只注入消费者的 `IOT_INGESTION_PASSWORD`，其SHA-256验证值只注入Broker的 `IOT_INGESTION_SECRET_HASH`。配置不入构建、版本库或日志。轮换使用新ID并重启所有Broker与消费者；当前不提供运行中热撤销。服务身份不能激活设备、伪造设备连接观察或冒用设备Client ID，只能消费上报并向当前启用设备的当前归属阶段发送下行。

`IngestionWorker` 每次只处理一个交付和一个持久工作。受控事务同步保存账本、事实及投影；仅在得到 `committed/released` 后发布独立QoS 1业务回执，并在该发布取得Broker PUBACK后才确认原交付。这个PUBACK只证明回执已由Broker接管，仍不能证明设备已收到。提交未知、网络失败或远端清理不能确认时进程退出，原持久交付继续保留，由监督者以原实例名恢复；停止会取消网络等待并完成精确后端清理。

业务载荷上限为完整16 KiB，客户端报文接收能力为1 MiB且有界；超业务上限的交付被拒绝，不缩小Broker二进制协议边界。合法业务永久拒绝经同样的同步边界发送 `rejected` 回执。终止时输出脱敏的接收、接受、拒绝、丢弃、确认与受管资源计数；不输出原件、内容摘要、密码或数据库连接值。

## 设备详情契约

`GET /customer/tenants/{tenant}/devices/{device}/current` 在 `data` 返回以下形状。请求使用客户或模拟登录 Bearer 令牌和一致的 `X-Tenant-Id`。`IngestionService::current` 独立检查当前成员的 `customer.telemetry.read`，不要求设备资料读取权限；无成员权限返回 403，其他租户或不存在的设备返回 404。平台资产身份不具有遥测读取权限。

```json
{
  "device_id": "32位设备标识",
  "ownership_id": "32位当前归属阶段标识",
  "model_version": 1,
  "sequence": "10000000000000000000000000000000000000",
  "sampled_at": 1789310000,
  "received_at": 1789310002,
  "freshness": "fresh",
  "realtime_sequence": "10000000000000000000000000000000000000",
  "realtime_sampled_at": 1789310000,
  "realtime_received_at": 1789310002,
  "fields": [
    {
      "identifier": "temperature",
      "value": 24.5,
      "sequence": "10000000000000000000000000000000000000",
      "sampled_at": 1789310000,
      "received_at": 1789310002
    }
  ],
  "last_receipt": {
    "sequence": "10000000000000000000000000000000000001",
    "status": "rejected",
    "code": "invalid_model_values",
    "received_at": 1789310003
  }
}
```

没有当前遥测时，顶层序号和双时间均为 `null`，`fields` 为 `[]`。查询只连接当前归属阶段及绑定模型的投影；模型改变后旧模型字段不会被解释为新模型字段。

设备确认模型切换后，同一版本再次绑定也有新的序号起点。当前值和状态观察仅连接`sequence > model_start_sequence`的本次区间，不复活旧区间字段；首条部分上报从空当前字段开始。迟到旧版本仅在已确认结束的绑定区间内进入历史，不推进当前。精确边界、结构摘要与管理员协议见[模型切换](iot-products.md#设备确认切换版本)。

`freshness` 由服务端在这次 SQL 读取中判断：没有当前遥测为 `empty`；已有当前值但没有有效实时事实，或距最近有效实时首次接收已达 60 秒，为 `stale`；否则为 `fresh`。三个 `realtime_*` 字段独立于当前值的序号及双时间，没有有效实时遥测时均为 `null`。判断时间使用服务端 UTC Unix 秒，客户端只负责按用户时区显示时间，不使用浏览器时钟重算状态。该状态独立于连接离线、Broker 未知及页面刷新失败，也不证明每个可选属性都在近期上报。

`last_receipt` 为当前租户、设备及归属阶段的最新**首次接收账本记录**，无记录时为 `null`。按 `received_at DESC, message_id DESC` 稳定排序，接受与拒绝均可出现。它独立于当前遥测，所以最新事件或拒绝报文不会覆盖当前属性。旧消息重试不改变排序，后续同身份异内容冲突仍保留原账本结论，冲突原因在审计中查看。

`last_receipt.received_at` 是首次平台接收 UTC Unix 秒，不是重试时间、回执发送时间或设备确认时间。这里的 `status` 只表示平台保存的业务接收结果，**不能推断设备已经收到业务回执**。HTTP 不返回内容散列、同步证明随机值、凭据或原始业务载荷。

当前字段、独立实时事实、新鲜度与最新接收账本通过一条带设备、租户、归属条件的 SQL 读取，避免两次读取在归属切换中拼接不同阶段。沿用账本现有设备时间索引；大规模历史归属记录下的查询容量没有在本子路径声称达标。

## 页面行为

设备详情复用既有五秒轮询：请求完成后再计时，隐藏页面暂停并取消请求，恢复可见立即读取，离开或切换租户清理请求和数据。读取失败保留上次数据与读取时间，并显示警告；403 清理租户上下文，404 清理设备资料。

当前属性按设备绑定模型展示名称、标识、类型、单位、原始标量值、每字段双时间及业务序号。字符串带引号，布尔值和数字零不当作空值，尚未上报的属性明确标注。页面保留原有连接状态；当前数据卡片显示服务端新鲜度及最近有效实时序号和双时间，失败时仅标注上次读取数据，不把旧响应当作本次服务端判断。

平台接收结果显示本阶段最新首次接收序号、接受/拒绝、中文原因和时间，并明确说明没有设备回执接收证明。页面在同一刷新周期内按各自权限读取设备资料、当前数据和指令，不因缺少资料权限阻断获准遥测。宽表复用统一横向滚动，长文本按单元格换行。

## 已执行验证

```sh
php tests/iot-ingestion.php all
php tests/iot-identity-databases.php --php "$TYPE_MYSQL_TOOLS" "$TYPE_PGSQL_TOOLS" --devices
php vendor/bin/type docs/build-config/type-app.json
php tests/iot-identity-databases.php build/app/type-app "$TYPE_MYSQL_TOOLS" "$TYPE_PGSQL_TOOLS" --no-source --devices
```

### 接收角色组合验收

正常路径验证服务身份隔离、遥测/事件、相同内容重复与键重排、异内容冲突、乱序不回退、完整16KiB、模型不匹配、48小时之外、时钟超前及QoS0永久拒绝。真实故障路径先让主库COMMIT进入SyncRep，再暂停备库重放并取消等待：主库记录可见时仍无业务回执；恢复同步复制和稳定消费身份后通过新的写屏障补发回执，首次接收时间保持且原始事实仍恰好一条。停止后网络、待确认交付及受管工作均归零。

## 实时新鲜度验收

```sh
php tests/iot-ingestion.php all
php tests/iot-ingestion.php all --native
php tests/iot-identity-databases.php --php "$TYPE_MYSQL_TOOLS" "$TYPE_PGSQL_TOOLS" --devices
php vendor/bin/type docs/build-config/type-app.json
php tests/iot-identity-databases.php build/app/type-app "$TYPE_MYSQL_TOOLS" "$TYPE_PGSQL_TOOLS" --no-source --devices
```
