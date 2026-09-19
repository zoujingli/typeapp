# MQTT 规范与互操作验证

MQTT 3.1.1 与 5.0 的兼容性以 OASIS 正文为准，编号附录只用于交叉核对。当前[条款矩阵](mqtt-conformance-matrix.json)包含 141 条 3.1.1 编号和 251 条 5.0 编号；[断言映射](mqtt-conformance-map.json)关联测试函数及断言文字，另外登记未编号义务。

矩阵是验证输入，当前未附本次产物的运行结果。`not-run` 表示需要执行，`not-applicable` 表示该条不属于当前服务端职责或未启用的可选功能；均不能计为已通过。完整符合性仍需逐条覆盖全部适用条件、传输方式、目标平台和故障路径。

## 标准依据与测试边界

标准来源及原文 SHA-256、版权与许可通知保存在矩阵中。官方编号勘误由 `tests/mqtt-conformance.py` 显式映射；MQTT 5 的 Retain Handling 以正文为准，不采用附录中相反的订阅重放描述。Topic 匹配、属性位置/重复/默认值、UTF-8 载荷、共享订阅和错误处理均须覆盖，编号数量不等于完整义务数量。

测试使用独立报文字节构造与 MQTT.js、Eclipse Paho、Mosquitto 客户端。外部套件固定源码身份，工具版本在测试入口中校验；测试客户端差异按报文及标准裁定，不通过修改预期隐藏失败。EMQX 仅用于显式差分，不能代替被测 Broker 或标准正文。

## 运行入口

在仓库根配置锁定 PHP/TypePHP/PHPX、Swoole 以及真实 PostgreSQL 工具，然后运行：

```sh
php tests/mqtt-consumer.php --native --qos2 --retained --session --will --subscriptions --shared --capacity
php tests/mqtt-consumer.php --native --conformance-only
TYPE_MQTT_CONFORMANCE_TOOLS=cli,probe php tests/mqtt-consumer.php --native --conformance-only
composer test:broker-interop
```

`TYPE_PAHO_TESTING`、`TYPE_MOSQUITTO_SOURCE`、`TYPE_MOSQUITTO_BIN` 和 `TYPE_PYTHON` 指定外部工具。`TYPE_MQTT_CONFORMANCE_CASE` 按逗号选择完整用例标识；未选择的用例应报告 `not-run`。消费者负责测试 Broker、连接与同步数据库的停止，报告保存于独立输出目录。

将官方 HTML 保存为 `build/mqtt-v3.1.1-os.html` 和 `build/mqtt-v5.0-os.html` 后，重新核对规范和当前断言：

```sh
python3 tests/mqtt-conformance.py inventory --mapping docs/development/mqtt-conformance-map.json --output build/mqtt-conformance-matrix.json
```

生成器校验官方原文摘要、正文与附录编号一致性、测试函数与断言存在性，并生成当前文件摘要及行号。清单生成不运行协议测试；实际测试还需核对全量 AOT 产物、协议响应、持久状态及资源回收。当前完整交付条件见[实现规划](../guide/roadmap.md)。
