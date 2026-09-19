<script setup lang="ts">
import type { TableColumnsType } from 'ant-design-vue';
import type { CommandAttempt, CurrentField, DeviceCommand, DeviceDetail, ModelOperation, ModelScalar, ModelSwitch, ModelVersion, Page, DeviceCurrent } from '../api';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Alert, Button, Card, Descriptions, DescriptionsItem, Empty, FormItem, Input, InputNumber, Modal, Pagination, Select, Table, Tag, message } from 'ant-design-vue';
import AppDrawer from '../components/app-drawer/app-drawer.vue';
import { ApiError, applyAdminContext, connectionColors, connectionNames, errorText, isCanceled, lifecycleNames, request, session } from '../api';
import { buildTableScrollX } from '../utils/table';

const route = useRoute();
const router = useRouter();
const device = ref<DeviceDetail | null>(null);
const busy = ref(false);
const failure = ref('');
const refreshedAt = ref<number | null>(null);
const commands = ref<Page<DeviceCommand>>({ items: [], total: 0, page: 1, per_page: 20 });
const telemetry = ref<DeviceCurrent | null>(null);
const can = (node: string) => session.realm === 'customer' && session.permissions.includes(`customer.${node}`);
const canReadDevice = computed(() => can('devices.read'));
const canReadTelemetry = computed(() => can('telemetry.read'));
const canReadCommands = computed(() => can('commands.read'));
const canCancel = computed(() => can('commands.cancel'));
const scopeKey = () => `${session.realm}:${session.token}:${session.tenant?.id}:${route.params.device}`;
const devicePath = () => `/customer/tenants/${session.tenant?.id}/devices/${route.params.device}`;
const commandVersion = ref(1);
const rawIdentifier = ref('');
const rawValues = ref('{}');
const actionCommand = ref('');
const canceling = ref(false);
const actionBusy = computed(() => commandSaving.value || switchSaving.value || querying.value || canceling.value);
type Intent = { kind: 'create' | 'switch' | 'query' | 'cancel'; path: string; data: Record<string, unknown> };
const intent = ref<Intent | null>(null);
function storageKey() { return session.identity?.key ? `typeapp.device-action.${session.identity.key}.${session.tenant?.id}.${route.params.device}` : ''; }
function restoreIntent() {
  intent.value = null; const key = storageKey(); if (!key) return;
  try {
    const stored = JSON.parse(sessionStorage.getItem(key) || 'null') as Intent | null;
    if (stored && ['create', 'switch', 'query', 'cancel'].includes(stored.kind) && stored.path.startsWith(devicePath() + '/') && stored.data && typeof stored.data === 'object') intent.value = stored;
  } catch { sessionStorage.removeItem(key); }
}
async function submitIntent<T>(kind: Intent['kind'], path: string, data: Record<string, unknown>): Promise<T> {
  const key = storageKey(); const scope = scopeKey(); const tenant = session.tenant?.id;
  if (!key || !tenant || intent.value && intent.value.kind !== kind) throw new Error('请先确认尚未确定受理的原操作。');
  const original = intent.value || { kind, path, data: JSON.parse(JSON.stringify(data)) };
  // 先保存原ID、版本和完整参数，刷新后仍确认首次操作；保存失败时不发送。
  sessionStorage.setItem(key, JSON.stringify(original)); intent.value = original;
  try {
    const result = await request<T>(original.path, { tenant, method: 'POST', data: original.data });
    if (scope !== scopeKey()) throw new DOMException('工作区已改变', 'AbortError');
    sessionStorage.removeItem(key); intent.value = null; return result;
  } catch (error) {
    if (scope === scopeKey() && error instanceof ApiError && error.status < 500 && error.status !== 408) { sessionStorage.removeItem(key); intent.value = null; }
    throw error;
  }
}
async function resumeIntent() {
  if (!intent.value || actionBusy.value) return;
  const original = intent.value; const scope = scopeKey(); commandSaving.value = true;
  try {
    await submitIntent(original.kind, original.path, original.data);
    if (scope !== scopeKey()) return;
    message.success('原操作受理已确认，请查看实际进度'); await load();
  } catch (error) { if (!isCanceled(error) && scope === scopeKey()) failure.value = errorText(error); }
  finally { if (scope === scopeKey()) commandSaving.value = false; }
}
const commandOpen = ref(false);
const selectedCommand = ref<DeviceCommand | null>(null);
const operation = ref<ModelOperation | null>(null);
const commandValues = ref<Record<string, number | string | boolean | undefined>>({});
const commandId = ref('');
const commandSaving = ref(false);
const commandFailure = ref('');
const commandFields = ref<string[]>([]);
const commandUncertain = ref(false);
const switches = ref<Page<ModelSwitch>>({ items: [], total: 0, page: 1, per_page: 20 });
const switchOpen = ref(false);
const switchSaving = ref(false);
const switchFailure = ref('');
const switchUncertain = ref(false);
const switchId = ref('');
const switchTarget = ref<number>();
const switchModels = ref<Page<ModelVersion>>({ items: [], total: 0, page: 1, per_page: 20 });
const switchModelsBusy = ref(false);
let switchRequest: AbortController | null = null;
let switchVersion = 0;
const canManageModel = computed(() => can('devices.model-switch'));
const requestedSwitchVersion = ref(1);
const retrySwitch = ref(false);
const switchOptions = computed(() => switchModels.value.items.map((item) => ({ value: item.model_version,
  label: `版本 ${item.model_version} · ${item.status === 'published' ? item.model_version === device.value?.model_version ? '当前版本' : '已发布' : '草稿，不可切换'}`,
  disabled: item.status !== 'published' || item.model_version === device.value?.model_version })));
