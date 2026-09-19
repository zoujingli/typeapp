# 设备预注册、生命周期与MQTT接入

## HTTP与设备契约

客户通过新账号域认证，向 `POST /customer/tenants/{tenant}/devices` 提交 `name,product_id,model_version`，单独要求 `customer.devices.create`。路径租户须与唯一 `X-Tenant-Id` 一致；产品和已发布模型必须属于当前租户。成功返回 `data.device` 和只显示一次的 `data.credential`。未知设备不能通过MQTT自注册，普通详情和列表不返回凭据或验证值。

`GET /customer/tenants/{tenant}/devices` 支持 `name,product_id,lifecycle,page,per_page`，默认20、最多100条，要求 `customer.devices.read`；详情追加 `/{device}`，返回设备资料、精确绑定模型和两个Topic，不包含遥测。平台使用 `GET /admin/devices[/{device}]` 与 `admin.devices.read` 查询全局资产，列表另可按 `tenant_id` 筛选；平台投影只有资产、归属及接入状态，没有模型内容、Topic、遥测、控制或私有导出。平台拒绝租户头，两端均拒绝旧支持及自报模拟身份头；不提供平台注册入口。

两端详情路径的 `PATCH` 只接受 `name,version`，分别要求 `admin.devices.update` 或 `customer.devices.update`；归属、产品、模型和状态不能借资料更新变更。生命周期是 `inactive,enabled,disabled,retired`；新注册为 `inactive`，首次完整成功CONNACK已经输出并仍持有连接时转为 `enabled`。认证成功、失败CONNACK及HTTP刷新均不激活设备。

注册结果、列表项和详情均含以下字段：

```ts
connection: {
  status: 'unknown' | 'online' | 'offline';
  observed_at: number | null;
  broker_observed_at: number | null;
}
```

时间是UTC Unix秒。`observed_at` 是最后一次连接建立或关闭的真实观察时间；从未连接为null。`broker_observed_at` 是所属Broker节点最近一次循环活性或停止观察时间。在线需要同一运行代次且活性未过期；每五秒只写一条节点活性，十五秒无新活性或节点运行代次改变后返回 `unknown`，保留最后连接观察时间。正常观察到关闭返回 `offline`，以后节点活性丢失不撤销已知关闭事实。HTTP读取失败按错误响应返回，不能伪造离线，也不写设备状态。连接观察是有新鲜度边界的事实，不是遥测新鲜度、业务持久接收证明或跨节点所有权隔离。

| 接入字段 | 值 |
| --- | --- |
| Client ID | `device.id` |
| Username | `device.id + ':' + credential.id` |
| Password | 256位随机秘密编码为64位小写hex；服务端只保存其SHA-256验证值。 |
| 协议与传输 | MQTT 5，TLS必需，客户端验证证书链和主机名。 |
| Keep Alive / Session Expiry | 30 / 86400秒；应用策略拒绝其他设备参数，不改变独立Broker的标准行为。 |
| 发布 | `iot/{tenant}/devices/{device}/epochs/{ownership}/up` |
| 订阅 | `iot/{tenant}/devices/{device}/epochs/{ownership}/down` |

消息类型在载荷中表达；遥测持久接收与下行指令分别见相应业务说明。CONNECT校验秘密；每次发布、订阅、恢复及遗嘱到期都按Broker已认证的设备/凭据身份重查当前凭据状态、生命周期及归属阶段，授权不依赖密码或连接仍存活。遗嘱持久恢复不保存秘密及其验证值；凭据身份不可变，轮换须生成新身份并撤销旧凭据。只允许精确的本设备方向Topic，其他设备、租户、旧阶段或方向拒绝。共享订阅按实际过滤器授权，因此本设备精确下行可以加入共享组，跨设备和含通配符的过滤器仍拒绝。

列表和详情通过同一次SQL查询读取生命周期与连接观察，避免首次激活在两次查询之间提交时拼出未激活但在线的状态；观察所属阶段不匹配时不向当前阶段泄露旧观察时间。

## 生命周期与持久撤权

复用状态机。客户使用 `POST /customer/tenants/{tenant}/devices/{device}/{action}`，平台使用 `POST /admin/devices/{device}/{action}`；动作是 `rotate/revoke/disable/enable/retire`，分别要求本域的 `devices.{action}` 固定节点，请求携带当前 `version` 与精确的 `confirm_device_id`。复用授权事务锁重验当前身份、角色和模拟来源，再锁定设备读取准确版本；等待期间撤权或设备变化返回明确拒绝。已知准确目标的写操作不隐式要求查询节点，平台操作不伪装客户成员。

