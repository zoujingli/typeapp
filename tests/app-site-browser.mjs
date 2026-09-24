import { chromium, expect } from '../web/node_modules/@playwright/test/index.mjs';
import { writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { DatabaseSync } from 'node:sqlite';
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
    expect(Object.keys(publicSite).sort()).toEqual(['name', 'official_url', 'description', 'logo_url', 'timezone', 'theme', 'preferences'].sort());
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
