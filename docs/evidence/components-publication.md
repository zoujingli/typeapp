# 组件文档与公共 Composer 发布验收

记录日期：2026-09-25。源码基线为主仓提交 `6835985bd991de536d92c5905da371fd01333cb7`。本次发布 15 个组件和 `type-project` 模板的 `dev-main` 开发分支，没有创建稳定标签，也没有以源码发布代替完整产品验收。

## 已执行范围

| 检查 | 实际结果与边界 |
| --- | --- |
| 第一方注释 | 审计 app、plugin、templates、examples、tests、tools 的 3209 个类、函数及公开方法声明，均有 PHPDoc；新增职责、参数、失败与资源所有权说明。前端职责注释另通过类型检查 |
| 本地独立消费 | 15 个组件的 README 示例全部通过 PHP 执行及适用的非法输入检查；真实 MySQL、PostgreSQL、SQLite、Redis 采用专用测试资源 |
| 公共独立消费 | 15 个组件全部通过 Packagist 安装，无自定义 repositories 或 path 回退；每个第一方依赖的锁定提交与本次分发计划一致，示例及适用拒绝路径通过 |
| 公共应用模板 | 通过 `composer create-project` 在含空格目录创建；31 个模板文件与分发源码字节一致。SQLite 迁移、真实 HTTP CRUD、分页、PATCH、版本冲突、认证及正常停止通过；这是 PHP 消费验证 |
| 内置 Swoole | 公共 `type-build` 分发的 26 个资源文件、四个平台模块、17 份许可原文和组件 `.gitattributes` 均与源码一致；含空格目录与不同工作目录可选择本机模块，未执行其他平台模块 |
| 基础质量 | 格式检查通过；765 个 PHP 文件语法及基础入口通过；91 项契约测试、3052 个断言通过；分发批次回归通过 |
| 文档一致性 | 核对 1621 处引用，覆盖 204 条路由、313 个命令和 248 个 Composer 脚本；公开站点白名单通过 |
| Docsify 浏览器 | 15 个组件指南的 22 幅图、实战教程的 2 幅图和架构页的 4 幅图渲染正常；1280 宽桌面与 390 宽移动端、搜索、标题及图片检查通过 |
| 线上站点 | `https://iots.top` 的首页、实战教程、组件安装页、队列指南、站点脚本及入口与本次源码一致；浏览器实际打开线上教程，保留“物联开源分享”标题后缀 |

MQTT README 本次仅验证离线公开报文接口，不构成网络协议、集群或容量验收。`type-testing` 示例使用既有原生产物验证测试控制端，沿用该产物自己的运行配置与身份，未把它称为本次重新编译。

## 分发身份与自动更新

16 个公开 GitHub 子仓均采用非强制推送。以下提交由主仓固定源码拆分，远端 `main` 与 Composer 实际使用的 `repo.packagist.org/p2/…~dev.json` 均逐项核对一致。每个仓库的 Packagist push webhook 已启用，本次 push delivery 均返回 HTTP 202；未另行复制账号凭据。

| 包名（`zoujingli/`） | 子仓 main 提交 |
| --- | --- |
| type-build | `30d6534a02f5760b3e30618de4103393803dacb0` |
| type-cache | `710734cb6ef0f89dc727fa5b159568f6ff59ef12` |
| type-core | `af4a3e7b27eafb8d4c19645fe1b1496e14c40fca` |
| type-log | `70d049b7de255177931c256cf441842ffd4e931d` |
| type-mqtt | `ab9f0b383223ac16338b93be7f9618a297ef6c4d` |
| type-orm | `b083cf89b6e5187089c93e86c5af21e61f953c2a` |
| type-orm-mysql | `c1576255f5a46a69be43c18aebd9fdc0fd9c5c69` |
| type-orm-pgsql | `efbf7f74c040edeacf87d7b42ae5a209aa74a901` |
| type-orm-sqlite | `73379481b3d6e790770edb5d5ea92232ec677189` |
| type-queue | `9fdb669d45a1b5d812eed7cc6585b8cfb3cb6248` |
| type-redis | `f1bfd8b4bef4b5c2f33d3d59c413614bf223e294` |
| type-runtime | `32a7d21b6681e1d6a1e089dbcb0d7d3efa7a2530` |
| type-scheduler | `0dad5269c2c8698a7ef91bd5978995c68496d6f9` |
| type-testing | `e05b31f691cc63bc83dcfdf0008dce2c12fb5449` |
| type-validate | `22634088e030b42868f54928af844c11ef10621f` |
| type-project | `3a416b0d062d0f072256c126b86fd3b4aea8e32c` |

