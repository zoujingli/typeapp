# 构建身份、缓存与隔离输入

## 内容身份

`NativeBuilder` 在源码、生成代码和资源准备完成后计算 SHA-256 身份，忽略文件修改时间。身份覆盖：

- 实际生产 PHP/C/C++ 源码、stub、生成代码、生产包头文件与明确的原生辅助输入。
- Composer 与工具链锁文件、生产包版本、包声明、应用构建配置和 Composer 自动加载/命令代理。
- type-build 的生成协议版本及构建工具完整依赖内容，TypePHP、PHPX 和解析器的本地修改也会失效。
- 实际 PHP 版本、ZTS、架构、GCC 目标 triple、扩展 ABI、所加载扩展、SDK/系统头文件、编译器内部程序、动态运行库及其内容摘要。
- 编译参数、资源内容与目标映射、应用版本和消息/schema/缓存能力。

应用目录内未纳入源码的 README 等文件不影响身份。同样内容仅改变时间戳可复用；源码保持原时间戳但改变内容仍会失效。工具依赖目录作为完整受信任工具输入保守处理，工具包中的说明文件变化也可能使缓存失效。绝对工作目录参与身份；迁移到不同路径时不承诺复用，避免把旧调试路径和生成常量当作等价输入。

`entry` 可以位于 `sources` 声明目录内，也允许目录与单文件重叠。交给 TypePHP 的清单使用锁定编译器 `FileScanner` 展开后按真实路径去重，入口只解析一次；原目录仍保留用于身份计算和构建完成前重扫。头文件进入身份与隔离快照，不作为独立编译单元；编译器接受的汇编与 Objective-C/Objective-C++ 源文件也进入同一输入身份，不能成为未记录的旁路。公开 `--stage` 回归 `composer test:build-source-collection` 验证重叠入口、全部业务文件、C++/汇编输入、头文件及变更失效；原生应用行为由模板全量编译与部署测试另外验证。

应用或第三方 C/C++ 使用声明目录外的头文件、链接脚本或其他辅助数据时，在应用 JSON 的 `native-inputs` 中显式列出相对文件/目录。SDK 头文件由工具链快照覆盖；不能依赖未声明、可变的项目外辅助文件。编译子进程固定区域设置、UTC 与 `SOURCE_DATE_EPOCH=0`，避免环境及 C 时间宏隐式改变结果。

```json
{
  "version": "1.0.0-dev",
  "compiler": {"optimize": 2, "debug": false, "jobs": 2},
  "native-inputs": ["native/include", "native/linker-data.txt"],
  "cache-directory": "build/cache/artifacts",
  "capabilities": {
    "schema": {"main": [1, 2]},
    "messages": {"example.event": [1]},
    "cache": {"example": [1]}
  }
}
```

`compiler` 只接受 0–3 的优化级别、布尔 debug 和 1–64 的并发数，不允许注入任意编译器或链接器命令。debug 的实际优化行为遵从固定 TypePHP 版本。默认版本是 `0.0.0-dev`。能力可以由应用或包的 `extra.type.capabilities` 声明，冲突版本拒绝；生成任务注册中的实际消息类型/版本也会合并记录。schema/cache 兼容版本是维护者声明，不会据此推断数据库已经迁移或自动修改数据库。

## 缓存与发布

默认缓存位于 `build/cache/artifacts/<构建身份>`，只支持当前构建用户拥有、其他用户不可写的本地目录。相同输出的构建先互斥；相同缓存键也有独立锁，默认等待最多 30 秒。缓存记录和完整 ELF 同时准备后以目录重命名提交，其他构建不会看到半成品。

命中前检查缓存协议、完整输入身份、ELF 内附清单及完整产物 SHA-256。命中报告含 `cache.hit=true`、键、原始创建时间、产物摘要和耗时；编译回调不会执行。首次构建记录 `reason=absent`。损坏或不匹配条目移动到同目录的 `.rejected-*`，记录 `reason=rejected` 后重新构建，便于调查且可恢复。

每次未命中使用独立的 `build-directory/attempts/<身份>/<随机目录>`，不同输入或失败尝试不共用 TypePHP 对象/PCH 缓存。当前增量单位是完整 ELF，不提供跨身份的对象级复用。失败编译不发布缓存或替换已有 ELF；发布前再次检查输入内容。隔离输入快照可避免开发目录在编译期间被修改；活动源码目录的内容检查不能替代文件系统快照。

缓存不自动删除历史身份、失败尝试或隔离条目，需由维护者按容量策略清理。缓存不是不受信任的远程制品源；有权同时改写缓存程序和清单的用户处于信任边界内。发布渠道应另行保存可信的完整产物摘要，不能仅凭一个来自同一不受信任来源的清单宣称真实性。

## 产物与运行库检查

构建身份与公开能力以 JSON 清单附在 ELF 尾部，包含原始 ELF 字节长度及 SHA-256；读取时不执行 ELF。额外尾部不会改变 ELF 的运行入口。`.build.json` 是诊断报告，部署核对以内附清单和可信发布摘要为准，不使用可能过期的旁路报告代替产物。

```sh
php vendor/bin/type --inspect build/identity/type-app
php vendor/bin/type --verify build/identity/type-app <可信发布记录中的完整SHA256>
```

