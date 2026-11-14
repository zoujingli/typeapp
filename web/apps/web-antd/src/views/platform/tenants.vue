<script setup lang="ts">
import type { AccountRecord, Page as PageResult } from '../../api/contracts';
import type { TenantDetails } from '../../api/platform';
import { onBeforeUnmount, reactive, ref, computed } from 'vue';
import { Alert, Button, Dropdown, Input, Menu, MenuItem, Modal, Space, Tag, message } from 'ant-design-vue';
import { Page, useVbenDrawer, useVbenModal } from '@vben/common-ui';
import { useVbenForm, z } from '../../adapter/form';
import { useVbenVxeGrid } from '../../adapter/vxe-table';
import { errorText, isCanceled, isDemo, request, session } from '../../api/identity';
import { impersonate } from '../../api/platform';
const can = (action: string) => session.permissions.includes(`platform.tenants.${action}`);
const failure = ref(''); const saveFailure = ref(''); const editing = ref<TenantDetails | null>(null); const requestId = ref('');
let pending: AbortController | undefined;
const [Form, form] = useVbenForm(reactive({ showDefaultActions: false, layout: 'vertical', commonConfig: { componentProps: { class: 'w-full' } }, schema: computed(() => [
  { fieldName: 'name', label: '客户名称', component: 'Input' as const, rules: z.string().trim().min(1, '请输入客户名称').max(100) },
  { fieldName: 'subdomain', label: '子域名', component: 'Input' as const, componentProps: { placeholder: '可选，填写子域名前缀' } },
  ...(!editing.value ? [
    { fieldName: 'login', label: '初始超管账号', component: 'Input' as const, defaultValue: 'root-admin', rules: z.string().regex(/^[a-z0-9][a-z0-9_.@-]{2,99}$/, '请输入3至100位小写账号') },
    { fieldName: 'owner_name', label: '初始超管姓名', component: 'Input' as const, rules: z.string().trim().min(1, '请输入姓名').max(100) },
  ] : []),
]) }));
const [Editor, editor] = useVbenModal({ onConfirm: save, onClosed: () => form.resetForm() });
const [Grid, grid] = useVbenVxeGrid<TenantDetails>({ formOptions: { schema: [
  { fieldName: 'search', label: '客户名称', component: 'Input' },
  { fieldName: 'enabled', label: '状态', component: 'Select', defaultValue: -1, componentProps: { options: [{ label: '全部', value: -1 }, { label: '启用', value: 1 }, { label: '停用', value: 0 }] } },
] }, gridOptions: { columns: [
  { field: 'name', title: '客户名称', minWidth: 200 }, { field: 'id', title: '客户标识', minWidth: 280 }, { field: 'subdomain', title: '子域名', minWidth: 150 },
  { field: 'enabled', title: '状态', width: 90, slots: { default: 'status' } }, { title: '操作', width: 220, fixed: 'right', slots: { default: 'actions' } },
], rowConfig: { keyField: 'id' }, pagerConfig: { pageSize: 20, pageSizes: [20, 50, 100] }, toolbarConfig: { refresh: true, search: true }, proxyConfig: { ajax: { query: async ({ page }: { page: { currentPage: number; pageSize: number } }, values: Record<string, unknown>) => {
  pending?.abort(); pending = new AbortController(); failure.value = '';
  try { return await request<PageResult<TenantDetails>>('/platform/tenants', { params: { ...values, page: page.currentPage, per_page: page.pageSize }, signal: pending.signal }); }
  catch (error) { if (!isCanceled(error)) failure.value = errorText(error); throw error; }
} } } } });
async function edit(tenant: TenantDetails | null) {
  editing.value = tenant; requestId.value = crypto.randomUUID().replaceAll('-', ''); saveFailure.value = '';
  editor.setState({ title: tenant ? '编辑客户' : '开通客户' }).open(); await form.resetForm();
  await form.setValues({ name: tenant?.name || '', subdomain: tenant?.subdomain || '', login: 'root-admin', owner_name: '' });
}
interface Secret { user: { login: string; name: string }; password: string }
const credential = ref<Secret | null>(null);
const [SecretView, secretView] = useVbenModal({ title: '初始账号与口令', showConfirmButton: false, onClosed: () => { credential.value = null; } });
function showSecret(value?: Secret | null) { if (value?.password) { credential.value = value; secretView.open(); } }
async function save() {
  if (!(await form.validate()).valid) return;
  editor.lock(); saveFailure.value = '';
  try {
    const values = await form.getValues(); const tenant = editing.value;
    const data = { name: values.name, subdomain: String(values.subdomain || '').trim() || null, ...(tenant ? { version: tenant.version } : { request_id: requestId.value, login: values.login, owner_name: values.owner_name }) };
    const result = await request<{ tenant: TenantDetails; credential?: Secret | null; replayed?: boolean }>(`/platform/tenants${tenant ? `/${tenant.id}` : ''}`, { method: tenant ? 'PATCH' : 'POST', data });
    editor.close(); await grid.query(); if (!tenant) showSecret(result.credential);
    if (result.replayed) message.info('客户已开通，口令已交付；需要时可重置超管口令。');
  } catch (error) { if (!isCanceled(error)) saveFailure.value = errorText(error); }
  finally { editor.unlock(); }
}
function status(tenant: TenantDetails) {
  Modal.confirm({ title: `${tenant.enabled ? '停用' : '启用'}客户 ${tenant.name}？`, content: '停用后客户人员与设备通信资格会立即失效。', async onOk() {
    try { await request(`/platform/tenants/${tenant.id}/status`, { method: 'POST', data: { version: tenant.version, enabled: !tenant.enabled } }); await grid.query(); }
    catch (error) { message.error(errorText(error)); throw error; }
  } });
}
const target = ref<TenantDetails | null>(null); const adminFailure = ref('');
const [Administrators, administrators] = useVbenDrawer({ title: '客户超级管理员', class: 'w-full md:w-4/5', showConfirmButton: false, onOpened: () => { void adminGrid.reload(); } });
const [AdminGrid, adminGrid] = useVbenVxeGrid<AccountRecord>({ gridOptions: { columns: [
  { field: 'login', title: '账号', minWidth: 150 }, { field: 'name', title: '姓名', minWidth: 130 }, { field: 'enabled', title: '状态', width: 90, slots: { default: 'status' } },
  { title: '操作', fixed: 'right', width: 220, slots: { default: 'actions' } },
], pagerConfig: { pageSize: 20, pageSizes: [20, 50, 100] }, proxyConfig: { autoLoad: false, ajax: { query: async ({ page }: { page: { currentPage: number; pageSize: number } }) => {
  if (!target.value) return { items: [], total: 0 };
  adminFailure.value = '';
  try {
    const data = await request<{ items: TenantDetails[] }>(`/platform/tenants/${target.value.id}`); target.value = data.items[0]!;
    return await request<PageResult<AccountRecord>>(`/platform/tenants/${target.value.id}/administrators`, { params: { page: page.currentPage, per_page: page.pageSize } });
  } catch (error) {
    if (!isCanceled(error)) adminFailure.value = errorText(error);
    throw error;
  }
} } } } });
function manage(tenant: TenantDetails) { target.value = tenant; adminFailure.value = ''; administrators.open(); }
const adminAction = ref<'add' | 'replace'>('add'); const oldAdmin = ref<AccountRecord | null>(null);
const [AdminForm, adminForm] = useVbenForm({ showDefaultActions: false, layout: 'vertical', commonConfig: { componentProps: { class: 'w-full' } }, schema: [
  { fieldName: 'login', label: '账号', component: 'Input', rules: z.string().regex(/^[a-z0-9][a-z0-9_.@-]{2,99}$/, '请输入3至100位小写账号') },
  { fieldName: 'name', label: '姓名', component: 'Input', rules: z.string().trim().min(1).max(100) },
] });
const [AdminEditor, adminEditor] = useVbenModal({ onConfirm: saveAdmin, onClosed: () => adminForm.resetForm() });
async function editAdmin(action: 'add' | 'replace', user?: AccountRecord) { adminAction.value = action; oldAdmin.value = user || null; saveFailure.value = ''; adminEditor.setState({ title: action === 'add' ? '新增超管' : '替换超管' }).open(); await adminForm.resetForm(); }
async function saveAdmin() {
  if (!(await adminForm.validate()).valid || !target.value) return;
  adminEditor.lock(); saveFailure.value = '';
  try {
    const values = await adminForm.getValues(); const user = oldAdmin.value;
    const result = await request<{ tenant: TenantDetails; credential?: Secret }>(`/platform/tenants/${target.value.id}/administrators${user ? `/${user.id}/replace` : ''}`, { method: 'POST', data: { version: target.value.version, login: values.login, name: values.name, ...(user ? { user_version: user.version } : {}) } });
    target.value = result.tenant; adminEditor.close(); await adminGrid.query(); await grid.query(); showSecret(result.credential);
  } catch (error) { saveFailure.value = errorText(error); }
  finally { adminEditor.unlock(); }
}
function changeAdmin(user: AccountRecord, action: 'password' | 'remove' | 'status') {
  Modal.confirm({ title: action === 'password' ? `重置 ${user.name} 的口令？` : action === 'remove' ? `移除 ${user.name} 的超管身份？` : `${user.enabled ? '停用' : '启用'}超管 ${user.name}？`, async onOk() {
    try {
      const result = await request<{ tenant: TenantDetails; credential?: Secret }>(`/platform/tenants/${target.value!.id}/administrators/${user.id}/${action}`, { method: 'POST', data: { version: target.value!.version, user_version: user.version, ...(action === 'status' ? { enabled: !user.enabled } : {}) } });
      target.value = result.tenant; await adminGrid.query(); await grid.query(); showSecret(result.credential);
    } catch (error) { message.error(errorText(error)); throw error; }
  } });
}
async function simulate(user: AccountRecord) { try { await impersonate(user, target.value!); } catch (error) { adminFailure.value = errorText(error); } }
async function copyCredential() {
  if (!credential.value) return;
  try { await navigator.clipboard.writeText(credential.value.password); message.success('口令已复制'); }
  catch { message.error('复制失败，请手动保存口令'); }
}
onBeforeUnmount(() => { pending?.abort(); credential.value = null; });
</script>
<template>
  <Page auto-content-height><Alert v-if="failure" :message="failure" type="error" class="mb-4" show-icon /><Grid table-title="客户管理"><template #toolbar-tools><Button v-if="can('create')" type="primary" :disabled="isDemo()" @click="edit(null)">开通客户</Button></template>
    <template #status="{ row }"><Tag :color="row.enabled ? 'success' : 'default'">{{ row.enabled ? '启用' : '停用' }}</Tag></template>
    <template #actions="{ row }"><Space><Button v-if="can('update')" type="link" size="small" :disabled="isDemo()" @click="edit(row)">编辑</Button><Button type="link" size="small" @click="manage(row)">超管</Button><Button v-if="can('status')" type="link" size="small" :disabled="isDemo()" @click="status(row)">{{ row.enabled ? '停用' : '启用' }}</Button></Space></template>
  </Grid><Editor><Alert v-if="saveFailure" :message="saveFailure" type="error" class="mb-4" show-icon /><Form /></Editor>
  <Administrators><Alert v-if="adminFailure" :message="adminFailure" type="error" class="mb-4" show-icon /><AdminGrid :table-title="target?.name"><template #toolbar-tools><Button v-if="can('administrators.manage')" :disabled="isDemo()" @click="editAdmin('add')">新增超管</Button></template>
    <template #status="{ row }"><Tag :color="row.enabled ? 'success' : 'default'">{{ row.enabled ? '启用' : '停用' }}</Tag></template>
    <template #actions="{ row }"><Space><Button v-if="can('impersonate')" type="link" size="small" :disabled="isDemo() || !row.enabled || !target?.enabled" @click="simulate(row)">模拟登录</Button>
      <Dropdown v-if="can('administrators.manage')" :trigger="['click']"><Button type="link" size="small" :disabled="isDemo()">更多</Button><template #overlay><Menu>
        <MenuItem @click="changeAdmin(row, 'password')">重置口令</MenuItem><MenuItem @click="editAdmin('replace', row)">替换超管</MenuItem><MenuItem @click="changeAdmin(row, 'status')">{{ row.enabled ? '停用' : '启用' }}</MenuItem><MenuItem danger @click="changeAdmin(row, 'remove')">移除超管</MenuItem>
      </Menu></template></Dropdown>
    </Space></template>
  </AdminGrid></Administrators><AdminEditor><Alert v-if="saveFailure" :message="saveFailure" type="error" class="mb-4" show-icon /><AdminForm /></AdminEditor>
  <SecretView><Alert message="请立即保存口令，关闭后将不再显示。" type="warning" show-icon class="mb-4" /><p>账号：{{ credential?.user.login }}</p><Input.Password :value="credential?.password" readonly autocomplete="off" /><Button class="mt-4" @click="copyCredential">复制口令</Button></SecretView>
  </Page>
</template>
