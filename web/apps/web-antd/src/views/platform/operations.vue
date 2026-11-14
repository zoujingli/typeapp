<script setup lang="ts">
import { displayDateTime } from '../../display';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Alert, Button, Card, Descriptions, DescriptionsItem, Statistic, Tag } from 'ant-design-vue';
import { Page, useVbenDrawer } from '@vben/common-ui';
import { useVbenVxeGrid } from '../../adapter/vxe-table';
import { ApiError, errorText, isCanceled, request, session } from '../../api/identity';
interface RuntimeNode {
  kind: 'broker'; node_id: string; run_id: string; state: 'reporting' | 'stopped' | 'unreachable';
  observed_at: number; expires_at: number; interval_seconds: number | null; metrics: Record<string, number | boolean>;
}
interface PlatformView {
  scope: 'platform'; generated_at: number; nodes: RuntimeNode[];
  health: { http_alive: boolean; durable_store_ready: boolean | null };
  store: { state: 'available' | 'unavailable' | 'unsupported'; quarantined: boolean; observed_at: number; metrics: Record<string, number> | null; nodes: { node_id: string; run_id: string; generation: number; state: string; actor: string; proof_ref: string }[] | null };
}

const result = ref<PlatformView | null>(null); const platformData = computed(() => result.value);
const busy = ref(false); const failure = ref(''); const denied = ref(false); const now = ref(Date.now() / 1000); const visible = ref(!document.hidden); const detail = ref<RuntimeNode | null>(null);
const canRead = computed(() => session.permissions.includes('platform.operations.read'));
const persistentFresh = computed(() => platformData.value?.store.state === 'available' && now.value < platformData.value.store.observed_at + 15);
const stateNames = { reporting: '正在上报', stopped: '已停止', unreachable: '节点暂不可达', expired: '页面观察已过期' };
let generation = 0; let pending: AbortController | undefined; let timer: ReturnType<typeof setInterval> | undefined;
const metricNames: Record<string, string> = {
  connections: '物理连接', accepted: '接纳计数', rejected: '拒绝计数', closed: '关闭计数', stopping: '正在停止', observationFailures: '观察失败',
  subscriptions: '订阅数', bufferedBytes: '发送缓冲字节', deviceConnections: '设备连接', serviceConnections: '服务连接', maximumConnections: '物理连接上限',
  maximumDeviceConnections: '设备连接上限', maximumServiceConnections: '服务连接上限', incomingExchanges: '入站在途', outgoingExchanges: '出站在途',
  pendingCommits: '处理中持久操作', durableCommits: '已确认持久操作', rejectedCommits: '持久操作拒绝', unknownCommits: '提交结果未知', quarantinedCommits: '后端隔离计数',
  connectionQuotaRefusals: '连接配额拒绝', packetQuotaRefusals: '报文配额拒绝', subscriptionQuotaRefusals: '订阅配额拒绝', commitQuotaRefusals: '持久配额拒绝',
  flushTimeouts: '输出超时', handshakeTimeouts: '握手超时', nodeGeneration: '节点代次', nodePending: '节点操作处理中', nodeFailures: '节点操作失败', pendingFences: '等待隔离',
  invalidationFailures: '撤权处理失败', invalidationPending: '撤权处理中', processMemoryBytes: 'PHP分配器当前字节', processPeakMemoryBytes: 'PHP分配器峰值字节',
  uptimeMs: '本次运行毫秒',
};
function nodeState(node: RuntimeNode) { return node.state === 'reporting' && now.value >= node.expires_at ? 'expired' : node.state; }
function faults(node: RuntimeNode) { return ['observationFailures', 'unknownCommits', 'quarantinedCommits', 'nodeFailures', 'invalidationFailures', 'quarantined'].reduce((sum, key) => sum + Number(node.metrics[key] || 0), 0); }
function sum(kind: RuntimeNode['kind'], metric: string): number | null {
  const nodes = platformData.value?.nodes.filter(node => node.kind === kind) || [];
  if (!nodes.length || nodes.some(node => nodeState(node) !== 'reporting' || typeof node.metrics[metric] !== 'number')) return null;
  return nodes.reduce((total, node) => total + Number(node.metrics[metric]), 0);
}
function number(value: number | null | undefined, digits = 0) { return value === null || value === undefined ? '—' : value.toLocaleString(undefined, { maximumFractionDigits: digits }); }
function time(value: number | null) { return value === null ? '尚无观察' : displayDateTime(value * 1000); }

