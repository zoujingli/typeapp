# 设备本地持久缓存

`app\iot\service\DeviceBuffer` 复用 `type-orm-sqlite` 的 WAL、`synchronous=FULL`、一秒 busy timeout，以及已有迁移与事务入口；网络及设备命令见[模拟器说明](iot-device-simulator.md)。

## 公开调用

```php
$buffer = new \app\iot\service\DeviceBuffer(
    filename: 'runtime/device-buffer.sqlite',
    deviceId: $registeredDeviceId,
    ownershipId: $registeredOwnershipId
);
try {
    $record = $buffer->enqueue(1, 'telemetry', time(), (object) ['temperature' => 20.0]);
    // null表示容量拒绝；完整原字节保存在record.payload，补传读取pending()。
    $pending = $buffer->pending(10);
    $status = $buffer->statistics();
    // 网络调用者收到并验证平台业务回执来源后：$buffer->applyReceipt($receipt);
} finally {
    $buffer->close();
}
```

路径相对调用进程的工作目录解析，父目录必须已存在。一个文件绑定一个设备及一个归属阶段；重开时设备、阶段和四项容量必须相同。不要删除文件或换新文件来重置同阶段序号。模型版本属于每条固定原件，调用者在明确模型切换后可以传入新的版本，旧原件不被重写；归属转移和缓存排空由设备生命周期流程负责。

| 接口 | 行为 |
| --- | --- |
| `enqueue(int $modelVersion, string $type, int $sampledAt, stdClass $values, string $identifier = ''): ?array` | `telemetry`不带identifier，`event`必须带identifier。同一事务递增十进制序号并保存一次生成的完整JSON、模型和摘要；成功只证明本地入队。 |
| `pending(int $limit = 1): array` | 最多100条，按十进制序号排列，返回`sequence/model_version/payload/payload_bytes/content_hash`；不重新序列化、不标记已发送。 |
| `applyReceipt(array $receipt): bool` | 精确校验当前设备、阶段、序号、摘要和完整`ingestion_receipt`。本次处理返回true；不存在或已处理返回false；无效回执抛异常且原件不变。 |
| `exceptions(int $limit = 100): array` | 最近终局异常，含原载荷、模型、序号、摘要、拒绝原因和平台时间；上限100条。 |
| `statistics(): array` | 返回持久序号、额度、占用、容量拒绝原因与累计计数，不含秘密和原载荷。 |
| `snapshot(int $modelVersion): array` | 同一事务分配共享持久序号，冻结至多2KiB的`device_status`观察。即使缓存满也能生成；不占用可靠采样空间，不改变任何待确认原件。 |
| `close(): void` | 幂等释放本地资源；后续读写抛`device_buffer_closed`，不改变持久记录。 |

`content_hash`统一使用`IngestionService::contentHash()`，保留接收端精确十进制和对象键归一化规则。不能用原JSON字节的SHA-256或普通浮点重编码替代该摘要。发送始终使用保存的原字节；构造入队数据的对象后续变化不影响原件。

缓存观察使用`app_version/type/device_id/ownership_id/model_version/sequence/sampled_at/values`封装，`type=device_status`。values包含额度、待确认占用、未接纳计数、终局异常及累计接受/拒绝/淘汰计数、full、capacity_reason和counters_saturated。它不进入可靠采样账本，不等待`ingestion_receipt`，也不触发采样清理。网络丢失时下一次运行重新观察；页面只能显示最近收到的观察，不能推定失联设备的当前余量。

## 额度和终局结果

默认`maximumRecords=8640`、`maximumBytes=8640000`，足够容纳每10秒一条、完整JSON为1000字节的24小时基线。SQLite行、索引、迁移记录、WAL与恢复空间另外计量。同一纯载荷额度只能容纳527条16KiB报文，不能宣传为同空间24小时。显式调整额度时，条数范围1–86400、字节范围128–1073741824；每条业务JSON最多16384字节。

条数或字节先不足时返回null，同步增加`not_admitted`，不占用序号或覆盖未确认旧记录。`full`表示实际触及额度或最近一次容量拒绝，`capacity_reason=records|bytes`说明拒绝原因；完成一条回执后解除该拒绝观察。`remaining_bytes`仅是剩余载荷空间，不能当作可接纳条数。

只有`accepted/accepted`业务回执移除成功记录并增加`accepted_total`。`rejected`必须携带当前已定义的永久原因之一：`content_conflict`、`device_unavailable`、`invalid_envelope`、`model_mismatch`、`clock_ahead`、`sample_expired`、`invalid_model_values`。该记录离开待补传集合，原件和原因保存在独立终局异常集合，增加`rejected_total`，不计作成功。新增协议原因须同步接收端与设备端契约。

异常默认最多128条、1048576字节原载荷；`maximumExceptions`范围1–1000，`exceptionBytes`范围16384–16384000。先达到任一额度时淘汰最旧终局异常并持久增加`exceptions_dropped`，待确认原件不受影响。异常元数据另计。累计计数达到有符号64位上限后保持饱和并置`counters_saturated=true`，显示的计数成为下界，不能回绕归零。

## 验证入口

```sh
php tests/iot-device-buffer.php
php tests/iot-device-buffer.php --native
```

测试只创建`build/iot-device-buffer-*`内的隔离SQLite，实际验证入队、错误回执、终局异常、双额度、8640×1000字节、16KiB区别、SIGKILL跨进程恢复、38位序号边界及真实SQLite写失败回滚。边界预置通过隔离数据库准备，结果仍从公开入口观察。

测试建立独立消费应用，完整复制IoT域的生产文件并实际安装主仓声明的全部生产组件；`--native`全量编译独立应用及其生产依赖，不遗漏声明入口，不据此声明默认HTTP/MQTT路径通过。macOS另外搬迁二进制并通过内核禁止读取生产源码和生成实现。平台回执同步证明、真实MQTT缓存补传、平台七天去重清理、系统状态和Web页面仍由完整接线验收，纯缓存测试不替代这些范围。
