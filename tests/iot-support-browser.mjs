import { chromium, expect } from '../web/node_modules/@playwright/test/index.mjs';
import { readFileSync, writeFileSync } from 'node:fs';

const fixture = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const origin = process.argv[3];
if (!fixture.support || !origin) throw new Error('用法：node tests/iot-support-browser.mjs <支持fixture.json> <生产预览地址>');
const report = { status: 'running', cases: [], errors: [], screenshots: [] };
const browser = await chromium.launch({ channel: 'msedge', headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, timezoneId: 'Asia/Shanghai' });
const page = await context.newPage();
page.on('pageerror', error => report.errors.push(error.message));
const drawer = () => page.locator('.ant-drawer-content-wrapper:visible').last();
const body = () => drawer().locator('.ant-drawer-body');
const screenshot = async name => {
  await expect(page.locator('.bg-overlay-content')).not.toBeVisible();
  if (await drawer().count()) await expect.poll(() => drawer().evaluate(element => Math.round(element.getBoundingClientRect().right))).toBe(page.viewportSize().width);
  const path = `${fixture.base}/${name}.png`; await page.screenshot({ path, animations: 'disabled' }); report.screenshots.push(path);
};
const goto = async path => { await page.evaluate(value => { location.hash = value; }, path); };
const login = async name => { await page.getByLabel('登录账号', { exact: true }).fill(name); await page.getByLabel('登录密码').fill('History-browser-password-2026'); await page.getByRole('button', { name: /^登\s*录$/ }).click(); await page.waitForURL('**/#/tenants'); };
const enterMember = async () => { await page.getByRole('row').filter({ hasText: '历史数据验收组织' }).filter({ has: page.getByRole('button', { name: '进入租户' }) }).getByRole('button', { name: '进入租户' }).click(); await page.waitForURL('**/#/members'); };
const close = async () => { await drawer().getByRole('button', { name: 'Close', exact: true }).click(); };
let token;
const call = async (method, path, data, tenant = fixture.tenant) => {
  const response = await fetch(`http://127.0.0.1:${fixture.port}${path}`, { method, headers: { 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}), ...(tenant ? { 'X-Tenant-Id': tenant } : {}) }, ...(method === 'GET' ? {} : { body: JSON.stringify(data) }) });
  return { status: response.status, body: await response.json() };
};
const grantPath = `/iot/tenants/${fixture.tenant}/support-grants`;
try {
  report.browser = browser.version();
  token = (await call('POST', '/iot/auth/login', { login: 'webadmin', password: 'History-browser-password-2026' }, null)).body.data.accessToken;
  await page.goto(origin); await login('webadmin'); await enterMember(); await page.getByRole('button', { name: '支持授权', exact: true }).click();
  await expect(body()).toContainText('暂无符合条件的支持授权'); await screenshot('support-admin-empty');
  await page.getByRole('button', { name: '授予支持权限', exact: true }).click();
  await page.getByLabel('平台人员账号', { exact: true }).fill('websupport'); await page.getByLabel('支持原因', { exact: true }).fill('长说明跨租户支持原因与范围'.repeat(7));
  await expect(page.getByRole('checkbox', { name: '控制及主动结果查询', exact: true })).not.toBeChecked();
  await expect(page.getByRole('checkbox', { name: '告警确认', exact: true })).not.toBeChecked(); await expect(page.getByRole('checkbox', { name: '导出及下载', exact: true })).not.toBeChecked();
  let calls = 0; let saved; let release; const gate = new Promise(resolve => { release = resolve; });
  await page.route(`**${grantPath}`, async route => { if (route.request().method() !== 'POST') return route.continue(); calls++; const response = await route.fetch(); saved = (await response.json()).data; await gate; await route.abort('failed'); });
  await page.getByRole('button', { name: '确认授予', exact: true }).click(); await expect.poll(() => calls).toBe(1);
  await expect(drawer().getByRole('button', { name: /^取\s*消$/ })).toBeDisabled(); await expect(drawer().locator('.ant-drawer-close')).toHaveCount(0); await page.keyboard.press('Escape'); await expect(body()).toContainText('默认只读查询');
  release(); await expect(body().getByRole('alert')).toContainText('原期限不会延长'); await screenshot('support-admin-response-lost'); await page.unrouteAll({ behavior: 'wait' });
  await page.getByRole('button', { name: '确认授予', exact: true }).click(); await expect(page.getByText('授予限时支持权限', { exact: true })).not.toBeVisible();
  const persisted = (await call('GET', grantPath)).body; expect(persisted.total).toBe(1); expect(persisted.items[0].expires_at).toBe(saved.expires_at);
  await expect(body()).toContainText('只读查询'); await screenshot('support-admin-created'); await close();
  report.cases.push('admin-grant-default-readonly-long-reason-save-guard-real-commit-response-loss-stable-id');

  await page.getByRole('button', { name: '退出登录', exact: true }).click(); await login('websupport'); await expect(page.getByText('我的临时支持授权', { exact: true })).toBeVisible();
  const supportRow = () => page.getByRole('row').filter({ has: page.getByRole('button', { name: '支持访问' }) });
  await supportRow().getByRole('button', { name: /^详\s*情$/ }).click(); await expect(body()).toContainText('不包含产品、模型、设备'); await screenshot('support-own-detail-dark'); await close();
  await supportRow().getByRole('button', { name: '支持访问' }).click(); await page.waitForURL('**/#/devices');
  await expect(page.getByRole('button', { name: '当前临时支持身份' })).toBeVisible(); await expect(page.getByRole('button', { name: '注册设备', exact: true })).toHaveCount(0); await expect(page.getByRole('button', { name: /^管\s*理$/ })).toHaveCount(0);
  await goto(`/devices/${fixture.device}`); await expect(page.getByRole('heading', { name: '设备详情', exact: true })).toBeVisible(); await expect(page.locator('.ant-descriptions').first()).toContainText('历史曲线设备'); await expect(page.getByText('设备指令', { exact: true })).toBeVisible(); await expect(page.getByRole('button', { name: '发起指令', exact: true })).toHaveCount(0); await expect(page.getByRole('button', { name: '主动对账', exact: true })).toHaveCount(0);
  await goto('/members'); await expect(page.getByRole('heading', { name: '成员管理', exact: true })).toBeVisible(); await expect(page.getByText('3 位成员', { exact: true })).toBeVisible(); await expect(page.getByRole('button', { name: '添加成员', exact: true })).toHaveCount(0); await expect(page.getByRole('button', { name: '支持授权', exact: true })).toHaveCount(0);
  await page.setViewportSize({ width: 390, height: 844 }); await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBe(390); await expect(page.getByRole('button', { name: '退出登录', exact: true })).toBeInViewport(); await screenshot('support-mobile-current-dark');
  await page.getByRole('button', { name: '当前临时支持身份' }).click(); await expect(page.getByText('当前身份：临时支持', { exact: true })).toBeVisible(); await expect.poll(() => page.locator('.ant-popover:visible').evaluate(element => getComputedStyle(element).opacity)).toBe('1'); await screenshot('support-mobile-current-popover'); await page.getByRole('button', { name: '当前临时支持身份' }).click();
  await goto('/tenants'); await enterMember(); await expect(page.getByRole('button', { name: '添加成员', exact: true })).toBeVisible(); await expect(page.getByRole('button', { name: '当前临时支持身份' })).toHaveCount(0);
  report.cases.push('explicit-support-entry-current-context-visible-no-member-union-no-management-control-mobile');

  await goto('/tenants'); await supportRow().getByRole('button', { name: '支持访问' }).click(); await page.waitForURL('**/#/devices');
  expect((await call('DELETE', `${grantPath}/${saved.id}`, { version: 1 })).status).toBe(200); await page.getByRole('button', { name: /^查\s*询$/ }).click(); await page.waitForURL('**/#/tenants'); await expect(page.getByText('暂无符合条件的支持授权', { exact: true })).toBeVisible(); await screenshot('support-mobile-revoked');
  await enterMember(); await expect(page.getByRole('button', { name: '添加成员', exact: true })).toBeVisible();
  report.cases.push('real-revoke-old-login-refused-current-context-cleared-independent-member-entry');

  await page.setViewportSize({ width: 1440, height: 1000 }); await page.getByRole('button', { name: 'light', exact: true }).click(); await page.getByRole('button', { name: '主题色', exact: true }).click(); await page.getByLabel('主题色', { exact: true }).first().click(); await page.getByText('紫罗兰', { exact: true }).last().click(); await page.getByText('成员管理', { exact: true }).last().click();
  await page.getByRole('button', { name: '支持授权', exact: true }).click(); await expect(body()).toContainText('已撤销'); await page.getByLabel('支持人员筛选').fill('no-such-user'); await page.getByRole('button', { name: '查询授权', exact: true }).click(); await expect(body()).toContainText('暂无符合条件的支持授权');
  await body().getByRole('button', { name: /^重\s*置$/ }).click(); await expect(page.getByLabel('支持人员筛选')).toHaveValue(''); await expect(body()).toContainText('已撤销');
  await page.getByLabel('支持人员筛选').fill('websupport'); await context.setOffline(true); await page.getByRole('button', { name: '查询授权', exact: true }).click(); await expect(body().getByRole('alert')).toContainText('网络连接失败'); await context.setOffline(false); await page.getByRole('button', { name: '查询授权', exact: true }).click(); await expect(body()).toContainText('已撤销');
  await page.getByRole('button', { name: '授予支持权限', exact: true }).click(); await page.setViewportSize({ width: 390, height: 844 }); await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBe(390); await screenshot('support-mobile-form-light-purple');
  await page.getByLabel('平台人员账号', { exact: true }).fill('websupport'); await page.getByLabel('支持原因', { exact: true }).fill('独立附加权限'); await page.getByRole('checkbox', { name: '告警确认', exact: true }).check(); await page.getByRole('button', { name: '确认授予', exact: true }).click(); await expect(page.getByText('授予限时支持权限', { exact: true })).not.toBeVisible();
  await body().getByRole('row').filter({ hasText: '独立附加权限' }).getByRole('button', { name: /^撤\s*销$/ }).click(); await page.getByRole('button', { name: '撤销授权', exact: true }).click(); await expect.poll(async () => (await call('GET', grantPath)).body.items.every(item => item.status === 'revoked')).toBe(true); await close();
  report.cases.push('grant-list-search-empty-offline-recovery-light-purple-mobile-form');
  const expiring = (await call('POST', grantPath, { id: crypto.randomUUID().replaceAll('-', ''), login: 'websupport', duration_seconds: 8, reason: '自动到期清除当前身份' })).body.data;
  await goto('/tenants'); await supportRow().getByRole('button', { name: '支持访问' }).click(); await page.waitForURL('**/#/devices'); await expect(page.getByRole('button', { name: '当前临时支持身份' })).toBeVisible(); await page.waitForURL('**/#/tenants', { timeout: 12000 }); await expect(page.getByText('支持授权已到期，请重新选择工作身份。', { exact: true })).toBeVisible();
  expect((await call('GET', `${grantPath}?status=expired`)).body.items.some(item => item.id === expiring.id)).toBe(true); report.cases.push('ui-confirmed-revoke-and-clock-expiry-clear-current-context');
  expect(report.errors).toEqual([]); report.status = 'passed';
} catch (error) { report.status = 'failed'; report.failure = error.stack; await screenshot('support-failure'); throw error; }
finally { writeFileSync(`${fixture.base}/support-browser-verification.json`, JSON.stringify(report, null, 2) + '\n'); await browser.close(); console.log(JSON.stringify(report, null, 2)); }