const [Grid] = useVbenVxeGrid<RuntimeNode>({ gridOptions: { columns: [
  { field: 'node_id', title: '节点标识', minWidth: 230 }, { field: 'kind', title: '进程角色', width: 130, slots: { default: 'cell' } },
  { field: 'state', title: '观察状态', width: 165, slots: { default: 'cell' } }, { field: 'time', title: '采样时间', width: 200, slots: { default: 'cell' } },
  { field: 'value', title: '连接数', width: 160, slots: { default: 'cell' } }, { field: 'faults', title: '故障计数', width: 120, slots: { default: 'cell' } },
  { field: 'actions', title: '操作', fixed: 'right', width: 90, slots: { default: 'cell' } },
], rowConfig: { keyField: 'node_id' }, pagerConfig: { pageSize: 20, pageSizes: [20, 50, 100] } } });
const [DetailDrawer, drawer] = useVbenDrawer({ title: '节点观察详情', footer: false, class: 'w-full max-w-3xl', onClosed() { detail.value = null; } });
watch(detail, value => { if (value) drawer.open(); else drawer.close(); });
async function load() {
  if (busy.value || !visible.value || !canRead.value) return;
  const current = ++generation; pending = new AbortController(); busy.value = true;
  try { const data = await request<PlatformView>('/platform/operations', { signal: pending.signal }); if (current === generation) { result.value = data; failure.value = ''; denied.value = false; now.value = Date.now() / 1000; } }
  catch (error) { if (current === generation && !isCanceled(error)) { failure.value = errorText(error); denied.value = error instanceof ApiError && error.status === 403; if (denied.value) { result.value = null; detail.value = null; } } }
  finally { if (current === generation) busy.value = false; }
}
function visibility() {
  visible.value = !document.hidden; clearInterval(timer);
  if (visible.value) { now.value = Date.now() / 1000; void load(); timer = setInterval(() => { now.value = Date.now() / 1000; if (!denied.value) void load(); }, 5000); }
  else { generation++; pending?.abort(); busy.value = false; }
}
onMounted(() => { document.addEventListener('visibilitychange', visibility); visibility(); });
onBeforeUnmount(() => { generation++; pending?.abort(); clearInterval(timer); document.removeEventListener('visibilitychange', visibility); });
</script>

