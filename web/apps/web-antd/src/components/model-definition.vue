<script setup lang="ts">
import type { ModelDefinition } from '../api';
import { Button, Card, Form, FormItem, Input, Tabs, TabPane } from 'ant-design-vue';
import ModelScalars from './model-scalars.vue';

const definition = defineModel<ModelDefinition>({ required: true });
defineProps<{ disabled?: boolean }>();
const groups = [{ key: 'events' as const, label: '事件' }, { key: 'commands' as const, label: '指令' }];
</script>

<template>
  <Form layout="vertical" :disabled="disabled">
    <Tabs>
      <TabPane key="properties" :tab="`属性（${definition.properties.length}）`"><ModelScalars v-model="definition.properties" label="属性" :limit="64" :disabled="disabled" /></TabPane>
      <TabPane v-for="group in groups" :key="group.key" :tab="`${group.label}（${definition[group.key].length}）`">
        <div class="model-operations">
          <Card v-for="(operation, index) in definition[group.key]" :key="index" :title="`${group.label} ${index + 1}`" size="small">
            <template #extra><Button v-if="!disabled" danger type="text" @click="definition[group.key].splice(index, 1)">移除{{ group.label }}</Button></template>
            <div class="form-grid">
              <FormItem :label="`${group.label}标识`" required><Input v-model:value="operation.identifier" :aria-label="`${group.label}${index + 1}标识`" :disabled="disabled" :maxlength="64" /></FormItem>
              <FormItem :label="`${group.label}名称`" required><Input v-model:value="operation.name" :aria-label="`${group.label}${index + 1}名称`" :disabled="disabled" :maxlength="100" /></FormItem>
            </div>
            <ModelScalars v-model="operation.parameters" label="参数" :limit="32" :disabled="disabled" />
          </Card>
          <p v-if="definition[group.key].length === 0" class="muted">尚未定义{{ group.label }}。</p>
          <Button v-if="!disabled" :disabled="definition[group.key].length >= 32" @click="definition[group.key].push({ identifier: '', name: '', parameters: [] })">添加{{ group.label }}（{{ definition[group.key].length }}/32）</Button>
        </div>
      </TabPane>
    </Tabs>
  </Form>
</template>

<style scoped>
.model-operations { display: grid; gap: 16px; min-width: 0; }
</style>
