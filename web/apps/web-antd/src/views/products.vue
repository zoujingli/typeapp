<script setup lang="ts">
import type { FormInstance, TableColumnsType } from 'ant-design-vue';
import type { Page, Product } from '../api';
import type { CrudTableAction } from '../components/crud-table-actions.vue';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Alert, Button, Card, Descriptions, DescriptionsItem, Empty, Form, FormItem, Input, Table, message } from 'ant-design-vue';
import AppDrawer from '../components/app-drawer/app-drawer.vue';
import CrudSearchField from '../components/crud-search-field.vue';
import CrudTableActions from '../components/crud-table-actions.vue';
import { buildTableScrollX, estimateVisibleActionColumnWidth } from '../utils/table';
import { ApiError, applyAdminContext, cancelContext, clearAdminAccess, errorText, isCanceled, request, session } from '../api';

const router = useRouter();
const result = ref<Page<Product>>({ items: [], total: 0, page: 1, per_page: 20 });
const canRead = computed(() => session.realm === 'customer' && session.permissions.includes('customer.products.read'));
const name = ref('');
let applied = '';
const busy = ref(false);
const failure = ref('');
const open = ref(false);
const saving = ref(false);
const saveFailure = ref('');
const formRef = ref<FormInstance>();
const editing = ref<Product | null>(null);
const detail = ref<Product | null>(null);
const form = reactive({ name: '', description: '' });
let requestVersion = 0;
let pending: AbortController | null = null;
const canEdit = computed(() => canRead.value && session.permissions.includes('customer.products.manage'));
const columns = computed<TableColumnsType<Product>>(() => [
  { title: '产品名称', dataIndex: 'name', width: 260, ellipsis: true },
  { title: '产品说明', dataIndex: 'description', width: 360, ellipsis: true },
  { title: '创建时间', key: 'time', width: 200 },
  { title: '操作', key: 'actions', fixed: 'right', width: estimateVisibleActionColumnWidth([{ label: '物模型' }, { label: '详情' }, { label: '编辑', visible: canEdit.value }, { label: '删除', visible: canEdit.value }]) },
]);
const pagination = computed(() => ({ current: result.value.page, pageSize: result.value.per_page, total: result.value.total, showSizeChanger: true, pageSizeOptions: ['20', '50', '100'] }));
function reject(error: unknown) { if (clearAdminAccess(error)) { result.value.items = []; result.value.total = 0; open.value = false; detail.value = null; editing.value = null; } }
async function load(page = 1, perPage = result.value.per_page) {
  const tenant = session.tenant?.id;
  if (!tenant || !canRead.value) return;
  const version = ++requestVersion;
  pending?.abort(); pending = new AbortController();
  busy.value = true; failure.value = '';
  try {
    const data = await request<Page<Product> & { context: { permissions: string[]; menus: typeof session.menus } }>(`/customer/tenants/${tenant}/products`, { tenant, signal: pending.signal, params: { page, per_page: perPage, name: applied } });
    if (version !== requestVersion) return;
    result.value = data; applyAdminContext(data.context);
  } catch (error) { if (!isCanceled(error) && version === requestVersion) { result.value.items = [];  failure.value = errorText(error); reject(error); } }
  finally { if (version === requestVersion) busy.value = false; }
}
function search(reset = false) { if (busy.value) return; if (reset) name.value = ''; applied = name.value.trim(); void load(); }
function edit(product: Product | null) {
  if (!canEdit.value) return;
  editing.value = product; form.name = product?.name || ''; form.description = product?.description || '';
  saveFailure.value = ''; open.value = true;
}
async function save() {
  const tenant = session.tenant?.id;
  if (saving.value || !tenant || !canEdit.value) return;
  try { await formRef.value?.validate(); } catch { return; }
  if (saving.value) return;
  saving.value = true; saveFailure.value = '';
  try {
    await request(`/customer/tenants/${tenant}/products${editing.value ? `/${editing.value.id}` : ''}`, { tenant, method: editing.value ? 'PATCH' : 'POST', data: { name: form.name.trim(), description: form.description, ...(editing.value ? { version: editing.value.version } : {}) } });
    open.value = false; message.success(editing.value ? '产品已更新' : '产品已创建'); await load(result.value.page);
  } catch (error) {
    if (!isCanceled(error)) { saveFailure.value = errorText(error); reject(error); }
    if (error instanceof ApiError && error.code === 'stale_version') await load(result.value.page);
  } finally { saving.value = false; }
}
async function remove(product: Product) {
  const tenant = session.tenant?.id;
  if (!tenant || !canEdit.value) return;
  try {
    await request(`/customer/tenants/${tenant}/products/${product.id}`, { tenant, method: 'DELETE', data: { version: product.version } });
    message.success('未发布产品及其草稿已删除'); await load(result.value.items.length === 1 ? Math.max(1, result.value.page - 1) : result.value.page);
  } catch (error) { if (!isCanceled(error)) { failure.value = errorText(error); reject(error); message.error(failure.value); } }
}
function actions(product: Product): CrudTableAction[] { return [
  { label: '物模型', onClick: () => router.push(`/products/${product.id}/models`) },
  { label: '详情', onClick: () => { detail.value = product; } },
  { label: '编辑', visible: canEdit.value, onClick: () => edit(product) },
  { label: '删除', danger: true, visible: canEdit.value, confirmTitle: `确认删除产品“${product.name}”？`, confirmContent: '仅未发布产品可删除，其草稿将一并删除。存在已发布版本时会拒绝删除，以保留历史定义。', confirmOkText: '删除产品', onClick: () => remove(product) },
]; }
watch([() => session.tenant?.id, () => session.token], ([tenant]) => {
  requestVersion++; pending?.abort(); cancelContext(); result.value.items = [];  open.value = false; detail.value = null;
  result.value.total = 0; failure.value = ''; saveFailure.value = '';
  if (!tenant) void router.replace('/tenants'); else if (canRead.value) void load(); else failure.value = '当前没有产品与物模型查看权限。';
}, { immediate: true });
onBeforeUnmount(() => { requestVersion++; pending?.abort(); });
</script>

