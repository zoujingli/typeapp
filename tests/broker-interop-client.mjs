import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { once } from 'node:events';

/**
 * 独立 MQTT.js 5.15.0：口令 TLS/WSS、证书 mTLS、双版本 QoS、会话、遗嘱/保留/共享/通配/别名与跨传输。
 * 不加载 type-mqtt 编解码器。
 */

const require = createRequire(`${process.argv[2]}/package.json`);
const mqtt = require('mqtt');
const metadata = require('mqtt/package.json');
assert.equal(metadata.version, '5.15.0');

const action = process.argv[3] || 'matrix';
const prefix = process.env.INTEROP_MQTT_PREFIX || 'broker-interop/';
const username = process.env.INTEROP_MQTT_USERNAME || 'broker-client';
const password = process.env.INTEROP_MQTT_PASSWORD || '';
const caPath = process.env.INTEROP_MQTT_CA || '';
const certPath = process.env.INTEROP_MQTT_CERT || '';
const keyPath = process.env.INTEROP_MQTT_KEY || '';
const tlsPort = Number(process.env.INTEROP_MQTT_TLS_PORT || 0);
const wssPort = Number(process.env.INTEROP_MQTT_WSS_PORT || 0);
const mtlsPort = Number(process.env.INTEROP_MQTT_MTLS_PORT || 0);
const ca = caPath ? await readFile(caPath) : undefined;
const cert = certPath ? await readFile(certPath) : undefined;
const key = keyPath ? await readFile(keyPath) : undefined;

const until = (client, event, label, ms = 20000) => new Promise((resolve, reject) => {
  const timer = setTimeout(() => reject(new Error(`${label} 超时`)), ms);
  once(client, event).then(args => {
    clearTimeout(timer);
    resolve(args);
  }, error => {
    clearTimeout(timer);
    reject(error);
  });
});

const reasonOf = error => Number(error?.packet?.reasonCode ?? error?.reasonCode ?? error?.code ?? 0);

const connected = (client, label, ms = 20000) => new Promise((resolve, reject) => {
  const timer = setTimeout(() => reject(new Error(`${label} 超时`)), ms);
  client.waitConnect.then(args => {
    clearTimeout(timer);
    resolve(args);
  }, error => {
    clearTimeout(timer);
    reject(error);
  });
});

const connect = (transport, protocolVersion, clientId, extra = {}) => {
  const mutual = transport === 'mtls' || transport === 'wss-mtls';
  const secureWs = transport === 'wss' || transport === 'wss-mtls';
  const port = transport === 'tls' ? tlsPort : transport === 'mtls' ? mtlsPort : wssPort;
  assert.ok(port > 0, `${transport} 端口无效`);
  const url = secureWs ? `wss://127.0.0.1:${port}/mqtt` : `mqtts://127.0.0.1:${port}`;
  const client = mqtt.connect(url, {
    protocol: secureWs ? 'wss' : 'mqtts',
    protocolVersion,
    clientId,
    username: extra.username === undefined ? (mutual && extra.certOnly ? undefined : username) : extra.username,
    password: extra.password === undefined ? (mutual && extra.certOnly ? undefined : password) : extra.password,
    clean: extra.clean ?? true,
    keepalive: extra.keepalive ?? 30,
    reconnectPeriod: 0,
    connectTimeout: extra.connectTimeout ?? 20000,
    protocolId: 'MQTT',
    rejectUnauthorized: true,
    ca,
    ...(mutual ? { cert, key } : {}),
    ...(secureWs ? { wsOptions: { protocol: extra.subprotocol ?? 'mqtt' } } : {}),
    properties: protocolVersion === 5 ? {
      sessionExpiryInterval: extra.sessionExpiry ?? 0,
      receiveMaximum: extra.receiveMaximum ?? 32,
      topicAliasMaximum: extra.topicAliasMaximum ?? 32,
      ...extra.properties,
    } : undefined,
    will: extra.will,
  });
  client.waitConnect = once(client, 'connect');
  return client;
};