const selectedModel = computed(() => switchModels.value.items.find((item) => item.model_version === switchTarget.value));
const switchNames: Record<ModelSwitch['state'], string> = { pending: '等待设备确认', unconfirmed: '超时，切换尚未确认', confirmed: '设备已确认切换', rejected: '设备拒绝切换' };
const switchReasons: Record<string, string> = { model_switched: '目标模型已在设备持久生效', model_unsupported: '设备未声明支持目标结构', model_state_conflict: '设备本地版本或待确认状态不一致', source_permission_revoked: '原会话或权限已失效，停止新的发送；已有设备效果继续对账' };
const queryId = ref('');
const querying = ref(false);
const queryUncertain = ref(false);
const timeline = ref<Page<CommandAttempt>>({ items: [], total: 0, page: 1, per_page: 20 });
const timelineBusy = ref(false);
let timelineRequest: AbortController | null = null;
let timelineVersion = 0;
const canCommand = computed(() => can('commands.create'));
const canQuery = computed(() => can('commands.query'));
const canStart = computed(() => canCommand.value && !intent.value && !failure.value && (!device.value || device.value.recovery_verified !== 0 && !device.value.model_switch && !device.value.transfer_frozen && device.value.lifecycle === 'enabled' && device.value.connection.status === 'online'));
const canReconcile = computed(() => canQuery.value && !failure.value && selectedCommand.value?.execution === 'unknown'
  && Date.now() / 1000 >= selectedCommand.value.manual_query_after && Date.now() / 1000 < selectedCommand.value.retain_until
  && !selectedCommand.value.manual_query_id && (!device.value || device.value.recovery_verified !== 0 && device.value.lifecycle === 'enabled' && device.value.connection.status === 'online'));
