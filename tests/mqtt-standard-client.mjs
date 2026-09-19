import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { once } from 'node:events';

// 依赖由独立消费者锁定安装，标准客户端不借用 Broker 的编解码器。
const require = createRequire(`${process.argv[2]}/package.json`);
const mqtt = require('mqtt');
const metadata = require('mqtt/package.json');
assert.equal(metadata.version, '5.15.0');
const port = Number(process.argv[3]);
const tls = process.argv[4] !== 'plain';
const ca = tls ? await readFile(process.argv[4]) : undefined;
const retained = process.argv[5] === 'retained';
const qos = process.argv[5] === 'qos2' ? 2 : process.argv[5] === 'qos1' ? 1 : 0;

if (['identity-seed', 'identity-resume', 'identity-cycle', 'identity-downgrade', 'identity-foreign'].includes(process.argv[5])) {
  const mode = process.argv[5];
  const protocolVersion = Number(process.argv[6]);
  const clientId = process.argv[7];
  const topic = `example/${clientId}`;
  const url = `${tls ? 'mqtts' : 'mqtt'}://127.0.0.1:${port}`;
  const options = { protocolVersion, username: process.env.MQTT_USERNAME, password: process.env.MQTT_PASSWORD,
    reconnectPeriod: 0, connectTimeout: 8000, keepalive: 5, ca, rejectUnauthorized: true };
  const clients = [];
  const make = (id, extra = {}) => {
    const client = mqtt.connect(url, { ...options, clientId: id, clean: true, ...extra });
    clients.push(client);
    return client;
  };
  const until = async (predicate, message, seconds = 15) => {
    const deadline = Date.now() + seconds * 1000;
    while (!(await predicate())) {
      assert(Date.now() < deadline, message);
      await new Promise(resolve => setTimeout(resolve, 25));
    }
  };
  const denied = async (extra, reason = 0x86) => {
    const client = make(clientId, extra);
    let connected = false;
    let failure;
    client.once('connect', () => { connected = true; });
    client.on('error', error => { failure = error; });
    await new Promise((resolve, reject) => {
      const timeout = setTimeout(() => reject(new Error('身份拒绝后没有真实关闭')), 10000);
      client.once('close', () => { clearTimeout(timeout); resolve(); });
    });
    assert.equal(connected, false);
    assert.equal(failure?.code, protocolVersion === 5 ? reason : reason === 0x87 ? 5 : 4);
    await client.endAsync(true);
  };
  try {
    if (mode === 'identity-downgrade') {
      await denied({ clean: false, properties: { sessionExpiryInterval: 86400 } }, 0x87);
      await denied({ clean: true }, 0x87);
      console.log('identity-downgrade-rejected');
    } else {
      const subscriber = make(clientId, { clean: false, properties: { sessionExpiryInterval: 86400 } });
      const deliveries = [];
      subscriber.on('message', (name, payload, packet) => deliveries.push({ name, payload, packet }));
      const acknowledgement = (await once(subscriber, 'connect'))[0];
      const seed = async () => {
        await subscriber.endAsync(false);
        const publisher = make(`${clientId}-p`);
        await once(publisher, 'connect');
        await publisher.publishAsync(topic, Buffer.from([0, 255, 1]), { qos: 1 });
        await publisher.publishAsync(topic, Buffer.alloc(131072, 0xa5), { qos: 2 });
        await publisher.endAsync(false);
      };
      if (mode === 'identity-seed') {
        assert.equal(acknowledgement.sessionPresent, false);
        await subscriber.subscribeAsync(topic, { qos: 2 });
        await seed();
        console.log('identity-seeded');
      } else if (mode === 'identity-foreign') {
        assert.equal(acknowledgement.sessionPresent, false, '其他稳定主体继承了旧会话');
        await subscriber.publishAsync(topic, Buffer.from('without-old-subscription'), { qos: 1 });
        await new Promise(resolve => setTimeout(resolve, 350));
        assert.deepEqual(deliveries, [], '其他稳定主体继承了旧订阅或旧积压');
        await subscriber.subscribeAsync(topic, { qos: 1 });
        const delivered = once(subscriber, 'message');
        await subscriber.publishAsync(topic, Buffer.from('new-principal-authorized'), { qos: 0 });
        const [name, payload] = await delivered;
        assert.equal(name, topic);
        assert.equal(payload.toString(), 'new-principal-authorized');
        await subscriber.endAsync(false);
        console.log('identity-foreign-session-isolated');
      } else {
        assert.equal(acknowledgement.sessionPresent, true);
        await until(() => deliveries.length === 2, '新凭据没有恢复旧的可靠积压');
        assert.deepEqual(deliveries.map(item => [item.name, item.packet.qos]), [[topic, 1], [topic, 2]]);
        assert.deepEqual(deliveries[0].payload, Buffer.from([0, 255, 1]));
        assert.deepEqual(deliveries[1].payload, Buffer.alloc(131072, 0xa5));
        await denied({ password: 'wrong-password' });
        await denied({ username: 'display-only', password: process.env.MQTT_PASSWORD });
        if (mode === 'identity-cycle') {
          let deniedGrant;
          const observe = packet => { if (packet.cmd === 'suback') deniedGrant = packet.granted[0]; };
          subscriber.on('packetreceive', observe);
          await subscriber.subscribeAsync('denied/topic', { qos: 1 }).catch(() => {});
          subscriber.off('packetreceive', observe);
          assert.equal(deniedGrant, protocolVersion === 5 ? 0x87 : 0x80);
          await seed();
          console.log('identity-session-cycled');
        } else {
          if (protocolVersion === 5) await denied({ username: 'example', password: 'mqtt-test-secret' });
          console.log('identity-ready');
          await until(async () => (await readFile(`${process.argv[2]}/identity-continue`, 'utf8').catch(() => '')) === 'continue', '没有收到迟到旧撤权完成信号');
          const delivered = once(subscriber, 'message');
          await subscriber.publishAsync(topic, Buffer.from('new-generation-active'), { qos: 1 });
          const [name, payload] = await delivered;
          assert.equal(name, topic);
          assert.equal(payload.toString(), 'new-generation-active');
          const closed = new Promise((resolve, reject) => {
            const timeout = setTimeout(() => reject(new Error('精确新凭据撤权没有关闭连接')), 12000);
            subscriber.once('close', () => { clearTimeout(timeout); resolve(); });
            subscriber.on('error', () => {});
          });
          console.log('identity-still-active');
          await closed;
          await subscriber.endAsync(true);
          await denied({});
          console.log('identity-revoked');
        }
      }
    }
  } finally {
    await Promise.all(clients.map(client => client.endAsync(true)));
  }
  process.exit(0);
}

