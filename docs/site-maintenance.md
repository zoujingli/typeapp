# 文档站维护

站点入口为 `index.html`，使用 Docsify 5.0.0 在浏览器中渲染 Markdown，无 Node/npm 构建步骤。页面标题为「TypeApp - 物联开源分享」，其中「物联开源分享」是 iots.top 的备案网站名称。页脚展示作者 Anyon、Apache-2.0、NOTICE、许可证说明，并链接备案号到工信部备案查询网站。

## 内容与资源

- `README.md`：文档首页。
- `guide/`：面向框架使用者的指南。`guide/architecture.md` 集中说明 TypeApp 应用框架、TypePHP 编译器、Swoole 原生运行能力与 Plugins 的关系；首页使用同一职责表述。Swoole 为必需的通信与并发底层，单程序加配置为交付约定；现有实现差距须标明。物联中心是成品案例，侧栏独立分组并放在 TypeApp 框架与开发指南之间；框架文档提及它时只作为案例。目录以 `_sidebar.md` 为准。
- `guide/plugins/`：15 个插件的独立使用页，由 `guide/components.md` 汇总，侧栏按用途分组；源码数量不等于已分发或完整验收数量。
- `_navbar.md`、`_404.md`：顶部导航与未找到页面。
- `assets/site.js`、`assets/site.css`：站点配置、代码复制、首页动效和主题变量。
- `assets/build-flow.svg`：首页构建流程插图，随站点本地加载。
- `assets/iot-architecture.svg`：物联中心业务架构动图，仅物联网中心页引用；颜色使用站点夜色与强调色，动效在减少动态效果时关闭。
- `assets/vendor/`：固定版本的 Docsify、搜索、Prism 语言资源和 Mermaid 及许可原文。
- `LICENSE`、`NOTICE`：由根目录复制到导出根，供网站访问者直接核对第一方许可证和第三方归属。

指南间使用相对当前 Markdown 文件的链接，与 `relativePath: true` 一致，也可直接在仓库中阅读。全站侧栏、导航和 404 页使用 Docsify 路由根的 `/guide/xxx.md`；首页 HTML 中使用 `#/guide/xxx`。Hash 路由支持根路径和子目录部署，静态服务器无需 SPA 路径重写。

标题由 `index.html` 和 `assets/site.js` 的 `siteTitle` 保持一致；Docsify 页面切换时通过 `pageTitleFormatter` 保留网站名称。作者、许可证与备案信息在静态 HTML 的 footer 中，渲染后移到正文下方，不会因换页重复追加。页脚链接使用普通段落，不使用 `nav`，避免被 Docsify 的 `mergeNavbar` 收走。首页不以法律声明开场；文档与业务地址的边界写在「使用前了解」。

首页使用 HTML 组织主视觉与指南卡片，正文指南仍使用普通 Markdown。首页标题保留 `tabindex="-1"`，供 Docsify 在路由跳转后聚焦，避免其自动滚动遮住主视觉顶部。手机顶部栏在 `index.html` 中定义，导航和搜索继续使用 Docsify 原有侧栏。

## 颜色与动效标准

TypeApp 文档使用青绿表达品牌和操作，深墨承载代码与编译过程，冷白承载正文阅读。色值统一定义在 `assets/site.css` 的 `:root` 中，组件样式只使用语义变量；透明度通过 `color-mix()` 派生，不另加近似颜色。

