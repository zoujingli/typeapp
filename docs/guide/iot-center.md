# 物联网中心

物联中心是基于 TypeApp 构建的成品案例，通过框架组件组织物联网业务，以 TypePHP 编译生产实现，原生运行库随应用交付。框架开发文档在侧栏「TypeApp 框架」和「开发指南」；职责分层见[系统架构](architecture.md)，本页说明案例的业务、使用契约和交付边界。

控制端与客户端的新业务范围已确定，详见[架构设计](../development/iot-control-client-design.md)。开发版已接入注册资格、无物模型设备注册、独立接入应用、逐设备通信授权及原始设备转配，并完成各分项的本机原生验收；旧设备业务转换及旧处理链退出继续实施。以下说明区分当前接口与尚待完成的范围，不作为整批新架构已交付的依据。

本案例提供两个人员入口：平台管理端面向平台人员，维护账号、租户、站点与全局观察；SaaS 用户端面向客户账号，在当前租户内管理成员、产品、设备、数据与告警。设备经独立 MQTT Broker 接入，遥测由独立接收进程落库，统计、告警、通知和导出分角色运行。MQTT 标准、高可用、目标容量与真实设备按对应产物和平台证据分别验收。

开发版界面将平台管理端标为「控制端」、客户工作区标为「客户端」。两端仍在同一应用内，登录地址分别是 `/#/admin/login` 与 `/#/login`；既有页面与 API 地址保持不变。各自的分组路由提供面包屑，品牌点击返回当前工作区；切换账号域、租户或模拟身份会清理旧菜单、标签页及请求上下文。人员权限继续由后端独立检查，界面分开不等于增加一套账号或部署。

## 能力范围

| 范围 | 当前提供 | 边界 |
| --- | --- | --- |
| 平台管理端 | 平台人员与角色、客户账号、租户工作区、站点品牌、系统配置、设备资产观察、Broker 观察与审计 | 平台身份不会自动获得租户内的客户权限 |
| SaaS 用户端 | 租户成员与角色、产品与物模型、设备生命周期、指令、历史、告警、站内通知、CSV 导出、设备转移 | 必须选择当前租户；不能靠 URL 或请求头扩大范围 |
| 设备接入 | MQTT 5、TLS、QoS 1；独立 Broker 与数据接收；设备凭据、Topic 与归属阶段 | 持久设备路径要求 PostgreSQL 严格同步主备；执行方式按 Swoole 实际构建能力选择 |
| 数据与运维 | 原始历史、分钟统计、告警规则、导出任务、WAL/备份/恢复核对 | 各角色须分别监督启动，不能用单个 `serve` 代替 |

```mermaid
flowchart LR
  Tenant["租户"] --> Product["产品"]
  Product --> Model["物模型版本"]
  Tenant --> Device["设备"]
  Device --> Model
  Device --> Epoch["归属阶段"]
  Epoch --> Up["遥测"]
  Epoch --> Down["指令"]
  Up --> Alarm["告警"]
```

租户拥有产品、设备和业务数据。产品通过已发布的物模型版本解释设备属性、事件和指令。设备同一时刻只归属于一个租户；转移后形成新的归属阶段，旧历史留在源租户。

## 如何装配 TypeApp

本案例按需组合 Plugins，不把物联网规则写进框架组件。

| TypeApp 能力 | 本案例用法 |
| --- | --- |
| Swoole | 通信与基础并发的必需底层；人员生产 HTTP API 已走原生线程与协程，Broker 尚需收敛内部通信路径 |
| TypePHP | `app/`、组件与生产依赖全量编译；前端先构建为静态资源，再作为原生常量链接进程序 |
| `type-core` / `type-runtime` | HTTP PSR 链、命令入口、作用域与资源预算 |
| `type-orm` 与驱动 | 管理 API 可用 MySQL、PostgreSQL、SQLite；设备持久接收只用 PostgreSQL |
| `type-mqtt` | 独立 Broker 进程，不由 `serve` 内嵌 |
| `type-redis` | 通知与导出的专用连接；通用缓存默认关闭 |
| Vben Admin Pro | `web/` 双端控制台，走 `/admin` 与 `/customer` |

其他业务不要改写这份 `app/` 冒充新产品，应使用 `type-project` 创建独立应用后再安装所需组件。

## 建议阅读顺序

1. 先看能力范围和业务架构，确认本案例覆盖哪些角色。
2. 按准备后端创建双端账号，再启动 HTTP 与管理端。
3. 在客户端创建产品、发布物模型、预注册设备，再按设备接入契约连 Broker。
4. 需要持久遥测、告警或导出时，分别启动接收与后台角色。
5. 上线前阅读进程清单和当前验收边界，不要把页面存在或编译成功当成完整交付。

```mermaid
flowchart LR
  Install["初始化双端账号"] --> Product["产品与物模型"]
  Product --> Device["预注册设备"]
  Device --> Broker["Broker 接入"]
  Broker --> Data["遥测、告警与导出"]
```

## 业务架构

<figure class="orbit-diagram" data-src="../assets/iot-architecture.svg">
  <img src="../assets/iot-architecture.svg" width="1120" height="540" alt="成品案例物联中心的业务架构：设备与人员接入案例应用，再进入控制台、数据运维或其他业务。">
  <figcaption>左侧是设备、人员与自定义接入；中间是本案例的 MQTT、HTTP、接收与存储；右侧是双端控制台、数据运维。示意图不是云厂商或大模型集成声明。</figcaption>
</figure>

## 运行角色

