import { chromium, expect } from '../web/node_modules/@playwright/test/index.mjs';
import { readFileSync, realpathSync, writeFileSync } from 'node:fs';
import { sep } from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';
import { brokerPreview } from './broker-resources-browser.mjs';

const fixture = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const upstream = new URL(process.argv[3]);
const dist = realpathSync(process.argv[4]);
if (!realpathSync(fixture.base).startsWith(`${realpathSync('build')}${sep}`)
  || upstream.protocol !== 'http:' || upstream.hostname !== '127.0.0.1' || upstream.username || upstream.password) throw new Error('operations_browser_arguments_invalid');
const preview = brokerPreview(dist, upstream);
const report = { status: 'running', cases: [], screenshots: [], errors: [] };
let browser; let context; let page;
const safe = error => [fixture.token, fixture.platform_token, fixture.denied_token, fixture.simulated_token]
  .reduce((text, token) => text.replaceAll(token, '<REDACTED>'), String(error?.stack || error)).replaceAll(process.cwd(), '.');
const interrupt = () => { void browser?.close(); };
process.on('SIGTERM', interrupt); process.on('SIGINT', interrupt);
const deadline = setTimeout(interrupt, 200000);
const screenshot = async (name, target = page) => {
  await expect.poll(() => target.evaluate(() => document.getAnimations().filter(animation =>
    animation.playState === 'running' && Number.isFinite(animation.effect?.getComputedTiming().endTime)).length)).toBe(0);
  await target.screenshot({ path: `${fixture.base}/${name}.png`, animations: 'disabled' });
  report.screenshots.push(`${name}.png`);
};
const refresh = async () => {
  const response = page.waitForResponse(response => response.url().endsWith('/admin/operations'));
  await page.getByRole('button', { name: /^刷\s*新$/ }).click(); return response;
};
try {
  await new Promise((resolve, reject) => { preview.server.once('error', reject); preview.server.listen(0, '127.0.0.1', resolve); });
  const origin = `http://127.0.0.1:${preview.server.address().port}`;
  browser = await chromium.launch({ channel: 'msedge', headless: true });
  report.browser = browser.version();
  context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, timezoneId: 'Asia/Shanghai' });
  await context.addInitScript(token => sessionStorage.setItem('typeapp.admin.token', token), fixture.platform_token);
  page = await context.newPage(); page.setDefaultTimeout(15000);
  page.on('pageerror', error => report.errors.push(safe(error)));
  await page.goto(`${origin}/#/admin/operations`);
  await expect(page.getByRole('heading', { name: '运行概览', exact: true })).toBeVisible();
  await expect(page.getByRole('cell', { name: 't14-test', exact: true })).toBeVisible();
  await expect(page.getByRole('cell', { name: 'test', exact: true })).toBeVisible();
  await expect(page.getByText('Asia/Shanghai', { exact: false })).toBeVisible();
  await page.getByRole('button', { name: /^详\s*情$/ }).first().click();
  await expect(page.getByText('节点观察详情', { exact: true })).toBeVisible();
  await expect(page.getByText('运行身份', { exact: true })).toBeVisible();
  await screenshot('operations-platform-desktop');
  await page.locator('.ant-drawer-close').click();
  report.cases.push('actual-native-api-node-identity-bounded-detail');
  await context.setOffline(true);
  await page.getByRole('button', { name: /^刷\s*新$/ }).click();
  await expect(page.getByRole('alert')).toContainText('页面刷新失败');
  await expect(page.getByRole('cell', { name: 't14-test', exact: true })).toBeVisible();
  await expect(page.getByText('页面观察已过期', { exact: true }).first()).toBeVisible({ timeout: 22000 });
  await expect(page.getByText('节点暂不可达', { exact: true })).toHaveCount(0);
  // 同步读取在节点快照之后完成，两种观察有各自到期时间，不能复用前一个节点的到期时刻。
  await expect(page.locator('.operations-cards .ant-card').filter({ hasText: '持久待投递积压' }).locator('strong')).toHaveText('—', { timeout: 22000 });
  await context.setOffline(false); expect((await refresh()).status()).toBe(200);
  await expect(page.getByRole('alert')).toHaveCount(0);
  await expect(page.getByText('正在上报', { exact: true }).first()).toBeVisible();
  report.cases.push('page-network-failure-retains-values-expires-observation-without-inventing-node-failure-recovers');
  await expect(page.locator('.ant-spin-spinning')).toHaveCount(0);
  let hiddenRequests = 0;
  const countHidden = request => { if (request.url().endsWith('/admin/operations')) hiddenRequests++; };
  page.on('request', countHidden);
  await page.evaluate(() => { Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' }); document.dispatchEvent(new Event('visibilitychange')); });
  await delay(5600); expect(hiddenRequests).toBe(0);
  page.off('request', countHidden);
  const visibleResponse = page.waitForResponse(response => response.url().endsWith('/admin/operations'));
  await page.evaluate(() => { delete document.visibilityState; document.dispatchEvent(new Event('visibilitychange')); });
  expect((await visibleResponse).status()).toBe(200);
  report.cases.push('browser-visibility-event-pauses-polling-and-visible-resumes');
  await page.setViewportSize({ width: 390, height: 844 });
  if (!(await page.locator('html').getAttribute('class'))?.includes('dark')) await page.getByRole('button', { name: 'dark', exact: true }).click();
  await expect(page.locator('html')).toHaveClass(/dark/);
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBe(390);
  await screenshot('operations-platform-mobile-dark');
  await page.getByRole('button', { name: 'light', exact: true }).click();
  await expect(page.locator('html')).not.toHaveClass(/dark/);
  await page.getByRole('button', { name: '主题色', exact: true }).click();
  await page.getByLabel('主题色', { exact: true }).click(); await page.getByText('紫罗兰', { exact: true }).click();
  await page.getByRole('button', { name: '主题色', exact: true }).click();
  await page.getByRole('button', { name: /^详\s*情$/ }).first().click();
  const drawer = page.locator('.ant-drawer-body:visible');
  await expect(page.getByText('节点观察详情', { exact: true })).toBeVisible();
  await expect.poll(async () => { const box = await page.locator('.ant-drawer-content-wrapper:visible').boundingBox(); return box ? Math.round(box.x + box.width) : 0; }).toBe(390);
  await expect.poll(() => drawer.evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1);
  await screenshot('operations-platform-mobile-light-purple-detail');
  await page.locator('.ant-drawer-close').click();
  report.cases.push('390px-dark-light-purple-drawer-no-document-overflow');
  await page.setViewportSize({ width: 1440, height: 1000 });
  let count = 0; let release;
  const gate = new Promise(resolve => { release = resolve; });
  await page.route('**/admin/operations', async route => { count++; const response = await route.fetch(); await gate; try { await route.fulfill({ response }); } catch (error) { if (!/already handled/i.test(error.message)) throw error; } });
  await page.getByRole('button', { name: /^刷\s*新$/ }).click();
  await expect.poll(() => count).toBe(1);
  await delay(5600); expect(count).toBe(1);
  await page.evaluate(() => { location.hash = '/admin/profile'; });
  await page.waitForURL('**/#/admin/profile'); release(); await page.unrouteAll({ behavior: 'wait' });
  await expect(page.getByRole('heading', { name: '运行概览', exact: true })).toHaveCount(0);
  report.cases.push('slow-native-response-does-not-overlap-polls-leaving-cancels');
  await page.goto(`${origin}/#/admin/operations`);
  await expect(page.getByRole('cell', { name: 't14-test', exact: true })).toBeVisible();
  await page.route('**/admin/operations', route => route.continue({ headers: { ...route.request().headers(), authorization: `Bearer ${fixture.denied_token}` } }));
  expect((await refresh()).status()).toBe(403);
  await expect(page.getByRole('alert')).toContainText('当前账号无权查看此概览');
  await expect(page.getByRole('cell', { name: 't14-test', exact: true })).toHaveCount(0);
  await page.unrouteAll({ behavior: 'wait' }); expect((await refresh()).status()).toBe(200);
  report.cases.push('actual-readonly-backend-denial-clears-old-platform-values-and-recovers');
  // 审计复用同一真实HTTP应用与生产前端；三个身份读取同一模拟动作。
  const auditQuery = async target => {
    const subject = await target.getByLabel('业务主体筛选').inputValue();
    const action = await target.getByLabel('操作类型筛选').inputValue();
    const [response] = await Promise.all([
      target.waitForResponse(response => {
        const url = new URL(response.url());
        return response.request().method() === 'GET' && url.pathname.endsWith('/audit')
          && (url.searchParams.get('subject_id') || '') === subject && (url.searchParams.get('action') || '') === action;
      }),
      target.getByRole('button', { name: /^查\s*询$/ }).click(),
    ]);
    expect(response.status()).toBe(200); return response.json();
  };
  const auditDetail = async (target, route, name) => {
    await Promise.all([
      target.waitForResponse(response => response.request().method() === 'GET' && new URL(response.url()).pathname.endsWith('/audit')),
      target.goto(`${origin}/#${route}`),
    ]);
    await expect(target.getByRole('heading', { name: route.startsWith('/admin') ? '平台操作审计' : '操作审计', exact: true })).toBeVisible();
    await expect(target.locator('.ant-spin-spinning')).toHaveCount(0);
    await target.getByLabel('业务主体筛选').fill(fixture.audit_subject);
    await target.getByLabel('操作类型筛选').fill('device.update');
    const data = await auditQuery(target);
    expect(data.items).toHaveLength(1);
    expect(data.items[0].actor_id).toBe(fixture.identity.actor_id);
    expect(data.items[0].tenant_id).toBe(fixture.tenant);
    expect(data.items[0].details.customer_id).toBe(fixture.identity.customer_id);
    expect(data.items[0].details.source_session_id).toBe(fixture.identity.source_session_id);
    expect(data.items[0].details.impersonation_id).toBe(fixture.identity.impersonation_id);
    await target.getByRole('button', { name: /^详\s*情$/ }).click();
    const detail = target.locator('.ant-drawer-body:visible');
    for (const key of ['actor_id', 'customer_id', 'source_session_id', 'impersonation_id']) await expect(detail).toContainText(fixture.identity[key]);
    await expect(detail).toContainText('来源管理会话'); await expect(detail).toContainText('模拟来源');
    await screenshot(name, target);
    await target.locator('.ant-drawer-close:visible').click();
    await expect(detail).toHaveCount(0);
    return data.items[0].id;
  };
  await page.goto(`${origin}/#/admin/audit`);
  await expect(page.getByRole('button', { name: '下一页', exact: true })).toBeEnabled();
  const firstKeys = await page.locator('.ant-table-tbody tr.ant-table-row').evaluateAll(rows => rows.map(row => row.dataset.rowKey));
  await page.getByRole('button', { name: '下一页', exact: true }).click();
  await expect(page.getByText(/第 2 页 · 本页/)).toBeVisible();
  const secondKeys = await page.locator('.ant-table-tbody tr.ant-table-row').evaluateAll(rows => rows.map(row => row.dataset.rowKey));
  expect(secondKeys.some(key => firstKeys.includes(key))).toBe(false);
  await page.getByRole('button', { name: '上一页', exact: true }).click();
  await expect(page.getByText(/第 1 页 · 本页/)).toBeVisible();
  const auditId = await auditDetail(page, '/admin/audit', 'audit-platform-source-detail');
  await page.getByLabel('业务主体筛选').fill('no-observation-event');
  expect((await auditQuery(page)).items).toEqual([]);
  await expect(page.getByText('暂无符合条件的操作记录', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: /^重\s*置$/ }).click();
  await expect(page.locator('.ant-table-tbody tr.ant-table-row').first()).toBeVisible();
  await page.route('**/admin/audit?*', route => route.continue({ headers: { ...route.request().headers(), authorization: `Bearer ${fixture.denied_token}` } }));
  await page.getByRole('button', { name: /^刷\s*新$/ }).click();
  await expect(page.getByRole('alert')).toBeVisible();
  await expect(page.locator('.ant-table-tbody tr.ant-table-row')).toHaveCount(0);
  await page.unrouteAll({ behavior: 'wait' });
  report.cases.push('platform-audit-keyset-empty-reset-current-source-detail-and-real-403-clears-results');
  const viewerContext = await browser.newContext({ viewport: { width: 390, height: 844 }, timezoneId: 'Asia/Shanghai' });
  await viewerContext.addInitScript(token => sessionStorage.setItem('typeapp.customer.token', token), fixture.token);
  const viewer = await viewerContext.newPage();
  viewer.on('pageerror', error => report.errors.push(safe(error)));
  await viewer.goto(`${origin}/#/tenants`);
  await viewer.getByRole('row').filter({ hasText: fixture.tenant }).getByRole('button', { name: '进入租户' }).click();
  await viewer.waitForURL('**/#/profile'); await viewer.evaluate(() => { location.hash = '/operations'; });
  await expect(viewer.getByRole('heading', { name: '运行概览', exact: true })).toBeVisible();
  await expect(viewer.getByText('数据陈旧', { exact: true }).first()).toBeVisible();
  await expect(viewer.getByText('尚无遥测', { exact: true }).first()).toBeVisible();
  await expect(viewer.getByRole('cell', { name: fixture.long_name, exact: true })).toBeVisible();
  await expect(viewer.locator('.ant-table script')).toHaveCount(0);
  await viewer.getByRole('cell', { name: fixture.long_name, exact: true }).scrollIntoViewIfNeeded();
  await screenshot('operations-tenant-mobile-long-name', viewer);
  await expect.poll(() => viewer.evaluate(() => document.documentElement.scrollWidth)).toBe(390);
  await viewer.getByLabel('设备名称筛选').fill('does-not-exist'); await viewer.getByRole('button', { name: /^查\s*询$/ }).click();
  await expect(viewer.getByText('暂无符合条件的设备', { exact: true })).toBeVisible();
  await viewer.getByRole('button', { name: /^重\s*置$/ }).click();
  await expect(viewer.getByRole('button', { name: '设备详情', exact: true }).first()).toBeVisible();
  await viewer.getByRole('button', { name: '设备详情', exact: true }).first().click();
  await viewer.waitForURL('**/#/devices/*');
  expect(await auditDetail(viewer, '/audit', 'audit-customer-mobile-detail')).toBe(auditId);
  await expect.poll(() => viewer.evaluate(() => document.documentElement.scrollWidth)).toBe(390);
  await viewer.evaluate(() => { location.hash = '/admin/operations'; });
  await viewer.waitForURL('**/#/admin/login');
  report.cases.push('tenant-readonly-mobile-empty-search-reset-device-navigation-platform-route-denied');
  await viewerContext.close();
  const simulatedContext = await browser.newContext({ viewport: { width: 1440, height: 1000 }, timezoneId: 'Asia/Shanghai' });
  await simulatedContext.addInitScript(data => {
    sessionStorage.setItem('typeapp.admin.token', data.platform_token);
    sessionStorage.setItem('typeapp.impersonation.token', data.simulated_token);
    sessionStorage.setItem('typeapp.impersonation.tenant', data.tenant);
  }, { platform_token: fixture.platform_token, simulated_token: fixture.simulated_token, tenant: fixture.tenant });
  const simulated = await simulatedContext.newPage();
  simulated.on('pageerror', error => report.errors.push(safe(error)));
  expect(await auditDetail(simulated, '/audit', 'audit-impersonation-source-detail')).toBe(auditId);
  await expect(simulated.getByText('模拟登录中', { exact: true })).toBeVisible();
  await expect(simulated.getByText(/真实管理人员：/)).toBeVisible();
  await simulatedContext.close();
  report.cases.push('customer-and-impersonation-audit-same-event-real-actor-source-session-and-banner');
  expect(report.errors).toEqual([]); report.status = 'passed';
} catch (error) { report.status = 'failed'; report.failure = safe(error); if (page && !page.isClosed()) await screenshot('operations-failure').catch(() => {}); process.exitCode = 1; }
finally {
  clearTimeout(deadline); process.off('SIGTERM', interrupt); process.off('SIGINT', interrupt);
  await browser?.close(); await preview.close();
  report.cleanup = { browser: true, preview: !preview.server.listening };
  writeFileSync(`${fixture.base}/operations-browser-verification.json`, JSON.stringify(report, null, 2) + '\n'); console.log(JSON.stringify(report));
}
