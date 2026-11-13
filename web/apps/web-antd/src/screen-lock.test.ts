import { beforeEach, expect, it, vi } from 'vitest';

const store = vi.hoisted(() => ({ isLockScreen: false, lockScreenPassword: '', lockScreen(password: string) { this.isLockScreen = true; this.lockScreenPassword = password; } }));
vi.mock('@vben/stores', () => ({ useAccessStore: () => store }));

beforeEach(() => {
  vi.resetModules();
  const entries = new Map<string, string>();
  vi.stubGlobal('sessionStorage', {
    getItem: (key: string) => entries.get(key) ?? null,
    setItem: (key: string, value: string) => { entries.set(key, value); },
    removeItem: (key: string) => { entries.delete(key); },
  });
  store.isLockScreen = false;
  store.lockScreenPassword = '';
});

it('锁屏摘要可在同一会话刷新后恢复，错误口令拒绝且不保存明文', async () => {
  let lock = await import('./screen-lock');
  lock.bindScreenLockScope('system.tenant-a.user-a.actor-a.session-a');
  expect(await lock.lockScreen('temporary-screen-password')).toBe(true);
  const saved = sessionStorage.getItem('typeapp.lock.system.tenant-a.user-a.actor-a.session-a')!;
  expect(saved).not.toContain('temporary-screen-password');
  expect(store.lockScreenPassword).toBe('');
  expect(await lock.verifyScreenPassword('wrong')).toBe(false);
  store.isLockScreen = false;
  vi.resetModules();
  lock = await import('./screen-lock');
  lock.bindScreenLockScope('system.tenant-a.user-a.actor-a.session-a');
  expect(store.isLockScreen).toBe(true);
  expect(await lock.verifyScreenPassword('temporary-screen-password')).toBe(true);
  lock.clearScreenLock();
  expect(await lock.verifyScreenPassword('temporary-screen-password')).toBe(false);
});

it('切换 Tenant、模拟操作者或来源会话后不能沿用旧锁', async () => {
  const lock = await import('./screen-lock');
  for (const next of ['system.tenant-b.user-a.actor-a.session-a', 'system.tenant-a.user-a.actor-b.session-a', 'system.tenant-a.user-a.actor-a.session-b']) {
    lock.bindScreenLockScope('system.tenant-a.user-a.actor-a.session-a');
    await lock.lockScreen('temporary-screen-password');
    lock.bindScreenLockScope(next);
    expect(await lock.verifyScreenPassword('temporary-screen-password')).toBe(false);
    expect(sessionStorage.getItem('typeapp.lock.system.tenant-a.user-a.actor-a.session-a')).toBeNull();
  }
});
