import { chromium, expect } from '../web/node_modules/@playwright/test/index.mjs';
import { readFileSync, realpathSync, writeFileSync } from 'node:fs';
import { resolve, sep } from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';
import { brokerPreview } from './broker-resources-browser.mjs';

// MQTT 入口沿用上层真实注册和登录；这里只读取同一设备的页面，不写遥测装置。
if (process.argv[2] === '--mqtt') {
  await mqttHistoryBrowser();
  process.exit(0);
}

const fixture = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const origin = process.argv[3];
if (!origin) throw new Error('用法：node tests/iot-history-browser.mjs <fixture.json> <Web地址>');
const report = { status: 'running', cases: [], errors: [], screenshots: [], fixture: fixture.base };
const browser = await chromium.launch({ channel: 'msedge', headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, timezoneId: 'Asia/Shanghai' });
const page = await context.newPage();
page.on('pageerror', error => report.errors.push(error.message));
const screenshot = async name => { const path = `${fixture.base}/${name}.png`; await page.screenshot({ path, animations: 'disabled' }); report.screenshots.push(path); };
const stableDrawer = async () => {
  const wrapper = page.locator('.ant-drawer-content-wrapper:visible');
  await expect.poll(async () => { const box = await wrapper.boundingBox(); return box ? Math.round(box.x + box.width) : 0; }).toBe(page.viewportSize().width);
  await expect.poll(() => page.locator('.ant-drawer-body:visible').evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1);
};
const enterTenant = async name => {
  await page.getByRole('row').filter({ hasText: name }).getByRole('button', { name: '进入租户' }).click();
  await page.waitForURL('**/#/profile');
};
const historyResponse = () => page.waitForResponse(response => response.url().includes(`/devices/${fixture.device}/history`) && response.request().method() === 'GET');
const search = async () => { const response = historyResponse(); await page.getByRole('button', { name: /^查\s*询$/ }).click(); const result = await response; await expect(page.locator('.ant-spin-spinning')).toHaveCount(0); return result; };
try {
  report.browser = browser.version();
  await page.goto(origin);
  await page.getByLabel('登录账号').fill('webread'); await page.getByLabel('登录密码').fill('History-browser-password-2026');
  await page.getByRole('button', { name: /^登\s*录$/ }).click();
  await page.waitForURL('**/#/profile'); await page.goto(`${origin}/#/tenants`);
  await enterTenant('历史数据验收组织');
  await page.evaluate(() => { location.hash = '/devices'; });
  await page.getByRole('row').filter({ hasText: '历史曲线设备' }).getByRole('button', { name: /^历\s*史$/ }).click();
  await expect(page.getByRole('heading', { name: '历史数据', exact: true })).toBeVisible();
  await expect(page.getByText('当前保留 2051 条 · 第 1 页')).toBeVisible();
  await expect(page.getByLabel('历史设备标识')).toHaveValue(fixture.device);
  await expect(page.getByText('Asia/Shanghai', { exact: false })).toBeVisible();
  await page.getByRole('button', { name: /^详\s*情$/ }).first().click();
  await expect(page.getByText('原始记录详情', { exact: true })).toBeVisible();
  await expect(page.getByText('原始长文本', { exact: false }).last()).toContainText('<script>仅作为文字</script>');
  await expect(page.locator('.ant-drawer script')).toHaveCount(0);
  await stableDrawer();
  await screenshot('history-detail-desktop');
  await page.getByRole('button', { name: /^关\s*闭$/ }).last().click();
  const nextResponse = historyResponse(); await page.getByRole('button', { name: '下一页', exact: true }).click();
  expect((await nextResponse).status()).toBe(200); await expect(page.getByText('当前保留 2051 条 · 第 2 页')).toBeVisible();
  await page.getByRole('button', { name: '上一页', exact: true }).click(); await expect(page.getByText('当前保留 2051 条 · 第 1 页')).toBeVisible();
  report.cases.push('readonly-device-navigation-default-24h-20-stable-pages-historical-detail-long-text');
  const curveResponse = historyResponse(); await page.getByRole('button', { name: /^曲\s*线$/ }).first().click();
  const curve = (await (await curveResponse).json()).data;
  expect(curve.function).toBe('display_average'); expect(curve.points.length).toBeLessThanOrEqual(2000);
  expect(curve.points.some(point => point.value === null)).toBe(true);
  await expect(page.locator('svg.history-chart')).toBeVisible(); await expect(page.getByText(/每 \d+ 秒显示均值/)).toBeVisible();
  report.cases.push('numeric-curve-fixed-model-ownership-explicit-buckets-null-gaps');
  await page.locator('.history-chart-viewport').scrollIntoViewIfNeeded();
  await screenshot('history-desktop-curve');
  await page.getByLabel('历史属性标识').fill('note');
  const notes = await search(); expect(notes.status()).toBe(200); await expect(page.getByText('当前保留 2 条 · 第 1 页')).toBeVisible();
  await page.getByLabel('历史属性标识').fill('unknown'); await search(); await expect(page.getByText('此范围暂无原始数据')).toBeVisible();
  await page.getByLabel('历史属性标识').fill("x'"); const invalid = await search(); expect(invalid.status()).toBe(422); await expect(page.getByRole('alert')).toBeVisible();
  await page.getByLabel('历史属性标识').fill(''); await search(); await expect(page.getByRole('alert')).toHaveCount(0);
  report.cases.push('attribute-filter-empty-server-validation-recovery');
  await context.setOffline(true); await page.getByRole('button', { name: /^查\s*询$/ }).click(); await expect(page.getByRole('alert')).toContainText('网络连接失败');
  await context.setOffline(false); await search(); await expect(page.getByText('当前保留 2051 条 · 第 1 页')).toBeVisible();
  report.cases.push('offline-failure-and-retry');
  await page.setViewportSize({ width: 390, height: 844 });
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBe(390);
  await expect.poll(async () => Math.round((await page.getByRole('heading', { name: '历史数据', exact: true }).boundingBox()).x)).toBeGreaterThanOrEqual(0);
  await page.getByRole('heading', { name: '历史数据', exact: true }).scrollIntoViewIfNeeded();
  await screenshot('history-mobile-dark');
  await page.getByRole('button', { name: 'light', exact: true }).click(); await expect(page.locator('html')).not.toHaveClass(/dark/);
  await page.getByRole('button', { name: '主题色', exact: true }).click(); await page.getByLabel('主题色', { exact: true }).click(); await page.getByText('紫罗兰', { exact: true }).click();
  await page.getByText('默认查询最近 24 小时。', { exact: false }).click();
  await page.getByRole('button', { name: /^详\s*情$/ }).first().click(); await stableDrawer(); await screenshot('history-mobile-light-purple-detail');
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBe(390);
  await page.getByRole('button', { name: /^关\s*闭$/ }).last().click();
  const mobileCurve = historyResponse(); await page.getByRole('button', { name: /^曲\s*线$/ }).first().click(); await mobileCurve;
  await expect(page.locator('.history-chart-viewport')).toBeVisible();
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBe(390);
  await page.locator('.history-chart-viewport').scrollIntoViewIfNeeded();
  await page.locator('.history-chart-viewport').evaluate(element => { element.scrollLeft = element.scrollWidth - element.clientWidth; });
  await screenshot('history-mobile-light-purple-curve');
  expect(await page.locator('.history-chart-viewport').evaluate(element => element.scrollWidth > element.clientWidth)).toBe(true);
  report.cases.push('390px-long-text-drawer-dark-light-purple-no-document-overflow');
  await page.setViewportSize({ width: 1440, height: 1000 });
  let release; const gate = new Promise(resolve => { release = resolve; }); let started = 0;
  await page.route(`**/devices/${fixture.device}/history?**`, async route => { started++; const response = await route.fetch(); await gate; try { await route.fulfill({ response }); } catch (error) { if (!/Route is already handled/.test(error.message)) throw error; } });
  await page.getByRole('button', { name: /^查\s*询$/ }).click(); await expect.poll(() => started).toBe(1);
  await expect(page.locator('.crud-search-grid button[type="submit"]')).toBeDisabled();
  await page.evaluate(() => { location.hash = '/tenants'; }); await page.waitForURL('**/#/tenants');
  await enterTenant('历史空数据组织'); release(); await page.unrouteAll({ behavior: 'wait' }); await delay(300);
  await page.evaluate(id => { location.hash = `/history?device=${id}`; }, fixture.device);
  await expect(page.getByRole('alert')).toContainText('设备不存在'); await expect(page.getByText('当前保留 2051 条', { exact: false })).toHaveCount(0);
  report.cases.push('slow-query-disables-repeat-switch-tenant-cancels-no-leak');
  await page.evaluate(() => { location.hash = '/tenants'; }); await page.waitForURL('**/#/tenants'); await enterTenant('历史数据验收组织');
  await page.evaluate(id => { location.hash = `/history?device=${id}`; }, fixture.empty); await expect(page.getByText('此范围暂无原始数据')).toBeVisible();
  report.cases.push('never-reported-device-empty-state');
  const admin = await fetch(`http://127.0.0.1:${fixture.port}/customer/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ login: 'webadmin', password: 'History-browser-password-2026' }) }).then(response => response.json());
  const revoked = await fetch(`http://127.0.0.1:${fixture.port}/customer/members/${fixture.member}`, { method: 'DELETE', headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${admin.data.accessToken}`, 'X-Tenant-Id': fixture.tenant }, body: JSON.stringify({ version: fixture.member_version }) });
  expect(revoked.status).toBe(200); await page.getByRole('button', { name: /^查\s*询$/ }).click(); await page.waitForURL('**/#/tenants');
  await expect(page.getByRole('heading', { name: '历史数据', exact: true })).toHaveCount(0); report.cases.push('membership-revocation-clears-history');
  expect(report.errors).toEqual([]); report.status = 'passed';
} catch (error) { report.status = 'failed'; report.failure = error.stack; await screenshot('failure'); throw error; }
finally { writeFileSync(`${fixture.base}/browser-verification.json`, JSON.stringify(report, null, 2) + '\n'); await browser.close(); console.log(JSON.stringify(report, null, 2)); }

async function mqttHistoryBrowser() {
  const base = realpathSync(process.argv[3]);
  const dist = realpathSync(process.argv[4]);
  const upstream = new URL(process.env.TYPE_DEVICE_HTTP);
  const input = JSON.parse(process.env.TYPE_DEVICE_FIXTURE);
  const device = input.second.device;
  if (!base.startsWith(`${realpathSync('build')}${sep}`) || upstream.protocol !== 'http:' || upstream.hostname !== '127.0.0.1'
    || upstream.username || upstream.password || !input.token) throw new Error('mqtt_browser_fixture_invalid');
  const preview = brokerPreview(dist, upstream);
  const report = { status: 'running', source: 'real-mqtt-tls-synchronous-ingestion', cases: [], screenshots: [], errors: [], cleanup: {} };
  let browser;
  let page;
  const safe = error => String(error?.stack || error).replaceAll(input.token, '<REDACTED>').replaceAll(process.cwd(), '.');
  const interrupt = () => { void browser?.close(); };
  process.on('SIGTERM', interrupt); process.on('SIGINT', interrupt);
  const deadline = setTimeout(interrupt, 60000);
  try {
    await new Promise((resolve, reject) => { preview.server.once('error', reject); preview.server.listen(0, '127.0.0.1', resolve); });
    const origin = `http://127.0.0.1:${preview.server.address().port}`;
    browser = await chromium.launch({ channel: 'msedge', headless: true, timeout: 20000 });
    report.browser = browser.version();
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, timezoneId: 'Asia/Shanghai' });
    context.setDefaultTimeout(10000);
    await context.addInitScript(({ token, tenant }) => {
      sessionStorage.setItem('typeapp.customer.token', token); sessionStorage.setItem('typeapp.customer.tenant', tenant);
    }, { token: input.token, tenant: input.tenant });
    page = await context.newPage();
    page.on('pageerror', error => report.errors.push(safe(error)));
    const screenshot = async name => {
      await expect(page.locator('.ant-spin-spinning')).toHaveCount(0);
      await expect(page.locator('.bg-overlay-content:visible')).toHaveCount(0);
      await expect.poll(() => page.evaluate(() => document.getAnimations().filter(animation =>
        animation.playState === 'running' && Number.isFinite(animation.effect?.getComputedTiming().endTime)).length)).toBe(0);
      await page.screenshot({ path: resolve(base, `${name}.png`), animations: 'disabled' }); report.screenshots.push(`${name}.png`);
    };
    const currentResponse = page.waitForResponse(response => response.url().endsWith(`/devices/${device.id}/current`));
    await page.goto(`${origin}/#/devices/${device.id}`);
    const current = await currentResponse;
    expect(current.status()).toBe(200);
    const data = (await current.json()).data;
    expect(data.sequence).toBe('3'); expect(data.fields[0].value).toBe(23);
    await expect(page.getByRole('row').filter({ hasText: 'temperature' })).toContainText('23');
    await expect(page.getByRole('row').filter({ hasText: 'temperature' })).toContainText('°C');
    await expect(page.getByRole('button', { name: '下发指令', exact: true })).toHaveCount(0);
    await page.getByText('当前数据', { exact: true }).scrollIntoViewIfNeeded();
    await screenshot('mqtt-current-browser');
    report.cases.push('real-tls-reports-current-sequence-3-value-23-readonly-no-control');
    const historyResponse = page.waitForResponse(response => response.url().includes(`/devices/${device.id}/history?`));
    await page.goto(`${origin}/#/history?device=${device.id}`);
    const history = await historyResponse; expect(history.status()).toBe(200);
    const records = await history.json();
    expect(records.total).toBe(3);
    expect(records.items.map(item => item.sequence).sort()).toEqual(['1', '2', '3']);
    expect(records.items.every(item => item.ownership_id === device.ownership_id && item.tenant_id === input.tenant && item.model_version === 1)).toBe(true);
    await expect(page.getByText('当前保留 3 条 · 第 1 页')).toBeVisible();
    await screenshot('mqtt-history-browser');
    report.cases.push('same-mqtt-facts-visible-in-customer-history-no-replay-duplicates-exact-ownership');
    const curveResponse = page.waitForResponse(response => response.url().includes(`/devices/${device.id}/history?`) && response.url().includes('view=curve'));
    await page.getByRole('button', { name: /^曲\s*线$/ }).first().click();
    const curve = (await (await curveResponse).json()).data;
    expect(curve.raw_count).toBe(3); expect(curve.ownership_id).toBe(device.ownership_id);
    expect(curve.points.filter(point => point.value !== null).map(point => point.value).sort((a, b) => a - b)).toEqual([21, 22, 23]);
    await expect(page.locator('svg.history-chart')).toBeVisible();
    await page.locator('.history-chart-viewport').scrollIntoViewIfNeeded();
    await screenshot('mqtt-history-curve-browser');
    report.cases.push('real-mqtt-numeric-history-curve-keeps-original-model-and-values');
    expect(report.errors).toEqual([]); report.status = 'passed';
  } catch (error) {
    report.status = 'failed'; report.failure = safe(error);
    if (page && !page.isClosed()) await page.screenshot({ path: resolve(base, 'mqtt-history-failure.png') }).catch(() => {});
    throw error;
  } finally {
    clearTimeout(deadline); process.off('SIGTERM', interrupt); process.off('SIGINT', interrupt);
    try { await browser?.close(); report.cleanup.browser = true; }
    finally { await preview.close(); report.cleanup.preview = !preview.server.listening;
      writeFileSync(resolve(base, 'mqtt-history-browser-verification.json'), JSON.stringify(report, null, 2) + '\n'); }
  }
}
