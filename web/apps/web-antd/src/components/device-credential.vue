<script setup lang="ts">
import type { DeviceCredential } from '../api/center';
import { onBeforeUnmount, ref } from 'vue';
import { Alert, Descriptions, DescriptionsItem } from 'ant-design-vue';
import { useVbenDrawer } from '@vben/common-ui';
const credential = ref<DeviceCredential>();
const [Drawer, drawer] = useVbenDrawer({ title: '保存一次性设备凭据', footer: false, class: 'w-full max-w-xl', onOpenChange(open) { if (!open) credential.value = undefined; } });
function show(value: DeviceCredential) { credential.value = value; drawer.open(); }
onBeforeUnmount(() => { credential.value = undefined; });
defineExpose({ show });
</script>
<template>
  <Drawer><Alert type="warning" show-icon class="mb-3" message="仅本次显示，关闭后清除。遗失请在设备管理中轮换凭据；转配不继承旧应用授权。" />
    <Descriptions v-if="credential" :column="1" bordered class="break-all" layout="vertical">
      <DescriptionsItem label="Client ID">{{ credential.client_id }}</DescriptionsItem><DescriptionsItem label="用户名">{{ credential.username }}</DescriptionsItem>
      <DescriptionsItem label="密码"><code>{{ credential.password }}</code></DescriptionsItem><DescriptionsItem label="协议">MQTT {{ credential.protocol_version }} / TLS</DescriptionsItem>
      <DescriptionsItem label="Keep Alive">{{ credential.keep_alive }} 秒</DescriptionsItem><DescriptionsItem label="Session Expiry">{{ credential.session_expiry }} 秒</DescriptionsItem>
      <DescriptionsItem label="上行 Topic">{{ credential.topics.publish }}</DescriptionsItem><DescriptionsItem label="下行 Topic">{{ credential.topics.subscribe }}</DescriptionsItem>
    </Descriptions>
  </Drawer>
</template>
