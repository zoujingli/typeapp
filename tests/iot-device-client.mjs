import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { closeSync, existsSync, fsyncSync, openSync, readFileSync, writeSync } from 'node:fs';
import { randomUUID } from 'node:crypto';
import { once } from 'node:events';
import { setTimeout as delay } from 'node:timers/promises';

const require = createRequire(`${process.env.TYPE_MQTT_CLIENT_ROOT}/package.json`);
const mqtt = require('mqtt');
assert.equal(require('mqtt/package.json').version, '5.15.0');
const fixture = JSON.parse(process.env.TYPE_DEVICE_FIXTURE);
const a = fixture.registration.credential;
const b = fixture.second.credential;
const url = `mqtts://127.0.0.1:${process.env.IOT_MQTT_PORT}`;
const ca = readFileSync(process.env.TYPE_DEVICE_CA);
const clients = [];
const checks = [];
const headers = { Authorization: `Bearer ${fixture.token}`, 'X-Tenant-Id': fixture.tenant };

async function read(path = fixture.path) {
  const response = await fetch(process.env.TYPE_DEVICE_HTTP + path, { headers, signal: AbortSignal.timeout(5000) });
  assert.equal(response.status, 200);
  return response.json();
}
async function observation(status, id = a.client_id, seconds = 8) {
  const until = Date.now() + seconds * 1000;
  do {
    const { data } = await read(`${fixture.path.slice(0, fixture.path.lastIndexOf('/'))}/${id}`);
    if (data.connection.status === status) return data;
    await delay(100);
  } while (Date.now() < until);
  throw new Error(`connection observation did not become ${status}`);
}
function start(credential = a, overrides = {}, endpoint = url) {
  const client = mqtt.connect(endpoint, {
    protocolVersion: 5, clientId: credential.client_id, username: credential.username, password: credential.password,
    keepalive: 30, clean: false, reconnectPeriod: 0, connectTimeout: 10000, ca, rejectUnauthorized: true,
    properties: { sessionExpiryInterval: 86400 }, ...overrides,
  });
  client.on('error', () => {});
  clients.push(client);
  return client;
}
async function connect(credential = a, overrides = {}) {
  const client = start(credential, overrides);
  const [ack] = await once(client, 'connect');
  return { client, ack };
}
async function denied(credential = a, overrides = {}, endpoint = url, reason = null) {
  const client = start(credential, overrides, endpoint);
  let accepted = false;
  let refusal = null;
  client.on('connect', () => { accepted = true; });
  client.on('packetreceive', packet => { if (packet.cmd === 'connack') refusal = packet.reasonCode; });
  await new Promise((resolve, reject) => {
    const timeout = setTimeout(() => reject(new Error('refusal exceeded deadline')), 12000);
    client.once('close', () => { clearTimeout(timeout); resolve(); });
  });
  assert.equal(accepted, false);
  if (reason !== null) assert.equal(refusal, reason);
  await client.endAsync(true);
}
async function finish(client) {
  await client.endAsync(false);
  await observation('offline');
}

