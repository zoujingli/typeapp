# 设备转移审批、冻结与正式切换

源管理员申请，目标管理员接受或拒绝；接受后冻结新控制，设备持久冻结新采样和新动作，继续原阶段缓存补传与指令对账。条件齐备仍为 `frozen`；目标管理员明确推进后，先隔离旧授权，再激活新归属，设备取得新阶段业务确认后解冻。

`TransferService` 继续拥有双方审批、冻结调度和排空条件，复用 `RoleService` 当前客户权限、`ProductService` 自有产品与发布、`ModelDefinition` 结构摘要、`DeviceService` 生命周期与连接观察、`CommandService` 对账。`TransferController` 仅负责新客户入口的严格输入和响应，可靠采样继续由 `DeviceBuffer` 和原接收入口处理。

## 管理入口

路径以 `/customer/tenants/{tenant}/transfers` 为根，需要有效客户或模拟会话及相同 `X-Tenant-Id`。固定节点 `customer.transfers.read/request/accept/reject/cancel/retry/switch` 分别授权；写操作均携带准确设备 `version`。

| 入口 | 输入与结果 |
| --- | --- |
| `GET /` | `direction=source/target`、`status=requested/rejected/cancelled/frozen/isolating/activating/completed`、可选设备标识及有界分页；只含本方发出或收到的邀请。 |
| `POST /` | 源方提交稳定 `transfer_id/device_id/target_tenant_id` 和设备 `version`，HTTP 202 返回原申请。身份均为32位小写十六进制。 |
| `GET /{transfer}` | 双方成员查看邀请、源模型、审批与排空观察；读取不联系设备。 |
| `POST /{transfer}` | 目标方以 `action=accept/reject`、稳定 `decision_id` 和设备 `version` 接受或拒绝，HTTP 202 返回原决定。 |
| `POST /{transfer}` | 源方以 `action=cancel`、稳定 `decision_id` 和设备 `version` 取消尚未审批的邀请；接受后不能取消解冻。 |
| `POST /{transfer}` | 任一方以 `action=retry` 和设备 `version` 明确重发原冻结请求；不解除冻结或变更目标。 |
| `POST /{transfer}` | 目标方以 `action=switch`、稳定 `switch_id` 和设备 `version` 推进原转移；HTTP 202 表示实际持久阶段，不等于已完成。 |

接受时选择 `target_product_id/target_model_version` 对应的目标已发布模型，或者以最多100字符的 `copy_name` 在同一审批事务内复制源定义并发布为目标自有产品版本。结构校验包含类型、单位、范围、枚举等，不能以同名产品或版本号代替。

申请ID固定源设备、双方租户、原设备版本及真实操作者；决策ID固定操作者与完整内容。普通和模拟来源不能相互复用，响应丢失后以原ID、原内容确认受理，不重复复制产品。相反或不同决策返回409；接受后不能用拒绝或取消解冻。拒绝或取消未决邀请释放设备当前转移标识并递增设备版本。详情提供 `device_version`；没有设备查看权限时可显式输入已获知的准确版本。

各动作只要求对应转移节点，`customer.transfers.read` 独立提供邀请查询。源方不能代目标审批，平台资产身份不继承租户业务权限，模拟会话只采用目标客户当前权限。复制产品及发布模型还须取得对应产品和模型权限；选择已存在的目标版本则不隐含复制授权。邀请不开放目标对源设备、凭据、指令载荷或历史的查询权限。

管理事务先锁当前授权，再按租户ID排序取得双方写锁，最后按设备、转移记录的顺序锁定并重读当前事实。邀请身份在事务外预查，事务内重新核验准确会话和权限，避免MySQL锁等待后沿用旧快照。新控制和设备回执使用一致的设备锁顺序；并发时要么先保存原指令并成为待对账条件，要么在冻结后明确拒绝。写入及异步审计保留有效客户、真实管理来源和原会话。

## 设备冻结与排空

设备须已启用、凭据有效且无待撤权或待模型切换。申请后阻止新的模型切换，接受前正常控制继续。接受后 `transfer_frozen=1` 拒绝新控制和旧指令动作重投，原指令结果查询及回执继续。管理员仍可停用异常设备，转移随之显示不可用。

