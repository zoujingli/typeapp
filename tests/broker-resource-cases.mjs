import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { once } from 'node:events';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import { connect as connectTls } from 'node:tls';
import { setTimeout as delay } from 'node:timers/promises';

/** 真实协议慢消费者：只保留两个未确认标识，仍持续解码服务端确认和心跳。 */
async function slowResourceConsumer(credential) {
  let packets; let ca; let port;
  try {
    if (!process.env.TYPE_MQTT_CLIENT_ROOT || !process.env.TYPE_DEVICE_CA) throw new Error();
    packets = createRequire(resolve(process.env.TYPE_MQTT_CLIENT_ROOT, 'package.json'))('mqtt-packet');
    ca = readFileSync(process.env.TYPE_DEVICE_CA);
    port = Number(process.env.IOT_MQTT_PORT);
    if (!Number.isInteger(port) || port < 1 || port > 65535) throw new Error();
  } catch { throw new Error('resource_slow_fixture_invalid'); }
  const maximumBytes = 1048576;
  let parser; let socket;
  try {
    parser = packets.parser({ protocolVersion: 5 });
    socket = connectTls({ host: '127.0.0.1', port, ca, rejectUnauthorized: true, minVersion: 'TLSv1.2' });
  } catch { throw new Error('resource_slow_tls_setup_failed'); }
  const received = new Set();
  let input = Buffer.alloc(0); let pending; let failure; let closing = false;
  let heartbeat; let lifetime; let lastPing = 0; let pingAt = 0; let pongs = 0; let identifier = 0;
  function fail(reason) {
    if (failure || closing) return;
    failure = new Error(reason);
    clearInterval(heartbeat); clearTimeout(lifetime);
    if (pending) { const waiting = pending; pending = undefined; clearTimeout(waiting.timer); waiting.reject(failure); }
    input = Buffer.alloc(0); socket.destroy();
  }
  function write(packet) {
    if (failure || closing) return;
    try {
      const frame = packets.generate(packet, { protocolVersion: 5 });
      if (frame.length > maximumBytes || socket.writableLength + frame.length > maximumBytes) { fail('resource_slow_write_budget'); return; }
      socket.write(frame);
    } catch { fail('resource_slow_write_failed'); }
  }
  function request(packet, reply) {
    if (failure || closing) return Promise.reject(failure ?? new Error('resource_slow_closed'));
    if (pending) return Promise.reject(new Error('resource_slow_request_busy'));
    return new Promise((resolve, reject) => {
      pending = { reply, identifier: packet.messageId, resolve, reject,
        timer: setTimeout(() => fail('resource_slow_response_deadline'), 10000) };
      if (packet.cmd === 'connect') socket.once('secureConnect', () => write(packet));
      else write(packet);
    });
  }
  parser.on('error', () => fail('resource_slow_packet_invalid'));
  parser.on('packet', packet => {
    if (failure || closing) return;
    if (packet.cmd === 'publish') {
      if (packet.qos !== 1 || !Number.isInteger(packet.messageId) || packet.messageId < 1 || packet.messageId > 65535) { fail('resource_slow_delivery_invalid'); return; }
      received.add(packet.messageId);
      if (received.size > 2) fail('resource_slow_receive_window_exceeded');
      // 不发送 PUBACK，不保留载荷；接收处理继续，不能卡住后续 PINGRESP。
      return;
    }
    if (packet.cmd === 'pingresp') {
      if (pingAt === 0) { fail('resource_slow_ping_unexpected'); return; }
      pingAt = 0; pongs++; return;
    }
    if (!pending || packet.cmd !== pending.reply || packet.messageId !== pending.identifier) { fail('resource_slow_response_unexpected'); return; }
    if ((packet.cmd === 'connack' && packet.reasonCode !== 0)
      || (packet.cmd === 'puback' && (packet.reasonCode ?? 0) >= 0x80)
      || (packet.cmd === 'suback' && (packet.granted?.length !== 1 || packet.granted[0] !== 1))) { fail('resource_slow_request_rejected'); return; }
    const waiting = pending; pending = undefined; clearTimeout(waiting.timer); waiting.resolve(packet);
  });
  socket.on('error', () => fail('resource_slow_tls_failed'));
  socket.on('close', () => fail('resource_slow_connection_closed'));
  socket.on('data', chunk => {
    if (failure || closing) return;
    if (input.length + chunk.length > maximumBytes) { fail('resource_slow_frame_budget'); return; }
    input = Buffer.concat([input, chunk]);
    while (input.length >= 2 && !failure) {
      let length = 0; let header = 1; let multiplier = 1;
      for (;;) {
        if (header === input.length) return;
        const byte = input[header++]; length += (byte & 0x7f) * multiplier;
        if (!(byte & 0x80)) break;
        if (header === 5) { fail('resource_slow_frame_invalid'); return; }
        multiplier *= 128;
      }
      if (header + length > maximumBytes) { fail('resource_slow_frame_budget'); return; }
      if (input.length < header + length) return;
      const frame = input.subarray(0, header + length); input = input.subarray(header + length);
      try { parser.parse(frame); } catch { fail('resource_slow_packet_invalid'); }
    }
  });
  async function close() {
    closing = true; clearInterval(heartbeat); clearTimeout(lifetime); input = Buffer.alloc(0);
    if (pending) { const waiting = pending; pending = undefined; clearTimeout(waiting.timer); waiting.reject(new Error('resource_slow_closed')); }
    if (socket.closed) return;
    await new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error('resource_slow_close_deadline')), 1000);
      socket.once('close', () => { clearTimeout(timer); resolve(); });
      socket.destroy();
    });
  }
  try {
    lifetime = setTimeout(() => fail('resource_slow_lifetime_deadline'), 450000);
    await request({ cmd: 'connect', protocolId: 'MQTT', protocolVersion: 5, clean: false, keepalive: 30,
      clientId: credential.client_id, username: credential.username, password: Buffer.from(credential.password),
      properties: { sessionExpiryInterval: 86400, receiveMaximum: 2 } }, 'connack');
    if (failure) throw failure;
    lastPing = performance.now();
    heartbeat = setInterval(() => {
      const now = performance.now();
      if (pingAt !== 0 && now - pingAt >= 15000) { fail('resource_slow_ping_deadline'); return; }
      if (pingAt === 0 && now - lastPing >= 10000) { pingAt = lastPing = now; write({ cmd: 'pingreq' }); }
    }, 1000);
    return { close,
      check() {
        if (failure) throw failure;
        if (process.env.TYPE_BROKER_RESOURCE_DIST && pongs === 0) throw new Error('resource_slow_heartbeat_not_observed');
      },
      client: {
        subscribeAsync(topic) {
          if (++identifier > 65535) return Promise.reject(new Error('resource_slow_identifier_budget'));
          return request({ cmd: 'subscribe', qos: 1, messageId: identifier, subscriptions: [{ topic, qos: 1, nl: false, rap: false, rh: 0 }] }, 'suback');
        },
        publishAsync(topic, payload, options) {
          if (++identifier > 65535) return Promise.reject(new Error('resource_slow_identifier_budget'));
          return request({ cmd: 'publish', qos: 1, dup: false, retain: options.retain === true, messageId: identifier, topic, payload }, 'puback');
        },
      },
    };
  } catch { await close(); throw failure ?? new Error('resource_slow_connect_failed'); }
}