| 角色 | 变量与标准色 | 使用位置 |
| --- | --- | --- |
| 品牌色 | `--brand: d64`、`--brand-strong: e5f4d` | 标志、链接、导航选中态 |
| 品牌浅色 | `--brand-soft: #e4f3ed`、`--brand-line: #b5d9cc` | 导航底色、推荐入口、提示框 |
| 深色区强调 | `--accent: dfbb`、`--accent-soft: #b6f2de` | 主按钮、光标、编译连线 |
| 阅读表面 | `--surface: #f8fbfa`、`--surface-raised: #ffffff`、`--surface-muted: #eff5f2` | 页面、卡片、侧栏 |
| 正文层级 | `--ink: e`、`--text: d54`、`--muted: f65` | 标题、正文、辅助文字 |
| 普通边界 | `--line: #dbe7e1` | 表格、分隔线、输入框 |
| 代码表面 | `--night: c211c`、`--night-raised: e26`、`--night-line: c4a3f` | 首页、代码面板及其边界 |
| 深色区文字 | `--night-text: #e4f5ed`、`--night-muted: b6a9` | 代码、注释、辅助说明 |
| 语法辅助色 | `--syntax-keyword: dc8f4`、`--syntax-literal: #e8c789` | 关键字、变量与数值等语法区分 |
| 警告语义 | `--warning-bg`、`--warning-line`、`--warning-text` | 保留琥珀色警告，避免与普通提示混淆 |

Docsify 和 Prism 的颜色入口映射到上述变量。独立 SVG 不继承页面变量，`favicon.svg` 与 `build-flow.svg` 直接使用标准色；修改对应变量时同步 SVG，`index.html` 的 `theme-color` 与 `--night` 一致。

首页动效使用通用 `#[Route]` 声明片段和 `php vendor/bin/type build` 命令，展示 TypeApp 编译路径，不执行命令、不模拟实时构建结果。完整路由说明通过面板链接进入。

动效自动循环播放，每轮完成后保留完整代码 2 秒，再重新开始，可随时暂停或继续。代码高亮只解析一次，逐字更新文本节点；离开可见区域或切换标签页停止计时，Docsify 换页时释放计时器、观察器和事件监听。系统开启“减少动态效果”时直接显示完整代码并关闭光标与连线动画；逐字内容不向屏幕阅读器连续播报。

## 本地预览与导出

从项目根执行：

```bash
DOCS_OUTPUT="$(bash docs/build-site.sh)"
python3 -m http.server 3000 --bind 127.0.0.1 --directory "$DOCS_OUTPUT"
```

打开 `http://127.0.0.1:3000`。上述预览需要 Python 3，只监听回环地址；站点自身只有静态文件，部署无需 Python 或 PHP。不要用 `file://` 直接打开 HTML，Markdown 需要 HTTP 读取；静态服务器对缺失文件应返回 404，不应把不存在的 Markdown 回退到 `index.html`。

导出脚本以自身路径定位项目，可以从其他工作目录调用，支持含空格路径。每次生成独立的 `build/docs-site.*` 目录并输出其实际位置，不覆盖已有产物。复制该目录中的全部文件（包含 `.nojekyll`、`LICENSE` 和 `NOTICE`）到选定静态站点即可；导出本身不会发布或改变任何线上配置。脚本在复制前拒绝缺失的公开输入，防止生成内容不完整的站点。

**发布根必须是导出目录。** 现有 `docs/` 还包含工程规范、架构决策和内部实现说明；隐藏侧栏链接不构成文件隔离。脚本仅复制明确列出的站点文件、根许可证、`guide/` 与 `assets/`，这些目录应只放准备随站点交付的内容。

## 更新与检查

修改指南时同步侧栏和交叉链接。新增外部资源前确认必要性；当前页面渲染、搜索与代码高亮均使用本地资源，备案查询与 TypePHP 链接只在用户点击时访问外部站点。

插件文档以 `plugin/type-*/composer.json`、公开源码及消费示例为依据。新增或删除组件时同步组件总览、插件页和侧栏；公开参数、默认值、返回类型或失败语义变化时，同步插件 README 与站内使用页。完整最小示例优先复用包 README，接续片段说明依赖对象与执行位置，PHP 开发启动器和生产声明入口分开。`guide/plugins/` 随 guide 递归导出，无需公开内部研发目录。

