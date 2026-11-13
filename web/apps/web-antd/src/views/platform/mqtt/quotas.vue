<script setup lang="ts">
import { displayDateTime } from '../../../display';
/**
 * Broker 配额页：预览并确认在线调整连接、会话与消息额度。
 * 降低额度不删除已确认积压，也不终止可靠会话；租户只读。
 */
import type { VxeTableGridOptions } from '../../../adapter/vxe-table';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { Page, useVbenDrawer } from '@vben/common-ui';
import { useVbenVxeGrid } from '../../../adapter/vxe-table';
import { useVbenForm } from '../../../adapter/form';
import { Alert, Button, Card, Checkbox, Space, Modal, Tag, message } from 'ant-design-vue';
import { ApiError, errorText, isCanceled, isDemo, request, session } from '../../../api/identity';

type LimitKey =
  | 'maximumConnections' | 'maximumDeviceConnections' | 'maximumServiceConnections'
  | 'maximumSessions' | 'maximumSubscriptions' | 'maximumPendingMessages' | 'maximumPendingBytes'
  | 'maximumDeviceMessages' | 'maximumDeviceBytes' | 'maximumApplicationMessages' | 'maximumApplicationBytes'
  | 'maximumSharedMessages' | 'maximumSharedBytes';
type UsageKey =
  | 'connections' | 'deviceConnections' | 'serviceConnections' | 'sessions' | 'liveSubscriptions'
  | 'pendingMessages' | 'pendingBytes' | 'devicePendingMessages' | 'devicePendingBytes'
  | 'applicationPendingMessages' | 'applicationPendingBytes' | 'sharedPendingMessages' | 'sharedPendingBytes';
