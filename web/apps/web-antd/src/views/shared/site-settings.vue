<script setup lang="ts">
import type { SiteSettingsView } from '../../api/contracts';
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { Alert, Button, Card, Empty, Space, Spin, message } from 'ant-design-vue';
import { Page } from '@vben/common-ui';
import { useVbenForm, z } from '../../adapter/form';
import { ApiError, errorText, isCanceled, isDemo, request, session, siteSettings } from '../../api/identity';
import { applySitePreferences } from '../../preferences';

const route = useRoute();
const defaults = computed(() => route.path === '/platform/system-defaults');
const endpoint = computed(() => defaults.value ? '/platform/system-defaults' : `/${session.realm}/site`);
const title = computed(() => defaults.value ? '系统登录与初始配置' : session.realm === 'platform' ? '平台设置' : '系统设置');
const canManage = computed(() => session.permissions.includes(defaults.value ? 'platform.defaults.manage' : `${session.realm}.site.manage`) && !isDemo());
const view = ref<SiteSettingsView>();
const failure = ref(''); const loading = ref(false); const saving = ref(false); const conflicted = ref(false);
const select = (values: [string, string][]) => ({ options: values.map(([value, label]) => ({ value, label })) });
const [Form, form] = useVbenForm({
  showDefaultActions: false, layout: 'vertical', commonConfig: { componentProps: { class: 'w-full' } }, wrapperClass: 'grid-cols-1 md:grid-cols-2 xl:grid-cols-3', schema: [
    { fieldName: '_brand', component: 'Divider', hideLabel: true, formItemClass: 'col-span-full', componentProps: { orientation: 'left', plain: true, class: 'my-0' }, renderComponentContent: () => ({ default: () => '品牌信息' }) },
    { fieldName: 'name', label: '系统名称', component: 'Input', rules: z.string().trim().min(1).max(100) },
    { fieldName: 'official_url', label: '官方网站', component: 'Input', defaultValue: '', rules: z.url().max(512) },
    { fieldName: 'logo_url', label: 'Logo 地址', component: 'Input', componentProps: { placeholder: '留空使用文字品牌', maxlength: 512 } },
    { fieldName: 'description', label: '系统说明', component: 'Textarea', formItemClass: 'col-span-full', componentProps: { maxlength: 500, autoSize: { minRows: 2, maxRows: 4 } } },
    { fieldName: '_theme', component: 'Divider', hideLabel: true, formItemClass: 'col-span-full', componentProps: { orientation: 'left', plain: true, class: 'my-0' }, renderComponentContent: () => ({ default: () => '主题与时间' }) },
    { fieldName: 'theme_mode', label: '主题模式', component: 'Select', componentProps: select([['light', '浅色'], ['dark', '深色'], ['auto', '跟随系统']]) },
    { fieldName: 'theme_color', label: '主题色', component: 'Input', rules: z.string().regex(/^#[0-9a-fA-F]{6}$/, '请输入六位十六进制颜色') },
    { fieldName: 'theme_radius', label: '圆角', component: 'Select', componentProps: { options: ['0', '0.25', '0.5', '0.75', '1'].map(value => ({ value, label: value })) } },
    { fieldName: 'timezone', label: '默认时区', component: 'Input', componentProps: { placeholder: '例如 Asia/Shanghai' }, rules: z.string().min(1).max(64) },
    { fieldName: '_navigation', component: 'Divider', hideLabel: true, formItemClass: 'col-span-full', componentProps: { orientation: 'left', plain: true, class: 'my-0' }, renderComponentContent: () => ({ default: () => '布局与导航' }) },
    { fieldName: 'layout_mode', label: '布局', component: 'Select', componentProps: select([['sidebar-nav', '侧栏导航'], ['mixed-nav', '混合导航'], ['header-nav', '顶栏导航']]) },
    { fieldName: 'navigation_style', label: '导航样式', component: 'Select', componentProps: select([['rounded', '圆润'], ['plain', '朴素']]) },
    { fieldName: 'tabbar_style', label: '标签页样式', component: 'Select', componentProps: select([['chrome', 'Chrome'], ['brisk', '轻快'], ['card', '卡片'], ['plain', '朴素']]) },
    { fieldName: 'breadcrumb_style', label: '面包屑样式', component: 'Select', componentProps: select([['normal', '标准'], ['background', '背景']]) },
    ...([
      ['sidebar_collapsed', '侧栏默认折叠'], ['navigation_split', '混合导航分割'], ['tabbar_enable', '标签页'],
      ['breadcrumb_enable', '面包屑'], ['breadcrumb_show_icon', '面包屑图标'], ['footer_enable', '页脚'], ['footer_fixed', '固定页脚'],
    ] as const).map(([fieldName, label]) => ({ fieldName, label, component: 'Switch' as const, componentProps: { class: 'w-auto' } })),
  ],
});
async function replace(data: SiteSettingsView) {
  view.value = data;
  await form.setValues({
    name: data.name, official_url: data.official_url, description: data.description, logo_url: data.logo_url, timezone: data.timezone,
    theme_mode: data.theme.mode, theme_color: data.theme.colorPrimary, theme_radius: data.theme.radius, layout_mode: data.preferences.layout,
    sidebar_collapsed: data.preferences.sidebar.collapsed, navigation_style: data.preferences.navigation.styleType, navigation_split: data.preferences.navigation.split,
    breadcrumb_enable: data.preferences.breadcrumb.enable, breadcrumb_show_icon: data.preferences.breadcrumb.showIcon, breadcrumb_style: data.preferences.breadcrumb.styleType,
    tabbar_enable: data.preferences.tabbar.enable, tabbar_style: data.preferences.tabbar.styleType, footer_enable: data.preferences.footer.enable, footer_fixed: data.preferences.footer.fixed,
  });
  // 初始模板不改变当前平台工作区的品牌与个人偏好。
  if (!defaults.value) { Object.assign(siteSettings, data); applySitePreferences(data); }
}
async function load() {
  if (loading.value || saving.value) return;
  loading.value = true; failure.value = '';
  try { await replace(await request<SiteSettingsView>(endpoint.value)); conflicted.value = false; }
  catch (error) { if (!isCanceled(error)) failure.value = errorText(error); }
  finally { loading.value = false; }
}
async function save() {
  if (!canManage.value || !view.value || saving.value || conflicted.value || !(await form.validate()).valid) return;
  saving.value = true; failure.value = '';
  try {
    const { _brand, _theme, _navigation, ...changes } = await form.getValues();
    await replace(await request<SiteSettingsView>(endpoint.value, { method: 'PUT', data: { version: view.value.version, changes } }));
    message.success('设置已保存');
  } catch (error) {
    if (!isCanceled(error)) {
      conflicted.value = error instanceof ApiError && error.status === 409;
      failure.value = conflicted.value ? '设置已被其他人修改，当前输入已保留。请记录需要保留的内容，再刷新最新设置。' : errorText(error);
    }
  } finally { saving.value = false; }
}
onMounted(load);
</script>
<template>
  <Page :title="title" header-class="flex-wrap gap-4" :description="defaults ? '主域名公共登录页及新客户的初始配置；已开通客户独立维护自己的设置。' : '维护当前端品牌与默认界面，个人显式覆盖继续生效。'">
    <template #extra><Space><Button :loading="loading" :disabled="saving" @click="load">刷新</Button><Button v-if="canManage" type="primary" :loading="saving" :disabled="loading || !view || conflicted" @click="save">保存设置</Button></Space></template>
    <Alert v-if="failure" :message="failure" type="error" class="mb-4" show-icon />
    <Spin :spinning="loading"><Card :bordered="false"><Form v-if="view" :common-config="{ disabled: !canManage || loading || saving, componentProps: { class: 'w-full' } }" /><Empty v-else-if="!loading" description="暂无可读取的站点配置"><Button @click="load">重新读取</Button></Empty></Card></Spin>
  </Page>
</template>
