# 独立业务应用模板

在 templates/type-project 维护独立业务模板，包名 zoujingli/type-project，目标公开子仓同名。模板仅选择 core、ORM 与一个驱动、validate、log，构建及 testing 作为开发依赖。生产代码目录 app 不包含未选驱动，也不把开发主仓当业务依赖。

## 首次使用前的环境检查

先准备 Git、Composer 2 和对模板及所选组件的 HTTPS 读取权限。开发 PHP 的包声明范围是 `>=8.4 <8.6`；模板固定要求 Swoole `>=6.2 <7`，ORM 要求 PDO，运行库与构建工具还需要 filter、JSON、DOM 等扩展。完整传递依赖以 Composer 平台检查为准，不用 `--ignore-platform-reqs` 绕过缺失扩展。

| 选择的数据库 | 必需 PDO 驱动 | 额外准备 |
| --- | --- | --- |
| MySQL | `pdo_mysql` | 可连接的专用数据库及数据库账号 |
| PostgreSQL | `pdo_pgsql` | 可连接的专用数据库及数据库账号 |
| SQLite | `pdo_sqlite` | 可写的本地数据库目录，不以共享网络文件系统承载 WAL |

进入已克隆的业务模板后，先查看实际 PHP 与扩展，再按模板步骤选择驱动并安装：

```bash
php -v
php -m
php configure.php sqlite
composer install --no-scripts --no-plugins
composer check-platform-reqs
composer prepare
```

## 入口与验收范围

复用 ModelCompiler、RouteCompiler、Schema/Input、MigrationConsole、Authentication/RequestPolicy、HttpControl 和 DatabaseManager。prepare.php 只为标准 PHP 开发生成模型与路由；原生构建使用同一 JSON 声明自行生成，生产入口是 app/main.php，不包含 Composer/PHP 文件回退。

运行角色为help/check/serve/migrate。帮助不要求有效业务配置，检查只读取启动配置而不连接数据库；迁移不要求鉴权令牌或Redis；HTTP在启动时冻结配置，在业务执行作用域内惰性借用连接。接口覆盖用户CRUD、分页、软删除、版本冲突、PATCH缺失/null区别、Host信任与Bearer应用身份。模板中是服务身份示例，不冒充完整用户登录产品。

`tests/application-template.php mysql|pgsql|sqlite` 为各自创建独立消费者，通过 Composer 复制安装选定插件，禁用未选驱动的 Composer 平台依赖，然后以实际专用数据库运行同一命令与 HTTP 验收。源模板使用公开 HTTPS Git 地址；测试消费者的临时 path 替换用于本地主仓联调与独立 AOT，不当作已完成真实分发消费的证据。

应用创建、配置、开发与生产部署命令详见[模板说明](../../templates/type-project/README.md)。原生检查使用 `--native`，真实远端消费增加 `--remote` 并提供已完成的批次报告。模板不携带主仓分发配置或密钥。
