# 独立 Broker 管理宿主

本文描述独立 Broker 宿主的管理、持久运行与运维边界。独立角色复用应用的账号会话、脱敏审计、ORM、HTTP 路由与 Vben 公共页面；管理安装只创建 `broker_*` 表，不需要 IoT 人员或设备表。Broker 核心仍在 `type-mqtt`，不读取应用管理表。

## 安装与启动

从主仓根准备已锁定依赖及完整应用 AOT。以下使用生成的 `build/app/type-app`；开发对照可替换为 `php bin/typeapp`，生产不能回退到此解释入口。运行包使用打包后的 `run` 执行相同角色。

按 `.env.example` 设置 `APP_BASE_PATH`、`DB_*`、`APP_LISTEN`、`APP_PORT`、`APP_ALLOWED_HOSTS` 等环境配置，指向新的专用管理数据库。SQLite 文件相对 `APP_BASE_PATH`，MySQL/PostgreSQL 由运维预先创建数据库和受限账号。安装是显式操作，服务启动不会隐式建表或迁移。

```sh
build/app/type-app broker:install
build/app/type-app broker:user administrator 管理员
build/app/type-app broker:serve
```

执行 `broker:user` 时通过受控进程环境临时提供 `BROKER_ADMIN_PASSWORD`，长度 12–72 字节；不要放到命令参数、共享默认配置或前端。重复登录名初始化被拒绝，不能借此覆盖口令。管理密码与 MQTT 接入密码分离。

登录入口为 `POST /broker/auth/login`，JSON 字段 `login`、`password`；返回可撤销 Bearer 会话。`GET /broker/auth/me` 和 `GET /broker/nodes` 每次重新核对当前账号及管理权限。`POST /broker/auth/logout` 撤销本人当前有效令牌，管理角色被撤销后仍允许退出。会话八小时到期，每账号最多十个；连续五次口令失败锁定五分钟，数据库只保存令牌散列。登录拒绝、成功、初始化成功及退出记录脱敏审计。

管理 HTTP 宿主只开放 `/broker/` 业务路由；普通 IoT 宿主拒绝这些独立路由。独立权限是 `broker_admin`，拒绝 IoT 租户或支持上下文头。沿用的 `platform_admin` 账号投影字段不授予 IoT 平台角色。此角色不依赖通用应用令牌配置。

## 实际节点采样

另一受控进程运行 `broker:run`，管理 HTTP 与 MQTT 进程使用同一管理数据库。节点配置为 `BROKER_NODE_ID`、`BROKER_LISTEN`、`BROKER_PORT`、`BROKER_CERTIFICATE` 和 `BROKER_PRIVATE_KEY`；默认 TLS，证书和密钥必须真实可读。明确的明文调试才设置 `BROKER_PLAINTEXT=true`，并保持证书配置为空。MQTT 用独立 `BROKER_CLIENT_USERNAME`、`BROKER_CLIENT_PASSWORD` 认证，只授权 `BROKER_TOPIC_PREFIX` 指定的 Topic 前缀；前缀以 `/` 结尾，不允许通配符。通信、连接关闭和基础并发均由 Swoole 官方能力提供。

未配置 `BROKER_COMMAND` 时使用 QoS 0 非持久运行路径，拒绝持久会话、QoS 1/2 发布和保留发布，订阅最多授予 QoS 0。可靠接收须配置下述持久装配。

节点数据来自 `Broker::statistics()`，每五秒采样一次。管理 API 返回采样时间、十五秒到期时间、实际监听配置与白名单资源计数；不返回口令、私钥或消息载荷。没有启动节点时返回空结果。采样过期表示暂不可达，未知计数保持 `null`；正常关闭完成后记录 `stopped`，不能把浏览器刷新失败等同于 Broker 已关闭。

采样最多保留 32 个运行槽位；新运行只复用已经停止或按下述维护命令明确登记隔离的槽，不清空其他历史行。崩溃运行保留过期观察，不能自动认定其已安全隔离。并发首次占槽冲突或槽满会明确停止采样宿主，不覆盖他人运行；提交成功才更新本进程的槽位身份，未知提交不自动重试。采样不是会话所有权证明。

## 持久运行与健康观察

持久角色复用 `PendingCommit`、`PostgresStore`、既有会话及节点所有权机制。安装和可靠确认均要求同步提交证明与资源已释放；多个节点使用不同的稳定节点标识，不能共享一个运行身份。配置不自动创建备库、放宽同步要求或把命令成功当作全部 Broker 功能完成。

