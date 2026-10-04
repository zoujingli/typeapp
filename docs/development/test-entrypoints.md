# 验收入口与迁移边界

本页区分当前测试入口、已经退出的身份流程和仍待迁移的夹具。运行记录必须绑定源码、程序摘要与实际参数；文件存在或基础场景通过，不代表附加专项已经执行。公开能力及待完成要求见[实现规划](../guide/roadmap.md)。

## 当前应用入口

| 验证范围 | 入口与前置条件 |
| --- | --- |
| 双端账号、租户、角色及旧入口拒绝 | `tests/iot-identity.php <程序或--php> <驱动> --app` |
| 产品、设备及业务操作 | 同一入口的 `--products` 或 `--devices`；历史、聚合、生命周期、告警、导出等专项按对应指南组合 |
| 独立 Broker 身份与观察 | 同一入口的 `--broker`；`--broker-observability` 由真实 PostgreSQL 同步装置调用 |
| 同一程序三库 | `tests/iot-identity-databases.php <程序或--php> <MySQL工具根> <PostgreSQL工具根> --app`；原生隔离追加 `--no-source` |
| 当前应用升级、就绪与停止 | `tests/application-runtime.php`，参数见该脚本及[修复验收](../evidence/system-architecture-fixes-20261004.md) |
| 双端页面 | 身份入口的 `--app --browser-dist=<本轮前端目录>`；历史、告警及导出页面沿用 `tests/iot-history-browser-fixture.php` |
| WAL 与物理恢复 | 独立 `tests/iot-recovery.php`；`--physical` 执行物理链，见[恢复说明](iot-recovery.md)；不使用身份入口的旧 `--recovery` 组合 |

物联应用与独立 Broker 模式不能混用。身份入口在创建运行目录、读取产物或连接数据库前拒绝已退出及尚未接入的专项，避免只执行基础用例后报告组合成功。

## 已退出与待迁移的范围

旧 `/iot/auth`、人员开通及临时支持授权已移除；当前使用独立的管理/客户账号域，以及受控的模拟登录。身份入口原有不可达的旧业务尾部、旧审计浏览器辅助函数和无调用者的支持授权测试已删除。旧入口返回拒绝、伪造身份头被拒绝及模拟来源撤销的用例继续由当前身份、设备和导出测试覆盖。

以下内容保留为专项迁移输入，不能加入通过清单：

| 待处理项 | 当前阻塞 | 完成要求 |
| --- | --- | --- |
| 生命周期浏览器装置 | `tests/iot-lifecycle-browser-fixture.php` / `tests/iot-lifecycle-browser.mjs` 仍使用旧人员初始化、登录和租户接口 | 接入当前双端身份与角色，重验页面凭据清除、冲突、响应未知和窄屏；已有 API/MQTT 结果不能代替浏览器验收 |
| IoT 节点隔离审计组合 | `tests/broker-observability.php --iot-audit-fence` 的辅助断言仍使用旧审计表与已移除的 `iot:audit-clean` | 按 `admin/customer` 审计域和 `app:audit-clean` 更新，保留真实硬隔离依据、未知结果对账和清理断言；独立 Broker 观察入口继续维护 |
| 混合负载 | `tests/iot-load.php` 的历史准备入口及 `tests/iot-load.mjs` 的登录仍依赖旧身份 | 更新人员、角色、租户与令牌取得方式，重新跑同负载；独立样本及资源回归只证明发生器自身行为 |
| 历史 HA/容量组合 | 旧 `--ha`、`--capacity`、`--cluster` 等组合未接入当前身份入口 | 使用当前设备装置保留持久确认、故障域、接管与资源收尾断言，再纳入完整组合验收 |

`tests/docs-consistency.php` 只登记两个待迁移文件中既有旧命令的准确次数。新文件调用旧命令或存量次数改变都会失败；清理后同时移除登记，不能增加按命令名称的全局豁免。检查输出单独显示待迁移夹具数量。

历史源码可从清理前提交 `4fe316fe40db83f1c9fe3d71cc112320abb4f89f` 查阅；原始运行证据保持自己的提交和产物身份。测试清理不将未迁移的义务改成已完成，也不以旧页面或旧人员接口恢复生产兼容。
