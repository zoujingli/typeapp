import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { setupI18n } from '@vben/locales';
import '@vben/styles';
import '@vben/styles/antd';
import './styles.css';
import App from './app.vue';
import { router } from './router';

/** 按依赖顺序安装状态、国际化与路由后挂载管理端，避免首屏读取尚未初始化的服务。 */
export async function bootstrap() {
  const app = createApp(App);
  app.use(createPinia());
  await setupI18n(app);
  app.use(router);
  app.mount('#app');
}