const roundtrip = async (transport, protocolVersion, qos) => {
  const topic = `${prefix}${transport}-${protocolVersion}-${qos}`;
  const payload = Buffer.from([0, 255, qos, protocolVersion, 1, 2, 3]);
  const subscriber = connect(transport, protocolVersion, `sub-${transport}-${protocolVersion}-${qos}`, { sessionExpiry: 0 });
  try {
    const subAck = await connected(subscriber, `订阅 CONNECT ${transport} v${protocolVersion}`);
    const publisher = connect(transport, protocolVersion, `pub-${transport}-${protocolVersion}-${qos}`, { sessionExpiry: 0 });
    try {
      await connected(publisher, `发布 CONNECT ${transport} v${protocolVersion}`);
      await subscriber.subscribeAsync(topic, { qos });
      const delivery = until(subscriber, 'message', `交付 ${transport} v${protocolVersion} QoS ${qos}`);
      await publisher.publishAsync(topic, payload, { qos });
      const [name, bytes, packet] = await delivery;
      assert.equal(name, topic);
      assert.deepEqual(bytes, payload);
      assert.equal(packet.qos, qos);
      const connack = Array.isArray(subAck) ? subAck[0] : subAck;
      return {
        transport, protocolVersion, qos, binary: true,
        receiveMaximum: protocolVersion === 5 ? Number(connack?.properties?.receiveMaximum ?? 0) : null,
        topicAliasMaximum: protocolVersion === 5 ? Number(connack?.properties?.topicAliasMaximum ?? 0) : null,
        maximumPacketSize: protocolVersion === 5 ? Number(connack?.properties?.maximumPacketSize ?? 0) : null,
      };
    } finally {
      await publisher.endAsync(true);
    }
  } finally {
    await subscriber.endAsync(true);
  }
};

if (action === 'matrix') {
  const rows = [];
  for (const transport of ['tls', 'wss']) {
    for (const protocolVersion of [4, 5]) {
      for (const qos of [0, 1, 2]) {
        rows.push(await roundtrip(transport, protocolVersion, qos));
      }
    }
  }
  for (const row of rows) {
    if (row.protocolVersion === 5) {
      assert.equal(row.receiveMaximum, 32);
      assert.equal(row.topicAliasMaximum, 32);
      assert.equal(row.maximumPacketSize, 1048576);
    }
  }
  console.log(JSON.stringify({ ok: true, action, client: metadata.version, rows }));
  process.exit(0);
}

