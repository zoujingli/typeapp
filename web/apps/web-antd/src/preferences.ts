import type { Preferences } from '@vben/preferences';
import type { PublicSiteSettings } from './api/contracts';
import { preferences, preferencesManager, resetPreferences, updatePreferences } from '@vben/preferences';
import { watch } from 'vue';

type PreferencePatch = { [K in keyof Preferences]?: Partial<Preferences[K]> };
type JsonRecord = Record<string, unknown>;
let activeScope = 'anonymous';
let activeHomePath = '/system/overview';
let defaults: PreferencePatch = {};
let baseline: JsonRecord = {};
let started = false;
let switching = false;
const clone = <T>(value: T): T => JSON.parse(JSON.stringify(value));
const cacheKey = () => `typeapp.preferences.${activeScope}`;

/** 品牌与身份由当前端管理；工具栏行为由 Vben 原生组件负责。 */
export function isPersonalPreference(path: string): boolean {
  const [section, field] = path.split('.');
  if (!section || !field) return false;
  if (section === 'app') return ['layout', 'timezone', 'colorWeakMode', 'colorGrayMode', 'contentCompact', 'contentCompactWidth', 'preferencesButtonPosition', 'dynamicTitle', 'watermark', 'watermarkContent', 'enableCheckUpdates', 'enableCopyPreferences', 'enableStickyPreferencesNavigationBar'].includes(field);
  if (section === 'widget' && !['globalSearch', 'themeToggle', 'languageToggle', 'notification', 'lockScreen', 'fullscreen', 'timezone', 'refresh'].includes(field)) return false;
  return ['theme', 'sidebar', 'navigation', 'breadcrumb', 'tabbar', 'footer', 'header', 'widget', 'shortcutKeys', 'transition'].includes(section);
}

function permitted(value: JsonRecord): PreferencePatch {
  const result: JsonRecord = {};
  for (const section of ['app', 'theme', 'sidebar', 'navigation', 'breadcrumb', 'tabbar', 'footer', 'header', 'widget', 'shortcutKeys', 'transition']) {
    const fields = value[section];
    const known = preferences[section as keyof Preferences] as unknown as JsonRecord;
    if (!fields || typeof fields !== 'object' || Array.isArray(fields) || !known) continue;
    const selected: JsonRecord = {};
    for (const field of Object.keys(known)) {
      if (!isPersonalPreference(`${section}.${field}`)) continue;
      const item = (fields as JsonRecord)[field];
      if (section === 'app' && field === 'timezone' && typeof item === 'string') {
        try { new Intl.DateTimeFormat('zh-CN', { timeZone: item }); } catch { continue; }
      }
      if (item !== undefined && typeof item === typeof known[field] && (typeof item !== 'object' || item === null)) selected[field] = item;
    }
    if (Object.keys(selected).length) result[section] = selected;
  }
  return result as PreferencePatch;
}
function differences(value: JsonRecord, initial: JsonRecord): JsonRecord {
  const patch: JsonRecord = {};
  for (const [key, item] of Object.entries(value)) {
    if (item && typeof item === 'object' && !Array.isArray(item)) {
      const child = differences(item as JsonRecord, (initial[key] || {}) as JsonRecord);
      if (Object.keys(child).length) patch[key] = child;
    } else if (JSON.stringify(item) !== JSON.stringify(initial[key])) patch[key] = item;
  }
  return patch;
}
function personalPatch(): PreferencePatch {
  try { return permitted(JSON.parse(localStorage.getItem(cacheKey()) || '{}')); }
  catch { localStorage.removeItem(cacheKey()); return {}; }
}
function persist() {
  const patch = permitted(differences(clone(preferences) as unknown as JsonRecord, baseline));
  if (Object.keys(patch).length) localStorage.setItem(cacheKey(), JSON.stringify(patch));
  else localStorage.removeItem(cacheKey());
}
function restore() {
  switching = true;
  preferencesManager.setInitialPreferences(defaults);
  void resetPreferences();
  updatePreferences(defaults);
  baseline = clone(preferences) as unknown as JsonRecord;
  updatePreferences(personalPatch());
  switching = false;
}

/** 当前端默认值只影响展示；品牌、入口和身份配置不进入个人覆盖。 */
export function sitePreferenceOverrides(site: Omit<PublicSiteSettings, 'environment'>): PreferencePatch {
  return {
    app: { name: site.name, defaultHomePath: activeHomePath, accessMode: 'frontend', locale: 'zh-CN', timezone: site.timezone, layout: site.preferences.layout },
    // VbenLogo 在图片为空时仍展示文字品牌；图片加载失败由原生 Avatar 提供回退。
    logo: { enable: true, source: site.logo_url },
    copyright: { enable: site.preferences.footer.enable, companyName: site.name, companySiteLink: site.official_url, settingShow: false },
    theme: { mode: site.theme.mode, colorPrimary: site.theme.colorPrimary, radius: site.theme.radius },
    sidebar: { collapsed: site.preferences.sidebar.collapsed },
    navigation: { styleType: site.preferences.navigation.styleType, split: site.preferences.navigation.split },
    breadcrumb: { enable: site.preferences.breadcrumb.enable, showIcon: site.preferences.breadcrumb.showIcon, styleType: site.preferences.breadcrumb.styleType },
    tabbar: { enable: site.preferences.tabbar.enable, styleType: site.preferences.tabbar.styleType },
    footer: { enable: site.preferences.footer.enable, fixed: site.preferences.footer.fixed },
    widget: { globalSearch: true, themeToggle: true, notification: true, languageToggle: true, lockScreen: true, fullscreen: true, timezone: true, refresh: true },
  };
}


export function configurePreferences(site: PublicSiteSettings) {
  defaults = sitePreferenceOverrides(site);
  if (!started) {
    started = true;
    watch(preferences, () => { if (!switching) persist(); }, { deep: true, flush: 'sync' });
  }
  restore();
}
export function applySitePreferences(site: Omit<PublicSiteSettings, 'environment'>) {
  defaults = sitePreferenceOverrides(site);
  if (started) restore();
}
export function switchPreferencesScope(realm = 'system', userId = '', impersonating = false, actorId = '', tenantId = '') {
  const scope = [realm, tenantId || 'platform', userId || 'anonymous', impersonating ? actorId : 'self'].join('.');
  if (!started || scope === activeScope) return;
  persist();
  activeScope = scope;
  activeHomePath = `/${realm}/overview`;
  defaults = { ...defaults, app: { ...defaults.app, defaultHomePath: activeHomePath } };
  restore();
}
export function resetScopedPreferences() {
  localStorage.removeItem(cacheKey());
  restore();
}
