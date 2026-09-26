# 原生发布目录与搬迁验证

Linux发布分析的`readelf`从当前PHP安装的`bin`及既有受限系统PATH查找，不再写死`/usr/bin/readelf`；私有工具依赖可位于该PHP前缀的`lib`。不会继承任意业务PATH或把分析工具复制进发布目录。`composer test:package-toolchain -- /标准应用原生产物`验证实际打包、清空SDK环境后的启动和无构建工具载荷。

## 构建端

```sh
php vendor/bin/type package build/app/type-app build/release .env.example
php vendor/bin/type verify-package build/release <受信发布记录中的release.json的SHA-256>
php vendor/bin/type archive build/release build/release.tar.gz <同一受信清单SHA-256>
```

标准应用提供 `composer typeapp:package`，模板提供 `composer package`。目标已存在时拒绝覆盖；升级使用新的版本目录，不能删除旧目录来掩盖迁移/回滚缺口。打包要求同平台、身份生成协议 3 或更新的真实产物；协议 3 的产物必须已有应用 LICENSE 与 NOTICE，缺任一项时需要按协议 4 重新构建。Windows 入口允许省略构建器添加的 `.exe` 后缀。

gzip归档先流式核对展开字节数及SHA-256与磁盘tar完全一致，再逐文件核对tar载荷与发布清单，避免`phar://`重新读取gzip时将完整归档留在内存。`tests/package-archive.php`通过公开`type archive`在128 MiB限制下创建两种格式，再使用Phar解包ZIP、原生`tar`解包tar.gz并验证实际原生启动；验收控制端需要可用的`tar`命令。

## 发布布局

```text
release.json                       版本、目标、构建身份、依赖与文件摘要
run / run.cmd                      本平台启动入口
bin/app[.exe]                      原生应用
bin/app[.exe].resources/<代次>/     仅属于本构建的资源
lib/                               Unix应用运行库；Windows DLL放在bin
runtime/php.ini                    无预加载、源码包含或秘密的运行配置
runtime/empty/                     明确的空INI扫描目录
config/env.example                 无秘密配置示例
DEPLOY.md                          运行目录说明
OPERATIONS.md                      首次部署、升级、备份与恢复操作手册
LICENSE / NOTICE                   构建时实际存在的应用原始材料
NOTICES.md                         依赖材料索引与覆盖状态的阅读入口
```

暂存目录完成静态校验后才原子发布。应用、库、资源、配置和启动器均有摘要；Unix应用/启动器还须具有执行权限。失败只回收本次暂存目录，不覆盖旧版本。

顶层 LICENSE、NOTICE 来自构建身份已绑定的应用原文，不从当前工作目录或构建组件目录借用。其他命名的许可文本和组件、第三方依赖材料仍保留在各自的资源索引中。协议 4 不强制应用提供这两个特定文件，也不改变应用许可；材料完整性仍由 `notices.require-complete` 门禁与构建报告处理。验包及协议 4 的原生启动会核对顶层材料归属，即使重算发布清单摘要，也不能替换为其他项目的材料。

## 运行端

```sh
./run help
./run verify-runtime
./run migrate run
./run serve
```

Windows使用`run.cmd`。将`config/env.example`复制为`.env`并填入实际部署值，或通过外部环境/APP_BASE_PATH指定配置。秘密、数据库、日志与上传数据由部署环境维护，不回写不可变发布载荷。

启动器设置TYPE_APP_RUNTIME_ROOT、受控INI与库路径，清除危险的加载器继承变量。物联中心标准项目和模板的编译入口调用BuildIdentity::verifyRuntime()，核对发布清单与本次构建、文件字节、实际加载库及资源原始摘要；发布模式缺文件时不回退构建SDK。自定义原始TypePHP入口也须保留此调用，打包器不会暗中改写业务入口。

首次部署及系统更新后先执行verify-runtime，包含macOS整个系统共享缓存的显式字节审计。普通启动不重复读取整套系统缓存，但仍检查应用库和系统映像。

发布清单不是来源真实性证明。须从独立受信渠道取得清单摘要；静态verify-package必须显式传入，运行时也可设置TYPE_APP_RELEASE_SHA256固定它。同一不受信任来源的程序与摘要不能互相证明可信，平台签名及受控分发仍是独立事项。

## 应用资源

构建配置的resources或根Composer的extra.type.resources均支持相同声明：

```json
{"resources":[{"source":"assets/message.txt","target":"data/message.txt"}]}
```

应用和依赖包共用路径、所有权、目标冲突与摘要规则。PHP源码、.env、认证文件和私钥不能作为运行资源。配置示例使用专用入口，已填写的常见秘密字段会被拒绝。大型资源按块检查，不一次载入内存。

### 内嵌前端资源

协议 5 增加 `embedded-resources`，内容由 TypePHP 构建入口生成原生常量并链接进程序；与上述随包保存的外置 `resources` 分别记录。物联中心声明 `{"embedded-resources":[{"source":"web/dist","target":"web"}]}`，全部路径、大小与 SHA-256 参与构建身份。原始许可材料仍随发布包保留。

