import { preferences } from '@vben/preferences';

/** API 时间仍以 UTC 保存；显示跟随当前账号的时区偏好，datetime-local 输入仍使用浏览器本地时间。 */
export function displayDateTime(value: Date | number | string, onlyTime = false): string {
  const date = value instanceof Date ? value : new Date(value);
  if (!Number.isFinite(date.getTime())) return '—';
  return new Intl.DateTimeFormat(preferences.app.locale, {
    timeZone: preferences.app.timezone,
    ...(onlyTime ? {} : { dateStyle: 'short' as const }),
    timeStyle: 'medium',
  }).format(date);
}
