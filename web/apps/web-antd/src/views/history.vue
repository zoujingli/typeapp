<script setup lang="ts">
import type { TableColumnsType } from 'ant-design-vue';
import type { AggregateStatistic, ExportTask, HistoryCurve, HistoryPage, HistoryRecord, MinuteCurve, MinutePage, MinuteRecord, Page, TenantContext } from '../api';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Alert, Button, Card, Descriptions, DescriptionsItem, Empty, Input, InputNumber, Modal, Select, Table, Tag } from 'ant-design-vue';
import AppDrawer from '../components/app-drawer/app-drawer.vue';
import CrudSearchField from '../components/crud-search-field.vue';
import CrudTableActions from '../components/crud-table-actions.vue';
import { ApiError, errorText, isCanceled, request, session } from '../api';
import { buildTableScrollX, estimateVisibleActionColumnWidth } from '../utils/table';

const route = useRoute(); const router = useRouter();
function localInput(at: number) { const date = new Date(at); return new Date(at - date.getTimezoneOffset() * 60000).toISOString().slice(0, 19); }
function defaults() { return { device: String(route.query.device || ''), from: localInput(Date.now() - 86400000), to: localInput(Date.now()), product_id: '', model_version: undefined as number | undefined, ownership_id: '', field: '', sort: 'sampled_desc' }; }
const filters = reactive(defaults());
const historyKind = ref('records');
const minuteMode = computed(() => historyKind.value === 'minutes');
const statistic = ref<AggregateStatistic>('avg');
const statisticOptions = [{ value: 'count', label: '样本数量 count' }, { value: 'min', label: '最小数值 min' }, { value: 'max', label: '最大数值 max' }, { value: 'sum', label: '数值合计 sum' }, { value: 'avg', label: '加权均值 avg' }, { value: 'last', label: '最后数值 last' }];
let applied: Record<string, unknown> = {};
let appliedDevice = '';
const result = ref<HistoryPage | MinutePage | null>(null);
const busy = ref(false); const failure = ref(''); const pageSize = ref(20);
const details = ref<HistoryRecord | MinuteRecord | null>(null); const curveRecord = ref<HistoryRecord | MinuteRecord | null>(null);
const curve = ref<HistoryCurve | MinuteCurve | null>(null); const curveField = ref(''); const curveBusy = ref(false); const curveFailure = ref('');
const cursors = ref<string[]>(['']);
const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
const canRead = computed(() => session.realm === 'customer' && session.permissions.includes('customer.telemetry.read'));
const canExport = computed(() => session.realm === 'customer' && session.permissions.includes('customer.exports.create'));
const canReadExports = computed(() => session.realm === 'customer' && session.permissions.includes('customer.exports.read'));
const canCancelExports = computed(() => session.permissions.includes('customer.exports.cancel'));
const canDownloadExports = computed(() => session.permissions.includes('customer.exports.download'));
/** 未确认受理的导出请求，绑定身份、租户、设备及首次筛选参数。 */
type ExportSubmission = { identity: string; tenant: string; device: string; data: Record<string, unknown> };
const exportSubmission = ref<ExportSubmission | null>(null);
const exportStorageKey = () => `typeapp.export.${session.identity?.key || ''}`;
/** 恢复当前工作区的原导出请求；损坏缓存直接清除，不产生新任务。 */
function restoreExport() {
  exportSubmission.value = null;
  try {
    const saved = JSON.parse(sessionStorage.getItem(exportStorageKey()) || 'null') as ExportSubmission | null;
    if (saved && saved.identity === session.identity?.key && saved.tenant === session.tenant?.id && /^[a-f0-9]{32}$/.test(saved.device) && /^[a-f0-9]{32}$/.test(String(saved.data.id))) exportSubmission.value = saved;
  } catch { sessionStorage.removeItem(exportStorageKey()); }
}
const exportOpen = ref(false); const exportBusy = ref(false); const exportCreating = ref(false); const exportFailure = ref('');
const exportTasks = ref<Page<ExportTask> | null>(null); const exportDetails = ref<ExportTask | null>(null);
const exportPage = ref(1); const exportPageSize = ref(20);
let exportVersion = 0; let exportPending: AbortController | null = null; let exportTimer: ReturnType<typeof setTimeout> | null = null;
const exportNames: Record<ExportTask['status'], string> = { queued: '排队中', running: '生成中', succeeded: '已完成', failed: '失败', cancelled: '已取消', expired: '已过期' };
const exportColors: Record<ExportTask['status'], string> = { queued: 'default', running: 'processing', succeeded: 'success', failed: 'error', cancelled: 'default', expired: 'warning' };
const exportErrors: Record<string, string> = { export_permission_revoked: '创建者权限已撤销', export_storage_unavailable: '文件存储不可用', export_file_unavailable: '文件丢失或不完整', export_limit_or_snapshot_invalid: '运行时超限或快照不完整' };
const exportColumns: TableColumnsType<ExportTask> = [
  { title: '创建时间', key: 'created', width: 190 }, { title: '数据类型', key: 'kind', width: 110 }, { title: '状态', key: 'status', width: 110 },
  { title: '处理进度', key: 'progress', width: 170 }, { title: '文件大小', key: 'bytes', width: 110 },
  { title: '设备标识', dataIndex: 'device_id', width: 280, ellipsis: true },
  { title: '操作', key: 'actions', fixed: 'right', width: estimateVisibleActionColumnWidth([{ label: '详情' }, { label: '下载' }, { label: '取消' }]) },
];
function clearExportPoll() { if (exportTimer) clearTimeout(exportTimer); exportTimer = null; }
/** 仅在抽屉打开且可读时查询；完成后再安排轮询，过期响应不回写列表。 */
async function loadExports(page = exportPage.value) {
  const tenant = session.tenant?.id;
  if (!tenant || !exportOpen.value || !canReadExports.value || exportBusy.value) return;
  clearExportPoll(); const current = ++exportVersion; exportPending?.abort(); exportPending = new AbortController(); exportBusy.value = true;
  try {
    const data = await request<Page<ExportTask> & { context: TenantContext }>(`/customer/tenants/${tenant}/exports`, { tenant, signal: exportPending.signal, params: { page, per_page: exportPageSize.value } });
    if (current !== exportVersion) return;
    exportTasks.value = data; exportPage.value = page; exportFailure.value = ''; session.permissions = data.context.permissions;
    if (exportDetails.value) exportDetails.value = data.items.find(task => task.id === exportDetails.value?.id) || null;
  } catch (error) { if (!isCanceled(error) && current === exportVersion) exportFailure.value = errorText(error); }
  finally {
    if (current === exportVersion) {
      exportBusy.value = false;
      if (exportOpen.value && canReadExports.value && !document.hidden && !exportFailure.value) exportTimer = setTimeout(() => void loadExports(), 5000);
    }
  }
}
function showExports() { exportOpen.value = true; void loadExports(); }
/** 确认后先保存请求标识；受理结果未知时复用原请求，避免生成重复导出。 */
function createExport() {
  const tenant = session.tenant?.id;
  if (!tenant || !session.identity?.key || (canRead.value && !result.value && !exportSubmission.value) || exportCreating.value || busy.value || !canExport.value) return;
  if (!exportSubmission.value && !canRead.value && !search()) return;
  const submission = exportSubmission.value || { tenant, identity: session.identity.key, device: appliedDevice, data: { ...applied, id: crypto.randomUUID().replaceAll('-', ''), kind: minuteMode.value ? 'minutes' : 'records', timezone } };
  const storageKey = exportStorageKey(); exportCreating.value = true;
  Modal.confirm({ title: '确认导出当前查询', content: exportSubmission.value ? '重试上次尚未确认的导出，保留原筛选和请求，不创建重复任务。' : `导出当前筛选条件下的全部匹配记录${result.value ? `，当前约 ${result.value.total} 条` : ''}；文件生成后保留 24 小时。单次最多 100000 行或 100 MiB，超限请缩小查询范围。`, okText: '创建任务', cancelText: '取消', maskClosable: false, keyboard: false, closable: false, onCancel: () => { exportCreating.value = false; }, onOk: async () => {
    if (tenant !== session.tenant?.id || submission.identity !== session.identity?.key) { exportCreating.value = false; return; }
    exportFailure.value = ''; exportSubmission.value = submission; sessionStorage.setItem(storageKey, JSON.stringify(submission));
    try {
      const task = await request<ExportTask>(`/customer/tenants/${tenant}/devices/${submission.device}/exports`, { method: 'POST', tenant, data: submission.data });
      sessionStorage.removeItem(storageKey);
      if (tenant !== session.tenant?.id || submission.identity !== session.identity?.key) return;
      exportSubmission.value = null; exportPage.value = 1; exportDetails.value = task; showExports();
    } catch (error) {
      if (tenant !== session.tenant?.id || submission.identity !== session.identity?.key) return;
      if (!isCanceled(error)) {
        if (error instanceof ApiError && error.status < 500 && error.status !== 408) { sessionStorage.removeItem(storageKey); exportSubmission.value = null; }
        exportFailure.value = errorText(error); exportOpen.value = true;
      }
    }
    finally { exportCreating.value = false; }
  } });
}
async function exportAction(task: ExportTask, action: 'download' | 'resume' | 'cancel') {
  const tenant = session.tenant?.id;
  if (!tenant || (action === 'download' ? !canDownloadExports.value : action === 'cancel' ? !canCancelExports.value : !canExport.value)) return;
  try {
    if (action === 'download') {
      const blob = await request<Blob>(`/customer/tenants/${tenant}/exports/${task.id}/download`, { tenant, responseType: 'blob' });
      const url = URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = `history-${task.kind}-${task.id}.csv`;
      try { link.click(); } finally { URL.revokeObjectURL(url); }
    } else {
      await request(`/customer/tenants/${tenant}/exports/${task.id}/${action}`, { tenant, method: 'POST', data: {} });
      await loadExports();
    }
  } catch (error) { if (!isCanceled(error)) exportFailure.value = errorText(error); }
}
function exportActions(task: ExportTask) {
  const active = ['queued', 'running'].includes(task.status);
  return [{ label: '详情', onClick: () => { exportDetails.value = task; } },
    { label: '下载', visible: canDownloadExports.value && task.status === 'succeeded', onClick: () => exportAction(task, 'download') },
    { label: '恢复', visible: canExport.value && active && task.updated_at <= Date.now() / 1000 - 60, onClick: () => exportAction(task, 'resume') },
    { label: '取消', visible: canCancelExports.value && active, danger: true, confirmTitle: '确认取消此导出任务？', confirmContent: '已经生成的临时文件会被清理。', confirmOkText: '取消任务', onClick: () => exportAction(task, 'cancel') }];
}
function exportVisibility() { clearExportPoll(); if (!document.hidden && exportOpen.value) void loadExports(); }
document.addEventListener('visibilitychange', exportVisibility);
watch(exportOpen, (open) => { if (!open) { clearExportPoll(); exportVersion++; exportPending?.abort(); exportBusy.value = false; exportDetails.value = null; } });
let version = 0; let curveVersion = 0; let pending: AbortController | null = null; let curvePending: AbortController | null = null;
const rawColumns: TableColumnsType<HistoryRecord | MinuteRecord> = [
  { title: '采样时间', key: 'sampled', width: 200 }, { title: '首次接收时间', key: 'received', width: 200 },
  { title: '业务序号', dataIndex: 'sequence', width: 190, ellipsis: true }, { title: '模型版本', dataIndex: 'model_version', width: 100 },
  { title: '原始属性', key: 'values', width: 280, ellipsis: true }, { title: '归属阶段', dataIndex: 'ownership_id', width: 290, ellipsis: true },
  { title: '操作', key: 'actions', fixed: 'right', width: estimateVisibleActionColumnWidth([{ label: '详情' }, { label: '曲线' }]) },
];
const minuteColumns: TableColumnsType<HistoryRecord | MinuteRecord> = [
  { title: '采样分钟开始', key: 'sampled', width: 200 }, { title: '分钟结束', key: 'ended', width: 200 },
  { title: '模型版本', dataIndex: 'model_version', width: 100 }, { title: '数值统计摘要', key: 'values', width: 320, ellipsis: true },
  { title: '最近修正时间', key: 'updated', width: 200 }, { title: '归属阶段', dataIndex: 'ownership_id', width: 290, ellipsis: true },
  { title: '操作', key: 'actions', fixed: 'right', width: estimateVisibleActionColumnWidth([{ label: '详情' }, { label: '曲线' }]) },
];
const columns = computed(() => minuteMode.value ? minuteColumns : rawColumns);
const sortOptions = computed(() => [{ value: 'sampled_desc', label: minuteMode.value ? '采样分钟降序' : '采样时间降序' }, { value: 'sampled_asc', label: minuteMode.value ? '采样分钟升序' : '采样时间升序' }, ...(minuteMode.value ? [] : [{ value: 'received_desc', label: '接收时间降序' }, { value: 'received_asc', label: '接收时间升序' }])]);
function isMinute(record: HistoryRecord | MinuteRecord): record is MinuteRecord { return 'window_start' in record; }
const numericFields = computed(() => curveRecord.value?.model.properties.filter((field) => ['number', 'integer'].includes(field.type)) || []);
function timeText(at: number) { return new Date(at * 1000).toLocaleString(); }
function textValue(value: unknown) { return value === undefined ? '未上报' : typeof value === 'string' ? `“${value}”` : String(value); }
function valuesText(record: HistoryRecord | MinuteRecord) { return record.model.properties.filter((field) => field.identifier in (isMinute(record) ? record.fields : record.values)).map((field) => `${field.name}: ${isMinute(record) ? `${record.fields[field.identifier]!.count} 个样本，均值 ${record.fields[field.identifier]!.avg}` : textValue(record.values[field.identifier])}${field.unit ? ` ${field.unit}` : ''}`).join('；'); }
function numeric(record: HistoryRecord | MinuteRecord) { return record.model.properties.some((field) => ['number', 'integer'].includes(field.type)); }
function changeMode() { filters.sort = 'sampled_desc'; search(); }
function selectRange(days: number) { filters.from = localInput(Date.now() - days * 86400000); filters.to = localInput(Date.now()); search(); }
function clearCurve() { curveVersion++; curvePending?.abort(); curve.value = null; curveRecord.value = null; curveField.value = ''; curveBusy.value = false; curveFailure.value = ''; }
async function load(page = 1, cursor = '') {
  const tenant = session.tenant?.id;
  if (!tenant || !appliedDevice || !canRead.value) return;
  const current = ++version; pending?.abort(); pending = new AbortController(); busy.value = true; failure.value = ''; details.value = null;
  try {
    const data = await request<HistoryPage | MinutePage>(`/customer/tenants/${tenant}/devices/${appliedDevice}/history`, { tenant, signal: pending.signal, params: { ...applied, view: minuteMode.value ? 'minutes' : 'records', page, per_page: pageSize.value, ...(cursor ? { cursor } : {}) } });
    if (current !== version) return;
    result.value = data;
    if (data.next_cursor) cursors.value[page] = data.next_cursor;
  } catch (error) { if (!isCanceled(error) && current === version) { result.value = null; failure.value = errorText(error); } }
  finally { if (current === version) busy.value = false; }
}
function search(reset = false): boolean {
  if (busy.value || (!canRead.value && !canExport.value)) return false;
  if (reset) Object.assign(filters, defaults());
  clearCurve(); result.value = null; cursors.value = ['']; failure.value = '';
  const from = Math.floor(new Date(filters.from).getTime() / 1000); const to = Math.floor(new Date(filters.to).getTime() / 1000);
  if (!/^[a-f0-9]{32}$/.test(filters.device.trim())) { failure.value = '请输入有效设备标识，也可从设备列表进入历史。'; return false; }
  const maxDays = minuteMode.value ? 90 : 9;
  if (!Number.isFinite(from) || !Number.isFinite(to) || from > to || to - from > maxDays * 86400 || to > Date.now() / 1000 + 5) { failure.value = `请选择有效时间范围，结束时间不晚于现在，单次跨度不超过 ${maxDays} 天。`; return false; }
  appliedDevice = filters.device.trim();
  applied = { from, to, sort: filters.sort, ...Object.fromEntries(['product_id', 'model_version', 'ownership_id', 'field'].map((key) => [key, filters[key as keyof typeof filters]]).filter(([, value]) => value !== '' && value !== undefined)) };
  void load();
  return true;
}
async function loadCurve() {
  const tenant = session.tenant?.id; const record = curveRecord.value;
  if (!tenant || !record || !curveField.value || !canRead.value) return;
  const current = ++curveVersion; curvePending?.abort(); curvePending = new AbortController(); curveBusy.value = true; curveFailure.value = ''; curve.value = null;
  try {
    const data = await request<HistoryCurve | MinuteCurve>(`/customer/tenants/${tenant}/devices/${appliedDevice}/history`, { tenant, signal: curvePending.signal, params: { view: minuteMode.value ? 'minute_curve' : 'curve', ...(minuteMode.value ? { stat: statistic.value } : {}), from: applied.from, to: applied.to, product_id: record.product_id, model_version: record.model_version, ownership_id: record.ownership_id, field: curveField.value } });
    if (current === curveVersion) curve.value = data;
  } catch (error) { if (!isCanceled(error) && current === curveVersion) curveFailure.value = errorText(error); }
  finally { if (current === curveVersion) curveBusy.value = false; }
}
function showCurve(record: HistoryRecord | MinuteRecord) { clearCurve(); curveRecord.value = record; curveField.value = numericFields.value[0]?.identifier || ''; void loadCurve(); }
const curveUnit = computed(() => curve.value?.function === 'minute_aggregate' && curve.value.statistic === 'count' ? '个样本' : curve.value?.property.unit || '无单位');
const chart = computed(() => {
  if (!curve.value) return null;
  const data = curve.value; const values = data.points.map((point) => point.value).filter((value): value is number => value !== null);
  if (!values.length) return null;
  const min = Math.min(...values); const max = Math.max(...values); const spread = max - min || Math.max(Math.abs(max) * 0.1, 1);
  const low = min - spread * 0.1; const high = max + spread * 0.1;
  let path = ''; let connected = false;
  const dots: { x: number; y: number; title: string }[] = [];
  for (const point of data.points) {
    if (point.value === null) { connected = false; continue; }
    const x = 70 + (point.sampled_at - ('start' in data.window ? data.window.start : data.window.from)) / Math.max(1, 'end' in data.window ? data.window.end - data.window.start : data.window.to - data.window.from) * 870;
    const y = 260 - (point.value - low) / (high - low) * 225;
    path += `${connected ? ' L' : ' M'}${x.toFixed(2)},${y.toFixed(2)}`; connected = true;
    dots.push({ x, y, title: `${timeText(point.sampled_at)} · ${point.value} ${curveUnit.value} · ${point.count} 个样本${point.sequence ? ` · 序号 ${point.sequence}` : ''}` });
  }
  return { path, dots, low, high };
});
function cleanup() { version++; pending?.abort(); busy.value = false; result.value = null; details.value = null; failure.value = ''; appliedDevice = ''; clearCurve(); exportOpen.value = false; exportVersion++; exportPending?.abort(); clearExportPoll(); exportBusy.value = false; exportTasks.value = null; exportDetails.value = null; exportFailure.value = ''; }
watch([() => session.tenant?.id, () => route.query.device, () => session.token, () => session.realm], ([tenant]) => { cleanup(); restoreExport(); Object.assign(filters, defaults()); if (!tenant && route.path === '/history') void router.replace('/tenants'); else if (filters.device) search(); }, { immediate: true });
watch(() => session.identity?.key, restoreExport);
onBeforeUnmount(() => { cleanup(); document.removeEventListener('visibilitychange', exportVisibility); });
</script>

