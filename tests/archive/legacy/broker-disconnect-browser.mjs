import { chromium, expect } from '../web/node_modules/@playwright/test/index.mjs';
import { writeFileSync, realpathSync, statSync } from 'node:fs';
import { dirname, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { setTimeout as delay } from 'node:timers/promises';
import { brokerPreview } from './broker-resources-browser.mjs';

/**
 * 真实浏览器核对独立端确认断开、租户资源页危险动作及平台隐藏页停止轮询。
 * 参数为隔离目录、前端 dist、独立管理源与双端预览源；口令只从进程环境读取。
 */

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
if (args.length !== 4) throw new Error('disconnect_browser_arguments_invalid');
const base = realpathSync(resolve(root, args[0]));
const dist = realpathSync(resolve(root, args[1]));
const origins = args.slice(2).map(value => new URL(value));
const build = realpathSync(resolve(root, 'build'));
if (!base.startsWith(`${build}${sep}`) || !statSync(base).isDirectory() || !statSync(resolve(dist, 'index.html')).isFile()
  || origins.some(origin => origin.protocol !== 'http:' || !['127.0.0.1', '[::1]'].includes(origin.hostname)
    || origin.username || origin.password || origin.pathname !== '/' || origin.search || origin.hash)) {
  throw new Error('disconnect_browser_arguments_invalid');
}
const accounts = {
  standalone: { login: 'access-admin', password: process.env.BROKER_BROWSER_PASSWORD, realm: 'broker' },
  platform: { login: 'platform-admin', password: process.env.APP_BROWSER_PASSWORD, realm: 'admin' },
  tenant: { login: 'customer-admin', password: process.env.APP_BROWSER_CUSTOMER_PASSWORD, realm: 'customer' },
};
const tenantId = process.env.APP_BROWSER_TENANT || '';
const clientId = process.env.BROKER_BROWSER_CLIENT_ID || '';
if (!Object.values(accounts).every(account => typeof account.password === 'string' && account.password.length >= 12 && account.password.length <= 72)
  || !/^[a-f0-9]{32}$/.test(tenantId) || clientId === '') {
  throw new Error('disconnect_browser_fixture_invalid');
}
const secrets = Object.values(accounts).map(account => account.password);
const safeError = error => secrets.reduce((text, secret) => text.replaceAll(secret, '<REDACTED>'), String(error?.stack ?? error)).replaceAll(root, '.').slice(0, 12000);
const report = { status: 'running', fixture: relative(root, base), dist: relative(root, dist), cases: [], errors: [], screenshots: [],
  cleanup: { context: false, browser: false, preview: false } };
const previewOwner = brokerPreview(dist, origins[1], origins[0]);
const preview = previewOwner.server;
const stop = new AbortController();
const onSignal = () => stop.abort(new Error('disconnect_browser_interrupted'));
process.on('SIGTERM', onSignal); process.on('SIGINT', onSignal);
const timer = setTimeout(() => stop.abort(new Error('disconnect_browser_deadline')), 150000);
let browser; let context; let page; let failed;
const rows = () => page.locator('.ant-table-tbody tr.ant-table-row');
const bounded = async operation => {
  let onAbort;
  try {
    return await Promise.race([operation, new Promise((_, reject) => {
      onAbort = () => reject(stop.signal.reason); stop.signal.addEventListener('abort', onAbort, { once: true }); if (stop.signal.aborted) onAbort();
    })]);
  } finally { if (onAbort) stop.signal.removeEventListener('abort', onAbort); }
};
const screenshot = async name => {
  const file = `browser-disconnect-${name}.png`;
  await page.screenshot({ path: resolve(base, file), animations: 'disabled' });
  report.screenshots.push(file);
};
const actionResponse = async (predicate, action) => {
  const results = await Promise.allSettled([page.waitForResponse(predicate, { timeout: 25000 }), Promise.resolve().then(action)]);
  for (const result of results) if (result.status === 'rejected') throw result.reason;
  return results[0].value;
};
const login = async kind => {
  const account = accounts[kind];
  if (await page.getByRole('button', { name: '退出登录', exact: true }).count()) {
    await page.getByRole('button', { name: '退出登录', exact: true }).click();
    await page.waitForURL(/#\/(?:admin\/|broker\/)?login$/);
  }
  const path = kind === 'standalone' ? '/broker/login' : kind === 'platform' ? '/admin/login' : '/login';
  await page.goto(`${report.preview}/#${path}`);
  await page.getByLabel('登录账号', { exact: true }).fill(account.login);
  await page.getByLabel('登录密码', { exact: true }).fill(account.password);
  const response = await actionResponse(response => new URL(response.url()).pathname === `/${account.realm}/auth/login` && response.request().method() === 'POST',
    () => page.getByRole('button', { name: /^登\s*录$/ }).click());
  expect(response.status()).toBe(200);
};
const openResources = async path => {
  await page.evaluate(path => { location.hash = path; }, path);
  await page.waitForURL(`**/#${path}`);
  await expect(page.getByRole('heading', { name: 'Broker 资源', exact: true })).toBeVisible();
  await expect(page.locator('.ant-spin-spinning')).toHaveCount(0);
};
try {
  await bounded(new Promise((resolve, reject) => { preview.once('error', reject); preview.listen(0, '127.0.0.1', resolve); }));
  report.preview = `http://127.0.0.1:${preview.address().port}`;
  browser = await chromium.launch({ channel: 'msedge', headless: true, timeout: 30000 });
  report.browser = browser.version();
  context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, timezoneId: 'Asia/Shanghai' });
  page = await context.newPage();
  page.setDefaultTimeout(10000); page.setDefaultNavigationTimeout(25000);
  page.on('pageerror', error => report.errors.push(safeError(error)));
  await bounded((async () => {
    await login('standalone');
    await page.waitForURL('**/#/broker/nodes');
    const list = await actionResponse(response => response.request().method() === 'GET' && new URL(response.url()).pathname === '/broker/resources/connections',
      () => openResources('/broker/resources'));
    expect(list.status()).toBe(200);
    const data = await list.json();
    expect(data.items.some(item => item.client_id === clientId && item.state === 'connected')).toBe(true);
    const target = rows().filter({ hasText: clientId }).first();
    await expect(target.getByRole('button', { name: '断开', exact: true })).toBeVisible();
    await target.getByRole('button', { name: '断开', exact: true }).click();
    await expect(page.getByText('将发送管理断开并保留仍有效的持久会话', { exact: false })).toBeVisible();
    const submitted = await actionResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith('/disconnect'),
      () => page.getByRole('button', { name: '断开连接', exact: true }).click());
    expect(submitted.status()).toBe(200);
    const submittedBody = await submitted.json();
    expect(submittedBody.operation_id).toMatch(/^[a-f0-9]{32}$/);
    await page.waitForResponse(response => {
      if (response.request().method() !== 'GET') return false;
      const path = new URL(response.url()).pathname;
      return path === `/broker/operations/${submittedBody.operation_id}` && response.status() === 200;
    }, { timeout: 12000 });
    await expect(page.locator('.ant-spin-spinning')).toHaveCount(0);
    await screenshot('standalone-disconnect-confirm');
    report.cases.push('real-standalone-connection-disconnect-confirm');
    await login('tenant');
    await page.waitForURL('**/#/profile');
    await page.evaluate(() => { location.hash = '/tenants'; });
    await page.waitForURL('**/#/tenants');
    await rows().filter({ hasText: tenantId }).getByRole('button', { name: '进入租户', exact: true }).click();
    await page.waitForURL('**/#/profile');
    const tenantList = await actionResponse(response => response.request().method() === 'GET' && new URL(response.url()).pathname.endsWith('/broker/resources/connections'),
      () => openResources('/broker-resources'));
    expect(tenantList.status()).toBe(200);
    await expect(page.getByRole('heading', { name: 'Broker 资源', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: '断开', exact: true })).toHaveCount(0);
    await screenshot('tenant-resources');
    report.cases.push('real-tenant-resources-page-without-live-connection');
    await login('platform');
    await page.waitForURL('**/#/admin/profile');
    const platformList = await actionResponse(response => response.request().method() === 'GET' && new URL(response.url()).pathname === '/admin/broker/resources/connections',
      () => openResources('/admin/broker'));
    expect(platformList.status()).toBe(200);
    await screenshot('platform-resources');
    let hidden = 0;
    const count = request => {
      const path = new URL(request.url()).pathname;
      if (request.method() === 'GET' && (path.endsWith('/broker/resources/connections') || path.includes('/broker/operations/'))) hidden++;
    };
    await page.evaluate(() => { Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' }); document.dispatchEvent(new Event('visibilitychange')); });
    page.on('request', count);
    try { await delay(5500, undefined, { signal: stop.signal }); expect(hidden).toBe(0); }
    finally { page.off('request', count); await page.evaluate(() => { delete document.visibilityState; document.dispatchEvent(new Event('visibilitychange')); }); }
    report.cases.push('real-platform-resources-hidden-page-stops-polling');
  })());
  if (report.errors.length) throw new Error('disconnect_browser_page_error');
  report.status = 'passed';
} catch (error) {
  failed = error; report.status = 'failed'; report.failure = safeError(error);
  try { await screenshot('failure'); } catch {}
} finally {
  stop.abort(new Error('disconnect_browser_finished'));
  clearTimeout(timer); process.off('SIGTERM', onSignal); process.off('SIGINT', onSignal);
  try { await context?.close(); report.cleanup.context = true; } catch (error) { report.errors.push(safeError(error)); }
  try { await browser?.close(); report.cleanup.browser = true; } catch (error) { report.errors.push(safeError(error)); }
  try { await previewOwner.close(); report.cleanup.preview = true; } catch (error) { report.errors.push(safeError(error)); }
  if (report.errors.length || !Object.values(report.cleanup).every(Boolean)) report.status = 'failed';
  writeFileSync(resolve(base, 'browser-disconnect-report.json'), `${JSON.stringify(report, null, 2)}\n`, { mode: 0o600 });
}
if (report.status !== 'passed') {
  process.stderr.write('broker_disconnect_browser_failed; inspect browser-disconnect-report.json\n');
  process.exitCode = 1;
} else process.stdout.write(`${JSON.stringify({ status: report.status, cases: report.cases.length })}\n`);
