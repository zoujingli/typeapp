<script setup lang="ts">
import type { FormInstance, TableColumnsType } from 'ant-design-vue';
import type { Device, Page } from '../api';
import type { CrudTableAction } from '../components/crud-table-actions.vue';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Alert, Button, Card, Checkbox, Descriptions, DescriptionsItem, Empty, Form, FormItem, Input, Pagination, Select, Table, Tag, message } from 'ant-design-vue';
import AppDrawer from '../components/app-drawer/app-drawer.vue';
import CrudSearchField from '../components/crud-search-field.vue';
import CrudTableActions from '../components/crud-table-actions.vue';
import MutationButton from '../components/mutation-button.vue';
import { buildTableScrollX } from '../utils/table';
import { applyAdminContext, clearAdminAccess, errorText, isCanceled, isDemo, request, session } from '../api';

/** 应用与人员会话独立；普通投影只含凭据代次和真实发布状态。 */
interface Application {
  id: string; tenant_id: string; name: string; enabled: boolean; recovery_verified: boolean; version: number;
  key: { version: number; active: boolean };
  publication: { id: string; version: number; status: string; stage: string; pending_invalidations: number; nodes: { node_id: string; state: string }[] };
}
interface Credential { client_id: string; username: string; password: string; version: number; protocol: number; tls: boolean; keep_alive: number; session_expiry: number }
interface DeviceGrant {
  id: string; device_id: string; device_name: string; ownership_id: string; version: number; current: boolean;
  receive: boolean; send: boolean; subscribe: boolean; topics: { publish: string; subscribe: string } | null;
}
type Context = { permissions: string[]; menus: typeof session.menus };
type Action = 'enable' | 'disable' | 'rotate' | 'revoke' | 'issue';
const router = useRouter();
const admin = computed(() => session.realm === 'admin');
const allowed = (action: string) => session.permissions.includes(`${session.realm}.applications.${action}`);
const result = ref<Page<Application>>({ items: [], total: 0, page: 1, per_page: 20 });
const tenantFilter = ref('');
const busy = ref(false); const failure = ref('');
const open = ref(false); const saving = ref(false); const saveFailure = ref('');
const formRef = ref<FormInstance>();
const form = reactive({ id: '', tenant_id: '', name: '', version: 0 });
const credential = ref<Credential | null>(null);
const detail = ref<Application | null>(null);
const detailFailure = ref(''); const detailBusy = ref(false);
let generation = 0; let pending: AbortController | null = null;
const grantApplication = ref<Application | null>(null);
const grants = ref<Page<DeviceGrant>>({ items: [], total: 0, page: 1, per_page: 20 });
const grantBusy = ref(false); const grantFailure = ref(''); const grantSaving = ref(false); const grantOpen = ref(false);
const grantFormRef = ref<FormInstance>();
const grantForm = reactive({ device_id: '', ownership_id: '', version: 0, receive: false, send: false, subscribe: false });
const candidates = ref<Page<Device>>({ items: [], total: 0, page: 1, per_page: 20 });
const candidateQuery = ref(''); const candidateBusy = ref(false); const candidateFailure = ref('');
const canReadDevices = computed(() => session.permissions.includes(`${session.realm}.devices.read`));
let grantGeneration = 0; let grantPending: AbortController | null = null; let grantSavePending: AbortController | null = null;
let candidateGeneration = 0; let candidatePending: AbortController | null = null;
const grantBlocked = computed(() => !grantApplication.value?.recovery_verified || ['pending', 'partial'].includes(grantApplication.value?.publication.status || ''));
const grantColumns: TableColumnsType<DeviceGrant> = [
  { title: '设备名称', dataIndex: 'device_name', width: 180, ellipsis: true }, { title: '设备标识', dataIndex: 'device_id', width: 290, ellipsis: true },
  { title: '通信权限', key: 'permissions', width: 200 }, { title: '使用关系', key: 'current', width: 130 },
  { title: '操作', key: 'actions', width: 110, fixed: 'right' },
];
const grantPagination = computed(() => ({ current: grants.value.page, pageSize: grants.value.per_page, total: grants.value.total, showSizeChanger: true, pageSizeOptions: ['20', '50', '100'] }));
const stageNames: Record<string, string> = { accepted: '已受理', executing: '执行中', completed: '已完成', failed: '失败', unknown: '未知' };
const columns = computed<TableColumnsType<Application>>(() => [
  { title: '应用名称', dataIndex: 'name', width: 220, ellipsis: true },
  ...(admin.value ? [{ title: '客户标识', dataIndex: 'tenant_id', width: 300, ellipsis: true }] : []),
  { title: '应用状态', key: 'status', width: 130 }, { title: '密钥状态', key: 'key', width: 170 },
  { title: '授权发布', key: 'publication', width: 150 }, { title: '操作', key: 'actions', width: 200, fixed: 'right' },
]);
const pagination = computed(() => ({ current: result.value.page, pageSize: result.value.per_page, total: result.value.total, showSizeChanger: true, pageSizeOptions: ['20', '50', '100'] }));
function path() { return admin.value ? '/admin/applications' : `/customer/tenants/${session.tenant?.id}/applications`; }
function scope() { return admin.value ? undefined : session.tenant?.id; }
function rejected(error: unknown) {
  if (clearAdminAccess(error)) { result.value.items = []; result.value.total = 0; open.value = false; detail.value = null; credential.value = null; closeGrants(); }
}
async function load(page = 1, perPage = result.value.per_page) {
  if (!allowed('read') || (!admin.value && !session.tenant)) return;
  const current = ++generation;
  pending?.abort(); pending = new AbortController(); busy.value = true; failure.value = '';
  try {
    const data = await request<Page<Application> & { context: Context }>(path(), { tenant: scope(), signal: pending.signal,
      params: { page, per_page: perPage, ...(admin.value ? { tenant_id: tenantFilter.value.trim() } : {}) } });
    if (current !== generation) return;
    result.value = data; applyAdminContext(data.context);
  } catch (error) { if (current === generation && !isCanceled(error)) { failure.value = errorText(error); rejected(error); } }
  finally { if (current === generation) busy.value = false; }
}
function edit(row?: Application) {
  if (isDemo() || !allowed(row ? 'update' : 'create')) return;
  Object.assign(form, { id: row?.id || crypto.randomUUID().replaceAll('-', ''), tenant_id: row?.tenant_id || tenantFilter.value.trim(), name: row?.name || '', version: row?.version || 0 });
  saveFailure.value = ''; open.value = true;
}
/** 请求标识在抽屉存续期间保持不变，响应丢失后的重试不会再创建应用。 */
async function save() {
  if (saving.value || isDemo() || !allowed(form.version ? 'update' : 'create')) return;
  try { await formRef.value?.validate(); } catch { return; }
  saving.value = true; saveFailure.value = '';
  try {
    const data = await request<{ application: Application; credential: Credential | null; replayed?: boolean }>(`${path()}${form.version ? `/${form.id}/update` : ''}`, {
      method: 'POST', tenant: scope(), data: { name: form.name.trim(), ...(admin.value ? { tenant_id: form.tenant_id.trim() } : {}), ...(form.version ? { version: form.version } : { id: form.id }) },
    });
    open.value = false; credential.value = data.credential;
    message.success(data.replayed ? '已确认原应用。如密钥未保存，请轮换后重新交付。' : form.version ? '应用资料已更新' : '应用已创建，请保存一次性密钥');
    await load();
  } catch (error) { if (!isCanceled(error)) { saveFailure.value = errorText(error); rejected(error); } }
  finally { saving.value = false; }
}
async function change(row: Application, action: Action) {
  try {
    const data = await request<{ application: Application; credential: Credential | null }>(`${path()}/${row.id}/${action}`, {
      method: 'POST', tenant: scope(), data: { version: row.version, ...(admin.value ? { tenant_id: row.tenant_id } : {}) },
    });
    credential.value = data.credential;
    message.success(data.application.publication.stage === 'completed' ? '变更已生效' : '变更已受理，请刷新查看节点执行结果');
    await load(result.value.page);
  } catch (error) { if (!isCanceled(error)) { failure.value = errorText(error); rejected(error); } }
}
async function showDetail(row: Application) {
  detail.value = row; detailBusy.value = true; detailFailure.value = '';
  const current = generation;
  try {
    const data = await request<{ application: Application; context: Context }>(`${path()}/${row.id}`, { tenant: scope() });
    if (current !== generation || detail.value?.id !== row.id) return;
    detail.value = data.application; applyAdminContext(data.context);
  } catch (error) { if (current === generation && !isCanceled(error)) { detailFailure.value = errorText(error); rejected(error); } }
  finally { if (current === generation) detailBusy.value = false; }
}
function closeGrants() {
  grantGeneration++; candidateGeneration++; grantPending?.abort(); grantSavePending?.abort(); candidatePending?.abort();
  grantApplication.value = null; grantOpen.value = false; grantSaving.value = false;
  grants.value.items = []; candidates.value.items = []; grantFailure.value = ''; candidateFailure.value = '';
}
async function showGrants(row: Application) {
  closeGrants(); grantApplication.value = row;
  await loadGrants();
}
async function loadGrants(page = 1, perPage = grants.value.per_page) {
  const app = grantApplication.value; if (!app || !allowed('read')) return;
  const current = ++grantGeneration; grantPending?.abort(); grantPending = new AbortController();
  grantBusy.value = true; grantFailure.value = '';
  try {
    const data = await request<Page<DeviceGrant> & { application: Application; context: Context }>(`${path()}/${app.id}/devices`, {
      tenant: scope(), params: { page, per_page: perPage }, signal: grantPending.signal,
    });
    if (current !== grantGeneration) return;
    grants.value = data; grantApplication.value = data.application; applyAdminContext(data.context);
    result.value.items = result.value.items.map(item => item.id === app.id ? data.application : item);
  } catch (error) { if (current === grantGeneration && !isCanceled(error)) { grantFailure.value = errorText(error); rejected(error); } }
  finally { if (current === grantGeneration) grantBusy.value = false; }
}
/** 设备选择沿用受权设备目录，分页和搜索不截断授权业务结果。 */
async function loadCandidates(page = 1) {
  const app = grantApplication.value; if (!app || !canReadDevices.value) return;
  const current = ++candidateGeneration; candidatePending?.abort(); candidatePending = new AbortController();
  candidateBusy.value = true; candidateFailure.value = '';
  try {
    const data = await request<Page<Device>>(admin.value ? '/admin/devices' : `/customer/tenants/${app.tenant_id}/devices`, {
      tenant: scope(), params: { page, per_page: 20, name: candidateQuery.value.trim(), ...(admin.value ? { tenant_id: app.tenant_id } : {}) }, signal: candidatePending.signal,
    });
    if (current === candidateGeneration) candidates.value = data;
  } catch (error) { if (current === candidateGeneration && !isCanceled(error)) candidateFailure.value = errorText(error); }
  finally { if (current === candidateGeneration) candidateBusy.value = false; }
}
function selectDevice(value: unknown) {
  const device = candidates.value.items.find(item => item.id === value);
  grantForm.device_id = device?.id || ''; grantForm.ownership_id = device?.ownership_id || '';
}
function editGrant(row?: DeviceGrant) {
  if (isDemo() || !allowed('authorize') || grantBlocked.value) return;
  Object.assign(grantForm, { device_id: row?.device_id || '', ownership_id: row?.ownership_id || '', version: row?.version || 0,
    receive: row?.receive || false, send: row?.send || false, subscribe: row?.subscribe || false });
  candidateQuery.value = ''; candidateFailure.value = ''; grantOpen.value = true;
  if (!row) void loadCandidates();
}
async function saveGrant() {
  const app = grantApplication.value; if (!app || grantSaving.value || isDemo() || !allowed('authorize') || grantBlocked.value) return;
  try { await grantFormRef.value?.validate(); } catch { return; }
  const current = grantGeneration; grantSavePending?.abort(); grantSavePending = new AbortController(); grantSaving.value = true; candidateFailure.value = '';
  try {
    const { device_id, ...data } = grantForm;
    const saved = await request<{ grant: DeviceGrant; application: Application; changed: boolean }>(`${path()}/${app.id}/devices/${device_id}`, {
      method: 'POST', tenant: scope(), signal: grantSavePending.signal, data: { ...data, ...(admin.value ? { tenant_id: app.tenant_id } : {}) },
    });
    if (current !== grantGeneration) return;
    grantOpen.value = false; grantApplication.value = saved.application;
    message.success(!saved.changed ? '权限未改变' : saved.application.publication.stage === 'completed' ? '授权变更已生效' : '授权变更已受理，请刷新核对节点回执');
    await loadGrants(grants.value.page);
  } catch (error) { if (current === grantGeneration && !isCanceled(error)) { candidateFailure.value = errorText(error); rejected(error); } }
  finally { grantSaving.value = false; }
}
function actions(row: Application): CrudTableAction[] {
  const pendingPublication = ['pending', 'partial'].includes(row.publication.status) || !row.recovery_verified;
  return [
    { label: '详情', readOnly: true, onClick: () => showDetail(row) },
    { label: '编辑', visible: allowed('update'), disabled: pendingPublication, onClick: () => edit(row) },
    { label: row.enabled ? '停用' : '启用', visible: allowed(row.enabled ? 'disable' : 'enable'), danger: row.enabled, disabled: pendingPublication, confirmTitle: row.enabled ? '停用接入应用？' : '启用接入应用？', confirmContent: row.enabled ? '新连接将被拒绝，活动连接按节点回执撤权。密钥不会因此重新签发。' : '启用后仍需有效密钥和对应设备通信授权。', onClick: () => change(row, row.enabled ? 'disable' : 'enable') },
    { label: '轮换密钥', visible: allowed('keys') && row.key.active, disabled: pendingPublication || !row.enabled, confirmTitle: '轮换应用密钥？', confirmContent: '旧密钥立即失效，活动连接将撤权。新密钥只在此次成功响应中显示。', onClick: () => change(row, 'rotate') },
    { label: '撤销密钥', visible: allowed('keys') && row.key.active, danger: true, disabled: pendingPublication, confirmTitle: '撤销应用密钥？', confirmContent: '应用不能再凭此密钥接入。恢复接入需另行签发新密钥。', onClick: () => change(row, 'revoke') },
    { label: '签发密钥', visible: allowed('keys') && !row.key.active, disabled: pendingPublication || !row.enabled, confirmTitle: '签发新的应用密钥？', confirmContent: '继续使用原应用身份，旧密钥不会恢复有效。', onClick: () => change(row, 'issue') },
    { label: '设备授权', readOnly: true, onClick: () => showGrants(row) },
  ];
}
watch([() => session.realm, () => session.token, () => session.tenant?.id], () => {
  generation++; pending?.abort(); result.value.items = []; result.value.total = 0;
  credential.value = null; detail.value = null; open.value = false; failure.value = '';
  closeGrants();
  if (!admin.value && !session.tenant) void router.replace('/tenants'); else void load();
}, { immediate: true });
onBeforeUnmount(() => { generation++; pending?.abort(); credential.value = null; closeGrants(); });
</script>

