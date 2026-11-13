<script setup lang="ts">
import type { AccessPage, RoleRecord } from '../../../api/contracts';
import { computed, onBeforeUnmount, ref } from 'vue';
import { Alert, Button, Dropdown, Menu, MenuItem, Modal, Space, Tag, Tree, message } from 'ant-design-vue';
import { Page, useVbenModal } from '@vben/common-ui';
import { useVbenForm, z } from '../../../adapter/form';
import { useVbenVxeGrid } from '../../../adapter/vxe-table';
import { errorText, isCanceled, isDemo, loadUser, request, session } from '../../../api/identity';
interface Node { id: string; parent_id: string | null; name: string; permission: string | null; enabled: boolean }
interface TreeNode { key: string; title: string; disabled: boolean; checkable: boolean; children: TreeNode[] }
const base = computed(() => `/${session.realm}`); const can = (action: string) => session.permissions.includes(`${session.realm}.roles.${action}`);
const failure = ref(''); const saveFailure = ref(''); const editing = ref<RoleRecord | null>(null); const nodes = ref<Node[]>([]); const checked = ref<string[]>([]);
const mode = ref<'create' | 'copy' | 'update' | 'permissions'>('create'); let pending: AbortController | undefined;
const tree = computed(() => {
  const children = (parent: string | null, enabled = true): TreeNode[] => nodes.value.filter(node => node.parent_id === parent).map(node => ({ key: node.id, title: node.name, checkable: !!node.permission,
    disabled: !enabled || !node.enabled || !!node.permission && !session.permissions.includes(node.permission), children: children(node.id, enabled && node.enabled) }));
  return children(null);
});
const [Form, form] = useVbenForm({ showDefaultActions: false, layout: 'vertical', commonConfig: { componentProps: { class: 'w-full' } }, schema: [{ fieldName: 'name', label: '角色名称', component: 'Input', rules: z.string().trim().min(1, '请输入角色名称').max(100) }] });
const [Editor, editor] = useVbenModal({ onConfirm: save, onClosed: () => { checked.value = []; return form.resetForm(); } });
const [Grid, grid] = useVbenVxeGrid<RoleRecord>({ formOptions: { schema: [
  { fieldName: 'search', label: '角色名称', component: 'Input' },
  { fieldName: 'enabled', label: '状态', component: 'Select', defaultValue: -1, componentProps: { options: [{ label: '全部', value: -1 }, { label: '启用', value: 1 }, { label: '停用', value: 0 }] } },
] }, gridOptions: { columns: [
  { field: 'name', title: '角色名称', minWidth: 200 }, { field: 'enabled', title: '状态', width: 90, slots: { default: 'status' } },
  { field: 'permissions', title: '权限数', width: 90, slots: { default: 'permissions' } }, { field: 'protected', title: '保护', width: 100, slots: { default: 'protected' } },
  { title: '操作', fixed: 'right', width: 200, slots: { default: 'actions' } },
], rowConfig: { keyField: 'id' }, pagerConfig: { pageSize: 20, pageSizes: [20, 50, 100] }, toolbarConfig: { refresh: true, search: true },
proxyConfig: { ajax: { query: async ({ page }: { page: { currentPage: number; pageSize: number } }, values: Record<string, unknown>) => {
  pending?.abort(); pending = new AbortController(); failure.value = '';
  try { return await request<AccessPage<RoleRecord>>(`${base.value}/roles`, { params: { ...values, page: page.currentPage, per_page: page.pageSize }, signal: pending.signal }); }
  catch (error) { if (!isCanceled(error)) failure.value = errorText(error); throw error; }
} } } } });
async function edit(role: RoleRecord | null, action: typeof mode.value) {
  editing.value = role; mode.value = action; checked.value = role?.menu_ids || []; saveFailure.value = '';
  editor.setState({ title: { create: '新增角色', copy: '复制角色', update: '编辑角色', permissions: '角色菜单授权' }[action] }).open();
  await form.setValues({ name: action === 'copy' ? `${role?.name}副本` : role?.name || '' });
  if (action === 'create' || action === 'permissions') {
    try { nodes.value = (await request<{ items: Node[] }>(session.realm === 'platform' ? '/platform/role-menus' : '/system/menus')).items; }
    catch (error) { saveFailure.value = errorText(error); }
  }
}
async function save() {
  if (mode.value !== 'permissions' && !(await form.validate()).valid) return;
  editor.lock(); saveFailure.value = '';
  try {
    const role = editing.value; const values = await form.getValues();
    const data = { ...(role ? { version: role.version } : {}), ...(mode.value !== 'permissions' ? { name: values.name } : {}), ...(['create', 'permissions'].includes(mode.value) ? { menu_ids: checked.value } : {}) };
    const suffix = mode.value === 'copy' ? '/copy' : mode.value === 'permissions' ? '/permissions' : '';
    await request(`${base.value}/roles${role ? `/${role.id}${suffix}` : ''}`, { method: mode.value === 'permissions' ? 'PUT' : mode.value === 'update' ? 'PATCH' : 'POST', data });
    editor.close(); message.success(['create', 'copy'].includes(mode.value) ? '角色已创建，请核对后启用' : '角色已保存'); await grid.query(); await loadUser();
  } catch (error) { if (!isCanceled(error)) saveFailure.value = errorText(error); }
  finally { editor.unlock(); }
}
function act(role: RoleRecord, action: 'status' | 'delete') {
  Modal.confirm({ title: action === 'delete' ? `删除角色 ${role.name}？` : `${role.enabled ? '停用' : '启用'}角色 ${role.name}？`, content: '已分配用户的权限将随本次变更生效。', async onOk() {
    try { await request(`${base.value}/roles/${role.id}${action === 'status' ? '/status' : ''}`, { method: action === 'delete' ? 'DELETE' : 'POST', data: { version: role.version, ...(action === 'status' ? { enabled: !role.enabled } : {}) } }); await grid.query(); await loadUser(); }
    catch (error) { message.error(errorText(error)); throw error; }
  } });
}
onBeforeUnmount(() => pending?.abort());
</script>
<template>
  <Page auto-content-height><Alert v-if="failure" :message="failure" type="error" class="mb-4" show-icon /><Grid :table-title="session.realm === 'platform' ? '平台角色' : '角色管理'">
    <template #toolbar-tools><Button v-if="can('create') && can('permissions')" type="primary" :disabled="isDemo()" @click="edit(null, 'create')">新增角色</Button></template>
    <template #status="{ row }"><Tag :color="row.enabled ? 'success' : 'default'">{{ row.enabled ? '启用' : '停用' }}</Tag></template>
    <template #permissions="{ row }">{{ row.permissions.length }}</template><template #protected="{ row }"><Tag v-if="row.protected" color="gold">超级管理员</Tag></template>
    <template #actions="{ row }"><Space wrap>
      <Button v-if="can('update')" type="link" size="small" :disabled="isDemo()" @click="edit(row, 'update')">编辑</Button>
      <Button v-if="can('permissions')" type="link" size="small" :disabled="isDemo() || Boolean(row.protected)" @click="edit(row, 'permissions')">授权</Button>
      <Dropdown v-if="can('copy') && can('permissions') || can('status') || can('delete')" :trigger="['click']"><Button type="link" size="small" :disabled="isDemo()">更多</Button><template #overlay><Menu>
        <MenuItem v-if="can('copy') && can('permissions')" @click="edit(row, 'copy')">复制角色</MenuItem>
        <MenuItem v-if="can('status')" :disabled="Boolean(row.protected)" @click="act(row, 'status')">{{ row.enabled ? '停用' : '启用' }}</MenuItem>
        <MenuItem v-if="can('delete')" :disabled="Boolean(row.protected)" danger @click="act(row, 'delete')">删除</MenuItem>
      </Menu></template></Dropdown>
    </Space></template>
  </Grid><Editor><Alert v-if="saveFailure" :message="saveFailure" type="error" class="mb-4" show-icon /><Form v-if="mode !== 'permissions'" /><Tree v-if="mode === 'create' || mode === 'permissions'" checkable check-strictly :checked-keys="checked" :tree-data="tree" @check="value => checked = Array.isArray(value) ? value as string[] : value.checked as string[]" /></Editor></Page>
</template>
