# 标准物联应用

`app` 直接承载成品案例物联中心的业务源码。TypeApp 是应用框架，TypePHP 负责编译，Swoole 是随应用交付的内置原生运行库，Plugins 提供可组合的框架组件；详见[系统架构](../guide/architecture.md)。物联中心是框架的使用方，其他业务用 `type-project` 创建。双端初始化、登录、个人工作区、平台人员/多角色管理、租户工作区、客户全局账号、租户成员和自定义角色维护已接入，待完成能力见[实现规划](../guide/roadmap.md)。

## 入口与责任

`app/main.php` 是唯一生产入口，`common/bootstrap/Application` 装配命令和 HTTP，`Settings` 读取一次启动配置。共用的 `AuthController`、`IdentityService`、`RoleService` 分别拥有请求输入、会话和固定目录/当前权限。业务不向组件反向注入业务表依赖。

`Schema::install` 复用 ORM 迁移锁和事务：先确认空库，创建新模式，再在一个事务中建立安装身份、管理账号、客户账号、初始租户及角色关系。两个账号域可以使用相同登录标识，但密码、会话及授权各自独立。密码使用既有 bcrypt 存储，令牌持久化只保存摘要；账号/成员/角色失效在后续请求重新判断。没有角色默认没有业务权限；固定目录由 `RoleService::catalog` 声明，初始化最高管理员获得当前目录的显式节点。

管理端 `GET /admin/users`、`GET /admin/roles` 提供分页、筛选、详情、当前权限与固定菜单。人员创建/资料/启停/密码/会话分别检查 `admin.users.create|update|status|password|sessions`；查询检查 `admin.users.read`。角色创建、复制、资料、启停、删除和权限分别检查 `admin.roles.create|copy|update|status|delete|permissions`，查询检查 `admin.roles.read`；`PUT /admin/users/roles` 检查 `admin.roles.assign`，支持最多100人批量替换、每人最多64个角色。所有修改携带预期 `version`，新账号不自动授予角色，新建及复制角色默认停用。

平台授权写入复用 ORM 事务与安装行锁，锁后重验来源会话、当前权限和目标潜在权限，包括停用角色。普通授权员不能授予自己没有的权限，也不能通过密码、账号状态或批量绑定接管高权限账号。最高管理员必要节点与最后一个有效最高管理员在同一事务保护；整批授权、版本推进及脱敏审计一并提交，失败整体回滚。菜单隐藏仅辅助操作，直接 HTTP 使用相同规则。

新初始化不迁移旧账号、不读取旧支持记录、不复制旧数据。MySQL DDL 本身不能整体回滚；任一步失败后非空库会拒绝重复安装，须由操作者核对失败记录和真实数据，不自动清库或重试初始化。

## 准备与初始化

以主仓为工作目录；先安装依赖，确认所选数据库为空。以下示例没有写入密码，运行前由受控进程环境提供 `APP_ADMIN_PASSWORD` 和 `APP_CUSTOMER_PASSWORD`：

```sh
composer install --no-scripts --no-plugins
composer typeapp:prepare
composer typeapp -- help
composer typeapp -- check
composer typeapp -- app:install platform-admin 平台管理员 customer-admin 客户管理员 初始租户
```

登录标识为 3–100 位小写字母、数字及 `_.@-`，首位是字母或数字；姓名非空且最多100字节，初始化密码12–72字节，不接受NUL。不在命令参数、配置示例或文档放置共享口令。初始化输出安装身份和人员标识，不回显秘密。

默认 SQLite 文件是应用根下 `build/app/runtime/typeapp.sqlite`；可通过 `DB_SQLITE_FILE` 指定隔离路径。MySQL/PostgreSQL 使用 `DB_DRIVER`、`DB_HOST`、`DB_PORT`、`DB_DATABASE`、`DB_USERNAME`、`DB_PASSWORD`，其余连接/TLS约束见 `config/database.php`。三个驱动按各自真实语义使用，不能据此推定 MQTT 后端也支持相同持久性。

`app:install` 是唯一全新安装入口。`migrate status|history` 读取记录，`migrate recover` 仅供操作者核对异常迁移状态，不能创建安装身份或替代初始化；`migrate run` 不开放。发现已有对象返回 `TYPE_MIGRATION_NOT_EMPTY`，不修改已有数据。

