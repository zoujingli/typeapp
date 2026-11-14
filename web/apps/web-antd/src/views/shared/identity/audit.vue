<script setup lang="ts">
import { displayDateTime } from '../../../display';
import type { Audit, BrokerAuditDetail, BrokerAuditPage } from '../../../api/contracts';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { Alert, Button, Descriptions, DescriptionsItem, Select, Tag } from 'ant-design-vue';
import { Page, useVbenDrawer } from '@vben/common-ui';
import type { VbenFormProps } from '../../../adapter/form';
import { useVbenVxeGrid } from '../../../adapter/vxe-table';
import { errorText, isCanceled, request, session } from '../../../api/identity';
const route = useRoute();
const broker = computed(() => route.meta.auditScope === 'mqtt');
const platform = computed(() => session.realm === 'platform');
const endpoint = computed(() => `/${session.realm}${broker.value ? '/broker' : ''}/audit`);
const title = computed(() => broker.value ? 'MQTT 操作审计' : '操作审计');
const failure = ref(''); const busy = ref(false); const detailBusy = ref(false); const detailFailure = ref(''); const detail = ref<BrokerAuditDetail['item']>(); const result = ref<BrokerAuditPage>();
const page = ref(0); const limit = ref(20); const cursors = ref<(string | null)[]>([null]);
let pending: AbortController | undefined; let detailPending: AbortController | undefined; let generation = 0; let detailGeneration = 0; let applied: Record<string, unknown> = {};
const outcomes: Record<string, { label: string; color: string }> = {
  pending: { label: '待确定', color: 'processing' }, success: { label: '成功', color: 'green' }, denied: { label: '拒绝', color: 'orange' },
  unknown: { label: '结果未知', color: 'gold' }, failed: { label: '失败', color: 'red' },
};
const stages: Record<string, string> = { accepted: '已受理', executing: '执行中', unknown: '结果未知', completed: '已完成', failed: '已失败' };
const labels: Record<string, string> = {
  version: '记录版本', context: '身份上下文', reason: '结果原因', changed_fields: '变更字段', facts: '阶段事实',
  source: '授权来源', permissions: '获准动作', required_action: '所需动作', decision: '授权结论',
  kind: '目标类型', node_id: '节点标识', node_run_id: '节点运行', observation_run: '观察运行', generation: '目标代次', confirmed: '影响已确认', effect: '影响说明', target_count: '目标数量', proof_hash: '依据摘要',
  store_confirmed: '持久存储已确认', resources_released: '执行资源已释放', observation_isolated: '节点观察已隔离',
  actor_realm: '真实人员账号域', tenant_id: '有效客户', customer_id: '有效账号', session_id: '执行会话', source_session_id: '来源平台会话', impersonation_id: '模拟来源', scope_key: '身份范围摘要',
};
const time = (value: number) => displayDateTime(value * 1000);
function display(value: unknown): string {
  if (value === null || value === undefined || value === '') return '未记录';
  if (typeof value === 'boolean') return value ? '是' : '否';
  if (Array.isArray(value)) return value.map(display).join('、') || '无';
  if (typeof value === 'object') return Object.entries(value).map(([key, item]) => `${labels[key] || key}：${display(item)}`).join('\n');
  return String(value);
}
const formOptions: VbenFormProps = { handleSubmit: async values => {
  const { from, to, ...fields } = values;
  const start = from ? Math.floor(Date.parse(String(from)) / 1000) : undefined;
  const end = to ? Math.floor(Date.parse(String(to)) / 1000) : undefined;
  if (start !== undefined && !Number.isFinite(start) || end !== undefined && !Number.isFinite(end) || start !== undefined && end !== undefined && start > end) { failure.value = '请选择有效的起止时间。'; return; }
  applied = { ...fields, from: start, to: end }; cursors.value = [null]; await load(0);
}, handleReset: async () => { await grid.formApi.resetForm(); applied = {}; cursors.value = [null]; await load(0); }, schema: [
  { fieldName: 'actor_id', label: '真实操作人员', component: 'Input' }, { fieldName: 'subject_id', label: '业务主体', component: 'Input' },
  { fieldName: 'action', label: '操作类型', component: 'Input' },
  { fieldName: 'result', label: '执行结果', component: 'Select', componentProps: { allowClear: true, options: Object.entries(outcomes).map(([value, item]) => ({ value, label: item.label })) } },
  { fieldName: 'from', label: '开始时间', component: 'Input', componentProps: { type: 'datetime-local' } },
  { fieldName: 'to', label: '结束时间', component: 'Input', componentProps: { type: 'datetime-local' } },
  ...(broker.value ? [{ fieldName: 'operation_id', label: '操作标识', component: 'Input' as const }, { fieldName: 'stage', label: '事件阶段', component: 'Select' as const, componentProps: { allowClear: true, options: Object.entries(stages).map(([value, label]) => ({ value, label })) } }] : []),
] };
const [Grid, grid] = useVbenVxeGrid<Audit>({ formOptions, gridOptions: { columns: [
  { field: 'created_at', title: '发生时间', width: 185, formatter: ({ cellValue }: { cellValue: number }) => time(Number(cellValue)) },
  { field: 'action', title: '操作类型', minWidth: 200 }, { field: 'actor_id', title: '真实操作人员', minWidth: 260 },
  { field: 'tenant_id', title: '所属客户', minWidth: 260 }, { field: 'subject_id', title: '业务主体', minWidth: 260 },
  ...(broker.value ? [{ field: 'operation_id', title: '操作标识', minWidth: 260 }, { field: 'stage', title: '事件阶段', width: 120, formatter: ({ cellValue }: { cellValue: string }) => stages[cellValue] || cellValue }] : []),
  { field: 'result', title: '执行结果', width: 100, slots: { default: 'result' } },
  { title: '操作', width: 80, fixed: 'right', slots: { default: 'actions' } },
], pagerConfig: { enabled: false }, rowConfig: { keyField: 'id' } } });
const [Detail, drawer] = useVbenDrawer({ title: '审计详情', footer: false, class: 'w-full max-w-3xl', onClosed: () => { detailGeneration++; detailPending?.abort(); detailPending = undefined; detailBusy.value = false; detail.value = undefined; } });
async function load(target = page.value) {
  const current = ++generation; pending?.abort(); pending = new AbortController(); busy.value = true; failure.value = ''; grid.setLoading(true);
  try {
    const data = await request<BrokerAuditPage>(endpoint.value, { signal: pending.signal, params: { ...applied, limit: limit.value, cursor: cursors.value[target] || undefined } });
    if (current !== generation) return;
    result.value = data; page.value = target; cursors.value[target + 1] = data.next_cursor; grid.setGridOptions({ data: data.items });
  } catch (error) { if (!isCanceled(error) && current === generation) { failure.value = errorText(error); result.value = undefined; grid.setGridOptions({ data: [] }); } }
  finally { if (current === generation) { busy.value = false; grid.setLoading(false); } }
}
async function show(item: Audit) {
  const current = ++detailGeneration; detailPending?.abort(); detailPending = new AbortController(); detailBusy.value = true; detailFailure.value = ''; detail.value = undefined; drawer.open();
  try {
    const data = await request<BrokerAuditDetail>(`${endpoint.value}/${platform.value ? `${item.audit_realm}/` : ''}${item.id}`, { signal: detailPending.signal });
    if (current === detailGeneration) detail.value = data.item;
  } catch (error) { if (!isCanceled(error) && current === detailGeneration) detailFailure.value = errorText(error); }
  finally { if (current === detailGeneration) { detailBusy.value = false; detailPending = undefined; } }
}
onMounted(() => load(0)); onBeforeUnmount(() => { generation++; detailGeneration++; pending?.abort(); detailPending?.abort(); });
</script>
<template>
  <Page auto-content-height :title="title" description="按身份、业务主体和结果核对可追溯操作记录。" header-class="flex-wrap gap-4">
    <Alert v-if="failure" :message="failure" type="error" show-icon class="mb-4" />
    <Grid table-title-help="时间按当前账号时区显示；审计保留真实操作者、目标账号与来源会话。" :form-options="{ ...formOptions, commonConfig: { disabled: busy } }">
      <template #toolbar-tools><Button :loading="busy" @click="load()">刷新</Button></template>
      <template #result="{ row }"><Tag :color="outcomes[row.result]?.color">{{ outcomes[row.result]?.label || row.result }}</Tag></template>
      <template #actions="{ row }"><Button type="link" size="small" @click="show(row)">详情</Button></template>
      <template #pager>
        <div class="flex flex-wrap items-center justify-between gap-3 px-3 py-4 text-sm">
          <span class="text-muted-foreground">第 {{ page + 1 }} 页 · 本页 {{ result?.items.length || 0 }} 项</span>
          <div class="flex flex-wrap items-center gap-2">
            <Select v-model:value="limit" aria-label="每页条数" :disabled="busy" :options="[20, 50, 100].map(value => ({ value, label: `${value} 条 / 页` }))" @change="() => { cursors = [null]; load(0); }" />
            <Button :disabled="busy || page === 0" @click="load(page - 1)">上一页</Button><Button :disabled="busy || !result?.has_more" @click="load(page + 1)">下一页</Button>
          </div>
        </div>
      </template>
    </Grid>
    <Detail :loading="detailBusy"><Alert v-if="detailFailure" :message="detailFailure" type="error" show-icon class="mb-4" /><Descriptions class="break-all" v-if="detail" :column="1" bordered><DescriptionsItem label="发生时间">{{ time(detail.created_at) }}</DescriptionsItem><DescriptionsItem label="操作类型">{{ detail.action }}</DescriptionsItem><DescriptionsItem label="真实操作人员">{{ detail.actor_id }}</DescriptionsItem><DescriptionsItem label="业务主体">{{ detail.subject_id }}</DescriptionsItem><DescriptionsItem label="所属客户">{{ detail.tenant_id || '平台' }}</DescriptionsItem><DescriptionsItem label="审计来源">{{ detail.audit_realm === 'platform' ? '平台' : '客户系统' }}</DescriptionsItem><DescriptionsItem label="执行结果">{{ outcomes[detail.result]?.label || detail.result }}</DescriptionsItem><DescriptionsItem v-if="detail.operation_id" label="操作标识">{{ detail.operation_id }}</DescriptionsItem><DescriptionsItem v-if="detail.request_id" label="请求标识">{{ detail.request_id }}</DescriptionsItem><DescriptionsItem v-if="detail.stage" label="事件阶段">{{ stages[detail.stage] || detail.stage }}</DescriptionsItem><DescriptionsItem v-for="(value, key) in detail.details" :key="key" :label="labels[key] || key"><span class="whitespace-pre-wrap break-all">{{ display(value) }}</span></DescriptionsItem><DescriptionsItem v-if="detail.operation" label="当前操作状态"><span class="whitespace-pre-wrap break-all">{{ display(detail.operation) }}</span></DescriptionsItem></Descriptions></Detail>
  </Page>
</template>
