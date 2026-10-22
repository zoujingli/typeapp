<script setup lang="ts">
import type { TableColumnsType } from 'ant-design-vue';
import type { DeviceCredential, DeviceReassignment, Page } from '../api';
import type { CrudTableAction } from '../components/crud-table-actions.vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Alert, Button, Card, Descriptions, DescriptionsItem, Empty, Table, Tag, message } from 'ant-design-vue';
import AppDrawer from '../components/app-drawer/app-drawer.vue';
import CrudTableActions from '../components/crud-table-actions.vue';
import { buildTableScrollX } from '../utils/table';
import { applyAdminContext, clearAdminAccess, errorText, isCanceled, isDemo, reassignmentPendingNames, reassignmentStatusNames, request, session } from '../api';

const router = useRouter();
const platform = computed(() => session.realm === 'admin');
const allowed = (action: string) => session.permissions.includes(`${session.realm}.reassignments.${action}`);
const endpoint = () => platform.value ? '/admin/reassignments' : `/customer/tenants/${session.tenant?.id}/reassignments`;
const scope = () => platform.value ? undefined : session.tenant?.id;
const result = ref<Page<DeviceReassignment>>({ items: [], total: 0, page: 1, per_page: 20 });
const busy = ref(false); const failure = ref(''); const saving = ref(false);
const selected = ref<DeviceReassignment | null>(null); const detailBusy = ref(false); const detailFailure = ref('');
const credential = ref<DeviceCredential | null>(null);
// 仅保留当前抽屉的一次确认请求；网络失败重试沿用同一 ID 和版本，不再次签发秘密。
let acceptance: { record: string; id: string; version: number } | null = null;
let epoch = 0; let listSerial = 0; let detailSerial = 0;
let listPending: AbortController | null = null; let detailPending: AbortController | null = null; let savePending: AbortController | null = null;
const kindNames = { reassign: '转配', end: '结束使用', expire: '使用到期' };
const columns: TableColumnsType<DeviceReassignment> = [
  { title: '设备名称', dataIndex: 'device_name', width: 220, ellipsis: true }, { title: '处理类型', key: 'kind', width: 110 },
  { title: '来源客户', dataIndex: 'source_tenant_id', width: 290, ellipsis: true }, { title: '目标客户', key: 'target', width: 290, ellipsis: true },
  { title: '实际阶段', key: 'status', width: 220 }, { title: '发起时间', key: 'created', width: 200 },
  { title: '操作', key: 'actions', width: 120, fixed: 'right' },
];
const pagination = computed(() => ({ current: result.value.page, pageSize: result.value.per_page, total: result.value.total, showSizeChanger: true, pageSizeOptions: ['20', '50', '100'] }));
const time = (value: number) => new Date(value * 1000).toLocaleString();
function rejected(error: unknown) { if (clearAdminAccess(error)) reset(); }
async function load(page = 1, perPage = result.value.per_page) {
  if (!allowed('read') || (!platform.value && !session.tenant)) return;
  const current = ++listSerial; listPending?.abort(); listPending = new AbortController(); busy.value = true; failure.value = '';
  try {
    const data = await request<Page<DeviceReassignment> & { context: { permissions: string[]; menus: typeof session.menus } }>(endpoint(), { tenant: scope(), signal: listPending.signal, params: { page, per_page: perPage } });
    if (current !== listSerial) return;
    result.value = data; applyAdminContext(data.context);
  } catch (error) { if (current === listSerial && !isCanceled(error)) { failure.value = errorText(error); rejected(error); } }
  finally { if (current === listSerial) busy.value = false; }
}
async function read(row: DeviceReassignment) {
  if (saving.value) return;
  if (selected.value?.id !== row.id) acceptance = null;
  selected.value = row;
  const current = ++detailSerial; detailPending?.abort(); detailPending = new AbortController(); detailBusy.value = true; detailFailure.value = '';
  try {
    const data = await request<{ operation: DeviceReassignment; context: { permissions: string[]; menus: typeof session.menus } }>(`${endpoint()}/${row.id}`, { tenant: scope(), signal: detailPending.signal });
    if (current !== detailSerial) return;
    selected.value = data.operation; applyAdminContext(data.context);
    if (acceptance && data.operation.status === 'awaiting_acceptance' && acceptance.version !== data.operation.version) acceptance = null;
  } catch (error) { if (current === detailSerial && !isCanceled(error)) { detailFailure.value = errorText(error); rejected(error); } }
  finally { if (current === detailSerial) detailBusy.value = false; }
}
async function change(action: 'advance' | 'accept') {
  const row = selected.value;
  if (!row || saving.value || detailBusy.value || isDemo() || !allowed(action)) return;
  const current = epoch; saving.value = true; detailFailure.value = ''; savePending = new AbortController();
  if (action === 'accept' && acceptance?.record !== row.id) acceptance = { record: row.id, id: crypto.randomUUID().replaceAll('-', ''), version: row.version };
  try {
    const data = await request<DeviceReassignment | { operation: DeviceReassignment; credential: DeviceCredential | null; replayed: boolean }>(`${endpoint()}/${row.id}`, {
      method: 'POST', tenant: scope(), signal: savePending.signal,
      data: action === 'accept' ? { action, id: acceptance!.id, version: acceptance!.version } : { action, version: row.version },
    });
    if (current !== epoch) return;
    if ('operation' in data) {
      selected.value = data.operation; credential.value = data.credential; acceptance = null;
      message.success(data.replayed ? '此前确认已完成；如未保存凭据，请在设备管理中轮换。' : '已接收设备，请保存新凭据，并重新授予应用通信权限。');
    } else { selected.value = data; message.info(reassignmentStatusNames[data.status]); }
    await load(result.value.page);
  } catch (error) { if (current === epoch && !isCanceled(error)) { detailFailure.value = errorText(error); rejected(error); } }
  finally { if (current === epoch) saving.value = false; }
}
function actions(row: DeviceReassignment): CrudTableAction[] {
  return [
    { label: '核验并推进', visible: platform.value && allowed('advance') && row.status === 'isolating', disabled: saving.value || detailBusy.value,
      confirmTitle: '核验旧授权及持久内容？', confirmContent: '仅在隔离回执和保留消息检查均完成后进入下一阶段。未完成时会继续显示待处理原因。', onClick: () => change('advance') },
    { label: '确认接收', visible: !platform.value && allowed('accept') && row.target_tenant_id === session.tenant?.id && row.status === 'awaiting_acceptance', disabled: saving.value || detailBusy.value,
      confirmTitle: '确认接收此设备？', confirmContent: '将建立新的使用关系并一次性签发凭据。旧应用授权不会复制，需交付新配置并重新授权。', onClick: () => change('accept') },
  ];
}
function closeDetail() { detailSerial++; detailPending?.abort(); selected.value = null; detailBusy.value = false; acceptance = null; }
function reset() {
  epoch++; listSerial++; listPending?.abort(); savePending?.abort(); closeDetail();
  saving.value = false; busy.value = false; credential.value = null; result.value.items = []; result.value.total = 0; failure.value = '';
}
watch([() => session.realm, () => session.token, () => session.tenant?.id], () => {
  reset(); if (!platform.value && !session.tenant) void router.replace('/tenants'); else void load();
}, { immediate: true });
onBeforeUnmount(reset);
</script>

