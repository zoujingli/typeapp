# 双端身份与 RBAC

物联中心的身份系统分为互不相通的管理端和 SaaS 客户端两个账号域。管理账号只进入 `/admin`，客户账号只进入 `/customer`；密码、会话、角色和审计来源分别存储和校验。客户账号通过租户成员关系进入一个或多个租户，平台管理账号不会因为能够管理租户目录而自动取得租户业务权限。身份规则由应用实现，通用框架不读取这些业务表。

## 模型与事务

账号、会话、租户、成员和角色使用稳定的领域 Model；`IdentityService`、`TenantService`、`RoleService` 的公开实体操作不接收连接。应用启动时装配 `Db`，HTTP 和任务入口绑定当前 Swoole 执行作用域，模型按实际操作取得当前作用域的受管连接。查询与上下文的通用规则见[Model 自动连接与主从路由](model-connections.md)。

| 数据表 | 当前 Model | 归属与字段语义 |
| --- | --- | --- |
| `admin_users` | `app\common\model\AdminUser` | 全局平台账号；`version` 是授权锁保护的资料版本 |
| `customer_users` | `app\common\model\CustomerUser` | 全局客户账号；不以当前租户限定账号 |
| `admin_sessions` | `app\common\model\AdminSession` | 平台会话与撤销状态 |
| `customer_sessions` | `app\common\model\CustomerSession` | 客户会话；普通登录的 `actor_id`、`source_session_id` 明确为 null |
| `iot_tenants` | `app\common\model\Tenant` | 全局租户目录，使用模型乐观锁 |
| `customer_members` | `app\common\model\CustomerMember` | 带 `tenant_id` 的成员实体，自动隔离并使用模型乐观锁 |
| `admin_roles` | `app\common\model\AdminRole` | 平台角色，使用模型乐观锁；`scope_id` 固定为平台范围 |
| `customer_roles` | `app\common\model\CustomerRole` | 将 `scope_id` 映射为租户字段，自动隔离并使用模型乐观锁 |
| `admin_user_roles` | `AdminUser.roles`、`AdminRole.users` | 纯绑定关系，由 `BelongsToMany` 管理 |
| `customer_member_roles` | `CustomerMember.roles`、`CustomerRole.members` | 纯绑定关系；中间表以 `pivotTenant: 'tenant_id'` 隔离 |
| `broker_users`、`broker_sessions` | `app\broker\model\BrokerUser`、`BrokerSession` | 独立 Broker 管理域的账号和会话 |

角色实体与人员角色绑定已通过 Model 和关系持久化。静态检查限制三个 Service 的底层表查询：安装互斥、本人租户投影、最后管理员保护和固定权限码集合按具体方法与数据集合登记，不向整个 Service 开放例外。角色权限集合的持久化归属仍需收口；macOS ARM64 同一完整应用 AOT 产物已通过三库身份 HTTP 与移除源码运行，其他平台及完整业务组合另行验收。完整身份表结构以 `app/common/database/Schema.php` 为准；角色授权规则由 `RoleService` 维护，权限码取自固定目录，不是可由客户端任意新增的实体。

登录、会话撤销检查、权限与租户资格验证明确读取主库。修改通过 `Db::transaction()` 保持同库事务，业务与审计共用该作用域的主库租约；事务外写后需要立即确认结果时显式 `master()`。账户资料版本由身份服务在授权和账号锁内推进，错误原密码只改变既定失败计数，不推进资料版本。租户、成员的普通模型保存使用各自声明的版本字段，不把这两种版本合同混为一谈。

`RoleService::authorized()` 在授权锁内重验身份，并向回调提供 `Identity` 和有效权限列表；回调不接收连接。普通实体直接调用 Model，审计及受控跨模型操作通过当前 `Db` 主库租约参与同一事务。角色解除和管理员替换同步推进受影响账号或成员的资料版本；失败整体回滚。

成员操作先验证会话、成员资格及权限，再将目标租户绑定到当前作用域。尚未选择租户时，本人可用租户列表是以已认证账号为条件的受控跨模型投影；平台管理员操作单个租户时，验证平台权限后临时绑定目标租户。退出正常或异常路径均恢复外层绑定，请求头与消息载荷不直接授予身份。

## 初始化