try {
  const mode = process.argv[2];
  if (mode === 'broker-resources') {
    const { brokerResourceCases } = await import('./broker-resource-cases.mjs');
    checks.push(...await brokerResourceCases(fixture, connect, start));
  } else if (mode === 'capacity') {
    const device = await connect();
    await observation('online');
    await denied(b, {}, url, 0x97);
    const service = { client_id: 'iot-ingestion-capacity', username: `service:ingestion:${process.env.IOT_INGESTION_CREDENTIAL_ID}`, password: process.env.TYPE_INGESTION_PASSWORD };
    await denied(b, { clientId: service.client_id, username: service.username });
    assert.equal((await observation('unknown', b.client_id)).lifecycle, 'inactive');
    const consumer = await connect(service);
    await denied(service, { clientId: 'iot-ingestion-overflow' }, url, 0x97);
    const taken = once(device.client, 'close');
    const takeover = await connect();
    assert.equal(takeover.ack.sessionPresent, true);
    await taken;
    await takeover.client.subscribeAsync(a.topics.subscribe, { qos: 1 });
    await finish(takeover.client);
    await consumer.client.publishAsync(a.topics.subscribe, Buffer.from('accepted-capacity\0binary'), { qos: 1 });
    const refused = once(consumer.client, 'disconnect');
    const closed = once(consumer.client, 'close');
    consumer.client.publish(a.topics.subscribe, 'must-not-replace', { qos: 1 }, () => {});
    const [refusal] = await refused;
    assert.equal(refusal.reasonCode, 0x97);
    await closed;
    await consumer.client.endAsync(true);
    checks.push('authenticated-service-reserve-device-overflow-and-service-overflow-standard-quota');
    checks.push('same-client-takeover-reuses-classification-and-offline-device-queue-preserves-first-message');
  } else if (mode === 'capacity-resume') {
    await denied(b, {}, url, 0x97);
    const device = start();
    const delivery = once(device, 'message');
    const [ack] = await once(device, 'connect');
    assert.equal(ack.sessionPresent, true);
    const [topic, payload] = await delivery;
    assert.equal(topic, a.topics.subscribe);
    assert.deepEqual(payload, Buffer.from('accepted-capacity\0binary'));
    await finish(device);
    checks.push('persistent-session-quota-after-restart-preserves-resume-and-original-binary-delivery');
  } else if (mode === 'normal') {
    assert.equal((await observation('unknown')).lifecycle, 'inactive');
    await denied(a, { password: 'wrong-secret' });
    await denied(b, { clientId: a.client_id });
    await denied(a, { clientId: 'f'.repeat(32) });
    await denied(a, { protocolVersion: 4, properties: undefined });
    await denied(a, { keepalive: 31 });
    await denied(a, { properties: { sessionExpiryInterval: 0 } });
    await denied(a, {}, `mqtt://127.0.0.1:${process.env.IOT_MQTT_PORT}`);
    await denied(a, { ca: undefined });
    assert.equal((await observation('unknown')).lifecycle, 'inactive');
    checks.push('wrong-cross-device-unknown-protocol-session-contract-plaintext-untrusted-tls-denied-without-activation');
    await denied(b, { properties: { sessionExpiryInterval: 86400, maximumPacketSize: 1 } });
    assert.equal((await observation('unknown', b.client_id)).lifecycle, 'inactive');
    checks.push('failed-connack-not-online-or-activated');
    const { client, ack } = await connect();
    assert.equal(ack.reasonCode, 0);
    const online = await observation('online');
    assert.equal(online.lifecycle, 'enabled');
    assert.equal(typeof online.connection.observed_at, 'number');
    assert.equal(typeof online.connection.broker_observed_at, 'number');
    const list = await read(fixture.path.slice(0, fixture.path.lastIndexOf('/')));
    assert.equal(list.items.find(item => item.id === a.client_id).connection.status, 'online');
    assert.equal((await client.subscribeAsync(a.topics.subscribe, { qos: 1 }))[0].qos, 1);
    await client.publishAsync(a.topics.publish, JSON.stringify({ type: 'device-test' }), { qos: 1 });
    await assert.rejects(client.subscribeAsync(b.topics.subscribe, { qos: 1 }));
    await assert.rejects(client.subscribeAsync(a.topics.publish, { qos: 1 }));
    for (const filter of ['iot/#', a.topics.subscribe.replace('/down', '/+'), `$share/device/iot/#`, `$share/device/${b.topics.subscribe}`]) {
      await assert.rejects(client.subscribeAsync(filter, { qos: 1 }));
    }
    assert.equal((await client.subscribeAsync(`$share/device/${a.topics.subscribe}`, { qos: 1 }))[0].qos, 1);
    await client.unsubscribeAsync(`$share/device/${a.topics.subscribe}`);
    checks.push('standard-mqtt5-tls-own-topics-qos1-http-online-and-cross-device-subscribe-denied');
    await finish(client);
    const resumed = await connect();
    assert.equal(resumed.ack.sessionPresent, true);
    await observation('online');
    const taken = new Promise(resolve => resumed.client.once('close', resolve));
    const takeover = await connect();
    await taken;
    await observation('online');
    await delay(400);
    assert.equal((await read()).data.connection.status, 'online');
    const refused = new Promise(resolve => takeover.client.once('close', resolve));
    takeover.client.publish(b.topics.publish, 'forbidden', { qos: 1 }, () => {});
    await refused;
    await observation('offline');
    checks.push('normal-close-session-resume-takeover-old-close-isolated-cross-device-publish-denied');
  } else if (mode === 'command-loss' || mode === 'command-recover') {
    // 参考客户端故意丢弃执行回执；先fsync动作与原结果，父进程随后SIGKILL，下一独立进程读取同一账本。
    const { client } = await connect(a);
    const messages = [];
    client.on('message', (topic, bytes) => { assert.equal(topic, a.topics.subscribe); messages.push(JSON.parse(bytes.toString())); });
    await client.subscribeAsync(a.topics.subscribe, { qos: 1 }); await observation('online');
    const ledgerPath = process.env.TYPE_COMMAND_LEDGER;
    assert.ok(ledgerPath);
    if (mode === 'command-loss') {
      const device = (await read()).data;
      const response = await fetch(process.env.TYPE_DEVICE_HTTP + fixture.path + '/commands', {
        method: 'POST', headers: { ...headers, Authorization: `Bearer ${fixture.simulated.accessToken}`, 'Content-Type': 'application/json' },
        body: JSON.stringify({ command_id: randomUUID().replaceAll('-', ''), identifier: 'switch', values: { on: true }, version: device.version }), signal: AbortSignal.timeout(5000),
      });
      assert.equal(response.status, 201); const record = (await response.json()).data;
      let envelope; const until = Date.now() + 20000;
      while (!envelope && Date.now() < until) { envelope = messages.find(item => item.type === 'command' && item.command_id === record.id); if (!envelope) await delay(30); }
      assert.ok(envelope, 'first command missing'); assert.equal(envelope.deadline_at, record.deadline_at); assert.ok(Date.now() / 1000 < envelope.deadline_at);
      const receipt = { app_version: 1, type: 'command_receipt', device_id: a.client_id, ownership_id: fixture.registration.device.ownership_id,
        command_id: record.id, content_hash: record.content_hash, status: 'succeeded', code: 'executed', started_at: envelope.issued_at,
        finished_at: envelope.issued_at + 1, result: { action_count: 1 } };
      const fd = openSync(ledgerPath, 'wx', 0o600);
      try { writeSync(fd, JSON.stringify({ envelope, receipt, action_count: 1 })); fsyncSync(fd); } finally { closeSync(fd); }
      console.log('command-ready');
      await delay(30000); throw new Error('fixture did not kill device process');
    }
    const ledger = JSON.parse(readFileSync(ledgerPath, 'utf8')); let queries = 0; let duplicateCommands = 0; let acknowledged = false;
    const until = ledger.envelope.deadline_at * 1000 + 30000;
    while (!acknowledged && Date.now() < until) {
      const envelope = messages.shift();
      if (!envelope) { await delay(30); continue; }
      if (envelope.type === 'command') {
        assert.deepEqual(envelope, ledger.envelope); duplicateCommands++; assert.equal(ledger.action_count, 1);
        // 原执行回执继续保持丢失，只有查询原结果才可使平台完成对账。
      } else if (envelope.type === 'command_query' && envelope.command_id === ledger.envelope.command_id) {
        queries++; assert.equal(envelope.content_hash, ledger.receipt.content_hash);
        await client.publishAsync(a.topics.publish, JSON.stringify({ ...envelope, type: 'command_query_result', receipt: ledger.receipt }), { qos: 1 });
      } else if (envelope.type === 'command_receipt_ack' && envelope.command_id === ledger.envelope.command_id) { acknowledged = true; }
    }
    assert.equal(acknowledged, true, 'result query did not receive durable business acknowledgement'); assert.equal(queries, 1); assert.equal(ledger.action_count, 1);
    const records = (await read(fixture.path + '/commands')).items; const record = records.find(item => item.id === ledger.envelope.command_id);
    assert.equal(record.execution, 'succeeded'); assert.equal(record.result.action_count, 1); assert.equal(record.deadline_at, ledger.envelope.deadline_at);
    const sends = record.timeline.items.filter(item => item.kind === 'send' && item.claimed_at !== null);
    assert.ok(sends.length >= 1 && sends.length <= 3); assert.equal(record.timeline.items.filter(item => item.response_code === 'result_found').length, 1);
    assert.ok(record.result_received_at >= record.deadline_at); await finish(client);
    checks.push({ case: 'real-tls-lost-receipt-process-kill-persistent-once-duplicate-and-60s-query-late-result', duplicate_commands: duplicateCommands, sends: sends.length, queries, action_count: ledger.action_count });
  } else if (mode === 'ha-pending') {
    let held = false;
    const client = start(b, { customHandleAcks: (topic, bytes, packet, acknowledge) => {
      const payload = JSON.parse(bytes.toString());
      if (payload.type !== 'ha_queue') { acknowledge(null, 0); return; }
      assert.equal(topic, b.topics.subscribe);
      assert.equal(packet.qos, 1);
      const descriptor = openSync(process.env.TYPE_HA_PENDING, 'w', 0o600);
      try { writeSync(descriptor, JSON.stringify({ packet_id: packet.messageId, payload })); fsyncSync(descriptor); } finally { closeSync(descriptor); }
      held = true;
      console.log('pending');
      // 刻意不调用acknowledge；保留真实已发送、未PUBACK的QoS 1交换，供主切换恢复。
    } });
    const [ack] = await once(client, 'connect');
    assert.equal(ack.sessionPresent, true);
    const deadline = new AbortController();
    try {
      await Promise.race([once(client, 'close'), delay(85000, undefined, { signal: deadline.signal }).then(() => { throw new Error('HA pending connection did not close'); })]);
    } finally { deadline.abort(); }
    assert.equal(held, true);
    checks.push('qos1-packet-delivered-without-puback-before-primary-loss');
  } else if (mode === 'ha-step') {
    const stage = Number(process.env.TYPE_HA_STAGE);
    const ledgerPath = process.env.TYPE_HA_LEDGER;
    const ledger = existsSync(ledgerPath) ? JSON.parse(readFileSync(ledgerPath, 'utf8')) : [];
    const messages = [];
    const packets = [];
    const client = start(b);
    client.on('message', (topic, bytes, packet) => { assert.equal(topic, b.topics.subscribe); messages.push(JSON.parse(bytes.toString())); packets.push(packet); });
    const [ack] = await once(client, 'connect');
    if (stage > 1) assert.equal(ack.sessionPresent, true);
    if (stage === 1) await client.subscribeAsync(b.topics.subscribe, { qos: 1 });
    if (stage === 2) {
      const until = Date.now() + 12000;
      while (!messages.some(item => item.type === 'ha_queue') && Date.now() < until) await delay(30);
      const index = messages.findIndex(item => item.type === 'ha_queue');
      assert.ok(index >= 0, 'persistent QoS1 packet missing after promotion');
      const pending = JSON.parse(readFileSync(process.env.TYPE_HA_PENDING, 'utf8'));
      assert.deepEqual(messages[index], pending.payload);
      assert.equal(packets[index].messageId, pending.packet_id);
      assert.equal(packets[index].dup, true);
    }
    async function accepted(bytes) {
      const sequence = JSON.parse(bytes).sequence;
      const count = messages.length;
      const deadline = new AbortController();
      try {
        await Promise.race([client.publishAsync(b.topics.publish, bytes, { qos: 1 }),
          delay(15000, undefined, { signal: deadline.signal }).then(() => { throw new Error('HA publish deadline'); })]);
      } finally { deadline.abort(); }
      const until = Date.now() + 15000;
      while (!messages.slice(count).some(item => item.type === 'ingestion_receipt' && item.sequence === sequence) && Date.now() < until) await delay(30);
      const receipt = messages.slice(count).find(item => item.type === 'ingestion_receipt' && item.sequence === sequence);
      assert.ok(receipt, 'HA business receipt missing');
      assert.equal(receipt.status, 'accepted');
      assert.equal(receipt.code, 'accepted');
      assert.equal(receipt.device_id, b.client_id);
      assert.equal(receipt.ownership_id, fixture.second.device.ownership_id);
      return receipt;
    }
    if (stage > 1) assert.deepEqual(await accepted(ledger[0].bytes), ledger[0].receipt);
    const bytes = JSON.stringify({ app_version: 1, type: 'telemetry', device_id: b.client_id, ownership_id: fixture.second.device.ownership_id,
      model_version: 1, sequence: String(1000 + stage), sampled_at: Math.floor(Date.now() / 1000), values: { temperature: 20 + stage } });
    ledger.push({ bytes, receipt: await accepted(bytes) });
    const descriptor = openSync(ledgerPath, 'w', 0o600);
    try { writeSync(descriptor, JSON.stringify(ledger)); fsyncSync(descriptor); } finally { closeSync(descriptor); }
    const current = (await read(`${fixture.path.slice(0, fixture.path.lastIndexOf('/'))}/${b.client_id}/current`)).data;
    assert.equal(current.sequence, String(1000 + stage));
    assert.equal(current.fields[0].value, 20 + stage);
    await client.endAsync(false);
    if (stage === 1) {
      await observation('offline', b.client_id);
      const service = { client_id: 'iot-ingestion-ha-probe', username: `service:ingestion:${process.env.IOT_INGESTION_CREDENTIAL_ID}`, password: process.env.TYPE_INGESTION_PASSWORD };
      const sender = await connect(service);
      await sender.client.publishAsync(b.topics.subscribe, JSON.stringify({ type: 'ha_queue', identity: randomUUID() }), { qos: 1 });
      await sender.client.endAsync(false);
    }
    checks.push(`stage-${stage}-persistent-session-original-receipt-current-http-and-new-confirmed-message`);
  } else if (mode === 'ha-no-sync') {
    const { client } = await connect(b);
    let successfulPubacks = 0;
    const receipts = [];
    client.on('message', (_topic, bytes) => receipts.push(JSON.parse(bytes.toString())));
    client.on('packetreceive', packet => { if (packet.cmd === 'puback' && (packet.reasonCode ?? 0) < 0x80) successfulPubacks++; });
    console.log('connected');
    const until = Date.now() + 50000;
    while (!existsSync(process.env.TYPE_HA_GATE) && Date.now() < until) await delay(20);
    assert.ok(existsSync(process.env.TYPE_HA_GATE));
    client.publish(b.topics.publish, JSON.stringify({ app_version: 1, type: 'telemetry', device_id: b.client_id,
      ownership_id: fixture.second.device.ownership_id, model_version: 1, sequence: '1999', sampled_at: Math.floor(Date.now() / 1000), values: { temperature: 99 } }), { qos: 1 }, () => {});
    await delay(7000);
    assert.equal(successfulPubacks, 0);
    assert.equal(receipts.filter(item => item.sequence === '1999').length, 0);
    await client.endAsync(true);
    checks.push('no-successful-puback-or-business-receipt-without-synchronous-standby');
  } else if (mode === 'ingestion') {
    const service = { client_id: 'iot-ingestion-probe', username: `service:ingestion:${process.env.IOT_INGESTION_CREDENTIAL_ID}`, password: process.env.TYPE_INGESTION_PASSWORD };
    await denied(service, { password: b.password });
    await denied(b, { username: service.username });
    await denied(service, { clientId: b.client_id });
    const serviceConnection = await connect(service);
    await assert.rejects(serviceConnection.client.subscribeAsync('iot/#', { qos: 1 }));
    await assert.rejects(serviceConnection.client.subscribeAsync(b.topics.subscribe, { qos: 1 }));
    await serviceConnection.client.endAsync(false);
    assert.equal((await observation('unknown', b.client_id)).lifecycle, 'inactive');
    checks.push('independent-service-credential-scope-without-device-activation');
    const { client } = await connect(b);
    await observation('online', b.client_id);
    await client.subscribeAsync(b.topics.subscribe, { qos: 1 });
    const received = [];
    client.on('message', (topic, payload) => {
      assert.equal(topic, b.topics.subscribe);
      received.push(JSON.parse(payload.toString()));
    });
    const payload = (sequence, temperature = 21, extra = {}) => JSON.stringify({ app_version: 1, type: 'telemetry', device_id: b.client_id,
      ownership_id: fixture.second.device.ownership_id, model_version: 1, sequence, sampled_at: Math.floor(Date.now() / 1000), values: { temperature }, ...extra });
    async function send(bytes, status = 'accepted', code = 'accepted', qos = 1) {
      const count = received.length;
      const publishAbort = new AbortController();
      try {
        await Promise.race([client.publishAsync(b.topics.publish, bytes, { qos }),
          once(client, 'close', { signal: publishAbort.signal }).then(() => { throw new Error('ingestion device closed during publish'); }),
          delay(15000, undefined, { signal: publishAbort.signal }).then(() => { throw new Error('ingestion publish exceeded deadline'); })]);
      } finally { publishAbort.abort(); }
      const until = Date.now() + 25000;
      while (received.length === count && Date.now() < until) await delay(30);
      assert.equal(received.length, count + 1, 'missing or duplicate business receipt');
      const receipt = received[count];
      const input = JSON.parse(bytes);
      assert.equal(receipt.app_version, 1);
      assert.equal(receipt.type, 'ingestion_receipt');
      assert.equal(receipt.device_id, b.client_id);
      assert.equal(receipt.ownership_id, input.ownership_id);
      assert.equal(receipt.sequence, input.sequence);
      assert.match(receipt.content_hash, /^[a-f0-9]{64}$/);
      assert.equal(receipt.status, status);
      assert.equal(receipt.code, code);
      return receipt;
    }
    const first = payload('1');
    const accepted = await send(first);
    // 设备未依据第一次回执清除缓存；原字节重传必须补发原首次接收结果。
    for (let replay = 0; replay < 8; replay++) {
      assert.deepEqual(await send(first), accepted);
    }
    // 仅改顶层顺序，不用JSON replacer裁掉嵌套values。
    const reordered = JSON.stringify(Object.fromEntries(Object.entries(JSON.parse(first)).reverse()));
    assert.deepEqual(await send(reordered), accepted);
    await send(payload('1', 99), 'rejected', 'content_conflict');
    const largest = payload('3', 23);
    await send(largest + ' '.repeat(16384 - Buffer.byteLength(largest)));
    await send(payload('2', 22));
    let current = (await read(`${fixture.path.slice(0, fixture.path.lastIndexOf('/'))}/${b.client_id}/current`)).data;
    assert.equal(current.sequence, '3');
    assert.equal(current.fields[0].value, 23);
    await send(payload('4', 1, { model_version: 2 }), 'rejected', 'model_mismatch');
    await send(payload('5', 1, { sampled_at: Math.floor(Date.now() / 1000) - 172900 }), 'rejected', 'sample_expired');
    await send(payload('6', 1, { sampled_at: Math.floor(Date.now() / 1000) + 60 }), 'rejected', 'clock_ahead');
    await send(payload('7'), 'rejected', 'invalid_envelope', 0);
    current = (await read(`${fixture.path.slice(0, fixture.path.lastIndexOf('/'))}/${b.client_id}/current`)).data;
    assert.equal(current.sequence, '3');
    assert.equal(current.last_receipt.status, 'rejected');
    await send(payload('8', 0, { type: 'event', identifier: 'fault', values: { code: 42 } }));
    assert.equal((await read(`${fixture.path.slice(0, fixture.path.lastIndexOf('/'))}/${b.client_id}/current`)).data.sequence, '3');
    const endAbort = new AbortController();
    try {
      await Promise.race([client.endAsync(false), delay(12000, undefined, { signal: endAbort.signal }).then(() => {
        throw new Error(`ingestion graceful close exceeded deadline connected=${client.connected} outgoing=${Object.keys(client.outgoing).length}`);
      })]);
    } finally { endAbort.abort(); }
    checks.push('synchronous-ingestion-business-receipt-duplicate-conflict-reorder-16k-current-http-permanent-rejections');
  } else if (mode === 'ingestion-fault') {
    const { client } = await connect(b);
    await observation('online', b.client_id);
    await client.subscribeAsync(b.topics.subscribe, { qos: 1 });
    const receipts = [];
    client.on('message', (_topic, payload) => receipts.push(JSON.parse(payload.toString())));
    console.log('connected');
    const until = Date.now() + 15000;
    while (!existsSync(process.env.TYPE_INGESTION_GATE) && Date.now() < until) await delay(20);
    assert.ok(existsSync(process.env.TYPE_INGESTION_GATE));
    await client.publishAsync(b.topics.publish, JSON.stringify({ app_version: 1, type: 'telemetry', device_id: b.client_id,
      ownership_id: fixture.second.device.ownership_id, model_version: 1, sequence: '20', sampled_at: Math.floor(Date.now() / 1000), values: { temperature: 25 } }), { qos: 1 });
    console.log('published');
    await delay(6500);
    assert.equal(receipts.length, 0);
    await client.endAsync(true);
    checks.push('no-receipt');
  } else if (mode === 'ingestion-recover') {
    const receipts = [];
    const client = start(b);
    client.on('message', (_topic, payload) => receipts.push(JSON.parse(payload.toString())));
    await once(client, 'connect');
    const until = Date.now() + 20000;
    while (!receipts.some(receipt => receipt.sequence === '20') && Date.now() < until) await delay(50);
    const receipt = receipts.find(receipt => receipt.sequence === '20');
    assert.ok(receipt);
    assert.equal(receipt.status, 'accepted');
    assert.equal(receipt.device_id, b.client_id);
    assert.equal(receipt.ownership_id, fixture.second.device.ownership_id);
    await client.endAsync(false);
    checks.push('persistent-broker-redelivery-new-synchronous-proof-receipt');
  } else if (mode === 'will' || mode === 'will-delayed') {
    const { client } = await connect(a, { will: {
      topic: a.topics.publish, payload: Buffer.from(`device-${mode}\0binary`), qos: 1, retain: true,
      properties: { willDelayInterval: mode === 'will-delayed' ? 10 : 0 },
    } });
    await observation('online');
    const closed = once(client, 'close');
    client.stream.destroy();
    await closed;
    await observation('offline');
    checks.push('authenticated-device-will-network-ended');
  } else if (mode === 'denied') {
    await denied();
    checks.push('current-state-or-credential-rechecked-on-session-resume');
  } else if (mode === 'resume') {
    const { client, ack } = await connect();
    assert.equal(ack.sessionPresent, true);
    await observation('online');
    await assert.rejects(client.subscribeAsync(b.topics.subscribe, { qos: 1 }));
    await finish(client);
    checks.push('restored-subscriptions-reauthorized');
  } else if (mode === 'crash') {
    await connect();
    const before = await observation('online');
    process.kill(Number(process.env.TYPE_DEVICE_BROKER_PID), 'SIGKILL');
    const after = await observation('unknown', a.client_id, 22);
    assert.equal(after.lifecycle, 'enabled');
    assert.equal(after.connection.observed_at, before.connection.observed_at);
    checks.push('broker-crash-observation-expires-to-unknown-without-fake-offline');
  } else if (mode === 'observation-failure') {
    await connect();
    const before = await observation('online');
    console.log('ready');
    const after = await observation('unknown', a.client_id, 25);
    assert.equal(after.connection.observed_at, before.connection.observed_at);
    assert.equal(after.lifecycle, 'enabled');
    checks.push('observation-write-failure-is-unknown-not-offline');
  } else if (mode === 'restart') {
    await observation('unknown');
    const { client, ack } = await connect();
    assert.equal(ack.sessionPresent, true);
    await observation('online');
    await finish(client);
    checks.push('broker-restart-new-run-and-persistent-session-restored');
  } else {
    throw new Error('unknown test mode');
  }
  console.log(JSON.stringify(checks));
} catch (failure) {
  // 先保留实际失败；已关闭客户端的再次end不能掩盖它或拖住整个装置。
  console.error(failure);
  throw failure;
} finally {
  const cleanupAbort = new AbortController();
  try {
    await Promise.race([Promise.allSettled(clients.filter(client => !client.disconnected).map(client => client.endAsync(true))),
      delay(1000, undefined, { signal: cleanupAbort.signal })]);
  } finally { cleanupAbort.abort(); }
}