设置 32–256 字符的独立 `BROKER_PROBE_TOKEN`，通过 `Authorization: Bearer` 请求以下入口；为空时不接受探针认证。探针令牌不能访问人员或节点管理接口，人员令牌也不能抓取专用探针；两者都不能携带租户或临时支持上下文。探针通过管理 HTTPS 入口访问，令牌不写入前端或 URL。

| 入口 | 实际含义 |
| --- | --- |
| `GET /broker/health/live` | 只证明管理 HTTP 进程能处理请求，认证和执行不需要查询数据库。 |
| `GET /broker/health/ready` | 所观察的活跃节点均有十五秒内采样、已配置持久路径且没有停机或未清理工作，当前同步存储检查通过且全局积压未满时返回 200；其他情况返回 503。 |
| `GET /broker/metrics` | 输出 Prometheus 文本。常规指标只使用最多 32 个 `slot` 标签，存储全局用量不按节点重复累计。管理库不可用返回 503；存储故障仍输出就绪和存储可用性为零，并省略未知用量。 |

就绪范围为 `observed_instances_only`，不是负载均衡器的实时连通性证明或一次发布的持久回执；公共 HTTP 引擎的 `/readyz` 也不代表 MQTT 可靠接收就绪。页面通过人员授权的节点查询获得相同健康结果。同步 worker 有正常操作与精确清理截止；抓取超时建议大于十秒，并按部署延迟保留余量。未证明资源释放时，本管理 HTTP 实例隔离后续存储探针，不能反复创建工作进程；须先核实并完成旧资源清理，再重启该管理实例。

累计事件使用 Prometheus `counter` 和 `_total` 后缀；当前连接、在途、缓冲、内存与预算使用 `gauge`。`observed_sum_*` 是当前有新鲜采样节点的数值总和，成员变化会令其下降，因此始终使用 `gauge`。存储 `pendingMessages` 与 `pendingBytes` 沿用公共容量语义，合计未完成原件与每份交付副本；例如六条发布各有一个待确认订阅者时占十二条，不能把它解释为十二条不同业务消息。不输出失联节点的旧当前值，不用零替代缺失指标；采样时间和失联事实继续输出。进程内存是 PHP 分配器当前/峰值字节，不冒充操作系统 RSS。结构化故障日志只保留状态和存储状态，不记录令牌、接入密码或完整载荷。

## 依赖恢复与准确隔离

节点失去同步证明会停止接收并按既有协议生命周期退出；恢复数据库不等于旧节点已安全退出。普通停止成功后可直接启动新运行；崩溃、停止事实未提交或存储仍登记旧运行时，先由运维核实并完成旧进程、socket、工作进程及远端数据库后端的真实隔离。

通过 `broker:nodes` 获取持久节点的 `node_id`、`run_id` 和代次，通过受权 `/broker/nodes` 查询获取同一节点的采样 `run_id`。两个运行标识职责不同，不能互换。确认隔离后执行：

```sh
build/app/type-app broker:node-fence <node-id> <node-run> <observation-run> <actor> <proof-ref> [operation-id]
build/app/type-app broker:node-fence-result <operation-id>
```

命令允许追加32位小写十六进制 `operation-id`；省略时生成并在 JSON 结果中返回。推荐运维在首次调用前生成并保管该 ID。未知结果也返回原 ID、`stage: unknown` 和 `result: unknown`，命令退出非零；不能把它当作确定失败、用新 ID 自动重发或删除表行来制造恢复成功。使用结果命令按原操作对账，或用完全相同参数及原 ID 重试；两者只查询原结果，不重新隔离节点。查询仍要求新的同步证明、本次资源已释放及准确原请求的数据库后端已消失。查不到原存储事实时继续未知。

同一操作 ID 绑定当时操作者、准确目标、代次和依据摘要，改变任何一项都会拒绝。已经完成的旧操作在节点和采样槽复用后仍只返回旧事实，不修改新运行。若原命令中断在受理之后、外部执行之前，结果查询可能一直未知；先由运维核对原动作与资源，不能仅凭记录缺失重发。IoT 宿主使用相同语义的 `iot:mqtt-fence <node-id> <run-id> <actor> <proof-ref> [operation-id]` 和 `iot:mqtt-fence-result <operation-id>`，不使用独立宿主的观察槽。

## Broker 操作审计

