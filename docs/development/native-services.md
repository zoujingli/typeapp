# 原生服务管理

服务配置由 `type-build` 的 `ServiceDefinition` 生成，应用继续使用已有原生发布包和同一业务入口，不依赖生产机器上的 PHP CLI、Composer 或编译工具。生成配置不代表安装或运行通过。

```sh
php vendor/bin/type service /已验证发布目录 service-spec.json /新的服务配置目录 受信发布清单SHA256
```

该命令先完整校验发布包，再原子生成系统描述文件、`service.json` 摘要记录和 `SERVICE.md`。不覆盖旧配置，不读 `.env`，不安装或启用服务，不创建系统账号或修改权限。三个目标位置必须分离：不可变发布、运行数据、服务配置。Unix默认user作用域，Windows固定system；用户按所选作用域执行后续安装操作。

## 声明与依赖

```json
{
  "name": "typeapp",
  "scope": "user",
  "user": "typeapp",
  "release-directory": "/srv/typeapp/releases/1.0.0",
  "runtime-directory": "/srv/typeapp/data",
  "arguments": ["serve"],
  "stop-seconds": 30,
  "restart-seconds": 10
}
```

- 名称仅接受字母开头的字母数字，最多64字符，兼容三个管理器的这套命名约定。
- `release-directory` 可省略，此时取被校验发布包的实际路径。这里及 `runtime-directory` 指目标机器的最终绝对路径，生成机器不必存在这些目标位置；生成后改变部署路径需重新生成配置。
- Unix显式选择非root账号。推荐专用、无Docker/管理权限的运行账号，不把“非root”误认为没有其他委托权限。运行根需私有且可写，发布目录只授予运行账号读取/执行权限；祖先目录需可遍历。
- 参数是公开启动参数，不用于传递秘密；最多32项、每项512字节。未知字段、控制字符、路径跳转、作用域错误和发布摘要错误会被拒绝。Windows路径/参数不接受`%VAR%`插值；systemd参数关闭环境替换，`%`按其原生规则转义。路径末尾空白及Linux末尾反斜线明确拒绝。
- 停止预算1–300秒、重启间隔1–3600秒。管理器超过停止预算会强杀；这不是正常排空证明。应用自身的停止协议仍需实测。
- 生成环境固定 `APP_ENV=production`、`APP_DEBUG=false`、`APP_BASE_PATH` 与 `TYPE_APP_RELEASE_SHA256`；Unix限制启动工具PATH。秘密和可变配置只放在目标运行根的私有`.env`或受控外部环境，不写入描述文件或应用产物。

## macOS：launchd

生成`<name>.plist`。`ProgramArguments`保留参数数组，启动已校验的`run`；日志写入数据根，umask为0077。异常退出按节流间隔重启，正常退出不重启；系统发出SIGTERM后留出退出预算。[Apple服务说明](https://developer.apple.com/library/archive/documentation/MacOSX/Conceptual/BPSystemStartup/Chapters/CreatingLaunchdJobs.html)

user作用域使用声明用户的`gui/<UID>`域：

```sh
launchctl bootstrap gui/用户UID /配置目录/typeapp.plist
launchctl print gui/用户UID/typeapp
launchctl bootout gui/用户UID/typeapp
```

生成器不会添加登录项；持续登录启动由用户明确安装LaunchAgent。注销时用户Agent通常会停止。system作用域应由管理员按LaunchDaemon要求安装，描述中仍保留显式非root `UserName`，不默认用root运行应用。日志轮转/保留由部署策略管理，不对未配置的轮转作保证。

## Linux：systemd

需要至少systemd 244。user配置通过`ConditionUser`限制管理器身份，不设置会触发附加组重置的`User=`；system配置要求root管理器，并使用`User=`切换到显式非root运行账号。两种作用域不能混用。[管理器身份条件](https://github.com/systemd/systemd/blob/v255/man/systemd.unit.xml)

`Type=exec`只能证明进程成功执行，不代表HTTP/数据库就绪。异常退出才重启，五分钟内最多启动五次；停止向整个控制组发送SIGTERM，再按预算回收。日志交给journal。[systemd服务生命周期](https://github.com/systemd/systemd/blob/v255/man/systemd.service.xml)

```sh
systemctl --user link /配置目录/typeapp.service
systemctl --user daemon-reload
systemctl --user start typeapp.service
systemctl --user status typeapp.service
journalctl --user -u typeapp.service
systemctl --user stop typeapp.service
```

system作用域由管理员使用不带`--user`的对应命令。需要自启动时显式enable；user退出登录后的继续运行另由管理员决定linger。生成器不执行这些持久化操作。测试仅用`--runtime link`，完成后移除本次链接，不改登录启动设置。

`WorkingDirectory`是原始路径值，不能直接复用ExecStart/Environment的外层引号规则。验证器必须采用真实systemd解析，并验证账号、进程、业务就绪与停止状态，不能只检查文件存在。

### 解析器隔离

部分systemd版本的`systemd-analyze --user verify`会重建现有用户管理器的私有socket。测试控制器为它单独创建私有`XDG_RUNTIME_DIR`，同时把两种D-Bus地址指向该测试目录；禁止在活动用户的原运行目录直接执行这类解析检查。[上游问题](https://github.com/systemd/systemd/issues/36540)、[修复记录](https://lists.ubuntu.com/archives/ubuntu-reviews/2025-July/160183.html)

本次遇到该工具缺陷后，核对管理器UID/PID/可执行文件后采用上游说明的原位daemon-reexec恢复。PID与已有PAM子进程保持，控制连接恢复，未重启虚拟机或既有用户服务；此恢复操作不是测试器的自动行为，也不应对未知管理器盲目重试。

## Windows：显式WinSW依赖

Windows普通控制台exe不能直接冒充Windows Service。生成器要求额外提供WinSW 2.12.0包装器的本地路径和外部受信摘要，复制为同名exe，并把摘要记入`service.json`；不会自动下载或执行包装器。

```json
{
  "name": "typeapp",
  "scope": "system",
  "release-directory": "C:/TypeApp/releases/1.0.0",
  "runtime-directory": "C:/TypeApp/data",
  "windows-wrapper": {
    "path": "C:/trusted-tools/WinSW-x64.exe",
    "sha256": "从独立受信记录取得的64位十六进制SHA256"
  }
}
```

需按实际目标核对包装器版本、签名/来源和系统兼容性，摘要相等不能独立证明这些事实。配置固定使用LocalService而非LocalSystem，不存账号密码；管理员为其授予发布读执行及数据根修改权限后，使用同目录`typeapp.exe install/start/status/stop/uninstall`。默认手动启动。

## 迁移与版本切换

升级生成新的发布与服务目录，不覆盖旧版本；备份数据和秘密配置与归档不可变程序是不同动作。服务停止后才切换到新定义，并确认旧进程已退出。数据库变更可否向后兼容必须独立核对；切回旧服务描述不等于数据库回滚。本节不是完整数据库升级/备份/回滚验收，相关门禁仍保留。

## 验证边界

`tests/native-service.php`可接受`原生产物 已验证发布目录 受信SHA256`，直接验证接入时完成搬迁、归档的同一发布，不另建版本。入口先核对发布清单及二进制身份，再通过`TYPE_TEMPLATE_EXPECTED_MESSAGE`核对实际业务修改，最后确认两个服务PID消失、监听端口释放和作业卸载。省略后两项时保留原标准应用打包流程；不接受身份不符的发布。
