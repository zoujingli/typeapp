import { createApp, watch } from 'vue';
import { createPinia } from 'pinia';
import { setupI18n } from '@vben/locales';
import { setTimezoneHandler, useAccessStore, useTabbarStore, useTimezoneStore } from '@vben/stores';
import { preferences, updatePreferences } from '@vben/preferences';
import { setCurrentTimezone } from '@vben/utils';
import '@vben/styles';
import '@vben/styles/antd';
import App from './app.vue';
import { initComponentAdapter } from './adapter/component';
import { initSetupVbenForm } from './adapter/form';
import { router } from './router';
import { session } from './api/identity';
import { bindScreenLockScope, clearScreenLock } from './screen-lock';

/** 按依赖顺序安装状态、国际化与路由后挂载管理端，避免首屏读取尚未初始化的服务。 */
export async function bootstrap() {
  await initComponentAdapter();
  await initSetupVbenForm();
  const app = createApp(App);
  app.use(createPinia());
  const access = useAccessStore();
  const tabs = useTabbarStore();
  setTimezoneHandler({ setTimezone: async timezone => { updatePreferences({ app: { timezone } }); } });
  const timezone = useTimezoneStore();
  watch(() => preferences.app.timezone, value => { timezone.timezone = value; setCurrentTimezone(value); }, { immediate: true });
  // 页面自身也会取消在途请求；只有身份、租户或权限实际改变才清理工作区状态。
  watch([() => session.realm, () => session.token, () => session.tenant?.id, () => session.impersonating, () => session.permissions.join('\0')],
    () => { access.$reset(); tabs.$reset(); }, { flush: 'sync' });
  watch(() => access.isLockScreen, locked => { if (!locked) clearScreenLock(); }, { flush: 'sync' });
  watch([() => session.identity?.key, () => session.token], () => {
    bindScreenLockScope(session.token && session.identity ? `${session.identity.key}.${session.identity.session_id}` : '');
  }, { flush: 'post' });
  let tabKey = '';
  let restoringTabs = false;
  watch([() => session.token, () => session.user?.id, () => session.identity?.key, () => session.permissions.join('\0')], () => {
    restoringTabs = true;
    try {
      const key = session.user && session.token
        ? `typeapp.tabs.${session.realm}.${session.tenant?.id || 'platform'}.${session.user.id}.${session.impersonating ? session.identity?.actor_id : 'self'}` : '';
      if (tabKey && tabKey !== key) sessionStorage.removeItem(tabKey);
      tabKey = key;
      if (!key || !preferences.tabbar.persist) return;
      let saved: unknown;
      try { saved = JSON.parse(sessionStorage.getItem(key) || '[]'); } catch { sessionStorage.removeItem(key); return; }
      if (!Array.isArray(saved)) return;
      for (const path of saved.slice(-64)) {
        if (typeof path !== 'string' || path.length > 1000) continue;
        const target = router.resolve(path);
        if (!target.name || target.meta.public || target.meta.directory || target.meta.realm !== session.realm) continue;
        if (typeof target.meta.permission === 'string' && !session.permissions.includes(target.meta.permission)) continue;
        tabs.addTab({ ...target, name: target.name });
      }
    } finally { restoringTabs = false; }
  }, { flush: 'post' });
  watch([() => tabs.tabs.map(tab => tab.fullPath || tab.path), () => preferences.tabbar.persist], ([paths, persist]) => {
    if (!tabKey || restoringTabs) return;
    if (persist) sessionStorage.setItem(tabKey, JSON.stringify(paths.slice(-64)));
    else sessionStorage.removeItem(tabKey);
  }, { deep: true, flush: 'post' });
  await setupI18n(app);
  app.use(router);
  app.mount('#app');
}
