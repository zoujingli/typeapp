<script setup lang="ts">
import type { FormInstance } from 'ant-design-vue';
import type { User } from '../api';
import { onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Alert, Button, Card, Descriptions, DescriptionsItem, Empty, Form, FormItem, Input, Space, message } from 'ant-design-vue';
import AppDrawer from '../components/app-drawer/app-drawer.vue';
import { ApiError, cancelContext, clearSession, errorText, isCanceled, loadUser, request, session } from '../api';
import { resetScopedPreferences } from '../preferences';

const busy = ref(false);
const failure = ref('');
const open = ref(false); const saving = ref(false); const saveFailure = ref('');
const mode = ref<'update' | 'password'>('update'); const version = ref(0);
const form = reactive({ login: '', name: '', current_password: '', password: '' }); const formRef = ref<FormInstance>();
function edit(action: 'update' | 'password') {
  mode.value = action; version.value = Number(session.user?.version);
  Object.assign(form, { login: session.user?.login || '', name: session.user?.name || '', current_password: '', password: '' });
  saveFailure.value = ''; open.value = true;
}
async function save() {
  if (saving.value) return; try { await formRef.value?.validate(); } catch { return; }
  if (saving.value) return; saving.value = true; saveFailure.value = '';
  try {
    const data = { version: version.value, current_password: form.current_password,
      ...(mode.value === 'password' ? { password: form.password } : { login: form.login.trim(), name: form.name.trim() }) };
    const user = await request<User>(`/customer/account${mode.value === 'password' ? '/password' : ''}`, { method: mode.value === 'password' ? 'POST' : 'PATCH', data });
    open.value = false; form.current_password = ''; form.password = '';
    if (mode.value === 'password') { clearSession(); session.notice = '密码已修改，全部旧会话已撤销，请重新登录。'; }
    else { session.user = user; message.success('个人资料已保存'); }
  } catch (error) {
    if (!isCanceled(error)) { saveFailure.value = errorText(error); if (error instanceof ApiError && error.status === 409) await refresh(); }
  } finally { saving.value = false; }
}
async function refresh() {
  if (busy.value) return;
  busy.value = true; failure.value = '';
  try {
    await loadUser();
    if (session.permissions.includes('identity.read')) await request(`/${session.realm}/profile`, { tenant: session.tenant?.id });
  } catch (error) { if (!isCanceled(error)) failure.value = errorText(error); }
  finally { busy.value = false; }
}
function resetViewPreferences() {
  resetScopedPreferences();
  message.success('已恢复当前账号的最新站点默认界面');
}
onMounted(refresh);
watch(() => session.realm, refresh);
watch(open, value => { if (!value) { form.current_password = ''; form.password = ''; } });
onBeforeUnmount(() => { form.current_password = ''; form.password = ''; cancelContext(); });
</script>

<template>
  <div class="iot-page">
    <Card title="个人工作区" :loading="busy">
      <template #extra><Space><Button :loading="busy" @click="refresh">刷新权限</Button><Button :disabled="busy || saving" @click="resetViewPreferences">恢复界面默认</Button></Space></template>
      <Alert v-if="failure" type="error" show-icon :message="failure" role="alert" class="page-alert" />
      <Empty v-if="!failure && !session.permissions.includes('identity.read')" description="当前账号没有工作区权限，请联系管理员分配角色。" />
      <Descriptions v-if="!failure && (session.realm === 'customer' || session.permissions.includes('identity.read'))" :column="1" bordered>
        <DescriptionsItem label="登录账号">{{ session.user?.login }}</DescriptionsItem>
        <DescriptionsItem label="显示姓名"><span class="break-all">{{ session.user?.name }}</span></DescriptionsItem>
        <DescriptionsItem label="账号类型">{{ session.realm === 'admin' ? '平台管理账号' : '客户账号' }}</DescriptionsItem>
        <DescriptionsItem v-if="session.realm === 'customer'" label="当前租户"><span class="break-all">{{ session.tenant?.name || '尚未加入租户' }}</span></DescriptionsItem>
      </Descriptions>
      <div v-if="session.realm === 'customer' && !session.impersonating && session.user && !failure" class="toolbar-actions mt-4">
        <Button :disabled="busy || saving" @click="edit('update')">修改个人资料</Button>
        <Button :disabled="busy || saving" danger @click="edit('password')">修改登录密码</Button>
      </div>
    </Card>
    <AppDrawer v-model:open="open" :title="mode === 'password' ? '修改登录密码' : '修改个人资料'" width-size="sm" :confirm-loading="saving" :closable="!saving" :ok-disabled="saving" :ok-danger="mode === 'password'" @ok="save">
      <Alert v-if="saveFailure" :message="saveFailure" type="error" show-icon role="alert" class="page-alert" />
      <Alert :message="mode === 'password' ? '修改密码将撤销所有设备上的旧登录，请使用新密码重新登录。' : '此资料由你加入的所有租户共享，修改登录账号后请使用新账号登录。'" type="warning" show-icon class="page-alert" />
      <Form ref="formRef" :model="form" layout="vertical" class="crud-form-grid">
        <FormItem v-if="mode === 'update'" label="登录账号" name="login" :rules="[{ required: true, pattern: /^[a-z0-9][a-z0-9_.@-]{2,99}$/, message: '请输入3至100位小写账号' }]"><Input v-model:value="form.login" :disabled="saving" :maxlength="100" autocomplete="username" /></FormItem>
        <FormItem v-if="mode === 'update'" label="显示姓名" name="name" :rules="[{ required: true, whitespace: true, message: '请输入显示姓名' }]"><Input v-model:value="form.name" :disabled="saving" :maxlength="100" /></FormItem>
        <FormItem label="当前密码" name="current_password" :rules="[{ required: true, message: '请输入当前登录密码' }]"><Input.Password v-model:value="form.current_password" :disabled="saving" :maxlength="72" autocomplete="current-password" /></FormItem>
        <FormItem v-if="mode === 'password'" label="新密码" name="password" :rules="[{ required: true, min: 12, max: 72, message: '请输入12至72字节密码' }]"><Input.Password v-model:value="form.password" :disabled="saving" :maxlength="72" autocomplete="new-password" /></FormItem>
      </Form>
    </AppDrawer>
  </div>
</template>
