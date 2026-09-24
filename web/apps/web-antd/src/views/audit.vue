<script setup lang="ts">
import type { TableColumnsType } from 'ant-design-vue';
import type { Audit, BrokerAuditDetail, BrokerAuditOperation, BrokerAuditPage } from '../api';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Alert, Button, Card, Descriptions, DescriptionsItem, Empty, Input, Select, Table, Tag } from 'ant-design-vue';
import AppDrawer from '../components/app-drawer/app-drawer.vue';
import CrudSearchField from '../components/crud-search-field.vue';
import CrudTableActions from '../components/crud-table-actions.vue';
import { buildTableScrollX, estimateVisibleActionColumnWidth } from '../utils/table';
import { ApiError, errorText, isCanceled, request, session } from '../api';

const route = useRoute();
const router = useRouter();
const standalone = computed(() => route.meta.auditScope === 'standalone');
const broker = computed(() => standalone.value || route.meta.auditScope === 'application-broker');
const platform = computed(() => session.realm === 'admin');
const tenant = computed(() => !standalone.value && !platform.value ? session.tenant?.id : undefined);
const canRead = computed(() => standalone.value ? !!session.user?.platform_admin : session.permissions.includes(`${session.realm}.audit.read`)
  && (!broker.value || session.permissions.includes(`${session.realm}.broker.read`)));
