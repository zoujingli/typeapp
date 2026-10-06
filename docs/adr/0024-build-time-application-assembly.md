---
status: accepted
---

# 构建期自动装配作为应用默认入口

命令、HTTP 和后台任务的手工装配重复依赖与生命周期规则，也使开发入口与原生入口容易产生差异。应用默认由构建器根据明确启用的入口、构造器类型与显式覆盖生成直接调用代码；执行范围沿用 `ExecutionScope`，共享实例限于所属执行单元，生产不引入反射容器或未知服务发现。此决策调整 [ADR0022](0022-explicit-assembly-and-boundary-contracts.md) 中必须手工构造的做法，保留其协议、资源所有权及全量 AOT 边界。

决策已确定，统一 HTTP、命令和任务的实现尚待完成；当前命令生成器不能作为并发 HTTP 装配器使用。范围与门禁见[框架开发闭环设计](../development/framework-ecosystem.md)。
