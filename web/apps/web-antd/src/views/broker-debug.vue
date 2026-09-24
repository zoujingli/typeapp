<script setup lang="ts">
/**
 * Broker 调试订阅页：签发当前租户或独立前缀范围内的短期 WSS 凭据并实时接收。
 * 口令只在签发响应出现一次；退出、换租户或离开页面立即断开。接收窗口最多 1000 条或 8 MiB。
 * MQTT 回执不是业务成功；调试名额每人 2、全局 20，业务服务可抢占并说明原因。
 */
import type { IClientOptions, MqttClient } from 'mqtt';
import type { TableColumnsType } from 'ant-design-vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Alert, Button, Card, Input, Select, Table, Tag, message } from 'ant-design-vue';
import mqtt from 'mqtt';
import { ApiError, errorText, isCanceled, request, session } from '../api';

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
const router = useRouter();
const platform = computed(() => session.realm === 'admin');
const tenant = computed(() => session.realm === 'customer' && !platform.value ? session.tenant?.id : undefined);
const base = computed(() => session.realm === 'broker' ? '/broker' : platform.value ? '/admin/broker' : `/customer/tenants/${tenant.value}/broker`);
const canRead = computed(() => session.realm === 'broker' ? !!session.user?.platform_admin
  : session.permissions.includes(`${session.realm}.broker.read`));
const canIssue = computed(() => canRead.value && !platform.value);
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
const columns: TableColumnsType<DebugMessage> = [
  { title: '时间', key: 'time', width: 180 }, { title: 'Topic', dataIndex: 'topic', ellipsis: true },
  { title: '载荷', dataIndex: 'payload', ellipsis: true }, { title: '字节', dataIndex: 'bytes', width: 90 },
];
const publishColumns: TableColumnsType<PublishRecord> = [
  { title: '时间', key: 'time', width: 180 }, { title: 'Topic', dataIndex: 'topic', ellipsis: true },
  { title: 'QoS', dataIndex: 'qos', width: 70 }, { title: '结果', dataIndex: 'outcome', width: 110 },
  { title: '说明', dataIndex: 'detail', ellipsis: true },
];
const time = (value: number) => new Date(value * 1000).toLocaleString();
const pagePaths = ['/broker-debug', '/admin/broker-debug', '/broker/debug'];
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
  if (!canRead.value || !pagePaths.includes(route.path) || (session.realm === 'customer' && !tenant.value)) return;
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
    failure.value = errorText(error); denied.value = error instanceof ApiError && error.status === 403;
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
    failure.value = errorText(error); denied.value = error instanceof ApiError && error.status === 403;
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
  if (session.realm === 'customer' && !tenant.value) void router.replace('/tenants'); else void load();
}, { immediate: true });
onMounted(() => { document.addEventListener('visibilitychange', visibility); visibility(); });
onBeforeUnmount(() => {
  generation++; pending?.abort(); clearInterval(refreshTimer); clearTimeout(expiryTimer);
  document.removeEventListener('visibilitychange', visibility); stopClient();
});
</script>