接收角色复用原同步 `command_claim` 事务同时领取冻结、模型和指令，每次最多一个冻结消息。接受后60秒内最多自动三次，预期0/10/30秒；离线轮询不跨过截止。失败和断线保留原意图，明确重发距上次发送至少10秒，每条总尝试有硬上限。

获准下行 Topic 的 MQTT5/QoS1 `transfer_freeze` 含 `app_version=1`、`device_id/ownership_id/transfer_id/source_model_version/target_tenant_id/target_product_id/target_model_version/structure_hash`。设备在SQLite FULL事务同时保存原请求、摘要和冻结时最后序号；重复无副作用，不同内容拒绝，重启后仍停止新采样。冻结前已开始的动作保留原未知或结果，后来到达的新ID保存 `rejected/device_transfer_frozen` 回执，旧ID只恢复原结果，不再次执行。

旧采样的字节、序号和归属不变，仍以 `ingestion_receipt` 清理或进入原终局异常；原指令仍用 `command_receipt_ack` 对账。MQTT PUBACK、状态观察和HTTP成功均不能替代业务确认。

设备通过 `IOT_DEVICE_SUPPORTED_MODELS` 中目标版本对应的结构摘要明确支持。`transfer_status` 在FULL事务分配共享递增序号，含固定 `transfer_id/ownership_id/model_version/structure_hash/boundary_sequence` 及 `supported/pending_count/pending_command_receipts/unresolved_commands/model_pending`。设备收到冻结后和连接循环每10秒报告，结束前再报告；观察不进可靠采样队列，发送失败下次重报，不取消冻结。

平台要求原设备、归属、源租户、模型、冻结意图与下行发送事实匹配。首次边界不能排除已接受采样，以后不得改变边界；之后新增序号采样以 `transfer_frozen` 永久拒绝，旧采样按原模型和时间规则接收。更大状态序号才更新观察；同序号异内容拒绝，迟到旧状态不回退数据或刷新时间。同步证明后才发 `transfer_status_ack`，它不清采样、不解冻、不表示归属切换。

## 待处理与持久性

详情只有在以下事实同时成立时显示 `ready_for_switch=true`：原归属仍启用、在线、凭据有效且无待撤权；设备确认未满60秒、支持目标、无待模型回执；缓存为空、设备动作与回执无未决；平台无未终局或未知指令。列表只显示审批状态，详情读取动态条件。正式切换必须在自己的事务中重核，HTTP读取不是持久授权令牌。

原指令载荷和时间线按180天清理时，未知效果不能变成“已处理”。`CommandService::prune` 在同事务保存最小 `command_id/device_id/ownership_id` 阻塞身份；明确取消或未下发的指令不产生未知阻塞。该记录无载荷与结果，当前不自动消解；普通清理和重启不能解除未知阻塞，也不能恢复已丢弃的历史。

全新应用通过 `app:install` 建立审批与观察表，设备继续由自己的SQLite模式保存持久冻结；不提供旧业务数据迁移。双方审批及首次设备冻结分别记录脱敏审计。源历史、凭据和产品关联不提前变更，复制只产生目标自己的模型。

## 正式归属切换（）

`frozen → isolating → activating → completed` 分别表示冻结待处理、旧授权隔离中、新归属已激活但设备待确认、设备已确认完成。目标管理员首次推进在双方租户/设备锁内重核最新排空、指令对账、已发布目标结构和60秒内设备确认；任何缺失返回 `transfer_prerequisites_pending`，不会撤销凭据。相同 `switch_id` 继续原阶段，不重新创建归属；不同ID拒绝。

进入 `isolating` 的同一事务立即吊销精确旧凭据，复用设备授权失效意图。Broker通过 `session_terminate` 清除旧会话、订阅和待交付，并等待原节点持有的网络及工作进程隔离；跨节点 `waiting` 未消除时不能写完成。旧阶段的在途/排队/保留载荷只能保留原Topic和归属，业务入口在隔离开始后拒绝该阶段所有采样；目标凭据和Topic均未产生。

再次推进只有读取匹配的隔离完成证明后，才在同一事务关闭原 `iot_device_ownerships` 阶段、生成新阶段、更新租户/自有目标模型并生成新凭据。旧实时投影和连接观察清空，原始事实、分钟聚合、指令、审计和既有导出快照保留源租户。历史及指令读取每次重验当前成员和所选历史归属；导出下载沿用不可变租户快照与当前下载权限。切换不转让旧下载链接。

