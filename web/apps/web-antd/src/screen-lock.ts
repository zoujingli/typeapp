import { useAccessStore } from '@vben/stores';

interface LockRecord { salt: string; digest: string }
let storageKey = '';
const hex = (value: ArrayBuffer | Uint8Array) => [...new Uint8Array(value)].map(byte => byte.toString(16).padStart(2, '0')).join('');

function read(): LockRecord | null {
  if (!storageKey) return null;
  try {
    const value = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
    if (value && typeof value.salt === 'string' && typeof value.digest === 'string' && /^[a-f0-9]{32}$/.test(value.salt) && /^[a-f0-9]{64}$/.test(value.digest)) return value;
  } catch { /* 损坏的展示状态不参与恢复。 */ }
  sessionStorage.removeItem(storageKey);
  return null;
}

async function digest(password: string, salt: string) {
  const key = await crypto.subtle.importKey('raw', new TextEncoder().encode(password), 'PBKDF2', false, ['deriveBits']);
  return hex(await crypto.subtle.deriveBits({ name: 'PBKDF2', salt: Uint8Array.from(salt.match(/../g)!, byte => Number.parseInt(byte, 16)), iterations: 100_000, hash: 'SHA-256' }, key, 256));
}

/** 只保存本标签页当前会话的盐和摘要；账号、入口及模拟身份变化后删除旧锁。 */
export function bindScreenLockScope(scope: string) {
  const next = scope ? `typeapp.lock.${scope}` : '';
  if (storageKey && next !== storageKey) sessionStorage.removeItem(storageKey);
  storageKey = next;
  if (read()) useAccessStore().lockScreen('');
}

export function clearScreenLock() {
  if (storageKey) sessionStorage.removeItem(storageKey);
}

export async function lockScreen(password: string): Promise<boolean> {
  const key = storageKey;
  if (!key || !password) return false;
  const salt = hex(crypto.getRandomValues(new Uint8Array(16)));
  const record = { salt, digest: await digest(password, salt) };
  if (storageKey !== key) return false;
  sessionStorage.setItem(key, JSON.stringify(record));
  useAccessStore().lockScreen('');
  return true;
}

export async function verifyScreenPassword(password: string): Promise<boolean> {
  const key = storageKey;
  const record = read();
  return !!record && await digest(password, record.salt) === record.digest && key === storageKey;
}
