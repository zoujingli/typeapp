# 物联网备份与恢复

## 原生 WAL 维护入口

```text
type-app iot:wal archive <私有归档目录> <源文件> <WAL名称>
type-app iot:wal restore <私有归档目录> <WAL名称> <新目标文件>
type-app iot:wal verify <私有归档目录> <WAL名称>
```

维护者预先创建 Linux/macOS 私有归档目录，权限为 `0700`。入口不读取业务配置、不连接数据库，数据库故障不阻止已产生 WAL 的归档。操作成功退出零并输出文件名称、字节数和 SHA-256；`verify` 只输出字节数和 SHA-256。失败退出一，标准错误沿用应用错误前缀，随后是稳定的 `recovery_*` 错误码。

只接受 PostgreSQL 的 WAL segment、timeline history、backup history 和 partial 文件名称。单文件最多 1 GiB，以 64 KiB 缓冲复制，循环预算60秒；归档端共用一个锁，最多等待5秒。原件及摘要分别以私有临时文件写入、`fsync` 后硬链接原子发布，并同步目录项。重试只接受相同字节，冲突不能覆盖已有原件；恢复拒绝缺失、等长损坏、符号链接和已有目标。归档文件的摘要证明字节完整性，独立备份域与可信来源仍须由实际部署提供。

`restore_command` 只可在命令成功退出后使用目标。文件发布后若目录同步失败，命令仍失败，目标可能已经存在；PostgreSQL 负责重试时的临时恢复文件生命周期，维护者不能把文件存在视为成功。私有目录和父目录不得交给不可信用户写入。不得指向在线库的已有 WAL 文件作为手动恢复目标。

## PostgreSQL 物理链

复用 PostgreSQL 17 的 `pg_basebackup --manifest-checksums=SHA256` 创建全量备份；源库启用 `summarize_wal=on`，后续 `pg_basebackup --incremental=<前一份backup_manifest>` 创建增量。使用 `pg_combinebackup --manifest-checksums=SHA256` 按全量至最后增量的顺序输出到新目录。全量与合成目录都必须通过 `pg_verifybackup`，并保存原始清单摘要；只有校验后才修改合成目录的恢复配置，原备份保持原样。

`archive_mode=on` 的 `archive_command` 调用上述 `archive`；`restore_command` 调用 `restore`。PostgreSQL 的 `%p` 通常相对 `PGDATA`，而应用或运行包可能改变工作目录，因此两个命令的源／目标都显式拼接已核验的数据根。命令常量先做 shell 参数转义，其中的百分号转为 `%%`；仅独立的 `%p`、`%f` 保留为 PostgreSQL 占位符。运行包及私有目录位置由维护者配置，不把本机 SDK 路径保存为默认配置。

恢复使用新目录和独立端口，`recovery.signal`、明确的 `recovery_target_name` 以及 `recovery_target_action=pause`。实际观察 `pg_is_in_recovery()` 和 `pg_get_wal_replay_pause_state()`；数据库可查询不代表可以开放设备或控制。先在只读暂停状态核对恢复事实，再由维护者保持网络隔离并按已验证的数据库提升流程准备写入授权核对记录。当前物理链测试在这一检查之后继续验证新同步备库与下述授权流程。

## 备份登记与七天保留清理

```text
type-app iot:backup register <私有归档目录> <备份ID> <PG17工具根>
type-app iot:backup status <私有归档目录>
type-app iot:backup pin <私有归档目录> <备份ID> <保护ID>
type-app iot:backup unpin <私有归档目录> <备份ID> <保护ID>
type-app iot:backup clean <私有归档目录> <PG17工具根> [批次1至100]
```

复用同一个 `RecoveryArchive` 维护者及 WAL 私有根，每个根只接受一个 PostgreSQL system identifier 与 WAL segment size。`incoming/<备份ID>` 由维护者预先创建父目录，使用官方 `pg_basebackup` 完成自包含、plain 格式备份后才执行 `register`。ID 与保护ID均为小写字母开头的1–64位小写字母、数字、下划线或连字符。目录和文件不得开放组或其他用户访问；拒绝 tablespace 符号链接、硬链接、特殊文件和未完成的备份。登记后禁止外部修改 `backups`、`retiring`、持久清单或已归档 WAL；数据根和工具安装位置仅供可信维护者管理。

