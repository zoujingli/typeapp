# 统一开发入口与重载

`php vendor/bin/type --help` 显示当前命令。开发工具、编译器选项和生产运行保持分开：这些命令不进入发布目录，生产使用原生产物的run/run.cmd，不回退执行PHP业务源码。

## 创建与准备

```sh
php vendor/bin/type create "$TYPE_PROJECT_TEMPLATE" "$TYPE_NEW_PROJECT" sqlite
```

创建入口读取模板Composer中的协议1白名单，支持mysql/pgsql/sqlite，选择对应工厂与依赖，不执行模板的configure.php或其他PHP。不会复制vendor、build、缓存、秘密或未声明的主仓工具，不覆盖已有项目。远端模板获取/分发仍是独立授权步骤；当前命令不冒充远端下载器。

进入创建好的项目后，按私有仓库访问授权执行Composer安装，然后：

```sh
php vendor/bin/type doctor type-app.json development
php vendor/bin/type doctor type-app.json build
php vendor/bin/type prepare type-app.json
php vendor/bin/type dev type-app.json migrate run
php vendor/bin/type test type-app.json
php vendor/bin/type watch type-app.json serve
php vendor/bin/type type-app.json
```

doctor不读取dotenv或连接业务服务。输出分别说明开发依赖、构建SDK前提和“尚未验证”的运行状态；退出码对应明确选择的development/build范围，不能把检测成功说成编译或部署通过。完整版本约束仍由Composer检查。

prepare使用共享DevelopmentBuilder生成不可变代次；主仓bin/typeapp-prepare与模板prepare.php只保留入口。配置、模型、路由与操作声明分别生成，省略的能力不被强行启用；首次生成前检查业务语法。后续准备先按源码、声明、生成器和锁文件的完整内容身份查找代次，再核对清单身份及每个生成文件；输入相同的并发进程无需重复串行解析和生成。缺少代次时才持锁发布，索引损坏或输入变化明确拒绝，不回退到可变的current指针。dotenv不进入这些身份，保留mtime的内容修改也会产生新代次。

## 显式开发声明

```json
{
  "development": {
    "entry": "dev.php",
    "prepare": "prepare.php",
    "output": "build/development",
    "check": ["check"],
    "test": ["tests/smoke.php"]
  }
}
```

entry/test路径必须属于项目。dev只执行明确的开发入口，test只执行明确的测试入口；它们不是目录扫描或自动注册业务路由。标准应用的开发权限仍来自开发入口的显式参数，不能由HTTP参数打开生产调试。

## watch行为

- 准备子进程使用当前PHP安装前缀的最小工具环境；Linux/macOS私有运行库目录保留，业务变量、任意PATH/库路径和预加载变量不继承。运行前缀不注入PHP_HOME/PHPX_HOME，不能据此宣称构建SDK已经配置。业务check与dev仍按既有应用运行环境执行。
- 以内容摘要观察明确源码、配置、入口、Composer锁文件，以及development.watch追加路径。保留mtime的源码变化也能检测。
- 运行dotenv只作为观察进程内的变更信号，不写入生成代次、构建身份或日志。
- 变更稳定后，在新的PHP进程中准备代码，避免长驻生成器把新源码误标为旧实现的结果。若声明check，再在独立子进程执行该应用检查。
- 准备或检查失败时保留旧服务，只报告错误并等待下一次变化。check应由应用实现为只读检查，其覆盖范围不等于全部运行失败都能预知。
- 成功后正常停止旧进程，再启动全新开发进程；不会在旧进程中重载类或混合代次。这不是零停机部署，也不是原生对象文件增量链接。
- 意外退出后不无限自动重启，等待下一次修改。正常停止超过预算时强制收回并终止监督，明确报告非正常停止，不自动启动新一代重复业务。
- 停止监督器会取消仍在进行的准备/检查，并收回自己创建的子进程/进程组。Unix需要POSIX/PCNTL；Windows需要可用控制台控制事件，不能把强杀标为正常排空。

标准模板可用 `composer watch`，主仓可用 `composer typeapp:watch`。生产构建、打包和服务管理仍走独立流程。C/C++辅助实现变更仍需要真正重新构建原生产物，PHP开发重载不证明它已生效。

## 实际验收

