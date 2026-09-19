import { chromium, expect } from '../web/node_modules/@playwright/test/index.mjs';
import { randomBytes } from 'node:crypto';
import { createReadStream, readFileSync, realpathSync, statSync, writeFileSync } from 'node:fs';
import { createServer, request as upstreamRequest } from 'node:http';
import { dirname, extname, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { setTimeout as delay } from 'node:timers/promises';
import { DatabaseSync } from 'node:sqlite';

/** 两类验收共用有界静态预览；双应用时按公开路径分发到各自真实HTTP宿主。 */
export function brokerPreview(dist, iotUpstream, brokerUpstream = iotUpstream) {
  const sockets = new Set();
  const forwarding = new Set();
  const mime = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.mjs': 'text/javascript; charset=utf-8',
    '.css': 'text/css; charset=utf-8', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.ico': 'image/x-icon',
    '.woff': 'font/woff', '.woff2': 'font/woff2', '.ttf': 'font/ttf', '.webp': 'image/webp' };
  const server = createServer((incoming, outgoing) => {
    let path;
    try { path = decodeURIComponent(new URL(incoming.url, 'http://preview.invalid').pathname); } catch { outgoing.writeHead(400).end(); return; }
    if (['/iot/', '/admin/', '/customer/', '/broker/'].some(prefix => path.startsWith(prefix)) || path === '/readyz') {
      const upstream = path.startsWith('/broker/') ? brokerUpstream : iotUpstream;
      const headers = { ...incoming.headers, host: upstream.host };
      for (const key of ['connection', 'proxy-connection', 'x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-for']) delete headers[key];
      const proxy = upstreamRequest(new URL(incoming.url, upstream), { method: incoming.method, headers, agent: false }, response => {
        outgoing.writeHead(response.statusCode, response.headers);
        let bytes = 0;
        response.on('data', chunk => { bytes += chunk.length; if (bytes > 4194304) proxy.destroy(new Error('proxy_response_budget')); });
        response.on('error', () => outgoing.destroy()); response.pipe(outgoing);
      });
      forwarding.add(proxy); proxy.setTimeout(20000, () => proxy.destroy(new Error('proxy_deadline')));
      proxy.on('close', () => forwarding.delete(proxy));
      proxy.on('error', () => { if (!outgoing.headersSent) outgoing.writeHead(502).end('test_proxy_unavailable'); else outgoing.destroy(); });
      incoming.on('aborted', () => proxy.destroy()); outgoing.on('close', () => { if (!outgoing.writableEnded) proxy.destroy(); });
      incoming.pipe(proxy); return;
    }
    if (!['GET', 'HEAD'].includes(incoming.method)) { outgoing.writeHead(405).end(); return; }
    try {
      const file = realpathSync(resolve(dist, `.${path === '/' ? '/index.html' : path}`));
      if (!file.startsWith(`${dist}${sep}`) || !statSync(file).isFile() || statSync(file).size > 67108864) { outgoing.writeHead(404).end(); return; }
      outgoing.writeHead(200, { 'Content-Type': mime[extname(file)] ?? 'application/octet-stream', 'Cache-Control': 'no-store' });
      if (incoming.method === 'HEAD') outgoing.end(); else createReadStream(file).on('error', () => outgoing.destroy()).pipe(outgoing);
    } catch { outgoing.writeHead(404).end(); }
  });
  server.maxConnections = 64; server.requestTimeout = 25000; server.headersTimeout = 10000;
  server.on('connection', socket => { sockets.add(socket); socket.on('close', () => sockets.delete(socket)); });
  return { server, close: async () => {
    for (const proxy of forwarding) proxy.destroy(); for (const socket of sockets) socket.destroy();
    await new Promise(resolve => server.close(resolve));
    if (server.listening) throw new Error('test_preview_cleanup_failed');
  } };
}

