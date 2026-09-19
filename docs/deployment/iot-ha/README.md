# PostgreSQL 严格同步与受控切换

生产拓扑为三个独立故障节点上的 PostgreSQL/Patroni、三个独立投票者的 etcd，以及两个独立的数据库写入口。Patroni 负责选主和同步节点选择，PostgreSQL 保存业务与 Broker 恢复状态，HAProxy 将连接送往持有租约的主库。旧主的硬隔离依赖各节点的独立 watchdog 和受控基础设施；配置存在不表示隔离已验收。

## 固定工具与目录基准

当前验证版本为 PostgreSQL 17.11、Patroni 4.1.0、etcd 3.6.5、HAProxy 3.4.4。Python 使用带 OpenSSL 的 3.12；在独立虚拟环境按 [requirements.txt](requirements.txt) 安装 Patroni 依赖。安装环境另保留平台、下载来源和二进制摘要，不把本机路径当作部署默认值。

每个服务使用独立私有运行目录，以下相对文件都以该进程工作目录为基准。复制模板时保留 `patroni.json`、`data`、`tls` 的相对关系；明确的外部二进制位置通过环境配置。任何生产数据目录均不参与本仓库故障测试。

## 三个 etcd 投票者

在三个节点分别配置 [etcd.env.example](etcd.env.example)，节点名为 `iot_a`、`iot_b`、`iot_c`。本节点监听、公告地址及证书 SAN 必须与实际 DNS 一致；三个节点的 `ETCD_INITIAL_CLUSTER` 和 token 一致，数据目录互不共享。服务监督者加载本节点环境后以 `etcd` 前台运行，并限制文件权限和日志轮转。

2379 是客户端 gRPC 入口，2381 是独立 HTTP 网关，2380 是投票者互联。所有入口采用 mTLS；防火墙只放行实际集群和管理来源。Patroni 的 `etcd3.hosts` 指向三个 HTTP 网关，`use_proxies=true` 固定使用这些地址，避免发现协议把请求改送到不承载 HTTP 的 gRPC 端口。投票者变更必须同步更新这份明确的地址清单，不能只改 DNS 后假定全部客户端已经迁移。

`initial-cluster-state=new` 仅初始化新集群时使用。已有节点恢复使用原数据目录；替换故障投票者按 etcd 的 member remove/add 流程进行，不能删除数据目录或用新 token 伪造原多数派。备份、恢复与节点销毁不由本测试自动操作。

## 三个 Patroni 数据节点

每节点复制 [patroni.json](patroni.json)。通过服务监督者注入以下配置；名称示例需替换为实际可达的私有 DNS：

| 配置 | 节点 A 示例及约束 |
| --- | --- |
| `PATRONI_NAME` | `iot_a`；另外两台为 `iot_b`、`iot_c`，永久唯一 |
| `PATRONI_RESTAPI_LISTEN` / `PATRONI_RESTAPI_CONNECT_ADDRESS` | `pg-a.iot.internal:8008`；必须可被其他 Patroni 和 HAProxy 到达 |
| `PATRONI_POSTGRESQL_LISTEN` / `PATRONI_POSTGRESQL_CONNECT_ADDRESS` | `pg-a.iot.internal:5432` |
| `PATRONI_ETCD3_HOSTS` | `["etcd-a.iot.internal:2381","etcd-b.iot.internal:2381","etcd-c.iot.internal:2381"]` |
| `PATRONI_POSTGRESQL_BIN_DIR` | 运行时解析的 PostgreSQL 17.11 `bin` 目录 |
| `PATRONI_SUPERUSER_PASSWORD` / `PATRONI_REPLICATION_PASSWORD` | 独立秘密，由受限凭据渠道提供；三个节点共享对应角色的秘密 |

启动前执行 `patroni --validate-config patroni.json`；正式地址、证书、工具及秘密未配置齐全时模板应拒绝就绪。验证命令不要使用 `--print`，避免输出秘密。以 `patroni patroni.json` 前台交给服务监督者运行。不要设置 `PATRONI_CONFIGURATION`，除非明确打算以完整配置取代文件与其他环境覆盖。

`tls/ca.crt` 为可信 CA，`tls/patroni.crt/key` 为本节点 API 证书，`tls/patroni-client.crt/key` 为集群操作的客户端证书，`tls/etcd-client.crt/key` 为 DCS 客户端证书，`tls/postgresql.crt/key` 为 PostgreSQL 服务端证书。私钥仅服务账号可读。生产使用签发的叶证书，不复用本机测试装置的一次性自签名证书。API 证书含本节点管理 DNS，数据库证书同时含本节点 DNS 和稳定写入口 DNS，以支持端到端 `verify-full`。Patroni 复制与内部管理连接同样校验证书。

