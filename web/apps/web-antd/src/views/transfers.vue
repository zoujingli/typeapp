<script setup lang="ts">
import type { TableColumnsType } from 'ant-design-vue';
import type { Device, DeviceCredential, DeviceTransfer, ModelDefinition, ModelVersion, Page, Product, TransferProvision } from '../api';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Alert, Button, Card, Descriptions, DescriptionsItem, Empty, Form, FormItem, Input, InputNumber, Pagination, Select, Table, Tag, message } from 'ant-design-vue';
import AppDrawer from '../components/app-drawer/app-drawer.vue';
import CrudSearchField from '../components/crud-search-field.vue';
import CrudTableActions from '../components/crud-table-actions.vue';
import DefinitionEditor from '../components/model-definition.vue';
import { buildTableScrollX, estimateVisibleActionColumnWidth } from '../utils/table';
import { ApiError, applyAdminContext, errorText, isCanceled, request, session, transferPendingNames, transferStatusNames } from '../api';

const route = useRoute(); const router = useRouter();
const result = ref<Page<DeviceTransfer>>({ items: [], total: 0, page: 1, per_page: 20 });
const filters = reactive({ direction: 'source', status: '', device_id: typeof route.query.device === 'string' ? route.query.device : '' });
let applied = { ...filters };
const busy = ref(false); const failure = ref(''); const refreshedAt = ref<number | null>(null);
const selected = ref<DeviceTransfer | null>(null); const detailOpen = ref(false); const detailBusy = ref(false); const detailFailure = ref('');
type Decision = 'request' | 'accept' | 'reject' | 'cancel';
const formOpen = ref(false); const saving = ref(false); const saveFailure = ref(''); const mode = ref<Decision>('request');
const form = reactive({ device_id: '', target_tenant_id: '', version: undefined as number | undefined, matching: 'copy', copy_name: '', target_product_id: '', target_model_version: undefined as number | undefined });
const products = ref<Page<Product>>({ items: [], total: 0, page: 1, per_page: 20 });
const models = ref<Page<ModelVersion>>({ items: [], total: 0, page: 1, per_page: 20 });
const optionsBusy = ref(false); const optionFailure = ref('');
const can = (action: string) => session.realm === 'customer' && session.permissions.includes(`customer.transfers.${action}`);
const canRead = computed(() => can('read'));
const canProducts = computed(() => session.permissions.includes('customer.products.read'));
const canCopy = computed(() => session.permissions.includes('customer.products.manage'));
const identityKey = () => `${session.realm}:${session.token}:${session.tenant?.id}`;
const definition = ref<ModelDefinition>({ properties: [], events: [], commands: [] });
interface TransferSubmission { tenant: string; identity: string; path: string; data: Record<string, unknown> }
const pendingSubmission = ref<TransferSubmission | null>(null);
const switchingOpen = ref(false);
const issued = ref<{ credential: DeviceCredential; provisioning: TransferProvision } | null>(null);
const storageKey = () => `typeapp.customer.transfer-intent.${session.identity?.key}.${session.tenant?.id}`;
let serial = 0; let detailSerial = 0; let optionSerial = 0; let active = true;
let pending: AbortController | null = null; let detailPending: AbortController | null = null; let optionPending: AbortController | null = null;
let timer: ReturnType<typeof setTimeout> | undefined;
let productSearch = '';
const columns: TableColumnsType<DeviceTransfer> = [
  { title: '设备名称', dataIndex: 'device_name', width: 230, ellipsis: true }, { title: '设备标识', dataIndex: 'device_id', width: 290, ellipsis: true },
  { title: '源租户', dataIndex: 'source_tenant_id', width: 290, ellipsis: true }, { title: '目标租户', dataIndex: 'target_tenant_id', width: 290, ellipsis: true },
  { title: '审批状态', key: 'status', width: 180 }, { title: '申请时间', key: 'created', width: 200 },
  { title: '操作', key: 'actions', fixed: 'right', width: estimateVisibleActionColumnWidth([{ label: '查看处理' }]) },
];
const selectedTarget = computed(() => models.value.items.find(item => item.model_version === form.target_model_version));
const mismatch = computed(() => form.matching === 'existing' && Boolean(selectedTarget.value) && selectedTarget.value?.structure_hash !== selected.value?.structure_hash);
const ready = computed(() => Boolean(pendingSubmission.value) || (Number.isInteger(form.version) && Number(form.version) > 0 && (mode.value === 'request' ? /^[a-f0-9]{32}$/.test(form.device_id) && /^[a-f0-9]{32}$/.test(form.target_tenant_id)
  : mode.value !== 'accept' || (form.matching === 'copy' ? canCopy.value && Boolean(form.copy_name.trim()) : /^[a-f0-9]{32}$/.test(form.target_product_id) && Number.isInteger(form.target_model_version) && Number(form.target_model_version) > 0 && !mismatch.value))));