```mermaid
flowchart TB
  subgraph people["人员入口"]
    Web["管理端 / 客户端"]
    Http["serve HTTP API"]
  end
  subgraph devices["设备入口"]
    Mqtt["iot:mqtt Broker"]
    Ingest["iot:ingest 接收"]
  end
  subgraph jobs["后台角色"]
    Agg["iot:aggregate"]
    Alarm["iot:alarm"]
    Notice["iot:notices"]
    Export["iot:exports"]
  end
  Web --> Http
  Mqtt --> Ingest
  Ingest --> Agg
  Ingest --> Alarm
  Alarm --> Notice
```

各角色来自同一应用入口，生产须分别监督启动；不能用单个 `serve` 代替 Broker 与接收。

## 源码布局

本案例业务源码在 `app/`，管理端在 `web/`。组件仍在 `plugin/type-*`，由 Composer 组合，不反向依赖本案例。

```text
app/
  common/                     配置、身份、RBAC、审计与跨端入口
  admin/                      平台管理端控制器与服务
  broker/                     Broker 管理与节点控制器
  iot/                        租户、产品、设备、数据与运维
web/                          Vben Admin Pro 管理端
config/                       应用、数据库与路由声明
docs/build-config/type-app.json  本案例应用与构建声明
build/                        生成入口、服务图与编译产物
```

当前开发源码通过应用声明生成原生入口，再调用 `Application::bootstrap()` 分派各角色；已删除手写 `app/main.php`。RC14 的原始入口随其版本保留，不能把当前布局套用到旧产物。

| 目录 | 当前责任 | 关键入口 |
| --- | --- | --- |
| `app/common` | 启动配置、数据库、认证、RBAC、审计、共享中间件与控制器 | `Application`、`Settings`、`AuthController`、`RoleService` |
| `app/admin` | 平台人员、角色、租户、站点与配置 API | `AdminController` |
| `app/iot` | 租户业务、设备、数据、告警、导出与恢复 | `app/iot/controller`、`app/iot/service` |
| `app/broker` | Broker 节点、资源、接入授权、审计和管理 API | `BrokerController`、`AccessController`、`NodeAccess` |
| `web` | 管理端静态资源和业务页面 | Vben Admin Pro 应用 |

## 准备后端与人员账号

以下步骤用于源码开发：准备 PHP CLI `>=8.4 <8.6`、Composer、匹配的 Swoole 与所选数据库的 PDO 扩展。共享库开发可复用构建组件内置模块，生产构建使用静态 SDK。只负责部署时，按[版本安装](releases.md)下载一个主程序并提供配置，无需安装 PHP、Swoole、Composer 或编译 SDK。环境分工见[环境与依赖](environment.md)。在仓库根安装依赖并准备空数据库：

```bash
git clone https://github.com/zoujingli/typeapp.git
cd typeapp
composer install --no-scripts --no-plugins
cp -n .env.example .env
composer web:build
composer typeapp:prepare
```

管理 API 支持 MySQL、PostgreSQL、SQLite；持久设备接入与接收进程要求 PostgreSQL 严格同步主备。只体验管理页面时，不必把默认 SQLite 当作 MQTT 存储。

在仓库根读取命令帮助，并在空库中一次性创建两类初始账号：

```sh
php bin/typeapp help
APP_ADMIN_PASSWORD='至少12字节的管理密码' \
APP_CUSTOMER_PASSWORD='至少12字节的客户密码' \
php bin/typeapp app:install platform-admin '平台管理员' customer-admin '客户管理员' '初始租户'
```

`app:install` 只接受空数据库；管理口令和客户口令由受控进程环境提供，长度为 5–72 字节，不写入 `.env`、命令参数或日志。安装先暂存并验证页面，再建立平台角色目录、客户初始租户和两套独立会话域，最后安装页面到应用根的 `public/`。数据库成功但页面失败时单独报告，通过 `web:install --force` 修复；后续账号、角色和成员由各自端的 RBAC 接口管理。

平台人员通过 `/admin/auth/login` 登录，管理平台人员、客户账号、租户、设备资产、站点展示设置和运行配置；客户人员通过 `/customer/auth/login` 登录，选择自己所属的租户后管理成员、角色和物联业务数据。两套令牌、密码、会话和权限完全隔离，API 每次请求重新验证当前角色和租户关系。

全新安装会在数据库中保存站点设置，不需要先进入页面逐项填写。管理端“系统管理 → 站点设置”（`/#/admin/site`）维护名称、官网、Logo 地址、说明和默认界面；“系统配置”维护进程启动参数，两者分别生效。官网链接指向项目文档，不会改写本机 API 或设备接入地址。

| 初始化内容 | 默认值与用途 |
| --- | --- |
| 站点名称、官网、说明 | `TypeApp`、`https://iots.top`、物联中心管理平台 |
| Logo | 地址留空，Vben 品牌区域显示站点名称；自定义图片加载失败时使用原生头像回退 |
| 界面 | 浅色、主题色 `#1677ff`、圆角 `0.5`、侧栏导航、侧栏展开、圆润导航、启用面包屑；标签页和页脚默认关闭 |
| 时区记录 | `Asia/Shanghai`；当前业务时间和历史导出仍按浏览器时区，不应把此字段的保存视为已统一时间显示 |
| 平台账号与角色 | 安装命令指定的一个平台账号，绑定受保护的最高管理员角色 |
| 客户账号与租户 | 安装命令指定的一个客户账号、一个租户及其成员关系；客户绑定该租户最高管理员 |
| 租户预设角色 | 最高管理员、操作员、只读成员；后两者初始仅含个人工作区权限，业务权限需管理员分配 |
| 业务数据 | 不预置虚拟产品、设备、遥测、告警或演示客户；这些列表首次打开为空，需要按实际业务创建或接入 |