if (process.argv[5] === 'wills') {
  for (const publisherVersion of [4, 5]) {
    for (const subscriberVersion of [4, 5]) {
      for (const level of [0, 1, 2]) {
        const id = `standard-will-${tls ? 'tls' : 'tcp'}-${publisherVersion}-${subscriberVersion}-${level}`;
        const topic = `example/${id}`;
        const subscriber = await connected(subscriberVersion, `${id}-s`, level);
        const payload = Buffer.from([0, 255, 254, 1]);
        const publisher = mqtt.connect(`${tls ? 'mqtts' : 'mqtt'}://127.0.0.1:${port}`, {
          protocolVersion: publisherVersion, clientId: id, username: 'example', password: process.env.MQTT_PASSWORD,
          clean: true, reconnectPeriod: 0, connectTimeout: 5000, ca, rejectUnauthorized: true,
          will: { topic, payload, qos: level, retain: true,
            properties: publisherVersion === 5 ? { contentType: 'binary/test', correlationData: Buffer.from([0, 255]) } : undefined },
        });
        try {
          await once(publisher, 'connect');
          await subscriber.subscribeAsync(topic, { qos: level });
          const [name, bytes, packet] = await received(subscriber, () => publisher.endAsync(true));
          assert.equal(name, topic);
          assert.deepEqual(bytes, payload);
          assert.equal(packet.qos, level);
          assert.equal(packet.retain, false);
          await subscriber.unsubscribeAsync(topic);
          const [, retainedBytes, retainedPacket] = await received(subscriber, () => subscriber.subscribeAsync(topic, { qos: level }));
          assert.deepEqual(retainedBytes, payload);
          assert.equal(retainedPacket.retain, true);
          if (publisherVersion === 5 && subscriberVersion === 5) {
            assert.equal(retainedPacket.properties.contentType, 'binary/test');
            assert.deepEqual(retainedPacket.properties.correlationData, Buffer.from([0, 255]));
          }
        } finally {
          await Promise.all([subscriber.endAsync(false), publisher.endAsync(true)]);
        }
      }
    }
  }
  console.log(`MQTT.js ${metadata.version} ${tls ? 'TLS' : 'TCP'} 双版本四种组合 × QoS 0/1/2 遗嘱及保留交付通过。`);
  process.exit(0);
}

