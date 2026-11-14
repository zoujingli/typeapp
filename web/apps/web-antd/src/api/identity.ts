import { reactive } from 'vue';
import { RequestClient } from '@vben/request';
import axios from 'axios';
import { applySitePreferences, switchPreferencesScope } from '../preferences';
import type { Realm, User, Menu, PublicSiteSettings, IdentityContext, Tenant, AccessPage } from './contracts';

/** 固定客户入口的可信路径前缀；客户标识为不可变的 32 位小写十六进制值。 */
export const entryBase = /^\/t\/[a-f0-9]{32}(?=\/|$)/.exec(window.location.pathname)?.[0] || '';
export const entryContext = reactive<{ bound: boolean; tenant: Tenant | null; loaded: boolean }>({ bound: false, tenant: null, loaded: false });
export const session = reactive<{ realm: Realm; token: string; user: User | null; tenant: Tenant | null; identity: IdentityContext | null; impersonating: boolean; returnToPlatform: boolean; generation: number; notice: string; permissions: string[]; menus: Menu[] }>({
  realm: 'system', token: '', user: null, tenant: null, identity: null, impersonating: false, returnToPlatform: false,
  generation: 0, notice: '', permissions: [], menus: [],
});
/** 站点公开设置的响应式副本；启动请求失败时保留这些默认值。 */
export const siteSettings = reactive<PublicSiteSettings>({
  environment: 'prod',
  name: 'TypeApp', official_url: 'https://iots.top', description: '物联中心管理平台', logo_url: '', timezone: 'Asia/Shanghai',
  theme: { mode: 'light', colorPrimary: '#1677ff', radius: '0.5' },
  preferences: {
    layout: 'sidebar-nav', sidebar: { collapsed: false }, navigation: { styleType: 'rounded', split: true },
    breadcrumb: { enable: true, showIcon: true, styleType: 'normal' }, tabbar: { enable: true, styleType: 'chrome' }, footer: { enable: false, fixed: false },
  },
});
function sessionKey() {
  return session.realm === 'platform' ? 'typeapp.platform.token' : `typeapp.system.${entryContext.tenant?.id || session.tenant?.id || 'unbound'}.token`;
}
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
  tenant_forbidden: '当前客户权限已改变，请重新选择租户。',
  tenant_context_mismatch: '租户上下文不一致，请重新选择租户。',
  owner_not_available: '管理员账号不可用，请确认账号已开通且未停用。',
  tenant_exists: '此创建请求已经使用，请刷新租户列表核对结果。',
  tenant_not_found: '租户不存在或你没有此工作区资格。',
  tenant_name_invalid: '租户名称不能为空，且不得超过100字节。',
  tenant_owner_input_invalid: '新账号需填写姓名和密码，关联已有账号仅填写准确登录标识。',
  administrator_account_unavailable: '最高管理员账号不可用，请确认系统账号已开通且未停用。',
  administrator_member_unavailable: '目标 System 账号在此 Tenant 中不可用，请先恢复账号资格。',
  administrator_exists: '此账号已经是当前客户最高管理员。',
  administrator_not_found: '最高管理员关系已变化，请刷新租户详情。',
  administrator_replacement_invalid: '更换目标无效，请刷新租户详情后重试。',
  tenant_admin_role_unavailable: '租户最高管理员角色不可用，请先完成权限恢复。',
  tenant_query_invalid: '租户筛选条件无效，请修改后重试。',
  account_not_available: '成员账号不可用，请确认账号已开通且未停用。',
  stale_version: '记录已被其他人修改，请刷新后重新编辑。',
  product_not_found: '分类不存在或不属于当前客户。',
  product_referenced: '分类仍被设备或保全的历史记录引用，不能删除。',
  device_not_found: '设备不存在或不属于当前客户。',
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
  member_exists: '此账号已经是系统用户。',
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
/** 登录前选择客户，不提供登录后切换客户能力。 */
export function selectTenant(tenant: Tenant | null) {
  cancelContext();
  session.tenant = tenant;
  session.permissions = []; session.menus = [];
}
export function clearSession() {
  cancelContext();
  sessionStorage.removeItem(sessionKey());
  session.returnToPlatform = session.impersonating;
  session.impersonating = false; session.identity = null; session.token = ''; session.user = null;
  session.permissions = []; session.menus = [];
  switchPreferencesScope(session.realm, '', false, '', session.tenant?.id || '');
}
export function activateRealm(realm: Realm) {
  if (session.realm === realm && session.token) return;
  cancelContext();
  session.realm = realm; session.user = null; session.identity = null; session.impersonating = false;
  session.tenant = realm === 'system' ? entryContext.tenant : null;
  session.token = sessionStorage.getItem(sessionKey()) || '';
  session.permissions = []; session.menus = []; session.notice = '';
  switchPreferencesScope(realm, '', false, '', session.tenant?.id || '');
}
export function loginPath(realm: Realm = session.realm) { return `/${realm}/login`; }
export function sessionExitPath() { return loginPath(); }
/** 用服务端响应替换当前权限与菜单，不在前端合并旧授权。 */
export function applyAccessContext(data: Pick<AccessPage<never>, 'permissions' | 'menus'>) {
  session.permissions = data.permissions; session.menus = data.menus;
}
/** 只读取服务端启动环境，不接受本地设置改变演示权限。 */
export function isDemo() { return siteSettings.environment === 'demo'; }
/** 授权失效时清空旧界面权限；越权提权请求被拒不代表现有授权失效。 */
export function clearAccess(error: unknown) {
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
    && !(method === 'POST' && /^\/(platform|system)\/auth\/(login|logout|handoff)$/.test(path))) {
    throw new ApiError('demo_readonly', 403);
  }
  // 固定路径沿用当前路径；子域名保持当前域名的直接 API 入口，主域名选定客户后才补固定路径。
  const prefix = path.startsWith('/platform') || path.startsWith('/t/') ? '' : entryBase
    || (!entryContext.bound && /^\/(system|center)\//.test(path) && session.tenant ? `/t/${session.tenant.id}` : '');
  if (options.tenant && session.realm === 'system' && options.tenant !== session.tenant?.id) throw new ApiError('tenant_context_mismatch', 403);
  const controller = new AbortController();
  const generation = session.generation;
  const token = session.token;
  activeRequests.add(controller);
  try {
    const response = await client.request<{ data: T | { data: T } }>(prefix + path, {
      method: options.method || 'GET', data: options.data, params: options.params, responseType: options.responseType, signal: options.signal ? AbortSignal.any([controller.signal, options.signal]) : controller.signal,
      headers: options.public ? {} : { Authorization: `Bearer ${token}` },
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
        clearSession(); session.notice = session.returnToPlatform ? '模拟登录已失效，请从管理端重新进入。' : '登录已失效，请重新登录。';
      }
      throw new ApiError(body.error || 'request_failed', error.response.status, body.request_id, body.fields);
    }
    throw new Error('网络连接失败，请检查网络后重试。');
  } finally { activeRequests.delete(controller); }
}

