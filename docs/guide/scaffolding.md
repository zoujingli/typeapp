# 生成可编辑业务源码

本页面向开发通道。`make` 在明确项目内生成普通 PHP 源码，并更新现有应用声明；不会安装依赖、连接数据库、执行迁移或替你授予权限。命令可在任何工作目录运行，构建配置路径决定项目根。使用当前开发候选的同批次模板与组件；已发布版本以发布通道说明为准。

## 新增第二个 HTTP 模块

先完成[教程第一节的应用创建与安装](tutorial.md#1-固定候选并创建)，暂不运行第二节的 `setup.php`，以免与本页手动生成的同名模块冲突。安装所选数据库驱动，执行原有 `migrate run`，设置至少 32 字符的 `APP_API_TOKEN`。SQLite 数据文件由迁移角色创建；MySQL/PostgreSQL 需要预建专属数据库及相应权限。

在应用根执行：

```sh
php vendor/bin/type make type-app.json module 'app\catalog\Product' \
  --table=products --route=/products --role=users --version=002_products \
  '--migration-registry=app\common\database\Schema'
php vendor/bin/type schema:prepare app/catalog/database/CreateProduct.php
php vendor/bin/type inspect-application type-app.json
php vendor/bin/type dev type-app.json migrate run
php vendor/bin/type dev type-app.json serve
```

生成的五个类位于 `app/catalog/model`、`input`、`service`、`controller` 和 `database`。迁移登记器只追加新迁移调用，不改写历史 SQL。准备命令冻结三库结果；审查并提交声明与快照后再部署。后续结构变化必须新建迁移，不能修改已发布快照。

模型持有 `id/name/version`，`present()` 明确输出这三个字段。输入类保留字段存在性；Service 的原类型方法声明事务，控制器由共同装配注入这个 Service，不传递 Connection。列表按 id 稳定排序且最多二十条。控制器先要求认证产生的 Identity，再核对明确角色；模板默认令牌只有 `users` 角色。

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
```

单独迁移输出空操作列表，须按 [Schema](plugins/type-orm.md) 填写业务结构，再准备快照并显式追加到迁移列表。空操作不会通过 `schema:prepare`。单独 Controller/Service 使用与模块相同的 `name` 字段示范契约；改变模型字段时同步修改输入、Service 和公开投影。

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
