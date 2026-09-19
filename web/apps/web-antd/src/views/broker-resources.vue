<script setup lang="ts">
import type { TableColumnsType } from 'ant-design-vue';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Alert, Button, Card, Descriptions, DescriptionsItem, Input, Modal, Select, Table, Tag } from 'ant-design-vue';
import AppDrawer from '../components/app-drawer/app-drawer.vue';
import CrudSearchField from '../components/crud-search-field.vue';
import CrudTableActions from '../components/crud-table-actions.vue';
import { buildTableScrollX, estimateVisibleActionColumnWidth } from '../utils/table';
import { ApiError, errorText, isCanceled, request, session } from '../api';

type Resource = 'nodes' | 'connections' | 'sessions' | 'subscriptions' | 'retained' | 'backlog';
interface Item { id: string; state: string; source: string; [key: string]: unknown }
interface ResourcePage { items: Item[]; next_cursor: string | null; has_more: boolean; total: null; observed_at: number; source: string }
interface Detail { found: boolean; item: Item; observed_at: number; source: string }
const route = useRoute();
const router = useRouter();
const platform = computed(() => session.realm === 'admin');
const tenant = computed(() => session.realm === 'customer' && !platform.value ? session.tenant?.id : undefined);
const base = computed(() => session.realm === 'broker' ? '/broker' : platform.value ? '/admin/broker' : `/customer/tenants/${tenant.value}/broker`);
const canRead = computed(() => session.realm === 'broker' ? !!session.user?.platform_admin : session.permissions.includes(`${session.realm}.broker.read`));
const canWrite = computed(() => session.realm === 'broker' ? !!session.user?.platform_admin : session.permissions.includes(`${session.realm}.broker.write`));
const resources: { label: string; value: Resource }[] = [
  { label: '节点', value: 'nodes' }, { label: '连接', value: 'connections' }, { label: '会话', value: 'sessions' },
  { label: '订阅', value: 'subscriptions' }, { label: '保留消息', value: 'retained' }, { label: '积压', value: 'backlog' },
];
const resource = ref<Resource>('connections');
const result = ref<ResourcePage | null>(null);
const detail = ref<Detail | null>(null);
const filters = reactive({ client_id: '', node_id: '', session_id: '', topic: '', state: '', qos: '' });
const applied = ref<Record<string, string>>({});
const limit = ref(20);
const cursors = ref<(string | null)[]>([null]);
const page = ref(0);
const busy = ref(false);
const polling = ref(false);
const detailBusy = ref(false);
const disconnecting = ref(false);
const terminating = ref(false);
const clearing = ref(false);
const controlsDisabled = computed(() => !canRead.value || detailBusy.value || disconnecting.value || terminating.value || clearing.value || busy.value && !polling.value);
const denied = ref(false);
const failure = ref('');
const now = ref(Date.now() / 1000);
let version = 0;
let pending: AbortController | null = null;
let timer: ReturnType<typeof setInterval> | undefined;
let clock: ReturnType<typeof setInterval> | undefined;
const fieldNames: Record<string, string> = {
  id: '资源标识', client_id: '客户端标识', client_id_bytes: '客户端原字节数', owner_id: '连接所有者', node_id: '节点标识',
  run_id: '采样运行', node_run_id: '持久运行', observation_run: '采样运行', node_generation: '节点代次', session_id: '会话标识', session_generation: '会话代次',
  resource_scope: '资源归属', access_identity: '接入身份', principal_id: '稳定主体', credential_id: '凭据标识', credential_version: '凭据代次', authentication_method: '认证方法',
  protocol: '协议版本', transport: '传输方式', durable: '持久管理', state: '观察状态', source: '数据来源', topic: '消息主题', topic_bytes: '主题原字节数',
  filter: '订阅过滤器', filter_bytes: '过滤器原字节数', qos: 'QoS', options: '订阅选项', subscription_identifier: '订阅标识', shared: '共享订阅',
  expiry: '会话保留秒数', expires_at: '到期时间', broker_expires_at: '节点观察到期', observed_at: '采样时间', broker_observed_at: '节点采样时间',
  updated_at: '更新时间', created_at: '创建时间', connection_confirmed: '已观察到连接', connections: '范围内连接数', counts: '授权范围计数',
  pending_messages: '积压份数', pending_bytes: '积压字节', pending_qos1: 'QoS1 积压', pending_qos2: 'QoS2 积压', pending_shared: '共享积压',
  shared_qos2_inflight: '共享 QoS2 在途', will_pending: '待发遗嘱', subscriptions: '订阅数', byte_size: '原件字节', generation: '原件代次',
  pending_deliveries: '在途交付', snapshot_references: '快照引用',
  kind: '记录类型', message_id: '消息标识', phase: '投递阶段', started: '已开始交付', capacity_class: '容量分类',
};
const stateNames: Record<string, string> = { connected: '已观察到连接', unknown: '连接状态未知', owner_claim: '持久所有者待核对', offline: '无持久所有者',
  expired: '已过期', subscribed: '已登记订阅', retained: '保留原件', accepted: '已接纳原件', pending: '交付待完成', reporting: '正在上报', stopped: '已停止', unreachable: '节点暂不可达' };
