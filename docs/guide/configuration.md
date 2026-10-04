# 配置与环境

TypeApp 在构建时解析配置声明，在应用启动时读取环境值并创建不可变快照。修改声明需要重新生成或构建；修改外部环境值需要重启应用。

本页关注部署时需要管理的配置。Swoole 等非系统原生运行依赖由构建校验并静态链接，部署者无需在 `.env` 中配置扩展安装流程；开发 CLI 与构建机的要求见[环境与依赖](environment.md)。程序与配置独立维护，启动方式见[构建与部署](deployment.md)。

## 配置声明

例如 `config/app.php`：

```php
<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'typeapp'),
    'http' => [
        'port' => env('APP_PORT', 9501),
    ],
    'debug' => env('APP_DEBUG', false),
];
```

`env()` 是受限配置声明的语法，构建器将它转为环境读取代码，不把它注册成运行时全局函数。`config/app.php` 对应 `app` 配置节，上述端口键为 `app.http.port`。

配置文件只包含 `declare(strict_types=1)` 和一个返回数组，允许嵌套标量与环境默认值。不要放入连接创建、文件读取、任意函数调用或业务逻辑。配置文件须在构建声明的 `config.files` 中显式列出。`config/route.php` 是路由声明，由 `routing` 指向，不进入配置节，也不允许 `env()`。

## 环境优先级

```text
进程环境 → 应用根的 .env → 配置声明中的默认值
```

管理端保存运行配置也按这一优先级校验。由进程环境提供的字段只读，保存其他字段不会把数据库类型恢复为缺省 SQLite，也不会将进程中的秘密写回 `.env`。文件本身仍须符合声明类型，生效的 `DB_DRIVER` 必须匹配所选程序 profile；校验失败保留原文件，成功保存后需重启服务。无副作用预检与实际连接检查分别进行，见下文范围；保存成功不代表外部服务可连接。

源码开发时，可以从项目根复制示例后填写实际值：

```bash
cp .env.example .env
```

