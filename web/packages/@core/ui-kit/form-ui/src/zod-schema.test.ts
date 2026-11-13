import { describe, expect, it } from 'vitest';
import { validate } from 'vee-validate';
import { z } from 'zod';
import { reactive } from 'vue';

import { toTypedSchema } from './zod-schema';

describe('Zod 4 与 vee-validate 的公开契约', () => {
  it('响应式表单中的 schema 保留 Zod 的真实实例与默认值', async () => {
    const schema = reactive({ rules: z.object({ name: z.string().default('默认') }) });
    const result = await validate({}, toTypedSchema(schema.rules));
    expect(result.valid).toBe(true);
    expect(result.value).toEqual({ name: '默认' });
  });
  it('联合非法值返回分支消息，异步转换返回实际输出', async () => {
    const union = toTypedSchema(z.union([z.string({ error: '需要文字' }), z.number({ error: '需要数字' })]));
    const rejected = await validate(false, union);
    expect(rejected.valid).toBe(false);
    expect(rejected.errors).toEqual(['需要文字', '需要数字']);
    const transformed = await validate('3', toTypedSchema(z.string().transform(async value => Number(value) + 1)));
    expect(transformed.valid).toBe(true);
    expect(transformed.value).toBe(4);
  });

  it('嵌套联合错误保留数组下标与根消息', async () => {
    const schema = toTypedSchema(z.object({
      items: z.array(z.object({ value: z.union([z.string(), z.number()]) })),
    }));
    const result = await schema.parse({ items: [{ value: false }] });
    expect(result.errors).toHaveLength(1);
    expect(result.errors[0]?.path).toBe('items[0].value');
    expect(result.errors[0]?.errors).toHaveLength(3);
  });

  it('非法兄弟字段不妨碍显式默认值，且不合成普通字段或改写输入', () => {
    const original = { nested: { value: '默认' } };
    const schema = toTypedSchema(z.object({
      setting: z.object({ nested: z.object({ value: z.string() }) }).default(original),
      ordinary: z.string(),
      broken: z.number(),
    }));
    const input = { broken: '非法' };
    const result = schema.cast?.(input);
    expect(result).toEqual({ setting: original, ordinary: undefined, broken: '非法' });
    expect(input).toEqual({ broken: '非法' });
    (result as { setting: typeof original }).setting.nested.value = '修改';
    expect(original.nested.value).toBe('默认');
  });

  it('字段描述区分可选、可空、数组、字面点号及不存在路径', () => {
    const schema = toTypedSchema(z.object({
      items: z.array(z.object({ name: z.string(), optional: z.string().optional() })),
      nullable: z.string().nullable(),
      'literal.key': z.string(),
    }));
    expect(schema.describe?.('items[0].name')).toEqual({ exists: true, required: true });
    expect(schema.describe?.('items[0].optional')).toEqual({ exists: true, required: false });
    expect(schema.describe?.('nullable')).toEqual({ exists: true, required: true });
    expect(schema.describe?.('[literal.key]')).toEqual({ exists: true, required: true });
    expect(schema.describe?.('missing')).toEqual({ exists: false, required: false });
  });
});
