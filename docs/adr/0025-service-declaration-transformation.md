---
status: accepted
---

# 在原 Service 类型上落实事务与缓存声明

另行生成的组合类与业务 Service 不是同一类型，容易使注入、手动构造和类内调用获得不同语义。构建器改为转换完整源码文件，保持 Service 的类名、`final`、构造器与公开签名，让开发入口和全量 AOT 执行同一转换结果；以生成身份和加载前校验换取一致调用语义，不引入运行时 AOP。此决策替换 [ADR0003](0003-static-declarations-and-runtime-config.md) 的独立组合类选择，配置与秘密边界继续有效。

开发分支已在统一生成过程中转换原 Service；应用、模板与示例直接调用同一业务类型，不再使用独立 Operations 类型。RC14 仍遵守其原有显式组合语义，新增行为须以对应候选的独立消费和原生验收为准，详见[框架开发闭环设计](../development/framework-ecosystem.md)。
