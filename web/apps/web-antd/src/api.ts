import { reactive } from 'vue';
import { RequestClient } from '@vben/request';
import { switchPreferencesScope } from './preferences';
import axios from 'axios';

/** 租户成员角色；平台权限仍以服务端返回的权限节点为准。 */
export type Role = 'admin' | 'operator' | 'readonly';
/** 区分客户、平台管理与独立 Broker 的登录域和令牌存储。 */
export type Realm = 'admin' | 'customer' | 'broker';
/** 认证后的账号摘要；可选字段由登录域决定，不能据此跳过服务端授权。 */
export interface User { id: string; login: string; name: string; realm?: Realm; platform_admin?: boolean; version?: number }
/** 租户管理员同时持有账号与成员关系版本，变更时使用对应版本。 */
export interface TenantAdministrator extends User { member_id: string; member_version: number }
/** 服务端获准的导航树；页面显示不替代接口鉴权。 */
export interface Menu { name: string; path: string; icon: string; order: number; redirectPath?: string; children?: Menu[] }
/** 站点主题默认值；个人偏好仅覆盖允许调整的展示字段。 */
export interface SiteTheme { mode: 'light' | 'dark' | 'auto'; colorPrimary: string; radius: string }
/** 已接入管理界面的布局选项，不包含认证和业务配置。 */
export interface SitePreferences {
  layout: 'sidebar-nav' | 'mixed-nav' | 'header-nav';
  sidebar: { collapsed: boolean };
  navigation: { styleType: 'plain' | 'rounded'; split: boolean };
  breadcrumb: { enable: boolean; showIcon: boolean; styleType: 'normal' | 'background' };
  tabbar: { enable: boolean; styleType: 'brisk' | 'card' | 'chrome' | 'plain' };
  footer: { enable: boolean; fixed: boolean };
}
/** 登录前可读取的站点公开设置，不承载密钥。 */
export interface PublicSiteSettings { name: string; official_url: string; description: string; logo_url: string; timezone: string; theme: SiteTheme; preferences: SitePreferences }
/** 带并发版本和权限目录的站点编辑结果。 */
export interface AdminSiteSettings extends PublicSiteSettings { version: number; updated_at: number; permissions: string[]; catalog: Record<string, string>; menus: Menu[]; changed?: string[] }
/** 当前登录、原始操作者与模拟身份的绑定；key 用于隔离未决操作。 */
export interface IdentityContext { realm: Realm; actor_id: string; actor_realm: Realm; actor_name: string; customer_id: string; session_id: string; source_session_id: string; impersonation_id: string; tenant_id: string; expires_at: number; key: string }
/** 当前账号可选择的租户摘要；role 为 null 时不能推定成员授权。 */
export interface Tenant { id: string; name: string; role: Role | null; version: number }
/** 平台维护的租户资料；管理员清单仅在相应查询中返回。 */
export interface WorkspaceTenant { id: string; name: string; enabled: number; version: number; created_at: number; administrators?: TenantAdministrator[] }
/** 单个审计事件；关联标识缺失与明确为空均保留服务端原值。 */
export interface Audit { id: string; action: string; actor_id: string; subject_id: string; result: string; details: Record<string, unknown>; created_at: number; tenant_id?: string | null; operation_id?: string | null; request_id?: string | null; stage?: string | null; category?: string; audit_realm?: 'admin' | 'customer' }
/** Broker 操作的授权、目标代次、影响确认和阶段证据。 */
export interface BrokerAuditOperation {
  operation_id: string; mode: 'sync' | 'async'; origin_request_id: string; actor_id: string; tenant_id: string | null; action: string; subject_id: string;
  authorization: { source: string; role: string; permissions: string[]; required_action: string; decision: string; support_id: string | null; support_version: number | null; support_expires_at: number | null };
  target: { kind: string; node_id: string; node_run_id: string; observation_run: string; generation: number };
  impact: { confirmed: boolean; effect: string; target_count: number; proof_hash: string };
  current_stage: string; current_result: string; version: number;
}
/** 按不透明游标读取审计事件，total 为 null 表示不提供总数。 */
export interface BrokerAuditPage { items: Audit[]; next_cursor: string | null; has_more: boolean; total: null; limit: number }
/** 已找到的审计详情；操作关联可能尚无对应记录。 */
export interface BrokerAuditDetail { found: true; item: Audit & { operation: BrokerAuditOperation | null } }
/** 服务端确认的当前租户成员角色及权限集合。 */
export interface TenantContext { tenant: Tenant; role: Role; permissions: string[] }
/** 按页码返回的列表；总数语义由各接口的数据快照约束决定。 */
export interface Page<T> { items: T[]; total: number; page: number; per_page: number }
/** 租户内产品资料；version 用于乐观并发变更。 */
export interface Product { id: string; tenant_id: string; name: string; description: string; version: number; created_at: number; updated_at: number }
/** 物模型标量及校验边界；未声明的上下限保持缺失。 */
export interface ModelScalar { identifier: string; name: string; type: 'integer' | 'number' | 'boolean' | 'string' | 'enum'; required: boolean; unit?: string; min?: number; max?: number; min_length?: number; max_length?: number; values?: string[] }
/** 事件或指令的稳定标识与有序参数定义。 */
export interface ModelOperation { identifier: string; name: string; parameters: ModelScalar[] }
/** 同一模型版本的属性、事件和指令结构。 */
export interface ModelDefinition { properties: ModelScalar[]; events: ModelOperation[]; commands: ModelOperation[] }
/** 草稿或已发布模型；结构摘要用于版本切换和设备转移核对。 */
export interface ModelVersion { tenant_id: string; product_id: string; model_version: number; version: number; status: 'draft' | 'published'; definition: ModelDefinition; structure_hash: string; created_at: number; published_at: number | null }
/** 基于观察时间的连接状态；unknown 不等于离线。 */
export interface DeviceConnection { status: 'online' | 'offline' | 'unknown'; observed_at: number | null; broker_observed_at: number | null }
/** 设备授权动作的执行进度，pending 时不能声称撤权已完成。 */
export interface DeviceAuthorization { status: 'pending' | 'enforced'; id?: string | null; requested_at?: number | null; completed_at?: number | null; node_id?: string | null }
/** 设备业务生命周期与连接观察分开记录，并绑定具体归属和模型版本。 */
export interface Device { id: string; tenant_id: string; product_id: string; model_version: number; ownership_id: string; name: string; lifecycle: 'inactive' | 'enabled' | 'disabled' | 'retired'; connection: DeviceConnection; authorization?: DeviceAuthorization; recovery_verified?: number; version: number; created_at: number; updated_at: number; transfer_id?: string | null; transfer_frozen?: number }
/** 设备转移后需要交付的新归属配置，与新凭据一起完成激活。 */
export interface TransferProvision { app_version: number; type: string; device_id: string; transfer_id: string; source_ownership_id: string; ownership_id: string; tenant_id: string; product_id: string; model_version: number; structure_hash: string }
/** 跨租户转移全过程；冻结、隔离、激活及设备确认分别记录，不以审批代表完成。 */
export interface DeviceTransfer { id: string; device_id: string; device_name: string; source_tenant_id: string; target_tenant_id: string; ownership_id: string; source_product_id: string; source_model_version: number; source_definition: ModelDefinition | null; structure_hash: string; source_actor_id: string; created_at: number; status: 'requested' | 'rejected' | 'cancelled' | 'frozen' | 'isolating' | 'activating' | 'completed'; decision_id: string | null; decision_actor_id: string | null; request_version: number; device_version: number | null; switch_version: number | null; decided_at: number | null; target_product_id: string | null; target_model_version: number | null; attempt_count: number; last_attempt_at: number | null; device_status_at: number | null; device_status: { supported: boolean; pending_count: number; pending_command_receipts: number; unresolved_commands: number; boundary_sequence: string; model_pending: boolean } | null; unresolved_commands?: number; expired_uncertain_commands?: number; pending_reasons: string[]; ready_for_switch: boolean; switch_id: string | null; new_ownership_id: string | null; isolation_requested_at: number | null; isolated_at: number | null; activated_at: number | null; completed_at: number | null; provisioning: TransferProvision | null; credential?: DeviceCredential | null }
export const transferStatusNames: Record<DeviceTransfer['status'], string> = { requested: '等待目标审批', cancelled: '已取消', rejected: '已拒绝', frozen: '已冻结，待切换', isolating: '旧授权隔离中', activating: '新归属待设备确认', completed: '转移完成' };
export const transferPendingNames: Record<string, string> = { recovery_not_verified: '恢复后尚未核对设备当前授权与归属', target_approval_required: '等待目标租户管理员审批', device_unavailable: '设备生命周期或归属不可用', device_not_online: '设备尚未在线确认', device_confirmation_required: '等待设备持久冻结确认', cache_state_unknown: '设备未报告待排空缓存', device_confirmation_stale: '设备确认已过期，等待新报告', device_model_not_ready: '设备尚未明确支持目标结构', cache_not_drained: '旧阶段上报仍待取得平台回执', device_commands_unresolved: '设备执行结果或回执尚未对账', commands_unresolved: '平台仍有未完成或未知指令', authorization_not_ready: '凭据不可用或撤权尚未完成', old_authorization_isolation_pending: '等待旧连接、会话和跨节点投递隔离；旧凭据已撤销', target_activation_required: '旧授权已隔离，等待目标管理员激活新归属', new_device_confirmation_required: '新归属已激活，请交付新配置和凭据，等待设备确认；超时仍保持待处理' };
/** 设备管理接口支持的明确动作，页面按授权及当前状态筛选。 */
export type DeviceAction = 'update' | 'rotate' | 'revoke' | 'disable' | 'enable' | 'retire';
/** 设备管理详情中的凭据可用性和可选租户信息。 */
export interface DeviceManagement extends Device { credential_active: boolean; tenant_name?: string; tenant_enabled?: number }
/** 设备变更后的状态；只有签发或轮换时才可能带回新凭据。 */
export interface DeviceChange { device: DeviceManagement; credential: DeviceCredential | null }
/** 设备获准发布和订阅的主题，由服务端生成。 */
export interface DeviceTopics { publish: string; subscribe: string }
/** 单个属性最后采纳的值和序号，不将其他字段的更新视为本字段更新。 */
export interface CurrentField { identifier: string; value: number | string | boolean; sequence: string; sampled_at: number; received_at: number }
/** 平台对一条上报的业务接收结果，与 MQTT 传输回执分开。 */
export interface IngestionReceipt { sequence: string; status: 'accepted' | 'rejected'; code: string; received_at: number }
/** 设备报告的缓存容量和累计计数；freshness 表示该报告的新鲜度。 */
export interface DeviceBufferStatus { sequence: string; sampled_at: number; received_at: number; freshness: 'fresh' | 'stale'; values: { maximum_records: number; maximum_bytes: number; pending_count: number; pending_bytes: number; not_admitted: number; exception_count: number; accepted_total: number; rejected_total: number; exceptions_dropped: number; full: boolean; capacity_reason: '' | 'records' | 'bytes'; counters_saturated: boolean } }
/** 当前值与实时样本分开呈现；empty、stale 和缺失值不能补零。 */
export interface DeviceCurrent { model: ModelVersion; device_id: string; ownership_id: string; model_version: number; sequence: string | null; sampled_at: number | null; received_at: number | null; freshness: 'empty' | 'fresh' | 'stale'; realtime_sequence: string | null; realtime_sampled_at: number | null; realtime_received_at: number | null; fields: CurrentField[]; buffer: DeviceBufferStatus | null; last_receipt: IngestionReceipt | null }
/** 模型切换请求与设备确认边界；超时的 unconfirmed 仍需核对原请求。 */
export interface ModelSwitch { id: string; request_version: number; retry_version: number | null; device_id: string; source_version: number; target_version: number; source_start: string; structure_hash: string; same_structure: boolean; created_at: number; last_attempt_at: number | null; attempt_count: number; status: 'pending' | 'confirmed' | 'rejected'; state: 'pending' | 'unconfirmed' | 'confirmed' | 'rejected'; result_code: string | null; boundary_sequence: string | null; confirmed_at: number | null }
/** 设备详情包含当前模型、主题和仍需关注的模型切换。 */
export interface DeviceDetail extends DeviceManagement { model: ModelVersion; topics: DeviceTopics; model_switch: ModelSwitch | null }
/** 告警边界及迟滞值；null 表示该方向未设置阈值。 */
export interface AlarmDefinition { name: string; lower: number | null; upper: number | null; hysteresis: number; enabled: boolean; property: ModelScalar }
/** 绑定归属、模型与序号区间的告警规则版本。 */
export interface AlarmRule { id: string; device_id: string; ownership_id: string; product_id: string; model_version: number; field: string; version: number; rule_version: number; definition: AlarmDefinition; starts_after: string; ends_after: string | null; published_at: number; retired_at: number | null; end_reason: string | null; trigger_count: number; recovery_count: number; active_alarm_id: string | null; finalized: number }
/** 触发或恢复告警的原始样本证据。 */
export interface AlarmSample { message_id: string; sequence: string; sampled_at: number; received_at: number; value: number }
/** 一次告警从触发到结束的状态；人工确认与数值恢复分别记录。 */
export interface Alarm { id: string; device_id: string; ownership_id: string; product_id: string; model_version: number; field: string; rule_id: string; rule_version: number; definition: AlarmDefinition; status: 'active' | 'ended'; trigger: AlarmSample; recovery: AlarmSample | null; created_at: number; ended_at: number | null; end_reason: string | null; rule_retired_at: number | null; acknowledged_by: string | null; acknowledged_at: number | null }
/** 告警通知摘要，确认状态沿用对应告警而非仅表示消息已读。 */
export interface Notice { id: string; alarm_id: string; kind: 'triggered' | 'ended'; created_at: number; alarm_status: Alarm['status'] | null; alarm_end_reason: string | null; acknowledged_by: string | null; acknowledged_at: number | null; payload: { device_id: string; name: string; rule_id: string; rule_version: number; occurred_at: number; end_reason: string | null } }
export const alarmEndReasons: Record<string, string> = { value_recovered: '数值恢复', rule_changed: '规则变更', rule_disabled: '规则停用' };
/** 只渲染已设置的告警方向，保留严格小于或大于的阈值语义。 */
export function alarmBounds(definition: AlarmDefinition) { return [definition.lower === null ? '' : `数值 < ${definition.lower}`, definition.upper === null ? '' : `数值 > ${definition.upper}`].filter(Boolean).join(' 或 '); }
/** 同一指令的一次发送或结果查询记录，传输与设备响应时间分开。 */
export interface CommandAttempt { id: string; kind: 'send' | 'query'; trigger_kind: 'automatic' | 'manual'; actor_id: string; scheduled_at: number; claimed_at: number | null; transport_at: number | null; mqtt_reason: number | null; state: string; response_at: number | null; response_code: string | null }
/** 指令受理、传输、执行与结果对账状态；unknown 不能展示为执行失败。 */
export interface DeviceCommand { id: string; request_version: number; cancelled_at: number | null; cancelled_by: string | null; dispatch_stopped_at: number | null; dispatch_stop_reason: string | null; device_id: string; ownership_id: string; model_version: number; identifier: string; values: Record<string, number | string | boolean>; accepted_at: number; deadline_at: number; dispatch_at: number | null; mqtt_at: number | null; mqtt_reason: number | null; device_received_at: number | null; execution: 'pending' | 'unknown' | 'succeeded' | 'failed' | 'rejected' | 'cancelled' | 'not_dispatched'; result_code: string | null; started_at: number | null; finished_at: number | null; result_received_at: number | null; result: Record<string, unknown> | null; unknown_reason: string | null; manual_query_after: number; retain_until: number; manual_query_id: string | null; timeline: Page<CommandAttempt> }
/** 签发时返回的设备连接参数和口令，页面只作当次交付展示。 */
export interface DeviceCredential { id: string; username: string; password: string; client_id: string; protocol_version: number; tls_required: boolean; keep_alive: number; session_expiry: number; topics: DeviceTopics }
/** 注册结果与当次签发的完整设备凭据。 */
export interface DeviceRegistration { device: Device; credential: DeviceCredential }
/** 历史查询时间窗、接收快照及保留边界，时间值均为 Unix 秒。 */
export interface HistoryWindow { from: number; to: number; snapshot: number; retention_cutoff: number }
/** 保留原模型和归属的历史上报；sequence 使用字符串避免数值精度损失。 */
export interface HistoryRecord { message_id: string; tenant_id: string; device_id: string; product_id: string; ownership_id: string; model_version: number; sequence: string; sampled_at: number; received_at: number; current_advanced: number; values: Record<string, number | string | boolean>; model: ModelDefinition }
/** 历史页码与后续游标并存，翻页继续使用服务端给出的查询窗口。 */
export interface HistoryPage extends Page<HistoryRecord> { next_cursor: string | null; sort: string; window: HistoryWindow }
/** 曲线时间桶或原始点；value 为 null 表示缺测，不能解释为零。 */
export interface HistoryPoint { sampled_at: number; received_at: number | null; value: number | null; count: number; sequence: string | null }
/** 用于展示的原始或均值曲线；均值展示不改写原始历史。 */
export interface HistoryCurve { points: HistoryPoint[]; raw_count: number; granularity_seconds: number; function: 'raw' | 'display_average'; property: ModelScalar; product_id: string; ownership_id: string; model_version: number; window: HistoryWindow }
/** 分钟聚合曲线允许选择的统计函数。 */
export type AggregateStatistic = 'count' | 'min' | 'max' | 'sum' | 'avg' | 'last';
/** 单个属性分钟窗口的有效样本统计和最后样本定位。 */
export interface MinuteStatistics { count: number; min: number; max: number; sum: number; avg: number; last: number; last_sampled_at: number; last_sequence: string }
/** 按设备归属、模型和分钟窗口保存的聚合记录。 */
export interface MinuteRecord { id: string; tenant_id: string; device_id: string; product_id: string; ownership_id: string; model_version: number; window_start: number; window_end: number; updated_at: number; fields: Record<string, MinuteStatistics>; model: ModelDefinition }
/** 分钟记录分页及实际聚合窗口；窗口可受迟到样本修正。 */
export interface MinutePage extends Page<MinuteRecord> { next_cursor: string | null; sort: string; window: HistoryWindow & { start: number; end: number } }
/** 分钟聚合曲线保留每个点的统计信息及所选统计函数。 */
export interface MinuteCurve extends Omit<HistoryCurve, 'function' | 'window' | 'points'> { function: 'minute_aggregate'; statistic: AggregateStatistic; minute_count: number; points: (HistoryPoint & { statistics: MinuteStatistics | null })[]; window: HistoryWindow & { start: number; end: number } }
/** 异步历史导出状态和文件保留期；succeeded 后仍需检查 expires_at。 */
export interface ExportTask { id: string; tenant_id: string; device_id: string; kind: 'records' | 'minutes'; timezone: string; status: 'queued' | 'running' | 'succeeded' | 'failed' | 'cancelled' | 'expired'; error_code: string; total_rows: number; completed_rows: number; estimated_bytes: number; file_bytes: number; created_at: number; updated_at: number; expires_at: number; filters: { from: number; to: number; sort: string; product_id?: string; model_version?: number; ownership_id?: string; field?: string } }
export const lifecycleNames: Record<Device['lifecycle'], string> = { inactive: '未激活', enabled: '启用', disabled: '禁用', retired: '退役' };
export const connectionNames: Record<DeviceConnection['status'], string> = { online: '在线', offline: '离线', unknown: '未知' };
export const connectionColors: Record<DeviceConnection['status'], string> = { online: 'success', offline: 'default', unknown: 'warning' };