const commandNames: Record<DeviceCommand['execution'], string> = { pending: '等待执行证据', cancelled: '已取消，未下发', not_dispatched: '已停止，未下发', unknown: '执行结果未知', succeeded: '执行成功', failed: '执行失败', rejected: '设备拒绝执行' };
const commandCodes: Record<string, string> = { executed: '设备已返回执行结果', execution_unknown: '动作可能已开始，尚无确定结果', clock_untrusted: '设备没有可信时间，未开始动作', command_expired: '原截止时间已到，未开始动作', model_mismatch: '设备模型版本不匹配，未开始动作' };
const unknownReasons: Record<string, string> = { execution_started_without_result: '设备已记录动作开始，但尚无确定执行结果；不会重新启动动作。', result_not_retained: '设备未找到保留的结果，无法由此确定是否执行。', execution_receipt_missing: '原 60 秒期限内未获得执行回执，无法确定设备是否执行。' };
const attemptNames: Record<string, string> = { queued: '等待发送', claimed: '已领取，传输结果尚未确认', mqtt_acknowledged: 'Broker 已响应', transport_unknown: '传输结果未知', deadline_elapsed: '发送前期限已到', schedule_missed: '调度中断，已跳过旧节点', device_unavailable: '设备不可用，已跳过', result_known: '已有确定结果，无需发送', execution_evidence_or_expired: '已有执行证据或原期限已到，停止重投', retention_expired: '已超过保留期', source_permission_revoked: '原会话或权限已失效，未发送本次动作' };
const commandColumns: TableColumnsType<DeviceCommand> = [
  { title: '指令', key: 'command', width: 220 }, { title: '平台受理', key: 'accepted', width: 190 },
  { title: 'MQTT 交付', key: 'delivery', width: 170 }, { title: '设备执行', key: 'execution', width: 160 },
  { title: '原截止时间', key: 'deadline', width: 190 }, { title: '操作', key: 'actions', fixed: 'right', width: 80 },
];
function commandName(identifier: string) { return device.value?.model.definition.commands.find((item) => item.identifier === identifier)?.name || identifier; }
async function loadSwitchModels(pageNumber = 1) {
  const item = device.value; const tenant = session.tenant?.id;
  if (!item || !tenant || !switchOpen.value || !can('products.read')) return;
  const current = ++switchVersion; switchRequest?.abort(); switchRequest = new AbortController(); switchModelsBusy.value = true;
  try {
    const data = await request<Page<ModelVersion>>(`/customer/tenants/${tenant}/products/${item.product_id}/models`, { tenant, signal: switchRequest.signal, params: { page: pageNumber, per_page: 20 } });
    if (current === switchVersion) { switchModels.value = data; if (!switchUncertain.value) switchFailure.value = ''; }
  } catch (error) { if (!isCanceled(error) && current === switchVersion) switchFailure.value = errorText(error); }
  finally { if (current === switchVersion) switchModelsBusy.value = false; }
}
function openModelSwitch() {
  if (!canManageModel.value || intent.value || actionBusy.value) return;
  requestedSwitchVersion.value = device.value?.version || 1; retrySwitch.value = Boolean(device.value?.model_switch);
  switchId.value = device.value?.model_switch?.id || crypto.randomUUID().replaceAll('-', '');
  switchTarget.value = device.value?.model_switch?.target_version; switchFailure.value = ''; switchUncertain.value = false; switchOpen.value = true;
  void loadSwitchModels();
}
async function saveModelSwitch() {
  const tenant = session.tenant?.id; const scope = scopeKey();
  if (!tenant || !canManageModel.value || !switchTarget.value || actionBusy.value) return;
  switchSaving.value = true; switchFailure.value = '';
  try {
    await submitIntent<ModelSwitch>('switch', devicePath() + '/model-switches', { switch_id: switchId.value, model_version: switchTarget.value, version: requestedSwitchVersion.value, retry: retrySwitch.value });
    if (scope !== scopeKey()) return;
    switchUncertain.value = false; switchOpen.value = false; message.success('切换请求已受理，等待设备明确确认'); await load();
  } catch (error) {
    if (!isCanceled(error) && scope === scopeKey()) {
      switchUncertain.value = Boolean(intent.value);
      switchFailure.value = switchUncertain.value ? '受理尚未确认，请保留原目标和切换标识，再次确认原请求。' : errorText(error);
    }
  } finally { if (scope === scopeKey()) switchSaving.value = false; }
}
function deliveryText(command: DeviceCommand) { return command.device_received_at ? '设备已收到' : command.mqtt_at ? command.mqtt_reason !== null && command.mqtt_reason < 128 ? 'Broker 已接收' : 'Broker 已拒绝' : command.dispatch_at ? '发送结果未知' : '尚无发送记录'; }
function editCommand(item: DeviceCommand | null) {
  if (actionBusy.value || !item && intent.value) return;
  commandVersion.value = device.value?.version || 1; rawIdentifier.value = ''; rawValues.value = '{}';
  timelineVersion++; timelineRequest?.abort(); timelineBusy.value = false;
  selectedCommand.value = item; commandFailure.value = ''; commandFields.value = []; commandUncertain.value = false;
  queryId.value = crypto.randomUUID().replaceAll('-', ''); queryUncertain.value = false;
  timeline.value = item?.timeline || { items: [], total: 0, page: 1, per_page: 20 };
  commandId.value = item?.id || crypto.randomUUID().replaceAll('-', ''); operation.value = null; commandValues.value = {}; commandOpen.value = true;
}
async function loadTimeline(pageNumber = 1) {
  const item = selectedCommand.value; const tenant = session.tenant?.id;
  if (!item || !tenant || !commandOpen.value || !canReadCommands.value) return;
  const current = ++timelineVersion; timelineRequest?.abort(); timelineRequest = new AbortController(); timelineBusy.value = true;
  try {
    const data = await request<Page<CommandAttempt>>(`/customer/tenants/${tenant}/devices/${item.device_id}/commands/${item.id}/queries`, { tenant, signal: timelineRequest.signal, params: { page: pageNumber, per_page: 20 } });
    if (current === timelineVersion) { timeline.value = data; if (!queryUncertain.value) commandFailure.value = ''; }
  } catch (error) { if (!isCanceled(error) && current === timelineVersion) commandFailure.value = errorText(error); }
  finally { if (current === timelineVersion) timelineBusy.value = false; }
}
async function reconcileCommand() {
  const id = selectedCommand.value?.id || actionCommand.value; const scope = scopeKey();
  if (!/^[a-f0-9]{32}$/.test(id) || !canQuery.value || actionBusy.value) return;
  querying.value = true; commandFailure.value = '';
  try {
    await submitIntent<CommandAttempt>('query', devicePath() + `/commands/${id}/queries`, { query_id: queryId.value || crypto.randomUUID().replaceAll('-', '') });
    if (scope !== scopeKey()) return;
    queryUncertain.value = false; queryId.value = crypto.randomUUID().replaceAll('-', '');
    message.success('对账查询已受理，等待设备已有结果'); await load(); await loadTimeline();
  } catch (error) { if (!isCanceled(error) && scope === scopeKey()) { queryUncertain.value = Boolean(intent.value); commandFailure.value = errorText(error); } }
  finally { if (scope === scopeKey()) querying.value = false; }
}
function cancelCommand() {
  const id = selectedCommand.value?.id || actionCommand.value; const scope = scopeKey();
  if (!/^[a-f0-9]{32}$/.test(id) || !canCancel.value || actionBusy.value) return;
  Modal.confirm({ title: '确认取消未下发指令', content: '只有平台尚未领取发送的指令可以取消。可能已送达的动作会明确拒绝取消，并继续等待真实执行结果。', okText: '取消指令', cancelText: '返回', onOk: async () => {
    if (scope !== scopeKey() || actionBusy.value) return;
    canceling.value = true; commandFailure.value = '';
    try {
      const result = await submitIntent<DeviceCommand>('cancel', devicePath() + `/commands/${id}/cancel`, { cancel_id: crypto.randomUUID().replaceAll('-', '') });
      if (scope !== scopeKey()) return;
      if (selectedCommand.value?.id === id) selectedCommand.value = result;
      message.success('指令已取消，未下发'); await load();
    } catch (error) { if (!isCanceled(error) && scope === scopeKey()) commandFailure.value = errorText(error); }
    finally { if (scope === scopeKey()) canceling.value = false; }
  } });
}
function selectOperation(value: unknown) { operation.value = device.value?.model.definition.commands.find((item) => item.identifier === value) || null; commandValues.value = {}; }
function parameterValue(field: string, value: unknown) { commandValues.value[field] = value === null || value === undefined ? undefined : value as number | string | boolean; }
async function sendCommand() {
  const tenant = session.tenant?.id; const scope = scopeKey();
  if (!tenant || !canCommand.value || actionBusy.value || selectedCommand.value) return;
  commandSaving.value = true; commandFailure.value = ''; commandFields.value = [];
  try {
    const values = device.value ? commandValues.value : JSON.parse(rawValues.value);
    if (!values || typeof values !== 'object' || Array.isArray(values)) throw new Error('指令参数必须是 JSON 对象。');
    await submitIntent<DeviceCommand>('create', devicePath() + '/commands', { command_id: commandId.value, identifier: operation.value?.identifier || rawIdentifier.value, values, version: commandVersion.value });
    if (scope !== scopeKey()) return;
    commandOpen.value = false; message.success('平台已受理，请查看设备执行结果'); commands.value.page = 1; await load();
  } catch (error) {
    if (!isCanceled(error) && scope === scopeKey()) {
      commandUncertain.value = Boolean(intent.value);
      commandFailure.value = commandUncertain.value ? '受理结果尚未确认。原指令和版本已保存，请确认同一请求。' : errorText(error);
      if (error instanceof ApiError) commandFields.value = Object.keys(error.fields).map((field) => `${operation.value?.parameters.find((item) => item.identifier === field.replace(/^values\./, ''))?.name || field}：请检查必填、类型与物模型范围`);
    }
  } finally { if (scope === scopeKey()) commandSaving.value = false; }
}
const typeNames: Record<ModelScalar['type'], string> = { integer: '整数', number: '数值', boolean: '布尔', string: '字符串', enum: '枚举' };
const receiptNames: Record<string, string> = { accepted: '已保存', device_unavailable: '设备不可用', invalid_envelope: '报文格式不符合约定', model_mismatch: '模型版本不匹配', clock_ahead: '采样时间超前', sample_expired: '采样时间超过补报窗口', invalid_model_values: '字段不符合物模型' };
type PropertyRow = ModelScalar & { current?: CurrentField };
const properties = computed<PropertyRow[]>(() => {
  const fields = new Map(telemetry.value?.fields.map((field) => [field.identifier, field]));
  return telemetry.value?.model.definition.properties.map((property) => ({ ...property, current: fields.get(property.identifier) })) || [];
});
const columns: TableColumnsType<PropertyRow> = [
  { title: '属性', key: 'property', width: 160 }, { title: '类型', key: 'type', width: 88 },
  { title: '当前值', key: 'value', width: 180 }, { title: '单位', dataIndex: 'unit', width: 80 },
  { title: '采样时间', key: 'sampled', width: 200 }, { title: '平台接收时间', key: 'received', width: 200 },
  { title: '业务序号', key: 'sequence', width: 180 },
];
function timeText(value: number | null | undefined) { return value == null ? '—' : new Date(value * 1000).toLocaleString(); }
function valueText(field?: CurrentField) { return !field ? '尚未上报' : typeof field.value === 'string' ? `“${field.value}”` : String(field.value); }
let version = 0;
let pending: AbortController | null = null;
let active = true;
let refreshTimer: ReturnType<typeof setTimeout> | undefined;
function stopRefresh() { clearTimeout(refreshTimer); refreshTimer = undefined; }
function scheduleRefresh() {
  stopRefresh();
  if (active && !document.hidden) refreshTimer = setTimeout(() => { if (!busy.value) void load(); }, 5000);
}
function visibilityChanged() {
  stopRefresh();
  if (document.hidden) { version++; pending?.abort(); busy.value = false; }
  else void load();
}
async function load() {
  const tenant = session.tenant?.id;
  if (!tenant || !active || document.hidden || session.realm !== 'customer') return;
  stopRefresh(); const current = ++version; const scope = scopeKey();
  pending?.abort(); pending = new AbortController(); busy.value = true; failure.value = '';
  const options = { tenant, signal: pending.signal }; const path = devicePath();
  try {
    const [data, live, history, modelHistory] = await Promise.all([
      canReadDevice.value ? request<DeviceDetail>(path, options) : null,
      canReadTelemetry.value ? request<DeviceCurrent>(path + '/current', options) : null,
      canReadCommands.value ? request<Page<DeviceCommand> & { context: { permissions: string[]; menus: typeof session.menus } }>(path + '/commands', { ...options, params: { page: commands.value.page, per_page: commands.value.per_page } }) : null,
      canReadDevice.value ? request<Page<ModelSwitch>>(path + '/model-switches', { ...options, params: { page: switches.value.page, per_page: switches.value.per_page } }) : null,
    ]);
    if (current !== version || scope !== scopeKey()) return;
    device.value = data; telemetry.value = live; refreshedAt.value = Date.now();
    commands.value = history || { items: [], total: 0, page: 1, per_page: 20 };
    switches.value = modelHistory || { items: [], total: 0, page: 1, per_page: 20 };
    if (history) applyAdminContext(history.context);
    if (selectedCommand.value && commandOpen.value && canReadCommands.value) {
      const selected = await request<DeviceCommand>(path + `/commands/${selectedCommand.value.id}`, options);
      if (current !== version || scope !== scopeKey()) return;
      selectedCommand.value = selected; void loadTimeline(timeline.value.page);
    }
  } catch (error) {
    if (!isCanceled(error) && current === version && scope === scopeKey()) {
      failure.value = errorText(error);
      if (error instanceof ApiError && [401, 403, 404].includes(error.status)) { device.value = null; telemetry.value = null; commands.value.items = []; selectedCommand.value = null; }
    }
  } finally { if (current === version && scope === scopeKey()) { busy.value = false; scheduleRefresh(); } }
}
watch([() => session.tenant?.id, () => route.params.device, () => session.token, () => session.realm], ([tenant]) => {
  stopRefresh(); version++; pending?.abort(); device.value = null; telemetry.value = null; refreshedAt.value = null; busy.value = false; failure.value = '';
  commands.value = { items: [], total: 0, page: 1, per_page: 20 }; commandOpen.value = false; selectedCommand.value = null; operation.value = null; commandValues.value = {};
  commandId.value = ''; commandSaving.value = false; commandFailure.value = ''; commandFields.value = []; commandUncertain.value = false; rawIdentifier.value = ''; rawValues.value = '{}';
  actionCommand.value = ''; querying.value = false; canceling.value = false; queryId.value = ''; queryUncertain.value = false;
  timelineVersion++; timelineRequest?.abort(); timelineBusy.value = false; timeline.value = { items: [], total: 0, page: 1, per_page: 20 };
  switches.value = { items: [], total: 0, page: 1, per_page: 20 }; switchModels.value = { items: [], total: 0, page: 1, per_page: 20 };
  switchOpen.value = false; switchSaving.value = false; switchUncertain.value = false; switchFailure.value = ''; switchModelsBusy.value = false; switchTarget.value = undefined;
  switchVersion++; switchRequest?.abort(); restoreIntent();
  if (tenant && session.realm === 'customer') void load(); else if (route.path.startsWith('/devices/')) void router.replace('/tenants');
}, { immediate: true });
watch(() => session.permissions.join(','), () => {
  if (!canReadTelemetry.value) telemetry.value = null;
  if (!canReadCommands.value) { commands.value.items = []; selectedCommand.value = null; timelineVersion++; timelineRequest?.abort(); timeline.value.items = []; }
  if (!canReadDevice.value) { device.value = null; switches.value.items = []; }
});
onMounted(() => document.addEventListener('visibilitychange', visibilityChanged));
watch(commandOpen, (open) => { if (!open) { timelineVersion++; timelineRequest?.abort(); } });
watch(switchOpen, (open) => { if (!open) { switchVersion++; switchRequest?.abort(); } });
onBeforeUnmount(() => { active = false; stopRefresh(); document.removeEventListener('visibilitychange', visibilityChanged); version++; pending?.abort(); timelineVersion++; timelineRequest?.abort(); switchVersion++; switchRequest?.abort(); });
</script>

