<script setup lang="ts">
import { displayDateTime } from '../../../display';
import type { VxeTableGridOptions } from '../../../adapter/vxe-table';
import { Page as VbenPage, useVbenDrawer } from '@vben/common-ui';
import { useVbenVxeGrid } from '../../../adapter/vxe-table';
import type { Page } from '../../../api/contracts';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Alert, Button, Card, Descriptions, DescriptionsItem, Tag } from 'ant-design-vue';
import { ApiError, errorText, isCanceled, request } from '../../../api/identity';

interface Node {
  node_id: string; run_id: string; state: 'isolated' | 'reporting' | 'stopped' | 'unreachable'; observed_at: number; expires_at: number;
  listener: { host: string; port: number; transport: string }; metrics: Record<string, number | null>;
}
interface Health {
  ready: boolean; state: string; observed_at: number;
  store: { state: string; quarantined: boolean; metrics: Record<string, number> | null };
}
interface Compat {
  binary_epoch: number; runtime_epoch: number; min_runtime_epoch: number; management_head: string; store_ready: number;
  recorded: boolean; compatible: boolean; rollback_allowed: boolean; rollback_reason: string; updated_at: number;
  maintenance: { keep_current_artifact: string; restore_via_b19: string; do_not_drop_state: string };
}
interface Recovery {
  state: string; host?: string; id?: string; kind_index?: number; cursor?: string;
  started_at?: number; reviewed_at?: number | null; completed_at?: number | null;
  subjects?: Record<string, { total: number; approved: number }>;
}
type NodesPage = Page<Node> & { generated_at: number; health: Health };
const result = ref<NodesPage | null>(null);
const compat = ref<Compat | null>(null);
const recovery = ref<Recovery | null>(null);
const busy = ref(false);
const failure = ref('');
const denied = ref(false);
const detail = ref<Node | null>(null);
const filters = reactive({ node_id: '' });
const applied = ref('');
const now = ref(Date.now() / 1000);
let pending: AbortController | null = null;
let timer: ReturnType<typeof setInterval> | undefined;
let version = 0;
const names = { reporting: '正在上报', stopped: '已停止', isolated: '已登记隔离', unreachable: '节点暂不可达', expired: '页面观察已过期' };
const recoveryNames: Record<string, string> = { none: '无进行中的恢复核对', isolating: '正在隔离未核对对象', reviewing: '等待当前授权清单核对', restoring: '正在恢复已核对对象', ready: '恢复核对已完成' };
const metricNames: Record<string, string> = { connections: '当前连接', accepted: '接纳计数', rejected: '协议拒绝计数', authenticationRefusals: '认证拒绝计数', subscriptions: '订阅数', bufferedBytes: '收发缓冲字节', incomingExchanges: '入站在途交换', outgoingExchanges: '出站在途交换', pendingCommits: '持久操作处理中', unknownCommits: '提交结果未知', quarantinedCommits: '待确认清理额度', flushTimeouts: '慢发送截止次数', maximumConnections: '物理连接预算', processMemoryBytes: '当前分配内存字节', processPeakMemoryBytes: '峰值分配内存字节' };
const healthNames: Record<string, string> = { ready: '可靠接收已就绪', no_reporting_nodes: '没有正在上报的节点', node_unavailable: '节点不可达或持久路径尚不可用', store_unavailable: '持久存储尚不可用', capacity_exhausted: '全局持久积压已满', management_unavailable: '管理依赖尚不可用' };
const storeNames: Record<string, string> = { available: '同步检查通过', unconfigured: '未配置', rejected: '同步检查被拒绝', unknown: '结果未知', quarantined: '资源清理待确认', unavailable: '不可用' };
const healthExpired = computed(() => !!result.value && now.value - result.value.health.observed_at >= 15);
const columns: VxeTableGridOptions<Node>['columns'] = [
  { title: '节点标识', field: 'node_id', width: 230 }, { title: '监听地址', field: 'listener', slots: { default: 'cell' }, width: 260 },
  { title: '观察状态', field: 'state', slots: { default: 'cell' }, width: 170 }, { title: '当前连接', field: 'connections', slots: { default: 'cell' }, width: 120 }, { title: '采样时间', field: 'time', slots: { default: 'cell' }, width: 200 },
  { title: '操作', field: 'actions', slots: { default: 'cell' }, fixed: 'right', width: 90 },
];
const pagination = computed(() => ({ currentPage: result.value?.page || 1, pageSize: result.value?.per_page || 20, total: result.value?.total || 0, pageSizes: [20, 50, 100] }));
const [Grid, grid] = useVbenVxeGrid<Node>({ formOptions: { handleSubmit: values => { filters.node_id = values.node_id || ''; search(); }, handleReset: async () => { if (busy.value) return; await grid.formApi.resetForm(); search(true); }, schema: [{ fieldName: 'node_id', label: '节点标识', component: 'Input', componentProps: { maxlength: 64 } }] }, gridOptions: { rowConfig: { keyField: 'run_id' } }, gridEvents: { pageChange: ({ currentPage, pageSize }: { currentPage: number; pageSize: number }) => load(currentPage, pageSize) } });
const [DetailDrawer, drawer] = useVbenDrawer({ title: '节点观察详情', footer: false, class: 'w-full max-w-3xl', onClosed() { detail.value = null; } });
watch(detail, value => { if (value) drawer.open(); else drawer.close(); });
function state(node: Node) { return node.state === 'reporting' && now.value >= node.expires_at ? 'expired' : node.state; }
function time(value: number) { return displayDateTime(value * 1000); }
async function load(page = result.value?.page || 1, perPage = result.value?.per_page || 20) {
  if (busy.value || document.visibilityState !== 'visible') return;
  const current = ++version;
  pending = new AbortController(); busy.value = true;
  try {
    const [data, currentCompat, currentRecovery] = await Promise.all([
      request<NodesPage>('/platform/broker/nodes', { signal: pending.signal, params: { node_id: applied.value, page, per_page: perPage } }),
      request<Compat>('/platform/broker/compat', { signal: pending.signal }),
      request<Recovery>('/platform/broker/recovery', { signal: pending.signal }),
    ]);
    if (current === version) { result.value = data; compat.value = currentCompat; recovery.value = currentRecovery; denied.value = false; failure.value = ''; now.value = Date.now() / 1000; }
  } catch (error) {
    if (current === version && !isCanceled(error)) {
      failure.value = errorText(error); denied.value = error instanceof ApiError && error.status === 403 && error.code !== 'demo_readonly';
      if (denied.value) { result.value = null; compat.value = null; recovery.value = null; detail.value = null; }
    }
  } finally { if (current === version) { busy.value = false; pending = null; } }
}
function search(reset = false) {
  if (busy.value) return;
  if (reset) filters.node_id = '';
  applied.value = filters.node_id.trim(); void load(1);
}
function visibility() {
  clearInterval(timer);
  if (document.visibilityState === 'visible') {
    now.value = Date.now() / 1000; void load();
    timer = setInterval(() => { now.value = Date.now() / 1000; if (!denied.value) void load(); }, 5000);
  } else { version++; pending?.abort(); pending = null; busy.value = false; }
}
onMounted(() => { document.addEventListener('visibilitychange', visibility); visibility(); });
onBeforeUnmount(() => { version++; pending?.abort(); clearInterval(timer); document.removeEventListener('visibilitychange', visibility); });
</script>

