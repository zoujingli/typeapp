import { chromium, expect } from '../web/node_modules/@playwright/test/index.mjs';
import { writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { DatabaseSync } from 'node:sqlite';
import { randomUUID } from 'node:crypto';
import { brokerPreview } from './broker-resources-browser.mjs';

/** 使用调用方持有的隔离 HTTP 应用，验证品牌读取、设置保存、冲突及窄屏真实交互。 */
export async function siteBrowser(base, dist, upstream, adminPassword, customerPassword) {
  const preview = brokerPreview(dist, upstream);
  const report = { status: 'running', cases: [], screenshots: [], errors: [], cleanup: {} };
  let browser; let context; let page;
  const stop = new AbortController();
  let interruptClose = Promise.resolve();
  const interrupt = () => {
    if (stop.signal.aborted) return;
    stop.abort(new Error('site_browser_interrupted'));
    if (context) interruptClose = context.close().catch(() => {});
  };
  process.on('SIGTERM', interrupt); process.on('SIGINT', interrupt);
  const deadline = setTimeout(interrupt, 180000);
  const safe = error => String(error?.stack || error).replaceAll(adminPassword, '<REDACTED>').replaceAll(customerPassword, '<REDACTED>').replaceAll(process.cwd(), '.');
  try {
    await new Promise((resolve, reject) => { preview.server.once('error', reject); preview.server.listen(0, '127.0.0.1', resolve); });
    const url = `http://127.0.0.1:${preview.server.address().port}`;
    browser = await chromium.launch({ channel: 'msedge', headless: true, timeout: 30000 });
    stop.signal.throwIfAborted();
    report.browser = browser.version();
    context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, timezoneId: 'Asia/Shanghai' });
    stop.signal.throwIfAborted();
    context.setDefaultTimeout(15000);
    page = await context.newPage();
    page.on('pageerror', error => report.errors.push(safe(error)));
    const publicResponse = await context.request.get(`${url}/public/site`);
    expect(publicResponse.status()).toBe(200);
    const publicSite = (await publicResponse.json()).data;
    expect(Object.keys(publicSite).sort()).toEqual(['environment', 'name', 'official_url', 'description', 'logo_url', 'timezone', 'theme', 'preferences'].sort());
    await page.goto(`${url}/#/admin/login`);
    await expect(page).toHaveTitle(`平台登录 · ${publicSite.name} · 物联开源分享`);
    await page.getByLabel('登录账号').fill('same-login');
    await page.getByLabel('登录密码').fill(adminPassword);
    await page.getByRole('button', { name: /^登\s*录$/ }).click();
    await expect(page).toHaveURL(/#\/admin\/profile$/);
    const token = await page.evaluate(() => sessionStorage.getItem('typeapp.admin.token'));
    const api = async (method, path, data, status = 200, bearer = token) => {
      const response = await context.request.fetch(`${url}${path}`, { method, data, headers: { Authorization: `Bearer ${bearer}` } });
      expect(response.status()).toBe(status);
      return (await response.json()).data;
    };
    if (process.env.TYPE_APP_BROWSER_CASES === 'applications') {
      const tenantId = randomUUID().replaceAll('-', '');
      await api('POST', '/admin/tenants', { id: tenantId, name: '浏览器应用客户', new_customer: false, owner_login: 'same-login' });
      await page.goto(`${url}/#/admin/applications`);
      await expect(page.getByRole('heading', { name: '接入应用', exact: true })).toBeVisible();
      await page.getByRole('button', { name: '创建应用', exact: true }).click();
      const drawer = () => page.locator('.ant-drawer-open').last();
      await drawer().getByLabel('客户标识', { exact: true }).fill(tenantId);
      await drawer().getByLabel('应用名称', { exact: true }).fill('页面接入应用');
      const createdResponse = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/admin/applications');
      await drawer().getByRole('button', { name: /^保\s*存$/ }).click();
      const created = await createdResponse; expect(created.status()).toBe(201);
      const result = (await created.json()).data;
      await expect(page.getByLabel('一次性应用密钥')).toHaveValue(result.credential.password);
      await drawer().getByRole('button', { name: 'Close', exact: true }).click();
      await expect(page.getByLabel('一次性应用密钥')).toHaveCount(0);
      let row = page.getByRole('row').filter({ hasText: '页面接入应用' });
      await row.getByRole('button', { name: '详情', exact: true }).click();
      await expect(drawer()).toContainText(`app:${result.application.id}`);
      expect(await drawer().innerText()).not.toContain(result.credential.password);
      await drawer().getByRole('button', { name: 'Close', exact: true }).click();
      await expect(page.locator('.ant-drawer-content-wrapper:visible')).toHaveCount(0);
      const enrollment = await api('POST', '/admin/device-authorizations', { id: randomUUID().replaceAll('-', ''), tenant_id: tenantId,
        name: '页面授权注册批次', capacity: 1, register_expires_at: Math.floor(Date.now() / 1000) + 3600 }, 201);
      const registered = await api('POST', '/public/devices/register', { id: randomUUID().replaceAll('-', ''), tenant_id: tenantId,
        device_id: randomUUID().replaceAll('-', ''), name: '页面通信设备', code: enrollment.code }, 201);
      await row.getByRole('button', { name: '更多' }).click();
      await page.getByRole('menuitem', { name: '设备授权', exact: true }).click();
      await drawer().getByRole('button', { name: '添加设备授权', exact: true }).click();
      await drawer().getByLabel('选择设备', { exact: true }).click();
      await page.getByTitle(`页面通信设备 · ${registered.device.id}`, { exact: true }).click();
      await expect(drawer().getByLabel('当前使用关系', { exact: true })).toHaveValue(registered.device.ownership_id);
      await drawer().getByRole('checkbox', { name: '接收上行', exact: true }).check();
      await drawer().getByRole('checkbox', { name: '发送下行', exact: true }).check();
      await drawer().getByRole('checkbox', { name: '订阅上行', exact: true }).check();
      const authorized = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith(`/devices/${registered.device.id}`));
      await drawer().getByRole('button', { name: /^保\s*存$/ }).click();
      expect((await authorized).status()).toBe(200);
      await expect(drawer().getByRole('row').filter({ hasText: '页面通信设备' })).toContainText('订阅');
      await page.screenshot({ path: resolve(base, 'application-grants-admin.png'), fullPage: true, animations: 'disabled' }); report.screenshots.push('application-grants-admin.png');
      await drawer().getByRole('button', { name: 'Close', exact: true }).click();
      await expect(page.locator('.ant-drawer-content-wrapper:visible')).toHaveCount(0);
      await page.screenshot({ path: resolve(base, 'applications-admin.png'), fullPage: true, animations: 'disabled' }); report.screenshots.push('applications-admin.png');
      const customer = await api('POST', '/customer/auth/login', { login: 'same-login', password: customerPassword });
      await page.evaluate(token => sessionStorage.setItem('typeapp.customer.token', token), customer.accessToken);
      await page.goto(`${url}/#/tenants`);
      await page.getByRole('row').filter({ hasText: '浏览器应用客户' }).getByRole('button', { name: '进入租户', exact: true }).click();
      await expect(page).toHaveURL(/#\/profile$/);
      await page.goto(`${url}/#/applications`);
      row = page.getByRole('row').filter({ hasText: '页面接入应用' });
      await expect(row).toContainText('已启用');
      await row.getByRole('button', { name: '编辑', exact: true }).click();
      await expect(drawer().getByLabel('客户标识', { exact: true })).toHaveCount(0);
      await drawer().getByLabel('应用名称', { exact: true }).fill('客户已编辑的接入应用');
      await drawer().getByRole('button', { name: /^保\s*存$/ }).click();
      row = page.getByRole('row').filter({ hasText: '客户已编辑的接入应用' });
      await expect(row).toContainText('已完成');
      await row.getByRole('button', { name: '更多' }).click();
      await page.getByRole('menuitem', { name: '轮换密钥', exact: true }).click();
      await page.getByRole('button', { name: /^确\s*认$/ }).click();
      await expect(page.getByLabel('一次性应用密钥')).toBeVisible();
      expect(await page.getByLabel('一次性应用密钥').inputValue()).not.toBe(result.credential.password);
      await drawer().getByRole('button', { name: 'Close', exact: true }).click();
      await expect(row).toContainText('v2');
      await expect(page.locator('.ant-drawer-content-wrapper:visible')).toHaveCount(0);
      await row.getByRole('button', { name: '更多' }).click();
      await page.getByRole('menuitem', { name: '设备授权', exact: true }).click();
      await drawer().getByRole('row').filter({ hasText: '页面通信设备' }).getByRole('button', { name: '调整授权', exact: true }).click();
      await drawer().getByRole('checkbox', { name: '接收上行', exact: true }).uncheck();
      await expect(drawer().getByRole('checkbox', { name: '订阅上行', exact: true })).not.toBeChecked();
      await expect(drawer().getByRole('checkbox', { name: '订阅上行', exact: true })).toBeDisabled();
      await drawer().getByRole('checkbox', { name: '发送下行', exact: true }).uncheck();
      await drawer().getByRole('button', { name: /^保\s*存$/ }).click();
      await expect(drawer().getByRole('row').filter({ hasText: '页面通信设备' })).toContainText('已撤销');
      await drawer().getByRole('button', { name: 'Close', exact: true }).click();
      await expect(page.locator('.ant-drawer-content-wrapper:visible')).toHaveCount(0);
      await page.setViewportSize({ width: 390, height: 844 });
      if (!(await page.locator('html').getAttribute('class'))?.includes('dark')) await page.getByRole('button', { name: 'dark', exact: true }).click();
      await expect(page.locator('html')).toHaveClass(/\bdark\b/);
      await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
      await page.screenshot({ path: resolve(base, 'applications-customer-narrow.png'), fullPage: true, animations: 'disabled' }); report.screenshots.push('applications-customer-narrow.png');
      report.cases.push('控制端创建与一次性交付、详情无秘密、设备选择及三项授权、客户端编辑换证与撤销授权、暗色窄屏无溢出');
      expect(report.errors).toEqual([]); report.status = 'passed';
      return;
    }
    if (process.env.TYPE_APP_BROWSER_CASES === 'enrollments') {
      const tenantId = randomUUID().replaceAll('-', '');
      await api('POST', '/admin/tenants', { id: tenantId, name: '浏览器注册客户', new_customer: false, owner_login: 'same-login' });
      await page.goto(`${url}/#/admin/device-authorizations`);
      await expect(page.getByRole('heading', { name: '设备注册授权', exact: true })).toBeVisible();
      await page.getByRole('button', { name: '签发授权', exact: true }).click();
      const drawer = () => page.locator('.ant-drawer-open').last();
      await drawer().getByLabel('客户标识', { exact: true }).fill(tenantId);
      await drawer().getByLabel('授权名称', { exact: true }).fill('页面验收注册批次');
      await drawer().getByLabel('可注册设备数', { exact: true }).fill('3');
      await drawer().getByLabel('注册截止时间（本地时区）', { exact: true }).fill('2030-01-01T00:00');
      const issuedResponse = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/admin/device-authorizations');
      await drawer().getByRole('button', { name: /^保\s*存$/ }).click();
      const issued = await issuedResponse; expect(issued.status()).toBe(201);
      const result = (await issued.json()).data;
      await expect.poll(async () => await drawer().getByLabel('一次性注册码').inputValue() === result.code).toBe(true);
      await drawer().locator('.ant-drawer-close').click();
      await expect(page.getByLabel('一次性注册码')).not.toBeVisible();
      const row = page.getByRole('row').filter({ hasText: '页面验收注册批次' });
      await row.getByRole('button', { name: /^详\s*情$/ }).click();
      await expect(drawer()).toContainText('尚无设备通过本资格注册');
      expect((await drawer().innerText()).includes(result.code)).toBe(false);
      await drawer().locator('.ant-drawer-close').click();
      await row.getByRole('button', { name: '重置注册码', exact: true }).click();
      const resetResponse = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith(`/${result.authorization.id}/reset`));
      await page.locator('.ant-modal-confirm').getByRole('button', { name: /^确\s*认$/ }).click();
      const reset = await resetResponse; expect(reset.status()).toBe(200);
      const changed = (await reset.json()).data;
      expect(changed.code !== result.code).toBe(true);
      await expect.poll(async () => await drawer().getByLabel('一次性注册码').inputValue() === changed.code).toBe(true);
      await drawer().locator('.ant-drawer-close').click();
      const registered = await api('POST', '/public/devices/register', { id: randomUUID().replaceAll('-', ''), tenant_id: tenantId,
        device_id: randomUUID().replaceAll('-', ''), name: '页面原始通信设备', code: changed.code }, 201, '');
      await row.getByRole('button', { name: /^详\s*情$/ }).click();
      await expect(drawer()).toContainText(registered.device.id);
      expect((await drawer().innerText()).includes(registered.credential.password)).toBe(false);
      await drawer().locator('.ant-drawer-close').click();
      await page.getByRole('button', { name: /^刷\s*新$/ }).click();
      await expect(row).toContainText('1 / 3 已注册');
      await row.getByRole('button', { name: /^停\s*用$/ }).click();
      const disableResponse = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith(`/${result.authorization.id}/disable`));
      await page.locator('.ant-modal-confirm').getByRole('button', { name: /^确\s*认$/ }).click();
      expect((await disableResponse).status()).toBe(200);
      await expect(row).toContainText('已停用'); await expect(row.getByRole('button', { name: '重置注册码', exact: true })).toBeDisabled();
      await expect(page.locator('.ant-modal-confirm')).toHaveCount(0);
      await page.locator('.ant-table-body, .ant-table-content').evaluateAll(elements => { for (const element of elements) element.scrollLeft = 0; });
      report.cases.push('控制端真实签发、一次性交付、详情无秘密、换码与停用');
      await page.screenshot({ path: resolve(base, 'enrollments-admin.png'), fullPage: true }); report.screenshots.push('enrollments-admin.png');
      const customer = await api('POST', '/customer/auth/login', { login: 'same-login', password: customerPassword });
      await page.evaluate(({ token }) => {
        sessionStorage.setItem('typeapp.customer.token', token);
      }, { token: customer.accessToken });
      await page.goto(`${url}/#/tenants`);
      const tenantRow = page.getByRole('row').filter({ hasText: '浏览器注册客户' });
      await tenantRow.getByRole('button', { name: '进入租户', exact: true }).click();
      await expect(page).toHaveURL(/#\/profile$/);
      await expect(page.locator('.current-tenant')).toContainText('浏览器注册客户');
      await page.goto(`${url}/#/device-authorizations`);
      await expect(page.getByRole('row').filter({ hasText: '页面验收注册批次' })).toContainText('已停用');
      await expect(page.getByRole('button', { name: '签发授权', exact: true })).toHaveCount(0);
      await expect(page.getByRole('button', { name: '重置注册码', exact: true })).toHaveCount(0);
      await page.setViewportSize({ width: 390, height: 844 });
      await page.getByRole('button', { name: 'dark', exact: true }).click();
      await expect(page.locator('html')).toHaveClass(/dark/);
      await expect.poll(() => page.evaluate(() => document.getAnimations().filter(animation =>
        animation.playState === 'running' && Number.isFinite(animation.effect?.getComputedTiming().endTime)).length)).toBe(0);
      await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
      await page.screenshot({ path: resolve(base, 'enrollments-customer-narrow.png'), fullPage: true }); report.screenshots.push('enrollments-customer-narrow.png');
      report.cases.push('客户端按当前客户读取真实资格，隐藏管理动作，窄屏暗色无页面横向溢出');
      await page.goto(`${url}/#/devices/${registered.device.id}`);
      await expect(page.getByRole('heading', { name: '设备详情', exact: true })).toBeVisible();
      await expect(page.locator('.iot-page')).toContainText('页面原始通信设备');
      await expect(page.locator('.iot-page')).toContainText('原始通信');
      await expect(page.locator('.iot-page')).toContainText('/raw/up');
      await expect(page.locator('.iot-page')).toContainText('尚无连接观察');
      await expect(page.getByText('模型切换', { exact: true })).toHaveCount(0);
      await expect(page.getByText('设备指令', { exact: true })).toHaveCount(0);
      await expect(page.getByRole('button', { name: '查看历史曲线' })).toHaveCount(0);
      await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
      await page.screenshot({ path: resolve(base, 'registration-device-narrow.png'), fullPage: true }); report.screenshots.push('registration-device-narrow.png');
      report.cases.push('真实资格消费与绑定可见，无物模型详情展示原始主题并隐藏旧业务动作');
      expect(report.errors).toEqual([]); report.status = 'passed';
      return;
    }
    if (process.env.TYPE_APP_BROWSER_CASES === 'workspaces') {
      let settings = await api('GET', '/admin/site');
      await api('PUT', '/admin/site', { version: settings.version, changes: { tabbar_enable: true, tabbar_style: 'chrome', sidebar_collapsed: false } });
      await page.reload();
      await page.getByText('身份与租户', { exact: true }).click();
      await page.getByRole('menuitem', { name: '平台人员', exact: true }).click();
      await expect(page).toHaveURL(/#\/admin\/users$/);
      await expect(page.getByRole('navigation', { name: 'breadcrumb' })).toContainText('身份与租户');
      await page.getByRole('menuitem', { name: '平台角色', exact: true }).click();
      await expect(page.getByRole('heading', { name: '平台角色', exact: true })).toBeVisible();
      const tabs = page.locator('[data-tab-item]');
      await expect(tabs.filter({ hasText: '平台人员' })).toHaveCount(1);
      await expect(tabs.filter({ hasText: '平台角色' })).toHaveCount(1);
      report.cases.push('普通页面导航保留同一控制端标签页及父级面包屑');
      await page.getByRole('link', { name: publicSite.name, exact: true }).click();
      await expect(page).toHaveURL(/#\/admin\/profile$/);
      await page.goBack(); await expect(page).toHaveURL(/#\/admin\/roles$/);
      await page.goForward(); await expect(page).toHaveURL(/#\/admin\/profile$/);
      await page.goto(`${url}/#/admin/unknown`); await expect(page).toHaveURL(/#\/admin\/profile$/);
      report.cases.push('品牌首页、前进后退、刷新及未知路径保持控制端');
      const customer = await api('POST', '/customer/auth/login', { login: 'same-login', password: customerPassword });
      await page.evaluate(({ token, realm }) => { sessionStorage.setItem(`typeapp.${realm}.token`, token); }, { token: customer.accessToken, realm: 'customer' });
      // hash 导航保持同一 JavaScript 应用，能观察切换域时旧标签和偏好的清理。
      await page.evaluate(() => { location.hash = '#/profile'; });
      await expect(page.locator('.current-tenant')).toContainText('客户端');
      await expect(tabs.filter({ hasText: '平台人员' })).toHaveCount(0);
      await expect(tabs.filter({ hasText: '平台角色' })).toHaveCount(0);
      await page.getByText('组织与成员', { exact: true }).click();
      await page.getByRole('menuitem', { name: '我的租户', exact: true }).click();
      await expect(page.getByRole('heading', { name: '我的租户', exact: true })).toBeVisible();
      await page.getByRole('link', { name: publicSite.name, exact: true }).click(); await expect(page).toHaveURL(/#\/profile$/);
      await expect(page.getByRole('navigation', { name: 'breadcrumb' })).not.toContainText('平台');
      report.cases.push('同一应用切换客户端清除旧域标签，品牌与菜单使用客户端路径');
      await page.evaluate(() => { location.hash = '#/admin/profile'; });
      await expect(page.locator('.current-tenant')).toHaveText('控制端');
      await expect(tabs.filter({ hasText: '我的租户' })).toHaveCount(0);
      await page.getByRole('button', { name: '退出登录', exact: true }).click(); await expect(page).toHaveURL(/#\/admin\/login$/);
      await page.evaluate(() => { location.hash = '#/profile'; });
      await expect(page.getByText('客户账号', { exact: true })).toBeVisible();
      report.cases.push('控制端单侧退出保持客户端独立会话');
      expect(report.errors).toEqual([]); report.status = 'passed';
      return;
    }
    let current = await api('GET', '/admin/site');
    await api('PUT', '/admin/site', { version: current.version, changes: { layout_mode: 'sidebar-nav', sidebar_collapsed: false, theme_mode: 'light', logo_url: '' } });
    await page.goto(`${url}/#/admin/site`); await page.reload();
    const name = page.getByLabel('项目名称', { exact: true });
    const save = page.getByRole('button', { name: '保存设置', exact: true });
    await expect(name).toHaveValue(publicSite.name);
    await expect(page.getByRole('link', { name: publicSite.name, exact: true })).toBeVisible();
    report.cases.push('公开站点白名单、真实品牌读取、空 Logo 的 Vben 文字品牌');
    await name.fill('验收物联中心');
    const response = page.waitForResponse(r => r.request().method() === 'PUT' && new URL(r.url()).pathname === '/admin/site');
    await save.click(); expect((await response).status()).toBe(200);
    await expect(page).toHaveTitle('站点设置 · 验收物联中心 · 物联开源分享');
    await expect(page.getByRole('link', { name: '验收物联中心', exact: true })).toBeVisible();
    await page.reload(); await expect(name).toHaveValue('验收物联中心');
    report.cases.push('管理页面保存、标题和品牌即时生效、刷新后持久化');

    await name.fill('保留尚未保存的输入');
    current = await api('GET', '/admin/site');
    await api('PUT', '/admin/site', { version: current.version, changes: { name: '另一管理员保存的名称' } });
    const conflict = page.waitForResponse(r => r.request().method() === 'PUT' && new URL(r.url()).pathname === '/admin/site');
    await save.click(); expect((await conflict).status()).toBe(409);
    await expect(page.getByRole('alert')).toContainText('当前输入已保留');
    await expect(name).toHaveValue('保留尚未保存的输入'); await expect(save).toBeDisabled();
    await page.getByRole('button', { name: /^刷\s*新$/ }).click();
    await expect(name).toHaveValue('另一管理员保存的名称'); await expect(save).toBeEnabled();
    report.cases.push('真实并发写入冲突保留输入、禁止旧版本重试、显式刷新恢复');

    // 撤权后重新读取必须禁用表单，不能把界面初值提交到已有数据库。
    const database = new DatabaseSync(resolve(base, 'identity.sqlite'));
    database.exec('PRAGMA busy_timeout = 3000');
    const bindings = database.prepare("SELECT role_id FROM admin_role_permissions WHERE permission = 'admin.site.manage'").all();
    try {
      database.exec("DELETE FROM admin_role_permissions WHERE permission = 'admin.site.manage'");
      await page.reload(); await expect(name).toBeDisabled(); await expect(save).toHaveCount(0);
      current = await api('GET', '/admin/site');
      await api('PUT', '/admin/site', { version: current.version, changes: { name: '越权修改' } }, 403);
      report.cases.push('缺少站点管理权限时只读、隐藏保存入口、服务端拒绝越权写入');
    } finally {
      for (const binding of bindings) database.prepare('INSERT INTO admin_role_permissions (role_id, permission) VALUES (?, ?)').run(binding.role_id, 'admin.site.manage');
      database.close();
    }
    await page.reload(); await expect(name).toBeEnabled();

    for (const [label, viewport, dark] of [['site-settings-light', { width: 1440, height: 1000 }, false], ['site-settings-dark-narrow', { width: 390, height: 844 }, true]]) {
      await page.setViewportSize(viewport);
      if (dark) await page.getByRole('button', { name: 'dark', exact: true }).click();
      if (dark) await expect(page.locator('html')).toHaveClass(/dark/);
      await expect(page.locator('.ant-spin-spinning')).toHaveCount(0);
      await expect.poll(() => page.evaluate(() => document.getAnimations().filter(animation =>
        animation.playState === 'running' && Number.isFinite(animation.effect?.getComputedTiming().endTime)).length)).toBe(0);
      await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
      await page.screenshot({ path: resolve(base, `${label}.png`), fullPage: true }); report.screenshots.push(`${label}.png`);
    }
    report.cases.push('桌面浅色与390px窄屏暗色表单可操作、页面无横向溢出');
    const customer = await api('POST', '/customer/auth/login', { login: 'same-login', password: customerPassword });
    await api('GET', '/admin/site', undefined, 401, customer.accessToken);
    const anonymous = await browser.newContext();
    try {
      const login = await anonymous.newPage(); await login.goto(`${url}/#/login`);
      await expect(login.getByRole('heading', { name: '另一管理员保存的名称', exact: true })).toBeVisible();
      await expect(login).toHaveTitle('用户登录 · 另一管理员保存的名称 · 物联开源分享');
    } finally { await anonymous.close(); }
    report.cases.push('客户令牌不能读取平台设置、新浏览器登录页读取最新品牌');
    expect(report.errors).toEqual([]); report.status = 'passed';
  } catch (error) {
    report.status = 'failed'; report.failure = safe(error);
    if (page) await page.screenshot({ path: resolve(base, 'site-browser-failure.png'), fullPage: true }).catch(() => {});
    throw error;
  } finally {
    clearTimeout(deadline); process.off('SIGTERM', interrupt); process.off('SIGINT', interrupt);
    await interruptClose;
    if (context) await context.close(); if (browser) await browser.close(); await preview.close();
    report.cleanup = { context: true, browser: true, preview: true };
    writeFileSync(resolve(base, 'browser-report.json'), `${JSON.stringify(report, null, 2)}\n`);
  }
}
