<script setup lang="ts">
import { displayDateTime } from '../../display';
import type { Page as PageResult } from '../../api/contracts';
import { computed, onBeforeUnmount, ref } from 'vue';
import { Alert, Button, Descriptions, DescriptionsItem, Input, Modal, Space, Tag, message } from 'ant-design-vue';
import { Page, useVbenDrawer, useVbenModal } from '@vben/common-ui';
import { useVbenForm, z } from '../../adapter/form';
import { useVbenVxeGrid } from '../../adapter/vxe-table';
import { applyAccessContext, errorText, isCanceled, isDemo, request, session } from '../../api/identity';
interface Enrollment {
  id: string; tenant_id: string; name: string; capacity: number; registered_count: number; remaining: number;
  register_expires_at: number; enabled: boolean; recovery_verified: boolean; status: 'available' | 'expired' | 'exhausted' | 'disabled' | 'unverified'; version: number;
}
interface Binding { device_id: string; ownership_id: string; registered_at: number }
const platform = computed(() => session.realm === 'platform');
const endpoint = computed(() => platform.value ? '/platform/enrollments' : '/center/enrollments');
const can = (action: string) => platform.value && session.permissions.includes(`platform.enrollments.${action}`) && !isDemo();
const failure = ref(''); const saveFailure = ref(''); const detailFailure = ref(''); const secret = ref(''); const secretId = ref('');
const selected = ref<Enrollment>(); const detailBusy = ref(false); let requestId = ''; let pending: AbortController | undefined; let detailGeneration = 0;
const statuses = { available: '可注册', expired: '注册期限已过', exhausted: '额度已用完', disabled: '已停用', unverified: '待恢复核对' };
const [Form, form] = useVbenForm({ showDefaultActions: false, layout: 'vertical', commonConfig: { componentProps: { class: 'w-full' } }, schema: [
  { fieldName: 'tenant_id', label: '客户标识', component: 'Input', rules: z.string().regex(/^[a-f0-9]{32}$/, '请输入完整客户标识') },
  { fieldName: 'name', label: '资格名称', component: 'Input', rules: z.string().trim().min(1).max(100) },
  { fieldName: 'capacity', label: '可注册设备数', component: 'InputNumber', defaultValue: 1, rules: z.number().int().min(1).max(2147483646) },
  { fieldName: 'register_expires_at', label: '注册截止（本地时区）', component: 'Input', componentProps: { type: 'datetime-local' }, rules: z.string().min(1) },
] });
const [Editor, editor] = useVbenModal({ title: '签发设备注册资格', onConfirm: save, onClosed: () => form.resetForm() });
const [Secret, secretModal] = useVbenModal({ title: '保存一次性注册码', footer: false, onOpenChange(open) { if (!open) { secret.value = ''; secretId.value = ''; } } });
const [Detail, detailDrawer] = useVbenDrawer({ title: '注册资格详情', footer: false, class: 'w-full max-w-3xl', onOpened: async () => { detailBusy.value = true; try { await bindings.reload(); } finally { detailBusy.value = false; } }, onClosed() { detailGeneration++; selected.value = undefined; } });
const [Grid, grid] = useVbenVxeGrid<Enrollment>({ ...(platform.value ? { formOptions: { schema: [{ fieldName: 'tenant_id', label: '客户标识', component: 'Input' as const }] } } : {}), gridOptions: {
  columns: [{ field: 'name', title: '资格名称', minWidth: 200 }, ...(platform.value ? [{ field: 'tenant_id', title: '客户标识', minWidth: 280 }] : []),
    { title: '注册额度', width: 160, slots: { default: 'capacity' } }, { field: 'register_expires_at', title: '注册截止', width: 185, formatter: ({ cellValue }: { cellValue: string | number }) => displayDateTime(Number(cellValue) * 1000) },
    { field: 'status', title: '状态', width: 140, slots: { default: 'status' } }, { title: '操作', fixed: 'right', width: platform.value ? 250 : 80, slots: { default: 'actions' } }],
  rowConfig: { keyField: 'id' }, pagerConfig: { pageSize: 20, pageSizes: [20, 50, 100] }, toolbarConfig: { refresh: true, search: platform.value },
  proxyConfig: { ajax: { query: async ({ page }: { page: { currentPage: number; pageSize: number } }, values: Record<string, unknown>) => {
    pending?.abort(); pending = new AbortController(); failure.value = '';
    try { const data = await request<PageResult<Enrollment> & { context: { permissions: string[]; menus: typeof session.menus } }>(endpoint.value, { signal: pending.signal, params: { ...values, page: page.currentPage, per_page: page.pageSize } }); applyAccessContext(data.context); return data; }
    catch (error) { if (!isCanceled(error)) failure.value = errorText(error); throw error; }
  } } },
} });
const [Bindings, bindings] = useVbenVxeGrid<Binding>({ gridOptions: {
  columns: [{ field: 'device_id', title: '设备标识', minWidth: 280 }, { field: 'ownership_id', title: '使用关系', minWidth: 280 }, { field: 'registered_at', title: '注册时间', width: 185, formatter: ({ cellValue }: { cellValue: string | number }) => displayDateTime(Number(cellValue) * 1000) }],
  rowConfig: { keyField: 'ownership_id' }, pagerConfig: { pageSize: 20, pageSizes: [20, 50, 100] },
  proxyConfig: { autoLoad: false, ajax: { query: async ({ page }: { page: { currentPage: number; pageSize: number } }) => {
    const id = selected.value?.id; if (!id) return { items: [], total: 0 };
    const current = ++detailGeneration; detailFailure.value = '';
    try {
      const data = await request<{ authorization: Enrollment; bindings: PageResult<Binding>; context: { permissions: string[]; menus: typeof session.menus } }>(`${endpoint.value}/${id}`, { params: { page: page.currentPage, per_page: page.pageSize } });
      if (current !== detailGeneration || selected.value?.id !== id) return { items: [], total: 0 };
      selected.value = data.authorization; applyAccessContext(data.context); return data.bindings;
    } catch (error) { if (!isCanceled(error)) detailFailure.value = errorText(error); throw error; }
  } } },
} });
async function issue() { requestId = crypto.randomUUID().replaceAll('-', ''); saveFailure.value = ''; editor.open(); await form.setValues({ tenant_id: '', name: '', capacity: 1, register_expires_at: '' }); }
async function save() {
  if (!can('issue') || !(await form.validate()).valid) return;
  const values = await form.getValues(); const expires = Math.floor(Date.parse(String(values.register_expires_at)) / 1000);
  if (!Number.isFinite(expires) || expires <= Date.now() / 1000) { saveFailure.value = '请选择将来的注册截止时间。'; return; }
  editor.lock(); saveFailure.value = '';
  try {
    const data = await request<{ authorization: Enrollment; code: string | null; replayed: boolean }>(endpoint.value, { method: 'POST', data: { ...values, id: requestId, register_expires_at: expires } });
    editor.close(); if (data.code) { secret.value = data.code; secretId.value = data.authorization.id; secretModal.open(); }
    message.success(data.replayed ? '原资格已签发，注册码不重复展示；遗失请重置。' : '注册资格已签发'); await grid.query();
  } catch (error) { if (!isCanceled(error)) saveFailure.value = errorText(error); }
  finally { editor.unlock(); }
}
function change(row: Enrollment, action: 'reset' | 'disable') {
  Modal.confirm({ title: action === 'reset' ? '重置注册码？' : '停用注册资格？', content: action === 'reset' ? '旧码立即失效，已用额度与截止时间保持。' : '禁止新注册且不能重新启用。已注册设备需在设备管理中单独撤权。', async onOk() {
    try {
      const data = await request<{ code: string | null }>(`${endpoint.value}/${row.id}/${action}`, { method: 'POST', data: { tenant_id: row.tenant_id, version: row.version } });
      if (data.code) { secret.value = data.code; secretId.value = row.id; secretModal.open(); }
      message.success('注册资格已更新'); await grid.query();
    } catch (error) { message.error(errorText(error)); throw error; }
  } });
}
function show(row: Enrollment) { selected.value = row; detailFailure.value = ''; detailDrawer.open(); }
onBeforeUnmount(() => { pending?.abort(); detailGeneration++; secret.value = ''; });
</script>
<template>
  <Page auto-content-height><Alert v-if="failure" :message="failure" type="error" class="mb-4" show-icon /><Grid table-title="设备注册资格">
    <template #toolbar-tools><Button v-if="can('issue')" type="primary" @click="issue">签发资格</Button></template>
    <template #capacity="{ row }">{{ row.registered_count }} / {{ row.capacity }} 已注册</template><template #status="{ row }"><Tag>{{ statuses[row.status as Enrollment['status']] }}</Tag></template>
    <template #actions="{ row }"><Space><Button type="link" size="small" @click="show(row)">详情</Button><Button v-if="can('reset')" type="link" size="small" :disabled="row.status !== 'available'" @click="change(row, 'reset')">重置注册码</Button><Button v-if="can('disable')" type="link" size="small" danger :disabled="!row.enabled" @click="change(row, 'disable')">停用</Button></Space></template>
  </Grid><Editor><Alert v-if="saveFailure" :message="saveFailure" type="error" class="mb-4" show-icon /><Form /></Editor>
  <Secret><Alert type="warning" show-icon class="mb-4" message="关闭后无法再次查看，遗失时请重置。" /><p>资格标识：{{ secretId }}</p><Input.TextArea :value="secret" readonly :rows="4" aria-label="一次性注册码" /></Secret>
  <Detail :loading="detailBusy"><Descriptions v-if="selected" :column="1" bordered class="break-all mb-4"><DescriptionsItem label="资格标识">{{ selected.id }}</DescriptionsItem><DescriptionsItem label="客户标识">{{ selected.tenant_id }}</DescriptionsItem><DescriptionsItem label="可用额度">{{ selected.remaining }}</DescriptionsItem><DescriptionsItem label="已注册数量">{{ selected.registered_count }}</DescriptionsItem><DescriptionsItem label="状态">{{ statuses[selected.status] }}</DescriptionsItem></Descriptions><Alert v-if="detailFailure" :message="detailFailure" type="error" class="mb-4" show-icon /><Bindings table-title="设备注册记录" /></Detail>
  </Page>
</template>
