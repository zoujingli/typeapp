# Windows 完整门禁的串行总预算

RC17 固定源码 `f41b84ad594ae7c96e66fb8b2199bd2b4a10c97c`。[正式发布运行](https://github.com/zoujingli/typeapp/actions/runs/38854215726)的十二个部署单程序及十二组教程候选均通过，但 Windows 完整平台任务 `116822588634` 未完成，不能公开该版本。

## 取消原因

GitHub 检查注释明确记录：`The job has exceeded the maximum execution time of 2h0m0s`。任务于 UTC 05:28:44 开始，最后的 PostgreSQL 步骤在 07:24:12 开始，07:29:10 被取消；随后执行证据上传，任务最终以 `cancelled` 结束。这是工作流总时间上限触发的取消，不能把未完成的 PostgreSQL 验收记为通过。

| 测量 | 实际结果 |
| --- | --- |
| 正式门禁进入 PostgreSQL 前的串行耗时 | 6928 秒，即 115 分 28 秒 |
| PostgreSQL 被取消前已执行时间 | 298 秒 |
| 同一源码的[完整应用复验](https://github.com/zoujingli/typeapp/actions/runs/38847727655)中 PostgreSQL 耗时 | 888 秒，即 14 分 48 秒；该轮通过 |
| 原工作流总预算 | 120 分钟 |

前序实际耗时加上相同源码已通过的 PostgreSQL 耗时约为 130 分钟，尚未计入最终证据上传。该合计只用于预算推导，不是另一轮完整门禁通过的声明。

Windows 此前已完成工具链线程与 IOCP、构建身份及缓存、完整公开契约、三库独立 ORM、共同装配和事件、快捷接口与内嵌资源，以及完整应用 SQLite、MySQL 和相应模板。失败任务的 Artifact 为 `12031314995`，共 98895106 字节，GitHub 登记的 ZIP SHA-256 为 `da698430688b3691b78b7306e0d8b24ec550fd75b1910bba006b746d12ba980d`。原日志、任务状态和检查注释按原身份保留。

## 调整与发布边界

将 `.github/workflows/native-platform.yml` 的 Windows 串行任务总预算改为 180 分钟，为完整编译、业务验收和证据保存提供有界余量。单项编译器、进程、业务请求和数据库步骤仍由现有验收入口约束；执行顺序、必需步骤、断言和发布门禁均保持原契约。无需修改生产运行时、SDK 或数据库实现。

RC17 标签保持原提交，未公开 Release。Windows 总时限失败确定后停止该发布的其余任务，未完成的 macOS 专项如实记为取消。后续以 RC18 重新执行同一轮次的完整矩阵；既有候选和定向验收只作为诊断依据，不替代 RC18 的正式结果。
