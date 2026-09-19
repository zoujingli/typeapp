import { initPreferences } from '@vben/preferences';
import { loadSiteSettings, siteSettings } from './api';
import { configurePreferences, sitePreferenceOverrides } from './preferences';

await loadSiteSettings().catch(() => siteSettings);
await initPreferences({
  namespace: 'typeapp-iot-v1',
  overrides: sitePreferenceOverrides(siteSettings),
});
configurePreferences(siteSettings);
const { bootstrap } = await import('./bootstrap');
await bootstrap();
