<script setup lang="ts">
import type { FormInstance, TableColumnsType } from 'ant-design-vue';
import type { Device, DeviceAction, DeviceChange, DeviceManagement, DeviceRegistration, ModelVersion, Page, Product } from '../api';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Alert, Button, Card, Descriptions, DescriptionsItem, Empty, Form, FormItem, Input, InputNumber, Pagination, Select, Table, Tag, message } from 'ant-design-vue';
import AppDrawer from '../components/app-drawer/app-drawer.vue';
import CrudSearchField from '../components/crud-search-field.vue';
import CrudTableActions from '../components/crud-table-actions.vue';
import { buildTableScrollX, estimateVisibleActionColumnWidth } from '../utils/table';
import { ApiError, applyAdminContext, clearAdminAccess, connectionColors, connectionNames, errorText, isCanceled, lifecycleNames, request, session } from '../api';

const router = useRouter();
const platform = computed(() => session.realm === 'admin');
const canRead = computed(() => session.permissions.includes(`${session.realm}.devices.read`));
const canReadProducts = computed(() => session.permissions.includes('customer.products.read'));
const canReadTelemetry = computed(() => !platform.value && session.permissions.includes('customer.telemetry.read'));
const endpoint = computed(() => platform.value ? '/admin/devices' : `/customer/tenants/${session.tenant?.id}/devices`);
const tenantOption = () => platform.value ? undefined : session.tenant?.id;
const result = ref<Page<Device>>({ items: [], total: 0, page: 1, per_page: 20 });
const filters = reactive({ name: '', product_id: '', lifecycle: '', tenant_id: '' });
let applied = { ...filters };
const busy = ref(false);
const failure = ref('');
const refreshedAt = ref<number | null>(null);
const open = ref(false);
const saving = ref(false);
const saveFailure = ref('');
const registration = ref<DeviceRegistration | null>(null);
const managementOpen = ref(false);
const managementDeviceId = ref('');
const managed = ref<DeviceManagement | null>(null);
const managementBusy = ref(false);
const changing = ref(false);
const managementFailure = ref('');
const managementFeedback = ref<HTMLElement>();
const managementNotice = ref('');
const outcomeUnknown = ref(false);
const editing = ref(false);
const deviceName = ref('');
const action = ref<DeviceAction>();
const confirmation = ref('');
let managementVersion = 0;
let managementPending: AbortController | null = null;
let changePending: AbortController | null = null;
const formRef = ref<FormInstance>();
const form = reactive<{ name: string; product_id?: string; model_version?: number }>({ name: '' });
const products = ref<Page<Product>>({ items: [], total: 0, page: 1, per_page: 20 });
const models = ref<Page<ModelVersion>>({ items: [], total: 0, page: 1, per_page: 20 });
const productBusy = ref(false);
const modelBusy = ref(false);
const optionFailure = ref('');
let productSearch = '';
let version = 0;
let productVersion = 0;
let modelVersion = 0;
let pending: AbortController | null = null;
let productPending: AbortController | null = null;
let modelPending: AbortController | null = null;
let savePending: AbortController | null = null;
let active = true;
let refreshTimer: ReturnType<typeof setTimeout> | undefined;
const canRegister = computed(() => !platform.value && session.permissions.includes('customer.devices.create'));
const canManage = computed(() => (Object.keys(actionNames) as DeviceAction[]).some(action => session.permissions.includes(`${session.realm}.devices.${action}`)));
const actionNames: Record<DeviceAction, string> = { update: '修改资料', rotate: '轮换凭据', revoke: '吊销凭据', disable: '禁用设备', enable: '启用设备', retire: '退役设备' };
const actionDescriptions: Record<DeviceAction, string> = {
  update: '更新设备名称，归属及模型保持当前绑定。归属变更须通过正式设备转移。',
  rotate: '生成新凭据并撤销旧凭据。新密码仅本次显示；旧连接和会话的撤权须等待 Broker 执行完成。',
  revoke: '撤销当前接入凭据，停止旧身份的连接、订阅和待投递授权。设备生命周期不变，重新接入需另行生成新凭据。',
  disable: '禁用设备并撤销当前凭据。重新启用不会恢复旧凭据，须另行生成新凭据。历史按原权限保留。',
  enable: '将禁用设备重新设为启用；旧凭据不会恢复，请按需要另行轮换生成新凭据。',
  retire: '永久退役设备，阻止后续接入和控制，无法重新启用。设备档案和保留期内的历史不会删除。',
};
const actionOptions = computed(() => {
  const device = managed.value;
  if (!device || Number(device.recovery_verified) === 0 || device.lifecycle === 'retired') return [];
  return (Object.keys(actionNames) as DeviceAction[]).filter(value => {
    if (!session.permissions.includes(`${session.realm}.devices.${value}`)) return false;
    return value === 'enable' ? device.lifecycle === 'disabled' : value === 'disable' ? device.lifecycle !== 'disabled' : value === 'revoke' ? device.credential_active : true;
  }).map(value => ({ value, label: actionNames[value] }));
});
const managementBlocked = computed(() => changing.value || managementBusy.value || outcomeUnknown.value || Number(managed.value?.recovery_verified) === 0 || managed.value?.authorization?.status === 'pending');
const lifecycleOptions = Object.entries(lifecycleNames).map(([value, label]) => ({ value, label }));
const productOptions = computed(() => products.value.items.map((product) => ({ value: product.id, label: product.name })));
const modelOptions = computed(() => models.value.items.map((model) => ({ value: model.model_version, label: `版本 ${model.model_version} · ${model.status === 'published' ? '已发布' : '草稿，不可注册'}`, disabled: model.status !== 'published' })));
const columns = computed<TableColumnsType<Device>>(() => [
  ...(platform.value ? [{ title: '所属租户', dataIndex: 'tenant_name', width: 220, ellipsis: true }] : []),
  { title: '设备名称', dataIndex: 'name', width: 240, ellipsis: true },
  { title: '设备标识', dataIndex: 'id', width: 290, ellipsis: true },
  { title: '产品标识', dataIndex: 'product_id', width: 290, ellipsis: true },
  { title: '模型版本', dataIndex: 'model_version', width: 100 },
  { title: '生命周期', key: 'lifecycle', width: 120 },
  { title: '恢复核对', key: 'recovery', width: 140 },
  { title: '连接状态', key: 'connection', width: 120 },
  { title: '授权执行', key: 'authorization', width: 160 },
  { title: '连接观察时间', key: 'observed', width: 200 },
  { title: '注册时间', key: 'created', width: 200 },
  { title: '操作', key: 'actions', fixed: 'right', width: estimateVisibleActionColumnWidth([{ label: '详情' }, { label: '数据', visible: !platform.value }, { label: '历史', visible: canReadTelemetry.value }, { label: '管理', visible: canManage.value }]) },
]);
const pagination = computed(() => ({ current: result.value.page, pageSize: result.value.per_page, total: result.value.total, showSizeChanger: true, pageSizeOptions: ['20', '50', '100'] }));
function stopRefresh() { clearTimeout(refreshTimer); refreshTimer = undefined; }
/** 可读且可见时逐轮刷新；上一轮未结束时不再启动新的自动请求。 */
function scheduleRefresh() {
  stopRefresh();
  if (active && canRead.value && !document.hidden) refreshTimer = setTimeout(() => { if (!busy.value) void load(result.value.page, result.value.per_page, true); }, 5000);
}
function visibilityChanged() {
  stopRefresh();
  if (document.hidden) { version++; pending?.abort(); busy.value = false; }
  else void load(result.value.page, result.value.per_page, true);
}
async function load(page = 1, perPage = result.value.per_page, refresh = false) {
  const tenant = tenantOption();
  if ((!platform.value && !tenant) || !canRead.value || !active || document.hidden) return;
  stopRefresh();
  const current = ++version;
  pending?.abort(); pending = new AbortController(); busy.value = true; failure.value = '';
  if (!refresh) { result.value.items = []; refreshedAt.value = null; }
  try {
    const data = await request<Page<Device> & { context: { permissions: string[]; menus: typeof session.menus } }>(endpoint.value, { tenant, signal: pending.signal, params: { name: applied.name, product_id: applied.product_id, lifecycle: applied.lifecycle, ...(platform.value ? { tenant_id: applied.tenant_id } : {}), page, per_page: perPage } });
    if (current !== version) return;
    result.value = data; applyAdminContext(data.context); refreshedAt.value = Date.now();
    const selected = data.items.find(device => device.id === managed.value?.id);
    if (selected && managed.value && !changing.value) {
      if (managed.value.authorization?.status === 'pending' && selected.authorization?.status === 'enforced' && managementNotice.value && !outcomeUnknown.value) managementNotice.value = 'Broker 已完成此前撤权，设备变更已执行。';
      managed.value.authorization = selected.authorization;
    }
    const credentialDevice = data.items.find(device => device.id === registration.value?.device.id);
    if (credentialDevice && registration.value) registration.value.device.authorization = credentialDevice.authorization;
  } catch (error) { if (!isCanceled(error) && current === version) { failure.value = errorText(error); reject(error); } }
  finally { if (current === version) { busy.value = false; scheduleRefresh(); } }
}
function search(reset = false) {
  if (busy.value) return;
  if (reset) Object.assign(filters, { name: '', product_id: '', lifecycle: '', tenant_id: '' });
  applied = { name: filters.name.trim(), product_id: filters.product_id.trim(), lifecycle: filters.lifecycle || '', tenant_id: filters.tenant_id.trim() }; void load();
}
async function loadProducts(page = 1, name = productSearch) {
  const tenant = session.tenant?.id;
  if (!tenant || !open.value || !canReadProducts.value) return;
  productSearch = name;
  const current = ++productVersion;
  productPending?.abort(); productPending = new AbortController(); productBusy.value = true; optionFailure.value = '';
  try {
    const data = await request<Page<Product>>(`/customer/tenants/${tenant}/products`, { tenant, signal: productPending.signal, params: { name, page, per_page: 20 } });
    if (current === productVersion) products.value = data;
  } catch (error) { if (!isCanceled(error) && current === productVersion) { products.value.items = []; optionFailure.value = errorText(error); } }
  finally { if (current === productVersion) productBusy.value = false; }
}
async function loadModels(page = 1) {
  const tenant = session.tenant?.id;
  const product = form.product_id;
  if (!tenant || !product || !open.value || !canReadProducts.value) return;
  const current = ++modelVersion;
  modelPending?.abort(); modelPending = new AbortController(); modelBusy.value = true; optionFailure.value = '';
  try {
    const data = await request<Page<ModelVersion>>(`/customer/tenants/${tenant}/products/${product}/models`, { tenant, signal: modelPending.signal, params: { page, per_page: 20 } });
    if (current === modelVersion) models.value = data;
  } catch (error) { if (!isCanceled(error) && current === modelVersion) { models.value.items = []; optionFailure.value = errorText(error); } }
  finally { if (current === modelVersion) modelBusy.value = false; }
}
watch(() => form.product_id, () => {
  form.model_version = undefined; modelVersion++; modelPending?.abort(); models.value = { items: [], total: 0, page: 1, per_page: 20 }; void loadModels();
});
function create() {
  if (!canRegister.value || saving.value) return;
  form.name = ''; form.product_id = undefined; form.model_version = undefined;
  registration.value = null; saveFailure.value = ''; optionFailure.value = ''; productSearch = ''; open.value = true; void loadProducts();
}
async function manage(device: Device, edit = false) {
  if (!canRead.value || saving.value || changing.value) return;
  editing.value = edit && canManage.value;
  registration.value = null; managed.value = null; action.value = undefined; confirmation.value = '';
  outcomeUnknown.value = false; managementNotice.value = ''; managementDeviceId.value = device.id; managementOpen.value = true;
  await readManagement(device.id);
}
async function readManagement(deviceId = managementDeviceId.value) {
  const tenant = tenantOption();
  if ((!platform.value && !tenant) || !deviceId || changing.value || !canRead.value) return;
  const current = ++managementVersion;
  managementPending?.abort(); managementPending = new AbortController(); managementBusy.value = true; managementFailure.value = '';
  try {
    const data = await request<DeviceManagement>(`${endpoint.value}/${deviceId}`, { tenant, signal: managementPending.signal });
    if (current !== managementVersion || !managementOpen.value) return;
    managed.value = data;
    deviceName.value = data.name;
    if (outcomeUnknown.value) managementNotice.value = '已读取当前设备状态。此前操作的密码不会再次返回；如需新凭据，请在撤权完成后明确发起一次新的轮换。';
    outcomeUnknown.value = false; action.value = undefined; confirmation.value = '';
  } catch (error) { if (!isCanceled(error) && current === managementVersion) { managementFailure.value = errorText(error); reject(error); } }
  finally { if (current === managementVersion) managementBusy.value = false; }
}
async function changeDevice() {
  const tenant = tenantOption(); const device = managed.value; const selectedAction = action.value;
  if ((!platform.value && !tenant) || !device || !selectedAction || !canManage.value || managementBlocked.value || !actionOptions.value.some(option => option.value === selectedAction)) return;
  if (selectedAction !== 'update' && confirmation.value !== device.id) { managementFailure.value = '请输入与当前设备完全一致的设备标识。'; return; }
  changing.value = true; managementFailure.value = ''; managementNotice.value = '';
  const generation = session.generation;
  changePending = new AbortController();
  try {
    const data = await request<DeviceChange>(`${endpoint.value}/${device.id}${selectedAction === 'update' ? '' : `/${selectedAction}`}`, { tenant, method: selectedAction === 'update' ? 'PATCH' : 'POST', signal: changePending.signal, data: { version: device.version, ...(selectedAction === 'update' ? { name: deviceName.value.trim() } : { confirm_device_id: confirmation.value }) } });
    if (!active || generation !== session.generation) return;
    managed.value = data.device; action.value = undefined; confirmation.value = '';
    managementNotice.value = data.device.authorization?.status === 'pending' ? '变更已提交，Broker 撤权仍在执行中。当前状态不代表旧连接已全部停止。' : '设备变更已完成。';
    if (data.credential) { registration.value = { device: data.device, credential: data.credential }; managementOpen.value = false; }
    await load(result.value.page, result.value.per_page, true);
  } catch (error) {
    if (!isCanceled(error) && generation === session.generation) {
      managementFailure.value = errorText(error); reject(error);
      if (!(error instanceof ApiError) || error.status >= 500) {
        outcomeUnknown.value = true;
        managementNotice.value = '未取得确定结果，操作可能已经提交。请先读取当前状态；不会自动重试或再次生成密码。';
      }
    }
  } finally { changing.value = false; }
}
async function save() {
  const tenant = session.tenant?.id;
  if (!tenant || !canRegister.value || saving.value) return;
  // 从校验开始占有执行状态，快速重复点击不能穿过异步校验窗口。
  saving.value = true; saveFailure.value = '';
  const generation = session.generation;
  try {
    try { await formRef.value?.validate(); } catch { return; }
    if (!active || generation !== session.generation) return;
    savePending = new AbortController();
    const data = await request<DeviceRegistration>(endpoint.value, { tenant, method: 'POST', signal: savePending.signal, data: { ...form, name: form.name.trim() } });
    if (!active || generation !== session.generation) return;
    registration.value = data; open.value = false; message.success('设备已注册，请保存本次凭据'); await load();
  } catch (error) { if (!isCanceled(error) && generation === session.generation) { saveFailure.value = errorText(error); reject(error); } }
  finally { saving.value = false; }
}
function clear() {
  stopRefresh(); busy.value = false; refreshedAt.value = null; failure.value = '';
  version++; productVersion++; modelVersion++;
  pending?.abort(); productPending?.abort(); modelPending?.abort(); savePending?.abort(); managementPending?.abort(); changePending?.abort();
  managementVersion++; managementOpen.value = false; managed.value = null; managementBusy.value = false; changing.value = false;
  managementDeviceId.value = '';
  managementFailure.value = ''; managementNotice.value = ''; outcomeUnknown.value = false; action.value = undefined; confirmation.value = '';
  registration.value = null; open.value = false; result.value = { items: [], total: 0, page: 1, per_page: 20 };
}
function reject(error: unknown) {
  if (clearAdminAccess(error)) { clear(); failure.value = '当前设备权限已失效，请重新选择工作区或登录。'; }
}
// 工作区改变时清理列表、凭据展示和在途操作，重新获取服务端授权后的状态。
watch([() => session.tenant?.id, () => session.token, () => session.realm], () => {
  clear();
  if (!platform.value && !session.tenant && router.currentRoute.value.path === '/devices') void router.replace('/tenants');
  else if (canRead.value && (platform.value || session.tenant)) void load();
  else failure.value = '当前没有设备查看权限，请选择获准的工作区。';
}, { immediate: true });
watch(canManage, value => { if (!value) { editing.value = false; registration.value = null; } });
watch(open, (value) => { if (!value) { productVersion++; modelVersion++; productPending?.abort(); modelPending?.abort(); } });
watch(managementOpen, value => { if (!value) { managementVersion++; managementPending?.abort(); managed.value = null; action.value = undefined; confirmation.value = ''; } });
watch(action, () => { confirmation.value = ''; managementFailure.value = ''; });
watch(managementFailure, async value => { if (value) { await nextTick(); managementFeedback.value?.scrollIntoView({ block: 'start' }); } });
onMounted(() => document.addEventListener('visibilitychange', visibilityChanged));
onBeforeUnmount(() => { active = false; document.removeEventListener('visibilitychange', visibilityChanged); clear(); });
</script>

