# 设备TLS补传模拟器

`app\iot\service\DeviceSimulator` 承担设备网络职责，复用 `Type\Mqtt\Client`、[设备持久缓存](iot-device-buffer.md)及 `DeviceService::topics()`。它固定一台预注册设备及一个归属阶段，读取本地原件发送，通过平台业务回执完成清理。应用启动角色、采样调度、平台系统状态和指令处理在各自调用者中装配，不由此类另建协议或Topic。

## 装配与公开接口

模拟器接收获准 `transfer_freeze` 后在原SQLite FULL事务保存冻结意图和序号边界，停止新采样及新指令动作，原缓存和回执继续排空。`state` 暴露转移标识和固定边界，重启不解除冻结；目标支持、定期排空观察与协议详见[转移说明](iot-transfers.md)。

正式归属激活后的无秘密配置通过 `iot:device transfer` 标准输入交给原持久缓存；设备部署方另行配置新租户、阶段和一次性凭据。只有原冻结和完全排空条件匹配才原子切换身份，保留固定最终确认；`send/listen` 在新Topic重发该确认，取得平台业务确认后才解除采样冻结。相同配置允许本地提交后进程退出的幂等恢复，旧缓存绝不重新标成新归属。

```php
$buffer = new \app\iot\service\DeviceBuffer('runtime/device.sqlite', $deviceId, $ownershipId);
$simulator = new \app\iot\service\DeviceSimulator(
    buffer: $buffer,
    tenantId: $tenantId,
    modelVersion: $modelVersion,
    credentialId: $credentialId,
    secret: $credentialSecret,
    host: $brokerIp,
    port: 8883,
    caFile: $certificateAuthority,
    peerName: $certificateHostname
);
try {
    $record = $simulator->enqueue('telemetry', time(), (object) ['temperature' => 20.0]);
    $result = $simulator->run(30.0, 5.0);
    $status = $simulator->statistics();
} finally {
    $simulator->close();
    $buffer->close();
}
```

身份、模型和凭据来自设备预注册结果，不在代码或命令行写入真实秘密。`host`为明确IP；`peerName`为证书中的名称，留空时验证IP。`caFile`留空时使用系统信任；没有跳过证书验证或明文选项。文件路径按调用进程工作目录解析，启动装配可先按自身配置根转换为绝对位置；缓存父目录必须存在。

设备Client ID为缓存绑定的设备ID，Username为`deviceId:credentialId`，使用MQTT5、KeepAlive30秒、SessionExpiry86400秒及CleanStart=false。上下行沿用 `iot/{tenant}/devices/{device}/epochs/{ownership}/up|down` 的精确Topic。订阅QoS1、RH=2和RAP=1，不订阅通配符、不重放保留消息，拒绝实时带RETAIN的回执。

| 接口 | 行为 |
| --- | --- |
| `enqueue(string $type, int $sampledAt, stdClass $values, string $identifier = ''): ?array` | 以设备本地持久确认的模型同步入队；不依赖连接，可在断网时采样。null是双额度拒绝，未接纳计数持久增加。 |
| `run(float $maximumSeconds = 30.0, float $receiptSeconds = 5.0, ?ProcessSignals $signals = null, bool $listenForCommands = false): array` | 建立一次TLS连接，顺序读取并发送原件，到排空、总截止或停止结束；监听指令时即使原件排空也保持至总截止。返回本次`published/accepted/rejected/stopped/pending`，不自动重连。两个预算都必须大于0且不超过60秒；可选信号所有者由应用创建及关闭，本方法只分发控制事件。 |
| `stop(): void` | 取消当前网络等待并立即释放连接；不改缓存，之后允许显式再次run。可从进程信号处理器调用。 |
| `statistics(): array` | `tenant_id/model_version/running/stopped/closed`，以及已有Client的`network`和缓存的`buffer`观察；没有凭据、原载荷或Topic。 |
| `close(): void` | 幂等永久关闭此模拟器，之后enqueue/run拒绝。借用的缓存仍由调用者负责close。 |