| 查询范围 | 列表入口 | 当前授权 |
| --- | --- | --- |
| 独立管理 | `GET /broker/audit` | `broker_admin`，拒绝租户和支持头；包含独立身份历史。 |
| 平台管理端 | `GET /admin/broker/audit` | 同时要求 `admin.audit.read`、`admin.broker.read`；只查询双端 Broker 类别，拒绝租户和伪来源头。 |
| SaaS 当前租户 | `GET /customer/tenants/{tenant}/broker/audit` | 路径与租户头一致，每次重验 `customer.audit.read`、`customer.broker.read` 及当前客户或模拟来源。 |

在独立及客户列表路径后追加事件 ID 查询详情；平台详情追加 `{source}/{id}`，其中 `source` 为 `admin` 或 `customer`。筛选接受 `actor_id`、`subject_id`、`operation_id`、`action`、`stage`、`result`、`from`、`to`；时间是 UTC 秒闭区间。`limit` 默认20、最大100，使用 `created_at DESC, id DESC` 键集分页，返回 `items`、`next_cursor`、`has_more`、`total: null`、`limit`。游标绑定完整当前授权、筛选和页大小；每页和详情都重新授权，不能沿用上一页权限。未知或重复参数拒绝，响应不超过1 MiB，不执行全表计数。

普通双端审计入口同样按当前 Broker 资源权限过滤事件：只有审计权限时不返回 Broker 类别，已知事件详情也返回404；同时具备两项权限才能读取。事件详情分别呈现当时阶段和操作当前结果，保留身份、权限来源、准确节点/运行/代次及确认影响；旧历史没有的上下文保持空值，不补造。身份操作和资源读取记录单次实际结果，不创建永久异步操作行。节点动作的异步操作事实保存冻结上下文及至多五份阶段回执；同键同内容返回原事件，不刷新时间，异内容冲突。迟到事件可以追加，不能把未知或已完成状态降级；确定迟到结果可以把未知推进为完成。

```sh
build/app/type-app broker:audit-clean 1000
build/app/type-app app:audit-clean admin 1000
build/app/type-app app:audit-clean customer 1000
```

审计从创建时刻保留180天，每次清理1–1000条，按返回的 `has_more` 安排下一批。失败或中断后重跑，不推进外部清理游标。只删除到期审计事件；异步操作事实和去重凭据、存储隔离、撤权与恢复依据不在该清理范围。事件清理后按原操作重试仍返回原事件 ID 和 `expired: true`，不重建历史日志；新的迟到完成事件从自身创建时间起保留180天。

### 按当前权限查看资源

资源包括 `nodes`、`connections`、`sessions`、`subscriptions`、`retained`、`backlog`。节点来自运行心跳，连接来自成功 CONNACK 后的真实观察，超过十五秒保持未知；持久会话的所有者声明不证明网络连接在线。订阅先查询持久状态，再分页查询纯实时连接的订阅。保留与积压只返回 Topic、QoS、字节数和交付阶段等元数据，不返回消息载荷、属性、密码或私钥。

客户接口要求路径和 `X-Tenant-Id` 相同，每页重新核对 `customer.broker.read`；模拟登录采用目标客户当前租户权限，保留真实管理人员、来源会话和有效客户。平台接口要求 `admin.broker.read`，只查询全局运行元数据，不自动获得客户入口、载荷或控制权限。应用拒绝 `X-Support-Id`、`X-Impersonation-Id`、`X-Identity-Realm` 等伪来源头；平台和独立入口也拒绝租户头。组件只消费宿主提供的归属与 Topic 范围，不读取应用角色表；Client ID 和 Topic 仍属于全局命名空间。

列表接受 `limit`（默认 20、最大 100）、上一响应的 `cursor` 及资源适用的筛选字段。返回 `items`、`next_cursor`、`has_more`、`total: null`、`observed_at` 和 `source`，不计算伪造总数。游标绑定当前会话、权限集合、真实来源、有效客户、租户、资源、筛选和页大小，切换上下文后必须从第一页查询。重复或非法参数返回 400；授权范围外和不存在的详情统一返回 404。列表长文本只预览 128 字符，并附原字节数；单次响应至多 1 MiB。未配置持久命令时仍能查看实时观察，持久资源返回 503；存储释放结果未知时沿健康入口的单向隔离规则处理。

资源页分别为 `/#/broker/resources`、`/#/admin/broker`、`/#/broker-resources`；双端 Broker 审计页为 `/#/admin/broker-audit`、`/#/broker-audit`，同时要求对应审计与资源权限。页面沿用公共筛选网格、列宽、行操作和只读详情抽屉，显示当前页与下一页，不在浏览器合并全量数据。切换租户、模拟来源或路由时清空旧内容；失败保留历史采样并标明过期未知，403 清空资源和详情。

