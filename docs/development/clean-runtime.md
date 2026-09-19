# 无源码运行目录与隔离验收

本页描述已封装 TypePHP ELF 的打包与验证入口，不代表全部框架、三库或发布流程已经交付。打包工具是构建期 PHP，工具本身、PHP CLI 和 Composer 不进入运行目录。

## 输入与范围

`tools/make-native-sandbox.sh <封装产物>` 接收 `ArtifactManifest` 已封装且摘要有效的真实 ELF；复用相同 `<产物>.lock`，在构建锁内创建 ELF 与资源快照，后续只读取该快照，避免并行构建混用版本；输出本仓 `build/native-sandbox.*` 的一个独立目录。失败不输出可消费目录，并收回本次创建的文件。输入产物、既有历史资源和其他项目目录不修改。

复用现有所有者：封装身份及资源验证使用 `Type\Build\ArtifactManifest`，扩展加载及实际共享库使用已准备的 embed 探测程序，ELF 的直接依赖使用 `ldd`，最终应用行为仍从编译产物入口验证。没有另造业务解释器或假原生入口。

## 部署内容

- 应用 ELF 放在 `/app/type-app`。只复制封装清单的当前 `resource-generation` 与明确列出的 `resources`，复制前后校验 SHA-256；不会递归夹带历史代次或清单外文件。
- 资源不能包含 PHP 文件扩展名或 PHP 开始标记。真实框架、业务与第三方 PHP 代码必须已编译，不能把源码改名成资源规避检查。
- 运行库保留其真实绝对路径，原路径与 SONAME 别名指向同一 ELF。除主 ELF 的依赖外，还收集实际映射的动态模块传递依赖；`/proc/self/maps` 中的 `libpq.so.5.15` 不能代替加载器需要的 `libpq.so.5`。复制前核对封装清单中已有的库摘要；同名不同内容失败，不用覆盖或加载顺序消除冲突。这样既满足动态加载器，也保留内嵌 `BuildIdentity::verifyRuntime()` 所需的路径身份。
- 最终 `/app/php.ini` 由运行白名单重新生成，不复制开发 ini 注释、未知应用配置、日志路径等。保留经过检查的容量、超时和时区设置；扩展按声明顺序规范化到真实绝对路径。关闭 Swoole 内置 PHP library、动态扩展加载入口、URL include、自动 prepend/append、用户 ini 和 OPcache CLI。
- 非空源码注入设置、凭据字段、环境插值和无法明确解析的配置使打包失败；错误不回显配置值。UTF-8 中文注释按真实 CRLF/CR/LF 分行，不按单字节 NEL 拆分。
- 优先使用明确的 `openssl.cafile`，否则查找 Linux 常见系统 CA 包；只接受公开 PEM 证书，不复制私钥、整个 `/etc/ssl` 或宿主认证目录。证书包放在 `/app/certs/ca-certificates.crt`，配置 OpenSSL/cURL 使用该文件。独立数据库驱动的专用 CA 仍须作为明确资源或运行时挂载提供；系统包不能代替它。
- 保留系统时区数据、`/etc/hosts` 和 `/etc/resolv.conf`；生成仅含 `files dns` 的名称解析协议，不复制 LDAP、认证或其他 NSS 配置。DNS 所需原生 NSS 库按存在情况复制。
- 实际 embed 预置或加载 SNMP 时，仅生成关闭隐式 MIB 搜索的无凭据配置和空的 `/var/lib/snmp/cert_indexes`。不复制宿主 SNMP 配置、凭据或持久状态，也不承诺自动支持专用 SNMP 业务的 MIB；这类业务须显式提供自己的运行资源。

生产秘密通过部署设施运行时注入，不能写入打包 ini、源代码、封装资源或镜像层。当前打包器不是任意数据的秘密扫描器；显式资源仍需要调用方按用途审计。

## 验证命令

已经准备锁定 Linux PHP SDK、PHPX、运行 ini 和原生产物后：

```bash
export TYPE_NATIVE_PHP_INI="$(bash tools/prepare-embed-runtime.sh)"
php tests/native-packaging.php build/native/type-app
TYPE_TEST_EXECUTION=host bash tools/test-clean-runtime.sh
```

开发机通过本项目现有 Linux 工具链容器执行打包时，直接运行 `bash tools/test-clean-runtime.sh`；`TYPE_TEST_IMAGE` 和 `TYPE_PHPX_SDK` 可指定已有的受控工具链与 PHPX。最终镜像从 `scratch` 创建，不从外部拉取运行镜像，不挂载仓库；测试使用唯一标签，结束只移除本轮镜像与目录。

`tests/native-packaging.php` 使用真实 TypePHP ELF 及独立的测试资源清单，验证资源篡改、配置注入、凭据脱敏、构建锁互斥与中文注释；Linux 进一步验证准确资源集合、扩展目录别名、原始 ABI 路径及扩展传递依赖的 SONAME。非 Linux 环境明确报告没有执行后半段，不把静态装配检查当作运行通过。

`tools/test-clean-runtime.sh` 在断网、只读根文件系统、删除全部 Linux capabilities、禁止提权和有界 `/tmp` 的 scratch 容器内运行问候命令的 9 项行为，以及身份产物的实际库身份检查。身份测试在该模式不执行重复构建缓存，输出不会将其算作通过。

## scratch 与 chroot 不同

OCI 容器运行时提供真实 `/proc`、`/dev` 和标准流设备；打包目录不复制宿主进程信息或设备快照。纯 `chroot` 只改变根目录，**不会**自动提供这些运行设施。HTTP、进程监督、`/dev/stderr` 以及依赖 `/proc/self/maps` 的完整库身份验证需要部署者准备正确的挂载和权限；不能用普通文件冒充设备。清理工具发现目录仍有挂载时拒绝递归删除。

`tests/support.php` 的 chroot 入口会显式选择 `/app/php.ini` 和空扫描目录，但这不构成完整 HTTP/三库隔离验收。无源码三库、Redis、TLS、HTTP、故障恢复由相应真实集成测试分别取证，基础问候/身份镜像不能代替这些结果。
