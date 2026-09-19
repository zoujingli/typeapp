# HTTP 背压、部署预算与停止

使用已有 ExecutionScope、Deadline 和 ResourcePool，补齐入口并发与跨身份连接总量。标准 PHP 的真实慢 MySQL、压力、worker 替换和收尾已验证，原生验收按当前顺序集中完成。

`HttpControl` 默认每 worker 64 个在途请求、256 个连接、每请求 30 秒、16 个受管子任务、1 秒清理预算与 5 秒停止预算。可作为 SwooleServer 的第六个构造参数显式替换。请求额度一直保留到响应流、作用域和消息流清理结束；没有为等待者创建额外队列。入口已满或停止接收时返回 503 和 Retry-After，数据库共享预算不足也转换为明确的 503。

Swoole 同时设置接收连接上限、包长、空闲连接检查与总协程数；每个请求的子任务树继续共享同一个 TaskBudget 和原 Deadline。正文读取前的接收缓冲由连接数和包长约束；这些内存与处理中的请求内存分别计入部署容量。multipart 暂存的硬文件系统上限仍通过 RequestLimits 配置，见[上传下载说明](file-streams.md)，不能把连接额度当作实际磁盘分区配额。

## 多身份与多进程数据库预算

```php
$budget = new Type\Runtime\DeploymentBudget(
    serverLimit: 60,
    replicas: 3,
    surge: 1,
    processesPerReplica: 6,
    administrationReserve: 12,
);
$databases = new Type\Orm\DatabaseManager($drivers, capacity: 4, idleLimit: 0, budget: $budget);
```

这里每个进程最多分到 2 个数据库资源槽位，全部部署上限为 `(3 + 1) × 6 × 2 = 48`，另留 12 个管理连接。进程数必须覆盖 HTTP、queue、scheduler、Outbox 等实际使用同一数据库服务的全部角色；新旧应用共存也不能超过声明的副本增量。维护者需将这份声明与真实部署及数据库上限保持一致，它不会自动发现 Kubernetes 副本或修改数据库配置。

一个预算对象注入同一进程内该数据库服务的全部 Database/DatabaseManager。命名连接、租户、reader/writer 和新旧凭据代次共同占用容量，旧代未排空不能因为新建连接池绕过上限。不同物理数据库服务可分别提供预算；不要为同一个容量域反复新建互不相干的预算对象。框架现有按用途隔离的 Redis 池仍使用各自配置，不与数据库共享物理连接额度。

预算在第一次借用时才绑定进程，可在 fork 前准备静态配置；已经使用的预算或原生连接不能跨进程继承。借用失败、归还、退役和关闭都会保持计数守恒。池等待者固定为 0，等待秒数为 0；容量不足立即拒绝。统计包含借用、拒绝和重置异常计数，标签只采用有限配置的连接名称，不加入任意租户输入、SQL、请求 ID。

## 探针与监督

显式设置 `HttpControl(..., probes: true)` 后启用 GET /readyz 和 /livez，二者绕过业务请求额度。超载或排空时就绪为 false，存活仍为 true；探针不连接数据库、不运行应用处理器，也不泄漏连接配置。没有开启时这两个路径继续按普通路由处理。

`HttpControl::stop()` 先拒绝新工作，再把所有在途作用域的截止和清理时间缩短到同一排空截止。Swoole 的 workerExit 同样进入此路径；SIGTERM 使用扩展已有的停止机制，不覆盖其保留信号处理器。

HTTP 使用 Swoole BASE 模式，固定一个 worker 并设置 100000 次请求的轮换上限，使该模式也启动独立 manager 监督进程。请求清理超时后，仍活动的 Scope 被隔离并继续占用请求与数据库预算；停止接收新工作，worker 定时检查确认未能收尾时终止自身，manager 重新创建干净 worker。没有在原生 I/O 仍活动时归还或关闭其句柄。被终止的事务或外部写入仍可能结果未知，必须沿用业务幂等与对账规则，不能将进程终止当作回滚。

这项进程内检查依赖事件循环能获得执行机会。永久 CPU 循环、扩展或内核调用完全阻塞进程时，部署监督器还必须有独立存活探针和最终 SIGKILL 时限；框架不能声称合作式 Deadline 可抢占任意原生调用。日志沿用 type-log 有界输出，进程异常和容量指标应由外部采集汇总。

## 实际验证

`tests/backpressure-http.php --php` 使用真实 MySQL 和两种命名身份，共享每进程 2 条连接。384 个并发压力请求分八轮执行，每轮 4 个成功、44 个返回 503；超载就绪为 false，存活正常；停止压力后数据库槽位归零、内存增量小于 16 MiB。延迟 SQL 用完原预算后返回 504，下一请求可以成功。

同一测试还让子任务 SQL 持续超过清理预算，核对旧 worker PID 被实际终止、新 PID 恢复服务，然后主动撤销就绪、拒绝新请求并完成已有请求后退出。使用日志公共接口记录各请求；文件、PSR、路由、模型 HTTP 和租户接口保持回归覆盖。

`tests/deployment-budget.php` 使用实际 SQLite 租约验证凭据轮换期间不能额外占用容量、旧代连接仍有效、关闭后归还以及 fork 隔离。原生 HTTP 入口为 `docs/build-config/type-backpressure-http.json`，复用相同外部断言。
