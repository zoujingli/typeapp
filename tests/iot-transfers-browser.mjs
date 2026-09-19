import { chromium, expect } from '../web/node_modules/@playwright/test/index.mjs';
import { readFileSync, writeFileSync } from 'node:fs';
import { randomUUID } from 'node:crypto';
import { spawnSync } from 'node:child_process';

const fixture = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const origin = process.argv[3];
if (!origin || !fixture.transfers) throw new Error('用法：node tests/iot-transfers-browser.mjs <带--transfers的fixture.json> <Web地址>');
const report = { status: 'running', cases: [], errors: [], screenshots: [], connection: fixture.transfers.connection };
const browser = await chromium.launch({ channel: 'msedge', headless: true });
const sourceContext = await browser.newContext({ viewport: { width: 1440, height: 1000 }, timezoneId: 'Asia/Shanghai' });
const source = await sourceContext.newPage();
const targetContext = await browser.newContext({ viewport: { width: 1440, height: 1000 }, timezoneId: 'Asia/Shanghai' });
const target = await targetContext.newPage();
for (const page of [source, target]) page.on('pageerror', error => report.errors.push(error.message));
const enter = async (page, organization) => {
  await page.goto(`${origin}/#/tenants`);
  await page.getByRole('row').filter({ hasText: organization }).getByRole('button', { name: '进入租户' }).click();
  await page.waitForURL('**/#/profile'); await page.evaluate(() => { location.hash = '/transfers'; });
  await expect(page.getByRole('heading', { name: '设备转移', exact: true })).toBeVisible();
};
const login = async (page, account, organization) => {
  await page.goto(origin); await page.getByLabel('登录账号').fill(account); await page.getByLabel('登录密码').fill('History-browser-password-2026');
  await page.getByRole('button', { name: /^登\s*录$/ }).click(); await page.waitForURL('**/#/profile'); await enter(page, organization);
};
const screenshot = async (page, name) => { const path = `${fixture.base}/${name}.png`; await page.screenshot({ path, animations: 'disabled' }); report.screenshots.push(path); };
const choose = async (page, label, text) => { await page.getByLabel(label, { exact: true }).click(); await page.getByText(text, { exact: true }).last().click(); };
const stableDrawer = async page => {
  await expect.poll(async () => { const box = await page.locator('.ant-drawer-content-wrapper:visible').last().boundingBox(); return box ? Math.round(box.x + box.width) : 0; }).toBe(page.viewportSize().width);
  await expect.poll(() => page.locator('.ant-drawer-body:visible').last().evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1);
};
const token = async account => {
  const response = await fetch(`http://127.0.0.1:${fixture.port}/customer/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ login: account, password: 'History-browser-password-2026' }) });
  expect(response.status).toBe(200); return (await response.json()).data.accessToken;
};
try {
  report.browser = browser.version(); await login(source, 'webadmin', '历史数据验收组织');
  await expect(source.getByText('暂无符合条件的转移记录', { exact: true })).toBeVisible();
  await source.getByRole('button', { name: '发起转移', exact: true }).click();
  await source.getByLabel('转移设备标识', { exact: true }).fill(fixture.device); await source.getByLabel('目标租户标识', { exact: true }).fill(fixture.transfers.target);
  await source.getByRole('button', { name: '读取当前版本', exact: true }).click();
  await source.getByRole('button', { name: '提交转移申请', exact: true }).click();
  await expect(source.getByText('等待目标租户管理员审批', { exact: true })).toBeVisible();
  await login(target, 'webtarget', '转移接收组织'); await choose(target, '转移方向', '本方收到');
  await target.getByRole('button', { name: /^查\s*询$/ }).click();
  await target.getByRole('row').filter({ hasText: fixture.device }).getByRole('button', { name: '查看处理' }).click();
  await target.getByRole('button', { name: '拒绝转移', exact: true }).click();
  await target.getByRole('button', { name: '确认拒绝', exact: true }).click();
  await expect(target.locator('.ant-drawer-body').getByText('已拒绝', { exact: true })).toBeVisible();
  await source.getByRole('button', { name: '刷新处理状态', exact: true }).click();
  await expect(source.locator('.ant-drawer-body').getByText('已拒绝', { exact: true })).toBeVisible();
  await expect(target.getByRole('button', { name: 'Close', exact: true })).toHaveCount(1);
  await target.getByRole('button', { name: 'Close', exact: true }).click(); await source.getByRole('button', { name: 'Close', exact: true }).click();
  report.cases.push('empty-list-and-target-rejection-visible-to-both-administrators');
  await source.getByRole('button', { name: '发起转移', exact: true }).click();
  await source.getByLabel('转移设备标识', { exact: true }).fill(fixture.device); await source.getByLabel('目标租户标识', { exact: true }).fill(fixture.transfers.target);
  await source.getByRole('button', { name: '读取当前版本', exact: true }).click();
  const endpoint = `**/tenants/${fixture.tenant}/transfers`;
  let transfer = '';
  await source.route(endpoint, async route => {
    if (route.request().method() !== 'POST') { await route.continue(); return; }
    const response = await route.fetch(); expect(response.status()).toBe(202); transfer = (await response.json()).data.id; await route.abort('failed');
  });
  await source.getByRole('button', { name: '提交转移申请', exact: true }).click();
  await expect(source.getByText('受理结果尚未确定', { exact: false })).toBeVisible();
  await source.unroute(endpoint); await source.reload(); await enter(source, '历史数据验收组织');
  await source.getByRole('button', { name: '发起转移', exact: true }).click();
  await expect(source.getByLabel('转移设备标识', { exact: true })).toHaveValue(fixture.device);
  await expect(source.getByLabel('转移设备标识', { exact: true })).toBeDisabled();
  await expect(source.getByText(`原转移标识：${transfer}`, { exact: false })).toBeVisible();
  const resumed = source.waitForResponse(response => response.url().endsWith('/transfers') && response.request().method() === 'POST');
  await source.getByRole('button', { name: '确认原请求结果', exact: true }).click(); expect((await (await resumed).json()).data.id).toBe(transfer);
  await expect(source.getByText('等待目标租户管理员审批', { exact: true })).toBeVisible();
  await expect(source.getByRole('button', { name: '匹配并接受', exact: true })).toHaveCount(0);
  await stableDrawer(source); await screenshot(source, 'transfers-source-pending');
  report.cases.push('request-lost-response-reload-original-id-and-source-cannot-approve');

  const sourceToken = await source.evaluate(() => sessionStorage.getItem('typeapp.customer.token'));
  const targetToken = await target.evaluate(() => sessionStorage.getItem('typeapp.customer.token'));
  expect(sourceToken).toBeTruthy(); expect(targetToken).toBeTruthy();
  const targetHeaders = { Authorization: `Bearer ${targetToken}`, 'X-Tenant-Id': fixture.transfers.target, 'Content-Type': 'application/json' };
  const sourceHeaders = { Authorization: `Bearer ${sourceToken}`, 'X-Tenant-Id': fixture.tenant, 'Content-Type': 'application/json' };
  const url = `http://127.0.0.1:${fixture.port}/customer/tenants/${fixture.transfers.target}/transfers/${transfer}`;
  await choose(target, '转移方向', '本方收到'); await target.getByRole('button', { name: /^查\s*询$/ }).click();
  await target.getByRole('row').filter({ hasText: fixture.device }).filter({ hasText: '等待目标审批' }).getByRole('button', { name: '查看处理' }).click();
  await target.getByRole('button', { name: '匹配并接受', exact: true }).click(); await stableDrawer(target);
  await target.getByLabel('复制产品名称', { exact: true }).fill('目标独立拥有的复制模型');
  let decision = null;
  const targetEndpoint = `**/tenants/${fixture.transfers.target}/transfers/${transfer}`;
  await target.route(targetEndpoint, async route => {
    if (route.request().method() !== 'POST') { await route.continue(); return; }
    decision = route.request().postDataJSON();
    const raced = report.approvalControlRaceStatus === undefined;
    const [response, duplicate, control] = await Promise.all([
      route.fetch(), fetch(url, { method: 'POST', headers: targetHeaders, body: JSON.stringify(decision) }),
      raced ? fetch(`http://127.0.0.1:${fixture.port}/customer/tenants/${fixture.tenant}/devices/${fixture.device}/commands`, { method: 'POST', headers: sourceHeaders,
        body: JSON.stringify({ command_id: randomUUID().replaceAll('-', ''), identifier: 'switch', values: { on: false }, version: decision.version }) }) : null,
    ]);
    if (control) {
      expect([201, 409]).toContain(control.status); report.approvalControlRaceStatus = control.status;
      if (control.status === 201) {
        expect(response.status()).toBe(409); expect(duplicate.status).toBe(409);
        expect((await response.json()).error).toBe('stale_version'); expect((await duplicate.json()).error).toBe('stale_version');
        await route.fulfill({ response }); return;
      }
      expect(['transfer_control_frozen', 'stale_version']).toContain((await control.json()).error);
    }
    expect(response.status()).toBe(202); expect(duplicate.status).toBe(202); await route.abort('failed');
  });
  await target.getByRole('button', { name: '接受并冻结', exact: true }).click();
  await expect.poll(() => report.approvalControlRaceStatus).toBeDefined();
  if (report.approvalControlRaceStatus === 201) {
    await expect(target.getByText('记录已被其他人修改，请刷新后重新编辑。', { exact: true })).toBeVisible();
    const before = await fetch(`http://127.0.0.1:${fixture.port}/customer/tenants/${fixture.transfers.target}/products`, { headers: targetHeaders }); expect((await before.json()).total).toBe(0);
    await target.getByRole('button', { name: 'Close', exact: true }).last().click();
    await target.getByRole('button', { name: '刷新处理状态', exact: true }).click();
    await target.getByRole('button', { name: '匹配并接受', exact: true }).click();
    await target.getByLabel('复制产品名称', { exact: true }).fill('目标独立拥有的复制模型');
    await target.getByRole('button', { name: '接受并冻结', exact: true }).click();
  }
  await expect(target.getByText('受理结果尚未确定', { exact: false })).toBeVisible();
  await target.unroute(targetEndpoint); await target.getByRole('button', { name: '确认原请求结果', exact: true }).click();
  await expect(target.getByText('等待设备持久冻结确认', { exact: true })).toBeVisible();
  await expect(target.getByText('已冻结，待切换', { exact: true }).last()).toBeVisible();
  await expect(target.getByRole('button', { name: '查看原设备与对账', exact: true })).toHaveCount(0);
  const outcomes = await Promise.all([0, 1].map(() => fetch(url, { method: 'POST', headers: targetHeaders, body: JSON.stringify(decision) })));
  for (const response of outcomes) { expect(response.status).toBe(202); expect((await response.json()).data.id).toBe(transfer); }
  const rejected = await fetch(url, { method: 'POST', headers: targetHeaders, body: JSON.stringify({ action: 'reject', decision_id: randomUUID().replaceAll('-', ''), version: decision.version }) }); expect(rejected.status).toBe(409);
  const products = await fetch(`http://127.0.0.1:${fixture.port}/customer/tenants/${fixture.transfers.target}/products`, { headers: targetHeaders }); expect((await products.json()).total).toBe(1);
  const frozenVersion = (await (await fetch(url, { headers: targetHeaders })).json()).data.device_version;
  const controls = await Promise.all([0, 1].map(() => fetch(`http://127.0.0.1:${fixture.port}/customer/tenants/${fixture.tenant}/devices/${fixture.device}/commands`, { method: 'POST', headers: sourceHeaders, body: JSON.stringify({ command_id: randomUUID().replaceAll('-', ''), identifier: 'switch', values: { on: true }, version: frozenVersion }) })));
  for (const response of controls) { expect(response.status).toBe(409); expect((await response.json()).error).toBe('transfer_control_frozen'); }
  const isolated = await fetch(`http://127.0.0.1:${fixture.port}/customer/tenants/${fixture.transfers.target}/devices/${fixture.device}`, { headers: targetHeaders }); expect(isolated.status).toBe(404);
  report.cases.push('independent-target-copy-and-lost-approval-response', 'concurrent-identical-approvals-single-copy-and-conflicting-decision-rejected', 'concurrent-new-controls-frozen-and-target-cannot-read-source');
  await screenshot(target, 'transfers-target-frozen-desktop');
  await target.setViewportSize({ width: 390, height: 844 }); await stableDrawer(target); await screenshot(target, 'transfers-mobile-dark');
  await target.getByRole('button', { name: 'Close', exact: true }).click();
  await target.getByRole('button', { name: 'light', exact: true }).click(); await target.getByRole('button', { name: '主题色', exact: true }).click(); await choose(target, '主题色', '紫罗兰');
  await target.getByRole('button', { name: '主题色', exact: true }).click();
  await target.getByRole('row').filter({ hasText: fixture.device }).filter({ hasText: '已冻结，待切换' }).getByRole('button', { name: '查看处理' }).click(); await stableDrawer(target);
  await screenshot(target, 'transfers-mobile-light-purple'); await target.getByRole('button', { name: 'Close', exact: true }).click();
  await expect.poll(() => target.evaluate(() => document.documentElement.scrollWidth)).toBe(390);
  await targetContext.setOffline(true); await target.getByRole('button', { name: /^刷\s*新$/ }).click();
  await expect(target.getByText('网络连接失败', { exact: false })).toBeVisible();
  await expect(target.getByRole('row').filter({ hasText: fixture.device })).toHaveCount(2);
  await targetContext.setOffline(false); await target.getByRole('button', { name: /^刷\s*新$/ }).click(); await expect(target.getByText('网络连接失败', { exact: false })).toHaveCount(0);
  report.cases.push('390px-long-name-dark-light-purple-without-overflow', 'read-failure-keeps-facts-and-recovers');

  const readerContext = await browser.newContext(); const reader = await readerContext.newPage(); await login(reader, 'webread', '转移接收组织');
  await expect(reader.getByRole('button', { name: '发起转移', exact: true })).toHaveCount(0);
  await choose(reader, '转移方向', '本方收到'); await reader.getByRole('button', { name: /^查\s*询$/ }).click();
  await reader.getByRole('row').filter({ hasText: fixture.device }).filter({ hasText: '已冻结，待切换' }).getByRole('button', { name: '查看处理' }).click();
  await expect(reader.getByRole('button', { name: '重发原冻结请求', exact: true })).toHaveCount(0);
  const forbidden = await fetch(url, { method: 'POST', headers: { ...targetHeaders, Authorization: `Bearer ${await token('webread')}` }, body: JSON.stringify(decision) }); expect(forbidden.status).toBe(403);
  await readerContext.close(); report.cases.push('readonly-can-view-but-cannot-approve-or-retry-server-enforced');

  const api = async (path, headers, body) => {
    const response = await fetch(`http://127.0.0.1:${fixture.port}${path}`, { method: body ? 'POST' : 'GET', headers, ...(body ? { body: JSON.stringify(body) } : {}) });
    expect(response.status).toBeLessThan(300); return (await response.json()).data;
  };
  const original = await api(`/customer/tenants/${fixture.tenant}/devices/${fixture.device}`, sourceHeaders);
  const registration = await api(`/customer/tenants/${fixture.tenant}/devices`, sourceHeaders, { name: '正式归属切换浏览器设备', product_id: original.product_id, model_version: 1 });
  const transferId = randomUUID().replaceAll('-', '');
  const stageFixture = (action, record) => {
    const code = '$db=new PDO("sqlite:".$argv[1]); $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $data=json_decode($argv[3],true,32,JSON_THROW_ON_ERROR); '
      + 'if($argv[2]==="online") { $node="browser-switch-".$data["id"]; $run=bin2hex(random_bytes(16)); $db->prepare("INSERT INTO iot_broker_observations (node_id,run_id,observed_at,expires_at) VALUES (?,?,?,?)")->execute([$node,$run,time(),time()+3600]); $db->prepare("UPDATE iot_devices SET lifecycle=? WHERE id=?")->execute(["enabled",$data["id"]]); $db->prepare("UPDATE iot_device_connections SET status=?,node_id=?,run_id=?,observed_at=? WHERE device_id=?")->execute(["online",$node,$run,time(),$data["id"]]); } '
      + 'elseif($argv[2]==="ready") { $receipt=["supported"=>true,"model_pending"=>false,"pending_count"=>0,"pending_command_receipts"=>0,"unresolved_commands"=>0,"boundary_sequence"=>"0"]; $db->prepare("UPDATE iot_transfers SET device_status=?,device_status_at=?,device_status_sequence=?,last_attempt_at=?,next_attempt_at=NULL WHERE id=?")->execute([json_encode($receipt),time(),"1",time(),$data["id"]]); } '
      + 'elseif($argv[2]==="isolated") { $db->prepare("UPDATE iot_authorization_invalidations SET completed_at=?,node_id=? WHERE id=?")->execute([time(),"browser-switch-proof",$data["isolation_id"]]); } '
      + 'elseif($argv[2]==="completed") { $db->beginTransaction(); $db->prepare("UPDATE iot_transfers SET status=?,completed_at=? WHERE id=? AND status=?")->execute(["completed",time(),$data["id"],"activating"]); $db->prepare("UPDATE iot_devices SET transfer_id=NULL,transfer_frozen=0 WHERE id=? AND ownership_id=?")->execute([$data["device_id"],$data["new_ownership_id"]]); $db->commit(); } else { throw new RuntimeException("invalid-browser-stage"); }';
    const result = spawnSync(fixture.transfers.fixturePhp, ['-r', code, `${fixture.base}/history.sqlite`, action, JSON.stringify(record)], { env: process.env, encoding: 'utf8', timeout: 10000 });
    if (result.error || result.status !== 0) throw new Error(result.error?.message || result.stderr);
  };
  stageFixture('online', registration.device);
  const requested = await api(`/customer/tenants/${fixture.tenant}/transfers`, sourceHeaders, { transfer_id: transferId, device_id: registration.device.id, target_tenant_id: fixture.transfers.target, version: registration.device.version });
  const switchPath = `/customer/tenants/${fixture.transfers.target}/transfers/${transferId}`;
  const frozen = await api(switchPath, targetHeaders, { action: 'accept', decision_id: randomUUID().replaceAll('-', ''), copy_name: '归属切换页面模型', version: requested.device_version });
  stageFixture('ready', frozen);
  await target.goto(`${origin}/#/transfers?transfer=${transferId}`);
  await expect(target.getByRole('button', { name: '开始正式切换', exact: true })).toBeEnabled();
  await target.getByRole('button', { name: '开始正式切换', exact: true }).click();
  await target.route(`**${switchPath}`, async route => { if (route.request().method() !== 'POST') { await route.continue(); return; } const response = await route.fetch(); expect(response.status()).toBe(202); await route.abort('failed'); });
  await target.getByRole('button', { name: '确认并继续', exact: true }).click();
  await expect(target.getByText('原请求标识已保留', { exact: false }).last()).toBeVisible();
  await target.unroute(`**${switchPath}`); await target.getByRole('button', { name: '确认并继续', exact: true }).click();
  await expect(target.getByText('旧授权隔离中', { exact: true }).last()).toBeVisible();
  const isolating = await api(switchPath, targetHeaders); expect(isolating.new_ownership_id).toBeNull(); stageFixture('isolated', isolating);
  await target.getByRole('button', { name: '刷新处理状态', exact: true }).click();
  await target.getByRole('button', { name: '继续归属切换', exact: true }).click(); await target.getByRole('button', { name: '确认并继续', exact: true }).click();
  await expect(target.getByText('密码仅本次显示', { exact: true })).toBeVisible();
  await expect(target.locator('.ant-drawer-body:visible').last().locator('code')).toHaveText(/^[a-f0-9]{64}$/);
  await stableDrawer(target); await target.getByRole('button', { name: '已保存，关闭', exact: true }).click();
  const activating = await api(switchPath, targetHeaders); expect(activating.status).toBe('activating'); expect(activating.credential).toBeUndefined();
  const replayedSwitch = await api(switchPath, targetHeaders, { action: 'switch', switch_id: activating.switch_id, version: activating.switch_version }); expect(replayedSwitch.credential).toBeNull();
  await expect(target.getByText('新归属待设备确认', { exact: true }).last()).toBeVisible();
  await stableDrawer(target); await screenshot(target, 'transfers-switch-activating-mobile');
  stageFixture('completed', activating); await target.getByRole('button', { name: '刷新处理状态', exact: true }).click();
  await expect(target.getByText('转移完成', { exact: true }).last()).toBeVisible(); await stableDrawer(target); await screenshot(target, 'transfers-switch-completed-mobile');
  report.cases.push('switch-stage-fixture-http-isolation-request-loss-and-idempotent-recovery', 'one-time-new-credential-cleared-and-no-repeat-secret', 'new-ownership-waiting-and-completed-states-390px');
  expect(report.errors).toEqual([]); report.status = 'passed';
} catch (error) { report.status = 'failed'; report.failure = error.stack; await screenshot(target, 'transfers-failure'); throw error; }
finally { writeFileSync(`${fixture.base}/transfers-browser-verification.json`, JSON.stringify(report, null, 2) + '\n'); await browser.close(); console.log(JSON.stringify(report, null, 2)); }