<template>
  <section class="iot-page">
    <header class="page-heading"><div><h1>设备转配</h1><p class="muted">{{ platform ? '结束旧使用关系，隔离完成后交付目标客户' : `${session.tenant?.name || ''} · 查看本客户的转出、接收与到期记录` }}</p></div></header>
    <Card :bordered="false">
      <Alert type="info" show-icon class="page-alert" message="平台从设备详情发起转配。旧授权与持久内容隔离完成后，由目标客户确认接收；已送达对端的消息无法撤回。" />
      <Alert v-if="failure" :message="failure" type="error" show-icon class="page-alert" role="alert" />
      <div class="table-toolbar"><span>{{ result.total }} 条处理记录</span><Button :loading="busy" :disabled="!allowed('read')" @click="load(result.page)">刷新</Button></div>
      <Table :columns="columns" :data-source="result.items" row-key="id" :loading="busy" :pagination="pagination" :scroll="buildTableScrollX(columns)" @change="page => load(page.current, page.pageSize)">
        <template #emptyText><Empty :description="failure ? '数据暂不可用' : '暂无转配或结束使用记录'" /></template>
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'kind'">{{ kindNames[(record as DeviceReassignment).kind] }}</template>
          <template v-else-if="column.key === 'target'">{{ record.target_tenant_id || '无，仅结束使用' }}</template>
          <Tag v-else-if="column.key === 'status'" :color="['ended', 'completed'].includes(record.status) ? 'success' : 'processing'">{{ reassignmentStatusNames[(record as DeviceReassignment).status] }}</Tag>
          <template v-else-if="column.key === 'created'">{{ time(record.created_at) }}</template>
          <CrudTableActions v-else-if="column.key === 'actions'" :actions="[{ label: '查看处理', readOnly: true, disabled: saving, onClick: () => read(record as DeviceReassignment) }]" />
        </template>
      </Table>
    </Card>
    <AppDrawer :open="Boolean(selected)" title="设备转配详情" width-size="md" :show-footer="false" :closable="!saving" @update:open="value => { if (!value) closeDetail(); }">
      <template v-if="selected">
        <Alert v-if="detailFailure" :message="detailFailure" type="error" show-icon class="page-alert" role="alert" />
        <div class="table-toolbar"><span>{{ reassignmentStatusNames[selected.status] }}</span><Button :loading="detailBusy" :disabled="saving" @click="read(selected)">读取当前状态</Button></div>
        <Alert v-for="reason in selected.pending_reasons" :key="reason" :message="reassignmentPendingNames[reason] || reason" type="info" show-icon class="page-alert" />
        <Descriptions :column="1" bordered>
          <DescriptionsItem label="设备名称">{{ selected.device_name }}</DescriptionsItem><DescriptionsItem label="设备标识">{{ selected.device_id }}</DescriptionsItem>
          <DescriptionsItem label="处理标识">{{ selected.id }}</DescriptionsItem><DescriptionsItem label="来源客户">{{ selected.source_tenant_id }}</DescriptionsItem>
          <DescriptionsItem label="目标客户">{{ selected.target_tenant_id || '无，仅结束使用' }}</DescriptionsItem><DescriptionsItem label="旧使用关系">{{ selected.ownership_id }}</DescriptionsItem>
          <DescriptionsItem label="新使用关系">{{ selected.new_ownership_id || '尚未建立' }}</DescriptionsItem><DescriptionsItem label="持久隔离证明">{{ selected.requires_store ? '需要真实存储回执与保留消息核验' : '已确认没有接入历史' }}</DescriptionsItem>
          <DescriptionsItem v-for="(proof, direction) in selected.retained" :key="direction" :label="direction === 'publish' ? '旧上行保留消息' : '旧下行保留消息'">{{ proof.state === 'absent' ? '核验不存在' : '清理待完成' }}{{ proof.checked_at ? ` · ${time(proof.checked_at)}` : '' }}</DescriptionsItem>
          <DescriptionsItem label="记录版本">{{ selected.version }}</DescriptionsItem><DescriptionsItem label="更新时间">{{ time(selected.updated_at) }}</DescriptionsItem>
        </Descriptions>
        <div class="table-toolbar"><CrudTableActions :actions="actions(selected)" /></div>
      </template>
    </AppDrawer>
    <AppDrawer :open="Boolean(credential)" title="保存新设备凭据" width-size="sm" :show-footer="false" @update:open="value => { if (!value) credential = null; }">
      <Alert type="warning" show-icon class="page-alert" message="仅本次显示。关闭或切换工作区后清除；遗失请在设备管理中轮换凭据。旧应用授权不会继承。" />
      <Descriptions v-if="credential" :column="1" bordered>
        <DescriptionsItem label="Client ID">{{ credential.client_id }}</DescriptionsItem><DescriptionsItem label="用户名">{{ credential.username }}</DescriptionsItem>
        <DescriptionsItem label="密码"><code>{{ credential.password }}</code></DescriptionsItem><DescriptionsItem label="协议">MQTT {{ credential.protocol_version }} / TLS</DescriptionsItem>
        <DescriptionsItem label="Keep Alive">{{ credential.keep_alive }} 秒</DescriptionsItem><DescriptionsItem label="Session Expiry">{{ credential.session_expiry }} 秒</DescriptionsItem>
        <DescriptionsItem label="上行 Topic">{{ credential.topics.publish }}</DescriptionsItem><DescriptionsItem label="下行 Topic">{{ credential.topics.subscribe }}</DescriptionsItem>
      </Descriptions>
    </AppDrawer>
  </section>
</template>
