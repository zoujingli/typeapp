<script setup lang="ts">
import { computed, ref, watch, watchEffect } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { Menu } from '../api/contracts';
import { BasicLayout, GlobalSearch, LockScreen, LockScreenModal, UserDropdown } from '@vben/layouts';
import { useVbenModal, VbenFullScreen, VbenIconButton } from '@vben/common-ui';
import { LockKeyhole } from '@vben/icons';
import { preferences } from '@vben/preferences';
import { useAccessStore } from '@vben/stores';
import { Alert, Button, Space, message } from 'ant-design-vue';
import { errorText, isDemo, logout, session, sessionExitPath } from '../api/identity';
import { lockScreen, verifyScreenPassword } from '../screen-lock';
const router = useRouter(); const route = useRoute(); const access = useAccessStore(); const leaving = ref(false);
const userMenus = computed(() => [{ text: '个人中心', icon: 'lucide:user', handler: () => router.push(`/${session.realm}/profile`) }]);
const searchKey = computed(() => `typeapp.search.${session.realm}.${session.tenant?.id || 'platform'}.${session.user?.id || 'anonymous'}.${session.impersonating ? session.identity?.actor_id : 'self'}`);
const searchMenus = computed(() => {
  const leaves = (menus: Menu[]): Menu[] => menus.flatMap(menu => menu.children?.length ? leaves(menu.children) : [menu]);
  return leaves(session.menus);
});
const [LockModal, lockModal] = useVbenModal({ connectedComponent: LockScreenModal });
async function lock(password: string) {
  try { if (await lockScreen(password)) { lockModal.close(); return true; } }
  catch { message.error('锁屏失败，请重试。'); }
  return false;
}
const mqttSections = [['', '资源'], ['/access', '授权'], ['/quotas', '配额'], ['/runtime', '配置'], ['/debug', '调试'], ['/nodes', '节点']];
watchEffect(() => { access.setAccessMenus(session.menus); access.setAccessCodes(session.permissions); });
watch(() => session.token, token => { if (!token) void router.replace(sessionExitPath()); });
async function leave() {
  if (leaving.value) return;
  leaving.value = true;
  try { await logout(); await router.replace(sessionExitPath()); }
  catch (error) { message.error(errorText(error)); }
  finally { leaving.value = false; }
}
</script>
<template>
  <BasicLayout @clear-preferences-and-logout="leave" @click-logo="router.push(preferences.app.defaultHomePath)">
    <template #content-before>
      <Alert v-if="isDemo()" type="info" show-icon message="演示环境，仅供浏览" class="m-3" />
      <Alert v-if="session.impersonating" type="warning" show-icon class="m-3" role="status">
        <template #message>正在模拟 {{ session.user?.name }}</template>
        <template #description><Space wrap><span>操作者：{{ session.identity?.actor_name }} · 客户：{{ session.tenant?.name }}</span><Button :loading="leaving" @click="leave">退出模拟</Button></Space></template>
      </Alert>
      <Space v-if="route.path.startsWith('/platform/broker')" wrap class="m-4"><Button v-for="[path, name] in mqttSections" :key="path" :type="route.path === '/platform/broker' + path ? 'primary' : 'default'" @click="router.push('/platform/broker' + path)">{{ name }}</Button></Space>
    </template>
    <template #header-left-60><span class="px-3">{{ session.realm === 'platform' ? '平台管理' : session.tenant?.name }}</span></template>
    <template #global-search><GlobalSearch :key="searchKey" :history-key="searchKey" :menus="searchMenus" :enable-shortcut-key="preferences.shortcutKeys.enable && preferences.shortcutKeys.globalSearch" class="mr-1 sm:mr-4" /></template>
    <template #fullscreen><VbenFullScreen tooltip="全屏切换" aria-label="全屏切换" class="mr-1" /></template>
    <template v-if="preferences.widget.lockScreen" #header-right-175><VbenIconButton tooltip="锁定屏幕" aria-label="锁定屏幕" class="mr-1" @click="lockModal.open()"><LockKeyhole class="size-4" /></VbenIconButton></template>
    <template #user-dropdown><UserDropdown :text="session.user?.name" :description="session.user?.login" :menus="userMenus" :lock-screen="lock" @logout="leave" @clear-preferences-and-logout="leave" /></template>
    <template #lock-screen><LockScreen :verify-password="verifyScreenPassword" :timezone="preferences.app.timezone" @to-login="leave" /></template>
    <template #extra><LockModal :text="session.user?.name" @submit="lock" /></template>
  </BasicLayout>
</template>
