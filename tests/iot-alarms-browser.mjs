import { chromium, expect } from '../web/node_modules/@playwright/test/index.mjs';
import { readFileSync, writeFileSync } from 'node:fs';

const fixture = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const origin = process.argv[3];
if (!fixture.alarms || !origin) throw new Error('用法：node tests/iot-alarms-browser.mjs <带--alarms的fixture.json> <生产预览地址>');
const report = { status: 'running', cases: [], errors: [], screenshots: [] };
const browser = await chromium.launch({ channel: 'msedge', headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, timezoneId: 'Asia/Shanghai' });
const page = await context.newPage();
page.on('pageerror', error => report.errors.push(error.message));
const screenshot = async name => { const path = `${fixture.base}/${name}.png`; await expect(page.locator('.ant-spin-spinning')).toHaveCount(0); await page.screenshot({ path, animations: 'disabled' }); report.screenshots.push(path); };
const enter = async () => { await page.getByRole('row').filter({ hasText: '历史数据验收组织' }).getByRole('button', { name: '进入租户' }).click(); await page.waitForURL('**/#/profile'); };
const login = async name => { await page.getByLabel('登录账号').fill(name); await page.getByLabel('登录密码').fill('History-browser-password-2026'); await page.getByRole('button', { name: /^登\s*录$/ }).click(); await page.waitForURL('**/#/profile'); await page.goto(`${origin}/#/tenants`); await enter(); };
const goto = async path => { await page.evaluate(value => { location.hash = value; }, path); };
const query = async () => { await page.getByRole('button', { name: /^查\s*询$/ }).click(); await expect(page.locator('.ant-spin-spinning')).toHaveCount(0); };
const select = async (label, option) => { await page.getByLabel(label, { exact: true }).first().click(); await page.getByText(option, { exact: true }).last().click(); };
const close = async () => { await page.locator('.ant-drawer-close:visible').click(); await expect(page.locator('.ant-drawer-content-wrapper:visible')).toHaveCount(0); };
const stableDrawer = async () => { await expect.poll(async () => { const box = await page.locator('.ant-drawer-content-wrapper:visible').boundingBox(); return box ? Math.round(box.x + box.width) : 0; }).toBe(page.viewportSize().width); await expect.poll(() => page.locator('.ant-drawer-body:visible').evaluate(el => el.scrollWidth - el.clientWidth)).toBeLessThanOrEqual(1); };
try {
  report.browser = browser.version(); await page.goto(origin); await login('webadmin'); await goto('/alarm-rules');
  await expect(page.getByText('1 条规则', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: /^版\s*本$/ }).first().click(); await stableDrawer();
  await expect(page.locator('.ant-drawer-body:visible')).toContainText('规则变更');
  await expect(page.locator('.ant-drawer-body:visible')).toContainText('温度超限长规则'); await screenshot('alarms-desktop-rule-versions'); await close();
  await page.getByRole('button', { name: '创建规则', exact: true }).click();
  await page.getByLabel('规则名称', { exact: true }).fill('浏览器创建阈值规则');
  await select('监控设备', `尚未上报设备 · ${fixture.empty}`); await select('监控属性', '温度 · temperature (°C)');
  await page.getByLabel('触发下限').fill('0'); await page.getByLabel('触发上限').fill('10'); await page.getByLabel('恢复回差').fill('6');
  await page.getByRole('button', { name: /^保\s*存$/ }).click(); await expect(page.getByRole('alert')).toContainText('恢复区间有效');
  await page.getByLabel('恢复回差').fill('2'); await context.setOffline(true); await page.getByRole('button', { name: /^保\s*存$/ }).click();
  await expect(page.getByRole('alert')).toContainText('网络连接失败'); await expect(page.getByLabel('规则名称', { exact: true })).toHaveValue('浏览器创建阈值规则'); await context.setOffline(false);
  let release; const gate = new Promise(resolve => { release = resolve; }); let posts = 0;
  await page.route('**/alarm-rules', async route => { if (route.request().method() !== 'POST') return route.continue(); posts++; const response = await route.fetch(); await gate; await route.fulfill({ response }); });
  await page.getByRole('button', { name: /^保\s*存$/ }).click(); await expect.poll(() => posts).toBe(1);
  await expect(page.getByRole('button', { name: /^取\s*消$/ })).toBeDisabled(); await page.keyboard.press('Escape'); await page.locator('.ant-drawer-close:visible').click();
  await expect(page.getByText('创建告警规则', { exact: true })).toBeVisible(); await expect(page.locator('.ant-drawer-footer .ant-btn-primary')).toBeDisabled();
  release(); await page.unrouteAll({ behavior: 'wait' }); await expect(page.getByText('2 条规则', { exact: true })).toBeVisible(); expect(posts).toBe(1);
  report.cases.push('admin-create-actual-model-invalid-hysteresis-offline-preserved-input-single-submit-close-blocked');
  await page.getByRole('row').filter({ hasText: '浏览器创建阈值规则' }).getByRole('button', { name: /^编\s*辑$/ }).click();
  await page.getByLabel('规则名称', { exact: true }).fill('浏览器停用规则'); await page.getByLabel('启用规则').click(); await page.getByRole('button', { name: /^保\s*存$/ }).click();
  await expect(page.getByRole('row').filter({ hasText: '浏览器停用规则' })).toContainText('停用');
  await page.getByRole('row').filter({ hasText: '浏览器停用规则' }).getByRole('button', { name: /^版\s*本$/ }).click(); await expect(page.locator('.ant-drawer-body:visible')).toContainText('规则停用'); await close();
  await goto('/alarms'); await expect(page.getByText('2 条告警', { exact: true })).toBeVisible();
  await select('告警状态', '活动告警'); await query(); await expect(page.getByText('1 条告警', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: /^详\s*情$/ }).first().click(); await stableDrawer(); await expect(page.locator('.ant-drawer-body:visible')).toContainText('触发采样时间'); await screenshot('alarms-desktop-active-detail');
  if (fixture.notices) {
    await context.setOffline(true); await page.getByRole('button', { name: '确认告警', exact: true }).click(); await expect(page.locator('.ant-drawer-body:visible').getByRole('alert')).toContainText('网络连接失败'); await context.setOffline(false);
    let releaseAck; const ackGate = new Promise(resolve => { releaseAck = resolve; }); let acknowledgements = 0;
    await page.route('**/acknowledge', async route => { acknowledgements++; const response = await route.fetch(); await ackGate; await route.fulfill({ response }); });
    await page.getByRole('button', { name: '确认告警', exact: true }).click(); await expect.poll(() => acknowledgements).toBe(1); await expect(page.locator('.ant-drawer-body:visible .ant-btn-primary')).toBeDisabled(); await expect(page.locator('.ant-drawer-close:visible')).toHaveCount(0); await page.keyboard.press('Escape'); await expect(page.getByText('告警详情', { exact: true })).toBeVisible(); releaseAck();
    await expect(page.locator('.ant-drawer-body:visible')).toContainText('已确认'); await expect(page.locator('.ant-drawer-body:visible')).toContainText('活动告警'); await page.unrouteAll({ behavior: 'wait' }); await screenshot('notices-desktop-confirmed-active');
  }
  await close();
  await select('告警状态', '已结束'); await query(); await page.getByRole('button', { name: /^详\s*情$/ }).first().click(); await stableDrawer();
  await expect(page.locator('.ant-drawer-body:visible')).toContainText('规则变更'); await expect(page.locator('.ant-drawer-body:visible')).toContainText('回差 2'); await close();
  report.cases.push('admin-publishes-disabled-version-status-filter-original-rule-end-reason-double-times');
  if (fixture.notices) {
    await goto('/notifications'); await expect(page.getByText('3 条通知', { exact: true })).toBeVisible(); await expect(page.getByText('已确认', { exact: true })).toBeVisible();
    await select('通知类型', '告警结束'); await query(); await expect(page.getByText('1 条通知', { exact: true })).toBeVisible(); await expect(page.getByRole('cell', { name: '规则变更', exact: true }).first()).toBeVisible();
    await page.getByRole('button', { name: '告警详情', exact: true }).click(); await stableDrawer(); await expect(page.locator('.ant-drawer-body:visible')).toContainText('规则变更'); await close();
    await goto('/notifications'); await expect(page.getByText('3 条通知', { exact: true })).toBeVisible(); await context.setOffline(true); await query(); await expect(page.getByRole('alert')).toContainText('网络连接失败'); await context.setOffline(false); await query(); await expect(page.getByText('3 条通知', { exact: true })).toBeVisible(); await screenshot('notices-desktop-dark');
    await page.setViewportSize({ width: 390, height: 844 }); await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBe(390); await page.getByRole('button', { name: 'light', exact: true }).click(); await page.getByRole('button', { name: '主题色', exact: true }).click(); await select('主题色', '紫罗兰'); await page.getByText('3 条通知', { exact: true }).click(); await expect(page.locator('.ant-popover:visible')).toHaveCount(0); await screenshot('notices-mobile-light-purple');
    await page.getByRole('button', { name: 'dark', exact: true }).click(); await page.setViewportSize({ width: 1440, height: 1000 }); await goto('/alarms'); await expect(page.getByText('2 条告警', { exact: true })).toBeVisible();
    report.cases.push('native-site-notices-ack-independent-failure-retry-save-guard-detail-theme-mobile');
  }
  await page.getByLabel('告警设备筛选').fill(fixture.empty); await query(); await expect(page.getByText('暂无符合条件的告警')).toBeVisible();
  await page.getByLabel('告警设备筛选').fill('invalid'); await query(); await expect(page.getByRole('alert')).toBeVisible();
  await page.getByRole('button', { name: /^重\s*置$/ }).click(); await expect(page.getByText('2 条告警', { exact: true })).toBeVisible();
  await context.setOffline(true); await query(); await expect(page.getByRole('alert')).toContainText('网络连接失败'); await context.setOffline(false); await query(); await expect(page.getByRole('alert')).toHaveCount(0);
  report.cases.push('empty-validation-http422-network-failure-retry');
  await page.setViewportSize({ width: 390, height: 844 }); await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBe(390);
  await screenshot('alarms-mobile-dark'); await page.getByRole('button', { name: 'light', exact: true }).click(); await page.getByRole('button', { name: '主题色', exact: true }).click(); await select('主题色', '紫罗兰');
  await page.getByRole('button', { name: '主题色', exact: true }).click();
  await page.getByRole('row').filter({ hasText: '温度超限长规则' }).getByRole('button', { name: /^详\s*情$/ }).click(); await stableDrawer();
  await screenshot('alarms-mobile-light-purple-long-detail'); await page.locator('.ant-drawer-body:visible').evaluate(el => { el.scrollTop = el.scrollHeight; }); await expect(page.locator('.ant-drawer-body:visible').getByText('归属标识')).toBeInViewport(); await close();
  await goto('/alarm-rules'); await expect(page.getByText('2 条规则', { exact: true })).toBeVisible(); await page.getByRole('row').filter({ hasText: '当前温度规则' }).getByRole('button', { name: /^版\s*本$/ }).click(); await stableDrawer(); await screenshot('alarms-mobile-rule-history'); await close();
  report.cases.push('390px-dark-light-purple-long-rule-and-readonly-drawer-internal-scroll');
  await page.setViewportSize({ width: 1440, height: 1000 }); await page.getByRole('button', { name: '退出登录', exact: true }).click(); await login('webread'); await goto('/alarm-rules');
  await expect(page.getByText('当前角色可查看规则', { exact: false })).toBeVisible(); await expect(page.getByRole('button', { name: '创建规则', exact: true })).toHaveCount(0); await expect(page.getByRole('button', { name: /^编\s*辑$/ })).toHaveCount(0);
  if (fixture.notices) { await goto('/alarms'); await expect(page.getByText('2 条告警', { exact: true })).toBeVisible(); await expect(page.getByRole('button', { name: /^确\s*认$/ })).toHaveCount(0); await goto('/alarm-rules'); await expect(page.getByText('2 条规则', { exact: true })).toBeVisible(); }
  let releaseList; const listGate = new Promise(resolve => { releaseList = resolve; }); let loads = 0;
  await page.route('**/alarm-rules?**', async route => { loads++; const response = await route.fetch(); await listGate; try { await route.fulfill({ response }); } catch (error) { if (!/Route is already handled/.test(error.message)) throw error; } });
  await page.getByRole('button', { name: /^查\s*询$/ }).click(); await expect.poll(() => loads).toBe(1); await expect(page.locator('.crud-search-grid button[type="submit"]')).toBeDisabled();
  await goto('/tenants'); await page.getByRole('row').filter({ hasText: '历史空数据组织' }).getByRole('button', { name: '进入租户' }).click(); await page.waitForURL('**/#/profile'); releaseList(); await page.unrouteAll({ behavior: 'wait' }); await goto('/alarm-rules'); await expect(page.getByText('暂无符合条件的规则')).toBeVisible();
  if (fixture.notices) { await goto('/notifications'); await expect(page.getByText('暂无符合条件的通知', { exact: true })).toBeVisible(); await screenshot('notices-empty-other-tenant'); }
  await goto('/tenants'); await enter(); await goto('/alarms'); await expect(page.getByText('2 条告警', { exact: true })).toBeVisible();
  if (fixture.notices) { await goto('/notifications'); await expect(page.getByText('3 条通知', { exact: true })).toBeVisible(); }
  const admin = await fetch(`http://127.0.0.1:${fixture.port}/customer/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ login: 'webadmin', password: 'History-browser-password-2026' }) }).then(r => r.json());
  const revoked = await fetch(`http://127.0.0.1:${fixture.port}/customer/members/${fixture.member}`, { method: 'DELETE', headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${admin.data.accessToken}`, 'X-Tenant-Id': fixture.tenant }, body: JSON.stringify({ version: fixture.member_version }) }); expect(revoked.status).toBe(200);
  await page.getByRole('button', { name: /^查\s*询$/ }).click(); await page.waitForURL('**/#/tenants'); await expect(page.getByText('告警详情', { exact: true })).toHaveCount(0);
  report.cases.push('readonly-hidden-actions-slow-read-tenant-switch-cancellation-live-revocation');
  expect(report.errors).toEqual([]); report.status = 'passed';
} catch (error) { report.status = 'failed'; report.failure = error.stack; await screenshot('alarms-failure'); throw error; }
finally { writeFileSync(`${fixture.base}/alarms-browser-verification.json`, JSON.stringify(report, null, 2) + '\n'); await browser.close(); console.log(JSON.stringify(report, null, 2)); }