Packagist 网站的 package JSON 在发布后短时间仍返回旧缓存，本次以 Composer 元数据、实际安装锁文件和 GitHub 提交共同确认版本。安装方式见[组件参考](../guide/components.md)及[应用开发实战](../guide/tutorial.md)。

## 原生打包修复的独立证据

真实模板发布暴露了顶层 NOTICE 被构建组件材料替代的问题。提交 `f7ba560` 改为从已校验产物资源提取唯一应用的原文，并以身份生成协议 4 支持可选的 LICENSE/NOTICE。依赖原文保持原归属；重算发布清单摘要不能绕过应用材料归属校验。

| 场景 | 原产物身份 | 验证范围 |
| --- | --- | --- |
| 本轮完整模板，协议 3 | build-id `5af2c106276c26f4db1848889f85a7a13018284fb3723388bde156b5d8102884`；SHA-256 `cf5bfaadcbc517b5c09f1febdfeb7237faafbcd7b67cde7e83d3d259d5c18b80` | 全量模板构建、开发与原生业务；同一产物更正打包后搬迁、禁止源码与 SDK 读取、SQLite HTTP、归档及原始材料回归 |
| 历史主仓应用，协议 3 | build-id `90dc751a469b84cb2fa781cbfef4848cbdbc39ebc928901ae6fde676b69251fe`；SHA-256 `ab73662861395bb6d6bd245c9948291bcdc36f5d16386767561c91c68671de35` | 复用原产物验证新打包工具、SQLite 无源码发布与归档；没有重新赋予本次源码身份 |
| 无应用材料，协议 4 | build-id `199cd09b748b4cfb65fd9b988a01731ed094f0ac1c125c4cf34b06149f91c34b`；SHA-256 `dae2e4cd9fc3ec33879be18b09a81ea4279473a97d7ecda680c44365377b86b2` | 小型独立消费者真实 AOT、原生资源读取、可选材料、依赖材料保留及篡改拒绝 |
| MIT 且无 NOTICE，协议 4 | build-id `ddb93be13831c554a4f7ced2410f9a0ea2a9f2e8794e7fc3c71d95210a5a2b8c`；SHA-256 `8bc236c62cb18cc3ffd948eff68bdb665fa7632b72c9927617a07183f0b8cda6` | 同上，另验证应用 MIT 原文不被框架许可替换 |

上述原生结果均来自 macOS ARM64。完整模板的协议 3 产物保持不变，协议 4 由单独的小消费者验收；不能合并声称“公共安装的完整模板已在所有平台按协议 4 编译”。当前仍为目录包与归档，未完成静态单程序交付。

## 保留的失败与未完成项

- 模板原生测试最初继承了控制端的 PHPRC，加载位置与自身身份不符；已改用该产物构建报告声明的运行配置，未关闭校验。
- Queue、Scheduler README 与指南的协程入口曾不一致；现统一在同一协程装配、执行和释放资源，并由本地与公共消费者复验。
- 小消费者首次构建期间发生构建工具 README 修改，输入身份检查正确拒绝；稳定输入下的新消费者通过，原失败日志保留。
- 本机文档部署隔离脚本因缺少 Linux `flock` 跳过；静态导出、站点边界及线上内容另有验证，不将跳过记为通过。
- 同源码的 [macOS CI 36028577573](https://github.com/zoujingli/typeapp/actions/runs/36028577573) 在 Swoole 加载阶段失败：所用 PHP SDK 缺少 `zend_signal_globals_offset`。这属于构建配置兼容问题，尚未修复，不能用本机结果替代；Linux 与 Windows 本次完整 CI 在取证时仍运行中。
- GitHub 没有开放 PR；ORM 全量原生最终验收和物理 PDO 安全复用两项 Issue 保留开放，未因文档或源码发布而关闭。

## 归档与恢复

本次原始报告、失败日志、分发计划及回执、源码/锁定依赖/工具链身份、实际产物和必要资源保存在 `.cache/components-publication-20260925/evidence.tar.gz`，共 588 个文件，归档 SHA-256 为 `864a3ccb084813fece4d14b7703d1dcffdcb2074250588941cc9820993db7f08`。逐文件解压读取核对字节数及 SHA-256 后再回收临时资源；完整清单见同目录的 `manifest.json`，回读结果见 `verification.json`。

恢复时将归档解到新的隔离目录，按清单查阅原项目相对路径。原始日志可能包含当时的机器路径，只用于还原身份，不作为其他机器的默认配置。执行重建时重新解析项目与 SDK 位置；重建产物另记新身份。共享 SDK、历史主仓产物及用户原有服务保留，临时目录回收结果另见归档目录的 `cleanup.json`。