const sourceNames: Record<string, string> = { live_observation: '连接采样', durable_store: '持久状态', durable_owner: '持久所有者' };
const visibleFields = computed(() => ({ nodes: ['node_id', 'state'], connections: ['client_id', 'node_id', 'session_id', 'state'],
  sessions: ['client_id', 'node_id', 'session_id', 'state'], subscriptions: ['client_id', 'node_id', 'session_id', 'state', 'qos'],
  retained: ['topic', 'state', 'qos'], backlog: ['topic', 'session_id', 'state', 'qos'] })[resource.value]);
const stateOptions = computed(() => ({ nodes: ['reporting', 'stopped', 'unreachable'], connections: ['connected', 'unknown'],
  sessions: ['owner_claim', 'offline', 'expired'], subscriptions: ['subscribed', 'unknown'], retained: ['retained', 'expired'], backlog: ['accepted', 'pending'] })[resource.value]
  .map(value => ({ value, label: stateNames[value] })));
const expired = computed(() => !!result.value && now.value - result.value.observed_at >= 15);
const columns = computed<TableColumnsType<Item>>(() => [
  { title: resource.value === 'nodes' ? '节点标识' : resource.value === 'retained' || resource.value === 'backlog' ? '消息主题' : '客户端标识',
    dataIndex: resource.value === 'nodes' ? 'node_id' : resource.value === 'retained' || resource.value === 'backlog' ? 'topic' : 'client_id', width: 260, ellipsis: true },
  ...(resource.value === 'subscriptions' ? [{ title: '订阅过滤器', dataIndex: 'filter', width: 280, ellipsis: true }] : []),
  { title: '资源标识', dataIndex: 'id', width: 260, ellipsis: true }, { title: '当前状态', key: 'state', width: 190 },
  { title: resource.value === 'nodes' ? '范围内连接' : '节点 / QoS', key: 'value', width: 170 },
  { title: '数据来源', key: 'source', width: 130 },
  { title: '操作', key: 'actions', fixed: 'right', width: estimateVisibleActionColumnWidth([{ label: '详情' }, { label: '断开' }, { label: '终止' }, { label: '清除' }]) },
]);
function display(value: unknown, key = ''): string {
  if (value === null || value === undefined || value === '') return '—';
  if (key === 'state') return stateNames[String(value)] || String(value);
  if (key === 'source') return sourceNames[String(value)] || String(value);
  if (key === 'protocol') return value === 5 ? 'MQTT 5.0' : value === 4 ? 'MQTT 3.1.1' : String(value);
  if (typeof value === 'boolean') return value ? '是' : '否';
  if (key.endsWith('_at') && (typeof value === 'number' || typeof value === 'string')) return new Date(typeof value === 'number' ? value * 1000 : value).toLocaleString();
  if (typeof value === 'object') return Object.entries(value).map(([name, entry]) => `${fieldNames[name] || name}：${display(entry, name)}`).join('\n');
  return String(value);
}
function stale(item: Item, observedAt = result.value?.observed_at ?? 0) {
  const deadline = item.broker_expires_at ?? item.expires_at;
  return now.value - observedAt >= 15 || item.source === 'live_observation' && deadline !== undefined && Number(deadline) <= now.value;
}
function state(item: Item, observedAt = result.value?.observed_at ?? 0) {
  if (stale(item, observedAt)) return '观察已过期，当前未知';
  return display(item.state, 'state');
}
function detailValue(value: unknown, key: string) {
  if (!detail.value) return '—';
  if (key === 'state') return state(detail.value.item, detail.value.observed_at);
  if (stale(detail.value.item, detail.value.observed_at) && ['connections', 'connection_confirmed', 'counts'].includes(key)) return '观察已过期，当前未知';
  return display(value, key);
}
function resetFilters() { Object.assign(filters, { client_id: '', node_id: '', session_id: '', topic: '', state: '', qos: '' }); applied.value = {}; }
function clear() { version++; pending?.abort(); pending = null; busy.value = false; polling.value = false; detailBusy.value = false; disconnecting.value = false; terminating.value = false; clearing.value = false; result.value = null; detail.value = null; cursors.value = [null]; page.value = 0; }
async function load(nextPage = page.value, cursor = cursors.value[nextPage] ?? null, background = false) {
  if (!canRead.value || busy.value && (!polling.value || background) || detailBusy.value || document.visibilityState !== 'visible' || session.realm === 'customer' && !tenant.value) return;
  const current = ++version;
  pending?.abort();
  busy.value = true; polling.value = background; pending = new AbortController();
  try {
    const data = await request<ResourcePage>(`${base.value}/resources/${resource.value}`, { tenant: tenant.value, signal: pending.signal, params: { ...applied.value, limit: limit.value, cursor: cursor || undefined } });
    if (current !== version) return;
    result.value = data; page.value = nextPage; cursors.value[nextPage] = cursor; failure.value = ''; denied.value = false; now.value = Date.now() / 1000;
  } catch (error) {
    if (current === version && !isCanceled(error)) {
      failure.value = errorText(error); denied.value = error instanceof ApiError && error.status === 403;
      if (denied.value) { result.value = null; detail.value = null; cursors.value = [null]; page.value = 0; }
    }
  } finally { if (current === version) { busy.value = false; polling.value = false; pending = null; } }
}
async function show(item: Item) {
  if (!canRead.value || busy.value || detailBusy.value || denied.value || document.visibilityState !== 'visible') return;
  const current = ++version;
  detailBusy.value = true; pending = new AbortController();
  try {
    const data = await request<Detail>(`${base.value}/resources/${resource.value}/${encodeURIComponent(item.id)}`, { tenant: tenant.value, signal: pending.signal });
    if (current === version) { detail.value = data; failure.value = ''; }
  } catch (error) {
    if (current === version && !isCanceled(error)) {
      detail.value = null; failure.value = errorText(error); denied.value = error instanceof ApiError && error.status === 403;
      if (denied.value) result.value = null;
    }
  } finally { if (current === version) { detailBusy.value = false; pending = null; } }
}
function actions(item: Item) {
  return [
    { label: '详情', disabled: busy.value || detailBusy.value || disconnecting.value || terminating.value || clearing.value, onClick: () => show(item) },
    { label: '断开', visible: resource.value === 'connections' && canWrite.value && item.state === 'connected' && !stale(item),
      danger: true, disabled: busy.value || detailBusy.value || disconnecting.value || terminating.value || clearing.value,
      confirmTitle: `确认断开连接“${String(item.client_id)}”？`,
      confirmContent: '将发送管理断开并保留仍有效的持久会话。凭据未撤销的客户端可以重连。不会终止会话或清除保留消息。',
      confirmOkText: '断开连接', onClick: () => disconnect(item) },
    { label: '终止', visible: resource.value === 'sessions' && canWrite.value && !stale(item),
      danger: true, disabled: busy.value || detailBusy.value || disconnecting.value || terminating.value || clearing.value,
      onClick: () => terminate(item) },
    { label: '清除', visible: resource.value === 'retained' && canWrite.value && !stale(item),
      danger: true, disabled: busy.value || detailBusy.value || disconnecting.value || terminating.value || clearing.value,
      onClick: () => clearRetained(item) },
  ];
}
async function disconnect(item: Item) {
  if (!canWrite.value || resource.value !== 'connections' || busy.value || detailBusy.value || disconnecting.value || terminating.value || clearing.value || denied.value) return;
  const current = ++version;
  disconnecting.value = true; pending = new AbortController();
  try {
    const submitted = await request<{ operation_id: string }>(`${base.value}/resources/connections/${encodeURIComponent(item.id)}/disconnect`, {
      method: 'POST', tenant: tenant.value, signal: pending.signal,
      data: { session_id: item.session_id, session_generation: Number(item.session_generation ?? 0), node_id: item.node_id,
        ...(item.node_run_id ? { node_run_id: item.node_run_id } : {}) },
    });
    const deadline = Date.now() + 8000;
    let stage = 'accepted';
    while (Date.now() < deadline) {
      const status = await request<{ stage: string; result: string; outcome: string }>(`${base.value}/operations/${submitted.operation_id}`, { tenant: tenant.value, signal: pending.signal });
      stage = status.stage;
      if (status.stage === 'completed' || status.stage === 'failed') break;
      await new Promise(resolve => setTimeout(resolve, 400));
    }
    if (current !== version) return;
    if (stage !== 'completed') failure.value = '断开尚未完成，连接状态仍待节点执行';
    else failure.value = '';
    denied.value = false;
    detail.value = null;
    disconnecting.value = false; pending = null;
    await load(page.value, cursors.value[page.value] ?? null);
  } catch (error) {
    if (current === version && !isCanceled(error)) {
      failure.value = errorText(error); denied.value = error instanceof ApiError && error.status === 403;
    }
  } finally { if (current === version) { disconnecting.value = false; pending = null; } }
}
async function terminate(item: Item) {
  if (!canWrite.value || resource.value !== 'sessions' || busy.value || detailBusy.value || disconnecting.value || terminating.value || clearing.value || denied.value) return;
  const current = ++version;
  terminating.value = true; pending = new AbortController();
  try {
    const preview = await request<{
      session_id: string; session_generation: number; counts: Record<string, number>; impact: Record<string, unknown>;
      subscriptions: { filter: string; qos: number; shared: boolean }[];
    }>(`${base.value}/resources/sessions/${encodeURIComponent(item.id)}/termination-preview`, { tenant: tenant.value, signal: pending.signal });
    if (current !== version) return;
    const counts = preview.counts || {};
    const confirmed = await new Promise<boolean>((resolve) => {
      Modal.confirm({
        title: `确认终止会话“${String(item.client_id)}”？`,
        content: `将结束该持久会话并放弃未完成交付。订阅 ${counts.subscriptions ?? 0} 条，积压 ${counts.pending_messages ?? 0} 份（QoS1 ${counts.pending_qos1 ?? 0} / QoS2 ${counts.pending_qos2 ?? 0}），待发遗嘱 ${counts.will_pending ?? 0}。不会影响其他会话或共享组仍在途的 QoS2 交换。`,
        okText: '终止会话', okType: 'danger', cancelText: '取消',
        onOk: () => resolve(true), onCancel: () => resolve(false),
      });
    });
    if (!confirmed || current !== version) return;
    const submitted = await request<{ operation_id: string }>(`${base.value}/resources/sessions/${encodeURIComponent(item.id)}/terminate`, {
      method: 'POST', tenant: tenant.value, signal: pending.signal,
      data: { session_generation: Number(item.session_generation ?? preview.session_generation ?? 0), node_id: item.node_id,
        confirmed: true, ...(item.node_run_id ? { node_run_id: item.node_run_id } : {}) },
    });
    const deadline = Date.now() + 12000;
    let stage = 'accepted';
    while (Date.now() < deadline) {
      const status = await request<{ stage: string; result: string; outcome: string }>(`${base.value}/operations/${submitted.operation_id}`, { tenant: tenant.value, signal: pending.signal });
      stage = status.stage;
      if (status.stage === 'completed' || status.stage === 'failed') break;
      await new Promise(resolve => setTimeout(resolve, 400));
    }
    if (current !== version) return;
    if (stage !== 'completed') failure.value = '终止尚未完成，会话状态仍待节点执行';
    else failure.value = '';
    denied.value = false;
    detail.value = null;
    terminating.value = false; pending = null;
    await load(page.value, cursors.value[page.value] ?? null);
  } catch (error) {
    if (current === version && !isCanceled(error)) {
      failure.value = errorText(error); denied.value = error instanceof ApiError && error.status === 403;
    }
  } finally { if (current === version) { terminating.value = false; pending = null; } }
}
async function clearRetained(item: Item) {
  if (!canWrite.value || resource.value !== 'retained' || busy.value || detailBusy.value || disconnecting.value || terminating.value || clearing.value || denied.value) return;
  const current = ++version;
  clearing.value = true; pending = new AbortController();
  try {
    const preview = await request<{
      topic: string; generation: number; qos: number; byte_size: number; counts: Record<string, number>; impact: Record<string, unknown>;
    }>(`${base.value}/resources/retained/${encodeURIComponent(item.id)}/clearance-preview`, { tenant: tenant.value, signal: pending.signal });
    if (current !== version) return;
    const counts = preview.counts || {};
    const confirmed = await new Promise<boolean>((resolve) => {
      Modal.confirm({
        title: `确认清除保留“${String(item.topic)}”？`,
        content: `将取消该保留原件未来重放，不制造普通发布，也不撤回已有交付。QoS ${preview.qos}，原件 ${preview.byte_size} 字节，代次 ${preview.generation}，在途交付 ${counts.pending_deliveries ?? 0}。并发替换的新原件不会被删除。`,
        okText: '清除保留', okType: 'danger', cancelText: '取消',
        onOk: () => resolve(true), onCancel: () => resolve(false),
      });
    });
    if (!confirmed || current !== version) return;
    const submitted = await request<{ operation_id: string }>(`${base.value}/resources/retained/${encodeURIComponent(item.id)}/clear`, {
      method: 'POST', tenant: tenant.value, signal: pending.signal,
      data: { generation: Number(item.generation ?? preview.generation ?? 0), confirmed: true },
    });
    const deadline = Date.now() + 12000;
    let stage = 'accepted';
    while (Date.now() < deadline) {
      const status = await request<{ stage: string; result: string; outcome: string }>(`${base.value}/operations/${submitted.operation_id}`, { tenant: tenant.value, signal: pending.signal });
      stage = status.stage;
      if (status.stage === 'completed' || status.stage === 'failed') break;
      await new Promise(resolve => setTimeout(resolve, 400));
    }
    if (current !== version) return;
    if (stage !== 'completed') failure.value = '清除尚未完成，保留状态仍待节点执行';
    else failure.value = '';
    denied.value = false;
    detail.value = null;
    clearing.value = false; pending = null;
    await load(page.value, cursors.value[page.value] ?? null);
  } catch (error) {
    if (current !== version && !isCanceled(error)) {
      failure.value = errorText(error); denied.value = error instanceof ApiError && error.status === 403;
    }
  } finally { if (current === version) { clearing.value = false; pending = null; } }
}
function search(reset = false) {
  if (busy.value || detailBusy.value) return;
  if (reset) resetFilters();
  applied.value = Object.fromEntries(visibleFields.value.map(field => [field, filters[field as keyof typeof filters]]).filter(([, value]) => value !== ''));
  clear(); void load(0);
}
function visibility() {
  clearInterval(timer);
  clearInterval(clock);
  if (document.visibilityState === 'visible') {
    now.value = Date.now() / 1000; void load();
    clock = setInterval(() => { now.value = Date.now() / 1000; }, 1000);
    timer = setInterval(() => { if (!denied.value) void load(page.value, cursors.value[page.value] ?? null, true); }, 5000);
  } else { version++; pending?.abort(); pending = null; busy.value = false; polling.value = false; detailBusy.value = false; disconnecting.value = false; terminating.value = false; clearing.value = false; }
}
watch(resource, () => { clear(); resetFilters(); denied.value = false; failure.value = ''; void load(0); });
watch(limit, () => { clear(); void load(0); });
watch(() => [session.generation, session.realm, session.tenant?.id, session.identity?.key, canRead.value, route.path], () => {
  clear(); resetFilters(); failure.value = ''; denied.value = false;
  if (!['/broker-resources', '/admin/broker', '/broker/resources'].includes(route.path)) return;
  if (session.realm === 'customer' && !tenant.value) void router.replace('/tenants'); else void load(0);
}, { immediate: true });
onMounted(() => { document.addEventListener('visibilitychange', visibility); visibility(); });
onBeforeUnmount(() => { clear(); clearInterval(timer); clearInterval(clock); document.removeEventListener('visibilitychange', visibility); });
</script>