function time(value: number | null) { return value === null ? '尚无记录' : new Date(Number(value) * 1000).toLocaleString(); }
/** 每轮完成后安排刷新；不可见或卸载后不继续轮询跨租户转移状态。 */
function schedule() { clearTimeout(timer); if (active && canRead.value && !document.hidden) timer = setTimeout(() => void load(result.value.page, true), 5000); }
async function load(page = 1, refresh = false, perPage = result.value.per_page) {
  const tenant = session.tenant?.id; if (!tenant || !canRead.value || !active || document.hidden || saving.value) { schedule(); return; }
  const current = ++serial; pending?.abort(); pending = new AbortController(); busy.value = true; failure.value = ''; clearTimeout(timer);
  if (!refresh) { result.value.items = []; refreshedAt.value = null; }
  try {
    const data = await request<Page<DeviceTransfer> & { context: { permissions: string[]; menus: typeof session.menus } }>(`/customer/tenants/${tenant}/transfers`, { tenant, signal: pending.signal, params: { ...applied, page, per_page: perPage } });
    if (current !== serial) return;
    result.value = data; applyAdminContext(data.context); refreshedAt.value = Date.now();
    if (detailOpen.value && selected.value && !formOpen.value) await read(selected.value.id, false);
  } catch (error) { if (!isCanceled(error) && current === serial) failure.value = errorText(error); }
  finally { if (current === serial) { busy.value = false; schedule(); } }
}
async function read(id: string, open = true) {
  const tenant = session.tenant?.id; if (!tenant || !canRead.value) return;
  const current = ++detailSerial; detailPending?.abort(); detailPending = new AbortController(); detailBusy.value = true; detailFailure.value = '';
  if (open) detailOpen.value = true;
  try {
    const data = await request<DeviceTransfer>(`/customer/tenants/${tenant}/transfers/${id}`, { tenant, signal: detailPending.signal });
    if (current !== detailSerial) return;
    selected.value = data; definition.value = data.source_definition || { properties: [], events: [], commands: [] };
  } catch (error) { if (!isCanceled(error) && current === detailSerial) detailFailure.value = errorText(error); }
  finally { if (current === detailSerial) detailBusy.value = false; }
}
function search(reset = false) { if (busy.value) return; if (reset) Object.assign(filters, { direction: 'source', status: '', device_id: '' }); applied = { ...filters }; void load(); }
function start(action: Decision) {
  if (!can(action) || saving.value) return;
  mode.value = action; saveFailure.value = ''; optionFailure.value = ''; pendingSubmission.value = null;
  const saved = sessionStorage.getItem(storageKey());
  let restored: TransferSubmission | null = null;
  if (saved) {
    try { const value = JSON.parse(saved) as TransferSubmission; if (value.identity === session.identity?.key && value.tenant === session.tenant?.id && value.path.startsWith(`/customer/tenants/${value.tenant}/transfers`) && value.data && can(String(value.data.action || 'request'))) restored = value; } catch { sessionStorage.removeItem(storageKey()); }
  }
  pendingSubmission.value = restored;
  if (restored) mode.value = restored.data.action as Decision || 'request';
  Object.assign(form, { device_id: typeof route.query.device === 'string' ? route.query.device : '', target_tenant_id: '', version: action === 'request' ? undefined : selected.value?.device_version, matching: canCopy.value ? 'copy' : 'existing', copy_name: selected.value ? `${selected.value.device_name}接收模型`.slice(0, 100) : '', target_product_id: '', target_model_version: undefined });
  if (restored) {
    Object.assign(form, { device_id: String(restored.data.device_id || ''), target_tenant_id: String(restored.data.target_tenant_id || ''),
      version: Number(restored.data.version), target_model_version: restored.data.target_model_version, matching: restored.data.copy_name ? 'copy' : 'existing', copy_name: String(restored.data.copy_name || ''), target_product_id: String(restored.data.target_product_id || '') });
    if (mode.value !== 'request') void read(restored.path.split('/').at(-1) || '', false);
  }
  formOpen.value = true;
}
async function options(kind: 'products' | 'models', page = 1, name = productSearch) {
  const tenant = session.tenant?.id; if (!tenant || !canProducts.value || (kind === 'models' && !form.target_product_id)) return;
  const current = ++optionSerial; optionPending?.abort(); optionPending = new AbortController(); optionsBusy.value = true; optionFailure.value = '';
  if (kind === 'products') productSearch = name;
  try {
    const data = await request<Page<Product> | Page<ModelVersion>>(`/customer/tenants/${tenant}/products${kind === 'models' ? `/${form.target_product_id}/models` : ''}`, { tenant, signal: optionPending.signal, params: { page, per_page: 20, ...(kind === 'products' ? { name } : {}) } });
    if (current !== optionSerial) return;
    if (kind === 'products') products.value = data as Page<Product>; else models.value = data as Page<ModelVersion>;
  } catch (error) { if (!isCanceled(error) && current === optionSerial) optionFailure.value = errorText(error); }
  finally { if (current === optionSerial) optionsBusy.value = false; }
}
async function submit() {
  const tenant = session.tenant?.id; if (!tenant || saving.value || !can(mode.value) || !ready.value || !session.identity) return;
  const origin = identityKey(); const intentKey = storageKey();
  if (!pendingSubmission.value) {
    const base = `/customer/tenants/${tenant}/transfers`;
    const data = mode.value === 'request' ? { version: form.version, transfer_id: crypto.randomUUID().replaceAll('-', ''), device_id: form.device_id, target_tenant_id: form.target_tenant_id }
      : { version: form.version, action: mode.value, decision_id: crypto.randomUUID().replaceAll('-', ''), ...(mode.value === 'accept' ? form.matching === 'copy' ? { copy_name: form.copy_name.trim() } : { target_product_id: form.target_product_id, target_model_version: form.target_model_version } : {}) };
    pendingSubmission.value = { tenant, identity: session.identity.key, path: mode.value === 'request' ? base : `${base}/${selected.value?.id}`, data };
    sessionStorage.setItem(intentKey, JSON.stringify(pendingSubmission.value));
  }
  saving.value = true; saveFailure.value = '';
  try {
    const intent = pendingSubmission.value;
    const data = await request<DeviceTransfer>(intent.path, { tenant, method: 'POST', data: intent.data });
    if (origin !== identityKey() || !active) return;
    sessionStorage.removeItem(intentKey); pendingSubmission.value = null; formOpen.value = false;
    message.success(data.status === 'frozen' ? '双方审批已保存，新控制已冻结；归属尚未切换。' : data.status === 'rejected' ? '拒绝结果已保存' : data.status === 'cancelled' ? '未决转移已取消，原归属保持不变' : '申请已保存，等待目标管理员审批');
    selected.value = data; detailOpen.value = true; definition.value = data.source_definition || definition.value;
  } catch (error) {
    if (origin !== identityKey() || !active) return;
    if (!isCanceled(error)) saveFailure.value = errorText(error);
    if (error instanceof ApiError && error.status < 500) { sessionStorage.removeItem(intentKey); pendingSubmission.value = null; }
    else saveFailure.value += ' 受理结果尚未确定，请使用原标识确认结果；刷新后仍可恢复原请求。';
  } finally { if (origin === identityKey() && active) { saving.value = false; void load(result.value.page, true); } }
}
async function retry() {
  const tenant = session.tenant?.id; const item = selected.value; if (!tenant || !item || saving.value || !can('retry') || !item.device_version) return;
  const origin = identityKey();
  saving.value = true; detailFailure.value = '';
  try { const data = await request<DeviceTransfer>(`/customer/tenants/${tenant}/transfers/${item.id}`, { tenant, method: 'POST', data: { action: 'retry', version: item.device_version } }); if (origin !== identityKey() || !active) return; selected.value = data; message.success('已受理原冻结请求重发，等待设备确认'); }
  catch (error) { if (origin === identityKey() && active && !isCanceled(error)) detailFailure.value = errorText(error); }
  finally { if (origin === identityKey() && active) saving.value = false; }
}
async function advance() {
  const tenant = session.tenant?.id; const item = selected.value;
  if (!tenant || !item || tenant !== item.target_tenant_id || saving.value || !can('switch')) return;
  const origin = identityKey(); const key = `${storageKey()}.switch.${item.id}`;
  let intent = { switch_id: item.switch_id || crypto.randomUUID().replaceAll('-', ''), version: item.switch_id ? item.switch_version : item.device_version };
  try { const saved = JSON.parse(sessionStorage.getItem(key) || 'null') as typeof intent | null; if (!item.switch_id && saved && /^[a-f0-9]{32}$/.test(saved.switch_id) && Number.isInteger(saved.version)) intent = saved; } catch { sessionStorage.removeItem(key); }
  if (!intent.version) return;
  sessionStorage.setItem(key, JSON.stringify(intent)); saving.value = true; detailFailure.value = '';
  try {
    const data = await request<DeviceTransfer>(`/customer/tenants/${tenant}/transfers/${item.id}`, { tenant, method: 'POST', data: { action: 'switch', ...intent } });
    if (origin !== identityKey() || !active) return;
    selected.value = { ...data, credential: undefined }; switchingOpen.value = false;
    if (data.credential && data.provisioning) issued.value = { credential: data.credential, provisioning: data.provisioning };
    sessionStorage.removeItem(key);
    message.success(data.status === 'isolating' ? '旧凭据已撤销，隔离完成后可继续激活' : data.status === 'completed' ? '设备已确认转移完成' : '新归属已激活，等待设备确认');
  } catch (error) {
    if (origin !== identityKey() || !active) return;
    if (!isCanceled(error)) detailFailure.value = `${errorText(error)} 原请求标识已保留，可重试确认实际阶段。`;
  } finally { if (origin === identityKey() && active) { saving.value = false; void load(result.value.page, true); } }
}
async function readDeviceVersion() {
  const tenant = session.tenant?.id; if (!tenant || !session.permissions.includes('customer.devices.read') || !/^[a-f0-9]{32}$/.test(form.device_id) || saving.value) return;
  const origin = identityKey(); const deviceId = form.device_id; saving.value = true;
  try { const data = await request<Device>(`/customer/tenants/${tenant}/devices/${deviceId}`, { tenant }); if (origin === identityKey() && active && deviceId === form.device_id) form.version = data.version; }
  catch (error) { if (origin === identityKey() && active && !isCanceled(error)) saveFailure.value = errorText(error); }
  finally { if (origin === identityKey() && active) saving.value = false; }
}
function visibility() { clearTimeout(timer); if (document.hidden) { serial++; detailSerial++; pending?.abort(); detailPending?.abort(); busy.value = false; detailBusy.value = false; } else void load(result.value.page, true); }
watch(() => form.target_product_id, () => { models.value = { items: [], total: 0, page: 1, per_page: 20 }; if (!pendingSubmission.value) form.target_model_version = undefined; if (form.target_product_id) void options('models'); });
watch(() => form.matching, value => { if (value === 'existing') void options('products'); });
// 身份变化同时清除三个请求代次和一次性新凭据，避免跨工作区复用转移状态。
watch([() => session.tenant?.id, () => session.token, () => session.realm], () => {
  serial++; detailSerial++; optionSerial++; clearTimeout(timer); pending?.abort(); detailPending?.abort(); optionPending?.abort();
  selected.value = null; issued.value = null; switchingOpen.value = false; detailOpen.value = false; formOpen.value = false; pendingSubmission.value = null;
  saving.value = false; busy.value = false; detailBusy.value = false; optionsBusy.value = false; failure.value = ''; saveFailure.value = ''; detailFailure.value = ''; optionFailure.value = '';
  result.value = { items: [], total: 0, page: 1, per_page: 20 }; products.value = { items: [], total: 0, page: 1, per_page: 20 }; models.value = { items: [], total: 0, page: 1, per_page: 20 }; refreshedAt.value = null;
  definition.value = { properties: [], events: [], commands: [] }; Object.assign(form, { device_id: '', target_tenant_id: '', version: undefined, copy_name: '', target_product_id: '', target_model_version: undefined });
  if (!session.tenant && router.currentRoute.value.path === '/transfers') void router.replace('/tenants'); else void load();
});
onMounted(() => { document.addEventListener('visibilitychange', visibility); void load(); if (typeof route.query.transfer === 'string') void read(route.query.transfer); });
onBeforeUnmount(() => { active = false; issued.value = null; serial++; detailSerial++; optionSerial++; clearTimeout(timer); pending?.abort(); detailPending?.abort(); optionPending?.abort(); document.removeEventListener('visibilitychange', visibility); });
</script>

