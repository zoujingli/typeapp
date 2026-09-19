import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { once } from 'node:events';

const require = createRequire(`${process.argv[2]}/package.json`);
const mqtt = require('mqtt');
const metadata = require('mqtt/package.json');
assert.equal(metadata.version, '5.15.0');

const port = Number(process.argv[3]);
const transport = process.argv[4];
assert.ok(transport === 'ws' || transport === 'wss', '传输必须是 ws 或 wss');
const qos12 = process.argv.includes('qos12');
const ca = transport === 'wss' ? await readFile(process.argv[5]) : undefined;
const url = `${transport}://127.0.0.1:${port}/mqtt`;
const cases = qos12
    ? [{ protocolVersion: 4, qos: 1 }, { protocolVersion: 5, qos: 2 }]
    : [{ protocolVersion: 4, qos: 0 }, { protocolVersion: 5, qos: 0 }];

const connect = (protocolVersion, clientId, extra = {}) => mqtt.connect(url, {
  protocol: transport,
  protocolVersion,
  clientId,
  username: process.env.MQTT_USERNAME || 'example',
  password: process.env.MQTT_PASSWORD,
  clean: true,
  reconnectPeriod: 0,
  connectTimeout: 8000,
  protocolId: 'MQTT',
  rejectUnauthorized: transport === 'wss',
  wsOptions: { protocol: 'mqtt' },
  ...(transport === 'wss' ? { ca } : {}),
  ...extra,
});

const until = (client, event, label) => Promise.race([
  once(client, event),
  new Promise((_, reject) => setTimeout(() => reject(new Error(`${label} 超时`)), 8000)),
]);

for (const { protocolVersion, qos } of cases) {
  const topic = `example/ws-${transport}-${protocolVersion}-${qos}`;
  const payload = Buffer.from([0, 255, qos, protocolVersion]);
  const subscriber = connect(protocolVersion, `ws-sub-${protocolVersion}-${qos}`);
  try {
    await until(subscriber, 'connect', `订阅 CONNECT v${protocolVersion} QoS ${qos}`);
    const publisher = connect(protocolVersion, `ws-pub-${protocolVersion}-${qos}`);
    try {
      await until(publisher, 'connect', `发布 CONNECT v${protocolVersion} QoS ${qos}`);
      await subscriber.subscribeAsync(topic, { qos });
      const delivery = until(subscriber, 'message', `交付 v${protocolVersion} QoS ${qos}`);
      await publisher.publishAsync(topic, payload, { qos });
      const [name, bytes, packet] = await delivery;
      assert.equal(name, topic);
      assert.deepEqual(bytes, payload);
      assert.equal(packet.qos, qos);
    } finally {
      await publisher.endAsync(true);
    }
  } finally {
    await subscriber.endAsync(true);
  }
}

console.log(`MQTT.js ${metadata.version} ${transport.toUpperCase()} ${qos12 ? 'QoS 1/2' : '3.1.1/5.0 QoS 0'} 通过。`);
process.exit(0);