if (process.argv[5] === 'shared') {
  const clients = [];
  try {
    for (const publisherVersion of [4, 5]) {
      const members = [];
      const collected = [];
      const topic = `example/standard-shared/${tls ? 'tls' : 'tcp'}/${publisherVersion}`;
      for (let index = 0; index < 3; index++) {
        const member = await connected(5, `standard-shared-${publisherVersion}-${index}`, 2);
        clients.push(member);
        members.push(member);
        member.on('message', (name, payload, packet) => collected.push({ index, name, payload, packet }));
        await member.subscribeAsync(`$share/${index === 2 ? 'other' : 'workers'}/${topic}`, {
          qos: 2, rh: index, rap: true, properties: { subscriptionIdentifier: 61 + index },
        });
      }
      const publisher = await connected(publisherVersion, `standard-shared-p-${publisherVersion}`, 2);
      clients.push(publisher);
      for (let sequence = 0; sequence < 6; sequence++) {
        const payload = Buffer.alloc(32768, sequence);
        payload[0] = 0xff;
        await publisher.publishAsync(topic, payload, { qos: (sequence % 2) + 1, retain: true });
        const until = Date.now() + 8000;
        while (collected.length < (sequence + 1) * 2 && Date.now() < until) await new Promise(resolve => setTimeout(resolve, 20));
        assert.equal(collected.length, (sequence + 1) * 2);
        const pair = collected.slice(sequence * 2);
        assert.equal(pair.filter(item => item.index === 2).length, 1);
        for (const item of pair) {
          assert.equal(item.name, topic);
          assert.deepEqual(item.payload, payload);
          assert.equal(item.packet.retain, true);
          assert.equal(item.packet.qos, (sequence % 2) + 1);
          assert.equal(item.packet.properties.subscriptionIdentifier, 61 + item.index);
        }
      }
      await members[0].unsubscribeAsync(`$share/workers/${topic}`);
      const before = collected.length;
      await publisher.publishAsync(topic, 'remaining', { qos: 1 });
      const until = Date.now() + 8000;
      while (collected.length < before + 2 && Date.now() < until) await new Promise(resolve => setTimeout(resolve, 20));
      assert.deepEqual(collected.slice(before).map(item => item.index).sort(), [1, 2]);
      await Promise.all([...members, publisher].map(client => client.endAsync(false)));
    }
    console.log(`MQTT.js ${metadata.version} ${tls ? 'TLS' : 'TCP'} 双版本发布者、多成员共享、独立组、二进制 QoS 1/2、RAP、标识及取消通过。`);
  } finally {
    await Promise.all(clients.map(client => client.endAsync(true)));
  }
  process.exit(0);
}

