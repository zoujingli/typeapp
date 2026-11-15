# 生成可编辑业务源码

本页面向开发通道。`make` 在明确项目内生成普通 PHP 源码，并更新现有应用声明；不会安装依赖、连接数据库、执行迁移或替你授予权限。命令可在任何工作目录运行，构建配置路径决定项目根。使用当前开发候选的同批次模板与组件；已发布版本以发布通道说明为准。

## 新增第二个 HTTP 模块

先完成[教程第一节的应用创建与安装](tutorial.md#1-固定候选并创建)，暂不运行第二节的 `setup.php`，以免与本页手动生成的同名模块冲突。安装所选数据库驱动，执行原有 `migrate run`，设置至少 32 字符的 `APP_API_TOKEN`。SQLite 数据文件由迁移角色创建；MySQL/PostgreSQL 需要预建专属数据库及相应权限。

在应用根执行：

```sh
php vendor/bin/type make type-app.json module 'app\catalog\Product' \
  --table=products --route=/products --role=users --version=002_products
php vendor/bin/type inspect-application type-app.json
php vendor/bin/type dev type-app.json migrate up
php vendor/bin/type dev type-app.json serve
```

生成的模型、服务、控制器位于 `app/catalog`，迁移脚本位于 `database/migrations`，文件返回匿名 `SchemaMigration`，版本来自文件名。构建期扫描目录并生成静态注册表，运行时不读取迁移源文件。控制器用 `_vali` 接收名称，Service 通过模型对象保存。后续结构变化新建迁移脚本，不能改写已经应用到数据库的版本。

模型持有 `id/name/version`，`present()` 明确输出这三个字段。Service 的原类型方法声明事务，控制器由共同装配注入这个 Service，不传递 Connection。列表按 id 稳定排序且最多二十条。控制器先要求认证产生的 Identity，再核对明确角色；模板默认令牌只有 `users` 角色。

```sh
curl -i -H "Authorization: Bearer $APP_API_TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"第一件商品"}' http://127.0.0.1:9501/products
curl -H "Authorization: Bearer $APP_API_TOKEN" http://127.0.0.1:9501/products/1
curl -i -X PATCH -H "Authorization: Bearer $APP_API_TOKEN" -H 'Content-Type: application/json' \
  -d '{}' http://127.0.0.1:9501/products/1
```

创建返回 201 和显式字段，查询与 PATCH 返回 200。`{}` 保持原名；`{"name":null}` 返回 422，`{"name":"新名称"}` 更新名称。正文中的主键、租户或身份字段不参与保存或授权。未认证返回 401，无所需角色返回 403，缺失记录返回 404 `record_not_found`。脚手架没有内置租户模型；多租户业务须增加受信上下文和数据范围，不能仅增加客户端 tenant 字段。

## 单类入口

以下入口用于按步骤添加源码。`--model/--service/--input` 指向当前项目 PSR-4 下已经存在的类；跨包服务应直接通过应用声明组合。

```sh
php vendor/bin/type make type-app.json model 'app\notes\Note' --table=notes
php vendor/bin/type make type-app.json input 'app\notes\NoteInput'
php vendor/bin/type make type-app.json service 'app\notes\NoteService' '--model=app\notes\Note'
php vendor/bin/type make type-app.json controller 'app\notes\NoteController' \
  '--service=app\notes\NoteService' '--input=app\notes\NoteInput' --route=/notes --role=users
php vendor/bin/type make type-app.json migration 'app\notes\CreateNotes' --version=003_notes
php vendor/bin/type make type-app.json migration-script 'app\notes\CreateNotesV2' --version=004_notes_v2
```

`migration` 与 `migration-script` 都在 `database/migrations/{序号}_{名称}.php` 写入 `return new class extends SchemaMigration`。文件名就是版本，构建期按目录顺序编译成静态迁移注册表，不生成具名脚本类，也不在运行时扫描目录。结构生成命令 `migrate generate <版本> <描述>` 只读输出当前数据库快照，审查后保存为同名脚本；改变模型字段时同步输入、Service 和公开投影。

## MySQL 反向同步模型

`model:sync` 从已安装的 `type-orm-mysql` 读取当前数据库的持久基础表，生成或更新简洁模型声明、字段说明和 IDE 提示。它只读数据库结构，写入目标是项目源码；建表与结构变更继续由迁移管理。默认返回预览及新增、移除字段清单，显式 `--write` 才发布源码。没有数据库连接时，可以用同一套声明从迁移 Blueprint 生成新模型：记录 `Schema` 收集表和列注释，`MigrationModelGenerator` 只补尚未有模型的表，并写上 `@property` 与 `where/order/with` 提示。

准备仅当前开发者可读的连接 JSON 文件，不将凭据写进构建配置、命令参数或版本库：

```json
{
  "host": "127.0.0.1",
  "port": 3306,
  "database": "development",
  "username": "schema_reader",
  "password": "本地开发凭据"
}
```

可选 `caFile` 指定实际 CA 文件并启用服务器证书校验。使用只有目标结构读取权限的开发连接；读取不到目标表会失败。命令不会加载应用的 `autoload.files` 或启动业务服务。

```sh
php vendor/bin/type model:sync type-app.json 'app\catalog\model\Product' \
  --table=center_product --connection=/私有目录/mysql.json
php vendor/bin/type model:sync type-app.json 'app\catalog\model\Product' \
  --table=center_product --connection=/私有目录/mysql.json --write
```

生成位置使用当前项目 PSR-4 映射。更新要求同表的 `$table/$schema` 声明，保留业务方法、关系、字段别名和既有可见性/赋值策略；不会重写整个文件。数据库删除字段时先拒绝并保全原文件，核对所有调用者后加 `--drop-missing`。相同结构重复同步不产生修改。写入前复用完整模型、路由和应用装配检查以及 make 的路径、锁、原字节核对和原子发布机制；失败不会留下半个模型。

支持整数、字符和文本、枚举、集合、JSON、精确小数，以及六位微秒的 `DATETIME(6)/TIMESTAMP(6)`。`BIGINT` 生成字符串字段和对应 `signed64/unsigned64` 范围；生成列不可赋值。数据库默认值保留在数据库端，字段允许省略，重新查询取得实际默认值。不会猜测布尔、版本、软删除或时间字段的业务语义，已有声明继续保留；新增声明需要开发者明确配置。

主键支持单列，或 `tenant_id` 加单一业务主键；后者仍由可信 Tenant 范围与复合数据库约束保证身份。无主键、其他复合主键、浮点、二进制、低于微秒精度的时间类型及其他不能无损映射的类型明确拒绝，需要开发者显式建模。视图、临时表及跨库表名不参与反向同步。

## 命令、Job 与 Task

先显式安装需要的 `type-queue` 或 `type-scheduler`，并准备真实 Redis 或持久状态目录。共享服务契约是 `public execute(string $marker): array`，生成代码保留构造器注入，可直接改成实际业务操作。

```sh
php vendor/bin/type make type-app.json service 'app\catalog\service\PublishService'
php vendor/bin/type make type-app.json command 'app\catalog\command\Publish' \
  '--service=app\catalog\service\PublishService' --name=catalog.publish
php vendor/bin/type make type-app.json job 'app\catalog\job\Publish' \
  '--service=app\catalog\service\PublishService' --type=catalog.publish --version=1
php vendor/bin/type make type-app.json task 'app\catalog\task\Publish' \
  '--service=app\catalog\service\PublishService' --name=catalog.publish \
  '--cron=0 * * * *' --timezone=Asia/Shanghai
php vendor/bin/type dev type-app.json catalog.publish first
```

命令输出 `{"marker":"first"}` 并返回零。Task 也可用 `--interval=60`，不能同时指定 cron 与 interval。Job 消息只接收 `marker` 业务值，不恢复身份或租户。消息 type/version、任务 ID 和 cron 时区写入 `application.jobs/schedules`，标准生成器直接产出 `CommandApplication::jobs()` 与 `schedules()`，不需手写任务工厂。角色宿主仍显式持有 Queue、RedisManager、时钟与状态存储，按[队列可靠性](plugins/type-queue.md)及[调度](plugins/type-scheduler.md)启动和正常停止。Job 的确认、重试和清理先后由 Worker 管理，Task 的游标和 occurrence 由 Scheduler 管理；生成服务不能在构造时打开资源。

## 失败与恢复

名称必须是可映射到唯一 PSR-4 目录的完整类名；类名末段大写。生成器拒绝覆盖、路径跳转、符号链接、控制字符、重复声明、路由冲突、缺失业务依赖和不合法计划。发布前使用正式路由与应用装配分析器检查完整图，检查不执行构造器或工厂。生成文件和配置全部暂存后才发布，新文件排他创建，既有声明核对原字节，项目根 `.type-make.lock` 串行化同项目生成。

普通写入失败恢复本轮文件并清理暂存目录。如果外部编辑器在发布期间另改文件，生成器不覆盖那次修改，会明确报错并保留 `.type-make-*` 恢复副本；依据报错对照副本恢复。稳定锁文件不应在运行期间删除。只读目录或登记文件请先修正权限后重试；重复运行须换类名或编辑已生成源码。

## 验证与清理

主仓 `php tests/scaffolding.php` 在含空格的新目录独立安装开发候选，从其他工作目录调用公开 CLI，冻结新表，执行真实 Swoole HTTP/SQLite、Redis Job 和跨进程文件调度，并覆盖拒绝写入和清理。该 PHP 结果不代表 MySQL、PostgreSQL 或 AOT 已验收；原生门槛仍是最终应用全量编译后重跑同一业务断言。

示例结束后正常停止 HTTP、Worker 与 Scheduler，清理专用 Redis 命名空间、状态文件及测试数据库，最后删除临时项目。不要删除共享队列、生产游标或其他应用的数据。