单程序部署不需要下载源码或 `.env.example`；在应用根创建自己的 `.env` 即可，最小 SQLite 示例见[首次启动](deployment.md#首次启动)。环境文件是启动数据，不执行 PHP 或 shell 命令，不进行变量插值，也不写回整个进程的环境。实际 `.env`、令牌、数据库密码与认证文件不进入 Git 或编译载荷。

| 默认值 | 环境读取规则 |
| --- | --- |
| 字符串 | 保留原始文本，空字符串也是一个值 |
| 整数 | 严格读取有界十进制整数，非法值不静默转换 |
| 布尔值 | 接受 true/yes/on/1、false/no/off/0，大小写不敏感 |
| null | 缺失时返回 null，存在时保留字符串 |

## 常用应用配置

新增 HTTP 预算、部署分额及共用预检属于当前 `main`，尚未包含公开 RC14；应使用包含对应修改的源码构建。历史版本按其随附配置说明使用。

| 环境键 | 用途 |
| --- | --- |
| `APP_NAME` | 应用名称，默认 `typeapp` |
| `APP_LISTEN` / `APP_PORT` | 监听地址与端口，默认 `127.0.0.1:9501` |
| `APP_ALLOWED_HOSTS` | 接受的 Host，列表以逗号分隔 |
| `APP_TRUSTED_PROXIES` | 明确信任的代理，默认不信任代理 |
| `APP_HTTP_THREADS` | HTTP 执行线程数，默认 2；不得超过部署计划的每进程线程上限；Windows 当前为单执行者 |
| `APP_HTTP_MAX_REQUESTS` / `APP_HTTP_MAX_CONNECTIONS` | 每个 HTTP 执行者的请求/连接上限，默认 64 / 256 |
| `APP_HTTP_REQUEST_MS` / `APP_HTTP_DRAIN_MS` / `APP_HTTP_CLEANUP_MS` | 请求、停止排空和清理预算，默认 30000 / 5000 / 1000 毫秒 |
| `APP_DEBUG` | 开发调试开关，默认 false |
| `APP_BASE_PATH` | 已存在的运行根目录，由进程环境指定 |

当前 RC 的单程序入口先定位可执行文件，默认以程序所在目录作为应用根，不随调用者的工作目录改变；开发入口使用源码项目根。需要分离程序和持久数据时，在进程环境中将 `APP_BASE_PATH` 设置为已存在的绝对目录，程序从该目录读取 `.env`。不能在尚未定位的 `.env` 里用此键改变它自身的位置。SQLite 数据路径等相对应用根解释，升级时保留同一个持久化根目录。

## 检查范围与生效时间

```bash
./type-app config:check
./type-app config:check --connect
```

第一条检查环境文件结构、声明类型及实际消费者的交叉约束：数据库/Redis 部署分额、HTTP 并发与排空预算、Host/代理、上传目录和构建 profile。第二条额外连接数据库及本程序启用的 Redis 用途。检查不创建 SQLite 数据库、不迁移、不启动角色；连接成功也不证明业务模式已完成升级。

检查命令、管理端保存和角色启动共用无副作用预检。检查/保存覆盖全部已启用配置；角色只检查自身需求，例如数据库维护不会因未使用的 HTTP 上传盘缺失而被阻断。保存失败保留原文件。被进程环境覆盖的文件值仍需符合声明类型，但实际角色约束按最终生效快照判断。

`--remember` 隐含连接检查，成功后保存私有恢复副本。`config:restore` 为修复入口，只校验恢复文件结构，不以当前失联依赖或错误进程覆盖阻止恢复；退出 4 表示文件已恢复、仍需运维重启。当前进程继续使用启动时的配置快照。恢复后重新执行 `config:check --connect`，核对部署角色和 `/readyz`，再切换流量。

## 数据库与缓存

物联中心成品案例支持 `sqlite`、`mysql` 和 `pgsql`，`DB_DRIVER` 必须与所下载程序的数据库 profile 一致；修改环境值不能让 SQLite 程序改用 MySQL。独立模板的驱动在安装与构建时选定，不能用环境值启用未安装的驱动。

MySQL/PostgreSQL 使用 `DB_HOST`、`DB_PORT`、`DB_DATABASE`、`DB_USERNAME`、`DB_PASSWORD`；端口为 0 时分别选择 3306、5432。`DB_TLS_CA` 用于受信任 CA 与主机名校验。SQLite 使用 `DB_SQLITE_FILE` 指向数据文件。

物联中心尚未接入通用业务查询缓存，因此配置表单不提供启用开关，旧 `APP_CACHE_ENABLED=true` 在预检时返回 `feature_unavailable`。旧通用 `REDIS_HOST` 等键不再作为业务缓存配置消费。独立应用仍可使用 [type-cache](plugins/type-cache.md)，显式配置缓存范围、租户键、TTL、事务后失效及故障策略，并在构建 profile 中声明能力。

通知、导出和调度使用各自的 Redis 配置及命名空间，HTTP 查询和维护清理不因此强制连接 Redis。默认发布 profile 保留这些后台能力，按运行的角色准备持久、不可淘汰的 Redis 服务。

## 部署连接预算

HTTP、数据库维护、通知、导出与调度通过同一应用工厂装配数据库池；迁移专用连接也计入预算。Redis 的命名端点和用途池共享当前执行者的分额。以下值是部署规划，不是服务端当前连接数。

| 配置 | 默认值与含义 |
| --- | --- |
| `DB_SERVER_BUDGET` / `DB_ADMIN_RESERVE` | 100 / 10；数据库连接总额与运维预留 |
| `REDIS_SERVER_BUDGET` / `REDIS_ADMIN_RESERVE` | 256 / 16；Redis 连接总额与运维预留 |
| `APP_MAX_REPLICAS` / `APP_ROLLING_SURGE` | 1 / 1；最大正常副本与滚动期间额外副本 |
| `APP_DATABASE_PROCESSES` | 8；每副本同时使用连接的全部角色及子进程上限 |
| `APP_DATABASE_THREADS` | 2；每进程最大同时使用连接的线程数，含主线程及未退出的旧代 |
| `DB_POOL_CAPACITY` / `DB_POOL_IDLE` | 4 / 0；单池容量与空闲保留上限，仍受部署分额限制 |
| `DB_POOL_WAITERS` / `DB_POOL_WAIT_MS` | 64 / 1000；池内等待数及最长等待毫秒 |
| `REDIS_POOL_CAPACITY` | 4；应用普通命令及脚本用途的单池容量 |

每线程分额为 `floor((服务端总额 − 运维预留) / ((最大副本 + 滚动增量) × 每副本角色数 × 每进程线程数))`。默认数据库每线程分得 2 个连接，整个声明拓扑最多分配 64 个。配置池容量为 4 不会把分额扩大到 4。预检拒绝预留超过总额、无可分配份额或 HTTP 线程超过计划等组合。

这是一套按声明拓扑执行的本地硬额度，不是跨机器自动发现或全局连接锁。部署者须计入实际运行的 HTTP、MQTT 专属持久连接、工作进程和新旧代重叠；不得启动超过声明数量的角色。独立组件应用在同一执行者内建立多个管理器时，须注入同一 `DeploymentBudget` 实例，不能各自复制完整分额。启用空闲连接复用仍受驱动的会话清洗能力约束。

## 物联网配置

主仓物联网配置声明同样位于 `config/app.php`，环境示例统一在 `.env.example`。`/admin` 与 `/customer` 使用独立账号域、登录令牌和每次请求的租户授权；初始账号由 `app:install` 创建，密码只从 `APP_ADMIN_PASSWORD`、`APP_CUSTOMER_PASSWORD` 的受控进程环境取得，不从命令参数读取。

| 配置组 | 用途 |
| --- | --- |
| `IOT_MQTT_*` | 独立 Broker、TLS、显式 worker 命令、节点身份和分类资源预算 |
| `IOT_INGESTION_*` | 接收进程的独立服务凭据、实例名和 TLS 验证 |
| `IOT_DEVICE_*` | 设备模拟器的归属、模型、凭据、持久缓存和 TLS 验证 |
| `IOT_EXPORT_*` | 私有导出目录、专用 Redis 连接和命名空间 |
| `IOT_NOTICES_*` | 通知消费的专用 Redis 连接和命名空间 |

Swoole 是必需通信与基础并发底层。Broker、客户端、持久 worker 和设备授权均直接使用 Swoole 官方能力，不再配置可切换的网络驱动。`IOT_MQTT_COMMAND` 是当前应用的 JSON 命令参数数组，不经 shell；开发可显式指向 PHP 与 `bin/typeapp`，原生部署改为该产物入口。设备接入与接收角色要求 PostgreSQL 严格同步主备，不能沿用物联中心成品案例的默认 SQLite。启动角色及使用流程见[物联网中心](iot-center.md)。

## 调试边界

PHP 开发入口明确取得开发权限；原生入口固定使用生产权限，HTTP 头、query 和 body 不能打开调试。内部错误响应保留 `internal_error` 和服务端生成的 `request_id`，调试细节写入受控日志。

当前 `main` 的生产内部异常会记录稳定错误码、异常类型、受控代码位置、`request_id` 和真实 `build_id`，请求完成记录包含状态、耗时及结果。原始异常消息、SQL、参数与凭据不入日志；开发权限只增加有限调用帧。原生异常可能没有准确 PHP 行号，应结合构建身份定位。该修复尚未包含公开 RC14，使用方式见[运行探针与诊断](deployment.md#运行探针与诊断)。

继续阅读：[HTTP 与路由](routing.md) · [数据库与模型](database.md)。