基础通信按 `guide/communications/http.md`、`tcp.md`、`udp.md`、`mqtt.md`、`websocket.md` 分篇，菜单保持 HTTP / TCP / UDP / MQTT / WebSocket 顺序。`guide/communications.md` 只保留协议选择与共享约定；组件页维护安装与高级接口参考。更新协议教程时核对描述、配置、完整入口、双端命令、预期结果、应用设计、资源关闭与当前限制，不只复制 API 列表。

物联网和 MQTT 公共指南记录使用流程、配置、确认边界与当前限制，不链接内部研发、任务或验收原件，也不因新增业务扩大导出白名单。README 与研发文档描述当前实现、可复用验证方法和未完成范围，运行报告放在任务专用目录。

平台已通过范围与完整应用限制统一维护在 `guide/platforms.md`；架构、快速开始、通信教程、构建部署和实现规划链接到该页。更新时按平台、源码与产物分别核对证据，组件、PHP 行为和完整应用验收分别描述，不把不同产物的结果合并为全平台通过。

搜索索引在浏览器缓存一小时，刷新 Markdown 不会立即替换已有索引。集中更新指南或章节后，递增 `assets/site.js` 中 `search.namespace` 的版本，保留站点 pathname 隔离；当前值以该配置为准。随同发布新的 site.js，并用新增章节标题验证搜索命中，避免页面已更新而搜索仍展示旧内容。

指南中的流程图、架构图和时序图使用语言标记为 mermaid 的代码块，由本地 `assets/vendor/mermaid/mermaid.min.js` 渲染，颜色映射到站点 CSS 变量。不要引用 CDN，也不要把示意图写成已验收能力。

浏览器检查首页、每个指南、章节锚点、搜索命中与无结果、代码复制、404 返回链接、窄屏导航和页脚。分别从站点根和子目录进入深层 Hash 地址并刷新，核对资源请求无遗漏。

更新依赖时从固定版本的 npm 包获取发行文件，核对包完整性，保留许可原文并更新 `assets/vendor/README.md` 的版本与摘要；不在浏览器引用浮动版本的 CDN。导出目录必须保留许可文件，不能把第三方许可误当成 TypeApp 项目许可。

## 宝塔部署与自动同步

`tools/deploy-docs-site.sh` 是 Linux 部署入口，依赖 Bash、Git、GNU coreutils（含 `timeout`、`realpath`、`cmp`）和 util-linux 的 `flock`。`--repo` 指定有 `origin` 的源码检出目录，`--publish-root` 指定与检出目录相互独立的发布目录，`--branch` 默认为 `main`；相对路径以调用时工作目录解析，支持空格路径。机器目录、域名、账号和凭据由服务器配置，不写入脚本。

`docs/build-site.sh` 的 `site_files` 导出清单与 runner 中的必需清单、发布白名单必须一致。仓库侧由 `composer test:docs-deployment`（随 `composer check` 运行）拦截两处清单漂移。runner 是独立副本，`git fetch` 不会替换它：计划任务只调用已安装脚本，导出则运行当前提交里的 `docs/build-site.sh`。因此新增或改名导出条目、调整发布白名单或改动部署逻辑后，必须先把该提交推到跟踪分支，再从该提交安装 runner，最后手动同步一次。若只推 Git、不更新 runner，旧副本会按旧白名单拒绝 `NOTICE` 等新根文件，并保留线上版本。

每轮日志的 `CHECK` 行带 `runner=` 摘要，可与该提交中 `sha256sum tools/deploy-docs-site.sh` 对照。解包后还会把已安装脚本与当前提交的 `tools/deploy-docs-site.sh` 逐字节核对；不一致时失败并写明请先更新已安装 runner，不自动改用仓库脚本，也不继续导出。

公开主仓使用 `https://github.com/zoujingli/typeapp.git` 拉取，服务器检出的 origin 使用该 HTTPS 地址，部署用户不需要仓库 Deploy Key。源码检出、凭据和运行脚本均放在 Web 根之外。将经过审核的部署脚本安装为独立、由管理员维护的 runner，计划任务调用它；不要改成直接执行检出工作树里的脚本。

