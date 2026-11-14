import type { PublicSiteSettings } from './api/contracts';

import { expect, it, vi } from 'vitest';

vi.mock('../../../packages/@core/preferences/src/update-css-variables', () => ({ updateCSSVariables: vi.fn() }));

import { preferences, resetPreferences, updatePreferences } from '@vben/preferences';
import { applySitePreferences, configurePreferences, switchPreferencesScope } from './preferences';

it('个人差量按 Tenant、账号和操作者隔离，未覆盖字段跟随最新端默认值', async () => {
  const entries = new Map<string, string>();
  vi.stubGlobal('document', { documentElement: { classList: { toggle: vi.fn() } } });
  vi.stubGlobal('localStorage', {
    getItem: (key: string) => entries.get(key) ?? null,
    setItem: (key: string, value: string) => { entries.set(key, value); },
    removeItem: (key: string) => { entries.delete(key); },
  });
  const site: PublicSiteSettings = {
    environment: 'dev', name: '测试客户', description: '', logo_url: '', official_url: 'https://example.test', timezone: 'Asia/Shanghai',
    theme: { mode: 'light', colorPrimary: '#1677ff', radius: '0.5' },
    preferences: { layout: 'sidebar-nav', sidebar: { collapsed: false }, navigation: { styleType: 'rounded', split: true }, breadcrumb: { enable: true, showIcon: true, styleType: 'normal' }, tabbar: { enable: true, styleType: 'chrome' }, footer: { enable: false, fixed: false } },
  };
  try {
    configurePreferences(site);
    switchPreferencesScope('system', 'user-a', false, '', 'tenant-a');
    updatePreferences({ theme: { mode: 'dark' } });
    expect(JSON.parse(entries.get('typeapp.preferences.system.tenant-a.user-a.self')!)).toEqual({ theme: { mode: 'dark' } });
    applySitePreferences({ ...site, theme: { ...site.theme, colorPrimary: '#ff0000' } });
    expect(preferences.theme.mode).toBe('dark');
    expect(preferences.theme.colorPrimary).toBe('#ff0000');
    for (const [tenant, user, simulated, actor] of [['tenant-b', 'user-a', false, ''], ['tenant-a', 'user-b', false, ''], ['tenant-a', 'user-a', true, 'operator-a']] as const) {
      switchPreferencesScope('system', user, simulated, actor, tenant);
      expect(preferences.theme.mode).toBe('light');
    }
    switchPreferencesScope('system', 'user-a', false, '', 'tenant-a');
    expect(preferences.theme.mode).toBe('dark');
    entries.set('typeapp.preferences.system.tenant-a.user-a.self', JSON.stringify({ app: { name: '伪造品牌', timezone: 'Invalid/Timezone' }, copyright: { companyName: '伪造归属' }, widget: { languageToggle: true, notification: true }, theme: { mode: 'dark' } }));
    applySitePreferences(site);
    expect(preferences.app.name).toBe(site.name);
    expect(preferences.copyright.companyName).toBe(site.name);
    expect(preferences.app.timezone).toBe(site.timezone);
    expect(preferences.widget.languageToggle).toBe(true);
    expect(preferences.widget.notification).toBe(true);
    await resetPreferences();
    expect(preferences.theme.mode).toBe('light');
    expect(entries.has('typeapp.preferences.system.tenant-a.user-a.self')).toBe(false);
  } finally { vi.unstubAllGlobals(); }
});
