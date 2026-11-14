# Platform / System / Center Tenant 重构验收记录

日期：2026-11-14。本记录按各节列出的源码、工具链和产物身份记录实际检查；不替代 MySQL/PostgreSQL、静态单程序、无源码和正式平台矩阵验收。

本轮在 PHP 8.5.12 ZTS、TypePHP `c5599c5c`、PHPX 2.9.8（`90e6d22a9c3f4d5dbd2c75d62e90bf5adfe58c89`）上重新完成线程源码适配、PHPX 编译、全量 AOT 和原生 SQLite Tenant 验收。

## 已通过

- `pnpm --dir web typecheck`：通过。
- `pnpm --dir web exec vitest run apps/web-antd/src/preferences.test.ts apps/web-antd/src/screen-lock.test.ts`：2 个文件、3 个测试通过。
- `pnpm --dir web build`：Vite 8.3.8 生产构建通过；保留大分块提示，未将其当作性能验收通过。
- Playwright CLI 真实浏览器 smoke：临时 SQLite 服务下主域 `/#/system/login` 显示客户搜索选择、账号、密码和平台入口；未选客户提交显示“请选择客户”；切换 `/#/platform/login` 显示平台账号密码和返回系统入口；375×812 窄屏下登录卡片、字段和按钮无横向溢出。该项只覆盖登录入口和布局，不替代登录后 CRUD 全量浏览器验收。
- PHP 8.5.12 ZTS（Swoole 6.3.0RC1、PDO MySQL/PostgreSQL/SQLite）下 `composer validate --strict`：通过。
- PHP CS-Fixer 3.95.30：918 个文件检查通过，0 个待修正。
- PHPUnit 13.4.6：306 tests、18,005 assertions 通过。
- `php tests/check.php`：963 个文件、3 个分发拒绝用例、文档站边界检查通过。
- `php tests/docs-consistency.php`：1,504 处引用、131 条路由、292 个命令、247 个 Composer 脚本通过。
- `php tests/tenant.php sqlite`：空库安装、模块表前缀、主域/子域/固定路径、RBAC、独立品牌、模拟交接、MQTT 模拟观测、恢复核对和真实 HTTP 隔离通过。
- `bash tools/prepare-toolchain.sh`：固定 PHPX 2.9.8 五个线程适配文件校验及适配通过；独立 SDK `build/phpx-thread-sdk.rB3rw1/phpx/lib/libphpx.dylib` 摘要为 `cbe798b1b20c09b1671737fcb0480859ddf464a0826bc46ec0903f9cd668505e`。
- `PATH=.cache/macos-locked-php/8.5.12-zts/bin:$PATH PHP_HOME=.cache/macos-locked-php/8.5.12-zts PHPX_HOME=build/phpx-thread-sdk.rB3rw1/phpx bash tools/build-application.php --shared-development`：前端 7,875 modules 构建通过；TypePHP 全量生成并编译 301 个 C++ 文件，原生程序链接通过。产物 `build/app/type-app` 摘要为 `ee6c90ee4622ffba7d7d06a25d341ff6b42b5cd0cf2b74a11bf4739700084331`，build id 为 `f7ab74faca810f57fbe61d248d0092994ea58730de5920fa996083d0852167a3`。
- `PHPRC=build/app/compiler/runtime-profile/native.ini PHP_INI_SCAN_DIR=build/app/compiler/runtime-profile/php.d .cache/macos-locked-php/8.5.12-zts/bin/php tests/tenant.php sqlite build/app/type-app`：同一 AOT 产物的原生 SQLite Tenant 空库安装、入口、RBAC、品牌、模拟交接、MQTT 模拟观测、恢复核对及真实 HTTP 隔离通过。
- `CenterRuntimeMetric` Model-first 改造：运行观察读取返回模型对象，64 槽位与运行代次 CAS 收口到 `recordObservation()`；模型边界契约、字段注释和 MySQL COMMENT 已通过。
- 本轮 AOT 产物 `build/app/type-app` 摘要为 `5690b7509eda78ffb21f3933d450876d2a144e3a724188de288d8efe09096ec4`，build id 为 `c094b8a4c891d260fdb0688802d05e6a223cd356de99868a215d16f9095b77c6`；同一产物再次通过原生 SQLite Tenant 闭环。
- `php tests/tenant.php mysql <MySQL工具根目录>`：MySQL 8.4.11 原生隔离实例上的同一 Tenant HTTP 闭环通过。
- `php tests/tenant.php pgsql <PostgreSQL工具根目录>`：PostgreSQL 17.11 原生隔离实例上的同一 Tenant HTTP 闭环通过。
- `php tests/model-sync.php <MySQL工具根目录>`：真实 MySQL 8.4.11 结构反向同步、字段注释、生成列、增删保护、非法输入拒绝及生成模型 Tenant CRUD 通过。
- `php tests/application-diagnostics.php --php`：应用数据库失败拒绝、显式错误码和秘密隔离诊断通过。
- `php tests/mqtt-consumer.php --connect-fields-only`：独立 MQTT 消费者的真实 TCP、TLS 1.2/1.3 CONNECT 字段和证书校验通过。
- `php tests/mqtt-consumer.php --retained`：MQTT.js 5.15.0 互操作、TCP/TLS QoS 0 发布订阅、QoS/retained 场景通过。
- `php tests/mqtt-mtls.php`：源运行 mTLS/WSS 证书链、CRL、吊销、轮换、SNI、TLS 1.2/1.3 和降级拒绝全部通过；报告明确 `native:false`。
- 活动源码、前端、文档、CI 和验收入口扫描：未发现旧租户命名、旧入口、旧表名前缀、旧双账号安装变量、旧客户成员模型、旧 `iot:*` 恢复命令或未加模块前缀的 `broker_nodes` 表名。
- `docs/build-config/type-app.json` JSON 解析通过；活动 GitHub 工作流 YAML 解析通过。
- 当前工作区 `git diff --check` 通过；提交按功能拆分并保留中文背景、影响和验证说明。

