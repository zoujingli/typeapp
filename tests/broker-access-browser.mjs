import { chromium, expect } from '../web/node_modules/@playwright/test/index.mjs';
import { writeFileSync, realpathSync, statSync } from 'node:fs';
import { dirname, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { setTimeout as delay } from 'node:timers/promises';
import { brokerPreview } from './broker-resources-browser.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
if (args.length !== 4) throw new Error('access_browser_arguments_invalid');
const base = realpathSync(resolve(root, args[0]));
const dist = realpathSync(resolve(root, args[1]));
const origins = args.slice(2).map(value => new URL(value));
const build = realpathSync(resolve(root, 'build'));
if (!base.startsWith(`${build}${sep}`) || !statSync(base).isDirectory() || !statSync(resolve(dist, 'index.html')).isFile()
  || origins.some(origin => origin.protocol !== 'http:' || !['127.0.0.1', '[::1]'].includes(origin.hostname)
    || origin.username || origin.password || origin.pathname !== '/' || origin.search || origin.hash)) {
  throw new Error('access_browser_arguments_invalid');
}
const accounts = {
  standalone: { login: 'access-admin', password: process.env.BROKER_BROWSER_PASSWORD, realm: 'broker' },
  platform: { login: 'platform-admin', password: process.env.APP_BROWSER_PASSWORD, realm: 'admin' },
  tenant: { login: 'customer-admin', password: process.env.APP_BROWSER_CUSTOMER_PASSWORD, realm: 'customer' },
};
const tenantId = process.env.APP_BROWSER_TENANT || '';
if (!Object.values(accounts).every(account => typeof account.password === 'string' && account.password.length >= 12 && account.password.length <= 72)
  || !/^[a-f0-9]{32}$/.test(tenantId)) throw new Error('access_browser_fixture_invalid');
const secrets = Object.values(accounts).map(account => account.password);
const safeError = error => secrets.reduce((text, secret) => text.replaceAll(secret, '<REDACTED>'), String(error?.stack ?? error)).replaceAll(root, '.').slice(0, 12000);
const report = { status: 'running', fixture: relative(root, base), dist: relative(root, dist), cases: [], errors: [], screenshots: [],
  cleanup: { context: false, browser: false, preview: false } };
const previewOwner = brokerPreview(dist, origins[1], origins[0]);
const preview = previewOwner.server;
const stop = new AbortController();
const onSignal = () => stop.abort(new Error('access_browser_interrupted'));
process.on('SIGTERM', onSignal); process.on('SIGINT', onSignal);
const timer = setTimeout(() => stop.abort(new Error('access_browser_deadline')), 150000);
let browser; let context; let page; let failed;
const rows = () => page.locator('.ant-table-tbody tr.ant-table-row');
const drawer = () => page.locator('.ant-drawer-content-wrapper:visible').last();
const bounded = async operation => {
  let onAbort;
  try {
    return await Promise.race([operation, new Promise((_, reject) => {
      onAbort = () => reject(stop.signal.reason); stop.signal.addEventListener('abort', onAbort, { once: true }); if (stop.signal.aborted) onAbort();
    })]);
  } finally { if (onAbort) stop.signal.removeEventListener('abort', onAbort); }
};
const screenshot = async name => {
  const file = `browser-access-${name}.png`;
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
  const closer = page.locator('.ant-drawer-close:visible');
  if (await closer.count()) {
    await closer.last().click();
    await expect(page.locator('.ant-drawer-content-wrapper:visible')).toHaveCount(0);
  }
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
const openAccess = async path => {
  await page.evaluate(path => { location.hash = path; }, path);
  await page.waitForURL(`**/#${path}`);
  await expect(page.getByRole('heading', { name: 'Broker 授权', exact: true })).toBeVisible();
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
    const list = await actionResponse(response => response.request().method() === 'GET' && new URL(response.url()).pathname === '/broker/access/principals',
      () => openAccess('/broker/access'));
    expect(list.status()).toBe(200);
    const data = await list.json();
    expect(data.items.length).toBeGreaterThan(0);
    await expect(rows()).toHaveCount(data.items.length);
    await page.getByRole('button', { name: '版本生效', exact: true }).click();
    await expect(drawer()).toContainText('授权版本生效');
    await screenshot('standalone-history');
    report.cases.push('real-standalone-access-list-and-version-history');
    await login('tenant');
    await page.waitForURL('**/#/profile');
    await page.evaluate(() => { location.hash = '/tenants'; });
    await page.waitForURL('**/#/tenants');
    await rows().filter({ hasText: tenantId }).getByRole('button', { name: '进入租户', exact: true }).click();
    await page.waitForURL('**/#/profile');
    const tenantList = await actionResponse(response => response.request().method() === 'GET' && new URL(response.url()).pathname.endsWith('/broker/access/principals'),
      () => openAccess('/broker-access'));
    expect(tenantList.status()).toBe(200);
    await expect(page.getByRole('button', { name: '新增主体', exact: true })).toBeVisible();
    await screenshot('tenant-list');
    report.cases.push('real-tenant-access-menu-and-write-action');
    await login('platform');
    await page.waitForURL('**/#/admin/profile');
    const platformList = await actionResponse(response => response.request().method() === 'GET' && new URL(response.url()).pathname === '/admin/broker/access/principals',
      () => openAccess('/admin/broker-access'));
    expect(platformList.status()).toBe(200);
    await screenshot('platform-list');
    let hidden = 0;
    const count = request => { if (request.method() === 'GET' && new URL(request.url()).pathname.endsWith('/broker/access/principals')) hidden++; };
    await page.evaluate(() => { Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' }); document.dispatchEvent(new Event('visibilitychange')); });
    page.on('request', count);
    try { await delay(5500, undefined, { signal: stop.signal }); expect(hidden).toBe(0); }
    finally { page.off('request', count); await page.evaluate(() => { delete document.visibilityState; document.dispatchEvent(new Event('visibilitychange')); }); }
    report.cases.push('real-platform-access-and-hidden-page-stops-polling');
  })());
  if (report.errors.length) throw new Error('access_browser_page_error');
  report.status = 'passed';
} catch (error) {
  failed = error; report.status = 'failed'; report.failure = safeError(error);
  try { await screenshot('failure'); } catch {}
} finally {
  stop.abort(new Error('access_browser_finished'));
  clearTimeout(timer); process.off('SIGTERM', onSignal); process.off('SIGINT', onSignal);
  try { await context?.close(); report.cleanup.context = true; } catch (error) { report.errors.push(safeError(error)); }
  try { await browser?.close(); report.cleanup.browser = true; } catch (error) { report.errors.push(safeError(error)); }
  try { await previewOwner.close(); report.cleanup.preview = true; } catch (error) { report.errors.push(safeError(error)); }
  if (report.errors.length || !Object.values(report.cleanup).every(Boolean)) report.status = 'failed';
  writeFileSync(resolve(base, 'browser-access-report.json'), `${JSON.stringify(report, null, 2)}\n`, { mode: 0o600 });
}
if (report.status !== 'passed') {
  process.stderr.write('broker_access_browser_failed; inspect browser-access-report.json\n');
  process.exitCode = 1;
} else process.stdout.write(`${JSON.stringify({ status: report.status, cases: report.cases.length })}\n`);
