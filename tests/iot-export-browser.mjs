import { chromium, expect } from '../web/node_modules/@playwright/test/index.mjs';
import { readFileSync, writeFileSync, unlinkSync } from 'node:fs';
import { spawnSync } from 'node:child_process';

const fixture = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const origin = process.argv[3];
if (!origin || !fixture.exports) throw new Error('需要带--exports的隔离fixture.json及Web地址');
const report = { status: 'running', cases: [], errors: [], screenshots: [] };
const browser = await chromium.launch({ channel: 'msedge', headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, timezoneId: 'Asia/Shanghai', acceptDownloads: true });
const page = await context.newPage();
page.on('pageerror', error => report.errors.push(error.message));
const screenshot = async name => {
  const path = `${fixture.base}/${name}.png`;
  if (name !== 'export-failure') {
    await expect(page.locator('.ant-spin-spinning')).toHaveCount(0);
    await expect.poll(async () => {
      const box = await page.locator('.ant-drawer-content-wrapper:visible').boundingBox();
      return box ? Math.round(box.x + box.width) : 0;
    }).toBe(page.viewportSize().width);
    await page.locator('.ant-table-body, .ant-table-content').evaluateAll(elements => elements.forEach(element => { element.scrollLeft = 0; }));
  }
  await page.screenshot({ path, animations: 'disabled' }); report.screenshots.push(path);
};
const worker = (steps = 100) => {
  const result = spawnSync(fixture.exports.command[0], [...fixture.exports.command.slice(1), 'iot:exports', String(steps)], { cwd: new URL('..', import.meta.url), env: { ...process.env, ...fixture.exports.environment }, encoding: 'utf8', timeout: 60000 });
  expect(result.status, result.stderr).toBe(0); return JSON.parse(result.stdout).data;
};
const login = async (user, history = true) => {
  await page.goto(origin); await page.getByLabel('登录账号').fill(user); await page.getByLabel('登录密码').fill('History-browser-password-2026');
  await page.getByRole('button', { name: /^登\s*录$/ }).click();
  await page.waitForURL('**/#/profile'); await page.goto(`${origin}/#/tenants`);
  await page.getByRole('row').filter({ hasText: '历史数据验收组织' }).getByRole('button', { name: '进入租户' }).click();
  await page.waitForURL('**/#/profile'); await page.evaluate(id => { location.hash = `/history?device=${id}`; }, fixture.device);
  if (history) await expect(page.getByText('当前保留 2051 条 · 第 1 页')).toBeVisible();
};
const drawer = () => page.locator('.ant-drawer-open');
const refresh = async () => {
  const waiting = page.waitForResponse(response => response.url().includes(`/tenants/${fixture.tenant}/exports?`));
  await drawer().getByRole('button', { name: '刷新任务', exact: true }).click(); return (await (await waiting).json()).items;
};
const close = async () => { await drawer().getByRole('button', { name: /^关\s*闭$/ }).last().click(); await expect(drawer()).toHaveCount(0); };
const create = async (retry = false) => {
  await page.getByRole('button', { name: retry ? '重试导出请求' : '导出当前查询', exact: true }).click();
  await expect(page.getByText('确认导出当前查询', { exact: true })).toBeVisible();
  const pending = page.waitForResponse(response => response.request().method() === 'POST' && response.url().endsWith(`/devices/${fixture.device}/exports`));
  await page.getByRole('button', { name: '创建任务', exact: true }).click(); const response = await pending;
  expect(response.status()).toBe(202); const job = (await response.json()).data;
  await expect(drawer().getByText('历史导出任务', { exact: true })).toBeVisible();
  await refresh(); return job;
};
const taskRow = id => drawer().locator(`tr[data-row-key="${id}"]`);
const api = async (path, method = 'GET', data) => {
  const token = await page.evaluate(() => sessionStorage.getItem('typeapp.customer.token'));
  return fetch(`http://127.0.0.1:${fixture.port}${path}`, { method, headers: { Authorization: `Bearer ${token}`, 'X-Tenant-Id': fixture.tenant, 'Content-Type': 'application/json' }, ...(data === undefined ? {} : { body: JSON.stringify(data) }) });
};
try {
  report.browser = browser.version();
  await login('webread'); await expect(page.getByRole('button', { name: '导出当前查询' })).toHaveCount(0); await expect(page.getByRole('button', { name: '导出任务', exact: true })).toHaveCount(0);
  expect((await api(`/customer/tenants/${fixture.tenant}/exports`)).status).toBe(403);
  await page.evaluate(() => { sessionStorage.clear(); location.reload(); }); await page.waitForURL('**/#/login');
  await login('webexport', false);
  await expect(page.getByText('当前角色没有遥测查询权限。')).toBeVisible();
  await expect(page.getByRole('button', { name: '导出任务', exact: true })).toHaveCount(0);
  await page.getByRole('button', { name: '导出当前查询', exact: true }).click();
  const isolatedCreation = page.waitForResponse(response => response.request().method() === 'POST' && response.url().endsWith(`/devices/${fixture.device}/exports`));
  await page.getByRole('button', { name: '创建任务', exact: true }).click();
  expect((await isolatedCreation).status()).toBe(202); await expect(drawer().getByText('任务与冻结筛选')).toBeVisible();
  await expect(drawer().getByRole('button', { name: '刷新任务', exact: true })).toHaveCount(0);
  await screenshot('export-create-only'); worker();
  report.cases.push('independent-create-only-without-telemetry-or-list-permissions');
  await page.evaluate(() => { sessionStorage.clear(); location.reload(); }); await page.waitForURL('**/#/login');
  await login('webadmin'); await page.getByRole('button', { name: '导出任务', exact: true }).click(); await expect(drawer().getByText('暂无导出任务')).toBeVisible(); await close();
  await page.locator('#__vben_main_content').getByRole('button', { name: '下一页', exact: true }).click(); await expect(page.getByText('当前保留 2051 条 · 第 2 页')).toBeVisible();
  const cancelled = await create(); expect(cancelled.total_rows).toBe(2051); expect(cancelled.filters.page).toBeUndefined();
  await expect(taskRow(cancelled.id)).toContainText('排队中'); worker(1); await refresh(); await expect(taskRow(cancelled.id)).toContainText('生成中');
  await taskRow(cancelled.id).getByRole('button', { name: /^取\s*消$/ }).click();
  await page.getByRole('button', { name: '取消任务', exact: true }).click(); await expect(taskRow(cancelled.id)).toContainText('已取消'); await close();
  report.cases.push('readonly-server-denial-empty-tasks-page-two-full-filter-confirm-queued-running-cancel');

  let lostTask; let submissions = 0;
  const lostRoute = `**/customer/tenants/${fixture.tenant}/devices/${fixture.device}/exports`;
  await page.route(lostRoute, async route => {
    submissions++; const response = await route.fetch(); expect(response.status()).toBe(202);
    lostTask = (await response.json()).data; await route.abort('failed');
  });
  await page.getByRole('button', { name: '导出当前查询', exact: true }).click();
  await expect(page.locator('.page-heading button').filter({ hasText: '导出当前查询' })).toBeDisabled();
  await page.getByRole('button', { name: '创建任务', exact: true }).click();
  await expect(drawer().getByRole('alert')).toContainText('网络连接失败');
  expect(submissions).toBe(1); await page.unroute(lostRoute);
  await page.reload(); await expect(page.getByRole('button', { name: '重试导出请求', exact: true })).toBeEnabled();
  const reconciled = await create(true); expect(reconciled.id).toBe(lostTask.id);
  expect((await refresh()).filter(item => item.id === lostTask.id)).toHaveLength(1);
  worker(); await refresh(); await expect(taskRow(lostTask.id)).toContainText('已完成'); await close();
  report.cases.push('lost-create-response-reload-same-request-reconciliation-no-duplicate');

  const complete = await create(); worker(); await refresh(); await expect(taskRow(complete.id)).toContainText('已完成');
  const event = page.waitForEvent('download'); await taskRow(complete.id).getByRole('button', { name: /^下\s*载$/ }).click();
  const downloaded = await event; const csv = readFileSync(await downloaded.path(), 'utf8'); expect(csv).toContain('°C'); expect(csv).toContain('Asia/Shanghai'); expect(csv).toContain("'原始上报");
  await taskRow(complete.id).getByRole('button', { name: /^详\s*情$/ }).click(); await expect(drawer().getByText('任务与冻结筛选')).toBeVisible();
  await screenshot('export-desktop-dark'); await close();
  const failed = await create(); worker(1); unlinkSync(`${fixture.base}/storage/exports/${failed.id}.csv`); worker(); await refresh();
  await expect(taskRow(failed.id)).toContainText('失败'); await taskRow(failed.id).getByRole('button', { name: /^详\s*情$/ }).click(); await expect(drawer().getByText('文件丢失或不完整')).toBeVisible();
  report.cases.push('real-background-download-and-file-loss-failure');

  await context.setOffline(true); await drawer().getByRole('button', { name: '刷新任务', exact: true }).click(); await expect(drawer().getByRole('alert')).toContainText('网络连接失败');
  await context.setOffline(false); await refresh(); await expect(drawer().getByRole('alert')).toHaveCount(0);
  await page.setViewportSize({ width: 390, height: 844 });
  await expect.poll(() => drawer().locator('.ant-drawer-body').evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1);
  await screenshot('export-mobile-dark'); await close();
  await page.getByRole('button', { name: 'light', exact: true }).click(); await page.getByRole('button', { name: '主题色', exact: true }).click();
  await page.getByLabel('主题色', { exact: true }).click(); await page.getByText('紫罗兰', { exact: true }).last().click(); await page.getByRole('heading', { name: '历史数据', exact: true }).click();
  await page.getByRole('button', { name: '导出任务', exact: true }).click(); await refresh();
  await taskRow(complete.id).getByRole('button', { name: /^详\s*情$/ }).click();
  await drawer().locator('.ant-drawer-body').evaluate(element => { element.scrollTop = element.scrollHeight; }); await screenshot('export-mobile-light-purple-details');
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBe(390);
  report.cases.push('network-recovery-390px-dark-light-purple-long-identifiers');

  await close(); await page.setViewportSize({ width: 1440, height: 1000 });
  const expired = await create(); worker(); await refresh(); await expect(taskRow(expired.id)).toContainText('已完成');
  const expiration = spawnSync(fixture.exports.fixturePhp, ['-r', '$db = new PDO("sqlite:" . $argv[1]); $db->prepare("UPDATE iot_exports SET expires_at = ? WHERE id = ?")->execute([time() - 1, $argv[2]]);', `${fixture.base}/history.sqlite`, expired.id], { env: process.env, encoding: 'utf8', timeout: 10000 });
  expect(expiration.status, expiration.stderr).toBe(0);
  expect((await api(`/customer/tenants/${fixture.tenant}/exports/${expired.id}/download`)).status).toBe(410);
  await refresh(); await expect(taskRow(expired.id)).toContainText('已过期'); await expect(taskRow(expired.id).getByRole('button', { name: /^下\s*载$/ })).toHaveCount(0);
  await screenshot('export-expired-light-purple');
  report.cases.push('server-expiry-rejects-old-file-and-browser-hides-download');

  const members = (await (await api('/customer/members')).json()).data;
  const roles = (await (await api('/customer/roles')).json()).data.items;
  const highest = roles.find(role => role.protected);
  const other = members.items.find(member => member.login === 'webread'); const self = members.items.find(member => member.login === 'webadmin');
  expect((await api('/customer/members/roles', 'PUT', { members: [{ id: other.id, version: other.version }], roles: [{ id: highest.id, version: highest.version }] })).status).toBe(200);
  expect((await api(`/customer/members/${self.id}`, 'DELETE', { version: self.version })).status).toBe(200);
  await taskRow(complete.id).getByRole('button', { name: /^下\s*载$/ }).click(); await page.waitForURL('**/#/tenants');
  report.cases.push('old-download-after-current-membership-revocation');
  expect(report.errors).toEqual([]); report.status = 'passed';
} catch (error) { report.status = 'failed'; report.failure = error.stack; await screenshot('export-failure'); throw error; }
finally { writeFileSync(`${fixture.base}/export-browser-verification.json`, JSON.stringify(report, null, 2) + '\n'); await browser.close(); console.log(JSON.stringify(report, null, 2)); }