const storedRealm: Realm = 'customer';
/** 当前标签页的认证上下文；generation 用于阻止旧身份响应回写。 */
export const session = reactive<{ realm: Realm; token: string; user: User | null; tenant: Tenant | null; identity: IdentityContext | null; impersonating: boolean; returnToAdmin: boolean; generation: number; notice: string; permissions: string[]; menus: Menu[] }>({
  realm: storedRealm, token: sessionStorage.getItem('typeapp.impersonation.token') || sessionStorage.getItem(`typeapp.${storedRealm}.token`) || '', user: null, tenant: null, identity: null, impersonating: Boolean(sessionStorage.getItem('typeapp.impersonation.token')), returnToAdmin: false, generation: 0, notice: '', permissions: [], menus: [],
});
/** 站点公开设置的响应式副本；启动请求失败时保留这些默认值。 */
export const siteSettings = reactive<PublicSiteSettings>({
  name: 'TypeApp', official_url: 'https://iots.top', description: '物联中心管理平台', logo_url: '', timezone: 'Asia/Shanghai',
  theme: { mode: 'light', colorPrimary: '#1677ff', radius: '0.5' },
  preferences: {
    layout: 'sidebar-nav', sidebar: { collapsed: false }, navigation: { styleType: 'rounded', split: true },
    breadcrumb: { enable: true, showIcon: true, styleType: 'normal' }, tabbar: { enable: false, styleType: 'chrome' }, footer: { enable: false, fixed: false },
  },
});
function sessionKey(field: 'token' | 'tenant') { return `typeapp.${session.impersonating ? 'impersonation' : session.realm}.${field}`; }
// 全局上下文取消与页面自身 AbortSignal 并存，finally 始终移除完成的控制器。
const activeRequests = new Set<AbortController>();
const client = new RequestClient({ timeout: 15_000 });
const errors: Record<string, string> = {
  impersonation_active: '已有模拟工作区，请先返回用户端退出模拟，再选择其他客户。',
  personal_credentials_required: '个人登录资料需由客户本人使用真实登录维护。',
  last_platform_admin: '请保留至少一位已启用且完成恢复核对的最高管理员。',
  protected_role: '最高管理员角色不能停用、删除或移除必要权限。',
  permission_escalation: '目标包含超出你当前权限的授权，不能执行此操作。',
  role_permissions_invalid: '请选择当前固定权限目录内的节点，不能重复。',
  role_binding_invalid: '请选择不重复的人员及角色，并刷新版本后重试。',
  role_not_found: '角色不存在或不在当前管理范围内。',
  role_exists: '角色名称已存在，请修改后重试。',
  role_name_invalid: '角色名称不能为空，且不能超过100字节。',
  account_exists: '登录账号已存在，请修改后重试。',
  invalid_account: '请检查账号和姓名；密码需为12至72字节且不能包含空字符。',
  broker_audit_query_invalid: '审计筛选条件无效，请核对标识、时间和每页数量。',
  broker_audit_cursor_invalid: '审计分页条件或授权范围已变化，请重新查询。',
  broker_audit_id_invalid: '审计事件标识无效，请刷新列表后重试。',
  broker_audit_not_found: '审计事件不存在，或不在当前授权范围内。',
  audit_query_invalid: '审计筛选条件无效，请核对标识、时间和每页数量。',
  audit_cursor_invalid: '审计分页条件或授权范围已变化，请重新查询。',
  audit_id_invalid: '审计事件标识无效，请刷新列表后重试。',
  audit_source_invalid: '审计来源无效，请刷新列表后重试。',
  audit_not_found: '审计事件不存在，或不在当前授权范围内。',
  authorization_changed: '读取期间授权已变化，请重新查询。',
  operations_filter_invalid: '运行状态筛选条件无效，请重新查询。',
  broker_resource_invalid: '请选择受支持的 Broker 资源类型。',
  broker_resource_query_invalid: '资源筛选条件无效，请核对后重新查询。',
  broker_resource_cursor_invalid: '分页条件或当前身份已变化，请重新查询。',
  broker_resource_id_invalid: '资源标识无效，请刷新列表后重试。',
  broker_resource_not_found: '资源已不存在，或不在当前授权范围内。',
  broker_resource_store_unavailable: '持久资源暂时无法确认，请稍后重试。',
  broker_resource_response_budget: '资源详情超出响应预算，请缩小查询范围。',
  transfer_identity_invalid: '请输入完整设备、租户及转移标识。',
  transfer_target_invalid: '目标租户不可用，或与源租户相同，请核对目标组织提供的标识。',
  transfer_identity_conflict: '此转移标识已经用于另一请求，请先核对原受理结果。',
  transfer_decision_conflict: '此审批已经保存其他结果，请刷新核对，不要重复创建审批。',
  transfer_decision_invalid: '请选择接受或拒绝，并保留原审批标识。',
  transfer_model_invalid: '请匹配已发布模型，或填写要复制的新产品名称。',
  transfer_model_mismatch: '目标模型结构与源模型不一致，请核对类型、单位、范围及枚举。',
  transfer_device_changed: '设备归属或模型已变化，请核对原转移记录。',
  transfer_device_unavailable: '设备需要已启用、凭据有效，并且没有待处理模型切换或撤权。',
  transfer_in_progress: '设备已经有待审批或待完成的转移，请继续处理原记录。',
  transfer_control_frozen: '设备正在转移冻结中，不能发起新控制；原指令结果仍可查询。',
  transfer_retry_unavailable: '记录不处于冻结状态，或距上次发送不足10秒，请稍后刷新。',
  transfer_target_admin_required: '只有目标租户管理员可以审批或推进正式切换。',
  transfer_switch_invalid: '请保留有效的原切换标识，再确认当前处理结果。',
  transfer_switch_conflict: '此转移已有正式切换请求，请刷新并继续原请求。',
  transfer_prerequisites_pending: '设备排空、指令对账或模型确认尚未齐备，请查看待处理原因。',
  transfer_switch_in_progress: '正式归属切换正在处理中，请在转移记录继续处置。',
  transfer_not_found: '转移记录不存在，或当前租户不是本次转移双方。',
  transfer_frozen: '设备已确认冻结，平台拒绝冻结边界之后的新增采样。',
  alarm_interval_invalid: '至少设置一个阈值；回差必须非负，恢复区间下界不得大于上界。',
  alarm_numeric_field_required: '请选择已发布物模型中的整数或数值属性。',
  alarm_rule_limit: '此设备归属已达 64 条规则，请编辑已有规则发布新版本。',
  alarm_device_scope_changed: '设备所属租户、物模型或归属已变化，请在当前设备下新建规则。',
  alarm_rule_not_found: '规则不存在或不属于当前租户。',
  alarm_not_found: '告警不存在或不属于当前租户。',
  notice_filter_invalid: '通知筛选条件无效，请修改后重试。',
  alarm_filter_invalid: '告警筛选无效，请检查标识、时间和分页。',
  invalid_credentials: '账号或密码不正确，或账号暂时被锁定。',
  current_password_invalid: '当前密码不正确，或账号暂时被锁定，请核对后重试。',
  recovery_reconciliation_required: '恢复后尚未核对设备当前授权与归属，请联系恢复核对负责人。',
  invalid_time_range: '结束时间不能早于开始时间。',
  history_filter_invalid: '历史筛选无效，请检查标识、时间、排序和分页。',
  history_cursor_invalid: '翻页条件已变化，请重新查询。',
  history_snapshot_expired: '本次查询已超过 15 分钟，请重新查询后翻页。',
  history_page_out_of_range: '此页已超出查询结果，请重新查询。',
  history_curve_scope_required: '请从一条历史记录选择曲线，以确定当时的产品、模型和归属。',
  history_numeric_field_required: '曲线仅支持数值或整数属性，其他值请查看原始详情。',
  aggregate_numeric_overflow: '统计数值超出可计算范围，请缩小范围并检查异常数值。',
  export_limit_exceeded: '预计导出超过 100000 行或 100 MiB，请缩小时间或属性范围后重试。',
  export_capacity_exceeded: '导出任务已达到保留容量，请等待现有任务到期清理后重试。',
  export_filter_invalid: '导出筛选无效，请重新查询后创建任务。',
  export_timezone_invalid: '导出时区无效，请刷新页面后重试。',
  export_expired: '导出文件已过期，请按需要重新创建任务。',
  export_not_ready: '文件尚未生成成功，请查看任务进度。',
  export_not_cancelable: '当前任务状态已变化，无法取消，请刷新查看。',
  export_not_resumable: '任务仍在推进、已结束或已过期，请刷新查看。',
  export_file_unavailable: '文件不可用，请重新创建导出任务。',
  export_not_found: '任务不存在或不属于当前租户。',
  export_identity_invalid: '请保留有效的导出请求标识后重试。',
  export_identity_conflict: '此请求标识已有其他来源或筛选，请先核对原导出结果。',
  unauthorized: '登录已失效，请重新登录。',
  forbidden: '当前账号没有此操作权限。',
  json_required: '请以 JSON 提交请求。',
  broker_connection_id_invalid: '连接标识无效。',
  broker_connection_invalid: '断开请求参数无效，请刷新连接后重试。',
  broker_connection_not_found: '连接不存在或不在当前权限范围。',
  broker_connection_stale: '连接代次已变化，请刷新后针对当前连接重试。',
  broker_operation_conflict: '此操作标识已用于另一断开目标。',
  broker_operation_id_invalid: '操作标识无效。',
  broker_operation_not_found: '断开操作不存在或不在当前权限范围。',
  broker_authorization_changed: '写入权限已变化，请刷新后重试。',
  broker_debug_invalid: '调试凭据请求无效，请刷新后重试。',
  broker_debug_wss_unavailable: '当前节点尚未开放 WSS 入口，不能签发实时订阅凭据。',
  broker_debug_session_expired: '登录会话即将到期，请重新登录后再签发调试凭据。',
  broker_debug_topic_unavailable: '没有可用于调试订阅的 Topic 前缀。',
  tenant_access_denied: '你已无权访问此租户，请重新选择租户。',
  tenant_forbidden: '当前租户权限已改变，请重新选择租户。',
  tenant_context_mismatch: '租户上下文不一致，请重新选择租户。',
  owner_not_available: '管理员账号不可用，请确认账号已开通且未停用。',
  tenant_exists: '此创建请求已经使用，请刷新租户列表核对结果。',
  tenant_not_found: '租户不存在或你没有此工作区资格。',
  tenant_name_invalid: '租户名称不能为空，且不得超过100字节。',
  tenant_owner_input_invalid: '新账号需填写姓名和密码，关联已有账号仅填写准确登录标识。',
  administrator_account_unavailable: '最高管理员账号不可用，请确认客户账号已开通且未停用。',
  administrator_member_unavailable: '目标账号在此租户的成员关系不可用，请先恢复成员资格。',
  administrator_exists: '此账号已经是当前租户最高管理员。',
  administrator_not_found: '最高管理员关系已变化，请刷新租户详情。',
  administrator_replacement_invalid: '更换目标无效，请刷新租户详情后重试。',
  tenant_admin_role_unavailable: '租户最高管理员角色不可用，请先完成权限恢复。',
  tenant_query_invalid: '租户筛选条件无效，请修改后重试。',
  account_not_available: '成员账号不可用，请确认账号已开通且未停用。',
  stale_version: '记录已被其他人修改，请刷新后重新编辑。',
  product_not_found: '产品不存在或不属于当前租户。',
  device_not_found: '设备不存在或不属于当前租户。',
  command_device_not_online: '设备当前没有有效在线观察，暂不能发起控制。',
  command_payload_too_large: '完整指令超过 16 KiB，请缩小参数内容。',
  command_identity_conflict: '同一指令标识的内容不能修改，请先查看原指令结果。',
  command_result_known: '已经取得确定结果，请刷新查看。',
  command_auto_query_pending: '自动查询尚未结束，创建满 5 分钟后可以主动对账。',
  command_retention_expired: '指令已超过 180 天保留期，不能再发起查询。',
  command_query_in_progress: '已有待发查询或距上次查询不足 10 秒，请稍后刷新。',
  command_query_identity_conflict: '查询标识已经用于另一请求，请刷新后重试。',
  command_not_found: '指令记录不存在或已超过保留期。',
  device_confirmation_required: '请输入与当前设备完全一致的设备标识。',
  device_action_invalid: '设备操作或确认参数无效，请重新选择操作。',
  device_retired: '设备已退役，不能恢复接入或再次修改；历史仍按原权限保留。',
  device_authorization_pending: '前次撤权仍等待 Broker 执行，请刷新状态后再操作。',
  device_lifecycle_conflict: '设备生命周期已变化，请刷新状态后重新选择操作。',
  device_credential_revoked: '当前凭据已吊销，请刷新设备状态。',
  device_credential_conflict: '设备凭据状态异常，请联系管理员核对。',
  model_not_found: '物模型版本不存在或不属于当前产品。',
  model_immutable: '已发布版本不可修改，请复制为新草稿。',
  model_not_published: '此版本尚未发布。',
  model_switch_invalid: '请选择已发布目标版本，并保留同一切换标识。',
  model_switch_identity_conflict: '此切换标识已用于另一版本，请刷新核对原请求。',
  model_switch_device_unavailable: '设备需已启用且没有生命周期待处理操作。',
  model_switch_in_progress: '模型切换尚未确认，请继续处理原切换；此时不能发起新控制。',
  model_switch_retry_too_soon: '距上次发送不足 10 秒，请稍后再试。',
  model_already_bound: '设备已绑定此版本，请选择其他已发布版本。',
  published_model_retained: '产品存在已发布版本，需保留历史定义，无法删除。',
  empty_model: '发布前至少定义一项属性、事件或指令。',
  model_version_exhausted: '此产品的模型版本编号已达到上限。',
  optimistic_conflict: '成员信息已被其他人修改，请刷新后重试。',
  last_tenant_admin: '请保留至少一位租户管理员。',
  last_admin: '请保留至少一位租户管理员。',
  member_exists: '此账号已经是租户成员。',
  user_not_found: '未找到此账号，请先由平台开通账号。',
  validation_failed: '请检查必填字段及输入格式。',
  configuration_invalid: '启动配置无效，请先核对运行文件或进程环境。',
  configuration_input_invalid: '配置请求格式无效，请刷新后重试。',
  configuration_field_read_only: '该配置由进程环境或安全策略接管，不能在网页中修改。',
  configuration_value_invalid: '配置值类型或范围无效，请核对后重试。',
  configuration_write_failed: '配置文件暂时无法写入，请检查运行目录权限后重试。',
  site_settings_input_invalid: '站点设置请求格式无效，请刷新后重试。',
  site_settings_value_invalid: '站点设置值无效，请核对网址、主题色和文本长度。',
  internal_error: '服务暂时无法完成请求，请稍后重试。',
};

