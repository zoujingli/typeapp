<script setup lang="ts">
import { displayDateTime } from '../../../display';
/**
 * Broker 运行配置页：校验并保存监听与证书路径。
 * 页面不执行重启；握手 CA 由节点在线重载，已有连接保持到吊销或到期。
 */
import type { VxeTableGridOptions } from '../../../adapter/vxe-table';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { Page, useVbenDrawer } from '@vben/common-ui';
import { useVbenVxeGrid } from '../../../adapter/vxe-table';
import { useVbenForm } from '../../../adapter/form';
import { Alert, Button, Card, Checkbox, Empty, Space, Spin, Modal, Tag, message } from 'ant-design-vue';
import { ApiError, errorText, isCanceled, isDemo, request, session } from '../../../api/identity';

type ConfigKey = 'listen' | 'port' | 'ws_port' | 'wss_port' | 'mtls_port' | 'plaintext' | 'allowed_origins';
interface RuntimeConfig {
  listen: string; port: number; ws_port: number; wss_port: number; mtls_port: number;
  plaintext: boolean; allowed_origins: string;
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
  plaintext: false, allowed_origins: '',
});
const fields: { key: ConfigKey; label: string }[] = [
  { key: 'listen', label: '监听地址' }, { key: 'port', label: 'TCP 端口' },
  { key: 'ws_port', label: '明文 WS 端口' }, { key: 'wss_port', label: 'WSS 端口' },
  { key: 'mtls_port', label: 'mTLS 端口' },
  { key: 'plaintext', label: '明文模式' }, { key: 'allowed_origins', label: 'Origin 白名单' },
];
const route = useRoute();
const platform = computed(() => session.realm === 'platform');
const tenant = computed(() => session.realm === 'system' && !platform.value ? session.tenant?.id : undefined);
const base = computed(() => '/platform/broker');
const canRead = computed(() => session.permissions.includes('platform.broker.read'));
const canWrite = computed(() => !isDemo() && session.permissions.includes('platform.broker.write'));
const emptyPage = (): RuntimePage => ({
  config: emptyConfig(), restart_required: false, loaded: [], handshake: { sha256: '', reloads: 0, failures: 0 },
  current_version: 0, publish_paused: false, revision: null,
});
const result = ref<RuntimePage>(emptyPage());
const hasData = ref(false);
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
const pageDescription = computed(() => hasData.value
  ? `平台监听与节点证书 · 当前版本 v${result.value.current_version}${paused.value ? ' · 已暂停后续保存' : ''}`
  : '平台监听与节点证书 · 等待读取');