<template>
  <div class="page-stack">
    <div class="page-heading"><div><h1>设备转移</h1><p>双方管理员分别确认，冻结和归属切换分开显示。</p></div><Button v-if="can('request')" type="primary" @click="start('request')">发起转移</Button></div>
    <Card :bordered="false"><div class="crud-search-grid">
      <CrudSearchField label="转移方向"><Select v-model:value="filters.direction" aria-label="转移方向" :options="[{ value: 'source', label: '本方发出' }, { value: 'target', label: '本方收到' }]" /></CrudSearchField>
      <CrudSearchField label="审批状态"><Select v-model:value="filters.status" aria-label="审批状态" :options="[{ value: '', label: '全部状态' }, ...Object.entries(transferStatusNames).map(([value, label]) => ({ value, label }))]" /></CrudSearchField>
      <CrudSearchField label="设备标识"><Input v-model:value="filters.device_id" aria-label="筛选设备标识" :maxlength="32" @press-enter="search()" /></CrudSearchField>
      <div class="crud-search-actions"><Button type="primary" :loading="busy" @click="search()">查询</Button><Button :disabled="busy" @click="search(true)">重置</Button></div>
    </div></Card>
    <Alert v-if="failure" :message="failure" type="error" show-icon />
    <Alert v-if="!canRead" type="info" show-icon message="当前没有转移查询权限；已获授权的发起操作仍可提交。" /><Card v-if="canRead" :bordered="false" title="转移记录"><template #extra><Button :loading="busy" @click="load(result.page, true)">刷新</Button></template>
      <p class="muted">{{ refreshedAt ? `最近读取 ${new Date(refreshedAt).toLocaleString()}` : '尚未取得转移记录' }} · 仅显示本组织发出或收到的邀请。</p>
      <Table row-key="id" :columns="columns" :data-source="result.items" :loading="busy" :scroll="buildTableScrollX(columns)" :pagination="{ current: result.page, pageSize: result.per_page, total: result.total, showSizeChanger: true, pageSizeOptions: ['20', '50', '100'] }" @change="pagination => load(pagination.current, false, pagination.pageSize)">
        <template #emptyText><Empty description="暂无符合条件的转移记录" /></template>
        <template #bodyCell="{ column, record }"><template v-if="column.key === 'status'"><Tag :color="record.status === 'frozen' ? 'warning' : record.status === 'rejected' ? 'default' : 'processing'">{{ transferStatusNames[record.status as DeviceTransfer['status']] }}</Tag></template><template v-else-if="column.key === 'created'">{{ time(record.created_at) }}</template><template v-else-if="column.key === 'actions'"><CrudTableActions :actions="[{ label: '查看处理', onClick: () => read(record.id) }]" /></template></template>
      </Table>
    </Card>
    <AppDrawer v-model:open="detailOpen" title="设备转移详情" width-size="lg" :show-footer="false">
      <Alert v-if="detailFailure" type="error" show-icon :message="detailFailure" class="page-alert" />
      <Button :loading="detailBusy" :disabled="!selected || saving" @click="selected && read(selected.id, false)">刷新处理状态</Button>
      <template v-if="selected">
        <Descriptions :column="2" bordered class="transfer-facts"><DescriptionsItem label="设备名称" :span="2">{{ selected.device_name }}</DescriptionsItem><DescriptionsItem label="设备标识" :span="2">{{ selected.device_id }}</DescriptionsItem><DescriptionsItem label="源租户">{{ selected.source_tenant_id }}</DescriptionsItem><DescriptionsItem label="目标租户">{{ selected.target_tenant_id }}</DescriptionsItem><DescriptionsItem label="原归属阶段" :span="2">{{ selected.ownership_id }}</DescriptionsItem><DescriptionsItem label="审批状态">{{ transferStatusNames[selected.status] }}</DescriptionsItem><DescriptionsItem label="设备确认时间">{{ time(selected.device_status_at) }}</DescriptionsItem><DescriptionsItem label="待排空上报">{{ selected.device_status?.pending_count ?? '尚未确认' }}</DescriptionsItem><DescriptionsItem label="未决指令">{{ selected.unresolved_commands ?? '等待接受后核对' }}</DescriptionsItem></Descriptions>
        <Alert v-if="selected.ready_for_switch" type="info" show-icon message="当前切换条件齐备，等待正式归属切换。源租户、原凭据和历史仍保持原阶段。" class="page-alert" />
        <Alert v-for="reason in selected.pending_reasons" :key="reason" type="warning" show-icon :message="transferPendingNames[reason] || reason" class="page-alert" />
        <Descriptions v-if="selected.new_ownership_id || selected.isolation_requested_at" :column="{ xs: 1, sm: 2 }" bordered class="transfer-facts"><DescriptionsItem label="新归属阶段" :span="2">{{ selected.new_ownership_id || '尚未激活' }}</DescriptionsItem><DescriptionsItem label="隔离完成">{{ time(selected.isolated_at) }}</DescriptionsItem><DescriptionsItem label="归属激活">{{ time(selected.activated_at) }}</DescriptionsItem><DescriptionsItem label="设备完成确认" :span="2">{{ time(selected.completed_at) }}</DescriptionsItem></Descriptions>
        <Alert v-if="selected.status === 'completed'" type="success" show-icon message="设备已确认新归属，转移完成。原始记录、指令和导出继续按原租户权限保留。" class="page-alert" />
        <div class="transfer-actions"><Button v-if="can('accept') && selected.status === 'requested' && selected.target_tenant_id === session.tenant?.id" type="primary" :disabled="detailBusy || Boolean(detailFailure) || selected.pending_reasons.includes('recovery_not_verified')" @click="start('accept')">匹配并接受</Button><Button v-if="can('retry') && selected.status === 'frozen'" :loading="saving" :disabled="detailBusy || Boolean(detailFailure) || selected.pending_reasons.includes('recovery_not_verified')" @click="retry">重发原冻结请求</Button><Button v-if="can('switch') && selected.target_tenant_id === session.tenant?.id && (selected.status === 'frozen' || selected.status === 'isolating')" type="primary" :disabled="saving || detailBusy || selected.pending_reasons.includes('recovery_not_verified') || (selected.status === 'frozen' && !selected.ready_for_switch)" @click="switchingOpen = true">{{ selected.status === 'frozen' ? '开始正式切换' : '继续归属切换' }}</Button><Button v-if="selected.source_tenant_id === session.tenant?.id && !selected.new_ownership_id" @click="router.push(`/devices/${selected.device_id}`)">查看原设备与对账</Button><Button v-if="selected.target_tenant_id === session.tenant?.id && selected.new_ownership_id" @click="router.push(`/devices/${selected.device_id}`)">查看当前设备</Button><Button v-if="can('reject') && selected.status === 'requested' && selected.target_tenant_id === session.tenant?.id" danger @click="start('reject')">拒绝转移</Button><Button v-if="can('cancel') && selected.status === 'requested' && selected.source_tenant_id === session.tenant?.id" danger @click="start('cancel')">取消转移</Button></div>
        <template v-if="selected.provisioning && selected.target_tenant_id === session.tenant?.id"><h3>设备归属配置</h3><p class="muted">将此配置与本次新凭据经受控渠道交付。若密码响应丢失，请在设备管理页读取状态，明确轮换后再交付；原密码不会再次显示。</p><pre class="transfer-intent">{{ JSON.stringify(selected.provisioning, null, 2) }}</pre></template>
        <h3>此次邀请的源模型定义</h3><DefinitionEditor v-model="definition" disabled />
      </template><Empty v-else-if="!detailBusy" description="尚未取得转移详情" />
    </AppDrawer>
    <AppDrawer v-model:open="switchingOpen" title="确认正式归属切换" width-size="sm" ok-text="确认并继续" :confirm-loading="saving" :ok-disabled="!can('switch')" @ok="advance">
      <Alert type="warning" show-icon message="先撤销旧凭据并隔离旧连接，随后激活新归属。超时会保持当前阶段，不恢复旧授权。" class="page-alert" />
      <p>请确认设备缓存和指令已经对账，并准备通过受控渠道交付新配置及凭据。隔离完成后可用同一转移记录继续，新密码仅首次激活显示。</p>
      <Alert v-if="detailFailure" type="error" show-icon :message="detailFailure" />
    </AppDrawer>
    <AppDrawer :open="Boolean(issued)" title="保存转移后的设备凭据" width-size="sm" :mask-closable="false" :ok-visible="false" cancel-text="已保存，关闭" @update:open="value => { if (!value) issued = null; }">
      <template v-if="issued"><Alert type="warning" show-icon message="密码仅本次显示" description="关闭、离开页面或切换租户后清除。请与新归属配置一并通过受控渠道交付。" class="page-alert" /><Descriptions :column="1" bordered class="transfer-facts"><DescriptionsItem label="新归属阶段">{{ issued.provisioning.ownership_id }}</DescriptionsItem><DescriptionsItem label="用户名">{{ issued.credential.username }}</DescriptionsItem><DescriptionsItem label="密码"><code>{{ issued.credential.password }}</code></DescriptionsItem><DescriptionsItem label="上报 Topic">{{ issued.credential.topics.publish }}</DescriptionsItem><DescriptionsItem label="下行 Topic">{{ issued.credential.topics.subscribe }}</DescriptionsItem></Descriptions></template>
    </AppDrawer>
    <AppDrawer v-model:open="formOpen" :title="mode === 'request' ? '发起设备转移' : mode === 'accept' ? '匹配模型并接受转移' : mode === 'cancel' ? '确认取消转移' : '确认拒绝转移'" width-size="sm" :confirm-loading="saving" :ok-disabled="!can(mode) || !ready" :ok-text="pendingSubmission ? '确认原请求结果' : mode === 'request' ? '提交转移申请' : mode === 'accept' ? '接受并冻结' : mode === 'cancel' ? '确认取消' : '确认拒绝'" @ok="submit">
      <Alert v-if="saveFailure" type="error" show-icon :message="saveFailure" class="page-alert" />
      <Alert v-if="pendingSubmission" type="warning" show-icon message="保留了一次未确定结果的原请求。确认时会使用相同标识与内容，不重复创建新的转移或审批。" class="page-alert" />
      <p v-if="pendingSubmission" class="transfer-intent">原转移标识：{{ pendingSubmission.data.transfer_id || pendingSubmission.path.split('/').at(-1) }}<br />{{ pendingSubmission.data.copy_name ? `原复制产品名称：${pendingSubmission.data.copy_name}` : pendingSubmission.data.target_product_id ? `原目标产品：${pendingSubmission.data.target_product_id}，版本 ${pendingSubmission.data.target_model_version}` : '' }}</p>
      <Alert type="info" show-icon :message="mode === 'request' ? '目标管理员接受之前，设备仍由本方管理。请核对目标组织提供的租户标识。' : mode === 'accept' ? '确认后立即冻结新控制，设备继续排空旧缓存和对账；此操作尚不改变归属、历史或凭据。' : '取消或拒绝后本次申请结束；不会冻结设备或改变原归属。'" class="page-alert" />
      <Form layout="vertical" :disabled="saving || Boolean(pendingSubmission)"><div class="form-grid"><FormItem label="设备版本" required><InputNumber v-model:value="form.version" aria-label="转移设备版本" :min="1" :max="2147483645" :precision="0" :disabled="mode !== 'request'" /><Button v-if="mode === 'request' && session.permissions.includes('customer.devices.read')" :loading="saving" @click="readDeviceVersion">读取当前版本</Button><p class="muted">使用已核对的准确版本；冲突后刷新详情重新确认。</p></FormItem>
        <template v-if="mode === 'request'"><FormItem label="设备标识" required><Input v-model:value="form.device_id" aria-label="转移设备标识" :maxlength="32" /></FormItem><FormItem label="目标租户" required><Input v-model:value="form.target_tenant_id" aria-label="目标租户标识" :maxlength="32" /></FormItem></template>
        <template v-else-if="mode === 'accept'"><FormItem label="模型匹配" class="form-span-all"><Select v-model:value="form.matching" aria-label="模型匹配方式" :options="[{ value: 'copy', label: '复制源定义为目标自己的新产品', disabled: !canCopy }, { value: 'existing', label: '选择目标已有的已发布模型' }]" /></FormItem>
          <FormItem v-if="form.matching === 'copy'" label="新产品名" required class="form-span-all"><Input v-model:value="form.copy_name" aria-label="复制产品名称" :maxlength="100" /></FormItem>
          <template v-else><FormItem label="目标产品" required><Input v-if="!canProducts" v-model:value="form.target_product_id" aria-label="目标产品标识" :maxlength="32" /><Select v-else v-model:value="form.target_product_id" aria-label="目标产品" show-search :filter-option="false" :loading="optionsBusy" :options="products.items.map(item => ({ value: item.id, label: item.name }))" @search="value => options('products', 1, value)" /><Pagination v-if="products.total > 20" size="small" :current="products.page" :total="products.total" :page-size="20" @change="page => options('products', page)" /></FormItem><FormItem label="目标版本" required><InputNumber v-if="!canProducts" v-model:value="form.target_model_version" aria-label="转移目标版本" :min="1" :max="2147483646" :precision="0" /><Select v-else v-model:value="form.target_model_version" aria-label="转移目标版本" :loading="optionsBusy" :options="models.items.map(item => ({ value: item.model_version, label: `版本 ${item.model_version} · ${item.status === 'published' ? '已发布' : '草稿，不可选择'}`, disabled: item.status !== 'published' }))" /><Pagination v-if="models.total > 20" size="small" :current="models.page" :total="models.total" :page-size="20" @change="page => options('models', page)" /></FormItem><Alert v-if="selectedTarget" :type="mismatch ? 'warning' : 'success'" :message="mismatch ? '结构不一致，无法接受。类型、单位、范围与枚举必须一致。' : '业务结构一致，仍需设备明确确认支持。'" class="form-span-all" /></template>
        </template>
      </div></Form><Alert v-if="optionFailure" type="error" :message="optionFailure" show-icon />
    </AppDrawer>
  </div>
</template>

<style scoped>
.transfer-facts { margin: 16px 0; overflow-wrap: anywhere; }
.transfer-actions { display: flex; flex-wrap: wrap; gap: 8px; margin: 16px 0; }
.transfer-intent { overflow-wrap: anywhere; white-space: pre-wrap; }
@media (max-width: 640px) { .transfer-facts :deep(.ant-descriptions-view table) { display: block; } .transfer-facts :deep(.ant-descriptions-row) { display: flex; flex-direction: column; } .transfer-facts :deep(.ant-descriptions-item-label), .transfer-facts :deep(.ant-descriptions-item-content) { display: block; width: 100%; } }
</style>
