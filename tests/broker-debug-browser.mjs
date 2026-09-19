import { chromium, expect } from '../web/node_modules/@playwright/test/index.mjs';
import { writeFileSync, readFileSync, realpathSync, statSync } from 'node:fs';
import { dirname, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
import { once } from 'node:events';
import { setTimeout as delay } from 'node:timers/promises';
import { brokerPreview } from './broker-resources-browser.mjs';

/**
 * 真实浏览器核对独立端签发 WSS 订阅、租户可签发（无节点时提示）及平台隐藏页停止轮询。
 * 第五参数为预分配预览源，须已写入 Broker Origin 白名单。
 */

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
if (args.length !== 5) throw new Error('debug_browser_arguments_invalid');
const base = realpathSync(resolve(root, args[0]));
const dist = realpathSync(resolve(root, args[1]));
const origins = args.slice(2, 4).map(value => new URL(value));
const previewUrl = new URL(args[4]);
const build = realpathSync(resolve(root, 'build'));
if (!base.startsWith(`${build}${sep}`) || !statSync(base).isDirectory() || !statSync(resolve(dist, 'index.html')).isFile()
  || [...origins, previewUrl].some(origin => origin.protocol !== 'http:' || !['127.0.0.1', '[::1]'].includes(origin.hostname)
    || origin.username || origin.password || origin.pathname !== '/' || origin.search || origin.hash)) {
  throw new Error('debug_browser_arguments_invalid');
}
const accounts = {
  standalone: { login: 'access-admin', password: process.env.BROKER_BROWSER_PASSWORD, realm: 'broker' },
  platform: { login: 'platform-admin', password: process.env.APP_BROWSER_PASSWORD, realm: 'admin' },
  tenant: { login: 'customer-admin', password: process.env.APP_BROWSER_CUSTOMER_PASSWORD, realm: 'customer' },
};
const tenantId = process.env.APP_BROWSER_TENANT || '';
if (!Object.values(accounts).every(account => typeof account.password === 'string' && account.password.length >= 12 && account.password.length <= 72)
  || !/^[a-f0-9]{32}$/.test(tenantId)) {
  throw new Error('debug_browser_fixture_invalid');
}
const secrets = [...Object.values(accounts).map(account => account.password), process.env.BROKER_SERVICE_PASSWORD].filter(value => typeof value === 'string' && value.length >= 12);
const safeError = error => secrets.reduce((text, secret) => text.replaceAll(secret, '<REDACTED>'), String(error?.stack ?? error)).replaceAll(root, '.').slice(0, 12000);
const report = { status: 'running', fixture: relative(root, base), dist: relative(root, dist), cases: [], errors: [], screenshots: [],
  cleanup: { context: false, browser: false, preview: false } };
const previewOwner = brokerPreview(dist, origins[1], origins[0]);
const preview = previewOwner.server;
const stop = new AbortController();
const onSignal = () => stop.abort(new Error('debug_browser_interrupted'));
process.on('SIGTERM', onSignal); process.on('SIGINT', onSignal);
const timer = setTimeout(() => stop.abort(new Error('debug_browser_deadline')), 180000);
let browser; let context; let page; let failed;
const bounded = async operation => {
  let onAbort;
  try {
    return await Promise.race([operation, new Promise((_, reject) => {
      onAbort = () => reject(stop.signal.reason); stop.signal.addEventListener('abort', onAbort, { once: true }); if (stop.signal.aborted) onAbort();
    })]);
  } finally { if (onAbort) stop.signal.removeEventListener('abort', onAbort); }
};
const screenshot = async name => {
  const file = `browser-debug-${name}.png`;
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
const openDebug = async path => {
  const list = await actionResponse(response => response.request().method() === 'GET' && new URL(response.url()).pathname.endsWith('/broker/debug'),
    async () => {
      await page.evaluate(path => { location.hash = path; }, path);
      await page.waitForURL(`**/#${path}`);
    });
  await expect(page.getByRole('heading', { name: 'Broker 调试订阅', exact: true })).toBeVisible();
  await expect(page.locator('.ant-spin-spinning')).toHaveCount(0);
  return list;
};
try {
  await bounded(new Promise((resolve, reject) => { preview.once('error', reject); preview.listen(Number(previewUrl.port), previewUrl.hostname, resolve); }));
  report.preview = previewUrl.origin;
  browser = await chromium.launch({ channel: 'msedge', headless: true, timeout: 30000 });
  report.browser = browser.version();
  context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, timezoneId: 'Asia/Shanghai', ignoreHTTPSErrors: true });
  page = await context.newPage();
  page.setDefaultTimeout(10000); page.setDefaultNavigationTimeout(25000);
  page.on('pageerror', error => report.errors.push(safeError(error)));
  await bounded((async () => {
    await login('standalone');
    await page.waitForURL('**/#/broker/nodes');
    await openDebug('/broker/debug');
    const issued = await actionResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith('/broker/debug') && !new URL(response.url()).pathname.endsWith('/revoke'),
      () => page.getByRole('button', { name: '签发凭据', exact: true }).click());
    expect(issued.status()).toBe(200);
    await expect(page.getByText('已签发短期凭据并开始订阅')).toBeVisible();
    await expect(page.getByText(/debug:[a-f0-9]{32}/)).toBeVisible();
    await expect(page.getByText('已订阅')).toBeVisible({ timeout: 20000 });
    await page.getByRole('button', { name: '向测试 Topic 发布', exact: true }).click();
    await expect(page.getByText('debug-ping')).toBeVisible({ timeout: 10000 });
    await expect(page.locator('.debug-meta dd').filter({ hasText: /^本账号 [1-2]\/2，全局 [1-2]\/20$/ })).toBeVisible({ timeout: 10000 });
    await expect(page.getByText('未知', { exact: true }).first()).toBeVisible();
    await page.getByLabel('测试发布内容', { exact: true }).fill('x'.repeat(5000));
    await page.getByRole('button', { name: '向测试 Topic 发布', exact: true }).click();
    await expect(page.getByText('已写出。QoS 0 无 MQTT 回执，不是业务成功', { exact: false }).nth(1)).toBeVisible({ timeout: 10000 });
    const api = origins[0].origin;
    const token = await page.evaluate(() => sessionStorage.getItem('typeapp.broker.token'));
    if (typeof token !== 'string' || token.length < 32) throw new Error('debug_browser_token_missing');
    const quotas = await (await page.request.get(`${api}/broker/quotas`, { headers: { Authorization: `Bearer ${token}` } })).json();
    const lowered = await page.request.post(`${api}/broker/quotas`, {
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      data: { expected_version: quotas.current_version, maximumServiceConnections: 1, confirmed: true },
    });
    expect(lowered.status()).toBe(200);
    const quotaReady = current => current.limits?.maximumServiceConnections === 1
      && (current.revision?.status === 'effective' || current.revision?.nodes?.[0]?.state === 'applied');
    const deadline = Date.now() + 12000;
    while (Date.now() < deadline) {
      const current = await (await page.request.get(`${api}/broker/quotas`, { headers: { Authorization: `Bearer ${token}` } })).json();
      if (quotaReady(current)) break;
      await delay(200, undefined, { signal: stop.signal });
    }
    const servicePassword = process.env.BROKER_SERVICE_PASSWORD || '';
    const servicePort = Number(process.env.BROKER_SERVICE_PORT || 0);
    const serviceCa = process.env.BROKER_SERVICE_CA || '';
    if (servicePassword.length < 12 || servicePort < 1 || !serviceCa) throw new Error('debug_browser_service_fixture_invalid');
    const require = createRequire(resolve(root, 'web/apps/web-antd/package.json'));
    const mqtt = require('mqtt');
    const service = mqtt.connect(`mqtts://127.0.0.1:${servicePort}`, {
      protocol: 'mqtts', protocolVersion: 5, clientId: process.env.BROKER_SERVICE_CLIENT_ID || 'service-preempt-browser',
      username: process.env.BROKER_SERVICE_USERNAME || 'service:business', password: servicePassword,
      clean: true, keepalive: 30, reconnectPeriod: 0, connectTimeout: 20000, protocolId: 'MQTT',
      rejectUnauthorized: true, ca: readFileSync(serviceCa), properties: { sessionExpiryInterval: 0 },
    });
    try {
      await Promise.race([once(service, 'connect'), new Promise((_, reject) => setTimeout(() => reject(new Error('业务服务 CONNECT 超时')), 20000))]);
      await expect(page.getByText('业务服务优先，调试连接已释放名额')).toBeVisible({ timeout: 15000 });
    } finally {
      await service.endAsync(true).catch(() => {});
    }
    await screenshot('standalone-debug-issue');
    report.cases.push('real-standalone-debug-issue-and-wss-subscribe');
    report.cases.push('real-standalone-debug-bounded-publish-and-preempt');
    await login('tenant');
    await page.waitForURL('**/#/profile');
    await page.evaluate(() => { location.hash = '/tenants'; });
    await page.waitForURL('**/#/tenants');
    await page.locator('.ant-table-tbody tr.ant-table-row').filter({ hasText: tenantId }).getByRole('button', { name: '进入租户', exact: true }).click();
    await page.waitForURL('**/#/profile');
    const tenantGet = await openDebug('/broker-debug');
    expect(tenantGet.status()).toBe(200);
    await expect(page.getByRole('button', { name: '签发凭据', exact: true })).toBeVisible();
    await screenshot('tenant-debug');
    report.cases.push('real-tenant-debug-can-issue');
    await login('platform');
    await page.waitForURL('**/#/admin/profile');
    const platformGet = await openDebug('/admin/broker-debug');
    expect(platformGet.status()).toBe(403);
    await screenshot('platform-debug');
    let hidden = 0;
    const count = request => {
      const path = new URL(request.url()).pathname;
      if (request.method() === 'GET' && path.endsWith('/broker/debug')) hidden++;
    };
    await page.evaluate(() => { Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' }); document.dispatchEvent(new Event('visibilitychange')); });
    page.on('request', count);
    try { await delay(5500, undefined, { signal: stop.signal }); expect(hidden).toBe(0); }
    finally { page.off('request', count); await page.evaluate(() => { delete document.visibilityState; document.dispatchEvent(new Event('visibilitychange')); }); }
    report.cases.push('real-platform-debug-hidden-page-stops-polling');
  })());
  if (report.errors.length) throw new Error('debug_browser_page_error');
  report.status = 'passed';
} catch (error) {
  failed = error; report.status = 'failed'; report.failure = safeError(error);
  try { await screenshot('failure'); } catch {}
} finally {
  stop.abort(new Error('debug_browser_finished'));
  clearTimeout(timer); process.off('SIGTERM', onSignal); process.off('SIGINT', onSignal);
  try { await context?.close(); report.cleanup.context = true; } catch (error) { report.errors.push(safeError(error)); }
  try { await browser?.close(); report.cleanup.browser = true; } catch (error) { report.errors.push(safeError(error)); }
  try { await previewOwner.close(); report.cleanup.preview = true; } catch (error) { report.errors.push(safeError(error)); }
  if (report.errors.length || !Object.values(report.cleanup).every(Boolean)) report.status = 'failed';
  writeFileSync(resolve(base, 'browser-debug-report.json'), `${JSON.stringify(report, null, 2)}\n`, { mode: 0o600 });
}
if (report.status !== 'passed') {
  process.stderr.write('broker_debug_browser_failed; inspect browser-debug-report.json\n');
  process.exitCode = 1;
} else process.stdout.write(`${JSON.stringify({ status: report.status, cases: report.cases.length })}\n`);
