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
export interface PublicSiteSettings { environment: 'dev' | 'prod' | 'demo'; name: string; official_url: string; description: string; logo_url: string; timezone: string; theme: SiteTheme; preferences: SitePreferences }
/** 带并发版本和权限目录的站点编辑结果。 */
export interface AdminSiteSettings extends Omit<PublicSiteSettings, 'environment'> { version: number; updated_at: number; permissions: string[]; catalog: Record<string, string>; menus: Menu[]; changed?: string[] }
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
/** 基于观察时间的连接状态；unknown 不等于离线。 */
export interface DeviceConnection { status: 'online' | 'offline' | 'unknown'; observed_at: number | null; broker_observed_at: number | null }
/** 设备授权动作的执行进度，pending 时不能声称撤权已完成。 */
export interface DeviceAuthorization { status: 'pending' | 'enforced'; id?: string | null; requested_at?: number | null; completed_at?: number | null; node_id?: string | null }
/** 设备业务生命周期与连接观察分开记录，并绑定具体归属和模型版本。 */
export interface Device { id: string; tenant_id: string; product_id: string | null; ownership_id: string; name: string; lifecycle: 'inactive' | 'enabled' | 'disabled' | 'retired'; connection: DeviceConnection; authorization?: DeviceAuthorization; recovery_verified?: number; version: number; created_at: number; updated_at: number; transfer_id?: string | null; transfer_frozen?: number }
/** 设备管理接口支持的明确动作，页面按授权及当前状态筛选。 */
export type DeviceAction = 'update' | 'rotate' | 'revoke' | 'disable' | 'enable' | 'retire';
/** 旧安装转换的安全投影；不包含原件 JSON、凭据或验证摘要。 */
export interface DeviceUpgrade { source: 'legacy_installation'; decision: 'converted' | 'quarantined'; reasons: string[]; product_id: string; model_version: number; lifecycle: string; converted_at: number }
/** 设备管理详情中的凭据可用性、使用关系和可选升级来源。 */
export interface DeviceManagement extends Device { upgrade?: DeviceUpgrade | null; credential_active: boolean; tenant_name?: string; tenant_enabled?: number; usage?: { started_at: number; ended_at: number | null; expires_at: number | null; available: boolean } }
/** 原始通信设备的关系交接；查询不推进清理，目标确认后才建立新关系。 */
export interface DeviceReassignment {
  id: string; device_id: string; device_name: string; source_tenant_id: string; target_tenant_id: string | null;
  ownership_id: string; new_ownership_id: string | null; kind: 'reassign' | 'end' | 'expire';
  status: 'isolating' | 'awaiting_acceptance' | 'completed' | 'ended'; requires_store: boolean;
  version: number; created_at: number; updated_at: number; pending_reasons: string[];
  retained: Record<string, { state: string; checked_at?: number; operation_id?: string }>;
}
export const reassignmentStatusNames: Record<DeviceReassignment['status'], string> = { isolating: '隔离旧授权与持久内容', awaiting_acceptance: '等待目标客户确认', completed: '已建立新使用关系', ended: '使用关系已结束' };
export const reassignmentPendingNames: Record<string, string> = { old_authorization_isolation_pending: '等待旧连接、会话及待投递消息隔离', retained_verification_required: '等待平台核验或清理旧保留消息', target_customer_confirmation_required: '隔离已完成，等待目标客户确认接收' };
/** 设备变更后的状态；只有签发或轮换时才可能带回新凭据。 */
export interface DeviceChange { device: DeviceManagement; credential: DeviceCredential | null }
/** 设备获准发布和订阅的主题，由服务端生成。 */
export interface DeviceTopics { publish: string; subscribe: string }
/** 设备详情提供当前使用关系的精确通信主题。 */
export interface DeviceDetail extends DeviceManagement { topics: DeviceTopics }
/** 签发时返回的设备连接参数和口令，页面只作当次交付展示。 */
export interface DeviceCredential { id: string; username: string; password: string; client_id: string; protocol_version: number; tls_required: boolean; keep_alive: number; session_expiry: number; topics: DeviceTopics }
/** 注册结果与当次签发的完整设备凭据。 */
export interface DeviceRegistration { device: Device; credential: DeviceCredential }
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
  environment: 'prod',
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
  demo_readonly: '演示环境，仅供浏览，无法修改数据。',
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
  invalid_account: '请检查账号和姓名；密码需为5至72字节且不能包含空字符。',
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
  invalid_credentials: '账号或密码不正确，或账号暂时被锁定。',
  current_password_invalid: '当前密码不正确，或账号暂时被锁定，请核对后重试。',
  recovery_reconciliation_required: '恢复后尚未核对设备当前授权与归属，请联系恢复核对负责人。',
  invalid_time_range: '结束时间不能早于开始时间。',
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
  product_not_found: '分类不存在或不属于当前租户。',
  product_referenced: '分类仍被设备或保全的历史记录引用，不能删除。',
  device_not_found: '设备不存在或不属于当前租户。',
  enrollment_not_found: '注册授权不存在或不属于当前客户。',
  enrollment_query_invalid: '注册授权筛选条件无效，请修改后重试。',
  enrollment_request_conflict: '此请求标识已用于另一项授权，请核对原结果或重新创建。',
  enrollment_expired: '注册截止时间已过，请填写将来的时间。',
  enrollment_unavailable: '资格已停用、过期或额度已用完，不能注册新设备或重置注册码。',
  enrollment_customer_disabled: '客户工作区已停用，不能签发或重置注册授权。',
  application_not_found: '应用不存在或不属于当前客户。',
  application_query_invalid: '应用筛选条件无效，请修改后重试。',
  application_subscription_requires_receive: '订阅设备上行前必须同时授予接收资格。',
  application_device_relationship_changed: '设备当前使用关系已变化，请重新选择本客户的设备。',
  application_device_unavailable: '设备已停用、正在转配或尚未升级为原始通信身份。',
  application_grant_empty: '新增授权至少选择一项通信能力。',
  application_request_conflict: '此标识已用于其他应用或设备，请核对原结果或重新创建。',
  application_customer_disabled: '客户工作区已停用，不能创建应用或签发密钥。',
  application_disabled: '应用已停用，请先启用再管理接入密钥。',
  application_key_unavailable: '当前密钥状态不适用此操作，请刷新后重试。',
  application_recovery_required: '应用恢复状态尚未核对，暂不能变更或接入。',
  application_action_invalid: '应用操作无效。',
  broker_access_managed_principal: '此身份由接入应用管理，请通过应用页面修改密钥与状态。',
  device_confirmation_required: '请输入与当前设备完全一致的设备标识。',
  device_action_invalid: '设备操作或确认参数无效，请重新选择操作。',
  device_retired: '设备已退役，不能恢复接入或再次修改；历史仍按原权限保留。',
  device_authorization_pending: '前次撤权仍等待 Broker 执行，请刷新状态后再操作。',
  device_expiry_invalid: '使用期限必须是未来时间，取消期限请留空。',
  device_ownership_unavailable: '当前使用关系已结束、到期或正在转配，请查看设备转配记录。',
  reassignment_request_conflict: '请求标识与原操作不一致，请读取当前记录核对结果。',
  reassignment_target_required: '只有目标客户获授权的人员可以确认接收。',
  reassignment_target_unavailable: '目标客户不存在、已停用或与来源客户相同。',
  reassignment_isolation_pending: '旧授权与持久内容尚未完成隔离，暂不能接收。',
  reassignment_relationship_changed: '设备当前关系已改变，请刷新记录。',
  reassignment_store_required: '此设备需要真实持久存储的隔离证明，请配置并恢复 MQTT 存储服务。',
  reassignment_in_progress: '设备已有未完成转配或已退役，请先查看现有记录。',
  reassignment_legacy_upgrade_required: '旧模型设备须先完成升级核对，不能直接按原始通信设备转配。',
  reassignment_not_found: '转配记录不存在或不在当前客户范围内。',
  device_lifecycle_conflict: '设备生命周期已变化，请刷新状态后重新选择操作。',
  device_credential_revoked: '当前凭据已吊销，请刷新设备状态。',
  device_credential_conflict: '设备凭据状态异常，请联系管理员核对。',
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
  switchPreferencesScope(session.realm);
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
  switchPreferencesScope(realm);
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
/** 只读取服务端启动环境，不接受本地设置改变演示权限。 */
export function isDemo() { return siteSettings.environment === 'demo'; }
/** 授权失效时清空旧界面权限；越权提权请求被拒不代表现有授权失效。 */
export function clearAdminAccess(error: unknown) {
  if (error instanceof ApiError && (error.status === 401 || error.status === 403 && !['permission_escalation', 'demo_readonly'].includes(error.code))) {
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
  const method = (options.method || 'GET').toUpperCase();
  if (isDemo() && !['GET', 'HEAD', 'OPTIONS'].includes(method)
    && !(method === 'POST' && /^\/(admin|customer)\/auth\/(login|logout)$/.test(path))) {
    throw new ApiError('demo_readonly', 403);
  }
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
      if (error.response.status === 403 && !['permission_escalation', 'demo_readonly'].includes(body.error || '') && options.tenant && options.tenant === session.tenant?.id) {
        selectTenant(null); session.notice = '当前租户权限已改变，请重新选择租户。';
      }
      if (error.response.status === 403 && body.error !== 'demo_readonly' && !options.tenant && token === session.token) {
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