每台设备最多一个有效凭据。轮换永久撤销旧凭据并生成新主体，秘密只在本次响应显示且仅保存不可逆摘要。禁用与退役同时吊销旧凭据；启用不会复活旧凭据，须显式轮换后重新配置。退役是终态，阻止新接入、上报与控制，不提供硬删除或普通恢复；已有历史与告警按原租户权限和各自保留周期保存，不因退役自动结束活动告警。

全新安装直接创建最终设备表和 `iot_authorization_invalidations`，每设备保存一项精确旧主体的持久失效意图，不走旧数据转换。管理事务提交后返回 HTTP 202，设备投影的 `authorization.status=pending` 表示Broker尚未完成隔离；无旧主体需要隔离时为HTTP 200。投影同时提供 `id/requested_at/completed_at/node_id`，详情另有 `credential_active`。未完成期间禁止第二个管理动作，防止旧意图被新动作覆盖；已完成行可供后续动作复用。连接状态和遥测新鲜度仍取自各自真实观察。

意图冻结租户、真实管理人员或客户、会话及模拟来源，提交与审计在同一事务内完成。Broker完成时使用冻结来源写入对应新审计域；来源退出不能撤回已提交的隔离。重复完成回调不重复写完成审计。MQTT接入、发布、订阅和服务的精确设备Topic授权同时检查租户启用状态。

`DeviceAccess` 通过新增的可选 `AuthorizationInvalidations` 公共契约提供旧主体，沿用已有有界子进程。Broker停止该主体的网络读写，丢弃尚未输出的数据，并等待相关持久工作结束；取得精确旧会话终止的同步提交证明后，才保存 `enforced` 完成状态。会话终止清理订阅、未完成交换、离线待投递与遗嘱。迟到旧意图不能终止轮换后不同主体的会话。已交给网络的字节不能撤回，页面不会在持久意图提交时提前声称网络隔离完成。

撤权来源异常、持久结果未知或后端未证明终止时，Broker停止服务并保留未完成意图；重启后重新完成同步隔离。该完成状态目前针对执行它的单Broker，跨节点逐一证明由扩展。服务退出统计含 `invalidated/invalidationPending/invalidationFailures`，失败不能被正常连接观察覆盖。

管理端复用设备列表和公共抽屉，危险操作确认设备标识；保存中禁重复提交和关闭。响应丢失时提示明确刷新核对，不自动重试生成凭据，也不能找回已经显示过的秘密。切租户、关闭凭据抽屉或会话失效会清除秘密。

## 启动与所有权

设备转移的双方审批、目标模型匹配、原阶段冻结与排空条件见[转移说明](iot-transfers.md)。冻结不会提前赋予目标访问源设备、凭据或历史的权限；详情显示持久转移标识，新控制在服务端拒绝，原结果对账继续。

HTTP与预注册支持真实MySQL、PostgreSQL、SQLite；设备持久MQTT接入按ADR0010仅使用PostgreSQL同步后端。配置沿用 `DB_*`，新增项见 `.env.example` 的 `IOT_MQTT_*`。生产选择锁定AOT产物，`IOT_MQTT_COMMAND` 为同一应用入口的JSON参数数组；相对命令按启动工作目录解析，证书及私钥相对 `APP_BASE_PATH`。示例开发入口可配置为 `["php","bin/typeapp"]`，原生部署使用自己的二进制或打包启动器。不得把PHP开发入口配置为生产回退。

```sh
php bin/typeapp migrate run
php bin/typeapp iot:mqtt-install
php bin/typeapp iot:mqtt
```

原生产物使用同名角色。安装角色通过已有持久worker取得同步证明，运行角色不自动迁移；备库名默认 `iot_sync`，沿用组件对 `FIRST 1`、`remote_apply`、WAL刷盘及备库重放的要求。TLS不提供明文开关。一个稳定 `node_id` 由一个进程独占监听，不同节点使用不同标识；不声明跨节点接管或高可用已经验收。

同名节点的资源观察仍有效时，新运行会被拒绝。强杀或观察失败退出后，须先确认旧进程已停止，再等待 `broker_resource_runs.expires_at` 自然到期（最后一次成功心跳后15秒），然后启动同名节点恢复持久意图；不能通过删除观察行或更改完成状态跳过保护。正常停止会结束本次观察有效期，无需固定延时。

应用默认物理连接10100、设备10000、服务预留100、持久会话20000。配置键见 `.env.example` 的 `IOT_MQTT_MAXIMUM_*`，只能调低。Broker在 `DeviceAccess` 校验真实TLS、Client ID和独立服务密码之后才把服务身份计入 `application`；客户端自报属性不能申请服务额度。同Client ID接管沿用分类名额，并在持久容量预留成功后完成接管。已持久保存的分类在普通恢复时不能改变。

