# 固定物模型与混合负载

## 固定输入

完整输入为 100 租户×10 产品×10 设备；每台设备上报 20 个数值属性及各 2 个布尔、枚举和字符串。设备索引为 0–9999，轮次、字段和种子独立决定数值，重启或调整分片顺序不改变同一样本。每 10 秒生成一条遥测，索引决定 0–9999 毫秒的错峰；每 100 台中的固定一台在每个 600 秒周期前三条遥测越界，随后三条恢复。四条阈值规则覆盖全部设备，共 40000 条设备规则。

事件为每设备 600 秒一条 `inspection`，控制为每租户 60 秒一次 `set_relay`，均单独计数。100 个 Web 会话使用 100 个独立租户账号，每 5 秒查询一次，前 50 个查询普通设备列表，后 50 个查询固定种子选中的单设备 24 小时曲线。小规模分片按实际覆盖租户运行，不能宣称已运行全部 Web 会话。

在项目根运行以下命令，冻结完整 JSON 样本及 10000 个样本的 UTF-8 字节分布：

```sh
node tests/iot-load.mjs samples > build/iot-load-samples.json
```

## 准备与运行

需要支持 `node:sqlite` 的 Node、锁定 MQTT.js 5.15.0 和已完成迁移的隔离 IoT 环境。本机工具验证使用 Node 26.5.0；正式被测应用仍执行全量 TypePHP AOT，Node/PHP 工具不进入生产编译范围。`TYPE_MQTT_CLIENT_ROOT` 指向安装 MQTT.js 的外部依赖根；工具按配置文件位置解析 `state` 和 `ca`，脚本依赖按脚本自身位置解析。

配置 JSON 不保存密码。以下是单设备小规模示例；HTTP 明文只允许回环测试地址，外部服务使用 HTTPS，MQTT 必须使用 TLS 并验证证书：

```json
{
  "baseline": "iot-baseline-v1",
  "namespace": "load-check",
  "devices": 1,
  "http": "https://iot-load.example.test",
  "platform_login": "load-platform",
  "owner_logins": ["load-owner-000"],
  "state": "state/load.sqlite",
  "mqtt": "mqtts://mqtt-load.example.test:8883",
  "ca": "certificates/ca.pem",
  "seconds": 60,
  "shard_start": 0,
  "shard_count": 1,
  "cache_disk_mib": 64,
  "maximum_rss_mib": 1024
}
```

将配置保存到本次隔离运行目录，设置 `TYPE_LOAD_PLATFORM_PASSWORD` 与 `TYPE_LOAD_OWNER_PASSWORD` 后，从项目根执行：

```sh
node tests/iot-load.mjs prepare build/load-check/config.json
node tests/iot-load.mjs run build/load-check/config.json
node tests/iot-load.mjs inspect build/load-check/config.json
```

账号须预先通过正式用户配置入口建立。`owner_logins` 必须唯一，数量恰好为 `ceil(devices / 100)`；完整输入需要 100 个租户所有者账号。准备阶段通过正式 HTTP 创建租户、产品、发布模型、设备和规则，并保存一次性设备凭据及恢复进度；第二次准备复用已有结果，发现同名对象不唯一时拒绝猜测。状态目录与 SQLite 文件仅允许当前运行者访问，保存的设备凭据属于秘密。

一次分片最多 1000 台设备，默认最多 250 台，持续时间为 1–86400 秒。不同分片共享准备状态的只读锁，各自独占缓存；准备时需独占状态锁，进程真实退出后由 SQLite 释放，不能按文件时间推定旧进程失效。完整规模的分片不得重叠，运行前核对分片总数、设备总数与实际 Web 会话数。

发送原件先进入 FULL 同步 SQLite，每设备最多 10000 条或 16 MiB 未确认消息，分片另有磁盘页数上限。每设备最多一个业务在途；收到并持久保存匹配身份与内容摘要的业务回执后才释放原件，PUBACK 不释放原件。拒收、回执超时、采样错过和生成器资源不足分别进入统计。未确认原件在满额时保留。

## 阶段速率、集中重连与稀疏租户

`phases` 在同一次在线运行内切换采样倍率，最多16个具名阶段，倍率为1–3，阶段秒数之和必须等于 `seconds`。倍率只影响新遥测；事件、控制和Web查询继续产生负载，平台须按正常方式运行聚合与告警消费者。每阶段按原设备索引错峰，序号和本地缓存连续；数值仍由固定种子和采样序号决定，固定1%设备每600秒只有前三条遥测越界，随后恢复，不因三倍率改成每200秒触发。

以下仅为两台设备、两个真实租户的90秒工具检查。`device_indices` 严格递增且数量等于 `devices`，保留原0–9999坐标，因此0号租户执行列表，50号租户执行曲线。`owner_logins` 按实际所选租户顺序对应，仍使用各自真实权限。分片起点/长度表示所选数组位置；省略稀疏选择时与原连续基线相同。历史 `seed` 暂不接受稀疏选择。

```json
{
  "devices": 2,
  "device_indices": [0, 5000],
  "owner_logins": ["load-owner-000", "load-owner-050"],
  "shard_count": 2,
  "seconds": 90,
  "phases": [
    {"name": "steady", "seconds": 30, "multiplier": 1},
    {"name": "burst", "seconds": 10, "multiplier": 3},
    {"name": "recovery", "seconds": 50, "multiplier": 1}
  ],
  "reconnect_seed": "typeapp-iot-small-reconnect-v1",
  "reconnect": {"after_seconds": 60, "offline_seconds": 2}
}
```

