import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { existsSync, readFileSync } from 'node:fs';
import { once } from 'node:events';
import { setTimeout as delay } from 'node:timers/promises';
import { request as httpRequest } from 'node:http';

const require = createRequire(`${process.env.TYPE_MQTT_CLIENT_ROOT}/package.json`);
const mqtt = require('mqtt');
assert.equal(require('mqtt/package.json').version, '5.15.0');
const fixture = JSON.parse(process.env.TYPE_LIFECYCLE_FIXTURE);
const credential = fixture.credential;
const clients = [];
const checks = [];
const headers = { Authorization: `Bearer ${fixture.token}`, 'X-Tenant-Id': fixture.tenant };
const endpoint = `mqtts://127.0.0.1:${process.env.IOT_MQTT_PORT}`;
const control = Buffer.from('lifecycle-control\0binary');

async function bounded(promise, seconds, label) {
  const abort = new AbortController();
  try {
    return await Promise.race([promise, delay(seconds * 1000, undefined, { signal: abort.signal }).then(() => { throw new Error(label); })]);
  } finally { abort.abort(); }
}
async function read() {
  const response = await fetch(process.env.TYPE_DEVICE_HTTP + fixture.path, { headers, signal: AbortSignal.timeout(5000) });
  assert.equal(response.status, 200);
  return (await response.json()).data;
}
async function observed(status) {
  const until = Date.now() + 12000;
  do {
    const data = await read();
    if (data.connection.status === status) return data;
    await delay(50);
  } while (Date.now() < until);
  throw new Error(`device observation did not become ${status}`);
}
async function gate() {
  const until = Date.now() + 20000;
  while (!existsSync(process.env.TYPE_LIFECYCLE_GATE) && Date.now() < until) await delay(20);
  assert.ok(existsSync(process.env.TYPE_LIFECYCLE_GATE), 'management gate did not open');
}
function start(identity = credential, overrides = {}) {
  const client = mqtt.connect(endpoint, {
    protocolVersion: 5, clientId: identity.client_id, username: identity.username, password: identity.password,
    keepalive: 30, clean: false, reconnectPeriod: 0, connectTimeout: 10000,
    ca: readFileSync(process.env.TYPE_DEVICE_CA), rejectUnauthorized: true, properties: { sessionExpiryInterval: 86400 },
    ...overrides,
  });
  client.on('error', () => {});
  clients.push(client);
  return client;
}
async function connect(identity = credential, overrides = {}) {
  const client = start(identity, overrides);
  const [ack] = await bounded(once(client, 'connect'), 12, 'MQTT connect exceeded deadline');
  return { client, ack };
}
async function finish(client) {
  await bounded(client.endAsync(false), 10, 'MQTT graceful close exceeded deadline');
}
function priorTransferReceipt(topic, bytes) {
  assert.equal(topic, credential.topics.subscribe);
  const receipt = JSON.parse(bytes.toString());
  assert.equal(receipt.app_version, 1);
  assert.equal(receipt.device_id, credential.client_id);
  assert.equal(receipt.ownership_id, fixture.ownership_id);
  if (receipt.type === 'time_response') {
    // 旧模拟器退出前的时钟请求可以迟到；此观察不触发动作，也不能解除冻结。
    assert.equal(Object.keys(receipt).length, 6);
    assert.match(receipt.nonce, /^[a-f0-9]{32}$/);
    assert.ok(Number.isInteger(receipt.server_time) && receipt.server_time > 0);
  } else if (receipt.type === 'transfer_freeze') {
    // 同一冻结通知可在原设备退出后迟到；精确核对本次转移，观察客户端不执行新动作。
    assert.deepEqual(receipt, fixture.freeze);
    checks.push('prior-freeze-matches-current-transfer');
    console.log('freeze-checked');
  } else {
    assert.equal(receipt.type, 'transfer_status_ack');
    assert.equal(receipt.transfer_id, fixture.transfer_id);
    assert.match(receipt.sequence, /^[1-9][0-9]{0,37}$/);
  }
}