激活响应包含一次性 `credential` 和无秘密的 `provisioning`。普通查询及重复激活不再返回密码；密码丢失只能在当前目标设备管理入口明确轮换，等待原凭据撤权后继续使用同一归属。`activating` 阶段禁止生命周期变更和新控制，允许必要的凭据轮换/吊销；不会因为超时恢复源授权、退回源归属或强制完成。

设备部署方经受控渠道交付新配置和凭据。`iot:device transfer` 从标准输入读取 `provisioning` 对象，复用原SQLite缓存及 `IOT_DEVICE_SUPPORTED_MODELS`：只有原冻结身份、目标模型支持、采样缓存排空、所有指令与模型回执已对账时才在FULL事务切换本地身份，保存固定 `transfer_activated` 回执。旧终局异常和已对账结果字节不变，序号继续递增；不存在批量改写旧缓存归属的入口。配置响应丢失可带同一配置、原或新阶段标识重试，其他身份仍拒绝。

完成本地配置后，运行设备角色时使用新租户、阶段和凭据；新阶段Topic周期重发持久 `transfer_activated`。平台同步保存 `completed` 后发送 `transfer_activated_ack`，设备核对完整身份才解除本地采样/动作冻结。确认丢失、设备或平台进程重启均继续同一回执；旧Topic、旧归属、不同转移或结构不能完成新阶段。

公开服务/HTTP装置允许准备受控的设备状态和隔离完成回调，只证明业务状态机、鉴权、事务与页面消费；真实TLS专项使用两个Broker节点、标准客户端及正式设备缓存验证隔离与业务确认。暂停的旧节点仍持有屏障；测试实际停止调度、确认子进程退出、硬停止所拥有节点后，才以 `iot:mqtt-fence` 登记这次已完成的基础设施隔离。该入口不执行基础设施隔离，也不能以节点心跳超时代替证明。

新应用的 `iot:mqtt-fence` 与 `iot:mqtt-fence-result` 复用平台域操作账本和审计：完成时保留同一操作的 accepted、executing、completed 三条事实，只读对账返回原事件而不重复审计。IoT隔离登记只隔离持久节点，`observation_isolated=false`，不表示已经清除同名资源观察。硬退出后立即重启仍可能被 `broker_resource_node_active` 拒绝；监督者应在原资源观察的15秒有效期自然结束后恢复，并核对持久节点、资源观察和运行代次一致。故障装置遵守这个边界，不删除观察记录或缩短生产保护。

接收角色的网络或协议失败遵循既有监督契约：进程退出，由监督者以同一实例标识恢复原持久会话，业务账本对账原交付。故障装置通过真实同身份MQTT会话接管触发退出；恢复必须确认原会话ID、新网络所有者和递增运行代次，再继续后续阶段。一次诊断构建或未触发恢复的正常运行不能充当这条恢复路径的生产验收。

## 页面及验证

转移目录复用公共筛选、列宽计算、行操作和抽屉；详情显示真实原因和设备确认时间。五秒轮询在隐藏或退出时取消，失败保留上次事实并可刷新。未定受理的原ID及内容按租户保存在本标签页会话存储，刷新后重新进入同租户可恢复；服务端重新校验双方身份。

```sh
php tests/iot-ingestion.php all
php tests/iot-ingestion.php all --native
php vendor/bin/type docs/build-config/type-app.json
php tests/iot-identity-databases.php build/app/type-app "$TYPE_MYSQL_TOOLS" "$TYPE_PGSQL_TOOLS" --no-source --products --devices
php tests/iot-device-mqtt.php build/app/type-app --no-source --ingestion --transfers-only
php tests/iot-device-mqtt.php build/app/type-app --no-source --ingestion --transfers-only --transfer-switch --transfer-faults
php tests/iot-identity-databases.php build/app/type-app "$TYPE_MYSQL_TOOLS" "$TYPE_PGSQL_TOOLS" --no-source --products --devices --exports --export-transfers-only
export TYPE_NATIVE_PHP_INI="$PWD/build/app/compiler/runtime-profile/native.ini"
php tests/iot-history-browser-fixture.php build/app/type-app 18131 --transfers
node tests/iot-transfers-browser.mjs build/<本轮装置>/fixture.json http://127.0.0.1:15131
```
