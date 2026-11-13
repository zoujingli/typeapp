
/** 租户内产品资料；version 用于乐观并发变更。 */
export interface Product { id: string; tenant_id: string; name: string; description: string; version: number; created_at: number; updated_at: number }
/** 基于观察时间的连接状态；unknown 不等于离线。 */
export interface DeviceConnection { status: 'online' | 'offline' | 'unknown'; observed_at: number | null; broker_observed_at: number | null }
/** 设备授权动作的执行进度，pending 时不能声称撤权已完成。 */
export interface DeviceAuthorization { status: 'pending' | 'enforced'; id?: string | null; requested_at?: number | null; completed_at?: number | null; node_id?: string | null }
/** 设备业务生命周期与连接观察分开记录，并绑定具体使用关系。 */
export interface Device { id: string; tenant_id: string; product_id: string | null; ownership_id: string; name: string; lifecycle: 'inactive' | 'enabled' | 'disabled' | 'retired'; connection: DeviceConnection; authorization?: DeviceAuthorization; recovery_verified?: number; version: number; created_at: number; updated_at: number; transfer_id?: string | null; transfer_frozen?: number }
/** 设备管理接口支持的明确动作，页面按授权及当前状态筛选。 */
export type DeviceAction = 'update' | 'rotate' | 'revoke' | 'disable' | 'enable' | 'retire';
/** 设备管理详情中的凭据可用性和当前使用关系。 */
export interface DeviceManagement extends Device { credential_active: boolean; tenant_name?: string; tenant_enabled?: number; usage?: { started_at: number; ended_at: number | null; expires_at: number | null; available: boolean } }
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
