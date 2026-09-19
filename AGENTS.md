# typeapp 协作约定

本仓库采用 Matt 工程技能的需求、规格、任务与实施流程。技术说明使用自然、准确的中文；包名、命名空间、配置键和稳定错误码按生态规范书写。

### Issue tracker

当前实现与规划以源码及配套文档为准；使用 GitHub 协作需求前，阅读 [需求跟踪约定](docs/agents/issue-tracker.md)。

### Triage labels

按 Matt 的五类状态和两类分类映射标签；涉及任务状态时，阅读 [标签词汇](docs/agents/triage-labels.md)。

### Domain docs

当前采用单一语境。设计和探索前阅读 [术语表](CONTEXT.md) 及相关 [架构决策](docs/adr/README.md)，消费规则见 [领域文档约定](docs/agents/domain.md)。

## 工作入口

- 开发、重构、调整依赖、命名、说明或清理资源前，阅读[项目标准](docs/standards/project.md)：主仓 `main` 开发与临时资源回收、TypeApp 定位、100% 全量编译门槛及可测量的极简高效要求以此为准。
- 梳理需求、写规格、拆任务或复核范围时，阅读 [工程流程](docs/agents/workflow.md)，并使用当前请求指定的技能。
- 实施或修复时，阅读 [实施约定](docs/agents/implementation.md)；先检查当前实现和复用点，再完成一个可观察的纵向行为。
- 修改 PHP、生成器或注释时，阅读[代码与 TypePHP 规范](docs/standards/coding.md)；调整目录或公共接口时，阅读[应用与组件结构](docs/standards/structure.md)；新增测试或提交前，阅读[测试与质量检查](docs/standards/testing.md)。
- 运行命令、测试、操作 GitHub 或发布时，阅读 [工具约定](docs/agents/tools.md)。实际命令以仓库当前配置和工具帮助为准。
- 完成修改后按 [提交与前端处理](docs/agents/implementation.md#提交与前端处理)检查并提交：按功能分开、前缀加中文描述，相关前端变更一并纳入，默认不发布或更新本地前端。

## 项目约束

- TypeApp 是以 TypePHP 全量编译、Swoole 驱动运行、Plugins 组合能力的 PHP 应用框架。Plugins 是 Composer 管理的 `type-xxxx` 框架组件；构建输入与运行依赖的关系见[系统架构](docs/guide/architecture.md)。`typeapp` 是按 Apache-2.0 公开的开发主仓，`app` 中的物联中心是成品案例，分发子仓是组件发布出口。其他业务用 `type-project` 创建应用并按需安装组件。
- 通信、进程、线程、协程及事件循环必须基于 Swoole 官方能力，平台按实际能力选择执行方式；进程不可用时使用线程/协程。迁移删除旧网络驱动及相关配置、代码和使用说明，不保留 stream 通信回退；具体边界见[Swoole 复用标准](docs/standards/swoole-reuse.md)。
- 框架与业务生产代码全量交给 TypePHP 编译；支持 MySQL、PostgreSQL、SQLite，按各自真实语义验证。
- 生产交付为一个程序文件加外置配置，所需原生运行库由程序携带和管理；生成的数据与日志由应用管理。当前打包实现与目标的差距见[构建与部署](docs/guide/deployment.md)，不能把目录包或压缩包当作单文件程序完成。
- 所有约定能力属于一次完整交付；按任务记录实际实现与验证范围，不能把目录、文档或一个演示当作框架完成。
- 主仓、Plugins 分发子仓及应用模板的第一方内容统一采用 Apache-2.0；第三方材料保留原许可证和归属。仓库简介取对应 Composer 清单的实际职责描述，公开分发规则见[项目标准](docs/standards/project.md#源码公开与完整交付)。
- 保持用户已有修改。仓库重建、历史清理和公开发布按会话中明确的目标与时间执行，既有授权持续有效；源码基线发布与完整产品验收分别记录。
