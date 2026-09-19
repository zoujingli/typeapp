<script setup lang="ts">
import type { AdminSiteSettings } from '../api';
import { computed, onMounted, reactive, ref } from 'vue';
import { Alert, Button, Card, Col, Form, FormItem, Input, Row, Select, Space, Spin, Switch, message } from 'ant-design-vue';
import { applyAdminContext, clearAdminAccess, errorText, isCanceled, request, session, siteSettings } from '../api';
import { applySitePreferences } from '../preferences';

const view = ref<AdminSiteSettings>({
  name: 'TypeApp', official_url: 'https://iots.top', description: '物联中心管理平台', logo_url: '', timezone: 'Asia/Shanghai',
  theme: { mode: 'light', colorPrimary: '#1677ff', radius: '0.5' }, preferences: {
    layout: 'sidebar-nav', sidebar: { collapsed: false }, navigation: { styleType: 'rounded', split: true },
    breadcrumb: { enable: true, showIcon: true, styleType: 'normal' }, tabbar: { enable: false, styleType: 'chrome' }, footer: { enable: false, fixed: false },
  }, version: 1, updated_at: 0, permissions: [], catalog: {}, menus: [],
});
const form = reactive({
  name: view.value.name, official_url: view.value.official_url, description: view.value.description, logo_url: view.value.logo_url, timezone: view.value.timezone,
  mode: view.value.theme.mode, colorPrimary: view.value.theme.colorPrimary, radius: view.value.theme.radius,
  layout: view.value.preferences.layout, sidebarCollapsed: view.value.preferences.sidebar.collapsed,
  navigationStyle: view.value.preferences.navigation.styleType, navigationSplit: view.value.preferences.navigation.split,
  breadcrumbEnable: view.value.preferences.breadcrumb.enable, breadcrumbShowIcon: view.value.preferences.breadcrumb.showIcon, breadcrumbStyle: view.value.preferences.breadcrumb.styleType,
  tabbarEnable: view.value.preferences.tabbar.enable, tabbarStyle: view.value.preferences.tabbar.styleType,
  footerEnable: view.value.preferences.footer.enable, footerFixed: view.value.preferences.footer.fixed,
});
const loading = ref(false); const saving = ref(false); const failure = ref('');
const canManage = computed(() => session.permissions.includes('admin.site.manage'));

function replaceView(data: AdminSiteSettings) {
  view.value = data;
  Object.assign(siteSettings, data);
  applyAdminContext(data);
  Object.assign(form, {
    name: data.name, official_url: data.official_url, description: data.description, logo_url: data.logo_url, timezone: data.timezone,
    mode: data.theme.mode, colorPrimary: data.theme.colorPrimary, radius: data.theme.radius, layout: data.preferences.layout,
    sidebarCollapsed: data.preferences.sidebar.collapsed, navigationStyle: data.preferences.navigation.styleType, navigationSplit: data.preferences.navigation.split,
    breadcrumbEnable: data.preferences.breadcrumb.enable, breadcrumbShowIcon: data.preferences.breadcrumb.showIcon, breadcrumbStyle: data.preferences.breadcrumb.styleType,
    tabbarEnable: data.preferences.tabbar.enable, tabbarStyle: data.preferences.tabbar.styleType, footerEnable: data.preferences.footer.enable, footerFixed: data.preferences.footer.fixed,
  });
}

async function load() {
  loading.value = true; failure.value = '';
  try { replaceView(await request<AdminSiteSettings>('/admin/site')); }
  catch (error) { if (!isCanceled(error)) { failure.value = errorText(error); clearAdminAccess(error); } }
  finally { loading.value = false; }
}

async function save() {
  if (saving.value || !canManage.value) return;
  saving.value = true; failure.value = '';
  try {
    const result = await request<AdminSiteSettings>('/admin/site', { method: 'PUT', data: { version: view.value.version, changes: {
      name: form.name, official_url: form.official_url, description: form.description, logo_url: form.logo_url, timezone: form.timezone,
      theme_mode: form.mode, theme_color: form.colorPrimary, theme_radius: form.radius, layout_mode: form.layout,
      sidebar_collapsed: form.sidebarCollapsed, navigation_style: form.navigationStyle, navigation_split: form.navigationSplit,
      breadcrumb_enable: form.breadcrumbEnable, breadcrumb_show_icon: form.breadcrumbShowIcon, breadcrumb_style: form.breadcrumbStyle,
      tabbar_enable: form.tabbarEnable, tabbar_style: form.tabbarStyle, footer_enable: form.footerEnable, footer_fixed: form.footerFixed,
    } } });
    replaceView(result);
    applySitePreferences(result);
    message.success('站点设置已保存');
  } catch (error) { if (!isCanceled(error)) { failure.value = errorText(error); clearAdminAccess(error); await (error instanceof Error && error.message.includes('记录已被其他人修改') ? load() : Promise.resolve()); } }
  finally { saving.value = false; }
}

onMounted(() => { void load(); });
</script>

