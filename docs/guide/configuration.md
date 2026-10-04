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

管理端保存运行配置也按这一优先级校验。由进程环境提供的字段只读，保存其他字段不会把数据库类型恢复为缺省 SQLite，也不会将进程中的秘密写回 `.env`。文件本身仍须符合声明类型，生效的 `DB_DRIVER` 必须匹配所选程序 profile；已覆盖的校验失败会保留原文件，成功保存后需按提示重启服务。当前校验尚未覆盖全部字段组合及启动条件，不能把保存成功当作服务已可启动，见下文检查范围。

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

| 环境键 | 用途 |
| --- | --- |
| `APP_NAME` | 应用名称，默认 `typeapp` |
| `APP_LISTEN` / `APP_PORT` | 监听地址与端口，默认 `127.0.0.1:9501` |
| `APP_ALLOWED_HOSTS` | 接受的 Host，列表以逗号分隔 |
| `APP_TRUSTED_PROXIES` | 明确信任的代理，默认不信任代理 |
| `APP_DEBUG` | 开发调试开关，默认 false |
| `APP_BASE_PATH` | 已存在的运行根目录，由进程环境指定 |

当前 RC 的单程序入口先定位可执行文件，默认以程序所在目录作为应用根，不随调用者的工作目录改变；开发入口使用源码项目根。需要分离程序和持久数据时，在进程环境中将 `APP_BASE_PATH` 设置为已存在的绝对目录，程序从该目录读取 `.env`。不能在尚未定位的 `.env` 里用此键改变它自身的位置。SQLite 数据路径等相对应用根解释，升级时保留同一个持久化根目录。

## 检查范围与生效时间

```bash
./type-app config:check
./type-app config:check --connect
```

第一条检查环境文件结构、声明类型、部分字段范围及数据库/Redis 配置；第二条额外连接所需依赖。它们不会重启运行角色。`--remember` 隐含连接检查，并在成功后保存私有恢复副本；`config:restore` 恢复该文件，仍需按返回结果处理进程环境覆盖并重启。

当前检查与实际启动尚未完全统一：数据库总预算与预留/线程的组合、Host/代理格式、上传临时目录条件仍可能到 HTTP 装配时才被拒绝。`valid` 只代表本命令已覆盖的检查通过，`--remember` 也不等于一次真实服务启动验收。修改这些配置后，应在隔离实例验证对应角色启动与业务入口，再按部署流程切换。正在运行的进程继续使用原配置快照；统一预检的完成条件见[启动与运行收口](roadmap.md#启动与运行收口)。

## 数据库与缓存

物联中心成品案例支持 `sqlite`、`mysql` 和 `pgsql`，`DB_DRIVER` 必须与所下载程序的数据库 profile 一致；修改环境值不能让 SQLite 程序改用 MySQL。独立模板的驱动在安装与构建时选定，不能用环境值启用未安装的驱动。

MySQL/PostgreSQL 使用 `DB_HOST`、`DB_PORT`、`DB_DATABASE`、`DB_USERNAME`、`DB_PASSWORD`；端口为 0 时分别选择 3306、5432。`DB_TLS_CA` 用于受信任 CA 与主机名校验。SQLite 使用 `DB_SQLITE_FILE` 指向数据文件。

通用 Redis 缓存默认关闭。当前物联中心声明了 `APP_CACHE_ENABLED` 及 `REDIS_HOST`、`REDIS_PORT`、`REDIS_DATABASE`、认证/TLS 配置，但尚未据此装配业务查询缓存；打开开关不会自动缓存查询。默认发布 profile 未启用通用 `cache` 功能，原生产物会拒绝在运行时开启它。独立应用需要在构建 profile 中声明能力，并通过缓存组件显式配置数据范围、TTL、失效及故障策略，见[type-cache](plugins/type-cache.md)。

通知、导出和调度使用各自的 Redis 配置及命名空间，不受通用缓存开关控制。数据库预算当前主要接入 HTTP；后台角色的连接需求应另行计入部署容量，不能把 `DB_SERVER_BUDGET` 理解为已覆盖所有角色的全局硬限制。

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

当前生产请求完成日志包含状态码，但内部异常的类型和位置只在开发调试路径记录。生产故障原因日志仍待补齐，不能假定凭 request ID 已可定位全部内部错误，也不要通过开放生产调试解决这一缺口。

继续阅读：[HTTP 与路由](routing.md) · [数据库与模型](database.md)。