首次执行 `app:install` 会先校验、暂存页面，再初始化空数据库，最后安装到应用根目录的 `public/`。后续使用 `web:install --dry-run` 预览、`web:install --force` 更新；安装只处理清单托管文件，保留上传和配置。普通启动只核验已安装页面，不释放资源。命令参数、恢复边界与时序图见[构建与部署](../guide/deployment.md#前端安装与更新)。

版本发布先生成最终归档，再对解包后的同一程序执行三库与页面验收。候选通过后保存摘要及平台回执，不以重新编译或重新归档的文件替换原验收附件。完整链路见[版本发布](../guide/releases.md)。内嵌前端不改变运行库目录归档的交付边界。

## 验收边界

已验收源码 `bf28c8b` 的 Linux x64 三库干净部署使用同一主程序、发布清单与 scratch 镜像，并完成备份恢复；macOS 三库独立模板有禁止读取源码/SDK及执行编译器的隔离，Windows 搬迁包仅证明载荷无 PHP 源码。准确产物、主应用与模板的区别见[四平台发布验收](../evidence/native-release-20260925.md)。以下入口提供不同强度的验证，不互相替代。

追加`--recover`可演练停止写入后的原生数据库备份、摘要/损坏检查、全新目标恢复、迁移历史/外部配置与资源、原生读写及原数据保留。SQLite使用维护端sqlite3，MySQL/PostgreSQL使用对应测试服务器镜像内的原生客户端；应用镜像仍无PHP源码或维护工具。详见随包`OPERATIONS.md`及[部署手册](deployment-runbook.md)。这不是整个集群/PITR或所有版本升级的完成证明。

`tests/native-package-clean.php <Linux发布目录> <受信清单SHA256> [sqlite|mysql|pgsql]` 使用同一原生产物生成scratch镜像，并完整检查运行文件系统、独立PID、非root、只读根和仅数据挂载。MySQL/PostgreSQL测试使用预先存在的固定镜像（可用`TYPE_TEST_MYSQL_IMAGE`/`TYPE_TEST_PGSQL_IMAGE`指定），不自动拉取；每次创建专用数据库及网络，结束后回收本轮资源，不使用现有业务库。

数据库只接内部网络、没有宿主端口；应用另接仅用于HTTP入口的测试网络。两个网络在应用启动前连接完成，不为通过测试改成host网络或放开数据库端口。原因是只连internal网络时本机Docker不会建立需要的宿主发布映射；这是测试拓扑约定，不是应用运行依赖Docker。[Docker多网络模式](https://docs.docker.com/engine/network/)

三库均检查显式重复迁移、鉴权/CRUD、PATCH空值与缺失、版本冲突、白名单、SQL注入文本绑定、软删除和正常停止，并从真实后端读取行数确认驱动未回退。错误数据库密码要非零退出且不泄漏值；运行镜像从未写入测试密码或令牌，秘密只在测试进程环境中传递。

macOS物联中心标准项目搬迁到含空格路径后，帮助、完整审计、SQLite迁移、双端授权、设备/数据验证、停止及篡改拒绝已通过。测试策略阻断开发源码、Composer、SDK、Homebrew和编译器，并用实际读取/执行负向探针确认限制；这是本机受限运行视图，不冒称另一台机器已安装验证。

入口为tests/native-package.php及tests/package-resources.php。发布回归也接受独立模板的配置与产物，`composer test:onboarding-native` 将它连接到空目录创建、业务修改、完整编译及开发/生产对照，详细范围见[接入验收](developer-cli.md#空目录到原生发布的重复验收)。

ZIP/tar.gz 使用 `php vendor/bin/type archive <发布目录> <新归档路径> <受信清单SHA256>`。归档只取发布清单中的不可变文件，不收集 `.env`、数据库、日志等部署数据；解包后仍须按原受信摘要校验，并保留 Unix 启动器的执行权限。归档是交付载荷，不是数据库或秘密配置备份。

### Linux本机无源码隔离验收

显式设置`TYPE_BWRAP_BINARY`为已核验的本机bubblewrap后，`tests/native-package.php`和归档回归使用新PID、IPC及UTS namespace，仅挂载只读系统运行文件、只读发布包和本轮可写数据目录。设置namespace需要测试宿主已有的非交互sudo权限；应用执行前切回原非root UID/GID、清空所有Linux能力并设置`no_new_privs`。不安装工具、不修改系统安全策略或持久服务配置。

探针先确认发布文件可读，再确认源码、Composer及SDK不可见、PHP和编译器不可执行，同时核对实际UID与能力集合。HTTP进程使用bubblewrap提供的真实子PID，核对账号和发布入口后发送SIGTERM并检查零退出状态。发布目录与运行数据分离，包含空格的搬迁路径、归档解包和篡改拒绝均走同一入口。未设置bubblewrap时，普通Linux发布回归保留未隔离状态；Linux三库接入验收则明确要求该工具。这是同一Linux宿主上的受限文件系统视图，不代表另一台干净机器，也不隔离三库测试需要的本机网络。
