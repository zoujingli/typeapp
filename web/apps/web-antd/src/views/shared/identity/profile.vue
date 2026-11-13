<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { Alert, Avatar, Button, Card, Descriptions, DescriptionsItem, Space, Tag, message } from 'ant-design-vue';
import { Page, useVbenModal } from '@vben/common-ui';
import { useVbenForm, z } from '../../../adapter/form';
import { clearSession, errorText, isCanceled, isDemo, loadUser, request, session } from '../../../api/identity';
import { resetScopedPreferences } from '../../../preferences';
const mode = ref<'update' | 'password'>('update'); const busy = ref(false); const failure = ref(''); const saveFailure = ref('');
const [Form, form] = useVbenForm(reactive({ showDefaultActions: false, layout: 'vertical', commonConfig: { componentProps: { class: 'w-full' } }, schema: computed(() => [
  ...(mode.value === 'update' ? [
    { fieldName: 'login', label: '登录账号', component: 'Input' as const, rules: z.string().regex(/^[a-z0-9][a-z0-9_.@-]{2,99}$/, '请输入3至100位小写账号') },
    { fieldName: 'name', label: '姓名', component: 'Input' as const, rules: z.string().trim().min(1).max(100) },
  ] : []),
  { fieldName: 'current_password', label: '当前密码', component: 'InputPassword' as const, componentProps: { autocomplete: 'current-password', maxlength: 72 }, rules: z.string().min(1, '请输入当前密码').max(72) },
  ...(mode.value === 'password' ? [{ fieldName: 'password', label: '新密码', component: 'InputPassword' as const, componentProps: { autocomplete: 'new-password', maxlength: 72 }, rules: z.string().min(5, '请输入至少5位密码').max(72) }] : []),
]) }));
const [Editor, editor] = useVbenModal({ onConfirm: save, onClosed: () => form.resetForm() });
async function edit(action: typeof mode.value) {
  mode.value = action; saveFailure.value = ''; editor.setState({ title: action === 'password' ? '修改密码' : '修改资料' }).open();
  await form.resetForm(); await form.setValues({ login: session.user?.login, name: session.user?.name, current_password: '', password: '' });
}
async function save() {
  if (!(await form.validate()).valid) return;
  editor.lock(); saveFailure.value = '';
  try {
    const values = await form.getValues(); const data = { version: session.user!.version, current_password: values.current_password,
      ...(mode.value === 'password' ? { password: values.password } : { login: values.login, name: values.name }) };
    await request(`/${session.realm}/profile${mode.value === 'password' ? '/password' : ''}`, { method: mode.value === 'password' ? 'POST' : 'PATCH', data });
    editor.close();
    if (mode.value === 'password') { clearSession(); session.notice = '密码已修改，请重新登录。'; }
    else { await loadUser(); message.success('资料已保存'); }
  } catch (error) { if (!isCanceled(error)) saveFailure.value = errorText(error); }
  finally { editor.unlock(); }
}
async function refresh() { busy.value = true; failure.value = ''; try { await loadUser(); } catch (error) { if (!isCanceled(error)) failure.value = errorText(error); } finally { busy.value = false; } }
function restore() { resetScopedPreferences(); message.success('已恢复当前系统最新的默认界面'); }
</script>
<template>
  <Page title="个人中心" description="管理个人资料、账号安全和当前浏览器的界面偏好。">
    <Alert v-if="failure" :message="failure" type="error" class="mb-4" /><Alert v-if="!session.permissions.length" message="尚未分配业务角色，请联系管理员。" type="info" class="mb-4" />
    <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
      <Card :bordered="false">
        <div class="flex flex-col items-center gap-3 py-4 text-center">
          <Avatar :size="72">{{ session.user?.name?.slice(0, 1) }}</Avatar>
          <span class="max-w-full break-all text-lg font-semibold">{{ session.user?.name }}</span>
          <span class="max-w-full break-all text-sm text-muted-foreground">{{ session.user?.login }}</span>
          <Tag>{{ session.realm === 'platform' ? '平台账号' : '系统账号' }}{{ session.impersonating ? ' · 模拟登录' : '' }}</Tag>
        </div>
      </Card>
      <div class="space-y-4 lg:col-span-2">
        <Card title="个人资料" :bordered="false">
          <template #extra><Button v-if="!session.impersonating" type="link" :disabled="isDemo()" @click="edit('update')">修改资料</Button></template>
          <Descriptions class="break-all" :column="{ xs: 1, sm: 2 }" layout="vertical"><DescriptionsItem label="登录账号"><span class="break-all">{{ session.user?.login }}</span></DescriptionsItem><DescriptionsItem label="姓名">{{ session.user?.name }}</DescriptionsItem><DescriptionsItem v-if="session.tenant" label="所属客户">{{ session.tenant.name }}</DescriptionsItem></Descriptions>
        </Card>
        <Card title="账号安全与权限" :bordered="false">
          <p class="mb-4 text-sm text-muted-foreground">修改密码后，所有旧登录会话将失效。角色发生变更时可重新读取当前权限。</p>
          <Space wrap><Button v-if="!session.impersonating" :disabled="isDemo()" @click="edit('password')">修改密码</Button><Button :loading="busy" @click="refresh">刷新权限</Button></Space>
        </Card>
        <Card title="界面偏好" :bordered="false">
          <p class="mb-4 text-sm text-muted-foreground">个人设置仅保存在当前浏览器。恢复默认将使用当前系统最新的品牌、主题和布局配置。</p>
          <Button @click="restore">恢复界面默认</Button>
        </Card>
      </div>
    </div>
    <Editor><Alert v-if="saveFailure" :message="saveFailure" type="error" class="mb-3" /><Alert v-if="mode === 'password'" message="修改密码后，所有旧登录会话将失效。" type="warning" class="mb-3" /><Form /></Editor>
  </Page>
</template>
