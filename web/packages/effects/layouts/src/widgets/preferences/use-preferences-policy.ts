import type { InjectionKey } from 'vue';

import { inject, provide } from 'vue';

const policyKey: InjectionKey<(path: string) => boolean> = Symbol('preferences-policy');

/** 应用可限制原生偏好编辑器的字段；未配置时保留上游全部选项。 */
export function providePreferencesPolicy(editable: (path: string) => boolean) {
  provide(policyKey, editable);
}

export function usePreferencesPolicy() {
  return inject(policyKey, () => true);
}
