# 日志组件接入与验收

`plugin/type-log` 已实现 PSR-3、八个级别、多通道、结构化 stdout、显式文件、Scope 关联快照、脱敏、有界缓冲、失败计数和停止排空。详细公共接口及文件系统限制见[包说明](../../plugin/type-log/README.md)。不把普通日志当可靠事务审计。

主仓已通过 Composer 安装 `zoujingli/type-log`，独立应用按需安装该组件即可。包自身声明 `psr/log` 3.0.2 的完整源码适配；无需根项目再次添加 imports。

HTTP 组合通过现有请求的 `type.scope` 完成；[示例中间件](../../examples/log/Http.php)把日志管理器登记到 Scope，将 Logger 放入 `type.logger`，正常和异常路径都会形成关联记录。生产应用可使用同一公共接口组合自己的生命周期与响应策略。示例没有要求 core 反向依赖日志包。

## PHP 检查

```sh
php tests/log.php
php tests/log-behavior.php
php tests/log-failures.php
php tests/log-http.php --php
php tests/log-consumer.php
```

`tests/log-bootstrap.php` 为示例测试提供组件加载入口；设置 `TYPE_LOG_AUTOLOAD` 时使用指定消费者的 Composer autoload，不添加本地插件路径。`tests/log-consumer.php` 在独立目录真正安装日志与 runtime，验证既不依赖 core、ORM、Redis，也能产生预期 PSR-3 日志。`LocalFileTest` 和 `ArchitectureRuntimeTest` 覆盖普通文件写出、外借句柄所有权及生产错误脱敏；普通文件不要求非阻塞切换，管道和 socket 的满载与停止预算由日志故障场景单独验证。

## 集中原生验收入口

在固定 TypePHP/PHPX 工具链下，通过独立场景入口编译完整组件与生产依赖：

```sh
php tests/build-scenario.php docs/build-config/type-log.json
php tests/log.php build/log/type-app
php tests/build-scenario.php docs/build-config/type-log-behavior.json
php tests/log-behavior.php build/log-behavior/type-app
php tests/build-scenario.php docs/build-config/type-log-failures.json
php tests/log-failures.php build/log-failures/type-app
php tests/build-scenario.php docs/build-config/type-log-http.json
php tests/log-http.php build/log-http/type-app
php tests/log-consumer.php --native
```