登记会自己同步备份文件及目录，不能依赖调用者是否省略 `--no-sync`。PG17 `pg_verifybackup --no-parse-wal` 核对 manifest、pg_control、文件完整性，`pg_waldump` 逐 manifest WAL range 核对真实记录，`pg_combinebackup --dry-run` 检查全量至增量的关系。三者各自直接受维护进程管理；不会把 combine 的成功当成文件完整性证明。增量父备份按 `INCREMENTAL FROM LSN/TLI` 精确匹配已登记的起点，缺父链、歧义或不同 system identifier 均拒绝。额外固定 PostgreSQL 校验器忽略的 auto.conf 与恢复信号文件摘要，登记后更改也拒绝清理。

验证完成后，先持久保存 `registering` 意图，再原子移动到 `backups/<ID>` 并同步目录，最后持久标为 `active`。登记时间由实际系统时间生成，作为备份完成的保守上界；同ID重试不刷新该时间，CLI不能自报过去日期。中断时使用原ID重新登记，支持移动前后两种状态；任何未完成登记都会阻止新的清理。

开始增量备份前，必须 `pin` 其父备份；开始合成或恢复前，必须 `pin` 选中的目标。保护自动覆盖全部祖先，直到任务完成后显式 `unpin`；进程崩溃和时钟推进不会自动取消保护。已持久退役的备份不能重新保护或登记，不能直接回改状态来复活。维护者应在每日备份登记后运行清理，并按 `has_more` 继续批次；运行器应记录失败和实际归档端点。

新一轮计划按实际时间保留最近七天内的全部登记项、能够覆盖窗口起点的最新旧备份、显式保护项及这些目标的全部增量父链。`mtime`、文件名日期和单个备库的 `%r` 都不参与日期决策。没有至少七天前已完成的合法锚点时返回 `recovery_backup_window_unavailable`，不提前假定窗口完整。时钟回退至登记时间之前、损坏备份、缺失父链、WAL缺失/损坏或未登记的新timeline都会阻止新删除计划。

WAL保留下界取全部保留备份所需最早段位置，保守保留所有timeline中此位置及之后的归档；所有timeline history永久保留，未知名称与未登记incoming目录不自动删除。计划核对保留备份的真实WAL范围、归档摘要，并沿当前timeline history切点从锚点父链起点解析至最后一个已归档完整段末端。`last_plan.validated_through_lsn` 和 `validated_through_wal` 只表示这次实际验证的端点，不能证明当前RPO或独立故障域。提升后应先归档history与新WAL，并登记新timeline的备份，才允许继续清理。

`backup-catalog.json` 是私有、原子替换并同步落盘的本地状态；固定未发布槽 `.retention-next` 在中断后安全重写。备份维护共用 `.backup.lock`，归档读写继续复用 `.archive.lock`，均最多等待5秒。每份树最多100万条目录项、深度64，manifest最多64MiB，catalog最多8MiB及4096份备份/4096个保护项；新登记或计划验证总预算10分钟，工具输出总量每次至多1MiB。达到资源上限时拒绝继续，不降低校验标准。

只有完整校验成功后才持久保存退役意图。过期备份先移入 `retiring/<ID>`，再每批删除至多100个文件、空目录或WAL对象；WAL对象包含原件与摘要。逐项同步目录，原件已删但摘要尚存等中断状态继续按持久身份核对和收敛。每批在归档锁内重新检查完整WAL、partial、backup history和timeline history，复查、意图发布及本批删除共用该锁；未知timeline即使只有history先到，也暂停尚未执行的删除。归档发布遇到锁超时仍沿用原幂等重试，不静默跳过归档。