<template>
  <section class="iot-page">
    <header class="page-heading"><div><h1>设备详情</h1><p class="muted tenant-title">{{ device?.name || session.tenant?.name }}</p></div><Button @click="router.push('/devices')">返回设备</Button></header>
    <Alert v-if="intent" message="上次操作受理结果尚未确认" type="warning" show-icon class="page-alert"><template #description><p>已保留原操作标识、版本与参数；请确认原受理，不要另建动作。</p><Button :loading="actionBusy" :disabled="actionBusy" @click="resumeIntent">确认原操作受理</Button></template></Alert>
    <Alert v-if="failure && !canReadDevice" :message="failure" type="error" show-icon class="page-alert" />
    <Button v-if="canReadTelemetry" @click="router.push({ path: '/history', query: { device: route.params.device } })">查看历史曲线</Button>
    <Card v-if="canReadDevice" title="基本信息" :bordered="false" :loading="busy && !device">
      <template #extra><Button :loading="busy" @click="load">刷新</Button></template>
      <Alert v-if="failure" :message="failure" :description="device ? '刷新失败，以下为上次成功读取的数据，连接状态、当前值和接收结果可能已变化。' : undefined" type="error" show-icon class="page-alert" role="alert" />
      <Alert v-if="device?.recovery_verified === 0" message="恢复后尚未核对设备当前授权与归属，接入、控制和变更保持隔离。请联系恢复核对负责人。" type="warning" show-icon class="page-alert" />
      <p class="muted">{{ busy ? '刷新中' : refreshedAt ? `数据读取于 ${new Date(refreshedAt).toLocaleString()}` : '等待数据' }} · 可见时每 5 秒刷新</p>
      <Descriptions v-if="device" :column="{ xs: 1, sm: 2 }" layout="vertical" bordered>
        <DescriptionsItem label="设备名称">{{ device.name }}</DescriptionsItem><DescriptionsItem label="设备标识">{{ device.id }}</DescriptionsItem>
        <DescriptionsItem label="生命周期"><Tag>{{ lifecycleNames[device.lifecycle] }}</Tag></DescriptionsItem><DescriptionsItem label="所属租户">{{ session.tenant?.name }}</DescriptionsItem>
        <DescriptionsItem label="连接状态"><Tag :color="connectionColors[device.connection.status]">{{ connectionNames[device.connection.status] }}</Tag></DescriptionsItem><DescriptionsItem label="连接观察时间">{{ device.connection.observed_at === null ? '尚无连接观察' : new Date(device.connection.observed_at * 1000).toLocaleString() }}</DescriptionsItem>
        <DescriptionsItem label="服务观察时间" :span="2">{{ device.connection.broker_observed_at === null ? '尚无服务观察' : new Date(device.connection.broker_observed_at * 1000).toLocaleString() }}</DescriptionsItem>
        <DescriptionsItem label="产品标识">{{ device.product_id }}</DescriptionsItem><DescriptionsItem label="模型版本"><Button type="link" @click="router.push(`/products/${device.product_id}/models`)">版本 {{ device.model_version }}</Button></DescriptionsItem>
        <DescriptionsItem label="归属阶段">{{ device.ownership_id }}</DescriptionsItem><DescriptionsItem label="注册时间">{{ new Date(Number(device.created_at) * 1000).toLocaleString() }}</DescriptionsItem>
        <DescriptionsItem v-if="can('transfers.read') || can('transfers.request')" label="转移状态"><Button type="link" @click="router.push({ path: '/transfers', query: { device: device.id, transfer: device.transfer_id || undefined } })">{{ device.transfer_frozen ? '已冻结，继续处理' : device.transfer_id ? '等待目标审批' : '查看或发起转移' }}</Button></DescriptionsItem>
        <DescriptionsItem label="上报 Topic" :span="2">{{ device.topics.publish }}</DescriptionsItem><DescriptionsItem label="下行 Topic" :span="2">{{ device.topics.subscribe }}</DescriptionsItem>
      </Descriptions>
      <Empty v-else-if="!busy" description="设备资料暂不可用，请重试" />
      <p v-if="device" class="muted">连接状态来自服务端观察；服务暂不可达或观察过期时为未知。生命周期独立表示设备是否获准使用。</p>
    </Card>
    <Card v-if="device || canManageModel" title="模型切换" :bordered="false" class="device-data-card">
      <template #extra><Button v-if="canManageModel" :disabled="Boolean(intent) || actionBusy || Boolean(failure) || Boolean(device && (device.recovery_verified === 0 || device.transfer_id || device.lifecycle !== 'enabled' || device.authorization?.status === 'pending' || !device.credential_active))" @click="openModelSwitch">{{ device?.model_switch ? '继续原切换' : '切换模型' }}</Button></template>
      <p class="muted">当前绑定版本 {{ device?.model_version ?? '未获准读取' }}。目标版本由设备明确支持并确认后生效；超时继续待处理。切换期间暂停新控制，旧缓存与历史按原版本保留。</p>
      <Alert v-if="device?.model_switch" :message="`版本 ${device?.model_switch.source_version} → ${device?.model_switch.target_version} · ${switchNames[device?.model_switch.state]}`" type="warning" show-icon class="page-alert" />
      <Empty v-if="!switches.items.length && canReadDevice" description="尚无模型切换记录" />
      <article v-for="item in switches.items" :key="item.id" class="command-attempt">
        <p><Tag :color="item.status === 'confirmed' ? 'success' : item.status === 'rejected' ? 'error' : 'warning'">{{ switchNames[item.state] }}</Tag>版本 {{ item.source_version }} → {{ item.target_version }}</p>
        <p>{{ item.same_structure ? '业务结构一致' : '业务结构有变化，历史不会直接合并' }} · {{ timeText(item.created_at) }}</p>
        <p v-if="item.result_code">{{ switchReasons[item.result_code] || item.result_code }} · {{ timeText(item.confirmed_at) }}</p>
        <p class="muted current-value">切换标识：{{ item.id }}<br>设备切换边界序号：{{ item.boundary_sequence ?? '尚未确认' }}</p>
      </article>
      <Pagination v-if="switches.total > switches.per_page" :current="switches.page" :page-size="switches.per_page" :total="switches.total" :show-size-changer="false" simple @change="(page) => { switches.page = page; void load(); }" />
    </Card>
    <Card v-if="telemetry" title="当前数据" :bordered="false" class="device-data-card">
      <template #extra><Tag :color="failure ? 'warning' : telemetry.freshness === 'empty' ? 'default' : telemetry.freshness === 'stale' ? 'warning' : 'success'">{{ failure ? '上次读取数据' : telemetry.freshness === 'empty' ? '尚无遥测' : telemetry.freshness === 'stale' ? '数据陈旧' : '数据新鲜' }}</Tag></template>
      <p class="muted">按绑定模型版本 {{ telemetry.model_version }} 解释属性。新鲜度由服务端判断：60 秒没有有效新遥测即陈旧，保留已有值。旧补传可推进当前值，但不会刷新实时状态；重复、事件和乱序不刷新。</p>
      <Descriptions :column="{ xs: 1, sm: 2 }" layout="vertical" bordered>
        <DescriptionsItem label="有效实时采样时间">{{ timeText(telemetry.realtime_sampled_at) }}</DescriptionsItem>
        <DescriptionsItem label="有效实时接收时间">{{ timeText(telemetry.realtime_received_at) }}</DescriptionsItem>
        <DescriptionsItem label="有效实时序号" :span="2">{{ telemetry.realtime_sequence || '尚无有效实时遥测' }}</DescriptionsItem>
      </Descriptions>
      <Table v-if="properties.length" :columns="columns" :data-source="properties" row-key="identifier" :pagination="false" :scroll="buildTableScrollX(columns)">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'property'">{{ record.name }}<div class="muted">{{ record.identifier }}</div></template>
          <template v-else-if="column.key === 'type'">{{ typeNames[record.type as ModelScalar['type']] }}</template>
          <span v-else-if="column.key === 'value'" class="current-value">{{ valueText(record.current) }}</span>
          <template v-else-if="column.dataIndex === 'unit'">{{ record.unit || '—' }}</template>
          <template v-else-if="column.key === 'sampled'">{{ timeText(record.current?.sampled_at) }}</template>
          <template v-else-if="column.key === 'received'">{{ timeText(record.current?.received_at) }}</template>
          <span v-else-if="column.key === 'sequence'" class="current-value">{{ record.current?.sequence || '—' }}</span>
        </template>
      </Table>
      <Empty v-else description="绑定模型未定义属性" />
    </Card>
    <Card v-if="telemetry" title="设备缓存" :bordered="false" class="device-data-card">
      <template #extra><Tag :color="failure || telemetry.buffer?.freshness === 'stale' ? 'warning' : telemetry.buffer?.values.full ? 'error' : 'default'">{{ failure ? '上次读取数据' : !telemetry.buffer ? '尚无观察' : telemetry.buffer.freshness === 'stale' ? '观察已过期' : telemetry.buffer.values.full ? '缓存已满' : '尚有空间' }}</Tag></template>
      <Descriptions v-if="telemetry.buffer" :column="{ xs: 1, sm: 2 }" layout="vertical" bordered>
        <DescriptionsItem label="最近报告状态"><Tag :color="telemetry.buffer.values.full ? 'error' : 'success'">{{ telemetry.buffer.values.full ? '缓存已满' : '尚有空间' }}</Tag></DescriptionsItem>
        <DescriptionsItem label="容量原因">{{ telemetry.buffer.values.capacity_reason === 'records' ? '条数不足' : telemetry.buffer.values.capacity_reason === 'bytes' ? '载荷空间不足' : '未发生容量拒绝' }}</DescriptionsItem>
        <DescriptionsItem label="待确认条数">{{ telemetry.buffer.values.pending_count }} / {{ telemetry.buffer.values.maximum_records }}</DescriptionsItem>
        <DescriptionsItem label="待确认载荷">{{ telemetry.buffer.values.pending_bytes }} / {{ telemetry.buffer.values.maximum_bytes }} 字节</DescriptionsItem>
        <DescriptionsItem label="未接纳采样">{{ telemetry.buffer.values.not_admitted }}</DescriptionsItem>
        <DescriptionsItem label="终局拒绝总数">{{ telemetry.buffer.values.rejected_total }}</DescriptionsItem>
        <DescriptionsItem label="本地保留异常">{{ telemetry.buffer.values.exception_count }}</DescriptionsItem>
        <DescriptionsItem label="已淘汰异常">{{ telemetry.buffer.values.exceptions_dropped }}</DescriptionsItem>
        <DescriptionsItem label="设备观察时间">{{ timeText(telemetry.buffer.sampled_at) }}</DescriptionsItem>
        <DescriptionsItem label="平台观察时间">{{ timeText(telemetry.buffer.received_at) }}</DescriptionsItem>
      </Descriptions>
      <Empty v-else description="当前归属阶段及模型尚无缓存报告" />
      <Alert v-if="telemetry.buffer?.values.counters_saturated" message="累计计数已达到上限，显示值为下界。" type="warning" show-icon class="page-alert" />
      <p class="muted">显示设备最近报告的缓存观察，60 秒未更新或设备时间异常即过期；失联期间容量可能继续变化。协议确认不会清理待确认采样，只有平台持久接收回执才能清理。载荷额度不包含本地元数据。</p>
    </Card>
    <Card v-if="telemetry" title="平台接收结果" :bordered="false" class="device-data-card">
      <Descriptions v-if="telemetry.last_receipt" :column="{ xs: 1, sm: 2 }" layout="vertical" bordered>
        <DescriptionsItem label="业务序号">{{ telemetry.last_receipt.sequence }}</DescriptionsItem>
        <DescriptionsItem label="平台结果"><Tag :color="telemetry.last_receipt.status === 'accepted' ? 'success' : 'error'">{{ telemetry.last_receipt.status === 'accepted' ? '已接收' : '已拒绝' }}</Tag></DescriptionsItem>
        <DescriptionsItem label="结果说明">{{ receiptNames[telemetry.last_receipt.code] || telemetry.last_receipt.code }}</DescriptionsItem>
        <DescriptionsItem label="首次接收时间">{{ timeText(telemetry.last_receipt.received_at) }}</DescriptionsItem>
      </Descriptions>
      <Empty v-else description="当前归属阶段尚无接收记录" />
      <p class="muted">显示当前归属阶段最新首次接收记录，重复上报不会改变排序。此结果表示平台保存的接收事实，不代表设备已经收到业务回执。</p>
    </Card>
    <Card v-if="canReadCommands || canCommand || canQuery || canCancel" title="设备指令" :bordered="false" class="device-data-card">
      <template #extra><Button v-if="canCommand" type="primary" :disabled="!canStart || Boolean(device && !device.model.definition.commands.length)" @click="editCommand(null)">发起指令</Button></template>
      <Alert v-if="device?.transfer_frozen" type="warning" show-icon message="设备转移已冻结新控制；原指令结果仍可查询、对账。" class="page-alert" />
      <Alert v-if="!canReadCommands" message="当前角色不能读取指令列表；获准的操作可使用准确指令标识执行。" type="info" show-icon class="page-alert" />
      <p class="muted">原截止时间固定为受理后 60 秒。在线且无执行证据时在 0/10/30 秒最多发送三次；到期无结果则在 60/120/300 秒查询已有结果，之后可主动对账。迟到合法回执可以补全未知结果；查询不会启动动作。</p>
      <Alert v-if="commandFailure && !commandOpen" :message="commandFailure" type="error" show-icon class="page-alert" />
      <div v-if="!canReadCommands && (canQuery || canCancel)" class="toolbar-actions"><Input v-model:value="actionCommand" aria-label="目标指令标识" :maxlength="32" :disabled="actionBusy || Boolean(intent)" placeholder="准确指令标识" /><Button v-if="canQuery" :loading="querying" :disabled="actionBusy || Boolean(intent)" @click="reconcileCommand">主动对账</Button><Button v-if="canCancel" danger :loading="canceling" :disabled="actionBusy || Boolean(intent)" @click="cancelCommand">取消未下发指令</Button></div>
      <Table v-if="canReadCommands" :columns="commandColumns" :data-source="commands.items" row-key="id" :scroll="buildTableScrollX(commandColumns)" :pagination="{ current: commands.page, pageSize: commands.per_page, total: commands.total, showSizeChanger: true, pageSizeOptions: ['20', '50', '100'] }" @change="(page) => { commands.page = page.current || 1; commands.per_page = page.pageSize || 20; void load(); }">
        <template #emptyText><Empty description="此设备尚无指令记录" /></template>
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'command'">{{ commandName(record.identifier) }}<div class="muted current-value">{{ record.id }}</div></template>
          <template v-else-if="column.key === 'accepted'">已受理<div class="muted">{{ timeText(record.accepted_at) }}</div></template>
          <template v-else-if="column.key === 'delivery'">{{ deliveryText(record as DeviceCommand) }}</template>
          <Tag v-else-if="column.key === 'execution'" :color="record.execution === 'succeeded' ? 'success' : record.execution === 'pending' ? 'processing' : 'warning'">{{ commandNames[record.execution as DeviceCommand['execution']] }}</Tag>
          <template v-else-if="column.key === 'deadline'">{{ timeText(record.deadline_at) }}</template>
          <Button v-else-if="column.key === 'actions'" type="link" @click="editCommand(record as DeviceCommand)">详情</Button>
        </template>
      </Table>
    </Card>
    <AppDrawer v-model:open="switchOpen" :title="device?.model_switch ? '继续原模型切换' : '切换设备模型'" width-size="sm" :confirm-loading="switchSaving" :ok-disabled="!switchTarget || !canManageModel || (can('products.read') && Boolean(device) && !device?.model_switch && !switchUncertain && (!selectedModel || selectedModel.status !== 'published'))" :ok-text="switchUncertain ? '确认原切换受理' : device?.model_switch ? '重试原切换' : '请求设备确认'" @ok="saveModelSwitch">
      <Alert v-if="switchFailure" :message="switchFailure" type="error" show-icon class="page-alert" role="alert" />
      <Alert message="设备明确确认后才改变绑定版本" description="请先确认固件支持目标版本；提交成功表示请求已受理，超时仍需继续原切换。" type="info" show-icon class="page-alert" />
      <template v-if="!canReadDevice"><FormItem label="操作方式"><Select :value="retrySwitch ? 'retry' : 'create'" aria-label="模型切换操作方式" :options="[{ value: 'create', label: '发起新切换' }, { value: 'retry', label: '重试原切换' }]" :disabled="switchSaving || switchUncertain" @change="value => retrySwitch = value === 'retry'" /></FormItem><FormItem label="切换标识" required><Input v-model:value="switchId" aria-label="模型切换标识" :maxlength="32" :disabled="switchSaving || switchUncertain" /><p class="muted">重试时填写原切换标识和目标版本，不创建另一条意图。</p></FormItem></template>
      <FormItem label="设备版本" required><InputNumber v-model:value="requestedSwitchVersion" aria-label="切换设备版本" :min="1" :max="2147483645" :precision="0" :disabled="switchSaving || switchUncertain" /></FormItem>
      <FormItem v-if="!can('products.read') || !device" label="目标版本" required><InputNumber v-model:value="switchTarget" aria-label="目标模型版本" :min="1" :max="2147483646" :precision="0" :disabled="switchSaving || switchUncertain || (retrySwitch && Boolean(device))" /></FormItem>
      <FormItem v-else label="目标版本" required><Select v-model:value="switchTarget" aria-label="目标模型版本" :options="switchOptions" :loading="switchModelsBusy" :disabled="switchSaving || switchUncertain || Boolean(device?.model_switch)" placeholder="选择已发布版本" /></FormItem>
      <Button v-if="can('products.read') && device" :loading="switchModelsBusy" @click="loadSwitchModels(switchModels.page)">刷新版本</Button>
      <Pagination v-if="switchModels.total > switchModels.per_page" :current="switchModels.page" :page-size="switchModels.per_page" :total="switchModels.total" :show-size-changer="false" simple @change="loadSwitchModels" />
      <Descriptions v-if="selectedModel" :column="1" layout="vertical" bordered>
        <DescriptionsItem label="结构比较">{{ selectedModel.structure_hash === device?.model.structure_hash ? '业务结构一致，仍需设备明确确认' : '业务结构有变化，旧历史与聚合按原版本解释' }}</DescriptionsItem>
        <DescriptionsItem label="目标属性"><p v-for="field in selectedModel.definition.properties" :key="field.identifier" class="current-value">{{ field.name }} · {{ typeNames[field.type] }}{{ field.unit ? ` · ${field.unit}` : '' }}{{ field.values ? ` · ${field.values.join(' / ')}` : '' }}</p><Empty v-if="!selectedModel.definition.properties.length" description="此版本没有属性" /></DescriptionsItem>
      </Descriptions>
    </AppDrawer>
    <AppDrawer v-model:open="commandOpen" :title="selectedCommand ? '指令详情' : '发起设备指令'" width-size="sm" :show-footer="!selectedCommand" :confirm-loading="commandSaving" :ok-disabled="(!operation && !rawIdentifier) || !canCommand" :ok-text="commandUncertain ? '确认原指令受理' : '确认发起'" @ok="sendCommand">
      <template v-if="selectedCommand">
        <Alert v-if="commandFailure" :message="commandFailure" type="error" show-icon class="page-alert" role="alert" />
        <Alert v-if="selectedCommand.unknown_reason" :message="unknownReasons[selectedCommand.unknown_reason] || '执行结果未知'" type="warning" show-icon class="page-alert" />
        <p v-if="canQuery && selectedCommand.execution === 'unknown'" class="muted">{{ selectedCommand.manual_query_id ? '已有主动查询等待发送。' : `可主动对账时间：${timeText(selectedCommand.manual_query_after)}。需设备在线，查询之间至少间隔 10 秒。` }}</p>
        <Button v-if="canQuery && selectedCommand.execution === 'unknown'" :disabled="(!canReconcile && !queryUncertain) || querying" :loading="querying" @click="reconcileCommand">{{ queryUncertain ? '确认原查询受理' : '主动对账' }}</Button>
        <Button v-if="canCancel && selectedCommand.dispatch_at === null && !['cancelled', 'not_dispatched', 'succeeded', 'failed', 'rejected'].includes(selectedCommand.execution)" danger :disabled="actionBusy || Boolean(intent)" :loading="canceling" @click="cancelCommand">取消未下发指令</Button>
        <Descriptions :column="{ xs: 1, sm: 2 }" layout="vertical" bordered>
          <DescriptionsItem label="指令标识" :span="2">{{ selectedCommand.id }}</DescriptionsItem><DescriptionsItem label="指令名称">{{ commandName(selectedCommand.identifier) }}</DescriptionsItem><DescriptionsItem label="绑定模型">版本 {{ selectedCommand.model_version }}</DescriptionsItem>
          <DescriptionsItem label="平台受理">{{ timeText(selectedCommand.accepted_at) }}</DescriptionsItem><DescriptionsItem label="原截止时间">{{ timeText(selectedCommand.deadline_at) }}</DescriptionsItem>
          <DescriptionsItem label="MQTT交付">{{ deliveryText(selectedCommand) }}</DescriptionsItem><DescriptionsItem label="执行结果">{{ commandNames[selectedCommand.execution] }}</DescriptionsItem>
          <DescriptionsItem label="设备开始执行">{{ timeText(selectedCommand.started_at) }}</DescriptionsItem><DescriptionsItem label="平台收到执行结果">{{ timeText(selectedCommand.result_received_at) }}</DescriptionsItem>
          <DescriptionsItem label="指令参数" :span="2"><pre class="current-value">{{ JSON.stringify(selectedCommand.values, null, 2) }}</pre></DescriptionsItem>
          <DescriptionsItem label="执行回执" :span="2"><pre class="current-value">{{ selectedCommand.result ? JSON.stringify(selectedCommand.result, null, 2) : '尚无确定执行结果' }}</pre>{{ selectedCommand.result_code ? commandCodes[selectedCommand.result_code] || '设备返回了执行说明' : '' }}</DescriptionsItem>
          <DescriptionsItem label="保留至" :span="2">{{ timeText(selectedCommand.retain_until) }} · 保留期结束不代表动作已完成</DescriptionsItem>
        </Descriptions>
        <h3>发送与查询时间线</h3>
        <p class="muted">{{ timelineBusy ? '正在刷新证据' : '每条记录对应原指令的一个节点或一次主动查询。领取与协议响应均不代表设备执行成功。' }}</p>
        <Empty v-if="!timeline.items.length" description="尚无发送或查询证据" />
        <article v-for="attempt in timeline.items" :key="attempt.id" class="command-attempt">
          <p><Tag>{{ attempt.kind === 'send' ? '指令发送' : attempt.trigger_kind === 'manual' ? '主动查询' : '自动查询' }}</Tag>{{ timeText(attempt.scheduled_at) }}</p>
          <p>{{ attemptNames[attempt.state] || attempt.state }}<span v-if="attempt.mqtt_reason !== null"> · {{ attempt.mqtt_reason < 128 ? 'Broker 接收' : 'Broker 拒绝' }}</span></p>
          <p v-if="attempt.response_at">{{ timeText(attempt.response_at) }} · {{ attempt.response_code === 'result_found' ? '设备返回已有结果' : '设备没有保留结果，继续未知' }}</p>
          <p class="muted current-value">查询/发送标识：{{ attempt.id }}<br>人员标识：{{ attempt.actor_id }}</p>
        </article>
        <Pagination v-if="timeline.total > timeline.per_page" :current="timeline.page" :page-size="timeline.per_page" :total="timeline.total" :show-size-changer="false" simple @change="loadTimeline" />
      </template>
      <template v-else>
        <Alert v-if="commandFailure" :message="commandFailure" type="error" show-icon class="page-alert" role="alert" />
        <p v-for="field in commandFields" :key="field" class="current-value">{{ field }}</p>
        <p class="muted">确认后向当前设备发起一次动作。平台受理不等于设备执行成功，请在记录中查看结果。</p>
        <FormItem label="设备版本" required><InputNumber v-model:value="commandVersion" aria-label="指令设备版本" :min="1" :max="2147483645" :precision="0" :disabled="commandSaving || commandUncertain" /></FormItem>
        <template v-if="!device"><FormItem label="指令标识" required><Input v-model:value="rawIdentifier" aria-label="指令标识" :disabled="commandSaving || commandUncertain" /></FormItem><FormItem label="指令参数 JSON" required><Input.TextArea v-model:value="rawValues" aria-label="指令参数 JSON" :disabled="commandSaving || commandUncertain" :rows="5" /></FormItem></template>
        <FormItem v-else label="选择指令" required><Select :value="operation?.identifier" aria-label="选择指令" :disabled="commandSaving || commandUncertain" :options="device?.model.definition.commands.map((item) => ({ label: item.name, value: item.identifier }))" @change="selectOperation" /></FormItem>
        <div class="form-grid">
          <FormItem v-for="field in operation?.parameters || []" :key="field.identifier" :label="field.name" :required="field.required" :class="field.type === 'string' ? 'span-full' : ''">
            <Select v-if="field.type === 'boolean'" :value="commandValues[field.identifier] === undefined ? undefined : String(commandValues[field.identifier])" :aria-label="field.name" :disabled="commandSaving || commandUncertain" :allow-clear="!field.required" :options="[{ label: '是', value: 'true' }, { label: '否', value: 'false' }]" @change="(value) => parameterValue(field.identifier, value === undefined ? undefined : value === 'true')" />
            <Select v-else-if="field.type === 'enum'" :value="commandValues[field.identifier] as string | undefined" :aria-label="field.name" :disabled="commandSaving || commandUncertain" :allow-clear="!field.required" :options="field.values?.map((value) => ({ label: value, value }))" @change="(value) => parameterValue(field.identifier, value)" />
            <InputNumber v-else-if="field.type === 'integer' || field.type === 'number'" :value="commandValues[field.identifier] as number | undefined" :aria-label="field.name" :disabled="commandSaving || commandUncertain" :min="field.min" :max="field.max" :precision="field.type === 'integer' ? 0 : undefined" style="width:100%" @change="(value) => parameterValue(field.identifier, value)" />
            <Input v-else :value="commandValues[field.identifier] as string | undefined" :aria-label="field.name" :disabled="commandSaving || commandUncertain" @update:value="(value) => parameterValue(field.identifier, value)" />
          </FormItem>
        </div>
      </template>
    </AppDrawer>
  </section>
</template>

<style scoped>
.command-attempt { margin-block: 12px; padding: 12px; border: 1px solid hsl(var(--border)); border-radius: 6px; }
.command-attempt p { margin-block: 4px; overflow-wrap: anywhere; }
</style>
