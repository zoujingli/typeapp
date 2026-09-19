<script setup lang="ts">
import type { FormInstance, TableColumnsType } from 'ant-design-vue';
import type { AlarmRule, Device, DeviceDetail, ModelScalar, Page, TenantContext } from '../api';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Alert, Button, Card, Descriptions, DescriptionsItem, Empty, Form, FormItem, Input, InputNumber, Select, Switch, Table, Tag, message } from 'ant-design-vue';
import AppDrawer from '../components/app-drawer/app-drawer.vue';
import CrudSearchField from '../components/crud-search-field.vue';
import CrudTableActions from '../components/crud-table-actions.vue';
import { buildTableScrollX, estimateVisibleActionColumnWidth } from '../utils/table';
import { ApiError, alarmBounds, alarmEndReasons, errorText, isCanceled, request, session } from '../api';

const router = useRouter();
const result = ref<Page<AlarmRule>>({ items: [], total: 0, page: 1, per_page: 20 });
const versions = ref<Page<AlarmRule>>({ items: [], total: 0, page: 1, per_page: 20 });
const context = ref<TenantContext | null>(null);
const filters = reactive({ name: '', device_id: '', field: '' });
let applied = { ...filters };
const busy = ref(false); const failure = ref('');
const open = ref(false); const saving = ref(false); const saveFailure = ref('');
const editing = ref<AlarmRule | null>(null); const detail = ref<AlarmRule | null>(null);
const versionBusy = ref(false); const versionFailure = ref('');
const devices = ref<Device[]>([]); const fields = ref<ModelScalar[]>([]); const deviceBusy = ref(false);
const formRef = ref<FormInstance>();
const form = reactive({ name: '', device_id: '', field: '', lower: null as number | null, upper: null as number | null, hysteresis: 0, enabled: true });
let generation = 0; let deviceGeneration = 0;
let pending: AbortController | null = null;
let versionPending: AbortController | null = null;
const canEdit = computed(() => Boolean(context.value?.permissions.includes('customer.alarms.manage')));
const columns = computed<TableColumnsType<AlarmRule>>(() => [
  { title: '规则名称', key: 'name', width: 240, ellipsis: true }, { title: '设备标识', dataIndex: 'device_id', width: 290, ellipsis: true },
  { title: '监控属性', key: 'field', width: 200, ellipsis: true }, { title: '触发条件', key: 'bounds', width: 260, ellipsis: true },
  { title: '版本状态', key: 'state', width: 160 }, { title: '操作', key: 'actions', fixed: 'right', width: estimateVisibleActionColumnWidth([{ label: '版本' }, { label: '编辑', visible: canEdit.value }]) },
]);
const versionColumns: TableColumnsType<AlarmRule> = [{ title: '版本', dataIndex: 'rule_version', width: 80 }, { title: '条件', key: 'bounds', width: 230 }, { title: '状态', key: 'state', width: 180 }, { title: '发布时间', key: 'time', width: 190 }];
const pagination = computed(() => ({ current: result.value.page, pageSize: result.value.per_page, total: result.value.total, showSizeChanger: true, pageSizeOptions: ['20', '50', '100'] }));
const time = (value: number | null) => value === null ? '—' : new Date(value * 1000).toLocaleString();
async function load(page = 1, perPage = result.value.per_page) {
  const tenant = session.tenant?.id; if (!tenant) return;
  const current = ++generation; pending?.abort(); pending = new AbortController(); busy.value = true; failure.value = '';
  try { const data = await request<Page<AlarmRule> & { context: TenantContext }>(`/customer/tenants/${tenant}/alarm-rules`, { tenant, signal: pending.signal, params: { ...applied, page, per_page: perPage } }); if (current === generation) { result.value = data; context.value = data.context; } }
  catch (error) { if (!isCanceled(error) && current === generation) { failure.value = errorText(error); result.value.items = []; context.value = null; } }
  finally { if (current === generation) busy.value = false; }
}
function search(reset = false) { if (busy.value) return; if (reset) Object.assign(filters, { name: '', device_id: '', field: '' }); applied = { name: filters.name.trim(), device_id: filters.device_id.trim(), field: filters.field.trim() }; void load(); }
async function searchDevices(name = '') {
  const tenant = session.tenant?.id; if (!tenant) return;
  const current = ++deviceGeneration; deviceBusy.value = true;
  try { const data = await request<Page<Device>>(`/customer/tenants/${tenant}/devices`, { tenant, params: { name, per_page: 100 } }); if (current === deviceGeneration) devices.value = data.items; }
  catch (error) { if (!isCanceled(error) && current === deviceGeneration) saveFailure.value = errorText(error); }
  finally { if (current === deviceGeneration) deviceBusy.value = false; }
}
async function chooseDevice(id: string) {
  const tenant = session.tenant?.id; if (!tenant || editing.value) return;
  const current = ++deviceGeneration; fields.value = []; form.field = ''; deviceBusy.value = true;
  try { const device = await request<DeviceDetail>(`/customer/tenants/${tenant}/devices/${id}`, { tenant }); if (current === deviceGeneration) fields.value = device.model.definition.properties.filter(field => ['number', 'integer'].includes(field.type)); }
  catch (error) { if (!isCanceled(error) && current === deviceGeneration) saveFailure.value = errorText(error); }
  finally { if (current === deviceGeneration) deviceBusy.value = false; }
}
function edit(rule: AlarmRule | null) {
  if (!canEdit.value) return;
  editing.value = rule; saveFailure.value = ''; fields.value = rule ? [rule.definition.property] : [];
  Object.assign(form, { name: rule?.definition.name || '', device_id: rule?.device_id || '', field: rule?.field || '', lower: rule?.definition.lower ?? null, upper: rule?.definition.upper ?? null, hysteresis: rule?.definition.hysteresis ?? 0, enabled: rule?.definition.enabled ?? true });
  open.value = true; if (!rule) void searchDevices();
}
async function save() {
  const tenant = session.tenant?.id; if (!tenant || saving.value || !canEdit.value || deviceBusy.value) return;
  try { await formRef.value?.validate(); } catch { return; }
  if ((form.lower === null && form.upper === null) || (form.lower !== null && form.upper !== null && form.lower + form.hysteresis > form.upper - form.hysteresis)) { saveFailure.value = '请设置阈值，并确保加入回差后的恢复区间有效。'; return; }
  saving.value = true; saveFailure.value = '';
  try { await request(`/customer/tenants/${tenant}/alarm-rules${editing.value ? `/${editing.value.id}` : ''}`, { tenant, method: editing.value ? 'PATCH' : 'POST', data: { name: form.name, lower: form.lower, upper: form.upper, hysteresis: form.hysteresis, enabled: form.enabled, ...(editing.value ? { version: editing.value.version } : { device_id: form.device_id, field: form.field }) } }); open.value = false; message.success('告警规则新版本已发布'); await load(result.value.page); }
  catch (error) { if (!isCanceled(error)) saveFailure.value = errorText(error); if (error instanceof ApiError && error.code === 'stale_version') await load(result.value.page); }
  finally { saving.value = false; }
}
async function showVersions(rule: AlarmRule, page = 1) {
  const tenant = session.tenant?.id; if (!tenant) return;
  versionPending?.abort(); const versionRequest = new AbortController(); versionPending = versionRequest;
  detail.value = rule; versions.value.items = []; versionBusy.value = true; versionFailure.value = '';
  try { const data = await request<Page<AlarmRule>>(`/customer/tenants/${tenant}/alarm-rules/${rule.id}/versions`, { tenant, signal: versionRequest.signal, params: { page } }); if (detail.value?.id === rule.id) versions.value = data; }
  catch (error) { if (!isCanceled(error)) versionFailure.value = errorText(error); }
  finally { if (versionPending === versionRequest) versionBusy.value = false; }
}
watch(() => session.tenant?.id, tenant => { generation++; deviceGeneration++; pending?.abort(); versionPending?.abort(); result.value.items = []; context.value = null; open.value = false; detail.value = null; devices.value = []; fields.value = []; if (tenant) void load(); else void router.replace('/tenants'); }, { immediate: true });
onBeforeUnmount(() => { generation++; deviceGeneration++; pending?.abort(); versionPending?.abort(); });
</script>

