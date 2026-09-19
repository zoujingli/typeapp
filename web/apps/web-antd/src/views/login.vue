<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Alert, Button, Card, Form, FormItem, Input, InputPassword } from 'ant-design-vue';
import { errorText, login, session, siteSettings } from '../api';

const router = useRouter();
const route = useRoute();
const broker = computed(() => route.path === '/broker/login');
const admin = computed(() => route.path === '/admin/login');
const realm = computed(() => broker.value ? 'broker' : admin.value ? 'admin' : 'customer');
const form = reactive({ login: '', password: '' });
const busy = ref(false);
const failure = ref('');
watch(realm, () => { form.password = ''; failure.value = ''; });
async function submit() {
  if (busy.value) return;
  busy.value = true;
  failure.value = '';
  try { await login(form.login.trim(), form.password, realm.value); form.password = ''; await router.replace(broker.value ? '/broker/nodes' : admin.value ? '/admin/profile' : '/profile'); }
  catch (error) { failure.value = errorText(error); }
  finally { busy.value = false; }
}
</script>

<template>
  <main class="login-page">
    <Card class="login-card" :bordered="false">
      <p class="eyebrow">{{ siteSettings.name }} · {{ broker ? 'BROKER' : admin ? 'ADMIN' : 'IOT' }}</p>
      <h1>{{ broker ? 'Broker 管理' : admin ? '平台管理端' : siteSettings.name }}</h1>
      <p class="muted login-subtitle">{{ broker ? '登录后查看 Broker 节点及运行状态。' : admin ? '使用平台管理账号登录。' : '使用客户账号进入所属租户的工作区。' }}</p>
      <Alert v-if="failure || session.notice" type="error" show-icon :message="failure || session.notice" class="page-alert" role="alert" />
      <Form layout="vertical" :model="form" @finish="submit">
        <FormItem label="登录账号" name="login" :rules="[{ required: true, message: '请输入登录账号' }]">
          <Input v-model:value="form.login" autocomplete="username" :maxlength="100" :disabled="busy" size="large" />
        </FormItem>
        <FormItem label="登录密码" name="password" :rules="[{ required: true, message: '请输入登录密码' }]">
          <InputPassword v-model:value="form.password" autocomplete="current-password" :maxlength="72" :disabled="busy" size="large" />
        </FormItem>
        <Button type="primary" html-type="submit" block size="large" :loading="busy">登录</Button>
      </Form>
      <p class="muted login-note">{{ broker ? '请使用独立管理账号。遇到访问问题，请联系服务管理员。' : '账号由平台开通。遇到访问问题，请联系所在组织的管理员。' }}</p>
      <nav class="login-links" aria-label="登录入口">
        <RouterLink v-if="!admin" to="/admin/login">平台管理登录</RouterLink>
        <RouterLink v-if="!broker" to="/broker/login">Broker 管理登录</RouterLink>
        <RouterLink v-if="!broker && !admin" to="/login">用户端登录</RouterLink>
        <RouterLink v-if="admin" to="/login">返回用户端登录</RouterLink>
        <RouterLink v-if="broker" to="/login">用户端登录</RouterLink>
      </nav>
      <a v-if="siteSettings.official_url" class="official-link" :href="siteSettings.official_url" target="_blank" rel="noreferrer">官方网站</a>
    </Card>
  </main>
</template>
