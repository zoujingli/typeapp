<script setup lang="ts">
import type { FormInstance, TableColumnsType } from 'ant-design-vue';
import type { Page } from '../api';
import type { CrudTableAction } from '../components/crud-table-actions.vue';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Alert, Button, Card, Descriptions, DescriptionsItem, Empty, Form, FormItem, Input, InputNumber, Table, Tag, message } from 'ant-design-vue';
import AppDrawer from '../components/app-drawer/app-drawer.vue';
import CrudSearchField from '../components/crud-search-field.vue';
import CrudTableActions from '../components/crud-table-actions.vue';
import MutationButton from '../components/mutation-button.vue';
import { buildTableScrollX, estimateVisibleActionColumnWidth } from '../utils/table';
import { applyAdminContext, clearAdminAccess, errorText, isCanceled, request, session } from '../api';

/** 普通投影从不含注册码或验证值；一次性交付只在内存抽屉中短暂持有。 */
interface Authorization {
  id: string; tenant_id: string; name: string; capacity: number; registered_count: number; remaining: number;
  register_expires_at: number; enabled: boolean; recovery_verified: boolean; status: 'available' | 'expired' | 'exhausted' | 'disabled' | 'unverified'; version: number;
}
const router = useRouter();
const admin = computed(() => session.realm === 'admin');
const canRead = computed(() => session.permissions.includes(`${session.realm}.enrollments.read`));
const canIssue = computed(() => admin.value && session.permissions.includes('admin.enrollments.issue'));
const result = ref<Page<Authorization>>({ items: [], total: 0, page: 1, per_page: 20 });
const tenantFilter = ref('');
const busy = ref(false);
const failure = ref('');
const open = ref(false);
const saving = ref(false);
const saveFailure = ref('');
const formRef = ref<FormInstance>();
const form = reactive({ id: '', tenant_id: '', name: '', capacity: 1, register_expires_at: '' });
const secret = ref('');
const secretId = ref('');
const detail = ref<Authorization | null>(null);
const bindings = ref<Page<{ device_id: string; ownership_id: string; registered_at: number }>>({ items: [], total: 0, page: 1, per_page: 20 });
const detailBusy = ref(false);
const detailFailure = ref('');
let generation = 0;
let pending: AbortController | null = null;
const status = { available: '可注册', expired: '注册期限已过', exhausted: '额度已用完', disabled: '已停用', unverified: '待恢复核对' };
const columns = computed<TableColumnsType<Authorization>>(() => [
  { title: '授权名称', dataIndex: 'name', width: 220, ellipsis: true },
  ...(admin.value ? [{ title: '客户标识', dataIndex: 'tenant_id', width: 300, ellipsis: true }] : []),
  { title: '注册额度', key: 'capacity', width: 150 }, { title: '注册截止', key: 'expiry', width: 200 },
  { title: '状态', key: 'status', width: 150 }, { title: '操作', key: 'actions', width: estimateVisibleActionColumnWidth([{ label: '详情' }, { label: '重置注册码', visible: admin.value && session.permissions.includes('admin.enrollments.reset') }, { label: '停用', visible: admin.value && session.permissions.includes('admin.enrollments.disable') }]), fixed: 'right' },
]);
const pagination = computed(() => ({ current: result.value.page, pageSize: result.value.per_page, total: result.value.total, showSizeChanger: true, pageSizeOptions: ['20', '50', '100'] }));
function path() { return admin.value ? '/admin/device-authorizations' : `/customer/tenants/${session.tenant?.id}/device-authorizations`; }
function rejected(error: unknown) {
  if (clearAdminAccess(error)) { result.value.items = []; result.value.total = 0; open.value = false; detail.value = null; secret.value = ''; }
}
async function load(page = 1, perPage = result.value.per_page) {
  if (!canRead.value || (!admin.value && !session.tenant)) return;
  const current = ++generation;
  pending?.abort(); pending = new AbortController(); busy.value = true; failure.value = '';
  try {
    const data = await request<Page<Authorization> & { context: { permissions: string[]; menus: typeof session.menus } }>(path(), {
      tenant: admin.value ? undefined : session.tenant?.id, signal: pending.signal,
      params: { page, per_page: perPage, ...(admin.value ? { tenant_id: tenantFilter.value.trim() } : {}) },
    });
    if (current !== generation) return;
    result.value = data; applyAdminContext(data.context);
  } catch (error) { if (current === generation && !isCanceled(error)) { failure.value = errorText(error); rejected(error); } }
  finally { if (current === generation) busy.value = false; }
}
function issue() {
  if (!canIssue.value) return;
  Object.assign(form, { id: crypto.randomUUID().replaceAll('-', ''), tenant_id: tenantFilter.value.trim(), name: '', capacity: 1, register_expires_at: '' });
  saveFailure.value = ''; open.value = true;
}
async function save() {
  if (saving.value || !canIssue.value) return;
  try { await formRef.value?.validate(); } catch { return; }
  const expires = Math.floor(new Date(form.register_expires_at).getTime() / 1000);
  if (!Number.isFinite(expires) || expires <= Date.now() / 1000) { saveFailure.value = '请选择将来的注册截止时间。'; return; }
  saving.value = true; saveFailure.value = '';
  try {
    const data = await request<{ authorization: Authorization; code: string | null; replayed: boolean }>(path(), { method: 'POST', data: { ...form, name: form.name.trim(), tenant_id: form.tenant_id.trim(), register_expires_at: expires } });
    open.value = false; secret.value = data.code || ''; secretId.value = data.authorization.id;
    message.success(data.replayed ? '已确认原授权。注册码不重复回显，如未保存请重置。' : '注册授权已签发'); await load();
  } catch (error) { if (!isCanceled(error)) { saveFailure.value = errorText(error); rejected(error); } }
  finally { saving.value = false; }
}
async function change(row: Authorization, action: 'disable' | 'reset') {
  try {
    const data = await request<{ authorization: Authorization; code: string | null }>(`${path()}/${row.id}/${action}`, { method: 'POST', data: { tenant_id: row.tenant_id, version: row.version } });
    secret.value = data.code || ''; secretId.value = row.id;
    message.success(action === 'reset' ? '旧注册码已失效，请保存新码' : '已禁止新的设备注册'); await load(result.value.page);
  } catch (error) { if (!isCanceled(error)) { failure.value = errorText(error); rejected(error); } }
}
/** 详情每页重新查询当前授权，列表行仅用于标识目标，不能冒充实时绑定结果。 */
async function showDetail(row: Authorization, page = 1) {
  detail.value = row; detailBusy.value = true; detailFailure.value = '';
  bindings.value = { items: [], total: 0, page, per_page: 20 };
  const current = generation;
  try {
    const data = await request<{ authorization: Authorization; bindings: typeof bindings.value; context: { permissions: string[]; menus: typeof session.menus } }>(`${path()}/${row.id}`, {
      tenant: admin.value ? undefined : session.tenant?.id, params: { page, per_page: 20 },
    });
    if (current !== generation || detail.value?.id !== row.id) return;
    detail.value = data.authorization; bindings.value = data.bindings; applyAdminContext(data.context);
  } catch (error) {
    if (current === generation && !isCanceled(error)) { detailFailure.value = errorText(error); rejected(error); }
  } finally { if (current === generation) detailBusy.value = false; }
}
function actions(row: Authorization): CrudTableAction[] { return [
  { label: '详情', readOnly: true, onClick: () => showDetail(row) },
  { label: '重置注册码', visible: admin.value && session.permissions.includes('admin.enrollments.reset'), disabled: row.status !== 'available', confirmTitle: '重置设备注册码？', confirmContent: '旧注册码立即失效。已注册设备、已用额度和注册截止时间保持不变。', onClick: () => change(row, 'reset') },
  { label: '停用', danger: true, visible: admin.value && session.permissions.includes('admin.enrollments.disable'), disabled: !row.enabled, confirmTitle: '停用注册资格？', confirmContent: '停止新的注册，不能重新启用。已注册设备的撤权应在设备管理中单独执行。', onClick: () => change(row, 'disable') },
]; }
watch([() => session.realm, () => session.token, () => session.tenant?.id], () => {
  generation++; pending?.abort(); result.value.items = []; result.value.total = 0;
  secret.value = ''; secretId.value = ''; detail.value = null; open.value = false; failure.value = '';
  if (!admin.value && !session.tenant) void router.replace('/tenants'); else void load();
}, { immediate: true });
onBeforeUnmount(() => { generation++; pending?.abort(); secret.value = ''; });
</script>