<template>
  <section class="iot-page">
    <header class="page-heading"><div><h1>产品管理</h1><p class="muted tenant-title">{{ session.tenant?.name }} · 产品与物模型版本</p></div><Button v-if="canEdit" type="primary" @click="edit(null)">创建产品</Button></header>
    <Card :bordered="false">
      <form class="crud-search-grid" @submit.prevent="search()">
        <CrudSearchField label="产品名称"><Input v-model:value="name" aria-label="产品名称筛选" :disabled="busy" :maxlength="100" allow-clear /></CrudSearchField>
        <div class="crud-search-grid__actions"><Button type="primary" html-type="submit" :loading="busy">查询</Button><Button :disabled="busy" @click="search(true)">重置</Button></div>
      </form>
      <Alert v-if="failure" :message="failure" type="error" show-icon class="page-alert" role="alert" />
      <Alert v-else-if="canRead && !canEdit" message="当前角色可查看产品与物模型，当前未获产品与模型维护权限。" type="info" show-icon class="page-alert" />
      <div class="table-toolbar"><span class="muted">{{ result.total }} 个产品</span><Button :loading="busy" :disabled="!canRead" @click="load(result.page)">刷新</Button></div>
      <Table :columns="columns" :data-source="result.items" row-key="id" :loading="busy" :scroll="buildTableScrollX(columns)" :pagination="pagination" @change="(page) => load(page.current, page.pageSize)">
        <template #emptyText><Empty :description="failure ? '数据暂不可用，请重试' : '暂无符合条件的产品'" /></template>
        <template #bodyCell="{ column, record }"><template v-if="column.key === 'time'">{{ new Date(Number(record.created_at) * 1000).toLocaleString() }}</template><CrudTableActions v-else-if="column.key === 'actions'" :actions="actions(record as Product)" /></template>
      </Table>
    </Card>
    <AppDrawer v-model:open="open" :title="editing ? '编辑产品' : '创建产品'" width-size="sm" :confirm-loading="saving" :ok-disabled="saving || !canEdit" :closable="!saving" @ok="save">
      <Alert v-if="saveFailure" :message="saveFailure" type="error" show-icon class="page-alert" role="alert" />
      <Form ref="formRef" :model="form" layout="vertical" class="form-grid" :disabled="saving">
        <FormItem label="产品名称" name="name" :rules="[{ required: true, whitespace: true, message: '请输入产品名称' }]" class="span-full"><Input v-model:value="form.name" :maxlength="100" /></FormItem>
        <FormItem label="产品说明" name="description" class="span-full"><Input.TextArea v-model:value="form.description" :maxlength="1000" :rows="4" /></FormItem>
      </Form>
    </AppDrawer>
    <AppDrawer :open="Boolean(detail)" title="产品详情" width-size="sm" :show-footer="false" @update:open="(value) => { if (!value) detail = null; }">
      <Descriptions v-if="detail" :column="{ xs: 1, sm: 2 }" layout="vertical" bordered>
        <DescriptionsItem label="产品名称">{{ detail.name }}</DescriptionsItem><DescriptionsItem label="产品标识">{{ detail.id }}</DescriptionsItem>
        <DescriptionsItem label="所属租户">{{ session.tenant?.name }}</DescriptionsItem><DescriptionsItem label="记录版本">{{ detail.version }}</DescriptionsItem>
        <DescriptionsItem label="产品说明" :span="2">{{ detail.description || '未填写' }}</DescriptionsItem>
      </Descriptions>
    </AppDrawer>
  </section>
</template>
