<script setup lang="ts">
/**
 * Broker 运行配置页：校验并保存监听、I/O 与证书路径。
 * 页面不执行重启；握手 CA 由节点在线重载，已有连接保持到吊销或到期。
 */
import type { TableColumnsType } from 'ant-design-vue';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Alert, Button, Card, Checkbox, Input, InputNumber, Select, Table, Tag, message } from 'ant-design-vue';
import AppDrawer from '../components/app-drawer/app-drawer.vue';
import CrudSearchField from '../components/crud-search-field.vue';
import CrudTableActions from '../components/crud-table-actions.vue';
import { buildTableScrollX, estimateVisibleActionColumnWidth } from '../utils/table';
import { ApiError, errorText, isCanceled, request, session } from '../api';

type ConfigKey = 'listen' | 'port' | 'ws_port' | 'wss_port' | 'mtls_port' | 'io_driver' | 'plaintext' | 'allowed_origins';
interface RuntimeConfig {
  listen: string; port: number; ws_port: number; wss_port: number; mtls_port: number;
  io_driver: string; plaintext: boolean; allowed_origins: string;
}
interface NodeState { node_id: string; applied_version: number; state: string; updated_at: number }
interface LoadedNode { node_id: string; config: RuntimeConfig; certificate_sha256: string; handshake_ca_sha256: string; observed_at: number }
interface Revision {
  id: string; operation_id: string; version: number; actor_id: string; actor_realm: string; tightening: boolean;
  status: string; stage: string; created_at: number; updated_at: number; nodes: NodeState[];
  pending_nodes: number; isolated_nodes: number;
}
interface RuntimePage {
  config: RuntimeConfig; restart_required: boolean; loaded: LoadedNode[];
  handshake: { sha256: string; reloads: number; failures: number };
  current_version: number; publish_paused: boolean; revision: Revision | null;
  current_config?: RuntimeConfig; tightening?: boolean;
}
interface RevisionPage { items: Revision[]; total: number; page: number; per_page: number; current_version: number; publish_paused: boolean }
const emptyConfig = (): RuntimeConfig => ({
  listen: '127.0.0.1', port: 8883, ws_port: 0, wss_port: 0, mtls_port: 0,
  io_driver: 'swoole', plaintext: false, allowed_origins: '',
});
const fields: { key: ConfigKey; label: string }[] = [
  { key: 'listen', label: '监听地址' }, { key: 'port', label: 'TCP 端口' },
  { key: 'ws_port', label: '明文 WS 端口' }, { key: 'wss_port', label: 'WSS 端口' },
  { key: 'mtls_port', label: 'mTLS 端口' }, { key: 'io_driver', label: 'I/O 驱动' },
  { key: 'plaintext', label: '明文模式' }, { key: 'allowed_origins', label: 'Origin 白名单' },
];
const route = useRoute();
const router = useRouter();
const platform = computed(() => session.realm === 'admin');
const tenant = computed(() => session.realm === 'customer' && !platform.value ? session.tenant?.id : undefined);
const base = computed(() => session.realm === 'broker' ? '/broker' : platform.value ? '/admin/broker' : `/customer/tenants/${tenant.value}/broker`);
const canRead = computed(() => session.realm === 'broker' ? !!session.user?.platform_admin
  : session.permissions.includes(`${session.realm}.broker.read`));
const canWrite = computed(() => session.realm === 'broker' ? !!session.user?.platform_admin
  : session.realm === 'admin' && session.permissions.includes('admin.broker.write'));