宝塔先创建原生 HTML 站点并绑定所需域名。最终站点根指向发布目录的 `current` 符号链接，创建站点时产生的默认文件应保留在独立初始化目录，不混入版本。Nginx 使用 `try_files $uri $uri/ =404`，不启用 PHP、不回退 HTML；允许 `README.md` 和公开第三方许可证，禁止隐藏文件与内部研发目录。HTML、Markdown、JS、CSS 设置 `Cache-Control: no-cache`，由浏览器重新验证缓存。两个域名共用 SAN 证书，通过宝塔申请、部署和自动续签；HTTP 使用 `$host` 跳转到同域名 HTTPS。ACME 验证路径单独映射到版本目录之外，不能随着 `current` 切换。

在宝塔创建 Shell 计划任务，选择原生 `second-n`，`second=30`，以独立部署用户调用已安装的 runner，并传入上述目录参数。先手动发布并验证站点，再启用任务；输出由宝塔任务日志接收。每轮记录 `CHECK`，随后为 `UNCHANGED`、`PUBLISHED` 或 `ERROR`；并发调用记录 `SKIP`。非阻塞锁覆盖完整同步流程，正常限时 115 秒，超时后最多再用 5 秒终止子进程。

脚本拉取分支并固定提交号，在临时目录解包，核对已安装 runner 与该提交脚本一致后，再复用 `docs/build-site.sh` 导出；拒绝过期 runner、文档源符号链接、导出非普通文件、隐藏内容及发布白名单外的文件。只有完整导出和校验通过才原子替换 `current`。失败保留原版本，无变化不重复导出。`current` 的目标名为 `releases/UTC时间-完整提交号`，即线上发布记录；最近三个成功版本保留在同级 `releases/` 下。

### 更新已安装 runner

导出白名单或部署脚本变更进入跟踪分支后，暂停宝塔对应同步任务，等待当前执行结束（最多 120 秒）并确认发布目录下 `.deploy.lock` 空闲。从**该提交**安装 runner，不要用未推送的工作区文件，也不要让计划任务改跑检出目录里的脚本：

```bash
git -C "$repo" fetch --no-tags origin "refs/heads/$branch"
commit=$(git -C "$repo" rev-parse --verify 'FETCH_HEAD^{commit}')
git -C "$repo" show "$commit:tools/deploy-docs-site.sh" > "$runner.tmp"
install -m 0755 "$runner.tmp" "$runner"
rm -f "$runner.tmp"
bash "$runner" --repo "$repo" --publish-root "$publish_root" --branch "$branch"
```

`$repo`、`$publish_root`、`$runner`、`$branch` 与计划任务使用同一组值。期望日志为 `PUBLISHED commit=...`；`current/LICENSE` 与 `current/NOTICE` 须为非空普通文件，且站点未出现 `docs/development` 等内部目录。`CHECK` 行的 `runner=` 摘要应等于该提交中 `tools/deploy-docs-site.sh` 的 SHA-256。确认后再恢复计划任务，下一轮应为 `UNCHANGED` 或正常 `PUBLISHED`。

回滚时先按同样方式暂停任务并确认部署锁空闲。核对 `releases/` 中上一版本后，在同一发布文件系统新建临时符号链接，再用 `mv -Tf` 替换 `current`，避免先删除当前链接造成空窗。验证页面和提交号后保持任务暂停；恢复自动跟踪前，先明确远端分支要发布的修正提交，避免下一轮覆盖回滚。

隔离验收入口为 `bash tools/test-docs-deployment.sh`，仅在项目 `build/` 下创建独立本地 Git 仓库和发布目录，保留日志便于复查。macOS 可使用已有 Linux 工具链容器运行该脚本，不需要启动应用或连接数据库。验收覆盖首次发布、更新、无变化、失败保留、符号链接与白名单拒绝、过期 runner 拒绝、并发跳过、恢复和版本保留；上线另行验证双域名 HTTPS、真实 404、缓存、浏览器交互与连续两次约 30 秒的自动触发。