持久预算同时约束条数和逻辑字节，先满者生效：每设备10000条/16 MiB，应用会话或共享组1000000条/2 GiB，全局待投递2000000条/4 GiB。订阅仍每会话最多100个，替换不重复计数。Broker及接收客户端均允许完整1 MiB协议包，接收业务的16 KiB限制独立执行；持久队列额度不代表能存放全部设备的24小时缓存。

`iot:mqtt-statistics` 复用持久worker返回同步证明后的聚合JSON，包括配置额度、会话分类、订阅和分类积压，不输出身份或载荷。Broker结束时输出连接、分类拒绝、持久拒绝、缓冲及资源回收计数；持久统计中的已连接会话只表示数据库所有者事实，不能替代实时网络在线观察。降低额度不能删除已确认未完成消息，恢复已有会话和交付继续使用原持久事实。

`DeviceAccess` 实现应用策略及可选连接观察。每次策略或观察同步等待一个受控子进程，输入至多397306字节、输出1KiB、硬截止3秒；父子共用输入预算，按单条标准65535字节过滤器的最坏JSON转义加4KiB信封计算，避免合法长共享组在已订阅后被观察通道拒绝。数据库租约使用 `DatabaseManager/ExecutionScope` 并立即归还，不会让空闲设备持有数据库连接。Unix非阻塞管道是当前应用接入角色的明确运行条件，不改变框架其他角色的Windows支持。凭据以验证值进入私有管道，进程参数和日志没有秘密。启动即用 `PGAPPNAME` 标识具体数据库操作；异常或超时后复用 `PendingCommit/PostgresStore` 的有界精确后端清理，停止当前策略实例及Broker，不重试未知写入。未证明清理成功的后端需要运维处理，不能无限重建工作进程。该串行有界策略尚未作万台连接或吞吐验收。

每台设备仅在真实连接变化时写一行观察；节点心跳不全表更新设备。关闭同时匹配连接owner和运行代次，旧连接迟到关闭不覆盖新连接；在线写入还检查当前节点代次、活性及设备归属。`AuditLog` 记录 `device.connected,device.disconnected,device.connection_denied,device.topic_denied`，设备详情可复用当前租户审计的 `subject_id` 筛选。未知身份拒绝以无租户审计保留，不创建设备；审计只含稳定身份、动作及允许的原因，不含密码、验证值、任意Topic或载荷。

## 验证入口

运行中的采样、持久积压、故障与租户设备观察由[运行概览](iot-operations.md)提供，保持观察、同步证明和页面读取失败分别表达。

```sh
php tests/iot-identity.php --php sqlite --devices --lifecycle
php tests/iot-device-mqtt.php --php
php tests/iot-device-mqtt.php --php --lifecycle-mqtt
php vendor/bin/type docs/build-config/type-app.json
php tests/iot-device-mqtt.php build/app/type-app --no-source
php tests/iot-device-mqtt.php build/app/type-app --no-source --lifecycle-mqtt
```

`TYPE_PGSQL_TOOLS` 指向已验证的本机PostgreSQL工具根，`TYPE_MQTT_CLIENT_ROOT` 指向已安装 `mqtt@5.15.0` 的测试依赖根。测试复用真实HTTP注册、独立主库及同步备库、临时TLS证书和标准客户端，分别记录PHP、全量AOT及原生无源码结果；`--no-source` 当前使用macOS内核拒读策略。只清理自己创建的进程和测试秘密，不停止其他HTTP/Broker。覆盖正确/错误/跨设备凭据、设备状态及归属、会话恢复重授权、CONNACK失败、在线/关闭/接管、崩溃未知、观察依赖失败及审计脱敏。原生与独立组件回归的实际完成范围以最终验收记录为准。

历史 `--capacity`、`--ingestion`、控制、转移、模型切换等装置保留在对应测试文件，按及原责任票接入新身份；新入口明确拒绝未迁移组合，不将跳过记为通过。PHP开发验证须显式加载锁定Swoole，官方内置library按已确认标准启用；其他生产源码仍全量编译。

PHP完整回执最终通过，但较早运行出现过单条回执等待超时及故障准备阶段未观察到设备行锁等待；stream对照也出现单条超时。独立故障场景和完整顺序都已有成功记录，尚未确定这些偶发失败的根因，不能把重跑通过当作稳定性问题已修复。单条发布15秒、回执12秒和完整客户端180秒的测试截止均保持不变。
