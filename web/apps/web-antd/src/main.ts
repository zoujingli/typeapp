import { initPreferences } from '@vben/preferences';
import { loadSiteSettings, siteSettings } from './api/identity';
import { configurePreferences, sitePreferenceOverrides } from './preferences';

// 品牌默认值先于应用挂载；公开设置不可用时仍允许登录页启动。
await loadSiteSettings().catch(() => siteSettings);
await initPreferences({
  namespace: 'typeapp',
  persistence: false,
  overrides: sitePreferenceOverrides(siteSettings),
});
configurePreferences(siteSettings);
const { bootstrap } = await import('./bootstrap');
await bootstrap();
