# type-orm-mysql

MySQL 驱动采用 PDO，连接时明确启用异常、原生预处理和 utf8mb4。构造函数只保存配置，真正连接在作用域借用时建立；缺少已选择的 pdo_mysql 扩展会明确失败。

## 安装与版本

本组件通过公开 Git 分发子仓安装，不假设已发布到 Packagist。先在应用的 Composer 根配置登记下列组件及传递依赖仓库；HTTPS 读取不需要 SSH 密钥，依赖包自己的 repositories 不会自动传递给消费应用。

```sh
composer config minimum-stability dev
composer config prefer-stable true
composer config repositories.type-runtime vcs https://github.com/zoujingli/type-runtime.git
composer config repositories.type-orm vcs https://github.com/zoujingli/type-orm.git
composer config repositories.type-orm-mysql vcs https://github.com/zoujingli/type-orm-mysql.git
composer require zoujingli/type-orm-mysql:dev-main
```

`dev-main` 的分支别名为 `1.0.x-dev`；本仓组件间使用 `~1.0.0@dev` 约束。开发分支不等于已发布稳定 1.0 版本。提交应用的 `composer.lock` 固定实际分发提交；构建工具只放 `require-dev`。详细依赖与公开分发规则见[组件组织与安装](https://github.com/zoujingli/typeapp/blob/main/docs/development/component-structure.md)。

仅依赖 type-orm 及其运行时，不要求 PostgreSQL、SQLite 或 HTTP 核心。密码不进入公开错误信息；底层错误保留为内部异常原因。

当前真实验证基线是 MySQL 8.4.11。服务器版本、驱动能力及高级锁与迁移行为需要各自测试，不能推定其他兼容数据库已经通过。

## 声明式使用示例

以下声明式入口通过当前作用域借用连接并执行参数绑定查询。只读测试仍需要有效运行配置与原生扩展，示例不会创建服务器数据库或账号。

```php
<?php

declare(strict_types=1);

use Type\Orm\Database;
use Type\Orm\Mysql\MysqlDriver;
use Type\Runtime\ExecutionScope;

/** 只在环境键不存在时采用默认值，保留合法的空字符串。 */
function databaseSetting(string $key, string $default): string
{
    $value = getenv($key);

    return $value === false ? $default : $value;
}

/**
 * 借用一个 MySQL 会话执行参数绑定查询，凭据仅在运行时读取。
 */
function main(): void
{
    $port = filter_var(databaseSetting('DB_PORT', '3306'), FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    if (!is_int($port)) {
        throw new InvalidArgumentException('DB_PORT 必须为有效整数端口');
    }
    $driver = new MysqlDriver(databaseSetting('DB_HOST', '127.0.0.1'), $port,
        databaseSetting('DB_DATABASE', 'type_example'), databaseSetting('DB_USERNAME', 'type_example'),
        databaseSetting('DB_PASSWORD', ''));
    $database = new Database($driver, 1, 0);
    $scope = new ExecutionScope();
    try {
        $connection = $database->connect($scope);
        echo json_encode($connection->query('SELECT ? AS value', [7]), JSON_THROW_ON_ERROR) . "\n";
    } finally {
        try {
            $scope->close();
        } finally {
            $database->close();
        }
    }
}
```

## 接口与源码组织

当前 `src/MysqlDriver.php` 是一个完整驱动职责：配置校验、连接身份、PDO 基线、TLS 与物理连接创建。共享查询、模型、事务与迁移留在 `type-orm`；不复制一套 MySQL 专用模型接口，也不为单个类建立多层空目录。

示例从运行环境读取凭据，只执行参数绑定查询；请提前创建数据库和具有所需权限的应用账号。驱动会设置 UTC 时区与严格 SQL 模式，身份包含 credential generation、读写角色与 TLS 策略但不含密码。可选 `caFile` 要求证书验证，CA 内容变更会拒绝沿用旧连接身份。MySQL DDL 明确非事务，`INSERT RETURNING` 等能力不能按 PG 语义假定，需查询实际方言能力。

## AOT 与运行要求

Composer 声明 `ext-pdo_mysql`；除 `type-orm` 与 `type-runtime` 外不要求其他驱动。AOT 需要匹配的 PDO MySQL 模块、其实际链接客户端库及 PHPX/libphp，客户端库可能由不同发行版采用 mysqlnd 或其他实现，按产物清单打包而非硬编码库名。

当 SDK 把 mysqlnd 作为共享模块提供时，在构建配置当前平台的 `runtime.extensions` 中声明 `mysqlnd`，真实 embed 探针会将它和 `pdo_mysql` 一起加载并记录摘要。独立消费者测试按实际 SDK 的 mysqlnd 能力生成这一声明；使用其他客户端库的 SDK 不添加此模块。

语言与整体编译约定见[TypePHP 0.9 基线](https://github.com/zoujingli/typeapp/blob/main/docs/standards/typephp.md)。文中的声明式示例不使用省略实参的回调兼容层；带上下文的闭包必须完整声明参数。

## 主仓验证入口

以下命令在安装完整开发依赖的 [TypeApp 开发主仓](https://github.com/zoujingli/typeapp/blob/main/composer.json)根执行，不是分发子仓默认自带的脚本。需要真实数据库、Redis、Linux SDK 或容器的用例应按其文档准备专属测试环境；先构建相应产物，再运行 native 验收。

```sh
composer build:mysql
composer test:mysql
composer test:mysql-consumer
php tests/orm-suite-consumer.php mysql
php tests/orm-suite-consumer.php mysql --native
```

Linux/macOS 原生服务器验收可用 `php tests/native-database-consumer.php mysql "$TYPE_MYSQL_TOOLS"`，工具根由调用环境明确提供。入口只启动本轮专用回环实例，比较独立安装消费者的 PHP/AOT 参数绑定、主键、事务、会话基线与完整迁移恢复，结束后停止实例。设置 `TYPE_EXPECT_SELECTED_DRIVER_ONLY=1` 还会断言控制端和原生产物实际只加载 `pdo_mysql`，需事先选择对应的单驱动 SDK。

`php tests/native-database-tls.php mysql "$TYPE_MYSQL_TOOLS" build/tls/type-app` 复用已构建 TLS 产物，生成临时证书并验证正确 CA、错误 CA、可达但证书不匹配的主机名和 CA 内容变化；结束后回收实例及测试私钥。

- [三库独立消费矩阵](https://github.com/zoujingli/typeapp/blob/main/docs/development/orm-consumer-matrix.md)
- [TLS 证书与主机名验证](https://github.com/zoujingli/typeapp/blob/main/docs/development/tls-verification.md)
- [三库迁移](https://github.com/zoujingli/typeapp/blob/main/docs/development/native-migrations.md)
