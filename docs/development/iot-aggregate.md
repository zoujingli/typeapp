# 分钟统计、迟到修正与九十天历史

`AggregateService` 复用 `IngestionService::pending/consume` 的独立完成事实，固定消费者名为 `aggregate`；已接收事实和平台持久接收回执不等待本消费者。HTTP与Web扩展[历史入口](iot-history.md)，授权、字段筛选、冻结模型和页面控件沿用现有所有者。

## 消费与统计

`bin/typeapp iot:aggregate [batch]` 每轮读取并处理1至100条未完成事实，默认100；原生产物提供同名角色。每条事实的统计效果及 `aggregate` 完成标记在同一事务提交。返回 `examined`、`completed`、`already_completed`、`has_more`；有剩余时由调用者安排下一批。此前成功批次不因后续失败回滚，失败事实保留待处理，重启继续幂等运行。不在接收事务中运行聚合，不删除或确认 `alarm` 的工作。

窗口为 `[floor(sampled_at/60)*60, 开始+60)`，统一UTC。每个租户、设备、产品、物模型版本、归属阶段和分钟保存一行，行中只保存实际出现的数值/整数属性统计。每个属性保存 `count/min/max/sum/last` 以及last对应的采样时间、十进制序号；查询时 `avg=sum/count`。布尔、字符串、枚举及事件不计算数值统计；没有数值字段的遥测也不创建空分钟行，但仍记录本消费者已经按规则处理完成。

last先比较采样秒，再按十进制序号位数和字典序比较；38位序号不转整数或浮点。合法迟到和乱序可更新仍在保留期的分钟；重复原始身份以及消费者重试不重复累计。聚合仅处理已经接受的不可变事实，不绕过首次接收48小时、未来5秒、模型或归属校验，也不修改当前属性或实时告警。

并发消费先由既有消费接口锁定原始事实，再通过分钟唯一键的无内容覆盖upsert取得分钟行写锁；统计读取、累计和保存串行化。MySQL使用明确的任意唯一键upsert，PostgreSQL/SQLite使用明确冲突目标；SQLite沿用立即事务。不同事实并发命中同一分钟不会丢失更新；原始清理仍固定等待 `current/aggregate/alarm` 全部完成。

累计使用有限浮点数；count保持JavaScript可精确表示的整数范围。超出有限数值或计数边界时明确失败 `aggregate_numeric_overflow`，该事实和未完成标记保留，不写非数值、不伪装完成。此范围不宣称任意精度十进制财务计算。

## 查询与Web

`GET /customer/tenants/{tenant}/devices/{device}/history` 提供两个分钟视图：

| 参数 | 行为 |
| --- | --- |
| `view=minutes` | 分页分钟行，包含各数值属性六项统计、窗口起止、最近修正时间、冻结模型和归属。 |
| `view=minute_curve` | 固定产品、模型、归属和数值属性的一条统计曲线。 |
| `stat=count|min|max|sum|avg|last` | 分钟曲线选用的统计，默认avg；其他视图不接受此参数。 |

查询需要当前租户的 `customer.telemetry.read` 和一致租户头；平台身份不能代替成员授权，模拟登录只采用目标客户的当前权限。原始事实被物理清理后，保留分钟数据仍能证明旧租户历史归属，设备转移不会把历史给新租户。模型变更、单位变更和类型变更分别保存，禁止直接合并。

默认单设备最近24小时，每页20、最大100。分钟列表允许 `sampled_asc`、`sampled_desc`，用窗口开始与ID键集翻页；游标固定输入范围、筛选、页大小和排序，有效15分钟。它不持有跨请求数据库快照；迟到修正可更新已有行，新增窗口或到期回收可改变总数。相同记录不因统计修正改变排序键。

`from/to` 为Unix秒，单次最多90天；视图返回 `window.start/end`，覆盖输入起止秒所在的完整UTC分钟，end不包含在窗口内。未结束分钟为当前已处理的部分结果，已结束分钟也可因合法迟到修正。只有 `window_end > 当前时间-90天` 的统计可见。

分钟曲线必须指定 `product_id/model_version/ownership_id/field`。返回实际 `granularity_seconds`，为 `60*ceil(范围分钟数/2000)`；最多2000个桶。数据库合并各分钟的sum/count/min/max，avg仍为总sum/总count，last由窗口内最新采样秒及序号选取，不能平均各分钟avg。每个点返回所选 `value` 和完整 `statistics`；缺测两者为null，`count=0` 仅表示点没有有效样本，count曲线也不画零。完整分钟行仍可分页查看。

Web在原历史页增加“数据类型”、最近24小时/7天/90天入口和统计函数，沿用四字标签、统一列宽、行操作、只读详情抽屉和内部滚动曲线。详情显示全部六项统计及last依据；count图明确使用样本数单位。双时间与窗口按用户时区显示，长名称换行。等待防重复、错误恢复、切换租户和权限撤销清理沿用同一请求层。

## 迁移、资源与清理

全新应用通过 `app:install` 创建分钟表及设备时间、模型归属时间、窗口到期索引，不提供旧业务数据迁移。每设备分钟一行的口径与每属性一行不同；字段最多64个，来自物模型上限。每条消费最多一次初始upsert和一次分钟更新，SQL绑定最多10个；不按积压规模构造参数列表。列表最多读取101行，曲线数据库最多返回2000行，应用补齐缺测仍不超过2000点。

`bin/typeapp iot:aggregate-clean [batch]` 默认及最大1000，一次事务仅删除 `window_end <= 当前时间-90天` 的分钟行；有剩余返回 `has_more`，中断回滚，重启幂等。原始事实、当前投影、物模型及其他消费者有独立生命周期，不随分钟清理删除。长期停机后才消费、且分钟已经超出90天的事实按保留策略处理完成，不重新创建已到期统计。

## 验收入口

```bash
php vendor/bin/type docs/build-config/type-app.json
php tests/iot-identity-databases.php --php "$TYPE_MYSQL_TOOLS" "$TYPE_PGSQL_TOOLS" --devices --aggregate
php tests/iot-identity-databases.php build/app/type-app "$TYPE_MYSQL_TOOLS" "$TYPE_PGSQL_TOOLS" --devices --aggregate --no-source
```

浏览器复用 `tests/iot-history-browser-fixture.php`，追加 `--aggregate`，再执行 `node tests/iot-aggregate-browser.mjs <fixture.json> <Web地址>`。装置通过真实接收服务准备2053条事实，由同一原生角色分批计算并物理清理两条旧原始事实，页面经原生HTTP验证89天分钟仍可读取。装置显式准备无告警规则的完成记录，不据此宣称告警实现完成。

三库用例包含六项统计、分钟边界、同秒38位序号、迟到/重复、加权降密度、2001个合法分钟分21批、90天边界、单位/类型变更、权限及旧租户归属。两个真实工作进程争用写锁并中断后恢复；清理在第二行锁等待处被终止，验证第一行删除也回滚。