/** 服务端业务错误的前端表示；保留字段错误及 requestId 供表单和排查使用。 */
export class ApiError extends Error {
  constructor(public code: string, public status: number, public requestId = '', public fields: Record<string, string[]> = {}) {
    super(errors[code] || (status === 403 ? '当前账号没有此操作权限。' : status === 401 ? '登录已失效，请重新登录。' : status === 409 ? '数据发生冲突，请刷新后重试。' : '请求未完成，请检查输入或稍后重试。'));
  }
}
/** 使所有在途请求失效并发送取消信号；代次检查还会拒绝取消之后到达的响应。 */
export function cancelContext() {
  session.generation++;
  for (const controller of activeRequests) controller.abort();
  activeRequests.clear();
}
/** 切换租户前清空旧权限和菜单，并隔离客户身份的租户选择记录。 */
export function selectTenant(tenant: Tenant | null) {
  cancelContext();
  session.tenant = tenant;
  session.permissions = []; session.menus = [];
  if (session.realm === 'customer') {
    if (tenant) sessionStorage.setItem(sessionKey('tenant'), tenant.id);
    else sessionStorage.removeItem(sessionKey('tenant'));
  }
}
/** 清除当前身份令牌与租户选择；模拟登录退出保留返回平台的导航意图。 */
export function clearSession() {
  cancelContext();
  sessionStorage.removeItem(sessionKey('token'));
  sessionStorage.removeItem(sessionKey('tenant'));
  session.returnToAdmin = session.impersonating;
  session.impersonating = false; session.identity = null;
  session.token = '';
  session.user = null;
  session.tenant = null;
  session.permissions = []; session.menus = [];
  switchPreferencesScope('anonymous');
}
/** 恢复目标登录域的独立令牌，同时使旧域在途响应和权限失效。 */
export function activateRealm(realm: Realm) {
  if (session.realm === realm) return;
  cancelContext();
  session.realm = realm;
  session.impersonating = realm === 'customer' && Boolean(sessionStorage.getItem('typeapp.impersonation.token'));
  session.token = sessionStorage.getItem(sessionKey('token')) || '';
  session.identity = null; session.returnToAdmin = false;
  session.user = null; session.tenant = null;
  session.permissions = []; session.menus = []; session.notice = '';
}
/** 按登录域生成登录入口，客户域使用根路径。 */
export function loginPath(realm: Realm = session.realm) { return realm === 'customer' ? '/login' : `/${realm}/login`; }
/** 模拟登录退出返回平台客户页，其他身份返回自身登录页。 */
export function sessionExitPath() { return session.returnToAdmin ? '/admin/customers' : loginPath(); }
/** 平台角色定义及并发版本；protected 角色的限制由后端执行。 */
export interface AdminRole { id: string; name: string; enabled: number; protected: number; version: number; created_at: number; permissions: string[] }
/** 平台账号或客户账号管理记录；角色清单按查询权限返回。 */
export interface AdminUser { id: string; user_id?: string; login: string; name: string; enabled: number; version: number; recovery_verified: number; created_at: number; roles?: AdminRole[] }
/** 管理列表附带当前获准的权限目录和菜单。 */
export interface AdminPage<T> extends Page<T> { permissions: string[]; catalog: Record<string, string>; menus: Menu[] }
/** 用服务端响应替换当前权限与菜单，不在前端合并旧授权。 */
export function applyAdminContext(data: Pick<AdminPage<never>, 'permissions' | 'menus'>) {
  session.permissions = data.permissions; session.menus = data.menus;
}
/** 配置字段展示其来源、可编辑性和密钥属性，不能将脱敏值当作原值。 */
export interface ConfigurationField {
  key: string; label: string; group: string; type: 'string' | 'integer' | 'boolean' | 'enum'; value: string | number | boolean | null;
  editable: boolean; secret: boolean; source: 'process' | 'file' | 'default' | 'unknown'; read_only: boolean; reason: string | null;
  file_present: boolean; options?: string[];
}
/** 可管理配置的版本化视图；restart_required 表示变更需重启生效。 */
export interface ConfigurationView extends Pick<AdminPage<never>, 'permissions' | 'menus' | 'catalog'> {
  version: string; restart_required: boolean; fields: ConfigurationField[]; undeclared: string[];
}
/** 仅用于平台界面可见性和按钮状态，接口权限仍由服务端判断。 */
export function canAdmin(action: string) { return session.realm === 'admin' && session.permissions.includes(`admin.${action}`); }
/** 授权失效时清空旧界面权限；越权提权请求被拒不代表现有授权失效。 */
export function clearAdminAccess(error: unknown) {
  if (error instanceof ApiError && (error.status === 401 || error.status === 403 && error.code !== 'permission_escalation')) {
    session.permissions = []; session.menus = []; return true;
  }
  return false;
}
/** 统一识别 Axios 与浏览器取消异常，避免将切换上下文显示为网络故障。 */
export function isCanceled(error: unknown) { return axios.isCancel(error) || error instanceof DOMException && error.name === 'AbortError'; }
/** 提取可显示的错误信息，未知异常使用通用提示。 */
export function errorText(error: unknown) { return error instanceof Error ? error.message : '请求未完成，请稍后重试。'; }