`--verify` 同时核对完整产物摘要、当前 PHP/ZTS/架构/扩展版本、运行库文件摘要和按代资源文件。运行库已迁移到其他目录时，可增加 JSON 映射文件，格式为 `{"libphp.so": "/部署路径/libphp.so"}`。路径须与实际动态加载器配置一致；检查器不会执行待检二进制来猜测加载结果，也不替代平台签名或远程证明。

构建器还将 `Type\Generated\BuildIdentity` 编入源码集合：

```php
$identity = \Type\Generated\BuildIdentity::info();
\Type\Generated\BuildIdentity::verifyRuntime();
```

应用可在启动时调用 `verifyRuntime()`，或把 `build-id` 传给日志/健康检查。生成类在目标 Linux 上还通过 `/proc/self/maps` 核对实际已加载的库文件，无法确认加载时拒绝启动检查。它不自动改写应用入口，也不把源码文件作为运行依赖。部署 SDK 路径变化同样可传运行库路径映射。此命名空间保留给生成代码。

### 原生平台与校验时机（身份生成协议2）

Windows使用PE与系统加载器枚举，macOS使用Mach-O内嵌段及签名校验；它们的实际完成范围见[平台矩阵](platform-support.md)，不能套用Linux旧验收结论。

- `verifyRuntime()` 是启动检查：确认PHP/ZTS/CPU、所需扩展，逐文件核对应用运行库SHA-256和实际加载路径；macOS另核对实际系统映像UUID。没有移除应用库的字节校验，也不读取业务源码。
- `verifyDeployment()` 是显式完整审计：先执行相同运行检查，再对macOS记录的整套dyld共享缓存逐字节计算SHA-256。Linux没有此类缓存，执行相同的完整运行库检查；Windows按其实际DLL闭包检查。
- 标准物联项目与模板的原生产物提供 `verify-runtime` 命令调用完整审计。首次部署、升级或系统更新后，在接收流量前执行；该命令不读取dotenv、不连接业务数据库。普通PHP开发启动器明确拒绝这个仅适用于原生产物的命令。
- macOS清单记录 `system-cache-policy=explicit-deployment-audit`；缓存文件的完整摘要继续进入构建身份，没有用文件时间或“曾经验证过”缓存替代。它可能读取数GB系统数据，不能与普通help/check/迁移的启动预算混淆。

系统提供的共享映像与发布包自带的库分开记录。macOS系统内容保护机制由操作系统管理，参见[Apple签名系统卷说明](https://support.apple.com/guide/security/signed-system-volume-security-secd698747c9/web)；框架的UUID核对不是远程证明，也不声称能替代已被关闭或破坏的操作系统安全机制。发布包的实际库、配置与资源仍必须完整校验，干净部署也仍需单独执行，不能由启动检查代替。

## 依赖安装与构建隔离

依赖安装和编译分开进行。安装入口要求已有锁文件，固定使用 `install --no-scripts --no-plugins`，独立 Composer home，不继承 `COMPOSER_AUTH`、SSH agent 或系统 Git 凭据配置；项目内认证文件与内嵌认证配置直接拒绝：

```sh
php vendor/bin/type --install-lock "$PWD" "$COMPOSER_BINARY"
```

该入口不会为私有包申请生产凭据；需要私有源码时，先在专门的依赖获取环节以受限凭据复制安装，再进入无凭据构建。下载阶段需要的网络只用于锁定依赖，编译阶段不执行下载或安装脚本。

`--stage` 先核对已安装生产依赖、生成输入和真实工具链，然后只复制参与身份的项目文件：

```sh
php tests/build-scenario.php --stage docs/build-config/type-app.json "$PWD/build/isolated-inputs"
```

目的目录必须尚不存在，位于本项目 build 下。未选择的 `.env`、认证及 Git 文件不会被复制；误选常见秘密文件明确拒绝。Composer 的项目内 path 包链接重建为相对链接，项目外路径包需先复制安装。被排除的文件和空自动加载目录仅保留审计所需的空占位，不复制其非生产内容。完成标记 `build-inputs.json` 记录实际复制的文件摘要。

之后只把这份输入目录挂载到固定工具链容器；SDK 以只读方式单独挂载，容器使用 `--network none --read-only --cap-drop ALL --security-opt no-new-privileges` 和临时 `/tmp`。输入目录中的 build 路径需可写；不得再挂载原始仓库、SSH home 或生产秘密目录。镜像和 SDK 应固定到受信任摘要。编译子进程只有固定工具链路径、区域设置和时间宏环境，没有父进程任意业务变量。PHP 预先/尾部注入与 CLI opcache preload 被关闭。

环境白名单本身不是操作系统沙箱；完整文件与网络隔离由上述容器配置实现。构建器只检查常见秘密文件名，无法判断开发者主动写在源码或资源中的每一个秘密值，输入必须经过正常代码与发布审查。

## 验证边界

已执行：

```sh
php tests/build-cache.php
php tests/build-install.php
php tests/assembly.php
php tests/routing-build.php
php tests/check.php
```

Linux 缓存测试使用系统已有真实 ELF `/usr/bin/true` 验证字节复用、内附清单、并发只生成一次、损坏隔离、失败保护与部署检查；这不是 TypePHP 应用编译验收。真实 Composer 插件已安装但未激活，安装脚本未执行。源码、头文件、锁、生成器、资源、参数、ABI 与能力变化均触发身份失效。独立输入已在断网、只读容器根文件系统中重新解析，输入摘要保持一致。

集中原生验收接入命令：

```sh
php tests/build-scenario.php docs/build-config/type-build-identity.json
php tests/build-identity-native.php build/identity/type-app
```
