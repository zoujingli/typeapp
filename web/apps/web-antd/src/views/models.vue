<script setup lang="ts">
import type { TableColumnsType } from 'ant-design-vue';
import type { ModelDefinition, ModelVersion, Page, Product } from '../api';
import type { CrudTableAction } from '../components/crud-table-actions.vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Alert, Button, Card, Empty, Table, Tag, message } from 'ant-design-vue';
import AppDrawer from '../components/app-drawer/app-drawer.vue';
import CrudTableActions from '../components/crud-table-actions.vue';
import DefinitionEditor from '../components/model-definition.vue';
import { buildTableScrollX, estimateVisibleActionColumnWidth } from '../utils/table';
import { ApiError, applyAdminContext, cancelContext, clearAdminAccess, errorText, isCanceled, request, session } from '../api';

const route = useRoute();
const router = useRouter();
const product = ref<Product | null>(null);
const result = ref<Page<ModelVersion>>({ items: [], total: 0, page: 1, per_page: 20 });
const canRead = computed(() => session.realm === 'customer' && session.permissions.includes('customer.products.read'));
const busy = ref(false);
const failure = ref('');
const open = ref(false);
const saving = ref(false);
const saveFailure = ref('');
const fieldFailures = ref<string[]>([]);
const editing = ref<ModelVersion | null>(null);
const readonly = ref(false);
const definition = ref<ModelDefinition>({ properties: [], events: [], commands: [] });
let requestVersion = 0;
let pending: AbortController | null = null;
const canEdit = computed(() => canRead.value && session.permissions.includes('customer.products.manage'));
const endpoint = computed(() => `/customer/tenants/${session.tenant?.id}/products/${route.params.product}`);
const columns = computed<TableColumnsType<ModelVersion>>(() => [
  { title: '模型版本', dataIndex: 'model_version', width: 110 }, { title: '版本状态', key: 'status', width: 120 },
  { title: '模型内容', key: 'counts', width: 250 }, { title: '创建时间', key: 'created', width: 200 }, { title: '发布时间', key: 'published', width: 200 },
  { title: '操作', key: 'actions', fixed: 'right', width: estimateVisibleActionColumnWidth([{ label: '详情' }, { label: '编辑', visible: canEdit.value }, { label: '复制草稿', visible: canEdit.value }, { label: '发布', visible: canEdit.value }, { label: '删除', visible: canEdit.value }]) },
]);
const pagination = computed(() => ({ current: result.value.page, pageSize: result.value.per_page, total: result.value.total, showSizeChanger: true, pageSizeOptions: ['20', '50', '100'] }));
const title = computed(() => readonly.value ? `模型版本 ${editing.value?.model_version} 详情` : editing.value ? `编辑草稿 ${editing.value.model_version}` : '创建模型草稿');
function time(value: number | null) { return value === null ? '尚未发布' : new Date(Number(value) * 1000).toLocaleString(); }
function reject(error: unknown) { if (clearAdminAccess(error)) { result.value.items = []; result.value.total = 0; product.value = null; open.value = false; editing.value = null; definition.value = { properties: [], events: [], commands: [] }; } }
async function load(page = 1, perPage = result.value.per_page) {
  const tenant = session.tenant?.id;
  if (!tenant || !canRead.value) return;
  const version = ++requestVersion;
  pending?.abort(); pending = new AbortController();
  busy.value = true; failure.value = '';
  try {
    const [item, data] = await Promise.all([
      request<Product>(endpoint.value, { tenant, signal: pending.signal }),
      request<Page<ModelVersion> & { context: { permissions: string[]; menus: typeof session.menus } }>(`${endpoint.value}/models`, { tenant, signal: pending.signal, params: { page, per_page: perPage } }),
    ]);
    if (version !== requestVersion) return;
    product.value = item; result.value = data; applyAdminContext(data.context);
  } catch (error) { if (!isCanceled(error) && version === requestVersion) { result.value.items = [];  failure.value = errorText(error); reject(error); } }
  finally { if (version === requestVersion) busy.value = false; }
}
function edit(model: ModelVersion | null, mode: 'edit' | 'copy' | 'detail') {
  if (mode !== 'detail' && !canEdit.value) return;
  readonly.value = mode === 'detail'; editing.value = mode === 'copy' ? null : model;
  definition.value = model ? JSON.parse(JSON.stringify(model.definition)) as ModelDefinition : { properties: [], events: [], commands: [] };
  saveFailure.value = ''; fieldFailures.value = []; open.value = true;
}
function validationErrors(error: ApiError) {
  const names: Record<string, string> = { definition: '物模型', properties: '属性', events: '事件', commands: '指令', parameters: '参数', identifier: '标识', name: '名称', type: '类型', required: '必填设置', min: '最小值', max: '最大值', unit: '单位', values: '枚举值', min_length: '最少字节', max_length: '最多字节' };
  const reasons: Record<string, string> = { invalid_identifier: '须以字母开头，仅包含字母、数字、下划线，最长64位', invalid_text: '文本为空、超长或包含非法字符', invalid_range: '最小值不能超过最大值', invalid_enum: '请输入1至100个枚举值', duplicate_enum_value: '枚举值不能重复', duplicate_identifier: '同一组中的标识不能重复', invalid_numeric_bound: '数值约束不合法', invalid_length: '字节数须为0至4096的整数', too_large: '完整模型不得超过16 KiB', bounded_list_required: '条目数超出限制', unknown_field: '存在不支持的字段' };
  return Object.entries(error.fields).map(([path, codes]) => `${path.split('.').map((part) => /^\d+$/.test(part) ? `第${Number(part) + 1}项` : names[part] || part).join(' · ')}：${codes.map((code) => reasons[code] || '输入格式不符合要求').join('；')}`);
}
async function save() {
  const tenant = session.tenant?.id;
  if (saving.value || !tenant || !canEdit.value || readonly.value) return;
  saving.value = true; saveFailure.value = ''; fieldFailures.value = [];
  try {
    await request(`${endpoint.value}/models${editing.value ? `/${editing.value.model_version}` : ''}`, { tenant, method: editing.value ? 'PATCH' : 'POST', data: { definition: definition.value, ...(editing.value ? { version: editing.value.version } : {}) } });
    open.value = false; message.success('模型草稿已保存'); await load(result.value.page);
  } catch (error) {
    if (!isCanceled(error)) { saveFailure.value = errorText(error); reject(error); }
    if (error instanceof ApiError) {
      fieldFailures.value = validationErrors(error);
      if (error.code === 'stale_version' || error.code === 'model_immutable') await load(result.value.page);
    }
  } finally { saving.value = false; }
}
async function change(model: ModelVersion, action: 'publish' | 'delete') {
  const tenant = session.tenant?.id;
  if (!tenant || !canEdit.value) return;
  try {
    await request(`${endpoint.value}/models/${model.model_version}${action === 'publish' ? '/publish' : ''}`, { tenant, method: action === 'publish' ? 'POST' : 'DELETE', data: { version: model.version } });
    message.success(action === 'publish' ? '模型已发布，定义已冻结' : '模型草稿已删除'); await load(action === 'delete' && result.value.items.length === 1 ? Math.max(1, result.value.page - 1) : result.value.page);
  } catch (error) { if (!isCanceled(error)) { failure.value = errorText(error); reject(error); message.error(failure.value); } }
}
function actions(model: ModelVersion): CrudTableAction[] { return [
  { label: '详情', onClick: () => edit(model, 'detail') },
  { label: '编辑', visible: canEdit.value && model.status === 'draft', onClick: () => edit(model, 'edit') },
  { label: '复制草稿', visible: canEdit.value, onClick: () => edit(model, 'copy') },
  { label: '发布', visible: canEdit.value && model.status === 'draft', confirmTitle: `确认发布模型版本 ${model.model_version}？`, confirmContent: '发布后的定义不可修改或删除。后续调整需建立新的版本。', confirmOkText: '发布版本', onClick: () => change(model, 'publish') },
  { label: '删除', danger: true, visible: canEdit.value && model.status === 'draft', confirmTitle: `确认删除草稿 ${model.model_version}？`, confirmContent: '仅删除此未发布草稿，版本编号不会重新使用。', confirmOkText: '删除草稿', onClick: () => change(model, 'delete') },
]; }
watch([() => session.tenant?.id, () => session.token, () => route.params.product], ([tenant]) => {
  requestVersion++; pending?.abort(); cancelContext(); product.value = null; result.value.items = [];  open.value = false;
  result.value.total = 0; failure.value = ''; saveFailure.value = '';
  if (!tenant) void router.replace('/tenants'); else if (canRead.value) void load(); else failure.value = '当前没有产品与物模型查看权限。';
}, { immediate: true });
onBeforeUnmount(() => { requestVersion++; pending?.abort(); });
</script>