if (action === 'features') {
  const report = {};
  const wildcard = connect('wss', 5, 'feat-wild-sub', { sessionExpiry: 0 });
  try {
    await connected(wildcard, '通配 CONNECT');
    await wildcard.subscribeAsync(`${prefix}wild/+`, { qos: 0 });
    const delivery = until(wildcard, 'message', '通配交付');
    const publisher = connect('tls', 5, 'feat-wild-pub', { sessionExpiry: 0 });
    try {
      await connected(publisher, '通配发布 CONNECT');
      await publisher.publishAsync(`${prefix}wild/one`, Buffer.from('wild-ok'), { qos: 0 });
      const [name, bytes] = await delivery;
      assert.equal(name, `${prefix}wild/one`);
      assert.equal(bytes.toString(), 'wild-ok');
      report.wildcard = true;
    } finally {
      await publisher.endAsync(true);
    }
  } finally {
    await wildcard.endAsync(true);
  }

  const shareA = connect('wss', 5, 'feat-share-a', { sessionExpiry: 0 });
  try {
    await connected(shareA, '共享 A CONNECT');
    const shareB = connect('tls', 5, 'feat-share-b', { sessionExpiry: 0 });
    try {
      await connected(shareB, '共享 B CONNECT');
      await shareA.subscribeAsync(`$share/interop/${prefix}shared`, { qos: 0 });
      await shareB.subscribeAsync(`$share/interop/${prefix}shared`, { qos: 0 });
      const got = [];
      shareA.on('message', (_topic, payload) => got.push(`a:${payload}`));
      shareB.on('message', (_topic, payload) => got.push(`b:${payload}`));
      const publisher = connect('wss', 5, 'feat-share-pub', { sessionExpiry: 0 });
      try {
        await connected(publisher, '共享发布 CONNECT');
        await publisher.publishAsync(`${prefix}shared`, Buffer.from('share-once'), { qos: 0 });
        const deadline = Date.now() + 8000;
        while (Date.now() < deadline && got.length < 1) {
          await new Promise(resolve => setTimeout(resolve, 50));
        }
        await new Promise(resolve => setTimeout(resolve, 400));
        assert.equal(got.length, 1, `共享组应只交付一份，实际 ${got.join(',')}`);
        assert.match(got[0], /^[ab]:share-once$/);
        report.shared = true;
      } finally {
        await publisher.endAsync(true);
      }
    } finally {
      await shareB.endAsync(true);
    }
  } finally {
    await shareA.endAsync(true);
  }

  const retainSub = connect('wss', 5, 'feat-retain-sub', { sessionExpiry: 0 });
  try {
    await connected(retainSub, '保留订阅 CONNECT');
    const retainPub = connect('tls', 5, 'feat-retain-pub', { sessionExpiry: 0 });
    try {
      await connected(retainPub, '保留发布 CONNECT');
      await retainPub.publishAsync(`${prefix}retain`, Buffer.from('kept'), { qos: 1, retain: true });
    } finally {
      await retainPub.endAsync(true);
    }
    const retained = until(retainSub, 'message', '保留重放');
    await retainSub.subscribeAsync(`${prefix}retain`, { qos: 1 });
    const [name, bytes, packet] = await retained;
    assert.equal(name, `${prefix}retain`);
    assert.equal(bytes.toString(), 'kept');
    assert.equal(packet.retain, true);
    report.retain = true;
  } finally {
    await retainSub.endAsync(true);
  }

  const willSub = connect('tls', 5, 'feat-will-sub', { sessionExpiry: 0 });
  try {
    await connected(willSub, '遗嘱订阅 CONNECT');
    await willSub.subscribeAsync(`${prefix}will`, { qos: 0 });
    const willer = connect('wss', 5, 'feat-will-pub', {
      sessionExpiry: 0,
      will: { topic: `${prefix}will`, payload: Buffer.from('died'), qos: 0, retain: false },
    });
    await connected(willer, '遗嘱 CONNECT');
    const died = until(willSub, 'message', '遗嘱交付');
    willer.stream.destroy();
    const [, willBytes] = await died;
    assert.equal(willBytes.toString(), 'died');
    report.will = true;
    await willer.endAsync(true).catch(() => {});
  } finally {
    await willSub.endAsync(true);
  }

  const aliasSub = connect('wss', 5, 'feat-alias-sub', { sessionExpiry: 0 });
  try {
    await connected(aliasSub, '别名订阅 CONNECT');
    await aliasSub.subscribeAsync(`${prefix}alias`, { qos: 0 });
    const aliasPub = connect('tls', 5, 'feat-alias-pub', { sessionExpiry: 0, topicAliasMaximum: 32 });
    try {
      await connected(aliasPub, '别名发布 CONNECT');
      const first = until(aliasSub, 'message', '别名首次');
      await aliasPub.publishAsync(`${prefix}alias`, Buffer.from('alias-1'), { qos: 0, properties: { topicAlias: 1 } });
      const [firstName, firstBytes] = await first;
      assert.equal(firstName, `${prefix}alias`);
      assert.equal(firstBytes.toString(), 'alias-1');
      const second = until(aliasSub, 'message', '别名复用');
      await aliasPub.publishAsync('', Buffer.from('alias-2'), { qos: 0, properties: { topicAlias: 1 } });
      const [secondName, secondBytes] = await second;
      assert.equal(secondName, `${prefix}alias`);
      assert.equal(secondBytes.toString(), 'alias-2');
      report.alias = true;
    } finally {
      await aliasPub.endAsync(true);
    }
  } finally {
    await aliasSub.endAsync(true);
  }

  const json = '{"kind":"business","pad":"' + 'x'.repeat(16356) + '"}';
  assert.equal(Buffer.byteLength(json), 16384);
  const jsonSub = connect('wss', 5, 'feat-json-sub', { sessionExpiry: 0 });
  try {
    await connected(jsonSub, 'JSON 订阅 CONNECT');
    await jsonSub.subscribeAsync(`${prefix}json`, { qos: 1 });
    const jsonPub = connect('tls', 5, 'feat-json-pub', { sessionExpiry: 0 });
    try {
      await connected(jsonPub, 'JSON 发布 CONNECT');
      const got = until(jsonSub, 'message', '16KiB JSON');
      await jsonPub.publishAsync(`${prefix}json`, json, { qos: 1 });
      const [, body] = await got;
      assert.equal(body.toString(), json);
      report.businessJson = Buffer.byteLength(json);
    } finally {
      await jsonPub.endAsync(true);
    }
  } finally {
    await jsonSub.endAsync(true);
  }

  console.log(JSON.stringify({ ok: true, action, client: metadata.version, report }));
  process.exit(0);
}

