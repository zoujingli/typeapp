<script setup lang="ts">
import { computed, watch, watchEffect } from 'vue';
import { useRoute } from 'vue-router';
import { useAntdDesignTokens, useWatermark } from '@vben/hooks';
import { providePreferencesPolicy } from '@vben/layouts';
import { preferences, usePreferences } from '@vben/preferences';
import { App, ConfigProvider, theme } from 'ant-design-vue';
import zhCN from 'ant-design-vue/es/locale/zh_CN';
import { session, siteSettings } from './api/identity';
import { isPersonalPreference } from './preferences';

providePreferencesPolicy(isPersonalPreference);

const { isDark } = usePreferences();
const { tokens } = useAntdDesignTokens();
const tokenTheme = computed(() => ({ token: tokens, algorithm: isDark.value ? theme.darkAlgorithm : theme.defaultAlgorithm }));
const route = useRoute();
const { destroyWatermark, updateWatermark } = useWatermark();
let watermarkRevision = 0;
let watermarkWork = Promise.resolve();
watch([() => session.identity?.key, () => session.token, () => session.user?.name, () => session.tenant?.name, () => siteSettings.name, () => preferences.app.watermark, () => preferences.app.watermarkContent], () => {
  const revision = ++watermarkRevision;
  const content = session.token && session.user && preferences.app.watermark
    ? preferences.app.watermarkContent || `${session.tenant?.name || siteSettings.name} · ${session.user.name}` : '';
  watermarkWork = watermarkWork.then(async () => {
    if (revision !== watermarkRevision) return;
    destroyWatermark();
    if (content) await updateWatermark({ content });
    if (revision !== watermarkRevision) destroyWatermark();
  }).catch(() => { destroyWatermark(); });
});

// 路由与品牌变化共用标题入口，备案名称始终保留在末尾。
watchEffect(() => {
  document.title = [preferences.app.dynamicTitle ? route.meta.title : '', siteSettings.name].filter(Boolean).join(' · ');
});
</script>

<template>
  <ConfigProvider :locale="zhCN" :theme="tokenTheme"><App><RouterView :key="route.meta.public ? 'auth' : `${session.realm}:${session.tenant?.id}:${session.user?.id}:${session.impersonating}:${session.permissions.join(',')}`" /></App></ConfigProvider>
</template>