<template>
  <section class="iot-page">
    <header class="page-heading"><div><h1>物模型版本</h1><p class="muted tenant-title">{{ product?.name || '正在读取产品' }} · 已发布版本保持原有含义</p></div><div class="toolbar-actions"><Button @click="router.push('/products')">返回产品</Button><Button v-if="canEdit" type="primary" @click="edit(null, 'edit')">创建草稿</Button></div></header>
    <Card :bordered="false">
      <Alert v-if="failure" :message="failure" type="error" show-icon class="page-alert" role="alert" />
      <Alert v-else-if="canRead && !canEdit" message="当前角色可查看产品及历史物模型，无法编辑或发布版本。" type="info" show-icon class="page-alert" />
      <div class="table-toolbar"><span class="muted">{{ result.total }} 个版本 · 按版本编号倒序</span><Button :loading="busy" :disabled="!canRead" @click="load(result.page)">刷新</Button></div>
      <Table :columns="columns" :data-source="result.items" row-key="model_version" :loading="busy" :scroll="buildTableScrollX(columns)" :pagination="pagination" @change="(page) => load(page.current, page.pageSize)">
        <template #emptyText><Empty :description="failure ? '数据暂不可用，请重试' : '此产品尚无物模型版本'" /></template>
        <template #bodyCell="{ column, record }">
          <Tag v-if="column.key === 'status'" :color="record.status === 'published' ? 'green' : undefined">{{ record.status === 'published' ? '已发布' : '草稿' }}</Tag>
          <template v-else-if="column.key === 'counts'">{{ record.definition.properties.length }} 属性 · {{ record.definition.events.length }} 事件 · {{ record.definition.commands.length }} 指令</template>
          <template v-else-if="column.key === 'created'">{{ time(record.created_at) }}</template><template v-else-if="column.key === 'published'">{{ time(record.published_at) }}</template>
          <CrudTableActions v-else-if="column.key === 'actions'" :actions="actions(record as ModelVersion)" />
        </template>
      </Table>
    </Card>
    <AppDrawer v-model:open="open" :title="title" width-size="lg" :show-footer="!readonly" :confirm-loading="saving" :ok-disabled="saving || !canEdit" :closable="!saving" @ok="save">
      <Alert v-if="saveFailure" :message="saveFailure" type="error" show-icon class="page-alert" role="alert"><template v-if="fieldFailures.length" #description><ul><li v-for="item in fieldFailures" :key="item">{{ item }}</li></ul></template></Alert>
      <p v-if="!readonly" class="muted">定义属性、事件和指令参数。字符串长度按 UTF-8 字节计算；保存草稿不会向设备发送指令。</p>
      <DefinitionEditor v-model="definition" :disabled="readonly || saving" />
    </AppDrawer>
  </section>
</template>
