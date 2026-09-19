import assert from 'node:assert/strict';
import { writeSync } from 'node:fs';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { once } from 'node:events';

const require = createRequire(`${process.argv[2]}/package.json`);
const mqtt = require('mqtt');
const metadata = require('mqtt/package.json');
assert.equal(metadata.version, '5.15.0');

const url = process.env.DEBUG_MQTT_URL || '';
const action = process.argv[3] || 'roundtrip';
const caPath = process.argv[4] || '';
assert.ok(url.startsWith('wss://'), '需要 WSS 入口');
const ca = caPath ? await readFile(caPath) : undefined;
const connect = (extra = {}) => mqtt.connect(url, {
  protocol: 'wss',
  protocolVersion: 5,
  clientId: extra.clientId || process.env.DEBUG_MQTT_CLIENT_ID,
  username: extra.username || process.env.DEBUG_MQTT_USERNAME,
  password: extra.password || process.env.DEBUG_MQTT_PASSWORD,
  clean: true,
  keepalive: 30,
  reconnectPeriod: 0,
  connectTimeout: extra.connectTimeout ?? 30000,
  protocolId: 'MQTT',
  properties: { sessionExpiryInterval: 0 },
  rejectUnauthorized: true,
  wsOptions: { protocol: 'mqtt' },
  ca,
  ...extra.options,
});
const until = (client, event, label, ms = 30000) => Promise.race([
  once(client, event),
  new Promise((_, reject) => setTimeout(() => reject(new Error(`${label} 超时`)), ms)),
]);
const reasonOf = error => Number(error?.packet?.reasonCode ?? error?.reasonCode ?? error?.code ?? 0);
const say = (text, stream = 1) => writeSync(stream, `${text}\n`);

if (action === 'connect-fail') {
  const client = connect();
  try {
    await until(client, 'connect', '调试拒绝 CONNECT');
    throw new Error('人员令牌或旧凭据不应接入');
  } catch (error) {
    if (String(error).includes('不应接入')) throw error;
  } finally {
    await client.endAsync(true).catch(() => {});
  }
  console.log('denied');
  process.exit(0);
}

if (action === 'connect-quota') {
  const client = connect();
  try {
    await until(client, 'connect', '调试超额 CONNECT');
    throw new Error('超额调试仍被接纳');
  } catch (error) {
    if (String(error).includes('仍被接纳')) throw error;
    const code = reasonOf(error);
    assert.ok(code === 0x97 || code === 151, `超额调试应返回 0x97，实际 ${code} ${error}`);
  } finally {
    await client.endAsync(true).catch(() => {});
  }
  console.log('quota');
  process.exit(0);
}

if (action === 'hold' || action === 'hold-many') {
  const items = action === 'hold-many'
    ? JSON.parse(await readFile(process.env.DEBUG_MQTT_HOLD_FILE, 'utf8'))
    : [{ clientId: process.env.DEBUG_MQTT_CLIENT_ID, username: process.env.DEBUG_MQTT_USERNAME, password: process.env.DEBUG_MQTT_PASSWORD }];
  const clients = [];
  const reasons = [];
  try {
    for (const item of items) {
      const client = connect({ clientId: item.clientId, username: item.username, password: item.password });
      clients.push(client);
      client.on('disconnect', packet => { reasons.push(Number(packet?.reasonCode ?? 0)); });
      await until(client, 'connect', '调试 HOLD CONNECT', 30000);
    }
    say(`held:${clients.length}`);
    await new Promise(resolve => {
      let done = false;
      const finish = () => { if (!done) { done = true; resolve(); } };
      process.once('SIGTERM', finish);
      process.once('SIGINT', finish);
      for (const client of clients) {
        client.once('close', () => {
          if (clients.length === 1 || clients.every(item => !item.connected)) finish();
        });
        if (clients.length === 1) client.once('disconnect', finish);
      }
    });
    say(`closed:${reasons[0] || 0}`);
    process.exit(0);
  } catch (error) {
    console.error(error);
    process.exit(1);
  }
}

const client = connect();
try {
  await until(client, 'connect', '调试 CONNECT');
  if (action === 'wait-close') {
    say('connected');
    let reason = 0;
    client.on('disconnect', packet => { reason = Number(packet?.reasonCode ?? 0); });
    await until(client, 'close', '撤权断开', 8000);
    say(reason ? `closed:${reason}` : 'closed');
    process.exit(0);
  }
  const subscribeTopic = process.env.DEBUG_MQTT_SUBSCRIBE || '';
  const publishTopic = process.env.DEBUG_MQTT_PUBLISH || '';
  if (action === 'deny-subscribe') {
    try {
      const granted = await new Promise((resolve, reject) => {
        client.subscribe(subscribeTopic, { qos: 0 }, (error, result) => error ? reject(error) : resolve(result));
      });
      assert.ok(granted && granted[0] && granted[0].qos >= 128, '越权订阅应返回失败码');
    } catch (error) {
      const granted = error?.packet?.granted;
      assert.ok(Array.isArray(granted) && granted[0] >= 128, '越权订阅应返回失败码');
    }
    console.log('denied');
    process.exit(0);
  }
  await client.subscribeAsync(subscribeTopic, { qos: 0 });
  if (action === 'deny-publish') {
    try {
      await client.publishAsync(publishTopic, Buffer.from('denied'), { qos: 0 });
      const closed = await Promise.race([
        until(client, 'close', '越权发布断开', 3000).then(() => true),
        new Promise(resolve => setTimeout(() => resolve(false), 2500)),
      ]);
      if (!closed) throw new Error('越权发布未被拒绝');
    } catch (error) {
      if (String(error).includes('未被拒绝')) throw error;
    }
    console.log('denied');
    process.exit(0);
  }
  const payload = action === 'binary' ? Buffer.from([0, 1, 2, 255, 9]) : Buffer.from(`debug-${Date.now()}`);
  const delivery = until(client, 'message', '调试交付');
  await client.publishAsync(publishTopic, payload, { qos: 0 });
  const [name, bytes] = await delivery;
  assert.equal(name, publishTopic);
  assert.deepEqual(bytes, payload);
  console.log(action === 'binary' ? 'binary' : 'delivered');
} finally {
  await client.endAsync(true);
}
process.exit(0);