`tests/development-watch.php` 在macOS和本地Linux环境观察真实HTTP：源码版本与PID改变、保留mtime、非法源码保留旧进程、修复后重载、dotenv更新、错误不泄漏配置值、取消检查和子进程清理。`tests/project-create.php` 观察三库配置选择、白名单、不执行模板、秘密拒绝和禁止覆盖；创建出的SQLite项目另完成独立安装/业务消费。

## 空目录到原生发布的重复验收

主仓新增 `composer test:onboarding` 和 `composer test:onboarding-native`。前者经正式 `vendor/bin/type create` 从不存在的目录创建 SQLite 应用，安装独立依赖、修改控制器，再经 doctor/prepare/test 验证真实 HTTP 返回了修改后的业务内容。后者增加 build、开发/原生同业务对照、全业务及生产依赖编译清单检查，再经 package 构造发布目录；搬迁后验证业务修改、迁移、授权、查询/校验、停止、篡改拒绝以及 ZIP/tar.gz 解包运行。

验收控制器仅把组件源映射为本地独立副本，Composer 不执行脚本或插件；业务配置与驱动选择由公开创建命令完成。这个本地接入测试不能当作远端模板/组件分发通过。macOS 隔离负向探针检查源码、Composer、SDK读取和PHP/编译器执行被禁止；Linux/Windows未建立同样隔离证据时报告为未隔离，不冒充干净部署通过。

运行原生入口前，仍需配置匹配的 PHP_HOME/PHPX_HOME 和真实工具链；测试不会自动安装或更改宿主 SDK。`tests/application-template.php <driver> --onboarding --native --package` 可选择三库，各驱动分别构建并发布。发布验收接收所选驱动，MySQL/PostgreSQL使用显式专用连接且创建/清理另一随机新库，不复用先前业务状态或切换到SQLite。缺失外部连接不能以默认配置猜测。四平台默认 CI 与公共模板验收已有结果，具体源码、应用类型与隔离范围见[平台与验收](../guide/platforms.md)，不外推为任意干净机器均已通过。

## macOS本机原生三库接入

使用匹配的PHP开发环境，显式设置`PHP_HOME`与`PHPX_HOME`，并准备可信的MySQL/PostgreSQL原生工具后，可运行：

```sh
composer test:native-database-onboarding -- "$TYPE_MYSQL_TOOLS" "$TYPE_PGSQL_TOOLS" "$COMPOSER_BINARY"
```

每个驱动调用既有`application-template.php --onboarding --native --package`完整路径：从新目录创建项目，独立复制安装私有组件，修改控制器，再执行doctor、prepare、开发/原生业务对照、全量AOT、发布搬迁及ZIP/tar.gz归档。仅安装所选驱动，不手工补业务配置；新目录、编译产物和证据留在build，原始业务环境与全局Composer配置不隐式传给测试。Composer使用显式PHAR、私有配置目录及项目依赖缓存。

这些是本机本地组件副本的接入验收，`remote=false`，不等同于远端组件分发、另一台干净机器或所有平台已经通过。测试仍采用各平台实际证据判断，不能因入口存在就标记完成。

## Linux本机原生三库接入与用户服务

Linux使用同一个`test:native-database-onboarding`入口及可选驱动参数，传入本机ELF数据库工具、匹配的PHP/PHPX SDK和Composer PHAR。另显式配置`TYPE_BWRAP_BINARY`，用于[发布与归档的源码缺席验证](native-packages.md#linux本机无源码隔离验收)；工具链仅由已校验的构建环境传入，运行包自行携带实际运行库。

测试通过私有空目录表达未配置的PHP扫描目录，避免`proc_open`省略空环境值后重新扫描宿主默认`conf.d`，与显式`PHPRC`重复加载扩展。调用者明确提供的非空扫描目录继续保留。

MySQL、PostgreSQL和SQLite各自完成空目录创建、独立安装、修改业务、PHP/AOT对照、全量编译、搬迁和两种归档。SQLite再使用刚完成搬迁的同一模板发布生成临时systemd用户服务，验证修改后的业务、授权CRUD、崩溃恢复、数据保留、正常停止、PID与端口回收以及运行期链接卸载；三库业务验证与SQLite用户服务验证分别记录。需要当前用户已有可连接的systemd管理器，不自动启用linger或登录启动。
