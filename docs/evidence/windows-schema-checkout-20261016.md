# Windows 冻结快照检出诊断

[Windows 完整预检](https://github.com/zoujingli/typeapp/actions/runs/38829673887)的第二次运行固定源码 `347df8ce2603fa8a40978b2c527323eb8f3239a5`。工具链九项原生回归与完整公开契约已通过，随后独立 SQLite ORM 消费者在首次 `schema:prepare` 返回 `TYPE_SCHEMA_FROZEN`，尚未进入该消费者的数据库行为验收。

原日志任务为 `116755678536`；证据 Artifact 为 `12019032166`，下载 ZIP 共 24134267 字节，SHA-256 为 `379fd30e00ba40776462d5380d6604fd5026690d6758e100cae2c7d5ea2b13be`。该 Artifact 未收录失败消费者的快照原件，因此本机对照单独记录，不冒充原运行文件检查。

## 可复现对照

使用 `git -c core.autocrlf=true cat-file --filters`，对源码 `59fcb0fbfb46f1fe80824ed889b60bf92a519fd1` 的四份 `examples/orm-suite/snapshots/catalog-*.json` 应用真实 Git 检出转换，并复制对应 Schema 声明到独立临时目录。通过公开 `php vendor/bin/type schema:prepare` 逐个准备：四份快照均稳定返回同一个冻结错误。

保持声明、生成器和独立目录不变，只将快照恢复为 Git 对象中的 LF 字节，四个入口均退出 0、返回 `created:false`，文件摘要不变。转换前后唯一差异为 CRLF 与 LF；原内容在当前生成器和不同路径下均可重复准备。这排除了该复演中的 SQL 漂移和目录身份差异。

## 修复与边界

主仓 `.gitattributes` 为 `**/snapshots/*.json` 声明 `text eol=lf`。冻结 JSON、SQL、协议与生成器均不修改；严格字节比较和篡改拒绝继续有效。应用自有快照的 Git 规则写入 [Schema 使用说明](../development/schema.md)。

修复后再次使用相同 Git 转换命令，四份快照均保持 LF，公开准备入口全部通过且摘要不变。现有 `SchemaCompilerTest` 的 4 项测试、136 个断言通过，文档检查通过 1961 处引用；临时对照目录已回收，保留红绿结果与原证据 ZIP。

[修复后的 Windows 运行](https://github.com/zoujingli/typeapp/actions/runs/38837392358)固定源码 `c0ba1367ef703d73819ede22637e8fa906351ef0`，完整公开契约和三库独立 ORM 步骤均通过。原有消费者仍逐份核对 `created:false` 和文件摘要，并执行各驱动的 PHP、AOT 及读写行为，未跳过冻结校验。

该运行随后在 HTTP 共同装配的独立源码路径断言失败，尚未完成完整 Windows 平台验收，详见[路径断言记录](windows-http-assembly-path-20261016.md)。Schema 修复后的三库结果不代表 RC17 已发布；最终源码与程序仍须完成正式矩阵。