### 独立节点页与部署

管理端沿用 `web` 工作区。访问 `/#/broker/login`，登录后进入 `/#/broker/nodes`；Hash 页面路径与 HTTP API 分别处理。列表有节点标识筛选、20/50/100 分页、采样时间及只读详情抽屉，详情保留打开时的采样。可见时五秒刷新，不叠加请求；隐藏或离开时取消，403 清除资源内容，网络失败保留旧采样并标示错误及到期。

开发代理的 `IOT_API_ORIGIN` 指向这次独立 `broker:serve` 地址。生产可在普通 HTTPS 反代后提供同源静态站点和 `/broker/` API，正确配置允许 Host 与受信代理；反代不得记录请求中的密码或 Authorization。MQTT TLS 仍由 Broker 终止，管理 HTTPS 反代不传递 MQTT 证书身份。

### 健康与故障观察

### 在线修改认证授权

独立与双端共用 `broker_access_*` 表。独立节点首次启动把环境凭据写入版本 1 并标为已生效；之后每次保存形成全局单调新版本，租户只过滤主体快照。保存成功不等于集群生效：状态为 `pending` / `partial` / `effective` / `failed` / `rolled_back`。最新版本处于 `pending` 或 `partial` 时后续发布返回 `409 broker_access_publish_paused`。收紧权限（停用、撤销、轮换凭据、收窄 Topic/QoS）不能回退恢复旧授权；新认证立即按数据库事实拒绝，健康节点约 0.5 秒轮询撤权意图，5 秒内停止旧连接收发并以 `0x87` 断开。同一 Client ID 的新代次连接不会被旧意图清掉。设备无自定义授权行时沿用产品 Topic；写入覆盖行后必须逐条匹配。

| 查询范围 | 列表入口 | 当前授权 |
| --- | --- | --- |
| 独立管理 | `GET/POST /broker/access/principals`、`GET /broker/access/revisions` | `broker_admin` 且 `platform_admin`，拒绝租户和支持头 |
| 平台管理端 | `GET/POST /admin/broker/access/principals`、`GET /admin/broker/access/revisions` | `admin.broker.read` 查看，`admin.broker.write` 发布/重试/回退 |
| SaaS 当前租户 | `GET/POST /customer/tenants/{tenant}/broker/access/principals` | 路径与租户头一致，每次重验 `customer.broker.read` 或 `customer.broker.write` |

发布、重试、回退使用 POST：`/broker/access/revisions/{id}/retry` 与 `/rollback`（平台与客户路径同样加前缀）。写入使用乐观 `expected_version`，冲突返回 `409 broker_access_version_conflict`。审计动作 `broker.access.read|publish|retry|rollback`，事实只保留 `reason`。页面为 `/broker/access`、`/admin/broker-access`、`/broker-access`，可见页每 5 秒刷新版本状态。

### 受信 CA 与证书身份

独立与双端共用 `broker_access_cas`、`broker_access_certificates`，发布仍走全局单调授权版本。管理端登记单份 CA 公钥和客户端证书公钥，按指纹绑定到启用主体。节点心跳按当前受信 CA 公钥重写 `BROKER_CLIENT_CA` / `IOT_MQTT_CLIENT_CA` 握手文件，Broker 按文件更新重载 SSL_CTX，只影响新连接；已有连接保持到吊销或到期。专用 mTLS 入口按已登记指纹认证，允许无 CONNECT 凭据；同时提供账号密码时必须映射同一主体，失败不降级为仅证书。未登记但由握手 CA 签发的证书在 MQTT 返回 `0x87`；未知 CA 在握手失败。吊销证书记为收紧，健康节点约 5 秒内停止对应会话。审计只保留 `reason`，响应与日志不含私钥。

| 查询范围 | 列表入口 | 写入 |
| --- | --- | --- |
| 独立管理 | `GET/POST /broker/access/cas`、主体 `certificates` | `broker_admin` 且 `platform_admin` |
| 平台管理端 | `/admin/broker/access/cas` | `admin.broker.write` |
| SaaS 当前租户 | `/customer/tenants/{tenant}/broker/access/cas` | 路径与租户头一致，`customer.broker.write` |

### 换证重叠与旧连接退出

