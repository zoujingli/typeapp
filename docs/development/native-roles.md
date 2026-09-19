# 独立运行角色的无源码部署验收

HTTP、数据命令与迁移已有[模板 scratch 证据](template-deployment.md)；此前集成 Scenario 在同进程组合 Outbox、queue、scheduler，不足以证明它们分别从无源码部署启动。

## 复用与可观察结果

| 独立进程 | 既有 ELF 参数 | 通过其公开入口观察到的结果 |
| --- | --- | --- |
| 数据命令 | `sqlite setup` | 在无网络的容器内迁移并原子保存业务和 Outbox 意图；回滚意图没有残留，不需要连接 Redis |
| Outbox relay | `sqlite relay` | 另一个容器读取前一进程的意图，向专用 Redis 发布一条消息并按 token 保存接受凭据 |
| queue worker | `sqlite consume-replay` | 第三个容器调用已有单消息消费模式，严格核对一个数据库业务效果、接受/消费凭据和保留窗口；并非宿主消费 |
| scheduler 帮助 | `help` | 无网络配置下返回调度帮助 |
| scheduler 执行 | `once` | 固定时钟下两个有限补跑任务实际完成，返回“计划任务已完成”及执行记录 |
| scheduler 重启 | `work 2 1` | 新容器、新 revision、有界两轮执行，持久游标阻止重复计划，返回两行空结果 |
| scheduler 历史 | `history` | 再一个容器读取相同 occurrence ID 和真实完成时间，证明跨进程状态保留 |

`consume-replay` 是 Outbox 演练程序的单消息 worker 模式名称，初次消费与重放由实际消息状态区分。消息来自前一个独立 relay 进程，SQLite 文件在本轮专用数据卷中共享，不能用各进程独立的内存数据库替代。

每次运行应记录独立容器 ID、`/app/type-app` 进程、准确参数、起止时间和退出码。应用镜像不含 PHP CLI、Composer 或业务 PHP/SDK 源码，只挂一个本轮数据卷，根目录只读、capabilities 为空、禁止提权、不共享宿主 PID。镜像内 ELF 的摘要等于构建产物；库身份、资源和准确 ELF 白名单沿用打包器与既有镜像检查，不另造解释路径。调度的四个进程及数据命令禁网；只有 relay 和 worker 进入本轮私有测试网络。

## 运行入口

本地已准备锁定 Linux 工具链镜像、根 Composer 依赖和 PHPX SDK 时执行：

```bash
bash tools/test-native-roles.sh
```

Linux CI 使用当前已安装的 PHP/Composer、`PHP_HOME`、`PHPX_HOME` 和 `TYPE_NATIVE_PHP_INI`：

```bash
TYPE_TEST_EXECUTION=host bash tools/test-native-roles.sh
```

`TYPE_TEST_IMAGE`、`TYPE_PHPX_SDK`、`TYPE_TEST_REDIS_IMAGE` 可选择已有受信任测试依赖；脚本先解析工具链和 Redis 镜像的不可变 ID，运行均不自动拉取。默认 Redis 为 `redis:8.10.1`。CI 应由前置服务准备该固定镜像，不使用开发者机器专属工具链。

## 实际验收

终版完整重跑退出 0，报告为 `build/native-roles-dxR/report.json`，七个角色 ID 不重复、每个退出码为 0，`business-effects.outbox-effects=1`、`scheduled-completions=2`，`status=passed`、`test-resources=removed`。镜像内 Outbox ELF SHA-256 为 `36e3399a17318ccfb9f3c47dc7a5a77da9c40add05651cb9bb84b4719a8f06f1`，scheduler 为 `2023fe21553b3fb742f08ed1fc27c65ae79051f90b0ac30558b4277f91bcefca`；构建日志、镜像日志及导出的实际文件清单均留在同一目录。

报告记录每个角色的启动、业务结果和镜像/构建身份。独立角色无源码运行与三库、长期负载、故障及目标平台验收分别执行。

## 正向部署预算与故障短租约分开

附加 CI 的独立 relay 偶发报告“发布完成但 Outbox token 已失效”。既有演练入口固定使用 100ms 租约和1秒保留期；租约从数据库短事务 claim 开始，覆盖真实 Redis 发布和新数据库连接回标，进程调度或外部写等待超过100ms时正确拒绝。SDK启动本身发生在claim之前，不直接扣除租约，但不能据此假定claim后的网络和调度总能在100ms完成。

在专属 SQLite/AOF Redis 环境，发布前实际 `CLIENT PAUSE 250 WRITE` 可稳定触发相同异常；5秒预算同一路径完成。进一步使用保留的旧真实 ELF 和已有 Outbox `setup/relay/consume-replay` 入口，`CLIENT PAUSE 500 WRITE` 使relay在606ms后失败，旧产物未被覆盖，保存于忽略目录 `.cache/outbox-token-before`，SHA-256为 `36e3399a17318ccfb9f3c47dc7a5a77da9c40add05651cb9bb84b4719a8f06f1`。这不是框架token错误，不能标为“修复框架覆盖/重复发布”。
