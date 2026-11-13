<script setup lang="ts">
import type { Device } from '../../api/center';
import type { Page as PageResult } from '../../api/contracts';
import { computed, onBeforeUnmount, ref } from 'vue';
import { Alert, Button, Descriptions, DescriptionsItem, Dropdown, Input, Menu, MenuItem, Modal, Pagination, Select, Space, Tag, message } from 'ant-design-vue';
import { Page, useVbenDrawer, useVbenModal } from '@vben/common-ui';
import { useVbenForm, z } from '../../adapter/form';
import { useVbenVxeGrid } from '../../adapter/vxe-table';
import { applyAccessContext, errorText, isCanceled, isDemo, request, session } from '../../api/identity';
interface Application {
  id: string; tenant_id: string; name: string; enabled: boolean; recovery_verified: boolean; version: number;
  key: { version: number; active: boolean };
  publication: { id: string; version: number; status: string; stage: string; pending_invalidations: number; nodes: { node_id: string; state: string }[] };
}
interface Credential { client_id: string; username: string; password: string; version: number; protocol: number; tls: boolean; keep_alive: number; session_expiry: number }
interface DeviceGrant { id: string; device_id: string; device_name: string; ownership_id: string; version: number; current: boolean; receive: boolean; send: boolean; subscribe: boolean; topics: { publish: string; subscribe: string } | null }
type Context = { permissions: string[]; menus: typeof session.menus };
type Action = 'enable' | 'disable' | 'rotate' | 'revoke' | 'issue';
const platform = computed(() => session.realm === 'platform'); const domain = computed(() => platform.value ? 'platform' : 'center');
const endpoint = computed(() => `/${domain.value}/applications`); const allowed = (action: string) => session.permissions.includes(`${domain.value}.applications.${action}`);
const failure = ref(''); const saveFailure = ref(''); const detailFailure = ref(''); const grantFailure = ref(''); const candidateFailure = ref('');
const editing = ref<Application>(); const detail = ref<Application>(); const grantApplication = ref<Application>(); const grant = ref<DeviceGrant>(); const credential = ref<Credential>();
const saving = ref(false); const detailBusy = ref(false); const candidateBusy = ref(false); const candidates = ref<PageResult<Device>>({ items: [], total: 0, page: 1, per_page: 20 });
const candidateQuery = ref(''); const canReadDevices = computed(() => session.permissions.includes(`${domain.value}.devices.read`));
const grantBlocked = computed(() => !grantApplication.value?.recovery_verified || ['pending', 'partial'].includes(grantApplication.value?.publication.status || ''));
let requestId = ''; let listPending: AbortController | undefined; let candidatePending: AbortController | undefined; let grantGeneration = 0; let candidateGeneration = 0;
const stages: Record<string, string> = { accepted: '已受理', executing: '执行中', completed: '已完成', failed: '失败', unknown: '未知' };
const pendingPublication = (row: Application) => !row.recovery_verified || ['pending', 'partial'].includes(row.publication.status);
const [Form, form] = useVbenForm({ showDefaultActions: false, layout: 'vertical', commonConfig: { componentProps: { class: 'w-full' } }, schema: [
  ...(platform.value ? [{ fieldName: 'tenant_id', label: '客户标识', component: 'Input' as const, componentProps: () => ({ disabled: !!editing.value }), rules: z.string().regex(/^[a-f0-9]{32}$/, '请输入完整客户标识') }] : []),
  { fieldName: 'name', label: '应用名称', component: 'Input', rules: z.string().trim().min(1).max(100) },
] });
const [Editor, editor] = useVbenModal({ onConfirm: save, onClosed: () => form.resetForm() });
const [Secret, secretModal] = useVbenModal({ title: '保存一次性应用密钥', footer: false, onOpenChange(open) { if (!open) credential.value = undefined; } });
const [Detail, detailDrawer] = useVbenDrawer({ title: '接入应用详情', footer: false, onClosed() { detail.value = undefined; } });
const [GrantsDrawer, grantsDrawer] = useVbenDrawer({ title: '应用设备授权', footer: false, class: 'w-full max-w-5xl', onBeforeClose: () => !saving.value, onOpened: () => { void grants.reload(); }, onClosed() { grantGeneration++; candidateGeneration++; candidatePending?.abort(); grantApplication.value = undefined; } });
const [Grid, grid] = useVbenVxeGrid<Application>({ ...(platform.value ? { formOptions: { schema: [{ fieldName: 'tenant_id', label: '客户标识', component: 'Input' as const }] } } : {}), gridOptions: {
  columns: [{ field: 'name', title: '应用名称', minWidth: 200 }, ...(platform.value ? [{ field: 'tenant_id', title: '客户标识', minWidth: 280 }] : []),
    { title: '应用状态', width: 110, slots: { default: 'status' } }, { title: '密钥状态', width: 180, slots: { default: 'key' } }, { title: '授权发布', width: 140, slots: { default: 'publication' } },
    { title: '操作', width: 250, fixed: 'right', slots: { default: 'actions' } }],
  rowConfig: { keyField: 'id' }, pagerConfig: { pageSize: 20, pageSizes: [20, 50, 100] }, toolbarConfig: { refresh: true, search: platform.value },
  proxyConfig: { ajax: { query: async ({ page }: { page: { currentPage: number; pageSize: number } }, values: Record<string, unknown>) => {
    listPending?.abort(); listPending = new AbortController(); failure.value = '';
    try { const data = await request<PageResult<Application> & { context: Context }>(endpoint.value, { signal: listPending.signal, params: { ...values, page: page.currentPage, per_page: page.pageSize } }); applyAccessContext(data.context); return data; }
    catch (error) { if (!isCanceled(error)) failure.value = errorText(error); throw error; }
  } } },
} });
async function edit(row?: Application) {
  editing.value = row; requestId = row?.id || crypto.randomUUID().replaceAll('-', ''); saveFailure.value = '';
  editor.setState({ title: row ? '编辑接入应用' : '创建接入应用' }).open(); await form.setValues({ name: row?.name || '', ...(platform.value ? { tenant_id: row?.tenant_id || '' } : {}) });
}
async function save() {
  if (isDemo() || !allowed(editing.value ? 'update' : 'create') || !(await form.validate()).valid) return;
  editor.lock(); saveFailure.value = '';
  try {
    const row = editing.value; const values = await form.getValues();
    const data = await request<{ application: Application; credential: Credential | null; replayed?: boolean }>(`${endpoint.value}${row ? `/${row.id}/update` : ''}`, { method: 'POST', data: { ...values, ...(row ? { version: row.version } : { id: requestId }) } });
    editor.close(); showSecret(data.credential); message.success(data.replayed ? '已确认原应用，密钥不重复展示；遗失请轮换。' : '应用已保存'); await grid.query();
  } catch (error) { if (!isCanceled(error)) saveFailure.value = errorText(error); }
  finally { editor.unlock(); }
}
function showSecret(value: Credential | null) { if (value) { credential.value = value; secretModal.open(); } }
function actions(row: Application) {
  return [
    { action: row.enabled ? 'disable' : 'enable', label: row.enabled ? '停用应用' : '启用应用', permission: row.enabled ? 'disable' : 'enable', danger: row.enabled, visible: true, disabled: false },
    { action: 'rotate', label: '轮换密钥', permission: 'keys', danger: false, visible: row.key.active, disabled: !row.enabled },
    { action: 'revoke', label: '撤销密钥', permission: 'keys', danger: true, visible: row.key.active, disabled: false },
    { action: 'issue', label: '签发密钥', permission: 'keys', danger: false, visible: !row.key.active, disabled: !row.enabled },
  ].filter(item => item.visible && allowed(item.permission));
}
function change(row: Application, action: Action, label: string) {
  Modal.confirm({ title: `${label}？`, content: '变更需要节点执行回执；密钥只在签发或轮换的成功响应中展示。', async onOk() {
    try {
      const data = await request<{ application: Application; credential: Credential | null }>(`${endpoint.value}/${row.id}/${action}`, { method: 'POST', data: { version: row.version, ...(platform.value ? { tenant_id: row.tenant_id } : {}) } });
      showSecret(data.credential); message.success(data.application.publication.stage === 'completed' ? '变更已生效' : '变更已受理，请核对节点执行结果'); await grid.query();
    } catch (error) { message.error(errorText(error)); throw error; }
  } });
}
async function show(row: Application) {
  detailBusy.value = true; detailFailure.value = ''; detail.value = row; detailDrawer.open();
  try { const data = await request<{ application: Application; context: Context }>(`${endpoint.value}/${row.id}`); if (detail.value?.id === row.id) { detail.value = data.application; applyAccessContext(data.context); } }
  catch (error) { if (!isCanceled(error)) detailFailure.value = errorText(error); }
  finally { detailBusy.value = false; }
}
const [Grants, grants] = useVbenVxeGrid<DeviceGrant>({ gridOptions: {
  columns: [{ field: 'device_name', title: '设备名称', minWidth: 180 }, { field: 'device_id', title: '设备标识', minWidth: 280 }, { title: '通信权限', width: 200, slots: { default: 'permissions' } }, { title: '使用关系', width: 120, slots: { default: 'current' } }, { title: '操作', width: 110, fixed: 'right', slots: { default: 'actions' } }],
  rowConfig: { keyField: 'id' }, pagerConfig: { pageSize: 20, pageSizes: [20, 50, 100] }, toolbarConfig: { refresh: true },
  proxyConfig: { autoLoad: false, ajax: { query: async ({ page }: { page: { currentPage: number; pageSize: number } }) => {
    const app = grantApplication.value; if (!app) return { items: [], total: 0 };
    const current = ++grantGeneration; grantFailure.value = '';
    try {
      const data = await request<PageResult<DeviceGrant> & { application: Application; context: Context }>(`${endpoint.value}/${app.id}/devices`, { params: { page: page.currentPage, per_page: page.pageSize } });
      if (current !== grantGeneration) return { items: [], total: 0 };
      grantApplication.value = data.application; applyAccessContext(data.context); return data;
    } catch (error) { if (!isCanceled(error)) grantFailure.value = errorText(error); throw error; }
  } } },
} });
function showGrants(row: Application) { grantApplication.value = row; grantsDrawer.open(); }
const [GrantForm, grantForm] = useVbenForm({ showDefaultActions: false, layout: 'vertical', commonConfig: { componentProps: { class: 'w-full' } }, schema: [
  { fieldName: 'device_id', label: '设备标识', component: 'Input', componentProps: () => ({ readonly: !!grant.value }), rules: z.string().regex(/^[a-f0-9]{32}$/) },
  { fieldName: 'ownership_id', label: '当前使用关系', component: 'Input', componentProps: () => ({ readonly: !!grant.value || canReadDevices.value }), rules: z.string().regex(/^[a-f0-9]{32}$/) },
  { fieldName: 'receive', label: '接收上行', component: 'Switch', componentProps: { class: 'w-auto' }, defaultValue: false },
  { fieldName: 'send', label: '发送下行', component: 'Switch', componentProps: { class: 'w-auto' }, defaultValue: false },
  { fieldName: 'subscribe', label: '订阅上行', component: 'Switch', componentProps: { class: 'w-auto' }, defaultValue: false, dependencies: { triggerFields: ['receive'], disabled: values => !values.receive, trigger: async values => { if (!values.receive) await grantForm.setFieldValue('subscribe', false); } } },
] });
const [GrantEditor, grantEditor] = useVbenModal({ title: '设备通信授权', onConfirm: saveGrant, onBeforeClose: () => !saving.value, onClosed() { candidateGeneration++; candidatePending?.abort(); candidates.value.items = []; return grantForm.resetForm(); } });
async function editGrant(row?: DeviceGrant) {
  if (isDemo() || !allowed('authorize') || grantBlocked.value) return;
  grant.value = row; candidateFailure.value = ''; candidateQuery.value = ''; grantEditor.open();
  await grantForm.setValues({ device_id: row?.device_id || '', ownership_id: row?.ownership_id || '', receive: row?.receive || false, send: row?.send || false, subscribe: row?.subscribe || false });
  if (!row && canReadDevices.value) void loadCandidates();
}
async function loadCandidates(page = 1) {
  const app = grantApplication.value; if (!app || !canReadDevices.value) return;
  const current = ++candidateGeneration; candidatePending?.abort(); candidatePending = new AbortController(); candidateBusy.value = true; candidateFailure.value = '';
  try {
    const data = await request<PageResult<Device>>(`/${domain.value}/devices`, { signal: candidatePending.signal, params: { page, per_page: 20, name: candidateQuery.value.trim(), ...(platform.value ? { tenant_id: app.tenant_id } : {}) } });
    if (current === candidateGeneration) candidates.value = data;
  } catch (error) { if (!isCanceled(error) && current === candidateGeneration) candidateFailure.value = errorText(error); }
  finally { if (current === candidateGeneration) candidateBusy.value = false; }
}
function selectDevice(value: unknown) { const device = candidates.value.items.find(item => item.id === value); void grantForm.setValues({ device_id: device?.id || '', ownership_id: device?.ownership_id || '' }); }
async function saveGrant() {
  const app = grantApplication.value;
  if (!app || saving.value || isDemo() || !allowed('authorize') || grantBlocked.value || !(await grantForm.validate()).valid) return;
  saving.value = true; grantEditor.lock(); candidateFailure.value = '';
  try {
    const { device_id, ...values } = await grantForm.getValues();
    const data = await request<{ application: Application; changed: boolean }>(`${endpoint.value}/${app.id}/devices/${device_id}`, { method: 'POST', data: { ...values, version: grant.value?.version || 0, ...(platform.value ? { tenant_id: app.tenant_id } : {}) } });
    grantApplication.value = data.application; saving.value = false; grantEditor.close(); message.success(!data.changed ? '权限未改变' : data.application.publication.stage === 'completed' ? '授权已生效' : '授权已受理，请核对节点回执'); await grants.query(); await grid.query();
  } catch (error) { if (!isCanceled(error)) candidateFailure.value = errorText(error); }
  finally { saving.value = false; grantEditor.unlock(); }
}
onBeforeUnmount(() => { listPending?.abort(); candidatePending?.abort(); grantGeneration++; candidateGeneration++; credential.value = undefined; });
</script>
<template>
  <Page auto-content-height><Alert v-if="failure" :message="failure" type="error" class="mb-4" show-icon /><Grid table-title="接入应用">
    <template #toolbar-tools><Button v-if="allowed('create')" type="primary" :disabled="isDemo()" @click="edit()">创建应用</Button></template>
    <template #status="{ row }"><Tag :color="row.enabled ? 'success' : 'default'">{{ row.enabled ? '已启用' : '已停用' }}</Tag></template><template #key="{ row }">v{{ row.key.version }} · {{ row.key.active ? '有效' : '已撤销或待核对' }}</template><template #publication="{ row }">{{ stages[row.publication.stage] || row.publication.stage }}</template>
    <template #actions="{ row }"><Space><Button type="link" size="small" @click="show(row)">详情</Button><Button type="link" size="small" @click="showGrants(row)">设备授权</Button><Dropdown><Button type="link" size="small">更多</Button><template #overlay><Menu><MenuItem v-if="allowed('update')" :disabled="isDemo() || pendingPublication(row)" @click="edit(row)">编辑资料</MenuItem><MenuItem v-for="item in actions(row)" :key="item.action" :danger="item.danger" :disabled="isDemo() || item.disabled || pendingPublication(row)" @click="change(row, item.action as Action, item.label)">{{ item.label }}</MenuItem></Menu></template></Dropdown></Space></template>
  </Grid><Editor><Alert v-if="saveFailure" :message="saveFailure" type="error" class="mb-4" show-icon /><Form /></Editor>
  <Secret><Alert type="warning" class="mb-4" message="关闭后无法再次查看，遗失时请轮换密钥。" show-icon /><Descriptions class="break-all" v-if="credential" :column="1" bordered><DescriptionsItem label="Client ID">{{ credential.client_id }}</DescriptionsItem><DescriptionsItem label="用户名">{{ credential.username }}</DescriptionsItem><DescriptionsItem label="连接参数">MQTT {{ credential.protocol }} / TLS；Keep Alive {{ credential.keep_alive }} 秒；Session Expiry {{ credential.session_expiry }} 秒</DescriptionsItem></Descriptions><Input.TextArea v-if="credential" :value="credential.password" readonly :rows="3" class="mt-4" aria-label="一次性应用密钥" /></Secret>
  <Detail><Alert v-if="detailFailure" :message="detailFailure" type="error" class="mb-4" show-icon /><template v-if="detail"><Descriptions class="break-all" :column="1" bordered><DescriptionsItem label="应用标识">{{ detail.id }}</DescriptionsItem><DescriptionsItem label="客户标识">{{ detail.tenant_id }}</DescriptionsItem><DescriptionsItem label="用户名">app:{{ detail.id }}</DescriptionsItem><DescriptionsItem label="密钥代次">{{ detail.key.version }}</DescriptionsItem><DescriptionsItem label="授权发布">{{ stages[detail.publication.stage] || detail.publication.stage }}</DescriptionsItem><DescriptionsItem label="待撤权数">{{ detail.publication.pending_invalidations }}</DescriptionsItem><DescriptionsItem label="恢复核对">{{ detail.recovery_verified ? '已核对' : '待核对，禁止接入' }}</DescriptionsItem><DescriptionsItem v-for="node in detail.publication.nodes" :key="node.node_id" :label="node.node_id">{{ node.state }}</DescriptionsItem></Descriptions><Button :loading="detailBusy" class="mt-4" @click="show(detail)">刷新详情</Button></template></Detail>
  <GrantsDrawer><template v-if="grantApplication"><Alert type="info" class="mb-4" message="授权绑定设备当前使用关系。接收、发送、订阅分别授权；取消全部权限即撤销授权。" show-icon /><Alert v-if="grantFailure" :message="grantFailure" type="error" class="mb-4" show-icon /><Grants :table-title="`${grantApplication.name} · ${stages[grantApplication.publication.stage] || grantApplication.publication.stage}`"><template #toolbar-tools><Button v-if="allowed('authorize')" type="primary" :disabled="isDemo() || grantBlocked" @click="editGrant()">添加设备授权</Button></template><template #permissions="{ row }"><Tag v-if="row.receive">接收</Tag><Tag v-if="row.send">发送</Tag><Tag v-if="row.subscribe">订阅</Tag><span v-if="!row.receive && !row.send && !row.subscribe">已撤销</span></template><template #current="{ row }"><Tag :color="row.current ? 'success' : 'default'">{{ row.current ? '当前关系' : '已结束' }}</Tag></template><template #actions="{ row }"><Button v-if="allowed('authorize')" type="link" :disabled="isDemo() || grantBlocked" @click="editGrant(row)">调整授权</Button></template></Grants></template></GrantsDrawer>
  <GrantEditor><Alert v-if="candidateFailure" :message="candidateFailure" type="error" class="mb-4" show-icon /><template v-if="!grant && canReadDevices"><Input.Search v-model:value="candidateQuery" :loading="candidateBusy" placeholder="搜索设备名称" class="mb-4" @search="loadCandidates()" /><Select :loading="candidateBusy" class="mb-4 w-full" placeholder="选择设备" :options="candidates.items.map(device => ({ value: device.id, label: `${device.name} · ${device.id}`, disabled: !['inactive', 'enabled'].includes(device.lifecycle) }))" @change="selectDevice" /><Pagination size="small" :current="candidates.page" :page-size="20" :total="candidates.total" :show-size-changer="false" :disabled="candidateBusy" class="mb-4" @change="loadCandidates" /></template><GrantForm :common-config="{ disabled: saving, componentProps: { class: 'w-full' } }" /></GrantEditor>
  </Page>
</template>
