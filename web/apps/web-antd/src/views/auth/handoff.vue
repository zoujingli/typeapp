<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Alert, Spin } from 'ant-design-vue';
import { consumeHandoff, errorText } from '../../api/identity';
const route = useRoute(); const router = useRouter(); const failure = ref('');
onMounted(async () => {
  const code = typeof route.query.code === 'string' ? route.query.code : '';
  history.replaceState(history.state, '', `${location.pathname}#/system/handoff`);
  try { await consumeHandoff(code); await router.replace('/system/profile'); }
  catch (error) { failure.value = errorText(error); }
});
</script>
<template><Alert v-if="failure" type="error" :message="failure" show-icon /><Spin v-else tip="正在进入系统" /></template>
