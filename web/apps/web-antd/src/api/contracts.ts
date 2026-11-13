
/** Platform 与 System 拥有独立登录域及会话。 */
export type Realm = 'platform' | 'system';
/** 认证后的账号摘要；可选字段由登录域决定，不能据此跳过服务端授权。 */
export interface User { id: string; login: string; name: string; realm?: Realm; version?: number }
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
export interface SiteSettingsView extends Omit<PublicSiteSettings, 'environment'> { version: number; updated_at: number; changed?: string[] }
/** 当前登录、原始操作者与模拟身份的绑定；key 用于隔离未决操作。 */
export interface IdentityContext { realm: Realm; actor_id: string; actor_realm: Realm; actor_name: string; user_id: string; session_id: string; source_session_id: string; impersonation_id: string; tenant_id: string; expires_at: number; key: string }
/** 登录前可选择的 Tenant 摘要，不承载账号授权。 */
export interface Tenant { id: string; name: string; version?: number }
/** 单个审计事件；关联标识缺失与明确为空均保留服务端原值。 */
export interface Audit { id: string; action: string; actor_id: string; subject_id: string; result: string; details: Record<string, unknown>; created_at: number; tenant_id?: string | null; operation_id?: string | null; request_id?: string | null; stage?: string | null; category?: string; audit_realm?: 'platform' | 'system' }
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
/** 按页码返回的列表；总数语义由各接口的数据快照约束决定。 */
export interface Page<T> { items: T[]; total: number; page: number; per_page: number }
/** 当前身份域的角色与并发版本；受保护角色的限制由后端执行。 */
export interface RoleRecord { id: string; name: string; enabled: number; protected: number; version: number; created_at: number; permissions: string[]; menu_ids: string[] }
/** 平台账号或系统账号管理记录；角色清单按查询权限返回。 */
export interface AccountRecord { id: string; login: string; name: string; enabled: number; version: number; recovery_verified: number; created_at: number; roles?: RoleRecord[] }
/** 管理列表附带当前获准的权限目录和菜单。 */
export interface AccessPage<T> extends Page<T> { permissions: string[]; catalog: Record<string, string>; menus: Menu[] }
