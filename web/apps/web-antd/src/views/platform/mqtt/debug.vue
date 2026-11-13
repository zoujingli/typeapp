<script setup lang="ts">
import { displayDateTime } from '../../../display';
/**
 * Broker 调试订阅页：签发当前平台会话专用前缀内的短期 WSS 凭据并实时接收。
 * 口令只在签发响应出现一次；退出、换租户或离开页面立即断开。接收窗口最多 1000 条或 8 MiB。
 * MQTT 回执不是业务成功；调试名额每人 2、全局 20，业务服务可抢占并说明原因。
 */
import type { IClientOptions, MqttClient } from 'mqtt';
import type { VxeTableGridOptions } from '../../../adapter/vxe-table';
import { Page } from '@vben/common-ui';
import { useVbenVxeGrid } from '../../../adapter/vxe-table';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { Alert, Button, Card, Descriptions, DescriptionsItem, Input, Select, Space, message } from 'ant-design-vue';
import mqtt from 'mqtt';
import { ApiError, errorText, isCanceled, isDemo, request, session } from '../../../api/identity';

interface Transport {
  available: boolean; url: string; protocol: string; clean_start: boolean; session_expiry: number; keep_alive: number;
  listen: string; wss_port: number;
}
interface Occupancy { person_used: number; person_limit: number; global_used: number; global_limit: number }
interface Credential {
  id: string; username: string; client_id: string; expires_at: number; subscribe_topic: string; publish_topic: string;
  status: string; password?: string; recovery_verified?: number; transport: Transport; occupancy?: Occupancy;
}
interface DebugPage { credential: Credential | null; transport: Transport; occupancy: Occupancy }
interface Recovery {
  state: string; host?: string;
  subjects?: Record<string, { total: number; approved: number }>;
}
interface DebugMessage { id: number; at: number; topic: string; payload: string; bytes: number }
interface PublishRecord { id: number; at: number; topic: string; qos: number; outcome: 'unknown' | 'mqtt_ack' | 'denied'; detail: string }
const emptyTransport = (): Transport => ({
  available: false, url: '', protocol: 'mqtt', clean_start: true, session_expiry: 0, keep_alive: 30, listen: '', wss_port: 0,
});
const emptyOccupancy = (): Occupancy => ({ person_used: 0, person_limit: 2, global_used: 0, global_limit: 20 });
const route = useRoute();
const platform = computed(() => session.realm === 'platform');
const tenant = computed(() => session.realm === 'system' && !platform.value ? session.tenant?.id : undefined);
const base = computed(() => '/platform/broker');
const canRead = computed(() => session.permissions.includes('platform.broker.read'));
const canIssue = computed(() => canRead.value && session.permissions.includes('platform.broker.write') && !isDemo());
const page = ref<DebugPage>({ credential: null, transport: emptyTransport(), occupancy: emptyOccupancy() });
const secret = ref('');
const status = ref<'idle' | 'connecting' | 'connected' | 'interrupted'>('idle');
const failure = ref(''); const denied = ref(false);
const busy = ref(false); const publishing = ref(false);
const testPayload = ref('debug-ping');
const publishQos = ref<0 | 1>(0);
const rows = ref<DebugMessage[]>([]);
const publishes = ref<PublishRecord[]>([]);
const evicted = ref(0);
let generation = 0; let pending: AbortController | null = null;
let client: MqttClient | null = null; let nextId = 1; let nextPublish = 1; let buffered = 0; let refreshTimer: ReturnType<typeof setInterval> | undefined;
let expiryTimer: ReturnType<typeof setTimeout> | undefined;
const columns: VxeTableGridOptions<DebugMessage>['columns'] = [
  { title: '时间', field: 'time', slots: { default: 'cell' }, width: 180 }, { title: 'Topic', field: 'topic' },
  { title: '载荷', field: 'payload' }, { title: '字节', field: 'bytes', width: 90 },
];
const publishColumns: VxeTableGridOptions<PublishRecord>['columns'] = [
  { title: '时间', field: 'time', slots: { default: 'cell' }, width: 180 }, { title: 'Topic', field: 'topic' },
  { title: 'QoS', field: 'qos', width: 70 }, { title: '结果', field: 'outcome', slots: { default: 'cell' }, width: 110 },
  { title: '说明', field: 'detail' },
];
const time = (value: number) => displayDateTime(value * 1000);
const pagePaths = ['/platform/broker/debug'];
const occupancyText = computed(() => `本账号 ${page.value.occupancy.person_used}/${page.value.occupancy.person_limit}，全局 ${page.value.occupancy.global_used}/${page.value.occupancy.global_limit}`);
const recovery = ref<Recovery | null>(null);
const isolatedDebug = computed(() => {
  const subjects = recovery.value?.subjects?.broker_debug;
  return !!subjects && subjects.total > subjects.approved;
});
/** 先解除事件回调再强制断开，防止旧连接事件改变新连接状态。 */
function stopClient() {
  const current = client; client = null;
  if (current) { current.removeAllListeners(); current.end(true); }
}
function displayPayload(payload: Buffer | string): { text: string; bytes: number } {
  if (typeof payload === 'string') {
    const bytes = new TextEncoder().encode(payload).byteLength;
    return { text: payload.slice(0, 4000), bytes };
  }
  const bytes = payload.byteLength;
  try {
    return { text: new TextDecoder('utf-8', { fatal: true }).decode(payload).slice(0, 4000), bytes };
  } catch {
    const preview = [...payload.subarray(0, 24)].map(value => value.toString(16).padStart(2, '0')).join(' ');
    return { text: `二进制 (${bytes} 字节) ${preview}`, bytes };
  }
}
/** 仅维护有上限的调试显示窗口；淘汰最早显示项不等于删除 Broker 消息。 */
function pushMessage(topic: string, payload: Buffer | string) {
  const shown = displayPayload(payload);
  const item: DebugMessage = { id: nextId++, at: Math.floor(Date.now() / 1000), topic, payload: shown.text, bytes: shown.bytes };
  buffered += shown.bytes;
  rows.value = [...rows.value, item];
  while (rows.value.length > 1000 || buffered > 8 * 1024 * 1024) {
    const removed = rows.value.shift();
    if (!removed) break;
    buffered -= removed.bytes;
    evicted.value += 1;
  }
}
function mqttReason(error: unknown): number {
  const packet = error && typeof error === 'object' ? (error as { code?: number; reasonCode?: number; packet?: { reasonCode?: number } }) : null;
  return Number(packet?.packet?.reasonCode ?? packet?.reasonCode ?? packet?.code ?? 0);
}
function quotaMessage(code: number): string {
  if (code === 0x97 || code === 151) return '调试名额已满（每人最多 2 个、全局最多 20 个）';
  if (code === 0x98 || code === 152) return '业务服务优先，调试连接已释放名额';
  return '';
}
function rememberPublish(topic: string, qos: number, outcome: PublishRecord['outcome'], detail: string) {
  publishes.value = [...publishes.value, { id: nextPublish++, at: Math.floor(Date.now() / 1000), topic, qos, outcome, detail }].slice(-20);
}
/** 使用短期凭据建立单次 MQTT 5 连接；禁用自动重连，回调核对连接实例。 */
function connectMqtt(credential: Credential, password: string) {
  stopClient();
  if (!credential.transport.available || !password) { status.value = 'idle'; return; }
  status.value = 'connecting';
  const options: IClientOptions = {
    protocolVersion: 5, clientId: credential.client_id, username: credential.username, password,
    clean: true, keepalive: 30, reconnectPeriod: 0, connectTimeout: 8000, protocolId: 'MQTT',
    properties: { sessionExpiryInterval: 0 }, wsOptions: { protocol: 'mqtt' },
  };
  const next = mqtt.connect(credential.transport.url, options);
  client = next;
  next.on('connect', () => {
    if (client !== next) return;
    status.value = 'connected';
    void load(true);
    const filter = credential.subscribe_topic.endsWith('/') ? `${credential.subscribe_topic}#` : credential.subscribe_topic;
    next.subscribe(filter, { qos: 0 }, error => { if (error && client === next) { status.value = 'interrupted'; failure.value = error.message; } });
  });
  next.on('message', (topic, payload) => { if (client === next) pushMessage(topic, payload); });
  next.on('disconnect', packet => {
    if (client !== next) return;
    const explained = quotaMessage(Number(packet?.reasonCode ?? 0));
    if (explained) failure.value = explained;
  });
  next.on('close', () => { if (client === next) { client = null; if (status.value === 'connected' || status.value === 'connecting') status.value = 'interrupted'; } });
  next.on('error', error => {
    if (client !== next) return;
    failure.value = quotaMessage(mqttReason(error)) || error.message;
    status.value = 'interrupted';
  });
}
/** expiresAt 为 Unix 秒，提前 30 秒重新签发，定时等待最低为 1 秒。 */
function scheduleRefresh(expiresAt: number) {
  clearTimeout(expiryTimer);
  const wait = Math.max(1000, expiresAt * 1000 - Date.now() - 30000);
  expiryTimer = setTimeout(() => { if (canIssue.value && !denied.value) void issue(); }, wait);
}
function applyPage(result: DebugPage | Credential, occupancy?: Occupancy) {
  if ('transport' in result && 'credential' in result) {
    page.value = {
      credential: result.credential, transport: result.transport,
      occupancy: result.occupancy || occupancy || page.value.occupancy,
    };
    return;
  }
  page.value = {
    credential: result, transport: result.transport,
    occupancy: result.occupancy || occupancy || page.value.occupancy,
  };
}
async function load(quiet = false) {
  if (!canRead.value || !pagePaths.includes(route.path) || (session.realm === 'system' && !tenant.value)) return;
  const version = ++generation; pending?.abort(); pending = new AbortController();
  if (!quiet) busy.value = true;
  try {
    const [result, currentRecovery] = await Promise.all([
      request<DebugPage>(`${base.value}/debug`, { tenant: tenant.value, signal: pending.signal }),
      request<Recovery>(`${base.value}/recovery`, { tenant: tenant.value, signal: pending.signal }),
    ]);
    if (version !== generation) return;
    applyPage(result); recovery.value = currentRecovery; denied.value = false;
    if (!quiet) failure.value = '';
    if (!secret.value) stopClient();
  } catch (error) {
    if (isCanceled(error) || version !== generation) return;
    failure.value = errorText(error); denied.value = error instanceof ApiError && error.status === 403 && error.code !== 'demo_readonly';
    if (denied.value) { stopClient(); secret.value = ''; }
  } finally { if (version === generation) busy.value = false; }
}
async function issue() {
  if (!canIssue.value) return;
  const version = ++generation; pending?.abort(); pending = new AbortController();
  busy.value = true; failure.value = '';
  try {
    const result = await request<Credential>(`${base.value}/debug`, { method: 'POST', data: {}, tenant: tenant.value, signal: pending.signal });
    if (version !== generation) return;
    secret.value = result.password || '';
    applyPage(result);
    rows.value = []; buffered = 0; nextId = 1; evicted.value = 0; publishes.value = []; nextPublish = 1;
    connectMqtt(result, secret.value);
    scheduleRefresh(result.expires_at);
    message.success('已签发短期凭据并开始订阅');
  } catch (error) {
    if (isCanceled(error) || version !== generation) return;
    failure.value = errorText(error); denied.value = error instanceof ApiError && error.status === 403 && error.code !== 'demo_readonly';
  } finally { if (version === generation) busy.value = false; }
}
async function revoke() {
  const version = ++generation; pending?.abort(); pending = new AbortController();
  busy.value = true;
  try {
    await request(`${base.value}/debug/revoke`, { method: 'POST', data: {}, tenant: tenant.value, signal: pending.signal });
    if (version !== generation) return;
    stopClient(); secret.value = ''; page.value.credential = null; status.value = 'idle';
    message.success('已撤销当前调试凭据');
  } catch (error) {
    if (isCanceled(error) || version !== generation) return;
    failure.value = errorText(error);
  } finally { if (version === generation) busy.value = false; }
}
/** 区分 QoS 0 写出与 QoS 1 回执；等待超时保留未知结果，不能宣称业务成功。 */
async function publishTest() {
  if (!client || status.value !== 'connected' || !page.value.credential) return;
  const topic = `${page.value.credential.publish_topic}ping`;
  const qos = publishQos.value;
  const payload = testPayload.value;
  if (new TextEncoder().encode(payload).byteLength > 65536) {
    rememberPublish(topic, qos, 'denied', '发布超过 64 KiB 上限');
    failure.value = '测试发布超过 64 KiB 上限';
    return;
  }
  publishing.value = true;
  try {
    const published = client.publishAsync(topic, payload, { qos });
    if (qos === 0) {
      await published;
      rememberPublish(topic, qos, 'unknown', '已写出。QoS 0 无 MQTT 回执，不是业务成功，设备控制仍走业务 API');
    } else {
      const timeout = new Promise<never>((_, reject) => setTimeout(() => reject(new Error('publish-timeout')), 5000));
      await Promise.race([published, timeout]);
      rememberPublish(topic, qos, 'mqtt_ack', 'MQTT PUBACK 仅证明 Broker 已确认，不是业务成功');
    }
  } catch (error) {
    if (error instanceof Error && error.message === 'publish-timeout') {
      rememberPublish(topic, qos, 'unknown', '等待 MQTT 回执超时，结果未知');
    } else {
      rememberPublish(topic, qos, 'denied', errorText(error));
      failure.value = quotaMessage(mqttReason(error)) || errorText(error);
      status.value = 'interrupted';
    }
  } finally { publishing.value = false; }
}
/** 隐藏页停止轮询并断开 WSS；恢复显示只刷新元数据，不自动恢复连接。 */
function visibility() {
  clearInterval(refreshTimer);
  if (document.visibilityState === 'visible') {
    void load(true);
    refreshTimer = setInterval(() => { if (!denied.value) void load(true); }, 15000);
  } else { generation++; pending?.abort(); pending = null; busy.value = false; stopClient(); status.value = status.value === 'connected' ? 'interrupted' : status.value; }
}
watch(() => [session.generation, session.token, session.realm, session.tenant?.id, canRead.value, route.path], () => {
  generation++; pending?.abort(); failure.value = ''; denied.value = false; secret.value = '';
  stopClient(); status.value = 'idle'; rows.value = []; buffered = 0; evicted.value = 0; publishes.value = [];
  if (!pagePaths.includes(route.path)) return;
  void load();
}, { immediate: true });
onMounted(() => { document.addEventListener('visibilitychange', visibility); visibility(); });
onBeforeUnmount(() => {
  generation++; pending?.abort(); clearInterval(refreshTimer); clearTimeout(expiryTimer);
  document.removeEventListener('visibilitychange', visibility); stopClient();
});
const [Messages] = useVbenVxeGrid<DebugMessage>({ gridOptions: { columns, rowConfig: { keyField: 'id' }, pagerConfig: { enabled: false }, maxHeight: 360 } });
const [Publishes] = useVbenVxeGrid<PublishRecord>({ gridOptions: { columns: publishColumns, rowConfig: { keyField: 'id' }, pagerConfig: { enabled: false }, maxHeight: 180 } });
</script>

