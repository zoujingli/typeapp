<script setup lang="ts">
import type { TableColumnsType } from 'ant-design-vue';
import type { Alarm, Page, TenantContext } from '../api';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Alert, Button, Card, Descriptions, DescriptionsItem, Empty, Input, Select, Table, Tag } from 'ant-design-vue';
import AppDrawer from '../components/app-drawer/app-drawer.vue';
import CrudSearchField from '../components/crud-search-field.vue';
import CrudTableActions from '../components/crud-table-actions.vue';
import { buildTableScrollX, estimateVisibleActionColumnWidth } from '../utils/table';
import { alarmBounds, alarmEndReasons, errorText, isCanceled, request, session } from '../api';

const router = useRouter();
const route = useRoute();
const permissions = ref<string[]>([]);
const canAcknowledge = computed(() => permissions.value.includes('customer.alarms.acknowledge'));
const saving = ref(false); let actionPending: AbortController | null = null;
const result = ref<Page<Alarm>>({ items: [], total: 0, page: 1, per_page: 20 });
const filters = reactive({ device_id: '', rule_id: '', status: '', from: '', to: '' });
let applied: Record<string, unknown> = {};
const busy = ref(false); const failure = ref(''); const detail = ref<Alarm | null>(null); const detailFailure = ref('');
let generation = 0; let pending: AbortController | null = null; let detailPending: AbortController | null = null;
const time = (value: number | null) => value === null ? '—' : new Date(value * 1000).toLocaleString();
const columns = computed<TableColumnsType<Alarm>>(() => [
  { title: '告警规则', key: 'name', width: 260, ellipsis: true }, { title: '设备标识', dataIndex: 'device_id', width: 290, ellipsis: true },
  { title: '状态', key: 'state', width: 150 }, { title: '人工确认', key: 'acknowledged', width: 180 }, { title: '触发数值', key: 'value', width: 180 }, { title: '触发时间', key: 'time', width: 200 },
  { title: '操作', key: 'actions', fixed: 'right', width: estimateVisibleActionColumnWidth([{ label: '详情' }, { label: '确认', visible: canAcknowledge.value }]) },
]);
const pagination = computed(() => ({ current: result.value.page, pageSize: result.value.per_page, total: result.value.total, showSizeChanger: true, pageSizeOptions: ['20', '50', '100'] }));
async function load(page = 1, perPage = result.value.per_page) {
  const tenant = session.tenant?.id; if (!tenant) return;
  const current = ++generation; pending?.abort(); pending = new AbortController(); busy.value = true; failure.value = '';
  try { const data = await request<Page<Alarm> & { context: TenantContext }>(`/customer/tenants/${tenant}/alarms`, { tenant, signal: pending.signal, params: { ...applied, page, per_page: perPage } }); if (current === generation) { result.value = data; permissions.value = data.context.permissions; } }
  catch (error) { if (!isCanceled(error) && current === generation) { failure.value = errorText(error); result.value.items = []; permissions.value = []; } }
  finally { if (current === generation) busy.value = false; }
}
function search(reset = false) {
  if (busy.value) return;
  if (reset) Object.assign(filters, { device_id: '', rule_id: '', status: '', from: '', to: '' });
  const from = filters.from ? Math.floor(new Date(filters.from).getTime() / 1000) : null;
  const to = filters.to ? Math.floor(new Date(filters.to).getTime() / 1000) : null;
  if (from !== null && to !== null && from > to) { failure.value = '结束时间不能早于开始时间。'; return; }
  applied = { device_id: filters.device_id.trim(), rule_id: filters.rule_id.trim(), status: filters.status, ...(from === null ? {} : { from }), ...(to === null ? {} : { to }) }; void load();
}
async function showDetail(alarm: Pick<Alarm, 'id'>) {
  if (saving.value) return;
  const tenant = session.tenant?.id; if (!tenant) return;
  detailFailure.value = ''; detailPending?.abort(); detailPending = new AbortController();
  try { detail.value = await request<Alarm>(`/customer/tenants/${tenant}/alarms/${alarm.id}`, { tenant, signal: detailPending.signal }); }
  catch (error) { if (!isCanceled(error)) detailFailure.value = errorText(error); }
}
async function acknowledge(alarm: Alarm) {
  const tenant = session.tenant?.id; if (!tenant || saving.value || !canAcknowledge.value || alarm.acknowledged_at !== null) return;
  saving.value = true; detailFailure.value = ''; actionPending = new AbortController();
  try { const updated = await request<Alarm>(`/customer/tenants/${tenant}/alarms/${alarm.id}/acknowledge`, { tenant, method: 'POST', data: {}, signal: actionPending.signal }); if (session.tenant?.id === tenant) { if (detail.value?.id === alarm.id) detail.value = updated; await load(result.value.page); } }
  catch (error) { if (!isCanceled(error) && session.tenant?.id === tenant) detailFailure.value = errorText(error); }
  finally { saving.value = false; }
}
watch(() => session.tenant?.id, tenant => { generation++; pending?.abort(); detailPending?.abort(); actionPending?.abort(); permissions.value = []; result.value.items = []; detail.value = null; detailFailure.value = ''; if (tenant) { void load(); if (typeof route.query.alarm === 'string' && /^[a-f0-9]{32}$/.test(route.query.alarm)) void showDetail({ id: route.query.alarm }); } else void router.replace('/tenants'); }, { immediate: true });
onBeforeUnmount(() => { generation++; pending?.abort(); detailPending?.abort(); actionPending?.abort(); });
</script>