构造与入队、运行不能与另一个同设备模拟器并发共享身份。每个实例独占一个Client；缓存仍由创建它的调用者持有。模型通过下述明确确认协议切换，旧记录继续使用它们原有模型和字节；不能修改归属或删除缓存文件来重置同阶段序号。

## 模型切换协议

`IOT_DEVICE_SUPPORTED_MODELS`是最多1000项的JSON版本到结构摘要映射，默认`{}`不支持任何切换；例如`{"2":"目标模型structure_hash的64位小写hex"}`，示例文字须换成真实摘要。构造函数末尾的`supportedModels`具有同一契约。此清单表示固件已实际具备目标属性、类型、单位、枚举与动作能力；仅复制摘要不能实现单位转换或升级物理固件。`IOT_DEVICE_MODEL_VERSION`只初始化尚未绑定的本地缓存，后续重启以持久版本为准，不被旧配置倒退。

所有消息保持`app_version=1`、MQTT5/TLS、原精确Topic、QoS1、非RETAIN和16KiB业务上限。下行`model_switch`字段为`device_id,ownership_id,switch_id,source_version,target_version,source_start,structure_hash`，不传完整模型。设备只在当前本地版本/起点匹配、无另一份待确认结果且支持映射匹配时切换；SQLite同步事务保存目标版本、当前`last_sequence`作为切换边界以及不可变结果。旧缓存不重写，新采样使用新版本。

上行`model_switch_receipt`字段为`device_id,ownership_id,switch_id,source_version,target_version,structure_hash,boundary_sequence,status,code`。成功为`confirmed/model_switched`；明确拒绝为`rejected/model_unsupported`或`rejected/model_state_conflict`。同ID同内容重复返回原结果，异内容拒绝。平台同步提交确认后下行`model_switch_ack`，字段为`device_id,ownership_id,switch_id,result_hash`；设备验证原结果摘要后才清除pending。网络PUBACK不替代该业务ACK。

## 确认、失败与额度

`published`仅表示QoS1取得PUBACK，不是平台持久接收。模拟器不重新生成缓存JSON，不添加会在重试时变化的MQTT属性，也不凭采样时间删除记录。每次只发送一个待确认业务原件；其后独立等待平台 `ingestion_receipt`。JSON顶层、重复键、精确下行Topic、QoS1、非RETAIN、设备、归属、序号、摘要、状态和原因都验证通过后，才调用缓存事务。

accepted清理成功记录，rejected进入独立有界终局异常；二者都在本地事务完成后才发送下行PUBACK。事务失败则关闭网络并保留原件，Broker可恢复尚未确认的回执。已经在本地完成的业务回执重放会释放新网络凭据，但不重复累计成功；即使本地待补传已空，也短暂接收恢复中的终局回执。无法识别的下行载荷不会被当作accepted或指令成功。

网络中断、TLS失败、协议拒绝、回执截止、无效回执或未知提交抛异常；缓存已完成的事务和未确认原件各自保持真实状态，调用者不能把异常解释成“平台肯定未收到”。后续运行由调用者明确启动，不在内部无限重试。每次网络操作受剩余总预算约束；正常结束另外最多一秒尝试DISCONNECT，失败和停止立即释放网络。`stop()`不会吞掉本地数据库等其他异常。

网络报文最多32768字节，包含16KiB业务JSON及Topic、协议头；接收窗口8条，Client的网络队列和未确认交付同样有界。缓存默认8640条及8640000字节纯业务JSON，SQLite元数据、WAL和恢复空间另计；支持每10秒1000字节完整JSON的24小时基线，同空间只能容纳527条16KiB载荷。容量、终局异常、持久计数饱和等规则均沿用DeviceBuffer，网络层不增加另一份缓存。

## 正式设备命令与状态

标准应用的PHP入口 `php bin/typeapp iot:device <state|enqueue|send|listen>` 与原生入口 `build/app/type-app iot:device <state|enqueue|send|listen>` 相同。设备角色在平台数据库装配前执行，只连接自己的SQLite缓存，平台数据库不可用不妨碍本地采样。父目录预先创建，缓存及CA相对`APP_BASE_PATH`解析；其他工作目录启动时仍使用同一配置根。`listen`处理单次指令的模拟动作，可信时间、去重及实际驱动接入契约见[单次指令](iot-commands.md)。

