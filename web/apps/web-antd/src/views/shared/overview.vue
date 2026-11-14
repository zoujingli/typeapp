<script setup lang="ts">
import type {
  AnalysisOverviewItem,
  WorkbenchProjectItem,
  WorkbenchQuickNavItem,
  WorkbenchTodoItem,
  WorkbenchTrendItem,
} from '@vben/common-ui';
import type { Page as PageResult } from '../../api/contracts';
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import {
  AnalysisOverview,
  Page,
  VbenButton,
  WorkbenchHeader,
  WorkbenchProject,
  WorkbenchQuickNav,
  WorkbenchTodo,
  WorkbenchTrends,
} from '@vben/common-ui';
import { errorText, isCanceled, request, session, siteSettings } from '../../api/identity';

interface PlatformOperations { nodes: { state: string }[]; health: { http_alive: boolean; durable_store_ready: boolean | null } }
interface Metric { key: string; label: string; value: number | string; description: string; icon: string }
interface QuickLink { label: string; path: string; permission: string; icon: string; color: string }

const busy = ref(false);
const failure = ref('');
const metrics = ref<Metric[]>([]);
const router = useRouter();
const realm = computed(() => session.realm);
const title = computed(() => realm.value === 'platform' ? '平台工作台' : '系统工作台');
const description = computed(() => realm.value === 'platform' ? '平台客户、账号与运行状态概览。' : '当前客户的设备、应用与注册资源概览。');
const greeting = computed(() => `你好，${session.user?.name || session.user?.login || '管理员'}，欢迎使用${realm.value === 'platform' ? '平台管理' : '客户系统'}。`);
const identityDescription = computed(() => session.tenant ? `当前客户：${session.tenant.name}` : '当前身份域：平台管理');
const quickLinks = computed<QuickLink[]>(() => realm.value === 'platform' ? [
  { label: '客户管理', path: '/platform/tenants', permission: 'platform.tenants.read', icon: 'lucide:building-2', color: '#1677ff' },
  { label: '平台用户', path: '/platform/users', permission: 'platform.users.read', icon: 'lucide:users', color: '#13c2c2' },
  { label: '运行概览', path: '/platform/operations', permission: 'platform.operations.read', icon: 'lucide:activity', color: '#52c41a' },
  { label: 'MQTT 资源', path: '/platform/broker', permission: 'platform.broker.read', icon: 'lucide:network', color: '#722ed1' },
] : [
  { label: '设备管理', path: '/center/devices', permission: 'center.devices.read', icon: 'lucide:cpu', color: '#1677ff' },
  { label: '接入应用', path: '/center/applications', permission: 'center.applications.read', icon: 'lucide:app-window', color: '#13c2c2' },
  { label: '注册资格', path: '/center/enrollments', permission: 'center.enrollments.read', icon: 'lucide:badge-check', color: '#52c41a' },
  { label: '连接观察', path: '/center/connections', permission: 'center.connections.read', icon: 'lucide:plug-zap', color: '#722ed1' },
]);
const visibleLinks = computed(() => quickLinks.value.filter(link => session.permissions.includes(link.permission)));
const metricIcon = (key: string): string => ({ tenants: 'lucide:building-2', users: 'lucide:users', nodes: 'lucide:activity', products: 'lucide:boxes', devices: 'lucide:cpu', applications: 'lucide:app-window', enrollments: 'lucide:badge-check' }[key] || 'lucide:bar-chart-3');
const overviewItems = computed<AnalysisOverviewItem[]>(() => metrics.value.map(metric => ({ icon: metric.icon, title: metric.label, totalTitle: '当前范围', totalValue: typeof metric.value === 'number' ? metric.value : 0, value: typeof metric.value === 'number' ? metric.value : 0 })));
const headerStats = computed(() => metrics.value.slice(0, 3).map(metric => ({ label: metric.label, value: metric.value })));
const projectItems = computed<WorkbenchProjectItem[]>(() => metrics.value.map(metric => ({ color: '#1677ff', content: `${metric.value} · ${metric.description}`, date: '实时', group: realm.value === 'platform' ? '平台数据' : '客户数据', icon: metric.icon, title: metric.label })));
const quickNavItems = computed<WorkbenchQuickNavItem[]>(() => visibleLinks.value.map(link => ({ color: link.color, icon: link.icon, title: link.label, url: link.path })));
const todoItems = computed<WorkbenchTodoItem[]>(() => [{ completed: !busy.value && !failure.value, content: busy.value ? '正在读取当前权限范围的数据' : failure.value ? '部分数据未完成，请查看上方提示并重试' : '当前权限范围的数据已完成读取', date: '刚刚', title: '工作台数据读取' }]);
const trendItems = computed<WorkbenchTrendItem[]>(() => metrics.value.map(metric => ({ avatar: metric.icon, content: `${metric.description}：<strong>${metric.value}</strong>`, date: '刚刚', title: metric.label })));

