# 静态程序的源码与重新构建

每个平台的 `typeapp-rebuild-<版本>-<平台>-<架构>.zip` 是供维护者使用的材料附件，不是部署包。部署只使用对应平台的可执行程序和外置配置。该附件保留同一源码提交、实际 SDK、第三方生产源码与编译工具、LGPL 库的对应源码及构建配方；程序内 `licenses` 命令继续提供原许可证。

## 材料布局与身份

- `manifest.json`：源码提交、版本、平台、SDK 清单摘要及每个成员的大小和 SHA-256。
- `typeapp.tar`：该提交的完整主仓，包含应用、组件、前端源码、锁文件、源码适配和构建脚本。
- `vendor/`：实际安装的第三方生产组件、TypePHP 与 PHPX。第一方组件源码保留在主仓的 `plugin/`。
- `sdk/`：本次链接的静态归档、公开头文件、许可材料与输入清单。SDK 只供构建，不复制到部署环境。
- `native-sources/`：PHP 原始源码（含 LGPL 的 libmbfl）、GMP、MPFR，以及 Windows 使用的 GNU libiconv、Zstd 对应源码。Zstd 保留 BSD/GPL 双许可证原文，同时提供已链接版本的源码。
- `recipes/`：macOS 实际安装的 Homebrew 配方和收据，或 Windows 固定 vcpkg 源码与 SPDX；Linux 源码目录包含发行版 `.dsc`、上游源码和发行版补丁。

先按 Release 的 `SHA256SUMS` 校验整个附件，再按 `manifest.json` 核对文件。旧版本材料不能与新程序混用；重新构建或修改后的程序有自己的摘要，不能继续引用原候选的验收结论。生成器先回读全部成员，候选门禁再核对来源提交、SDK 和附件摘要；离线回归入口是 `python3 tests/rebuild-materials.py`。

## 重建应用与替换库

1. 在匹配的 OS/架构上解开 `typeapp.tar`，按其中锁文件和[原生开发说明](native-command.md)准备构建宿主。编译器、C/C++ 工具、Python 3 和 Node.js 仅供构建。
2. 在项目根按 `composer.lock` 安装开发工具。附件中的第三方源码可用来核对或恢复同版本文件；第一方包继续由主仓路径仓库装配，不把材料目录当作另一个 Composer 仓库。
3. 将 `TYPE_STATIC_RUNTIME` 指向材料中的 `sdk/manifest.json`，执行 `php tests/static-runtime-sdk.php "$TYPE_STATIC_RUNTIME"`。需要从原生源码重建 SDK 时，使用项目内对应平台的制备脚本；其版本、源码摘要、补丁和编译开关均保留在主仓及 SDK 清单中。
4. 修改 GMP/MPFR 等库时，依据对应源码与平台配方重新生成相同 ABI 的静态归档。Windows 使用固定 vcpkg 提交与 `tools/static-windows/x64-typeapp-static.cmake`；Linux 保留发行版补丁，使用源码包中的 `debian/rules`；macOS 使用随材料保存的配方及目标系统版本。PHP/libmbfl 的修改在 PHP 源码树中进行，并按平台制备脚本重新生成完整 PHP 静态归档。
5. 用新归档及实际头文件替换工作副本中的 SDK 输入，将 `manifest.json` 对应文件的 SHA-256、版本与许可材料更新为修改后的真实内容，再运行 SDK 探针。不要修改原下载材料或把新库登记成旧库的摘要。
6. 按发布版本设置 `TYPE_RELEASE_VERSION`，执行 `composer typeapp:build`。TypePHP 从完整应用源码重新生成对象并链接所选静态 SDK；随后运行全量 AOT、同一程序三库隔离及服务生命周期测试。`type package` 只输出验证后的单程序。

原生制备脚本对官方源码摘要采取严格校验。维护自有修改时，应在自己的源码分支保存补丁和修改后的输入身份，不能关闭校验或让下载端悄悄替换官方源码。SDK 清单校验约束的是来源可追溯性，不禁止使用修改后的 LGPL 库。

应用源码采用 Apache-2.0，第三方库保留各自许可证；允许为调试对 LGPL 部分的修改进行许可证规定范围内的逆向工程。重建无需原发布者的签名密钥。源码材料不含业务秘密、用户配置、数据库或上传文件；外部数据库服务仍由测试或部署环境管理。
