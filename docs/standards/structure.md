# 应用与组件结构

`typeapp` 开发主仓维护 TypeApp 应用框架。TypePHP 负责编译，Swoole 属于随应用交付的原生运行库，Plugins 是 Composer 管理的框架组件，源码在 `plugin/`；职责关系见[系统架构](../guide/architecture.md)。物联中心是成品案例，不是框架本身；其他应用用 `type-project` 创建。业务使用组件的公开接口；组件不会反向依赖根应用。项目及组件命名遵守[项目标准](project.md#命名与兼容边界)。

```text
app/
  common/                     多模块共享的应用能力
    bootstrap/                配置、模式、HTTP及命令装配
    database/                 驱动选择与示例迁移
    middleware/               应用日志与错误响应
  admin/                      平台管理端控制器与服务
  broker/                     Broker 管理与节点控制器
  iot/
    controller/               物联网 HTTP 输入与响应
    middleware/               人员与租户鉴权
    service/                  设备业务、接收、控制、转移与恢复
    database/                 物联网业务迁移
  main.php                    AOT 入口
web/                          独立 Vben 管理端及公共页面标准
config/                       应用配置声明与路由声明
.env.example                  无秘密的变量示例
.env                          本地启动数据，不入仓、不入构建
plugin/type-*/                独立 Composer 组件
docs/build-config/            构建场景 JSON
tests/                        公共行为与集成验证
build/                        生成源码、平台产物、报告与开发数据，不入仓
```

上图是当前物联中心的标准分层，不是框架强制层级。业务模块可以继续增加职责目录：PSR-4 负责命名空间到文件，构建 `sources` 明确全量源码，控制器通过 `#[Route]`/`#[Group]`/`#[Resource]` 或 `config/route.php` 的 `routes` 表参与注册。不会依据路径深度自动发布接口。

## 业务职责

| 分组 | 职责 | 常见名称 |
| --- | --- | --- |
| controller | 请求输入、授权信息、调用服务、返回响应 | `AdminController` |
| service | 业务规则、业务状态和事务/缓存声明 | `DeviceService` |
| model | 字段状态、查询入口、持久化与业务投影 | `ModelDefinition` |
| middleware | 请求级策略、关联日志和统一错误响应 | `RequestLog` |
| bootstrap | 启动环境、组件组合与角色选择 | `Settings` |

只有真实职责才建目录；不为每个类新建层级。查询很复杂时才引入 mapper/repository 等真实变化点，不要求简单 CRUD 一开始叠多层代理。

`app/iot`是业务应用，使用独立`type-mqtt`组件及已有ORM/运行时等公开接口。MQTT协议、会话和持久投递由组件拥有，租户归属、持久接收回执、设备执行结果和恢复授权由业务拥有。Web遵守[统一页面标准](iot-web.md)，验收范围见[实现规划](../guide/roadmap.md)。通用框架的三数据库和正式平台目标不因IoT选择PostgreSQL同步高可用而缩减。

## 组件职责与兼容

组件根 `src/` 保留主要公开入口；已有 `Http/Message`、`Migration`、`Outbox`、`Attribute` 等目录按职责分组。相同职责同一命名空间，类名表达行为，不使用无含义的 Utils/Helper 大杂烩。已有发布 FQCN 保持兼容；需要内部拆分时先明确所有者和调用接口，迁移公共类需单独兼容方案及验证。

组件 README 必须可以在分发子仓独立阅读：安装要求、有效例子、主要接口、真实目录职责、配置与失败语义、TypePHP 要求及主仓测试入口。指向主仓文档的链接使用准确 GitHub 路径，不使用在子仓内失效的 `../../docs`。

## 配置与分发

构建 JSON 使用相对其文件的 `project-root`，源码和产物路径相对项目根。`config/` 保存可审计默认值和 env 键引用，秘密由 `.env` 或外部环境注入。配置工厂与缓存/事务组合类是生成产物，不把它们作为手写业务目录。

分发始终只拆 `plugin/<组件名>` 子树，应用源码、根配置、`.env`、测试数据与主仓元数据不会进入组件子仓。独立 `type-project` 模板仍保留，物联中心应用不会改变模板的独立消费用途。