async function load() {
  if (busy.value) return;
  busy.value = true; failure.value = ''; metrics.value = [];
  const failures: string[] = [];
  try {
    if (realm.value === 'platform') {
      const jobs: Promise<unknown>[] = [];
      if (session.permissions.includes('platform.tenants.read')) jobs.push(request<PageResult<unknown>>('/platform/tenants', { params: { page: 1, per_page: 1 } }).then(data => metrics.value.push({ key: 'tenants', label: '客户数量', value: data.total, description: '当前平台已登记客户', icon: metricIcon('tenants') })).catch(error => { if (!isCanceled(error)) failures.push(errorText(error)); }));
      if (session.permissions.includes('platform.users.read')) jobs.push(request<PageResult<unknown>>('/platform/users', { params: { page: 1, per_page: 1 } }).then(data => metrics.value.push({ key: 'users', label: '平台用户', value: data.total, description: '当前平台账号总数', icon: metricIcon('users') })).catch(error => { if (!isCanceled(error)) failures.push(errorText(error)); }));
      if (session.permissions.includes('platform.operations.read')) jobs.push(request<PlatformOperations>('/platform/operations').then(data => metrics.value.push({ key: 'nodes', label: '运行节点', value: data.nodes.length, description: data.health.http_alive ? 'HTTP 已响应' : 'HTTP 状态未知', icon: metricIcon('nodes') })).catch(error => { if (!isCanceled(error)) failures.push(errorText(error)); }));
      await Promise.all(jobs);
    } else {
      const jobs: Promise<unknown>[] = [];
      if (session.permissions.includes('center.products.read')) jobs.push(request<PageResult<unknown>>('/center/products', { params: { page: 1, per_page: 1 } }).then(data => metrics.value.push({ key: 'products', label: '产品分类', value: data.total, description: '当前客户产品分类', icon: metricIcon('products') })).catch(error => { if (!isCanceled(error)) failures.push(errorText(error)); }));
      if (session.permissions.includes('center.devices.read')) jobs.push(request<PageResult<unknown>>('/center/devices', { params: { page: 1, per_page: 1 } }).then(data => metrics.value.push({ key: 'devices', label: '设备数量', value: data.total, description: '当前客户设备目录', icon: metricIcon('devices') })).catch(error => { if (!isCanceled(error)) failures.push(errorText(error)); }));
      if (session.permissions.includes('center.applications.read')) jobs.push(request<PageResult<unknown>>('/center/applications', { params: { page: 1, per_page: 1 } }).then(data => metrics.value.push({ key: 'applications', label: '接入应用', value: data.total, description: '当前客户接入应用', icon: metricIcon('applications') })).catch(error => { if (!isCanceled(error)) failures.push(errorText(error)); }));
      if (session.permissions.includes('center.enrollments.read')) jobs.push(request<PageResult<unknown>>('/center/enrollments', { params: { page: 1, per_page: 1 } }).then(data => metrics.value.push({ key: 'enrollments', label: '注册资格', value: data.total, description: '当前客户注册资格', icon: metricIcon('enrollments') })).catch(error => { if (!isCanceled(error)) failures.push(errorText(error)); }));
      await Promise.all(jobs);
    }
    const metricOrder = realm.value === 'platform'
      ? ['tenants', 'users', 'nodes']
      : ['products', 'devices', 'applications', 'enrollments'];
    metrics.value.sort((left, right) => metricOrder.indexOf(left.key) - metricOrder.indexOf(right.key));
    if (failures.length) failure.value = failures[0] || '请求未完成，请稍后重试。';
  } finally { busy.value = false; }
}
function navigate(path?: string) { if (path && path.startsWith('/')) void router.push(path); }
function openQuickLink(item: WorkbenchQuickNavItem) { navigate(item.url); }
onMounted(load);
</script>

<template>
  <Page auto-content-height :title="title" :description="description" header-class="flex-wrap gap-4">
    <template #extra><VbenButton :loading="busy" @click="load">刷新</VbenButton></template>
    <div class="flex flex-col gap-4">
      <WorkbenchHeader :avatar="siteSettings.logo_url" :stats="headerStats"><template #title>{{ greeting }}</template><template #description>{{ identityDescription }}</template></WorkbenchHeader>
      <div v-if="failure" class="rounded-md border border-warning/30 bg-warning/5 p-3 text-sm text-warning" role="alert"><strong>部分概览数据读取失败</strong><span class="ml-2">{{ failure }}</span></div>
      <AnalysisOverview v-if="overviewItems.length" :items="overviewItems" />
      <div v-else-if="!busy" class="rounded-md border border-border p-8 text-center text-muted-foreground">暂无可读取的概览数据</div>
      <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <WorkbenchQuickNav v-if="visibleLinks.length" title="常用入口" :items="quickNavItems" @click="openQuickLink" />
        <div v-else class="rounded-md border border-border p-6 text-center text-sm text-muted-foreground">当前账号暂无可用快捷入口</div>
        <WorkbenchTodo title="工作台状态" :items="todoItems" readonly />
      </div>
      <div v-if="metrics.length" class="grid grid-cols-1 gap-4 xl:grid-cols-2"><WorkbenchProject title="数据概览" :items="projectItems" /><WorkbenchTrends title="实时指标" :items="trendItems" /></div>
      <div v-if="busy" class="text-center text-sm text-muted-foreground" role="status">正在刷新工作台数据…</div>
    </div>
  </Page>
</template>
