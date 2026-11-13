<script setup lang="ts">
import { displayDateTime } from '../../display';
import type { Device, DeviceAction, DeviceChange, DeviceManagement, Product } from '../../api/center';
import type { Page as PageResult } from '../../api/contracts';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { Alert, Button, Descriptions, DescriptionsItem, Pagination, Select, Space, Tag, message } from 'ant-design-vue';
import { Page, useVbenDrawer, useVbenModal } from '@vben/common-ui';
import { useVbenForm, z } from '../../adapter/form';
import { useVbenVxeGrid } from '../../adapter/vxe-table';
import DeviceCredentialView from '../../components/device-credential.vue';
import { ApiError, applyAccessContext, errorText, isCanceled, isDemo, request, session } from '../../api/identity';
import { connectionColors, connectionNames, lifecycleNames } from '../../api/center';
const router = useRouter(); const platform = computed(() => session.realm === 'platform');
const domain = computed(() => platform.value ? 'platform' : 'center'); const endpoint = computed(() => `/${domain.value}/devices`);
const can = (action: string) => session.permissions.includes(`${domain.value}.devices.${action}`);
const canReadProducts = computed(() => session.permissions.includes('center.products.read'));
const failure = ref(''); const detailFailure = ref(''); const notice = ref(''); const managed = ref<DeviceManagement>();
const editing = ref(false); const detailBusy = ref(false); const saving = ref(false); const unknown = ref(false); const currentAction = ref<DeviceAction>();
const secret = ref<InstanceType<typeof DeviceCredentialView>>(); const refreshedAt = ref(0);
let listPending: AbortController | undefined; let detailPending: AbortController | undefined; let productPending: AbortController | undefined;
let detailGeneration = 0; let productGeneration = 0; let active = true; let timer: ReturnType<typeof setTimeout> | undefined;
const products = ref<PageResult<Product>>({ items: [], total: 0, page: 1, per_page: 20 }); const productBusy = ref(false); const productFailure = ref(''); let productSearch = '';
const names: Record<DeviceAction, string> = { update: '修改资料', rotate: '轮换凭据', revoke: '吊销凭据', disable: '禁用设备', enable: '启用设备', retire: '退役设备' };
const descriptions: Record<DeviceAction, string> = {
  update: '修改名称及同客户分类，分类不影响通信授权。', rotate: '新凭据仅本次展示，旧连接与持久会话等待 Broker 撤权。',
  revoke: '撤销接入凭据，重新接入需另行轮换。', disable: '禁用并撤销凭据，重新启用不会恢复旧凭据。',
  enable: '重新启用设备，接入需另行轮换凭据。', retire: '永久退役，不能重新启用；设备档案保留。',
};
const actionOptions = computed(() => {
  const device = managed.value;
  if (!device || !device.recovery_verified || device.lifecycle === 'retired') return [];
  return (Object.keys(names) as DeviceAction[]).filter(action => can(action)
    && (device.usage?.available || !['enable', 'rotate'].includes(action))
    && (action === 'enable' ? device.lifecycle === 'disabled' : action === 'disable' ? device.lifecycle !== 'disabled' : action === 'revoke' ? device.credential_active : true)
  ).map(value => ({ value, label: names[value] }));
});
const blocked = computed(() => saving.value || detailBusy.value || unknown.value || !managed.value?.recovery_verified || managed.value.authorization?.status === 'pending' || isDemo());
const time = (value?: number | null) => value ? displayDateTime(value * 1000) : '尚无记录';
const [Form, form] = useVbenForm({ showDefaultActions: false, layout: 'vertical', commonConfig: { componentProps: { class: 'w-full' } }, schema: [
  { fieldName: 'action', label: '管理动作', component: 'Select', rules: z.enum(['update', 'rotate', 'revoke', 'disable', 'enable', 'retire']), componentProps: () => ({ options: actionOptions.value, onChange: (value: DeviceAction) => { currentAction.value = value; void form.setFieldValue('confirm_device_id', ''); } }) },
  { fieldName: 'name', label: '设备名称', component: 'Input', rules: z.string().trim().min(1).max(100), dependencies: { triggerFields: ['action'], if: values => values.action === 'update' } },
  { fieldName: 'product_id', label: '设备分类', component: 'Input', dependencies: { triggerFields: ['action'], if: values => values.action === 'update' } },
  { fieldName: 'confirm_device_id', label: '确认设备标识', component: 'Input', componentProps: { maxlength: 32, autocomplete: 'off' }, dependencies: { triggerFields: ['action'], if: values => !!values.action && values.action !== 'update' }, rules: z.string().regex(/^[a-f0-9]{32}$/, '输入上方完整设备标识') },
] });
const [Detail, drawer] = useVbenDrawer({ title: '设备详情', class: 'w-full max-w-2xl', onBeforeClose: () => !saving.value, onConfirm: change, onClosed() { detailGeneration++; productGeneration++; detailPending?.abort(); productPending?.abort(); managed.value = undefined; currentAction.value = undefined; void form.resetForm(); } });
const [Grid, grid] = useVbenVxeGrid<Device>({ formOptions: { schema: [
  ...(platform.value ? [{ fieldName: 'tenant_id', label: '客户标识', component: 'Input' as const }] : []),
  { fieldName: 'name', label: '设备名称', component: 'Input' }, { fieldName: 'product_id', label: '分类标识', component: 'Input' },
  { fieldName: 'lifecycle', label: '生命周期', component: 'Select', componentProps: { allowClear: true, options: Object.entries(lifecycleNames).map(([value, label]) => ({ value, label })) } },
] }, gridOptions: {
  columns: [...(platform.value ? [{ field: 'tenant_name', title: '所属客户', minWidth: 180 }] : []),
    { field: 'name', title: '设备名称', minWidth: 200 }, { field: 'id', title: '设备标识', minWidth: 280 }, { field: 'product_id', title: '分类标识', minWidth: 280 },
    { field: 'lifecycle', title: '生命周期', width: 100, slots: { default: 'lifecycle' } }, { field: 'recovery_verified', title: '恢复核对', width: 130, slots: { default: 'recovery' } },
    { title: '连接状态', width: 100, slots: { default: 'connection' } }, { title: '授权执行', width: 160, slots: { default: 'authorization' } },
    { title: '连接观察时间', width: 185, slots: { default: 'observed' } }, { field: 'created_at', title: '注册时间', width: 185, formatter: ({ cellValue }: { cellValue: number }) => time(cellValue) },
    { title: '操作', fixed: 'right', width: 220, slots: { default: 'actions' } }],
  rowConfig: { keyField: 'id' }, pagerConfig: { pageSize: 20, pageSizes: [20, 50, 100] }, toolbarConfig: { refresh: true, search: true },
  proxyConfig: { ajax: { query: async ({ page }: { page: { currentPage: number; pageSize: number } }, values: Record<string, unknown>) => {
    clearTimeout(timer); listPending?.abort(); listPending = new AbortController(); failure.value = '';
    try {
      const data = await request<PageResult<Device> & { context: { permissions: string[]; menus: typeof session.menus } }>(endpoint.value, { signal: listPending.signal, params: { ...values, page: page.currentPage, per_page: page.pageSize } });
      applyAccessContext(data.context); refreshedAt.value = Date.now();
      const selected = data.items.find(device => device.id === managed.value?.id);
      if (selected && managed.value && !saving.value) managed.value.authorization = selected.authorization;
      return data;
    } catch (error) { if (!isCanceled(error)) failure.value = errorText(error); throw error; }
    finally { schedule(); }
  } } },
} });
function schedule() { clearTimeout(timer); if (active && !document.hidden && can('read')) timer = setTimeout(() => { void grid.query(); }, 5000); }
function visibility() { clearTimeout(timer); if (document.hidden) listPending?.abort(); else void grid.query(); }
async function read(device: Pick<Device, 'id'>, edit = editing.value) {
  if (saving.value) return;
  editing.value = edit; drawer.setState({ title: edit ? '管理设备授权' : '设备详情', showConfirmButton: edit }).open();
  const current = ++detailGeneration; detailPending?.abort(); detailPending = new AbortController(); detailBusy.value = true; detailFailure.value = '';
  try {
    const data = await request<DeviceManagement>(`${endpoint.value}/${device.id}`, { signal: detailPending.signal });
    if (current !== detailGeneration) return;
    managed.value = data; currentAction.value = undefined;
    await form.setValues({ name: data.name, product_id: data.product_id || '', action: undefined, confirm_device_id: '' });
    if (unknown.value) notice.value = '已读取当前状态。此前密码不会再次返回；需要时请明确发起新轮换。';
    unknown.value = false; if (!platform.value && canReadProducts.value && edit) void loadProducts();
  } catch (error) { if (!isCanceled(error) && current === detailGeneration) detailFailure.value = errorText(error); }
  finally { if (current === detailGeneration) detailBusy.value = false; }
}
async function loadProducts(page = 1, name = productSearch) {
  productSearch = name; const current = ++productGeneration; productPending?.abort(); productPending = new AbortController(); productBusy.value = true; productFailure.value = '';
  try { const data = await request<PageResult<Product>>('/center/products', { signal: productPending.signal, params: { name, page, per_page: 20 } }); if (current === productGeneration) products.value = data; }
  catch (error) { if (!isCanceled(error) && current === productGeneration) productFailure.value = errorText(error); }
  finally { if (current === productGeneration) productBusy.value = false; }
}
async function change() {
  const device = managed.value;
  if (!device || blocked.value || !(await form.validate()).valid) return;
  const values = await form.getValues(); const action = values.action as DeviceAction;
  if (!actionOptions.value.some(option => option.value === action)) return;
  if (action !== 'update' && values.confirm_device_id !== device.id) { detailFailure.value = '确认标识与当前设备不一致。'; return; }
  saving.value = true; detailFailure.value = ''; notice.value = '';
  try {
    const data = await request<DeviceChange>(`${endpoint.value}/${device.id}${action === 'update' ? '' : `/${action}`}`, { method: action === 'update' ? 'PATCH' : 'POST', data: { version: device.version,
      ...(action === 'update' ? { name: values.name, ...(values.product_id !== (device.product_id || '') ? { product_id: values.product_id || null } : {}) } : { confirm_device_id: values.confirm_device_id }) } });
    managed.value = data.device; currentAction.value = undefined; await form.setValues({ action: undefined, confirm_device_id: '' });
    notice.value = data.device.authorization?.status === 'pending' ? '变更已保存，仍等待 Broker 隔离旧连接与会话。' : '设备变更已完成。';
    if (data.credential) secret.value?.show(data.credential); await grid.query();
  } catch (error) {
    if (!isCanceled(error)) { detailFailure.value = errorText(error); if (!(error instanceof ApiError) || error.status >= 500) { unknown.value = true; notice.value = '未取得确定结果，操作可能已提交。请先读取当前状态；不会自动重试或再次生成密码。'; } }
  } finally { saving.value = false; }
}
type UsageAction = 'expiry' | 'end' | 'reassign';
const usageNames = { expiry: '设置使用期限', end: '结束使用关系', reassign: '转配设备' }; const usageAction = ref<UsageAction>('expiry'); const usageFailure = ref(''); let usageId = '';
const canUsage = (action: UsageAction) => platform.value && session.permissions.includes(action === 'reassign' ? 'platform.reassignments.create' : `platform.devices.${action}`);
const usageUnavailable = (action: UsageAction) => blocked.value || !!managed.value?.transfer_id || managed.value?.lifecycle === 'retired' || !managed.value?.usage || (action === 'expiry' ? !managed.value.usage.available : action === 'end' ? managed.value.usage.ended_at !== null : false);
const [UsageForm, usageForm] = useVbenForm({ showDefaultActions: false, layout: 'vertical', commonConfig: { componentProps: { class: 'w-full' } }, schema: [
  { fieldName: 'expires_at', label: '使用期限（留空取消）', component: 'Input', componentProps: { type: 'datetime-local' }, dependencies: { triggerFields: [], if: () => usageAction.value === 'expiry' } },
  { fieldName: 'target_tenant_id', label: '目标客户标识', component: 'Input', dependencies: { triggerFields: [], if: () => usageAction.value === 'reassign' }, rules: z.string().regex(/^[a-f0-9]{32}$/) },
  { fieldName: 'confirm_device_id', label: '确认设备标识', component: 'Input', rules: z.string().regex(/^[a-f0-9]{32}$/, '输入完整设备标识') },
] });
const [Usage, usage] = useVbenModal({ onConfirm: saveUsage, onBeforeClose: () => !saving.value, onClosed: () => usageForm.resetForm() });
async function editUsage(action: UsageAction) {
  if (!canUsage(action) || usageUnavailable(action)) return;
  usageAction.value = action; usageId = crypto.randomUUID().replaceAll('-', ''); usageFailure.value = ''; usage.setState({ title: usageNames[action] }).open();
  const date = managed.value?.usage?.expires_at ? new Date(managed.value.usage.expires_at * 1000) : undefined;
  await usageForm.setValues({ expires_at: date ? new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16) : '', target_tenant_id: '', confirm_device_id: '' });
}
async function saveUsage() {
  const device = managed.value; const action = usageAction.value;
  if (!device || saving.value || !canUsage(action) || isDemo() || !(await usageForm.validate()).valid) return;
  const values = await usageForm.getValues(); if (values.confirm_device_id !== device.id) { usageFailure.value = '确认标识与当前设备不一致。'; return; }
  const expires = values.expires_at ? Math.floor(Date.parse(values.expires_at) / 1000) : null;
  if (action === 'expiry' && expires !== null && (!Number.isFinite(expires) || expires <= Date.now() / 1000)) { usageFailure.value = '请选择未来期限，留空表示取消。'; return; }
  saving.value = true; usage.lock(); usageFailure.value = '';
  try {
    await request(`/platform/devices/${device.id}/usage/${action}`, { method: 'POST', data: { version: device.version, ...(action === 'expiry' ? { expires_at: expires } : { id: usageId, ...(action === 'reassign' ? { target_tenant_id: values.target_tenant_id } : {}) }) } });
    saving.value = false; usage.close(); drawer.close(); message.success(action === 'expiry' ? '使用期限已更新' : '旧使用关系已结束，请核验隔离进度');
    if (action !== 'expiry' && session.permissions.includes('platform.reassignments.read')) await router.push('/platform/reassignments'); else await grid.query();
  } catch (error) { if (!isCanceled(error)) usageFailure.value = errorText(error); }
  finally { saving.value = false; usage.unlock(); }
}
onMounted(() => document.addEventListener('visibilitychange', visibility));
onBeforeUnmount(() => { active = false; clearTimeout(timer); detailGeneration++; productGeneration++; listPending?.abort(); detailPending?.abort(); productPending?.abort(); document.removeEventListener('visibilitychange', visibility); });
</script>
<template>
  <Page auto-content-height><Alert v-if="failure" :message="failure" type="error" class="mb-4" show-icon /><Grid :table-title="platform ? '设备资产' : '设备管理'">
    <template #toolbar-tools><span class="text-sm text-muted-foreground">{{ refreshedAt ? `读取于 ${displayDateTime(refreshedAt, true)}` : '等待数据' }} · 可见时每 5 秒刷新</span></template>
    <template #lifecycle="{ row }"><Tag>{{ lifecycleNames[row.lifecycle as Device['lifecycle']] }}</Tag></template><template #recovery="{ row }"><Tag :color="row.recovery_verified ? 'default' : 'warning'">{{ row.recovery_verified ? '无待处理核对' : '待恢复核对' }}</Tag></template>
    <template #connection="{ row }"><Tag :color="connectionColors[row.connection.status as Device['connection']['status']]">{{ connectionNames[row.connection.status as Device['connection']['status']] }}</Tag></template>
    <template #authorization="{ row }"><Tag :color="row.authorization?.status === 'pending' ? 'warning' : 'default'">{{ row.authorization?.status === 'pending' ? '等待 Broker 撤权' : '无待处理撤权' }}</Tag></template><template #observed="{ row }">{{ time(row.connection.observed_at) }}</template>
    <template #actions="{ row }"><Space><Button type="link" size="small" :disabled="saving" @click="read(row, false)">详情</Button><Button v-if="!platform" type="link" size="small" @click="router.push(`/center/devices/${row.id}`)">通信详情</Button><Button v-if="Object.keys(names).some(can) || platform" type="link" size="small" :disabled="saving" @click="read(row, true)">管理</Button></Space></template>
  </Grid><Detail :confirm-loading="saving" :loading="detailBusy"><Alert v-if="detailFailure" :message="detailFailure" type="error" class="mb-4" show-icon /><Alert v-if="notice" :message="notice" :type="unknown || managed?.authorization?.status === 'pending' ? 'warning' : 'info'" class="mb-4" show-icon />
    <Button v-if="managed" :loading="detailBusy" :disabled="saving" class="mb-4" @click="read(managed)">读取当前状态</Button>
    <template v-if="managed"><Descriptions :column="1" bordered class="break-all mb-4"><DescriptionsItem label="设备名称">{{ managed.name }}</DescriptionsItem><DescriptionsItem label="设备标识">{{ managed.id }}</DescriptionsItem><DescriptionsItem label="所属客户">{{ managed.tenant_name }} · {{ managed.tenant_id }}</DescriptionsItem><DescriptionsItem label="客户状态">{{ managed.tenant_enabled ? '启用' : '停用' }}</DescriptionsItem><DescriptionsItem label="生命周期">{{ lifecycleNames[managed.lifecycle] }}</DescriptionsItem><DescriptionsItem label="连接状态">{{ connectionNames[managed.connection.status] }}</DescriptionsItem><DescriptionsItem label="分类标识">{{ managed.product_id || '未分类' }}</DescriptionsItem><DescriptionsItem label="使用关系">{{ managed.ownership_id }}</DescriptionsItem><DescriptionsItem label="恢复核对">{{ managed.recovery_verified ? '已核对' : '待核对，禁止接入' }}</DescriptionsItem><DescriptionsItem label="凭据状态">{{ managed.credential_active ? '存在有效凭据' : '无有效凭据' }}</DescriptionsItem><DescriptionsItem label="授权执行">{{ managed.authorization?.status === 'pending' ? '等待 Broker 撤权' : '无待处理撤权' }}</DescriptionsItem><DescriptionsItem label="撤权完成时间">{{ time(managed.authorization?.completed_at) }}</DescriptionsItem><DescriptionsItem label="使用期限">{{ managed.usage?.expires_at ? time(managed.usage.expires_at) : '未设置' }}</DescriptionsItem><DescriptionsItem label="使用状态">{{ managed.usage?.ended_at ? `已结束 · ${time(managed.usage.ended_at)}` : managed.usage?.available ? '当前有效' : '不可使用，请核对转配或期限' }}</DescriptionsItem><DescriptionsItem label="版本">{{ managed.version }}</DescriptionsItem></Descriptions>
      <Space v-if="platform" wrap class="mb-4"><Button v-for="action in (['expiry', 'end', 'reassign'] as UsageAction[]).filter(canUsage)" :key="action" :disabled="usageUnavailable(action)" @click="editUsage(action)">{{ usageNames[action] }}</Button></Space>
      <Alert v-if="managed.authorization?.status === 'pending'" type="warning" class="mb-4" message="变更已保存，等待 Broker 隔离旧身份。当前不能继续修改设备。" show-icon />
      <Alert v-if="managed.lifecycle === 'retired'" type="info" message="设备已永久退役，档案保留。" show-icon />
      <template v-else-if="editing"><Alert v-if="currentAction" :message="descriptions[currentAction]" type="info" class="mb-4" show-icon /><Form :common-config="{ disabled: blocked, componentProps: { class: 'w-full' } }">
        <template v-if="!platform && canReadProducts" #product_id="slotProps"><Select v-bind="slotProps" show-search allow-clear :filter-option="false" :loading="productBusy" :disabled="blocked || !!managed.transfer_id" :options="products.items.map(item => ({ value: item.id, label: item.name }))" @search="name => loadProducts(1, name)" /><Pagination v-if="products.total > 20" size="small" simple :current="products.page" :total="products.total" :page-size="20" :disabled="productBusy" @change="page => loadProducts(page)" /><Alert v-if="productFailure" :message="productFailure" type="warning" show-icon /></template>
      </Form></template>
    </template>
  </Detail><Usage :confirm-loading="saving"><Alert type="warning" class="mb-4" :message="usageAction === 'expiry' ? '到期后立即拒绝通信，Broker 负责后续隔离。' : '提交后结束旧关系并撤销旧授权。隔离完成后才能建立新关系。'" show-icon /><p class="mb-4">当前设备：{{ managed?.name }} · {{ managed?.id }}</p><Alert v-if="usageFailure" :message="usageFailure" type="error" class="mb-4" show-icon /><UsageForm /></Usage><DeviceCredentialView ref="secret" /></Page>
</template>