interface NodeState { node_id: string; applied_version: number; state: string; updated_at: number }
interface Revision {
  id: string; operation_id: string; version: number; actor_id: string; actor_realm: string; lowering: boolean;
  status: string; stage: string; created_at: number; updated_at: number; nodes: NodeState[];
  pending_nodes: number; isolated_nodes: number;
}
interface QuotaPage {
  limits: Record<LimitKey, number>; ceilings: Record<LimitKey, number>; usage: Record<UsageKey, number | null>;
  excess: Record<UsageKey, number | null>; waiting_release: boolean; store_available: boolean;
  current_version: number; publish_paused: boolean; revision: Revision | null; current_limits?: Record<LimitKey, number>; lowering?: boolean;
}
interface RevisionPage { items: Revision[]; total: number; page: number; per_page: number; current_version: number; publish_paused: boolean }
const fields: { key: LimitKey; usage: UsageKey; label: string }[] = [
  { key: 'maximumConnections', usage: 'connections', label: '总连接' },
  { key: 'maximumDeviceConnections', usage: 'deviceConnections', label: '设备连接' },
  { key: 'maximumServiceConnections', usage: 'serviceConnections', label: '服务连接' },
  { key: 'maximumSessions', usage: 'sessions', label: '持久会话' },
  { key: 'maximumSubscriptions', usage: 'liveSubscriptions', label: '每会话订阅' },
  { key: 'maximumPendingMessages', usage: 'pendingMessages', label: '全局积压条数' },
  { key: 'maximumPendingBytes', usage: 'pendingBytes', label: '全局积压字节' },
  { key: 'maximumDeviceMessages', usage: 'devicePendingMessages', label: '设备积压条数' },
  { key: 'maximumDeviceBytes', usage: 'devicePendingBytes', label: '设备积压字节' },
  { key: 'maximumApplicationMessages', usage: 'applicationPendingMessages', label: '应用积压条数' },
  { key: 'maximumApplicationBytes', usage: 'applicationPendingBytes', label: '应用积压字节' },
  { key: 'maximumSharedMessages', usage: 'sharedPendingMessages', label: '共享积压条数' },
  { key: 'maximumSharedBytes', usage: 'sharedPendingBytes', label: '共享积压字节' },
];
const emptyLimits = Object.fromEntries(fields.map(field => [field.key, 1])) as Record<LimitKey, number>;
const emptyUsage = Object.fromEntries(fields.map(field => [field.usage, null])) as Record<UsageKey, number | null>;
const route = useRoute();
const platform = computed(() => session.realm === 'platform');
const tenant = computed(() => session.realm === 'system' && !platform.value ? session.tenant?.id : undefined);
const base = computed(() => '/platform/broker');
const canRead = computed(() => session.permissions.includes('platform.broker.read'));
const canWrite = computed(() => !isDemo() && session.permissions.includes('platform.broker.write'));
const result = ref<QuotaPage>({
  limits: { ...emptyLimits }, ceilings: { ...emptyLimits, maximumConnections: 10100 } as Record<LimitKey, number>,
  usage: { ...emptyUsage }, excess: { ...emptyUsage }, waiting_release: false, store_available: false,
  current_version: 0, publish_paused: false, revision: null,
});
const draft = reactive<Record<LimitKey, number>>({ ...emptyLimits });
const preview = ref<QuotaPage | null>(null);
const revisions = ref<RevisionPage>({ items: [], total: 0, page: 1, per_page: 20, current_version: 0, publish_paused: false });
const busy = ref(false); const failure = ref(''); const denied = ref(false);
const previewBusy = ref(false); const previewFailure = ref('');
const publishing = ref(false); const publishFailure = ref('');
const confirmed = ref(false);
const historyOpen = ref(false); const historyBusy = ref(false); const historyFailure = ref('');
const acting = ref('');
let generation = 0; let pending: AbortController | null = null; let timer: ReturnType<typeof setInterval> | undefined;
const statusNames: Record<string, string> = { pending: '待生效', partial: '部分生效', effective: '已生效', failed: '失败', rolled_back: '已回退' };
const stageNames: Record<string, string> = { accepted: '已受理', executing: '执行中', completed: '已完成', failed: '失败', unknown: '未知' };
const nodeNames: Record<string, string> = { applied: '已应用', pending: '待应用', isolated: '已隔离' };
const paused = computed(() => result.value.publish_paused || !!(revisions.value.items[0] && ['pending', 'partial'].includes(revisions.value.items[0].status)));
const revisionColumns: VxeTableGridOptions<Revision>['columns'] = [
  { title: '版本', field: 'version', width: 80 }, { title: '发布状态', field: 'status', slots: { default: 'cell' }, width: 140 },
  { title: '操作阶段', field: 'stage', slots: { default: 'cell' }, width: 110 }, { title: '降低额度', field: 'lowering', slots: { default: 'cell' }, width: 110 },
  { title: '节点生效', field: 'nodes', slots: { default: 'cell' }, width: 260 },
  { title: '发布时间', field: 'time', slots: { default: 'cell' }, width: 190 },
  { title: '操作', field: 'actions', slots: { default: 'cell' }, fixed: 'right', width: 160 },
];
const revisionPagination = computed(() => ({ currentPage: revisions.value.page, pageSize: revisions.value.per_page, total: revisions.value.total, pageSizes: [20] }));
const time = (value: number) => displayDateTime(value * 1000);
function nodeText(item: Revision) {
  if (item.nodes.length === 0) return '尚无上报节点';
  return item.nodes.map(node => `${node.node_id} · ${nodeNames[node.state] || node.state} · v${node.applied_version}`).join('；');
}
function usageText(value: number | null) { return value === null ? '未知' : String(value); }
function excessText(value: number | null) { return value === null ? '—' : value > 0 ? `超额 ${value}` : '未超额'; }
function payload() {
  const data: Record<string, number> = { expected_version: draftVersion };
  for (const field of fields) data[field.key] = Number(draft[field.key]);
  return data;
}
async function load(background = false) {
  if (!canRead.value || busy.value && !background || document.visibilityState !== 'visible' || session.realm === 'system' && !tenant.value) return;
  const current = ++generation; pending?.abort(); pending = new AbortController(); busy.value = true;
  try {
    const data = await request<QuotaPage>(`${base.value}/quotas`, { tenant: tenant.value, signal: pending.signal });
    if (current === generation) {
      result.value = data; failure.value = ''; denied.value = false;
      if (!preview.value && !publishing.value && !draftDirty) await syncDraft(data.limits);
    }
  } catch (error) {
    if (current === generation && !isCanceled(error)) {
      failure.value = errorText(error); denied.value = error instanceof ApiError && error.status === 403 && error.code !== 'demo_readonly';
    }
  } finally { if (current === generation) busy.value = false; }
}
async function loadRevisions(page = 1, background = false) {
  if (!canRead.value || !historyOpen.value || historyBusy.value && !background || document.visibilityState !== 'visible') return;
  const current = ++generation; historyBusy.value = true;
  try {
    const data = await request<RevisionPage>(`${base.value}/quotas/revisions`, { tenant: tenant.value, params: { page, per_page: 20 } });
    if (historyOpen.value) { revisions.value = data; historyFailure.value = ''; }
  } catch (error) { if (!isCanceled(error)) historyFailure.value = errorText(error); }
  finally { if (current === generation || historyOpen.value) historyBusy.value = false; }
}
async function runPreview() {
  if (previewBusy.value || !canRead.value || !(await configForm.validate()).valid) return;
  previewBusy.value = true; previewFailure.value = ''; confirmed.value = false;
  try {
    preview.value = await request<QuotaPage>(`${base.value}/quotas/preview`, { tenant: tenant.value, method: 'POST', data: payload() });
  } catch (error) {
    preview.value = null;
    if (!isCanceled(error)) previewFailure.value = errorText(error);
    if (error instanceof ApiError && error.status === 409) await load();
  } finally { previewBusy.value = false; }
}
async function publish() {
  if (publishing.value || !canWrite.value || !confirmed.value || !preview.value) return;
  publishing.value = true; publishFailure.value = '';
  try {
    await request(`${base.value}/quotas`, { tenant: tenant.value, method: 'POST', data: { ...payload(), confirmed: true } });
    message.success('额度版本已保存，节点生效后状态会更新'); preview.value = null; confirmed.value = false; draftDirty = false;
    await load(); if (historyOpen.value) await loadRevisions(revisions.value.page);
  } catch (error) {
    if (!isCanceled(error)) publishFailure.value = errorText(error);
    if (error instanceof ApiError && error.status === 409) await load();
  } finally { publishing.value = false; }
}
async function act(item: Revision, action: 'retry' | 'rollback') {
  if (!canWrite.value || acting.value || item.status === 'effective' || item.status === 'rolled_back') return;
  acting.value = item.id + action;
  try {
    await request(`${base.value}/quotas/revisions/${item.id}/${action}`, { tenant: tenant.value, method: 'POST', data: {} });
    message.success(action === 'retry' ? '已重试同一版本' : '已回退并发布新版本'); await load(); await loadRevisions(revisions.value.page);
  } catch (error) { if (!isCanceled(error)) historyFailure.value = errorText(error); }
  finally { acting.value = ''; }
}
function resetDraft() {
  void syncDraft(result.value.limits);
  preview.value = null;
  confirmed.value = false;
  previewFailure.value = '';
  publishFailure.value = '';
}
function showHistory() { historyOpen.value = true; historyFailure.value = ''; void loadRevisions(1); }
function visibility() {
  clearInterval(timer);
  if (document.visibilityState === 'visible') {
    void load(true);
    if (historyOpen.value) void loadRevisions(revisions.value.page, true);
    timer = setInterval(() => { if (!denied.value) { void load(true); if (historyOpen.value) void loadRevisions(revisions.value.page, true); } }, 5000);
  } else { generation++; pending?.abort(); pending = null; busy.value = false; }
}
let draftDirty = false; let syncingDraft = false; let draftVersion = 0;
const [ConfigForm, configForm] = useVbenForm({ showDefaultActions: false, layout: 'vertical', commonConfig: { componentProps: { class: 'w-full' } }, wrapperClass: 'grid-cols-1 md:grid-cols-2 xl:grid-cols-3', schema: fields.map(field => ({ fieldName: field.key, label: field.label, component: 'InputNumber' as const, componentProps: () => ({ min: field.key === 'maximumServiceConnections' ? 0 : 1, max: result.value.ceilings[field.key], precision: 0 }) })), handleValuesChange(values, changed) {
  if (syncingDraft) return;
  for (const field of fields) if (changed.includes(field.key) && values[field.key] !== draft[field.key]) {
    Object.assign(draft, { [field.key]: values[field.key] }); draftDirty = true; preview.value = null; confirmed.value = false;
  }
} });
async function syncDraft(values: typeof draft) {
  syncingDraft = true;
  try { Object.assign(draft, values); await configForm.setValues(values); draftVersion = result.value.current_version; draftDirty = false; }
  finally { syncingDraft = false; }
}
watch(() => [session.generation, session.realm, session.tenant?.id, session.identity?.key, canRead.value, route.path], () => {
  generation++; pending?.abort(); preview.value = null; confirmed.value = false; failure.value = ''; denied.value = false;
  historyOpen.value = false; previewFailure.value = ''; publishFailure.value = '';

  void load();
}, { immediate: true });
onMounted(() => { document.addEventListener('visibilitychange', visibility); visibility(); });
onBeforeUnmount(() => { generation++; pending?.abort(); clearInterval(timer); document.removeEventListener('visibilitychange', visibility); });

