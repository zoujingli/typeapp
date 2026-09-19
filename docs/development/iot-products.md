# 产品与不可变物模型

## 新应用客户权限（）

产品与物模型入口为 `/customer/tenants/{tenant}/products`，继续要求 `X-Tenant-Id` 与路由租户完全一致。`customer.products.read` 允许查询、精确版本与模型值校验；`customer.products.manage` 允许产品和草稿维护及发布。固定菜单仅随查询节点展示，维护节点不由菜单或角色名称推断。新初始化复用原产品表声明，不创建旧人员或支持身份表。

`ProductService` 保留产品、草稿、发布、版本分页与 `publishedModel()` 契约，改用客户当前成员角色及可信会话事实；授权写入与账号/角色/模拟来源撤销共用 `RoleService::authorized()` 的现有安装行锁，在取得锁后重验准确会话与来源。产品编辑不再推进租户资料版本。成功变更及真实来源审计同事务写入 `customer_audit`，审计失败整体回滚；跨租户资源统一返回不存在，无成员或缺节点返回拒绝。

前端复用产品表格、抽屉及模型编辑器，查询结果更新当前权限和菜单；切换身份或租户取消旧请求，撤权清除旧产品、模型和操作。未知查询字段（包括不支持的游标）明确拒绝，列表使用有界页码；接口没有隐式跨租户批量选择。业务载荷中的租户字段不能覆盖路由归属。

`tests/iot-identity.php <产物或--php> <驱动> --products` 已切到新安装/双端认证装置，复用 `tests/iot-products.php` 的原模型行为场景；三库使用 `tests/iot-identity-databases.php <产物> <MySQL工具根> <PostgreSQL工具根> --products`。适用时加 `--no-source`，浏览器沿新应用装置的 `--browser-dist=<隔离产物>`。旧设备、接收、HA及分发组合尚待各票迁移，组合不匹配会明确失败，不能跳过旧场景后冒称通过。设备模型切换与历史解释仍由原业务所有者负责， 的设备回执及完整验收责任保留。

## HTTP 契约

所有路径以 `/customer/tenants/{tenant}/products` 为前缀，实体响应为 `{data: ...}`，列表为 `{items,total,page,per_page,context}`。列表默认20、最多100条；错误保留真实HTTP状态。标识为32位小写十六进制，时间为UTC Unix秒。

| 方法及路径 | 输入和行为 |
| --- | --- |
| `GET /` | `name,page,per_page` 查询当前租户产品。 |
| `POST /` | `{name,description?}` 创建产品，返回201。 |
| `GET /{product}` | 查询当前产品。 |
| `PATCH /{product}` | `{name,description,version}` 更新名称说明，旧版本返回409。 |
| `DELETE /{product}` | `{version}`；已有已发布模型时返回409 `published_model_retained`。 |
| `GET /{product}/models` | `page,per_page` 查询版本，按 `model_version` 倒序；含完整定义。 |
| `POST /{product}/models` | `{definition}` 新建下一编号草稿，返回201；从旧版本修改时复制选中定义再新建。 |
| `GET /{product}/models/{model}` | 查询精确版本，`model` 是正整数。 |
| `PATCH /{product}/models/{model}` | `{definition,version}` 替换草稿定义。 |
| `DELETE /{product}/models/{model}` | `{version}` 删除草稿；已发布版本返回409 `model_immutable`。 |
| `POST /{product}/models/{model}/publish` | `{version}` 发布草稿，至少定义一项属性、事件或指令。 |
| `POST /{product}/models/{model}/validate` | `{kind,identifier?,values}` 只校验已发布精确版本；`kind` 为 `properties` / `event` / `command`，后两者必须给 `identifier`；返回 `{data:{valid:true,model_version}}`，不产生设备动作。只需查看权限。 |

产品返回 `id,tenant_id,name,description,version,created_at,updated_at`。模型返回 `product_id,tenant_id,model_version,version,status,definition,structure_hash,created_at,published_at`；草稿 `published_at=null`。`model_version` 是永久版本标识，`version` 是草稿编辑/发布乐观锁，二者不能混用。编号不因删除草稿复用。已发布版本不能修改或删除；新草稿、产品改名均不影响旧定义。

## 设备确认切换版本

模型切换复用 /  的设备确认流程。`POST /customer/tenants/{tenant}/devices/{device}/model-switches` 接受 `{switch_id,model_version,version,retry?}`，返回202和切换事实；`switch_id`为调用者生成的32位小写hex，`version`为准确设备版本，`retry`默认false。独立权限 `customer.devices.model-switch` 允许发起；已启用、有效凭据且无待执行撤权的设备可离线受理，同一设备只允许一个待确认切换。同ID确认须保持设备、准确会话及模拟来源、原请求版本和目标不变，重复HTTP请求返回原事实。普通平台资产身份不能绕过客户授权。

目标须为同产品已发布且非当前版本。受理后绑定不变，详情`model_switch`显示原意图；暂停新控制和旧指令重投，同归属的已有指令结果查询仍可继续。设备明确支持并持久切换后，平台收到合法回执才原子更新`model_version`、`model_start_sequence`并清除待确认标识。拒绝仅结束该请求、保留原绑定；已发布定义始终不改写。停用、退役、待撤权或无有效凭据时不会继续切换，也不会把此前可能已在设备生效的意图假装取消。

`GET`同路径通过 `customer.devices.read` 查看`items,total,page,per_page`，默认20、最多100条。记录有原/目标版本、结构比较、请求/发送/确认时间、次数、边界序号和明确结果；超时状态由服务端计算。详情使用公共抽屉、模型版本分页、五秒轮询及请求取消；未知HTTP受理锁定原ID和目标，刷新失败保留已有事实。请求、实际发送、设备确认/拒绝各保留脱敏审计。

