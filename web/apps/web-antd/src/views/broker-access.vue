<script setup lang="ts">
/**
 * Broker 授权页：主体、受信 CA、客户端证书绑定、签名 CRL、平台吊销、换证预览与版本生效。
 * 握手 CA 文件不在本页更新；保存成功不等于集群生效。隐藏页停止轮询。
 */
import type { FormInstance, TableColumnsType } from 'ant-design-vue';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Alert, Button, Card, Checkbox, Form, FormItem, Input, InputNumber, InputPassword, Select, Switch, Table, Tag, message } from 'ant-design-vue';
import AppDrawer from '../components/app-drawer/app-drawer.vue';
import CrudSearchField from '../components/crud-search-field.vue';
import CrudTableActions from '../components/crud-table-actions.vue';
import { buildTableScrollX, estimateVisibleActionColumnWidth } from '../utils/table';
import { ApiError, errorText, isCanceled, request, session } from '../api';

interface Grant { topic: string; publish: boolean; subscribe: boolean; max_qos: number }
interface Certificate { id: string; fingerprint: string; subject: string; not_after: number; overlap_until: number; certificate_version: number; status: string }
interface Principal {
  id: string; name: string; enabled: boolean; login: string | null; credential_version: number | null;
  recovery_verified?: number; grants: Grant[]; certificates: Certificate[]; updated_at: number;
}
interface Authority {
  id: string; fingerprint: string; subject: string; created_at: number; updated_at: number;
  recovery_verified?: number;
  crl?: {
    configured: boolean; source: string; url: string; fetch_interval: number; fetch_status: string; fetch_error: string;
    fetched_at: number; accepted_at: number; this_update: number; next_update: number; serial_count: number; serials: string[];
    state: string; recovery: string; execute_stage: string;
  };
}
interface CaPage { items: Authority[]; current_version: number; publish_paused?: boolean }
interface NodeState { node_id: string; applied_version: number; state: string; updated_at: number }
interface Revision {
  id: string; operation_id: string; version: number; actor_id: string; actor_realm: string; tightening: boolean;
  status: string; stage: string; created_at: number; updated_at: number; nodes: NodeState[]; pending_invalidations: number;
}
interface RotationPreview {
  principal_id: string; name: string; current_version: number; overlap_seconds: number; overlap_until: number;
  incoming: { fingerprint: string; subject: string; not_after: number };
  retiring: { id: string; fingerprint: string; subject: string; not_after: number; overlap_until: number; action: string }[];
  tightening: boolean;
}
interface PrincipalPage { items: Principal[]; total: number; page: number; per_page: number; current_version: number; publish_paused?: boolean }
interface RevisionPage { items: Revision[]; total: number; page: number; per_page: number }
const route = useRoute();
const router = useRouter();
const platform = computed(() => session.realm === 'admin');
const tenant = computed(() => session.realm === 'customer' && !platform.value ? session.tenant?.id : undefined);
const base = computed(() => session.realm === 'broker' ? '/broker' : platform.value ? '/admin/broker' : `/customer/tenants/${tenant.value}/broker`);
const canRead = computed(() => session.realm === 'broker' ? !!session.user?.platform_admin
  : session.permissions.includes(`${session.realm}.broker.read`) || session.permissions.includes(`${session.realm}.broker.write`));