PostgreSQL `pg_hba` 只允许 TLS 与 SCRAM；网络来源进一步收窄至实际复制节点、应用与管理网段。应用使用独立非超级用户，只授予迁移或业务所需权限；`iot_cluster_admin` 与 `iot_replication` 不下发给业务角色。

`bootstrap.dcs` 只在首次引导时生效。已有集群必须通过带有效客户端证书的 `patronictl -c patroni.json show-config` 核对，并通过 `edit-config` 修改动态配置；修改本地模板不会自动修改 DCS。持续保持：

- `synchronous_mode=true`、`synchronous_mode_strict=true`、`synchronous_node_count=1`，且 `failsafe_mode=false`。
- `synchronous_commit=remote_apply`、`fsync=on`、`full_page_writes=on`；丢失同步节点不得人工降为异步。
- `ttl=30`、`loop_wait=5`、`retry_timeout=5`、`primary_start_timeout=0`、`maximum_lag_on_failover=0`、`check_timeline=true`。
- 数据页校验和、`wal_log_hints=on`、`use_pg_rewind=true`，使旧主能够从正确时间线重新加入。

无合格备库时 Patroni 可能临时选择 `*`；应用会拒绝这类同步证明。正式选择必须是 `iot_a`、`iot_b`、`iot_c` 中的一个确定节点，且它真实处于 `streaming/sync`。不能仅观察 PostgreSQL 的 `sync` 一列就开始故障验收，还必须确认 DCS 已将同一个节点登记为 `sync_standby`。

## 旧主隔离和数据库入口

生产配置要求 `watchdog.mode=required`、`safety_margin=-1`。服务账号只能访问自己故障节点的已核验 watchdog；无法激活时不能当选主库。必须在独立可重启的故障节点验证超时真正关闭旧主写入能力，且发生在旧租约可被接管之前。不可在开发者物理主机加载 watchdog、启用 softdog 或进行宿主重启。软件模拟、仅关闭入口或 Patroni 的正常退出均不能替代硬隔离证据。

两个独立入口各使用 [haproxy.cfg](haproxy.cfg)，交由既有网络设施提供稳定 DNS/VIP。单个 HAProxy 进程不能代表入口高可用。每个入口注入：

| 配置 | 值的含义 |
| --- | --- |
| `IOT_HA_LISTEN` | 入口自身的私有监听地址与端口 |
| `IOT_HA_DB_A/B/C` | 三个 PostgreSQL 的 `host:5432` |
| `IOT_HA_API_PORT_A/B/C` | 对应 Patroni API 端口，通常为 `8008` |
| `IOT_HA_HOST_A/B/C` | 对应 API 证书的可验证 DNS |
| `IOT_HA_HEALTH_HOST` | HTTP Host 请求头；无需改变数据库 TLS |
| `IOT_HA_CA` / `IOT_HA_CLIENT_PEM` | 运行时解析的 CA 与包含客户端证书、私钥的 PEM 文件 |

先执行 `haproxy -c -f haproxy.cfg`，再以 `haproxy -db -f haproxy.cfg` 前台运行。入口使用 mTLS 请求 `/primary`，只接纳 200，启动时所有节点为 `fully-down`，两次探活成功后才可路由。节点降为不可用后关闭既有连接，促使连接池丢弃旧连接。入口透传 PostgreSQL TLS；应用配置 `DB_HOST` 为稳定写入口、`DB_TLS_CA` 为 CA，`IOT_MQTT_STANDBY=iot_a,iot_b,iot_c`。

数据库连接错误不会触发未知写事务重试。HTTP 可在后续新请求重新建连；失败的 Broker、接收角色由监督者使用原 `IOT_MQTT_NODE_ID`、原 `IOT_INGESTION_INSTANCE` 和凭据恢复。集群 Broker 须先完成下述旧运行关闭或硬隔离登记，再允许相同稳定节点名的新运行注册。接收角色的连接截止为十秒，与应用 Broker 的握手上限一致；超过截止仍退出，由监督者恢复。恢复依靠持久会话和幂等账本，不能用新 Client ID 绕过旧状态。网络/持久失败期间不得发送成功回执，数据库中可见记录也不能把取消或未知提交改写为成功。

## 多 Broker 与旧运行隔离

多个 Broker 使用相同严格同步数据库和 `IOT_MQTT_CLUSTERED=true`，每个独立进程配置唯一稳定 `IOT_MQTT_NODE_ID`、各自的监听地址与 TLS 凭据。应用为每次运行生成随机身份并同步注册；同稳定节点仍有 `active` 旧运行时拒绝注册新运行。默认 `false` 仅兼容单节点。从 legacy 模式迁移须先让原独占节点恢复并关闭遗留在线会话，全部停止后再注册集群节点；已启用集群的数据库不允许 legacy Broker 混入。

