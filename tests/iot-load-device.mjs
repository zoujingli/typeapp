import assert from 'node:assert/strict';
import { DatabaseSync } from 'node:sqlite';
import { chmodSync, mkdirSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { createRequire } from 'node:module';
import { randomBytes } from 'node:crypto';
import { performance } from 'node:perf_hooks';
import { once } from 'node:events';
import { baseline, baselineHash, contentHash, envelope, reconnectDelay } from './iot-load-model.mjs';

// 每个分片一个FULL同步SQLite。只保存参考设备自己的原件/回执，绝不直接写被测平台。
export class LoadCache {
  constructor(file, shardIdentity, maximumMiB = 256) {
    assert.ok(Number.isInteger(maximumMiB) && maximumMiB >= 32 && maximumMiB <= 65536);
    mkdirSync(dirname(file), { recursive: true, mode: 0o700 });
    try {
      this.guard = new DatabaseSync(file + '.guard'); chmodSync(file + '.guard', 0o600);
      this.guard.exec('PRAGMA busy_timeout=0; CREATE TABLE IF NOT EXISTS guard (id INTEGER PRIMARY KEY); BEGIN EXCLUSIVE;');
      this.guardLocked = true;
    this.db = new DatabaseSync(file); chmodSync(file, 0o600);
    assert.equal(this.db.prepare('PRAGMA page_size').get().page_size, 4096, 'load_cache_page_size');
    this.db.exec(`PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL; PRAGMA busy_timeout=1000; PRAGMA cache_size=-8192;
      PRAGMA wal_autocheckpoint=256; PRAGMA journal_size_limit=4194304; PRAGMA max_page_count=${maximumMiB * 256};
      CREATE TABLE IF NOT EXISTS metadata (key TEXT PRIMARY KEY,value TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS devices (id TEXT PRIMARY KEY, ownership TEXT NOT NULL, sequence TEXT NOT NULL DEFAULT '0',
        pending INTEGER NOT NULL DEFAULT 0, bytes INTEGER NOT NULL DEFAULT 0, not_admitted INTEGER NOT NULL DEFAULT 0);
      CREATE TABLE IF NOT EXISTS messages (device TEXT NOT NULL, sequence TEXT NOT NULL, payload TEXT NOT NULL, bytes INTEGER NOT NULL,
        content_hash TEXT NOT NULL, type TEXT NOT NULL, sampled_ms INTEGER NOT NULL, first_sent_ms INTEGER, receipt_ms INTEGER,
        status TEXT, code TEXT, server_received_at INTEGER, PRIMARY KEY(device,sequence));
      CREATE INDEX IF NOT EXISTS pending ON messages(device,length(sequence),sequence) WHERE status IS NULL;
      CREATE TABLE IF NOT EXISTS commands (device TEXT NOT NULL,id TEXT NOT NULL, content_hash TEXT NOT NULL,
        receipt TEXT NOT NULL, retain_until INTEGER NOT NULL, pending INTEGER NOT NULL DEFAULT 1, PRIMARY KEY(device,id));
      CREATE TABLE IF NOT EXISTS queries (device TEXT PRIMARY KEY, payload TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS control_requests (id TEXT PRIMARY KEY, device TEXT NOT NULL, round INTEGER NOT NULL,
        requested_ms INTEGER NOT NULL, accepted_ms INTEGER, terminal_ms INTEGER, status TEXT NOT NULL DEFAULT 'pending',
        observed_ms INTEGER, attempts INTEGER NOT NULL DEFAULT 0, query_id TEXT, query_at INTEGER);
      CREATE TABLE IF NOT EXISTS connections (device TEXT NOT NULL, attempt INTEGER NOT NULL, started_ms INTEGER NOT NULL,
        connack_ms INTEGER, session_present INTEGER, subscribed_ms INTEGER, closed_ms INTEGER, jitter_ms INTEGER, delay_ms INTEGER,
        PRIMARY KEY(device,attempt));
      CREATE TABLE IF NOT EXISTS load_observations (observed_ms INTEGER PRIMARY KEY, phase TEXT NOT NULL, state TEXT NOT NULL,
        pending_messages INTEGER, pending_bytes INTEGER, local_pending INTEGER NOT NULL);`);
    const messageColumns = this.db.prepare('PRAGMA table_info(messages)').all().map(column => column.name);
    if (!messageColumns.includes('publish_attempts')) this.db.exec('ALTER TABLE messages ADD COLUMN publish_attempts INTEGER NOT NULL DEFAULT 0');
    if (!this.db.prepare('PRAGMA table_info(connections)').all().some(column => column.name === 'restored_subscription')) this.db.exec('ALTER TABLE connections ADD COLUMN restored_subscription INTEGER');
    const columns = this.db.prepare('PRAGMA table_info(control_requests)').all().map(column => column.name);
    if (!columns.includes('query_round')) this.db.exec('ALTER TABLE control_requests ADD COLUMN query_round INTEGER');
    if (!columns.includes('query_pending')) this.db.exec('ALTER TABLE control_requests ADD COLUMN query_pending INTEGER NOT NULL DEFAULT 0; UPDATE control_requests SET query_pending=1 WHERE query_id IS NOT NULL');
    this.db.exec('CREATE INDEX IF NOT EXISTS unfinished_controls ON control_requests(device,observed_ms,round,id) WHERE terminal_ms IS NULL AND attempts>0');
    const identity = JSON.stringify({ baseline_sha256: baselineHash, ...shardIdentity });
    const existing = this.db.prepare('SELECT value FROM metadata WHERE key=?').get('identity');
    if (existing) assert.equal(existing.value, identity, 'load_cache_identity_mismatch');
    else this.db.prepare('INSERT INTO metadata VALUES (?,?)').run('identity', identity);
    } catch (error) {
      try { this.close(); } catch (cleanup) { throw new AggregateError([error, cleanup], 'load_cache_initialization_cleanup_failed'); }
      throw error;
    }
  }
  transaction(action) {
    this.db.exec('BEGIN IMMEDIATE');
    try { const result = action(); this.db.exec('COMMIT'); return result; }
    catch (error) { this.db.exec('ROLLBACK'); throw error; }
  }
  enqueue(device, round, sampledMs, type = 'telemetry', signalRound = round) {
    return this.transaction(() => {
      const cursorKey = `${type}:${device.id}`;
      const cursor = this.db.prepare('SELECT value FROM metadata WHERE key=?').get(cursorKey);
      assert.equal(round, cursor ? Number(cursor.value) : 0, 'load_sampling_cursor_mismatch');
      this.db.prepare('INSERT OR IGNORE INTO devices(id,ownership) VALUES (?,?)').run(device.id, device.ownership_id);
      const state = this.db.prepare('SELECT * FROM devices WHERE id=?').get(device.id);
      assert.equal(state.ownership, device.ownership_id, 'load_cache_ownership_mismatch');
      const sequence = String(BigInt(state.sequence) + 1n);
      const payload = envelope(device, sequence, Math.floor(sampledMs / 1000), round, type, signalRound);
      const bytes = Buffer.byteLength(payload);
      if (state.pending >= baseline.cache_maximum_records || state.bytes + bytes > baseline.cache_maximum_bytes) {
        this.db.prepare('UPDATE devices SET not_admitted=not_admitted+1 WHERE id=?').run(device.id);
        this.db.prepare('INSERT INTO metadata VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value').run(cursorKey, String(round + 1));
        return false;
      }
      this.db.prepare('INSERT INTO messages(device,sequence,payload,bytes,content_hash,type,sampled_ms) VALUES (?,?,?,?,?,?,?)')
        .run(device.id, sequence, payload, bytes, contentHash(JSON.parse(payload)), type, sampledMs);
      this.db.prepare('UPDATE devices SET sequence=?,pending=pending+1,bytes=bytes+? WHERE id=?').run(sequence, bytes, device.id);
      this.db.prepare('INSERT INTO metadata VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value').run(cursorKey, String(round + 1));
      return true;
    });
  }
  pending(device) { return this.db.prepare('SELECT * FROM messages WHERE device=? AND status IS NULL ORDER BY length(sequence),sequence LIMIT 1').get(device); }
  sent(device, sequence) { this.db.prepare('UPDATE messages SET first_sent_ms=coalesce(first_sent_ms,?),publish_attempts=publish_attempts+1 WHERE device=? AND sequence=?').run(Date.now(), device, sequence); }
  receipt(device, message) {
    assert.deepEqual(Object.keys(message).sort(), ['app_version','type','device_id','ownership_id','sequence','content_hash','status','code','received_at'].sort());
    assert.equal(message.app_version, 1); assert.equal(message.type, 'ingestion_receipt');
    assert.equal(message.device_id, device.id); assert.equal(message.ownership_id, device.ownership_id);
    assert.ok(Number.isSafeInteger(message.received_at) && message.received_at > 0);
    assert.match(message.sequence, /^[1-9][0-9]{0,37}$/);
    assert.ok(message.status === 'accepted' ? message.code === 'accepted' : message.status === 'rejected'
      && ['content_conflict','device_unavailable','invalid_envelope','model_mismatch','clock_ahead','sample_expired','invalid_model_values'].includes(message.code));
    return this.transaction(() => {
      const original = this.db.prepare('SELECT * FROM messages WHERE device=? AND sequence=?').get(device.id, message.sequence);
      if (!original || original.status !== null) return null;
      assert.equal(original.content_hash, message.content_hash, 'load_receipt_hash_mismatch');
      // 一次持久事务保存观察再释放原件；保留轻量ID账本用于已确认集合对照，受分片磁盘硬上限约束。
      this.db.prepare('UPDATE messages SET payload=?,status=?,code=?,receipt_ms=?,server_received_at=? WHERE device=? AND sequence=?')
        .run('', message.status, message.code, Date.now(), message.received_at, device.id, message.sequence);
      this.db.prepare('UPDATE devices SET pending=pending-1,bytes=bytes-? WHERE id=?').run(original.bytes, device.id);
      return original;
    });
  }
  close() {
    if (this.closed) return; this.closed = true;
    const failures = [];
    for (const action of [() => this.db?.exec('PRAGMA wal_checkpoint(TRUNCATE)'), () => this.db?.close(),
      () => { if (this.guardLocked) this.guard.exec('ROLLBACK'); }, () => this.guard?.close()]) {
      try { action(); } catch (error) { failures.push(error); }
    }
    if (failures.length) throw new AggregateError(failures, 'load_cache_cleanup_failed');
  }
}

export class LoadDevice {
  constructor(record, cache, config, metrics) {
    this.device = { ...record.device, index: record.index }; this.credential = record.credential;
    this.cache = cache; this.config = config; this.metrics = metrics;
    this.client = null; this.busy = false; this.nextConnect = 0; this.connecting = false; this.failures = 0;
    this.clock = null; this.challenge = null; this.lastTimeRequest = 0;
    this.inflight = null; this.fatal = null; this.closed = false;
    this.stopSignal = new AbortController(); this.ending = null; this.closePromise = null;
    this.pausedUntil = 0;
  }
  async connect() {
    if (this.closed || this.connecting || this.client || this.ending || Date.now() < Math.max(this.nextConnect, this.pausedUntil)) return;
    this.connecting = true;
    let client;
    try {
    assert.ok(process.env.TYPE_MQTT_CLIENT_ROOT, 'TYPE_MQTT_CLIENT_ROOT is required');
    const require = createRequire(resolve(process.env.TYPE_MQTT_CLIENT_ROOT, 'package.json'));
    assert.equal(require('mqtt/package.json').version, '5.15.0');
    const started = performance.now();
    const attempt = this.cache.db.prepare('SELECT coalesce(max(attempt),0)+1 AS next FROM connections WHERE device=?').get(this.device.id).next;
    this.cache.db.prepare('INSERT INTO connections(device,attempt,started_ms) VALUES (?,?,?)').run(this.device.id, attempt, Date.now());
    client = require('mqtt').connect(this.config.mqtt, { protocolVersion: 5,
      clientId: this.credential.client_id, username: this.credential.username, password: this.credential.password,
      keepalive: 30, clean: false, reconnectPeriod: 0, connectTimeout: 15000,
      ca: readFileSync(this.config.ca), rejectUnauthorized: true, queueQoSZero: false,
      properties: { sessionExpiryInterval: 86400, receiveMaximum: 8, maximumPacketSize: 32768 },
    });
    this.client = client;
    client.on('error', () => { this.metrics.increment('mqtt_errors'); });
    client.on('close', () => {
      if (this.client === client) this.client = null;
      this.clock = null; this.challenge = null; this.inflight = null;
      this.metrics.increment('disconnects');
      const backoff = reconnectDelay(this.config.reconnect_seed ?? baseline.seed + ':reconnect-v1', this.device.index, attempt, ++this.failures);
      this.nextConnect = Math.max(Date.now(), this.pausedUntil) + backoff.delay_ms;
      try { this.cache.db.prepare('UPDATE connections SET closed_ms=?,jitter_ms=?,delay_ms=? WHERE device=? AND attempt=?')
        .run(Date.now(), backoff.jitter_ms, backoff.delay_ms, this.device.id, attempt); }
      catch (error) { this.fatal ??= error; }
    });
    // handleMessage的完成回调晚于本地事务，MQTT下行ACK不能先于持久业务处理。
    client.handleMessage = (packet, done) => {
      try { this.delivery(packet); done(); }
      catch (error) {
        this.fatal = error; this.metrics.increment('invalid_deliveries'); done(error);
        void this.stopClient(client).catch(cleanup => { this.fatal = new AggregateError([error, cleanup], 'load_delivery_cleanup_failed'); });
      }
    };
      const [connack] = await once(client, 'connect', { signal: AbortSignal.any([AbortSignal.timeout(16000), this.stopSignal.signal]) });
      if (this.closed) return;
      this.cache.db.prepare('UPDATE connections SET connack_ms=?,session_present=? WHERE device=? AND attempt=?')
        .run(Date.now(), connack.sessionPresent ? 1 : 0, this.device.id, attempt);
      this.metrics.increment(connack.sessionPresent ? 'session_present' : 'session_absent');
      // 恢复会话不重新SUBSCRIBE，随后真实业务投递才可证明旧订阅仍可用。
      const knownSubscription = this.cache.db.prepare('SELECT 1 FROM connections WHERE device=? AND subscribed_ms IS NOT NULL LIMIT 1').get(this.device.id);
      const restoredSubscription = connack.sessionPresent && Boolean(knownSubscription);
      if (!restoredSubscription) {
        const grants = await this.bounded(client.subscribeAsync(this.credential.topics.subscribe, { qos: 1, rh: 2, rap: true }), 'load_subscribe_timeout');
        assert.ok(grants.length > 0 && grants.every(grant => grant.qos === 1), 'load_subscription_rejected');
      }
      this.cache.db.prepare('UPDATE connections SET subscribed_ms=?,restored_subscription=? WHERE device=? AND attempt=?').run(Date.now(), restoredSubscription ? 1 : 0, this.device.id, attempt);
      this.failures = 0; this.metrics.observe('connect_ms', performance.now() - started);
      this.metrics.increment('connections');
    } catch (error) {
      if (!client || error instanceof TypeError || error instanceof SyntaxError || error.code?.startsWith('ERR_SQLITE') || error.code === 'ERR_ASSERTION') this.fatal = error;
      if (client) await this.stopClient(client);
      if (!this.closed) this.metrics.increment('connect_failures');
      if (this.fatal) throw this.fatal;
    }
    finally { this.connecting = false; }
  }
  async tick() {
    if (this.fatal) throw this.fatal;
    if (!this.client?.connected || this.connecting || this.busy) return;
    this.busy = true;
    try {
      if (this.inflight && Date.now() - this.inflight.sent > 15000) {
        this.metrics.increment('receipt_timeouts'); await this.stopClient(this.client); return;
      }
      if (performance.now() - this.lastTimeRequest >= 20000 || this.lastTimeRequest === 0) {
        const nonce = randomBytes(16).toString('hex');
        this.challenge = { nonce, at: performance.now() }; this.lastTimeRequest = performance.now();
        await this.publish({ app_version: 1, type: 'time_request', device_id: this.device.id,
          ownership_id: this.device.ownership_id, nonce });
      }
      const query = this.cache.db.prepare('SELECT payload FROM queries WHERE device=?').get(this.device.id);
      if (query) {
        await this.publishRaw(query.payload);
        // 上行PUBACK表明查询结果已持久进入Broker；未确认时原件跨重启保留。
        this.cache.db.prepare('DELETE FROM queries WHERE device=? AND payload=?').run(this.device.id, query.payload);
        this.metrics.increment('query_results_published');
      }
      if (performance.now() - (this.commandSentAt ?? -Infinity) >= 5000) {
        const pendingCommand = this.cache.db.prepare('SELECT receipt FROM commands WHERE device=? AND pending=1 ORDER BY id LIMIT 1').get(this.device.id);
        if (pendingCommand) { await this.publish(JSON.parse(pendingCommand.receipt)); this.commandSentAt = performance.now(); }
      }
      if (!this.inflight) {
        const pending = this.cache.pending(this.device.id);
        if (pending) {
          this.cache.sent(this.device.id, pending.sequence);
          this.inflight = { sequence: pending.sequence, sent: Date.now() };
          const started = performance.now();
          await this.publishRaw(pending.payload);
          this.metrics.observe('puback_ms', performance.now() - started);
          this.metrics.increment(`${pending.type}_published`);
        }
      }
    } catch (error) {
      if (error instanceof TypeError || error instanceof SyntaxError || error.code?.startsWith('ERR_SQLITE') || error.code === 'ERR_ASSERTION') { this.fatal = error; throw error; }
      this.metrics.increment('publish_failures'); await this.stopClient(this.client);
    }
    finally { this.busy = false; }
  }
  async publishRaw(payload) {
    const client = this.client;
    if (!client?.connected || this.closed) throw new Error('load_device_disconnected');
    await this.bounded(client.publishAsync(this.credential.topics.publish, payload, { qos: 1 }), 'load_publish_timeout');
  }
  async bounded(operation, code, milliseconds = 15000) {
    let timer;
    try {
      return await Promise.race([operation, new Promise((_, reject) => { timer = setTimeout(() => reject(Object.assign(new Error(code), { code: code.toUpperCase() })), milliseconds); })]);
    } finally { clearTimeout(timer); }
  }
  publish(message) { return this.publishRaw(JSON.stringify(message)); }
  trustedTime() {
    if (!this.clock || performance.now() - this.clock.at > 30000) return null;
    return this.clock.upper + Math.ceil((performance.now() - this.clock.at) / 1000);
  }
  delivery(packet) {
    assert.equal(packet.topic, this.credential.topics.subscribe); assert.equal(packet.qos, 1); assert.equal(packet.retain, false);
    assert.ok(packet.payload.length <= 16384);
    const message = JSON.parse(packet.payload.toString());
    assert.equal(message.app_version, 1); assert.equal(message.device_id, this.device.id); assert.equal(message.ownership_id, this.device.ownership_id);
    if (message.type === 'ingestion_receipt') {
      const original = this.cache.receipt(this.device, message);
      if (original) {
        this.metrics.increment(`${original.type}_${message.status}`);
        this.metrics.observe('receipt_ms', Date.now() - original.first_sent_ms);
        this.metrics.observe('sample_to_receipt_ms', Date.now() - original.sampled_ms);
      }
      if (this.inflight?.sequence === message.sequence) this.inflight = null;
    } else if (message.type === 'time_response') {
      const elapsed = this.challenge ? performance.now() - this.challenge.at : Infinity;
      if (message.nonce === this.challenge?.nonce && elapsed <= 15000 && Number.isSafeInteger(message.server_time)) {
        this.clock = { at: performance.now(), upper: message.server_time + Math.ceil(elapsed / 1000) + 1, lower: message.server_time };
      } else this.clock = null;
      this.challenge = null;
    } else if (message.type === 'command') this.command(message);
    else if (message.type === 'command_receipt_ack') {
      const stored = this.cache.db.prepare('SELECT * FROM commands WHERE device=? AND id=?').get(this.device.id, message.command_id);
      if (stored) {
        // 结果摘要仍与正式ack契约核验后才标记，未确认条目继续保留。
        const receipt = JSON.parse(stored.receipt);
        assert.deepEqual(Object.keys(message).sort(), ['app_version','type','device_id','ownership_id','command_id','result_hash'].sort());
        if (message.result_hash === contentHash(receipt)) this.cache.db.prepare('UPDATE commands SET pending=0 WHERE device=? AND id=?').run(this.device.id, message.command_id);
      }
    } else if (message.type === 'command_query') {
      const stored = this.cache.db.prepare('SELECT * FROM commands WHERE device=? AND id=?').get(this.device.id, message.command_id);
      if (stored) assert.equal(stored.content_hash, message.content_hash);
      // 查询从同一本地持久结果取原件，不触发模拟动作；异步发送由现有tick单路完成。
      const payload = JSON.stringify({ ...message, type: 'command_query_result', receipt: stored ? JSON.parse(stored.receipt) : null });
      const previous = this.cache.db.prepare('SELECT payload FROM queries WHERE device=?').get(this.device.id);
      assert.ok(!previous || previous.payload === payload, 'load_query_capacity');
      this.cache.db.prepare('INSERT OR IGNORE INTO queries(device,payload) VALUES (?,?)').run(this.device.id, payload);
    } else throw new Error('load_unexpected_delivery');
  }
  command(message) {
    assert.match(message.command_id, /^[a-f0-9]{32}$/); assert.equal(message.identifier, 'set_relay');
    assert.deepEqual(Object.keys(message.values), ['on']); assert.equal(typeof message.values.on, 'boolean');
    assert.ok(Number.isSafeInteger(message.issued_at) && message.issued_at > 0); assert.equal(message.deadline_at, message.issued_at + 60);
    const hash = contentHash(message); const now = this.trustedTime();
    this.cache.transaction(() => {
      const old = this.cache.db.prepare('SELECT * FROM commands WHERE device=? AND id=?').get(this.device.id, message.command_id);
      if (old) {
        assert.equal(old.content_hash, hash); this.metrics.increment('duplicate_commands');
        this.cache.db.prepare('UPDATE commands SET pending=1 WHERE device=? AND id=?').run(this.device.id, message.command_id); return;
      }
      if (this.clock) this.cache.db.prepare('DELETE FROM commands WHERE device=? AND retain_until<=? AND pending=0').run(this.device.id, this.clock.lower);
      assert.ok(this.cache.db.prepare('SELECT count(*) AS total FROM commands WHERE device=?').get(this.device.id).total < 10000, 'load_command_capacity');
      const code = now === null || now < message.issued_at ? 'clock_untrusted' : now >= message.deadline_at ? 'command_expired'
        : message.model_version !== this.device.model_version ? 'model_mismatch' : 'executed';
      const receipt = { app_version: 1, type: 'command_receipt', device_id: this.device.id, ownership_id: this.device.ownership_id,
        command_id: message.command_id, content_hash: hash, status: code === 'executed' ? 'succeeded' : 'rejected', code,
        started_at: code === 'executed' ? now : null, finished_at: code === 'executed' ? now : null, result: code === 'executed' ? { simulated: true, on: message.values.on } : {} };
      // 参考动作仅为事务内持久保存目标继电器值，无外部物理副作用；不会冒充真实设备掉电验收。
      this.cache.db.prepare('INSERT INTO commands(device,id,content_hash,receipt,retain_until) VALUES (?,?,?,?,?)')
        .run(this.device.id, message.command_id, hash, JSON.stringify(receipt), message.deadline_at + 86400);
      this.metrics.increment(code === 'executed' ? 'simulated_actions' : 'command_rejections');
    });
  }
  stopClient(client) {
    if (this.ending) return this.ending;
    if (!client) return Promise.resolve();
    const ending = this.bounded(Promise.resolve().then(() => client.endAsync(true)), 'load_device_shutdown_timeout', 5000)
      .catch(error => {
        this.fatal ??= error;
        try { client.stream?.destroy(); } catch (cleanup) { throw new AggregateError([error, cleanup], 'load_stream_cleanup_failed'); }
        throw error;
      })
      .finally(() => { if (this.client === client) this.client = null; this.ending = null; });
    this.ending = ending;
    return ending;
  }
  close() {
    if (!this.closePromise) {
      this.closed = true; this.stopSignal.abort();
      this.closePromise = this.stopClient(this.client);
    }
    return this.closePromise;
  }
}