以上字段合并到完整连接配置中。阶段计划要求新建在线缓存，不用于改变已生成的24小时离线采样时间。计划、重连种子和主动断连时刻持久保存，重试不能改变原计划、延长截止或重新注入同一断连。集中断连使用当前分片的真实MQTT连接；恢复计时从记录的允许恢复时刻开始，退避由种子、原设备索引和持久连接次数决定，最大6.9秒。TLS证书核验、认证和标准会话过期继续生效，可信时间挑战保持安全随机数。

真实短时联调复用现有装置：

```sh
php tests/iot-device-mqtt.php "$TYPE_LOAD_NATIVE_APP" --ingestion --load-mqtt --load-phases --no-source
```

## 24 小时设备缓存

将同一配置的 `seconds` 设为 `86400`，运行 `cache`：

```sh
node tests/iot-load.mjs cache build/load-check/config.json
```

该入口按固定历史起点生成设备本地积压，不向平台发送。完整规模预期为 86400000 条遥测、1440000 条额外事件；单设备 8640 条遥测加 144 条事件，低于 10000 条上限。第一次生成后保存游标，重跑不重复生成；同一缓存不得修改离线持续时间、种子或设备归属来冒充恢复。随后使用匹配配置的在线 `run` 排空并对照平台回执，不能以 `cache` 成功证明实际重连和排空速率。

## 可恢复历史预置

`seed` 只为查询和容量准备历史。目标必须是专用数据库 `type_app_test` 或 `type_iot_load_*`，同时校验 PostgreSQL 的真实 `system_identifier`。生成前还从公开接口核对设备、归属和冻结模型；不同身份、改变窗口或重叠分片均拒绝继续写入。

在运行配置增加以下对象，`end_utc` 由操作者设为固定的历史 UTC 分钟边界且至少早于当前一分钟，不能每次重试重新计算：

```json
{
  "history": {
    "end_utc": 1789257600,
    "raw_seconds": 604800,
    "aggregate_seconds": 7776000,
    "database": "type_iot_load_check",
    "system_identifier": "需替换为目标数据库实际数字标识",
    "max_batches": 1
  }
}
```

从受控数据库读取 `SELECT current_database(), system_identifier::text FROM pg_control_system()`，将实际标识写入配置。设置 `TYPE_LOAD_SEED_HOST`、`TYPE_LOAD_SEED_PORT`、`TYPE_LOAD_SEED_USER`、`TYPE_LOAD_SEED_PASSWORD`；`TYPE_LOAD_PHP` 可指定具有 PDO PostgreSQL 的 PHP 可执行文件，未设置时使用 `php`。然后运行：

```sh
node tests/iot-load.mjs seed build/load-check/config.json
```

`max_batches` 适合验证断点，每批最多 128 行；省略后继续至完成。数据与游标在同一事务提交，同 namespace 的真实数据库会话锁串行化写入。SIGTERM/SIGINT 在当前批次结束后记录进度；异常退出由 PostgreSQL 回滚未完成事务，重启复用已提交游标。Node 与 PHP 工作进程每次交换共用 120 秒截止和 2 MiB 输出预算，退出失败会记录为失败。

原始数据使用 `synthetic` 接收账本和 `load_history_seed` 标记，回执 nonce 为空、请求时间为零，不伪造 `accepted` 或同步确认；历史数据不推进设备当前状态、不重复触发实时消费者。固定一行分钟聚合包含 20 个数值属性的 JSON 统计。

| 完整规模存储项 | 逻辑行数 |
| --- | ---: |
| 7 天遥测原始事实 | 604800000 |
| 配套合成接收账本 | 604800000 |
| 配套三个消费者完成标记 | 1814400000 |
| 90 天设备分钟聚合 | 1296000000 |

## 已有验证与剩余范围

发生器自身资源回归不需要平台或旧测试目录，在项目根运行 `node tests/iot-load-resource.mjs`。它使用自有临时 SQLite 和真实子进程验证双读者/独占准备、进程强制退出释放锁、构造失败释放锁、RSS不足仍保存失败报告，以及10000条满额拒绝后未确认原件保持不变；结果只说明发生器资源语义。

当前组合产物已完成原生禁源码、真实TLS及同步主备的单设备90秒混合验证：9条遥测、1条事件均得到业务回执，2个计划控制均受理且执行成功，19次列表请求成功，四条告警恢复并产生8条站内通知。公开历史与回执的消息身份、采样时间和接收时间逐条一致，接收进程、HTTP服务和客户端资源释放。中间发生2次指令结果查询失败，最终状态均为成功，该失败计数保留。

没有三倍阶段或主动重连的稳态输入仍然失败；在相同稳态输入上，分别移除运维采样、sandbox前缀或控制命令，也未消除回执超时。首个收发探针中，两台设备首条遥测已分别在2.102秒和2.821秒取得PUBACK，但业务回执到达耗时仍为10.685秒和10.123秒。共享队列部分较早消息尚未领取时，较晚消息已推进；31次稀疏数据库采样未观察到锁或同步复制等待，不能排除采样间隔中的短等待。对照设备PUBACK、消息类型和创建时间后，未观察到同一已确认下行交付整轮卡住，不能仅凭复用的包标识符判定此问题。

当前已收敛到共享领取和接收角色在附加业务消息加入后的处理延迟，具体生产根因仍未确认。接收角色的同步持久化、回执发布和Broker持久工作进程需要进一步分段计时；停止时保留的发布未知须与运行中超时分别核对。临时探针已移至本次调试目录，正式工具无诊断开关、生产源码未修改、没有重新AOT；原90秒失败与全部正式门槛保持不变。