const canWrite = computed(() => session.realm === 'broker' ? !!session.user?.platform_admin : session.permissions.includes(`${session.realm}.broker.write`));
const result = ref<PrincipalPage>({ items: [], total: 0, page: 1, per_page: 20, current_version: 0, publish_paused: false });
const cas = ref<CaPage>({ items: [], current_version: 0, publish_paused: false });
const revisions = ref<RevisionPage>({ items: [], total: 0, page: 1, per_page: 20 });
const filters = reactive({ name: '' });
let applied = '';
const busy = ref(false); const failure = ref(''); const denied = ref(false);
const open = ref(false); const saving = ref(false); const saveFailure = ref('');
const editing = ref<Principal | null>(null);
const historyOpen = ref(false); const historyBusy = ref(false); const historyFailure = ref('');
const acting = ref('');
const formRef = ref<FormInstance>();
const form = reactive({ name: '', login: '', password: '', enabled: true, rotate: false, certificatePem: '', overlapSeconds: 86400, revokeFingerprints: [] as string[], grants: [{ topic: '', publish: true, subscribe: true, max_qos: 2 }] as Grant[] });
const preview = ref<RotationPreview | null>(null);
const previewBusy = ref(false);
const previewFailure = ref('');
const caPem = ref('');
const caSaving = ref(false);
const caFailure = ref('');
const crlPem = ref('');
const crlUrl = ref('');
const crlInterval = ref(300);
const crlCa = ref('');
const platformSerial = ref('');
let generation = 0; let pending: AbortController | null = null; let timer: ReturnType<typeof setInterval> | undefined;
const statusNames: Record<string, string> = { pending: '待生效', partial: '部分生效', effective: '已生效', failed: '失败', rolled_back: '已回退' };
const stageNames: Record<string, string> = { accepted: '已受理', executing: '执行中', completed: '已完成', failed: '失败', unknown: '未知' };
const fetchNames: Record<string, string> = { idle: '未获取', success: '成功', failed: '失败' };
const crlStateNames: Record<string, string> = { none: '未配置', active: '有效', missing: '缺失', expired: '过期' };
const nodeNames: Record<string, string> = { applied: '已应用', pending: '待应用', isolated: '已隔离' };
const columns = computed<TableColumnsType<Principal>>(() => [
  { title: '主体名称', dataIndex: 'name', width: 200, ellipsis: true }, { title: '登录名', dataIndex: 'login', width: 180, ellipsis: true },
  { title: '授权条数', key: 'grants', width: 110 }, { title: '启用状态', key: 'enabled', width: 110 },
  { title: '恢复核对', key: 'recovery', width: 120 },
  { title: '凭据代次', dataIndex: 'credential_version', width: 110 }, { title: '证书', key: 'certificates', width: 90 },
  { title: '操作', key: 'actions', fixed: 'right', width: estimateVisibleActionColumnWidth([{ label: '版本' }, { label: '编辑', visible: canWrite.value }]) },
]);
const revisionColumns: TableColumnsType<Revision> = [
  { title: '版本', dataIndex: 'version', width: 80 }, { title: '发布状态', key: 'status', width: 140 },
  { title: '操作阶段', key: 'stage', width: 110 }, { title: '收紧权限', key: 'tightening', width: 110 },
  { title: '节点生效', key: 'nodes', width: 260, ellipsis: true },
  { title: '发布时间', key: 'time', width: 190 },
  { title: '操作', key: 'actions', fixed: 'right', width: estimateVisibleActionColumnWidth([{ label: '重试' }, { label: '回退' }]) },
];
const pagination = computed(() => ({ current: result.value.page, pageSize: result.value.per_page, total: result.value.total, showSizeChanger: true, pageSizeOptions: ['20', '50', '100'] }));
const revisionPagination = computed(() => ({ current: revisions.value.page, pageSize: revisions.value.per_page, total: revisions.value.total, showSizeChanger: false }));
const paused = computed(() => !!result.value.publish_paused || !!cas.value.publish_paused || !!(revisions.value.items[0] && ['pending', 'partial'].includes(revisions.value.items[0].status)));
const unverified = computed(() => result.value.items.some(item => Number(item.recovery_verified) === 0) || cas.value.items.some(item => Number(item.recovery_verified) === 0));
const time = (value: number) => new Date(value * 1000).toLocaleString();
/** 无自定义授权时沿用产品主题。 */
function grantText(item: Principal) { return item.grants.length === 0 ? '沿用产品主题' : `${item.grants.length} 条`; }
/** 活动证书条数；已吊销项不计入列表摘要。 */
function certificateText(item: Principal) { return (item.certificates || []).filter(row => row.status === 'active').length; }
/** 指纹中间省略，完整值仍在接口与详情中。 */
function fingerprintText(value: string) { return value.length <= 16 ? value : `${value.slice(0, 8)}…${value.slice(-8)}`; }
/** 活动证书的重叠截止；0 表示未进入换证窗口。 */
function overlapText(item: Certificate) {
  return item.overlap_until > 0 ? `重叠至 ${time(item.overlap_until)}` : '无重叠窗口';
}
/** 获取状态与执行状态分开；缺失或过期给出恢复条件。 */
function crlFetchText(item: Authority) {
  const crl = item.crl;
  if (!crl?.configured) return '未配置';
  return `${fetchNames[crl.fetch_status] || crl.fetch_status}${crl.fetch_error ? ` · ${crl.fetch_error}` : ''}`;
}
function crlExecuteText(item: Authority) {
  const crl = item.crl;
  if (!crl?.configured) return '—';
  return `${crlStateNames[crl.state] || crl.state} · ${stageNames[crl.execute_stage] || crl.execute_stage}`;
}
function crlRecoveryText(item: Authority) {
  return item.crl?.recovery || '—';
}
/** 节点生效与待撤权摘要，不含载荷。 */
function nodeText(item: Revision) {
  if (item.nodes.length === 0) return item.pending_invalidations > 0 ? `待撤权 ${item.pending_invalidations}` : '尚无上报节点';
  return item.nodes.map(node => `${node.node_id} · ${nodeNames[node.state] || node.state} · v${node.applied_version}`).join('；');
}
/** 同时刷新主体列表与受信 CA；后台轮询不打断正在编辑的抽屉。 */
async function load(page = result.value.page, perPage = result.value.per_page, background = false) {
  if (!canRead.value || busy.value && !background || document.visibilityState !== 'visible' || session.realm === 'customer' && !tenant.value) return;
  const current = ++generation; pending?.abort(); pending = new AbortController(); busy.value = true;
  try {
    const data = await request<PrincipalPage>(`${base.value}/access/principals`, { tenant: tenant.value, signal: pending.signal, params: { name: applied, page, per_page: perPage } });
    if (current === generation) { result.value = data; failure.value = ''; denied.value = false; }
    const authorities = await request<CaPage>(`${base.value}/access/cas`, { tenant: tenant.value, signal: pending.signal });
    if (current === generation) {
      cas.value = authorities;
      if (!crlCa.value && authorities.items[0]) crlCa.value = authorities.items[0].id;
    }
  } catch (error) {
    if (current === generation && !isCanceled(error)) {
      failure.value = errorText(error); denied.value = error instanceof ApiError && error.status === 403;
      if (denied.value) {
        result.value = { items: [], total: 0, page: 1, per_page: 20, current_version: 0, publish_paused: false };
        cas.value = { items: [], current_version: 0, publish_paused: false };
      }
    }
  } finally { if (current === generation) busy.value = false; }
}
/** 只在版本抽屉打开时拉取生效摘要。 */
async function loadRevisions(page = 1, background = false) {
  if (!canRead.value || !historyOpen.value || historyBusy.value && !background || document.visibilityState !== 'visible') return;
  const current = ++generation; historyBusy.value = true;
  try {
    const data = await request<RevisionPage>(`${base.value}/access/revisions`, { tenant: tenant.value, params: { page, per_page: 20 } });
    if (historyOpen.value) { revisions.value = data; historyFailure.value = ''; }
  } catch (error) { if (!isCanceled(error)) historyFailure.value = errorText(error); }
  finally { if (current === generation || historyOpen.value) historyBusy.value = false; }
}
function search(reset = false) { if (busy.value) return; if (reset) filters.name = ''; applied = filters.name.trim(); void load(1); }
/** 打开编辑抽屉；已有证书可勾选吊销，新增只提交公钥 PEM。 */
function edit(item: Principal | null) {
  if (!canWrite.value) return;
  editing.value = item; saveFailure.value = ''; preview.value = null; previewFailure.value = '';
  Object.assign(form, { name: item?.name || '', login: item?.login || '', password: '', enabled: item?.enabled ?? true, rotate: false,
    certificatePem: '', overlapSeconds: 86400, revokeFingerprints: [],
    grants: item?.grants.length ? item.grants.map(grant => ({ ...grant })) : [{ topic: '', publish: true, subscribe: true, max_qos: 2 }] });
  open.value = true;
}
function addGrant() { if (form.grants.length < 32) form.grants.push({ topic: '', publish: true, subscribe: true, max_qos: 2 }); }
function removeGrant(index: number) { form.grants.splice(index, 1); if (form.grants.length === 0) addGrant(); }
function toggleRevoke(fingerprint: string) {
  const index = form.revokeFingerprints.indexOf(fingerprint);
  if (index >= 0) form.revokeFingerprints.splice(index, 1); else form.revokeFingerprints.push(fingerprint);
  clearPreview();
}
/** 改公钥或重叠秒数后丢弃旧预览，避免把上次结果当成当前计划。 */
function clearPreview() { preview.value = null; previewFailure.value = ''; }
/** 预览换证：不写版本。勾选吊销时立即失效，预览只看新增公钥相对当前活动证书。 */
async function previewRotation() {
  if (previewBusy.value || !canWrite.value || !editing.value || !form.certificatePem.trim()) return;
  previewBusy.value = true; previewFailure.value = '';
  try {
    preview.value = await request<RotationPreview>(`${base.value}/access/principals/${editing.value.id}/certificate-preview`, {
      tenant: tenant.value, method: 'POST',
      data: { pem: form.certificatePem.trim(), overlap_seconds: Math.min(86400, Math.max(0, Number(form.overlapSeconds ?? 86400))) },
    });
  } catch (error) {
    preview.value = null;
    if (!isCanceled(error)) previewFailure.value = errorText(error);
  } finally { previewBusy.value = false; }
}
/** 写入主体、授权与证书绑定；409 时重新读取当前版本。 */
async function save() {
  if (saving.value || !canWrite.value) return;
  try { await formRef.value?.validate(); } catch { return; }
  const grants = form.grants.filter(grant => grant.topic.trim() !== '');
  const certificates = [
    ...form.revokeFingerprints.map(fingerprint => ({ fingerprint, revoke: 1 })),
    ...(form.certificatePem.trim() ? [{ pem: form.certificatePem.trim(), overlap_seconds: Math.min(86400, Math.max(0, Number(form.overlapSeconds ?? 86400))) }] : []),
  ];
  saving.value = true; saveFailure.value = '';
  try {
    await request(`${base.value}/access/principals${editing.value ? `/${editing.value.id}` : ''}`, {
      tenant: tenant.value, method: 'POST',
      data: { name: form.name, login: form.login, password: form.password, enabled: form.enabled ? 1 : 0, rotate: form.rotate ? 1 : 0, revoke: 0,
        expected_version: result.value.current_version,
        grants: grants.map(grant => ({ topic: grant.topic, publish: grant.publish ? 1 : 0, subscribe: grant.subscribe ? 1 : 0, max_qos: grant.max_qos })),
        ...(certificates.length ? { certificates } : {}) },
    });
    open.value = false; message.success('授权版本已保存，节点生效后状态会更新'); await load(result.value.page); if (historyOpen.value) await loadRevisions(revisions.value.page);
  } catch (error) {
    if (!isCanceled(error)) saveFailure.value = errorText(error);
    if (error instanceof ApiError && error.status === 409) await load(result.value.page);
  } finally { saving.value = false; }
}
/** 重试当前版本或回退非收紧发布；收紧失败不能回退。 */
async function act(item: Revision, action: 'retry' | 'rollback') {
  if (!canWrite.value || acting.value || item.status === 'effective' || item.status === 'rolled_back') return;
  if (action === 'rollback' && item.tightening) { historyFailure.value = '收紧权限不能回退恢复旧授权'; return; }
  acting.value = item.id + action;
  try {
    await request(`${base.value}/access/revisions/${item.id}/${action}`, { tenant: tenant.value, method: 'POST', data: {} });
    message.success(action === 'retry' ? '已重试同一版本' : '已回退并发布新版本'); await load(result.value.page); await loadRevisions(revisions.value.page);
  } catch (error) { if (!isCanceled(error)) historyFailure.value = errorText(error); }
  finally { acting.value = ''; }
}
/** 登记一张 CA 公钥，进入同一授权版本序列；不要粘贴私钥。 */
async function saveCa() {
  if (caSaving.value || !canWrite.value || !caPem.value.trim()) return;
  caSaving.value = true; caFailure.value = '';
  try {
    await request(`${base.value}/access/cas`, { tenant: tenant.value, method: 'POST', data: { pem: caPem.value.trim(), expected_version: result.value.current_version } });
    caPem.value = ''; message.success('受信 CA 已写入授权版本'); await load(result.value.page); if (historyOpen.value) await loadRevisions(revisions.value.page);
  } catch (error) {
    if (!isCanceled(error)) caFailure.value = errorText(error);
    if (error instanceof ApiError && error.status === 409) await load(result.value.page);
  } finally { caSaving.value = false; }
}
/** 仍被活动叶子使用的 CA 由服务端拒绝；前端只展示错误。 */
async function revokeCa(item: Authority) {
  if (!canWrite.value || caSaving.value) return;
  caSaving.value = true; caFailure.value = '';
  try {
    await request(`${base.value}/access/cas/${item.id}`, { tenant: tenant.value, method: 'POST', data: { revoke: 1, expected_version: result.value.current_version } });
    message.success('已移除未使用的受信 CA'); await load(result.value.page); if (historyOpen.value) await loadRevisions(revisions.value.page);
  } catch (error) {
    if (!isCanceled(error)) caFailure.value = errorText(error);
    if (error instanceof ApiError && error.status === 409) await load(result.value.page);
  } finally { caSaving.value = false; }
}
/** 导入签名 CRL 或登记受控 HTTPS 源；成功校验并持久接纳后才开始 5 秒执行。 */
async function saveCrl() {
  if (caSaving.value || !canWrite.value || !crlCa.value || (!crlPem.value.trim() && !crlUrl.value.trim())) return;
  caSaving.value = true; caFailure.value = '';
  try {
    await request(`${base.value}/access/cas/${crlCa.value}/crl`, { tenant: tenant.value, method: 'POST', data: {
      pem: crlPem.value.trim(), url: crlUrl.value.trim(), fetch_interval: Math.min(3600, Math.max(1, Number(crlInterval.value || 300))),
      expected_version: result.value.current_version,
    } });
    crlPem.value = ''; message.success('吊销列表已写入授权版本'); await load(result.value.page); if (historyOpen.value) await loadRevisions(revisions.value.page);
  } catch (error) {
    if (!isCanceled(error)) caFailure.value = errorText(error);
    if (error instanceof ApiError && error.status === 409) await load(result.value.page);
  } finally { caSaving.value = false; }
}
/** 平台直接吊销序列号，不必等待 CA CRL。 */
async function savePlatformSerial() {
  if (caSaving.value || !canWrite.value || !platformSerial.value.trim()) return;
  caSaving.value = true; caFailure.value = '';
  try {
    await request(`${base.value}/access/revocations`, { tenant: tenant.value, method: 'POST', data: {
      serial: platformSerial.value.trim(), expected_version: result.value.current_version,
    } });
    platformSerial.value = ''; message.success('平台吊销已写入授权版本'); await load(result.value.page); if (historyOpen.value) await loadRevisions(revisions.value.page);
  } catch (error) {
    if (!isCanceled(error)) caFailure.value = errorText(error);
    if (error instanceof ApiError && error.status === 409) await load(result.value.page);
  } finally { caSaving.value = false; }
}
function showHistory() { historyOpen.value = true; historyFailure.value = ''; void loadRevisions(1); }
/** 页面隐藏立即停轮询；可见后再按 5 秒刷新生效状态。 */
function visibility() {
  clearInterval(timer);
  if (document.visibilityState === 'visible') {
    void load(result.value.page, result.value.per_page, true);
    if (historyOpen.value) void loadRevisions(revisions.value.page, true);
    timer = setInterval(() => { if (!denied.value) { void load(result.value.page, result.value.per_page, true); if (historyOpen.value) void loadRevisions(revisions.value.page, true); } }, 5000);
  } else { generation++; pending?.abort(); pending = null; busy.value = false; }
}
watch(() => [session.generation, session.realm, session.tenant?.id, session.identity?.key, canRead.value, route.path], () => {
  generation++; pending?.abort(); result.value = { items: [], total: 0, page: 1, per_page: 20, current_version: 0, publish_paused: false };
  cas.value = { items: [], current_version: 0, publish_paused: false };
  open.value = false; historyOpen.value = false; failure.value = ''; denied.value = false; applied = ''; filters.name = ''; caPem.value = ''; caFailure.value = '';
  crlPem.value = ''; crlUrl.value = ''; crlInterval.value = 300; crlCa.value = ''; platformSerial.value = '';
  preview.value = null; previewFailure.value = '';
  if (!['/broker-access', '/admin/broker-access', '/broker/access'].includes(route.path)) return;
  if (session.realm === 'customer' && !tenant.value) void router.replace('/tenants'); else void load(1);
}, { immediate: true });
onMounted(() => { document.addEventListener('visibilitychange', visibility); visibility(); });
onBeforeUnmount(() => { generation++; pending?.abort(); clearInterval(timer); document.removeEventListener('visibilitychange', visibility); });
</script>