<template>
  <VbenPage title="MQTT 节点">
    <Alert v-if="failure" class="mb-4" type="error" show-icon role="alert" :message="denied ? failure : `页面刷新失败：${failure}`" />
    <Alert v-if="result" class="mb-4" :type="result.health.ready && !healthExpired ? 'success' : 'warning'" show-icon
      :message="healthExpired ? '就绪观察已过期，当前结果未知' : (healthNames[result.health.state] || '接收状态未知')">
      <template #description>
        <span>存储：{{ healthExpired ? '未知' : (storeNames[result.health.store.state] || '未知') }}；检查时间：{{ time(result.health.observed_at) }}。</span>
        <span v-if="result.health.store.metrics">持久占用 {{ result.health.store.metrics.pendingMessages }} 条 / {{ result.health.store.metrics.pendingBytes }} 字节（原件与交付副本合计），预算 {{ result.health.store.metrics.maximumPendingMessages }} 条 / {{ result.health.store.metrics.maximumPendingBytes }} 字节{{ healthExpired ? '（历史观察）' : '' }}。</span>
        <span v-if="result.health.store.quarantined">本实例仍有资源未确认清理，后续检查已暂停。</span>
      </template>
    </Alert>
    <Alert v-if="compat" class="mb-4" :type="compat.rollback_allowed ? 'success' : 'warning'" show-icon
      :message="compat.rollback_allowed ? '当前库允许回退到本应用代次' : '危险回退已阻止'">
      <template #description>
        <span>应用代次 {{ compat.binary_epoch }}，库代次 {{ compat.runtime_epoch }}（最低 {{ compat.min_runtime_epoch }}），管理计划 {{ compat.management_head || '尚未记录' }}。</span>
        <span>{{ compat.rollback_reason }}</span>
      </template>
    </Alert>
    <Alert v-if="recovery" class="mb-4" :type="recovery.state === 'ready' || recovery.state === 'none' ? 'success' : 'warning'" show-icon
      :message="recoveryNames[recovery.state] || '恢复核对状态未知'">
      <template #description>
        <span>核对宿主 {{ '平台管理' }}{{ recovery.id ? `，恢复标识 ${recovery.id}` : '' }}。</span>
        <span v-if="recovery.subjects">未匹配对象保持隔离，不能把只读暂停或旧快照标成恢复完成。</span>
      </template>
    </Alert>
    <Card v-if="compat" :bordered="false" class="mb-4">
      <Descriptions class="break-all" bordered :column="{ xs: 1, sm: 2 }" layout="vertical">
        <DescriptionsItem label="应用代次">{{ compat.binary_epoch }}</DescriptionsItem>
        <DescriptionsItem label="库代次">{{ compat.runtime_epoch }}</DescriptionsItem>
        <DescriptionsItem label="持久存储">{{ compat.store_ready ? '已取得同步证明' : '尚未标记' }}</DescriptionsItem>
        <DescriptionsItem label="回退">{{ compat.rollback_allowed ? '允许' : '已阻止' }}</DescriptionsItem>
      </Descriptions>
      <ol class="text-muted-foreground leading-7">
        <li>{{ compat.maintenance.keep_current_artifact }}</li>
        <li>{{ compat.maintenance.restore_via_b19 }}</li>
        <li>{{ compat.maintenance.do_not_drop_state }}</li>
      </ol>
    </Card>
    <Grid table-title="节点观察" :grid-options="{ columns, loading: busy, pagerConfig: pagination }" :table-data="result?.items || []">
      <template #toolbar-tools><Button :loading="busy" @click="load()">刷新</Button></template>
        <template #cell="{ column, row: record }">
          <template v-if="column.field === 'listener'">{{ record.listener.transport.toUpperCase() }} · {{ record.listener.host }}:{{ record.listener.port }}</template>
          <template v-else-if="column.field === 'state'"><Tag :color="state(record as Node) === 'reporting' ? 'success' : state(record as Node) === 'stopped' ? 'default' : 'warning'">{{ names[state(record as Node)] }}</Tag></template>
          <template v-else-if="column.field === 'connections'">{{ state(record as Node) === 'reporting' ? (record.metrics.connections ?? '未知') : '—' }}</template>
          <template v-else-if="column.field === 'time'">{{ time(record.observed_at) }}</template>
          <template v-else-if="column.field === 'actions'"><Button type="link" size="small" @click="detail = record">详情</Button></template>
        </template>
      </Grid>
    <p class="mt-3 text-sm text-muted-foreground">{{ result ? `采样查询时间：${time(result.generated_at)}` : '等待首次查询' }}</p>
    <DetailDrawer>
      <Alert v-if="detail" class="mb-4" type="info" show-icon message="详情保留打开时的采样。节点暂不可达或采样过期时，数值仅作历史观察。" />
      <Descriptions class="break-all" v-if="detail" bordered :column="{ xs: 1, sm: 2 }" layout="vertical">
        <DescriptionsItem label="节点标识">{{ detail.node_id }}</DescriptionsItem>
        <DescriptionsItem label="运行身份">{{ detail.run_id }}</DescriptionsItem>
        <DescriptionsItem label="采样状态">{{ names[state(detail)] }}</DescriptionsItem>
        <DescriptionsItem label="采样时间">{{ time(detail.observed_at) }}</DescriptionsItem>
        <DescriptionsItem v-for="(label, key) in metricNames" :key="key" :label="label">{{ detail.metrics[key] ?? '未知' }}</DescriptionsItem>
      </Descriptions>
    </DetailDrawer>
  </VbenPage>
</template>
