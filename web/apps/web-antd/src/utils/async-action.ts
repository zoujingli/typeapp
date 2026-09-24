import { computed, readonly, ref } from 'vue';

/** 按钮操作可同步返回或返回 Promise，调用方负责业务错误展示。 */
export type AsyncActionHandler<T = unknown> = () => Promise<T> | T;

interface UseAsyncActionOptions {
  /**
   * 同步操作也保留一个极短 pending 窗口（毫秒），拦截双击/连点造成的重复弹窗或重复请求。
   */
  minimumDuration?: number;
}

/** 只检查 then 能力，允许跨 realm 的 Promise 或兼容对象。 */
export function isPromiseLike<T = unknown>(value: unknown): value is PromiseLike<T> {
  return Boolean(value && typeof (value as PromiseLike<T>).then === 'function');
}

/**
 * 统一管理按钮/表格操作的异步执行状态：同一作用域内任一操作执行中时，后续点击直接忽略。
 * 适合列表行操作、搜索/导出等需要防重复提交的高频按钮场景。
 */
export function useAsyncAction(options: UseAsyncActionOptions = {}) {
  const pendingKey = ref('');
  const running = computed(() => pendingKey.value !== '');
  const minimumDuration = options.minimumDuration ?? 220;

  function isPending(key: string) {
    return pendingKey.value === key;
  }

  /** 同一实例串行接收点击；无处理器或已有操作时返回 undefined，异常继续传播。 */
  async function run<T>(key: string, handler?: AsyncActionHandler<T>) {
    if (running.value || !handler) {
      return undefined;
    }

    pendingKey.value = key;
    const startedAt = Date.now();
    try {
      return await handler();
    } finally {
      const remaining = minimumDuration - (Date.now() - startedAt);
      if (remaining > 0) {
        await new Promise((resolve) => setTimeout(resolve, remaining));
      }
      pendingKey.value = '';
    }
  }

  return {
    isPending,
    pendingKey: readonly(pendingKey),
    run,
    running,
  };
}
