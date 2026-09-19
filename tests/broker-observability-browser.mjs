import { chromium, expect } from '../web/node_modules/@playwright/test/index.mjs';
import { createReadStream, readFileSync, realpathSync, renameSync, statSync, writeFileSync } from 'node:fs';
import { createServer, request as upstreamRequest } from 'node:http';
import { dirname, extname, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { setTimeout as delay } from 'node:timers/promises';

// PHP持有真实应用、MQTT节点和数据库；本进程仅持有专用静态预览与浏览器。
// 用法：node tests/broker-observability-browser.mjs <base> <dist> <backend-origin>
// PHP写入browser-stage.json {stage}，浏览器原子回写对应阶段确认。
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const [baseArgument, distArgument, originArgument, ...extra] = process.argv.slice(2);
if (!baseArgument || !distArgument || !originArgument || extra.length) throw new Error('用法：node tests/broker-observability-browser.mjs <base> <dist> <backend-origin>');
const base = realpathSync(resolve(root, baseArgument));
const dist = realpathSync(resolve(root, distArgument));
const build = realpathSync(resolve(root, 'build'));
const upstream = new URL(originArgument);
if (!base.startsWith(`${build}${sep}`) || !statSync(base).isDirectory() || !statSync(resolve(dist, 'index.html')).isFile()
  || upstream.protocol !== 'http:' || !['127.0.0.1', '[::1]'].includes(upstream.hostname)
  || upstream.username || upstream.password || upstream.pathname !== '/' || upstream.search || upstream.hash) {
  throw new Error('浏览器验收需要build下本轮目录、真实dist及无凭据的回环HTTP后端');
}
const password = process.env.BROKER_BROWSER_PASSWORD;
if (!password || password.length < 12 || password.length > 72) throw new Error('BROKER_BROWSER_PASSWORD必须提供本轮管理员口令');
const output = base;
const stages = ['healthy', 'slow_consumer', 'standby_unavailable', 'isolated', 'recovered', 'denied'];
const report = { status: 'running', browser: null, cases: [], errors: [], screenshots: [], observations: {},
  fixture: relative(root, base), dist: relative(root, dist), cleanup: { browser: false, context: false, preview: false } };
const safeError = error => String(error?.stack ?? error).replaceAll(password, '<REDACTED>').replaceAll(root, '.').slice(0, 12000);
const writeJson = (path, value) => {
  const temporary = `${path}.${process.pid}.tmp`;
  writeFileSync(temporary, `${JSON.stringify(value, null, 2)}\n`, { mode: 0o600 });
  renameSync(temporary, path);
};
const ack = (phase, status, error) => writeJson(resolve(base, 'browser-ack.json'), {
  stage: phase.stage, ...(phase.seq === undefined ? {} : { seq: phase.seq }), status, ...(error ? { error: safeError(error) } : {}),
});
const sockets = new Set();
const forwarding = new Set();
const mime = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.mjs': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.ico': 'image/x-icon',
  '.woff': 'font/woff', '.woff2': 'font/woff2', '.ttf': 'font/ttf', '.webp': 'image/webp' };