<template>
  <section class="iot-page">
    <header class="page-heading"><div><h1>告警中心</h1><p class="muted tenant-title">{{ session.tenant?.name }} · 触发、恢复与原始规则</p></div><Button @click="router.push('/alarm-rules')">告警规则</Button></header>
    <Card :bordered="false">
      <form class="crud-search-grid" @submit.prevent="search()">
        <CrudSearchField label="设备标识"><Input v-model:value="filters.device_id" aria-label="告警设备筛选" :disabled="busy" :maxlength="32" allow-clear /></CrudSearchField>
        <CrudSearchField label="规则标识"><Input v-model:value="filters.rule_id" aria-label="告警规则筛选" :disabled="busy" :maxlength="32" allow-clear /></CrudSearchField>
        <CrudSearchField label="告警状态"><Select v-model:value="filters.status" aria-label="告警状态" :disabled="busy" :options="[{ label: '全部状态', value: '' }, { label: '活动告警', value: 'active' }, { label: '已结束', value: 'ended' }]" /></CrudSearchField>
        <CrudSearchField label="开始时间"><Input v-model:value="filters.from" aria-label="告警开始时间" type="datetime-local" :disabled="busy" /></CrudSearchField>
        <CrudSearchField label="结束时间"><Input v-model:value="filters.to" aria-label="告警结束时间" type="datetime-local" :disabled="busy" /></CrudSearchField>
        <div class="crud-search-grid__actions"><Button type="primary" html-type="submit" :loading="busy" :disabled="busy">查询</Button><Button :disabled="busy" @click="search(true)">重置</Button></div>
      </form>
      <Alert v-if="failure || detailFailure" :message="failure || detailFailure" type="error" show-icon class="page-alert" role="alert" />
      <p class="muted">人工确认表示已知悉，不结束活动告警。时间按触发样本的首次接收时间筛选；缺测和离线不会恢复告警。</p>
      <div class="table-toolbar"><span class="muted">{{ result.total }} 条告警</span><Button :loading="busy" @click="load(result.page)">刷新</Button></div>
      <Table :columns="columns" :data-source="result.items" row-key="id" :loading="busy" :scroll="buildTableScrollX(columns)" :pagination="pagination" @change="page => load(page.current, page.pageSize)">
        <template #emptyText><Empty :description="failure ? '数据暂不可用，请重试' : '暂无符合条件的告警'" /></template>
        <template #bodyCell="{ column, record }"><template v-if="column.key === 'name'">{{ record.definition.name }} · v{{ record.rule_version }}</template><template v-else-if="column.key === 'state'"><Tag :color="record.status === 'active' ? 'error' : 'default'">{{ record.status === 'active' ? '活动告警' : alarmEndReasons[record.end_reason] || '已结束' }}</Tag></template><template v-else-if="column.key === 'acknowledged'"><Tag :color="record.acknowledged_at ? 'success' : 'warning'">{{ record.acknowledged_at ? '已确认' : '未确认' }}</Tag></template><template v-else-if="column.key === 'value'">{{ record.trigger.value }} {{ record.definition.property.unit }}</template><template v-else-if="column.key === 'time'">{{ time(record.created_at) }}</template><CrudTableActions v-else-if="column.key === 'actions'" :actions="[{ label: '详情', disabled: saving, onClick: () => showDetail(record as Alarm) }, { label: '确认', visible: canAcknowledge && record.acknowledged_at === null, disabled: saving, onClick: () => acknowledge(record as Alarm) }]" /></template>
      </Table>
    </Card>
    <AppDrawer :open="Boolean(detail)" title="告警详情" width-size="md" :show-footer="false" :confirm-loading="saving" :closable="!saving" @update:open="value => { if (!value && !saving) detail = null; }">
      <template v-if="detail">
        <Alert v-if="detailFailure" :message="detailFailure" type="error" show-icon class="page-alert" role="alert" />
        <p><Button v-if="canAcknowledge && detail.acknowledged_at === null" type="primary" :loading="saving" :disabled="saving" @click="acknowledge(detail)">确认告警</Button><span class="muted"> 确认与数值恢复分别记录。</span></p>
        <Alert v-if="detail.status === 'active' && detail.rule_retired_at" message="规则已变更；正在处理该版本已接收的样本，随后记录结束原因。" type="info" show-icon class="page-alert" />
        <Descriptions :column="{ xs: 1, sm: 2 }" layout="vertical" bordered>
          <DescriptionsItem label="告警规则">{{ detail.definition.name }} · v{{ detail.rule_version }}</DescriptionsItem><DescriptionsItem label="告警状态">{{ detail.status === 'active' ? '活动告警' : alarmEndReasons[detail.end_reason || ''] || '已结束' }}</DescriptionsItem>
          <DescriptionsItem label="人工确认">{{ detail.acknowledged_at ? '已确认' : '未确认' }}</DescriptionsItem><DescriptionsItem label="确认时间">{{ time(detail.acknowledged_at) }}</DescriptionsItem><DescriptionsItem label="确认人员" :span="2">{{ detail.acknowledged_by || '—' }}</DescriptionsItem>
          <DescriptionsItem label="告警标识">{{ detail.id }}</DescriptionsItem><DescriptionsItem label="设备标识">{{ detail.device_id }}</DescriptionsItem>
          <DescriptionsItem label="监控属性">{{ detail.definition.property.name }} · {{ detail.field }} · {{ detail.definition.property.unit || '无单位' }}</DescriptionsItem><DescriptionsItem label="原始条件">{{ alarmBounds(detail.definition) }}；回差 {{ detail.definition.hysteresis }}</DescriptionsItem>
          <DescriptionsItem label="触发数值">{{ detail.trigger.value }}</DescriptionsItem><DescriptionsItem label="恢复数值">{{ detail.recovery?.value ?? '—' }}</DescriptionsItem>
          <DescriptionsItem label="触发采样时间">{{ time(detail.trigger.sampled_at) }}</DescriptionsItem><DescriptionsItem label="触发接收时间">{{ time(detail.trigger.received_at) }}</DescriptionsItem>
          <DescriptionsItem label="恢复采样时间">{{ time(detail.recovery?.sampled_at ?? null) }}</DescriptionsItem><DescriptionsItem label="恢复接收时间">{{ time(detail.recovery?.received_at ?? null) }}</DescriptionsItem>
          <DescriptionsItem label="触发样本序号">{{ detail.trigger.sequence }}</DescriptionsItem><DescriptionsItem label="恢复样本序号">{{ detail.recovery?.sequence || '—' }}</DescriptionsItem>
          <DescriptionsItem label="结束时间">{{ time(detail.ended_at) }}</DescriptionsItem><DescriptionsItem label="冻结物模型">v{{ detail.model_version }}</DescriptionsItem>
          <DescriptionsItem label="产品标识">{{ detail.product_id }}</DescriptionsItem><DescriptionsItem label="归属标识">{{ detail.ownership_id }}</DescriptionsItem>
        </Descriptions>
      </template>
    </AppDrawer>
  </section>
</template>