<template>
  <Page auto-content-height title="运行概览" header-class="flex-wrap gap-4">
    <template #extra><Button :loading="busy" :disabled="!canRead" @click="load">刷新</Button></template>
    <Alert v-if="!canRead" message="当前账号无权查看此概览" type="warning" show-icon class="mb-4" />
    <Alert v-if="failure" :message="denied ? '当前账号无权查看此概览' : '页面刷新失败'" :description="`${failure}${result ? ' 以下保留上次成功读取的数据，不能据此推定设备离线。' : ''}`" type="error" show-icon role="alert" class="mb-4" />
    <p class="mb-4 text-sm text-muted-foreground" aria-live="polite">{{ !canRead ? '无读取权限，已暂停刷新' : busy ? '正在刷新…' : visible ? '可见时每 5 秒刷新' : '页面隐藏，已暂停刷新' }}<span v-if="result"> · 上次查询 {{ time(result.generated_at) }}</span></p>

      <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Card :bordered="false"><Statistic title="设备连接" :value="number(sum('broker', 'deviceConnections'))" /><p class="mt-2 text-sm text-muted-foreground">新鲜节点采样的当前连接</p></Card>
        <Card :bordered="false"><Statistic title="持久待投递积压" :value="number(persistentFresh ? platformData?.store.metrics?.pendingMessages : null)" suffix="条" /><p class="mt-2 text-sm text-muted-foreground">{{ persistentFresh ? `${number(platformData?.store.metrics?.pendingBytes)} 字节` : '尚无可确认的持久状态' }}</p></Card>
        <Card :bordered="false"><Statistic title="配额拒绝" :value="number(sum('broker', 'commitQuotaRefusals'))" /><p class="mt-2 text-sm text-muted-foreground">本次运行的持久配额拒绝</p></Card>
        <Card :bordered="false"><Statistic title="提交结果未知" :value="number(sum('broker', 'unknownCommits'))" /><p class="mt-2 text-sm text-muted-foreground">本次运行累计</p></Card>
      </div>
      <Card title="服务就绪" :bordered="false" class="mb-4"><Descriptions class="break-all" :column="{ xs: 1, sm: 2 }" layout="vertical"><DescriptionsItem label="HTTP">{{ platformData?.health.http_alive && now - platformData.generated_at < 15 ? '已响应' : '未知或观察过期' }}</DescriptionsItem><DescriptionsItem label="持久依赖">{{ !platformData || now - platformData.store.observed_at >= 15 ? '未知或观察过期' : platformData.health.durable_store_ready === null ? '当前驱动不支持可靠 MQTT' : platformData.health.durable_store_ready ? '已取得同步证明' : '未就绪' }}</DescriptionsItem></Descriptions><p class="text-sm text-muted-foreground">HTTP 响应不代表消息服务就绪，节点仍按各自采样判断。</p></Card>
      <Alert v-if="platformData?.store.state === 'unavailable'" message="同步持久状态暂不可确认" description="未取得本次同步证明，持久积压指标留空；不会降级成零值。" type="warning" show-icon class="mb-4" />
      <Alert v-if="platformData?.store.quarantined" message="持久观察已隔离" description="后端资源清理尚未确认，当前服务已停止发起新的持久观察。需要运维核对清理结果后恢复服务。" type="error" show-icon class="mb-4" />
        <Grid table-title="运行节点" table-title-help="15 秒无新采样显示暂不可达。协议积压及确认不代表应用业务执行结果。" :table-data="platformData?.nodes || []" :grid-options="{ loading: busy }">
          <template #cell="{ column, row: record }">
            <template v-if="column.field === 'kind'">MQTT 接入</template>
            <Tag v-else-if="column.field === 'state'" :color="nodeState(record as RuntimeNode) === 'reporting' ? 'blue' : 'warning'">{{ stateNames[nodeState(record as RuntimeNode)] }}</Tag>
            <template v-else-if="column.field === 'time'">{{ time(record.observed_at) }}</template>
            <template v-else-if="column.field === 'value'">{{ nodeState(record as RuntimeNode) !== 'reporting' ? '—' : `${number(Number(record.metrics.connections))} 连接` }}</template>
            <template v-else-if="column.field === 'faults'"><Tag :color="nodeState(record as RuntimeNode) === 'reporting' && faults(record as RuntimeNode) ? 'error' : undefined">{{ nodeState(record as RuntimeNode) === 'reporting' ? number(faults(record as RuntimeNode)) : '—' }}</Tag></template>
            <Button v-else-if="column.field === 'actions'" type="link" size="small" @click="detail = record">详情</Button>
          </template>
        </Grid>
      <Card v-if="platformData?.store.nodes?.length" title="集群节点登记" :bordered="false" class="mt-4">
        <p class="text-muted-foreground leading-7">登记状态与实时可达分别判断；隔离证据用于核对恢复操作。</p>
        <Descriptions v-for="node in platformData.store.nodes" :key="node.node_id" :column="{ xs: 1, sm: 2 }" layout="vertical" bordered class="break-all registry-node">
          <DescriptionsItem label="稳定节点">{{ node.node_id }}</DescriptionsItem><DescriptionsItem label="运行身份">{{ node.run_id }}</DescriptionsItem>
          <DescriptionsItem label="登记代次">{{ node.generation }}</DescriptionsItem><DescriptionsItem label="登记状态">{{ node.state }}</DescriptionsItem>
          <DescriptionsItem label="隔离操作人">{{ node.actor || '尚无隔离登记' }}</DescriptionsItem><DescriptionsItem label="隔离证据标识">{{ node.proof_ref || '尚无隔离证据' }}</DescriptionsItem>
        </Descriptions>
      </Card>
    <DetailDrawer>
      <template v-if="detail"><Alert v-if="nodeState(detail) !== 'reporting'" message="以下是历史采样，不能作为当前正常状态" type="warning" show-icon class="mb-4" /><Descriptions class="break-all" :column="{ xs: 1, sm: 2 }" layout="vertical" bordered>
        <DescriptionsItem label="稳定节点">{{ detail.node_id }}</DescriptionsItem><DescriptionsItem label="运行身份">{{ detail.run_id }}</DescriptionsItem><DescriptionsItem label="采样时间">{{ time(detail.observed_at) }}</DescriptionsItem><DescriptionsItem label="采样区间">{{ number(detail.interval_seconds, 2) }} 秒</DescriptionsItem>
        <DescriptionsItem v-for="(value, key) in detail.metrics" :key="key" :label="metricNames[key] || key">{{ typeof value === 'boolean' ? value ? '是' : '否' : number(value, 2) }}</DescriptionsItem>
      </Descriptions><p class="text-muted-foreground leading-7">计数从当前运行开始；重启切换运行身份。内存字段为 PHP 分配器字节，不是进程 RSS。</p></template>
    </DetailDrawer>
  </Page>
</template>

<style scoped>
.registry-node { margin-top: 12px; overflow-wrap: anywhere; }
</style>
