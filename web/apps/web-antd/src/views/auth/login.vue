<script setup lang="ts">
import type { VbenFormSchema } from '@vben/common-ui';
import type { Page, Realm, Tenant } from '../../api/contracts';
import { computed, markRaw, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { AuthenticationLogin, z } from '@vben/common-ui';
import { Alert, Select } from 'ant-design-vue';
import { cancelContext, entryContext, errorText, isCanceled, login, loadSiteSettings, request, selectTenant, session } from '../../api/identity';

const route = useRoute();
const router = useRouter();
const realm = computed<Realm>(() => route.path.startsWith('/platform') ? 'platform' : 'system');
const form = ref<InstanceType<typeof AuthenticationLogin>>();
const busy = ref(false); const configuring = ref(false); const contextReady = ref(false); const failure = ref('');
const choices = ref<Tenant[]>([]); const total = ref(0); const page = ref(1); const search = ref(''); const searching = ref(false);
let searchTimer: ReturnType<typeof setTimeout> | undefined;
let listController: AbortController | undefined;
let listGeneration = 0;
const choose = computed(() => realm.value === 'system' && !entryContext.bound);
async function fetchTenants(append = false) {
  listController?.abort(); listController = new AbortController();
  const generation = ++listGeneration; searching.value = true;
  const nextPage = append ? page.value + 1 : 1;
  try {
    const data = await request<Page<Tenant>>('/public/tenants', { params: { search: search.value, page: nextPage, per_page: 20 }, public: true, signal: listController.signal });
    if (generation !== listGeneration) return;
    choices.value = append ? [...choices.value, ...data.items] : data.items;
    total.value = data.total; page.value = nextPage;
  } catch (error) { if (!isCanceled(error)) failure.value = errorText(error); }
  finally { if (generation === listGeneration) searching.value = false; }
}
function searchTenants(value: string) {
  search.value = value; clearTimeout(searchTimer);
  searchTimer = setTimeout(() => void fetchTenants(), 250);
}
function scrollTenants(event: Event) {
  const element = event.target as HTMLElement;
  if (!searching.value && choices.value.length < total.value && element.scrollHeight - element.scrollTop - element.clientHeight < 32) void fetchTenants(true);
}
async function customerChanged(id?: string) {
  cancelContext();
  await form.value?.getFormApi().resetForm();
  form.value?.getFormApi().setValues({ tenant_id: id, username: '', password: '' });
  failure.value = ''; contextReady.value = false; configuring.value = true;
  const tenant = choices.value.find(item => item.id === id) || null;
  selectTenant(tenant);
  try { await loadSiteSettings(tenant, 'system'); contextReady.value = true; }
  catch (error) { if (!isCanceled(error)) failure.value = errorText(error); }
  finally { if (session.tenant?.id === id || !id) configuring.value = false; }
}
const schema = computed<VbenFormSchema[]>(() => [
  ...(choose.value ? [{ fieldName: 'tenant_id', label: '客户', defaultValue: undefined, component: markRaw(Select), modelPropName: 'value',
    componentProps: { style: { width: '100%' }, placeholder: '搜索并选择客户', showSearch: true, allowClear: true, filterOption: false, loading: searching.value,
      options: choices.value.map(item => ({ label: item.name, value: item.id })), onSearch: searchTenants, onPopupScroll: scrollTenants, onChange: customerChanged },
    rules: z.string({ error: '请选择客户' }).min(1, '请选择客户') }] : []),
  { fieldName: 'username', label: '账号', component: 'VbenInput', componentProps: { placeholder: '账号', autocomplete: 'username', maxlength: 100, disabled: busy.value }, rules: z.string().min(1, '请输入账号') },
  { fieldName: 'password', label: '密码', component: 'VbenInputPassword', componentProps: { placeholder: '密码', autocomplete: 'current-password', maxlength: 72, disabled: busy.value }, rules: z.string().min(1, '请输入密码') },
]);
async function submit(values: Record<string, unknown>) {
  if (busy.value || configuring.value || !contextReady.value) return;
  busy.value = true; failure.value = '';
  try {
    await login(String(values.username).trim(), String(values.password), realm.value);
    form.value?.getFormApi().setFieldValue('password', '');
    if (realm.value === 'platform' || entryContext.bound) await router.replace(`/${realm.value}/profile`);
  } catch (error) { if (!isCanceled(error)) failure.value = errorText(error); }
  finally { busy.value = false; }
}
watch(realm, async () => {
  failure.value = ''; contextReady.value = false; configuring.value = true;
  try {
    await loadSiteSettings(undefined, realm.value);
    contextReady.value = true;
    await nextTick(); await form.value?.getFormApi().resetForm();
    if (choose.value) await fetchTenants();
  } catch (error) { if (!isCanceled(error)) failure.value = errorText(error); }
  finally { configuring.value = false; }
}, { immediate: true });
onBeforeUnmount(() => { clearTimeout(searchTimer); listController?.abort(); form.value?.getFormApi().setValues({ username: '', password: '' }); });
</script>
<template>
  <div>
    <Alert v-if="failure || session.notice" :message="failure || session.notice" type="error" show-icon class="mb-4" role="alert" />
    <AuthenticationLogin ref="form" :form-schema="schema" :loading="busy || configuring" :submit-disabled="!contextReady" :title="realm === 'platform' ? '平台登录' : '系统登录'" :sub-title="realm === 'platform' ? '使用平台账号登录' : choose ? '选择客户后登录系统' : '使用当前系统账号登录'" :show-code-login="false" :show-qrcode-login="false" :show-forget-password="false" :show-register="false" :show-third-party-login="false" :show-remember-me="false" @submit="submit" />
    <div v-if="!entryContext.bound" class="mt-5 text-center"><RouterLink :to="realm === 'platform' ? '/system/login' : '/platform/login'">{{ realm === 'platform' ? '返回系统登录' : '平台管理登录' }}</RouterLink></div>
  </div>
</template>
