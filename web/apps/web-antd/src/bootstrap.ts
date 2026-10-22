import { createApp, watch } from 'vue';
import { createPinia } from 'pinia';
import { setupI18n } from '@vben/locales';
import { useAccessStore, useTabbarStore } from '@vben/stores';
import '@vben/styles';
import '@vben/styles/antd';
import './styles.css';
import App from './app.vue';
import { router } from './router';
import { session } from './api';

/** 按依赖顺序安装状态、国际化与路由后挂载管理端，避免首屏读取尚未初始化的服务。 */
export async function bootstrap() {
  const app = createApp(App);
  app.use(createPinia());
  const access = useAccessStore();
  const tabs = useTabbarStore();
  // 页面自身也会取消在途请求；只有身份、租户或权限实际改变才清理工作区状态。
  watch([() => session.realm, () => session.token, () => session.tenant?.id, () => session.impersonating, () => session.permissions.join('\0')],
    () => { access.$reset(); tabs.$reset(); }, { flush: 'sync' });
  await setupI18n(app);
  app.use(router);
  app.mount('#app');
}
