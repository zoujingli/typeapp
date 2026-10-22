import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { once } from 'node:events';
import { existsSync, readFileSync, renameSync, writeFileSync } from 'node:fs';
import { setTimeout as delay } from 'node:timers/promises';

/** 独立 MQTT.js 客户端验证原字节、跨节点隔离与新关系；所有故障只作用于本轮装置。 */
export async function reassignmentClient({ fixture, url, start, connect, denied }) {
  const id = () => randomUUID().replaceAll('-', '');
  const otherUrl = `mqtts://127.0.0.1:${process.env.TYPE_REASSIGNMENT_PORT}`;
  const gate = process.env.TYPE_REASSIGNMENT_GATE;
  const call = async (method, path, data = null, token = fixture.platform_token, tenant = '', status = 200) => {
    const response = await fetch(process.env.TYPE_DEVICE_HTTP + path, { method,
      headers: { 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}), ...(tenant ? { 'X-Tenant-Id': tenant } : {}) },
      body: data === null ? undefined : JSON.stringify(data), signal: AbortSignal.timeout(10000) });
    const body = await response.json();
    assert.equal(response.status, status, `${method} ${path}: ${body.error || response.status}`);
    return body.data ?? body;
  };
  const until = async (probe, message, seconds = 25) => {
    const end = Date.now() + seconds * 1000;
    do { const value = await probe(); if (value) return value; await delay(80); } while (Date.now() < end);
    throw new Error(message);
  };
  const phase = async (stage, data = {}) => {
    writeFileSync(`${gate}.tmp`, JSON.stringify({ stage, ...data }), { mode: 0o600 }); renameSync(`${gate}.tmp`, `${gate}.json`);
    await until(() => existsSync(`${gate}.ack`) && readFileSync(`${gate}.ack`, 'utf8') === stage, `phase ${stage} stalled`, 40);
  };
  const join = async (credential, endpoint = url, messages = []) => {
    const client = start(credential, {}, endpoint);
    client.on('message', (topic, bytes) => messages.push({ topic, hex: bytes.toString('hex') }));
    const [ack] = await once(client, 'connect', { signal: AbortSignal.timeout(12000) });
    return { client, ack, messages };
  };
  const target = id();
  await call('POST', '/admin/tenants', { id: target, name: '真实转配目标客户', new_customer: false, owner_login: 'device-outsider' });
  const targetToken = fixture.tokens.outsider;
  const enrollment = await call('POST', '/admin/device-authorizations', { id: id(), tenant_id: fixture.tenant, name: '隔离设备批次', capacity: 2, register_expires_at: Math.floor(Date.now() / 1000) + 3600 }, fixture.platform_token, '', 201);
  const registered = await call('POST', '/public/devices/register', { id: id(), tenant_id: fixture.tenant, device_id: id(), name: '双节点转配设备', code: enrollment.code }, '', '', 201);
  const raw = registered.device; const topics = registered.credential.topics;
  const devicePath = `/admin/devices/${raw.id}`;
  const apps = [];
  const settled = async (app, tenant = fixture.tenant, token = fixture.admin_token) => until(async () => {
    const data = await call('GET', `/customer/tenants/${tenant}/applications/${app.application.id}`, null, token, tenant);
    return data.application.publication.status === 'effective' ? data.application : null;
  }, 'application publication not effective');
  for (const name of ['活动应用', '离线应用']) {
    const app = await call('POST', `/customer/tenants/${fixture.tenant}/applications`, { id: id(), name }, fixture.admin_token, fixture.tenant, 201);
    await settled(app);
    await call('POST', `/customer/tenants/${fixture.tenant}/applications/${app.application.id}/devices/${raw.id}`, { version: 0, ownership_id: raw.ownership_id, receive: true, send: true, subscribe: true }, fixture.admin_token, fixture.tenant);
    await settled(app); apps.push(app);
  }
  const device = await join(registered.credential, otherUrl);
  await device.client.subscribeAsync(topics.subscribe, { qos: 2 });
  const online = await join(apps[0].credential);
  const offline = await join(apps[1].credential);
  await online.client.subscribeAsync(topics.publish, { qos: 2 });
  await offline.client.subscribeAsync(topics.publish, { qos: 2 });
  const retainedUp = Buffer.from([0, 255, 24, 10]); const retainedDown = Buffer.from([255, 0, 11]);
  await device.client.publishAsync(topics.publish, retainedUp, { qos: 1, retain: true });
  await online.client.publishAsync(topics.subscribe, retainedDown, { qos: 1, retain: true });
  await until(() => online.messages.some(item => item.hex === retainedUp.toString('hex')) && device.messages.some(item => item.hex === retainedDown.toString('hex')), 'cross-node raw communication missing');
  await offline.client.endAsync(false);
  const queued = Buffer.from('old-pending-message');
  await device.client.publishAsync(topics.publish, queued, { qos: 1 });
  await phase('freeze');
  const snapshot = await call('GET', devicePath);
  const input = { id: id(), version: snapshot.version, target_tenant_id: target };
  let record = await call('POST', `${devicePath}/usage/reassign`, input, fixture.platform_token, '', 202);
  assert.equal(record.status, 'isolating'); assert.equal(record.requires_store, true);
  assert.equal((await call('POST', `${devicePath}/usage/reassign`, input, fixture.platform_token, '', 202)).id, record.id);
  const recordPath = `/admin/reassignments/${record.id}`;
  const targetPath = `/customer/tenants/${target}/reassignments/${record.id}`;
  await delay(250);
  record = await call('POST', recordPath, { action: 'advance', version: record.version });
  assert.equal(record.status, 'isolating');
  await call('POST', targetPath, { action: 'accept', id: id(), version: record.version }, targetToken, target, 409);
  await phase('restart', { id: record.id, device: raw.id });
  await until(() => device.client.stream.destroyed, 'old device socket survived interrupted node');
  await denied(registered.credential, {}, url);
  await denied(registered.credential, {}, otherUrl);
  record = await until(async () => {
    const current = (await call('GET', recordPath)).operation;
    const advanced = await call('POST', recordPath, { action: 'advance', version: current.version });
    return advanced.status === 'awaiting_acceptance' ? advanced : null;
  }, 'isolation never completed after real node restart', 50);
  assert.equal(record.retained.publish.state, 'absent'); assert.equal(record.retained.subscribe.state, 'absent');
  for (const app of apps) {
    await settled(app);
    const resumed = await join(app.credential);
    assert.equal(resumed.ack.sessionPresent, false);
    await assert.rejects(resumed.client.subscribeAsync(topics.publish, { qos: 1 }));
    assert.ok(!resumed.messages.some(item => item.hex === queued.toString('hex')));
    await resumed.client.endAsync(false);
  }
  const acceptance = { action: 'accept', id: id(), version: record.version };
  const accepted = await call('POST', targetPath, acceptance, targetToken, target);
  assert.equal(accepted.operation.status, 'completed'); assert.ok(accepted.credential);
  assert.equal((await call('POST', targetPath, acceptance, targetToken, target)).credential, null);
  const fresh = await join(accepted.credential, otherUrl);
  assert.equal(fresh.ack.sessionPresent, false);
  assert.notEqual(accepted.credential.topics.subscribe, topics.subscribe);
  await fresh.client.subscribeAsync(accepted.credential.topics.subscribe, { qos: 2 });
  await assert.rejects(fresh.client.subscribeAsync(topics.subscribe, { qos: 1 }));
  const targetApp = await call('POST', `/customer/tenants/${target}/applications`, { id: id(), name: '新客户应用' }, targetToken, target, 201);
  await settled(targetApp, target, targetToken);
  let receiver = await join(targetApp.credential);
  await assert.rejects(receiver.client.subscribeAsync(accepted.credential.topics.publish, { qos: 1 }));
  await receiver.client.endAsync(false);
  await call('POST', `/customer/tenants/${target}/applications/${targetApp.application.id}/devices/${raw.id}`, { version: 0,
    ownership_id: accepted.operation.new_ownership_id, receive: true, send: true, subscribe: true }, targetToken, target);
  await settled(targetApp, target, targetToken);
  receiver = await join(targetApp.credential);
  await receiver.client.subscribeAsync(accepted.credential.topics.publish, { qos: 2 });
  await phase('replay', { id: record.id });
  const binary = Buffer.from([17, 0, 128, 255]);
  const received = once(receiver.client, 'message', { signal: AbortSignal.timeout(10000) });
  await fresh.client.publishAsync(accepted.credential.topics.publish, binary, { qos: 2 });
  assert.deepEqual((await received)[1], binary);
  assert.ok(fresh.client.connected, 'late old intent closed new relationship');
  assert.ok(!fresh.messages.some(item => item.hex === retainedDown.toString('hex')));
  // 到期不读取设备或转配页面；等待原连接主动断开，再验证旧身份无法认证。
  const expiring = await call('GET', devicePath);
  await call('POST', `${devicePath}/usage/expiry`, { version: expiring.version, expires_at: Math.floor(Date.now() / 1000) + 3 });
  await until(() => fresh.client.stream.destroyed, 'expiry without page access did not isolate connection', 25);
  await denied(accepted.credential);
  const expired = await call('GET', devicePath);
  assert.equal(expired.usage.available, false); assert.notEqual(expired.usage.ended_at, null);
  return ['raw-bytes-two-node-communication', 'active-and-offline-application-grants-revoked', 'suspended-owner-prevents-handoff',
    'interrupted-owner-restart-resumes-isolation', 'both-retained-topics-confirmed-absent', 'old-credentials-denied-on-two-nodes',
    'target-confirms-once-new-session-no-inherited-grants', 'late-old-intent-does-not-close-new-session', 'usage-expiry-enforced-without-page-read'];
}