领取时重新检查当前客户权限及真实管理来源，发送期限不超过会话期限。来源撤销且尚未领取时终止请求并解除模型冻结；已有发送领取则停止新发送、保留未知和冻结，迟到设备确认仍可对账。获准人员人工重试使用准确当前设备版本，保留原切换标识和目标，并登记本次发送来源。

`ModelDefinition::structuralHash(array): string`和`structurallyEqual(array,array): bool`是后续转移可复用的公开行为，输入仍执行完整模型校验。摘要按属性/事件/指令及参数标识比较，忽略显示名称、声明顺序、枚举排列、等价数值表示及默认空单位/字符串长度；类型、单位、必填、数值范围、字符串长度和枚举集合参与。产品同名或版本号相同不能证明结构相同。HTTP返回的`structure_hash`与该公开接口相同。

新应用通过 `app:install` 初始化模型切换结构，设备缓存沿用自身迁移。旧版本首次迟到消息只接受其真实绑定过的序号区间`source_start < sequence <= boundary_sequence`，沿用原归属及48小时/5秒时钟规则；当前版本只以本次区间序号推进当前字段与缓存观察。回切同一旧版本也不复活早先区间当前值。旧重复仍按七天账本确认，不重复产生历史或聚合；历史/分钟统计继续按不可变的产品、版本、归属解释，不把摄氏、华氏或枚举混算。

HTTP JSON 最多16 KiB、12层；重复键拒绝。应用传输解析总字段预算为2048，以支持完整模型；产品控制器继续执行更小的字节及深度限制。模型定义最多64项属性、32项事件、32项指令，每个事件或指令最多32个参数，总体仍受字节和字段预算约束。任何未声明定义字段、重复标识、无效类型、反向范围均返回422及 `fields` 内字段路径。发布后内容和单位按精确版本读取，设备注册只能绑定已发布版本，历史数据也必须保存该绑定。

## 定义与校验

顶层 `properties,events,commands` 均为必填列表。属性及参数拥有 `identifier,name,type,required`，标识为字母起始的1至64位ASCII字母、数字或下划线；`name` 为1至100字节文本，`required` 为布尔值。类型为 `integer,number,boolean,string,enum`；整数和数值允许可选 `unit,min,max`，字符串允许可选 `min_length,max_length`（UTF-8字节数，默认0至1024，最大4096），枚举必须给1至100个互异字符串 `values`，单值最多100字节。禁止隐式把字符串变成数值或布尔值。整数字段限JSON可精确交换的安全整数范围。

事件和指令拥有 `identifier,name,parameters`。同一列表内标识唯一，不跨属性、事件、指令强行合并命名空间。校验对象不允许未知字段，`required=true` 的字段不可缺失，所有显式值都不可为null。属性上报至少包含一个定义内属性；事件或指令可按其参数定义传空对象。事件和指令校验使用各自参数定义。

```json
{
  "properties": [
    {"identifier":"temperature","name":"温度","type":"number","required":true,"unit":"°C","min":-40,"max":125},
    {"identifier":"relay","name":"继电器","type":"boolean","required":false},
    {"identifier":"mode","name":"运行模式","type":"enum","required":false,"values":["auto","manual"]},
    {"identifier":"firmware","name":"固件版本","type":"string","required":false,"max_length":100}
  ],
  "events": [
    {"identifier":"fault","name":"故障事件","parameters":[{"identifier":"code","name":"故障代码","type":"integer","required":true,"min":1,"max":999}]}
  ],
  "commands": [
    {"identifier":"set_target","name":"设置目标","parameters":[{"identifier":"target","name":"目标温度","type":"number","required":true,"unit":"°C","min":0,"max":100}]}
  ]
}
```

`ProductService::publishedModel()` 按租户、产品、版本读取发布事实，供设备注册及业务消息入口复用；调用方负责设备或人员授权，不能用用户传来的租户替代已认证设备归属。`ModelDefinition::validateValues()` 执行同一值校验。涉及注册与业务写入时，在调用者事务里查到模型并保存精确版本引用，不能使用最新草稿作为缺省值。

操作与模型变化在同一事务中写入脱敏审计。授权拒绝、越租户资源ID和版本冲突按当前请求上下文记录，不泄露另一租户产品；审计不记录完整模型或参数载荷。

## 验证入口

使用项目锁定 SDK 的 PHP CLI，并确认加载 `pdo_mysql,pdo_pgsql,pdo_sqlite`。数据库工具根由当前环境显式提供，装置只创建 `build` 下的新实例。产品用例复用身份装置的登录、迁移、真实HTTP、实例退出及无源码隔离，不直接调用私有方法或把SQL布局作为产品行为断言。

```sh
php tests/iot-identity.php --php sqlite --products
php tests/iot-identity-databases.php --php <MySQL工具根> <PostgreSQL工具根> --products
php vendor/bin/type docs/build-config/type-app.json
php tests/iot-identity-databases.php build/app/type-app <MySQL工具根> <PostgreSQL工具根> --products
php tests/iot-identity-databases.php build/app/type-app <MySQL工具根> <PostgreSQL工具根> --products --no-source
```

用例涵盖真实持久化、权限撤销、跨租户ID与列表、草稿乐观冲突、发布后所有修改入口、旧版本单位及值校验、空定义发布、版本删除不复用、事件/指令参数、重复JSON键、64项属性和16 KiB边界、分页、脱敏审计，以及停止后重新启动应用仍可读取同一历史定义。三库清理记录数据库进程正常退出；无源码模式搬迁产物并以系统策略禁止读取应用、组件、依赖、配置和生成源码。
