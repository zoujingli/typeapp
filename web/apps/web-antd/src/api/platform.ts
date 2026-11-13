import type { User, AccountRecord, AccessPage } from './contracts';
import { request } from './identity';

/** Tenant 超管直接归属该 Tenant，变更使用账号版本。 */
export interface TenantAdministrator extends User { tenant_id?: string }
/** 平台维护的租户资料；管理员清单仅在相应查询中返回。 */
export interface TenantDetails { id: string; name: string; enabled: number; version: number; created_at: number; subdomain: string | null; administrators?: TenantAdministrator[] }
/** 配置字段展示其来源、可编辑性和密钥属性，不能将脱敏值当作原值。 */
export interface ConfigurationField {
  key: string; label: string; group: string; type: 'string' | 'integer' | 'boolean' | 'enum'; value: string | number | boolean | null;
  editable: boolean; secret: boolean; source: 'process' | 'file' | 'default' | 'unknown'; read_only: boolean; reason: string | null;
  file_present: boolean; options?: string[];
}
/** 可管理配置的版本化视图；restart_required 表示变更需重启生效。 */
export interface ConfigurationView extends Pick<AccessPage<never>, 'permissions' | 'menus' | 'catalog'> {
  version: string; restart_required: boolean; fields: ConfigurationField[]; undeclared: string[];
}
/** 跨入口仅发送60秒交接码，秘密位于浏览器片段，兑换后立即移除。 */
export async function impersonate(user: AccountRecord, tenant: TenantDetails) {
  const result = await request<{ code: string; target: string }>(`/platform/tenants/${tenant.id}/impersonate`, { method: 'POST', data: { version: tenant.version, user_id: user.id, user_version: user.version, entry: tenant.subdomain ? 'subdomain' : 'path' } });
  window.open(`${result.target}/#/system/handoff?code=${encodeURIComponent(result.code)}`, '_blank', 'noopener,noreferrer');
}