const endpoint = computed(() => standalone.value ? '/broker/audit' : `${platform.value ? '/admin' : `/customer/tenants/${tenant.value}`}${broker.value ? '/broker' : ''}/audit`);
const title = computed(() => broker.value ? 'Broker 审计' : platform.value ? '平台操作审计' : '操作审计');
const brokerResult = ref<BrokerAuditPage | null>(null);
const rows = computed(() => brokerResult.value?.items || []);
const filters = reactive({ operation_id: '', stage: '', actor_id: '', subject_id: '', action: '', result: '', from: '', to: '' });
const applied = ref<Record<string, string | number>>({});
const limit = ref(20);
const page = ref(0);
const cursors = ref<(string | null)[]>([null]);
const busy = ref(false);
const detailBusy = ref(false);
const blocked = computed(() => busy.value || detailBusy.value || !canRead.value);
const failure = ref('');
const detail = ref<(Audit & { operation?: BrokerAuditOperation | null }) | null>(null);
let requestVersion = 0;
let pending: AbortController | null = null;
const outcomes: Record<string, { label: string; color: string }> = {
  pending: { label: '待确定', color: 'processing' }, success: { label: '成功', color: 'green' }, denied: { label: '拒绝', color: 'orange' },
  unknown: { label: '结果未知', color: 'gold' }, failed: { label: '失败', color: 'red' },
};
const stages: Record<string, string> = { accepted: '已受理', executing: '执行中', unknown: '结果未知', completed: '已完成', failed: '已失败' };
const actions: Record<string, string> = {
  'tenant.created': '创建租户', 'member.added': '添加成员', 'member.changed': '变更成员', 'member.removed': '移除成员',
  'product.created': '创建产品', 'product.changed': '修改产品', 'product.deleted': '删除产品', 'product.listed': '查询产品', 'product.viewed': '查看产品',
  'model.created': '创建模型草稿', 'model.edit': '编辑模型草稿', 'model.delete': '删除模型草稿', 'model.publish': '发布模型', 'model.listed': '查询模型版本', 'model.viewed': '查看模型版本', 'model.validated': '校验模型数据',
};
const contexts: Record<string, string> = { 'customer-impersonation': '模拟客户登录', 'tenant-member': '租户成员', platform: '平台管理', identity: '人员身份', 'operator-command': '受控开通', 'product-model': '产品与物模型' };
const labels: Record<string, string> = {
  role: '成员角色', previous_role: '原有角色', version: '记录版本', context: '身份上下文', reason: '结果原因', changed_fields: '变更字段', facts: '阶段事实',
  source: '授权来源', permissions: '获准动作', required_action: '所需动作', decision: '授权结论', support_id: '支持标识', support_version: '支持版本', support_expires_at: '支持到期',
  kind: '目标类型', node_id: '节点标识', node_run_id: '节点运行', observation_run: '观察运行', generation: '目标代次', confirmed: '影响已确认', effect: '影响说明', target_count: '目标数量', proof_hash: '依据摘要',
  store_confirmed: '持久存储已确认', resources_released: '执行资源已释放', observation_isolated: '节点观察已隔离',
  actor_realm: '真实人员账号域', customer_id: '有效客户', session_id: '执行会话', source_session_id: '来源管理会话', impersonation_id: '模拟来源', scope_key: '身份范围摘要',
};
const columns = computed<TableColumnsType<Audit>>(() => [
  { title: '发生时间', key: 'time', width: 200 }, { title: '操作类型', key: 'action', width: 190, ellipsis: true },
  ...(broker.value ? [{ title: '操作标识', dataIndex: 'operation_id', width: 280, ellipsis: true }, { title: '事件阶段', key: 'stage', width: 120 }] : []),
  { title: '真实操作人员', dataIndex: 'actor_id', width: 280, ellipsis: true },
  ...(!standalone.value ? [{ title: '有效客户 / 模拟来源', key: 'identity', width: 290 }, { title: '所属租户', dataIndex: 'tenant_id', width: 280, ellipsis: true }] : []),
  { title: '业务主体', dataIndex: 'subject_id', width: 280, ellipsis: true },
  { title: broker.value ? '事件结果' : '执行结果', key: 'result', width: 110 },
  { title: '操作', key: 'actions', fixed: 'right', width: estimateVisibleActionColumnWidth([{ label: '详情' }]) },
]);
function timeLabel(value: number) { return new Date(Number(value) * 1000).toLocaleString(); }
function display(value: unknown, key = ''): string {
  if (value === null || value === undefined || value === '') return '未记录';
  if (typeof value === 'boolean') return value ? '是' : '否';
  if (key === 'context') return contexts[String(value)] || String(value);
  if (key.endsWith('_at') && typeof value === 'number') return timeLabel(value);
  if (Array.isArray(value)) return value.length ? value.map(item => display(item)).join('、') : '无';
  if (typeof value === 'object') return Object.entries(value).map(([name, item]) => `${labels[name] || name}：${display(item, name)}`).join('\n');
  return String(value);
}
function cancel() { requestVersion++; pending?.abort(); pending = null; busy.value = false; detailBusy.value = false; }
function clearResults() { brokerResult.value = null; detail.value = null; page.value = 0; cursors.value = [null]; }
function resetFilters() { Object.assign(filters, { operation_id: '', stage: '', actor_id: '', subject_id: '', action: '', result: '', from: '', to: '' }); applied.value = {}; }
async function load(nextPage = page.value, cursor = cursors.value[nextPage] ?? null) {
  if (blocked.value || !standalone.value && !platform.value && !tenant.value) return;
  const version = ++requestVersion;
  pending = new AbortController(); busy.value = true; failure.value = '';
  try {
    const data = await request<BrokerAuditPage>(endpoint.value, { tenant: tenant.value, signal: pending.signal, params: { ...applied.value, limit: limit.value, cursor: cursor || undefined } });
    if (version === requestVersion) { brokerResult.value = data; page.value = nextPage; cursors.value[nextPage] = cursor; }
  } catch (error) {
    if (!isCanceled(error) && version === requestVersion) { failure.value = errorText(error); if (error instanceof ApiError && error.status === 403) clearResults(); }
  } finally { if (version === requestVersion) { busy.value = false; pending = null; } }
}
async function show(item: Audit) {
  if (blocked.value) return;
  const version = ++requestVersion;
  pending = new AbortController(); detailBusy.value = true; detail.value = null; failure.value = '';
  try {
    const source = platform.value ? `${item.audit_realm}/` : '';
    const data = await request<BrokerAuditDetail>(`${endpoint.value}/${source}${encodeURIComponent(item.id)}`, { tenant: tenant.value, signal: pending.signal });
    if (version === requestVersion) detail.value = data.item;
  } catch (error) {
    if (!isCanceled(error) && version === requestVersion) { failure.value = errorText(error); if (error instanceof ApiError && error.status === 403) clearResults(); }
  } finally { if (version === requestVersion) { detailBusy.value = false; pending = null; } }
}
function search(reset = false) {
  if (blocked.value) return;
  if (reset) resetFilters();
  const from = filters.from ? Math.floor(Date.parse(filters.from) / 1000) : undefined;
  const to = filters.to ? Math.floor(Date.parse(filters.to) / 1000) : undefined;
  if ((from !== undefined && (!Number.isFinite(from) || from < 0)) || (to !== undefined && (!Number.isFinite(to) || to < 0)) || (from !== undefined && to !== undefined && from > to)) {
    failure.value = '请选择有效时间，结束时间不能早于开始时间。'; return;
  }
  applied.value = Object.fromEntries(Object.entries({ actor_id: filters.actor_id.trim(), subject_id: filters.subject_id.trim(), action: filters.action.trim(), result: filters.result,
    ...(broker.value ? { operation_id: filters.operation_id.trim(), stage: filters.stage } : {}), from, to }).filter((entry): entry is [string, string | number] => entry[1] !== '' && entry[1] !== undefined));
  clearResults(); void load(0);
}
function changeLimit() { if (blocked.value) return; clearResults(); void load(0); }
watch(() => [session.generation, session.realm, session.tenant?.id, session.identity?.key, canRead.value, route.fullPath], () => {
  cancel(); clearResults(); resetFilters(); failure.value = ''; limit.value = 20;
  if (!['/audit', '/admin/audit', '/broker/audit', '/broker-audit', '/admin/broker-audit'].includes(route.path)) return;
  if (!standalone.value && !platform.value && !tenant.value) void router.replace('/tenants');
  else void load(0);
}, { immediate: true });
onBeforeUnmount(cancel);
</script>

