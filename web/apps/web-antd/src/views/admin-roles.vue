<script setup lang="ts">
import type { FormInstance, TableColumnsType } from 'ant-design-vue';
import type { AdminPage, AdminRole, Page } from '../api';
import type { CrudTableAction } from '../components/crud-table-actions.vue';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Alert, Button, Card, Descriptions, DescriptionsItem, Empty, Form, FormItem, Input, Select, Table, Tag, Tree, message } from 'ant-design-vue';
import AppDrawer from '../components/app-drawer/app-drawer.vue';
import CrudSearchField from '../components/crud-search-field.vue';
import CrudTableActions from '../components/crud-table-actions.vue';
import { buildTableScrollX, estimateVisibleActionColumnWidth } from '../utils/table';
import { ApiError, applyAdminContext, cancelContext, clearAdminAccess, errorText, isCanceled, request, session } from '../api';

const route = useRoute(); const router = useRouter();
const realm = computed(() => route.path === '/roles' ? 'customer' : 'admin');
const tenant = computed(() => realm.value === 'customer' ? session.tenant?.id : undefined);
const endpoint = computed(() => `/${realm.value}/roles`);
const title = computed(() => realm.value === 'customer' ? '租户角色' : '平台角色');
const result = ref<Page<AdminRole>>({ items: [], total: 0, page: 1, per_page: 20 });
const catalog = ref<Record<string, string>>({});
const filters = reactive({ search: '', enabled: -1 }); const applied = reactive({ search: '', enabled: -1 });
const busy = ref(false); const failure = ref(''); const saving = ref(false); const saveFailure = ref('');
const open = ref(false); const mode = ref<'create' | 'copy' | 'update' | 'permissions'>('create');
const editing = ref<AdminRole | null>(null); const detail = ref<AdminRole | null>(null); const detailBusy = ref(false);
const form = reactive({ name: '' }); const checked = ref<string[]>([]); const formRef = ref<FormInstance>();
let read: AbortController | null = null; let detailRead: AbortController | null = null;
const can = (action: string) => session.realm === realm.value && session.permissions.includes(`${realm.value}.roles.${action}`);
const grantable = (role: AdminRole) => role.permissions.every(permission => session.permissions.includes(permission));
const groupNames: Record<string, string> = { identity: '个人工作区', 'admin.users': '平台人员', 'admin.roles': '平台角色', 'admin.tenants': '平台租户', 'admin.customers': '客户账号', 'customer.members': '租户成员', 'customer.roles': '租户角色', 'customer.products': '产品与物模型' };
const tree = computed(() => [...new Set(Object.keys(catalog.value).map(key => key.slice(0, key.lastIndexOf('.'))))].map(prefix => ({
  key: prefix, title: groupNames[prefix] || prefix,
  children: Object.entries(catalog.value).filter(([key]) => key.startsWith(`${prefix}.`)).map(([key, label]) => ({ key, title: label, disabled: !session.permissions.includes(key) || saving.value || Boolean(Number(editing.value?.protected)) && mode.value === 'permissions' })),
})));
const columns = computed<TableColumnsType<AdminRole>>(() => [
  { title: '角色名称', dataIndex: 'name', width: 260, ellipsis: true }, { title: '角色状态', key: 'status', width: 110 },
  { title: '权限数量', key: 'permissions', width: 120 }, { title: '角色保护', key: 'protected', width: 130 },
  { title: '操作', key: 'actions', fixed: 'right', width: estimateVisibleActionColumnWidth([{ label: '详情' }, { label: '编辑', visible: can('update') }, { label: '权限', visible: can('permissions') }, { label: '复制', visible: can('copy') }, { label: '停用', visible: can('status') }, { label: '删除', visible: can('delete') }]) },
]);
const pagination = computed(() => ({ current: result.value.page, pageSize: result.value.per_page, total: result.value.total, showSizeChanger: true, pageSizeOptions: ['20', '50', '100'], showTotal: (total: number) => `共 ${total} 个角色` }));
function reject(error: unknown) { if (clearAdminAccess(error)) { result.value.items = []; open.value = false; detail.value = null; } }
async function load(page = 1, perPage = result.value.per_page) {
  if (realm.value === 'customer' && !tenant.value) return;
  read?.abort(); const pending = new AbortController(); read = pending; busy.value = true; failure.value = '';
  try {
    const data = await request<AdminPage<AdminRole>>(endpoint.value, { tenant: tenant.value, params: { ...applied, page, per_page: perPage }, signal: pending.signal });
    if (read !== pending) return; result.value = data; catalog.value = data.catalog; applyAdminContext(data);
  } catch (error) { if (!isCanceled(error)) { failure.value = errorText(error); reject(error); } }
  finally { if (read === pending) busy.value = false; }
}
function search(reset = false) { if (busy.value) return; if (reset) Object.assign(filters, { search: '', enabled: -1 }); Object.assign(applied, filters); void load(); }
function edit(role: AdminRole | null, action: typeof mode.value) {
  editing.value = role; mode.value = action; form.name = action === 'copy' ? `${role?.name || ''}副本` : role?.name || ''; checked.value = [...(role?.permissions || [])]; saveFailure.value = ''; open.value = true;
}
async function showDetail(role: AdminRole) {
  detailRead?.abort(); const pending = new AbortController(); detailRead = pending; detailBusy.value = true;
  try { const data = await request<AdminPage<AdminRole>>(`${endpoint.value}/${role.id}`, { tenant: tenant.value, signal: pending.signal }); if (detailRead === pending) { detail.value = data.items[0] || null; catalog.value = data.catalog; applyAdminContext(data); } }
  catch (error) { if (!isCanceled(error)) { failure.value = errorText(error); reject(error); } }
  finally { if (detailRead === pending) detailBusy.value = false; }
}
async function save() {
  if (saving.value) return;
  if (mode.value !== 'permissions') { try { await formRef.value?.validate(); } catch { return; } }
  if (saving.value) return;
  saving.value = true; saveFailure.value = '';
  try {
    const role = editing.value;
    const version = role ? { version: Number(role.version) } : {};
    const payload = mode.value === 'permissions' ? { ...version, permissions: checked.value.filter(key => key in catalog.value) }
      : { ...version, name: form.name.trim(), ...(mode.value === 'create' ? { permissions: checked.value.filter(key => key in catalog.value) } : {}) };
    const suffix = mode.value === 'copy' ? '/copy' : mode.value === 'permissions' ? '/permissions' : '';
    await request(`${endpoint.value}${role ? `/${role.id}${suffix}` : ''}`, { tenant: tenant.value, method: mode.value === 'update' ? 'PATCH' : mode.value === 'permissions' ? 'PUT' : 'POST', data: payload });
    open.value = false; message.success(mode.value === 'create' || mode.value === 'copy' ? '角色已保存为停用，请核对后启用' : '角色已更新'); await load(result.value.page);
  } catch (error) { if (!isCanceled(error)) { saveFailure.value = errorText(error); reject(error); if (error instanceof ApiError && error.status === 409) await load(result.value.page); } }
  finally { saving.value = false; }
}
async function action(role: AdminRole, kind: 'status' | 'delete') {
  try {
    await request(`${endpoint.value}/${role.id}${kind === 'status' ? '/status' : ''}`, { tenant: tenant.value, method: kind === 'delete' ? 'DELETE' : 'POST', data: { version: Number(role.version), ...(kind === 'status' ? { enabled: !Number(role.enabled) } : {}) } });
    message.success(kind === 'delete' ? '角色与全部绑定已删除' : '角色状态已更新'); await load(result.value.page);
  } catch (error) { if (!isCanceled(error)) { failure.value = errorText(error); reject(error); } }
}
function actions(role: AdminRole): CrudTableAction[] { return [
  { label: '详情', disabled: detailBusy.value, onClick: () => showDetail(role) },
  { label: '编辑', visible: can('update'), disabled: !grantable(role), onClick: () => edit(role, 'update') },
  { label: '权限', visible: can('permissions'), disabled: !grantable(role), onClick: () => edit(role, 'permissions') },
  { label: '复制', visible: can('copy') && can('permissions'), disabled: !grantable(role), onClick: () => edit(role, 'copy') },
  { label: Number(role.enabled) ? '停用' : '启用', visible: can('status') && !Number(role.protected), disabled: !grantable(role), danger: Boolean(Number(role.enabled)), confirmTitle: `${Number(role.enabled) ? '停用' : '启用'}角色“${role.name}”？`, confirmContent: '所有绑定人员的后续访问都会使用新的角色状态。', onClick: () => action(role, 'status') },
  { label: '删除', visible: can('delete') && !Number(role.protected), disabled: !grantable(role), danger: true, confirmTitle: `删除角色“${role.name}”？`, confirmContent: '将一并移除全部人员绑定，此操作不可恢复。', onClick: () => action(role, 'delete') },
]; }
watch([realm, tenant], () => {
  read?.abort(); detailRead?.abort(); cancelContext(); open.value = false; detail.value = null; editing.value = null;
  checked.value = []; catalog.value = {}; form.name = ''; failure.value = ''; saveFailure.value = '';
  Object.assign(filters, { search: '', enabled: -1 }); Object.assign(applied, filters);
  result.value = { items: [], total: 0, page: 1, per_page: 20 };
  if (realm.value === 'customer' && !tenant.value) void router.replace('/tenants'); else void load();
}, { immediate: true });
onBeforeUnmount(() => { read?.abort(); detailRead?.abort(); cancelContext(); });
</script>