try {
  const mode = process.argv[2];
  if (mode === 'transfer-response-drop') {
    // 请求提交后关闭响应流并退出调用进程；不读取一次性凭据响应正文。
    await bounded(new Promise((resolve, reject) => {
      const bytes = JSON.stringify(fixture.operation.body);
      const request = httpRequest(process.env.TYPE_DEVICE_HTTP + fixture.operation.path, {
        method: 'POST', headers: { ...headers, 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(bytes) },
      }, response => {
        try { assert.equal(response.statusCode, 202); } catch (error) { reject(error); }
        response.destroy(); request.destroy(); resolve();
      });
      request.on('error', reject); request.end(bytes);
    }), 10, 'transfer request response drop exceeded deadline');
    checks.push('committed-response-body-dropped-caller-exits');
  } else if (mode === 'transfer-receiver-takeover') {
    const { client, ack } = await connect({ client_id: 'iot-ingestion-test',
      username: `service:ingestion:${process.env.IOT_INGESTION_CREDENTIAL_ID}`, password: process.env.TYPE_INGESTION_PASSWORD });
    assert.equal(ack.sessionPresent, true, 'receiver takeover replaced the original durable session');
    await finish(client);
    checks.push('receiver-original-session-taken-over-and-released-for-supervisor');
  } else if (mode === 'transfer-confirm-drop') {
    // 使用正式设备缓存生成的原始确认字节；不订阅业务ACK，不写设备缓存，MQTT确认后断线退出。
    const { client } = await connect();
    await bounded(client.publishAsync(credential.topics.publish, fixture.confirmation, { qos: 1 }), 12, 'transfer confirmation publish exceeded deadline');
    await finish(client);
    checks.push('durable-device-confirmation-published-and-business-ack-unconsumed');
  } else if (mode === 'transfer-new-clean') {
    const { client, ack } = await connect();
    assert.equal(ack.sessionPresent, false, 'new ownership inherited the old session');
    const received = [];
    client.on('message', (topic, bytes) => received.push({ topic, bytes }));
    await bounded(client.subscribeAsync(credential.topics.subscribe, { qos: 1 }), 12, 'new ownership subscribe exceeded deadline');
    await delay(500);
    assert.deepEqual(received, [], 'new ownership received old queued or retained payload');
    await finish(client);
    checks.push('new-ownership-fresh-session-without-old-queue-or-retained-replay');
  } else if (mode === 'denied') {
    const client = start();
    let connected = false;
    let reason = null;
    client.on('connect', () => { connected = true; });
    client.on('packetreceive', packet => { if (packet.cmd === 'connack') reason = packet.reasonCode; });
    await bounded(new Promise(resolve => client.once('close', resolve)), 12, 'old identity was not rejected');
    assert.equal(connected, false);
    assert.equal(reason, 0x86);
    checks.push('old-credential-connack-bad-user-name-or-password');
  } else if (mode === 'recovery-telemetry') {
    const { client } = await connect();
    await bounded(client.subscribeAsync(credential.topics.subscribe, { qos: 1 }), 12, 'recovery subscription exceeded deadline');
    const receipts = new Map();
    client.on('message', (topic, bytes) => {
      assert.equal(topic, credential.topics.subscribe);
      const receipt = JSON.parse(bytes.toString());
      assert.equal(receipt.type, 'ingestion_receipt');
      assert.equal(receipt.device_id, credential.client_id);
      assert.equal(receipt.ownership_id, fixture.ownership_id);
      assert.equal(receipt.status, 'accepted');
      assert.equal(receipt.code, 'accepted');
      receipts.set(receipt.sequence, receipt);
    });
    for (const [index, value] of fixture.values.entries()) {
      const sequence = String(fixture.sequence_start + index);
      const bytes = JSON.stringify({ app_version: 1, type: 'telemetry', device_id: credential.client_id,
        ownership_id: fixture.ownership_id, model_version: 1, sequence,
        sampled_at: Math.floor(Date.now() / 1000), values: { temperature: value } });
      await bounded(client.publishAsync(credential.topics.publish, bytes, { qos: 1 }), 12, 'recovery publication exceeded deadline');
      const until = Date.now() + 12000;
      while (!receipts.has(sequence) && Date.now() < until) await delay(20);
      assert.ok(receipts.has(sequence), 'missing persistent business receipt before recovery');
    }
    await finish(client);
    await observed('offline');
    checks.push('real-tls-telemetry-with-durable-business-receipts-and-offline-subscription');
  } else if (mode === 'control' || mode === 'control-denied' || mode === 'transfer-retained' || mode === 'transfer-freeze-replay') {
    const { client } = await connect({ client_id: 'iot-ingestion-lifecycle',
      username: `service:ingestion:${process.env.IOT_INGESTION_CREDENTIAL_ID}`, password: process.env.TYPE_INGESTION_PASSWORD });
    let reason = null;
    let disconnected = null;
    client.on('packetreceive', packet => {
      if (packet.cmd === 'puback') reason = packet.reasonCode;
      if (packet.cmd === 'disconnect') disconnected = packet.reasonCode;
    });
    const closed = new Promise(resolve => client.once('close', resolve));
    const payload = mode === 'transfer-freeze-replay' ? Buffer.from(JSON.stringify(fixture.freeze)) : control;
    const publication = client.publishAsync(credential.topics.subscribe, payload, { qos: 1, retain: mode === 'transfer-retained' });
    if (mode === 'control-denied') {
      let accepted = false;
      publication.then(() => { accepted = true; }, () => {});
      await bounded(closed, 12, 'control denial exceeded deadline');
      assert.equal(accepted, false);
      assert.equal(disconnected, 0x87, 'unavailable device control was not explicitly denied');
    } else {
      await bounded(publication, 12, 'control publication exceeded deadline');
      assert.ok(reason === undefined || reason === 0);
      await finish(client);
    }
    checks.push(mode === 'transfer-freeze-replay' ? 'trusted-service-replayed-current-freeze'
      : mode === 'control-denied' ? 'unavailable-device-control-disconnect-not-authorized'
      : mode === 'transfer-retained' ? 'trusted-service-real-qos1-retained-control' : 'trusted-service-real-qos1-control');
  } else {
    const { client, ack } = await connect(credential, mode === 'transfer-hold' || mode === 'transfer-idle' ? {
      customHandleAcks: (topic, bytes, packet, acknowledge) => {
        assert.equal(topic, credential.topics.subscribe);
        if (mode === 'transfer-hold' && bytes.equals(control)) { console.log('held'); return; }
        priorTransferReceipt(topic, bytes);
        acknowledge(null, 0);
      },
    } : {});
    await observed('online');
    const received = [];
    client.on('message', (topic, payload) => received.push({ topic, payload }));
    await bounded(client.subscribeAsync(credential.topics.subscribe, { qos: 1 }), 12, 'initial subscription exceeded deadline');
    if (mode === 'offline') {
      await finish(client);
      await observed('offline');
      checks.push('persistent-subscription-offline');
    } else if (mode === 'new-identity') {
      assert.equal(ack.sessionPresent, false, 'new credential inherited an old identity session');
      let disconnectReason = null;
      client.on('packetreceive', packet => { if (packet.cmd === 'disconnect') disconnectReason = packet.reasonCode; });
      console.log('ready');
      await gate();
      const until = Date.now() + 15000;
      while (received.length === 0 && client.connected && Date.now() < until) await delay(30);
      assert.equal(client.connected, true, `new identity disconnected after late intent; reason=${disconnectReason}`);
      assert.equal(received.length, 1);
      assert.equal(received[0].topic, credential.topics.subscribe);
      assert.deepEqual(received[0].payload, control);
      await bounded(client.publishAsync(credential.topics.publish, 'new-identity-authorized', { qos: 1 }), 12, 'new identity publication failed');
      await finish(client);
      checks.push('late-old-intent-keeps-new-identity-control-and-publish');
    } else {
      const closed = new Promise(resolve => client.once('close', resolve));
      console.log('ready');
      if (mode === 'publish' || mode === 'subscribe') {
        await gate();
        if (client.connected) {
          let succeeded = false;
          const operation = mode === 'publish'
            ? client.publishAsync(credential.topics.publish, 'revoked-publication', { qos: 1 })
            : client.subscribeAsync(credential.topics.subscribe, { qos: 1 });
          await bounded(Promise.race([operation.then(() => { succeeded = true; }, () => {}), closed]), 12, 'revoked operation exceeded deadline');
          assert.equal(succeeded, false, 'revoked operation was accepted');
        }
      }
      await bounded(closed, 20, 'idle revoked connection stayed open');
      if (mode === 'transfer-idle' || mode === 'transfer-hold') {
        // 恢复会话可先收到上一设备运行的冻结或时钟响应；这些回执不代表新控制或新归属授权。
        for (const message of received) {
          priorTransferReceipt(message.topic, message.payload);
        }
        checks.push('transfer-prior-status-acks-and-active-connection-closed');
      } else {
        assert.deepEqual(received, [], 'old identity received control after revocation');
        checks.push(mode === 'idle' ? 'idle-connection-actively-closed' : `old-${mode}-not-authorized`);
      }
    }
  }
  console.log(JSON.stringify(checks));
} finally {
  await Promise.all(clients.map(client => client.endAsync(true)));
}