const [MqttGrid1] = useVbenVxeGrid({ gridOptions: {} });
const [MqttGrid2] = useVbenVxeGrid({ gridOptions: {} });
const [MqttGrid3] = useVbenVxeGrid({ gridOptions: {} });
interface MqttAction { label: string; visible?: boolean; disabled?: boolean; danger?: boolean; readOnly?: boolean; confirmTitle?: string; confirmContent?: string; onClick: () => void | Promise<unknown> }
function runMqttAction(action: MqttAction) {
  if (action.confirmTitle) Modal.confirm({ title: action.confirmTitle, content: action.confirmContent, onOk: action.onClick });
  else void action.onClick();
}
const [MqttDrawer1, mqttDrawer1] = useVbenDrawer({ onBeforeClose: () => !acting.value, onOpenChange(value) { historyOpen.value = value; } });
watch(historyOpen, value => { if (value) mqttDrawer1.open(); else mqttDrawer1.close(); });
</script>

<template>
  <Page title="MQTT 配额" :description="`平台连接、会话与消息额度 · 当前版本 v${result.current_version}${paused ? ' · 已暂停后续发布' : ''}${result.waiting_release ? ' · 超额等待自然释放' : ''}`" header-class="flex-wrap gap-4">
    <template #extra><Button @click="showHistory">版本生效</Button></template>
    <Alert v-if="failure" class="mb-4" type="error" show-icon role="alert" :message="denied ? failure : `额度刷新失败：${failure}`" />
    <Alert v-if="!canRead" class="mb-4" type="warning" show-icon message="当前账号无权查看 Broker 配额" />
    <Alert v-else-if="canRead && !canWrite" class="mb-4" type="info" show-icon message="当前角色可查看额度与超额，发布、重试和回退需要平台 MQTT 写入权限。" />
    <Alert v-else-if="result.waiting_release" class="mb-4" type="warning" show-icon message="当前用量已高于拟生效或已生效额度；已确认积压会保留到自然释放，新的超额占用将被拒绝。" />
    <Card title="额度设置" :bordered="false" class="mb-4">
      <ConfigForm :common-config="{ disabled: busy || publishing || !canWrite, componentProps: { class: 'w-full' } }" /><Space class="mb-3"><Button type="primary" :loading="previewBusy" :disabled="!canRead || publishing" @click="runPreview">预览调整</Button><Button :disabled="busy || publishing" @click="resetDraft">重置</Button></Space>
      <Alert v-if="previewFailure" :message="previewFailure" type="error" show-icon class="mt-4" />
    </Card>
    <Card title="当前用量" :bordered="false">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-4"><span class="text-muted-foreground leading-7">当前用量{{ result.store_available ? '' : '（持久用量未知）' }}{{ busy ? ' · 刷新中' : '' }}</span><Button :loading="busy" :disabled="!canRead" @click="load()">刷新</Button></div>
      <MqttGrid1 :grid-options="{ columns: [{ title: '额度', field: 'label', width: 180 }, { title: '当前限额', field: 'limit', slots: { default: 'cell' }, width: 140 }, { title: '实际用量', field: 'usage', slots: { default: 'cell' }, width: 140 }, { title: '超额', field: 'excess', slots: { default: 'cell' }, width: 140 }], pagerConfig: { enabled: false }, rowConfig: { keyField: 'key' } }" :table-data="fields">
        <template #cell="{ column, row: record }">
          <template v-if="column.field === 'limit'">{{ result.limits[(record as typeof fields[number]).key] }}</template>
          <template v-else-if="column.field === 'usage'">{{ usageText(result.usage[(record as typeof fields[number]).usage]) }}</template>
          <template v-else-if="column.field === 'excess'"><Tag :color="(result.excess[(record as typeof fields[number]).usage] || 0) > 0 ? 'warning' : 'success'">{{ excessText(result.excess[(record as typeof fields[number]).usage]) }}</Tag></template>
        </template>
      </MqttGrid1>
    </Card>
    <Card v-if="preview" :bordered="false" class="mt-4">
      <template #title>调整预览</template>
      <Alert v-if="previewFailure" :message="previewFailure" type="error" show-icon class="mb-4" role="alert" />
      <Alert v-if="publishFailure" :message="publishFailure" type="error" show-icon class="mb-4" role="alert" />
      <p class="text-muted-foreground leading-7">{{ preview.lowering ? '本次降低额度。已确认积压会保留到自然释放，新的超额占用返回标准配额失败。' : '本次未降低额度。保存成功不等于集群立即生效。' }} Swoole 连接上限仍以启动配置为顶，不会按最大槽位预分配。</p>
      <MqttGrid2 :grid-options="{ columns: [{ title: '额度', field: 'label', width: 180 }, { title: '拟发布', field: 'next', slots: { default: 'cell' }, width: 140 }, { title: '超额', field: 'excess', slots: { default: 'cell' }, width: 140 }], pagerConfig: { enabled: false }, rowConfig: { keyField: 'key' } }" :table-data="fields">
        <template #cell="{ column, row: record }">
          <template v-if="column.field === 'next'">{{ preview?.limits[(record as typeof fields[number]).key] }}</template>
          <template v-else-if="column.field === 'excess'"><Tag :color="(preview?.excess[(record as typeof fields[number]).usage] || 0) > 0 ? 'warning' : 'success'">{{ excessText(preview?.excess[(record as typeof fields[number]).usage] ?? null) }}</Tag></template>
        </template>
      </MqttGrid2>
      <Checkbox v-if="canWrite" v-model:checked="confirmed" :disabled="publishing || paused">确认按此额度拒绝新的超额占用，并保留已确认积压直到自然释放</Checkbox>
      <div v-if="canWrite" class="mt-4 flex flex-wrap items-center gap-3">
        <Button type="primary" :loading="publishing" :disabled="isDemo() || (!confirmed || paused)" @click="publish">发布额度</Button>
      </div>
    </Card>
    <MqttDrawer1 title="额度版本生效" class="w-full max-w-5xl" :footer="false">
      <Alert v-if="historyFailure" :message="historyFailure" type="error" show-icon class="mb-4" role="alert" />
      <MqttGrid3 :grid-options="{ columns: revisionColumns, loading: historyBusy, pagerConfig: revisionPagination, rowConfig: { keyField: 'id' } }" :table-data="revisions.items" :grid-events="{ pageChange: (page: { currentPage: number; pageSize: number }) => loadRevisions(page.currentPage) }">
        <template #cell="{ column, row: record }">
          <template v-if="column.field === 'status'"><Tag>{{ statusNames[record.status] || record.status }}</Tag></template>
          <template v-else-if="column.field === 'stage'">{{ stageNames[record.stage] || record.stage }}</template>
          <template v-else-if="column.field === 'lowering'">{{ record.lowering ? '是' : '否' }}</template>
          <template v-else-if="column.field === 'nodes'">{{ nodeText(record as Revision) }}</template>
          <template v-else-if="column.field === 'time'">{{ time(record.created_at) }}</template>
          <Space v-else-if="column.field === 'actions'"><Button v-for="action in ([{ label: '重试', visible: canWrite, disabled: acting !== '' || ['effective', 'rolled_back'].includes(record.status), onClick: () => act(record as Revision, 'retry') }, { label: '回退', visible: canWrite, disabled: acting !== '' || ['effective', 'rolled_back'].includes(record.status), onClick: () => act(record as Revision, 'rollback') }] as MqttAction[]).filter(item => item.visible !== false)" :key="action.label" type="link" size="small" :danger="action.danger" :disabled="action.disabled || (!action.readOnly && isDemo())" @click="runMqttAction(action)">{{ action.label }}</Button></Space>
        </template>
      </MqttGrid3>
    </MqttDrawer1>
  </Page>
</template>