const revisionColumns: VxeTableGridOptions<Revision>['columns'] = [
  { title: '版本', field: 'version', width: 80 }, { title: '发布状态', field: 'status', slots: { default: 'cell' }, width: 140 },
  { title: '操作阶段', field: 'stage', slots: { default: 'cell' }, width: 110 }, { title: '收紧边界', field: 'tightening', slots: { default: 'cell' }, width: 110 },
  { title: '节点生效', field: 'nodes', slots: { default: 'cell' }, width: 260 },
  { title: '保存时间', field: 'time', slots: { default: 'cell' }, width: 190 },
  { title: '操作', field: 'actions', slots: { default: 'cell' }, fixed: 'right', width: 160 },
];
const revisionPagination = computed(() => ({ currentPage: revisions.value.page, pageSize: revisions.value.per_page, total: revisions.value.total, pageSizes: [20] }));
const time = (value: number) => displayDateTime(value * 1000);
function nodeText(item: Revision) {
  if (item.nodes.length === 0) return '尚无上报节点';
  return item.nodes.map(node => `${node.node_id} · ${nodeNames[node.state] || node.state} · v${node.applied_version}`).join('；');
}
function payload() {
  return {
    expected_version: draftVersion,
    listen: draft.listen, port: Number(draft.port), ws_port: Number(draft.ws_port), wss_port: Number(draft.wss_port),
    mtls_port: Number(draft.mtls_port), plaintext: draft.plaintext === true,
    allowed_origins: draft.allowed_origins,
  };
}
async function load(background = false) {
  if (!canRead.value || busy.value && !background || document.visibilityState !== 'visible' || session.realm === 'system' && !tenant.value) return;
  const current = ++generation; pending?.abort(); pending = new AbortController(); busy.value = true;
  try {
    const data = await request<RuntimePage>(`${base.value}/runtime`, { tenant: tenant.value, signal: pending.signal });
    if (current === generation) {
      result.value = data; hasData.value = true; failure.value = ''; denied.value = false;
      if (!preview.value && !publishing.value && !draftDirty) await syncDraft(data.config);
    }
  } catch (error) {
    if (current === generation && !isCanceled(error)) {
      failure.value = errorText(error); denied.value = error instanceof ApiError && error.status === 403 && error.code !== 'demo_readonly';
      if (denied.value) { hasData.value = false; result.value = emptyPage(); revisions.value = { items: [], total: 0, page: 1, per_page: 20, current_version: 0, publish_paused: false }; preview.value = null; }
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
  if (previewBusy.value || !canRead.value || !(await configForm.validate()).valid) return;
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
    message.success('运行配置已保存，运维重启节点后状态会更新'); preview.value = null; confirmed.value = false; draftDirty = false;
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
  void syncDraft(result.value.config);
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
const [ConfigForm, configForm] = useVbenForm({ showDefaultActions: false, layout: 'vertical', commonConfig: { componentProps: { class: 'w-full' } }, wrapperClass: 'grid-cols-1 md:grid-cols-2 xl:grid-cols-3', schema: fields.map(field => ({ fieldName: field.key, label: field.label, component: field.key === 'plaintext' ? 'Switch' as const : ['listen', 'allowed_origins'].includes(field.key) ? 'Input' as const : 'InputNumber' as const, componentProps: { class: field.key === 'plaintext' ? 'w-auto' : 'w-full', ...(!['listen', 'allowed_origins', 'plaintext'].includes(field.key) ? { min: field.key === 'port' ? 1 : 0, max: 65535, precision: 0 } : {}) } })), handleValuesChange(values, changed) {
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
  generation++; pending?.abort(); result.value = emptyPage(); hasData.value = false; revisions.value = { items: [], total: 0, page: 1, per_page: 20, current_version: 0, publish_paused: false }; preview.value = null; confirmed.value = false; failure.value = ''; denied.value = false;
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
  <Page title="MQTT 运行配置" :description="pageDescription" header-class="flex-wrap gap-4">
    <template #extra><Button :disabled="!canRead" @click="showHistory">版本生效</Button></template>
    <Alert v-if="failure" class="mb-4" type="error" show-icon role="alert" :message="denied ? failure : `运行配置刷新失败：${failure}`" />
    <Alert v-if="!canRead" class="mb-4" type="warning" show-icon message="当前账号无权查看 Broker 运行配置" />
    <Alert v-else-if="canRead && !canWrite" class="mb-4" type="info" show-icon message="当前角色可查看已保存配置与节点加载身份，保存、重试和回退需要平台 MQTT 写入权限。" />
    <Alert v-else class="mb-4" type="info" show-icon message="本页只校验并保存。监听、端口与节点证书路径须由运维重启后生效。握手 CA 在节点心跳后在线重载，已有连接保持到吊销或到期。私钥不会出现在页面或接口中。" />
    <Spin :spinning="busy && !hasData">
      <template v-if="hasData">
        <Card title="监听配置" :bordered="false" class="mb-4">
          <ConfigForm :common-config="{ disabled: busy || publishing || !canWrite, componentProps: { class: 'w-full' } }" /><Space class="mb-4"><Button type="primary" :loading="previewBusy" :disabled="!canRead || publishing" @click="runPreview">预览调整</Button><Button :disabled="busy || publishing" @click="resetDraft">重置</Button></Space>
          <Alert v-if="previewFailure" :message="previewFailure" type="error" show-icon class="mt-4" />
        </Card>
        <Card title="节点已加载配置" :bordered="false">
          <div class="mb-4 flex flex-wrap items-center justify-between gap-4"><span class="text-muted-foreground leading-7">握手 CA {{ result.handshake.sha256 ? result.handshake.sha256.slice(0, 12) : '尚未上报' }}{{ result.handshake.reloads ? ` · 已重载 ${result.handshake.reloads} 次` : '' }}{{ busy ? ' · 刷新中' : '' }}</span><Button :loading="busy" :disabled="!canRead" @click="load()">刷新</Button></div>
          <MqttGrid1 :grid-options="{ columns: [{ title: '节点', field: 'node_id', width: 160 }, { title: '已加载端口', field: 'port', slots: { default: 'cell' }, width: 120 }, { title: '证书指纹', field: 'cert', slots: { default: 'cell' }, width: 180 }, { title: '握手 CA', field: 'ca', slots: { default: 'cell' }, width: 180 }], loading: busy, pagerConfig: { enabled: false }, rowConfig: { keyField: 'node_id' } }" :table-data="result.loaded">
            <template #cell="{ column, row: record }">
              <template v-if="column.field === 'port'">{{ record.config.port }} / {{ record.config.mtls_port || '—' }}</template>
              <template v-else-if="column.field === 'cert'">{{ record.certificate_sha256 ? record.certificate_sha256.slice(0, 16) : '—' }}</template>
              <template v-else-if="column.field === 'ca'">{{ record.handshake_ca_sha256 ? record.handshake_ca_sha256.slice(0, 16) : '—' }}</template>
            </template>
          </MqttGrid1>
        </Card>
        <Card v-if="preview" :bordered="false" class="mt-4">
      <template #title>保存预览</template>
      <Alert v-if="previewFailure" :message="previewFailure" type="error" show-icon class="mb-4" role="alert" />
      <Alert v-if="publishFailure" :message="publishFailure" type="error" show-icon class="mb-4" role="alert" />
      <p class="text-muted-foreground leading-7">{{ preview.tightening ? '本次收紧安全边界，保存后不能回退。' : '本次未收紧安全边界。' }} 保存成功不等于节点已加载；实际重启与滚动分发由运维执行。</p>
      <MqttGrid2 :grid-options="{ columns: [{ title: '项', field: 'label', width: 160 }, { title: '拟保存', field: 'next', slots: { default: 'cell' }, width: 220 }], pagerConfig: { enabled: false }, rowConfig: { keyField: 'key' } }" :table-data="fields">
        <template #cell="{ column, row: record }">
          <template v-if="column.field === 'next'">{{ String(preview?.config[(record as typeof fields[number]).key] ?? '') }}</template>
        </template>
      </MqttGrid2>
      <Checkbox v-if="canWrite" v-model:checked="confirmed" :disabled="publishing || paused">确认仅校验并保存，实际重启与滚动分发由运维执行</Checkbox>
      <div v-if="canWrite" class="mt-4 flex flex-wrap items-center gap-3">
        <Button type="primary" :loading="publishing" :disabled="isDemo() || (!confirmed || paused)" @click="publish">保存配置</Button>
      </div>
        </Card>
      </template>
      <Empty v-else-if="!busy" description="暂无可读取的运行配置" />
    </Spin>
    <MqttDrawer1 title="运行配置版本生效" class="w-full max-w-5xl" :footer="false">
      <Alert v-if="historyFailure" :message="historyFailure" type="error" show-icon class="mb-4" role="alert" />
      <MqttGrid3 :grid-options="{ columns: revisionColumns, loading: historyBusy, pagerConfig: revisionPagination, rowConfig: { keyField: 'id' } }" :table-data="revisions.items" :grid-events="{ pageChange: (page: { currentPage: number; pageSize: number }) => loadRevisions(page.currentPage) }">
        <template #cell="{ column, row: record }">
          <template v-if="column.field === 'status'"><Tag>{{ statusNames[record.status] || record.status }}</Tag></template>
          <template v-else-if="column.field === 'stage'">{{ stageNames[record.stage] || record.stage }}</template>
          <template v-else-if="column.field === 'tightening'">{{ record.tightening ? '是' : '否' }}</template>
          <template v-else-if="column.field === 'nodes'">{{ nodeText(record as Revision) }}</template>
          <template v-else-if="column.field === 'time'">{{ time(record.created_at) }}</template>
          <Space v-else-if="column.field === 'actions'"><Button v-for="action in ([{ label: '重试', visible: canWrite, disabled: acting !== '' || ['effective', 'rolled_back'].includes(record.status), onClick: () => act(record as Revision, 'retry') }, { label: '回退', visible: canWrite, disabled: acting !== '' || record.tightening || ['effective', 'rolled_back'].includes(record.status), onClick: () => act(record as Revision, 'rollback') }] as MqttAction[]).filter(item => item.visible !== false)" :key="action.label" type="link" size="small" :danger="action.danger" :disabled="action.disabled || (!action.readOnly && isDemo())" @click="runMqttAction(action)">{{ action.label }}</Button></Space>
        </template>
      </MqttGrid3>
    </MqttDrawer1>
  </Page>
</template>