if (action === 'session') {
  const report = {};
  const v5id = 'interop-session-5';
  const first = connect('wss', 5, v5id, { clean: false, sessionExpiry: 86400 });
  const ack = await connected(first, 'MQTT5 首次 CONNECT');
  const packet = Array.isArray(ack) ? ack[0] : ack;
  assert.equal(Boolean(packet.sessionPresent), false);
  await first.subscribeAsync(`${prefix}session5`, { qos: 1 });
  first.stream.destroy();
  await until(first, 'close', 'MQTT5 断线');

  const publisher = connect('tls', 5, 'interop-session-5-pub', { sessionExpiry: 0 });
  try {
    await connected(publisher, 'MQTT5 排队发布 CONNECT');
    await publisher.publishAsync(`${prefix}session5`, Buffer.from('offline-5'), { qos: 1 });
  } finally {
    await publisher.endAsync(true);
  }
  const restored = connect('wss', 5, v5id, { clean: false, sessionExpiry: 86400 });
  try {
    const ack = await connected(restored, 'MQTT5 恢复 CONNECT');
    const packet = Array.isArray(ack) ? ack[0] : ack;
    assert.equal(Boolean(packet.sessionPresent), true);
    const [name, bytes] = await until(restored, 'message', 'MQTT5 排队恢复', 15000);
    assert.equal(name, `${prefix}session5`);
    assert.equal(bytes.toString(), 'offline-5');
    report.mqtt5 = { sessionPresent: true, expiry: 86400 };
  } finally {
    await restored.endAsync(true);
  }

  const v4id = 'interop-session-311';
  const first311 = connect('tls', 4, v4id, { clean: false });
  const ack311 = await connected(first311, 'MQTT3.1.1 首次 CONNECT');
  const packet311 = Array.isArray(ack311) ? ack311[0] : ack311;
  assert.equal(Boolean(packet311.sessionPresent), false);
  await first311.subscribeAsync(`${prefix}session311`, { qos: 1 });
  first311.stream.destroy();
  await until(first311, 'close', 'MQTT3.1.1 断线');

  const publisher311 = connect('tls', 4, 'interop-session-311-pub', { clean: true });
  try {
    await connected(publisher311, 'MQTT3.1.1 排队发布 CONNECT');
    await publisher311.publishAsync(`${prefix}session311`, Buffer.from('offline-311'), { qos: 1 });
  } finally {
    await publisher311.endAsync(true);
  }
  const restored311 = connect('tls', 4, v4id, { clean: false });
  try {
    const ack = await connected(restored311, 'MQTT3.1.1 恢复 CONNECT');
    const packet = Array.isArray(ack) ? ack[0] : ack;
    assert.equal(Boolean(packet.sessionPresent), true);
    const [name, bytes] = await until(restored311, 'message', 'MQTT3.1.1 排队恢复', 15000);
    assert.equal(name, `${prefix}session311`);
    assert.equal(bytes.toString(), 'offline-311');
    report.mqtt311 = { sessionPresent: true, cleanSession: 0 };
  } finally {
    await restored311.endAsync(true);
  }

  console.log(JSON.stringify({ ok: true, action, client: metadata.version, report }));
  process.exit(0);
}

if (action === 'cross') {
  const subscriber = connect('tls', 5, 'cross-tls-sub', { sessionExpiry: 0 });
  try {
    await connected(subscriber, '跨传输 TLS 订阅 CONNECT');
    await subscriber.subscribeAsync(`${prefix}cross`, { qos: 1 });
    const publisher = connect('wss', 5, 'cross-wss-pub', { sessionExpiry: 0 });
    try {
      await connected(publisher, '跨传输 WSS 发布 CONNECT');
      const got = until(subscriber, 'message', 'WSS→TLS');
      await publisher.publishAsync(`${prefix}cross`, Buffer.from('wss-to-tls'), { qos: 1 });
      const [, bytes] = await got;
      assert.equal(bytes.toString(), 'wss-to-tls');
    } finally {
      await publisher.endAsync(true);
    }
  } finally {
    await subscriber.endAsync(true);
  }
  const wsSub = connect('wss', 4, 'cross-wss-sub', { sessionExpiry: 0, clean: true });
  try {
    await connected(wsSub, '跨传输 WSS 订阅 CONNECT');
    await wsSub.subscribeAsync(`${prefix}cross-back`, { qos: 0 });
    const tcpPub = connect('tls', 4, 'cross-tls-pub', { sessionExpiry: 0, clean: true });
    try {
      await connected(tcpPub, '跨传输 TLS 发布 CONNECT');
      const got = until(wsSub, 'message', 'TLS→WSS');
      await tcpPub.publishAsync(`${prefix}cross-back`, Buffer.from('tls-to-wss'), { qos: 0 });
      const [, bytes] = await got;
      assert.equal(bytes.toString(), 'tls-to-wss');
    } finally {
      await tcpPub.endAsync(true);
    }
  } finally {
    await wsSub.endAsync(true);
  }
  console.log(JSON.stringify({ ok: true, action, client: metadata.version, directions: ['wss-to-tls', 'tls-to-wss'] }));
  process.exit(0);
}

