# 配置与环境

TypeApp 在构建时解析配置声明，在应用启动时读取环境值并创建不可变快照。修改声明需要重新生成或构建；修改外部环境值需要重启应用。

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

需要文件配置时，从项目根复制示例后填写实际值：

```bash
cp .env.example .env
```

环境文件是启动数据，不执行 PHP 或 shell 命令，不进行变量插值，也不写回整个进程的环境。实际 `.env`、令牌、数据库密码与认证文件不进入 Git 或编译载荷。

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

`APP_BASE_PATH` 未指定时，原生应用以工作目录为运行根；开发入口先切换到应用根。SQLite 数据路径等相对运行根解释，部署时应明确工作目录与持久化数据位置。

## 数据库与缓存

物联中心成品案例通过 `DB_DRIVER` 选择 `sqlite`、`mysql` 或 `pgsql`。独立模板的驱动在安装与构建时选定，不能用环境值启用未安装的驱动。

MySQL/PostgreSQL 使用 `DB_HOST`、`DB_PORT`、`DB_DATABASE`、`DB_USERNAME`、`DB_PASSWORD`；端口为 0 时分别选择 3306、5432。`DB_TLS_CA` 用于受信任 CA 与主机名校验。SQLite 使用 `DB_SQLITE_FILE` 指向数据文件。

物联中心成品案例默认关闭通用 Redis 缓存。显式启用 `APP_CACHE_ENABLED=true` 后，配置 `REDIS_HOST`、`REDIS_PORT`、`REDIS_DATABASE` 及所需认证/TLS 参数。缓存已启用但连接失败会明确报错，不把失败伪装成命中。

## 物联网配置

主仓物联网配置声明同样位于 `config/app.php`，环境示例统一在 `.env.example`。`/admin` 与 `/customer` 使用独立账号域、登录令牌和每次请求的租户授权；初始账号由 `app:install` 创建，密码只从 `APP_ADMIN_PASSWORD`、`APP_CUSTOMER_PASSWORD` 的受控进程环境取得，不从命令参数读取。

| 配置组 | 用途 |
| --- | --- |
| `IOT_MQTT_*` | 独立 Broker、TLS、显式 worker 命令、节点身份和分类资源预算 |
| `IOT_INGESTION_*` | 接收进程的独立服务凭据、实例名和 TLS 验证 |
| `IOT_DEVICE_*` | 设备模拟器的归属、模型、凭据、持久缓存和 TLS 验证 |
| `IOT_EXPORT_*` | 私有导出目录、专用 Redis 连接和命名空间 |
| `IOT_NOTICES_*` | 通知消费的专用 Redis 连接和命名空间 |

Swoole 是必需通信底层。当前业务仍保留 `IOT_MQTT_IO_DRIVER=swoole` 配置键，组件和调用者迁移完成后应一并移除驱动选择；现有配置不代表全部内部网络路径已完成迁移。`IOT_MQTT_COMMAND` 是当前应用的 JSON 命令参数数组，不经 shell；开发可显式指向 PHP 与 `bin/typeapp`，原生部署改为该产物入口。设备接入与接收角色要求 PostgreSQL 严格同步主备，不能沿用物联中心成品案例的默认 SQLite。启动角色及使用流程见[物联网中心](iot-center.md)。

## 调试边界

PHP 开发入口明确取得开发权限；原生入口固定使用生产权限，HTTP 头、query 和 body 不能打开调试。内部错误响应保留 `internal_error` 和服务端生成的 `request_id`，调试细节写入受控日志。

继续阅读：[HTTP 与路由](routing.md) · [数据库与模型](database.md)。