<template>
  <section class="iot-page">
    <header class="page-heading"><div><h1>{{ title }}</h1><p class="muted tenant-title">{{ tenant ? `${session.tenant?.name} · ` : '' }}时间按当前浏览器时区显示，事件记录保留180天</p></div></header>
    <Alert v-if="failure" :message="failure" type="error" show-icon class="page-alert" role="alert" />
    <Alert v-if="!canRead" message="当前账号无权查看操作审计" type="warning" show-icon class="page-alert" />
    <Card :bordered="false" class="search-card audit-search-card">
      <form class="crud-search-grid" @submit.prevent="search()">
        <CrudSearchField v-if="broker" label="操作标识"><Input v-model:value="filters.operation_id" aria-label="操作标识筛选" allow-clear :maxlength="32" :disabled="blocked" /></CrudSearchField>
        <CrudSearchField v-if="broker" label="事件阶段"><Select v-model:value="filters.stage" aria-label="事件阶段筛选" :disabled="blocked" :options="[{ label: '全部阶段', value: '' }, ...Object.entries(stages).map(([value, label]) => ({ value, label }))]" /></CrudSearchField>
        <CrudSearchField label="操作人员"><Input v-model:value="filters.actor_id" aria-label="操作人员筛选" allow-clear :maxlength="32" :disabled="blocked" placeholder="人员标识" /></CrudSearchField>
        <CrudSearchField label="业务主体"><Input v-model:value="filters.subject_id" aria-label="业务主体筛选" allow-clear :maxlength="100" :disabled="blocked" placeholder="设备、成员等业务标识" /></CrudSearchField>
        <CrudSearchField label="操作类型"><Input v-model:value="filters.action" aria-label="操作类型筛选" allow-clear :maxlength="80" :disabled="blocked" placeholder="例如 member.changed" /></CrudSearchField>
        <CrudSearchField label="执行结果"><Select v-model:value="filters.result" aria-label="执行结果筛选" :disabled="blocked" :options="[{ label: '全部结果', value: '' }, ...Object.entries(outcomes).map(([value, item]) => ({ value, label: item.label }))]" /></CrudSearchField>
        <CrudSearchField label="开始时间"><Input v-model:value="filters.from" type="datetime-local" aria-label="开始时间筛选" :disabled="blocked" /></CrudSearchField>
        <CrudSearchField label="结束时间"><Input v-model:value="filters.to" type="datetime-local" aria-label="结束时间筛选" :disabled="blocked" /></CrudSearchField>
        <div class="crud-search-grid__actions"><Button type="primary" html-type="submit" :loading="busy" :disabled="detailBusy">查询</Button><Button :disabled="blocked" @click="search(true)">重置</Button></div>
      </form>
    </Card>
    <Card :bordered="false">
      <div class="table-toolbar"><span class="muted">本页 {{ rows.length }} 条事件记录{{ detailBusy ? ' · 详情读取中' : '' }}</span><Button :loading="busy" :disabled="detailBusy || !canRead" @click="load()">刷新</Button></div>
      <Table :columns="columns" :data-source="rows" :row-key="(row: Audit) => `${row.audit_realm || 'broker'}:${row.id}`" :loading="busy" :scroll="buildTableScrollX(columns)" :pagination="false">
        <template #emptyText><Empty :description="failure ? '数据暂不可用，请重试' : '暂无符合条件的操作记录'" /></template>
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'time'">{{ timeLabel(record.created_at) }}</template>
          <template v-else-if="column.key === 'action'">{{ actions[record.action] || record.action }}</template>
          <template v-else-if="column.key === 'stage'">{{ stages[record.stage] || display(record.stage) }}</template>
          <template v-else-if="column.key === 'identity'"><span class="audit-value">{{ display(record.details.customer_id) }}</span><br /><Tag v-if="record.details.impersonation_id" color="orange">模拟客户登录</Tag><span v-else class="muted">普通操作</span></template>
          <Tag v-else-if="column.key === 'result'" :color="outcomes[record.result]?.color">{{ outcomes[record.result]?.label || record.result }}</Tag>
          <CrudTableActions v-else-if="column.key === 'actions'" :actions="[{ label: '详情', disabled: blocked, onClick: () => show(record as Audit) }]" />
        </template>
      </Table>
      <div class="table-toolbar audit-pagination">
        <span class="muted">第 {{ page + 1 }} 页 · 本页 {{ rows.length }} 项</span>
        <div class="crud-search-grid__actions"><Select v-model:value="limit" aria-label="每页条数" :disabled="blocked" :options="[20, 50, 100].map(value => ({ value, label: `${value} 条 / 页` }))" @change="changeLimit" />
          <Button :disabled="blocked || page === 0" @click="load(page - 1)">上一页</Button><Button :disabled="blocked || !brokerResult?.has_more" @click="load(page + 1, brokerResult?.next_cursor || null)">下一页</Button></div>
      </div>
    </Card>
    <AppDrawer :open="!!detail" title="审计详情" :ok-visible="false" :show-footer="false" :mask-closable="true" @close="detail = null">
      <template v-if="detail">
        <div class="table-toolbar"><span class="muted">按需重新读取当前授权范围内的详情</span><Button :loading="detailBusy" :disabled="busy || !canRead" @click="show(detail)">刷新详情</Button></div>
        <h3 v-if="broker">阶段事件</h3>
        <Descriptions :column="{ xs: 1, sm: 1, md: 2 }" layout="vertical" bordered size="small">
          <DescriptionsItem label="发生时间">{{ timeLabel(detail.created_at) }}</DescriptionsItem><DescriptionsItem :label="broker ? '事件结果' : '执行结果'">{{ outcomes[detail.result]?.label || detail.result }}</DescriptionsItem>
          <DescriptionsItem v-if="broker" label="事件阶段">{{ stages[detail.stage || ''] || display(detail.stage) }}</DescriptionsItem><DescriptionsItem v-if="broker" label="操作标识"><span class="audit-value">{{ display(detail.operation_id) }}</span></DescriptionsItem>
          <DescriptionsItem v-if="broker" label="请求标识"><span class="audit-value">{{ display(detail.request_id) }}</span></DescriptionsItem>
          <DescriptionsItem label="真实操作人员"><span class="audit-value">{{ detail.actor_id }}</span></DescriptionsItem><DescriptionsItem label="业务主体"><span class="audit-value">{{ detail.subject_id }}</span></DescriptionsItem>
          <DescriptionsItem label="所属租户"><span class="audit-value">{{ display(detail.tenant_id) }}</span></DescriptionsItem>
          <DescriptionsItem v-if="detail.audit_realm" label="审计来源">{{ detail.audit_realm === 'admin' ? '平台管理端' : 'SaaS 用户端' }}</DescriptionsItem>
          <DescriptionsItem label="操作类型"><span class="audit-value">{{ actions[detail.action] || detail.action }}</span></DescriptionsItem><DescriptionsItem v-if="!broker" label="动作标识"><span class="audit-value">{{ detail.action }}</span></DescriptionsItem>
          <DescriptionsItem v-for="(value, key) in detail.details" :key="key" :label="labels[key] || key"><span class="audit-value">{{ display(value, key) }}</span></DescriptionsItem>
        </Descriptions>
        <template v-if="detail.operation">
          <h3 class="audit-section-title">当前操作状态</h3><p class="muted">当前状态来自操作记录；上方阶段事实保留事件发生时的内容。</p>
          <Descriptions :column="{ xs: 1, sm: 1, md: 2 }" layout="vertical" bordered size="small">
            <DescriptionsItem label="当前阶段">{{ stages[detail.operation.current_stage] || detail.operation.current_stage }}</DescriptionsItem><DescriptionsItem label="当前结果">{{ outcomes[detail.operation.current_result]?.label || detail.operation.current_result }}</DescriptionsItem>
            <DescriptionsItem label="记录版本">{{ detail.operation.version }}</DescriptionsItem><DescriptionsItem label="操作模式">{{ detail.operation.mode === 'async' ? '异步操作' : '同步操作' }}</DescriptionsItem>
            <DescriptionsItem label="原始请求"><span class="audit-value">{{ detail.operation.origin_request_id }}</span></DescriptionsItem><DescriptionsItem label="所属租户"><span class="audit-value">{{ display(detail.operation.tenant_id) }}</span></DescriptionsItem>
            <DescriptionsItem label="授权依据" :span="2"><span class="audit-value">{{ display(detail.operation.authorization) }}</span></DescriptionsItem>
            <DescriptionsItem label="目标对象" :span="2"><span class="audit-value">{{ display(detail.operation.target) }}</span></DescriptionsItem>
            <DescriptionsItem label="影响确认" :span="2"><span class="audit-value">{{ display(detail.operation.impact) }}</span></DescriptionsItem>
          </Descriptions>
        </template>
        <Alert v-else-if="broker" class="page-alert" type="info" show-icon message="这条历史记录未保存操作上下文，缺失字段不作推断。" />
      </template>
    </AppDrawer>
  </section>
</template>

<style scoped>
.audit-value { white-space: pre-wrap; overflow-wrap: anywhere; }
.audit-pagination { margin-top: 16px; flex-wrap: wrap; gap: 12px; }
.audit-section-title { margin-top: 20px; }
</style>