<template>
  <section class="iot-page">
    <header class="page-heading"><div><h1>历史数据</h1><p class="muted tenant-title">{{ session.tenant?.name }} · 时间按 {{ timezone }} 显示</p></div><div class="toolbar-actions"><Button v-if="canExport" :disabled="(canRead && !result && !exportSubmission) || busy || exportCreating" :loading="exportCreating" @click="createExport">{{ exportSubmission ? '重试导出请求' : '导出当前查询' }}</Button><Button v-if="canReadExports" @click="showExports">导出任务</Button><Button v-if="session.permissions.includes('customer.devices.read')" @click="router.push('/devices')">设备列表</Button></div></header>
    <Alert v-if="exportSubmission" message="上次导出结果尚未确认，请重试原请求核对结果。" type="warning" show-icon class="page-alert" />
    <Card :bordered="false">
      <Alert v-if="!canRead" message="当前角色没有遥测查询权限。" type="info" show-icon class="page-alert" />
      <form v-if="canRead || canExport" class="crud-search-grid" @submit.prevent="search()">
        <CrudSearchField label="数据类型"><Select v-model:value="historyKind" aria-label="历史数据类型" :disabled="busy" :options="[{ value: 'records', label: '原始上报 · 保留七天' }, { value: 'minutes', label: '分钟统计 · 保留九十天' }]" @change="changeMode" /></CrudSearchField>
        <CrudSearchField label="设备标识"><Input v-model:value="filters.device" aria-label="历史设备标识" :disabled="busy" :maxlength="32" allow-clear /></CrudSearchField>
        <CrudSearchField label="开始时间"><Input v-model:value="filters.from" type="datetime-local" step="1" aria-label="历史开始时间" :disabled="busy" /></CrudSearchField>
        <CrudSearchField label="结束时间"><Input v-model:value="filters.to" type="datetime-local" step="1" aria-label="历史结束时间" :disabled="busy" /></CrudSearchField>
        <CrudSearchField label="产品标识"><Input v-model:value="filters.product_id" aria-label="历史产品标识" :disabled="busy" :maxlength="32" allow-clear /></CrudSearchField>
        <CrudSearchField label="模型版本"><InputNumber v-model:value="filters.model_version" aria-label="历史模型版本" :disabled="busy" :min="1" :max="2147483646" :precision="0" /></CrudSearchField>
        <CrudSearchField label="归属阶段"><Input v-model:value="filters.ownership_id" aria-label="历史归属阶段" :disabled="busy" :maxlength="32" allow-clear /></CrudSearchField>
        <CrudSearchField label="属性标识"><Input v-model:value="filters.field" aria-label="历史属性标识" :disabled="busy" :maxlength="64" allow-clear /></CrudSearchField>
        <CrudSearchField label="排序方式"><Select v-model:value="filters.sort" aria-label="历史排序方式" :disabled="busy" :options="sortOptions" /></CrudSearchField>
        <div class="crud-search-grid__actions"><Button type="primary" html-type="submit" :loading="busy" :disabled="busy">{{ canRead ? '查询' : '应用筛选' }}</Button><Button :disabled="busy" @click="search(true)">重置</Button></div>
      </form>
      <Alert v-if="failure" :message="failure" type="error" show-icon class="page-alert" role="alert" />
      <p v-if="minuteMode" class="muted">分钟统计按 UTC 采样分钟保存，窗口结束后保留 90 天。查询包含起止时间所在的完整分钟；未结束分钟及合法迟到可能继续修正，缺测为空。</p>
      <p v-else class="muted">默认查询最近 24 小时。原始记录从首次接收起保留 7 天，补报的采样时间可能更早；按当时模型解释，设备转移不改变历史归属。</p>
      <div v-if="minuteMode" class="toolbar-actions"><Button :disabled="busy" @click="selectRange(1)">最近24小时</Button><Button :disabled="busy" @click="selectRange(7)">最近7天</Button><Button :disabled="busy" @click="selectRange(90)">最近90天</Button></div>
      <Table v-if="canRead" :columns="columns" :data-source="result?.items || []" :row-key="record => 'id' in record ? record.id : record.message_id" :loading="busy" :pagination="false" :scroll="buildTableScrollX(columns)">
        <template #emptyText><Empty :description="failure ? '查询未完成，请修正或重试' : result ? minuteMode ? '此范围暂无分钟统计' : '此范围暂无原始数据' : '请选择设备和时间范围'" /></template>
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'sampled'">{{ timeText(record.window_start ?? record.sampled_at) }}</template>
          <template v-else-if="column.key === 'ended'">{{ timeText(record.window_end) }}</template>
          <template v-else-if="column.key === 'updated'">{{ timeText(record.updated_at) }}</template>
          <template v-else-if="column.key === 'received'">{{ timeText(record.received_at) }}</template>
          <span v-else-if="column.key === 'values'" :title="valuesText(record as HistoryRecord)">{{ valuesText(record as HistoryRecord) }}</span>
          <CrudTableActions v-else-if="column.key === 'actions'" :actions="[{ label: '详情', onClick: () => details = record as HistoryRecord }, ...(numeric(record as HistoryRecord) ? [{ label: '曲线', onClick: () => showCurve(record as HistoryRecord) }] : [])]" />
        </template>
      </Table>
      <div v-if="canRead" class="history-pagination"><span class="muted">{{ result ? `当前保留 ${result.total} ${minuteMode ? '个分钟窗口' : '条'} · 第 ${result.page} 页` : '每页最多 100 条' }}</span><div class="toolbar-actions"><Select v-model:value="pageSize" aria-label="历史每页条数" :disabled="busy" :options="[20, 50, 100].map(value => ({ value, label: `${value} 条/页` }))" @change="search()" /><Button :disabled="busy || !result || result.page <= 1" @click="load(result!.page - 1, cursors[result!.page - 2])">上一页</Button><Button :disabled="busy || !result?.next_cursor" @click="load(result!.page + 1, result!.next_cursor!)">下一页</Button></div></div>
      <p v-if="result" class="muted">{{ minuteMode ? '按分钟与标识翻页，有效期 15 分钟；迟到修正可更新统计，新增窗口与到期清理可能改变总数。' : '翻页固定本次接收边界，有效期 15 分钟；到期清理可能减少保留总数，重新查询可看到新的上报。' }}</p>
    </Card>
    <Card v-if="curveRecord" title="历史曲线" :bordered="false" class="device-data-card" :loading="curveBusy">
      <template #extra><Button :loading="curveBusy" :disabled="curveBusy" @click="loadCurve">刷新曲线</Button></template>
      <p class="muted current-value">产品 {{ curveRecord.product_id }} · 模型版本 {{ curveRecord.model_version }} · 归属 {{ curveRecord.ownership_id }}</p>
      <CrudSearchField label="曲线属性"><Select v-model:value="curveField" aria-label="曲线属性" :disabled="curveBusy" :options="numericFields.map(field => ({ value: field.identifier, label: `${field.name} (${field.unit || '无单位'})` }))" @change="loadCurve" /></CrudSearchField>
      <CrudSearchField v-if="minuteMode" label="统计函数"><Select v-model:value="statistic" aria-label="分钟统计函数" :disabled="curveBusy" :options="statisticOptions" @change="loadCurve" /></CrudSearchField>
      <Alert v-if="curveFailure" :message="curveFailure" type="error" show-icon class="page-alert" role="alert" />
      <p v-if="curve?.function === 'minute_aggregate'" class="muted">{{ curve.raw_count }} 个有效数值样本 · {{ curve.minute_count }} 个分钟窗口 · 每 {{ curve.granularity_seconds }} 秒显示 {{ curve.statistic }}，{{ curve.points.length }} 个时间桶 · 单位 {{ curveUnit }} · 缺测为空，不补零</p>
      <p v-else-if="curve" class="muted">{{ curve.raw_count }} 条原始上报 · {{ curve.granularity_seconds ? `每 ${curve.granularity_seconds} 秒显示均值，${curve.points.length} 个时间桶` : `原始采样，${curve.points.length} 个点` }} · 缺测为空，不补零</p>
      <div v-if="chart && curve" class="history-chart-viewport" tabindex="0" aria-label="历史曲线，可横向滚动查看时间范围"><svg class="history-chart" viewBox="0 0 980 325" role="img" :aria-label="`${curve.property.name}历史曲线，单位${curveUnit}，${curve.points.length}个点`">
        <line x1="70" y1="35" x2="70" y2="260" class="history-chart-axis" /><line x1="70" y1="260" x2="940" y2="260" class="history-chart-axis" />
        <text x="62" y="40" text-anchor="end">{{ chart.high.toPrecision(4) }}</text><text x="62" y="260" text-anchor="end">{{ chart.low.toPrecision(4) }}</text>
        <text x="70" y="295">{{ timeText('start' in curve.window ? curve.window.start : curve.window.from) }}</text><text x="940" y="295" text-anchor="end">{{ timeText('end' in curve.window ? curve.window.end : curve.window.to) }}</text>
        <path :d="chart.path" class="history-chart-line" /><circle v-for="(dot, index) in chart.dots" :key="index" :cx="dot.x" :cy="dot.y" r="2.5" class="history-chart-dot"><title>{{ dot.title }}</title></circle>
      </svg></div>
      <Empty v-else-if="!curveBusy && !curveFailure" description="此属性在范围内无数值采样" />
    </Card>
    <AppDrawer v-model:open="exportOpen" title="历史导出任务" width-size="lg" :ok-visible="false" cancel-text="关闭">
      <p class="muted">仅显示本次登录创建的任务，模拟登录与本人登录相互独立。每租户最多 2 个同时生成，全局最多 10 个；文件完成后保留 24 小时，下载时重新校验权限。</p>
      <Alert v-if="exportFailure" :message="exportFailure" type="error" show-icon class="page-alert" role="alert" />
      <div v-if="canReadExports" class="toolbar-actions"><Button :loading="exportBusy" :disabled="exportBusy" @click="loadExports()">刷新任务</Button></div>
      <Table v-if="canReadExports" :columns="exportColumns" :data-source="exportTasks?.items || []" row-key="id" :loading="exportBusy" :pagination="false" :scroll="buildTableScrollX(exportColumns)">
        <template #emptyText><Empty :description="exportFailure ? '任务查询未完成，请重试' : '暂无导出任务'" /></template>
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'created'">{{ timeText(record.created_at) }}</template>
          <template v-else-if="column.key === 'kind'">{{ record.kind === 'minutes' ? '分钟统计' : '原始上报' }}</template>
          <Tag v-else-if="column.key === 'status'" :color="exportColors[record.status as ExportTask['status']]">{{ exportNames[record.status as ExportTask['status']] }}</Tag>
          <template v-else-if="column.key === 'progress'">{{ record.completed_rows }} / {{ record.total_rows }} 行</template>
          <template v-else-if="column.key === 'bytes'">{{ (record.file_bytes / 1048576).toFixed(2) }} MiB</template>
          <CrudTableActions v-else-if="column.key === 'actions'" :actions="exportActions(record as ExportTask)" />
        </template>
      </Table>
      <div v-if="canReadExports" class="history-pagination"><span class="muted">共 {{ exportTasks?.total || 0 }} 个 · 第 {{ exportPage }} 页</span><div class="toolbar-actions"><Select v-model:value="exportPageSize" aria-label="导出每页条数" :disabled="exportBusy" :options="[20, 50, 100].map(value => ({ value, label: `${value} 条/页` }))" @change="loadExports(1)" /><Button :disabled="exportBusy || exportPage <= 1" @click="loadExports(exportPage - 1)">上一页</Button><Button :disabled="exportBusy || !exportTasks || exportPage * exportPageSize >= exportTasks.total" @click="loadExports(exportPage + 1)">下一页</Button></div></div>
      <Descriptions v-if="exportDetails" title="任务与冻结筛选" :column="{ xs: 1, sm: 2 }" layout="vertical" bordered class="device-data-card">
        <DescriptionsItem label="任务标识" :span="2"><span class="current-value">{{ exportDetails.id }}</span></DescriptionsItem>
        <DescriptionsItem label="当前状态">{{ exportNames[exportDetails.status] }}</DescriptionsItem><DescriptionsItem label="有效期限">{{ timeText(exportDetails.expires_at) }}</DescriptionsItem>
        <DescriptionsItem label="开始时间">{{ timeText(exportDetails.filters.from) }}</DescriptionsItem><DescriptionsItem label="结束时间">{{ timeText(exportDetails.filters.to) }}</DescriptionsItem>
        <DescriptionsItem label="产品标识"><span class="current-value">{{ exportDetails.filters.product_id || '全部产品' }}</span></DescriptionsItem><DescriptionsItem label="模型版本">{{ exportDetails.filters.model_version || '全部版本' }}</DescriptionsItem>
        <DescriptionsItem label="归属阶段"><span class="current-value">{{ exportDetails.filters.ownership_id || '全部历史归属' }}</span></DescriptionsItem><DescriptionsItem label="属性标识">{{ exportDetails.filters.field || '全部属性' }}</DescriptionsItem>
        <DescriptionsItem label="文件时区">{{ exportDetails.timezone }}</DescriptionsItem><DescriptionsItem label="处理进度">{{ exportDetails.completed_rows }} / {{ exportDetails.total_rows }} 行</DescriptionsItem>
        <DescriptionsItem v-if="exportDetails.error_code" label="失败原因" :span="2">{{ exportErrors[exportDetails.error_code] || '任务处理失败，请重新创建或联系管理员' }}</DescriptionsItem>
      </Descriptions>
    </AppDrawer>
    <AppDrawer :open="Boolean(details)" :title="minuteMode ? '分钟统计详情' : '原始记录详情'" width-size="lg" :ok-visible="false" cancel-text="关闭" @update:open="value => { if (!value) details = null; }">
      <template v-if="details && isMinute(details)">
        <Descriptions :column="{ xs: 1, sm: 2 }" layout="vertical" bordered>
          <DescriptionsItem label="分钟开始">{{ timeText(details.window_start) }}</DescriptionsItem><DescriptionsItem label="分钟结束">{{ timeText(details.window_end) }}</DescriptionsItem>
          <DescriptionsItem label="最近修正">{{ timeText(details.updated_at) }}</DescriptionsItem><DescriptionsItem label="当时模型">版本 {{ details.model_version }}</DescriptionsItem>
          <DescriptionsItem label="产品标识" :span="2">{{ details.product_id }}</DescriptionsItem><DescriptionsItem label="归属阶段" :span="2">{{ details.ownership_id }}</DescriptionsItem>
          <DescriptionsItem v-for="property in details.model.properties.filter(field => ['number', 'integer'].includes(field.type))" :key="property.identifier" :label="`${property.name} · ${property.identifier} · ${property.unit || '无单位'}`" :span="2">
            <div v-if="details.fields[property.identifier]" class="current-value"><p>count {{ details.fields[property.identifier]!.count }} · min {{ details.fields[property.identifier]!.min }} · max {{ details.fields[property.identifier]!.max }}</p><p>sum {{ details.fields[property.identifier]!.sum }} · avg {{ details.fields[property.identifier]!.avg }} · last {{ details.fields[property.identifier]!.last }}</p><p>最后采样 {{ timeText(details.fields[property.identifier]!.last_sampled_at) }} · 序号 {{ details.fields[property.identifier]!.last_sequence }}</p></div><span v-else>此分钟无数值采样</span>
          </DescriptionsItem>
        </Descriptions>
        <p class="muted">avg = sum / count；last 按采样时间、十进制序号确定。合法迟到仍可修正此窗口。</p>
      </template>
      <template v-else-if="details">
        <Descriptions :column="{ xs: 1, sm: 2 }" layout="vertical" bordered>
          <DescriptionsItem label="采样时间">{{ timeText(details.sampled_at) }}</DescriptionsItem><DescriptionsItem label="首次接收">{{ timeText(details.received_at) }}</DescriptionsItem>
          <DescriptionsItem label="业务序号">{{ details.sequence }}</DescriptionsItem><DescriptionsItem label="当时模型">版本 {{ details.model_version }}</DescriptionsItem>
          <DescriptionsItem label="产品标识" :span="2">{{ details.product_id }}</DescriptionsItem><DescriptionsItem label="归属阶段" :span="2">{{ details.ownership_id }}</DescriptionsItem>
          <DescriptionsItem label="消息标识" :span="2">{{ details.message_id }}</DescriptionsItem>
          <DescriptionsItem v-for="property in details.model.properties" :key="property.identifier" :label="`${property.name} · ${property.identifier}`" :span="2"><span class="current-value">{{ textValue(details.values[property.identifier]) }}{{ property.unit ? ` ${property.unit}` : '' }}</span><Tag>{{ property.type }}</Tag></DescriptionsItem>
        </Descriptions>
      </template>
    </AppDrawer>
  </section>
</template>