## 配置与运行

`config.files` 中的 PHP 只包含受限数组及 `env` 声明，构建时生成并编译，生产不解释 PHP 配置。`config/route.php` 是路由声明，不允许 `env()`。`.env` 是启动数据，优先级为：进程环境 > 软件运行根 `.env` > 声明默认值；不读取实际部署密钥生成源码。运行根依次取进程显式 `APP_BASE_PATH`、发布启动器提供的 `TYPE_APP_RUNTIME_ROOT`、原生软件所在目录；开发 `bin/typeapp` 使用项目根。根必须为已经存在的绝对目录；软件绝对/相对路径或 PATH 启动均按实际入口定位，切换工作目录不改变配置目标。数据库 CA、SQLite、导出和通知 Redis CA 的相对路径均基于该根。

### 本地检查与恢复

在软件启动根执行以下命令；开发辅助入口可用 `composer typeapp --` 替换软件路径：

```sh
./app config:check
./app config:check --connect
./app config:check --remember
./app config:restore
```

`config:check` 只读，报告已编译声明的键、类型、文件是否声明、实际来源、文件 SHA256 版本和结构错误，不输出任何配置值。进程覆盖键显示只读及 `process_environment_requires_operator_restart`；运维须修改进程环境并重启。未声明的文件键单独列出，不成为运行代码或可管理字段。检查也验证被覆盖文件值的声明类型，防止恢复副本藏有无效类型。普通检查不连接数据库、不初始化 SQLite、不创建恢复文件。

`--connect` 在当前环境下用真实驱动执行 `SELECT 1`，并检查导出、通知及已启用缓存的 Redis 连接、认证和 PING；各结果独立返回且不泄漏驱动异常。它不迁移模式、不改变安装身份，也不表示所有业务角色已就绪。`--remember` 隐含此检查，全部通过且文件版本未变化才更新 `runtime/config/last-valid.json`；纯检查、失联或错误文件不会覆盖有效副本。副本保存原始文件内容、校验和、检查时间及进程覆盖键名，**不会把进程环境中的值写入文件**；连通结果只对检查时的有效配置成立，不承诺后续环境或依赖不变。

`config:restore` 不连接数据库或 Redis，即使当前 `.env` 损坏、数据库失联或进程覆盖存在类型错误，也可把受控副本原子恢复到原位置。当前环境覆盖继续生效，输出显示其来源及结构是否有效；`loaded_version` 为 null，成功状态为 `restored_restart_required`，由运维重启并核对实际健康。它不启动或重启服务，不将文件成功等同于进程已恢复。

命令输出为JSON，退出码：0检查有效（`valid` / `valid_remembered`）；2声明、类型或范围错误；3依赖连接失败；4文件恢复成功但仍须重启；5锁、文件、版本冲突或持久性错误。5中的 `config_durability_unknown` 表示原子替换可能已发生但目录持久化未确认，应重新检查文件版本，不能视为未提交。

`bin/typeapp` 为 PHP 辅助开发入口，每次按声明内容生成或复用 `build/app/development/<sha256>` 下的不可变代次。原生构建独立生成配置、模型、路由及调用入口，不依赖开发代次。`APP_DEBUG` 只在明确开发入口有效；HTTP 内部错误不回显栈、口令、配置或异常参数。

```sh
composer typeapp:build
build/app/type-app serve
```

原生构建配置为 `docs/build-config/type-app.json`，产物为 `build/app/type-app`。默认生产 HTTP 使用 Swoole 的线程与协程，`APP_DATABASE_THREADS` 默认2；主线程启用 hook 并建立监听，每个编译业务线程建立自身配置和数据库资源，复用既有 `ThreadSupervisor`、`SwooleServer::serveThread` 和资源预算。没有另建线程池或事件循环，子线程不重复更改进程级 hook。

HTTP 传输固定复用 Swoole 线程与协程入口，不提供并行的同步传输实现。独立 Broker 仍使用 `broker:install`、`broker:user`、`broker:serve`，见[Broker说明](broker-management.md)。原设备和恢复维护命令保留到相应任务转换，不能直接作用于新身份模式并声称业务完成。

