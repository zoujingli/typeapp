<script setup lang="ts">
import { displayDateTime } from '../../display';
import type { Page as PageResult } from '../../api/contracts';
import type { Product } from '../../api/center';
import { computed, onBeforeUnmount, ref } from 'vue';
import { Alert, Button, Descriptions, DescriptionsItem, Modal, Space, message } from 'ant-design-vue';
import { Page, useVbenDrawer, useVbenModal } from '@vben/common-ui';
import { useVbenForm, z } from '../../adapter/form';
import { useVbenVxeGrid } from '../../adapter/vxe-table';
import { applyAccessContext, errorText, isCanceled, isDemo, request, session } from '../../api/identity';
const canEdit = computed(() => session.permissions.includes('center.products.manage') && !isDemo());
const editing = ref<Product | null>(null); const detail = ref<Product>(); const failure = ref(''); const detailBusy = ref(false); const detailFailure = ref(''); const saveFailure = ref('');
let pending: AbortController | undefined; let detailPending: AbortController | undefined; let detailGeneration = 0;
const [Form, form] = useVbenForm({ showDefaultActions: false, layout: 'vertical', commonConfig: { componentProps: { class: 'w-full' } }, schema: [
  { fieldName: 'name', label: '分类名称', component: 'Input', rules: z.string().trim().min(1, '请输入分类名称').max(100) },
  { fieldName: 'description', label: '分类说明', component: 'Textarea', componentProps: { rows: 4, maxlength: 1000 } },
] });
const [Editor, editor] = useVbenModal({ onConfirm: save, onClosed: () => form.resetForm() });
const [Detail, drawer] = useVbenDrawer({ title: '分类详情', footer: false, onClosed: () => { detailGeneration++; detailPending?.abort(); detailPending = undefined; detailBusy.value = false; detail.value = undefined; } });
const [Grid, grid] = useVbenVxeGrid<Product>({ formOptions: { schema: [{ fieldName: 'name', label: '分类名称', component: 'Input' }] }, gridOptions: {
  columns: [{ field: 'name', title: '分类名称', minWidth: 180 }, { field: 'description', title: '分类说明', minWidth: 260 },
    { field: 'created_at', title: '创建时间', width: 180, formatter: ({ cellValue }: { cellValue: string | number }) => displayDateTime(Number(cellValue) * 1000) },
    { title: '操作', width: 190, fixed: 'right', slots: { default: 'actions' } }],
  rowConfig: { keyField: 'id' }, pagerConfig: { pageSize: 20, pageSizes: [20, 50, 100] }, toolbarConfig: { refresh: true, search: true },
  proxyConfig: { ajax: { query: async ({ page }: { page: { currentPage: number; pageSize: number } }, values: Record<string, unknown>) => {
    pending?.abort(); pending = new AbortController(); failure.value = '';
    try {
      const result = await request<PageResult<Product> & { context: { permissions: string[]; menus: typeof session.menus } }>('/center/products', { signal: pending.signal, params: { ...values, page: page.currentPage, per_page: page.pageSize } });
      applyAccessContext(result.context); return result;
    } catch (error) { if (!isCanceled(error)) failure.value = errorText(error); throw error; }
  } } },
} });
async function edit(product: Product | null) {
  editing.value = product; saveFailure.value = ''; editor.setState({ title: product ? '编辑分类' : '创建分类' }).open();
  await form.setValues({ name: product?.name || '', description: product?.description || '' });
}
async function save() {
  if (!canEdit.value || !(await form.validate()).valid) return;
  editor.lock(); saveFailure.value = '';
  try {
    const product = editing.value;
    await request(`/center/products${product ? `/${product.id}` : ''}`, { method: product ? 'PATCH' : 'POST', data: { ...await form.getValues(), ...(product ? { version: product.version } : {}) } });
    editor.close(); message.success('分类已保存'); await grid.query();
  } catch (error) { if (!isCanceled(error)) saveFailure.value = errorText(error); }
  finally { editor.unlock(); }
}
function remove(product: Product) {
  Modal.confirm({ title: `删除分类 ${product.name}？`, content: '仍有设备引用时会拒绝删除。', async onOk() {
    try { await request(`/center/products/${product.id}`, { method: 'DELETE', data: { version: product.version } }); message.success('分类已删除'); await grid.query(); }
    catch (error) { message.error(errorText(error)); throw error; }
  } });
}
async function show(product: Product) {
  const current = ++detailGeneration; detailPending?.abort(); detailPending = new AbortController(); detailBusy.value = true; detailFailure.value = ''; detail.value = undefined; drawer.open();
  try {
    const data = await request<Product>(`/center/products/${product.id}`, { signal: detailPending.signal });
    if (current === detailGeneration) detail.value = data;
  } catch (error) { if (!isCanceled(error) && current === detailGeneration) detailFailure.value = errorText(error); }
  finally { if (current === detailGeneration) { detailBusy.value = false; detailPending = undefined; } }
}
onBeforeUnmount(() => { detailGeneration++; pending?.abort(); detailPending?.abort(); });
</script>
<template>
  <Page auto-content-height title="设备分类" description="维护当前客户的设备分类与说明。" header-class="flex-wrap gap-4"><Alert v-if="failure" :message="failure" type="error" class="mb-4" show-icon /><Grid>
    <template #toolbar-tools><Button v-if="canEdit" type="primary" @click="edit(null)">创建分类</Button></template>
    <template #actions="{ row }"><Space><Button type="link" size="small" @click="show(row)">详情</Button><Button v-if="canEdit" type="link" size="small" @click="edit(row)">编辑</Button><Button v-if="canEdit" type="link" size="small" danger @click="remove(row)">删除</Button></Space></template>
  </Grid><Editor><Alert v-if="saveFailure" :message="saveFailure" type="error" class="mb-4" show-icon /><Form /></Editor>
  <Detail :loading="detailBusy"><Alert v-if="detailFailure" :message="detailFailure" type="error" class="mb-4" show-icon /><Descriptions class="break-all" v-if="detail" :column="1" bordered><DescriptionsItem label="分类名称">{{ detail.name }}</DescriptionsItem><DescriptionsItem label="分类标识">{{ detail.id }}</DescriptionsItem><DescriptionsItem label="说明">{{ detail.description || '未填写' }}</DescriptionsItem><DescriptionsItem label="记录版本">{{ detail.version }}</DescriptionsItem></Descriptions></Detail></Page>
</template>
