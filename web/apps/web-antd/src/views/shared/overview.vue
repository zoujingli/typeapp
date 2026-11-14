<script setup lang="ts">
import type { Page as PageResult } from '../../api/contracts';
import { computed, onMounted, ref } from 'vue';
import { Alert, Button, Card, Empty, Space, Spin, Statistic, Tag } from 'ant-design-vue';
import { Page } from '@vben/common-ui';
import { errorText, isCanceled, request, session } from '../../api/identity';

interface PlatformOperations { nodes: { state: string }[]; health: { http_alive: boolean; durable_store_ready: boolean | null } }
interface Metric { key: string; label: string; value: number | string; description: string }
interface QuickLink { label: string; path: string; permission: string }

const busy = ref(false);
const failure = ref('');
const metrics = ref<Metric[]>([]);
const realm = computed(() => session.realm);
const title = computed(() => realm.value === 'platform' ? '平台工作台' : '系统工作台');
const description = computed(() => realm.value === 'platform' ? '平台客户、账号与运行状态概览。' : '当前客户的设备、应用与注册资源概览。');
const quickLinks = computed<QuickLink[]>(() => realm.value === 'platform' ? [
  { label: '客户管理', path: '/platform/tenants', permission: 'platform.tenants.read' },
  { label: '平台用户', path: '/platform/users', permission: 'platform.users.read' },
  { label: '运行概览', path: '/platform/operations', permission: 'platform.operations.read' },
  { label: 'MQTT 资源', path: '/platform/broker', permission: 'platform.broker.read' },
] : [
  { label: '设备管理', path: '/center/devices', permission: 'center.devices.read' },
  { label: '接入应用', path: '/center/applications', permission: 'center.applications.read' },
  { label: '注册资格', path: '/center/enrollments', permission: 'center.enrollments.read' },
  { label: '连接观察', path: '/center/connections', permission: 'center.connections.read' },
]);
const visibleLinks = computed(() => quickLinks.value.filter(link => session.permissions.includes(link.permission)));

async function load() {
  if (busy.value) return;
  busy.value = true; failure.value = ''; metrics.value = [];
  const failures: string[] = [];
  try {
    if (realm.value === 'platform') {
      const jobs: Promise<unknown>[] = [];
      if (session.permissions.includes('platform.tenants.read')) jobs.push(request<PageResult<unknown>>('/platform/tenants', { params: { page: 1, per_page: 1 } }).then(data => metrics.value.push({ key: 'tenants', label: '客户数量', value: data.total, description: '当前平台已登记客户' })).catch(error => { if (!isCanceled(error)) failures.push(errorText(error)); }));
      if (session.permissions.includes('platform.users.read')) jobs.push(request<PageResult<unknown>>('/platform/users', { params: { page: 1, per_page: 1 } }).then(data => metrics.value.push({ key: 'users', label: '平台用户', value: data.total, description: '当前平台账号总数' })).catch(error => { if (!isCanceled(error)) failures.push(errorText(error)); }));
      if (session.permissions.includes('platform.operations.read')) jobs.push(request<PlatformOperations>('/platform/operations').then(data => metrics.value.push({ key: 'nodes', label: '运行节点', value: data.nodes.length, description: data.health.http_alive ? 'HTTP 已响应' : 'HTTP 状态未知' })).catch(error => { if (!isCanceled(error)) failures.push(errorText(error)); }));
      await Promise.all(jobs);
    } else {
      const jobs: Promise<unknown>[] = [];
      if (session.permissions.includes('center.products.read')) jobs.push(request<PageResult<unknown>>('/center/products', { params: { page: 1, per_page: 1 } }).then(data => metrics.value.push({ key: 'products', label: '产品分类', value: data.total, description: '当前客户产品分类' })).catch(error => { if (!isCanceled(error)) failures.push(errorText(error)); }));
      if (session.permissions.includes('center.devices.read')) jobs.push(request<PageResult<unknown>>('/center/devices', { params: { page: 1, per_page: 1 } }).then(data => metrics.value.push({ key: 'devices', label: '设备数量', value: data.total, description: '当前客户设备目录' })).catch(error => { if (!isCanceled(error)) failures.push(errorText(error)); }));
      if (session.permissions.includes('center.applications.read')) jobs.push(request<PageResult<unknown>>('/center/applications', { params: { page: 1, per_page: 1 } }).then(data => metrics.value.push({ key: 'applications', label: '接入应用', value: data.total, description: '当前客户接入应用' })).catch(error => { if (!isCanceled(error)) failures.push(errorText(error)); }));
      if (session.permissions.includes('center.enrollments.read')) jobs.push(request<PageResult<unknown>>('/center/enrollments', { params: { page: 1, per_page: 1 } }).then(data => metrics.value.push({ key: 'enrollments', label: '注册资格', value: data.total, description: '当前客户注册资格' })).catch(error => { if (!isCanceled(error)) failures.push(errorText(error)); }));
      await Promise.all(jobs);
    }
    if (failures.length) failure.value = failures[0] || '请求未完成，请稍后重试。';
  } finally { busy.value = false; }
}

onMounted(load);
</script>

<template>
  <Page auto-content-height :title="title" :description="description" header-class="flex-wrap gap-4">
    <template #extra><Button :loading="busy" @click="load">刷新</Button></template>
    <Alert v-if="failure" type="warning" show-icon class="mb-4" message="部分概览数据读取失败" :description="failure" />
    <Spin :spinning="busy && !metrics.length">
      <div v-if="metrics.length" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Card v-for="metric in metrics" :key="metric.key" :bordered="false">
          <Statistic :title="metric.label" :value="metric.value" />
          <p class="mt-2 text-sm text-muted-foreground">{{ metric.description }}</p>
        </Card>
      </div>
      <Empty v-else-if="!busy" description="暂无可读取的概览数据" />
      <Card title="常用入口" :bordered="false" class="mt-4">
        <Space wrap><Button v-for="link in visibleLinks" :key="link.path" @click="$router.push(link.path)">{{ link.label }}</Button><Tag v-if="!visibleLinks.length" color="default">当前账号暂无可用快捷入口</Tag></Space>
      </Card>
      <Card title="当前身份" :bordered="false" class="mt-4">
        <p class="mb-1">账号：{{ session.user?.name }}（{{ session.user?.login }}）</p>
        <p v-if="session.tenant" class="mb-0 text-sm text-muted-foreground">客户：{{ session.tenant.name }}</p>
        <p v-else class="mb-0 text-sm text-muted-foreground">身份域：平台管理</p>
      </Card>
    </Spin>
  </Page>
</template>
