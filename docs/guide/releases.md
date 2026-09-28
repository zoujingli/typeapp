# 版本发布与安装

版本由主仓的不可变 tag 驱动：`vX.Y.Z` 是正式版本，`vX.Y.Z-rc.N` 是候选版本。一次发布关联同一主仓提交、15 个组件、应用模板、四个平台运行包及各自的验收记录。RC 标记为预发布，不成为稳定最新版。

当前公开候选为 [v1.0.0-rc.10](https://github.com/zoujingli/typeapp/releases/tag/v1.0.0-rc.10)，固定源码 `359627e`。四平台完整原生回归、静态单程序三库隔离部署、组件与模板分发、默认 Packagist 独立消费和公开下载回读均已通过，共核对 17 个 Release、16 个子仓 tag、16 个 Packagist 版本、四个程序及四份重建材料。RC 尚非稳定版，实际平台范围见[平台与验收](platforms.md)，原始身份见[本轮验收记录](https://github.com/zoujingli/typeapp/blob/main/docs/evidence/single-program-ci-20260928.md)。

每个平台下载一个可执行文件，外置配置独立维护。PHP、PHPX、Swoole 等非系统原生库已静态链接，普通启动不释放运行库。历史 RC7 的目录归档与旧标签保持原样，其使用方式和验收身份保留在[历史记录](https://github.com/zoujingli/typeapp/blob/main/docs/evidence/rc-release-20260926.md)。

## 一次 tag 如何形成版本

```mermaid
flowchart TB
  Tag["版本 tag → 固定完整提交"] --> Gate["main 历史、依赖约束、标签冲突检查"]
  Gate --> Web["冻结安装 → 类型检查 → 构建一份前端"]
  Web --> AOT["四平台 TypePHP 全量 AOT · 内嵌同一份资源"]
  AOT --> Verify["保存最终可执行文件 → 只复制该文件 → 三库与页面验收"]
  Verify --> Draft["保存主仓候选草稿、附件和摘要"]
  Draft --> Split["15 组件 + 模板 · 同版本 tag"]
  Split --> Index["GitHub webhook → Packagist 索引"]
  Index --> Consume["默认 Packagist 独立安装 · 版本、提交及三库消费"]
  Consume --> Children["公开 16 个子仓 Release"]
  Children --> Main["最后公开主仓 Release"]
  style AOT fill:#147d64,color:#fff,stroke:#147d64
```

四个平台分别编译、验收。任何一个平台失败都会阻止主仓版本公开。新候选验收直接使用待上传程序的同一字节，移走构建端前端资源后验证安装、登录和 CRUD；程序 SHA-256 必须与三库回执一致，不能重新编译后替换附件。旧版本恢复仍按原归档身份读取，不改写旧标签或附件。

发布链保留各平台完整业务回归，并单独核验最终静态候选。Linux 两种架构调用 `static-linux.yml`，macOS 调用 `native-macos.yml` 的 `single-program` 范围，Windows 调用 `static-windows-candidate.yml`；它们使用同一固定提交及前端清单。Windows 先构建并验证静态 SDK，再全量编译 EXE，以该文件完成三库无源码部署。分发门禁同时检查这些任务的成功状态、源码和执行轮次，只有旧共享库回归或静态核心探针成功不能继续发布。任一候选或重建材料失败均阻止发布。

新候选附件名为 `typeapp-iot-<版本>-<平台>-<架构>`，Windows 追加 `.exe`，Unix 不加 `.tar.gz`。`SHA256SUMS` 和 `release-manifest.json` 是下载核验材料，不是运行依赖。下载后在 Unix 赋予执行权限，按[单程序部署](deployment.md)直接运行；运行库不会释放到磁盘，页面只在显式安装时写入 `public`。

每个平台另提供 `typeapp-rebuild-<版本>-<平台>-<架构>.zip`，供维护者取得对应应用源码、实际静态 SDK、LGPL 库源码与重建配方；部署者无需下载或解压它。该附件与程序一起封存、校验和重试，来源或摘要不匹配时阻止发布。维护方法见[静态程序重新构建](https://github.com/zoujingli/typeapp/blob/main/docs/development/rebuild.md)。

## 下载当前公开 RC

选择与操作系统、CPU 和系统库基线匹配的附件，具体要求见[平台与验收](platforms.md)。文件名中的版本不带前缀 `v`：

| 目标 | 附件名 |
| --- | --- |
| Linux x64 | `typeapp-iot-1.0.0-rc.10-linux-x64` |
| Linux ARM64 | `typeapp-iot-1.0.0-rc.10-linux-arm64` |
| macOS ARM64 | `typeapp-iot-1.0.0-rc.10-macos-arm64` |
| Windows x64 | `typeapp-iot-1.0.0-rc.10-windows-x64.exe` |

同一 Release 提供 `SHA256SUMS` 和 `release-manifest.json`。前者用于核对下载字节，后者记录源码、版本、候选运行轮次、平台及同一产物的三库验收。摘要应从受信发布渠道取得。

下面以 Linux x64 为例，在一个新目录中下载、校验并运行：

```bash
set -eu
release_version=1.0.0-rc.10
release_base="https://github.com/zoujingli/typeapp/releases/download/v${release_version}"
release_program="typeapp-iot-${release_version}-linux-x64"
curl --fail --location --output "$release_program" "$release_base/$release_program"
curl --fail --location --output SHA256SUMS "$release_base/SHA256SUMS"
curl --fail --location --output release-manifest.json "$release_base/release-manifest.json"
sha256sum --check --ignore-missing SHA256SUMS
chmod +x "$release_program"
mv "$release_program" app
./app verify-runtime
./app licenses
```

校验失败就停止。Linux ARM64 换用对应程序；macOS 使用 `shasum -a 256 文件名` 对照 `SHA256SUMS`，再赋予执行权限；Windows 使用 PowerShell 的 `Get-FileHash -Algorithm SHA256 文件名`，校验后可改名为 `app.exe`，执行 `.\app.exe verify-runtime`。程序名称可变，应用根和持久数据路径按[配置规则](configuration.md)确定。

运行端只需匹配平台的主程序和配置，无需 PHP、Swoole、Node.js、Composer 或编译 SDK。前端内容已编入程序，显式安装命令在应用根生成 `public/`；数据库文件、上传和日志由应用按需创建。操作系统基线仍须匹配，所选 MySQL/PostgreSQL、Redis 等外部业务服务仍需准备，SQLite 使用本地文件。运行要求见[环境与依赖](environment.md)。

核对下载摘要和运行身份后，按[首次启动](deployment.md#首次启动)完成账号、数据库与页面安装。后续替换程序版本时，使用 `web:install --dry-run --force` 查看页面变化，再执行 `web:install --force`，不要重新执行空库初始化。

## Composer 按版本安装

组件与通用模板不包含物联中心前端。`1.0.0-rc.10` 已由默认 Packagist 索引，可按明确版本安装。下面以 SQLite 独立应用为例：

```bash
composer create-project --no-install --no-plugins --no-scripts zoujingli/type-project my-app 1.0.0-rc.10
cd my-app
php configure.php sqlite
composer config minimum-stability RC
composer config prefer-stable true
composer require --no-update \
  zoujingli/type-core:1.0.0-rc.10 \
  zoujingli/type-orm:1.0.0-rc.10 \
  zoujingli/type-orm-sqlite:1.0.0-rc.10 \
  zoujingli/type-runtime:1.0.0-rc.10 \
  zoujingli/type-log:1.0.0-rc.10 \
  zoujingli/type-validate:1.0.0-rc.10
composer require --dev --no-update \
  zoujingli/type-build:1.0.0-rc.10 \
  zoujingli/type-testing:1.0.0-rc.10
composer install --no-plugins --no-scripts
php dev.php check
```

改用 MySQL 或 PostgreSQL 时，在首次安装前选择 `configure.php mysql` 或 `configure.php pgsql`，并将上面的 `type-orm-sqlite` 约束换为对应驱动。所选数据库服务和开发 PDO 扩展也须准备，见[环境与依赖](environment.md)。

示例固定模板使用的第一方组件；仅指定 `create-project` 的模板版本，不会将传递依赖也固定为同一批次。新增组件也应选择明确版本。`minimum-stability RC` 允许解析候选依赖，不表示 RC 已稳定。安装后可核对实际版本和来源：

```bash
composer show 'zoujingli/type-*'
composer show zoujingli/type-build --format=json
```

第一条列出已安装的组件版本；第二条的 `source.reference` 是对应子仓的拆分提交，不是主仓 SHA。发布流程会核对这两种身份的对应关系。提交生成的 `composer.lock`，日常构建用 `composer install` 复现实际版本；升级依赖时再受控更新。接着按[快速开始](quickstart.md#启动服务)启动服务，或进入[应用开发实战](tutorial.md)。

## 维护者触发与重试

发布工作流为 `.github/workflows/release.yml`。推送版本 tag 即触发；手动重试时选择原 tag 作为工作流 ref，并填写同一个版本。tag 必须解析为 `main` 历史中的完整提交，不得移动已有标签。

创建新版本时，先确定未使用的版本号，并在已检查的 `main` 提交上执行：

```bash
# 先设置已决定且尚未使用的版本号，例如 vX.Y.Z-rc.N。
: "${release_tag:?请先设置本次新版本的 release_tag}"
git tag -a "$release_tag" -m "TypeApp ${release_tag} 候选发布"
git push origin "$release_tag"
```

需要补齐既有候选时，使用原版本重试，不创建或移动标签：

```bash
gh workflow run release.yml --ref v1.0.0-rc.10 -f version=v1.0.0-rc.10
```

候选尚未封存时，重新执行完整工作流；不要把不同运行轮次的零散平台结果拼为一次验收。候选草稿已存在时，工作流复用原运行的验收和已保存附件；附件上传不完整时从原 Actions artifact 恢复。原证据过期或同名附件摘要不同会停止，不能靠重编译冒充原候选。

工作流代码同样固定在所选 tag，后续 `main` 上的修复不会自动注入旧版本。当前 `main` 的发布工具对创建和公开后的列表回读增加了有界重试；超过预算仍保留失败与已有草稿，可按上述命令继续原候选。

子仓 Git 写入使用各仓独立 deploy key。跨仓 Release 使用主仓 Actions secret `TYPE_RELEASE_TOKEN`，凭据为仅授权映射中 16 个子仓 **Contents: Read and write** 的 fine-grained PAT；主仓 Release 使用自身 `GITHUB_TOKEN`。未配置该 secret 时在构建前明确失败。Packagist 沿用各子仓 webhook，有界等待 Composer v2 静态索引中的版本与提交，再执行公开安装验证。包详情 API 存在长时间缓存，不用它判断新版本是否可安装。

版本模式只创建固定的子仓 tag，不移动各子仓的 `main`。`dev-main` 由独立的分支分发维护，不一定与最新版本 tag 指向同一提交。需要复现本批次时安装明确版本并提交锁文件，不用 `dev-main` 代替该版本。

跨仓发布不具有原子性。失败时已成功的标签和 Release 保留，回执写入 `release-receipts-<轮次>`；用同一 tag 重试后补齐。全部 16 个子仓公开并回读通过，主仓才公开。标签或附件内容冲突需要核对原因和新版本计划，不能强制覆盖。

[安装与更新页面](deployment.md#前端安装与更新) · [组件职责](components.md) · [环境与依赖](environment.md)
