import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { setupI18n } from '@vben/locales';
import '@vben/styles';
import '@vben/styles/antd';
import './styles.css';
import App from './app.vue';
import { router } from './router';

export async function bootstrap() {
  const app = createApp(App);
  app.use(createPinia());
  await setupI18n(app);
  app.use(router);
  app.mount('#app');
}
