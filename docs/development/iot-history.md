# 原始历史、显示曲线与七天回收

复用 `IngestionService` 保存的首次接收账本、不可变事实和冻结物模型；`HistoryService` 拥有查询和回收，`DeviceController` 负责严格HTTP输入，`RoleService` 检查当前客户权限。Web沿用[Vben页面标准](../standards/iot-web.md)。全新应用模式由 `app:install` 初始化，不迁移旧业务数据。

## 查询契约

`GET /customer/tenants/{tenant}/devices/{device}/history` 需要客户 Bearer 令牌、一致的 `X-Tenant-Id` 和当前租户的 `customer.telemetry.read`。同设备的 `/current` 返回当前投影，也只要求遥测查看权限，不额外捆绑设备资产或控制权限。平台管理员身份不能代替租户权限；模拟登录采用有效客户权限。设备转移后，原租户仍可读取属于本租户的原始事实，新租户不会继承这些事实；直接设备标识、范围、批量和游标均不能绕过每次授权。

默认只读单设备最近24小时遥测，按采样时间降序，每页20条，最多100条。`from`、`to` 为UTC Unix秒且包含端点；单次跨度最多9天，以容纳七天保留期内最多48小时延迟的采样。结束时间不得超过当前时间5秒。原始事实只在 `received_at > 当前时间 - 7天` 时公开，清理因待消费而延后的过期事实也不会继续出现在查询中。

筛选白名单为 `from`、`to`、`product_id`、`model_version`、`ownership_id`、`field`、`sort`、`page`、`per_page`、`cursor`、`view`。其中 `field` 筛选确实带该属性的原始上报。未知参数、重复参数、非法标识符、时间范围、排序及越界页明确失败。字符串、布尔、枚举、长文本和38位业务序号保持原类型；详情带有每条记录当时的物模型、单位、产品、归属阶段、采样时间和首次接收时间。

这里的边界是接收时间上界，不是跨请求持有的数据库快照；到期清理或晚提交的旧接收事务可能改变保留总数。分页不使用OFFSET，也不承诺可回放任意旧数据库视图。游标只是分页条件，不能替代重新授权或租户条件。

## 曲线与Web

`view=curve` 必须指定当时的 `product_id`、`model_version`、`ownership_id` 和数值/整数 `field`。同一曲线不合并产品、模型版本或归属，非数值属性仍能通过原始详情读取。

不超过2000条范围内上报时按采样时间、十进制序号、消息ID返回原始点，省略该属性的上报值为null。超过2000条时，数据库按 `ceil((to-from+1)/2000)` 秒分桶计算显示均值；返回 `granularity_seconds`、`function=display_average`、原始上报总数、每桶有效数值数量和至多2000个点。无有效数值的桶为null，不补零、不插值；分桶只是本次显示，不写回原始事实，也不代替的分钟聚合。SQL分别使用三库真实JSON和数值转换语义，应用层不加载全部原始行；列表查询最多101行，单条查询最多12个绑定参数。

Web提供独立“历史数据”菜单和设备列表“历史”入口。默认24小时，四字筛选标签、共享搜索网格、标准行操作、固定列宽和只读详情抽屉保持一致。从某条记录选择“曲线”自动锁定当时产品、模型和归属，再选择该模型的数值属性。浏览器按用户当前时区显示双时间及坐标，页面注明时区与实际粒度；窄屏表格和曲线内部滚动，长文本换行并按文字显示。请求等待禁用重复查询，离开、切换租户和权限撤销会取消并清空旧结果；失败明确提示，可修正条件后重试。

## 有界清理

生产命令为 `bin/typeapp iot:history-clean [batch] [cursor]` 或原生产物的同名角色。默认及最大批次1000，最小1；它只执行一次事务并输出JSON，不创建数据库、不在进程内无限循环。沿用已有连接管理器及作用域生命周期。

`HistoryService::prune(Connection, int $batch, string $cursor = '')` 固定等待 `current`、`aggregate`、`alarm` 三个消费者的完成事实。accepted账本必须有原始事实且三者均完成；缺少任意一个返回blocked并保留账本、原始事实和已完成标记。拒绝账本从自己的首次接收起七天到期，不等待原始消费者。没有HTTP清理入口，也没有删减消费者集合的参数。

## 可复现验证

安装仓库锁定依赖与TypePHP工具链后，先使用 `php vendor/bin/type docs/build-config/type-app.json` 全量构建。三库验证复用隔离数据库装置：

```bash
php tests/iot-identity-databases.php --php "$TYPE_MYSQL_TOOLS" "$TYPE_PGSQL_TOOLS" --devices --history
php tests/iot-identity-databases.php build/app/type-app "$TYPE_MYSQL_TOOLS" "$TYPE_PGSQL_TOOLS" --devices --history --no-source
```

`--no-source` 在macOS通过内核规则禁止读取生产、插件、vendor、配置与编译器源码；HTTP查询和清理均由打包后的实际原生入口执行。`tests/iot-history.php` 用SQL准备历史事实及真实行锁故障，不能据此宣称执行了MQTT同步接收；浏览器装置的2051条上报则经过公开 `IngestionService::accept` 校验。同步MQTT接收、去重和回执另见[接收说明](iot-ingestion.md)及证据。

浏览器先在独立端口启动 `php tests/iot-history-browser-fixture.php build/app/type-app <HTTP端口>`，记录输出的 `fixture.json`；从Web应用目录以 `IOT_API_ORIGIN` 指向该端口运行生产预览，再执行 `node tests/iot-history-browser.mjs <fixture.json> <Web地址>`。装置只管理自己启动的服务，结束后对装置输出的PID发送SIGTERM并停止本次预览。相关路径均由仓库位置或显式参数解析。

本记录不声明Linux/Windows原生、容量压测、完整设备转移操作、分钟聚合和告警消费者、MQTT集群或自动运维调度已完成。未部署或公开发布本地产物。
