# MQTT 持久状态保留受控基础设施边界

状态：已决定，2026-11-14

## 背景

物联业务 CRUD 以 Model 为标准；MQTT 访问控制、资源观察、连接代次、撤权、恢复和兼容记录同时承担 CAS、锁、发布顺序与节点回执语义。把这些热路径直接改成普通 Model 查询会拆开原子条件，导致状态发布、撤权和恢复核对失去同一事务边界。

## 决策

以下表族继续由协议/基础设施 Service 在受控事务中直接访问，并按具体调用登记边界：

- `platform_mqtt_access_*`：主体、凭据、授权、证书、CA/CRL、版本、节点状态和撤权状态；
- `platform_mqtt_resource_*`、`platform_mqtt_connection_operation`：节点资源连接、订阅、运行代次和连接操作；
- `platform_mqtt_debug_*`、`platform_mqtt_quota_*`、`platform_mqtt_runtime_*`、`platform_mqtt_compatibility`：调试、配额、运行配置和兼容记录；
- Center 的运行观察、恢复主体、批量撤权和跨模型投影。

这些访问必须满足闭合表族白名单、参数化条件、事务/锁/CAS 语义和现有核心切片登记；`kind`、`realm` 等动态选择只能来自源码内部白名单，不能由请求输入拼出表名。普通 Tenant 业务实体（用户、角色、站点、分类、设备、注册资格、接入应用及其常规 CRUD）不得借此扩大底层表访问范围，统一使用具体 Model、`_vali`/`InputFields` 和 `_query()`。

## 取舍与验收

保留协议基础设施边界可维持节点发布、撤权和恢复的原子性，代价是不能宣称全仓所有表访问均由 Model 封装。业务切片继续由 `tests/fixtures/model-first-boundary.json` 精确门禁；当前 MQTT 表族和调用者由本 ADR 进行人工复核，尚未建立独立静态 fixture，不能把它计为自动门禁。协议 Service 的新增直接访问必须先做人工复核，补充调用者、闭合表族和原因，再同步本 ADR。协议重构只有在不改变锁、CAS、发布顺序和恢复语义且有真实 MQTT 验收时才可迁移到 Model。