<template>
  <section class="iot-page">
    <header class="page-heading"><div><h1>接入应用</h1><p class="muted">{{ admin ? '管理客户业务系统的独立通信身份' : `${session.tenant?.name || ''} · 外部业务系统接入` }}</p></div><MutationButton v-if="allowed('create')" type="primary" @click="edit()">创建应用</MutationButton></header>
    <Card :bordered="false">
      <Alert class="page-alert" type="info" show-icon message="应用密钥独立于后台账号和设备凭据。认证成功后，仍须获得对应设备的通信权限。" />
      <form v-if="admin" class="crud-search-grid" @submit.prevent="load()"><CrudSearchField label="客户标识"><Input v-model:value="tenantFilter" aria-label="客户标识筛选" :maxlength="32" :disabled="busy" allow-clear /></CrudSearchField><div class="crud-search-grid__actions"><Button type="primary" html-type="submit" :loading="busy">查询</Button><Button :disabled="busy" @click="tenantFilter = ''; load()">重置</Button></div></form>
      <Alert v-if="failure" :message="failure" type="error" class="page-alert" show-icon role="alert" />
      <div class="table-toolbar"><span>{{ result.total }} 个接入应用</span><Button :loading="busy" :disabled="!allowed('read')" @click="load(result.page)">刷新</Button></div>
      <Table :columns="columns" :data-source="result.items" row-key="id" :loading="busy" :pagination="pagination" :scroll="buildTableScrollX(columns)" @change="page => load(page.current, page.pageSize)">
        <template #emptyText><Empty :description="failure ? '数据暂不可用' : '暂无接入应用'" /></template>
        <template #bodyCell="{ column, record }"><Tag v-if="column.key === 'status'" :color="record.enabled ? 'success' : 'default'">{{ record.enabled ? '已启用' : '已停用' }}</Tag><span v-else-if="column.key === 'key'">v{{ record.key.version }} · {{ record.key.active ? '有效' : '已撤销或待核对' }}</span><span v-else-if="column.key === 'publication'">{{ stageNames[record.publication.stage] || record.publication.stage }}</span><CrudTableActions v-else-if="column.key === 'actions'" :actions="actions(record as Application)" /></template>
      </Table>
    </Card>
    <AppDrawer v-model:open="open" :title="form.version ? '编辑接入应用' : '创建接入应用'" width-size="sm" :confirm-loading="saving" :ok-disabled="saving || isDemo()" :closable="!saving" @ok="save">
      <Alert v-if="saveFailure" type="error" :message="saveFailure" class="page-alert" show-icon role="alert" />
      <Form ref="formRef" :model="form" layout="vertical" :disabled="saving">
        <FormItem v-if="admin" label="客户标识" name="tenant_id" :rules="[{ required: true, pattern: /^[a-f0-9]{32}$/, message: '请输入客户工作区的完整标识' }]"><Input v-model:value="form.tenant_id" :disabled="Boolean(form.version)" :maxlength="32" /></FormItem>
        <FormItem label="应用名称" name="name" :rules="[{ required: true, whitespace: true, message: '请输入应用名称' }]"><Input v-model:value="form.name" :maxlength="100" /></FormItem>
      </Form>
    </AppDrawer>
    <AppDrawer :open="Boolean(credential)" title="保存一次性应用密钥" width-size="sm" :show-footer="false" @update:open="value => { if (!value) credential = null; }">
      <Alert type="warning" message="关闭后无法再次查看；遗失时请轮换密钥。不要将密钥写入公开日志或页面代码。" class="page-alert" show-icon />
      <Descriptions v-if="credential" :column="1" bordered><DescriptionsItem label="Client ID / 用户名">{{ credential.username }}</DescriptionsItem><DescriptionsItem label="连接参数">MQTT {{ credential.protocol }} / TLS；keepalive {{ credential.keep_alive }} 秒；session expiry {{ credential.session_expiry }} 秒</DescriptionsItem></Descriptions>
      <Input.TextArea v-if="credential" :value="credential.password" aria-label="一次性应用密钥" readonly :rows="3" class="mt-4" />
    </AppDrawer>
    <AppDrawer :open="Boolean(detail)" title="接入应用详情" width-size="sm" :show-footer="false" @update:open="value => { if (!value) detail = null; }">
      <Alert v-if="detailFailure" type="error" :message="detailFailure" show-icon role="alert" />
      <template v-if="detail"><Descriptions :column="1" bordered><DescriptionsItem label="应用标识">{{ detail.id }}</DescriptionsItem><DescriptionsItem label="客户标识">{{ detail.tenant_id }}</DescriptionsItem><DescriptionsItem label="用户名">app:{{ detail.id }}</DescriptionsItem><DescriptionsItem label="密钥代次">{{ detail.key.version }}</DescriptionsItem><DescriptionsItem label="授权发布">{{ stageNames[detail.publication.stage] || detail.publication.stage }}</DescriptionsItem><DescriptionsItem label="恢复核对">{{ detail.recovery_verified ? '已核对' : '待核对，禁止接入' }}</DescriptionsItem></Descriptions>
        <p class="muted mt-4">密钥不会在详情中回显。发布完成表示相关节点已执行授权变更，不表示应用当前在线。</p>
        <Button :loading="detailBusy" @click="showDetail(detail)">刷新详情</Button>
      </template>
    </AppDrawer>
    <AppDrawer :open="Boolean(grantApplication)" title="应用设备授权" width-size="lg" :show-footer="false" @update:open="value => { if (!value) closeGrants(); }">
      <template v-if="grantApplication">
        <Alert type="info" class="page-alert" show-icon message="授权仅作用于设备当前使用关系。接收不会自动订阅；订阅必须具备接收资格，发送权限独立。" />
        <p>{{ grantApplication.name }} · 授权发布：{{ stageNames[grantApplication.publication.stage] || grantApplication.publication.stage }}</p>
        <Alert v-if="grantFailure" :message="grantFailure" type="error" class="page-alert" show-icon role="alert" />
        <div class="table-toolbar"><MutationButton v-if="allowed('authorize')" type="primary" :disabled="grantBlocked" @click="editGrant()">添加设备授权</MutationButton><Button :loading="grantBusy" :disabled="grantSaving" @click="loadGrants(grants.page)">刷新授权</Button></div>
        <Table :columns="grantColumns" :data-source="grants.items" row-key="id" :loading="grantBusy" :pagination="grantPagination" :scroll="buildTableScrollX(grantColumns)" @change="page => loadGrants(page.current, page.pageSize)">
          <template #emptyText><Empty :description="grantFailure ? '数据暂不可用' : '尚未授予设备通信权限'" /></template>
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'permissions'"><Tag v-if="record.receive">接收</Tag><Tag v-if="record.send">发送</Tag><Tag v-if="record.subscribe">订阅</Tag><span v-if="!record.receive && !record.send && !record.subscribe">已撤销</span></template>
            <Tag v-else-if="column.key === 'current'" :color="record.current ? 'success' : 'default'">{{ record.current ? '当前关系' : '已结束' }}</Tag>
            <MutationButton v-else-if="column.key === 'actions' && allowed('authorize')" type="link" :disabled="grantBlocked" @click="editGrant(record as DeviceGrant)">调整授权</MutationButton>
          </template>
        </Table>
        <p class="muted mt-4">发布完成表示节点已执行授权变更。消息接收、MQTT 协议确认和设备业务执行结果是不同事实，平台不解析原始载荷或保证设备已经执行。</p>
      </template>
    </AppDrawer>
    <AppDrawer v-model:open="grantOpen" :title="grantForm.version ? '调整设备通信授权' : '添加设备通信授权'" width-size="sm" :confirm-loading="grantSaving" :closable="!grantSaving" :ok-disabled="grantSaving || isDemo() || grantBlocked" @ok="saveGrant">
      <Alert v-if="candidateFailure" :message="candidateFailure" type="error" show-icon class="page-alert" role="alert" />
      <Form ref="grantFormRef" :model="grantForm" layout="vertical" :disabled="grantSaving">
        <template v-if="!grantForm.version && canReadDevices">
          <FormItem label="搜索设备"><Input.Search v-model:value="candidateQuery" :maxlength="100" :loading="candidateBusy" @search="loadCandidates()" /></FormItem>
          <FormItem label="选择设备" name="device_id" :rules="[{ required: true, message: '请选择设备' }]"><Select :value="grantForm.device_id || undefined" :loading="candidateBusy" :options="candidates.items.map(device => ({ value: device.id, label: `${device.name} · ${device.id}`, disabled: device.model_version !== null || !['inactive', 'enabled'].includes(device.lifecycle) }))" @change="selectDevice" /></FormItem>
          <Pagination size="small" :current="candidates.page" :page-size="20" :total="candidates.total" :show-size-changer="false" @change="loadCandidates" />
        </template>
        <FormItem v-else label="设备标识" name="device_id" :rules="[{ required: true, pattern: /^[a-f0-9]{32}$/, message: '请输入完整设备标识' }]"><Input v-model:value="grantForm.device_id" :readonly="Boolean(grantForm.version)" :maxlength="32" /></FormItem>
        <FormItem label="当前使用关系" name="ownership_id" :rules="[{ required: true, pattern: /^[a-f0-9]{32}$/, message: '请选择设备或输入当前使用关系标识' }]"><Input v-model:value="grantForm.ownership_id" :readonly="Boolean(grantForm.version) || canReadDevices" :maxlength="32" /></FormItem>
        <FormItem label="通信权限"><Checkbox v-model:checked="grantForm.receive" @change="() => { if (!grantForm.receive) grantForm.subscribe = false; }">接收上行</Checkbox><Checkbox v-model:checked="grantForm.send">发送下行</Checkbox><Checkbox v-model:checked="grantForm.subscribe" :disabled="!grantForm.receive">订阅上行</Checkbox></FormItem>
        <p class="muted">取消全部权限将撤销此授权。已有授权保留版本，过期表单不能重新赋权。</p>
      </Form>
    </AppDrawer>
  </section>
</template>