接管同 Client ID 时，新连接保存旧 owner 的关闭意图，旧节点关闭其精确 socket 并回收相关 worker 后同步确认，新连接才返回成功 CONNACK。节点失联时只拒绝不可证明的接管，不以时间经过自动宣布旧节点已隔离。凭据轮换、吊销、禁用和退役的 `pending` 状态也等到全集群匹配的旧 owner 关闭后才转为 `enforced`。

管理命令使用已配置的数据库与编译应用入口：

```bash
build/app/type-app iot:mqtt-nodes
build/app/type-app iot:mqtt-fence <node-id> <run-id> <actor> <proof-ref>
```

先通过统计取得并核对精确旧 `node_id/run_id/generation`，再由资源拥有者实际隔离该运行及继承监听或连接的 worker，确认它们不能恢复任何旧网络输出，最后登记对应 `actor/proof_ref`。证据标识引用实际基础设施操作和完成时间线；命令本身不执行硬隔离，也不会凭非空字符串证明机器已断电。不得在旧进程仅暂停、短暂失联或隔离结果未知时登记成功。正常 Broker 在全部网络和工作释放后自动登记退休；异常退出且登记失败时仍须完成受控隔离流程。

定向验收入口：

```bash
php tests/mqtt-consumer.php --native --cluster-only
php tests/iot-device-mqtt.php build/app/type-app --cluster --no-source
```

## 本机可重复故障入口

准备真实 PostgreSQL 工具、上述 Patroni 虚拟环境、etcd、HAProxy 与已安装 MQTT.js 5.15.0 的客户端目录。环境变量 `TYPE_PGSQL_TOOLS`、`TYPE_PATRONI_BINARY`、`TYPE_ETCD_BINARY`、`TYPE_HAPROXY_BINARY`、`TYPE_MQTT_CLIENT_ROOT` 均由运行时解析，指向实际工具，不写入仓库。先按项目入口构建全应用，然后运行：

```bash
composer typeapp:build
php tests/iot-ha.php --infrastructure-only
php tests/iot-ha.php build/app/type-app --no-source
```

入口仅在 `build/iot-ha-<随机值>` 下建立新私有数据，所有监听仅在回环。`PostgresHa` 拥有三个真实 Patroni/PostgreSQL、三个 etcd 投票者、三个仅供注入 DCS 网络故障的 TCP 转发及一个写入口。启动检查除明确的本机回环公告地址外不允许 Patroni 校验错误；正式部署没有这个例外。

故障顺序为：设备保存真实业务回执；服务给离线设备保存 QoS 1 消息；设备接收但故意不 PUBACK；旧主进程退出并重新加入；同一会话恢复原 packet ID 与 DUP 消息、重发原上报得到原回执；隔离现主到全部 DCS 入口；恢复后验证旧主直连真实写入被只读拒绝；停止全部备库，验证没有成功 PUBACK 或业务回执；恢复备库及原应用角色；再执行一次正常角色重启。

可复用边界是 `PostgresHa::crashLeader()`、`partitionLeader()`、`stopStandbys()`、`restartStandbys()` 和 `snapshot()`，只操作对象自行创建的进程。多 Broker/满负载入口可以复用相同业务客户端、故障阶段和证据字段；独立节点测试必须替换资源拥有者，并使用已经核验的故障节点控制及硬隔离。不要通过任意 shell 命令字符串或裸 PID 让该本机入口接管外部资源。

最终证据保存到本轮目录的 `verification.json` 与身份测试目录的 `verification.json`。分别记录 `topology`、`watchdog`、`hard_fencing_verified`、工具版本、真实 DCS 同步角色、旧/新主、单次故障耗时、旧主只读拒绝、客户端确认 ID 集合、原回执及 QoS 重放、同一 HTTP 进程连接恢复、角色退出和数据库后端回收。原始日志留在同轮目录，测试私钥与复制密码文件在退出时删除。失败记录必须保持 `failed`，不能以清理完成推定验收通过。

## 上游依据

- [Patroni 4.1.0 同步复制](https://github.com/patroni/patroni/blob/v4.1.0/docs/replication_modes.rst)
- [Patroni 4.1.0 watchdog](https://github.com/patroni/patroni/blob/v4.1.0/docs/watchdog.rst)
- [Patroni 4.1.0 环境配置](https://github.com/patroni/patroni/blob/v4.1.0/docs/ENVIRONMENT.rst)
- [etcd 3.6 配置](https://etcd.io/docs/v3.6/op-guide/configuration/)
- [HAProxy 3.4 配置](https://docs.haproxy.org/3.4/configuration.html)
