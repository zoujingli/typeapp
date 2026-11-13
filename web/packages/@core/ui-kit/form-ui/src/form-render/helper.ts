import type { ZodType } from 'zod';

import { toRaw } from 'vue';
import { isObject, isString } from '@vben-core/shared/utils';

/** Zod 4 将转换放入 pipe；只展开包装，不执行转换或校验。 */
export function getBaseRules<T extends ZodType = ZodType>(schema: T): T | null {
  if (!schema || isString(schema)) return null;
  schema = toRaw(schema);
  const definition = schema._def as { type: string; innerType?: T; in?: T };
  if (definition.innerType) return getBaseRules(definition.innerType);
  if (definition.type === 'pipe' && definition.in) return getBaseRules(definition.in);
  return schema;
}

/** 默认值使用 Zod 4 的值访问器，保留上游默认值工厂的惰性行为。 */
export function getDefaultValueInZodStack(schema: ZodType): unknown {
  if (!schema || isString(schema)) return undefined;
  schema = toRaw(schema);
  const definition = schema._def as { type: string; defaultValue?: unknown; innerType?: ZodType; in?: ZodType };
  if (definition.type === 'default') return definition.defaultValue;
  if (definition.innerType) return getDefaultValueInZodStack(definition.innerType);
  if (definition.type === 'pipe' && definition.in) return getDefaultValueInZodStack(definition.in);
  return undefined;
}

export function isEventObjectLike(obj: any) {
  if (!obj || !isObject(obj)) {
    return false;
  }
  return Reflect.has(obj, 'target') && Reflect.has(obj, 'stopPropagation');
}
