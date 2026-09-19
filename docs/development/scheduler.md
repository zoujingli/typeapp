# 调度组件实施与验收

## 已实现与 PHP 验证

新增 `type-scheduler` 插件，复用 runtime 的执行者所有权、作用域和资源清理。生产注册使用编译源码中的 Closure 与 Task；PSR-20 时钟可注入。Cron 采用精确锁定的 `dragonmantank/cron-expression` 3.6.0，不自造字段解析器；由本包处理 UTC 与 DST 映射，明确 spring gap 跳过和 fall overlap 的 first/both 策略。

`tests/scheduler-consumer.php` 创建真实独立 Composer 项目，镜像安装 scheduler/runtime/Redis 及 Cron/PSR Clock，明确检查未混入 core、ORM 或 queue。运行以下 PHP 验证：

- 默认 UTC 与 Asia/Shanghai；纽约春季缺口、秋季重复与闰年月末 Cron。
- 固定锚点间隔、最新有限补跑、正常轮询宽限、首次启动与停机补跑边界。
- 新版本和时区文本变化保留同一 occurrence ID；文件状态重启、时钟回拨不重复执行。
- 每次工厂重新生成 Task，每次独立作用域清理，业务异常、清理异常与有限历史。
- 独立命令离线帮助、真实任务结果、有界循环、非法任意命令拒绝。
- 真实 SIGKILL 留下 running，重启记录 interrupted，保留身份并继续后续计划。

```bash
# 在具备 phpredis 6.3、Composer 的 PHP 环境执行：
php tests/scheduler-consumer.php
```

## 主流程接入

根 Composer 增加 `zoujingli/type-scheduler: ~1.0.0@dev`，path 仓库版本映射增加 `zoujingli/type-scheduler: 1.0.x-dev`，然后更新锁定安装。无需修改 core、ORM 或 queue。可加入脚本：

```json
{
  "build:scheduler": "@php vendor/bin/type docs/build-config/type-scheduler.json",
  "test:scheduler": "@php tests/scheduler.php",
  "test:scheduler-consumer": "@php tests/scheduler-consumer.php",
  "test:scheduler-native": "@php tests/scheduler-command.php build/scheduler/type-app --native",
  "test:scheduler-native-consumer": "@php tests/scheduler-consumer.php --native"
}
```

原生验证阶段使用现有 PHPX/PHP 工具链运行 `build:scheduler` 与两种 native 测试；`--native` 消费模式安装 type-build、TypePHP 与 PHPX，并复用完全相同的命令验证。完整验收需要第三方 Cron 库整体编译、可控时钟场景、无业务源码运行及同提交 CI 全部通过。

 的 Redis 租约与应用队列适配已实现，当前 PHP 多进程验证及集中原生接入见[调度协调说明](scheduler-coordination.md)。本地文件互斥仍只用于本机；Redis 实现复用 `StateStore` 的 acquire/load/save/release 契约与稳定 occurrence ID。