独立与双端在同一授权版本上给同主体正常换证写入 `overlap_until`。缺省及最长 24 小时，可显式缩短或设零，且不超过新旧证书 `not_after`。重叠期内新旧指纹都可认证；截止后旧证拒绝新认证，健康节点按既有撤权意图约 5 秒内停止对应旧连接。吊销、主体停用、收权和证书到期没有重叠窗口。迟到清理按证书凭据标识匹配，不能断开同主体新证会话。`POST .../certificate-preview` 在提交前返回新旧指纹、重叠截止和是否立即收紧，不含 PEM。版本摘要带可对账 `operation_id`（版本标识）和 `stage`（accepted/executing/completed/failed/unknown）。页面为既有授权页的换证预览与操作阶段。

### 签名 CRL 与平台吊销

独立与双端共用 `broker_access_crls`、`broker_access_crl_serials` 与 `broker_access_platform_serials`。管理员导入 PEM 签名 CRL，或登记受控 HTTPS 源（默认 300 秒，由 `broker:run` 用官方协程 HTTP 客户端拉取）。校验签名、颁发者、有效期和 thisUpdate 顺序后才持久接纳；已接纳序列号只增不减，普通回退不能复活。获取状态与执行状态分开展示：刷新失败且旧列表仍有效则继续使用；配置后缺失或过期拒绝该 CA 新接入，健康节点约 5 秒内停止相关旧连接，并给出恢复条件。平台直接吊销序列号不经 CA 签名，立即进入同一撤权流程。客户端不能指定下载地址，不查询 OCSP。尚未有节点上报且无待撤权时，版本记为已生效，避免双端管理库在启动 MQTT 前二次写入被暂停。握手 CA 文件热重载遵守证书轮换的代次与生效规则。

| 查询范围 | 列表入口 | 写入 |
| --- | --- | --- |
| 独立管理 | `GET/POST /broker/access/cas/{id}/crl`、`/broker/access/revocations` | `broker_admin` 且 `platform_admin` |
| 平台管理端 | `/admin/broker/access/cas/{id}/crl`、`/admin/broker/access/revocations` | `admin.broker.write` |
| SaaS 当前租户 | `/customer/tenants/{tenant}/broker/access/cas/{id}/crl`、`.../revocations` | 路径与租户头一致，`customer.broker.write` |

### 精确断开并保留可恢复会话

| 查询范围 | 断开入口 | 结果对账 | 写入 |
| --- | --- | --- | --- |
| 独立管理 | `POST /broker/resources/connections/{id}/disconnect` | `GET /broker/operations/{id}` | `broker_admin` 且 `platform_admin` |
| 平台管理端 | `POST /admin/broker/resources/connections/{id}/disconnect` | `GET /admin/broker/operations/{id}` | `admin.broker.write` |
| SaaS 当前租户 | `POST /customer/tenants/{tenant}/broker/resources/connections/{id}/disconnect` | `GET /customer/tenants/{tenant}/broker/operations/{id}` | 路径与租户头一致，`customer.broker.write` |

### 预览并明确终止指定持久会话

| 查询范围 | 预览 | 终止 | 结果对账 | 写入 |
| --- | --- | --- | --- | --- |
| 独立管理 | `GET /broker/resources/sessions/{id}/termination-preview` | `POST /broker/resources/sessions/{id}/terminate` | `GET /broker/operations/{id}` | `broker_admin` 且 `platform_admin` |
| 平台管理端 | `GET /admin/broker/resources/sessions/{id}/termination-preview` | `POST /admin/broker/resources/sessions/{id}/terminate` | `GET /admin/broker/operations/{id}` | 预览 `admin.broker.read`，终止 `admin.broker.write` |
| SaaS 当前租户 | `GET /customer/tenants/{tenant}/broker/resources/sessions/{id}/termination-preview` | `POST /customer/tenants/{tenant}/broker/resources/sessions/{id}/terminate` | `GET /customer/tenants/{tenant}/broker/operations/{id}` | 路径与租户头一致；预览 `customer.broker.read`，终止 `customer.broker.write` |

### 清除指定保留原件而不撤回已有交付

