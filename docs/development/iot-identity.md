# 双端身份与 RBAC

TypeApp 的身份系统分为互不相通的管理端和 SaaS 客户端两个账号域。管理账号只进入 `/admin`，客户账号只进入 `/customer`；密码、会话、角色和审计来源分别存储和校验。客户账号通过租户成员关系进入一个或多个租户，平台管理账号不会因为能够管理租户目录而自动取得租户业务权限。

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

## 验证

优先在 macOS 上使用同一 PHP 入口和同一 AOT 产物分别验证：

```sh
php tests/iot-identity.php --php sqlite --app
composer typeapp:build
php tests/iot-identity.php build/app/type-app sqlite --app
```

MySQL 和 PostgreSQL 验收必须使用独立测试实例；无源码运行再追加 `--no-source`。身份测试只证明当前双端契约，不替代设备接入、MQTT 协议、告警、导出和跨平台原生验收。
