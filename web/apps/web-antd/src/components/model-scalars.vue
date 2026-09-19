<script setup lang="ts">
import type { ModelScalar } from '../api';
import { Button, Card, FormItem, Input, InputNumber, Select, Switch } from 'ant-design-vue';

const fields = defineModel<ModelScalar[]>({ required: true });
defineProps<{ disabled?: boolean; limit: number; label: string }>();
const types = [{ label: '整数', value: 'integer' }, { label: '数值', value: 'number' }, { label: '布尔', value: 'boolean' }, { label: '字符串', value: 'string' }, { label: '枚举', value: 'enum' }];
function changeType(field: ModelScalar, value: unknown) {
  field.type = value as ModelScalar['type'];
  delete field.unit; delete field.min; delete field.max; delete field.min_length; delete field.max_length; delete field.values;
  if (field.type === 'enum') field.values = [];
}
function bound(field: ModelScalar, key: 'min' | 'max' | 'min_length' | 'max_length', value: unknown) {
  if (value === null || value === undefined || value === '') delete field[key]; else field[key] = Number(value);
}
</script>

<template>
  <div class="model-fields">
    <Card v-for="(field, index) in fields" :key="index" size="small" :title="`${label} ${index + 1}`">
      <template #extra><Button v-if="!disabled" danger type="text" @click="fields.splice(index, 1)">移除此项</Button></template>
      <div class="form-grid">
        <FormItem label="字段标识" required><Input v-model:value="field.identifier" :aria-label="`${label}${index + 1}字段标识`" :disabled="disabled" :maxlength="64" placeholder="字母开头，仅字母、数字和下划线" /></FormItem>
        <FormItem label="字段名称" required><Input v-model:value="field.name" :aria-label="`${label}${index + 1}字段名称`" :disabled="disabled" :maxlength="100" /></FormItem>
        <FormItem label="数据类型" required><Select :value="field.type" :aria-label="`${label}${index + 1}数据类型`" :disabled="disabled" :options="types" @keydown.esc.stop @change="(value) => changeType(field, value)" /></FormItem>
        <FormItem label="必须提供"><Switch v-model:checked="field.required" :aria-label="`${label}${index + 1}必须提供`" :disabled="disabled" /></FormItem>
        <template v-if="field.type === 'number' || field.type === 'integer'">
          <FormItem label="计量单位"><Input v-model:value="field.unit" :aria-label="`${label}${index + 1}计量单位`" :disabled="disabled" :maxlength="32" /></FormItem>
          <FormItem label="最小数值"><InputNumber :value="field.min" :aria-label="`${label}${index + 1}最小数值`" :disabled="disabled" :precision="field.type === 'integer' ? 0 : undefined" style="width: 100%" @change="(value) => bound(field, 'min', value)" /></FormItem>
          <FormItem label="最大数值"><InputNumber :value="field.max" :aria-label="`${label}${index + 1}最大数值`" :disabled="disabled" :precision="field.type === 'integer' ? 0 : undefined" style="width: 100%" @change="(value) => bound(field, 'max', value)" /></FormItem>
        </template>
        <template v-else-if="field.type === 'string'">
          <FormItem label="最少字节"><InputNumber :value="field.min_length" :aria-label="`${label}${index + 1}最少字节`" :disabled="disabled" :min="0" :max="4096" :precision="0" placeholder="默认0" style="width: 100%" @change="(value) => bound(field, 'min_length', value)" /></FormItem>
          <FormItem label="最多字节"><InputNumber :value="field.max_length" :aria-label="`${label}${index + 1}最多字节`" :disabled="disabled" :min="0" :max="4096" :precision="0" placeholder="默认1024" style="width: 100%" @change="(value) => bound(field, 'max_length', value)" /></FormItem>
        </template>
        <FormItem v-else-if="field.type === 'enum'" label="可选取值" required class="span-full"><Select v-model:value="field.values" :aria-label="`${label}${index + 1}可选取值`" mode="tags" :disabled="disabled" placeholder="输入一个值后按回车，最多100项" :max-tag-count="10" @keydown.esc.stop /></FormItem>
      </div>
    </Card>
    <p v-if="fields.length === 0" class="muted">尚未定义{{ label }}。</p>
    <Button v-if="!disabled" :disabled="fields.length >= limit" @click="fields.push({ identifier: '', name: '', type: 'number', required: false })">添加{{ label }}（{{ fields.length }}/{{ limit }}）</Button>
  </div>
</template>

<style scoped>
.model-fields { display: grid; gap: 16px; min-width: 0; }
</style>
