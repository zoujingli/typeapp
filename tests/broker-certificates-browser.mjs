import { chromium, expect } from '../web/node_modules/@playwright/test/index.mjs';
import { writeFileSync, realpathSync, statSync } from 'node:fs';
import { dirname, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { setTimeout as delay } from 'node:timers/promises';
import { brokerPreview } from './broker-resources-browser.mjs';

/**
 * 真实浏览器核对立、平台、租户三端能否看到受信 CA 与证书表单；隐藏页停止轮询。
 * 参数为隔离目录、前端 dist、管理源与预览源；口令只从进程环境读取。
 */

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
if (args.length !== 4) throw new Error('certificates_browser_arguments_invalid');
const base = realpathSync(resolve(root, args[0]));
const dist = realpathSync(resolve(root, args[1]));
const origins = args.slice(2).map(value => new URL(value));
const build = realpathSync(resolve(root, 'build'));
if (!base.startsWith(`${build}${sep}`) || !statSync(base).isDirectory() || !statSync(resolve(dist, 'index.html')).isFile()
  || origins.some(origin => origin.protocol !== 'http:' || !['127.0.0.1', '[::1]'].includes(origin.hostname)
    || origin.username || origin.password || origin.pathname !== '/' || origin.search || origin.hash)) {
  throw new Error('certificates_browser_arguments_invalid');
}
const accounts = {
  standalone: { login: 'access-admin', password: process.env.BROKER_BROWSER_PASSWORD, realm: 'broker' },
  platform: { login: 'platform-admin', password: process.env.APP_BROWSER_PASSWORD, realm: 'admin' },
  tenant: { login: 'customer-admin', password: process.env.APP_BROWSER_CUSTOMER_PASSWORD, realm: 'customer' },
};
const tenantId = process.env.APP_BROWSER_TENANT || '';
const caPem = process.env.BROKER_BROWSER_CA_PEM || '';
const clientPem = process.env.BROKER_BROWSER_CLIENT_PEM || '';
const extraPem = process.env.BROKER_BROWSER_EXTRA_PEM || '';
if (!Object.values(accounts).every(account => typeof account.password === 'string' && account.password.length >= 12 && account.password.length <= 72)
  || !/^[a-f0-9]{32}$/.test(tenantId) || !caPem.includes('BEGIN CERTIFICATE') || !clientPem.includes('BEGIN CERTIFICATE')
  || !extraPem.includes('BEGIN CERTIFICATE')) {
  throw new Error('certificates_browser_fixture_invalid');
}
const secrets = Object.values(accounts).map(account => account.password);
const safeError = error => secrets.reduce((text, secret) => text.replaceAll(secret, '<REDACTED>'), String(error?.stack ?? error)).replaceAll(root, '.').slice(0, 12000);
const report = { status: 'running', fixture: relative(root, base), dist: relative(root, dist), cases: [], errors: [], screenshots: [],
  cleanup: { context: false, browser: false, preview: false } };
const previewOwner = brokerPreview(dist, origins[1], origins[0]);
const preview = previewOwner.server;
const stop = new AbortController();
const onSignal = () => stop.abort(new Error('certificates_browser_interrupted'));
process.on('SIGTERM', onSignal); process.on('SIGINT', onSignal);
const timer = setTimeout(() => stop.abort(new Error('certificates_browser_deadline')), 150000);
let browser; let context; let page; let failed;
const rows = () => page.locator('.ant-table-tbody tr.ant-table-row');
const drawer = () => page.locator('.ant-drawer-content-wrapper:visible').last();
/** 把页面操作限制在总截止时间内，避免隐藏页用例挂死。 */
const bounded = async operation => {
  let onAbort;
  try {
    return await Promise.race([operation, new Promise((_, reject) => {
      onAbort = () => reject(stop.signal.reason); stop.signal.addEventListener('abort', onAbort, { once: true }); if (stop.signal.aborted) onAbort();
    })]);
  } finally { if (onAbort) stop.signal.removeEventListener('abort', onAbort); }
};
/** 失败或关键步骤截图写入隔离目录。 */
const screenshot = async name => {
  const file = `browser-certificates-${name}.png`;
  await page.screenshot({ path: resolve(base, file), animations: 'disabled' });
  report.screenshots.push(file);
};
/** 先等接口响应再点按钮，避免登录或跳转竞态。 */
const actionResponse = async (predicate, action) => {
  const results = await Promise.allSettled([page.waitForResponse(predicate, { timeout: 25000 }), Promise.resolve().then(action)]);
  for (const result of results) if (result.status === 'rejected') throw result.reason;
  return results[0].value;
};
/** 三端各自登录；已登录则先退出，口令不写入报告。 */
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
/** 打开授权页并等到受信 CA 卡片可见。 */
const openAccess = async path => {
  await page.evaluate(path => { location.hash = path; }, path);
  await page.waitForURL(`**/#${path}`);
  await expect(page.getByRole('heading', { name: 'Broker 授权', exact: true })).toBeVisible();
  await expect(page.getByText('受信 CA', { exact: true }).first()).toBeVisible();
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
    const list = await actionResponse(response => response.request().method() === 'GET' && new URL(response.url()).pathname === '/broker/access/cas',
      () => openAccess('/broker/access'));
    expect(list.status()).toBe(200);
    const data = await list.json();
    expect(data.items.length).toBeGreaterThan(0);
    await expect(page.getByLabel('受信 CA 公钥')).toBeVisible();
    await expect(page.getByLabel('签名 CRL')).toBeVisible();
    await expect(page.getByLabel('受控 HTTPS 源')).toBeVisible();
    await expect(page.getByRole('button', { name: '接纳 CRL', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: '平台吊销', exact: true })).toBeVisible();
    await page.getByRole('button', { name: '编辑', exact: true }).first().click();
    await expect(drawer()).toContainText('登记客户端证书公钥');
    await expect(page.getByLabel('换证重叠秒数')).toBeVisible();
    await page.getByLabel('客户端证书公钥').fill(extraPem);
    await expect(page.getByRole('button', { name: '预览换证', exact: true })).toBeEnabled();
    const preview = await actionResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith('/certificate-preview'),
      () => page.getByRole('button', { name: '预览换证', exact: true }).click());
    expect(preview.status()).toBe(200);
    await expect(drawer()).toContainText('新证书');
    await expect(drawer()).toContainText('重叠');
    await screenshot('standalone-ca-and-certificate-form');
    report.cases.push('real-standalone-ca-list-and-certificate-form');
    report.cases.push('real-standalone-certificate-rotation-preview');
    report.cases.push('real-standalone-crl-import-and-https-source');
    await login('tenant');
    await page.waitForURL('**/#/profile');
    await page.evaluate(() => { location.hash = '/tenants'; });
    await page.waitForURL('**/#/tenants');
    await rows().filter({ hasText: tenantId }).getByRole('button', { name: '进入租户', exact: true }).click();
    await page.waitForURL('**/#/profile');
    const tenantList = await actionResponse(response => response.request().method() === 'GET' && new URL(response.url()).pathname.endsWith('/broker/access/cas'),
      () => openAccess('/broker-access'));
    expect(tenantList.status()).toBe(200);
    await expect(page.getByRole('button', { name: '登记 CA', exact: true })).toBeVisible();
    await screenshot('tenant-ca');
    report.cases.push('real-tenant-ca-menu-and-write-action');
    await login('platform');
    await page.waitForURL('**/#/admin/profile');
    const platformList = await actionResponse(response => response.request().method() === 'GET' && new URL(response.url()).pathname === '/admin/broker/access/cas',
      () => openAccess('/admin/broker-access'));
    expect(platformList.status()).toBe(200);
    await screenshot('platform-ca');
    let hidden = 0;
    const count = request => {
      const path = new URL(request.url()).pathname;
      if (request.method() === 'GET' && (path.endsWith('/broker/access/principals') || path.endsWith('/broker/access/cas'))) hidden++;
    };
    await page.evaluate(() => { Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' }); document.dispatchEvent(new Event('visibilitychange')); });
    page.on('request', count);
    try { await delay(5500, undefined, { signal: stop.signal }); expect(hidden).toBe(0); }
    finally { page.off('request', count); await page.evaluate(() => { delete document.visibilityState; document.dispatchEvent(new Event('visibilitychange')); }); }
    report.cases.push('real-platform-certificates-hidden-page-stops-polling');
  })());
  if (report.errors.length) throw new Error('certificates_browser_page_error');
  report.status = 'passed';
} catch (error) {
  failed = error; report.status = 'failed'; report.failure = safeError(error);
  try { await screenshot('failure'); } catch {}
} finally {
  stop.abort(new Error('certificates_browser_finished'));
  clearTimeout(timer); process.off('SIGTERM', onSignal); process.off('SIGINT', onSignal);
  try { await context?.close(); report.cleanup.context = true; } catch (error) { report.errors.push(safeError(error)); }
  try { await browser?.close(); report.cleanup.browser = true; } catch (error) { report.errors.push(safeError(error)); }
  try { await previewOwner.close(); report.cleanup.preview = true; } catch (error) { report.errors.push(safeError(error)); }
  if (report.errors.length || !Object.values(report.cleanup).every(Boolean)) report.status = 'failed';
  writeFileSync(resolve(base, 'browser-certificates-report.json'), `${JSON.stringify(report, null, 2)}\n`, { mode: 0o600 });
}
if (report.status !== 'passed') {
  process.stderr.write('broker_certificates_browser_failed; inspect browser-certificates-report.json\n');
  process.exitCode = 1;
} else process.stdout.write(`${JSON.stringify({ status: report.status, cases: report.cases.length })}\n`);