平台 `/admin/customers` 复用账号目录和授权事务，提供列表/详情、创建、资料更新、启停、密码重置及会话撤销。各写入按独立固定节点检查，修改使用预期 `version`；启用租户必须始终保有有效最高管理员，平台停用客户同样遵守。客户本人通过 `PATCH /customer/account` 修改 `login/name`，通过 `POST /customer/account/password` 改密；两者均需要 `version/current_password`，目标来自真实客户会话，不依赖租户角色或接受客户端账号标识。改密、全局停用及会话撤销删除对应客户全部旧会话；资料变更不隐含这些敏感动作。操作与审计同事务，平台操作记录在平台审计并保留客户目标域。

租户成员复用同一授权锁、事务、目标保护及最后管理员检查。`GET /customer/members[/{id}]` 查询本租户关系，`POST /customer/members` 同时创建或关联全局客户并分配初始角色；`PATCH /customer/members/{id}` 只修改本租户姓名，`POST .../{id}/status` 只启停当前成员，`DELETE .../{id}` 只删除本租户关系和绑定。请求必须有准确 `X-Tenant-Id`，不能使用平台、支持或模拟身份头。`GET /customer/roles` 读取本租户角色，`PUT /customer/members/roles` 原子替换最多100个成员的角色，每人最多64个；成员和角色都携带预期版本。新增账号、关系、角色与审计任一失败全部回滚，既有客户全局资料及其他租户不受影响。

`GET /customer/roles/{id}` 读取作用域内详情，`POST /customer/roles` 创建角色，`POST .../{id}/copy` 复制当前版本权限，`PATCH .../{id}` 修改名称，`POST .../{id}/status` 启停，`PUT .../{id}/permissions` 替换固定节点，`DELETE .../{id}` 删除角色与绑定。每个动作有对应 `customer.roles.*` 明确节点；创建及复制还需权限编辑资格。所有变更复用 `RoleService::changeRole`，当前租户启用角色取权限并集，无角色或节点默认拒绝。角色删除的关系清理、成员版本及审计一并提交，失败整体回滚；受保护最高管理员和最后有效管理员分别检查。角色不存在或不属于当前租户统一返回404，不增加跨租户共享、继承或通配机制。

## 验证与分发

`composer typeapp:test` 使用新建隔离 SQLite 数据库验证 PHP 双端行为；`composer typeapp:test-native` 使用已有完整原生产物验证三库，网络数据库参数须来自专用测试实例。自动准备本机隔离三库可运行：

```sh
php tests/iot-identity-databases.php build/app/type-app <MySQL工具根> <PostgreSQL工具根> --app
```

原生环境需提供匹配的 `PHP_HOME`、`PHPX_HOME`；包装入口从构建报告取得原生配置。macOS 可追加 `--no-source`，以系统策略拒绝业务、组件、vendor、配置与编译器源码读取；这项运行包仍不是最终单软件文件。浏览器从 `tests/iot-identity.php <产物> sqlite --app --browser-dist=<隔离构建目录>` 运行，使用同一真实后端。

应用组合测试从主仓 `app/`、配置和前端构建物联中心，通过 `composer test:app-candidate` / `composer test:app-candidate-native` 检查全新安装、身份边界、业务目录和独立 Broker。该测试不代替单程序封装、独立分发及全平台完整验收。

独立候选验证使用 `composer test:broker-candidate` / `composer test:broker-candidate-native`，分别安装 Broker 管理宿主与物联组合，核对标准 MQTT 到业务持久回执的接收链；该入口的结果按实际运行状态记录。

全量 AOT 包含框架、业务、生成代码及全部生产 PHP 依赖；唯一例外是固定 Swoole 官方内置库沿用官方加载，版本和内容进入产物身份。当前仅 macOS ARM64 先验，Linux和Windows的最终同候选验证仍未完成。

`type-project` 的后续生成以本业务、前端及配置为单一来源；完整同源创建和真实子仓消费仍需验收，旧独立极简模板不能冒充当前完整项目。组件依然只分发 `plugin/type-*` 子树，应用与私有配置不会进入组件子仓。
