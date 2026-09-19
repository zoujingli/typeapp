import assert from 'node:assert/strict';
import { writeSync } from 'node:fs';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { once } from 'node:events';

const require = createRequire(`${process.argv[2]}/package.json`);
const mqtt = require('mqtt');
assert.equal(require('mqtt/package.json').version, '5.15.0');

const action = process.argv[3] || '';
const caPath = process.argv[4] || '';
const ca = caPath ? await readFile(caPath) : undefined;
const host = process.env.COMPAT_MQTT_HOST || '127.0.0.1';
const port = Number(process.env.COMPAT_MQTT_PORT || '0');
const username = process.env.COMPAT_MQTT_USERNAME || '';
const password = process.env.COMPAT_MQTT_PASSWORD || '';
const clientId = process.env.COMPAT_MQTT_CLIENT_ID || '';
assert.ok(port > 0 && username !== '' && password !== '' && clientId !== '', '兼容 MQTT 夹具无效');
const say = text => writeSync(1, `${text}\n`);
const connect = extra => mqtt.connect(`mqtts://${host}:${port}`, {
  protocol: 'mqtts', protocolVersion: 5, clientId, username, password,
  clean: extra.clean ?? true, keepalive: 30, reconnectPeriod: 0, connectTimeout: extra.connectTimeout ?? 20000,
  protocolId: 'MQTT', rejectUnauthorized: true, ca,
  properties: extra.properties ?? { sessionExpiryInterval: 0 },
  will: extra.will,
});
const until = (client, event, label, ms = 20000) => Promise.race([
  once(client, event),
  new Promise((_, reject) => setTimeout(() => reject(new Error(`${label} 超时`)), ms)),
]);

if (action === 'will-hold') {
  process.on('SIGTERM', () => {});
  process.on('SIGINT', () => {});
  const client = connect({
    will: { topic: process.env.COMPAT_WILL_TOPIC, payload: process.env.COMPAT_WILL_PAYLOAD, qos: 1, retain: false },
  });
  await until(client, 'connect', '遗嘱占用 CONNECT');
  say('held:1');
  await new Promise(() => {});
}

if (action === 'connect-fail') {
  const client = connect({});
  try {
    await until(client, 'connect', '不应成功的 CONNECT');
    throw new Error('已撤销身份仍被接入');
  } catch (error) {
    if (String(error).includes('仍被接入')) throw error;
  } finally {
    await client.endAsync(true).catch(() => {});
  }
  say('denied');
  process.exit(0);
}

throw new Error('compat_mqtt_action_invalid');