timeline不变时，恢复已提交意图的后续批次不重跑全库校验；新timeline备份登记后，重新验证仍为active的完整父链和归档端点，再继续原有退役进度，不能将已部分删除的备份恢复为active。目录归属和不可变约束必须持续成立。`status` 可检查未完成登记、退役和保护，`clean` 返回实际删除对象数及是否还有批次。任何命令失败均不代表没有留下已提交进度，维护者使用相同入口重试。

## 恢复后的授权核对

```text
type-app iot:recovery snapshot
type-app iot:recovery status
type-app iot:recovery begin <恢复ID> <操作人> <已完成隔离的依据>
type-app iot:recovery isolate <恢复ID>
type-app iot:recovery review <恢复ID> <私有清单文件> <已核对SHA256> <偏移量>
type-app iot:recovery restore <恢复ID>
```

旧系统、恢复实例和当前授权来源的隔离由维护者负责。必须先停止旧 HTTP、Broker、接收与后台进程，隔离旧节点及设备网络，确认恢复库仅供维护命令访问。`begin` 的依据是审计引用，不会执行或证明基础设施隔离；启动门也不会主动终止已运行的进程。不能让旧进程与核对流程并发写入。

`snapshot` 从调用者配置的当前授权来源导出四类主体的身份指纹：用户、租户成员、设备和设备凭据。来源须已核实包含备份点之后的变更，并在整个导出和核对期间停止授权写入；导出不是跨库自动一致快照，也不会自动找到独立权威源。不能把待恢复旧库自身的导出当成最新授权证明。没有可靠当前证据时，清单缺失的主体保持隔离。

清单没有密码、凭据原值或原始散列字段，但仍按敏感身份资料保存：属主可访问的普通文件、私有父目录、至多64MiB和20万主体。维护者通过备份之外的受控渠道核对文件精确字节的 SHA-256。`review` 检查路径、打开后文件身份、字节摘要、字段及重复主体；相对清单路径以 `APP_BASE_PATH` 为基准。更换空白也会改变摘要，不能在同一次恢复中替换已固定清单。

从偏移量0调用 `review`，之后使用返回的 `cursor` 继续，直到进入 `restoring`。每批最多核对100条，只有当前证据与旧身份指纹相同的主体才获批准；重复同一摘要和已完成偏移返回现有进度，超前偏移或换清单被拒绝。再逐次调用 `restore`，直到 `ready`；它再次核对当前行，核对期间发生变化的主体仍然隔离。旧登录会话被分批删除，所有旧临时支持授权均撤销，最后记录完成审计。中断后用原恢复ID和原清单继续，不能跳过阶段；新事故保留既有恢复记录。

`ready` 表示核对批次已经结束，不表示所有主体都通过。返回的各类 `total/approved` 和时间用于检查剩余隔离及实际核对耗时。未核对用户不能登录，未核对成员不能进入租户；未核对设备或凭据不能接入，设备不能控制、切换模型或推进归属。设备生命周期仍保持原来的四种状态。管理端在成员、设备与转移页面显示隔离原因；历史读取仍按当前人员权限校验。已核对的租户管理员可通过原成员编辑入口显式重新授权已核对用户，另记 `member.reauthorized` 审计；此操作不能解除用户账户本身的隔离，也不自动恢复设备授权。

维护者检查所有拒绝、剩余隔离和当前身份，再依次开放受控业务角色与网络；启动门数据库不可用时拒绝启动。离线 `check`、迁移、设备端以及受已通过启动门的父角色拥有的内部持久子进程沿用原入口。内部命令、数据库写入和迁移凭据仅供可信维护者及进程所有者使用。

## 已执行验证

从仓库根使用[原生开发环境](native-command.md)中的锁定工具链和真实 PostgreSQL 工具目录：

```sh
php tests/iot-recovery.php --php
php tests/iot-recovery.php build/app/type-app --no-source
php tests/iot-recovery.php build/app/type-app --no-source --physical
php tests/iot-identity-databases.php build/app/type-app "$TYPE_MYSQL_TOOLS" "$TYPE_PGSQL_TOOLS" --no-source --devices --recovery --operations
```