<template>
  <section class="iot-page">
    <header class="page-heading">
      <div>
        <h1>Broker 调试订阅</h1>
        <p class="muted">短期 MQTT 凭据绑定当前登录与{{ platform ? '平台工作区' : session.realm === 'broker' ? '独立前缀' : '当前租户' }}。口令只在签发时显示一次，退出或换租户后立即失效。接收窗口最多 1000 条或 8 MiB，先满淘汰最旧显示。MQTT 确认不是业务成功。</p>
      </div>
      <div class="crud-search-grid__actions">
        <Button type="primary" :loading="busy" :disabled="!canIssue" @click="issue">{{ page.credential ? '重新签发' : '签发凭据' }}</Button>
        <Button :disabled="busy || !page.credential || !canIssue" @click="revoke">撤销</Button>
      </div>
    </header>
    <Alert v-if="failure" class="page-alert" type="error" show-icon role="alert" :message="denied ? failure : `调试订阅失败：${failure}`" />
    <Alert v-if="!canRead" class="page-alert" type="warning" show-icon message="当前账号无权查看 Broker 调试订阅" />
    <Alert v-else-if="platform" class="page-alert" type="info" show-icon message="平台管理端不能签发或读取租户调试载荷。请在独立管理端或租户工作区订阅。" />
    <Alert v-else-if="!page.transport.available" class="page-alert" type="warning" show-icon message="当前节点尚未开放 WSS 入口，签发会被拒绝。" />
    <Alert v-else-if="page.credential && Number(page.credential.recovery_verified) === 0" class="page-alert" type="warning" show-icon message="该调试凭据恢复后尚未核对，连接保持隔离。请运维按当前授权清单核对后再签发。" />
    <Alert v-else-if="isolatedDebug" class="page-alert" type="warning" show-icon message="存在未核对的调试凭据，连接保持隔离。不能把旧快照标成恢复完成。" />
    <Alert v-else class="page-alert" type="info" show-icon :message="`连接状态：${status === 'connected' ? '已订阅' : status === 'connecting' ? '正在连接' : status === 'interrupted' ? '已中断' : '未连接'}。调试名额 ${occupancyText}。Clean Start，会话期限 0，不会积压离线消息。`" />
    <Card v-if="canIssue">
      <dl class="debug-meta">
        <div><dt>用户名</dt><dd>{{ page.credential?.username || '尚未签发' }}</dd></div>
        <div><dt>Client ID</dt><dd>{{ page.credential?.client_id || '—' }}</dd></div>
        <div><dt>到期</dt><dd>{{ page.credential ? time(page.credential.expires_at) : '—' }}</dd></div>
        <div><dt>订阅前缀</dt><dd>{{ page.credential?.subscribe_topic || '—' }}</dd></div>
        <div><dt>测试发布前缀</dt><dd>{{ page.credential?.publish_topic || '—' }}</dd></div>
        <div><dt>WSS</dt><dd>{{ page.transport.url || '不可用' }}</dd></div>
        <div><dt>调试名额</dt><dd>{{ occupancyText }}</dd></div>
        <div><dt>已淘汰显示</dt><dd>{{ evicted }} 条（不删除 Broker 持久消息）</dd></div>
      </dl>
      <p v-if="secret" class="muted">本次口令：<code>{{ secret }}</code>。刷新页面后需重新签发才能再连接。</p>
      <div class="crud-search-grid__actions">
        <Input v-model:value="testPayload" aria-label="测试发布内容" :disabled="status !== 'connected'" :maxlength="65536" />
        <Select v-model:value="publishQos" aria-label="测试发布 QoS" style="width: 200px" :options="[{ value: 0, label: 'QoS 0 无回执' }, { value: 1, label: 'QoS 1 MQTT 回执' }]" />
        <Button :loading="publishing" :disabled="status !== 'connected'" @click="publishTest">向测试 Topic 发布</Button>
        <Tag :color="status === 'connected' ? 'green' : status === 'interrupted' ? 'orange' : 'default'">{{ status }}</Tag>
      </div>
    </Card>
    <Card v-if="canIssue" class="page-card">
      <template #title>测试发布结果</template>
      <Table :columns="publishColumns" :data-source="publishes" row-key="id" :pagination="false" size="small" :scroll="{ x: 720, y: 180 }">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'time'">{{ time(record.at) }}</template>
          <template v-else-if="column.dataIndex === 'outcome'">{{ record.outcome === 'mqtt_ack' ? 'MQTT 已确认' : record.outcome === 'denied' ? '已拒绝' : '未知' }}</template>
        </template>
      </Table>
    </Card>
    <Card v-if="canIssue" class="page-card">
      <template #title>实时消息</template>
      <Table :columns="columns" :data-source="rows" row-key="id" :pagination="false" size="small" :scroll="{ x: 720, y: 360 }">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'time'">{{ time(record.at) }}</template>
        </template>
      </Table>
    </Card>
  </section>
</template>

<style scoped>
.debug-meta { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px 24px; margin: 0 0 16px; }
.debug-meta dt { color: var(--ant-color-text-secondary); font-size: 12px; }
.debug-meta dd { margin: 0; overflow-wrap: anywhere; }
</style>
