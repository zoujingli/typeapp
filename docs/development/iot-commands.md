# 设备指令与未知结果对账

指令处理复用设备执行与结果对账机制。设备详情、发布物模型、同步接收工作进程、精确上下行 Topic、MQTT5 客户端及设备 SQLite 缓存沿用原实现。`CommandService` 拥有指令事实，`RoleService` 检查独立动作权限，`IdentityService` 恢复准确来源会话。全新应用的指令、逐次传输证据和清理索引随 `app:install` 初始化；不提供旧支持身份或旧业务数据转换。

## 平台受理与查询

`POST /customer/tenants/{tenant}/devices/{device}/commands` 需要 `customer.commands.create`。JSON 字段为 `command_id`（32位小写十六进制）、`identifier`、对象 `values` 和准确设备 `version`。在同一事务重验当前客户或模拟会话、租户权限、设备版本、启用与有效在线观察、绑定模型和参数，保存原始指令并递增设备版本后返回201。离线或连接未知返回409 `command_device_not_online`，陈旧版本返回409 `stale_version`，物模型非法返回422和字段路径。

调用方每个新动作生成一次 `command_id`。同一准确来源会话对同租户、设备、指令、参数及原设备版本的相同 ID 再次请求，只返回保留期内已有记录；不重新创建动作或延长期限。账号相同但会话、普通/模拟来源或内容不同均返回409 `command_identity_conflict`。浏览器在发送前按身份、租户和设备保存原ID及完整参数；响应丢失或刷新后继续确认原受理。创建另一个 ID 是另一个动作；不能在记录保留期结束后复用旧ID发起新动作。

同路径 GET 需要 `customer.commands.read`，只读取已有事实，不联系设备，也不捆绑设备资产查看权限。按 `accepted_at DESC, id DESC` 分页，每页默认20、最多100。平台受理时间 `accepted_at` 与 `deadline_at=accepted_at+60` 固定；`dispatch_at` 表示首次发送被持久领取，`mqtt_at/mqtt_reason` 表示首次取得的 Broker PUBACK，`device_received_at` 与执行状态来自合法设备回执。每条指令带最近20条时间线，全部逐次证据可按下述查询入口分页。没有收到 PUBACK 时保持发送结果未知，不能推定未送达。

执行状态为 `pending/cancelled/not_dispatched/succeeded/failed/rejected/unknown`。无确定停止事实且第60秒尚无执行证据时，读取投影为 `unknown`；不会写入假失败。迟到且匹配原动作的确定结果可以补全未知，相互矛盾的终局结果不能覆盖。原指令、当前设备及归属需一致，回执中的开始时间必须在原受理时间至截止之前。

指令及逐次记录持久保存真实管理人员、有效客户、租户、准确会话和模拟来源。新的发送、重投及手动查询领取时重验该会话和当前角色，绝不换用同账号的另一会话。尚无发送领取且已撤权的指令持久记为 `not_dispatched/source_permission_revoked`；到期未领取记为 `not_dispatched/deadline_elapsed`。已领取可能产生外部效果，保持实际未知或设备结果；自动结果对账及迟到回执继续运行。每次人工查询的领取、传输和回应审计使用该次查询来源，不能误用原指令创建者。

`POST /customer/tenants/{tenant}/devices/{device}/commands/{command}/cancel` 独立要求 `customer.commands.cancel`，提交稳定 `cancel_id`。只取消从未领取发送的指令；一旦可能送达返回409 `command_already_dispatched`。相同取消ID与来源返回原结果，成功状态为 `cancelled`，不会承诺撤回已经发生的设备效果。普通平台资产身份不能调用这些客户动作；模拟身份也不会合并平台权限。

`GET /customer/tenants/{tenant}/devices/{device}/commands/{command}` 按原ID读取单条结果，权限为 `customer.commands.read`，返回 `data` 中与列表相同的受理、执行与未知原因字段，不附带时间线。服务端同时限定租户、设备和指令身份；无记录或归属不匹配返回404 `command_not_found`。该读取不创建查询或发送意图，适合持续观察已翻出最近100条列表的旧未知指令。完整传输时间线继续使用 `/queries` 分页入口。

## 固定重投、查询和回执

`iot:ingest` 复用同步持久工作进程边界接收上行，每秒尝试领取一个到期节点。发送节点固定在受理后0/10/30秒，最多三次含首次；只在原设备归属、模型、启用及有效在线观察一致，没有执行证据且未到原截止时发送。每次都使用原报文和原ID，MQTT5 Message Expiry为剩余原期限。已开始但结果未知的动作不重投。

60秒无终局结果时保留未知，在60/120/300秒的节点查询设备保存的原结果，查询有效交付期30秒。调度中断恢复时，仅处理当前所处节点，错过的旧节点记为 `schedule_missed`，不集中补发；当前节点离线或已知终局时记录跳过。领取成功但网络失败、提交未知或进程退出不重新领取同一节点；后续原节点仍可执行。领取记录只能证明持久的发送意图；实际取得的PUBACK、传输异常和设备查询回应各自保存，不将无证据的网络字节写成已送达。

`GET /customer/tenants/{tenant}/devices/{device}/commands/{command}/queries` 按时间和ID分页读取全部证据，权限为 `customer.commands.read`。同路径POST接受32位小写十六进制 `query_id`，独立要求 `customer.commands.query`；仅在创建满300秒、未超过180天保留期、结果未知且原设备在线时受理。相同准确来源与查询ID只返回原查询，换会话重用ID返回409 `command_query_identity_conflict`；待发查询只保留一个，近期请求有10秒间隔。202仅表示请求已保存。页面隐藏操作不能替代服务端授权；仅有查询或取消权限时可用准确指令标识操作，无须获得列表读取权限。

