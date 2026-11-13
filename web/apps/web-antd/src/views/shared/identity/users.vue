<script setup lang="ts">
import type { AccessPage, RoleRecord, AccountRecord } from '../../../api/contracts';
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { Alert, Button, Dropdown, Menu, MenuItem, Modal, Select, Space, Tag, message } from 'ant-design-vue';
import { Page, useVbenModal } from '@vben/common-ui';
import { useVbenForm, z } from '../../../adapter/form';
import { useVbenVxeGrid } from '../../../adapter/vxe-table';
import { errorText, isCanceled, isDemo, loadUser, request, session } from '../../../api/identity';

const base = computed(() => `/${session.realm}`);
const can = (action: string) => session.permissions.includes(`${session.realm}.${action}`);
const editing = ref<AccountRecord | null>(null); const mode = ref<'create' | 'update' | 'password'>('create');
const failure = ref(''); const saveFailure = ref('');
let pending: AbortController | undefined;
const [Form, form] = useVbenForm(reactive({ showDefaultActions: false, layout: 'vertical', commonConfig: { componentProps: { class: 'w-full' } }, schema: computed(() => [
  ...(mode.value !== 'password' ? [
    { fieldName: 'login', label: '账号', component: 'Input' as const, rules: z.string().regex(/^[a-z0-9][a-z0-9_.@-]{2,99}$/, '请输入3至100位小写账号') },
    { fieldName: 'name', label: '姓名', component: 'Input' as const, rules: z.string().trim().min(1, '请输入姓名').max(100) },
  ] : []),
  ...(mode.value !== 'update' ? [{ fieldName: 'password', label: '密码', component: 'InputPassword' as const, componentProps: { autocomplete: 'new-password', maxlength: 72 }, rules: z.string().min(5, '请输入至少5位密码').max(72) }] : []),
]) }));
const [Editor, editor] = useVbenModal({ onConfirm: save, onClosed: () => form.resetForm() });
const [Grid, grid] = useVbenVxeGrid<AccountRecord>({
  formOptions: { schema: [
    { fieldName: 'search', label: '姓名', component: 'Input', componentProps: { placeholder: '请输入姓名' } },
    { fieldName: 'enabled', label: '状态', component: 'Select', defaultValue: -1, componentProps: { options: [{ label: '全部', value: -1 }, { label: '启用', value: 1 }, { label: '停用', value: 0 }] } },
  ] },
  gridOptions: { columns: [
    { type: 'checkbox', width: 50 }, { field: 'login', title: '账号', minWidth: 180 }, { field: 'name', title: '姓名', minWidth: 160 },
    { field: 'enabled', title: '状态', width: 90, slots: { default: 'status' } },
    { field: 'roles', title: '角色', minWidth: 220, slots: { default: 'roles' } },
    { title: '操作', width: 220, fixed: 'right', slots: { default: 'actions' } },
  ], rowConfig: { keyField: 'id' }, pagerConfig: { pageSize: 20, pageSizes: [20, 50, 100] }, toolbarConfig: { refresh: true, search: true },
  proxyConfig: { ajax: { query: async ({ page }: { page: { currentPage: number; pageSize: number } }, values: Record<string, unknown>) => {
    pending?.abort(); pending = new AbortController(); failure.value = '';
    try { return await request<AccessPage<AccountRecord>>(`${base.value}/users`, { params: { ...values, page: page.currentPage, per_page: page.pageSize }, signal: pending.signal }); }
    catch (error) { if (!isCanceled(error)) failure.value = errorText(error); throw error; }
  } } } },
});
async function edit(user: AccountRecord | null, action: typeof mode.value) {
  editing.value = user; mode.value = action; saveFailure.value = '';
  editor.setState({ title: action === 'create' ? '新增用户' : action === 'password' ? '重置密码' : '编辑用户' }).open();
  await form.resetForm(); await form.setValues({ login: user?.login || '', name: user?.name || '', password: '' });
}
async function save() {
  if (!(await form.validate()).valid) return;
  editor.lock(); saveFailure.value = '';
  try {
    const values = await form.getValues(); const user = editing.value;
    const data = mode.value === 'password' ? { version: user!.version, password: values.password }
      : { login: values.login, name: values.name, ...(user ? { version: user.version } : { password: values.password }) };
    await request(`${base.value}/users${user ? `/${user.id}${mode.value === 'password' ? '/password' : ''}` : ''}`, { method: mode.value === 'update' ? 'PATCH' : 'POST', data });
    editor.close(); message.success('用户已保存'); await grid.query();
  } catch (error) { if (!isCanceled(error)) saveFailure.value = errorText(error); }
  finally { editor.unlock(); }
}
function act(user: AccountRecord, action: 'status' | 'sessions' | 'delete') {
  Modal.confirm({ title: action === 'status' ? `${user.enabled ? '停用' : '启用'}用户 ${user.name}？` : action === 'sessions' ? `撤销 ${user.name} 的所有会话？` : `删除用户 ${user.name}？`, async onOk() {
    try {
      await request(`${base.value}/users/${user.id}${action === 'delete' ? '' : `/${action}`}`, { method: action === 'status' ? 'POST' : 'DELETE', data: { version: user.version, ...(action === 'status' ? { enabled: !user.enabled } : {}) } });
      await grid.query(); await loadUser();
    } catch (error) { if (!isCanceled(error)) { message.error(errorText(error)); throw error; } }
  } });
}
const selected = ref<AccountRecord[]>([]); const roleOptions = ref<RoleRecord[]>([]); const selectedRoles = ref<string[]>([]); const roleSearch = ref(''); const rolePage = ref(1); const roleTotal = ref(0); const roleBusy = ref(false);
const [Assignment, assignment] = useVbenModal({ onConfirm: assign });
async function readRoles(append = false) {
  roleBusy.value = true;
  try {
    const page = append ? rolePage.value + 1 : 1;
    const data = await request<AccessPage<RoleRecord>>(`${base.value}/roles`, { params: { search: roleSearch.value, page, per_page: 100 } });
    const retained = roleOptions.value.filter(role => selectedRoles.value.includes(role.id));
    roleOptions.value = [...new Map([...retained, ...(append ? roleOptions.value : []), ...data.items].map(role => [role.id, role])).values()];
    rolePage.value = page; roleTotal.value = data.total;
  } catch (error) { message.error(errorText(error)); }
  finally { roleBusy.value = false; }
}
async function chooseRoles(user?: AccountRecord) {
  selected.value = user ? [user] : grid.grid.getCheckboxRecords();
  if (!selected.value.length) { message.info('请先选择用户'); return; }
  selectedRoles.value = user?.roles?.map(role => role.id) || []; roleOptions.value = user?.roles || []; roleSearch.value = ''; saveFailure.value = '';
  assignment.setState({ title: `分配角色（${selected.value.length} 人）` }).open(); await readRoles();
}
async function assign() {
  assignment.lock(); saveFailure.value = '';
  try {
    await request(`${base.value}/roles/assign`, { method: 'PUT', data: { users: selected.value.map(user => ({ id: user.id, version: user.version })), roles: selectedRoles.value.map(id => ({ id, version: roleOptions.value.find(role => role.id === id)!.version })) } });
    assignment.close(); await grid.query(); await loadUser();
  } catch (error) { if (!isCanceled(error)) saveFailure.value = errorText(error); }
  finally { assignment.unlock(); }
}
onBeforeUnmount(() => pending?.abort());
</script>
<template>
  <Page auto-content-height>
    <Alert v-if="failure" :message="failure" type="error" show-icon class="mb-3" />
    <Grid :table-title="session.realm === 'platform' ? '平台用户' : '用户管理'">
      <template #toolbar-tools><Space wrap><Button v-if="can('users.create')" type="primary" :disabled="isDemo()" @click="edit(null, 'create')">新增用户</Button><Button v-if="can('roles.assign') && can('roles.read')" :disabled="isDemo()" @click="chooseRoles()">批量分配角色</Button></Space></template>
      <template #status="{ row }"><Tag :color="row.enabled ? 'success' : 'default'">{{ row.enabled ? '启用' : '停用' }}</Tag></template>
      <template #roles="{ row }"><Space wrap><Tag v-for="role in row.roles" :key="role.id">{{ role.name }}</Tag></Space></template>
      <template #actions="{ row }"><Space wrap>
        <Button v-if="can('users.update')" type="link" size="small" :disabled="isDemo()" @click="edit(row, 'update')">编辑</Button>
        <Button v-if="can('roles.assign') && can('roles.read')" type="link" size="small" :disabled="isDemo()" @click="chooseRoles(row)">角色</Button>
        <Dropdown v-if="can('users.password') || can('users.sessions') || can('users.status') || can('users.delete')" :trigger="['click']"><Button type="link" size="small" :disabled="isDemo()">更多</Button><template #overlay><Menu>
          <MenuItem v-if="can('users.password')" @click="edit(row, 'password')">重置密码</MenuItem>
          <MenuItem v-if="can('users.sessions')" @click="act(row, 'sessions')">撤销会话</MenuItem>
          <MenuItem v-if="can('users.status')" @click="act(row, 'status')">{{ row.enabled ? '停用' : '启用' }}</MenuItem>
          <MenuItem v-if="can('users.delete')" danger @click="act(row, 'delete')">删除</MenuItem>
        </Menu></template></Dropdown>
      </Space></template>
    </Grid>
    <Editor><Alert v-if="saveFailure" :message="saveFailure" type="error" class="mb-3" /><Form /></Editor>
    <Assignment><Alert v-if="saveFailure" :message="saveFailure" type="error" class="mb-3" /><Select v-model:value="selectedRoles" mode="multiple" class="w-full" show-search :filter-option="false" :loading="roleBusy" :options="roleOptions.map(role => ({ label: `${role.name}${role.enabled ? '' : '（停用）'}`, value: role.id }))" @search="value => { roleSearch = value; readRoles(); }" /><Button v-if="rolePage * 100 < roleTotal" :loading="roleBusy" class="mt-3" @click="readRoles(true)">加载更多角色</Button></Assignment>
  </Page>
</template>
