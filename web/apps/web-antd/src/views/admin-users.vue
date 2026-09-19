<script setup lang="ts">
import type { FormInstance, TableColumnsType } from 'ant-design-vue';
import type { AdminPage, AdminRole, AdminUser, Page } from '../api';
import type { CrudTableAction } from '../components/crud-table-actions.vue';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Alert, Button, Card, Descriptions, DescriptionsItem, Empty, Form, FormItem, Input, Select, Table, Tag, message } from 'ant-design-vue';
import AppDrawer from '../components/app-drawer/app-drawer.vue';
import CrudSearchField from '../components/crud-search-field.vue';
import CrudTableActions from '../components/crud-table-actions.vue';
import { buildTableScrollX, estimateVisibleActionColumnWidth } from '../utils/table';
import { ApiError, applyAdminContext, cancelContext, clearAdminAccess, errorText, impersonate, isCanceled, request, session } from '../api';

const route = useRoute();
const router = useRouter();
const members = computed(() => route.path === '/members');
const customers = computed(() => route.path === '/admin/customers');
const resource = computed(() => members.value ? 'members' : customers.value ? 'customers' : 'users');
const realm = computed(() => members.value ? 'customer' : 'admin');
const tenant = computed(() => members.value ? session.tenant?.id : undefined);
const endpoint = computed(() => `/${realm.value}/${resource.value}`);
const title = computed(() => members.value ? '租户成员' : customers.value ? '客户账号' : '平台人员');
const rolePermission = (action: string) => session.realm === realm.value && session.permissions.includes(`${realm.value}.roles.${action}`);
const canAssign = computed(() => !customers.value && rolePermission('assign') && rolePermission('read'));
const result = ref<Page<AdminUser>>({ items: [], total: 0, page: 1, per_page: 20 });
const filters = reactive({ search: '', enabled: -1 });
const applied = reactive({ search: '', enabled: -1 });
const busy = ref(false); const failure = ref(''); const saving = ref(false); const saveFailure = ref('');
const open = ref(false); const mode = ref<'create' | 'update' | 'password'>('create');
const editing = ref<AdminUser | null>(null); const detail = ref<AdminUser | null>(null); const detailBusy = ref(false);
const form = reactive({ login: '', name: '', password: '', account_name: '', new_customer: 1 }); const formRef = ref<FormInstance>();
const selected = ref<AdminUser[]>([]); const assigning = ref<AdminUser[]>([]); const assignOpen = ref(false);
const roles = ref<Page<AdminRole>>({ items: [], total: 0, page: 1, per_page: 20 });
const roleSearch = ref(''); const roleBusy = ref(false); const roleFailure = ref(''); const chosenRoles = ref<AdminRole[]>([]);
let read: AbortController | null = null; let detailRead: AbortController | null = null; let roleRead: AbortController | null = null;
const can = (action: string) => session.realm === realm.value && session.permissions.includes(`${realm.value}.${resource.value}.${action}`);
function reject(error: unknown) {
  if (clearAdminAccess(error)) { result.value.items = []; selected.value = []; open.value = false; assignOpen.value = false; detail.value = null; form.password = ''; }
}
const columns = computed<TableColumnsType<AdminUser>>(() => [
  { title: '登录账号', dataIndex: 'login', width: 210, ellipsis: true }, { title: members.value ? '本租户姓名' : '显示姓名', dataIndex: 'name', width: 220, ellipsis: true },
  { title: members.value ? '成员状态' : '账号状态', key: 'status', width: 110 }, ...(!customers.value ? [{ title: '已分配角色', key: 'roles', width: 300 }] : []),
  { title: '操作', key: 'actions', fixed: 'right', width: estimateVisibleActionColumnWidth([{ label: '详情' }, { label: '编辑', visible: can('update') }, { label: '角色', visible: canAssign.value }, { label: '模拟登录', visible: customers.value && can('impersonate') }, { label: '重置密码', visible: can('password') }, { label: '撤销会话', visible: can('sessions') }, { label: '停用', visible: can('status') }]) },
]);
const roleColumns: TableColumnsType<AdminRole> = [{ title: '角色名称', dataIndex: 'name', width: 260, ellipsis: true }, { title: '状态', key: 'status', width: 100 }];
const pagination = computed(() => ({ current: result.value.page, pageSize: result.value.per_page, total: result.value.total, showSizeChanger: true, pageSizeOptions: ['20', '50', '100'], showTotal: (total: number) => `共 ${total} 人` }));
async function load(page = 1, perPage = result.value.per_page) {
  if (members.value && !tenant.value) return;
  read?.abort(); const pending = new AbortController(); read = pending; busy.value = true; failure.value = '';
  try {
    const data = await request<AdminPage<AdminUser>>(endpoint.value, { tenant: tenant.value, params: { ...applied, page, per_page: perPage }, signal: pending.signal });
    if (read !== pending) return;
    result.value = data; applyAdminContext(data); selected.value = [];
  } catch (error) { if (!isCanceled(error)) { failure.value = errorText(error); reject(error); } }
  finally { if (read === pending) busy.value = false; }
}
function search(reset = false) { if (busy.value) return; if (reset) Object.assign(filters, { search: '', enabled: -1 }); Object.assign(applied, filters); void load(); }
function edit(user: AdminUser | null, action: 'create' | 'update' | 'password') {
  editing.value = user; mode.value = action; Object.assign(form, { login: user?.login || '', name: user?.name || '', password: '', account_name: '', new_customer: 1 }); saveFailure.value = ''; open.value = true;
  if (members.value && action === 'create') { chosenRoles.value = []; roleSearch.value = ''; void loadRoles(); }
}
async function showDetail(user: AdminUser) {
  detailRead?.abort(); const pending = new AbortController(); detailRead = pending; detailBusy.value = true;
  try { const data = await request<AdminPage<AdminUser>>(`${endpoint.value}/${user.id}`, { tenant: tenant.value, signal: pending.signal }); if (detailRead === pending) { detail.value = data.items[0] || null; applyAdminContext(data); } }
  catch (error) { if (!isCanceled(error)) { failure.value = errorText(error); reject(error); } }
  finally { if (detailRead === pending) detailBusy.value = false; }
}
async function save() {
  if (saving.value) return; try { await formRef.value?.validate(); } catch { return; }
  if (saving.value) return;
  saving.value = true; saveFailure.value = '';
  try {
    const user = editing.value;
    const payload = members.value ? { name: form.name.trim(), ...(user ? { version: Number(user.version) }
      : { login: form.login.trim(), new_customer: Boolean(form.new_customer), roles: chosenRoles.value.map(role => ({ id: role.id, version: Number(role.version) })), ...(form.new_customer ? { account_name: form.account_name.trim(), password: form.password } : {}) }) }
      : mode.value === 'password' ? { version: Number(user?.version), password: form.password }
      : { login: form.login.trim(), name: form.name.trim(), ...(user ? { version: Number(user.version) } : { password: form.password }) };
    await request(`${endpoint.value}${user ? `/${user.id}${mode.value === 'password' ? '/password' : ''}` : ''}`, { tenant: tenant.value, method: mode.value === 'update' ? 'PATCH' : 'POST', data: payload });
    form.password = ''; open.value = false; message.success(mode.value === 'password' ? '密码已重置，旧会话已撤销' : '人员资料已保存'); await load(result.value.page);
  } catch (error) { if (!isCanceled(error)) { saveFailure.value = errorText(error); reject(error); if (error instanceof ApiError && error.status === 409) await load(result.value.page); } }
  finally { saving.value = false; }
}
async function action(user: AdminUser, kind: 'status' | 'sessions' | 'delete') {
  try {
    await request(`${endpoint.value}/${user.id}${kind === 'delete' ? '' : `/${kind}`}`, { tenant: tenant.value, method: kind === 'status' ? 'POST' : 'DELETE', data: { version: Number(user.version), ...(kind === 'status' ? { enabled: !Number(user.enabled) } : {}) } });
    message.success(kind === 'delete' ? '已移除本租户成员关系' : kind === 'sessions' ? '此人员旧会话已撤销' : '状态已更新'); await load(result.value.page);
  } catch (error) { if (!isCanceled(error)) { failure.value = errorText(error); reject(error); } }
}
async function enterCustomer(user: AdminUser) {
  try { await impersonate(user); await router.push('/tenants'); }
  catch (error) { if (!isCanceled(error)) { failure.value = errorText(error); reject(error); } }
}
async function loadRoles(page = 1) {
  if (saving.value) return;
  roleRead?.abort(); const pending = new AbortController(); roleRead = pending; roleBusy.value = true; roleFailure.value = '';
  try { const data = await request<AdminPage<AdminRole>>(`/${realm.value}/roles`, { tenant: tenant.value, params: { search: roleSearch.value.trim(), page, per_page: 20 }, signal: pending.signal }); if (roleRead === pending) { roles.value = data; applyAdminContext(data); } }
  catch (error) { if (!isCanceled(error)) { roleFailure.value = errorText(error); reject(error); } }
  finally { if (roleRead === pending) roleBusy.value = false; }
}
function assign(users: AdminUser[]) {
  if (!users.length || users.length > 100) return;
  assigning.value = users; chosenRoles.value = users.length === 1 ? [...(users[0]!.roles || [])] : []; roleSearch.value = ''; saveFailure.value = ''; assignOpen.value = true; void loadRoles();
}
async function saveRoles() {
  if (saving.value || roleBusy.value) return; saving.value = true; saveFailure.value = '';
  try {
    await request(`${endpoint.value}/roles`, { tenant: tenant.value, method: 'PUT', data: { [members.value ? 'members' : 'users']: assigning.value.map(user => ({ id: user.id, version: Number(user.version) })), roles: chosenRoles.value.map(role => ({ id: role.id, version: Number(role.version) })) } });
    assignOpen.value = false; message.success('角色已分配，后续请求使用当前权限'); await load(result.value.page);
  } catch (error) { if (!isCanceled(error)) { saveFailure.value = errorText(error); reject(error); } }
  finally { saving.value = false; }
}
function actions(user: AdminUser): CrudTableAction[] { return [
  { label: '详情', disabled: detailBusy.value, onClick: () => showDetail(user) },
  { label: '编辑', visible: can('update'), onClick: () => edit(user, 'update') },
  { label: '角色', visible: canAssign.value, onClick: () => assign([user]) },
  { label: '模拟登录', visible: customers.value && can('impersonate'), disabled: !Number(user.enabled) || !Number(user.recovery_verified), confirmTitle: `模拟登录“${user.login}”？`, confirmContent: '使用客户当前租户权限，全部操作保留你的真实管理身份。', onClick: () => enterCustomer(user) },
  { label: '重置密码', visible: can('password'), danger: true, onClick: () => edit(user, 'password') },
  { label: '撤销会话', visible: can('sessions'), danger: true, confirmTitle: `撤销“${user.login}”的全部会话？`, confirmContent: '此人员需要重新登录。操作不能管理超出你权限的账号。', onClick: () => action(user, 'sessions') },
  { label: Number(user.enabled) ? '停用' : '启用', visible: can('status'), danger: Boolean(Number(user.enabled)), confirmTitle: `${Number(user.enabled) ? '停用' : '启用'}“${user.login}”？`, confirmContent: members.value ? '仅改变当前租户资格，其他租户和全局登录保持；最后一位最高管理员不能停用。' : '停用会撤销全部会话，最后一位最高管理员不能停用。', onClick: () => action(user, 'status') },
  { label: '移除', visible: members.value && can('delete'), danger: true, confirmTitle: `移除“${user.login}”的本租户成员关系？`, confirmContent: '只删除本租户关系及角色，保留全局账号及其他租户；最后一位最高管理员不能移除。', onClick: () => action(user, 'delete') },
]; }
watch(open, value => { if (!value) form.password = ''; });
watch(() => form.new_customer, () => { form.password = ''; form.account_name = ''; });
watch([resource, tenant], () => {
  read?.abort(); detailRead?.abort(); roleRead?.abort(); cancelContext();
  selected.value = []; detail.value = null; open.value = false; assignOpen.value = false; form.password = '';
  Object.assign(filters, { search: '', enabled: -1 }); Object.assign(applied, filters);
  chosenRoles.value = []; roles.value.items = [];
  result.value = { items: [], total: 0, page: 1, per_page: 20 };
  if (members.value && !tenant.value) void router.replace('/tenants'); else void load();
}, { immediate: true });
onBeforeUnmount(() => { read?.abort(); detailRead?.abort(); roleRead?.abort(); form.password = ''; cancelContext(); });
</script>

