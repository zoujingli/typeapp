<script setup lang="ts">
import { displayDateTime } from '../../display';
import type { DeviceCredential, DeviceReassignment } from '../../api/center';
import type { Page as PageResult } from '../../api/contracts';
import { computed, onBeforeUnmount, ref } from 'vue';
import { Alert, Button, Descriptions, DescriptionsItem, Modal, Space, Tag, message } from 'ant-design-vue';
import { Page, useVbenDrawer } from '@vben/common-ui';
import { useVbenVxeGrid } from '../../adapter/vxe-table';
import DeviceCredentialView from '../../components/device-credential.vue';
import { applyAccessContext, errorText, isCanceled, isDemo, request, session } from '../../api/identity';
import { reassignmentPendingNames, reassignmentStatusNames } from '../../api/center';
const platform = computed(() => session.realm === 'platform');
const allowed = (action: string) => session.permissions.includes(`${platform.value ? 'platform' : 'center'}.reassignments.${action}`);
const endpoint = computed(() => platform.value ? '/platform/reassignments' : '/center/reassignments');
const failure = ref(''); const detailFailure = ref(''); const saving = ref(false); const detailBusy = ref(false);
const selected = ref<DeviceReassignment>(); const secret = ref<InstanceType<typeof DeviceCredentialView>>();
// 响应丢失时沿用同一确认 ID 和版本，避免重复签发秘密。
let acceptance: { record: string; id: string; version: number } | undefined;
let listPending: AbortController | undefined; let detailPending: AbortController | undefined; let detailGeneration = 0;
const kindNames = { reassign: '转配', end: '结束使用', expire: '使用到期' };
const time = (value: number) => displayDateTime(value * 1000);
const [Detail, drawer] = useVbenDrawer({ title: '设备转配详情', footer: false, class: 'w-full max-w-3xl', onBeforeClose: () => !saving.value, onClosed() { detailGeneration++; detailPending?.abort(); selected.value = undefined; acceptance = undefined; } });
const [Grid, grid] = useVbenVxeGrid<DeviceReassignment>({ gridOptions: {
  columns: [{ field: 'device_name', title: '设备名称', minWidth: 180 }, { field: 'kind', title: '处理类型', width: 110, formatter: ({ cellValue }: { cellValue: string | number }) => kindNames[cellValue as DeviceReassignment['kind']] },
    { field: 'source_tenant_id', title: '来源客户', minWidth: 280 }, { field: 'target_tenant_id', title: '目标客户', minWidth: 280 },
    { field: 'status', title: '实际阶段', width: 220, slots: { default: 'status' } }, { field: 'created_at', title: '发起时间', width: 185, formatter: ({ cellValue }: { cellValue: string | number }) => time(Number(cellValue)) },
    { title: '操作', width: 100, fixed: 'right', slots: { default: 'actions' } }],
  rowConfig: { keyField: 'id' }, pagerConfig: { pageSize: 20, pageSizes: [20, 50, 100] }, toolbarConfig: { refresh: true },
  proxyConfig: { ajax: { query: async ({ page }: { page: { currentPage: number; pageSize: number } }) => {
    listPending?.abort(); listPending = new AbortController(); failure.value = '';
    try { const data = await request<PageResult<DeviceReassignment> & { context: { permissions: string[]; menus: typeof session.menus } }>(endpoint.value, { signal: listPending.signal, params: { page: page.currentPage, per_page: page.pageSize } }); applyAccessContext(data.context); return data; }
    catch (error) { if (!isCanceled(error)) failure.value = errorText(error); throw error; }
  } } },
} });
async function read(row: DeviceReassignment) {
  if (saving.value) return;
  if (selected.value?.id !== row.id) acceptance = undefined;
  selected.value = row; drawer.open(); detailFailure.value = ''; detailBusy.value = true;
  const current = ++detailGeneration; detailPending?.abort(); detailPending = new AbortController();
  try {
    const data = await request<{ operation: DeviceReassignment; context: { permissions: string[]; menus: typeof session.menus } }>(`${endpoint.value}/${row.id}`, { signal: detailPending.signal });
    if (current !== detailGeneration) return;
    selected.value = data.operation; applyAccessContext(data.context);
    if (acceptance && acceptance.version !== data.operation.version) acceptance = undefined;
  } catch (error) { if (!isCanceled(error) && current === detailGeneration) detailFailure.value = errorText(error); }
  finally { if (current === detailGeneration) detailBusy.value = false; }
}
function confirm(action: 'advance' | 'accept') {
  Modal.confirm({ title: action === 'accept' ? '确认接收设备？' : '核验旧授权及持久内容？', content: action === 'accept' ? '将建立新使用关系并签发一次性凭据，需重新授予应用通信权限。' : '隔离回执和保留消息核验完成后才会进入下一阶段。', onOk: () => change(action) });
}
async function change(action: 'advance' | 'accept') {
  const row = selected.value;
  if (!row || saving.value || detailBusy.value || isDemo() || !allowed(action)) return;
  saving.value = true; detailFailure.value = '';
  if (action === 'accept' && acceptance?.record !== row.id) acceptance = { record: row.id, id: crypto.randomUUID().replaceAll('-', ''), version: row.version };
  try {
    const data = await request<DeviceReassignment | { operation: DeviceReassignment; credential: DeviceCredential | null; replayed: boolean }>(`${endpoint.value}/${row.id}`, {
      method: 'POST', data: action === 'accept' ? { action, id: acceptance!.id, version: acceptance!.version } : { action, version: row.version },
    });
    if ('operation' in data) {
      selected.value = data.operation; acceptance = undefined; if (data.credential) secret.value?.show(data.credential);
      message.success(data.replayed ? '此前确认已完成，凭据不重复展示；遗失请轮换。' : '已接收设备，请保存新凭据并重新授权应用。');
    } else { selected.value = data; message.info(reassignmentStatusNames[data.status]); }
    await grid.query();
  } catch (error) { if (!isCanceled(error)) { detailFailure.value = errorText(error); throw error; } }
  finally { saving.value = false; }
}
onBeforeUnmount(() => { detailGeneration++; listPending?.abort(); detailPending?.abort(); });
</script>
<template>
  <Page auto-content-height><Alert v-if="failure" :message="failure" type="error" class="mb-3" /><Grid :table-title="platform ? '设备转配' : '转配接收'">
    <template #status="{ row }"><Tag :color="['ended', 'completed'].includes(row.status) ? 'success' : 'processing'">{{ reassignmentStatusNames[row.status as DeviceReassignment['status']] }}</Tag></template>
    <template #actions="{ row }"><Button type="link" size="small" :disabled="saving" @click="read(row)">查看处理</Button></template>
  </Grid><Detail><template v-if="selected">
    <Alert v-if="detailFailure" :message="detailFailure" type="error" class="mb-3" /><Space wrap class="mb-3"><Tag>{{ reassignmentStatusNames[selected.status] }}</Tag><Button :loading="detailBusy" :disabled="saving" @click="read(selected)">读取当前状态</Button>
      <Button v-if="platform && allowed('advance') && selected.status === 'isolating'" :disabled="saving || detailBusy || isDemo()" @click="confirm('advance')">核验并推进</Button>
      <Button v-if="!platform && allowed('accept') && selected.target_tenant_id === session.tenant?.id && selected.status === 'awaiting_acceptance'" type="primary" :disabled="saving || detailBusy || isDemo()" @click="confirm('accept')">确认接收</Button></Space>
    <Alert v-for="reason in selected.pending_reasons" :key="reason" :message="reassignmentPendingNames[reason] || reason" type="info" class="mb-3" />
    <Descriptions class="break-all" :column="1" bordered><DescriptionsItem label="设备名称">{{ selected.device_name }}</DescriptionsItem><DescriptionsItem label="设备标识">{{ selected.device_id }}</DescriptionsItem><DescriptionsItem label="处理标识">{{ selected.id }}</DescriptionsItem><DescriptionsItem label="来源客户">{{ selected.source_tenant_id }}</DescriptionsItem><DescriptionsItem label="目标客户">{{ selected.target_tenant_id || '无，仅结束使用' }}</DescriptionsItem><DescriptionsItem label="旧使用关系">{{ selected.ownership_id }}</DescriptionsItem><DescriptionsItem label="新使用关系">{{ selected.new_ownership_id || '尚未建立' }}</DescriptionsItem><DescriptionsItem label="持久隔离证明">{{ selected.requires_store ? '需要存储回执与保留消息核验' : '已确认没有接入历史' }}</DescriptionsItem><DescriptionsItem v-for="(proof, direction) in selected.retained" :key="direction" :label="direction === 'publish' ? '旧上行保留消息' : '旧下行保留消息'">{{ proof.state === 'absent' ? '核验不存在' : '清理待完成' }}{{ proof.checked_at ? ` · ${time(proof.checked_at)}` : '' }}</DescriptionsItem><DescriptionsItem label="记录版本">{{ selected.version }}</DescriptionsItem><DescriptionsItem label="更新时间">{{ time(selected.updated_at) }}</DescriptionsItem></Descriptions>
  </template></Detail><DeviceCredentialView ref="secret" /></Page>
</template>
