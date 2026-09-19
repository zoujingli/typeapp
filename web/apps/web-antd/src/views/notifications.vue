<script setup lang="ts">
import type { TableColumnsType } from 'ant-design-vue';
import type { Notice, Page, TenantContext } from '../api';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Alert, Button, Card, Empty, Select, Table, Tag } from 'ant-design-vue';
import CrudSearchField from '../components/crud-search-field.vue';
import CrudTableActions from '../components/crud-table-actions.vue';
import { buildTableScrollX, estimateVisibleActionColumnWidth } from '../utils/table';
import { alarmEndReasons, errorText, isCanceled, request, session } from '../api';

const router = useRouter();
const result = ref<Page<Notice>>({ items: [], total: 0, page: 1, per_page: 20 });
const permissions = ref<string[]>([]);
const canReadAlarm = computed(() => permissions.value.includes('customer.alarms.read'));
const kind = ref(''); let appliedKind = '';
const busy = ref(false); const failure = ref(''); let generation = 0; let pending: AbortController | null = null;
const time = (value: number) => new Date(value * 1000).toLocaleString();
const columns: TableColumnsType<Notice> = [
  { title: '通知内容', key: 'name', width: 300, ellipsis: true }, { title: '通知类型', key: 'kind', width: 150 },
  { title: '当前告警', key: 'state', width: 150 }, { title: '人工确认', key: 'acknowledged', width: 130 },
  { title: '发生时间', key: 'occurred', width: 200 }, { title: '通知创建', key: 'created', width: 200 },
  { title: '操作', key: 'actions', fixed: 'right', width: estimateVisibleActionColumnWidth([{ label: '告警详情' }]) },
];
const pagination = computed(() => ({ current: result.value.page, pageSize: result.value.per_page, total: result.value.total, showSizeChanger: true, pageSizeOptions: ['20', '50', '100'] }));
async function load(page = 1, perPage = result.value.per_page) {
  const tenant = session.tenant?.id; if (!tenant) return;
  const current = ++generation; pending?.abort(); pending = new AbortController(); busy.value = true; failure.value = '';
  try { const data = await request<Page<Notice> & { context: TenantContext }>(`/customer/tenants/${tenant}/notifications`, { tenant, signal: pending.signal, params: { kind: appliedKind, page, per_page: perPage } }); if (current === generation) { result.value = data; permissions.value = data.context.permissions; } }
  catch (error) { if (!isCanceled(error) && current === generation) { failure.value = errorText(error); permissions.value = []; result.value = { ...result.value, items: [], total: 0 }; } }
  finally { if (current === generation) busy.value = false; }
}
function search(reset = false) { if (busy.value) return; if (reset) kind.value = ''; appliedKind = kind.value; void load(); }
watch(() => session.tenant?.id, tenant => { generation++; pending?.abort(); permissions.value = []; result.value.items = []; if (tenant) void load(); else void router.replace('/tenants'); }, { immediate: true });
onBeforeUnmount(() => { generation++; pending?.abort(); });
</script>

<template>
  <section class="iot-page">
    <header class="page-heading"><div><h1>站内通知</h1><p class="muted tenant-title">{{ session.tenant?.name }} · 告警触发与结束通知</p></div><Button v-if="canReadAlarm" @click="router.push('/alarms')">告警中心</Button></header>
    <Card :bordered="false">
      <form class="crud-search-grid" @submit.prevent="search()">
        <CrudSearchField label="通知类型"><Select v-model:value="kind" aria-label="通知类型" :disabled="busy" :options="[{ label: '全部通知', value: '' }, { label: '告警触发', value: 'triggered' }, { label: '告警结束', value: 'ended' }]" /></CrudSearchField>
        <div class="crud-search-grid__actions"><Button type="primary" html-type="submit" :loading="busy" :disabled="busy">查询</Button><Button :disabled="busy" @click="search(true)">重置</Button></div>
      </form>
      <Alert v-if="failure" :message="failure" type="error" show-icon class="page-alert" role="alert" />
      <p class="muted">通知从创建起保留 180 天。告警确认仅表示已知悉；数值恢复、规则变更和规则停用分别显示。</p>
      <div class="table-toolbar"><span class="muted">{{ result.total }} 条通知</span><Button :loading="busy" :disabled="busy" @click="load(result.page)">刷新</Button></div>
      <Table :columns="columns" :data-source="result.items" row-key="id" :loading="busy" :scroll="buildTableScrollX(columns)" :pagination="pagination" @change="page => load(page.current, page.pageSize)">
        <template #emptyText><Empty :description="failure ? '通知暂不可用，请重试' : '暂无符合条件的通知'" /></template>
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'name'">{{ record.payload.name }} · v{{ record.payload.rule_version }}</template>
          <template v-else-if="column.key === 'kind'"><Tag :color="record.kind === 'triggered' ? 'error' : 'default'">{{ record.kind === 'triggered' ? '告警触发' : alarmEndReasons[record.payload.end_reason || ''] || '告警结束' }}</Tag></template>
          <template v-else-if="column.key === 'state'">{{ record.alarm_status === 'active' ? '活动告警' : record.alarm_status === 'ended' ? alarmEndReasons[record.alarm_end_reason || ''] || '已结束' : '告警保留期已过' }}</template>
          <template v-else-if="column.key === 'acknowledged'"><Tag v-if="record.alarm_status" :color="record.acknowledged_at ? 'success' : 'warning'">{{ record.acknowledged_at ? '已确认' : '未确认' }}</Tag><span v-else>—</span></template>
          <template v-else-if="column.key === 'occurred'">{{ time(record.payload.occurred_at) }}</template><template v-else-if="column.key === 'created'">{{ time(record.created_at) }}</template>
          <CrudTableActions v-else-if="column.key === 'actions'" :actions="[{ label: '告警详情', visible: canReadAlarm, disabled: !record.alarm_status, onClick: () => router.push({ path: '/alarms', query: { alarm: record.alarm_id } }) }]" />
        </template>
      </Table>
    </Card>
  </section>
</template>
