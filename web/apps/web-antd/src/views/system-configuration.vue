<script setup lang="ts">
import type { ConfigurationField, ConfigurationView } from '../api';
import { computed, onMounted, reactive, ref } from 'vue';
import { Alert, Button, Card, Col, Empty, Form, FormItem, Input, InputNumber, Row, Select, Space, Spin, Switch, Tag, message } from 'ant-design-vue';
import { ApiError, applyAdminContext, clearAdminAccess, errorText, isCanceled, request, session } from '../api';

type ConfigValue = string | number | boolean | null;
const view = ref<ConfigurationView>({ version: '', restart_required: false, fields: [], undeclared: [], permissions: [], catalog: {}, menus: [] });
const values = reactive<Record<string, ConfigValue>>({});
const loading = ref(false); const saving = ref(false); const failure = ref(''); const pendingRestart = ref(false);

const groups = computed(() => view.value.fields.reduce<Record<string, ConfigurationField[]>>((result, field) => {
  (result[field.group] ||= []).push(field);
  return result;
}, {}));
const groupNames = ['应用', 'HTTP', '数据库', '缓存', 'Broker', 'MQTT', '其他'];
const orderedGroups = computed(() => [...groupNames.filter(name => groups.value[name]), ...Object.keys(groups.value).filter(name => !groupNames.includes(name))]);
const canManage = computed(() => session.permissions.includes('admin.config.manage'));

function replaceView(data: ConfigurationView) {
  view.value = data;
  applyAdminContext(data);
  Object.keys(values).forEach(key => delete values[key]);
  data.fields.forEach(field => { values[field.key] = field.value; });
}

async function load() {
  loading.value = true; failure.value = '';
  try {
    const data = await request<ConfigurationView>('/admin/configuration');
    replaceView(data);
  } catch (error) {
    if (!isCanceled(error)) { failure.value = errorText(error); clearAdminAccess(error); }
  } finally { loading.value = false; }
}

function editable(field: ConfigurationField) { return canManage.value && field.editable && !field.read_only && !field.secret; }
function fieldValue(field: ConfigurationField): ConfigValue { return values[field.key] ?? null; }
function setValue(field: ConfigurationField, value: ConfigValue) { values[field.key] = value; }
function sameValue(left: ConfigValue, right: ConfigValue) { return left === right; }
function sourceLabel(field: ConfigurationField) { return field.source === 'process' ? '进程环境' : field.source === 'file' ? '运行文件' : field.source === 'default' ? '默认值' : '未知来源'; }

async function save() {
  if (saving.value || !canManage.value) return;
  const changes: Record<string, ConfigValue> = {};
  view.value.fields.forEach(field => {
    if (editable(field) && !sameValue(fieldValue(field), field.value)) changes[field.key] = fieldValue(field);
  });
  if (Object.keys(changes).length === 0) { message.info('没有需要保存的变更'); return; }
  saving.value = true; failure.value = '';
  try {
    const result = await request<{ version: string; restart_required: boolean; changed: string[]; status: string }>('/admin/configuration', {
      method: 'PUT', data: { version: view.value.version, changes },
    });
    view.value.version = result.version;
    view.value.restart_required = result.restart_required;
    pendingRestart.value = true;
    view.value.fields.forEach(field => { if (Object.prototype.hasOwnProperty.call(changes, field.key)) { field.value = fieldValue(field); field.source = 'file'; field.file_present = true; } });
    message.success('配置已写入，重启应用后生效');
  } catch (error) {
    if (!isCanceled(error)) {
      failure.value = errorText(error);
      clearAdminAccess(error);
      if (error instanceof ApiError && error.status === 409) await load();
    }
  } finally { saving.value = false; }
}

function inputValue(field: ConfigurationField): string | number { const value = fieldValue(field); return typeof value === 'string' || typeof value === 'number' ? value : ''; }
function numberValue(field: ConfigurationField): number | undefined { const value = fieldValue(field); return typeof value === 'number' ? Number(value) : undefined; }
function hint(field: ConfigurationField) {
  if (field.secret) return '敏感值不会在网页中回显或修改。';
  if (field.read_only) return field.reason === 'process_environment_requires_operator_restart' ? '当前由进程环境覆盖，请修改进程环境并重启。' : '当前字段只读。';
  return '保存后需要重启应用。';
}

onMounted(() => { void load(); });
</script>

<template>
  <section class="iot-page configuration-page">
    <header class="page-heading">
      <div><h1>系统配置</h1><p class="muted">查看运行配置来源并维护非敏感启动参数</p></div>
      <Space><Button :loading="loading" @click="load">刷新</Button><Button v-if="canManage" type="primary" :loading="saving" @click="save">保存配置</Button></Space>
    </header>
    <Alert v-if="pendingRestart || view.restart_required" message="配置已写入运行文件，重启应用后生效；当前进程不会热加载新值。" type="warning" show-icon class="page-alert" />
    <Alert v-if="failure" :message="failure" type="error" show-icon role="alert" class="page-alert" />
    <Spin :spinning="loading">
      <template v-if="view.fields.length">
        <Card v-for="group in orderedGroups" :key="group" :title="group" :bordered="false" class="configuration-group">
          <Row :gutter="[20, 4]">
            <Col v-for="field in groups[group]" :key="field.key" :xs="24" :lg="12">
              <Form layout="vertical">
                <FormItem :label="field.label">
                  <Select v-if="field.type === 'enum'" :value="inputValue(field)" :options="(field.options || []).map(option => ({ label: option, value: option }))" :disabled="!editable(field)" @change="value => setValue(field, String(value))" />
                  <InputNumber v-else-if="field.type === 'integer'" :value="numberValue(field)" :disabled="!editable(field)" :controls="false" style="width: 100%" @change="value => setValue(field, typeof value === 'number' ? value : null)" />
                  <Switch v-else-if="field.type === 'boolean'" :checked="values[field.key] === true" :disabled="!editable(field)" @change="checked => setValue(field, Boolean(checked))" />
                  <Input v-else :value="field.secret ? '已隐藏' : inputValue(field)" :disabled="!editable(field)" :maxlength="4096" @update:value="value => setValue(field, value)" />
                  <div class="configuration-meta"><Tag>{{ sourceLabel(field) }}</Tag><span class="muted">{{ field.secret ? '敏感字段' : field.key }}</span><span class="muted">{{ hint(field) }}</span></div>
                </FormItem>
              </Form>
            </Col>
          </Row>
        </Card>
        <Card v-if="view.undeclared.length" title="未登记配置" :bordered="false" class="configuration-group">
          <Alert message="运行文件包含未纳入管理页面的键，网页不会读取或修改其值。" type="info" show-icon />
          <p class="muted configuration-undeclared">{{ view.undeclared.join('、') }}</p>
        </Card>
      </template>
      <Empty v-else-if="!loading" description="暂无可显示的配置" />
    </Spin>
  </section>
</template>

<style scoped>
.configuration-group { margin-bottom: 20px; }
.configuration-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 10px; margin-top: 6px; font-size: 12px; }
.configuration-undeclared { overflow-wrap: anywhere; margin: 16px 0 0; }
</style>
