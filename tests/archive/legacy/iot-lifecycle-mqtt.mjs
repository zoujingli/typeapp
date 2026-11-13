import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { existsSync, readFileSync } from 'node:fs';
import { once } from 'node:events';
import { setTimeout as delay } from 'node:timers/promises';

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
try {
  const mode = process.argv[2];
  if (mode === 'denied') {
    const client = start();
    let connected = false;
    let reason = null;
    client.on('connect', () => { connected = true; });
    client.on('packetreceive', packet => { if (packet.cmd === 'connack') reason = packet.reasonCode; });
    await bounded(new Promise(resolve => client.once('close', resolve)), 12, 'old identity was not rejected');
    assert.equal(connected, false);
    assert.equal(reason, 0x86);
    checks.push('old-credential-connack-bad-user-name-or-password');
  } else if (mode === 'control' || mode === 'control-denied') {
    const { client } = await connect(fixture.application);
    let reason = null;
    let disconnected = null;
    client.on('packetreceive', packet => {
      if (packet.cmd === 'puback') reason = packet.reasonCode;
      if (packet.cmd === 'disconnect') disconnected = packet.reasonCode;
    });
    const closed = new Promise(resolve => client.once('close', resolve));
    const publication = client.publishAsync(credential.topics.subscribe, control, { qos: 1 });
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
    checks.push(mode === 'control-denied' ? 'unavailable-device-control-disconnect-not-authorized' : 'device-scoped-application-qos1-control');
  } else {
    const { client, ack } = await connect();
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
      assert.deepEqual(received, [], 'old identity received control after revocation');
      checks.push(mode === 'idle' ? 'idle-connection-actively-closed' : `old-${mode}-not-authorized`);
    }
  }
  console.log(JSON.stringify(checks));
} finally {
  await Promise.all(clients.map(client => client.endAsync(true)));
}