新部署只接受空数据库，使用 `app:install` 在一个事务边界内建立安装身份、平台初始管理员、客户初始管理员、初始租户及固定权限目录：

```sh
export APP_ADMIN_PASSWORD='至少12字节的管理密码'
export APP_CUSTOMER_PASSWORD='至少12字节的客户密码'
php bin/typeapp app:install platform-admin '平台管理员' customer-admin '客户管理员' '初始租户'
```

口令只从受控进程环境读取，不写入命令参数、`.env` 或日志。安装前必须确认数据库为空；已有表或安装记录返回 `TYPE_MIGRATION_NOT_EMPTY`，不会迁移、删除或覆盖现有数据。安装后使用 `php bin/typeapp serve` 启动 HTTP，生产使用同一命令名的 AOT 产物。

## 认证接口

所有认证请求要求 JSON，令牌使用 Bearer 头传递。服务端每次读取会话、账号状态、角色和租户成员关系，不把前端菜单或上一次 `auth/me` 结果当作授权依据。

| 入口 | 作用 |
| --- | --- |
| `POST /admin/auth/login` | 管理账号登录，返回平台会话令牌。 |
| `GET /admin/auth/me` | 返回管理账号、平台权限目录和管理端菜单。 |
| `POST /admin/auth/logout` | 撤销当前管理会话。 |
| `POST /customer/auth/login` | 客户账号登录，返回客户会话令牌。 |
| `GET /customer/auth/me` | 返回客户账号、可用租户、当前成员角色和客户端菜单。 |
| `POST /customer/auth/logout` | 撤销当前客户会话。 |
| `GET /admin/profile` | 管理人员个人工作区。 |
| `GET /customer/profile` | 当前租户客户工作区，需要准确的 `X-Tenant-Id`。 |

登录标识为 3–100 位小写字母、数字及 `_.@-`，密码为 1–72 字节；初始化口令另外要求至少 12 字节。密码使用 bcrypt，数据库只保存会话令牌摘要。账号停用、角色停用、成员停用、密码修改和会话撤销会在下一次请求立即生效。

## 管理端权限

平台角色使用固定 `admin.*` 权限目录，由 `app\common\service\RoleService::catalog('admin')` 声明。平台管理端可维护平台人员、平台角色、客户账号、租户、设备资产、全局配置、操作审计和 Broker 观察；每个读取和写入动作都有独立节点。平台最高管理员和最后一个有效最高管理员受到事务级保护，普通授权员不能授予自己没有的节点。

## 客户端权限

租户角色使用固定 `customer.*` 权限目录，由 `RoleService::catalog('customer')` 声明。客户端管理租户成员、租户角色、产品、物模型、设备、历史数据、告警、通知、导出和运行观察。所有租户业务路径同时校验路由租户 ID、`X-Tenant-Id`、成员状态和角色并集；角色或成员停用后直接拒绝访问。菜单分组只用于导航，不构成授权边界。

成员和角色变更携带读取时的 `version`，写入在同一事务中重新读取调用者权限并保护最后一个有效租户管理员。批量角色绑定限制成员和角色数量，失败整体回滚；跨租户查询、跨域令牌和伪造支持头均拒绝。

## 模拟登录与审计

平台人员可在明确的 `admin.customers.impersonate` 权限下进入客户工作区。模拟会话绑定来源管理会话、目标客户和期限，权限只取目标客户当前租户角色；退出来源、撤销来源或停用目标都会撤销派生会话。审计记录真实操作人、来源会话、目标客户、租户、动作和结果，不记录密码、令牌或设备秘密。

后台导出从已持久化的来源信息恢复身份，执行时重新验证会话、模拟来源和当前权限。队列消息仅定位已保存任务，不直接建立授权上下文。Worker 为每次任务绑定独立作用域，模型重验与任务状态写入使用同一主库事务；受管子协程只复制可信标识值，不能继承父连接或父事务。

## 验证

优先在 macOS 上使用同一 PHP 入口和同一 AOT 产物分别验证：

```sh
php tests/iot-identity.php --php sqlite --app
composer typeapp:build
php tests/iot-identity.php build/app/type-app sqlite --app
```

MySQL 和 PostgreSQL 验收必须使用独立测试实例；无源码运行再追加 `--no-source`。身份测试只证明当前双端契约，不替代设备接入、MQTT 协议、告警、导出和跨平台原生验收。