## 当前实现范围

Platform、System、Center 的职责、Tenant 入口、主域名客户选择、子域名和 `/t/{tenant_id}/` 固定路径、双身份会话、RBAC、菜单分组与图标、模拟登录、Vben 5.7.0 页面、模型优先 CRUD、设备注册限流/凭据/连接投影 Model、表字段注释、MySQL `model:sync` 和发布包安装说明均已同步到当前源码。

核心表使用 `platform_*`、`system_*`、`center_*` 和 `platform_mqtt_*` 前缀。Platform 跨 Tenant 的接入应用目录使用不带 `tenantKey` 的 `ApplicationDirectory` 只读 Model，写入仍使用带 Tenant 范围的 `ClientApplication`。MQTT 协议持久状态使用组件自身的 `type_mqtt_*` 表；恢复单主键进度和运行观察投影分别使用 `CenterRecovery`、`CenterRuntimeMetric` Model，复合主体、审计和协议认证热路径保留受控基础设施查询，并在数据库指南中说明边界。

## 未验证及阻塞

当前使用工作区已有的 PHP 8.5.12 ZTS SDK 与临时 Composer 2.10.3 phar 完成 PHP 静态检查、单元测试和三库管理闭环；未把临时工具写入仓库。MySQL 8.4.11、PostgreSQL 17.11 实例由本机测试控制器独占创建并清理。Docker daemon 仍不可用，因此无法用容器替代正式平台验收。

设备注册首次 SQLite 闭环发现 `DeviceOwnership` 创建时遗漏可空字段，已补齐 `ended_at`、`expires_at`、`expiry_context` 的显式 `null` 并复验通过。

仓库目标工具链记录在 `toolchain.lock.json`：PHP 8.5.12 ZTS、TypePHP `c5599c5c`、PHPX 2.9.8；Node.js 26.13.1 和项目声明的 pnpm 12.18.0 已通过 `npx --yes pnpm@12.18.0` 实际执行验证，系统全局 pnpm 11.15.1 不作为目标版本证据。

仍未验证：正式平台/profile 矩阵、Windows SDK、物联中心 AOT 产物上的真实设备身份 MQTT 持久会话/跨 Tenant 撤权与转配。静态 SDK 单程序、删除源码后的安装运行、embed-probe、加载项审计和 SQLite 单程序业务验收已在追加章节通过；独立通信组件的源运行 MQTT 结果仍不替代应用生产产物验收。补齐正式平台、Windows 和真实设备环境后，按 `docs/development/test-entrypoints.md` 顺序重跑剩余门禁，并以同一源码、锁文件和原生产物记录最终结果。