/** 调用者持有真实数据库、HTTP与TLS客户端；本函数拥有静态预览、浏览器和本轮模拟会话，临时撤权最终恢复。 */
export async function brokerResourceBrowser({ origin, dist: distArgument, base: baseArgument, fixture }) {
  const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
  const base = realpathSync(resolve(root, baseArgument));
  const dist = realpathSync(resolve(root, distArgument));
  const build = realpathSync(resolve(root, 'build'));
  const upstream = new URL(origin);
  const password = process.env.TYPE_BROKER_RESOURCE_PASSWORD;
  if (!base.startsWith(`${build}${sep}`) || !statSync(base).isDirectory() || !statSync(resolve(dist, 'index.html')).isFile()
    || upstream.protocol !== 'http:' || !['127.0.0.1', '[::1]'].includes(upstream.hostname)
    || upstream.username || upstream.password || upstream.pathname !== '/' || upstream.search || upstream.hash
    || !password || password.length < 12 || password.length > 72) throw new Error('resource_browser_fixture_invalid');
  const { tenant_b: otherTenant, tokens, browser: browserFixture } = fixture.broker_resources;
  let readerRole = fixture.broker_resources.reader_role;
  const tenant = fixture.tenant;
  const tenantPath = `/customer/tenants/${tenant}/broker/resources`;
  const secrets = [password, ...Object.values(tokens), fixture.registration.credential.password, fixture.second.credential.password].filter(Boolean);
  const safeError = error => secrets.reduce((text, secret) => text.replaceAll(secret, '<REDACTED>'), String(error?.stack ?? error)).replaceAll(root, '.').slice(0, 12000);
  const report = { status: 'running', cases: [], errors: [], screenshots: [], observations: {}, requests: [], current: null,
    fixture: relative(root, base), dist: relative(root, dist), cleanup: { context: false, browser: false, preview: false, role: true, simulation: true } };
  // 沿用节点浏览器验收的有界预览代理，所有业务请求都发往调用者的真实HTTP服务。
  const previewOwner = brokerPreview(dist, upstream);
  const preview = previewOwner.server;
  let browser; let context; let page; let simulatedToken; let cancelDelay; let failed;
  const stop = new AbortController();
  const onSignal = () => stop.abort(new Error('resource_browser_interrupted'));
  const overall = setTimeout(() => stop.abort(new Error('resource_browser_deadline')), 300000);
  process.on('SIGTERM', onSignal); process.on('SIGINT', onSignal);
  let resource = 'connections';
  let prefix = tenantPath;
  const rows = () => page.locator('.ant-table-tbody tr.ant-table-row');
  const drawer = () => page.locator('.ant-drawer-content-wrapper:visible').last();
  const resourceControl = () => page.locator('.search-card .crud-search-field').filter({ has: page.getByText('资源类型', { exact: true }) }).locator('.ant-select');
  const resourceRequest = request => request.method() === 'GET' && new URL(request.url()).pathname === `${prefix}/${resource}`;
  const observedRequest = (request, event, status) => {
    const url = new URL(request.url());
    const path = url.pathname;
    const match = path.match(/\/broker\/resources\/(nodes|connections|sessions|subscriptions|retained|backlog)(\/[^/]+)?$/);
    if (request.method() !== 'GET' || !match) return;
    report.requests.push({ event, resource: match[1], detail: !!match[2], has_cursor: url.searchParams.has('cursor'), query_keys: [...url.searchParams.keys()].sort(), ...(status ? { status } : {}) });
    if (report.requests.length > 24) report.requests.shift();
  };
  // 同时登记动作和响应的拒绝处理；点击超时不能让尚未await的响应Promise终止整个装置。
  const actionResponse = async (predicate, action) => {
    const [response] = await Promise.all([
      page.waitForResponse(predicate, { timeout: 25000 }),
      Promise.resolve().then(action),
    ]);
    return response;
  };
  const bounded = async operation => {
    let onAbort;
    try { return await Promise.race([operation, new Promise((_, reject) => {
      onAbort = () => reject(stop.signal.reason); stop.signal.addEventListener('abort', onAbort, { once: true }); if (stop.signal.aborted) onAbort();
    })]); } finally { if (onAbort) stop.signal.removeEventListener('abort', onAbort); }
  };
  const waitIdle = async () => { await expect(page.locator('.table-toolbar .ant-btn-loading')).toHaveCount(0); await expect(page.locator('.ant-spin-spinning')).toHaveCount(0); await expect(page.getByLabel('资源类型', { exact: true })).toBeEnabled(); };
  const noSecrets = data => {
    const encoded = JSON.stringify(data);
    for (const secret of [...secrets, '"payload"', '"password"', '"properties"', 'secret_hash', 'private_key']) expect(encoded.includes(secret)).toBe(false);
  };
  // 调用者声明目标参数；默认第一页20条无筛选，避免同路径的旧轮询抢先满足查询。
  const query = async (button = /^刷\s*新$/, target = {}) => {
    const expected = new URLSearchParams({ limit: '20', ...target.params });
    if (target.cursor) expected.set('cursor', target.cursor);
    expected.sort();
    report.current = { action: 'query', resource, button: String(button), page: target.page ?? 1, query_keys: [...expected.keys()] };
    await waitIdle();
    const result = await actionResponse(response => {
      const actual = new URL(response.url()).searchParams; actual.sort();
      return resourceRequest(response.request()) && actual.toString() === expected.toString();
    },
      () => page.getByRole('button', { name: button, exact: true }).click());
    expect(result.status()).toBe(200);
    const data = await result.json(); noSecrets(data); await waitIdle();
    await expect(rows()).toHaveCount(data.items.length);
    await expect(page.locator('.resource-pagination')).toContainText(`第 ${target.page ?? 1} 页`);
    return data;
  };
  const choose = async (value, label) => {
    report.current = { action: 'choose', previous: resource, resource: value, label };
    await waitIdle();
    if (resource !== value) {
      const previous = resource;
      resource = value;
      const result = await actionResponse(response => resourceRequest(response.request()), async () => {
        if (value === 'sessions') {
          await actionResponse(response => response.request().method() === 'GET' && new URL(response.url()).pathname === `${prefix}/${previous}`,
            () => resourceControl().locator('.ant-select-selector').click());
          await expect(page.getByLabel('资源类型', { exact: true })).toBeEnabled();
          await expect(page.locator('.ant-select-dropdown:visible .ant-select-item-option').filter({ has: page.getByText(label, { exact: true }) })).toBeVisible();
          report.cases.push('real-background-refresh-keeps-resource-menu-open');
        } else await resourceControl().locator('.ant-select-selector').click();
        await page.locator('.ant-select-dropdown:visible .ant-select-item-option').filter({ has: page.getByText(label, { exact: true }) }).click();
        await expect(resourceControl().locator('.ant-select-selection-item')).toHaveText(label);
      });
      expect(result.status()).toBe(200); await waitIdle();
    }
    return query();
  };
  const closeDetail = async () => {
    if (await drawer().count()) { await page.locator('.ant-drawer-mask:visible').click({ position: { x: 4, y: 4 } }); await expect(drawer()).toHaveCount(0); }
  };
  const openDetail = async (row = rows().first()) => {
    report.current = { action: 'detail', resource };
    await waitIdle();
    const result = await actionResponse(response => response.request().method() === 'GET' && new URL(response.url()).pathname.startsWith(`${prefix}/${resource}/`),
      () => row.getByRole('button', { name: /^详\s*情$/ }).click());
    expect(result.status()).toBe(200); const data = await result.json(); noSecrets(data); expect(data.found).toBe(true);
    await expect(drawer()).toContainText('详情保留打开时的采样');
    await expect(drawer().getByRole('button', { name: /保存|确认|取消/ })).toHaveCount(0); return data;
  };
  const screenshot = async name => {
    await expect(page.locator('.bg-overlay-content')).not.toBeVisible();
    if (await drawer().count()) await expect.poll(async () => { const box = await drawer().boundingBox(); return box ? Math.round(box.x + box.width) : 0; }).toBe(page.viewportSize().width);
    const file = `browser-resources-${name}.png`; await page.screenshot({ path: resolve(base, file), animations: 'disabled' }); report.screenshots.push(file);
  };
  const navigate = async path => { await page.evaluate(path => { location.hash = path; }, path); await page.waitForURL(`**/#${path}`); };
  const login = async (account, realm = 'customer') => {
    report.current = { action: 'login', account };
    if (await page.getByRole('button', { name: '退出登录', exact: true }).count()) {
      await page.getByRole('button', { name: '退出登录', exact: true }).click(); await page.waitForURL(/#\/(?:admin\/)?login$/);
    }
    await page.goto(`${report.preview}/#${realm === 'admin' ? '/admin/login' : '/login'}`);
    await page.getByLabel('登录账号', { exact: true }).fill(account); await page.getByLabel('登录密码', { exact: true }).fill(password);
    const response = await actionResponse(response => new URL(response.url()).pathname === `/${realm}/auth/login` && response.request().method() === 'POST',
      () => page.getByRole('button', { name: /^登\s*录$/ }).click());
    expect(response.status()).toBe(200); await page.waitForURL(`**/#${realm === 'admin' ? '/admin/profile' : '/profile'}`);
  };
  const enterTenant = async (id, name) => {
    await navigate('/tenants');
    await rows().filter({ hasText: id }).getByRole('button', { name: '进入租户', exact: true }).click();
    await page.waitForURL('**/#/profile'); await expect(page.locator('.current-tenant')).toContainText(name);
    prefix = `/customer/tenants/${id}/broker/resources`; resource = 'connections';
    await navigate('/broker-resources'); await expect(page.getByRole('heading', { name: 'Broker 资源', exact: true })).toBeVisible(); return query();
  };
  const browserApi = async (path, scope) => page.evaluate(async ({ path, scope }) => {
    const realm = location.hash.startsWith('#/admin/') ? 'admin' : 'customer';
    const token = realm === 'customer' ? sessionStorage.getItem('typeapp.impersonation.token') || sessionStorage.getItem('typeapp.customer.token') : sessionStorage.getItem('typeapp.admin.token');
    const response = await fetch(path, { headers: { Authorization: `Bearer ${token}`,
      ...(scope ? { 'X-Tenant-Id': scope } : {}) }, signal: AbortSignal.timeout(15000) });
    return { status: response.status, body: await response.json() };
  }, { path, scope });
  const changeReader = async enabled => {
    const response = await fetch(`${origin}/customer/roles/${readerRole.id}/status`, {
      method: 'POST', headers: { Authorization: `Bearer ${tokens.bob}`, 'X-Tenant-Id': tenant, 'Content-Type': 'application/json' },
      body: JSON.stringify({ version: readerRole.version, enabled }), signal: AbortSignal.timeout(15000) });
    expect(response.status).toBe(200); readerRole = (await response.json()).data; report.cleanup.role = enabled;
  };
  try {
    await bounded(new Promise((resolve, reject) => { preview.once('error', reject); preview.listen(0, '127.0.0.1', resolve); }));
    report.preview = `http://127.0.0.1:${preview.address().port}`;
    browser = await chromium.launch({ channel: 'msedge', headless: true }); report.browser = browser.version();
    context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, timezoneId: 'Asia/Shanghai' });
    page = await context.newPage(); page.setDefaultTimeout(10000); page.setDefaultNavigationTimeout(25000);
    page.on('pageerror', error => report.errors.push(safeError(error)));
    page.on('request', request => observedRequest(request, 'request'));
    page.on('requestfailed', request => observedRequest(request, 'failed'));
    page.on('response', response => observedRequest(response.request(), 'response', response.status()));
    await bounded((async () => {
      await login('device-owner'); const first = await enterTenant(tenant, '设备甲'); expect(first.items.length).toBe(2);
      for (const [value, label] of [['nodes', '节点'], ['connections', '连接'], ['sessions', '会话'], ['subscriptions', '订阅'], ['retained', '保留消息'], ['backlog', '积压']]) {
        const data = await choose(value, label); expect(data.items.length).toBeGreaterThan(0); expect(data.total).toBeNull();
        if (['connections', 'sessions', 'subscriptions'].includes(value)) for (const item of data.items) expect(item.resource_scope).toBe(`iot:${tenant}`);
        if (['retained', 'backlog'].includes(value)) for (const item of data.items) expect(item.topic.startsWith(`iot/${tenant}/`)).toBe(true);
        await openDetail(); await closeDetail(); report.observations[value] = { first_page: data.items.length, has_more: data.has_more };
      }
      report.cases.push('real-six-resource-lists-scoped-metadata-and-readonly-mask-close-details');
      await choose('connections', '连接'); await page.getByLabel('客户标识筛选').fill('browser-empty-resource');
      expect((await query(/^查\s*询$/, { params: { client_id: 'browser-empty-resource' } })).items).toEqual([]); await screenshot('empty');
      expect((await query(/^重\s*置$/)).items.length).toBe(2); await expect(page.getByLabel('客户标识筛选')).toHaveValue('');
      await page.getByLabel('客户标识筛选').fill(fixture.second.credential.client_id);
      const filtered = await query(/^查\s*询$/, { params: { client_id: fixture.second.credential.client_id } }); expect(filtered.items.map(item => item.client_id)).toEqual([fixture.second.credential.client_id]);
      await query(/^重\s*置$/); report.cases.push('real-exact-filter-empty-reset');
      const subscriptions = await choose('subscriptions', '订阅'); expect(subscriptions.items.length).toBe(20); expect(subscriptions.has_more).toBe(true);
      const next = await query('下一页', { cursor: subscriptions.next_cursor, page: 2 }); expect(next.items.length).toBeGreaterThan(0); expect(next.has_more).toBe(false);
      expect(next.items.some(item => subscriptions.items.some(previous => previous.id === item.id))).toBe(false);
      await expect(page.getByText('第 2 页', { exact: false })).toBeVisible();
      const previous = await query('上一页', { cursor: null, page: 1 }); expect(previous.items.map(item => item.id)).toEqual(subscriptions.items.map(item => item.id));
      await query('下一页', { cursor: subscriptions.next_cursor, page: 2 });
      let releasePagination;
      const paginationHold = new Promise(resolve => { releasePagination = resolve; }); cancelDelay = releasePagination;
      const pollingPage = url => url.pathname === `${prefix}/${resource}` && url.searchParams.get('cursor') === subscriptions.next_cursor;
      let paginationDone = Promise.resolve(); let paginationFailure;
      const delayPagination = route => {
        paginationDone = (async () => { await paginationHold; if (!stop.signal.aborted) await route.continue(); else await route.abort(); })()
          .catch(error => { paginationFailure = error; });
        return paginationDone;
      };
      await page.route(pollingPage, delayPagination);
      try {
        report.current = { action: 'paginate-during-background-refresh', resource, page: 1 };
        await page.waitForRequest(request => request.method() === 'GET' && pollingPage(new URL(request.url())), { timeout: 10000 });
        const response = await actionResponse(response => resourceRequest(response.request()) && !new URL(response.url()).searchParams.has('cursor'),
          () => page.getByRole('button', { name: '上一页', exact: true }).click());
        expect(response.status()).toBe(200);
        const data = await response.json(); noSecrets(data);
        expect(data.items.map(item => item.id)).toEqual(subscriptions.items.map(item => item.id));
        await expect(page.locator('.resource-pagination')).toContainText('第 1 页');
        await expect(rows()).toHaveCount(20);
      } finally { releasePagination(); await paginationDone; await page.unroute(pollingPage, delayPagination); cancelDelay = undefined; if (paginationFailure) throw paginationFailure; }
      report.cases.push('real-background-refresh-does-not-drop-pagination');
      expect(browserFixture?.long_filter?.length).toBeGreaterThan(200);
      const longPrefix = browserFixture.long_filter.slice(0, 120);
      let longRow = rows().filter({ hasText: longPrefix });
      if (!await longRow.count()) { await query('下一页', { cursor: subscriptions.next_cursor, page: 2 }); longRow = rows().filter({ hasText: longPrefix }); }
      await openDetail(longRow); await expect(drawer()).toContainText(browserFixture.long_filter); await screenshot('long-detail'); await closeDetail();
      report.cases.push('real-default-20-row-stable-next-previous-and-long-shared-filter');
      const dark = async enabled => {
        if (await page.locator('html').evaluate(element => element.classList.contains('dark')) !== enabled) await page.getByRole('button', { name: enabled ? 'dark' : 'light', exact: true }).click();
        if (enabled) await expect(page.locator('html')).toHaveClass(/dark/); else await expect(page.locator('html')).not.toHaveClass(/dark/);
      };
      await dark(true); await screenshot('dark'); await dark(false); await screenshot('light');
      await page.getByRole('button', { name: '主题色', exact: true }).click();
      await page.getByLabel('主题色', { exact: true }).first().click(); await page.getByText('紫罗兰', { exact: true }).last().click();
      await page.getByRole('heading', { name: 'Broker 资源', exact: true }).click();
      await page.setViewportSize({ width: 390, height: 844 }); await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBe(390);
      await expect(page.getByRole('button', { name: '退出登录', exact: true })).toBeInViewport();
      const fields = await page.locator('.search-card .crud-search-field').count();
      expect(fields).toBeGreaterThan(0);
      await expect.poll(() => page.locator('.search-card .crud-search-grid').evaluate(element => getComputedStyle(element).gridTemplateColumns.split(' ').length)).toBe(1);
      await screenshot('mobile-purple'); await openDetail(rows().filter({ hasText: longPrefix }));
      await expect.poll(() => drawer().locator('.ant-drawer-body').evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1);
      await screenshot('mobile-long-detail'); await closeDetail(); await page.setViewportSize({ width: 1440, height: 1000 });
      report.cases.push('real-dark-light-purple-mobile-single-column-long-readonly-detail');
      await choose('connections', '连接');
      let started = 0; let release;
      const hold = new Promise(resolve => { release = resolve; }); cancelDelay = release;
      const route = async route => { started++; await hold; if (!stop.signal.aborted) await route.continue(); else await route.abort(); };
      await page.route(url => url.pathname === `${prefix}/${resource}`, route);
      report.current = { action: 'delayed-refresh', resource };
      const delayed = await actionResponse(response => resourceRequest(response.request()), async () => {
        await page.getByRole('button', { name: /^刷\s*新$/ }).click(); await expect.poll(() => started).toBe(1);
        await expect(page.getByLabel('客户标识筛选')).toBeDisabled(); await expect(page.getByRole('button', { name: /^重\s*置$/ })).toBeDisabled();
        await delay(6200, undefined, { signal: stop.signal }); expect(started).toBe(1); await screenshot('loading');
        release(); cancelDelay = null;
      });
      expect(delayed.status()).toBe(200); await page.unrouteAll({ behavior: 'wait' }); await waitIdle();
      const fresh = await query(); const snapshot = await openDetail();
      await context.setOffline(true);
      await expect(drawer()).toContainText('已过期，当前状态未知', { timeout: 22000 });
      await expect(drawer().getByText('观察已过期，当前未知', { exact: true }).first()).toBeVisible();
      await screenshot('offline-detail'); await closeDetail();
      await expect(page.getByRole('alert').filter({ hasText: '资源刷新失败' })).toContainText('网络连接失败');
      await expect(page.getByText('观察已过期，当前连接和投递状态未知', { exact: true })).toBeVisible();
      await expect(rows().first()).toContainText('观察已过期，当前未知'); await screenshot('offline-expired');
      await context.setOffline(false); const restored = await query(); expect(restored.observed_at).toBeGreaterThan(fresh.observed_at);
      await expect(page.getByRole('alert').filter({ hasText: '资源刷新失败' })).toHaveCount(0);
      report.observations.polling = { delayed_requests: started, delay_ms: 6200, snapshot_at: snapshot.observed_at, recovered_at: restored.observed_at };
      report.cases.push('real-delayed-network-no-overlap-offline-list-and-detail-15s-unknown-recovery');
      let hiddenRequests = 0;
      const countHidden = request => { if (resourceRequest(request)) hiddenRequests++; };
      await page.evaluate(() => { Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' }); document.dispatchEvent(new Event('visibilitychange')); });
      page.on('request', countHidden);
      try { await delay(5500, undefined, { signal: stop.signal }); expect(hiddenRequests).toBe(0); }
      finally { page.off('request', countHidden); await page.evaluate(() => { delete document.visibilityState; document.dispatchEvent(new Event('visibilitychange')); }); }
      await query(); report.cases.push('simulated-visibility-event-stops-polling-and-resumes-real-requests');
      const switched = await enterTenant(otherTenant, '设备乙'); expect(switched.items.length).toBe(1);
      expect(switched.items.every(item => item.resource_scope === `iot:${otherTenant}`)).toBe(true);
      expect(switched.items.some(item => first.items.some(previous => previous.client_id === item.client_id))).toBe(false);
      await expect(page.getByLabel('客户标识筛选')).toHaveValue(''); await screenshot('tenant-b');
      await login('device-reader'); expect((await enterTenant(tenant, '设备甲')).items.length).toBe(2);
      expect((await browserApi(`/customer/tenants/${otherTenant}/broker/resources/connections`, otherTenant)).status).toBe(403);
      expect((await browserApi('/admin/broker/resources/connections')).status).toBe(401);
      await expect(page.getByRole('link', { name: 'Broker 审计', exact: true })).toHaveCount(0);
      expect((await browserApi(`/customer/tenants/${tenant}/broker/audit`, tenant)).status).toBe(403);
      await openDetail(); await changeReader(false);
      await page.waitForURL('**/#/tenants', { timeout: 20000 });
      await expect(drawer()).toHaveCount(0); await screenshot('role-revoked'); await changeReader(true);
      report.cases.push('real-tenant-switch-independent-audit-permission-and-role-revocation-clears-detail');
      await login('device-outsider'); await page.evaluate(() => { location.hash = '/broker-resources'; }); await page.waitForURL('**/#/tenants');
      await expect(page.getByText('Broker 资源', { exact: true })).toHaveCount(0);
      expect((await browserApi(`${tenantPath}/connections`, tenant)).status).toBe(403); await screenshot('no-membership');
      await login('same-login', 'admin'); expect((await browserApi(`${tenantPath}/connections`, tenant)).status).toBe(401);
      prefix = '/admin/broker/resources'; resource = 'connections'; await navigate('/admin/broker');
      const platform = await query(); expect(platform.items.length).toBe(4); await screenshot('platform');
      await navigate('/admin/customers');
      await page.getByLabel('登录账号筛选', { exact: true }).fill('device-owner');
      await actionResponse(response => new URL(response.url()).pathname === '/admin/customers' && new URL(response.url()).searchParams.get('search') === 'device-owner',
        () => page.getByRole('button', { name: /^查\s*询$/ }).click());
      const targetCustomer = rows().filter({ hasText: 'device-owner' });
      const impersonateButton = targetCustomer.getByRole('button', { name: '模拟登录', exact: true });
      if (await impersonateButton.count()) await impersonateButton.click();
      else { await targetCustomer.getByRole('button', { name: '更多', exact: true }).click(); await page.getByRole('menuitem', { name: '模拟登录', exact: true }).click(); }
      const simulation = await actionResponse(response => new URL(response.url()).pathname.endsWith('/impersonate'),
        () => page.getByRole('dialog').getByRole('button', { name: /^确\s*认$/ }).click());
      expect(simulation.status()).toBe(200); const simulated = (await simulation.json()).data;
      simulatedToken = simulated.accessToken; secrets.push(simulatedToken); report.cleanup.simulation = false;
      await page.waitForURL('**/#/tenants');
      expect((await enterTenant(tenant, '设备甲')).items.length).toBe(2);
      const simulatedIdentity = (await browserApi('/customer/auth/me', tenant)).body.data.identity;
      await expect(page.locator('.impersonation-banner')).toContainText('模拟登录中');
      expect((await browserApi('/admin/broker/resources/connections')).status).toBe(401);
      const simulatedResource = (await openDetail()).item; await screenshot('impersonation'); await closeDetail();
      const events = await browserApi(`/customer/tenants/${tenant}/broker/audit?action=broker.resource.read&subject_id=${simulatedResource.id}`, tenant);
      expect(events.status).toBe(200);
      const event = events.body.items.find(item => item.details.impersonation_id === simulatedIdentity.impersonation_id);
      expect(event.actor_id).toBe(simulatedIdentity.actor_id); expect(event.details.customer_id).toBe(simulatedIdentity.customer_id);
      const inspectAudit = async path => {
        await navigate(path); await expect(page.getByRole('heading', { name: 'Broker 审计', exact: true })).toBeVisible();
        await expect(page.getByLabel('业务主体筛选')).toBeEnabled();
        await page.getByLabel('业务主体筛选').fill(simulatedResource.id); await page.getByLabel('操作类型筛选').fill('broker.resource.read');
        await actionResponse(response => new URL(response.url()).pathname.endsWith('/broker/audit') && new URL(response.url()).searchParams.get('subject_id') === simulatedResource.id,
          () => page.getByRole('button', { name: /^查\s*询$/ }).click());
        await expect(page.getByLabel('业务主体筛选')).toBeEnabled();
        await rows().filter({ hasText: event.operation_id }).getByRole('button', { name: /^详\s*情$/ }).click();
        await expect(drawer()).toContainText(simulatedIdentity.source_session_id);
        await expect(drawer()).toContainText(simulatedIdentity.customer_id);
        await expect(drawer()).toContainText(simulatedIdentity.actor_id);
        await expect(drawer()).toContainText('customer.broker.read'); await screenshot(path.startsWith('/admin/') ? 'audit-platform' : 'audit-simulated'); await closeDetail();
      };
      await inspectAudit('/broker-audit');
      await page.locator('.impersonation-banner').getByRole('button', { name: '退出模拟', exact: true }).click();
      await page.waitForURL('**/#/admin/customers'); report.cleanup.simulation = true; simulatedToken = null;
      await inspectAudit('/admin/broker-audit');
      report.cases.push('real-platform-global-metadata-simulated-tenant-and-dual-audit-exact-source');
      await page.getByRole('button', { name: '退出登录', exact: true }).click(); await page.waitForURL('**/#/admin/login');
    })());
    if (report.errors.length) throw new Error('resource_browser_page_error');
    report.status = 'passed';
  } catch (error) {
    failed = error; report.status = 'failed'; report.failure = safeError(error);
    if (page) try {
      report.observations.failure = await page.evaluate(() => {
        const field = [...document.querySelectorAll('.search-card .crud-search-field')].find(element => element.querySelector('.crud-search-field__label')?.textContent === '资源类型');
        return { route: location.hash, visibility: document.visibilityState, selected: field?.querySelector('.ant-select-selection-item')?.textContent,
          disabled: field?.querySelector('input')?.disabled, drawer_open: !!document.querySelector('.ant-drawer-open'),
          options: [...document.querySelectorAll('.ant-select-dropdown .ant-select-item-option-content')].map(element => element.textContent) };
      });
    } catch {}
    if (page) try { await page.screenshot({ path: resolve(base, 'browser-resources-failure.png'), animations: 'disabled', timeout: 5000 }); report.screenshots.push('browser-resources-failure.png'); } catch {}
  } finally {
    stop.abort(new Error('resource_browser_finished')); cancelDelay?.(); clearTimeout(overall);
    process.off('SIGTERM', onSignal); process.off('SIGINT', onSignal);
    if (!report.cleanup.role) try { await changeReader(true); } catch (error) { report.errors.push(safeError(error)); }
    if (simulatedToken) try {
      const response = await fetch(`${origin}/customer/auth/logout`, { method: 'POST', headers: { Authorization: `Bearer ${simulatedToken}` }, signal: AbortSignal.timeout(15000) });
      expect([200, 401]).toContain(response.status); report.cleanup.simulation = true;
    } catch (error) { report.errors.push(safeError(error)); }
    try { await context?.close(); report.cleanup.context = true; } catch (error) { report.errors.push(safeError(error)); }
    try { await browser?.close(); report.cleanup.browser = true; } catch (error) { report.errors.push(safeError(error)); }
    try { await previewOwner.close(); report.cleanup.preview = true; } catch (error) { report.errors.push(safeError(error)); }
    if (report.errors.length || !Object.values(report.cleanup).every(Boolean)) report.status = 'failed';
    writeFileSync(resolve(base, 'browser-resources-report.json'), `${JSON.stringify(report, null, 2)}\n`, { mode: 0o600 });
  }
  if (report.status !== 'passed') throw new Error(failed ? safeError(failed) : 'resource_browser_cleanup_failed');
  return report.cases;
}

/**
 * 主任务持有专用page、真实预览baseUrl、数据库及支持授权；本函数只持有路由拦截和本轮报告。
 * fixture.accounts={standalone,tenant,platform}，各含login/password；tenants={primary,other}各含id/name。
 * fixture.events={standalone,platform,tenant,legacy,other}均为真实事件ID；legacy是独立历史事件。
 * tenant事件应为已取得不同当前阶段的旧阶段事件，租户无筛选列表至少21项；long_text为详情内至少80字符文本。
 * fixture.support={id,revoke:async()=>...}由主任务创建并通过真实HTTP撤销；其最终清理由主任务保证。
 * fixture.adminRole(kind, enabled)修改隔离身份库中的现有账号，用于验证真实403；每次撤权都在finally恢复。
 * 180秒预算在步骤边界检查，已开始的页面请求最多25秒；退出前等待当前步骤结束，不遗留后台work。
 */
export async function brokerAuditBrowser({ page, baseUrl, base: baseArgument, fixture, signal }) {
  const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
  const base = realpathSync(resolve(root, baseArgument));
  const build = realpathSync(resolve(root, 'build'));
  const preview = new URL(baseUrl);
  if (!base.startsWith(`${build}${sep}`) || !statSync(base).isDirectory() || preview.protocol !== 'http:'
    || !['127.0.0.1', '[::1]'].includes(preview.hostname) || preview.username || preview.password || preview.pathname !== '/' || preview.search || preview.hash
    || !Object.values(fixture.events).every(id => /^[a-f0-9]{32}$/.test(id)) || !/^[a-f0-9]{32}$/.test(fixture.support.id)
    || typeof fixture.support.revoke !== 'function' || typeof fixture.adminRole !== 'function' || fixture.long_text.length < 80) throw new Error('audit_browser_fixture_invalid');
  const secrets = [...Object.values(fixture.accounts).map(account => account.password), ...Object.values(fixture.tokens || {})].filter(Boolean);
  const safeError = error => secrets.reduce((text, secret) => text.replaceAll(secret, '<REDACTED>'), String(error?.stack ?? error)).replaceAll(root, '.').slice(0, 12000);
  const report = { status: 'running', cases: [], screenshots: [], errors: [], current: null, cleanup: { routes: false, offline: false, listeners: false } };
  const stop = new AbortController();
  const onAbort = () => stop.abort(signal?.reason || new Error('audit_browser_interrupted'));
  signal?.addEventListener('abort', onAbort, { once: true }); if (signal?.aborted) onAbort();
  const timer = setTimeout(() => stop.abort(new Error('audit_browser_deadline')), 180000);
  const onPageError = error => report.errors.push(safeError(error)); page.on('pageerror', onPageError);
  page.setDefaultTimeout(10000); page.setDefaultNavigationTimeout(25000);
  let release; let intercepted = Promise.resolve(); let interceptionError; let failed;
  let realm = 'iot'; let tenantId; let supportId; let path;
  const rows = () => page.locator('.ant-table-tbody tr.ant-table-row');
  const drawer = () => page.locator('.ant-drawer-content-wrapper:visible').last();
  const matches = response => response.request().method() === 'GET' && new URL(response.url()).pathname === path;
  const active = () => stop.signal.throwIfAborted();
  const responseAction = async (predicate, action) => {
    active();
    const results = await Promise.allSettled([page.waitForResponse(predicate, { timeout: 25000 }), Promise.resolve().then(action)]);
    for (const result of results) if (result.status === 'rejected') throw result.reason;
    active(); return results[0].value;
  };
  const noSecrets = value => { const json = JSON.stringify(value); for (const secret of [...secrets, '"password"', '"payload"', '"token_hash"', '"private_key"']) expect(json.includes(secret)).toBe(false); };
  const dataOf = async response => { expect(response.status()).toBe(200); const body = await response.json(); const value = body.data ?? body; noSecrets(value); return value; };
  const idle = async () => { active(); await expect(page.locator('.table-toolbar .ant-btn-loading')).toHaveCount(0); await expect(page.locator('.ant-spin-spinning')).toHaveCount(0); await expect(page.getByLabel('操作人员筛选', { exact: true })).toBeEnabled(); };
  const query = async (params = {}, button = /^刷\s*新$/, number = 1) => {
    report.current = { action: 'query', path, query_keys: Object.keys(params), page: number };
    const expected = new URLSearchParams({ limit: '20', ...params }); expected.sort(); await idle();
    const response = await responseAction(response => { const actual = new URL(response.url()).searchParams; actual.sort(); return matches(response) && actual.toString() === expected.toString(); },
      () => page.getByRole('button', { name: button, exact: true }).click());
    const data = await dataOf(response); expect(data.total).toBeNull(); expect(data.limit).toBe(20); await idle();
    await expect(rows()).toHaveCount(data.items.length); await expect(page.locator('.audit-pagination')).toContainText(`第 ${number} 页`); return data;
  };
  const get = async (target, scopeTenant = tenantId) => { active(); return page.evaluate(async ({ target, realm, tenant, support }) => {
    const response = await fetch(target, { headers: { Authorization: `Bearer ${sessionStorage.getItem(`typeapp.${realm}.token`)}`,
      ...(tenant ? { 'X-Tenant-Id': tenant, ...(support ? { 'X-Support-Id': support } : {}) } : {}) }, signal: AbortSignal.timeout(15000) });
    const body = await response.json(); return { status: response.status, data: body.data ?? body };
  }, { target, realm, tenant: scopeTenant, support: supportId }); };
  const navigate = async target => { active(); await page.evaluate(target => { location.hash = target; }, target); await page.waitForURL(`**/#${target}`); };
  const login = async kind => {
    active();
    report.current = { action: 'login', account: kind };
    if (await page.getByRole('button', { name: '退出登录', exact: true }).count()) {
      const previousRealm = realm;
      const logout = await responseAction(response => new URL(response.url()).pathname === `/${previousRealm}/auth/logout` && response.request().method() === 'POST',
        () => page.getByRole('button', { name: '退出登录', exact: true }).click());
      expect(logout.status()).toBe(200);
      // 点击结束不等于退出跳转结束，跨宿主前等待旧页面完成导航。
      await page.waitForURL(`**/#/${previousRealm === 'broker' ? 'broker/' : ''}login`);
      await expect(page.getByRole('button', { name: '退出登录', exact: true })).toHaveCount(0);
    }
    realm = kind === 'standalone' ? 'broker' : 'iot'; tenantId = undefined; supportId = undefined;
    await page.goto(`${preview.origin}/#/${realm === 'broker' ? 'broker/' : ''}login`);
    await expect(page.getByRole('heading', { name: realm === 'broker' ? 'Broker 管理' : '物联网中心', exact: true })).toBeVisible();
    await page.getByLabel('登录账号', { exact: true }).fill(fixture.accounts[kind].login); await page.getByLabel('登录密码', { exact: true }).fill(fixture.accounts[kind].password);
    const response = await responseAction(response => new URL(response.url()).pathname === `/${realm}/auth/login` && response.request().method() === 'POST',
      () => page.getByRole('button', { name: /^登\s*录$/ }).click());
    expect(response.status()).toBe(200); await page.waitForURL(`**/#/${realm === 'broker' ? 'broker/nodes' : 'tenants'}`);
  };
  const enter = async tenant => {
    await navigate('/tenants'); await rows().filter({ hasText: tenant.id }).getByRole('button', { name: '进入租户', exact: true }).click();
    await page.waitForURL('**/#/members'); tenantId = tenant.id; supportId = undefined; path = `/iot/tenants/${tenantId}/broker/audit`;
    await navigate('/broker-resources/audit'); await expect(page.getByRole('heading', { name: '租户 Broker 审计', exact: true })).toBeVisible(); return query();
  };
  const close = async () => { active(); await page.locator('.ant-drawer-mask:visible').click({ position: { x: 4, y: 4 } }); await expect(drawer()).toHaveCount(0); };
  const open = async id => {
    report.current = { action: 'detail', path, id }; await idle();
    const response = await responseAction(response => response.request().method() === 'GET' && new URL(response.url()).pathname === `${path}/${id}`,
      () => page.locator(`.ant-table-tbody tr.ant-table-row[data-row-key="${id}"]`).getByRole('button', { name: /^详\s*情$/ }).click());
    const data = await dataOf(response); expect(data.found).toBe(true); expect(data.item.id).toBe(id);
    await expect(drawer().getByRole('button', { name: /保存|确定|取消/ })).toHaveCount(0); return data.item;
  };
  const inspect = async id => {
    const known = await get(`${path}/${id}`); expect(known.status).toBe(200); noSecrets(known.data);
    const item = known.data.item;
    const params = item.operation_id ? { operation_id: item.operation_id } : { subject_id: item.subject_id, action: item.action };
    for (const [key, label] of [['operation_id', '操作标识筛选'], ['subject_id', '业务主体筛选'], ['action', '操作类型筛选']]) await page.getByLabel(label, { exact: true }).fill(params[key] || '');
    await query(params, /^查\s*询$/); const detail = await open(id);
    await expect(drawer().getByText('阶段事件', { exact: true })).toBeVisible();
    if (detail.operation) {
      await expect(drawer().getByText('当前操作状态', { exact: true })).toBeVisible();
      const stages = { accepted: '已受理', executing: '执行中', unknown: '结果未知', completed: '已完成', failed: '已失败' };
      await expect(drawer().locator('.ant-descriptions').first()).toContainText(stages[detail.stage]);
      await expect(drawer().locator('.ant-descriptions').nth(1)).toContainText(stages[detail.operation.current_stage]);
    } else { await expect(drawer()).toContainText('这条历史记录未保存操作上下文'); await expect(drawer().getByText('当前操作状态', { exact: true })).toHaveCount(0); }
    return detail;
  };
  const screenshot = async name => {
    await expect(page.locator('.bg-overlay-content')).not.toBeVisible();
    if (await drawer().count()) await expect.poll(async () => { const box = await drawer().boundingBox(); return box ? Math.round(box.x + box.width) : 0; }).toBe(page.viewportSize().width);
    const file = `browser-audit-${name}.png`; await page.screenshot({ path: resolve(base, file), animations: 'disabled' }); report.screenshots.push(file);
  };
  const intercept = async target => {
    active();
    let started = 0; const hold = new Promise(resolve => { release = resolve; }); interceptionError = undefined;
    const handler = route => { started++; intercepted = (async () => { await hold; if (stop.signal.aborted) await route.abort(); else await route.continue(); })().catch(error => { interceptionError = error; }); return intercepted; };
    const match = url => url.pathname === target; await page.route(match, handler);
    return { started: () => started, finish: async () => { release(); await intercepted; await page.unroute(match, handler); release = undefined; if (interceptionError) throw interceptionError; } };
  };
  const work = async () => {
    for (const kind of ['standalone', 'platform']) {
      await login(kind); path = kind === 'standalone' ? '/broker/audit' : '/iot/broker/audit';
      await navigate(kind === 'standalone' ? '/broker/audit' : '/broker-platform/audit'); const list = await query();
      if (kind === 'platform') expect(list.items.every(item => item.category === 'broker')).toBe(true);
      await inspect(fixture.events[kind]); await screenshot(kind); await close();
      if (kind === 'standalone') { const legacy = await inspect(fixture.events.legacy); expect(legacy.operation).toBeNull(); await close(); }
    }
    report.cases.push('real-standalone-platform-audit-and-historical-null-context');
    await login('tenant'); const first = await enter(fixture.tenants.primary);
    expect(first.items.length).toBe(20); expect(first.has_more).toBe(true); expect(first.items.every(item => item.tenant_id === tenantId && item.category === 'broker')).toBe(true);
    const next = await query({ cursor: first.next_cursor }, '下一页', 2); expect(next.items.length).toBeGreaterThan(0);
    expect(next.items.some(item => first.items.some(previous => previous.id === item.id))).toBe(false);
    const previous = await query({}, '上一页'); expect(previous.items.map(item => item.id)).toEqual(first.items.map(item => item.id));
    let automatic = 0; const count = request => { if (request.method() === 'GET' && new URL(request.url()).pathname === path) automatic++; };
    page.on('request', count); try { await delay(5500, undefined, { signal: stop.signal }); expect(automatic).toBe(0); } finally { page.off('request', count); }
    const item = await inspect(fixture.events.tenant); expect(item.stage).not.toBe(item.operation.current_stage); await expect(drawer()).toContainText(fixture.long_text); await close();
    const from = Math.floor(item.created_at / 60) * 60 - 60; const to = from + 180;
    const localTime = seconds => page.evaluate(seconds => { const value = new Date(seconds * 1000); const pad = value => String(value).padStart(2, '0'); return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}T${pad(value.getHours())}:${pad(value.getMinutes())}`; }, seconds);
    for (const [field, label] of [['actor_id', '操作人员筛选'], ['subject_id', '业务主体筛选'], ['action', '操作类型筛选']]) await page.getByLabel(label, { exact: true }).fill(item[field]);
    for (const [label, text] of [['事件阶段', { accepted: '已受理', executing: '执行中', unknown: '结果未知', completed: '已完成', failed: '已失败' }[item.stage]], ['执行结果', { pending: '待确定', success: '成功', denied: '拒绝', unknown: '结果未知', failed: '失败' }[item.result]]]) {
      await page.locator('.audit-search-card .crud-search-field').filter({ has: page.getByText(label, { exact: true }) }).locator('.ant-select-selector').click(); await page.locator('.ant-select-dropdown:visible .ant-select-item-option').filter({ has: page.getByText(text, { exact: true }) }).click();
    }
    await page.getByLabel('开始时间筛选', { exact: true }).fill(await localTime(from)); await page.getByLabel('结束时间筛选', { exact: true }).fill(await localTime(to));
    const filters = { operation_id: item.operation_id, actor_id: item.actor_id, subject_id: item.subject_id, action: item.action, stage: item.stage, result: item.result, from: String(from), to: String(to) };
    const filtered = await query(filters, /^查\s*询$/); expect(filtered.items.map(row => row.id)).toContain(item.id);
    await page.getByLabel('业务主体筛选').fill('audit-browser-no-such-subject'); expect((await query({ ...filters, subject_id: 'audit-browser-no-such-subject' }, /^查\s*询$/)).items).toEqual([]); await screenshot('empty');
    await query({}, /^重\s*置$/); for (const label of ['操作标识筛选', '操作人员筛选', '业务主体筛选', '操作类型筛选', '开始时间筛选', '结束时间筛选']) await expect(page.getByLabel(label)).toHaveValue('');
    report.cases.push('real-tenant-keyset-all-filters-empty-reset-no-automatic-list-refresh');
    const slow = await intercept(path);
    try {
      const response = await responseAction(matches, async () => { await page.getByRole('button', { name: /^刷\s*新$/ }).click(); await expect.poll(slow.started).toBe(1); await expect(page.getByLabel('操作标识筛选')).toBeDisabled(); await expect(page.getByRole('button', { name: /^重\s*置$/ })).toBeDisabled(); await screenshot('loading'); await slow.finish(); });
      await dataOf(response); await idle();
    } finally { if (release) await slow.finish(); }
    await page.context().setOffline(true); await page.getByRole('button', { name: /^刷\s*新$/ }).click(); await expect(page.getByRole('alert').filter({ hasText: '网络连接失败' })).toBeVisible(); await idle(); await screenshot('failure');
    await page.context().setOffline(false); await query(); await expect(page.getByRole('alert')).toHaveCount(0);
    report.cases.push('real-audit-slow-request-controls-and-network-recovery');
    await inspect(item.id);
    const dark = async enabled => { if (await page.locator('html').evaluate(element => element.classList.contains('dark')) !== enabled) { await close(); await page.getByRole('button', { name: enabled ? 'dark' : 'light', exact: true }).click(); await open(item.id); } await expect(page.locator('html')).toHaveClass(enabled ? /dark/ : /^(?!.*\bdark\b)/); };
    await dark(true); await screenshot('dark-detail'); await dark(false); await screenshot('light-detail'); await close();
    await page.getByRole('button', { name: '主题色', exact: true }).click(); await page.getByLabel('主题色', { exact: true }).first().click(); await page.getByText('紫罗兰', { exact: true }).last().click(); await page.getByRole('heading', { name: '租户 Broker 审计', exact: true }).click();
    await page.setViewportSize({ width: 390, height: 844 }); await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBe(390);
    await expect.poll(() => page.locator('.audit-search-card .crud-search-grid').evaluate(element => getComputedStyle(element).gridTemplateColumns.split(' ').length)).toBe(1);
    await open(item.id); await expect(drawer()).toContainText(fixture.long_text); await expect.poll(() => drawer().locator('.ant-drawer-body').evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1); await screenshot('mobile-purple-long-detail'); await close(); await page.setViewportSize({ width: 1440, height: 1000 });
    report.cases.push('real-audit-readonly-mask-close-dark-light-purple-mobile-long-text');
    const pendingDetail = await intercept(`${path}/${item.id}`);
    try {
      await page.locator(`.ant-table-tbody tr.ant-table-row[data-row-key="${item.id}"]`).getByRole('button', { name: /^详\s*情$/ }).click(); await expect.poll(pendingDetail.started).toBe(1);
      await navigate('/tenants'); await expect(drawer()).toHaveCount(0); await enter(fixture.tenants.other); await pendingDetail.finish();
      await expect(page.getByLabel('操作标识筛选')).toHaveValue(''); await expect(page.getByText(item.operation_id, { exact: true })).toHaveCount(0); await expect(drawer()).toHaveCount(0);
    } finally { if (release) await pendingDetail.finish(); }
    expect((await get(`${path}/${item.id}`)).status).toBe(404); expect((await get('/iot/broker/audit', null)).status).toBe(403);
    const other = await inspect(fixture.events.other); expect(other.tenant_id).toBe(fixture.tenants.other.id); await close();
    await page.evaluate(() => { location.hash = '/broker-platform/audit'; }); await page.waitForURL('**/#/tenants'); await expect(page.getByText('平台 Broker 审计', { exact: true })).toHaveCount(0);
    await enter(fixture.tenants.primary); path = `/iot/tenants/${tenantId}/audit`;
    const legacyList = await dataOf(await responseAction(response => matches(response), () => navigate('/audit'))); expect(typeof legacyList.total).toBe('number'); await expect(page.getByLabel('操作标识筛选')).toHaveCount(0); await expect(page.locator('.audit-pagination')).toHaveCount(0);
    report.cases.push('real-audit-context-switch-cancels-detail-scope-denial-and-legacy-iot-page');
    await login('platform'); await page.locator(`.ant-table-tbody tr.ant-table-row[data-row-key="${fixture.support.id}"]`).getByRole('button', { name: '支持访问', exact: true }).click(); await page.waitForURL('**/#/devices');
    tenantId = fixture.tenants.primary.id; supportId = fixture.support.id; path = `/iot/tenants/${tenantId}/broker/audit`; await navigate('/broker-resources/audit'); await query(); await inspect(item.id);
    await expect(page.getByLabel('当前临时支持身份')).toBeVisible(); await expect(page.getByText('平台 Broker 审计', { exact: true })).toHaveCount(0);
    active(); await fixture.support.revoke();
    const denied = await responseAction(response => response.request().method() === 'GET' && new URL(response.url()).pathname === `${path}/${item.id}`,
      () => drawer().getByRole('button', { name: '刷新详情', exact: true }).click());
    expect(denied.status()).toBe(403); await page.waitForURL('**/#/tenants'); await expect(drawer()).toHaveCount(0); await expect(page.getByText(item.operation_id, { exact: true })).toHaveCount(0); await expect(page.getByText('租户 Broker 审计', { exact: true })).toHaveCount(0); await screenshot('support-revoked');
    report.cases.push('real-audit-support-revalidation-clears-open-detail-and-menu');
    for (const kind of ['standalone', 'platform']) {
      for (const target of ['list', 'detail']) {
        await login(kind); path = kind === 'standalone' ? '/broker/audit' : '/iot/broker/audit';
        await navigate(kind === 'standalone' ? '/broker/audit' : '/broker-platform/audit');
        await query(); await inspect(fixture.events[kind]);
        if (target === 'list') await close();
        try {
          await fixture.adminRole(kind, false);
          const endpoint = target === 'list' ? path : `${path}/${fixture.events[kind]}`;
          const rejected = await responseAction(response => response.request().method() === 'GET' && new URL(response.url()).pathname === endpoint,
            () => target === 'list' ? page.getByRole('button', { name: /^刷\s*新$/ }).click() : drawer().getByRole('button', { name: '刷新详情', exact: true }).click());
          expect(rejected.status()).toBe(403);
          await page.waitForURL(kind === 'standalone' ? '**/#/broker/login' : '**/#/tenants');
          await expect(drawer()).toHaveCount(0); await expect(page.getByLabel('操作标识筛选')).toHaveCount(0);
          await expect(page.getByText(kind === 'standalone' ? 'Broker 审计' : '平台 Broker 审计', { exact: true })).toHaveCount(0);
          await screenshot(`${kind}-${target}-revoked`);
        } finally { await fixture.adminRole(kind, true); }
      }
    }
    report.cases.push('real-admin-revocation-list-and-detail-clear-authorization-filters-and-menu');
  };
  try {
    await work(); active();
    if (report.errors.length) throw new Error('audit_browser_page_error'); report.status = 'passed';
  } catch (error) {
    failed = error; report.status = 'failed'; report.failure = safeError(error);
    try { await screenshot('failure-final'); } catch {}
  } finally {
    stop.abort(new Error('audit_browser_finished')); clearTimeout(timer); signal?.removeEventListener('abort', onAbort);
    release?.(); await intercepted;
    try { await page.unrouteAll({ behavior: 'wait' }); report.cleanup.routes = true; } catch (error) { report.errors.push(safeError(error)); }
    try { await page.context().setOffline(false); report.cleanup.offline = true; } catch (error) { report.errors.push(safeError(error)); }
    page.off('pageerror', onPageError); report.cleanup.listeners = true;
    if (interceptionError) report.errors.push(safeError(interceptionError)); if (report.errors.length) report.status = 'failed';
    writeFileSync(resolve(base, 'browser-audit-report.json'), `${JSON.stringify(report, null, 2)}\n`, { mode: 0o600 });
  }
  if (report.status !== 'passed') throw new Error(failed ? safeError(failed) : 'audit_browser_cleanup_failed');
  return report.cases;
}

/** PHP装置持有数据库与两个HTTP宿主；Node只持有本轮预览、浏览器与一次真实支持撤销。 */
async function brokerAuditCli(args) {
  if (args.length !== 5 || args[0] !== '--audit') throw new Error('audit_browser_arguments_invalid');
  const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
  const build = realpathSync(resolve(root, 'build'));
  const base = realpathSync(resolve(root, args[1]));
  const dist = realpathSync(resolve(root, args[2]));
  const origins = args.slice(3).map(value => new URL(value));
  if (!base.startsWith(`${build}${sep}`) || !statSync(base).isDirectory() || !statSync(resolve(dist, 'index.html')).isFile()
    || origins.some(origin => origin.protocol !== 'http:' || !['127.0.0.1', '[::1]'].includes(origin.hostname)
      || origin.username || origin.password || origin.pathname !== '/' || origin.search || origin.hash)) throw new Error('audit_browser_arguments_invalid');
  const fixturePath = realpathSync(resolve(base, 'audit-browser-fixture.json'));
  const fixtureStat = statSync(fixturePath);
  if (!fixturePath.startsWith(`${base}${sep}`) || !fixtureStat.isFile() || fixtureStat.size > 65536 || (fixtureStat.mode & 0o077) !== 0) throw new Error('audit_browser_fixture_invalid');
  const fixture = JSON.parse(readFileSync(fixturePath, 'utf8'));
  const id = value => typeof value === 'string' && /^[a-f0-9]{32}$/.test(value);
  if (!['standalone', 'tenant', 'platform'].every(key => typeof fixture.accounts?.[key]?.login === 'string'
      && /^[a-z0-9][a-z0-9_.@-]{2,99}$/.test(fixture.accounts[key].login) && typeof fixture.accounts[key].password === 'string'
      && fixture.accounts[key].password.length >= 1 && fixture.accounts[key].password.length <= 72)
    || !['primary', 'other'].every(key => id(fixture.tenants?.[key]?.id) && typeof fixture.tenants[key].name === 'string')
    || !['standalone', 'platform', 'tenant', 'legacy', 'other'].every(key => id(fixture.events?.[key]))
    || !id(fixture.support?.id) || !Number.isSafeInteger(fixture.support.version) || fixture.support.version < 1
    || typeof fixture.tokens?.tenant !== 'string' || !fixture.tokens.tenant || typeof fixture.long_text !== 'string' || fixture.long_text.length < 80) throw new Error('audit_browser_fixture_invalid');
  const secrets = [...Object.values(fixture.accounts).map(account => account.password), ...Object.values(fixture.tokens)];
  const safeError = error => secrets.reduce((value, secret) => value.replaceAll(secret, '<REDACTED>'), String(error?.stack ?? error)).replaceAll(root, '.').slice(0, 12000);
  const report = { status: 'running', fixture: relative(root, base), dist: relative(root, dist), cases: [], errors: [], support_revoked: false,
    cleanup: { context: false, browser: false, preview: false, listeners: false } };
  const previewOwner = brokerPreview(dist, origins[0], origins[1]);
  const preview = previewOwner.server;
  const stop = new AbortController();
  let browser; let context; let interruptClose = Promise.resolve();
  const interrupt = reason => {
    if (stop.signal.aborted) return;
    stop.abort(new Error(reason));
    if (context) interruptClose = context.close().catch(error => { report.errors.push(safeError(error)); });
  };
  const onSignal = () => interrupt('audit_browser_interrupted');
  process.on('SIGTERM', onSignal); process.on('SIGINT', onSignal);
  // 给PHP的240秒外层期限预留上下文、浏览器与代理回收时间。
  const timer = setTimeout(() => interrupt('audit_browser_runner_deadline'), 210000);
  const revoke = async () => {
    const response = await fetch(new URL(`/iot/tenants/${fixture.tenants.primary.id}/support-grants/${fixture.support.id}`, origins[0]), {
      method: 'DELETE', headers: { Authorization: `Bearer ${fixture.tokens.tenant}`, 'X-Tenant-Id': fixture.tenants.primary.id, 'Content-Type': 'application/json' },
      body: JSON.stringify({ version: fixture.support.version }), signal: AbortSignal.timeout(15000),
    });
    if (response.status !== 200) { await response.body?.cancel(); throw new Error('audit_browser_support_revoke_failed'); }
    await response.body?.cancel(); report.support_revoked = true;
  };
  // SQLite是本页验收的隔离身份库；只改变既有夹具账号，再由真实HTTP重新鉴权。
  const adminRole = async (kind, enabled) => {
    if (!['standalone', 'platform'].includes(kind) || typeof enabled !== 'boolean') throw new Error('audit_admin_fixture_invalid');
    const databasePath = realpathSync(resolve(base, kind === 'standalone' ? 'audit-broker/audit-broker.sqlite' : 'identity.sqlite'));
    if (!databasePath.startsWith(`${base}${sep}`) || !statSync(databasePath).isFile()) throw new Error('audit_admin_database_invalid');
    const database = new DatabaseSync(databasePath);
    try {
      database.exec('PRAGMA busy_timeout=3000');
      const result = database.prepare(`UPDATE ${kind === 'standalone' ? 'broker' : 'iot'}_users SET platform_admin = ? WHERE login = ? AND enabled = 1`).run(enabled ? 1 : 0, fixture.accounts[kind].login);
      if (result.changes !== 1) throw new Error('audit_admin_fixture_missing');
    } finally { database.close(); }
  };
  try {
    stop.signal.throwIfAborted();
    await new Promise((resolve, reject) => { preview.once('error', reject); preview.listen(0, '127.0.0.1', resolve); });
    stop.signal.throwIfAborted();
    browser = await chromium.launch({ channel: 'msedge', headless: true, timeout: 30000 }); report.browser = browser.version();
    stop.signal.throwIfAborted();
    context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, timezoneId: 'Asia/Shanghai' });
    stop.signal.throwIfAborted();
    const page = await context.newPage();
    report.cases = await brokerAuditBrowser({ page, baseUrl: `http://127.0.0.1:${preview.address().port}`, base,
      fixture: { ...fixture, support: { ...fixture.support, revoke }, adminRole }, signal: stop.signal });
    report.status = 'passed';
  } catch (error) {
    report.status = 'failed'; report.failure = safeError(error);
  } finally {
    clearTimeout(timer); process.off('SIGTERM', onSignal); process.off('SIGINT', onSignal); report.cleanup.listeners = true;
    stop.abort(new Error('audit_browser_runner_finished')); await interruptClose;
    try { await context?.close(); report.cleanup.context = true; } catch (error) { report.errors.push(safeError(error)); }
    try { await browser?.close(); report.cleanup.browser = true; } catch (error) { report.errors.push(safeError(error)); }
    try { await previewOwner.close(); report.cleanup.preview = true; } catch (error) { report.errors.push(safeError(error)); }
    if (report.errors.length || !Object.values(report.cleanup).every(Boolean)) report.status = 'failed';
    writeFileSync(resolve(base, 'browser-audit-runner-report.json'), `${JSON.stringify(report, null, 2)}\n`, { mode: 0o600 });
  }
  if (report.status !== 'passed') throw new Error('audit_browser_failed_see_report');
  return report;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try { const report = await brokerAuditCli(process.argv.slice(2)); process.stdout.write(`${JSON.stringify({ status: report.status, cases: report.cases.length })}\n`); }
  catch { process.stderr.write('broker_audit_browser_failed; inspect browser-audit-runner-report.json and browser-audit-report.json\n'); process.exitCode = 1; }
}
