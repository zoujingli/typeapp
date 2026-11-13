<script setup lang="ts">
import { displayDateTime } from '../../display';
import type { DeviceDetail } from '../../api/center';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Page } from '@vben/common-ui';
import { Alert, Button, Card, Descriptions, DescriptionsItem, Empty, Tag } from 'ant-design-vue';
import { clearAccess, errorText, isCanceled, request, session } from '../../api/identity';
import { connectionColors, connectionNames, lifecycleNames } from '../../api/center';

const route = useRoute();
const router = useRouter();
const device = ref<DeviceDetail | null>(null);
const busy = ref(false);
const failure = ref('');
const refreshedAt = ref<number | null>(null);
const domain = computed(() => session.realm === 'platform' ? 'platform' : 'center');
const canRead = computed(() => session.permissions.includes(`${domain.value}.devices.read`));
let revision = 0;
let pending: AbortController | null = null;
let timer: ReturnType<typeof setTimeout> | undefined;
let active = true;
const timeText = (value: number | null | undefined) => value ? displayDateTime(value * 1000) : '尚无记录';

/** 仅观察当前关系与通信主题；原始载荷不进入页面或业务数据处理链。 */
async function load() {
  const tenant = session.tenant?.id;
  if (!active || !canRead.value || session.realm === 'system' && !tenant || document.hidden) return;
  clearTimeout(timer);
  const current = ++revision;
  pending?.abort(); pending = new AbortController(); busy.value = true;
  try {
    const data = await request<DeviceDetail>(`/${domain.value}/devices/${route.params.device}`, { tenant, signal: pending.signal });
    if (current !== revision) return;
    device.value = data; failure.value = ''; refreshedAt.value = Date.now();
  } catch (error) {
    if (current === revision && !isCanceled(error)) {
      failure.value = errorText(error);
      if (clearAccess(error)) device.value = null;
    }
  } finally {
    if (current === revision) {
      busy.value = false;
      if (active && canRead.value && !document.hidden) timer = setTimeout(() => void load(), 5000);
    }
  }
}
function reset() {
  revision++; pending?.abort(); clearTimeout(timer); device.value = null; failure.value = ''; refreshedAt.value = null; busy.value = false;
}
function visibilityChanged() {
  clearTimeout(timer);
  if (document.hidden) { revision++; pending?.abort(); busy.value = false; } else void load();
}
watch([() => session.generation, () => session.tenant?.id, () => route.params.device, canRead], () => { reset(); void load(); }, { immediate: true });
onMounted(() => document.addEventListener('visibilitychange', visibilityChanged));
onBeforeUnmount(() => { active = false; reset(); document.removeEventListener('visibilitychange', visibilityChanged); });
</script>

<template>
  <Page :title="device?.name || '设备通信详情'" description="当前使用关系与原始通信" header-class="flex-wrap gap-4">
    <template #extra><Button @click="router.push(`/${domain}/devices`)">返回设备</Button></template>
    <Alert v-if="failure" :message="failure" :description="refreshedAt ? '读取失败，以下保留上次观察；不能据此确认当前在线。' : undefined" type="error" show-icon class="mb-4" role="alert" />
    <Alert v-if="!canRead" message="当前没有设备查看权限" type="info" show-icon class="mb-4" />
    <Card :bordered="false" title="设备与使用关系" class="mb-4">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3 text-sm"><span class="text-muted-foreground">{{ refreshedAt ? `读取于 ${displayDateTime(refreshedAt)}` : '等待数据' }} · 可见时每 5 秒刷新</span><Button :loading="busy" :disabled="!canRead" @click="load">刷新</Button></div>
      <Empty v-if="!device" description="暂无可查看的设备状态" />
      <Descriptions class="break-all" v-else :column="{ xs: 1, sm: 2 }" layout="vertical" bordered>
        <DescriptionsItem label="设备标识"><span class="break-all font-mono text-sm">{{ device.id }}</span></DescriptionsItem>
        <DescriptionsItem label="当前使用关系"><span class="break-all font-mono text-sm">{{ device.ownership_id }}</span></DescriptionsItem>
        <DescriptionsItem label="产品分类">{{ device.product_id || '未分类' }}</DescriptionsItem>
        <DescriptionsItem label="生命周期"><Tag>{{ lifecycleNames[device.lifecycle] }}</Tag></DescriptionsItem>
        <DescriptionsItem label="连接状态"><Tag :color="connectionColors[device.connection.status]">{{ connectionNames[device.connection.status] }}</Tag></DescriptionsItem>
        <DescriptionsItem label="连接观察时间">{{ timeText(device.connection.observed_at) }}</DescriptionsItem>
        <DescriptionsItem label="使用关系">{{ device.usage?.available ? '当前有效' : '不可使用，请核对到期、转配或恢复状态' }}</DescriptionsItem>
        <DescriptionsItem label="使用期限">{{ device.usage?.expires_at ? timeText(device.usage.expires_at) : '未设置' }}</DescriptionsItem>
        <DescriptionsItem label="凭据状态">{{ device.credential_active ? '存在有效凭据' : '无有效凭据' }}</DescriptionsItem>
        <DescriptionsItem label="授权执行">{{ device.authorization?.status === 'pending' ? '等待 Broker 隔离旧身份' : '无待处理撤权' }}</DescriptionsItem>
      </Descriptions>
    </Card>
    <Card v-if="device?.topics" title="原始通信" :bordered="false">
      <p class="mb-4 text-sm leading-6 text-muted-foreground">设备发布上行、订阅下行；接入应用须另行取得当前使用关系的授权。协议确认不代表业务执行成功。</p>
      <Descriptions class="break-all" :column="1" layout="vertical" bordered>
        <DescriptionsItem label="设备上行 Topic"><code class="break-all text-sm">{{ device.topics.publish }}</code></DescriptionsItem>
        <DescriptionsItem label="设备下行 Topic"><code class="break-all text-sm">{{ device.topics.subscribe }}</code></DescriptionsItem>
      </Descriptions>
    </Card>
  </Page>
</template>
