import type { Preferences } from '@vben/preferences';
import type { PublicSiteSettings } from './api';
import { preferences, resetPreferences, updatePreferences } from '@vben/preferences';
import { watch } from 'vue';

type PreferenceScope = 'anonymous' | `${string}-${string}` | `customer-impersonation-${string}-${string}`;
type PreferencePatch = { [K in keyof Preferences]?: Partial<Preferences[K]> };

let activeScope: PreferenceScope = 'anonymous';
let defaults: PreferencePatch = {};
let started = false;
let switching = false;

function cacheKey(scope: PreferenceScope): string {
  return `typeapp-iot-v1-scope-${scope}-preferences`;
}

function readCache(scope: PreferenceScope): Partial<Preferences> | null {
  const raw = localStorage.getItem(cacheKey(scope));
  if (!raw) return null;
  try {
    const value = JSON.parse(raw) as { value?: Partial<Preferences> };
    return value.value && typeof value.value === 'object' ? value.value : null;
  } catch {
    localStorage.removeItem(cacheKey(scope));
    return null;
  }
}

function writeCache(scope: PreferenceScope) {
  localStorage.setItem(cacheKey(scope), JSON.stringify({ value: JSON.parse(JSON.stringify(preferences)) }));
}

/** 个人缓存只影响展示习惯，站点名称、入口和授权模式以当前站点默认值为准。 */
function withoutBrandFields(value: Partial<Preferences>): Partial<Preferences> {
  const result = JSON.parse(JSON.stringify(value)) as PreferencePatch;
  const app = result.app as Partial<Preferences['app']> | undefined;
  if (app) {
    app.name = undefined;
    app.defaultHomePath = undefined;
    app.accessMode = undefined;
  }
  result.logo = undefined;
  result.copyright = undefined;
  return result as Partial<Preferences>;
}

function restore(scope: PreferenceScope) {
  resetPreferences();
  updatePreferences(defaults);
  const stored = readCache(scope);
  if (stored) updatePreferences(withoutBrandFields(stored));
}

/** 将站点配置映射到 Vben 已接通的原生偏好字段，品牌字段不允许被个人缓存覆盖。 */
export function sitePreferenceOverrides(site: PublicSiteSettings): PreferencePatch {
  return {
    app: { name: site.name, defaultHomePath: '/profile', accessMode: 'frontend', locale: 'zh-CN', layout: site.preferences.layout },
    logo: { enable: site.logo_url !== '', source: site.logo_url },
    copyright: { enable: site.preferences.footer.enable, companyName: site.name, companySiteLink: site.official_url },
    theme: { mode: site.theme.mode, colorPrimary: site.theme.colorPrimary, radius: site.theme.radius },
    sidebar: { collapsed: site.preferences.sidebar.collapsed },
    navigation: { styleType: site.preferences.navigation.styleType, split: site.preferences.navigation.split },
    breadcrumb: { enable: site.preferences.breadcrumb.enable, showIcon: site.preferences.breadcrumb.showIcon, styleType: site.preferences.breadcrumb.styleType },
    tabbar: { enable: site.preferences.tabbar.enable, styleType: site.preferences.tabbar.styleType },
    footer: { enable: site.preferences.footer.enable, fixed: site.preferences.footer.fixed },
    widget: { globalSearch: false, notification: false, languageToggle: false, lockScreen: false, fullscreen: false, timezone: false, refresh: false },
  };
}

/** 初始化站点默认值并开始按账号、身份域保存 Vben 原生个人偏好。 */
export function configurePreferences(site: PublicSiteSettings) {
  defaults = sitePreferenceOverrides(site);
  updatePreferences(defaults);
  if (started) return;
  started = true;
  watch(preferences, () => {
    if (!switching) writeCache(activeScope);
  }, { deep: true });
  restore(activeScope);
}

/** 站点设置更新后立即应用新默认值，个人已明确选择的字段仍保留。 */
export function applySitePreferences(site: PublicSiteSettings) {
  const previous = defaults;
  defaults = sitePreferenceOverrides(site);
  const patch = JSON.parse(JSON.stringify(defaults)) as PreferencePatch;
  const preserve = (section: keyof Preferences, field: string) => {
    const current = preferences[section] as unknown as Record<string, unknown>;
    const oldDefaults = previous[section] as unknown as Record<string, unknown> | undefined;
    if (oldDefaults && current[field] !== oldDefaults[field]) {
      const next = patch[section] as unknown as Record<string, unknown> | undefined;
      if (next) delete next[field];
    }
  };
  for (const field of ['layout']) preserve('app', field);
  for (const field of ['mode', 'colorPrimary', 'radius']) preserve('theme', field);
  for (const field of ['collapsed']) preserve('sidebar', field);
  for (const field of ['styleType', 'split']) preserve('navigation', field);
  for (const field of ['enable', 'showIcon', 'styleType']) preserve('breadcrumb', field);
  for (const field of ['enable', 'styleType']) preserve('tabbar', field);
  for (const field of ['enable', 'fixed']) preserve('footer', field);
  updatePreferences(patch);
}

/** 切换登录域或账号时隔离个人缓存，模拟登录再增加独立来源键。 */
export function switchPreferencesScope(realm: string = '', userId: string = '', impersonating = false, actorId = '') {
  if (!started) return;
  const next: PreferenceScope = !userId ? 'anonymous' : impersonating
    ? `customer-impersonation-${actorId || 'unknown'}-${userId}`
    : `${realm}-${userId}`;
  if (next === activeScope) return;
  writeCache(activeScope);
  // 恢复另一身份偏好时抑制持久化监听，防止中间默认值覆盖目标缓存。
  switching = true;
  activeScope = next;
  restore(activeScope);
  switching = false;
}

/** 清除当前账号个人覆盖，只恢复最近取得的站点默认值，不影响身份和业务数据。 */
export function resetScopedPreferences() {
  localStorage.removeItem(cacheKey(activeScope));
  switching = true;
  restore(activeScope);
  switching = false;
}