/** 复用现有真实TLS MQTT客户端、HTTP人员装置和生命周期；仅返回脱敏断言名称。 */
export async function brokerResourceCases(fixture, connect, start) {
  const { tenant_b: otherTenant, tokens } = fixture.broker_resources;
  const tenant = fixture.tenant;
  const origin = process.env.TYPE_DEVICE_HTTP;
  const checks = [];
  async function http(path, token = tokens.bob, currentTenant = tenant, status = 200, method = 'GET', data, support) {
    const response = await fetch(origin + path, { method, headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json',
      ...(currentTenant ? { 'X-Tenant-Id': currentTenant } : {}), ...(support ? { 'X-Support-Id': support } : {}) },
      ...(data ? { body: JSON.stringify(data) } : {}), signal: AbortSignal.timeout(15000) });
    assert.equal(response.status, status, `resource HTTP ${method} ${path.split('?')[0]} expected ${status}, got ${response.status}`);
    return response.json();
  }
  const path = `/customer/tenants/${tenant}/broker/resources`;
  const otherPath = `/customer/tenants/${otherTenant}/broker/resources`;
  const a = fixture.registration.credential;
  const b = fixture.second.credential;
  const product = (await http(`/customer/tenants/${otherTenant}/products`, tokens.bob, otherTenant, 201, 'POST', { name: 'Broker 跨租户产品' })).data;
  const modelPath = `/customer/tenants/${otherTenant}/products/${product.id}/models`;
  await http(modelPath, tokens.bob, otherTenant, 201, 'POST', { definition: { properties: [{ identifier: 'temperature', name: '温度', type: 'number', required: true }], events: [], commands: [] } });
  await http(`${modelPath}/1/publish`, tokens.bob, otherTenant, 200, 'POST', { version: 1 });
  const otherDevice = (await http(`/customer/tenants/${otherTenant}/devices`, tokens.bob, otherTenant, 201, 'POST', { name: '资源隔离设备', product_id: product.id, model_version: 1 })).data;
  const c = otherDevice.credential;
  const first = await connect(a);
  const second = await connect(b);
  const third = await connect(c);
  const secretPayload = `broker-resource-private-${randomBytes(20).toString('hex')}`;
  const service = { client_id: 'iot-ingestion-broker-resources', username: `service:ingestion:${process.env.IOT_INGESTION_CREDENTIAL_ID}`, password: process.env.TYPE_INGESTION_PASSWORD };
  const ingestion = await slowResourceConsumer(service);
  try {
    await first.client.subscribeAsync(a.topics.subscribe, { qos: 1 });
    await second.client.subscribeAsync(b.topics.subscribe, { qos: 1 });
    await third.client.subscribeAsync(c.topics.subscribe, { qos: 1 });
    await assert.rejects(first.client.subscribeAsync(`iot/${otherTenant}/#`, { qos: 1 }));
    await assert.rejects(first.client.subscribeAsync(`$share/cross/${c.topics.subscribe}`, { qos: 1 }));
    await assert.rejects(first.client.subscribeAsync('iot/+/devices/+/epochs/+/down', { qos: 1 }));
    await first.client.subscribeAsync(`$share/authorized/${a.topics.subscribe}`, { qos: 1 });
    await ingestion.client.subscribeAsync('$share/resource-workers/iot/+/devices/+/epochs/+/up', { qos: 1 });
    // 同一服务连接发送下行并等待真实 PUBACK，入站慢消费不阻塞出站交换。
    const down = once(second.client, 'message');
    await ingestion.client.publishAsync(b.topics.subscribe, secretPayload, { qos: 1, retain: true });
    assert.equal((await down)[0], b.topics.subscribe);
    await second.client.unsubscribeAsync(b.topics.subscribe);
    const retained = once(second.client, 'message');
    await second.client.subscribeAsync(b.topics.subscribe, { qos: 1 });
    assert.equal((await retained)[0], b.topics.subscribe);
    await first.client.publishAsync(a.topics.publish, secretPayload, { qos: 1, retain: true });
    await third.client.publishAsync(c.topics.publish, secretPayload, { qos: 1, retain: true });
    // 接收窗口已满，余下两租户消息必须以真实共享积压留存。
    await first.client.publishAsync(a.topics.publish, secretPayload, { qos: 1 });
    await third.client.publishAsync(c.topics.publish, secretPayload, { qos: 1 });
    checks.push('standard-tls-cross-tenant-wildcard-and-shared-refused-authorized-retained-replayed');

    function safe(body, foreign = true) {
      const encoded = JSON.stringify(body);
      for (const forbidden of [secretPayload, a.password, b.password, c.password, service.password, '"payload"', '"properties"', '"password"', 'secret_hash']) assert(!encoded.includes(forbidden));
      if (foreign) { assert(!encoded.includes(c.client_id)); assert(!encoded.includes(c.topics.publish)); }
    }
    async function list(resource, token = tokens.alice, scope = tenant, prefix = path, support) {
      let cursor = null;
      const items = [];
      for (let page = 0; page < 16; page++) {
        const result = await http(`${prefix}/${resource}?limit=1${cursor ? `&cursor=${encodeURIComponent(cursor)}` : ''}`, token, scope, 200, 'GET', undefined, support);
        assert(result.items.length <= 1);
        assert.equal(result.has_more, result.next_cursor !== null);
        safe(result, scope === tenant);
        items.push(...result.items);
        if (!result.has_more) { assert.equal(new Set(items.map(item => item.id)).size, items.length); return items; }
        assert.notEqual(result.next_cursor, cursor);
        cursor = result.next_cursor;
      }
      throw new Error('resource pagination exceeded bounded fixture');
    }
    const connections = await list('connections');
    assert.equal(connections.length, 2);
    assert(connections.every(item => item.connection_confirmed && item.resource_scope === `iot:${tenant}`));
    const sessions = await list('sessions');
    assert.equal(sessions.length, 2);
    assert(sessions.every(item => item.connection_confirmed === false && item.state === 'owner_claim'));
    const subscriptions = await list('subscriptions');
    assert.equal(subscriptions.length, 3);
    assert(subscriptions.some(item => item.shared));
    const retainedItems = await list('retained');
    assert.deepEqual(retainedItems.map(item => item.topic).sort(), [a.topics.publish, b.topics.subscribe].sort());
    const backlog = await list('backlog');
    assert(backlog.length > 0);
    assert(backlog.some(item => item.shared));
    assert(backlog.every(item => item.topic.startsWith(`iot/${tenant}/`)));
    const nodes = await list('nodes');
    assert.equal(nodes.length, 1); assert.equal(nodes[0].connections, 2);
    for (const [kind, items] of [['connections', connections], ['sessions', sessions], ['subscriptions', subscriptions], ['retained', retainedItems], ['backlog', backlog], ['nodes', nodes]]) {
      const result = await http(`${path}/${kind}/${encodeURIComponent(items[0].id)}`, tokens.alice);
      assert(result.found); safe(result);
      await http(`${otherPath}/${kind}/${encodeURIComponent(items[0].id)}`, tokens.bob, otherTenant, kind === 'nodes' ? 200 : 404);
    }
    const otherConnections = await list('connections', tokens.bob, otherTenant, otherPath);
    assert.equal(otherConnections.length, 1); assert.equal(otherConnections[0].client_id, c.client_id);
    const platformConnections = await list('connections', tokens.platform, null, '/admin/broker/resources');
    assert.equal(platformConnections.length, 4);
    checks.push('real-connections-sessions-subscriptions-retained-shared-backlog-stable-pagination-and-scoped-details');
    await http(`${path}/connections`, tokens.platform, tenant, 401);
    await http(`${path}/connections`, tokens.outsider, tenant, 403);
    await http('/admin/broker/resources/connections', tokens.alice, null, 401);
    await http(`${path}/connections`, tokens.alice, otherTenant, 403);
    await http(`${path}/connections?limit=101`, tokens.alice, tenant, 400);
    await http(`${path}/connections?limit=1&limit=2`, tokens.alice, tenant, 400);
    await http(`${path}/connections?client.id=invalid`, tokens.alice, tenant, 400);
    await http(`${path}/connections?${'limit=1&'.repeat(1001)}`, tokens.alice, tenant, 400);
    await http(`${path}/connections?cursor=not-base64`, tokens.alice, tenant, 400);
    await http(`${path}/sessions/${'a'.repeat(64)}`, tokens.alice, tenant, 400);
    await http(`${path}/subscriptions/${'a'.repeat(32)}`, tokens.alice, tenant, 400);
    await http(`${path}/backlog/${'a'.repeat(32)}`, tokens.alice, tenant, 400);
    const pageOne = await http(`${path}/connections?limit=1`, tokens.alice);
    assert(pageOne.next_cursor);
    await http(`${otherPath}/connections?limit=1&cursor=${encodeURIComponent(pageOne.next_cursor)}`, tokens.bob, otherTenant, 400);
    await http(`${path}/connections?limit=1&state=connected&cursor=${encodeURIComponent(pageOne.next_cursor)}`, tokens.alice, tenant, 400);
    const simulated = fixture.simulated.accessToken;
    assert.equal((await list('connections', simulated)).length, 2);
    await http('/admin/broker/resources/connections', simulated, null, 401);
    await http(`${path}/connections?limit=1&cursor=${encodeURIComponent(pageOne.next_cursor)}`, simulated, tenant, 400);
    await http(`${path}/connections`, simulated, tenant, 403, 'GET', undefined, randomBytes(16).toString('hex'));
    assert.equal((await list('connections', simulated, otherTenant, otherPath)).length, 1);
    const simulationRead = await http(`${path}/connections/${connections[0].id}`, simulated);
    safe(simulationRead);
    const audits = await http(`/customer/tenants/${tenant}/broker/audit?action=broker.resource.read&subject_id=${connections[0].id}`);
    const event = audits.items.find(item => item.details.impersonation_id === fixture.simulated.identity.impersonation_id);
    assert(event);
    const detail = (await http(`/customer/tenants/${tenant}/broker/audit/${event.id}`)).item;
    assert.equal(detail.actor_id, fixture.source.identity.actor_id);
    assert.equal(detail.operation.identity.customer_id, fixture.simulated.identity.customer_id);
    assert.equal(detail.operation.identity.source_session_id, fixture.source.identity.session_id);
    assert.deepEqual((await http(`/admin/broker/audit/customer/${event.id}`, tokens.platform, null)).item, detail);
    checks.push('new-rbac-platform-metadata-exact-simulation-source-and-cursor-revalidation');
    await first.client.unsubscribeAsync(`$share/authorized/${a.topics.subscribe}`);
    assert.equal((await list('subscriptions')).length, 2);
    const oldOwner = connections.find(item => item.client_id === a.client_id).id;
    const closed = once(first.client, 'close');
    let takeover = start(a);
    await once(takeover, 'connect'); await closed;
    let current;
    for (let retry = 0; retry < 30; retry++) {
      current = await http(`${path}/connections?client_id=${a.client_id}`);
      if (current.items.length === 1 && current.items[0].id !== oldOwner) break;
      await delay(50);
    }
    assert.equal(current.items.length, 1); assert.notEqual(current.items[0].id, oldOwner);
    await http(`${path}/connections/${oldOwner}`, tokens.alice, tenant, 404);
    checks.push('subscription-removal-and-same-client-takeover-preserve-exact-live-owner');
    // 标准最大过滤器仍沿短设备 Topic 授权；第二组覆盖引号、反斜杠和 PHP JSON 的 Unicode 转义膨胀。
    const groupBytes = 65535 - Buffer.byteLength(`$share//${a.topics.subscribe}`);
    const escapedUnit = '"\\é';
    const escapedGroup = escapedUnit.repeat(Math.floor(groupBytes / Buffer.byteLength(escapedUnit)))
      + 'x'.repeat(groupBytes % Buffer.byteLength(escapedUnit));
    const longFilters = ['a'.repeat(groupBytes), escapedGroup].map(group => `$share/${group}/${a.topics.subscribe}`);
    assert(longFilters.every(filter => Buffer.byteLength(filter) === 65535));
    assert(Buffer.byteLength(JSON.stringify(longFilters[1])) > 65535);
    async function longSubscriptions() {
      const items = (await list('subscriptions')).filter(item => item.filter_bytes === 65535);
      assert.equal(items.length, longFilters.length);
      const actual = [];
      for (const item of items) {
        assert.equal(item.client_id, a.client_id); assert.equal(item.resource_scope, `iot:${tenant}`);
        assert.equal(item.durable, true); assert.equal(item.shared, true); assert.equal(item.qos, 1);
        const detail = await http(`${path}/subscriptions/${item.id}`, tokens.alice);
        assert(detail.found); safe(detail);
        assert.equal(detail.item.filter_bytes, 65535);
        assert(longFilters.includes(detail.item.filter), '标准长共享订阅详情必须保留完整过滤器');
        actual.push(detail.item.filter);
      }
      assert.equal(new Set(actual).size, longFilters.length);
      return items;
    }
    async function ordinaryTraffic(stage) {
      const payload = `${stage}-${secretPayload}`;
      const received = once(second.client, 'message', { signal: AbortSignal.timeout(10000) });
      const [, [topic, bytes]] = await Promise.all([
        ingestion.client.publishAsync(b.topics.subscribe, payload, { qos: 1, retain: false }), received,
      ]);
      assert.equal(topic, b.topics.subscribe); assert.deepEqual(bytes, Buffer.from(payload));
      await second.client.publishAsync(b.topics.publish, payload, { qos: 1 });
      assert(takeover.connected && second.client.connected && third.client.connected, '长共享订阅操作不能停止 Broker 或断开其他设备');
    }
    for (const filter of longFilters) await takeover.subscribeAsync(filter, { qos: 1 });
    const longItems = await longSubscriptions();
    await ordinaryTraffic('long-subscribed');
    await takeover.endAsync(false);
    const resumed = await connect(a);
    assert.equal(resumed.ack.sessionPresent, true);
    takeover = resumed.client;
    const restoredItems = await longSubscriptions();
    assert.deepEqual(restoredItems.map(item => [item.id, item.session_id]), longItems.map(item => [item.id, item.session_id]));
    await ordinaryTraffic('long-restored');
    for (const filter of longFilters) await takeover.unsubscribeAsync(filter);
    assert.equal((await list('subscriptions')).length, 2);
    for (const item of longItems) await http(`${path}/subscriptions/${item.id}`, tokens.alice, tenant, 404);
    await ordinaryTraffic('long-unsubscribed');
    checks.push('standard-65535-byte-shared-filters-json-expansion-resume-and-unsubscribe-preserve-other-client-traffic');
    if (process.env.TYPE_BROKER_RESOURCE_DIST) {
      // 浏览器的20条默认页需要真实跨页数据，使用同一设备已获准Topic上的独立共享组。
      const browserFilters = Array.from({ length: 22 }, (_, index) => `$share/browser-${index === 0 ? 'long-group-'.repeat(12) : index}/${a.topics.subscribe}`);
      for (const filter of browserFilters) await takeover.subscribeAsync(filter, { qos: 1 });
      fixture.broker_resources.browser = { long_filter: browserFilters[0] };
      const { brokerResourceBrowser } = await import('./broker-resources-browser.mjs');
      // 这些订阅属于本轮专用数据库；外层关闭全部客户端并回收数据库。
      // 浏览器失败时不能再等待可能已经断开的客户端确认，掩盖原始错误。
      checks.push(...await brokerResourceBrowser({ origin, dist: process.env.TYPE_BROKER_RESOURCE_DIST, base: process.env.APP_BASE_PATH, fixture }));
      assert(takeover.connected && second.client.connected && third.client.connected, '浏览器验收期间真实设备连接保持在线');
    }
    ingestion.check();
    return checks;
  } finally { await ingestion.close(); }
}
