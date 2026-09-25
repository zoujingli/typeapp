# 应用模板的无源码部署验收

部署验收使用应用模板公开的 `help`、`check`、`migrate`、`serve` 和用户 HTTP API，不新增测试专用生产入口，也不删除 `BuildIdentity::verifyRuntime()`。PHP 只运行在构建机和验收控制器；应用服务本身从 `FROM scratch` 镜像启动编译后的 ELF。

`bf28c8b` 的公开模板分发已通过 MySQL、PostgreSQL、SQLite 三库原生干净部署，模板拆分提交、组件批次和运行链接见[发布验收](../evidence/native-release-20260925.md)。这里的 scratch 路径是 Linux 验收；macOS/Windows 的模板搬迁用例及隔离限制在[平台页](../guide/platforms.md)分别记录。

## 复用与边界

- `tests/application-template.php` 继续负责复制独立模板、显式选择数据库驱动、独立 Composer 安装、核对生产依赖和完整 TypePHP 构建。新增 `--native --build-only` 只保存“已经编译、尚未验收”的记录，不生成通过证据。
- `tools/make-native-sandbox.sh` 与 `tools/runtime.Dockerfile` 负责原生产物和运行库封装，模板测试不重新实现打包器。
- `templates/type-project/tests/smoke.php` 保留同一组应用公开行为，测试控制器通过 JSON 数组命令指定 OCI 载体，参数不经过 shell。测试代码不进入运行镜像。
- `tests/template-deployment.php` 从实际镜像文件系统核对 ELF 摘要、生产包覆盖、唯一入口、全部业务类和运行边界；再启动独立数据库、运行同一套 smoke 并回收本轮资源。

“无源码”表示镜像没有 PHP CLI、Composer、PHP 依赖源码或编译 SDK 源码，不表示没有 PHPX、`libphp` 和原生扩展。CI 的 PHPX 库可能来自包含 `vendor` 的绝对路径；只有 ELF 封装身份中明确声明且摘要匹配的原生库及其父目录可以保留，不能仅凭目录名称判断解释回退，也不能因此放行其他 `vendor` 内容。

## 执行入口

本地 macOS/Colima 使用已经准备好的 `type-app-toolchain:redis-local` 镜像，构建限制为两个 CPU、编译默认两个 job，多个驱动串行执行：

```bash
bash tools/test-template-deployment.sh sqlite
bash tools/test-template-deployment.sh mysql
bash tools/test-template-deployment.sh pgsql
```

数据库凭据使用现有 `TYPE_MYSQL_*`、`TYPE_PGSQL_*` 环境变量注入，不写入镜像或通过报告。可用 `TYPE_TEST_IMAGE`、`TYPE_PHPX_SDK`、`COMPOSER_BINARY`、`COMPOSER_CACHE_DIR` 选择已有工具链、SDK 和依赖缓存。默认仅连接专用 `type-app-tests` 网络及对应测试数据库容器。

Linux CI 已配置固定的 PHP、Composer、`PHP_HOME`、`PHPX_HOME` 和 embed ini 时，使用宿主构建模式；应用执行仍在 scratch 容器，默认 host 网络只连接 CI 测试服务的发布端口：

```bash
bash tools/test-template-deployment.sh mysql --host
bash tools/test-template-deployment.sh pgsql --host
bash tools/test-template-deployment.sh sqlite --host
```

已完成同一源码的组件批次与模板分发，并将 `TYPE_TEMPLATE_SOURCE` 设置为重新克隆验证通过的模板目录后，追加 `--remote`。入口读取 `build/distribution/batch-result.json` 与 `build/distribution/template.json`，在复制模板或执行配置脚本前复核完整批次、模板报告、提交与树，以及检出目录是否干净；未设置模板来源、被修改的检出、错配报告或克隆验证失败均拒绝消费。分支批次固定 `dev-main#提交`，标签批次使用明确标签，安装后继续核对实际组件提交。

公开模板和组件通过 HTTPS 获取，无需逐包只读私钥。发布写入权限与消费读取分开；本地 path 包验收不代替真实子仓消费。模板发布报告只有重新克隆核对成功后才标记完成，克隆失败会保留已发生的发布结果，并记录当前失败阶段与原因。