初始账号名、显示姓名、租户名由安装命令指定，密码由受控环境提供，没有通用默认密码。安装只接受空库，不会重置已有站点或替换已有业务数据。

未登录页面通过 `GET /public/site` 读取公开白名单；管理设置通过 `/admin/site` 读写，检查权限与版本并在同一事务内记录变更字段。保存成功后当前页面品牌立即更新，其他浏览器在刷新后读取最新设置；版本冲突保留输入并提示人工核对后刷新。页面标题由当前页面、站点名称和备案名称“物联开源分享”组成。

```mermaid
sequenceDiagram
  participant Admin as 平台管理员
  participant App as 管理接口
  participant DB as 业务数据库
  participant Browser as 登录页与工作区
  Admin->>App: 保存字段与当前版本
  App->>App: 核对会话与站点管理权限
  App->>DB: 同一事务更新设置和审计
  alt 版本一致
    DB-->>App: 提交新版本
    App-->>Admin: 保存成功，更新当前品牌
    Browser->>App: 刷新后读取公开站点设置
    App-->>Browser: 品牌与默认界面白名单
  else 版本冲突
    App-->>Admin: 拒绝覆盖，保留表单输入
  end
```

个人界面偏好保存在当前浏览器，按身份域、账号及模拟来源隔离；个人资料页可恢复本次加载的站点默认值。当前仍需完善跨页面刷新后的默认值与个人覆盖合并、Vben 设置面板的重置行为以及统一时区消费。站点尚无独立的备案号、联系信息或页脚文案管理字段，页脚仅使用站点名称和官网，不能把“页脚开关”理解为完整网站信息管理。

## 账号域与授权

管理端和客户端是两个独立的账号域，不能用请求头互相切换，也不能把其中一个域的权限当作另一个域的权限。

| 账号域 | 登录入口 | 管理范围 | 权限来源 |
| --- | --- | --- | --- |
| 管理端（admin） | `POST /admin/auth/login` | 平台用户、平台角色、固定菜单、客户端租户、管理配置、审计与 Broker 观察 | 管理端角色目录与平台范围授权 |
| 客户端（customer） | `POST /customer/auth/login` | 当前用户可见租户、成员、客户角色、产品、物模型、设备、数据、告警、导出与操作记录 | 客户角色、成员关系与当前租户 |

```mermaid
flowchart LR
  AdminLogin["管理端登录"] --> AdminSess["平台会话与角色"]
  CustomerLogin["客户端登录"] --> CustSess["客户会话与成员"]
  AdminSess --> AdminApi["平台范围 API"]
  CustSess --> Tenant["选择当前租户"]
  Tenant --> CustApi["租户范围 API"]
```

登录成功后的 `GET /admin/auth/me` 或 `GET /customer/auth/me` 返回当前用户、权限、菜单、固定目录和身份上下文。客户可以拥有多个租户，必须明确选择当前租户；管理端身份不会因为创建或查看租户而自动获得该租户的客户权限。

管理端可以为平台用户和客户用户维护账号、角色、状态、密码和会话，也可以设置租户管理员。删除、禁用、改密和撤销会话都在服务端重新校验当前权限，并使用版本字段拒绝陈旧写入。客户端只能在当前租户范围内维护成员和角色，不能通过修改 URL、`X-Tenant-Id` 或请求体扩大范围。

RBAC 的权限节点和菜单是代码中的固定目录，由 `app\common\service\RoleService` 提供。前端菜单用于导航和隐藏无权操作，真正的授权发生在每个 HTTP 请求的中间件、控制器和业务服务中；隐藏按钮不是安全边界。

- 管理端固定分组：个人工作区、身份与租户（平台人员、平台角色、平台租户、客户账号）、系统管理（站点设置、系统配置）、运维中心（设备资产、操作审计、运行概览、Broker 资源与授权）。
- 客户端固定分组：个人工作区、组织与成员（我的租户、租户成员、租户角色）、物联管理（产品管理、设备管理、设备转移）、数据与告警（历史数据、告警规则、告警中心、站内通知）、运维中心（操作审计、运行概览、Broker 资源与授权）。
- 租户业务节点必须带租户标识，服务层根据当前身份和成员关系重新查询归属；平台范围的节点不复用客户范围的权限。
- 模拟身份拥有独立上下文、期限和审计记录，结束或过期后不会把权限合并回本人会话。

菜单按功能分组，不把所有页面平铺到一级。`web/` 使用锁定的 Vue Vben Admin 5.6.0 `BasicLayout`、菜单、主题和偏好机制；业务表格与表单主要直接使用 Ant Design Vue，仍有 `CrudSearchField`、`CrudTableActions`、`AppDrawer` 等应用封装。当前是已接入 Vben 布局的业务应用，尚未完全达到项目规定的原生表单、表格、抽屉及用户入口复用标准。菜单分组与路由层级也需继续对齐，才能保持完整的分组面包屑和详情父级关系。

![原生运行包安装后的平台角色页面，包含分组导航、角色筛选、权限数量和受保护的最高管理员](../assets/iot-platform-roles.png)

图示来自 `v1.0.0-rc.6` macOS ARM64 原候选的隔离验收，页面由程序内资源安装后提供。图中使用专用测试账号，临时新增角色已在 CRUD 验收后删除；不是预置演示账号或生产数据。实际菜单和操作由登录身份与权限决定。

## 启动管理端

