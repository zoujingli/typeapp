<script setup lang="ts">
import type { FormInstance, TableColumnsType } from 'ant-design-vue';
import type { Page } from '../api';
import type { CrudTableAction } from '../components/crud-table-actions.vue';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Alert, Button, Card, Descriptions, DescriptionsItem, Empty, Form, FormItem, Input, Table, Tag, message } from 'ant-design-vue';
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
  if (clearAdminAccess(error)) { result.value.items = []; result.value.total = 0; open.value = false; detail.value = null; credential.value = null; }
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
function actions(row: Application): CrudTableAction[] {
  const pendingPublication = ['pending', 'partial'].includes(row.publication.status) || !row.recovery_verified;
  return [
    { label: '详情', readOnly: true, onClick: () => showDetail(row) },
    { label: '编辑', visible: allowed('update'), disabled: pendingPublication, onClick: () => edit(row) },
    { label: row.enabled ? '停用' : '启用', visible: allowed(row.enabled ? 'disable' : 'enable'), danger: row.enabled, disabled: pendingPublication, confirmTitle: row.enabled ? '停用接入应用？' : '启用接入应用？', confirmContent: row.enabled ? '新连接将被拒绝，活动连接按节点回执撤权。密钥不会因此重新签发。' : '启用后仍需有效密钥和对应设备通信授权。', onClick: () => change(row, row.enabled ? 'disable' : 'enable') },
    { label: '轮换密钥', visible: allowed('keys') && row.key.active, disabled: pendingPublication || !row.enabled, confirmTitle: '轮换应用密钥？', confirmContent: '旧密钥立即失效，活动连接将撤权。新密钥只在此次成功响应中显示。', onClick: () => change(row, 'rotate') },
    { label: '撤销密钥', visible: allowed('keys') && row.key.active, danger: true, disabled: pendingPublication, confirmTitle: '撤销应用密钥？', confirmContent: '应用不能再凭此密钥接入。恢复接入需另行签发新密钥。', onClick: () => change(row, 'revoke') },
    { label: '签发密钥', visible: allowed('keys') && !row.key.active, disabled: pendingPublication || !row.enabled, confirmTitle: '签发新的应用密钥？', confirmContent: '继续使用原应用身份，旧密钥不会恢复有效。', onClick: () => change(row, 'issue') },
  ];
}
watch([() => session.realm, () => session.token, () => session.tenant?.id], () => {
  generation++; pending?.abort(); result.value.items = []; result.value.total = 0;
  credential.value = null; detail.value = null; open.value = false; failure.value = '';
  if (!admin.value && !session.tenant) void router.replace('/tenants'); else void load();
}, { immediate: true });
onBeforeUnmount(() => { generation++; pending?.abort(); credential.value = null; });
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
  </section>
</template>