| 消息 | 字段 |
| --- | --- |
| 下行 `command` | `app_version=1,type,command_id,device_id,ownership_id,model_version,identifier,values,issued_at,deadline_at`。完整 JSON 最多16KiB。 |
| 上行 `command_receipt` | `app_version=1,type,device_id,ownership_id,command_id,content_hash,status,code,started_at,finished_at,result`。result 必须为对象，最多4096字节。 |
| 下行 `command_receipt_ack` | `app_version=1,type,device_id,ownership_id,command_id,result_hash`。匹配当前回执摘要才停止设备补发。 |
| 下行 `command_query` | `app_version=1,type,device_id,ownership_id,command_id,content_hash,query_id`。查询不携带新动作参数，不调用执行入口。 |
| 上行 `command_query_result` | 原查询身份字段，`type=command_query_result`，加 `receipt`：完整已有 `command_receipt` 对象或null。null表示没有保留结果，不能证明未执行。 |

摘要复用 `IngestionService::contentHash()` 的精确数字与对象键规范化，不能换为原字节 SHA-256。合法回执在当前归属的受控上行接收，每次回执确认前都写入新的同步证明 nonce；重复结果也必须取得覆盖本次响应的持久证明。协议 PUBACK 不会清理设备执行结果。

查询回应须匹配已领取的查询ID、原指令内容摘要与原归属。已有结果经过同一个执行回执入口校验和确认；长动作的合法迟到成功/失败可补全未知。无结果查询只更新查询观察，原执行状态仍未知。`DeviceBuffer::queryCommand()` 只读本地原结果并安排已有结果补发，不检查动作资格、不创建去重记录、不调用执行者，即使没有可信时间也能对账。

## 保留和有界清理

`iot:command-clean [batch]` 清理创建满180天的指令和逐次证据，默认与最大预算1000。预算是每次实际删除的指令与证据记录总数；证据多的指令可分多轮完成。每轮事务独立提交，无外部清理游标，失败回滚后可重跑。审计按独立保留规则保存原指令、设备、归属、人员、内容摘要、原截止及每次领取、协议响应和查询观察。清理追加 `command.retention_expired`，缺确定结果仍记 `unknown`，不会调用设备、重建指令或伪造执行完成。以 `has_more` 驱动后续有界调用；未知提交按既有数据库运维边界核验，不能直接当成已完成。

## 设备动作与可信时间

正式设备入口增加 `iot:device listen`，沿用[模拟器配置](iot-device-simulator.md)。本轮保持连接至60秒或收到停止信号，处理原有补传及指令；下一轮由调用者显式启动。CLI 的动作是明确的模拟结果 `{simulated:true,identifier:...}`，不代表真实硬件已接通。应用可给 `DeviceSimulator` 的最后一个可选构造参数提供 `Closure(string $identifier, stdClass $values): stdClass`，由驱动执行实际动作并返回确定结果；异常可能发生在动作之后，保持原未知记录。

监听的TLS、认证与持久会话恢复共用15秒连接预算，仍受本轮60秒总截止约束。连接后发送含随机32位 nonce 的 `time_request`，精确匹配 `time_response` 的设备、归属及一次性 nonce。往返不超过15秒时，以平台秒时间加完整往返和一秒粒度裕量作为保守上界；单调时钟推进，观察有效30秒，每20秒重新挑战。断连、重启、超时或异常响应清除信任。设备本地墙上时间不作为开始控制的依据。

`DeviceBuffer::beginCommand()` 在动作前检查精确身份、绑定模型、原60秒期限、可信时间及持久去重；同一同步 SQLite 事务先保存未知结果，只有返回 `execute=true` 才允许一次动作。模型不符、过期或失去可信时间持久保存明确拒绝，不开始动作。重复 ID 仅返回已保存结果，内容变化拒绝；掉电后留下的未知不再次执行。动作返回后 `completeCommand()` 同步保存实际结果；长动作完成时间根据开始时的保守上界及单调持续时间推进。

## 页面和验证

设备详情使用公共抽屉及按发布模型生成的参数控件，分别展示受理、MQTT交付、执行结果、未知原因及分页时间线。失败保留输入；受理响应未知时锁住原参数，使用同一ID确认原受理；主动查询响应未知时同样保留原查询ID。只读成员只看已有结果。离线、连接未知或刷新失败禁止新控制。亮暗主题、窄屏、长内容和刷新/权限行为沿用设备详情标准。

仓库根执行（外部工具通过既有环境变量定位）：

```sh
php tests/iot-ingestion.php all
php tests/iot-ingestion.php all --native
php vendor/bin/type docs/build-config/type-app.json
php tests/iot-device-mqtt.php --php --ingestion
php tests/iot-device-mqtt.php build/app/type-app --ingestion --no-source
```

`--control-only` 运行正式设备缓存、指令及对账专项，报告标明范围；不替代完整标准客户端及未知同步提交故障验收。另以MQTT.js参考执行者故意丢弃回执，将一次动作和原结果fsync后由装置SIGKILL，下一独立进程读取同一账本，拒绝重复动作，等60秒查询才返回原结果；正式原生模拟器同时验收缺失结果查询不会新建动作。整套装置总预算480秒，包含新增的真实等待；标准客户端180秒、各网络交换预算及业务60秒期限没有因此放宽。`tests/iot-history-browser-fixture.php <产物> <端口> --commands` 配合 `tests/iot-commands-browser.mjs <fixture.json> <Web地址>` 使用真实授权API与受控历史事实验证页面、同ID丢响应恢复和并发确认；MQTT在线与动作证据另由真实TLS套件提供。设备动作次数、跨重启去重、迟到结果、三库语义、全量AOT和无源码运行分别记录，模拟器成功不替代实物设备和全系统容量验收。
