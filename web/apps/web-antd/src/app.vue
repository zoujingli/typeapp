<script setup lang="ts">
import { computed, watchEffect } from 'vue';
import { useRoute } from 'vue-router';
import { useAntdDesignTokens } from '@vben/hooks';
import { usePreferences } from '@vben/preferences';
import { App, ConfigProvider, theme } from 'ant-design-vue';
import zhCN from 'ant-design-vue/es/locale/zh_CN';
import { siteSettings } from './api';

const { isDark } = usePreferences();
const { tokens } = useAntdDesignTokens();
const tokenTheme = computed(() => ({ token: tokens, algorithm: isDark.value ? theme.darkAlgorithm : theme.defaultAlgorithm }));
const route = useRoute();

// 路由与品牌变化共用标题入口，备案名称始终保留在末尾。
watchEffect(() => {
  document.title = [route.meta.title, siteSettings.name, '物联开源分享'].filter(Boolean).join(' · ');
});
</script>

<template>
  <ConfigProvider :locale="zhCN" :theme="tokenTheme"><App><RouterView /></App></ConfigProvider>
</template>