<template>
  <section class="iot-page">
    <div class="page-heading"><div><h1>Broker 资源</h1><p class="muted">{{ platform ? '平台运行元数据' : session.realm === 'broker' ? '独立 Broker 运行元数据' : '当前租户获准的运行元数据' }}</p></div></div>
    <Alert v-if="failure" class="page-alert" type="error" show-icon role="alert" :message="denied ? failure : `资源刷新失败：${failure}`" />
    <Alert v-if="!canRead" class="page-alert" type="warning" show-icon message="当前账号无权查看 Broker 资源" />
    <Alert v-if="expired" class="page-alert" type="warning" show-icon message="观察已过期，当前连接和投递状态未知" />
    <Card class="search-card">
      <div class="crud-search-grid">
        <CrudSearchField label="资源类型"><Select v-model:value="resource" aria-label="资源类型" :disabled="controlsDisabled" :options="resources" /></CrudSearchField>
        <CrudSearchField v-if="visibleFields.includes('client_id')" label="客户标识"><Input v-model:value="filters.client_id" aria-label="客户标识筛选" :disabled="controlsDisabled" :maxlength="65535" allow-clear @press-enter="search()" /></CrudSearchField>
        <CrudSearchField v-if="visibleFields.includes('node_id')" label="节点标识"><Input v-model:value="filters.node_id" aria-label="节点标识筛选" :disabled="controlsDisabled" :maxlength="64" allow-clear @press-enter="search()" /></CrudSearchField>
        <CrudSearchField v-if="visibleFields.includes('session_id')" label="会话标识"><Input v-model:value="filters.session_id" aria-label="会话标识筛选" :disabled="controlsDisabled" :maxlength="32" allow-clear @press-enter="search()" /></CrudSearchField>
        <CrudSearchField v-if="visibleFields.includes('topic')" label="消息主题"><Input v-model:value="filters.topic" aria-label="消息主题筛选" :disabled="controlsDisabled" :maxlength="65535" allow-clear @press-enter="search()" /></CrudSearchField>
        <CrudSearchField label="资源状态"><Select v-model:value="filters.state" aria-label="资源状态筛选" :disabled="controlsDisabled" :options="[{ value: '', label: '全部状态' }, ...stateOptions]" /></CrudSearchField>
        <CrudSearchField v-if="visibleFields.includes('qos')" label="服务质量"><Select v-model:value="filters.qos" aria-label="服务质量筛选" :disabled="controlsDisabled" :options="[{ value: '', label: '全部 QoS' }, ...[0, 1, 2].map(value => ({ value: String(value), label: `QoS ${value}` }))]" /></CrudSearchField>
        <div class="crud-search-grid__actions"><Button type="primary" :loading="busy" :disabled="!canRead || detailBusy" @click="search()">查询</Button><Button :disabled="!canRead || busy || detailBusy" @click="search(true)">重置</Button></div>
      </div>
    </Card>
    <Card>
      <div class="table-toolbar"><span class="muted">采样时间：{{ result ? display(result.observed_at, 'observed_at') : '尚无数据' }}{{ busy ? ' · 刷新中' : '' }}</span><Button :loading="busy" :disabled="!canRead || detailBusy" @click="load()">刷新</Button></div>
      <Table :columns="columns" :data-source="result?.items || []" :loading="busy" :pagination="false" :scroll="buildTableScrollX(columns)" row-key="id">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'state'"><Tag :color="stale(record as Item) || ['unknown', 'owner_claim', 'unreachable'].includes(record.state) ? 'warning' : 'default'">{{ state(record as Item) }}</Tag></template>
          <template v-else-if="column.key === 'source'">{{ display(record.source, 'source') }}</template>
          <template v-else-if="column.key === 'value'">{{ resource === 'nodes' ? display(stale(record as Item) ? null : record.connections) : display(record.node_id ?? record.qos) }}</template>
          <template v-else-if="column.key === 'actions'"><CrudTableActions :actions="actions(record as Item)" /></template>
        </template>
      </Table>
      <div class="table-toolbar resource-pagination">
        <span class="muted">第 {{ page + 1 }} 页 · 本页 {{ result?.items.length || 0 }} 项</span>
        <div class="crud-search-grid__actions"><Select v-model:value="limit" aria-label="每页条数" :disabled="controlsDisabled" :options="[20, 50, 100].map(value => ({ value, label: `${value} 条 / 页` }))" />
          <Button :disabled="controlsDisabled || page === 0" @click="load(page - 1)">上一页</Button><Button :disabled="controlsDisabled || !result?.has_more" @click="load(page + 1, result?.next_cursor || null)">下一页</Button></div>
      </div>
    </Card>
    <AppDrawer :open="!!detail" title="Broker 资源详情" :ok-visible="false" :show-footer="false" :mask-closable="true" @close="detail = null">
      <template v-if="detail"><p class="muted">详情保留打开时的采样：{{ display(detail.observed_at, 'observed_at') }}{{ stale(detail.item, detail.observed_at) ? ' · 已过期，当前状态未知' : '' }}</p>
        <Descriptions bordered :column="{ xs: 1, sm: 1, md: 2 }" layout="vertical" size="small">
          <DescriptionsItem v-for="(value, key) in detail.item" :key="key" :label="fieldNames[key] || key"><span class="resource-value">{{ detailValue(value, key) }}</span></DescriptionsItem>
        </Descriptions>
      </template>
    </AppDrawer>
  </section>
</template>

<style scoped>
.resource-value { white-space: pre-wrap; overflow-wrap: anywhere; }
.resource-pagination { margin-top: 16px; flex-wrap: wrap; gap: 12px; }
</style>