<template>
  <section class="iot-page site-settings-page">
    <header class="page-heading">
      <div><h1>站点设置</h1><p class="muted">管理登录页品牌、官方网站和 Vben Admin Pro 主题默认值</p></div>
      <Space><Button :loading="loading" @click="load">刷新</Button><Button v-if="canManage" type="primary" :loading="saving" @click="save">保存设置</Button></Space>
    </header>
    <Alert v-if="failure" :message="failure" type="error" show-icon role="alert" class="page-alert" />
    <Spin :spinning="loading">
      <Card title="品牌信息" :bordered="false" class="settings-group">
        <Form layout="vertical"><Row :gutter="[20, 4]">
          <Col :xs="24" :lg="12"><FormItem label="项目名称"><Input v-model:value="form.name" :disabled="!canManage" :maxlength="100" /></FormItem></Col>
          <Col :xs="24" :lg="12"><FormItem label="官方网站"><Input v-model:value="form.official_url" :disabled="!canManage" :maxlength="512" /></FormItem></Col>
          <Col :xs="24" :lg="12"><FormItem label="Logo 地址"><Input v-model:value="form.logo_url" :disabled="!canManage" :maxlength="512" placeholder="可留空，使用文字品牌" /></FormItem></Col>
          <Col :xs="24"><FormItem label="站点说明"><Input.TextArea v-model:value="form.description" :disabled="!canManage" :maxlength="500" :rows="3" /></FormItem></Col>
        </Row></Form>
      </Card>
      <Card title="界面主题与默认布局" :bordered="false" class="settings-group">
        <Form layout="vertical"><Row :gutter="[20, 4]">
          <Col :xs="24" :lg="8"><FormItem label="主题模式"><Select v-model:value="form.mode" :disabled="!canManage" :options="[{ label: '浅色', value: 'light' }, { label: '深色', value: 'dark' }, { label: '跟随系统', value: 'auto' }]" /></FormItem></Col>
          <Col :xs="24" :lg="8"><FormItem label="主题色"><Input v-model:value="form.colorPrimary" :disabled="!canManage" placeholder="#1677ff" /></FormItem></Col>
          <Col :xs="24" :lg="8"><FormItem label="圆角"><Select v-model:value="form.radius" :disabled="!canManage" :options="['0', '0.25', '0.5', '0.75', '1'].map(value => ({ label: value, value }))" /></FormItem></Col>
          <Col :xs="24" :lg="8"><FormItem label="默认时区"><Input v-model:value="form.timezone" :disabled="!canManage" :maxlength="64" /></FormItem></Col>
          <Col :xs="24" :lg="8"><FormItem label="布局"><Select v-model:value="form.layout" :disabled="!canManage" :options="[{ label: '侧栏导航', value: 'sidebar-nav' }, { label: '混合导航', value: 'mixed-nav' }, { label: '顶栏导航', value: 'header-nav' }]" /></FormItem></Col>
          <Col :xs="24" :lg="8"><FormItem label="导航样式"><Select v-model:value="form.navigationStyle" :disabled="!canManage" :options="[{ label: '圆润', value: 'rounded' }, { label: '朴素', value: 'plain' }]" /></FormItem></Col>
          <Col :xs="24" :lg="8"><FormItem label="标签页样式"><Select v-model:value="form.tabbarStyle" :disabled="!canManage" :options="[{ label: 'Chrome', value: 'chrome' }, { label: '轻快', value: 'brisk' }, { label: '卡片', value: 'card' }, { label: '朴素', value: 'plain' }]" /></FormItem></Col>
          <Col :xs="24" :lg="8"><FormItem label="侧栏默认折叠"><Switch v-model:checked="form.sidebarCollapsed" :disabled="!canManage" /></FormItem></Col>
          <Col :xs="24" :lg="8"><FormItem label="混合导航分割"><Switch v-model:checked="form.navigationSplit" :disabled="!canManage" /></FormItem></Col>
          <Col :xs="24" :lg="8"><FormItem label="标签页"><Switch v-model:checked="form.tabbarEnable" :disabled="!canManage" /></FormItem></Col>
          <Col :xs="24" :lg="8"><FormItem label="面包屑"><Switch v-model:checked="form.breadcrumbEnable" :disabled="!canManage" /></FormItem></Col>
          <Col :xs="24" :lg="8"><FormItem label="面包屑图标"><Switch v-model:checked="form.breadcrumbShowIcon" :disabled="!canManage" /></FormItem></Col>
          <Col :xs="24" :lg="8"><FormItem label="面包屑样式"><Select v-model:value="form.breadcrumbStyle" :disabled="!canManage" :options="[{ label: '标准', value: 'normal' }, { label: '背景', value: 'background' }]" /></FormItem></Col>
          <Col :xs="24" :lg="8"><FormItem label="页脚"><Switch v-model:checked="form.footerEnable" :disabled="!canManage" /></FormItem></Col>
          <Col :xs="24" :lg="8"><FormItem label="固定页脚"><Switch v-model:checked="form.footerFixed" :disabled="!canManage" /></FormItem></Col>
        </Row></Form>
      </Card>
    </Spin>
  </section>
</template>

<style scoped>
.settings-group { margin-bottom: 20px; }
</style>
