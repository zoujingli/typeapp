<script setup lang="ts">
import type { FormInstance, TableColumnsType } from 'ant-design-vue';
import type { AdminPage, Page, TenantAdministrator, WorkspaceTenant } from '../api';
import type { CrudTableAction } from '../components/crud-table-actions.vue';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Alert, Button, Card, Descriptions, DescriptionsItem, Empty, Form, FormItem, Input, Select, Table, Tag, message } from 'ant-design-vue';
import AppDrawer from '../components/app-drawer/app-drawer.vue';
import CrudSearchField from '../components/crud-search-field.vue';
import CrudTableActions from '../components/crud-table-actions.vue';
import { buildTableScrollX, estimateVisibleActionColumnWidth } from '../utils/table';
import { ApiError, applyAdminContext, canAdmin, cancelContext, clearAdminAccess, errorText, isCanceled, loadUser, request, selectTenant, session } from '../api';

const route = useRoute(); const router = useRouter();
const platform = computed(() => route.path === '/admin/tenants');
const result = ref<Page<WorkspaceTenant>>({ items: [], total: 0, page: 1, per_page: 20 });
const filters = reactive({ search: '', enabled: -1 }); const applied = reactive({ search: '', enabled: -1 });
const busy = ref(false); const failure = ref(''); const saving = ref(false); const saveFailure = ref(''); const entering = ref('');
const open = ref(false); const editing = ref<WorkspaceTenant | null>(null); const detail = ref<WorkspaceTenant | null>(null); const detailBusy = ref(false);
const form = reactive({ id: '', name: '', new_customer: true, owner_login: '', owner_name: '', owner_password: '' }); const formRef = ref<FormInstance>();
const administratorOpen = ref(false); const administratorMode = ref<'add' | 'replace'>('add'); const administratorSaving = ref(false); const administratorFailure = ref('');
const administratorForm = reactive({ login: '', new_customer: false, owner_name: '', owner_password: '', replace_member_id: '', replace_member_version: 0 }); const administratorFormRef = ref<FormInstance>();
let read: AbortController | null = null; let detailRead: AbortController | null = null;
const can = (action: string) => platform.value && canAdmin(`tenants.${action}`);
const canAdministratorManage = computed(() => can('administrators.manage'));
const columns = computed<TableColumnsType<WorkspaceTenant>>(() => [
  { title: '租户名称', dataIndex: 'name', width: 320, ellipsis: true },
  { title: '租户标识', dataIndex: 'id', width: 300, ellipsis: true },
  ...(platform.value ? [{ title: '租户状态', key: 'status', width: 110 }] : []),
  { title: '操作', key: 'actions', fixed: 'right', width: platform.value ? estimateVisibleActionColumnWidth([{ label: '详情' }, { label: '编辑', visible: can('update') }, { label: '停用', visible: can('status') }]) : estimateVisibleActionColumnWidth(['进入租户']) },
]);
const administratorColumns: TableColumnsType<TenantAdministrator> = [
  { title: '登录账号', dataIndex: 'login', width: 180, ellipsis: true },
  { title: '姓名', dataIndex: 'name', width: 140, ellipsis: true },
  { title: '操作', key: 'actions', fixed: 'right', width: 150 },
];
const pagination = computed(() => ({ current: result.value.page, pageSize: result.value.per_page, total: result.value.total, showSizeChanger: true, pageSizeOptions: ['20', '50', '100'], showTotal: (total: number) => `共 ${total} 个租户` }));
function reject(error: unknown) {
  if (platform.value && clearAdminAccess(error)) { result.value.items = []; detail.value = null; open.value = false; form.owner_password = ''; }
}
async function load(page = 1, perPage = result.value.per_page) {
  read?.abort(); const pending = new AbortController(); read = pending; busy.value = true; failure.value = '';
  try {
    const data = await request<AdminPage<WorkspaceTenant>>(`/${platform.value ? 'admin' : 'customer'}/tenants`, { signal: pending.signal, params: { page, per_page: perPage, search: applied.search, ...(platform.value ? { enabled: applied.enabled } : {}) } });
    if (read !== pending) return;
    result.value = data; if (platform.value) applyAdminContext(data);
  } catch (error) { if (!isCanceled(error)) { failure.value = errorText(error); reject(error); } }
  finally { if (read === pending) busy.value = false; }
}
function search(reset = false) { if (busy.value) return; if (reset) Object.assign(filters, { search: '', enabled: -1 }); Object.assign(applied, filters); void load(); }
function edit(tenant: WorkspaceTenant | null) {
  editing.value = tenant; Object.assign(form, { id: tenant?.id || crypto.randomUUID().replaceAll('-', ''), name: tenant?.name || '', new_customer: true, owner_login: '', owner_name: '', owner_password: '' });
  saveFailure.value = ''; open.value = true;
}
function manageAdministrator(mode: 'add' | 'replace', owner: TenantAdministrator | null = null) {
  administratorMode.value = mode; administratorFailure.value = '';
  Object.assign(administratorForm, { login: '', new_customer: false, owner_name: '', owner_password: '', replace_member_id: owner?.member_id || '', replace_member_version: owner?.member_version || 0 });
  administratorOpen.value = true;
}
async function save() {
  if (saving.value) return; try { await formRef.value?.validate(); } catch { return; }
  if (saving.value) return;
  saving.value = true; saveFailure.value = '';
  try {
    const tenant = editing.value;
    await request(`/admin/tenants${tenant ? `/${tenant.id}` : ''}`, { method: tenant ? 'PATCH' : 'POST', data: tenant
      ? { name: form.name.trim(), version: Number(tenant.version) }
      : { id: form.id, name: form.name.trim(), new_customer: form.new_customer, owner_login: form.owner_login.trim(), ...(form.new_customer ? { owner_name: form.owner_name.trim(), owner_password: form.owner_password } : {}) } });
    form.owner_password = ''; open.value = false; message.success(tenant ? '租户资料已保存' : '租户及初始客户工作区已创建'); await load(tenant ? result.value.page : 1);
  } catch (error) { if (!isCanceled(error)) { saveFailure.value = errorText(error); reject(error); if (error instanceof ApiError && error.status === 409) await load(result.value.page); } }
  finally { saving.value = false; }
}
async function showDetail(tenant: WorkspaceTenant) {
  detailRead?.abort(); const pending = new AbortController(); detailRead = pending; detailBusy.value = true;
  try { const data = await request<AdminPage<WorkspaceTenant>>(`/admin/tenants/${tenant.id}`, { signal: pending.signal }); if (detailRead === pending) { detail.value = data.items[0] || null; applyAdminContext(data); } }
  catch (error) { if (!isCanceled(error)) { failure.value = errorText(error); reject(error); } }
  finally { if (detailRead === pending) detailBusy.value = false; }
}
async function saveAdministrator() {
  if (!detail.value || administratorSaving.value) return;
  try { await administratorFormRef.value?.validate(); } catch { return; }
  administratorSaving.value = true; administratorFailure.value = '';
  try {
    const current = detail.value;
    await request(`/admin/tenants/${current.id}/administrators`, { method: administratorMode.value === 'add' ? 'POST' : 'PUT', data: {
      version: Number(current.version), login: administratorForm.login.trim(), new_customer: administratorForm.new_customer,
      ...(administratorForm.new_customer ? { owner_name: administratorForm.owner_name.trim(), owner_password: administratorForm.owner_password } : {}),
      ...(administratorMode.value === 'replace' ? { replace_member_id: administratorForm.replace_member_id, replace_member_version: administratorForm.replace_member_version } : {}),
    } });
    administratorForm.owner_password = ''; administratorOpen.value = false; message.success(administratorMode.value === 'add' ? '最高管理员已新增' : '最高管理员已更换');
    await showDetail(current); await load(result.value.page);
  } catch (error) { if (!isCanceled(error)) { administratorFailure.value = errorText(error); reject(error); if (error instanceof ApiError && error.status === 409) { await load(result.value.page); if (detail.value) await showDetail(detail.value); } } }
  finally { administratorSaving.value = false; }
}
async function removeAdministrator(owner: TenantAdministrator) {
  if (!detail.value || administratorSaving.value) return;
  administratorSaving.value = true; administratorFailure.value = '';
  try {
    const current = detail.value;
    await request(`/admin/tenants/${current.id}/administrators/${owner.member_id}`, { method: 'DELETE', data: { version: Number(current.version), member_version: Number(owner.member_version) } });
    message.success('最高管理员已移除'); await showDetail(current); await load(result.value.page);
  } catch (error) { if (!isCanceled(error)) { administratorFailure.value = errorText(error); failure.value = administratorFailure.value; reject(error); if (error instanceof ApiError && error.status === 409) { await load(result.value.page); if (detail.value) await showDetail(detail.value); } } }
  finally { administratorSaving.value = false; }
}
async function status(tenant: WorkspaceTenant) {
  try {
    await request(`/admin/tenants/${tenant.id}/status`, { method: 'POST', data: { version: Number(tenant.version), enabled: !Number(tenant.enabled) } });
    message.success('租户状态已更新'); await load(result.value.page);
  } catch (error) { if (!isCanceled(error)) { failure.value = errorText(error); reject(error); } }
}
async function enter(tenant: WorkspaceTenant) {
  if (entering.value) return; entering.value = tenant.id; failure.value = ''; session.notice = '';
  selectTenant({ ...tenant, role: null });
  try { await loadUser(tenant.id); await router.push('/profile'); }
  catch (error) { if (!isCanceled(error)) { selectTenant(null); await load(); failure.value = errorText(error); } }
  finally { entering.value = ''; }
}
function actions(tenant: WorkspaceTenant): CrudTableAction[] {
  if (!platform.value) return [{ label: '进入租户', disabled: Boolean(entering.value), onClick: () => enter(tenant) }];
  return [
    { label: '详情', disabled: detailBusy.value, onClick: () => showDetail(tenant) },
    { label: '编辑', visible: can('update'), onClick: () => edit(tenant) },
    { label: Number(tenant.enabled) ? '停用' : '启用', visible: can('status'), danger: Boolean(Number(tenant.enabled)), confirmTitle: `${Number(tenant.enabled) ? '停用' : '启用'}“${tenant.name}”？`, confirmContent: '停用只取消本租户业务访问，客户全局账号和其他租户不受影响。启用前必须保留有效最高管理员。', onClick: () => status(tenant) },
  ];
}
function administratorActions(owner: TenantAdministrator): CrudTableAction[] {
  return [
    { label: '更换', visible: canAdministratorManage.value, disabled: administratorSaving.value, onClick: () => manageAdministrator('replace', owner) },
    { label: '移除', visible: canAdministratorManage.value, disabled: administratorSaving.value, danger: true, confirmTitle: `移除“${owner.login}”的最高管理员角色？`, confirmContent: '仅解绑当前租户的最高管理员角色，成员关系、其他角色、全局账号及其他租户保持不变；最后一位有效管理员不能移除。', onClick: () => removeAdministrator(owner) },
  ];
}
watch(open, value => { if (!value) form.owner_password = ''; });
watch(administratorOpen, value => { if (!value) { administratorForm.owner_password = ''; administratorFailure.value = ''; } });
watch(() => form.new_customer, () => { form.owner_password = ''; form.owner_name = ''; formRef.value?.clearValidate(); });
watch(platform, () => { Object.assign(filters, { search: '', enabled: -1 }); Object.assign(applied, filters); result.value.items = []; detail.value = null; open.value = false; administratorOpen.value = false; void load(); }, { immediate: true });
onBeforeUnmount(() => { read?.abort(); detailRead?.abort(); cancelContext(); form.owner_password = ''; });
</script>

