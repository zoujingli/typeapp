# 版本发布与安装

版本由主仓的不可变 tag 驱动：`vX.Y.Z` 是正式版本，`vX.Y.Z-rc.N` 是候选版本。一次发布关联同一主仓提交、15 个组件、应用模板、四个平台运行包及各自的验收记录。RC 标记为预发布，不成为稳定最新版。

首次发布目标为 `v1.0.0-rc.1`；只有[主仓 Release](https://github.com/zoujingli/typeapp/releases)公开后，才表示这一批次的四平台构建、分发和公开消费均已通过。源码提交、构建成功和候选草稿分别有自己的状态，不能代替最终发布结果。

## 一次 tag 如何形成版本

```mermaid
flowchart TB
  Tag["版本 tag → 固定完整提交"] --> Gate["main 历史、依赖约束、标签冲突检查"]
  Gate --> Web["冻结安装 → 类型检查 → 构建一份前端"]
  Web --> AOT["四平台 TypePHP 全量 AOT · 内嵌同一份资源"]
  AOT --> Verify["生成最终归档 → 解包 → 三库与页面验收"]
  Verify --> Draft["保存主仓候选草稿、附件和摘要"]
  Draft --> Split["15 组件 + 模板 · 同版本 tag"]
  Split --> Index["GitHub webhook → Packagist 索引"]
  Index --> Consume["默认 Packagist 独立安装 · 版本、提交及三库消费"]
  Consume --> Children["公开 16 个子仓 Release"]
  Children --> Main["最后公开主仓 Release"]
  style AOT fill:#147d64,color:#fff,stroke:#147d64
```

四个平台分别编译、验收。任何一个平台失败都会阻止主仓版本公开。验收使用最终归档重新解包后的程序，上传前核对程序和归档摘要；不会用验收后重新编译的文件替换候选附件。

## 下载物联中心

选择与操作系统、CPU 和系统库基线匹配的附件，具体要求见[平台与验收](platforms.md)。文件名中的版本不带前缀 `v`：

| 目标 | 附件名 |
| --- | --- |
| Linux x64 | `typeapp-iot-1.0.0-rc.1-linux-x64.tar.gz` |
| Linux ARM64 | `typeapp-iot-1.0.0-rc.1-linux-arm64.tar.gz` |
| macOS ARM64 | `typeapp-iot-1.0.0-rc.1-macos-arm64.tar.gz` |
| Windows x64 | `typeapp-iot-1.0.0-rc.1-windows-x64.zip` |

同一 Release 提供 `SHA256SUMS` 和 `release-manifest.json`。前者用于核对下载字节，后者记录源码、版本、候选运行轮次、平台及同一产物的三库验收。摘要应从受信发布渠道取得。

当前附件是**包含原生运行库的目录归档**，解压后须保留完整目录。前端内容已编入主程序，安装命令将页面写到应用根下的 `public/`；运行端无需 Node.js、pnpm、Composer 或业务 PHP 源码，也无需另行部署 Swoole 服务。所选 MySQL/PostgreSQL、Redis 等业务服务仍需准备，SQLite 使用本地数据文件。完整静态“一个主程序 + 配置”尚未完成，见[构建与部署](deployment.md)。

核对下载、解压和运行库后，按[首次启动](deployment.md#首次启动)完成账号、数据库与页面安装。后续替换程序版本时，使用 `web:install --dry-run --force` 查看页面变化，再执行 `web:install --force`，不要重新执行空库初始化。

## Composer 按版本安装

组件与通用模板不包含物联中心前端。发布公开且 Packagist 已列出该版本后，可从默认公共索引安装 RC：

```bash
composer create-project --no-install --no-plugins --no-scripts zoujingli/type-project my-app 1.0.0-rc.1
cd my-app
php configure.php sqlite
composer config minimum-stability RC
composer config prefer-stable true
composer require --no-update zoujingli/type-core:1.0.0-rc.1 zoujingli/type-orm:1.0.0-rc.1 zoujingli/type-orm-sqlite:1.0.0-rc.1 zoujingli/type-runtime:1.0.0-rc.1 zoujingli/type-log:1.0.0-rc.1 zoujingli/type-validate:1.0.0-rc.1
composer require --dev --no-update zoujingli/type-build:1.0.0-rc.1 zoujingli/type-testing:1.0.0-rc.1
composer install --no-plugins --no-scripts
php dev.php check
```

示例固定模板使用的第一方组件；新增组件也应选择明确版本。`minimum-stability RC` 允许解析候选依赖，不表示 RC 已稳定。提交生成的 `composer.lock`，日常构建用 `composer install` 复现实际版本；升级依赖时再受控更新。开发分支用法继续见[快速开始](quickstart.md)。

## 维护者触发与重试

发布工作流为 `.github/workflows/release.yml`。推送版本 tag 即触发；手动重试时选择原 tag 作为工作流 ref，并填写同一个版本。tag 必须解析为 `main` 历史中的完整提交，不得移动已有标签。

```bash
git tag -a v1.0.0-rc.1 -m 'TypeApp 1.0.0 首次候选发布'
git push origin v1.0.0-rc.1
gh workflow run release.yml --ref v1.0.0-rc.1 -f version=v1.0.0-rc.1
```

上面最后一行仅在需要重试时执行。候选尚未封存时，重新执行完整工作流；不要把不同运行轮次的零散平台结果拼为一次验收。候选草稿已存在时，工作流复用原运行的验收和已保存附件；附件上传不完整时从原 Actions artifact 恢复。原证据过期或同名附件摘要不同会停止，不能靠重编译冒充原候选。

子仓 Git 写入使用各仓独立 deploy key。跨仓 Release 使用主仓 Actions secret `TYPE_RELEASE_TOKEN`，凭据为仅授权映射中 16 个子仓 **Contents: Read and write** 的 fine-grained PAT；主仓 Release 使用自身 `GITHUB_TOKEN`。未配置该 secret 时在构建前明确失败。Packagist 沿用各子仓 webhook，并有界等待索引，不跳过公开安装验证。

跨仓发布不具有原子性。失败时已成功的标签和 Release 保留，回执写入 `release-receipts-<轮次>`；用同一 tag 重试后补齐。全部 16 个子仓公开并回读通过，主仓才公开。标签或附件内容冲突需要核对原因和新版本计划，不能强制覆盖。

[安装与更新页面](deployment.md#前端安装与更新) · [组件职责](components.md) · [环境与依赖](environment.md)
