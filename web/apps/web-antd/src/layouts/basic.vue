<script setup lang="ts">
import { watch, watchEffect, ref } from 'vue';
import { useRouter } from 'vue-router';
import { BasicLayout } from '@vben/layouts';
import { useAccessStore } from '@vben/stores';
import { Alert, Button, message } from 'ant-design-vue';
import { errorText, logout, session, sessionExitPath } from '../api';

const router = useRouter();
const access = useAccessStore();
const leaving = ref(false);
watchEffect(() => {
  access.setAccessMenus(session.realm === 'broker'
    ? [{ name: 'Broker 管理', path: '/broker/operations', redirectPath: '/broker/nodes', icon: 'lucide:server', order: 1, children: [
      { name: 'Broker 节点', path: '/broker/nodes', icon: 'lucide:server', order: 1 },
      { name: 'Broker 资源', path: '/broker/resources', icon: 'lucide:network', order: 2 },
      { name: 'Broker 授权', path: '/broker/access', icon: 'lucide:key-round', order: 3 },
      { name: 'Broker 配额', path: '/broker/quotas', icon: 'lucide:gauge', order: 4 },
      { name: 'Broker 运行配置', path: '/broker/runtime', icon: 'lucide:sliders-horizontal', order: 5 },
      { name: 'Broker 调试订阅', path: '/broker/debug', icon: 'lucide:radio-tower', order: 6 },
      ...(session.user?.platform_admin ? [{ name: 'Broker 审计', path: '/broker/audit', icon: 'lucide:history', order: 7 }] : []),
    ] }]
    : session.menus);
});
watch(() => session.token, (token) => { if (!token) void router.replace(sessionExitPath()); });
async function leave() {
  if (leaving.value) return;
  leaving.value = true;
  try { await logout(); await router.replace(sessionExitPath()); } catch (error) { message.error(errorText(error)); } finally { leaving.value = false; }
}
</script>

<template>
  <BasicLayout @clear-preferences-and-logout="leave">
    <template #content-before>
      <Alert v-if="session.impersonating" class="impersonation-banner" type="warning" show-icon role="status">
        <template #message><strong>模拟登录中</strong></template>
        <template #description>
          <div class="impersonation-details"><span>真实管理人员：{{ session.identity?.actor_name || '正在核验' }} · 客户：{{ session.user?.name }}（{{ session.user?.login }}） · 当前租户：{{ session.tenant?.name || '尚未选择' }}</span><Button :loading="leaving" @click="leave">退出模拟</Button></div>
        </template>
      </Alert>
    </template>
    <template #header-left-60>
      <span class="px-3 current-tenant" :title="session.tenant?.name">{{ session.realm === 'broker' ? '独立 Broker' : session.realm === 'admin' ? '平台管理端' : session.tenant?.name || '客户工作区' }}</span>
    </template>
    <template #user-dropdown>
      <div class="user-actions">
        <span class="user-name" :title="session.user?.name">{{ session.user?.name }}</span>
        <Button type="text" :loading="leaving" @click="leave">{{ session.impersonating ? '退出模拟' : '退出登录' }}</Button>
      </div>
    </template>
  </BasicLayout>
</template>

<style scoped>
.impersonation-banner { position: sticky; top: var(--vben-header-height, 0px); z-index: 10; margin: 12px; }
.impersonation-details { display: flex; align-items: center; flex-wrap: wrap; gap: 8px 16px; }
.impersonation-details span { flex: 1 1 260px; overflow-wrap: anywhere; }
</style>