/** 绑定调用时的令牌和上下文代次，合并外部取消信号并拒绝过期响应。
 * 仅适配现有 data 响应封装；HTTP 错误保留稳定错误码与请求标识。 */
export async function request<T>(path: string, options: { method?: string; data?: unknown; params?: Record<string, unknown>; tenant?: string; public?: boolean; signal?: AbortSignal; responseType?: 'blob' } = {}): Promise<T> {
  const controller = new AbortController();
  const generation = session.generation;
  const token = session.token;
  activeRequests.add(controller);
  try {
    const response = await client.request<{ data: T | { data: T } }>(path, {
      method: options.method || 'GET', data: options.data, params: options.params, responseType: options.responseType, signal: options.signal ? AbortSignal.any([controller.signal, options.signal]) : controller.signal,
      headers: { ...(options.public ? {} : { Authorization: `Bearer ${token}` }), ...(options.tenant ? { 'X-Tenant-Id': options.tenant } : {}) },
    });
    if (generation !== session.generation) throw new DOMException('租户上下文已改变', 'AbortError');
    const body = response.data;
    return body && typeof body === 'object' && 'data' in body ? body.data : body as T;
  } catch (error) {
    if (isCanceled(error) || generation !== session.generation) throw new DOMException('请求已取消', 'AbortError');
    if (axios.isAxiosError(error) && error.response) {
      let body = error.response.data as { error?: string; request_id?: string; fields?: Record<string, string[]> };
      if (error.response.data instanceof Blob) {
        try { body = JSON.parse(await error.response.data.text()); } catch { body = {}; }
        if (generation !== session.generation) throw new DOMException('请求已取消', 'AbortError');
      }
      if (error.response.status === 401 && !options.public && token === session.token) {
        clearSession(); session.notice = session.returnToAdmin ? '模拟登录已失效，请从管理端重新进入。' : '登录已失效，请重新登录。';
      }
      if (error.response.status === 403 && body.error !== 'permission_escalation' && options.tenant && options.tenant === session.tenant?.id) {
        selectTenant(null); session.notice = '当前租户权限已改变，请重新选择租户。';
      }
      if (error.response.status === 403 && !options.tenant && token === session.token) {
        if (session.realm === 'broker' && (path === '/broker/audit' || path.startsWith('/broker/audit/')
          || path === '/broker/access/principals' || path.startsWith('/broker/access/')
          || path === '/broker/quotas' || path.startsWith('/broker/quotas/')
          || path === '/broker/runtime' || path.startsWith('/broker/runtime/')
          || path === '/broker/debug' || path.startsWith('/broker/debug/')
          || path === '/broker/compat' || path === '/broker/recovery' || path === '/broker/nodes' || path.startsWith('/broker/nodes?'))) {
          clearSession(); session.notice = 'Broker 管理权限已改变，请重新登录。';
        }
      }
      throw new ApiError(body.error || 'request_failed', error.response.status, body.request_id, body.fields);
    }
    throw new Error('网络连接失败，请检查网络后重试。');
  } finally { activeRequests.delete(controller); }
}