const result = ref<RuntimePage>({
  config: emptyConfig(), restart_required: true, loaded: [], handshake: { sha256: '', reloads: 0, failures: 0 },
  current_version: 0, publish_paused: false, revision: null,
});
const draft = reactive<RuntimeConfig>(emptyConfig());
const preview = ref<RuntimePage | null>(null);
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
const revisionColumns: TableColumnsType<Revision> = [
  { title: '版本', dataIndex: 'version', width: 80 }, { title: '发布状态', key: 'status', width: 140 },
  { title: '操作阶段', key: 'stage', width: 110 }, { title: '收紧边界', key: 'tightening', width: 110 },
  { title: '节点生效', key: 'nodes', width: 260, ellipsis: true },
  { title: '保存时间', key: 'time', width: 190 },
  { title: '操作', key: 'actions', fixed: 'right', width: estimateVisibleActionColumnWidth([{ label: '重试' }, { label: '回退' }]) },
];
const revisionPagination = computed(() => ({ current: revisions.value.page, pageSize: revisions.value.per_page, total: revisions.value.total, showSizeChanger: false }));
const time = (value: number) => new Date(value * 1000).toLocaleString();
function nodeText(item: Revision) {
  if (item.nodes.length === 0) return '尚无上报节点';
  return item.nodes.map(node => `${node.node_id} · ${nodeNames[node.state] || node.state} · v${node.applied_version}`).join('；');
}
function payload() {
  return {
    expected_version: result.value.current_version,
    listen: draft.listen, port: Number(draft.port), ws_port: Number(draft.ws_port), wss_port: Number(draft.wss_port),
    mtls_port: Number(draft.mtls_port), io_driver: draft.io_driver, plaintext: draft.plaintext === true,
    allowed_origins: draft.allowed_origins,
  };
}
async function load(background = false) {
  if (!canRead.value || busy.value && !background || document.visibilityState !== 'visible' || session.realm === 'customer' && !tenant.value) return;
  const current = ++generation; pending?.abort(); pending = new AbortController(); busy.value = true;
  try {
    const data = await request<RuntimePage>(`${base.value}/runtime`, { tenant: tenant.value, signal: pending.signal });
    if (current === generation) {
      result.value = data; failure.value = ''; denied.value = false;
      if (!preview.value && !publishing.value) Object.assign(draft, data.config);
    }
  } catch (error) {
    if (current === generation && !isCanceled(error)) {
      failure.value = errorText(error); denied.value = error instanceof ApiError && error.status === 403;
    }
  } finally { if (current === generation) busy.value = false; }
}
async function loadRevisions(page = 1, background = false) {
  if (!canRead.value || !historyOpen.value || historyBusy.value && !background || document.visibilityState !== 'visible') return;
  const current = ++generation; historyBusy.value = true;
  try {
    const data = await request<RevisionPage>(`${base.value}/runtime/revisions`, { tenant: tenant.value, params: { page, per_page: 20 } });
    if (historyOpen.value) { revisions.value = data; historyFailure.value = ''; }
  } catch (error) { if (!isCanceled(error)) historyFailure.value = errorText(error); }
  finally { if (current === generation || historyOpen.value) historyBusy.value = false; }
}
async function runPreview() {
  if (previewBusy.value || !canRead.value) return;
  previewBusy.value = true; previewFailure.value = ''; confirmed.value = false;
  try {
    preview.value = await request<RuntimePage>(`${base.value}/runtime/preview`, { tenant: tenant.value, method: 'POST', data: payload() });
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
    await request(`${base.value}/runtime`, { tenant: tenant.value, method: 'POST', data: { ...payload(), confirmed: true } });
    message.success('运行配置已保存，运维重启节点后状态会更新'); preview.value = null; confirmed.value = false;
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
    await request(`${base.value}/runtime/revisions/${item.id}/${action}`, { tenant: tenant.value, method: 'POST', data: {} });
    message.success(action === 'retry' ? '已重试同一版本' : '已回退并保存新版本'); await load(); await loadRevisions(revisions.value.page);
  } catch (error) { if (!isCanceled(error)) historyFailure.value = errorText(error); }
  finally { acting.value = ''; }
}
function resetDraft() {
  Object.assign(draft, result.value.config);
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
watch(() => [session.generation, session.realm, session.tenant?.id, session.identity?.key, canRead.value, route.path], () => {
  generation++; pending?.abort(); preview.value = null; confirmed.value = false; failure.value = ''; denied.value = false;
  historyOpen.value = false; previewFailure.value = ''; publishFailure.value = '';
  if (!['/broker-runtime', '/admin/broker-runtime', '/broker/runtime'].includes(route.path)) return;
  if (session.realm === 'customer' && !tenant.value) void router.replace('/tenants'); else void load();
}, { immediate: true });
onMounted(() => { document.addEventListener('visibilitychange', visibility); visibility(); });
onBeforeUnmount(() => { generation++; pending?.abort(); clearInterval(timer); document.removeEventListener('visibilitychange', visibility); });
</script>

<template>
  <section class="iot-page">
    <header class="page-heading">
      <div><h1>Broker 运行配置</h1><p class="muted">{{ platform ? '平台监听、I/O 与节点证书路径' : session.realm === 'broker' ? '独立监听、I/O 与节点证书路径' : '当前租户可见的全局运行配置' }}。当前版本 v{{ result.current_version }}{{ paused ? ' · 部分失败已暂停后续保存' : '' }}</p></div>
      <div class="crud-search-grid__actions"><Button @click="showHistory">版本生效</Button></div>
    </header>
    <Alert v-if="failure" class="page-alert" type="error" show-icon role="alert" :message="denied ? failure : `运行配置刷新失败：${failure}`" />
    <Alert v-if="!canRead" class="page-alert" type="warning" show-icon message="当前账号无权查看 Broker 运行配置" />
    <Alert v-else-if="canRead && !canWrite" class="page-alert" type="info" show-icon message="当前角色可查看已保存配置与节点加载身份，保存、重试和回退仅限独立或平台管理员。" />
    <Alert v-else class="page-alert" type="info" show-icon message="本页只校验并保存。监听、端口、I/O 与节点证书路径须由运维重启后生效。握手 CA 在节点心跳后在线重载，已有连接保持到吊销或到期。私钥不会出现在页面或接口中。" />
    <Card>
      <form class="crud-search-grid" @submit.prevent="runPreview()">
        <CrudSearchField label="监听地址">
          <Input v-model:value="draft.listen" aria-label="监听地址" :disabled="busy || publishing || !canWrite" />
        </CrudSearchField>
        <CrudSearchField label="TCP 端口">
          <InputNumber v-model:value="draft.port" aria-label="TCP 端口" :min="1" :max="65535" :precision="0" style="width:100%" :disabled="busy || publishing || !canWrite" />
        </CrudSearchField>
        <CrudSearchField label="明文 WS 端口">
          <InputNumber v-model:value="draft.ws_port" aria-label="明文 WS 端口" :min="0" :max="65535" :precision="0" style="width:100%" :disabled="busy || publishing || !canWrite" />
        </CrudSearchField>
        <CrudSearchField label="WSS 端口">
          <InputNumber v-model:value="draft.wss_port" aria-label="WSS 端口" :min="0" :max="65535" :precision="0" style="width:100%" :disabled="busy || publishing || !canWrite" />
        </CrudSearchField>
        <CrudSearchField label="mTLS 端口">
          <InputNumber v-model:value="draft.mtls_port" aria-label="mTLS 端口" :min="0" :max="65535" :precision="0" style="width:100%" :disabled="busy || publishing || !canWrite" />
        </CrudSearchField>
        <CrudSearchField label="I/O 驱动">
          <Select v-model:value="draft.io_driver" aria-label="I/O 驱动" style="width:100%" :options="[{ value: 'swoole', label: 'swoole' }, { value: 'stream', label: 'stream' }]" :disabled="busy || publishing || !canWrite" />
        </CrudSearchField>
        <CrudSearchField label="明文模式">
          <Checkbox v-model:checked="draft.plaintext" :disabled="busy || publishing || !canWrite">允许明文调试</Checkbox>
        </CrudSearchField>
        <CrudSearchField label="Origin 白名单">
          <Input v-model:value="draft.allowed_origins" aria-label="Origin 白名单" :disabled="busy || publishing || !canWrite" />
        </CrudSearchField>
        <div class="crud-search-grid__actions">
          <Button type="primary" html-type="submit" :loading="previewBusy" :disabled="!canRead || publishing">预览保存</Button>
          <Button :disabled="busy || !canRead" @click="resetDraft">重置</Button>
        </div>
      </form>
      <div class="table-toolbar"><span class="muted">握手 CA {{ result.handshake.sha256 ? result.handshake.sha256.slice(0, 12) : '尚未上报' }}{{ result.handshake.reloads ? ` · 已重载 ${result.handshake.reloads} 次` : '' }}{{ busy ? ' · 刷新中' : '' }}</span><Button :loading="busy" :disabled="!canRead" @click="load()">刷新</Button></div>
      <Table :columns="[{ title: '节点', dataIndex: 'node_id', width: 160 }, { title: '已加载端口', key: 'port', width: 120 }, { title: '证书指纹', key: 'cert', width: 180, ellipsis: true }, { title: '握手 CA', key: 'ca', width: 180, ellipsis: true }]"
        :data-source="result.loaded" row-key="node_id" :pagination="false" size="small" :scroll="{ x: 640 }">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'port'">{{ record.config.port }} / {{ record.config.mtls_port || '—' }}</template>
          <template v-else-if="column.key === 'cert'">{{ record.certificate_sha256 ? record.certificate_sha256.slice(0, 16) : '—' }}</template>
          <template v-else-if="column.key === 'ca'">{{ record.handshake_ca_sha256 ? record.handshake_ca_sha256.slice(0, 16) : '—' }}</template>
        </template>
      </Table>
    </Card>
    <Card v-if="preview" class="page-card">
      <template #title>保存预览</template>
      <Alert v-if="previewFailure" :message="previewFailure" type="error" show-icon class="page-alert" role="alert" />
      <Alert v-if="publishFailure" :message="publishFailure" type="error" show-icon class="page-alert" role="alert" />
      <p class="muted">{{ preview.tightening ? '本次收紧安全边界，保存后不能回退。' : '本次未收紧安全边界。' }} 保存成功不等于节点已加载；实际重启与滚动分发由运维执行。</p>
      <Table :columns="[{ title: '项', dataIndex: 'label', width: 160 }, { title: '拟保存', key: 'next', width: 220 }]"
        :data-source="fields" row-key="key" :pagination="false" size="small" :scroll="{ x: 400 }">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'next'">{{ String(preview?.config[(record as typeof fields[number]).key] ?? '') }}</template>
        </template>
      </Table>
      <Checkbox v-if="canWrite" v-model:checked="confirmed" :disabled="publishing || paused">确认仅校验并保存，实际重启与滚动分发由运维执行</Checkbox>
      <div v-if="canWrite" class="crud-search-grid__actions">
        <Button type="primary" :loading="publishing" :disabled="!confirmed || paused" @click="publish">保存配置</Button>
      </div>
    </Card>
    <AppDrawer :open="historyOpen" title="运行配置版本生效" width-size="lg" :show-footer="false" @update:open="value => { if (!value) historyOpen = false; }">
      <Alert v-if="historyFailure" :message="historyFailure" type="error" show-icon class="page-alert" role="alert" />
      <Table :columns="revisionColumns" :data-source="revisions.items" row-key="id" :loading="historyBusy" :scroll="buildTableScrollX(revisionColumns)" :pagination="revisionPagination" @change="page => loadRevisions(page.current)">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'status'"><Tag>{{ statusNames[record.status] || record.status }}</Tag></template>
          <template v-else-if="column.key === 'stage'">{{ stageNames[record.stage] || record.stage }}</template>
          <template v-else-if="column.key === 'tightening'">{{ record.tightening ? '是' : '否' }}</template>
          <template v-else-if="column.key === 'nodes'">{{ nodeText(record as Revision) }}</template>
          <template v-else-if="column.key === 'time'">{{ time(record.created_at) }}</template>
          <CrudTableActions v-else-if="column.key === 'actions'" :actions="[{ label: '重试', visible: canWrite, disabled: acting !== '' || ['effective', 'rolled_back'].includes(record.status), onClick: () => act(record as Revision, 'retry') }, { label: '回退', visible: canWrite, disabled: acting !== '' || record.tightening || ['effective', 'rolled_back'].includes(record.status), onClick: () => act(record as Revision, 'rollback') }]" />
        </template>
      </Table>
    </AppDrawer>
  </section>
</template>
