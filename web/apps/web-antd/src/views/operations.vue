<script setup lang="ts">
import type { TableColumnsType } from 'ant-design-vue';
import type { Device, Page } from '../api';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Alert, Button, Card, Descriptions, DescriptionsItem, Empty, Input, Table, Tag } from 'ant-design-vue';
import AppDrawer from '../components/app-drawer/app-drawer.vue';
import CrudSearchField from '../components/crud-search-field.vue';
import CrudTableActions from '../components/crud-table-actions.vue';
import { buildTableScrollX, estimateVisibleActionColumnWidth } from '../utils/table';
import { ApiError, errorText, isCanceled, request, session } from '../api';

interface RuntimeNode {
  kind: 'broker' | 'ingestion'; node_id: string; run_id: string; state: 'reporting' | 'stopped' | 'unreachable';
  observed_at: number; expires_at: number; interval_seconds: number | null; received_per_second: number | null;
  receipt_latency_mean_ms: number | null; receipt_samples: number; metrics: Record<string, number | boolean>;
}
interface PlatformView {
  scope: 'platform'; generated_at: number; nodes: RuntimeNode[];
  health: { http_alive: boolean; durable_store_ready: boolean | null };
  store: { state: 'available' | 'unavailable' | 'unsupported'; quarantined: boolean; observed_at: number; metrics: Record<string, number> | null; nodes: { node_id: string; run_id: string; generation: number; state: string; actor: string; proof_ref: string }[] | null };
}
interface ObservedDevice extends Pick<Device, 'id' | 'name' | 'lifecycle' | 'connection'> { authorization: { status: string; requested_at: number | null; completed_at: number | null }; current: { freshness: 'empty' | 'fresh' | 'stale'; received_at: number | null; sampled_at: number | null } }
interface TenantView { scope: 'tenant'; generated_at: number; window_seconds: number; recorded: number; rejected: number; recorded_per_second: number; devices: Page<ObservedDevice> }
const route = useRoute();
const router = useRouter();
const platform = computed(() => route.path === '/admin/operations');
const canRead = computed(() => session.permissions.includes(`${session.realm}.operations.read`));
const canReadDevice = computed(() => session.permissions.includes('customer.devices.read'));
const result = ref<PlatformView | TenantView | null>(null);
const busy = ref(false);
const failure = ref('');
const denied = ref(false);
const now = ref(Date.now() / 1000);
const visible = ref(document.visibilityState === 'visible');
const detail = ref<RuntimeNode | null>(null);
const filters = reactive({ name: '' });
const applied = ref('');
const page = ref(1);
const perPage = ref(20);
let requestVersion = 0;
let pending: AbortController | null = null;
let timer: ReturnType<typeof setInterval> | undefined;
const platformData = computed(() => result.value?.scope === 'platform' ? result.value : null);
const tenantData = computed(() => result.value?.scope === 'tenant' ? result.value : null);
const persistentFresh = computed(() => platformData.value?.store.state === 'available' && now.value < platformData.value.store.observed_at + 15);
const pagination = computed(() => ({ current: page.value, pageSize: perPage.value, total: tenantData.value?.devices.total || 0, showSizeChanger: true, pageSizeOptions: ['20', '50', '100'] }));
const stateNames = { reporting: '正在上报', stopped: '已停止', unreachable: '节点暂不可达', expired: '页面观察已过期' };
const connectionNames = { online: '在线', offline: '已观察到离线', unknown: '连接状态未知' };
const freshnessNames = { empty: '尚无遥测', fresh: '实时数据', stale: '数据陈旧' };
const lifecycleNames = { inactive: '未激活', enabled: '启用', disabled: '禁用', retired: '退役' };
const metricNames: Record<string, string> = {
  connections: '物理连接', accepted: '接纳计数', rejected: '拒绝计数', closed: '关闭计数', stopping: '正在停止', observationFailures: '观察失败',
  subscriptions: '订阅数', bufferedBytes: '发送缓冲字节', deviceConnections: '设备连接', serviceConnections: '服务连接', maximumConnections: '物理连接上限',
  maximumDeviceConnections: '设备连接上限', maximumServiceConnections: '服务连接上限', incomingExchanges: '入站在途', outgoingExchanges: '出站在途',
  pendingCommits: '处理中持久操作', durableCommits: '已确认持久操作', rejectedCommits: '持久操作拒绝', unknownCommits: '提交结果未知', quarantinedCommits: '后端隔离计数',
  connectionQuotaRefusals: '连接配额拒绝', packetQuotaRefusals: '报文配额拒绝', subscriptionQuotaRefusals: '订阅配额拒绝', commitQuotaRefusals: '持久配额拒绝',
  flushTimeouts: '输出超时', handshakeTimeouts: '握手超时', nodeGeneration: '节点代次', nodePending: '节点操作处理中', nodeFailures: '节点操作失败', pendingFences: '等待隔离',
  invalidationFailures: '撤权处理失败', invalidationPending: '撤权处理中', processMemoryBytes: 'PHP分配器当前字节', processPeakMemoryBytes: 'PHP分配器峰值字节',
  received: '应用收到消息', discarded: '无法识别消息', acknowledged: '原交付确认', observed: '设备状态观察', pending: '采样时持久操作处理中', quarantined: '后端隔离', running: '接收进程运行中',
  receiptCount: '已发布持久接收回执', receiptLatencyTotalMs: '回执处理累计毫秒', receiptLatencyMaximumMs: '本次运行最慢回执毫秒', uptimeMs: '本次运行毫秒',
};
const nodeColumns: TableColumnsType<RuntimeNode> = [
  { title: '节点标识', dataIndex: 'node_id', width: 230, ellipsis: true }, { title: '进程角色', key: 'kind', width: 130 },
  { title: '观察状态', key: 'state', width: 165 }, { title: '采样时间', key: 'time', width: 200 },
  { title: '连接 / 接收速率', key: 'value', width: 160 }, { title: '故障计数', key: 'faults', width: 120 },
  { title: '操作', key: 'actions', fixed: 'right', width: estimateVisibleActionColumnWidth([{ label: '详情' }]) },
];
const deviceColumns = computed<TableColumnsType<ObservedDevice>>(() => [
  { title: '设备名称', dataIndex: 'name', width: 220, ellipsis: true }, { title: '生命周期', key: 'lifecycle', width: 120 },
  { title: '连接观察', key: 'connection', width: 180 }, { title: '遥测新鲜度', key: 'freshness', width: 150 },
  { title: '最近有效接收', key: 'time', width: 210 },
  { title: '授权进度', key: 'authorization', width: 140 },
  ...(canReadDevice.value ? [{ title: '操作', key: 'actions', fixed: 'right' as const, width: estimateVisibleActionColumnWidth([{ label: '设备详情' }]) }] : []),
]);
function nodeState(node: RuntimeNode) { return node.state === 'reporting' && now.value >= node.expires_at ? 'expired' : node.state; }
function faults(node: RuntimeNode) { return ['observationFailures', 'unknownCommits', 'quarantinedCommits', 'nodeFailures', 'invalidationFailures', 'quarantined'].reduce((sum, key) => sum + Number(node.metrics[key] || 0), 0); }
function sum(kind: RuntimeNode['kind'], metric: string): number | null {
  const nodes = platformData.value?.nodes.filter(node => node.kind === kind) || [];
  if (!nodes.length || nodes.some(node => nodeState(node) !== 'reporting' || typeof node.metrics[metric] !== 'number')) return null;
  return nodes.reduce((total, node) => total + Number(node.metrics[metric]), 0);
}
const receiveRate = computed(() => {
  const nodes = platformData.value?.nodes.filter(node => node.kind === 'ingestion') || [];
  return !nodes.length || nodes.some(node => nodeState(node) !== 'reporting' || node.received_per_second === null) ? null : nodes.reduce((total, node) => total + Number(node.received_per_second), 0);
});
const meanLatency = computed(() => {
  const nodes = platformData.value?.nodes.filter(node => node.kind === 'ingestion') || [];
  if (!nodes.length || nodes.some(node => nodeState(node) !== 'reporting')) return null;
  const samples = nodes.reduce((total, node) => total + node.receipt_samples, 0);
  return samples > 0 ? nodes.reduce((total, node) => total + (node.receipt_latency_mean_ms || 0) * node.receipt_samples, 0) / samples : null;
});
function number(value: number | null | undefined, digits = 0) { return value === null || value === undefined ? '—' : value.toLocaleString(undefined, { maximumFractionDigits: digits }); }
function time(value: number | null) { return value === null ? '尚无观察' : new Date(value * 1000).toLocaleString(); }
function deviceFreshness(device: ObservedDevice) { return device.current.freshness === 'fresh' && (device.current.received_at === null || now.value - device.current.received_at >= 60) ? 'stale' : device.current.freshness; }
function connectionState(device: ObservedDevice) { return device.connection.status === 'online' && (device.connection.broker_observed_at === null || now.value - device.connection.broker_observed_at >= 15) ? 'unknown' : device.connection.status; }
async function load(nextPage = page.value, nextPerPage = perPage.value) {
  if (busy.value || !visible.value || !canRead.value) return;
  const tenant = session.tenant?.id;
  if (!platform.value && !tenant) { await router.replace('/tenants'); return; }
  const version = ++requestVersion;
  pending = new AbortController(); busy.value = true;
  try {
    const data = platform.value
      ? await request<PlatformView>('/admin/operations', { signal: pending.signal })
      : await request<TenantView>(`/customer/tenants/${tenant}/operations`, { tenant, signal: pending.signal, params: { name: applied.value, page: nextPage, per_page: nextPerPage } });
    if (version === requestVersion) { result.value = data; page.value = nextPage; perPage.value = nextPerPage; failure.value = ''; denied.value = false; now.value = Date.now() / 1000; }
  } catch (error) {
    if (version === requestVersion && !isCanceled(error)) {
      failure.value = errorText(error); denied.value = error instanceof ApiError && error.status === 403;
      if (denied.value) { result.value = null; detail.value = null; }
    }
  } finally { if (version === requestVersion) { busy.value = false; pending = null; } }
}
function search(reset = false) {
  if (busy.value) return;
  if (reset) filters.name = '';
  applied.value = filters.name.trim(); void load(1);
}
function visibility() {
  visible.value = document.visibilityState === 'visible'; clearInterval(timer);
  if (visible.value) {
    now.value = Date.now() / 1000; void load();
    timer = setInterval(() => { now.value = Date.now() / 1000; if (!denied.value) void load(); }, 5000);
  } else pending?.abort();
}
watch(() => [platform.value, session.tenant?.id, session.generation, session.identity?.key, canRead.value], () => {
  requestVersion++; pending?.abort(); busy.value = false; result.value = null; detail.value = null; failure.value = ''; denied.value = false;
  page.value = 1; filters.name = ''; applied.value = ''; void load();
});
onMounted(() => { document.addEventListener('visibilitychange', visibility); visibility(); });
onBeforeUnmount(() => { clearInterval(timer); document.removeEventListener('visibilitychange', visibility); requestVersion++; pending?.abort(); });
</script>