| 查询范围 | 预览 | 清除 | 结果对账 | 写入 |
| --- | --- | --- | --- | --- |
| 独立管理 | `GET /broker/resources/retained/{id}/clearance-preview` | `POST /broker/resources/retained/{id}/clear` | `GET /broker/operations/{id}` | `broker_admin` 且 `platform_admin` |
| 平台管理端 | `GET /admin/broker/resources/retained/{id}/clearance-preview` | `POST /admin/broker/resources/retained/{id}/clear` | `GET /admin/broker/operations/{id}` | 预览 `admin.broker.read`，清除 `admin.broker.write` |
| SaaS 当前租户 | `GET /customer/tenants/{tenant}/broker/resources/retained/{id}/clearance-preview` | `POST /customer/tenants/{tenant}/broker/resources/retained/{id}/clear` | `GET /customer/tenants/{tenant}/broker/operations/{id}` | 路径与租户头一致；预览 `customer.broker.read`，清除 `customer.broker.write` |

### 在线降低配额并保留已确认积压

| 查询范围 | 当前额度 | 预览 | 发布 / 重试 / 回退 | 当前授权 |
| --- | --- | --- | --- | --- |
| 独立管理 | `GET /broker/quotas` | `POST /broker/quotas/preview` | `POST /broker/quotas`、`/quotas/revisions/{id}/retry`、`/rollback` | `broker_admin` 且 `platform_admin` |
| 平台管理端 | `GET /admin/broker/quotas` | `POST /admin/broker/quotas/preview` | 对应 `/admin/broker/quotas*` | 读取 `admin.broker.read`，发布 `admin.broker.write` |
| SaaS 当前租户 | `GET /customer/tenants/{tenant}/broker/quotas` | `POST .../quotas/preview` | 写入一律 403 | 路径与租户头一致；只读 `customer.broker.read` |

### 滚动应用监听、节点证书和运行配置

独立与双端共用 `broker_runtime_revisions` / `broker_runtime_node_states`。管理员先预览拟保存的监听、端口、I/O 与 Origin，再以 `confirmed: true` 提交；页面只校验并保存，不在应用内重启或滚动分发节点。保存成功不等于节点已加载。节点上报实际加载身份（监听、端口、I/O、明文、Origin 与服务端证书 sha256，不含私钥）后，版本呈现待生效、部分生效或已生效。不安全组合（明文与 mTLS/WSS 混用、WS 却未开明文、非 swoole 开 WS/WSS/mTLS、端口冲突或缺失）返回 `422`。收紧变更（关闭明文、新开 mTLS/WSS、从空 Origin 加白名单或删去已有 Origin）不允许回退，返回 `409 broker_runtime_tightening`。普通监听变更允许显式回退。租户不能写入全局运行配置，返回 `403 forbidden`。握手 CA 不进入本表：节点心跳按当前受信 CA 公钥重写握手文件，Broker 按 mtime 对监听 `Port::set` 重建 `SSL_CTX`，只影响新连接。`Server::set` 在启动后会被拒绝。

| 查询范围 | 当前配置 | 预览 | 保存 / 重试 / 回退 | 当前授权 |
| --- | --- | --- | --- | --- |
| 独立管理 | `GET /broker/runtime` | `POST /broker/runtime/preview` | `POST /broker/runtime`、`/runtime/revisions/{id}/retry`、`/rollback` | `broker_admin` 且 `platform_admin` |
| 平台管理端 | `GET /admin/broker/runtime` | `POST /admin/broker/runtime/preview` | 对应 `/admin/broker/runtime*` | 读取 `admin.broker.read`，保存 `admin.broker.write` |
| SaaS 当前租户 | `GET /customer/tenants/{tenant}/broker/runtime` | `POST .../runtime/preview` | 写入一律 403 | 路径与租户头一致；只读 `customer.broker.read` |

### 使用短期凭据进行授权 WSS 实时订阅

独立与双端共用 `broker_debug_credentials`，不写入接入主体快照。登录人员通过业务 API 签发 MQTT 专用短期凭据：最长 10 分钟且不超过剩余登录或模拟会话，口令只在签发响应出现一次。凭据绑定主体、当前租户或独立引导 Topic 前缀；订阅授权前缀，发布仅测试前缀 `{prefix}debug/`。CONNECT 要求 Clean Start、会话期限 0、无遗嘱、Keep Alive 30；调试连接按 application 分类，不占用设备名额。退出登录、换租户或重新签发会排队撤权，健康节点约 5 秒内断开旧连接。人员登录令牌不能当作 MQTT 口令。平台管理端不能签发或读取租户调试载荷。接收窗口最多 1000 条或 8 MiB，离线不积压。调试连接遵守名额限制和业务连接优先规则。