<template>
  <section class="iot-page">
    <header class="page-heading">
      <div><h1>Broker 授权</h1><p class="muted">{{ platform ? '平台接入主体、受信 CA 与 Topic 授权' : session.realm === 'broker' ? '独立接入主体、受信 CA、证书绑定与 Topic 授权' : '当前租户设备 Topic 授权、受信 CA 与证书绑定' }}。当前版本 v{{ result.current_version }}{{ paused ? ' · 部分失败已暂停后续发布' : '' }}</p></div>
      <div class="crud-search-grid__actions"><Button @click="showHistory">版本生效</Button><Button v-if="canWrite" type="primary" @click="edit(null)">新增主体</Button></div>
    </header>
    <Alert v-if="failure" class="page-alert" type="error" show-icon role="alert" :message="denied ? failure : `授权刷新失败：${failure}`" />
    <Alert v-if="!canRead" class="page-alert" type="warning" show-icon message="当前账号无权查看 Broker 授权" />
    <Alert v-else-if="canRead && !canWrite" class="page-alert" type="info" show-icon message="当前角色可查看授权版本，保存、重试和回退需要写入权限。" />
    <Alert v-if="unverified" class="page-alert" type="warning" show-icon message="恢复后尚未核对的接入主体或受信 CA 保持隔离，不能接入或签发握手，也不能把旧快照标成恢复完成。" />
    <Card class="page-card">
      <template #title>受信 CA</template>
      <p class="muted">在线登记用于证书到主体的绑定与 MQTT 身份。节点握手仍读取启动时的客户端 CA 文件，更新该文件需滚动重启（不在本页完成）。签名 CRL 须校验后持久接纳才开始 5 秒执行；获取失败时若旧列表仍有效则继续使用。不要粘贴私钥。</p>
      <Alert v-if="caFailure" :message="caFailure" type="error" show-icon class="page-alert" role="alert" />
      <div v-if="canWrite" class="ca-form">
        <Input.TextArea v-model:value="caPem" aria-label="受信 CA 公钥" placeholder="粘贴单份 CA 公钥 PEM" :rows="4" :maxlength="16384" :disabled="caSaving || paused" />
        <div class="crud-search-grid__actions"><Button type="primary" :loading="caSaving" :disabled="!caPem.trim() || paused" @click="saveCa">登记 CA</Button></div>
      </div>
      <div v-if="canWrite && cas.items.length" class="ca-form">
        <Select v-model:value="crlCa" aria-label="CRL 对应 CA" :options="cas.items.map(item => ({ value: item.id, label: item.subject }))" :disabled="caSaving || paused" />
        <Input.TextArea v-model:value="crlPem" aria-label="签名 CRL" placeholder="粘贴 PEM 编码的签名 CRL" :rows="4" :maxlength="65536" :disabled="caSaving || paused" />
        <Input v-model:value="crlUrl" aria-label="受控 HTTPS 源" placeholder="https:// 受控 CRL 地址" :maxlength="2048" :disabled="caSaving || paused" />
        <InputNumber v-model:value="crlInterval" aria-label="刷新间隔秒数" :min="1" :max="3600" :precision="0" style="width:100%" :disabled="caSaving || paused" />
        <p class="muted">默认每 300 秒刷新。来源由管理员配置，客户端不能指定下载地址。不查询 OCSP。</p>
        <div class="crud-search-grid__actions"><Button type="primary" :loading="caSaving" :disabled="(!crlPem.trim() && !crlUrl.trim()) || paused" @click="saveCrl">接纳 CRL</Button></div>
        <Input v-model:value="platformSerial" aria-label="平台吊销序列号" placeholder="证书序列号十六进制" :maxlength="64" :disabled="caSaving || paused" />
        <div class="crud-search-grid__actions"><Button :loading="caSaving" :disabled="!platformSerial.trim() || paused" @click="savePlatformSerial">平台吊销</Button></div>
      </div>
      <div class="table-toolbar"><span class="muted">{{ cas.items.length }} 份受信 CA</span></div>
      <Table :columns="[{ title: '主体', dataIndex: 'subject', ellipsis: true }, { title: '指纹', key: 'fingerprint', width: 180 }, { title: '核对', key: 'verified', width: 90 }, { title: '获取状态', key: 'fetch', width: 140 }, { title: '执行状态', key: 'execute', width: 160 }, { title: '恢复条件', key: 'recovery', ellipsis: true }, { title: '操作', key: 'actions', width: 90 }]" :data-source="cas.items" row-key="id" :pagination="false" size="small" :scroll="{ x: 1050 }">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'fingerprint'">{{ fingerprintText((record as Authority).fingerprint) }}</template>
          <template v-else-if="column.key === 'verified'"><Tag :color="Number((record as Authority).recovery_verified) === 0 ? 'warning' : undefined">{{ Number((record as Authority).recovery_verified) === 0 ? '待核对' : '已核对' }}</Tag></template>
          <template v-else-if="column.key === 'fetch'">{{ crlFetchText(record as Authority) }}</template>
          <template v-else-if="column.key === 'execute'">{{ crlExecuteText(record as Authority) }}</template>
          <template v-else-if="column.key === 'recovery'">{{ crlRecoveryText(record as Authority) }}</template>
          <CrudTableActions v-else-if="column.key === 'actions'" :actions="[{ label: '移除', visible: canWrite, disabled: caSaving || paused, onClick: () => revokeCa(record as Authority) }]" />
        </template>
      </Table>
    </Card>
    <Card>
      <form class="crud-search-grid" @submit.prevent="search()">
        <CrudSearchField label="主体名称"><Input v-model:value="filters.name" aria-label="主体名称筛选" :disabled="busy || !canRead" :maxlength="100" allow-clear /></CrudSearchField>
        <div class="crud-search-grid__actions"><Button type="primary" html-type="submit" :loading="busy" :disabled="!canRead">查询</Button><Button :disabled="busy || !canRead" @click="search(true)">重置</Button></div>
      </form>
      <div class="table-toolbar"><span class="muted">{{ result.total }} 个接入主体{{ busy ? ' · 刷新中' : '' }}</span><Button :loading="busy" :disabled="!canRead" @click="load(result.page)">刷新</Button></div>
      <Table :columns="columns" :data-source="result.items" row-key="id" :loading="busy" :scroll="buildTableScrollX(columns)" :pagination="pagination" @change="page => load(page.current, page.pageSize)">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'grants'">{{ grantText(record as Principal) }}</template>
          <template v-else-if="column.key === 'certificates'">{{ certificateText(record as Principal) }}</template>
          <template v-else-if="column.key === 'enabled'"><Tag :color="record.enabled ? 'success' : 'default'">{{ record.enabled ? '启用' : '停用' }}</Tag></template>
          <template v-else-if="column.key === 'recovery'"><Tag :color="Number(record.recovery_verified) === 0 ? 'warning' : undefined">{{ Number(record.recovery_verified) === 0 ? '待核对' : '已核对' }}</Tag></template>
          <CrudTableActions v-else-if="column.key === 'actions'" :actions="[{ label: '版本', onClick: showHistory }, { label: '编辑', visible: canWrite, onClick: () => edit(record as Principal) }]" />
        </template>
      </Table>
    </Card>
    <AppDrawer v-model:open="open" :title="editing ? '发布授权新版本' : '新增接入主体'" width-size="md" :confirm-loading="saving" :closable="!saving" :ok-disabled="saving || !canWrite" @ok="save">
      <Alert v-if="saveFailure" :message="saveFailure" type="error" show-icon class="page-alert" role="alert" />
      <p class="muted">保存成功只表示本版本已写入。收紧权限后新认证立即按新事实拒绝，健康节点约 5 秒内停止旧连接收发并断开；部分失败会暂停后续发布。正常换证可设 0–24 小时重叠，吊销、停用和收权无宽限。客户端证书须由已登记 CA 签发，只提交公钥 PEM。</p>
      <Form ref="formRef" :model="form" layout="vertical" class="form-grid" :disabled="saving">
        <FormItem label="主体名称" name="name" :rules="[{ required: true, whitespace: true, message: '请输入主体名称' }]" class="span-full"><Input v-model:value="form.name" :maxlength="100" /></FormItem>
        <FormItem v-if="!editing || form.login" label="登录名" name="login" :rules="editing ? [] : [{ required: true, whitespace: true, message: '请输入登录名' }]" class="span-full"><Input v-model:value="form.login" :disabled="Boolean(editing)" :maxlength="100" /></FormItem>
        <FormItem v-if="!editing || form.rotate" label="接入密码" name="password" :rules="[{ required: !editing || form.rotate, min: 12, message: '密码至少 12 字节' }]" class="span-full"><InputPassword v-model:value="form.password" :maxlength="72" /></FormItem>
        <FormItem v-if="editing" label="轮换凭据"><Checkbox v-model:checked="form.rotate" aria-label="轮换凭据">递增凭据代次并断开旧连接</Checkbox></FormItem>
        <FormItem label="启用主体"><Switch v-model:checked="form.enabled" aria-label="启用主体" /></FormItem>
        <div v-if="editing" class="span-full grant-list">
          <div class="table-toolbar"><span>已绑定证书</span></div>
          <div v-for="item in (editing.certificates || []).filter(row => row.status === 'active')" :key="item.id" class="grant-row cert-row">
            <span>{{ item.subject }} · {{ fingerprintText(item.fingerprint) }} · {{ overlapText(item) }}</span>
            <Checkbox :checked="form.revokeFingerprints.includes(item.fingerprint)" aria-label="吊销证书" @change="() => toggleRevoke(item.fingerprint)">吊销</Checkbox>
          </div>
          <p v-if="!(editing.certificates || []).some(row => row.status === 'active')" class="muted">尚未绑定客户端证书</p>
        </div>
        <FormItem v-if="editing" label="登记客户端证书公钥" class="span-full"><Input.TextArea v-model:value="form.certificatePem" aria-label="客户端证书公钥" placeholder="粘贴单份客户端证书 PEM，不要包含私钥" :rows="4" :maxlength="16384" @update:value="clearPreview" /></FormItem>
        <FormItem v-if="editing" label="换证重叠秒数" class="span-full">
          <InputNumber v-model:value="form.overlapSeconds" aria-label="换证重叠秒数" :min="0" :max="86400" :precision="0" :disabled="form.revokeFingerprints.length > 0" style="width:100%" @change="clearPreview" />
          <p class="muted">默认 86400 秒。勾选吊销则立即失效，不使用重叠窗口。</p>
        </FormItem>
        <div v-if="editing" class="span-full grant-list">
          <div class="crud-search-grid__actions">
            <Button :loading="previewBusy" :disabled="!form.certificatePem.trim()" @click="previewRotation">预览换证</Button>
          </div>
          <Alert v-if="previewFailure" :message="previewFailure" type="error" show-icon class="page-alert" role="alert" />
          <div v-if="preview" class="preview-box">
            <p>新证书 {{ preview.incoming.subject }} · {{ fingerprintText(preview.incoming.fingerprint) }}</p>
            <p>{{ preview.tightening ? '将立即收紧旧证书' : `重叠 ${preview.overlap_seconds} 秒` }}{{ preview.overlap_until ? `，截止 ${time(preview.overlap_until)}` : '' }}</p>
            <p v-if="preview.retiring.length === 0" class="muted">没有需要退出的旧证书</p>
            <p v-for="item in preview.retiring" :key="item.id">{{ item.action === 'revoke' ? '立即吊销' : '重叠' }} · {{ fingerprintText(item.fingerprint) }}</p>
          </div>
        </div>
        <div class="span-full grant-list">
          <div class="table-toolbar"><span>Topic 授权</span><Button size="small" :disabled="form.grants.length >= 32" @click="addGrant">添加规则</Button></div>
          <div v-for="(grant, index) in form.grants" :key="index" class="grant-row">
            <Input v-model:value="grant.topic" aria-label="授权主题" placeholder="以 / 结尾表示前缀" :maxlength="200" />
            <Checkbox v-model:checked="grant.publish">发布</Checkbox>
            <Checkbox v-model:checked="grant.subscribe">订阅</Checkbox>
            <Select v-model:value="grant.max_qos" aria-label="最大 QoS" :options="[0, 1, 2].map(value => ({ value, label: `QoS ${value}` }))" />
            <Button size="small" @click="removeGrant(index)">删除</Button>
          </div>
        </div>
      </Form>
    </AppDrawer>
    <AppDrawer :open="historyOpen" title="授权版本生效" width-size="lg" :show-footer="false" @update:open="value => { if (!value) historyOpen = false; }">
      <Alert v-if="historyFailure" :message="historyFailure" type="error" show-icon class="page-alert" role="alert" />
      <p class="muted">节点必须实际上报已加载版本。操作身份为版本标识，阶段区分受理、执行、完成、失败和未知。收紧失败不能用回退恢复旧授权；未执行限制的节点须停止收发或硬隔离后才算完成。</p>
      <Table :columns="revisionColumns" :data-source="revisions.items" row-key="id" :loading="historyBusy" :scroll="buildTableScrollX(revisionColumns)" :pagination="revisionPagination" @change="page => loadRevisions(page.current)">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'status'"><Tag :color="record.status === 'effective' ? 'success' : record.status === 'failed' ? 'error' : 'warning'">{{ statusNames[record.status] || record.status }}</Tag></template>
          <template v-else-if="column.key === 'stage'"><span :title="record.operation_id">{{ stageNames[record.stage] || record.stage }}</span></template>
          <template v-else-if="column.key === 'tightening'">{{ record.tightening ? '是' : '否' }}</template>
          <template v-else-if="column.key === 'nodes'">{{ nodeText(record as Revision) }}</template>
          <template v-else-if="column.key === 'time'">{{ time(record.created_at) }}</template>
          <CrudTableActions v-else-if="column.key === 'actions'" :actions="[
            { label: '重试', visible: canWrite && ['pending', 'partial', 'failed'].includes(record.status), disabled: acting === record.id + 'retry', onClick: () => act(record as Revision, 'retry') },
            { label: '回退', visible: canWrite && !record.tightening && ['pending', 'partial', 'failed'].includes(record.status), disabled: acting === record.id + 'rollback', onClick: () => act(record as Revision, 'rollback') },
          ]" />
        </template>
      </Table>
    </AppDrawer>
  </section>
</template>

<style scoped>
.grant-list { display: flex; flex-direction: column; gap: 8px; }
.grant-row { display: grid; grid-template-columns: minmax(0, 1fr) auto auto 110px auto; gap: 8px; align-items: center; }
.cert-row { grid-template-columns: minmax(0, 1fr) auto; }
.preview-box { display: flex; flex-direction: column; gap: 4px; padding: 8px 0; }
.ca-form { display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px; }
.page-card { margin-bottom: 16px; }
@media (max-width: 760px) { .grant-row { grid-template-columns: 1fr; } }
</style>