const preview = createServer((incoming, outgoing) => {
  let path;
  try { path = decodeURIComponent(new URL(incoming.url, 'http://preview.invalid').pathname); } catch { outgoing.writeHead(400).end(); return; }
  if (path.startsWith('/broker/') || path === '/readyz') {
    const headers = { ...incoming.headers, host: upstream.host };
    for (const key of ['connection', 'proxy-connection', 'x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-for']) delete headers[key];
    const proxy = upstreamRequest(new URL(incoming.url, upstream), { method: incoming.method, headers, agent: false }, response => {
      outgoing.writeHead(response.statusCode, response.headers);
      let bytes = 0;
      response.on('data', chunk => { bytes += chunk.length; if (bytes > 4194304) proxy.destroy(new Error('proxy_response_budget')); });
      response.on('error', () => outgoing.destroy());
      response.pipe(outgoing);
    });
    forwarding.add(proxy);
    proxy.setTimeout(20000, () => proxy.destroy(new Error('proxy_deadline')));
    proxy.on('close', () => forwarding.delete(proxy));
    proxy.on('error', () => { if (!outgoing.headersSent) outgoing.writeHead(502, { 'Content-Type': 'text/plain' }).end('test_proxy_unavailable'); else outgoing.destroy(); });
    incoming.on('aborted', () => proxy.destroy());
    outgoing.on('close', () => { if (!outgoing.writableEnded) proxy.destroy(); });
    incoming.pipe(proxy);
    return;
  }
  if (!['GET', 'HEAD'].includes(incoming.method)) { outgoing.writeHead(405).end(); return; }
  try {
    const file = realpathSync(resolve(dist, `.${path === '/' ? '/index.html' : path}`));
    if (!file.startsWith(`${dist}${sep}`) || !statSync(file).isFile() || statSync(file).size > 67108864) { outgoing.writeHead(404).end(); return; }
    outgoing.writeHead(200, { 'Content-Type': mime[extname(file)] ?? 'application/octet-stream', 'Cache-Control': 'no-store' });
    if (incoming.method === 'HEAD') outgoing.end(); else createReadStream(file).on('error', () => outgoing.destroy()).pipe(outgoing);
  } catch { outgoing.writeHead(404).end(); }
});
preview.maxConnections = 64;
preview.requestTimeout = 25000;
preview.headersTimeout = 10000;
preview.on('connection', socket => { sockets.add(socket); socket.on('close', () => sockets.delete(socket)); });
let browser;
let context;
let page;
let current;
let cancelDelay;
let lastNodesStatus = 0;
const stop = new AbortController();
const onSignal = () => stop.abort(new Error('browser_interrupted'));
process.on('SIGTERM', onSignal);
process.on('SIGINT', onSignal);
const overall = setTimeout(() => stop.abort(new Error('browser_total_deadline')), 600000);
const bounded = async (operation, milliseconds) => {
  let timer;
  let onAbort;
  try {
    return await Promise.race([operation, new Promise((_, reject) => {
      timer = setTimeout(() => reject(new Error('browser_stage_deadline')), milliseconds);
      onAbort = () => reject(stop.signal.reason);
      stop.signal.addEventListener('abort', onAbort, { once: true });
      if (stop.signal.aborted) onAbort();
    })]);
  } finally { clearTimeout(timer); if (onAbort) stop.signal.removeEventListener('abort', onAbort); }
};
const drawer = () => page.locator('.ant-drawer-content-wrapper:visible').last();
const rows = () => page.locator('.ant-table-tbody tr.ant-table-row');
const nodeRequest = request => new URL(request.url()).pathname === '/broker/nodes' && request.method() === 'GET';
const screenshot = async name => {
  await expect(page.locator('.bg-overlay-content')).not.toBeVisible();
  if (await drawer().count()) await expect.poll(async () => { const box = await drawer().boundingBox(); return box ? Math.round(box.x + box.width) : 0; }).toBe(page.viewportSize().width);
  const file = `browser-${name}.png`;
  await page.screenshot({ path: resolve(output, file), animations: 'disabled' });
  report.screenshots.push(file);
};
const waitIdle = async () => { await expect(page.locator('.ant-spin-spinning')).toHaveCount(0); await expect(page.getByLabel('节点标识筛选')).toBeEnabled(); };
// 动作和响应同时登记失败处理，点击或断言失败时不会留下未处理的响应Promise。
const actionResponse = async (predicate, action) => {
  const [response] = await Promise.all([
    page.waitForResponse(predicate, { timeout: 25000 }),
    Promise.resolve().then(action),
  ]);
  return response;
};
const query = async (button = /^刷\s*新$/) => {
  await waitIdle();
  const result = await actionResponse(response => nodeRequest(response.request()), () => page.getByRole('button', { name: button }).click());
  await waitIdle();
  return { status: result.status(), data: await result.json() };
};
const ready = async () => {
  const result = await query();
  expect(result.status).toBe(200); expect(result.data.health.ready).toBe(true); expect(result.data.health.store.state).toBe('available');
  await expect(page.getByText('可靠接收已就绪', { exact: true })).toBeVisible();
  return result.data;
};
const closeDetail = async () => {
  if (await drawer().count()) { await drawer().getByRole('button', { name: 'Close', exact: true }).click(); await expect(drawer()).toHaveCount(0); }
};
const openDetail = async () => {
  await rows().first().getByRole('button', { name: /^详\s*情$/ }).click();
  await expect(page.getByText('节点观察详情', { exact: true })).toBeVisible();
  await expect(drawer().getByRole('button', { name: /保存|确认|取消/ })).toHaveCount(0);
};
const detailValue = async label => page.getByText(label, { exact: true }).last().evaluate(element => {
  const cell = element.closest('th,td');
  const adjacent = cell?.querySelector('.ant-descriptions-item-content');
  if (adjacent) return adjacent.textContent.trim();
  const row = cell?.parentElement?.nextElementSibling;
  return row?.children[cell.cellIndex]?.textContent?.trim() ?? '';
});
const dark = async enabled => {
  if (await page.locator('html').evaluate(element => element.classList.contains('dark')) !== enabled) await page.getByRole('button', { name: enabled ? 'dark' : 'light', exact: true }).click();
  if (enabled) await expect(page.locator('html')).toHaveClass(/dark/); else await expect(page.locator('html')).not.toHaveClass(/dark/);
};
const stage = async name => {
  if (name === 'healthy') {
    await page.goto(`${report.preview}/#/broker/login`);
    await page.getByLabel('登录账号', { exact: true }).fill('broker-admin');
    await page.getByLabel('登录密码', { exact: true }).fill(password);
    const login = page.waitForResponse(response => new URL(response.url()).pathname === '/broker/auth/login' && response.request().method() === 'POST');
    await page.getByRole('button', { name: /^登\s*录$/ }).click();
    expect((await login).status()).toBe(200);
    await page.waitForURL('**/#/broker/nodes');
    const initial = await ready();
    expect(initial.items.some(item => item.state === 'reporting')).toBe(true);
    report.observations.healthy = { nodes: initial.total, observed_at: initial.health.observed_at };
    await page.getByLabel('节点标识筛选').fill('browser-empty-node');
    const empty = await query(/^查\s*询$/); expect(empty.data.items).toEqual([]); await expect(rows()).toHaveCount(0); await screenshot('healthy-empty');
    const reset = await query(/^重\s*置$/); expect(reset.data.total).toBe(initial.total); await expect(page.getByLabel('节点标识筛选')).toHaveValue('');
    await openDetail(); await expect(drawer()).toContainText('详情保留打开时的采样');
    await screenshot('healthy-detail');
    await page.locator('.ant-drawer-mask:visible').click({ position: { x: 4, y: 4 } }); await expect(drawer()).toHaveCount(0);
    await dark(true); await screenshot('healthy-dark'); await dark(false);
    await page.getByRole('button', { name: '主题色', exact: true }).click();
    await page.getByLabel('主题色', { exact: true }).first().click(); await page.getByText('紫罗兰', { exact: true }).last().click();
    await page.getByRole('heading', { name: 'Broker 节点', exact: true }).click();
    await page.setViewportSize({ width: 390, height: 844 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBe(390);
    await expect(page.getByRole('button', { name: '退出登录', exact: true })).toBeInViewport();
    await screenshot('healthy-mobile-light-purple'); await openDetail();
    await expect.poll(() => drawer().locator('.ant-drawer-body').evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1);
    await screenshot('healthy-mobile-detail'); await closeDetail(); await page.setViewportSize({ width: 1440, height: 1000 });
    report.cases.push('real-login-ready-filter-empty-reset-readonly-mask-close-dark-light-purple-mobile');
  } else if (name === 'slow_consumer') {
    const data = await ready();
    expect(data.health.store.metrics.pendingMessages).toBe(12);
    expect(data.items[0].metrics.outgoingExchanges).toBe(2);
    await expect(page.getByText(/持久占用 12 条/)).toBeVisible();
    await expect(page.getByText(/原件与交付副本合计/)).toBeVisible();
    await openDetail(); expect(await detailValue('出站在途交换')).toBe('2');
    report.observations.slow_consumer = { pendingMessages: 12, pendingBytes: data.health.store.metrics.pendingBytes, outgoingExchanges: 2, observed_at: data.health.observed_at };
    await screenshot('slow-consumer'); await closeDetail(); report.cases.push('real-persistent-original-and-delivery-capacity-two-inflight');
  } else if (name === 'standby_unavailable') {
    const { status, data } = await query();
    expect(status).toBe(200); expect(data.health.ready).toBe(false); expect(data.health.store.state).not.toBe('available'); expect(data.health.store.metrics).toBeNull();
    await expect(page.getByText('可靠接收已就绪', { exact: true })).toHaveCount(0); await expect(page.getByText(/持久占用/)).toHaveCount(0);
    await expect(page.getByText(/存储：/)).toBeVisible();
    report.observations.standby_unavailable = { state: data.health.state, store: data.health.store.state, observed_at: data.health.observed_at };
    await screenshot('standby-unavailable'); report.cases.push('real-standby-failure-not-ready-no-stale-storage-values');
  } else if (name === 'isolated') {
    const { status, data } = await query();
    expect(status).toBe(200); expect(data.health.ready).toBe(false); expect(data.items.some(item => item.state === 'isolated')).toBe(true);
    expect(data.items.some(item => item.state === 'reporting')).toBe(false);
    await expect(page.getByText('已登记隔离', { exact: true }).first()).toBeVisible();
    await screenshot('isolated'); report.cases.push('real-fenced-observation-isolated-without-active-node');
  } else if (name === 'recovered') {
    await ready(); await screenshot('recovered');
    let started = 0;
    let complete;
    const hold = new Promise(resolve => { complete = resolve; });
    cancelDelay = complete;
    const route = async route => { started++; await hold; await route.continue(); };
    await page.route(request => new URL(request).pathname === '/broker/nodes', route);
    const response = await actionResponse(response => nodeRequest(response.request()), async () => {
      await page.getByRole('button', { name: /^刷\s*新$/ }).click(); await expect.poll(() => started).toBe(1);
      await expect(page.getByLabel('节点标识筛选')).toBeDisabled(); await expect(page.getByRole('button', { name: /^重\s*置$/ })).toBeDisabled();
      await delay(6200, undefined, { signal: stop.signal }); expect(started).toBe(1);
      await screenshot('slow-response'); complete(); cancelDelay = null;
    });
    expect(response.status()).toBe(200);
    await page.unrouteAll({ behavior: 'wait' }); await waitIdle();
    const fresh = await ready();
    await context.setOffline(true);
    await page.getByRole('button', { name: /^刷\s*新$/ }).click();
    await expect(page.getByRole('alert').filter({ hasText: '页面刷新失败' })).toContainText('网络连接失败');
    await expect(page.getByText('就绪观察已过期，当前结果未知', { exact: true })).toBeVisible({ timeout: 22000 });
    await expect(page.getByText(/存储：未知/)).toBeVisible(); await expect(page.getByText(/历史观察/).first()).toBeVisible();
    await screenshot('offline-expired'); await context.setOffline(false);
    const restored = await ready(); expect(restored.health.observed_at).toBeGreaterThan(fresh.health.observed_at);
    await expect(page.getByRole('alert').filter({ hasText: '页面刷新失败' })).toHaveCount(0);
    await screenshot('offline-recovered');
    let hiddenRequests = 0;
    const countHidden = request => { if (nodeRequest(request)) hiddenRequests++; };
    page.on('request', countHidden);
    // 触发浏览器可见性事件验证公共生命周期，不冒充操作系统实际后台切换。
    await page.evaluate(() => { Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' }); document.dispatchEvent(new Event('visibilitychange')); });
    try { await delay(5500, undefined, { signal: stop.signal }); expect(hiddenRequests).toBe(0); }
    finally {
      page.off('request', countHidden);
      await page.evaluate(() => { delete document.visibilityState; document.dispatchEvent(new Event('visibilitychange')); });
    }
    await ready(); await openDetail();
    report.observations.recovered = { delayed_requests: started, hold_milliseconds: 6200, before: fresh.health.observed_at, after: restored.health.observed_at };
    report.cases.push('real-restart-ready-slow-response-no-overlap-offline-15s-expiry-unknown-recovery');
    report.cases.push('simulated-visibility-event-stops-hidden-polling-and-resumes');
  } else if (name === 'denied') {
    await expect.poll(() => lastNodesStatus, { timeout: 20000 }).toBe(403);
    await expect(page.getByRole('alert')).toContainText('当前账号没有此操作权限');
    await expect(rows()).toHaveCount(0); await expect(drawer()).toHaveCount(0);
    await expect(page.getByText('可靠接收已就绪', { exact: true })).toHaveCount(0);
    await expect(page.getByText(/持久占用/)).toHaveCount(0);
    await screenshot('denied'); report.cases.push('real-role-revocation-403-clears-nodes-health-and-open-detail');
    const logout = await actionResponse(response => new URL(response.url()).pathname === '/broker/auth/logout' && response.request().method() === 'POST',
      () => page.getByRole('button', { name: '退出登录', exact: true }).click());
    expect(logout.status()).toBe(200); await page.waitForURL('**/#/broker/login');
    report.cases.push('revoked-administrator-can-still-revoke-own-login-session');
  }
};
try {
  await bounded(new Promise((resolve, reject) => { preview.once('error', reject); preview.listen(0, '127.0.0.1', resolve); }), 10000);
  report.preview = `http://127.0.0.1:${preview.address().port}`;
  browser = await chromium.launch({ channel: 'msedge', headless: true });
  report.browser = browser.version();
  context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, timezoneId: 'Asia/Shanghai' });
  page = await context.newPage(); page.setDefaultTimeout(10000); page.setDefaultNavigationTimeout(25000);
  page.on('pageerror', error => report.errors.push(safeError(error)));
  page.on('response', response => { if (nodeRequest(response.request())) lastNodesStatus = response.status(); });
  let previousSequence = -1;
  for (const expected of stages) {
    current = { stage: expected };
    current = await bounded((async () => {
      for (;;) {
        let candidate;
        try { candidate = JSON.parse(readFileSync(resolve(base, 'browser-stage.json'), 'utf8')); }
        catch (error) { if (error.code !== 'ENOENT' && !(error instanceof SyntaxError)) throw error; }
        if (candidate?.stage === expected) {
          if (candidate.seq !== undefined && (!Number.isSafeInteger(candidate.seq) || candidate.seq <= previousSequence)) throw new Error('browser_stage_sequence_invalid');
          return candidate;
        }
        if (candidate && !stages.includes(candidate.stage)) throw new Error('browser_stage_unknown');
        await delay(100, undefined, { signal: stop.signal });
      }
    })(), 90000);
    const began = Date.now();
    await bounded(stage(expected), 80000);
    if (report.errors.length) throw new Error('browser_page_error');
    report.observations[`${expected}_duration_ms`] = Date.now() - began;
    if (current.seq !== undefined) previousSequence = current.seq;
    writeJson(resolve(output, 'browser-report.json'), report); ack(current, 'passed');
  }
  report.status = 'passed';
} catch (error) {
  report.status = 'failed'; report.failure = safeError(error);
  if (page) { try { await page.screenshot({ path: resolve(output, 'browser-failure.png'), animations: 'disabled', timeout: 5000 }); report.screenshots.push('browser-failure.png'); } catch {} }
  ack(current ?? { stage: 'startup' }, 'failed', error);
  process.exitCode = 1;
} finally {
  stop.abort(new Error('browser_finished'));
  cancelDelay?.(); clearTimeout(overall);
  process.off('SIGTERM', onSignal); process.off('SIGINT', onSignal);
  try { await context?.close(); report.cleanup.context = true; } catch (error) { report.errors.push(safeError(error)); }
  try { await browser?.close(); report.cleanup.browser = true; } catch (error) { report.errors.push(safeError(error)); }
  for (const proxy of forwarding) proxy.destroy();
  for (const socket of sockets) socket.destroy();
  await new Promise(resolve => preview.close(resolve)); report.cleanup.preview = !preview.listening;
  if (report.errors.length || !Object.values(report.cleanup).every(Boolean)) { report.status = 'failed'; process.exitCode = 1; }
  writeJson(resolve(output, 'browser-report.json'), report);
  if (report.status !== 'passed') ack(current ?? { stage: 'startup' }, 'failed', report.failure ?? 'browser_cleanup_failed');
  console.log(JSON.stringify({ status: report.status, report: relative(root, resolve(output, 'browser-report.json')), cleanup: report.cleanup }));
}