let contextGeneration = 0;
let contextRequest: AbortController | undefined;
/** 只接受当前入口的公开配置；选择变化取消旧请求并拒绝乱序响应。 */
export async function loadSiteSettings(tenant?: Tenant | null, realm: Realm = window.location.hash.startsWith('#/platform') ? 'platform' : 'system') {
  const generation = ++contextGeneration;
  contextRequest?.abort(); contextRequest = new AbortController();
  const path = realm === 'platform' ? '/platform/public/context' : tenant ? `/t/${tenant.id}/public/context` : '/public/context';
  const current = await request<{ realm: Realm; bound: boolean; tenant: Tenant | null; site: Omit<PublicSiteSettings, 'environment'>; environment: PublicSiteSettings['environment'] }>(path, { public: true, signal: contextRequest.signal });
  if (generation !== contextGeneration) throw new DOMException('客户配置请求已取消', 'AbortError');
  Object.assign(siteSettings, current.site, { environment: current.environment });
  applySitePreferences(siteSettings);
  if (tenant === undefined) { entryContext.bound = current.bound; entryContext.tenant = current.tenant; entryContext.loaded = true; }
  session.tenant = realm === 'system' ? current.tenant : null;
  return siteSettings;
}
export async function login(login: string, password: string, realm: Realm = 'system') {
  if (realm === 'system' && !session.tenant) throw new ApiError('tenant_context_required', 422);
  const tenant = session.tenant;
  session.realm = realm;
  const result = await request<{ accessToken: string; expiresAt: number; user: User }>(`/${realm}/auth/login`, { method: 'POST', data: { login, password }, public: true });
  cancelContext();
  session.token = result.accessToken; session.user = result.user; session.notice = '';
  sessionStorage.setItem(sessionKey(), result.accessToken);
  if (realm === 'system' && !entryContext.bound && tenant) {
    window.location.assign(`/t/${tenant.id}/#${'/system/overview'}`);
    return;
  }
  await loadUser();
}
export async function loadUser() {
  const current = await request<{ user: User; identity: IdentityContext; permissions: string[]; menus: Menu[] }>(`/${session.realm}/auth/me`);
  session.user = current.user; session.identity = current.identity;
  session.impersonating = Boolean(current.identity.impersonation_id);
  session.permissions = current.permissions; session.menus = current.menus;
  session.tenant = session.realm === 'system' ? entryContext.tenant || session.tenant : null;
  switchPreferencesScope(session.realm, current.user.id, session.impersonating, current.identity.actor_id, current.identity.tenant_id);
  return { tenant: session.tenant, permissions: current.permissions };
}
export async function logout() { await request(`/${session.realm}/auth/logout`, { method: 'POST', data: {} }); clearSession(); }
export async function consumeHandoff(code: string) {
  session.realm = 'system'; session.tenant = entryContext.tenant;
  const result = await request<{ accessToken: string }>(`/system/auth/handoff`, { method: 'POST', data: { code }, public: true });
  session.token = result.accessToken; sessionStorage.setItem(sessionKey(), result.accessToken);
  await loadUser();
}
