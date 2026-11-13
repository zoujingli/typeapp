import type { TypedSchema, TypedSchemaError } from 'vee-validate';
import type { ZodIssue, ZodType } from 'zod';

import { toRaw } from 'vue';
import { cleanupNonNestedPath, isNotNestedPath } from 'vee-validate';
import { ZodArray, ZodDefault, ZodObject, ZodPipe } from 'zod';
import { cloneDeep } from '@vben-core/shared/utils';

/** 保留 vee-validate 4 的 TypedSchema 契约，适配 Zod 4 的错误、默认值及字段路径。 */
export function toTypedSchema(schema: ZodType): TypedSchema<unknown, unknown> {
  schema = toRaw(schema);
  return {
    __type: 'VVTypedSchema',
    async parse(value) {
      const result = await schema.safeParseAsync(value);
      if (result.success) return { value: result.data, errors: [] };
      const errors = new Map<string, TypedSchemaError>();
      collectIssues(result.error.issues, [], errors);
      return { errors: [...errors.values()] };
    },
    cast(value) {
      try {
        return schema.parse(value);
      } catch {
        const defaults = explicitDefaults(schema);
        return record(defaults) && record(value) ? merge(defaults, value) : value;
      }
    },
    describe(path) {
      try {
        const target = path ? atPath(schema, path) : schema;
        return { exists: target !== null, required: target !== null && !target.isOptional() };
      } catch {
        return { exists: false, required: false };
      }
    },
  };
}

function collectIssues(issues: ZodIssue[], prefix: PropertyKey[], errors: Map<string, TypedSchemaError>) {
  for (const issue of issues) {
    const parts = [...prefix, ...issue.path];
    const path = parts.map((part, index) => typeof part === 'number' ? '[' + part + ']' : (index ? '.' : '') + String(part)).join('');
    if (issue.code === 'invalid_union') {
      for (const branch of issue.errors) collectIssues(branch, parts, errors);
      if (!path) continue;
    }
    const entry = errors.get(path) ?? { path, errors: [] };
    entry.errors.push(issue.message);
    errors.set(path, entry);
  }
}

/** 只提取显式 default，不能为普通字段合成空串、零或空数组。 */
function explicitDefaults(schema: ZodType): unknown {
  if (schema instanceof ZodDefault) return cloneDeep(schema.def.defaultValue);
  if (schema instanceof ZodObject) {
    return Object.fromEntries(Object.entries(schema.shape).map(([key, value]) => [key, explicitDefaults(value)]));
  }
  return undefined;
}

function record(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && Object.getPrototypeOf(value) === Object.prototype;
}

/** 合并创建新对象，不改写输入或 schema 中可能共享的默认对象。 */
function merge(defaults: Record<string, unknown>, values: Record<string, unknown>): Record<string, unknown> {
  const result: Record<string, unknown> = { ...defaults };
  for (const [key, value] of Object.entries(values)) {
    const fallback = defaults[key];
    result[key] = record(fallback) && record(value) ? merge(fallback, value) : value;
  }
  return result;
}

function unwrap(schema: ZodType): ZodType {
  let current = schema;
  const seen = new Set<ZodType>();
  while (!seen.has(current)) {
    seen.add(current);
    if (current instanceof ZodArray || current instanceof ZodObject) {
      return current;
    } else if (current instanceof ZodPipe) {
      current = current.in as ZodType;
    } else if ('unwrap' in current && typeof current.unwrap === 'function') {
      current = current.unwrap() as ZodType;
    } else {
      return current;
    }
  }
  return current;
}

function atPath(schema: ZodType, path: string): ZodType | null {
  let current = unwrap(schema);
  if (isNotNestedPath(path)) {
    return current instanceof ZodObject ? current.shape[cleanupNonNestedPath(path)] ?? null : null;
  }
  const parts = path.split(/\.|\[(\d+)\]/).filter(Boolean);
  for (const part of parts) {
    current = unwrap(current);
    if (current instanceof ZodObject) {
      const field = current.shape[part] as ZodType | undefined;
      if (!field) return null;
      current = field;
    } else if (current instanceof ZodArray && /^\d+$/.test(part)) {
      current = current.element as ZodType;
    } else {
      return null;
    }
  }
  return current;
}
