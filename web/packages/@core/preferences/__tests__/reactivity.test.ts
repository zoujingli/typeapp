import { expect, it, vi } from 'vitest';
import { watch } from 'vue';

vi.mock('../src/update-css-variables', () => ({ updateCSSVariables: vi.fn() }));

import { PreferenceManager } from '../src/preferences';

it('连续更新后仍通知外部偏好持久化观察者', () => {
  const manager = new PreferenceManager();
  const preferences = manager.getPreferences();
  const observed: boolean[] = [];
  const stop = watch(preferences, value => observed.push(value.tabbar.enable), { deep: true, flush: 'sync' });
  try {
    manager.updatePreferences({ tabbar: { enable: false } });
    manager.updatePreferences({ tabbar: { enable: true } });
    expect(observed).toEqual([false, true]);
  } finally { stop(); }
});

it('恢复端默认值时保留当前设备断点', async () => {
  vi.stubGlobal('document', { documentElement: { classList: { toggle: vi.fn() } } });
  try {
    const manager = new PreferenceManager();
    manager.updatePreferences({ app: { isMobile: true }, theme: { mode: 'dark' } });
    manager.setInitialPreferences({ theme: { mode: 'light' } });
    await manager.resetPreferences();
    expect(manager.getPreferences().theme.mode).toBe('light');
    expect(manager.getPreferences().app.isMobile).toBe(true);
  } finally { vi.unstubAllGlobals(); }
});