<template>
  <section class="iot-page operations-page">
    <header class="page-heading"><div><h1>运行概览</h1><p class="muted tenant-title">{{ platform ? '平台运维 · 仅包含已观察实例' : session.tenant?.name }} · {{ Intl.DateTimeFormat().resolvedOptions().timeZone }}</p></div><Button :loading="busy" :disabled="!canRead" @click="load()">刷新</Button></header>
    <Alert v-if="!canRead" message="当前账号无权查看此概览" type="warning" show-icon class="page-alert" />
    <Alert v-if="failure" :message="denied ? '当前账号无权查看此概览' : '页面刷新失败'" :description="`${failure}${result ? ' 以下保留上次成功读取的数据，不能据此推定设备离线。' : ''}`" type="error" show-icon role="alert" class="page-alert" />
    <p class="muted" aria-live="polite">{{ !canRead ? '无读取权限，已暂停刷新' : busy ? '正在刷新…' : visible ? '可见时每 5 秒刷新' : '页面隐藏，已暂停刷新' }}<span v-if="result"> · 上次查询 {{ time(result.generated_at) }}</span></p>
    <template v-if="platform">
      <div class="operations-cards">
        <Card title="HTTP 存活 / 持久依赖就绪" :bordered="false"><p>HTTP：{{ platformData?.health.http_alive && now - platformData.generated_at < 15 ? '已响应' : '未知或观察过期' }}</p><p>持久依赖：{{ !platformData || now - platformData.store.observed_at >= 15 ? '未知或观察过期' : platformData.health.durable_store_ready === null ? '当前驱动不支持可靠 MQTT' : platformData.health.durable_store_ready ? '已取得同步证明' : '未就绪' }}</p><p class="muted">HTTP 响应不代表消息服务就绪，节点仍按各自采样判断。</p></Card>
        <Card title="设备连接" :bordered="false"><strong>{{ number(sum('broker', 'deviceConnections')) }}</strong><p class="muted">新鲜节点采样的当前连接</p></Card>
        <Card title="应用接收速率" :bordered="false"><strong>{{ number(receiveRate, 2) }}</strong><span> 条/秒</span><p class="muted">前后两次采样间的实际交付</p></Card>
        <Card title="持久回执处理延迟" :bordered="false"><strong>{{ number(meanLatency, 1) }}</strong><span> ms</span><p class="muted">区间均值 · 无回执样本时留空</p></Card>
        <Card title="持久待投递积压" :bordered="false"><strong>{{ number(persistentFresh ? platformData?.store.metrics?.pendingMessages : null) }}</strong><span> 条</span><p class="muted">{{ persistentFresh ? `${number(platformData?.store.metrics?.pendingBytes)} 字节` : '尚无可确认的持久状态' }}</p></Card>
        <Card title="配额拒绝" :bordered="false"><strong>{{ number(sum('broker', 'commitQuotaRefusals')) }}</strong><p class="muted">本次运行的持久配额拒绝</p></Card>
        <Card title="提交结果未知" :bordered="false"><strong>{{ number(sum('broker', 'unknownCommits')) }}</strong><p class="muted">本次运行累计 · 不作为成功</p></Card>
      </div>
      <Alert v-if="platformData?.store.state === 'unavailable'" message="同步持久状态暂不可确认" description="未取得本次同步证明，持久积压指标留空；不会降级成零值。" type="warning" show-icon class="page-alert" />
      <Alert v-if="platformData?.store.quarantined" message="持久观察已隔离" description="后端资源清理尚未确认，当前服务已停止发起新的持久观察。需要运维核对清理结果后恢复服务。" type="error" show-icon class="page-alert" />
      <Card title="运行节点" :bordered="false">
        <p class="muted">15 秒无新采样显示暂不可达；尚未登记的实例不推定健康。回执延迟从应用取得交付计至持久回执发布获得协议确认，不含此前 Broker 排队时间，不代表设备已收到回执。</p>
        <Table :columns="nodeColumns" :data-source="platformData?.nodes || []" :row-key="(node: RuntimeNode) => `${node.kind}:${node.node_id}`" :loading="busy" :scroll="buildTableScrollX(nodeColumns)" :pagination="{ pageSize: 20, showSizeChanger: false }">
          <template #emptyText><Empty :description="failure ? '数据暂不可用' : '尚无运行节点采样'" /></template>
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'kind'">{{ record.kind === 'broker' ? 'MQTT 接入' : '业务接收' }}</template>
            <Tag v-else-if="column.key === 'state'" :color="nodeState(record as RuntimeNode) === 'reporting' ? 'blue' : 'warning'">{{ stateNames[nodeState(record as RuntimeNode)] }}</Tag>
            <template v-else-if="column.key === 'time'">{{ time(record.observed_at) }}</template>
            <template v-else-if="column.key === 'value'">{{ nodeState(record as RuntimeNode) !== 'reporting' ? '—' : record.kind === 'broker' ? `${number(Number(record.metrics.connections))} 连接` : `${number(record.received_per_second, 2)} 条/秒` }}</template>
            <template v-else-if="column.key === 'faults'"><Tag :color="nodeState(record as RuntimeNode) === 'reporting' && faults(record as RuntimeNode) ? 'error' : undefined">{{ nodeState(record as RuntimeNode) === 'reporting' ? number(faults(record as RuntimeNode)) : '—' }}</Tag></template>
            <CrudTableActions v-else-if="column.key === 'actions'" :actions="[{ label: '详情', onClick: () => { detail = record as RuntimeNode; } }]" />
          </template>
        </Table>
      </Card>
      <Card v-if="platformData?.store.nodes?.length" title="集群节点登记" :bordered="false">
        <p class="muted">登记状态与实时可达分别判断；隔离证据用于核对恢复操作。</p>
        <Descriptions v-for="node in platformData.store.nodes" :key="node.node_id" :column="{ xs: 1, sm: 2 }" layout="vertical" bordered class="registry-node">
          <DescriptionsItem label="稳定节点">{{ node.node_id }}</DescriptionsItem><DescriptionsItem label="运行身份">{{ node.run_id }}</DescriptionsItem>
          <DescriptionsItem label="登记代次">{{ node.generation }}</DescriptionsItem><DescriptionsItem label="登记状态">{{ node.state }}</DescriptionsItem>
          <DescriptionsItem label="隔离操作人">{{ node.actor || '尚无隔离登记' }}</DescriptionsItem><DescriptionsItem label="隔离证据标识">{{ node.proof_ref || '尚无隔离证据' }}</DescriptionsItem>
        </Descriptions>
      </Card>
    </template>
    <template v-else>
      <div class="operations-cards">
        <Card title="首次接收记录速率" :bordered="false"><strong>{{ number(tenantData?.recorded_per_second, 2) }}</strong><span> 条/秒</span><p class="muted">当前租户最近 60 秒的账本记录</p></Card>
        <Card title="首次接收记录" :bordered="false"><strong>{{ number(tenantData?.recorded) }}</strong><p class="muted">记录可见不代表设备已收到持久回执</p></Card>
        <Card title="接收拒绝记录" :bordered="false"><strong>{{ number(tenantData?.rejected) }}</strong><p class="muted">最近 60 秒 · 仅本租户</p></Card>
      </div>
      <Card title="设备观察" :bordered="false">
        <form class="crud-search-grid" @submit.prevent="search()"><CrudSearchField label="设备名称"><Input v-model:value="filters.name" aria-label="设备名称筛选" :disabled="busy" :maxlength="100" allow-clear /></CrudSearchField><div class="crud-search-grid__actions"><Button type="primary" html-type="submit" :loading="busy">查询</Button><Button :disabled="busy" @click="search(true)">重置</Button></div></form>
        <p class="muted">生命周期、连接观察和数据新鲜度分别展示。节点观察过期时连接为未知；60 秒无有效实时遥测为数据陈旧。</p>
        <Table :columns="deviceColumns" :data-source="tenantData?.devices.items || []" row-key="id" :loading="busy" :scroll="buildTableScrollX(deviceColumns)" :pagination="pagination" @change="(value) => load(value.current, value.pageSize)">
          <template #emptyText><Empty :description="failure ? '数据暂不可用' : '暂无符合条件的设备'" /></template>
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'lifecycle'">{{ lifecycleNames[record.lifecycle as Device['lifecycle']] }}</template>
            <Tag v-else-if="column.key === 'connection'" :color="connectionState(record as ObservedDevice) === 'online' ? 'green' : 'warning'">{{ connectionNames[connectionState(record as ObservedDevice)] }}</Tag>
            <Tag v-else-if="column.key === 'freshness'" :color="deviceFreshness(record as ObservedDevice) === 'fresh' ? 'green' : 'warning'">{{ freshnessNames[deviceFreshness(record as ObservedDevice)] }}</Tag>
            <template v-else-if="column.key === 'time'">{{ time(record.current.received_at) }}</template>
            <Tag v-else-if="column.key === 'authorization'" :color="record.authorization.status === 'pending' ? 'processing' : undefined">{{ record.authorization.status === 'pending' ? '等待执行' : '已生效' }}</Tag>
            <CrudTableActions v-else-if="column.key === 'actions'" :actions="[{ label: '设备详情', visible: canReadDevice, onClick: () => router.push(`/devices/${record.id}`) }]" />
          </template>
        </Table>
      </Card>
    </template>
    <AppDrawer :open="Boolean(detail)" title="节点观察详情" width-size="md" :show-footer="false" @update:open="(open) => { if (!open) detail = null; }">
      <template v-if="detail"><Alert v-if="nodeState(detail) !== 'reporting'" message="以下是历史采样，不能作为当前正常状态" type="warning" show-icon class="page-alert" /><Descriptions :column="{ xs: 1, sm: 2 }" layout="vertical" bordered>
        <DescriptionsItem label="稳定节点">{{ detail.node_id }}</DescriptionsItem><DescriptionsItem label="运行身份">{{ detail.run_id }}</DescriptionsItem><DescriptionsItem label="采样时间">{{ time(detail.observed_at) }}</DescriptionsItem><DescriptionsItem label="采样区间">{{ number(detail.interval_seconds, 2) }} 秒</DescriptionsItem>
        <DescriptionsItem v-for="(value, key) in detail.metrics" :key="key" :label="metricNames[key] || key">{{ typeof value === 'boolean' ? value ? '是' : '否' : number(value, 2) }}</DescriptionsItem>
      </Descriptions><p class="muted">计数从当前运行开始；重启切换运行身份。内存字段为 PHP 分配器字节，不是进程 RSS。</p></template>
    </AppDrawer>
  </section>
</template>

<style scoped>
.operations-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr)); gap: 16px; margin-bottom: 20px; }
.operations-page > .muted { margin-bottom: 16px; }
.operations-cards strong { font-size: 28px; font-weight: 600; font-variant-numeric: tabular-nums; }
.operations-cards p { margin: 8px 0 0; }
.registry-node { margin-top: 12px; overflow-wrap: anywhere; }
</style>