使用原生运行包时，首次 `app:install` 已从程序内安装管理端；直接启动 `./run serve`，访问 `/#/login` 或 `/#/admin/login`。升级使用 `./run web:install --dry-run --force` 预览，再执行 `./run web:install --force`。命令不会重置账号、站点设置或上传文件；完整步骤和恢复规则见[前端安装与更新](deployment.md#前端安装与更新)。

前端开发和构建使用 Node.js 20.19 以上和 `pnpm@10.28.2`，它们不是原生后端的运行依赖。以下命令从仓库根执行：

```sh
pnpm --dir web install --frozen-lockfile
IOT_API_ORIGIN=http://127.0.0.1:9501 pnpm --dir web dev
```

后端需另行启动：

```bash
composer typeapp:serve
```

默认 HTTP 监听 `127.0.0.1:9501`。也可用 `php bin/typeapp serve`。前端监听 `127.0.0.1:5173`，将 `/admin`、`/customer` 及业务 API 代理到指定后端。`IOT_API_ORIGIN` 只用于开发服务器；生产静态资源和 API 配置同源 HTTPS。`pnpm --dir web build` 只生成 `web/dist`，不部署或替换本地站点。

页面使用统一的搜索字段、表格行操作、列宽和右侧抽屉，列表默认 20 条、最多 100 条。主题与亮暗模式遵循 Vben 偏好，窄屏保留操作入口；异步提交防重，危险操作需要明确确认。切租户取消旧请求，旧响应不能覆盖当前页面。

## 页面与使用顺序

管理端和客户端使用 Hash 路由，平台个人工作区为 `/#/admin/profile`，客户默认入口为 `/#/profile`；设备、告警和数据详情从所属分组列表进入，不占独立一级菜单。

| 页面 | 使用方式 |
| --- | --- |
| 我的租户、成员管理 | 选择成员租户，维护角色 |
| 平台租户 | 平台管理员维护平台租户目录；不代替成员租户选择 |
| 设备注册授权 | 控制端签发、停用与重置注册资格；客户端只查看本客户获配资格及绑定结果 |
| 产品管理、物模型版本 | 创建产品，编辑模型草稿并发布不可变版本 |
| 设备管理、设备详情 | 预注册、交付凭据，查看连接、当前数据与缓存，管理生命周期、模型切换和指令 |
| 设备转移 | 双方审批后冻结、排空、隔离旧身份并完成新归属确认 |
| 历史数据 | 查询原始记录、分钟统计与曲线，管理 CSV 导出任务 |
| 告警规则、告警中心、站内通知 | 发布规则，处理告警确认并查看触发或结束通知 |
| 运行概览、平台运行 | 分别查看当前租户与平台范围的运行观察及恢复隔离门状态 |
| 操作审计 | 按当前权限查询脱敏操作记录 |
| 站点设置、系统配置 | 平台维护站点品牌与默认主题；运行配置继续按独立的保存、校验和重启生效规则管理 |
| Broker 审计 | 独立、平台或当前租户范围分别查询，详情区分事件阶段与操作当前结果 |

产品、模型与设备属于租户。模型先编辑草稿，再发布版本；发布后不原地修改结构。预注册设备必须选择该产品的已发布版本。注册或轮换仅当次返回密码及精确 Topic，请及时通过受控渠道交付；普通列表和详情无法找回秘密。

设备首次完整成功连接后激活。生命周期的未激活、启用、禁用、退役，与在线、离线、未知以及数据新鲜度分别展示。没有新观察时保留未知或过期状态；浏览器断网不证明设备离线。禁用会撤销旧凭据，重新启用不会恢复它；退役为终态，保留期内历史仍按原权限查询。

轮换、吊销、禁用或退役提交后，旧授权可能显示“待完成”。这表示 Broker 尚未完成旧连接、会话和持久工作的隔离，不能按 HTTP 受理成功提前认定撤权完成。秘密响应丢失时先刷新核对，不能通过自动重试重复生成新凭据。

## 接入应用与密钥

客户端在「物联管理 → 接入应用」维护本客户外部业务系统，控制端在「运维中心 → 接入应用」按平台权限管理。两端共用一套应用事实，分别核对人员权限和客户范围。应用凭据用于 MQTT 服务认证，后台人员令牌、设备凭据与应用密钥不能混用；创建者退出后台不会使应用身份失效。

创建时填写名称，控制端还需明确客户标识。首次成功返回随机密钥，页面关闭后无法再次查看；列表和详情只显示密钥代次及状态。请求标识 `id` 是 32 位小写十六进制值，同一客户和名称使用同一标识重试只确认原应用，不重新交付秘密。响应丢失且密钥未保存时，使用「轮换密钥」恢复。

| 操作 | 权限后缀 | 行为 |
| --- | --- | --- |
| 查看 | `applications.read` | 分页、详情和当前授权发布回执 |
| 创建、编辑 | `applications.create`、`applications.update` | 创建独立身份或修改名称，不隐式更换已有密钥 |
| 启用、停用 | `applications.enable`、`applications.disable` | 控制应用可否接入，停用保留身份与密钥代次 |
| 轮换、撤销、签发 | `applications.keys` | 旧密钥不能恢复，重新签发继续推进代次 |
| 调整设备通信授权 | `applications.authorize` | 按设备当前使用关系明确授予或撤销接收、发送和订阅能力 |

权限带所属域前缀 `admin.` 或 `customer.`。升级只给受保护的最高管理员补充权限，普通角色由管理员明确授权。模拟登录仍按目标客户权限执行，并记录真实平台人员来源。`APP_ENV=demo` 拒绝上述写操作。

例如，客户端在已认证的当前客户范围内创建应用：

```http
POST /customer/tenants/{tenant}/applications
Authorization: Bearer <客户人员令牌>
X-Tenant-Id: <与路径一致的客户标识>
Content-Type: application/json

{"id":"93c2a69096b84484bd42e52a832783ef","name":"客户业务系统"}
```

首次响应为 `201`，`data.application` 是公开应用信息，`data.credential` 是一次性交付；重试为 `200`，`credential=null`。普通详情使用 `GET /customer/tenants/{tenant}/applications/{application}`。控制端对应 `/admin/applications` 和 `/admin/applications/{application}`，创建及变更请求体包含 `tenant_id`，不携带客户工作区头。

变更入口是 `POST .../{application}/{action}`，`action` 为 `update`、`enable`、`disable`、`rotate`、`revoke` 或 `issue`，请求体携带当前 `version`；仅 `update` 另带 `name`。旧表单返回 `stale_version`；前次隔离未完成时返回 `broker_access_publish_paused`，应先核对发布详情，不能通过重试强行越过。

```mermaid
sequenceDiagram
  actor Operator as 获准人员
  participant API as 应用管理
  participant DB as 管理数据库
  participant Broker as MQTT 节点
  participant External as 外部业务系统
  Operator->>API: 轮换密钥（当前版本）
  API->>DB: 同事务保存新代次、审计及撤权意图
  API-->>Operator: 一次性新密钥与发布状态
  Broker->>DB: 读取撤权意图
  Broker->>External: 终止旧凭据对应连接
  Broker->>DB: 写入实际执行回执
  Operator->>API: 刷新详情
  API-->>Operator: 当前节点执行结果
```

连接使用交付结果中的 `client_id` 和 `username`（均为 `app:<应用标识>`）、一次性 `password`，固定 MQTT 5、TLS、30 秒 keepalive 和 86400 秒 session expiry。一个应用对应一个受管 Client ID，多份独立会话应分别创建应用。完整持久 MQTT 仍要求 PostgreSQL 同步存储；三库管理能力不表示 SQLite/MySQL 可启动该通信路径。

轮换、撤销或停用先使新认证拒绝旧身份，再由现有 Broker 撤权流程处理活动连接。页面显示实际发布状态；节点不在线或隔离待处理时不宣称完成。通用 Broker 主体修改和快照回退不能绕过应用管理恢复旧密钥。未授予逐设备权限的应用，认证成功后仍拒绝设备收发和订阅。

## 应用设备授权与原始通信

在接入应用的「设备授权」中查看当前范围、选择设备并保存权限。具有设备读取权限的人员可分页搜索设备，页面自动带入当前使用关系；接口调用方也可提供准确的 `device_id` 与 `ownership_id`。授权限定同一客户和设备当前未结束的关系，同属客户、新设备注册或应用密钥有效都不会自动扩大范围。旧模型设备须先完成迁移，不能直接授予新的原始通信权限。

| 能力 | 对应行为 | 限制 |
| --- | --- | --- |
| 接收 | 允许取得该设备上行的资格 | 单独授予不会建立 MQTT 订阅，也不允许发送下行 |
| 订阅 | 建立、恢复及持续交付该设备上行订阅 | 必须同时有接收资格；通配符和其他设备 Topic 被拒绝 |
| 发送 | 向该设备当前关系的下行 Topic 发布 | 独立授权，不附带接收或订阅能力 |

查询使用 `GET .../applications/{application}/devices?page=1&per_page=20`，返回分页授权、当前关系及应用发布状态。保存示例：

```http
POST /customer/tenants/{tenant}/applications/{application}/devices/{device}
Authorization: Bearer <客户人员令牌>
X-Tenant-Id: <与路径一致的客户标识>
Content-Type: application/json

{"ownership_id":"<设备当前使用关系标识>","version":0,"receive":true,"send":false,"subscribe":true}
```

控制端对应 `/admin/applications/{application}/devices/{device}`，请求体另带 `tenant_id`。`version=0` 仅用于首次创建，后续用授权行自己的 `version`，不是应用版本。相同权限返回 `changed=false`；取消全部权限会保留撤销记录并推进版本，旧表单返回 `stale_version`。订阅缺少接收资格返回 `application_subscription_requires_receive`。应用停用、设备失效或使用关系已更改时不能新增有效授权，已失效关系仍可撤销。

```mermaid
sequenceDiagram
  actor Operator as 获准管理人员
  participant API as 应用授权接口
  participant DB as 管理数据库
  participant Broker as MQTT 节点
  participant External as 接入应用
  participant Device as 设备
  Operator->>API: 设备当前关系、权限与版本
  API->>DB: 同事务保存授权、审计和发布意图
  API-->>Operator: 授权版本与实际发布状态
  Broker->>DB: 核对当前身份及设备范围
  External->>Broker: 订阅设备上行
  Broker-->>External: SUBACK（接收与订阅均获准）
  Device->>Broker: 发布原始上行
  Broker->>DB: 再次核对交付权限
  Broker-->>External: 原字节消息
  Operator->>API: 撤销或收紧权限
  Broker->>External: 隔离旧连接和持久会话资源
  Broker->>DB: 写入实际执行回执
```

设备只发布 `iot/{tenant}/devices/{device}/epochs/{ownership}/raw/up`、订阅对应 `raw/down`；应用方向相反。使用注册响应或授权列表中的精确 Topic，不自行省略关系标识。下面的外部业务示例使用 MQTT.js 5，凭据和服务地址由受控环境提供：

```js
import mqtt from 'mqtt';
import { readFileSync } from 'node:fs';

const client = await mqtt.connectAsync(process.env.MQTT_URL, {
  protocolVersion: 5,
  clientId: process.env.APPLICATION_USERNAME,
  username: process.env.APPLICATION_USERNAME,
  password: process.env.APPLICATION_PASSWORD,
  ca: readFileSync(process.env.MQTT_CA_FILE),
  rejectUnauthorized: true,
  keepalive: 30,
  clean: false,
  reconnectPeriod: 0,
  properties: { sessionExpiryInterval: 86400 },
});
client.on('message', (topic, bytes) => {
  // bytes 是原始载荷；业务系统自行解码、保存和确认执行结果。
  console.log(topic, bytes.length);
});
await client.subscribeAsync(process.env.DEVICE_UP_TOPIC, { qos: 1 });
await client.publishAsync(process.env.DEVICE_DOWN_TOPIC, Buffer.from([0x01, 0x00]), {
  qos: 1, properties: { messageExpiryInterval: 30 },
});
// publishAsync 完成表示对应协议发送流程完成，不表示设备业务执行成功。
await client.endAsync(false);
```

消息继续遵守现有大小、频率、QoS 0/1/2、有效期和持久队列预算。MQTT 协议确认、订阅方收到原字节、设备自己的业务应答是三个不同事实；平台不解析载荷，不为原始通信写入遥测、告警或指令记录。撤权不能仅靠页面隐藏，身份、当前关系与通信权限会在新动作和持续交付时重新核对。`demo` 禁止授权写入，后台不提供绕过只读模式的发送入口。

## 设备使用期限与转配

开发版原始通信设备在控制端「设备资产 → 详情」管理使用期限和归属交接。使用期限属于当前客户使用关系，默认不设置；注册资格过期只影响新注册，不会自动结束已建立的关系。设定的使用期限到达后，新认证及通信动作立即被拒绝，Broker 的撤权轮询负责收尾，无须打开页面触发。

控制端发起「转配设备」时固定设备版本、目标客户和请求标识，在同一管理事务内结束旧关系、撤销凭据和旧应用权限，并记录真实操作者。页面返回受理结果后，设备仍处于隔离阶段。平台从「设备转配」显式执行「核验并推进」，目标客户只能在隔离完成后确认接收。

```mermaid
stateDiagram-v2
  [*] --> isolating: 平台发起转配或结束使用
  isolating: 隔离旧授权与持久内容
  awaiting_acceptance: 等待目标客户确认
  completed: 新使用关系已建立
  ended: 使用关系已结束
  isolating --> isolating: 旧所有者或持久清理仍待完成
  isolating --> awaiting_acceptance: 全部隔离证明成立且有目标客户
  isolating --> ended: 全部隔离证明成立且仅结束使用
  awaiting_acceptance --> completed: 目标客户确认并取得新凭据
```

隔离复用 Broker 的连接关闭、跨节点所有者隔离和持久会话清理，还会核验旧关系两个精确 Topic 的 retained 内容。设备从未认证且相关应用也没有接入历史时可使用管理账本证明；历史未知按需要真实存储核验处理。节点不可达、存储缺失或清理没有完成时保留待处理状态，不能凭离线显示或观察超时推断全部隔离成功。集群进程异常退出后先完成基础设施硬隔离，再通过既有 `iot:mqtt-fence` 登记准确节点与运行身份，详见 [MQTT 集群运维](plugins/type-mqtt.md)。

| HTTP 入口 | 权限与输入 |
| --- | --- |
| `POST /admin/devices/{device}/usage/{operation}`，`operation=expiry` | `admin.devices.expiry`；`version`、Unix 秒 `expires_at`，显式 `null` 取消期限 |
| 同一入口，`operation=end` | `admin.devices.end`；`id`、`version`，仅结束使用 |
| 同一入口，`operation=reassign` | `admin.reassignments.create`；`id`、`version`、`target_tenant_id` |
| `GET /admin/reassignments[/{id}]` | `admin.reassignments.read`；列表支持 `page`、`per_page` |
| `POST /admin/reassignments/{id}` | `admin.reassignments.advance`；`action: "advance"`、当前记录 `version` |
| `GET /customer/tenants/{tenant}/reassignments[/{id}]` | `customer.reassignments.read`；仅本客户转出或接收记录 |
| `POST /customer/tenants/{tenant}/reassignments/{id}` | `customer.reassignments.accept`；目标客户提交 `action: "accept"`、确认请求 `id`、记录 `version` |

控制端不发送 `X-Tenant-Id`，客户端该头须与路径一致。普通角色须明确取得新增权限，升级不自动增权；`demo` 拒绝发起、推进、设置期限和确认接收。

```sh
# 控制端发起；保留同一个 REQUEST_ID 与 VERSION，用于结果未知时核对原请求。
curl --fail-with-body "$APP_URL/admin/devices/$DEVICE_ID/usage/reassign" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H 'Content-Type: application/json' \
  -d "{\"id\":\"$REQUEST_ID\",\"version\":$VERSION,\"target_tenant_id\":\"$TARGET_TENANT_ID\"}"

# 先读取实际阶段及版本，再明确推进；GET 本身不启动清理。
curl --fail-with-body "$APP_URL/admin/reassignments/$REQUEST_ID" \
  -H "Authorization: Bearer $ADMIN_TOKEN"
```

目标客户确认后设备标识保持不变，使用关系、凭据和精确 Topic 更新，使用期限默认重新置空。旧应用授权不会继承，新客户须明确重新授权。首次确认响应一次性交付密码；同一确认请求重试只返回原结果，密码为 `null`，遗失时从设备管理轮换。不要将密码写入日志、工单或普通查询记录。

`pending_reasons` 表示仍缺少的证明；`stale_version` 要求读取当前记录，`reassignment_isolation_pending` 表示尚不可接收，`reassignment_store_required` 表示必须恢复正确的持久存储配置。结束旧关系后不能通过重新启用设备或轮换旧凭据绕过交接；已送达对端的消息无法撤回。

## 设备注册授权

开发版在控制端的「运维中心 → 设备注册授权」管理注册资格，客户端从「物联管理 → 设备注册授权」查看当前客户已获配的资格。每项资格有独立标识、客户、额度和注册截止时间。页面分别展示可注册、已过期、额度用完及已停用；这些状态不表示设备在线，也不等于应用获得通信权限。

控制端签发需要 `admin.enrollments.issue`，换码与停用分别需要 `admin.enrollments.reset`、`admin.enrollments.disable`。只读人员使用对应身份域的 `enrollments.read`；升级时只为现有受保护最高管理员补齐新增节点，普通角色需要明确授予权限。客户端没有签发或增额接口，`demo` 环境拒绝所有相关写操作。

签发时保留请求 `id`，相同内容重试返回原资格，不能再次取回注册码；相同 `id` 携带不同内容返回 `enrollment_request_conflict`。注册码仅在成功签发或重置时交付一次，服务端只存验证摘要，列表、详情和审计不返回秘密。丢失注册码后通过控制端重置；已停用、过期或额度用完的资格不能通过重置重新启用。重置不改变客户、注册截止时间和已用额度，设备已形成的使用关系也不被删除。

| HTTP 入口 | 行为 |
| --- | --- |
| `GET /admin/device-authorizations` | 控制端分页查询，可用 `tenant_id` 筛选客户 |
| `POST /admin/device-authorizations` | 签发，提供 `id`、`tenant_id`、`name`、`capacity`、`register_expires_at`（Unix 秒） |
| `GET /admin/device-authorizations/{authorization}` | 查询公开资格及绑定记录分页 |
| `POST /admin/device-authorizations/{authorization}/{action}` | 提供准确 `tenant_id`、`version`；`action=reset` 一次性交付新注册码，`action=disable` 禁止新的注册 |
| `GET /customer/tenants/{tenant}/device-authorizations` | 客户查看自身资格，`X-Tenant-Id` 必须与路径一致 |
| `GET /customer/tenants/{tenant}/device-authorizations/{authorization}` | 客户查看自身资格的真实绑定记录 |

列表和绑定分页使用 `page`、`per_page`，默认 20 条，单页最多 100 条。注册码的注册期限只约束新的注册，不自动限制已注册设备的使用期；已注册设备撤权继续走设备生命周期。设备通过 `POST /public/devices/register` 消费资格，不要求物模型，成功时一次性交付独立接入凭据。重复请求只确认原注册结果；秘密遗失由有权人员轮换恢复。完整请求、错误码与原始 Topic 见[设备注册教程](../development/iot-devices.md#使用控制端签发的资格注册)。签发或注册成功均不代表设备已经建立网络连接。

## 设备接入与确认

独立 Broker 的协议契约见 [type-mqtt](plugins/type-mqtt.md)。物联网业务固定使用 MQTT 5、TLS 和 QoS 1，JSON 业务载荷与通用 Broker 的二进制能力分开。客户端必须验证证书链与服务端身份。

| 设备连接字段 | 契约 |
| --- | --- |
| Client ID | 预注册的设备标识 |
| Username | `设备标识:凭据标识` |
| Password | 当次交付的 64 位小写十六进制随机秘密 |
| Keep Alive / Session Expiry | 30 秒 / 86400 秒 |
| 上行 Topic | `iot/{tenant}/devices/{device}/epochs/{ownership}/up` |
| 下行 Topic | `iot/{tenant}/devices/{device}/epochs/{ownership}/down` |

使用注册响应返回的归属与 Topic，不猜测另一设备或旧归属的地址。设备以稳定业务标识持久缓存待确认数据；MQTT PUBACK 只说明 Broker 的协议接管，收到平台业务持久接收回执后才清理对应缓存。超时、重复和重连沿用原标识；事件、重复和乱序数据不会伪装成新的实时遥测。

```mermaid
sequenceDiagram
  autonumber
  participant Dev as 设备
  participant Br as MQTT Broker
  participant Ing as 数据接收
  participant Db as PostgreSQL

  Dev->>Br: CONNECT 凭据
  Br-->>Dev: CONNACK
  Dev->>Br: PUBLISH 上行 QoS1
  Br-->>Dev: PUBACK 协议接管
  Br->>Ing: 业务消息
  Ing->>Db: 持久接收
  Note over Dev,Db: PUBACK 不等于业务已落库
```

“设备详情”从三个独立层次显示指令：平台受理、MQTT 交付、设备执行。在线单次指令使用原 60 秒期限；期限内无结果保持等待，到期仍无结果才显示未知，不推断成功或失败。响应丢失可用同一标识确认原受理；设备必须持久去重，不能把重投当作第二次动作。主动对账是独立结果查询，也不能重新执行动作。

```mermaid
sequenceDiagram
  autonumber
  actor User as 客户账号
  participant Api as HTTP API
  participant Br as MQTT Broker
  participant Dev as 设备

  User->>Api: 下发指令
  Api-->>User: 平台已受理
  Api->>Br: 下行 PUBLISH
  Br->>Dev: Topic 下行
  Dev-->>Api: 执行结果
  Note over User,Dev: 受理、交付、执行分三层判断
```

模型切换同样等待设备明确确认其支持并持久应用目标版本。请求受理后暂停新控制，超时继续原切换，不能提前修改设备绑定；旧缓存和历史仍按原模型版本解释。

## 数据、告警与导出

原始历史保留 7 天，分钟统计独立保留 90 天。曲线支持 `count/min/max/sum/avg/last` 六项统计和最多 2000 点，显示实际时间粒度与当时模型单位；页面时间采用浏览器时区。旧原始记录到期不代表统计同时删除。

告警规则针对单个数值属性配置上下限、恢复回差和连续三次样本判断，发布新版本保留旧规则语义。确认表示人员已知悉并登记确认，数值恢复另行判断。站内通知保留触发或结束时的租户与规则版本，跳转原告警；规则停用或设备退役不会伪造数值恢复。

历史页内创建异步 CSV 导出任务，支持进度、取消、恢复和下载，没有独立导出菜单。文件保留 24 小时，每次下载重新检查当前权限；撤权后不能继续使用旧下载地址。导出目录是私有存储，不作为静态站点目录。

## 转移与恢复

临时支持授权已从当前应用移除，管理端不再提供支持授权的创建、进入与撤销入口。旧客户端继续携带 `X-Support-Id` 等旧身份请求头时会被服务端拒绝。

设备转移从双方审批开始，依次进入 `frozen → isolating → activating → completed`。源归属冻结后仍需排空缓存、处理未知指令、证明旧身份隔离，再受控交付新凭据并等待设备持久确认。目标租户不会提前获得源历史；旧历史留在源租户，新数据使用新归属。

恢复由维护人员通过 `iot:recovery` 执行，管理端没有可直接恢复数据库的页面。先停止旧系统并完成网络及基础设施隔离，再按受信的当前授权快照核对，依次进行 `begin/isolate/review/restore`。恢复门会限制应用角色启动，但不会替操作者停止已经运行的旧进程，也不自动删除旧数据或重新开放全部权限。

## 进程与维护入口

所有角色来自同一个应用入口。以下为角色清单，不是一段可以不经配置直接启动的脚本；生产使用已验证的原生产物或运行包入口替换 `php bin/typeapp`。

| 角色 | 用途及前置条件 |
| --- | --- |
| `serve` | 人员与租户 HTTP API；先通过 `app:install` 完成空库安装 |
| `app:schedule once/history/work` | 当前源码的维护调度入口；用持久 Redis 协调两端审计清理及执行历史，普通启动不自动运行；[配置与例子](plugins/type-scheduler.md#最小使用示例) |
| `iot:mqtt-install` | 显式安装 MQTT 持久表，并取得同步提交证明 |
| `iot:mqtt` | 独立 Broker；按运行库能力使用 Swoole Server 或 Coroutine Socket，角色沿用 Process/Thread/Coroutine。启用持久消息须准备 PostgreSQL 同步主备、唯一节点身份和 worker 命令；TLS 与传输边界见 [MQTT 说明](plugins/type-mqtt.md) |
| `iot:ingest` | 独立数据接收；准备单独服务凭据与 TLS CA |
| `iot:aggregate`、`iot:alarm`、`iot:notices` | 分别执行统计、告警和通知的有界批次；由部署调度重复运行 |
| `iot:exports`、`iot:exports-work` | 分别按步数或有界时间执行导出后台任务，需专用 Redis 和私有目录 |
| `iot:device state/enqueue/send/listen/transfer` | 受控设备模拟器，使用独立持久缓存；不代表真实固件已验收 |
| `iot:mqtt-statistics`、`iot:mqtt-nodes` | 读取持久配额及节点观察，失败或未知不能当作零 |
| `iot:mqtt-fence` | 登记精确节点及运行代次已完成的硬隔离，可追加原操作ID；不执行基础设施隔离 |
| `iot:mqtt-fence-result` | 按原操作ID查询同步隔离结果；查不到或资源未确认时继续未知，不重发隔离 |
| `iot:wal`、`iot:backup`、`iot:recovery` | WAL 归档、备份保留和恢复核对；具体参数先看当前 `help` |

`iot:history-clean`、`iot:aggregate-clean`、`iot:notices-clean`、`iot:exports-clean`、`app:audit-clean`、`iot:command-clean` 是各自有界清理入口。按 `help` 提供批次与续扫参数，不把一次批次完成当作所有积压已经处理。

Broker 操作事件保留180天，复用人员审计、服务端筛选与有界分页。租户查询每页及详情重验 `audit.read` 与 `broker.read`；在线修改接入主体、凭据代次和 Topic 授权另需 `broker.write`。平台身份不自动获得租户控制权。到期清理不删除异步操作事实、去重凭据、隔离、撤权或恢复依据。未知操作用原 ID 对账，不能仅凭日志缺失或命令非零退出认定动作未执行。

完整配置在 `config/app.php` 与 `.env.example`。应用必须使用匹配的 Swoole 原生扩展；Broker、客户端、持久 worker 和设备授权直接使用 Swoole 官方通信与并发能力，不再配置网络驱动选择。设备 10000、服务 100、物理连接 10100 及会话 20000 是配置预算，尚不是目标负载通过证明。通知和导出各自配置 Redis，缓存开启与否不替代这两个后台角色的依赖。

## 当前验收边界

已有本机 PHP、全量 AOT、无源码运行包及多项真实数据库和 MQTT 局部验证。独立候选的 MQTT 首轮出现重复回执数不匹配和接收 `internal_error`；同产物一次复验通过，但首轮原因未解释，原候选仍记失败。双设备 90 秒混合负载也未通过，不能把编译成功、三库管理 API 或某个协议专题通过合并成完整交付结论。

MQTT 全部适用标准、Linux x64 环境、独立故障域、受控旧主硬隔离、目标容量与真实设备验收仍需完成。自行组合时锁定实际源码、依赖、工具链和产物，再验证自己的设备协议、恢复流程与负载。

[快速开始](quickstart.md) · [MQTT 组件](plugins/type-mqtt.md) · [构建与部署](deployment.md)。