<template>
  <section class="iot-page">
    <header class="page-heading">
      <div><h1>{{ platform ? '平台租户' : '我的租户' }}</h1><p class="muted">{{ platform ? '管理组织，创建或关联初始客户管理员。' : '选择当前工作区，各租户权限与业务数据独立。' }}</p></div>
      <Button v-if="can('create')" type="primary" @click="edit(null)">创建租户</Button>
    </header>
    <Card :bordered="false">
      <form class="crud-search-grid" @submit.prevent="search()">
        <CrudSearchField label="租户名称"><Input v-model:value="filters.search" aria-label="租户名称筛选" :maxlength="100" allow-clear :disabled="busy" /></CrudSearchField>
        <CrudSearchField v-if="platform" label="租户状态"><Select v-model:value="filters.enabled" aria-label="租户状态筛选" :disabled="busy" :options="[{ label: '全部状态', value: -1 }, { label: '启用', value: 1 }, { label: '停用', value: 0 }]" /></CrudSearchField>
        <div class="crud-search-grid__actions"><Button type="primary" html-type="submit" :loading="busy">查询</Button><Button :disabled="busy" @click="search(true)">重置</Button></div>
      </form>
      <Alert v-if="failure || session.notice" :message="failure || session.notice" type="error" show-icon class="page-alert" role="alert" />
      <div class="table-toolbar"><span class="muted">{{ result.total }} 个{{ platform ? '平台租户' : '有效工作区' }}</span><Button :loading="busy" @click="load(result.page)">刷新</Button></div>
      <Table :columns="columns" :data-source="result.items" row-key="id" :loading="busy" :scroll="buildTableScrollX(columns)" :pagination="pagination" @change="page => load(page.current, page.pageSize)">
        <template #emptyText><Empty :description="failure ? '数据暂不可用，请重试' : '暂无符合条件的租户'" /></template>
        <template #bodyCell="{ column, record }">
          <Tag v-if="column.key === 'status'" :color="Number(record.enabled) ? 'success' : 'default'">{{ Number(record.enabled) ? '启用' : '停用' }}</Tag>
          <CrudTableActions v-else-if="column.key === 'actions'" :actions="actions(record as WorkspaceTenant)" />
        </template>
      </Table>
    </Card>
    <AppDrawer :open="Boolean(detail)" title="租户详情" width-size="lg" :ok-visible="false" mask-closable @update:open="value => { if (!value) detail = null; }">
      <Descriptions v-if="detail" :column="1" bordered>
        <DescriptionsItem label="租户名称"><span class="break-all">{{ detail.name }}</span></DescriptionsItem>
        <DescriptionsItem label="租户标识"><span class="break-all">{{ detail.id }}</span></DescriptionsItem>
        <DescriptionsItem label="状态">{{ Number(detail.enabled) ? '启用' : '停用' }}</DescriptionsItem>
        <DescriptionsItem label="版本">{{ detail.version }}</DescriptionsItem>
        <DescriptionsItem label="最高管理员">
          <div class="table-toolbar"><span class="muted">{{ detail.administrators?.length || 0 }} 位有效管理员</span><Button v-if="canAdministratorManage" type="primary" :disabled="administratorSaving" @click="manageAdministrator('add')">新增最高管理员</Button></div>
          <Table :columns="administratorColumns" :data-source="detail.administrators || []" row-key="member_id" size="small" :pagination="false" :scroll="{ x: 470 }">
            <template #emptyText><Empty description="暂无有效最高管理员" /></template>
            <template #bodyCell="{ column, record }"><CrudTableActions v-if="column.key === 'actions'" :actions="administratorActions(record as TenantAdministrator)" /></template>
          </Table>
        </DescriptionsItem>
      </Descriptions>
    </AppDrawer>
    <AppDrawer v-model:open="administratorOpen" :title="administratorMode === 'add' ? '新增最高管理员' : '更换最高管理员'" width-size="sm" :confirm-loading="administratorSaving" :closable="!administratorSaving" @ok="saveAdministrator">
      <Alert v-if="administratorFailure" :message="administratorFailure" type="error" show-icon class="page-alert" role="alert" />
      <Form ref="administratorFormRef" :model="administratorForm" layout="vertical" class="form-grid" :disabled="administratorSaving">
        <FormItem label="客户账号方式"><Select :value="administratorForm.new_customer ? 'new' : 'existing'" aria-label="客户账号方式" :options="[{ label: '关联已有客户账号', value: 'existing' }, { label: '创建新客户账号', value: 'new' }]" @change="value => { administratorForm.new_customer = value === 'new'; }" /></FormItem>
        <FormItem label="客户登录账号" name="login" :rules="[{ required: true, pattern: /^[a-z0-9][a-z0-9_.@-]{2,99}$/, message: '请输入3至100位小写登录标识' }]"><Input v-model:value="administratorForm.login" :maxlength="100" autocomplete="off" /></FormItem>
        <template v-if="administratorForm.new_customer">
          <FormItem label="客户显示姓名" name="owner_name" :rules="[{ required: true, whitespace: true, message: '请输入客户姓名' }]"><Input v-model:value="administratorForm.owner_name" :maxlength="100" /></FormItem>
          <FormItem label="初始登录密码" name="owner_password" :rules="[{ required: true, min: 12, max: 72, message: '请输入12至72字节密码' }]"><Input.Password v-model:value="administratorForm.owner_password" :maxlength="72" autocomplete="new-password" /></FormItem>
        </template>
        <p v-if="administratorMode === 'replace'" class="muted">提交后将在同一事务中授予新账号最高管理员角色并解绑当前管理员，旧成员及其其他角色会保留。</p>
        <p v-else class="muted">关联已有账号不会修改其全局资料或密码；创建新账号后请安全交付登录凭据。</p>
      </Form>
    </AppDrawer>
    <AppDrawer v-model:open="open" :title="editing ? '编辑租户' : '创建租户'" width-size="sm" :confirm-loading="saving" @ok="save">
      <Alert v-if="saveFailure" :message="saveFailure" type="error" show-icon class="page-alert" role="alert" />
      <Form ref="formRef" :model="form" layout="vertical" class="form-grid" :disabled="saving">
        <FormItem label="租户名称" name="name" :rules="[{ required: true, whitespace: true, message: '请输入租户名称' }]"><Input v-model:value="form.name" :maxlength="100" /></FormItem>
        <template v-if="!editing">
          <FormItem label="客户账号方式"><Select :value="form.new_customer ? 'new' : 'existing'" aria-label="客户账号方式" :options="[{ label: '创建新客户账号', value: 'new' }, { label: '关联已有客户账号', value: 'existing' }]" @change="value => { form.new_customer = value === 'new'; }" /></FormItem>
          <FormItem label="客户登录账号" name="owner_login" :rules="[{ required: true, pattern: /^[a-z0-9][a-z0-9_.@-]{2,99}$/, message: '请输入3至100位小写登录标识' }]"><Input v-model:value="form.owner_login" :maxlength="100" autocomplete="off" /></FormItem>
          <template v-if="form.new_customer">
            <FormItem label="客户显示姓名" name="owner_name" :rules="[{ required: true, whitespace: true, message: '请输入客户姓名' }]"><Input v-model:value="form.owner_name" :maxlength="100" /></FormItem>
            <FormItem label="初始登录密码" name="owner_password" :rules="[{ required: true, min: 12, max: 72, message: '请输入12至72字节密码' }]"><Input.Password v-model:value="form.owner_password" :maxlength="72" autocomplete="new-password" /></FormItem>
          </template>
          <p class="muted span-full">客户取得此租户的最高管理员角色。关联已有账号不会修改其资料或密码。请安全交付新账号凭据。</p>
        </template>
      </Form>
    </AppDrawer>
  </section>
</template>
