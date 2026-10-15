<script setup lang="ts">
import { Button } from 'ant-design-vue';
import { useAttrs } from 'vue';
import { isDemo } from '../api';

/** 业务写操作统一表达演示禁用状态；查询和认证按钮继续使用普通 Button。 */
defineOptions({ inheritAttrs: false });
const attrs = useAttrs();
</script>

<template>
  <Button v-bind="attrs" :disabled="isDemo() || Boolean(attrs.disabled)" :title="isDemo() ? '演示环境，仅供浏览' : undefined"><slot /></Button>
</template>