<template>
  <section class="iot-page">
    <header class="page-heading"><div><h1>告警规则</h1><p class="muted tenant-title">{{ session.tenant?.name }} · 连续三次触发与恢复</p></div><Button v-if="canEdit" type="primary" @click="edit(null)">创建规则</Button></header>
    <Card :bordered="false">
      <form class="crud-search-grid" @submit.prevent="search()">
        <CrudSearchField label="规则名称"><Input v-model:value="filters.name" aria-label="规则名称筛选" :disabled="busy" :maxlength="100" allow-clear /></CrudSearchField>
        <CrudSearchField label="设备标识"><Input v-model:value="filters.device_id" aria-label="设备标识筛选" :disabled="busy" :maxlength="32" allow-clear /></CrudSearchField>
        <CrudSearchField label="属性标识"><Input v-model:value="filters.field" aria-label="属性标识筛选" :disabled="busy" :maxlength="64" allow-clear /></CrudSearchField>
        <div class="crud-search-grid__actions"><Button type="primary" html-type="submit" :loading="busy" :disabled="busy">查询</Button><Button :disabled="busy" @click="search(true)">重置</Button></div>
      </form>
      <Alert v-if="failure" :message="failure" type="error" show-icon class="page-alert" role="alert" />
      <Alert v-else-if="context && !canEdit" message="当前角色可查看规则，发布和停用需要对应的业务权限。" type="info" show-icon class="page-alert" />
      <p class="muted">严格小于下限或大于上限才算超限。有效样本间隔超过30秒重新计数；缺测不会自动恢复告警。</p>
      <div class="table-toolbar"><span class="muted">{{ result.total }} 条规则</span><Button :loading="busy" @click="load(result.page)">刷新</Button></div>
      <Table :columns="columns" :data-source="result.items" row-key="id" :loading="busy" :scroll="buildTableScrollX(columns)" :pagination="pagination" @change="page => load(page.current, page.pageSize)">
        <template #emptyText><Empty :description="failure ? '数据暂不可用，请重试' : '暂无符合条件的规则'" /></template>
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'name'">{{ record.definition.name }}</template><template v-else-if="column.key === 'field'">{{ record.definition.property.name }} · {{ record.field }}</template>
          <template v-else-if="column.key === 'bounds'">{{ alarmBounds(record.definition) }}</template><template v-else-if="column.key === 'state'"><Tag :color="record.definition.enabled ? 'success' : 'default'">{{ record.definition.enabled ? '启用' : '停用' }}</Tag>v{{ record.rule_version }}</template>
          <CrudTableActions v-else-if="column.key === 'actions'" :actions="[{ label: '版本', onClick: () => showVersions(record as AlarmRule) }, { label: '编辑', visible: canEdit, onClick: () => edit(record as AlarmRule) }]" />
        </template>
      </Table>
    </Card>
    <AppDrawer v-model:open="open" :title="editing ? '发布规则新版本' : '创建告警规则'" width-size="md" :confirm-loading="saving" :ok-disabled="saving || deviceBusy" @ok="save">
      <Alert v-if="saveFailure" :message="saveFailure" type="error" show-icon class="page-alert" role="alert" />
      <p class="muted">编辑后保留旧版本与原告警；旧活动告警以规则变更结束，新版本重新累计三次。停用也会发布版本。</p>
      <Form ref="formRef" :model="form" layout="vertical" class="form-grid" :disabled="saving">
        <FormItem label="规则名称" name="name" :rules="[{ required: true, whitespace: true, message: '请输入规则名称' }]" class="span-full"><Input v-model:value="form.name" :maxlength="100" /></FormItem>
        <FormItem label="监控设备" name="device_id" :rules="[{ required: true, message: '请选择监控设备' }]" class="span-full"><Input v-if="editing" :value="form.device_id" disabled /><Select v-else v-model:value="form.device_id" aria-label="监控设备" show-search :filter-option="false" :loading="deviceBusy" :options="devices.map(device => ({ label: `${device.name} · ${device.id}`, value: device.id }))" placeholder="输入设备名称搜索" @search="searchDevices" @change="value => chooseDevice(String(value))" /></FormItem>
        <FormItem label="监控属性" name="field" :rules="[{ required: true, message: '请选择数值属性' }]" class="span-full"><Select v-model:value="form.field" aria-label="监控属性" :disabled="Boolean(editing) || deviceBusy" :options="fields.map(field => ({ label: `${field.name} · ${field.identifier}${field.unit ? ` (${field.unit})` : ''}`, value: field.identifier }))" /></FormItem>
        <FormItem label="触发下限"><InputNumber :value="form.lower ?? undefined" aria-label="触发下限" class="w-full" placeholder="不设下限" @update:value="value => form.lower = value == null ? null : Number(value)" /></FormItem><FormItem label="触发上限"><InputNumber :value="form.upper ?? undefined" aria-label="触发上限" class="w-full" placeholder="不设上限" @update:value="value => form.upper = value == null ? null : Number(value)" /></FormItem>
        <FormItem label="恢复回差"><InputNumber v-model:value="form.hysteresis" aria-label="恢复回差" :min="0" class="w-full" /></FormItem><FormItem label="启用规则"><Switch v-model:checked="form.enabled" aria-label="启用规则" /></FormItem>
        <p class="muted span-full">恢复区间：{{ form.lower === null ? '无下界' : form.lower + form.hysteresis }} 至 {{ form.upper === null ? '无上界' : form.upper - form.hysteresis }}，包含边界。</p>
      </Form>
    </AppDrawer>
    <AppDrawer :open="Boolean(detail)" title="规则版本历史" width-size="lg" :show-footer="false" @update:open="value => { if (!value) detail = null; }">
      <Alert v-if="versionFailure" :message="versionFailure" type="error" show-icon class="page-alert" role="alert" /><Button v-if="versionFailure && detail" @click="showVersions(detail)">重试</Button>
      <Descriptions v-if="detail" :column="{ xs: 1, sm: 2 }" layout="vertical" bordered class="page-alert"><DescriptionsItem label="规则名称">{{ detail.definition.name }}</DescriptionsItem><DescriptionsItem label="设备标识">{{ detail.device_id }}</DescriptionsItem><DescriptionsItem label="监控属性">{{ detail.definition.property.name }} · {{ detail.field }}</DescriptionsItem><DescriptionsItem label="冻结物模型">v{{ detail.model_version }} · {{ detail.definition.property.unit || '无单位' }}</DescriptionsItem><DescriptionsItem label="产品标识">{{ detail.product_id }}</DescriptionsItem><DescriptionsItem label="归属标识">{{ detail.ownership_id }}</DescriptionsItem></Descriptions>
      <Table :columns="versionColumns" :data-source="versions.items" row-key="rule_version" :loading="versionBusy" :scroll="buildTableScrollX(versionColumns)" :pagination="{ current: versions.page, pageSize: 20, total: versions.total, showSizeChanger: false }" @change="page => detail && showVersions(detail, page.current)">
        <template #bodyCell="{ column, record }"><div v-if="column.key === 'bounds'" class="current-value">{{ alarmBounds(record.definition) }}<br />回差 {{ record.definition.hysteresis }} · {{ record.definition.name }}</div><template v-else-if="column.key === 'state'">{{ record.retired_at ? (alarmEndReasons[record.end_reason] || record.end_reason) : record.definition.enabled ? '当前启用' : '当前停用' }}<br /><span v-if="record.retired_at && !record.finalized" class="muted">等待已接收样本完成</span><span v-else-if="!record.retired_at" class="muted">触发 {{ record.trigger_count }}/3 · 恢复 {{ record.recovery_count }}/3</span></template><template v-else-if="column.key === 'time'">{{ time(record.published_at) }}</template></template>
      </Table>
    </AppDrawer>
  </section>
</template>