| 查询范围 | 当前凭据 | 签发 | 撤销 | 当前授权 |
| --- | --- | --- | --- | --- |
| 独立管理 | `GET /broker/debug` | `POST /broker/debug` | `POST /broker/debug/revoke` | `broker_admin` 且 `platform_admin` |
| 平台管理端 | `GET /admin/broker/debug` | 一律 403 | 一律 403 | 页面存在；不能取得租户载荷 |
| SaaS 当前租户 | `GET /customer/tenants/{tenant}/broker/debug` | `POST .../debug` | `POST .../debug/revoke` | 路径与租户头一致；`customer.broker.read` 可签发订阅凭据 |

### 有界调试发布并为业务服务优先释放名额

调试连接共享既有 100 个服务名额，每人最多 2 个、全局最多 20 个，按真实身份和 Client ID 计额：重连、跨标签页、跨节点或更换传输不额外占用。名额不足时 MQTT 5 CONNECT 返回 `0x97`。本节点 application 名额已满时，新的非调试业务服务可断开一条本地调试连接（DISCONNECT `0x98`）并在界面说明业务服务优先；不踢设备或其他业务会话，也不跨进程抢占。测试发布仅 `{prefix}debug/`，按实际 QoS 显示未知或 MQTT 回执，不把 MQTT 确认当作业务成功，设备控制仍走业务 API。接收窗口 1000 条或 8 MiB 淘汰最旧显示并给出计数，不删除 Broker 持久消息；发布等待 5 秒、载荷 64 KiB。隐藏或离开页面断开自有连接。

### 升级和回退后保留会话及新增管理状态

独立 `broker:install` 与双端 `app:install` 在空库或增量计划上应用 `036_broker_compatibility`，并记录兼容代次 18。该代次覆盖占用名额、CRL 粘性序列与当前会话/配额/运行配置语义；最低代次只升不降。`broker:serve` 与 `broker:run` 在启动时核对库代次，高于本二进制时以 `broker_compat_runtime_stale` 拒绝启动，并给出维护步骤：继续使用当前原生产物；若必须回到旧应用，先按备份恢复任务从已核对撤销事实的备份恢复；不能删除 `broker_debug_occupancy`、`broker_access_crl_serials`、`broker_access_platform_serials` 或 `broker_compatibility` 来假装兼容。从备份恢复后须执行下述授权核对流程。缺少兼容表时允许启动，以便先写入真实会话再执行当前安装。迁移中断须 `broker:migrate recover` 后重试，未知不当成功。

| 查询范围 | 入口 | 写入 | 当前授权 |
| --- | --- | --- | --- |
| 独立管理 | `GET /broker/compat` | 无写入入口 | `broker_admin` 且 `platform_admin` |
| 平台管理端 | `GET /admin/broker/compat` | 一律 405 | `admin.broker.read` |
| SaaS 当前租户 | `GET /customer/tenants/{tenant}/broker/compat` | 一律 405 | 路径与租户头一致；只读 `customer.broker.read` |

独立节点页展示代次、危险回退已阻止及上述维护步骤。租户与平台只读查询同一快照。

### 恢复旧备份后核对证书与调试授权再开放

独立 `broker:install` 与双端 `app:install` 应用 `037_broker_recovery`（及独立 `027_broker_operation_recovery`、双端 `101_app_broker_operation_recovery`），兼容代次 19。接入主体、凭据、证书、CA、调试凭据、接入/配额/运行修订和操作账本增加 `recovery_verified`；CRL 与平台吊销序列没有该列，核对时把当前清单里缺失的序列并入粘性表。独立命令为 `broker:recovery snapshot|status|begin|isolate|review|restore`；双端继续 `iot:recovery`，同一状态机会走 Broker 种类，避免已撤销凭据在 IoT 恢复 ready 后复活。`snapshot` 只输出 `{kind,id,sha256}` 和 CRL/平台序列的 `data`，口令散列与 PEM 不出现在清单里。

物理恢复复用 `iot:wal` 与 PostgreSQL 17 `pg_basebackup`/`pg_combinebackup`/`pg_verifybackup`。到达 `recovery_target_action=pause` 的只读点不是核对完成，`begin` 必须在提升之后。核对期间 `broker:serve`/`broker:run` 返回 `recovery_isolated`；源库进程已退出后，旧备份里仍为 `active` 的 MQTT 节点在 restore 时登记为 `fenced`，否则新运行无法 `node_open`。未匹配主体保持隔离，不能把只读暂停或旧快照标成恢复完成。核对完成后删除管理登录会话；匹配的 MQTT 持久会话与 QoS1 积压仍按标准客户端恢复。每条只读查询使用新的审计操作 ID，不能把恢复标识当成可重复写入的操作主键。

