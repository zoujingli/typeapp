# 文档站发布

[iots.top](https://iots.top) 发布仓库 `docs/` 导出的静态文档。站点主体是 TypeApp 应用框架的开发文档；TypePHP、Swoole 与 Plugins 的职责见[系统架构](architecture.md)。物联中心是成品案例，放在独立文档分组。站点不托管业务 API、管理端登录、设备 MQTT 或上传文件。这些地址由应用部署配置决定。

## 公开内容边界

仓库中的 `docs/` 同时包含公开指南、架构决策、工程规范和实现说明。只有 `docs/build-site.sh` 白名单中的内容会进入站点：

```text
index.html       Docsify 入口
README.md        首页
_sidebar.md      分组菜单
_navbar.md       顶部导航
_404.md          未找到页面
assets/          固定版本的 Docsify、PrismJS 和主题资源
guide/           面向使用者的公开指南及插件页
LICENSE NOTICE   第一方许可证和第三方归属说明
```

`docs/adr`、`docs/agents`、`docs/build-config`、`docs/deployment`、`docs/development`、`docs/research` 和 `docs/standards` 默认不发布。不要通过隐藏侧栏或手工复制把内部材料放入公开目录；要公开的新内容必须先放入 `docs/guide/`，并同时更新侧栏、首页卡片和交叉链接。

需求、规格与任务以 GitHub Issues 为唯一权威来源，仓库内不再保留 `docs/specs`、`docs/tickets`、`docs/drafts` 等镜像目录；文档引用这些材料时指向对应 Issue。

## 本地预览和导出

在项目根执行：

```bash
site_output="$(bash docs/build-site.sh)"
python3 -m http.server 3000 --bind 127.0.0.1 --directory "$site_output"
```

打开 `http://127.0.0.1:3000/`，检查首页、导航、搜索、代码复制、深层 hash 路由和 404。导出脚本会在 `build/docs-site.*` 创建独立目录，不覆盖旧产物，也不会自动发布或重启服务。

文档站只需要静态文件服务器；不要用 `file://` 打开，因为 Docsify 需要通过 HTTP 读取 Markdown。Nginx/Apache 应将站点根指向导出目录，并对缺失资源返回真实 404，不把所有路径回退到 `index.html`。

## 发布到 iots.top

Linux 发布使用 `tools/deploy-docs-site.sh`。部署用户需要一个带 `origin` 的 Git 检出和独立发布根；公开主仓使用 `https://github.com/zoujingli/typeapp.git` 只读拉取，无需 Deploy Key。源码检出、密钥、证书和 runner 不放在 Web 根目录。示例：

```bash
bash tools/deploy-docs-site.sh \
  --repo /srv/typeapp-docs/repository \
  --publish-root /srv/typeapp-docs/site \
  --branch main
```

脚本先获取远端提交、核对此次安装的 runner 与该提交中的 `tools/deploy-docs-site.sh` 一致、运行 `docs/build-site.sh`、校验公开白名单和普通文件，再把新版本写入 `releases/<UTC时间>-<完整提交号>`，最后原子替换 `current`。导出失败、过期 runner、源文件符号链接、白名单外文件和并发执行都会拒绝或跳过，并保留当前线上版本；最近三个成功版本保留用于回滚。`LICENSE` 和 `NOTICE` 随站点根发布；导出清单变更后必须先更新已安装 runner，不能只推 Git。

上线前由维护者完成：

1. 在本地预览和 `bash tools/test-docs-deployment.sh` 中验证首次发布、更新、无变化、失败保留、回滚和版本清理。
2. 检查 `LICENSE`、`NOTICE` 和 `assets/vendor/` 许可材料随导出目录存在。
3. 在真实站点核对 HTTPS、证书续期、缓存重新验证、深层 hash 刷新和真实 404。
4. 发布后在页脚核对「TypeApp · 物联开源分享」、Apache-2.0、NOTICE、许可证说明、粤 ICP 备案号和粤公网安备备案号；确认公安备案图标显示在备案号左侧，图标与文字共同链接到对应备案查询页，并在新窗口打开。

回滚时暂停计划任务，确认部署锁空闲，再把 `current` 原子切换到指定 `releases/` 目录。修复提交明确进入远端分支后才能恢复自动同步，避免下一轮发布覆盖人工回滚。

## 文档写作与代码说明

公开页面必须说明实际实现、调用前提、失败语义和未验证范围，稳定 API 必须对应当前实现与实际验证范围。架构图、流程图与时序图使用 Mermaid 代码块，由站点本地脚本渲染，不引用 CDN；示意图帮助理解结构，不能代替验收记录。新增或修改 PHP 类、接口和公开方法时，在源码中写职责型 PHPDoc；涉及事务、缓存、Socket、线程或协程时说明所有权、期限、清理和跨线程限制。实现、配置和文档必须在同一提交中更新。

站点搜索使用本地 Docsify 资源。集中修改指南后递增 `docs/assets/site.js` 的 `search.namespace`，避免读者继续使用旧索引。第三方文档、代码或前端资源必须保留原始许可证和来源，不能把它们重新声明为 Apache-2.0。

[构建与部署](deployment.md) · [许可证与归属](licensing.md) · [返回文档首页](../README.md)