<template>
  <section class="iot-page">
    <header class="page-heading"><div><h1>{{ platform ? '设备资产' : '设备管理' }}</h1><p class="muted tenant-title">{{ platform ? '全局资产、归属与接入生命周期' : `${session.tenant?.name || '未选择租户'} · 设备身份与模型绑定` }}</p></div><Button v-if="canRegister" type="primary" :disabled="saving || changing" @click="create">注册设备</Button></header>
    <Card :bordered="false">
      <form class="crud-search-grid" @submit.prevent="search()">
        <CrudSearchField v-if="platform" label="租户标识"><Input v-model:value="filters.tenant_id" aria-label="租户标识筛选" :disabled="busy" :maxlength="32" allow-clear /></CrudSearchField>
        <CrudSearchField label="设备名称"><Input v-model:value="filters.name" aria-label="设备名称筛选" :disabled="busy" :maxlength="100" allow-clear /></CrudSearchField>
        <CrudSearchField label="产品标识"><Input v-model:value="filters.product_id" aria-label="产品标识筛选" :disabled="busy" :maxlength="32" allow-clear /></CrudSearchField>
        <CrudSearchField label="生命周期"><Select v-model:value="filters.lifecycle" aria-label="生命周期筛选" :disabled="busy" :options="[{ value: '', label: '全部状态' }, ...lifecycleOptions]" /></CrudSearchField>
        <div class="crud-search-grid__actions"><Button type="primary" html-type="submit" :loading="busy" :disabled="busy">查询</Button><Button :disabled="busy" @click="search(true)">重置</Button></div>
      </form>
      <Alert v-if="failure" :message="failure" :description="refreshedAt ? '刷新失败，以下为上次成功读取的数据，连接状态可能已变化。' : undefined" type="error" show-icon class="page-alert" role="alert" />
      <Alert v-else-if="canRead && !canManage && !canRegister" message="当前角色可查看设备，尚未取得设备维护权限。" type="info" show-icon class="page-alert" />
      <div class="table-toolbar"><span class="muted">{{ result.total }} 台设备 · {{ busy ? '刷新中' : refreshedAt ? `数据读取于 ${new Date(refreshedAt).toLocaleString()}` : '等待数据' }} · 可见时每 5 秒刷新</span><Button :loading="busy" :disabled="!canRead" @click="load(result.page, result.per_page, true)">刷新</Button></div>
      <Table :columns="columns" :data-source="result.items" row-key="id" :loading="busy" :scroll="buildTableScrollX(columns)" :pagination="pagination" @change="(page) => load(page.current, page.pageSize)">
        <template #emptyText><Empty :description="failure ? '数据暂不可用，请重试' : '暂无符合条件的设备'" /></template>
        <template #bodyCell="{ column, record }">
          <Tag v-if="column.key === 'lifecycle'">{{ lifecycleNames[(record as Device).lifecycle] }}</Tag>
          <Tag v-else-if="column.key === 'recovery'" :color="Number(record.recovery_verified) === 0 ? 'warning' : undefined">{{ Number(record.recovery_verified) === 0 ? '待恢复核对' : '无待处理核对' }}</Tag>
          <Tag v-else-if="column.key === 'connection'" :color="connectionColors[(record as Device).connection.status]">{{ connectionNames[(record as Device).connection.status] }}</Tag>
          <Tag v-else-if="column.key === 'authorization'" :color="record.authorization?.status === 'pending' ? 'warning' : 'default'">{{ record.authorization?.status === 'pending' ? '等待 Broker 撤权' : record.authorization?.id ? '撤权已执行' : '无待处理撤权' }}</Tag>
          <template v-else-if="column.key === 'observed'">{{ record.connection.observed_at === null ? '尚无连接观察' : new Date(Number(record.connection.observed_at) * 1000).toLocaleString() }}</template>
          <template v-else-if="column.key === 'created'">{{ new Date(Number(record.created_at) * 1000).toLocaleString() }}</template>
          <CrudTableActions v-else-if="column.key === 'actions'" :actions="[{ label: '详情', disabled: saving || changing, onClick: () => manage(record as Device) }, { label: '数据', visible: !platform, onClick: () => router.push(`/devices/${record.id}`) }, { label: '历史', visible: canReadTelemetry, onClick: () => router.push({ path: '/history', query: { device: record.id } }) }, { label: '管理', visible: canManage, disabled: saving || changing, onClick: () => manage(record as Device, true) }]" />
        </template>
      </Table>
    </Card>
    <AppDrawer v-model:open="open" title="注册设备" width-size="sm" ok-text="注册并生成凭据" :closable="!saving" :ok-disabled="!canRegister" :confirm-loading="saving" @ok="save">
      <Alert v-if="saveFailure || optionFailure" :message="saveFailure || optionFailure" type="error" show-icon class="page-alert" role="alert" />
      <Form ref="formRef" :model="form" layout="vertical" class="form-grid" :disabled="saving">
        <FormItem label="设备名称" name="name" :rules="[{ required: true, whitespace: true, message: '请输入设备名称' }]" class="span-full"><Input v-model:value="form.name" :maxlength="100" /></FormItem>
        <FormItem label="绑定产品" name="product_id" :rules="[{ required: true, message: '请选择产品' }]">
          <Input v-if="!canReadProducts" v-model:value="form.product_id" :maxlength="32" placeholder="输入已获知的产品标识" />
          <Select v-else v-model:value="form.product_id" show-search :filter-option="false" :loading="productBusy" :options="productOptions" placeholder="输入名称搜索产品" @search="(name) => loadProducts(1, name)" />
          <Pagination v-if="products.total > 20" size="small" simple :current="products.page" :total="products.total" :page-size="20" :disabled="saving || productBusy" @change="(page) => loadProducts(page)" />
          <Button v-if="optionFailure" size="small" :loading="productBusy" @click="loadProducts(products.page)">重试产品</Button>
        </FormItem>
        <FormItem label="模型版本" name="model_version" :rules="[{ required: true, message: '请选择已发布的模型版本' }]">
          <InputNumber v-if="!canReadProducts" v-model:value="form.model_version" :min="1" :max="2147483646" placeholder="输入已发布版本" />
          <Select v-else v-model:value="form.model_version" :disabled="saving || !form.product_id" :loading="modelBusy" :options="modelOptions" placeholder="选择已发布版本" />
          <Pagination v-if="models.total > 20" size="small" simple :current="models.page" :total="models.total" :page-size="20" :disabled="saving || modelBusy" @change="(page) => loadModels(page)" />
          <Button v-if="optionFailure && form.product_id" size="small" :loading="modelBusy" @click="loadModels(models.page)">重试版本</Button>
        </FormItem>
      </Form>
      <p class="muted">设备绑定所选的已发布版本。注册成功后请立即通过受控渠道交付凭据，关闭后无法再次查看原密码。</p>
    </AppDrawer>
    <AppDrawer v-model:open="managementOpen" :title="editing ? '管理设备授权' : '设备详情'" width-size="sm" :confirm-loading="changing" :closable="!changing" :ok-visible="Boolean(editing && canManage && managed && managed.lifecycle !== 'retired')" :ok-text="action ? `确认${actionNames[action]}` : '确认操作'" :ok-danger="action !== 'enable' && action !== 'update'" :ok-disabled="managementBlocked || !action" @ok="changeDevice">
      <div ref="managementFeedback">
        <Alert v-if="managementFailure" :message="managementFailure" type="error" show-icon class="page-alert" role="alert" />
        <Alert v-if="managementNotice" :message="managementNotice" :type="outcomeUnknown || managed?.authorization?.status === 'pending' ? 'warning' : 'info'" show-icon class="page-alert" role="status" />
      </div>
      <div class="table-toolbar"><span class="muted">{{ managementBusy ? '正在读取设备状态' : outcomeUnknown ? '上次读取状态；操作结果待核对' : '以服务端读取状态为准' }}</span><Button :loading="managementBusy" :disabled="managementBusy || changing" @click="readManagement()">读取当前状态</Button></div>
      <template v-if="managed">
        <Alert v-if="Number(managed.recovery_verified) === 0" message="设备仍待恢复核对" description="接入与控制保持隔离。请运维核对当前归属和授权；历史资料仍按当前人员权限提供。" type="warning" show-icon class="page-alert" />
        <Descriptions :column="{ xs: 1, sm: 2 }" layout="vertical" bordered>
          <DescriptionsItem label="设备名称" :span="2">{{ managed.name }}</DescriptionsItem><DescriptionsItem label="设备标识" :span="2">{{ managed.id }}</DescriptionsItem>
          <DescriptionsItem label="生命周期">{{ lifecycleNames[managed.lifecycle] }}</DescriptionsItem><DescriptionsItem label="接入凭据">{{ Number(managed.recovery_verified) === 0 ? '待恢复核对' : managed.credential_active ? '存在有效凭据' : '凭据已吊销' }}</DescriptionsItem>
          <DescriptionsItem label="所属租户" :span="2">{{ managed.tenant_name }} · {{ managed.tenant_id }}</DescriptionsItem>
          <DescriptionsItem label="租户状态">{{ Number(managed.tenant_enabled) === 0 ? '停用，接入被拒绝' : '启用' }}</DescriptionsItem><DescriptionsItem label="连接状态">{{ connectionNames[managed.connection.status] }}</DescriptionsItem>
          <DescriptionsItem label="产品标识" :span="2">{{ managed.product_id }}</DescriptionsItem><DescriptionsItem label="绑定模型版本">{{ managed.model_version }}</DescriptionsItem><DescriptionsItem label="归属阶段">{{ managed.ownership_id }}</DescriptionsItem>
          <DescriptionsItem label="授权执行" :span="2"><Tag :color="outcomeUnknown || managed.authorization?.status === 'pending' ? 'warning' : 'success'">{{ outcomeUnknown ? '操作结果待核对' : managed.authorization?.status === 'pending' ? '等待 Broker 撤权' : managed.authorization?.id ? '撤权已执行' : '无待处理撤权' }}</Tag></DescriptionsItem>
          <DescriptionsItem label="设备版本">{{ managed.version }}</DescriptionsItem><DescriptionsItem label="撤权完成时间">{{ managed.authorization?.completed_at ? new Date(managed.authorization.completed_at * 1000).toLocaleString() : '尚无完成记录' }}</DescriptionsItem>
        </Descriptions>
        <Alert v-if="managed.authorization?.status === 'pending'" message="管理变更已保存，仍等待 Broker 执行撤权。页面每 5 秒更新执行状态；当前不能继续修改设备。" type="warning" show-icon class="page-alert" />
        <Alert v-if="managed.lifecycle === 'retired'" message="设备已永久退役，不提供恢复或硬删除。保留期内历史仍可按原权限查询。" type="info" show-icon class="page-alert" />
        <Form v-else-if="editing && canManage" layout="vertical" class="form-grid" :disabled="managementBlocked">
          <FormItem label="管理动作" class="span-full"><Select v-model:value="action" aria-label="管理动作" :options="actionOptions" placeholder="选择本次操作" /></FormItem>
          <template v-if="action">
            <p class="muted span-full">{{ actionDescriptions[action] }}</p>
            <FormItem v-if="action === 'update'" label="设备名称" class="span-full" required><Input v-model:value="deviceName" aria-label="修改设备名称" :maxlength="100" /></FormItem>
            <FormItem v-else label="确认设备标识" class="span-full" required><Input v-model:value="confirmation" aria-label="确认设备标识" :maxlength="32" autocomplete="off" placeholder="输入上方完整设备标识" /></FormItem>
          </template>
        </Form>
      </template>
    </AppDrawer>
    <AppDrawer :open="Boolean(registration)" title="保存设备接入凭据" width-size="sm" :mask-closable="false" :ok-visible="false" cancel-text="已保存，关闭" @update:open="(value) => { if (!value) registration = null; }">
      <template v-if="registration">
        <Alert message="密码仅本次显示" description="请保存并通过受控渠道交付。关闭、离开页面或切换租户后，密码将从本页清除。" type="warning" show-icon class="page-alert" />
        <Alert v-if="registration.device.authorization?.status === 'pending'" message="新凭据已生成，旧凭据仍等待 Broker 完成撤权。请勿将此次响应视为旧连接已经停止。" type="warning" show-icon class="page-alert" />
        <Descriptions :column="{ xs: 1, sm: 2 }" layout="vertical" bordered>
          <DescriptionsItem label="设备名称">{{ registration.device.name }}</DescriptionsItem><DescriptionsItem label="Client ID">{{ registration.credential.client_id }}</DescriptionsItem>
          <DescriptionsItem label="用户名" :span="2">{{ registration.credential.username }}</DescriptionsItem><DescriptionsItem label="密码" :span="2"><code>{{ registration.credential.password }}</code></DescriptionsItem>
          <DescriptionsItem label="连接协议">MQTT {{ registration.credential.protocol_version }} / TLS</DescriptionsItem><DescriptionsItem label="Keep Alive">{{ registration.credential.keep_alive }} 秒</DescriptionsItem>
          <DescriptionsItem label="Session Expiry" :span="2">{{ registration.credential.session_expiry }} 秒</DescriptionsItem>
          <DescriptionsItem label="上报 Topic" :span="2">{{ registration.credential.topics.publish }}</DescriptionsItem><DescriptionsItem label="下行 Topic" :span="2">{{ registration.credential.topics.subscribe }}</DescriptionsItem>
        </Descriptions>
      </template>
    </AppDrawer>
  </section>
</template>
