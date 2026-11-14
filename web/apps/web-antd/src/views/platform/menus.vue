<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Alert, Button, Modal, Space, Tabs, TabPane, Tag, message } from 'ant-design-vue';
import { Page, useVbenModal } from '@vben/common-ui';
import { useVbenForm, z } from '../../adapter/form';
import { useVbenVxeGrid } from '../../adapter/vxe-table';
import { errorText, isCanceled, isDemo, loadUser, request, session } from '../../api/identity';
interface MenuNode { id: string; parent_id: string | null; realm: string; name: string; kind: 'directory' | 'page' | 'action'; permission: string | null; component: string; icon: string; order_number: number; enabled: boolean; hidden: boolean; guarded: boolean; version: number }
const directory = ref<'platform' | 'system'>('platform'); const rows = ref<MenuNode[]>([]); const bindings = ref<MenuNode[]>([]); const bindingsBusy = ref(false); const editing = ref<MenuNode | null>(null); const failure = ref(''); const saveFailure = ref('');
const canManage = computed(() => session.permissions.includes('platform.menus.manage'));
let pending: AbortController | undefined;
const [Form, form] = useVbenForm({ showDefaultActions: false, layout: 'vertical', commonConfig: { componentProps: { class: 'w-full' } }, schema: [
  { fieldName: 'name', label: '名称', component: 'Input', rules: z.string().trim().min(1, '请输入名称').max(100) },
  { fieldName: 'kind', label: '类型', component: 'Select', defaultValue: 'directory', componentProps: () => ({ disabled: !!editing.value, options: [{ label: '目录', value: 'directory' }, { label: '页面', value: 'page' }, { label: '操作', value: 'action' }] }) },
  { fieldName: 'permission', label: '登记能力', component: 'Select', componentProps: () => ({ allowClear: true, loading: bindingsBusy.value, disabled: !!editing.value || bindingsBusy.value, options: bindings.value.filter(node => !rows.value.some(row => row.permission === node.permission)).map(node => ({ label: `${node.name} · ${node.permission}`, value: node.permission })) }), dependencies: { triggerFields: ['kind'], show: values => values.kind !== 'directory' } },
  { fieldName: 'parent_id', label: '上级', component: 'Select', componentProps: () => ({ allowClear: true, options: rows.value.filter(node => node.kind !== 'action' && node.id !== editing.value?.id).map(node => ({ label: node.name, value: node.id })) }) },
  { fieldName: 'icon', label: '图标', component: 'IconPicker', defaultValue: '' },
  { fieldName: 'order_number', label: '排序', component: 'InputNumber', defaultValue: 0 },
  { fieldName: 'enabled', label: '启用', component: 'Switch', componentProps: { class: 'w-auto' }, defaultValue: true },
  { fieldName: 'hidden', label: '隐藏导航', component: 'Switch', componentProps: { class: 'w-auto' }, defaultValue: false },
] });
const [Editor, editor] = useVbenModal({ onConfirm: save, onClosed: () => form.resetForm() });
const [Grid, grid] = useVbenVxeGrid<MenuNode>({ gridOptions: { columns: [
  { field: 'name', title: '菜单名称', treeNode: true, minWidth: 200 }, { field: 'kind', title: '类型', width: 90, slots: { default: 'kind' } },
  { field: 'permission', title: '权限', minWidth: 260 }, { field: 'enabled', title: '状态', width: 110, slots: { default: 'status' } },
  { field: 'hidden', title: '导航', width: 90, slots: { default: 'hidden' } }, { field: 'order_number', title: '排序', width: 80 },
  { title: '操作', width: 140, fixed: 'right', slots: { default: 'actions' } },
], treeConfig: { transform: true, rowField: 'id', parentField: 'parent_id' }, pagerConfig: { enabled: false }, toolbarConfig: { refresh: true },
proxyConfig: { ajax: { query: async () => {
  pending?.abort(); pending = new AbortController(); failure.value = '';
  try { const result = await request<{ items: MenuNode[] }>(`/platform/menus/${directory.value}`, { signal: pending.signal }); rows.value = result.items; return result; }
  catch (error) { if (!isCanceled(error)) failure.value = errorText(error); throw error; }
} } } } });
async function edit(node: MenuNode | null) {
  editing.value = node; saveFailure.value = '';
  editor.setState({ title: node ? '编辑菜单' : '新增菜单' }).open();
  await form.resetForm(); await form.setValues(node || { name: '', kind: 'directory', parent_id: null, permission: null, icon: '', order_number: 0, enabled: true, hidden: false });
  if (!node) {
    bindingsBusy.value = true;
    try { bindings.value = (await request<{ items: MenuNode[] }>(`/platform/menu-bindings/${directory.value}`)).items; }
    catch (error) { bindings.value = []; if (!isCanceled(error)) saveFailure.value = errorText(error); }
    finally { bindingsBusy.value = false; }
  }
}
async function save() {
  if (!(await form.validate()).valid) return;
  editor.lock(); saveFailure.value = '';
  try {
    const values = await form.getValues(); const node = editing.value;
    const data = { version: node?.version || 0, name: values.name, parent_id: values.parent_id || null, icon: values.icon || '', order_number: values.order_number, enabled: values.enabled, hidden: values.hidden,
      ...(!node ? { kind: values.kind, permission: values.kind === 'directory' ? null : values.permission || null } : {}) };
    await request(`/platform/menus/${directory.value}${node ? `/${node.id}` : ''}`, { method: node ? 'PUT' : 'POST', data });
    editor.close(); message.success('菜单已保存'); await grid.query(); await loadUser();
  } catch (error) { if (!isCanceled(error)) saveFailure.value = errorText(error); }
  finally { editor.unlock(); }
}
function remove(node: MenuNode) {
  Modal.confirm({ title: `删除菜单 ${node.name}？`, content: '已被角色使用或存在子节点的菜单不能删除。', async onOk() {
    try { await request(`/platform/menus/${directory.value}/${node.id}`, { method: 'DELETE', data: { version: node.version } }); await grid.query(); await loadUser(); }
    catch (error) { message.error(errorText(error)); throw error; }
  } });
}
watch(directory, () => grid.reload()); onBeforeUnmount(() => pending?.abort());
</script>
<template>
  <Page auto-content-height title="菜单目录" description="维护平台与客户系统共用的权限目录。" header-class="flex-wrap gap-4"><Tabs v-model:active-key="directory" class="mb-4 rounded-md bg-card px-4"><TabPane key="platform" tab="平台菜单" /><TabPane key="system" tab="子系统菜单" /></Tabs>
    <Alert v-if="failure" :message="failure" type="error" class="mb-4" show-icon /><Grid><template #toolbar-tools><Button v-if="canManage" type="primary" :disabled="isDemo()" @click="edit(null)">新增菜单</Button></template>
    <template #kind="{ row }">{{ { directory: '目录', page: '页面', action: '操作' }[row.kind as 'directory' | 'page' | 'action'] }}</template>
    <template #status="{ row }"><Tag :color="row.enabled ? 'success' : 'default'">{{ row.enabled ? '启用' : '停用' }}</Tag></template>
    <template #hidden="{ row }">{{ row.hidden ? '隐藏' : '显示' }}</template>
    <template #actions="{ row }"><Space><Button v-if="canManage" type="link" size="small" :disabled="isDemo()" @click="edit(row)">编辑</Button><Button v-if="canManage" type="link" size="small" danger :disabled="isDemo() || row.guarded" @click="remove(row)">删除</Button></Space></template>
    </Grid><Editor><Alert v-if="saveFailure" :message="saveFailure" type="error" class="mb-4" show-icon /><Form /></Editor>
  </Page>
</template>