启动值从环境或配置根的`.env`读取：`IOT_DEVICE_ID`、`IOT_DEVICE_OWNERSHIP_ID`、`IOT_DEVICE_TENANT_ID`、`IOT_DEVICE_MODEL_VERSION`、`IOT_DEVICE_CREDENTIAL_ID`、`IOT_DEVICE_PASSWORD`来自注册结果；`IOT_DEVICE_CACHE`指定缓存文件；`IOT_DEVICE_HOST/PORT/CA/PEER_NAME`指定可信TLS目标。CLI强制显式CA，秘密不作为命令参数。默认额度`IOT_DEVICE_MAXIMUM_RECORDS=8640`和`IOT_DEVICE_MAXIMUM_BYTES=8640000`，沿用缓存纯载荷与元数据分离的定义。

`state`输出持久容量和计数。`enqueue`只从标准输入读取最多16KiB JSON，接受`type`、`sampled_at`、对象`values`及event所需`identifier`；设备身份和模型由配置注入，不接受输入改写。完整封装后仍不得超过16KiB。示例在已经配置的独立设备目录中运行：

```sh
php -r 'echo json_encode(["type" => "telemetry", "sampled_at" => time(), "values" => ["temperature" => 20.0]]);' | build/app/type-app iot:device enqueue
build/app/type-app iot:device state
build/app/type-app iot:device send
```

每次连接订阅成功后及正常补传结束前，用相同精确上行Topic发送一份QoS1缓存观察。状态协议确认仅证明Broker接收；平台消费者经同步提交后确认原交付，不发送业务回执。平台只保存每设备当前归属和模型的一份最新序号观察，重复或乱序不刷新时间，观察不产生遥测原始事实、当前值或平台接收账本。追加迁移011，不改既有迁移。

设备详情复用同一授权HTTP和五秒轮询，显示缓存满、未接纳采样、待确认条数/字节、终局拒绝、异常淘汰及双观察时间。60秒无新观察或设备采样时间不在平台接收前30秒至后5秒范围内，显示观察过期并保留最后报告。没有本阶段/模型观察时为空；刷新失败保持上次数据标记，权限撤销清空。该状态独立于实时遥测陈旧和连接在线状态。

## 验证与范围

```sh
php tests/iot-device-simulator.php
php tests/iot-device-simulator.php --native
```

验证建立独立消费应用，完整复制IoT生产域并安装主仓全部生产依赖，以同一Broker和模拟器入口分别执行PHP及AOT路径。真实TLS服务使用隔离主库和同步备库、临时证书及公开AccessPolicy；回执由公开MQTT客户端对端发送。该对端用于精确控制“回执缺失、错误、永久拒绝”等输入，不替代平台持久接收证明。

覆盖PUBACK后缓存保留、跨进程原字节重发、重复终局回执、身份/摘要/重复JSON键/RETAIN拒绝、有界永久异常、16KiB真实载荷、实际SQLite事务失败后下行回执重放、信号停止、SIGKILL、证书/主机名拒绝、Broker停止后的显式恢复和资源归零。macOS原生路径搬迁产物，并使用内核禁读生产源码、依赖自动加载及生成实现。

本子路径不声明平台去重七天清理、48小时/5秒接收窗口、平台同步提交未知、系统状态、60秒陈旧或Web页面已经验收；这些须在正式平台装配后的完整路径中验证。

## 正式命令与缓存状态验证

本切片复用既有缓存、TLS补传器和平台接收角色，新增 `iot:device` 命令和 `device_status` 观察；没有另建设备Topic或可靠账本。PHP及禁止读取业务源码的原生接收场景均在三库通过25组；标准应用全量编译214单元、21个生产源码集且零排除，原生HTTP三库各103项通过。正式CLI在平台数据库地址不可用时仍能离线入队，通过真实TLS及业务回执排空，再由授权HTTP显示未接纳采样数；永久拒绝转入设备有界异常集合。