`TYPE_PGSQL_TOOLS` 指向 PostgreSQL 工具根；测试只创建 `build` 下的随机私有目录和受控前台进程。`--no-source` 当前使用 macOS 内核禁读生产与生成源码的策略；其他正式目标仍须各自真实验证。测试用现有公开服务装置建立实际 IoT 数据，再以真实主备、官方备份工具和原生 WAL 命令完成恢复。

## 非空告警、通知与 Broker 恢复事实

MQTT.js 验证 TLS 服务证书，以持久会话发送三次超限及三次恢复采样，每条都等待独立业务持久回执。现有 `iot:alarm` 消费者形成告警与结束事实，管理 HTTP 保存人工确认，`iot:notices` 通过真实 Redis 形成触发和结束两条站内通知。设备断开后，可信服务再发送含 NUL 的 QoS 1 保留消息，分别验证持久订阅、尚未开始发送的 `pending` 投递和保留载荷原字节。`queued` 是现有存储中 QoS 0 的终态，不能用它判断 QoS 1 离线待投递。

备份前先停止接收、Broker、HTTP 与 Redis，核对受管资源和 Broker 数据库后端归零，再记录恢复点。逐表内容身份覆盖 `iot_*`、`type_mqtt_*` 和恢复阶段标记；规则、规则版本、告警状态、告警、通知意图、通知、会话、消息、投递与保留消息必须非空。全量、增量原清单保持原样；实际恢复到只读暂停点后逐表比较全部行的内容摘要，不能用表存在或空表一致替代业务恢复。

## 物理恢复后核对与重新开放

`--physical` 在同一条链中预先登记三台设备：身份保持一致、随后轮换凭据、随后转移归属。第一台在备份前通过正式设备 CLI 取得遥测业务回执并完成 HTTP 指令，保留自己的 SQLite 缓存供恢复后重连。支持授权也在备份前真实生效。固定全量／增量／WAL恢复点之后，管理 HTTP 轮换第二台设备凭据并撤销支持；双方不同管理员审批第三台设备转移，正式设备通过 MQTT 5/TLS 冻结、排空原缓存、切换配置和确认新阶段。网络验收负责旧连接隔离与新归属采样；不通过 SQL 改写归属。

全部业务角色退出后，从仍保有实际最新变更的源主库导出私有当前授权清单并固定 SHA-256，再停止源主备。恢复只读暂停时先逐表比较原恢复点全部事实，确认恢复点之后的变更确实没有混入；随后在受控隔离下提升这一合成目录。测试复用 `PostgresSync` 建立新的真实同步备库，确认 `streaming/sync` 后才执行 `begin/isolate/review/restore`。显式恢复库入口只接受本机 `build` 内已提升主库，备库仍由原有进程所有者负责退出。

核对期间 HTTP 与 Broker 启动须返回 `recovery_isolated`。重新开放时核对旧登录令牌、旧支持、轮换前凭据和原归属凭据的拒绝；未匹配设备的管理端指令沿用 `command_device_not_online` 稳定码，MQTT 返回 `0x86`。已匹配设备沿用备份前自己的缓存序号重新接入，等待新遥测业务回执及授权 HTTP 当前值，再以设备执行回执和本地结果业务确认闭合新的控制。测试最后停止业务角色，比较新主备全部业务表事实，再停止新备库和恢复主库。

报告分别记录只读暂停耗时、授权核对耗时和恢复启动至业务闭环耗时，以及各类剩余隔离和各阶段资源统计。当前权威清单来自真实后续状态，仍不构成独立备份故障域证明；本机小数据计时也不能用于目标量 RPO/RTO。

## 物理恢复后的启动门与授权核对

恢复报告记录隔离、review、restore 共 18 个有界批次，授权状态最终为 `ready`；管理账号、客户账号、平台/租户角色和成员全部按当前清单批准，变更设备 9/11、凭据 11/13 获准。核对期间 `serve` 与 `iot:mqtt` 均拒绝，核对完成后合法设备重新通过 TLS 接入、上报和控制闭环；旧登录会话、旧模拟会话、轮换前凭据和未获准设备继续拒绝。恢复主库和新同步备库事实一致，受管连接、接收队列和临时进程清理失败为零。