## 当前提交追加验证

提交 `1fb2a25fca07e128975290a41a117a3f7417adf7` 后，针对 ORM 内部状态槽改名及恢复模型 `state` 字段重新执行：

- `bin/typeapp-prepare --json`：通过；当前模型生成包含 `CenterRecovery::getState()`。
- 在未注入 AOT runtime profile、仅使用锁定 PHP 8.5.12 ZTS 默认扩展配置下运行 PHPUnit：309 tests、18,029 assertions 全部通过。
- `ProjectStructureTest`、`ModelFirstBoundaryTest`、`ModelSchemaSynchronizerTest`：15 tests、631 assertions 通过。
- `composer cs-check`：922 个文件通过，0 个待修正。
- `php tests/check.php`：969 个文件、3 个分发拒绝用例、文档站边界检查通过。
- `pnpm --dir web typecheck` 与 `pnpm --dir web build`：通过；Vite 8.3.8 转换 7,875 modules。
- 锁定 PHP 8.5.12 ZTS、PHPX 2.9.8 下 `tools/build-application.php --shared-development`：全量 301 个 C++ 文件编译、链接通过；当前原生程序 `build/app/type-app` SHA-256 为 `a00ac52e25934e66c8d9ba18ada577aaffce86651983022aa0b5388be3080776`，build id 为 `44ae4901bbe571d9345aaf240a01839ac4270aba972c11591465e677933afb4d`。
- 同一当前 AOT 程序的 `tests/tenant.php sqlite`、`mysql`（8.4.11）和 `pgsql`（17.11）Tenant 闭环均通过。
- 同一锁定环境的 `tests/model-sync.php`：真实 MySQL 结构反向同步、注释、生成列、字段保护和 Tenant CRUD 通过。

本历史段记录模型状态变更时的验证范围；当前静态发布状态以末尾“静态 embed 修复与无源码验收追加”为准。

## 历史列表与 Web 精修追加验证（静态发布前）

本次追加提交为 `bfbac97`、`6e83493` 和 `5390371`，分别收口普通业务列表的 Model-first 查询入口、精修 Vben 工作区导航和同步模型/页面边界文档。

- 身份目录、注册资格、接入应用、通信授权明细和设备转配列表统一使用具体 Model 与 `_query()` 白名单助手完成筛选、排序和有界分页；跨表设备运行观察、审计、恢复和 MQTT 协议原子操作继续按已登记例外执行。
- Platform、System、Center 路由补齐服务端登记一致的目录与页面图标、顺序和详情选中路径；MQTT 次级导航使用标准 Ant Design Vue 小尺寸间距和可访问标签，认证后仍由服务端菜单覆盖静态元数据。
- 原生 PHP 8.5.12 ZTS + Swoole 6.3.0RC1：完整 PHPUnit `309 tests / 18029 assertions`、`tests/app-models.php`、`tests/tenant.php sqlite` 通过；Model-first、ProjectStructure 与模型同步筛选共 `15 tests / 631 assertions` 通过。
- `composer cs-check` 通过（922 个文件，0 个待修正）；`composer test:docs` 通过（1504 处引用、131 条路由、292 个命令、247 个 Composer 脚本）；`pnpm typecheck` 与 `pnpm build` 通过（Vite 8.3.8，7875 个模块），仅保留已有大 chunk 警告。
- 本轮仍未重新执行静态单程序、删除源码运行、正式平台/profile 矩阵、真实 MySQL/PostgreSQL 服务、真实 TLS MQTT 设备会话和跨 Tenant 转配；既有记录中的对应证据和限制继续有效，不能由本轮 SQLite 与源运行检查替代。

## 当前提交的 AOT 与三库追加验证

当前源码提交 `022f16f` 在锁定 PHP 8.5.12 ZTS、PHPX 2.9.8、Swoole 6.3.0RC1 和前端 Node.js 26.13.1 / pnpm 12.18.0 工作区下重新执行：