| 查询范围 | 入口 | 写入 | 当前授权 |
| --- | --- | --- | --- |
| 独立管理 | `GET /broker/recovery` | 无写入入口 | `broker_admin` 且 `platform_admin` |
| 平台管理端 | `GET /admin/broker/recovery` | 一律 405 | `admin.broker.read` |
| SaaS 当前租户 | `GET /customer/tenants/{tenant}/broker/recovery` | 一律 405 | 路径与租户头一致；只读 `customer.broker.read` |

独立节点页展示核对宿主与进度；授权页和调试页展示 `recovery_verified`。租户与平台只读查询同一进度。

### 传输与身份组合的标准互操作

互操作测试分别覆盖口令 TLS/WSS 与证书 mTLS/WSS，核对协议条款、Origin、mTLS 与管理动作的组合。独立 `mqtt.js` 5.15.0 覆盖双版本全部 QoS、二进制、通配/共享/保留/遗嘱/别名、跨传输、MQTT 5 显式 Session Expiry 86400、MQTT 3.1.1 CleanSession=0 无内部 24 小时 TTL，以及 CONNACK Receive Maximum=32、Topic Alias Maximum=32、Maximum Packet Size=1 MiB。原始 WebSocket 帧核对子协议 `mqtt`、文本帧 1003、拒绝 Origin 1008、半包重组、超限 0x95 与 Keep Alive 30 秒的 1.5 倍超时。管理断开对占用会话发送 `0x98` 后，其余客户端仍按标准跨传输收发；清除保留不改变该语义。16 KiB 业务 JSON 作为 MQTT 载荷正向样本。页面为既有 `/broker/debug` 与 `/broker/resources`。

### 独立安装完整候选与持久回执

独立管理宿主与物联组合继续使用不同数据库和账号域。管理密码只通过受控进程环境 `BROKER_ADMIN_PASSWORD` 提供；MQTT 接入密码、探针令牌和数据库口令同样不进命令参数、文档或前端。权限边界：

| 宿主 | 安装 | 登录与权限 | 不得携带 |
| --- | --- | --- | --- |
| 独立 Broker | `broker:install`；可靠接收另执行 `broker:store-install` | `broker_admin`；`POST /broker/auth/login` | 租户头、`X-Support-Id`、物联网人员表 |
| 物联组合 | `app:install`；MQTT 另执行 `iot:mqtt-install` | 平台 `admin.*` / 客户 `customer.*`；Broker 资源只读另需对应 `broker.read` | 独立管理令牌、旧 `/iot/*` |

配置、监控、升级与恢复沿用既有公开入口，不另造内部旁路：

```sh
# 独立管理：空库安装、账号、HTTP 与节点。生产入口是已发布的 run / 原生产物，不能回退 bin/typeapp。
./run broker:install
./run broker:store-install
./run broker:user administrator 管理员
./run broker:serve
./run broker:run

# 探针只证明本管理 HTTP 与已观察节点，不是业务回执。
curl -fsS -H "Authorization: Bearer ${BROKER_PROBE_TOKEN}" http://127.0.0.1:PORT/broker/health/live
curl -fsS -H "Authorization: Bearer ${BROKER_PROBE_TOKEN}" http://127.0.0.1:PORT/broker/health/ready
curl -fsS -H "Authorization: Bearer ${BROKER_PROBE_TOKEN}" http://127.0.0.1:PORT/broker/metrics

# 证书身份、调试签发、运行配置与恢复核对走人员会话。
# GET /broker/access  GET /broker/debug  GET /broker/nodes  GET /broker/recovery
# 升级前核对兼容代次；备份恢复后执行授权核对。
./run broker:recovery snapshot
./run broker:recovery status
```

独立候选测试使用 `composer test:broker-candidate`；原生入口为 `php tests/broker-candidate.php build/app/type-app --no-source --independent`。独立安装需要 `TYPE_COMPOSER_PHAR`，持久接收链需要 `TYPE_PGSQL_TOOLS`。验证同时观察协议确认、业务持久回执、公开当前值与设备缓存清理，完整平台、容量和故障验证另行执行。

### 独立安装与登录

`tests/iot-identity.php` 的 `--broker` 场景复用实际应用命令及 HTTP，验证独立安装、账号隔离、权限、真实 MQTT 连接计数、正常停止和审计；`tests/iot-identity-databases.php` 可追加同一参数逐库运行，原生可追加 `--no-source`。