if (action === 'hold') {
  const client = connect('wss', 5, process.env.INTEROP_MQTT_CLIENT_ID || 'interop-hold', { sessionExpiry: 86400, clean: false });
  const reasons = [];
  try {
    await connected(client, '占用 CONNECT');
    await client.subscribeAsync(`${prefix}hold`, { qos: 1 });
    client.on('disconnect', packet => { reasons.push(Number(packet?.reasonCode ?? 0)); });
    process.stdout.write('held:1\n');
    await new Promise(resolve => {
      const finish = () => resolve();
      process.once('SIGTERM', finish);
      process.once('SIGINT', finish);
      client.once('close', finish);
      client.once('disconnect', finish);
    });
    console.log(JSON.stringify({ ok: true, action, closed: reasons[0] || 0 }));
  } finally {
    await client.endAsync(true).catch(() => {});
  }
  process.exit(0);
}

if (action === 'mtls') {
  const rows = [];
  rows.push(await roundtrip('mtls', 5, 1));
  const certOnly = connect('mtls', 5, 'mtls-cert-only', { certOnly: true, sessionExpiry: 0 });
  try {
    await connected(certOnly, '证书单独认证 CONNECT');
    rows.push({ transport: 'mtls', identity: 'certificate', ok: true });
  } finally {
    await certOnly.endAsync(true);
  }
  const both = connect('mtls', 5, 'mtls-both', { sessionExpiry: 0 });
  try {
    await connected(both, '证书加口令 CONNECT');
    rows.push({ transport: 'mtls', identity: 'certificate+password', ok: true });
  } finally {
    await both.endAsync(true);
  }
  const mismatch = connect('mtls', 5, 'mtls-mismatch', { password: 'wrong-password-12', sessionExpiry: 0 });
  try {
    await Promise.race([
      connected(mismatch, '错误口令不应成功'),
      once(mismatch, 'error').then(([error]) => {
        throw error;
      }),
    ]);
    throw new Error('错误口令降级为仅证书');
  } catch (error) {
    if (String(error).includes('降级')) throw error;
    const code = reasonOf(error);
    assert.ok(code === 0x87 || code === 135 || code === 5, `错误口令原因码异常 ${code} ${error}`);
    rows.push({ transport: 'mtls', identity: 'mismatch', reason: code || 0x87 });
  } finally {
    await mismatch.endAsync(true).catch(() => {});
  }
  const wssCert = connect('wss-mtls', 5, 'wss-mtls-cert', { certOnly: true, sessionExpiry: 0 });
  try {
    await connected(wssCert, 'WSS 证书 CONNECT');
    const tlsSub = connect('tls', 5, 'wss-mtls-tls-sub', { sessionExpiry: 0 });
    try {
      await connected(tlsSub, 'mTLS 交叉 TLS 订阅');
      await tlsSub.subscribeAsync(`${prefix}mtls-cross`, { qos: 0 });
      const got = until(tlsSub, 'message', 'WSS-mTLS→TLS');
      await wssCert.publishAsync(`${prefix}mtls-cross`, Buffer.from('mtls-cross'), { qos: 0 });
      const [, bytes] = await got;
      assert.equal(bytes.toString(), 'mtls-cross');
      rows.push({ transport: 'wss-mtls', identity: 'certificate', cross: 'tls' });
    } finally {
      await tlsSub.endAsync(true);
    }
  } finally {
    await wssCert.endAsync(true);
  }
  console.log(JSON.stringify({ ok: true, action, client: metadata.version, rows }));
  process.exit(0);
}

throw new Error(`未知动作 ${action}`);