<template>
  <section class="iot-page">
    <header class="page-heading"><div><h1>设备注册授权</h1><p class="muted">{{ admin ? '控制端统一签发注册资格' : `${session.tenant?.name || ''} · 已分配的注册资格` }}</p></div><MutationButton v-if="canIssue" type="primary" @click="issue">签发授权</MutationButton></header>
    <Card :bordered="false">
      <Alert class="page-alert" type="info" show-icon message="注册资格用于建立设备身份，不代表设备已在线或应用已获通信权限。" />
      <form v-if="admin" class="crud-search-grid" @submit.prevent="load()"><CrudSearchField label="客户标识"><Input v-model:value="tenantFilter" aria-label="客户标识筛选" :maxlength="32" :disabled="busy" allow-clear /></CrudSearchField><div class="crud-search-grid__actions"><Button type="primary" html-type="submit" :loading="busy">查询</Button><Button :disabled="busy" @click="tenantFilter = ''; load()">重置</Button></div></form>
      <Alert v-if="failure" :message="failure" type="error" class="page-alert" show-icon role="alert" />
      <div class="table-toolbar"><span>{{ result.total }} 项注册授权</span><Button :loading="busy" :disabled="!canRead" @click="load(result.page)">刷新</Button></div>
      <Table :columns="columns" :data-source="result.items" row-key="id" :loading="busy" :pagination="pagination" :scroll="buildTableScrollX(columns)" @change="page => load(page.current, page.pageSize)">
        <template #emptyText><Empty :description="failure ? '数据暂不可用' : '暂无注册授权'" /></template>
        <template #bodyCell="{ column, record }"><template v-if="column.key === 'capacity'">{{ record.registered_count }} / {{ record.capacity }} 已注册</template><template v-else-if="column.key === 'expiry'">{{ new Date(record.register_expires_at * 1000).toLocaleString() }}</template><Tag v-else-if="column.key === 'status'">{{ status[record.status as Authorization['status']] }}</Tag><CrudTableActions v-else-if="column.key === 'actions'" :actions="actions(record as Authorization)" /></template>
      </Table>
    </Card>
    <AppDrawer v-model:open="open" title="签发设备注册授权" width-size="sm" :confirm-loading="saving" :ok-disabled="saving || !canIssue" :closable="!saving" @ok="save">
      <Alert v-if="saveFailure" type="error" :message="saveFailure" class="page-alert" show-icon role="alert" />
      <Form ref="formRef" :model="form" layout="vertical" :disabled="saving">
        <FormItem label="客户标识" name="tenant_id" :rules="[{ required: true, pattern: /^[a-f0-9]{32}$/, message: '请输入客户工作区的完整标识' }]"><Input v-model:value="form.tenant_id" :maxlength="32" /></FormItem>
        <FormItem label="授权名称" name="name" :rules="[{ required: true, whitespace: true, message: '请输入授权名称' }]"><Input v-model:value="form.name" :maxlength="100" /></FormItem>
        <FormItem label="可注册设备数" name="capacity" :rules="[{ required: true }]"><InputNumber v-model:value="form.capacity" :min="1" :max="2147483646" :precision="0" /></FormItem>
        <FormItem label="注册截止时间（本地时区）" name="register_expires_at" :rules="[{ required: true }]"><Input v-model:value="form.register_expires_at" type="datetime-local" /></FormItem>
      </Form>
    </AppDrawer>
    <AppDrawer :open="Boolean(secret)" title="保存一次性注册码" width-size="sm" :show-footer="false" @update:open="value => { if (!value) secret = ''; }">
      <Alert type="warning" message="关闭后无法再次查看；遗失时请在控制端重置。不要将注册码写入公开日志。" class="page-alert" show-icon />
      <p>授权标识：{{ secretId }}</p><Input.TextArea :value="secret" aria-label="一次性注册码" readonly :rows="4" />
    </AppDrawer>
    <AppDrawer :open="Boolean(detail)" title="注册授权详情" width-size="sm" :show-footer="false" @update:open="value => { if (!value) detail = null; }">
      <Descriptions v-if="detail" :column="1" bordered><DescriptionsItem label="授权标识">{{ detail.id }}</DescriptionsItem><DescriptionsItem label="客户标识">{{ detail.tenant_id }}</DescriptionsItem><DescriptionsItem label="可用额度">{{ detail.remaining }}</DescriptionsItem><DescriptionsItem label="已注册数量">{{ detail.registered_count }}</DescriptionsItem><DescriptionsItem label="当前状态">{{ status[detail.status] }}</DescriptionsItem></Descriptions>
      <h2 class="mt-4 mb-2">设备注册记录</h2>
      <Alert v-if="detailFailure" type="error" :message="detailFailure" show-icon role="alert" />
      <Table :columns="[{ title: '设备标识', dataIndex: 'device_id', width: 290 }, { title: '客户使用关系', dataIndex: 'ownership_id', width: 290 }, { title: '注册时间', key: 'registered', width: 200 }]" :data-source="bindings.items" row-key="ownership_id" :loading="detailBusy" :scroll="{ x: 780 }" :pagination="{ current: bindings.page, pageSize: 20, total: bindings.total, showSizeChanger: false }" @change="page => detail && showDetail(detail, page.current)">
        <template #bodyCell="{ column, record }"><span v-if="column.key === 'registered'">{{ new Date(record.registered_at * 1000).toLocaleString() }}</span></template>
        <template #emptyText><Empty :description="detailFailure ? '绑定记录暂不可用' : '尚无设备通过本资格注册'" /></template>
      </Table>
    </AppDrawer>
  </section>
</template>