- `tools/build-application.php --shared-development`：TypePHP 全量生成并编译 301 个 C++ 文件，原生程序链接通过。当前开发 AOT 产物 `build/app/type-app` SHA-256 为 `00aa43d23fb8072296fe28c10fd28b84404ca3548a867cb2a39cb1ede433a785`，build id 为 `81ee21bee40b978d3e94e189a9954e4947de6f864133b91cc26970366270d265`。
- 同一 AOT 产物注入受控 runtime profile 后，`tests/tenant.php sqlite`、`mysql`（MySQL 8.4.11）和 `pgsql`（PostgreSQL 17.11）Tenant 闭环全部通过；覆盖空库安装、模块表前缀、登录入口、RBAC、品牌、模拟交接、MQTT 模拟观测、恢复核对和真实 HTTP 隔离。
- `tests/model-sync.php` 在真实 MySQL 8.4.11 上通过预览、生成、重复同步、字段注释、默认值、生成列、字段增删保护、非法输入拒绝和 Tenant 隔离 CRUD。
- AOT 首次未注入 runtime profile 时的 MySQL `internal_error` 已定位为启动配置缺失；补充 `PHPRC` 和 `PHP_INI_SCAN_DIR` 后同一产物通过，不能把首次配置错误记作数据库兼容性失败。
- 静态适配前的历史复现：macOS ARM64 `embed-probe` 在 Swoole `php_swoole_runtime_minit` 解引用尚未登记的 `php` stream wrapper 时退出；该问题及修复依据见下方追加章节。

## 静态 embed 修复与无源码验收追加

在当前锁定工具链下，静态 embed 探针曾因 Swoole 在模块初始化阶段读取尚未登记的 `php` stream wrapper 而发生空指针崩溃。本轮新增静态适配：Unix 静态 embed 直接引用 PHP 内置的 `php_stream_php_wrapper`，并保留 Swoole 源码原文摘要校验；Windows 适配仍由既有 Windows 源码适配负责，避免改变其固定原文顺序。静态 SDK 清单的 PHPX 许可版本同步为锁定的 2.9.8。

- `TYPE_STATIC_MINIMUM_MACOS=27.0 bash tools/prepare-static-macos.sh build/static-macos-patched sqlite`：SQLite 静态 SDK 制备通过，清单记录 PHP 8.5.12 ZTS、PHPX 2.9.8、Swoole 6.3.0RC1、macOS ARM64 和目标系统版本。
- `tools/build-application.php` 使用该 SDK 重新完成 302 个 C++ 文件编译和静态链接；加入身份目录搜索修复后，最终应用 SHA-256 为 `83d6911156dc7984ad782a62b0ecba71f5bde1b14b5f3c1453f1732c192e14a7`，build id 为 `f2b89e2692f7f5707c8457b367cd0ff57e306cbc6c946c194f9c8872e6cb403e`，依赖材料完整。
- 静态 `embed-probe` 返回 PHP 8.5.12、ZTS、embed、Swoole 6.3.0RC1 和 pdo_sqlite；`otool -L` 仅包含系统库、系统框架和 libcurl/libz 等系统提供项。
- 同一静态程序执行 `tests/tenant.php sqlite` 通过，覆盖空库安装、Tenant 入口、RBAC、品牌、模拟交接、MQTT 观测、恢复核对和 HTTP 隔离。
- 将静态程序置于仓库外、仅设置临时 `APP_BASE_PATH` 后执行安装及 HTTP 服务，`/readyz` 返回 200；该过程未读取源码、Composer 或 SDK 文件。
- `tests/native-single-program.php build/app/type-app` 通过，覆盖只读程序目录、不同工作目录、内嵌资源、外部 INI 忽略、许可证材料、平台用户/角色 CRUD、HTTP 页面和正常停止；测试夹具已同步为当前顶层分页响应契约。
- Platform 用户搜索回归已通过动态入口和最终静态程序验证：`/platform/users?search=package-user` 返回 HTTP 200 且 `total=1`；搜索同时匹配账号和姓名，分页参数进入 `_query()`。
- 使用项目声明的 `npx --yes pnpm@12.18.0 --dir web typecheck`、`build` 和前端 Vitest：类型检查、生产构建（7,875 modules）及 3 个测试全部通过。
- 同一单程序验收还核对了单程序导出与覆盖保护、外部 PHP 配置忽略、54 份内嵌许可原文、前端重复安装保护、默认站点和优雅停止；临时回执已核对后清理。
- macOS ARM64 target 27.0 的 PostgreSQL 静态 profile 已完成：SDK 记录 PostgreSQL 17.11，AOT SHA-256 为 `9f62300b2db55ccd29ff9d37539a1fc73b70a1291647ec480e807b62419bb34b`、build id 为 `a915a1e6b47877263e26e3b84a9075621c9b756b6025413be88987dcbb81176d`；`tests/tenant.php pgsql` 使用真实 PostgreSQL 17.11 通过，embed-probe 含 `pdo_pgsql`。
- macOS ARM64 target 27.0 的 MySQL 静态 profile 已完成：SDK 记录 MySQL 8.4.11 profile，AOT SHA-256 为 `1855ac7f3948faecb527cb97fe74b5cd3b9057be0b6c79b3d5ff903d84d070ea`、build id 为 `e4c703b4f928375cfd387ca612f0b40547a8d621d5a988d371ea2f5d38804075`；`tests/tenant.php mysql` 使用真实 MySQL 8.4.11 通过，embed-probe 含 `pdo_mysql`。
- 平台人员目录验收同步修正为当前列表响应契约；普通人员搜索由 Model 查询条件完成，避免把业务字段误当作模型列。

