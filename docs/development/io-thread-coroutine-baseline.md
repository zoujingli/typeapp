# I/O 验证方法

I/O 优化沿用 Swoole 的线程、协程、Socket、等待及文件机制，代码职责见[Swoole 复用标准](../standards/swoole-reuse.md)。性能结论使用固定源码、锁定工具链、同一产物与相同负载，当前待完成项见[实现规划](../guide/roadmap.md)。

## 调用与资源边界

逐条追踪 HTTP、MQTT、数据库、Redis、文件与后台任务的实际调用者，记录建连、等待、读写、取消及关闭由谁负责。协程等待结束不代表底层操作已经退出；资源额度直到真实清理完成才归还。同步业务授权、持久工作启动与 IPC 也属于端到端耗时，不能只测 Socket 或 SQL。

## 观察入口

现有测试覆盖已编译线程、独立资源租约、网络客户端/服务端、设备上报与业务接收回执。设备回执测量使用公开 MQTT 客户端、真实 PostgreSQL 与应用的持久工作入口：

```sh
TYPE_IO_SAMPLES=30 TYPE_IO_WARMUP=5 php tests/iot-device-mqtt.php \
  "$TYPE_BASELINE_APP" --ingestion --io-receipt-baseline --no-source
TYPE_IO_SAMPLES=5 TYPE_IO_WARMUP=1 php tests/iot-device-mqtt.php \
  "$TYPE_BASELINE_APP" --ingestion --io-receipt-baseline --io-receipt-db-trace --no-source
```

`TYPE_BASELINE_APP` 指向待测产物，使用其真实运行配置与相邻 `php.d` 目录；SDK、PHPX 和数据库工具由环境显式提供。分别记录持久工作完成、回执发布至 PUBACK、设备消费三个时间边界，预热和慢依赖样本与正常分位数分开。

数据库诊断只记录连接身份和耗时，不记录 SQL 文本及参数；以会话标识关联事件，重叠区间取并集，不能把多个会话耗时相加作为墙钟时间。独立运行日志与阶段报告可重新分析：

```sh
php tests/iot-device-mqtt.php --analyze-receipt-db-trace "$TYPE_TRACE_LOG" "$TYPE_TRACE_PHASES"
```

完整验收还需每个目标平台的全量 AOT、三库真实语义、容量、取消、停止及故障恢复。单路径测量不代表高可用、目标设备规模或全部 I/O 已完成。