<template>
  <Page title="MQTT 调试" description="使用当前平台会话的短期凭据测试原始通信，MQTT 确认不代表业务执行成功。" header-class="flex-wrap gap-4">
      <template #extra><Space wrap>
        <Button type="primary" :loading="busy" :disabled="!canIssue" @click="issue">{{ page.credential ? '重新签发' : '签发凭据' }}</Button>
        <Button :disabled="busy || !page.credential || !canIssue" @click="revoke">撤销</Button>
      </Space></template>
    <Alert v-if="failure" class="mb-4" type="error" show-icon role="alert" :message="denied ? failure : `调试订阅失败：${failure}`" />
    <Alert v-if="!canRead" class="mb-4" type="warning" show-icon message="当前账号无权查看 Broker 调试订阅" />
    <Alert v-else-if="!page.transport.available" class="mb-4" type="warning" show-icon message="当前节点尚未开放 WSS 入口，签发会被拒绝。" />
    <Alert v-else-if="page.credential && Number(page.credential.recovery_verified) === 0" class="mb-4" type="warning" show-icon message="该调试凭据恢复后尚未核对，连接保持隔离。请运维按当前授权清单核对后再签发。" />
    <Alert v-else-if="isolatedDebug" class="mb-4" type="warning" show-icon message="存在未核对的调试凭据，连接保持隔离。不能把旧快照标成恢复完成。" />
    <Alert v-else class="mb-4" type="info" show-icon :message="`连接状态：${status === 'connected' ? '已订阅' : status === 'connecting' ? '正在连接' : status === 'interrupted' ? '已中断' : '未连接'}。调试名额 ${occupancyText}。Clean Start，会话期限 0，不会积压离线消息。`" />
    <Card v-if="canIssue" title="连接信息" :bordered="false">
      <Descriptions :column="{ xs: 1, sm: 2, xl: 3 }" layout="vertical" class="mb-4 break-all">
        <DescriptionsItem label="用户名">{{ page.credential?.username || '尚未签发' }}</DescriptionsItem>
        <DescriptionsItem label="Client ID">{{ page.credential?.client_id || '—' }}</DescriptionsItem>
        <DescriptionsItem label="到期时间">{{ page.credential ? time(page.credential.expires_at) : '—' }}</DescriptionsItem>
        <DescriptionsItem label="订阅前缀">{{ page.credential?.subscribe_topic || '—' }}</DescriptionsItem>
        <DescriptionsItem label="测试发布前缀">{{ page.credential?.publish_topic || '—' }}</DescriptionsItem>
        <DescriptionsItem label="WSS">{{ page.transport.url || '不可用' }}</DescriptionsItem>
        <DescriptionsItem label="调试名额">{{ occupancyText }}</DescriptionsItem>
        <DescriptionsItem label="已淘汰显示">{{ evicted }} 条</DescriptionsItem>
      </Descriptions>
      <Alert v-if="secret" type="warning" class="mb-4" message="本次口令只显示一次，刷新后需重新签发。"><template #description><code class="break-all">{{ secret }}</code></template></Alert>
      <div class="grid grid-cols-1 items-center gap-3 md:grid-cols-[minmax(0,1fr)_200px_auto]">
        <Input v-model:value="testPayload" aria-label="测试发布内容" :disabled="status !== 'connected'" :maxlength="65536" />
        <Select v-model:value="publishQos" aria-label="测试发布 QoS" :options="[{ value: 0, label: 'QoS 0 无回执' }, { value: 1, label: 'QoS 1 MQTT 回执' }]" />
        <Button :loading="publishing" :disabled="status !== 'connected'" @click="publishTest">向测试 Topic 发布</Button>
      </div>
      <p class="mt-4 text-sm text-muted-foreground">口令随退出或会话撤销失效。接收窗口最多保留 1000 条或 8 MiB，超出时淘汰最早的显示记录。</p>
    </Card>
    <Card v-if="canIssue" :bordered="false" class="mt-4">
      <template #title>测试发布结果</template>
      <Publishes :table-data="publishes">
        <template #cell="{ column, row: record }">
          <template v-if="column.field === 'time'">{{ time(record.at) }}</template>
          <template v-else-if="column.field === 'outcome'">{{ record.outcome === 'mqtt_ack' ? 'MQTT 已确认' : record.outcome === 'denied' ? '已拒绝' : '未知' }}</template>
        </template>
      </Publishes>
    </Card>
    <Card v-if="canIssue" :bordered="false" class="mt-4">
      <template #title>实时消息</template>
      <Messages :table-data="rows">
        <template #cell="{ column, row: record }">
          <template v-if="column.field === 'time'">{{ time(record.at) }}</template>
        </template>
      </Messages>
    </Card>
  </Page>
</template>