/** 登录前读取公开站点设置；请求失败向上传播，由启动入口决定使用默认值。 */
export async function loadSiteSettings() {
  const current = await request<PublicSiteSettings>('/public/site', { public: true });
  Object.assign(siteSettings, current);
  switchPreferencesScope('anonymous');
  return siteSettings;
}

/** 验证指定登录域并保存独立令牌；已有模拟身份时要求先结束模拟。 */
export async function login(login: string, password: string, realm: Realm = 'customer') {
  activateRealm(realm);
  if (session.impersonating) throw new ApiError('impersonation_active', 409);
  const result = await request<{ accessToken: string; expiresAt: number; user: User }>(`/${realm}/auth/login`, { method: 'POST', data: { login, password }, public: true });
  cancelContext();
  session.realm = realm;
  session.tenant = null;
  session.token = result.accessToken;
  session.user = result.user;
  switchPreferencesScope(realm, result.user.id);
  session.notice = '';
  sessionStorage.setItem(`typeapp.${realm}.token`, result.accessToken);
  sessionStorage.removeItem(`typeapp.${realm}.tenant`);
}
/** 重新取得身份及获准工作区，同步个人偏好范围和服务端权限。 */
export async function loadUser(tenant = session.realm === 'customer' ? session.tenant?.id || sessionStorage.getItem(sessionKey('tenant')) || undefined : undefined) {
  const current = await request<{ user: User; identity?: IdentityContext; context: TenantContext | null; permissions?: string[]; menus?: Menu[]; tenant?: Tenant | null }>(`/${session.realm}/auth/me`, { tenant });
  session.user = current.user;
  switchPreferencesScope(session.realm, current.user.id, session.impersonating, current.identity?.actor_id || '');
  session.identity = current.identity || null;
  session.permissions = current.permissions || [];
  session.menus = current.menus || [];
  if (session.realm !== 'broker') session.tenant = current.tenant || null;
  if (session.realm === 'customer') {
    if (session.tenant) sessionStorage.setItem(sessionKey('tenant'), session.tenant.id);
    else sessionStorage.removeItem(sessionKey('tenant'));
  }
  return current.context;
}
/** 服务端注销成功后清除本地会话，失败由调用方展示并决定后续操作。 */
export async function logout() { await request(`/${session.realm}/auth/logout`, { method: 'POST', data: {} }); clearSession(); }
/** 模拟令牌单独保存，客户原登录及租户选择不被覆盖。 */
export async function impersonate(user: AdminUser) {
  if (sessionStorage.getItem('typeapp.impersonation.token')) throw new ApiError('impersonation_active', 409);
  const result = await request<{ accessToken: string }>(`/admin/customers/${user.id}/impersonate`, { method: 'POST', data: { version: Number(user.version) } });
  sessionStorage.setItem('typeapp.impersonation.token', result.accessToken);
  sessionStorage.removeItem('typeapp.impersonation.tenant');
  activateRealm('customer');
}
export const roleNames: Record<Role, string> = { admin: '管理员', operator: '操作员', readonly: '只读成员' };
