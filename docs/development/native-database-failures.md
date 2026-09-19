# 原生数据库事务与流边界专项

本入口使用专用原生MySQL/PostgreSQL实例和SQLite文件，复用原有测试与应用，不重写故障模型，不使用用户现有业务数据库。

先在匹配的PHP_HOME/PHPX_HOME环境中构建四个既有场景：

```sh
composer build:transactions
composer build:outcomes
composer build:read-write
composer build:pagination
composer test:native-database-failures -- build /MySQL工具根目录 /PostgreSQL工具根目录
```

入口要求非root Linux/macOS，先核对四份产物的平台、架构和摘要，再通过NativeDatabase创建本轮私有实例。原生方式与PHP方式分别运行同一测试入口，总计26次专项调用：

| 专项 | 实际断言范围 |
| --- | --- |
| transactions（三库两模式） | 嵌套savepoint、内外层回滚、模型/部分字段失效、跨执行者拒绝、活动租约安全收尾 |
| outcomes（三库两模式） | 事务结果分类、提交后回调顺序、异常汇总、已提交事实与禁止边界SQL |
| read-write（三库两模式） | 两个独立测试库之间的受控延迟、显式主读、事务固定、每次执行的粘滞隔离 |
| pagination（三库两模式） | 分页/有界关系批次、2万行流式导出、完整计数和求和、12MiB以内峰值增长、执行者/冲突查询拒绝、早停/异常/超大单行/作用域关闭时归还租约 |
| commit-failure（MySQL两模式） | 真实协议代理在开始、提交前和成功确认后断线，区分NOT_STARTED/UNKNOWN，事务体不重试、回调不误执行、持久化0/0/1次 |

这组专项不替代数据库WAL崩溃、完整主从切换、队列/调度故障、独立干净部署或其他平台的验收。不会因为目录或报告文件存在就将未成功的调用计为通过。
