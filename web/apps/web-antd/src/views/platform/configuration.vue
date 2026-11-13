<script setup lang="ts">
import type { ConfigurationField, ConfigurationView } from '../../api/platform';
import type { VbenFormSchema } from '../../adapter/form';
import { computed, onMounted, ref } from 'vue';
import { Alert, Button, Card, Space, Spin, message } from 'ant-design-vue';
import { Page } from '@vben/common-ui';
import { useVbenForm } from '../../adapter/form';
import { ApiError, applyAccessContext, errorText, isCanceled, isDemo, request, session } from '../../api/identity';
const view = ref<ConfigurationView>(); const loading = ref(false); const saving = ref(false); const failure = ref(''); const pendingRestart = ref(false); const conflicted = ref(false);
const canManage = computed(() => session.permissions.includes('platform.config.manage') && !isDemo());
const [Form, form] = useVbenForm({ showDefaultActions: false, layout: 'vertical', commonConfig: { componentProps: { class: 'w-full' } }, wrapperClass: 'grid-cols-1 md:grid-cols-2 xl:grid-cols-3' });
const editable = (field: ConfigurationField) => canManage.value && field.editable && !field.read_only && !field.secret;
function hint(field: ConfigurationField) {
  const source = { process: '进程环境', file: '运行文件', default: '默认值', unknown: '未知来源' }[field.source];
  return `${field.group} · ${source} · ${field.secret ? '敏感值不能在网页中读取或修改' : field.read_only ? field.reason === 'process_environment_requires_operator_restart' ? '修改进程环境并重启' : '只读' : '保存后重启生效'}${field.secret ? '' : ` · ${field.key}`}`;
}
async function load() {
  if (loading.value || saving.value) return;
  loading.value = true; failure.value = '';
  try {
    const data = await request<ConfigurationView>('/platform/configuration'); view.value = data; applyAccessContext(data); conflicted.value = false;
    const schema: VbenFormSchema[] = Object.entries(Object.groupBy(data.fields, field => field.group)).flatMap(([group, fields], index) => [
      { fieldName: `_section_${index}`, component: 'Divider', hideLabel: true, formItemClass: 'col-span-full', componentProps: { orientation: 'left', plain: true, class: 'my-0' }, renderComponentContent: () => ({ default: () => group }) },
      ...fields!.map((field): VbenFormSchema => ({ fieldName: field.key, label: field.label, help: hint(field),
      component: field.type === 'enum' ? 'Select' : field.type === 'integer' ? 'InputNumber' : field.type === 'boolean' ? 'Switch' : 'Input',
      componentProps: { class: field.type === 'boolean' ? 'w-auto' : 'w-full', disabled: !editable(field), ...(field.options ? { options: field.options.map(value => ({ value, label: value })) } : {}), maxlength: 4096 },
    })),
    ]);
    form.setState({ schema }); await form.setValues(Object.fromEntries(data.fields.map(field => [field.key, field.secret ? '已隐藏' : field.value])));
  } catch (error) { if (!isCanceled(error)) failure.value = errorText(error); }
  finally { loading.value = false; }
}
async function save() {
  if (!view.value || saving.value || !canManage.value || conflicted.value) return;
  const values = await form.getValues();
  const changes = Object.fromEntries(view.value.fields.filter(field => editable(field) && values[field.key] !== field.value).map(field => [field.key, values[field.key]]));
  if (!Object.keys(changes).length) { message.info('没有需要保存的变更'); return; }
  saving.value = true; failure.value = '';
  try {
    const result = await request<{ version: string; restart_required: boolean }>('/platform/configuration', { method: 'PUT', data: { version: view.value.version, changes } });
    view.value.version = result.version; pendingRestart.value = result.restart_required;
    view.value.fields.forEach(field => { if (Object.hasOwn(changes, field.key)) field.value = changes[field.key]; });
    message.success('配置已写入，重启后生效');
  } catch (error) { if (!isCanceled(error)) { conflicted.value = error instanceof ApiError && error.status === 409; failure.value = conflicted.value ? '配置已被其他人修改。当前输入已保留，请记录后刷新最新配置。' : errorText(error); } }
  finally { saving.value = false; }
}
onMounted(load);
</script>
<template>
  <Page title="运行配置" description="查看配置来源并维护非敏感启动参数。" header-class="flex-wrap gap-4">
    <template #extra><Space><Button :loading="loading" :disabled="saving" @click="load">刷新</Button><Button v-if="canManage" type="primary" :loading="saving" :disabled="!view || loading || conflicted" @click="save">保存配置</Button></Space></template>
    <Alert v-if="pendingRestart || view?.restart_required" type="warning" class="mb-4" message="配置已写入，重启后生效；当前进程不会热加载。" show-icon /><Alert v-if="failure" :message="failure" type="error" class="mb-4" show-icon />
    <Spin :spinning="loading"><Card :bordered="false"><Form :common-config="{ disabled: loading || saving, componentProps: { class: 'w-full' } }" /></Card></Spin><Alert v-if="view?.undeclared.length" type="info" class="mt-4" message="以下配置键未纳入网页管理，不会读取或修改其值" :description="view.undeclared.join('、')" show-icon />
  </Page>
</template>