## 当前 HEAD 的 PostgreSQL 静态追加验证

修复前源码提交为 `9a81c9d07ea689b6bb285d260516452797f4264e`；本节保留该阶段的静态基线。使用仓库内锁定的 macOS ARM64 PostgreSQL 静态 SDK（PHP 8.5.12 ZTS、PHPX 2.9.8、Swoole 6.3.0RC1、PostgreSQL 17.11）重建当前应用，TypePHP 全量编译 302 个 C++ 文件并完成静态链接。

- 当前 AOT 程序 `build/app/type-app` SHA-256 为 `7d7965b882e00730138157dbc9ca439983001154c3276aeb89edaaebf256269b`，build id 为 `94a3c44e90f919c4aec11d1c77fedef982a4695070ca358101601d37c7473d8b`，profile 为 `pgsql`，运行时链接为 `static`。
- 同一 AOT 程序执行 `tests/tenant.php pgsql`，使用真实 PostgreSQL 17.11 隔离实例通过 Tenant 空库安装、模块表前缀、主域/子域/固定路径、RBAC、品牌、模拟交接、MQTT 模拟观测、恢复核对和真实 HTTP 隔离。
- Windows 侧只读核对通过：工作流固定 Windows 原生 runner，SDK、数据库和静态候选均有版本及 SHA-256 校验；当前 macOS ARM64 环境没有 Windows runner、PowerShell、PE SDK 和 Windows 数据库，因此 Windows SDK、正式四平台矩阵仍保持未验证。
- 本节基线不包含后续租户作用域修复；后续修复与最终 AOT 身份见下一节。代码和文档未加入外部项目对照说明。

## 租户作用域修复后的最终 AOT MQTT 追加验证

本轮真实设备探针发现 `center:mqtt-access` 的设备认证路径把含 `tenant_id` 的跨 Tenant 目录 Model 误当作租户 Model，触发 `tenant_scope_required` 并被 Broker 映射为 MQTT 0x88。已完成三层修复：ORM 不再按字段名自动推导租户边界；Platform 的 `DeviceDirectory`、`EnrollmentDirectory`、`ApplicationDirectory` 显式声明 `tenantKey = null`；设备认证读取 Tenant Model 时显式绑定可信作用域。模型编译器和 MySQL 反向同步器同步保留显式空声明。

- 修复后的最终 macOS ARM64 PostgreSQL 静态 AOT 程序 build id 为 `f102346735a8a7245a4772805da10f2e028985c43dd505be3b180db245b2b2b5`，SHA-256 为 `ae3ad2f822bda19f71225df57418d4571599f4bec15116f12c060d98ef31a0e3`，profile 为 `pgsql`，运行时链接为 `static`。
- `build/aot-device-mqtt-recheck.php` 使用真实 PostgreSQL 17.11 同步备库、当前 AOT 程序和 TLS Broker 完成通过：Tenant/设备注册、凭据签发、设备首次 TLS CONNECT、持久会话重连并返回 Session Present、撤权后现有连接关闭、旧凭据重连被拒绝。最终回执为 `status=passed`、`session_present=true`、`revocation_reconnect_denied=true`。
- 本轮临时 PostgreSQL 主库、同步备库、HTTP、Broker 和探针进程均已停止，工作区没有遗留运行进程。
