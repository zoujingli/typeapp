# 应用与组件结构

`typeapp` 开发主仓维护 TypeApp 应用框架。TypePHP 负责编译，Swoole 属于随应用交付的原生运行库，Plugins 是 Composer 管理的框架组件，源码在 `plugin/`；职责关系见[系统架构](../guide/architecture.md)。物联中心是成品案例，不是框架本身；其他应用用 `type-project` 创建。业务使用组件的公开接口；组件不会反向依赖根应用。项目及组件命名遵守[项目标准](project.md#命名与兼容边界)。

```text
app/
  common/                     启动装配、可信 Tenant 上下文、共享认证授权与审计机制
    bootstrap/                配置、运行角色、HTTP 及命令装配
    database/                 Platform / System / Center 新安装基线
    middleware/               可信入口、人员身份、请求日志和错误响应
    service/                  双域授权、菜单目录、会话和资源生命周期
  platform/                   平台配置、Tenant 开通、超管、模拟登录及全局物联资产
    mqtt/                     全局 MQTT 运维与节点管理，不再维护独立人员后台
  system/                     当前 Tenant 的独立账号、角色、会话及系统设置
  center/                     当前 Tenant 的分类、设备、注册资格、接入应用及通信授权
web/                          独立 Vben 管理端及公共页面标准
  apps/web-antd/src/
    api/                      共享协议、可信请求与独立身份、Platform 管理、Center 业务契约
    router/routes/            Platform、System、Center 分别声明路由与功能目录
    views/platform/           平台管理、全局 MQTT 运维与运行配置
    views/center/             分类、设备、注册资格、应用授权与转配
    views/shared/             双域身份表单、站点编辑与获准的资源展示
config/                       应用配置声明与路由声明
.env.example                  无秘密的变量示例
.env                          本地启动数据，不入仓、不入构建
plugin/type-*/                独立 Composer 组件
docs/build-config/            构建场景 JSON
tests/                        公共行为与集成验证
build/                        生成源码、平台产物、报告与开发数据，不入仓
```

上图是当前物联中心的标准分层，不是框架强制层级。业务模块可以继续增加职责目录：PSR-4 负责命名空间到文件，构建 `sources` 明确全量源码，控制器通过 `#[Route]`/`#[Group]`/`#[Resource]` 或 `config/route.php` 的 `routes` 表参与注册。不会依据路径深度自动发布接口。

物联中心由 `docs/build-config/type-app.json` 的应用声明生成原生入口，再调用 `app/common/bootstrap/Application::bootstrap()` 分派宿主及普通命令；入口与服务图生成在 `build/`，不再维护手写的 `app/main.php`。资源归属见[物联中心统一装配](../development/iot-application-assembly.md)。

## 业务职责

| 分组 | 职责 | 常见名称 |
| --- | --- | --- |
| controller | 请求输入、授权信息、调用服务、返回响应 | `TenantController` |
| service | 业务规则、业务状态和事务/缓存声明 | `DeviceService` |
| model | 字段状态、查询入口、持久化与业务投影 | `Product`、`SystemSetting` |
| middleware | 请求级策略、关联日志和统一错误响应 | `RequestLog` |
| bootstrap | 启动环境、组件组合与角色选择 | `Settings` |

只有真实职责才建目录；不为每个类新建层级。查询很复杂时才引入 mapper/repository 等真实变化点，不要求简单 CRUD 一开始叠多层代理。

`app/center`是业务应用，使用独立`type-mqtt`组件及已有ORM/运行时等公开接口。MQTT协议、会话和持久投递由组件拥有，租户归属、持久接收回执、设备执行结果和恢复授权由业务拥有。Web遵守[统一页面标准](iot-web.md)，验收范围见[实现规划](../guide/roadmap.md)。通用框架的三数据库和正式平台目标不因IoT选择PostgreSQL同步高可用而缩减。

## 应用数据表命名

业务表采用 `<模块>_<单数业务名>`：平台为 `platform_*`，客户系统为 `system_*`，物联中心为 `center_*`；归 Platform 的 MQTT 运维表使用 `platform_mqtt_*`。关联表同样采用模块前缀和单数名，例如 `system_user_role`、`system_role_permission`。模型声明、SQL、外键、索引及验证入口使用同一名称，不保留旧表别名或自动数据转换分支。

`platform_menu` 与 `system_menu` 分别保存平台目录和所有 Tenant 共用的子系统目录，均由 Platform 维护。角色权限外键只能引用所属目录。System 账号、角色、关联、会话及业务记录绑定可信 `tenant_id`；共享子系统菜单不属于单个 Tenant。框架组件自有表继续由组件管理其前缀与契约，不混入应用模块。

## 组件职责与兼容

组件根 `src/` 保留主要公开入口；已有 `Http/Message`、`Migration`、`Outbox`、`Attribute` 等目录按职责分组。相同职责同一命名空间，类名表达行为，不使用无含义的 Utils/Helper 大杂烩。已有发布 FQCN 保持兼容；需要内部拆分时先明确所有者和调用接口，迁移公共类需单独兼容方案及验证。

当前 RC 阶段已允许按[框架开发闭环设计](../development/framework-ecosystem.md) 集中收敛公开接口；这不是任意重命名许可。每次调整须同步主仓、模板、示例、文档和升级说明，新增接口经过独立消费及原生验收后才归入发布版。开发分支以同名 Service 转换替代独立 Operations 类型；RC14 继续按其原有组合入口调用。

组件 README 必须可以在分发子仓独立阅读：安装要求、有效例子、主要接口、真实目录职责、配置与失败语义、TypePHP 要求及主仓测试入口。指向主仓文档的链接使用准确 GitHub 路径，不使用在子仓内失效的 `../../docs`。

## 配置与分发

构建 JSON 使用相对其文件的 `project-root`，源码和产物路径相对项目根。`config/` 保存可审计默认值和 env 键引用，秘密由 `.env` 或外部环境注入。配置工厂、装配入口与转换后的 Service 是生成产物，不把它们作为手写业务目录。

分发始终只拆 `plugin/<组件名>` 子树，应用源码、根配置、`.env`、测试数据与主仓元数据不会进入组件子仓。独立 `type-project` 模板仍保留，物联中心应用不会改变模板的独立消费用途。