if (process.argv[5] === 'sessions') {
  for (const protocolVersion of [4, 5]) {
    const clientId = `standard-persistent-${protocolVersion}`;
    const topic = `example/standard-session/${protocolVersion}`;
    const options = { protocolVersion, clientId, username: 'example', password: process.env.MQTT_PASSWORD,
      clean: false, reconnectPeriod: 0, connectTimeout: 5000, properties: { sessionExpiryInterval: 86400 } };
    let subscriber = mqtt.connect(`mqtt://127.0.0.1:${port}`, options);
    assert.equal((await once(subscriber, 'connect'))[0].sessionPresent, false);
    await subscriber.subscribeAsync(topic, { qos: 2 });
    await subscriber.endAsync(false);
    const publisher = await connected(protocolVersion, `standard-session-publisher-${protocolVersion}`);
    await publisher.publishAsync(topic, Buffer.from([0, 255, 1]), { qos: 1 });
    await publisher.publishAsync(topic, Buffer.alloc(131072, 0xa5), { qos: 2 });
    await publisher.endAsync(false);
    subscriber = mqtt.connect(`mqtt://127.0.0.1:${port}`, options);
    const messages = [];
    const trace = [];
    subscriber.on('packetreceive', packet => trace.push(`${packet.cmd}:${packet.messageId ?? ''}:${packet.qos ?? ''}`));
    subscriber.on('packetsend', packet => trace.push(`>${packet.cmd}:${packet.messageId ?? ''}`));
    const completed = new Promise((resolve, reject) => {
      const timeout = setTimeout(() => reject(new Error(`标准客户端 v${protocolVersion} 离线队列恢复超时 ${JSON.stringify(trace)}`)), 6000);
      subscriber.on('message', (name, payload, packet) => {
        messages.push({ name, payload, packet });
        if (messages.length === 2) { clearTimeout(timeout); resolve(); }
      });
      subscriber.once('error', reject);
    });
    assert.equal((await once(subscriber, 'connect'))[0].sessionPresent, true);
    await completed;
    assert.equal(messages[0].name, topic);
    assert.equal(messages[0].packet.qos, 1);
    assert.deepEqual(messages[0].payload, Buffer.from([0, 255, 1]));
    assert.equal(messages[1].packet.qos, 2);
    assert.deepEqual(messages[1].payload, Buffer.alloc(131072, 0xa5));
    await new Promise(resolve => setTimeout(resolve, 300));
    await subscriber.endAsync(false);
  }
  console.log(`MQTT.js ${metadata.version} 双版本持久订阅及离线 QoS 1/2 队列恢复通过。`);
  process.exit(0);
}

for (const protocolVersion of qos === 0 && !retained ? [4, 5] : []) {
  for (const tlsVersion of tls ? ['TLSv1.2', 'TLSv1.3', undefined] : [undefined]) {
    let sentPings = 0;
    let receivedPings = 0;
    const client = mqtt.connect(`${tls ? 'mqtts' : 'mqtt'}://127.0.0.1:${port}`, {
      protocolVersion,
      clientId: `independent-${protocolVersion}-${tlsVersion ?? 'plain'}`,
      username: 'example',
      password: process.env.MQTT_PASSWORD,
      clean: true,
      reconnectPeriod: 0,
      connectTimeout: 3000,
      keepalive: 1,
      ca,
      minVersion: tlsVersion,
      maxVersion: tlsVersion,
      rejectUnauthorized: true,
    });
    client.on('packetsend', packet => { if (packet.cmd === 'pingreq') sentPings++; });
    client.on('packetreceive', packet => { if (packet.cmd === 'pingresp') receivedPings++; });
    const failure = new Promise((_, reject) => client.on('error', reject));
    try {
      await Promise.race([
        new Promise(resolve => client.once('connect', connack => {
          assert.equal(connack.sessionPresent, false);
          if (tls) assert.equal(client.stream.getProtocol(), tlsVersion ?? 'TLSv1.3');
          resolve();
        })),
        failure,
      ]);
      await Promise.race([new Promise(resolve => setTimeout(resolve, 2200)), failure]);
      assert.ok(sentPings >= 2 && receivedPings >= 2, '标准客户端保活未完成');
      await client.endAsync(false);
    } finally {
      if (!client.disconnected) await client.endAsync(true);
    }
  }
}

async function connected(protocolVersion, id, deliveryQos = qos) {
  const client = mqtt.connect(`${tls ? 'mqtts' : 'mqtt'}://127.0.0.1:${port}`, {
    protocolVersion, clientId: id, username: 'example', password: process.env.MQTT_PASSWORD,
    clean: true, reconnectPeriod: 0, connectTimeout: 3000, keepalive: 10, ca, rejectUnauthorized: true,
    // 5.15.0 的 QoS 2 incomingStore 保存未展开的 Topic；完整 Topic 路径仍作兼容回归。
    properties: protocolVersion === 5 && deliveryQos !== 2 ? { topicAliasMaximum: 1 } : undefined,
    autoAssignTopicAlias: protocolVersion === 5,
    autoUseTopicAlias: protocolVersion === 5,
  });
  await once(client, 'connect');
  return client;
}