<template>
  <section class="iot-page">
    <header class="page-heading"><div><h1>{{ title }}</h1><p class="muted">{{ members ? '管理当前租户的姓名、成员状态和角色，客户全局资料由账号本人或平台维护' : customers ? '管理跨租户共享的客户资料、全局状态、密码和会话' : '管理平台账号、独立敏感动作及多角色授权' }}</p></div><div class="toolbar-actions"><Button v-if="canAssign" :disabled="!selected.length || busy" @click="assign(selected)">批量分配角色</Button><Button v-if="can('create') && (!members || canAssign)" type="primary" @click="edit(null, 'create')">{{ members ? '新增成员' : customers ? '新增客户' : '新增人员' }}</Button></div></header>
    <Card :bordered="false">
      <form class="crud-search-grid" @submit.prevent="search()"><CrudSearchField label="登录账号"><Input v-model:value="filters.search" aria-label="登录账号筛选" :disabled="busy" allow-clear :maxlength="100" /></CrudSearchField><CrudSearchField label="账号状态"><Select v-model:value="filters.enabled" :disabled="busy" :options="[{ label: '全部状态', value: -1 }, { label: '启用', value: 1 }, { label: '停用', value: 0 }]" /></CrudSearchField><div class="crud-search-grid__actions"><Button type="primary" html-type="submit" :disabled="busy" :loading="busy">查询</Button><Button :disabled="busy" @click="search(true)">重置</Button></div></form>
      <Alert v-if="failure" :message="failure" type="error" show-icon role="alert" class="page-alert" />
      <div class="table-toolbar"><span class="muted">{{ result.total }} 位{{ title }}</span><Button :disabled="busy" :loading="busy" @click="load(result.page)">刷新</Button></div>
      <Table :columns="columns" :data-source="result.items" row-key="id" :loading="busy" :scroll="buildTableScrollX(columns)" :pagination="pagination" :row-selection="canAssign ? { selectedRowKeys: selected.map(user => user.id), onChange: (_keys: unknown, users: AdminUser[]) => selected = users } : undefined" @change="page => load(page.current, page.pageSize)">
        <template #emptyText><Empty :description="failure ? '数据暂不可用，请重试' : '暂无符合条件的人员'" /></template>
        <template #bodyCell="{ column, record }"><Tag v-if="column.key === 'status'" :color="Number(record.enabled) ? 'success' : undefined">{{ Number(record.enabled) ? '启用' : '停用' }}</Tag><template v-else-if="column.key === 'roles'"><Tag v-for="role in record.roles" :key="role.id">{{ role.name }}{{ Number(role.enabled) ? '' : '（停用）' }}</Tag><span v-if="!record.roles.length" class="muted">无角色</span></template><CrudTableActions v-else-if="column.key === 'actions'" :actions="actions(record as AdminUser)" /></template>
      </Table>
    </Card>
    <AppDrawer v-model:open="open" :title="mode === 'create' ? `新增${title}` : mode === 'password' ? `重置${title}密码` : `编辑${title}`" width-size="sm" :confirm-loading="saving" :closable="!saving" :ok-disabled="saving || (members && mode === 'create' && (roleBusy || chosenRoles.length > 64))" :ok-danger="mode === 'password'" @ok="save">
      <Alert v-if="saveFailure" :message="saveFailure" type="error" show-icon role="alert" class="page-alert" />
      <Alert v-if="mode === 'password'" :message="`重置 ${editing?.login} 的密码，并撤销全部旧会话。`" type="warning" show-icon class="page-alert" />
      <Form ref="formRef" :model="form" layout="vertical" class="crud-form-grid">
        <FormItem v-if="members && mode === 'create'" label="账号来源"><Select v-model:value="form.new_customer" aria-label="账号来源" :disabled="saving" :options="[{ label: '创建新客户账号', value: 1 }, { label: '关联已有客户账号', value: 0 }]" /></FormItem>
        <FormItem v-if="mode !== 'password' && !(members && mode === 'update')" label="登录账号" name="login" :rules="[{ required: true, pattern: /^[a-z0-9][a-z0-9_.@-]{2,99}$/, message: '请输入3至100位小写账号' }]"><Input v-model:value="form.login" :disabled="saving" :maxlength="100" autocomplete="off" /></FormItem>
        <FormItem v-if="mode !== 'password'" :label="members ? '本租户姓名' : '显示姓名'" name="name" :rules="[{ required: true, whitespace: true, message: '请输入姓名' }]"><Input v-model:value="form.name" :disabled="saving" :maxlength="100" /></FormItem>
        <FormItem v-if="members && mode === 'create' && form.new_customer" label="客户全局姓名" name="account_name" :rules="[{ required: true, whitespace: true, message: '请输入初始客户姓名' }]"><Input v-model:value="form.account_name" :disabled="saving" :maxlength="100" /></FormItem>
        <FormItem v-if="mode !== 'update' && (!members || form.new_customer)" label="登录密码" name="password" :rules="[{ required: true, min: 12, max: 72, message: '请输入12至72字节密码' }]"><Input.Password v-model:value="form.password" :disabled="saving" autocomplete="new-password" :maxlength="72" /></FormItem>
      </Form>
      <template v-if="members && mode === 'create'">
        <p class="muted">账号、成员与所选角色同时保存；未选择角色的成员可以登录及选择租户，尚无业务权限。</p>
        <Alert v-if="roleFailure" :message="roleFailure" type="error" show-icon class="page-alert" />
        <form class="crud-search-grid" @submit.prevent="loadRoles()"><CrudSearchField label="角色名称"><Input v-model:value="roleSearch" :disabled="roleBusy || saving" :maxlength="100" /></CrudSearchField><div class="crud-search-grid__actions"><Button html-type="submit" :disabled="roleBusy || saving">查询角色</Button></div></form>
        <p>已选择 {{ chosenRoles.length }} 个角色</p>
        <Table :columns="roleColumns" :data-source="roles.items" row-key="id" :loading="roleBusy" :row-selection="{ selectedRowKeys: chosenRoles.map(role => role.id), preserveSelectedRowKeys: true, getCheckboxProps: () => ({ disabled: saving }), onChange: (_keys: unknown, values: AdminRole[]) => chosenRoles = values }" :pagination="{ current: roles.page, pageSize: 20, total: roles.total, showSizeChanger: false }" @change="page => loadRoles(page.current)"><template #bodyCell="{ column, record }"><Tag v-if="column.key === 'status'">{{ Number(record.enabled) ? '启用' : '停用' }}</Tag></template></Table>
      </template>
      <p v-else-if="mode === 'create'" class="muted">{{ customers ? '新客户没有租户成员关系，加入租户后按该租户的角色访问业务。' : '新人员没有角色，创建后请分配所需角色。' }}</p>
    </AppDrawer>
    <AppDrawer v-model:open="assignOpen" :title="members ? '分配租户角色' : '分配平台角色'" width-size="sm" :confirm-loading="saving" :closable="!saving" :ok-disabled="saving || roleBusy || chosenRoles.length > 64" @ok="saveRoles">
      <Alert :message="`将替换 ${assigning.length} 位人员的全部角色；未选择角色表示移除全部绑定。`" type="warning" show-icon class="page-alert" />
      <p class="muted">{{ assigning.map(user => user.login).join('、') }}</p>
      <Alert v-if="saveFailure || roleFailure" :message="saveFailure || roleFailure" type="error" show-icon role="alert" class="page-alert" />
      <form class="crud-search-grid" @submit.prevent="loadRoles()"><CrudSearchField label="角色名称"><Input v-model:value="roleSearch" :disabled="roleBusy || saving" :maxlength="100" /></CrudSearchField><div class="crud-search-grid__actions"><Button html-type="submit" :disabled="roleBusy || saving" :loading="roleBusy">查询角色</Button></div></form>
      <p>已选择 {{ chosenRoles.length }} 个角色</p>
      <Table :columns="roleColumns" :data-source="roles.items" row-key="id" :loading="roleBusy" :row-selection="{ selectedRowKeys: chosenRoles.map(role => role.id), preserveSelectedRowKeys: true, getCheckboxProps: () => ({ disabled: saving }), onChange: (_keys: unknown, values: AdminRole[]) => chosenRoles = values }" :pagination="{ current: roles.page, pageSize: 20, total: roles.total, showSizeChanger: false }" @change="page => loadRoles(page.current)"><template #bodyCell="{ column, record }"><Tag v-if="column.key === 'status'">{{ Number(record.enabled) ? '启用' : '停用' }}</Tag></template></Table>
    </AppDrawer>
    <AppDrawer :open="Boolean(detail)" :title="`${title}详情`" width-size="sm" :ok-visible="false" :mask-closable="true" @update:open="value => { if (!value) detail = null; }">
      <Descriptions v-if="detail" :column="1" bordered><DescriptionsItem label="登录账号">{{ detail.login }}</DescriptionsItem><DescriptionsItem :label="members ? '本租户姓名' : '显示姓名'">{{ detail.name }}</DescriptionsItem><DescriptionsItem label="账号标识">{{ detail.user_id || detail.id }}</DescriptionsItem><DescriptionsItem v-if="members" label="成员标识">{{ detail.id }}</DescriptionsItem><DescriptionsItem label="状态">{{ Number(detail.enabled) ? '启用' : '停用' }}</DescriptionsItem><DescriptionsItem label="恢复核对">{{ Number(detail.recovery_verified) ? '已核对' : '待核对' }}</DescriptionsItem><DescriptionsItem v-if="!customers" label="角色">{{ (detail.roles || []).map(role => `${role.name}${Number(role.enabled) ? '' : '（停用）'}`).join('、') || '无角色' }}</DescriptionsItem><DescriptionsItem label="版本">{{ detail.version }}</DescriptionsItem></Descriptions>
    </AppDrawer>
  </section>
</template>
