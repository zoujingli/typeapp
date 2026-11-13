import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { once } from 'node:events';
import { setTimeout as delay } from 'node:timers/promises';

// 本轮恢复装置的独立标准客户端；只保存调用者传入的内存身份，结束时关闭全部连接。
const require = createRequire(`${process.env.TYPE_MQTT_CLIENT_ROOT}/package.json`);
const mqtt = require('mqtt');
assert.equal(require('mqtt/package.json').version, '5.15.0');
const fixture = JSON.parse(process.env.TYPE_RECOVERY_FIXTURE);
const clients = [];
const payloads = { up: Buffer.from('recovery-up\0binary'), down: Buffer.from('recovery-down\0binary') };
function start(credential) {
  const client = mqtt.connect(`mqtts://127.0.0.1:${process.env.IOT_MQTT_PORT}`, {
    protocolVersion: 5, clientId: credential.client_id, username: credential.username, password: credential.password,
    clean: false, keepalive: 30, reconnectPeriod: 0, connectTimeout: 10000,
    ca: readFileSync(process.env.TYPE_DEVICE_CA), rejectUnauthorized: true, properties: { sessionExpiryInterval: 86400 },
  });
  client.on('error', () => {}); clients.push(client); return client;
}
async function bounded(operation, label) {
  const abort = new AbortController();
  try {
    return await Promise.race([operation, delay(12000, undefined, { signal: abort.signal }).then(() => { throw new Error(label); })]);
  } finally { abort.abort(); }
}
async function connect(credential) {
  const client = start(credential);
  await bounded(once(client, 'connect'), 'recovery MQTT connect deadline');
  return client;
}
async function exchange(sender, recipient, topic, bytes, retain) {
  const receive = once(recipient, 'message', { signal: AbortSignal.timeout(10000) });
  const [, [receivedTopic, received]] = await Promise.all([
    bounded(sender.publishAsync(topic, bytes, { qos: 1, retain }), 'recovery publication deadline'), receive,
  ]);
  assert.equal(receivedTopic, topic); assert.deepEqual(received, bytes);
}
try {
  const mode = process.argv[2];
  assert(['seed', 'roundtrip', 'denied', 'application-denied'].includes(mode));
  if (mode.endsWith('denied')) {
    const client = start(mode === 'denied' ? fixture.credential : fixture.application);
    let accepted = false; let reason = null;
    client.on('connect', () => { accepted = true; });
    client.on('packetreceive', packet => { if (packet.cmd === 'connack') reason = packet.reasonCode; });
    await bounded(new Promise(resolve => client.once('close', resolve)), 'old recovered identity was not rejected');
    assert.equal(accepted, false); assert.equal(reason, 0x86);
  } else {
    const device = await connect(fixture.credential);
    const application = await connect(fixture.application);
    await bounded(device.subscribeAsync(fixture.credential.topics.subscribe, { qos: 1 }), 'device subscription deadline');
    await bounded(application.subscribeAsync(fixture.credential.topics.publish, { qos: 1 }), 'application subscription deadline');
    await exchange(device, application, fixture.credential.topics.publish, payloads.up, mode === 'seed');
    await exchange(application, device, fixture.credential.topics.subscribe, payloads.down, mode === 'seed');
    await bounded(device.endAsync(false), 'device close deadline');
    if (mode === 'seed') {
      await bounded(application.publishAsync(fixture.credential.topics.subscribe, payloads.down, { qos: 1 }), 'offline delivery deadline');
    }
    await bounded(application.endAsync(false), 'application close deadline');
  }
  console.log(JSON.stringify({ mode, protocol: 5, tls: true, raw_bytes_preserved: !mode.endsWith('denied') }));
} finally {
  await bounded(Promise.all(clients.filter(client => !client.disconnected).map(client => client.endAsync(true))), 'client cleanup deadline');
}