async function received(client, action) {
  let deadline;
  try {
    const packet = once(client, 'message');
    await action();
    return await Promise.race([packet, new Promise((_, reject) => {
      deadline = setTimeout(() => reject(new Error('标准客户端没有收到完整消息')), 3000);
    })]);
  } finally {
    clearTimeout(deadline);
  }
}

for (const publisherVersion of retained ? [] : [4, 5]) {
  for (const subscriberVersion of [4, 5]) {
    const subscriber = await connected(subscriberVersion, `subscriber-${publisherVersion}-${subscriberVersion}`);
    const publisher = await connected(publisherVersion, `publisher-${publisherVersion}-${subscriberVersion}`);
    const topic = `example/标准/二进制/${publisherVersion}/${subscriberVersion}`;
    try {
      const grants = await subscriber.subscribeAsync(topic, { qos: qos === 0 ? 2 : qos });
      assert.equal(grants[0].qos, qos, '必须明确授予已配置的最大 QoS');
      for (const payload of [Buffer.alloc(0), Buffer.from(Array.from({ length: 256 }, (_, index) => index)), Buffer.alloc(262144, 0xa5)]) {
        const properties = publisherVersion === 5 ? {
          contentType: 'application/octet-stream', correlationData: Buffer.from([0, 255, 0]),
          responseTopic: 'example/response', userProperties: { same: ['first', 'second'] },
          messageExpiryInterval: 60,
        } : undefined;
        const [receivedTopic, bytes, packet] = await received(subscriber, () => publisher.publishAsync(topic, payload, { qos, properties }));
        assert.equal(receivedTopic, topic);
        assert.deepEqual(bytes, payload);
        assert.equal(packet.qos, qos);
        if (subscriberVersion === 5 && qos !== 2) assert.equal(packet.properties.topicAlias, 1);
        if (publisherVersion === 5 && subscriberVersion === 5) {
          assert.deepEqual(packet.properties.correlationData, properties.correlationData);
          assert.deepEqual(packet.properties.userProperties.same, ['first', 'second']);
          assert.equal(packet.properties.contentType, properties.contentType);
          assert.ok(packet.properties.messageExpiryInterval > 0 && packet.properties.messageExpiryInterval <= 60);
        }
      }
      let unexpected = 0;
      subscriber.on('message', () => unexpected++);
      await publisher.publishAsync(`${topic}/different`, '精确 Topic 不匹配', { qos: 0 });
      await subscriber.unsubscribeAsync(topic);
      await publisher.publishAsync(topic, '取消后不再交付', { qos: 0 });
      // 发布者的 SUBACK 是前面两个 QoS 0 输入已被 Broker 消费的有序屏障。
      await publisher.subscribeAsync('example/barrier');
      await new Promise(resolve => setTimeout(resolve, 100));
      assert.equal(unexpected, 0);
    } finally {
      await Promise.all([subscriber.endAsync(true), publisher.endAsync(true)]);
    }
  }
}
if (!retained) {
  for (const protocolVersion of [4, 5]) {
    const subscriber = await connected(protocolVersion, `options-subscriber-${protocolVersion}`);
    const publisher = await connected(protocolVersion, `options-publisher-${protocolVersion}`);
    const filter = `example/标准选项/${tls ? 'tls' : 'tcp'}/${qos}/${protocolVersion}/+`;
    const topic = `example/标准选项/${tls ? 'tls' : 'tcp'}/${qos}/${protocolVersion}/`;
    const subscription = id => ({ qos, ...(protocolVersion === 5 ? { rh: 2, rap: true, properties: { subscriptionIdentifier: id } } : {}) });
    try {
      await subscriber.subscribeAsync(filter, subscription(71));
      await subscriber.subscribeAsync(topic, subscription(72));
      const payload = Buffer.from([0, 255, 0, 128]);
      const [name, bytes, packet] = await received(subscriber, () => publisher.publishAsync(topic, payload, { qos, retain: qos > 0 }));
      assert.equal(name, topic);
      assert.deepEqual(bytes, payload);
      assert.equal(packet.qos, qos);
      assert.equal(packet.retain, protocolVersion === 5 && qos > 0);
      if (protocolVersion === 5) assert.deepEqual([...packet.properties.subscriptionIdentifier].sort((a, b) => a - b), [71, 72]);
      await subscriber.subscribeAsync(filter, subscription(73));
      await subscriber.unsubscribeAsync(topic);
      const [, , replaced] = await received(subscriber, () => publisher.publishAsync(topic, payload, { qos }));
      if (protocolVersion === 5) assert.equal(replaced.properties.subscriptionIdentifier, 73);
      await subscriber.unsubscribeAsync(filter);
      let unexpected = 0;
      subscriber.on('message', () => unexpected++);
      await publisher.publishAsync(topic, 'cancelled', { qos });
      await publisher.subscribeAsync('example/options-barrier');
      await new Promise(resolve => setTimeout(resolve, 100));
      assert.equal(unexpected, 0);
    } finally {
      await Promise.all([subscriber.endAsync(true), publisher.endAsync(true)]);
    }
  }
}
if (retained) {
  for (const publisherVersion of [4, 5]) {
    for (const subscriberVersion of [4, 5]) {
      for (const level of [0, 1, 2]) {
        const name = `${publisherVersion}-${subscriberVersion}-${level}`;
        const topic = `example/retained-standard/${name}`;
        const publisher = await connected(publisherVersion, `retained-p-${name}`, level);
        const live = await connected(subscriberVersion, `retained-live-${name}`, level);
        const later = await connected(subscriberVersion, `retained-later-${name}`, level);
        try {
          await live.subscribeAsync(topic, { qos: level });
          const payload = Buffer.alloc(98304, 0xa5);
          payload[0] = 0;
          payload[1] = 255;
          const properties = publisherVersion === 5 ? { correlationData: Buffer.from([0, 255]), contentType: 'application/octet-stream' } : undefined;
          // QoS 0 publishAsync 只证明本地排队；先观察真实交付，再创建新订阅。
          const [liveTopic, liveBytes, livePacket] = await received(live, () => publisher.publishAsync(topic, payload, { qos: level, retain: true, properties }));
          assert.equal(liveTopic, topic);
          assert.deepEqual(liveBytes, payload);
          assert.equal(livePacket.retain, false);
          const [replayTopic, replayBytes, replayPacket] = await received(later, () => later.subscribeAsync(topic, { qos: level }));
          assert.equal(replayTopic, topic);
          assert.deepEqual(replayBytes, payload);
          assert.equal(replayPacket.qos, level);
          assert.equal(replayPacket.retain, true);
          if (publisherVersion === 5 && subscriberVersion === 5) {
            assert.deepEqual(replayPacket.properties.correlationData, Buffer.from([0, 255]));
            assert.equal(replayPacket.properties.contentType, 'application/octet-stream');
          }
          await later.unsubscribeAsync(topic);
          await received(live, () => publisher.publishAsync(topic, Buffer.from('replacement'), { qos: level, retain: true }));
          const [, replacedBytes, replacedPacket] = await received(later, () => later.subscribeAsync(topic, { qos: level }));
          assert.equal(replacedBytes.toString(), 'replacement');
          assert.equal(replacedPacket.retain, true);
          await later.unsubscribeAsync(topic);
          const [, clearedBytes, clearedPacket] = await received(live, () => publisher.publishAsync(topic, Buffer.alloc(0), { qos: level, retain: true }));
          assert.equal(clearedBytes.length, 0);
          assert.equal(clearedPacket.retain, false);
          let unexpected = 0;
          later.on('message', () => unexpected++);
          await later.subscribeAsync(topic, { qos: level });
          await new Promise(resolve => setTimeout(resolve, 700));
          assert.equal(unexpected, 0, '清除后仍向新订阅交付保留消息');
          await assert.rejects(later.subscribeAsync('denied/retained', { qos: level }));
        } finally {
          await Promise.all([publisher.endAsync(true), live.endAsync(true), later.endAsync(true)]);
        }
      }
    }
  }
  console.log(`MQTT.js ${metadata.version} ${tls ? 'TLS' : 'TCP'} 双版本四种组合 × QoS 0/1/2 的保留保存、替换、删除、属性与拒绝授权通过。`);
} else {
  console.log(`MQTT.js ${metadata.version} 双版本${tls ? ' TLS 1.2/1.3' : ' TCP'}及四种版本组合的二进制 QoS ${qos} 发布/订阅/取消通过。`);
}