<template>
  <section class="iot-page">
    <header class="page-heading"><div><h1>{{ title }}</h1><p class="muted">固定功能节点、多角色并集和最高管理员保护</p></div><Button v-if="can('create') && can('permissions')" type="primary" @click="edit(null, 'create')">新增角色</Button></header>
    <Card :bordered="false">
      <form class="crud-search-grid" @submit.prevent="search()"><CrudSearchField label="角色名称"><Input v-model:value="filters.search" aria-label="角色名称筛选" :disabled="busy" allow-clear :maxlength="100" /></CrudSearchField><CrudSearchField label="角色状态"><Select v-model:value="filters.enabled" :disabled="busy" :options="[{ label: '全部状态', value: -1 }, { label: '启用', value: 1 }, { label: '停用', value: 0 }]" /></CrudSearchField><div class="crud-search-grid__actions"><Button type="primary" html-type="submit" :disabled="busy" :loading="busy">查询</Button><Button :disabled="busy" @click="search(true)">重置</Button></div></form>
      <Alert v-if="failure" :message="failure" type="error" show-icon role="alert" class="page-alert" />
      <div class="table-toolbar"><span class="muted">{{ result.total }} 个{{ title }}</span><Button :disabled="busy" :loading="busy" @click="load(result.page)">刷新</Button></div>
      <Table :columns="columns" :data-source="result.items" row-key="id" :loading="busy" :scroll="buildTableScrollX(columns)" :pagination="pagination" @change="page => load(page.current, page.pageSize)">
        <template #emptyText><Empty :description="failure ? '数据暂不可用，请重试' : '暂无符合条件的角色'" /></template>
        <template #bodyCell="{ column, record }"><Tag v-if="column.key === 'status'" :color="Number(record.enabled) ? 'success' : undefined">{{ Number(record.enabled) ? '启用' : '停用' }}</Tag><span v-else-if="column.key === 'permissions'">{{ record.permissions.length }}</span><Tag v-else-if="column.key === 'protected'">{{ Number(record.protected) ? '受保护' : '自定义' }}</Tag><CrudTableActions v-else-if="column.key === 'actions'" :actions="actions(record as AdminRole)" /></template>
      </Table>
    </Card>
    <AppDrawer v-model:open="open" :title="mode === 'create' ? `新增${title}` : mode === 'copy' ? `复制${title}` : mode === 'permissions' ? '编辑角色权限' : `编辑${title}`" width-size="sm" :confirm-loading="saving" :closable="!saving" :ok-disabled="saving" @ok="save">
      <Alert v-if="saveFailure" :message="saveFailure" type="error" show-icon role="alert" class="page-alert" />
      <Alert v-if="Number(editing?.protected) && mode === 'permissions'" message="最高管理员的必要节点受保护，不能收紧。" type="info" show-icon class="page-alert" />
      <Form v-if="mode !== 'permissions'" ref="formRef" :model="form" layout="vertical"><FormItem label="角色名称" name="name" :rules="[{ required: true, whitespace: true, message: '请输入角色名称' }]"><Input v-model:value="form.name" :disabled="saving" :maxlength="100" /></FormItem></Form>
      <template v-if="mode === 'create' || mode === 'permissions'"><p class="muted">只可授予自己当前拥有的节点。新建角色为停用状态。</p><Tree v-model:checked-keys="checked" :tree-data="tree" checkable default-expand-all block-node /></template>
      <p v-if="mode === 'copy'" class="muted">复制当前版本的全部权限，副本为停用状态且不复制人员绑定。启用前会再次检查权限。</p>
    </AppDrawer>
    <AppDrawer :open="Boolean(detail)" :title="`${title}详情`" width-size="sm" :ok-visible="false" :mask-closable="true" @update:open="value => { if (!value) detail = null; }">
      <Descriptions v-if="detail" :column="1" bordered><DescriptionsItem label="角色名称">{{ detail.name }}</DescriptionsItem><DescriptionsItem label="角色标识">{{ detail.id }}</DescriptionsItem><DescriptionsItem label="状态">{{ Number(detail.enabled) ? '启用' : '停用' }}</DescriptionsItem><DescriptionsItem label="保护">{{ Number(detail.protected) ? '最高管理员' : '自定义角色' }}</DescriptionsItem><DescriptionsItem label="版本">{{ detail.version }}</DescriptionsItem><DescriptionsItem label="权限节点"><div v-for="permission in detail.permissions" :key="permission">{{ catalog[permission] || permission }}</div><span v-if="!detail.permissions.length">无权限</span></DescriptionsItem></Descriptions>
    </AppDrawer>
  </section>
</template>
