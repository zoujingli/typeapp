# type-orm

提供独立于 HTTP 核心的驱动协议、受管连接、参数化查询、模型/关系、事务、迁移与 Outbox。数据库差异由所选驱动及明确的查询方言处理，不提供隐藏的写入重试或跨系统事务保证。

历史源码 `5abdb5e53ea9ca67054f69d17113b91eb3402d69` 在 Linux ARM64、macOS ARM64、Windows x64 完成三库独立消费者的 PHP、AOT 和无源码运行，三库分别为 MySQL、PostgreSQL、SQLite。该记录使用本地 Composer 包复制安装，不等于当前公开子仓已完成独立消费验收；Linux x64 的基础命令结果也不代替 ORM 验收。当前目标覆盖四个平台，具体身份、场景与剩余限制见[平台与验收](https://iots.top/#/guide/platforms)。

业务实体 CRUD 使用无连接参数的 Model。启动时由 `Db::configure()` 装配数据库管理器，模型在当前 Swoole 执行作用域中按需借用连接；默认读从写主，`master()` 指定主读，事务内固定同一主库，事务外写后不自动粘主。具有租户字段的模型从应用已验证并绑定的上下文自动隔离，缺失身份拒绝执行。PostgreSQL 已实现完整会话重置后的 PDO 复用；MySQL、SQLite 的物理复用及完整原生平台验收仍有缺口，当前能力和限制见开发主仓的[模型连接与主从路由](https://github.com/zoujingli/typeapp/blob/main/docs/development/model-connections.md)。

## 安装与版本

本组件通过公开 Git 分发子仓安装，不假设已发布到 Packagist。先在应用的 Composer 根配置登记下列组件及传递依赖仓库；HTTPS 读取不需要 SSH 密钥，依赖包自己的 repositories 不会自动传递给消费应用。

```sh
composer config minimum-stability dev
composer config prefer-stable true
composer config repositories.type-runtime vcs https://github.com/zoujingli/type-runtime.git
composer config repositories.type-orm vcs https://github.com/zoujingli/type-orm.git
composer require zoujingli/type-orm:dev-main
```

`dev-main` 的分支别名为 `1.0.x-dev`；本仓组件间使用 `~1.0.0@dev` 约束。开发分支不等于已发布稳定 1.0 版本。提交应用的 `composer.lock` 固定实际分发提交；构建工具只放 `require-dev`。运行时通过 `type-runtime` 传递硬依赖 `ext-swoole >=6.2 <7`，PDO 和所选 PDO 驱动仍是数据库访问的直接依赖。详细依赖与公开分发规则见[组件组织与安装](https://github.com/zoujingli/typeapp/blob/main/docs/development/component-structure.md)。

Database 通过 type-runtime 的有界池按作用域借还会话。Connection 不返回底层 PDO，支持 query、execute、lastInsertId 和固定连接的事务闭包；关闭租约或作用域后不能继续使用。归还先处理流和事务，只有驱动确认完整重置后才能保留物理连接。PostgreSQL 使用 `DISCARD ALL` 后恢复配置基线；MySQL 和 SQLite 当前归还即关闭，下一次借用重建。

池归属创建它的进程和线程请求，同线程协程可独立借用，连接仍独占于原作用域和执行者。同步调用者满载立即拒绝；Swoole 协程默认最多排队 64 个等待者、等待 1 秒，实际截止取作用域和借用上限中的较小值。`Database` 与 `DatabaseManager` 构造器在 `$budget` 后接受 `$waiterLimit`、`$waitSeconds`；`Database::connect($scope, 0)` 或 `DatabaseManager::connect($scope, 'default', 0)` 显式即时借用。取消、截止、退役与凭据轮换均撤销等待，不能提前归还仍在使用或关闭失败的连接额度。

同一服务端连接域的命名池和旧凭据代次共用一个 `DeploymentBudget`。其第六个参数为每进程最大同时使用连接的线程数，包含主线程及未退出旧代；每线程只获得分配份额，调用者须按部署计划启动线程并在旧代 join 后再复用份额。标准应用配置 `APP_DATABASE_THREADS`、`DB_POOL_WAITERS` 和 `DB_POOL_WAIT_MS`，池统计返回等待时长、拒绝、在途、关闭及隔离数。线程内排队使用 Swoole Channel；PDO 负责数据库协议和会话，Swoole 提供协程上下文与等待，`type-runtime` 管理作用域、取消、截止与资源收尾，ORM 负责连接租约和会话恢复。

`query/execute` 与 `raw/rawQuery` 共用事务和资源所有权检查；当前复用策略有区别：`raw/rawQuery` 标记归还时退役，`query/execute` 在归还时交由驱动判断能否完整重置。不能以 SELECT 等首关键词推断没有副作用；存储函数、触发器及会话状态也属于重置验证范围。SQL 错误和未知提交的会话不可复用，框架不自动重试写入。

`DatabaseManager` 管理明确命名连接和凭据代次轮换。驱动 `identity()` 不含密码，记录端点、数据库、认证身份、TLS、会话配置与读写角色。旧代池停止新借用，已借出租约保持原身份直到归还；每个命名连接最多保留两个未排空旧代。详情见开发主仓 `docs/development/database-identities.md`。

事务闭包异常会回滚并保留原错误；提交确认失败报告未知结果，既不把它当作回滚成功，也不自动重跑。嵌套事务使用 savepoint；查询、水合、保存和删除参与的模型按层登记，回滚使对应对象失效。事务持有租约直到操作退出，Fiber 或协程不能接管其他执行者的连接。

回调签名由调用入口固定，TypePHP 不会忽略多余实参；未使用的参数也应保留。业务 `Db::transaction()` 为 `Closure(): mixed`，`Db::afterCommit()` 为 `Closure(): void`；底层 `Connection::transaction()` 仍接收 `Closure(Connection): mixed`。条件分组为 `Closure(Conditions): Conditions`，生成关系工厂为 `Closure(Connection): ModelQuery`。`ModelQuery::scope()` 接收 `Closure(ModelQuery): ModelQuery`，实例 `search()` 的每个搜索器接收 `Closure(ModelQuery, mixed): ModelQuery`；静态 `Model::search(array $input = [], string $alias = '')` 返回 `QueryHelper`，只消费显式输入和筛选白名单。模型 getter/setter 只接收字段值。普通和模型 `chunk()` 分别传一个行数组或模型数组，`RowStream::each()` 传一个当前行数组，仅返回 `false` 提前结束，也可以使用 `void` 消费者。

`Connection::table()` 提供不可变 Query，支持条件、Join、聚合、JSON 标量、批量写入和明确的三库能力差异。`Model`、`ModelQuery` 与生成映射提供受控访问、变更追踪、部分字段保存和安全输出；详细用法见开发主仓 `docs/development/models.md`。本包第一方源码按 Apache-2.0 提供；具体仓库可见性和分发批次由维护者管理。

模型 CRUD、事务结果与作用域收尾的完整路径见[数据库与模型](https://iots.top/#/guide/database)。`ModelQuery::update/delete` 以单条写入 SQL 保留字段、租户、软删除及版本约束，没有额外行数上限；集合操作不触发逐模型观察器，已有对象需重新读取。没有业务条件时须显式 `allowAll()`。模型级 `insertMany/upsert` 尚未提供。具体约束及验收边界见[模型集合写入](https://github.com/zoujingli/typeapp/blob/main/docs/development/models.md#模型集合写入)。

## 迁移

`Type\Orm\Migration\Migration`、`Migrator` 和 `MigrationConsole` 提供已编译的迁移计划、三库互斥、内容校验、执行历史与显式失败恢复。迁移只连接选择的数据库；MySQL 明确使用非事务 DDL，PostgreSQL、SQLite 普通 DDL 与成功记录原子提交。完整用法和恢复边界见开发主仓 `docs/development/native-migrations.md`。

全新应用可以调用 `Migrator::run($migrations, true)`：在同一迁移锁内检查空数据库（PostgreSQL 为当前 schema），在创建任何迁移记录前以 `TYPE_MIGRATION_NOT_EMPTY` 拒绝已有对象。默认第二个参数为 `false`，既有版本化迁移行为不变；空库建表失败同样保留真实记录，不自动清库或重试 MySQL DDL。

## 声明式使用示例

下面的业务函数假定应用已声明 `app\model\User` 模型、完成迁移，并在启动期装配 `Db`。HTTP 请求或任务入口绑定执行作用域后调用，业务无需传入连接。完整可运行入口见应用模板及各驱动组件。

```php
<?php

declare(strict_types=1);

use app\model\User;
use Type\Orm\Db;
use Type\Orm\ModelException;

/**
 * 应用服务显式声明输入白名单，模型保留当前租户及软删除约束。
 *
 * @return list<array<string, mixed>>
 */
function findAdultUsers(string $name): array
{
    $models = User::search(['name' => $name])->equal('name')->query()
        ->where('age', '>=', 18)
        ->orderBy('id')
        ->limit(20)
        ->get();
    return array_map(static fn (User $user): array => $user->project(['id', 'name', 'age']), $models);
}

/** 事务体不接收连接，失败不自动重试写入。 */
function renameUser(int $id, string $name): string
{
    return Db::transaction(static function () use ($id, $name): string {
        $user = User::query()->find($id);
        if ($user === null) {
            throw new ModelException('user_not_found', '用户不存在');
        }
        $user->set('name', $name);
        return $user->save();
    });
}
```

## 接口与源码组织

`Database/DatabaseManager/Driver/Connection/PdoSession` 是连接公共入口与会话实现；`Query/Conditions/SqlDialect/SqlStatement` 负责查询编译；`Model/ModelQuery/ModelDefinition/ModelField` 与关系类负责水合及模型生命周期；分页/流类持有有界结果。`Migration/` 持有迁移协议，`Outbox/` 持有事务意图与 relay，`Attribute/Transactional` 是可选构建期事务声明。既有公共 FQCN 保持兼容，不为目录整齐强制改名。

模型直接继承 `Type\Orm\Model`，以 PHP 类型属性和 `Table/Column` Attribute 声明字段；构建生成同名业务类的水合工厂与属性钩子，保留业务方法。业务通过 `$user->name` 读写字段，不再声明 JSON 或继承生成基类。开发入口先加载本代模型；生产编译同一转换结果。`Outbox\Store/Relay/Publisher` 将持久意图和外部投递分开，仍需稳定操作 ID 与目标端幂等，不保证跨系统事务。可选 `#[Transactional(database: 'default')]` 只有通过 type-build 的显式生成包装才执行，直接调用原方法不发生拦截；它与 `Db::transaction()` 共用当前作用域和逻辑数据源。

`HasOne/HasMany/BelongsTo/BelongsToMany` Attribute 为 `with`、`whereHas/whereDoesntHave` 和 `withCount/withSum` 提供共同关系声明。关系属性只返回已加载结果；列表通过 `load/loadMissing` 显式批量补加载。统计留在数据库中执行，结果通过 `computed($alias)` 读取，通过 `project` 的第三个参数显式输出，不参与保存和变更追踪。SQLite 精确数值文本列的 `withSum` 明确拒绝，避免数值亲和转换丢失精度。

`Query` 支持列比较、EXISTS、IN 子查询、标量和派生表子查询、子查询联表、DISTINCT、UNION/UNION ALL。子查询只能使用同一连接，组合时不执行 SQL。任意联表或分组投影使用行数据 `Query`；`ModelQuery` 保持每行对应一个模型。原子 `increment/decrement` 执行条件写入保护，模型版本列同时推进，但不触发逐模型事件。

普通分页对复杂结果按实际行数计算总数，并要求 `uniqueOrderBy` 声明唯一排序键；单表自动补主键排序。`simplePaginate` 返回无总数的 `SimplePage`，通过多取一行判断下一页。游标分页仍限制单表及唯一排序。

`toSql()` 与 `bindings()` 只预览目标 SQL 和绑定。`Connection::listen($scope, ...)` 返回由作用域管理的有界 `QueryLog`；默认仅记录 SQL 摘要、参数数量、连接身份、操作、耗时、成败及事务状态。SQL 原文和值需显式开启；监听异常单独计数，不改变提交事实。停止或关闭作用域后解除注册，记录仍可读取。

## AOT 与运行要求

基础组件只要求 PDO 与 runtime，不隐式选择具体数据库驱动，不要求 core、queue 或 Redis。实际 MySQL/PostgreSQL/SQLite 应另装对应驱动；AOT 运行要包含所选 PDO 模块和其真实传递共享库、匹配 PHPX/libphp。静态内置驱动与可卸载模块的客观差异以 embed 验证记录为准。

语言与整体编译约定见[TypePHP 0.9 基线](https://github.com/zoujingli/typeapp/blob/main/docs/standards/typephp.md)。文中的声明式示例不使用省略实参的回调兼容层；带上下文的闭包必须完整声明参数。

## 主仓验证入口

以下命令在安装完整开发依赖的 [TypeApp 开发主仓](https://github.com/zoujingli/typeapp/blob/main/composer.json)根执行，不是分发子仓默认自带的脚本。需要真实数据库、Redis、Linux SDK 或容器的用例应按其文档准备专属测试环境；先构建相应产物，再运行 native 验收。

三库独立消费验收分别创建 Composer 应用，保留所选 PDO 驱动和必需的 Swoole，记录实际扩展版本及加载方式。原生验收先构建 release，再归档并逐文件核对 PHP 输入摘要，移除消费目录的应用、vendor 和生成源码后运行同一程序。报告记录产物与源码归档摘要、移除文件数和业务结果；编译成功、无源码运行成功与同提交平台矩阵分别判断。

```sh
composer test:models
composer test:relations
composer test:transactions
composer test:outcomes
composer test:orm-suite
composer test:orm-suite-native
```

- [模型与关系](https://github.com/zoujingli/typeapp/blob/main/docs/development/models.md)
- [事务结果](https://github.com/zoujingli/typeapp/blob/main/docs/development/transactions.md)
- [事务 Outbox](https://github.com/zoujingli/typeapp/blob/main/docs/development/outbox.md)
- [数据库身份与代次](https://github.com/zoujingli/typeapp/blob/main/docs/development/database-identities.md)
- [编译期事务包装](https://github.com/zoujingli/typeapp/blob/main/docs/development/operations.md)
