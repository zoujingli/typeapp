---
status: accepted
---

# 在原 Service 类型上落实事务与缓存声明

另行生成的组合类与业务 Service 不是同一类型，容易使注入、手动构造和类内调用获得不同语义。构建器改为转换完整源码文件，保持 Service 的类名、`final`、构造器与公开签名，让开发入口和全量 AOT 执行同一转换结果；以生成身份和加载前校验换取一致调用语义，不引入运行时 AOP。此决策替换 [ADR0003](0003-static-declarations-and-runtime-config.md) 的独立组合类选择，配置与秘密边界继续有效。

决策已确定，当前实现仍使用显式组合入口；旧版本的直接 Service 调用不会因此自动获得声明行为。实施必须一起迁移生成器、应用、模板、测试和文档，保留历史产物身份，详见[框架开发闭环设计](../development/framework-ecosystem.md)。
