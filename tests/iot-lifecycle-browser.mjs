import { chromium, expect } from '../web/node_modules/@playwright/test/index.mjs';
import { readFileSync, writeFileSync } from 'node:fs';

const fixture = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const origin = process.argv[3];
if (!fixture.devices || !origin) throw new Error('用法：node tests/iot-lifecycle-browser.mjs <生命周期fixture.json> <生产预览地址>');
const report = { status: 'running', native: fixture.native, binary_sha256: fixture.binary_sha256, cases: [], errors: [], screenshots: [] };
const browser = await chromium.launch({ channel: 'msedge', headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, timezoneId: 'Asia/Shanghai' });
const page = await context.newPage();
page.on('pageerror', error => report.errors.push(error.message));
const drawer = () => page.locator('.ant-drawer-content-wrapper:visible');
const body = () => page.locator('.ant-drawer-body:visible');
const screenshot = async name => { const path = `${fixture.base}/${name}.png`; await page.screenshot({ path, animations: 'disabled', mask: [page.locator('.ant-drawer-body code')] }); report.screenshots.push(path); };
const goto = async path => { await page.evaluate(value => { location.hash = value; }, path); };
const enter = async name => { await page.getByRole('row').filter({ hasText: name }).getByRole('button', { name: '进入租户' }).click(); await page.waitForURL('**/#/members'); };
const login = async name => { await page.getByLabel('登录账号').fill(name); await page.getByLabel('登录密码').fill('Lifecycle-browser-password-2026'); await page.getByRole('button', { name: /^登\s*录$/ }).click(); await enter('生命周期验收组织'); await goto('/devices'); };
const close = async () => { await expect(page.locator('.ant-drawer-close:visible')).toHaveCount(1); await page.locator('.ant-drawer-close:visible').click(); await expect(drawer()).toHaveCount(0); };
const manage = async id => { await page.getByRole('row').filter({ hasText: id }).getByRole('button', { name: /^管\s*理$/ }).click(); await expect(page.getByText('管理设备授权', { exact: true })).toBeVisible(); await expect(body()).toContainText(id); await expect(page.getByRole('button', { name: '读取当前状态', exact: true })).toBeEnabled(); };
const selectAction = async label => { await page.getByLabel('管理动作', { exact: true }).click(); await page.getByText(label, { exact: true }).last().click(); };
const prepare = async (label, id) => { await selectAction(label); await page.getByLabel('确认设备标识', { exact: true }).fill(id); };
const readCurrent = async () => { await page.getByRole('button', { name: '读取当前状态', exact: true }).click(); await expect(page.getByRole('button', { name: '读取当前状态', exact: true })).toBeEnabled(); };
const stored = () => page.evaluate(() => JSON.stringify({ local: { ...localStorage }, session: { ...sessionStorage } }));
let token;
const call = async (method, path, data, tenant = fixture.tenant) => {
  const response = await fetch(`http://127.0.0.1:${fixture.port}${path}`, { method, headers: { 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}), ...(tenant ? { 'X-Tenant-Id': tenant } : {}) }, ...(method === 'GET' ? {} : { body: JSON.stringify(data) }) });
  return { status: response.status, body: await response.json() };
};
const devicePath = id => `/iot/tenants/${fixture.tenant}/devices/${id}`;
const enforced = async id => { await expect.poll(async () => (await call('GET', devicePath(id))).body.data.authorization.status, { timeout: 30000 }).toBe('enforced'); };
const submit = async label => { await page.getByRole('button', { name: `确认${label}`, exact: true }).click(); await expect(page.getByRole('button', { name: '读取当前状态', exact: true })).toBeEnabled(); };
try {
  report.browser = browser.version();
  if (fixture.recovery) {
    token = (await call('POST', '/iot/auth/login', { login: 'bob', password: fixture.password }, null)).body.data.accessToken;
    await page.goto(origin);
    await page.getByLabel('登录账号').fill('bob'); await page.getByLabel('登录密码').fill(fixture.password);
    await page.getByRole('button', { name: /^登\s*录$/ }).click();
    await page.getByRole('row').filter({ hasText: fixture.tenant }).getByRole('button', { name: '进入租户' }).click();
    await page.waitForURL('**/#/members');
    const member = page.getByRole('row').filter({ hasText: fixture.member_login });
    await expect(member).toContainText('待核对或重新授权');
    await goto('/devices');
    await page.getByLabel('设备名称筛选').fill('旧凭据已吊销'); await page.getByRole('button', { name: /^查\s*询$/ }).click();
    await expect(page.getByRole('row').filter({ hasText: fixture.devices.isolated })).toContainText('待恢复核对');
    await manage(fixture.devices.isolated); await expect(body()).toContainText('设备仍待恢复核对');
    await expect(page.getByLabel('管理动作', { exact: true }).getByRole('combobox')).toBeDisabled();
    await screenshot('recovery-desktop-isolated-dark'); await close();
    await goto(`/devices/${fixture.devices.isolated}`);
    await expect(page.getByText('恢复后尚未核对设备当前授权与归属，接入、控制和变更保持隔离。请联系恢复核对负责人。')).toBeVisible();
    await expect(page.getByRole('button', { name: '切换模型', exact: true })).toBeDisabled();
    const modelButton = page.getByRole('button', { name: '发起指令', exact: true });
    if (await modelButton.count()) await expect(modelButton).toBeDisabled();
    await page.setViewportSize({ width: 390, height: 844 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBe(390);
    await screenshot('recovery-mobile-isolated-dark');
    await page.getByRole('button', { name: 'light', exact: true }).click();
    await expect(page.locator('html')).not.toHaveClass(/\bdark\b/);
    await expect(page.getByRole('button', { name: 'dark', exact: true })).toBeVisible();
    await screenshot('recovery-mobile-isolated-light');
    const stale = await call('POST', `${devicePath(fixture.devices.isolated)}/rotate`, {
      version: (await call('GET', devicePath(fixture.devices.isolated))).body.data.version, confirm_device_id: fixture.devices.isolated,
    });
    expect(stale.status).toBe(409); expect(stale.body.error).toBe('recovery_reconciliation_required');
    await page.setViewportSize({ width: 1440, height: 1000 });
    await goto(`/devices/${fixture.devices.stable}`); await expect(page.getByText('身份保持一致', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('恢复后尚未核对设备当前授权与归属，接入、控制和变更保持隔离。请联系恢复核对负责人。')).toHaveCount(0);
    await screenshot('recovery-desktop-stable-light');
    report.cases.push('restored-member-isolation-visible', 'isolated-device-list-drawer-and-detail', 'server-refuses-isolated-management', 'stable-device-readable', 'dark-light-mobile-no-overflow');
  } else {
  token = (await call('POST', '/iot/auth/login', { login: 'webadmin', password: 'Lifecycle-browser-password-2026' }, null)).body.data.accessToken;
  await page.goto(origin); await login('webadmin'); await expect(page.getByText('4 台设备', { exact: false })).toBeVisible();
  await manage(fixture.devices.main); await prepare('轮换凭据', '0'.repeat(32)); await submit('轮换凭据'); await expect(body().getByRole('alert')).toContainText('完全一致');
  await page.getByLabel('确认设备标识', { exact: true }).fill(fixture.devices.main); await context.setOffline(true); await submit('轮换凭据');
  await expect(body().getByRole('alert')).toContainText('网络连接失败'); await expect(body().getByRole('status')).toContainText('不会自动重试'); await expect(page.getByRole('button', { name: '确认轮换凭据', exact: true })).toBeDisabled();
  await context.setOffline(false); await readCurrent(); await prepare('轮换凭据', fixture.devices.main);
  let release; const gate = new Promise(resolve => { release = resolve; }); let calls = 0;
  await page.route(`**/devices/${fixture.devices.main}/rotate`, async route => { calls++; const response = await route.fetch(); await gate; await route.fulfill({ response }); });
  await page.getByRole('button', { name: '确认轮换凭据', exact: true }).click(); await expect.poll(() => calls).toBe(1); await expect(page.getByRole('button', { name: /^取\s*消$/ })).toBeDisabled(); await expect(page.locator('.ant-drawer-footer .ant-btn-primary')).toBeDisabled(); await expect(page.locator('.ant-drawer-close:visible')).toHaveCount(0); await page.keyboard.press('Escape'); await expect(page.getByText('管理设备授权', { exact: true })).toBeVisible();
  release(); await page.unrouteAll({ behavior: 'wait' }); await expect(page.getByText('保存设备接入凭据', { exact: true })).toBeVisible();
  const secret = await body().locator('code').innerText(); expect(secret).toMatch(/^[a-f0-9]{64}$/); expect(await stored()).not.toContain(secret); await close(); await expect(page.getByText(secret, { exact: true })).toHaveCount(0);
  await manage(fixture.devices.main); await expect(body()).toContainText('等待 Broker 撤权'); await expect(page.getByLabel('管理动作', { exact: true }).getByRole('combobox')).toBeDisabled(); await screenshot('lifecycle-desktop-pending');
  writeFileSync(fixture.broker_start_marker, 'start\n'); await enforced(fixture.devices.main); await expect(body()).toContainText('撤权已执行', { timeout: 15000 }); await readCurrent(); await screenshot('lifecycle-desktop-enforced');
  report.cases.push('exact-id-offline-no-automatic-retry-single-rotate-save-guard-one-time-secret-real-broker-pending-enforced');

  await prepare('禁用设备', fixture.devices.main); await submit('禁用设备'); await expect(body()).toContainText('凭据已吊销'); await enforced(fixture.devices.main); await readCurrent();
  await prepare('启用设备', fixture.devices.main); await submit('启用设备'); await expect(body()).toContainText('凭据已吊销'); expect((await call('GET', devicePath(fixture.devices.main))).body.data.lifecycle).toBe('enabled');
  await prepare('轮换凭据', fixture.devices.main); await page.getByRole('button', { name: '确认轮换凭据', exact: true }).click(); await expect(page.getByText('保存设备接入凭据', { exact: true })).toBeVisible(); await close(); await manage(fixture.devices.main);
  await prepare('吊销凭据', fixture.devices.main); await submit('吊销凭据'); await enforced(fixture.devices.main); await readCurrent();
  await prepare('退役设备', fixture.devices.main); await submit('退役设备'); await expect(body()).toContainText('设备已永久退役'); await expect(page.getByLabel('管理动作', { exact: true })).toHaveCount(0); await expect(page.locator('.ant-drawer-footer .ant-btn-primary')).toHaveCount(0);
  await page.setViewportSize({ width: 390, height: 844 }); await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBe(390); await screenshot('lifecycle-mobile-retired-dark'); await close();
  await page.getByRole('button', { name: 'light', exact: true }).click(); await page.getByRole('button', { name: '主题色', exact: true }).click(); await page.getByLabel('主题色', { exact: true }).first().click(); await page.getByText('紫罗兰', { exact: true }).last().click(); await page.getByText('4 台设备', { exact: false }).click(); await expect(page.locator('.ant-popover:visible')).toHaveCount(0);
  await manage(fixture.devices.main); await screenshot('lifecycle-mobile-retired-light-purple'); await body().evaluate(el => { el.scrollTop = el.scrollHeight; }); await expect(body().getByText('设备已永久退役，不提供恢复或硬删除。保留期内历史仍可按原权限查询。')).toBeInViewport(); await close(); await page.setViewportSize({ width: 1440, height: 1000 });
  await page.getByRole('row').filter({ hasText: fixture.devices.main }).getByRole('button', { name: /^历\s*史$/ }).click(); await expect(page.getByText('当前保留 1 条', { exact: false })).toBeVisible(); await goto('/devices');
  report.cases.push('disable-revoke-enable-does-not-revive-retire-terminal-original-history-long-name-theme-mobile');

  await manage(fixture.devices.lost); await prepare('轮换凭据', fixture.devices.lost); let lostCalls = 0; let lostSecret = '';
  await page.route(`**/devices/${fixture.devices.lost}/rotate`, async route => { lostCalls++; const response = await route.fetch(); lostSecret = (await response.json()).data.credential.password; await route.abort('failed'); });
  await submit('轮换凭据'); await expect(body().getByRole('status')).toContainText('操作可能已经提交'); await expect(body().getByRole('status')).toBeInViewport(); expect(lostCalls).toBe(1); expect((await call('GET', devicePath(fixture.devices.lost))).body.data.version).toBe(2); expect(await stored()).not.toContain(lostSecret); await expect(page.getByRole('button', { name: '确认轮换凭据', exact: true })).toBeDisabled();
  await screenshot('lifecycle-desktop-response-lost'); await page.unrouteAll({ behavior: 'wait' }); await readCurrent(); await expect(page.getByRole('button', { name: '确认操作', exact: true })).toBeDisabled(); expect(lostCalls).toBe(1); await close();
  report.cases.push('real-committed-rotation-response-lost-no-replay-no-secret-recovery-explicit-refresh');

  await manage(fixture.devices.conflict); await prepare('禁用设备', fixture.devices.conflict);
  expect((await call('POST', `${devicePath(fixture.devices.conflict)}/revoke`, { version: 1, confirm_device_id: fixture.devices.conflict })).status).toBe(202);
  await submit('禁用设备'); await expect(body().getByRole('alert')).toContainText('记录已被其他人修改'); await expect(page.getByLabel('确认设备标识', { exact: true })).toHaveValue(fixture.devices.conflict); await enforced(fixture.devices.conflict); await readCurrent(); await prepare('禁用设备', fixture.devices.conflict); await submit('禁用设备'); expect((await call('GET', devicePath(fixture.devices.conflict))).body.data.lifecycle).toBe('disabled'); await close();
  report.cases.push('real-version-conflict-preserves-input-explicit-refresh-corrected-submit');

  await manage(fixture.devices.switch); await prepare('轮换凭据', fixture.devices.switch); let releaseSwitch; const switchGate = new Promise(resolve => { releaseSwitch = resolve; }); let switchCalls = 0;
  await page.route(`**/devices/${fixture.devices.switch}/rotate`, async route => { switchCalls++; const response = await route.fetch(); await switchGate; try { await route.fulfill({ response }); } catch (error) { if (!/Route is already handled/.test(error.message)) throw error; } });
  await page.getByRole('button', { name: '确认轮换凭据', exact: true }).click(); await expect.poll(() => switchCalls).toBe(1); await goto('/tenants'); await enter('生命周期空组织'); releaseSwitch(); await page.unrouteAll({ behavior: 'wait' }); await goto('/devices'); await expect(page.getByText('暂无符合条件的设备', { exact: true })).toBeVisible(); await expect(page.getByText('保存设备接入凭据', { exact: true })).toHaveCount(0); await screenshot('lifecycle-empty-other-tenant');
  await page.getByRole('button', { name: '退出登录', exact: true }).click(); await login('webread'); await expect(page.getByText('4 台设备', { exact: false })).toBeVisible(); await expect(page.getByRole('button', { name: /^管\s*理$/ })).toHaveCount(0); await expect(page.getByRole('button', { name: '注册设备', exact: true })).toHaveCount(0);
  expect((await call('DELETE', `/iot/tenants/${fixture.tenant}/members/${fixture.member}`, { version: 1 })).status).toBe(200); await page.getByRole('button', { name: /^查\s*询$/ }).click(); await page.waitForURL('**/#/tenants'); await expect(page.getByText('管理设备授权', { exact: true })).toHaveCount(0);
  report.cases.push('switch-tenant-cancels-secret-response-empty-list-readonly-no-management-real-revocation');
  }
  expect(report.errors).toEqual([]); report.status = 'passed';
} catch (error) { report.status = 'failed'; report.failure = error.stack; await screenshot('lifecycle-failure'); throw error; }
finally { writeFileSync(`${fixture.base}/browser-verification.json`, JSON.stringify(report, null, 2) + '\n'); await browser.close(); console.log(JSON.stringify(report, null, 2)); }
