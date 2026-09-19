# 原生命令开发与验证

## 实际实现

- type-runtime 提供显式命令参数读取，检查未知、重复、缺失值和整数范围。
- type-build 从应用的已安装生产依赖读取协议 1 的源码声明，收集声明的生产包；缺少声明时明确失败。
- 示例命令输出中文问候，支持名称、重复次数与帮助；错误参数输出中文错误并返回退出码 64。
- 独立消费项目分别复制安装 runtime 和 build，使用自己的 Composer 命令代理和自定义依赖目录构建，不依赖主仓生产源码软链接。

构建器使用 Composer 生成的 TypePHP 命令代理，避免绕过其依赖加载。内部候选文件名使用合法标识符，验证 ELF 后才替换最终产物；失败不会把旧二进制报告为本次成功结果。

## 锁定与环境

需要 Linux 环境下的 PHP embed SDK、C++17 编译器、CMake、GMP 与 MPFR。PHP_HOME 必须指向包含 php-config 和 libphp 的同一安装，PHPX_HOME 指向当前已安装并构建的 PHPX；不要混用不同 PHP 版本、ZTS/NTS 或机器架构的库。

GitHub Actions 使用 Linux x64。开发机也可在 Linux 容器中做对应架构的补充验证；本机其他项目的工具链镜像不是本项目公开分发依赖。

## 安装和快速检查

```bash
composer install --no-interaction --no-scripts --no-plugins --prefer-dist
composer validate --strict
composer check
```

快速检查覆盖 PHP 语法及 Arguments 公共接口，不代替 AOT 验证。没有 Linux SDK 的普通 PHP 环境可以执行这一组检查，但不能声明原生构建通过。

## Linux 原生验证

在已经安装对应 PHP SDK 和原生依赖的 Linux 环境运行：

```bash
export PHP_HOME="$(php-config --prefix)"
export PHPX_HOME="$PWD/vendor/swoole/phpx"
export LD_LIBRARY_PATH="$PHPX_HOME/lib:$PHP_HOME/lib"

bash tools/prepare-toolchain.sh
composer build:native
composer test:native
composer test:build-errors
composer test:consumer
```

准备脚本只针对锁定 SDK 应用官方 TypePHP 使用的 PHP 头文件兼容修正，并构建 PHPX。若 SDK 不可写，脚本需要当前隔离构建环境中已有的 sudo 能力；不要把这一步对准未经确认的其他项目 SDK。

构建报告记录输出哈希、Composer 和工具链锁定哈希、PHP/ZTS/架构、构建环境扩展、编译器与 PHPX 引用以及两个主要运行库哈希。构建环境加载的扩展不等于应用产物必须携带的扩展；应用是否可运行仍通过实际产物验证。

## 没有业务源码的执行验证

```bash
task_sandbox="$(bash tools/make-native-sandbox.sh build/native/type-app)"
php tests/native.php --chroot "$task_sandbox"
```

隔离目录复制 ELF、ldd 解析出的动态运行库，以及对应 PHP SDK 可能需要的系统时区数据；不复制 PHP CLI、业务源码或 Composer 自动加载文件。动态库同时进入加载器默认目录，因此不依赖 sudo 保留环境变量。验证器从外部启动 chroot 中的命令，检查默认参数、中文/空格参数、帮助、重复选项、缺失值、未知选项和整数越界，共 9 个行为用例。

隔离执行需要 Linux root 或可用于 chroot 的非交互 sudo。该步骤仅验证命令运行，不部署服务，也不启动或替换本地前端。

## 尚未覆盖的范围

完整 AST 依赖装配、第三方包兼容适配、全部 Composer 高级配置、运行时作用域和增量缓存策略由各自后续任务处理。本轮构建器对不支持的输入报错；不提供业务源码解释回退。

当前自有包为私有开发，公开许可证尚未确定；第三方依赖保留自身许可证。构建目录、依赖目录及验证临时目录不进入版本控制。
