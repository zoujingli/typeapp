import assert from 'node:assert/strict';
import { DatabaseSync } from 'node:sqlite';
import { chmodSync, existsSync, mkdirSync, readFileSync, realpathSync, renameSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { performance, monitorEventLoopDelay } from 'node:perf_hooks';
import { setTimeout as delay } from 'node:timers/promises';
import { baseline, baselineHash, coordinates, deviceIndices, ownerLogin, loadPhases, phaseSample, digest, values, webSelection, sampleReport } from './iot-load-model.mjs';

// 负载控制器只调用公开HTTP；数据库只保存本工具的配置、一次性凭据和恢复进度。
export class LoadState {
  constructor(file, writable = true) {
    mkdirSync(dirname(file), { recursive: true, mode: 0o700 });
    // 独立DELETE日志锁：准备独占、不同运行分片共享；进程退出由SQLite释放，不能按时间猜测抢占。
    try {
      this.guard = new DatabaseSync(file + '.guard');
      chmodSync(file + '.guard', 0o600);
      this.guard.exec('PRAGMA busy_timeout=0; CREATE TABLE IF NOT EXISTS guard (id INTEGER PRIMARY KEY);');
      this.guard.exec(writable ? 'BEGIN EXCLUSIVE' : 'BEGIN');
      this.guardLocked = true;
      this.guard.prepare('SELECT count(*) FROM guard').get();
    this.db = new DatabaseSync(file);
    chmodSync(file, 0o600);
    this.db.exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL; PRAGMA busy_timeout=5000;');
    this.db.exec('CREATE TABLE IF NOT EXISTS state (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    this.getStatement = this.db.prepare('SELECT value FROM state WHERE key=?');
    this.setStatement = this.db.prepare('INSERT INTO state VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
    } catch (error) {
      try { this.close(); } catch (cleanup) { throw new AggregateError([error, cleanup], 'load_state_initialization_cleanup_failed'); }
      throw error;
    }
  }
  get(key) { const row = this.getStatement.get(key); return row ? JSON.parse(row.value) : null; }
  set(key, value) { this.setStatement.run(key, JSON.stringify(value)); return value; }
  close() {
    if (this.closed) return; this.closed = true;
    const failures = [];
    for (const action of [() => this.db?.close(), () => { if (this.guardLocked) this.guard.exec('ROLLBACK'); }, () => this.guard?.close()]) {
      try { action(); } catch (error) { failures.push(error); }
    }
    if (failures.length) throw new AggregateError(failures, 'load_state_cleanup_failed');
  }
}

// 只有网络或真实HTTP拒绝可计为请求失败；SQL、协议断言和程序错误必须使整个运行失败。
export class LoadHttpError extends Error {
  constructor(code, cause) { super(code, { cause }); this.code = code; }
}

export class LoadApi {
  constructor(config) {
    this.config = config;
    const endpoint = new URL(config.http);
    assert.ok(endpoint.protocol === 'https:' || (endpoint.protocol === 'http:' && ['127.0.0.1', '[::1]', 'localhost'].includes(endpoint.hostname)), 'HTTP plaintext is restricted to local fixtures');
    assert.equal(endpoint.username + endpoint.password + endpoint.search + endpoint.hash, '');
    this.endpoint = endpoint.origin;
    this.tokens = new Map();
    this.requests = {};
  }
  async request(method, path, actor, tenant, body = undefined, signal = undefined) {
    assert.ok(path.startsWith('/iot/'));
    const started = performance.now();
    const headers = { 'Content-Type': 'application/json' };
    if (actor) headers.Authorization = `Bearer ${await this.token(actor, signal)}`;
    if (tenant) headers['X-Tenant-Id'] = tenant;
    const category = path.includes('/history') ? 'curve' : path.includes('/commands') ? 'commands'
      : path.split('?')[0].endsWith('/devices') ? 'devices' : 'setup';
    const stats = this.requests[method + ':' + category] ??= { count: 0, errors: 0, elapsed_ms: 0 };
    stats.count++;
    try {
    const response = await fetch(this.endpoint + path, { method, headers,
      body: body === undefined ? undefined : JSON.stringify(body), signal: AbortSignal.any([AbortSignal.timeout(10000), ...(signal ? [signal] : [])]) })
      .catch(error => { throw new LoadHttpError('load_http_transport', error); });
    const length = Number(response.headers.get('content-length') ?? 0);
    const chunks = []; let received = 0;
    const reader = response.body.getReader();
    let readFailure;
    try {
      assert.ok(length <= 8 * 1024 * 1024, 'load_response_too_large');
      while (true) {
        const next = await reader.read().catch(error => { throw new LoadHttpError('load_http_transport', error); });
        if (next.done) break;
        received += next.value.byteLength;
        assert.ok(received <= 8 * 1024 * 1024, 'load_response_too_large');
        chunks.push(next.value);
      }
    } catch (error) { readFailure = error; throw error; }
    finally {
      try { await reader.cancel(); }
      catch (error) {
        const cleanup = new LoadHttpError('load_http_transport', error);
        throw readFailure ? new AggregateError([readFailure, cleanup], 'load_response_cleanup_failed') : cleanup;
      }
    }
    const data = JSON.parse(Buffer.concat(chunks).toString());
    if (!response.ok) throw new LoadHttpError(`load_http_${response.status}`);
    return data;
    } catch (error) { stats.errors++; throw error; }
    finally { stats.elapsed_ms += performance.now() - started; }
  }
  async token(actor, signal) {
    const current = this.tokens.get(actor);
    if (current && current.until > Date.now()) return current.value;
    const role = actor === 'platform' ? 'platform' : 'owner';
    const password = process.env[`TYPE_LOAD_${role.toUpperCase()}_PASSWORD`];
    assert.ok(password, `TYPE_LOAD_${role.toUpperCase()}_PASSWORD is required`);
    const login = role === 'platform' ? this.config.platform_login : ownerLogin(this.config, Number(actor.split(':')[1]));
    assert.equal(typeof login, 'string');
    const result = await this.request('POST', '/iot/auth/login', null, null, { login, password }, signal);
    // 100个Web会话分别使用100个账号，不绕过正式每账号10个会话的额度。
    this.tokens.set(actor, { value: result.data.accessToken, until: Math.min(Date.now() + 7 * 3600000, result.data.expiresAt * 1000 - 60000) });
    return result.data.accessToken;
  }
  async exact(path, actor, tenant, name) {
    const result = await this.request('GET', `${path}?name=${encodeURIComponent(name)}&per_page=100`, actor, tenant);
    const matching = result.items.filter(item => item.name === name);
    assert.ok(result.total <= 100 && matching.length <= 1, 'load_recovery_ambiguous_name');
    return matching[0] ?? null;
  }
}

export function configuration(file) {
  const source = realpathSync(file);
  const config = JSON.parse(readFileSync(source, 'utf8'));
  assert.match(config.namespace, /^[a-z][a-z0-9-]{2,31}$/);
  assert.equal(config.baseline, baseline.name);
  assert.ok(Number.isInteger(config.devices) && config.devices >= 1 && config.devices <= 10000);
  for (const key of ['platform_login', 'state']) assert.equal(typeof config[key], 'string');
  const tenants = new Set(deviceIndices(config).map(index => coordinates(index).tenant));
  assert.ok(Array.isArray(config.owner_logins) && config.owner_logins.length === tenants.size);
  assert.equal(new Set(config.owner_logins).size, config.owner_logins.length);
  config.state = resolve(dirname(source), config.state);
  if (config.ca) config.ca = resolve(dirname(source), config.ca);
  return config;
}

export async function prepare(config, state, api) {
  const identity = { baseline_sha256: baselineHash, namespace: config.namespace, endpoint: api.endpoint,
    devices: config.devices, platform_login: config.platform_login, owner_logins: config.owner_logins,
    ...(config.device_indices ? { device_indices: config.device_indices } : {}) };
  const previous = state.get('identity');
  if (previous) assert.deepEqual(previous, identity, 'load_state_identity_mismatch');
  else state.set('identity', identity);
  const indices = deviceIndices(config);
  for (let offset = 0; offset < indices.length; offset++) {
    const index = indices[offset];
    const position = coordinates(index);
    const owner = `owner:${position.tenant}`;
    const tenantKey = `tenant:${position.tenant}`;
    let tenant = state.get(tenantKey);
    if (!tenant) {
      const name = `${config.namespace}-tenant-${position.tenant}`;
      // 平台列表单独带platform=1，不能把平台身份伪装为成员。
      const existing = await api.request('GET', '/iot/tenants?platform=1&per_page=100', 'platform', null);
      assert.ok(existing.total <= 100, 'load_prepare_requires_isolated_tenant_inventory');
      const matches = existing.items.filter(item => item.name === name);
      assert.ok(matches.length <= 1, 'load_recovery_ambiguous_tenant');
      tenant = state.set(tenantKey, matches[0] ?? (await api.request('POST', '/iot/tenants', 'platform', null,
        { name, owner_login: ownerLogin(config, position.tenant) })).data);
    }
    const path = `/iot/tenants/${tenant.id}`;
    const productKey = `product:${position.tenant}:${position.product}`;
    let product = state.get(productKey);
    if (!product) {
      const name = `${config.namespace}-product-${position.product}`;
      product = state.set(productKey, await api.exact(path + '/products', owner, tenant.id, name)
        ?? (await api.request('POST', path + '/products', owner, tenant.id, { name })).data);
    }
    const models = `${path}/products/${product.id}/models`;
    if (!state.get(`${productKey}:published`)) {
      const list = await api.request('GET', models, owner, tenant.id);
      assert.ok(list.total <= 1, 'load_model_inventory_changed');
      let model = list.items[0] ?? (await api.request('POST', models, owner, tenant.id, { definition: baseline.model })).data;
      model = (await api.request('GET', models + '/1', owner, tenant.id)).data;
      assert.deepEqual(model.definition, baseline.model, 'load_model_definition_changed');
      if (model.status === 'draft') model = (await api.request('POST', models + '/1/publish', owner, tenant.id, { version: model.version })).data;
      assert.equal(model.status, 'published');
      // 通过正式已发布模型入口验证正常、越界触发、事件和控制样本。
      for (const round of [0, 3]) await api.request('POST', models + '/1/validate', owner, tenant.id,
        { kind: 'properties', values: values(index, round) });
      await api.request('POST', models + '/1/validate', owner, tenant.id,
        { kind: 'event', identifier: 'inspection', values: { code: 0, detail: '例行巡检' } });
      await api.request('POST', models + '/1/validate', owner, tenant.id,
        { kind: 'command', identifier: 'set_relay', values: { on: true } });
      state.set(`${productKey}:published`, true);
    }
    const key = `device:${index}`;
    let record = state.get(key);
    if (!record) {
      const name = `${config.namespace}-device-${index}`;
      const found = await api.exact(path + '/devices', owner, tenant.id, name);
      if (found) {
        // 注册响应丢失无法找回一次性秘密；明确轮换，绝不猜测或复制其他设备凭据。
        assert.equal(found.product_id, product.id); assert.equal(found.model_version, 1);
        const rotated = (await api.request('POST', `${path}/devices/${found.id}/rotate`, owner, tenant.id,
          { version: found.version, confirm_device_id: found.id })).data;
        record = { device: rotated.device, credential: rotated.credential };
      } else record = (await api.request('POST', path + '/devices', owner, tenant.id,
        { name, product_id: product.id, model_version: 1 })).data;
      assert.ok(record.credential?.password, 'load_credential_response_missing');
      state.set(key, { ...record, index });
    }
    for (let number = 0; number < baseline.rules.length; number++) {
      const ruleKey = `${key}:rule:${number}`;
      if (state.get(ruleKey)) continue;
      const definition = baseline.rules[number];
      const name = `${config.namespace}-rule-${index}-${number}`;
      const existing = await api.request('GET', `${path}/alarm-rules?device_id=${record.device.id}&per_page=100`, owner, tenant.id);
      const matches = existing.items.filter(item => item.definition.name === name);
      assert.ok(existing.total <= 100 && matches.length <= 1, 'load_rule_inventory_ambiguous');
      const saved = matches[0] ?? (await api.request('POST', path + '/alarm-rules', owner, tenant.id,
        { name, device_id: record.device.id, field: definition.field, lower: definition.lower,
          upper: definition.upper, hysteresis: definition.hysteresis })).data;
      const rule = (await api.request('GET', `${path}/alarm-rules/${saved.id}/versions`, owner, tenant.id)).items[0];
      assert.equal(rule.device_id, record.device.id);
      assert.equal(rule.field, definition.field);
      assert.deepEqual(rule.definition, { name, lower: definition.lower, upper: definition.upper,
        hysteresis: definition.hysteresis, enabled: true,
        property: baseline.model.properties.find(item => item.identifier === definition.field) });
      state.set(ruleKey, rule);
    }
    state.set('prepared_devices', offset + 1);
  }
  return { status: 'prepared', baseline_sha256: baselineHash, devices: config.devices,
    rules: config.devices * 4, device_indices: config.device_indices ?? null, scope: config.devices === 10000 ? 'full-input' : 'isolated-small-input', requests: api.requests };
}

export class LoadMetrics {
  constructor() { this.counters = {}; this.histograms = {}; }
  increment(key, amount = 1) { this.counters[key] = (this.counters[key] ?? 0) + amount; }
  observe(key, value) {
    assert.ok(Number.isFinite(value) && value >= 0);
    const histogram = this.histograms[key] ??= { count: 0, sum: 0, minimum: Infinity, maximum: 0, buckets: {} };
    histogram.count++; histogram.sum += value; histogram.minimum = Math.min(histogram.minimum, value); histogram.maximum = Math.max(histogram.maximum, value);
    // 固定对数桶不保留无限样本；分位数报告上界，误差最多5%，不足1ms按1ms。
    const bucket = Math.min(1023, Math.ceil(Math.log(Math.max(1, value)) / Math.log(1.05)));
    histogram.buckets[bucket] = (histogram.buckets[bucket] ?? 0) + 1;
  }
  report() {
    return { counters: this.counters, histograms: Object.fromEntries(Object.entries(this.histograms).map(([key, item]) => {
      const percentile = percent => {
        let total = 0;
        for (const bucket of Object.keys(item.buckets).map(Number).sort((a,b) => a-b)) {
          total += item.buckets[bucket]; if (total >= Math.ceil(item.count * percent)) return 1.05 ** bucket;
        }
        return null;
      };
      return [key, { ...item, mean: item.sum / item.count, p50_upper: percentile(0.5), p95_upper: percentile(0.95), p99_upper: percentile(0.99) }];
    })), quantiles: 'fixed logarithmic buckets; upper bound; relative error<=5%, minimum1ms' };
  }
}

// 每次运行从公开入口核对归属/模型/规则；本地准备断点不能证明外部对象仍保持原样。
export async function verifyPrepared(config, state, api, start, count, signal = undefined) {
  const checked = new Set();
  for (const index of deviceIndices(config).slice(start, start + count)) {
    signal?.throwIfAborted();
    const record = state.get(`device:${index}`); assert.ok(record, 'load_device_not_prepared');
    const tenantIndex = coordinates(index).tenant; const actor = `owner:${tenantIndex}`;
    const tenant = record.device.tenant_id; const path = `/iot/tenants/${tenant}`;
    const current = (await api.request('GET', `${path}/devices/${record.device.id}`, actor, tenant, undefined, signal)).data;
    for (const field of ['id','tenant_id','product_id','ownership_id','model_version']) assert.equal(current[field], record.device[field], 'load_device_identity_changed');
    assert.ok(['inactive','enabled'].includes(current.lifecycle), 'load_device_unavailable');
    const product = current.product_id;
    if (!checked.has(product)) {
      const model = (await api.request('GET', `${path}/products/${product}/models/1`, actor, tenant, undefined, signal)).data;
      assert.equal(model.status, 'published'); assert.deepEqual(model.definition, baseline.model, 'load_model_definition_changed');
      checked.add(product);
    }
    const rules = await api.request('GET', `${path}/alarm-rules?device_id=${current.id}&per_page=100`, actor, tenant, undefined, signal);
    assert.equal(rules.total, 4, 'load_rule_inventory_changed');
    for (let number = 0; number < 4; number++) {
      const original = state.get(`device:${index}:rule:${number}`);
      const saved = rules.items.find(item => item.id === original.id);
      assert.ok(saved, 'load_rule_missing');
      assert.deepEqual(saved.definition, original.definition, 'load_rule_definition_changed');
    }
  }
  return { devices: count, products: checked.size, rules: count * 4, checked_at: Date.now() };
}

export async function inspect(config, state, api) {
  const start = config.shard_start ?? 0; const count = config.shard_count ?? Math.min(config.devices, 250);
  const reports = [];
  for (const index of deviceIndices(config).slice(start, start + count)) {
    const record = state.get(`device:${index}`); const device = record.device;
    const actor = `owner:${coordinates(index).tenant}`; const tenant = device.tenant_id;
    const path = `/iot/tenants/${tenant}/devices/${device.id}`;
    const current = (await api.request('GET', path, actor, tenant)).data;
    const history = await api.request('GET', `${path}/history?per_page=100`, actor, tenant);
    const curve = (await api.request('GET', `${path}/history?view=minute_curve&field=ambient_temperature&product_id=${device.product_id}&model_version=1&ownership_id=${device.ownership_id}`, actor, tenant)).data;
    const controls = await api.request('GET', `${path}/commands?per_page=100`, actor, tenant);
    const notices = await api.request('GET', `/iot/tenants/${tenant}/notifications?per_page=100`, actor, tenant);
    reports.push({ index, device_id: device.id, observed_ms: Date.now(), current: current.current,
      history_total: history.total, history: history.items.map(item => ({ message_id: item.message_id, sequence: item.sequence,
        type: item.type, sampled_at: item.sampled_at, received_at: item.received_at })),
      curve, controls: controls.items.map(item => ({ id: item.id, accepted_at: item.accepted_at,
        execution: item.execution, result_status: item.result_status, finished_at: item.finished_at })),
      notices: { total: notices.total, kinds: notices.items.map(item => item.kind) } });
  }
  return { status: 'inspected', baseline_sha256: baselineHash, reports, http: api.requests };
}

// 缓存和CLI状态库各有所有者，最终退出失败也必须原子更新报告并保留先前原件。
function saveRunReport(cachePath, result) {
  const reportPath = cachePath + '.report.json'; const temporary = reportPath + '.' + process.pid + '.tmp';
  if (existsSync(reportPath)) {
    const previous = readFileSync(reportPath);
    try { writeFileSync(reportPath + '.previous.' + digest(previous).slice(0, 24), previous, { mode: 0o600, flag: 'wx', flush: true }); }
    catch (error) { if (error.code !== 'EEXIST') throw error; }
  }
  writeFileSync(temporary, JSON.stringify(result, null, 2), { mode: 0o600, flush: true }); renameSync(temporary, reportPath);
}

export async function run(config, state, api, offline = false) {
  const { LoadCache, LoadDevice } = await import('./iot-load-device.mjs');
  const start = config.shard_start ?? 0; const count = config.shard_count ?? Math.min(config.devices, 250);
  assert.ok(Number.isInteger(start) && Number.isInteger(count) && start >= 0 && count >= 1 && count <= 1000 && start + count <= config.devices);
  assert.ok(Number.isInteger(config.seconds) && config.seconds >= 1 && config.seconds <= 86400);
  const phases = loadPhases(config); const indices = deviceIndices(config); const selected = indices.slice(start, start + count);
  assert.ok(!config.phases || !offline, 'load_phases_require_fresh_online_run');
  const reconnect = config.reconnect ?? null;
  const reconnectSeed = config.reconnect_seed ?? baseline.seed + ':reconnect-v1';
  assert.ok(typeof reconnectSeed === 'string' && reconnectSeed.length >= 1 && reconnectSeed.length <= 128);
  if (reconnect) {
    assert.ok(!offline && Number.isInteger(reconnect.after_seconds) && reconnect.after_seconds >= 1);
    assert.ok(Number.isInteger(reconnect.offline_seconds) && reconnect.offline_seconds >= 1);
    assert.ok(reconnect.after_seconds + reconnect.offline_seconds < config.seconds, 'load_reconnect_outside_run');
  }
  const cachePath = resolve(dirname(config.state), 'load-cache-' + start + '-' + count + '.sqlite');
  const metrics = new LoadMetrics(); const loopDelay = monitorEventLoopDelay({ resolution: 20 });
  const cpuStart = process.cpuUsage(); const operationAbort = new AbortController();
  const devices = []; const cursors = new Map(); const work = new Set();
  const webBusy = new Set(); const commandBusy = new Set(); const resultBusy = new Set(); const nextResults = new Map(); const nextCommands = new Map();
  const tenantDevices = new Map();
  for (const index of indices) { const tenant = coordinates(index).tenant; if (!tenantDevices.has(tenant)) tenantDevices.set(tenant, []); tenantDevices.get(tenant).push(index); }
  const ownTenants = [...tenantDevices.keys()].filter(tenant => selected.includes(tenantDevices.get(tenant)[0]));
  const firstDevice = tenant => tenantDevices.get(tenant)[0];
  let cache; let startMs; let runStart; let runEnd; let verified = null; let result; let failure = null;
  let stopped = false; let interrupted = false; let fatal = null; let nextResource = 0; let peakRss = 0; let nextWeb = 0; let cycle = 0;
  const cleanup = [];
  let nextObservation = 0; let observationBusy = false; let disconnect = null;
  const stop = () => { stopped = true; interrupted = true; operationAbort.abort(); };
  const startWork = action => {
    // 每个异步失败进入同一个fatal槽，退出时等待所有已登记操作后再次检查。
    let operation;
    try { operation = action(); } catch (error) { fatal ??= error; return; }
    const promise = Promise.resolve(operation).catch(error => { fatal ??= error; }).finally(() => work.delete(promise));
    work.add(promise);
  };
  const httpFailure = (error, counter) => {
    if (!(error instanceof LoadHttpError)) throw error;
    if (!stopped) metrics.increment(counter);
  };
  const errorSummary = error => ({ name: error.name, code: /^[A-Z_0-9]+$/.test(error.code ?? '') ? error.code : 'load_run_failed',
    ...(error instanceof AggregateError ? { causes: error.errors.map(errorSummary) } : {}) });
  const request = (method, path, actor, tenant, body) => api.request(method, path, actor, tenant, body, operationAbort.signal);
  process.on('SIGINT', stop); process.on('SIGTERM', stop); loopDelay.enable();
  try {
    assert.equal(state.get('prepared_devices'), config.devices, 'load_prepare_incomplete');
    assert.equal(state.get('identity').baseline_sha256, baselineHash);
    if (!offline) {
      const endpoint = new URL(config.mqtt); assert.equal(endpoint.protocol, 'mqtts:'); assert.ok(config.ca);
      verified = await verifyPrepared(config, state, api, start, count, operationAbort.signal);
    }
    cache = new LoadCache(cachePath, { namespace: config.namespace, start, count,
      ...(config.device_indices ? { device_indices: selected } : {}) }, config.cache_disk_mib ?? (count * 24 + 64));
    const plan = JSON.stringify({ phases: config.phases ?? null, reconnect, reconnect_seed: reconnectSeed });
    const previousPlan = cache.db.prepare('SELECT value FROM metadata WHERE key=?').get('load_plan');
    if (previousPlan) assert.equal(previousPlan.value, plan, 'load_plan_changed');
    else cache.db.prepare('INSERT INTO metadata VALUES (?,?)').run('load_plan', plan);
    if (offline) {
      const duration = cache.db.prepare('SELECT value FROM metadata WHERE key=?').get('offline_seconds');
      if (duration) assert.equal(Number(duration.value), config.seconds, 'load_offline_duration_changed');
      else cache.db.prepare('INSERT INTO metadata VALUES (?,?)').run('offline_seconds', String(config.seconds));
    }
    const previousStart = cache.db.prepare('SELECT value FROM metadata WHERE key=?').get('start_ms');
    startMs = previousStart ? Number(previousStart.value) : (offline ? Date.now() - config.seconds * 1000 : Date.now() + 5000);
    if (!previousStart) cache.db.prepare('INSERT INTO metadata VALUES (?,?)').run('start_ms', String(startMs));
    const savedRun = cache.db.prepare('SELECT value FROM metadata WHERE key=?').get('run_start_ms');
    runStart = offline ? startMs : savedRun ? Number(savedRun.value) : config.phases ? startMs : Math.max(startMs, Date.now() + 5000);
    assert.ok(Number.isSafeInteger(startMs) && startMs > 0 && Number.isSafeInteger(runStart) && runStart > 0, 'load_run_clock_invalid');
    runEnd = runStart + config.seconds * 1000;
    if (config.phases) assert.equal(startMs, runStart, 'load_phases_require_fresh_online_run');
    disconnect = JSON.parse(cache.db.prepare('SELECT value FROM metadata WHERE key=?').get('disconnect')?.value ?? 'null');
    if (!offline) cache.transaction(() => {
      if (!savedRun) cache.db.prepare('INSERT INTO metadata VALUES (?,?)').run('run_start_ms', String(runStart));
      const duration = cache.db.prepare('SELECT value FROM metadata WHERE key=?').get('run_seconds');
      if (duration) assert.equal(Number(duration.value), config.seconds, 'load_run_duration_changed');
      else cache.db.prepare('INSERT INTO metadata VALUES (?,?)').run('run_seconds', String(config.seconds));
      // 先登记固定轮次，包括从未连接的设备；requested_ms=0表示尚未尝试，不假装已发送。
      const insert = cache.db.prepare("INSERT OR IGNORE INTO control_requests(id,device,round,requested_ms,status) VALUES (?,?,?,0,'planned')");
      for (const tenantIndex of ownTenants) {
        const record = state.get('device:' + firstDevice(tenantIndex)); assert.ok(record, 'load_device_not_prepared');
        for (let round = 0; round * baseline.command_interval_seconds < config.seconds; round++) {
          const id = digest(config.namespace + ':' + runStart + ':command:' + tenantIndex + ':' + round).slice(0, 32);
          insert.run(id, record.device.id, round);
        }
      }
    });
    for (let offset = 0; offset < count; offset++) {
      const record = state.get('device:' + selected[offset]); assert.ok(record, 'load_device_not_prepared');
      devices.push(new LoadDevice(record, cache, config, metrics));
      devices.at(-1).pausedUntil = disconnect?.eligible_ms ?? 0;
    }
    for (const device of devices) for (const type of ['telemetry', 'event']) {
      const key = type + ':' + device.device.id;
      cursors.set(key, Number(cache.db.prepare('SELECT value FROM metadata WHERE key=?').get(key)?.value ?? 0));
    }
    const stopAt = performance.now() + Math.max(0, runEnd - Date.now());
    do {
      if (fatal) throw fatal;
      const now = Date.now(); let admissions = 0;
      if (!offline && reconnect && !disconnect && now >= runStart + reconnect.after_seconds * 1000) {
        disconnect = { started_ms: now, eligible_ms: now + reconnect.offline_seconds * 1000,
          connected_before: devices.filter(device => device.client?.connected && !device.connecting).length,
          inflight_before: devices.filter(device => device.inflight).length };
        cache.db.prepare('INSERT INTO metadata VALUES (?,?)').run('disconnect', JSON.stringify(disconnect));
        for (const device of devices) { device.pausedUntil = disconnect.eligible_ms; startWork(() => device.stopClient(device.client)); }
      }
      for (const device of devices) {
        if (device.fatal) throw device.fatal;
        for (const type of ['telemetry', 'event']) {
          const key = type + ':' + device.device.id; let round = cursors.get(key);
          const interval = type === 'telemetry' ? 10000 : baseline.event_interval_seconds * 1000;
          const endMs = offline ? startMs + config.seconds * 1000 : Math.min(now, runEnd);
          while (admissions < 1000) {
            const sample = config.phases && type === 'telemetry' ? phaseSample(phases, device.device.index, round)
              : { offset_ms: round * interval + device.device.index, signal_round: round };
            const scheduled = startMs + sample.offset_ms;
            if (scheduled >= endMs) break;
            const accepted = cache.enqueue(device.device, round, scheduled, type, sample.signal_round);
            metrics.increment(accepted ? type + '_generated' : type + '_not_admitted');
            if (!offline) metrics.observe('scheduling_lag_ms', Math.max(0, now - scheduled));
            cursors.set(key, ++round); admissions++;
          }
        }
        if (!offline && !stopped) {
          if (!device.client && !device.connecting && devices.filter(item => item.connecting).length < 32) startWork(() => device.connect());
          if (device.client?.connected && !device.busy && !device.connecting) startWork(() => device.tick());
        }
      }
      if (!offline && !stopped && now >= nextWeb) {
        nextWeb = now + baseline.web_interval_ms;
        for (const tenantIndex of ownTenants) {
          if (webBusy.has(tenantIndex)) { metrics.increment('web_missed_cycles'); continue; }
          const selection = webSelection(tenantIndex, cycle);
          const available = tenantDevices.get(tenantIndex);
          const index = available[(selection.device - tenantIndex * 100) % available.length];
          const record = state.get('device:' + index); const tenant = record.device.tenant_id;
          const path = '/iot/tenants/' + tenant + '/devices';
          const query = selection.kind === 'list' ? path : path + '/' + record.device.id + '/history?view=minute_curve&field=ambient_temperature&product_id=' + record.device.product_id + '&model_version=1&ownership_id=' + record.device.ownership_id;
          webBusy.add(tenantIndex);
          startWork(async () => {
            const began = performance.now();
            try { await request('GET', query, 'web:' + tenantIndex, tenant); metrics.increment('web_' + selection.kind + '_success'); }
            catch (error) { httpFailure(error, 'web_' + selection.kind + '_failed'); }
            finally { metrics.observe('web_' + selection.kind + '_ms', performance.now() - began); webBusy.delete(tenantIndex); }
          });
        }
        cycle++;
      }
      if (!offline && config.phases && !stopped && now >= runStart && now < runEnd && now >= nextObservation && !observationBusy) {
        nextObservation = now + 5000; observationBusy = true;
        startWork(async () => {
          let observed = { state: 'unavailable', metrics: null };
          try { observed = (await request('GET', '/iot/operations', 'platform', null)).store; }
          catch (error) { httpFailure(error, 'operations_failed'); }
          finally {
            try {
              const available = observed.state === 'available' && observed.metrics !== null;
              if (available) for (const field of ['pendingMessages', 'pendingBytes']) assert.ok(Number.isSafeInteger(observed.metrics[field]) && observed.metrics[field] >= 0);
              const completedAt = Date.now();
              const phase = phases.find(item => completedAt - runStart >= item.start_ms && completedAt - runStart < item.end_ms);
              cache.db.prepare('INSERT INTO load_observations VALUES (?,?,?,?,?,?)').run(completedAt, phase?.name ?? 'after-run', observed.state,
                available ? observed.metrics.pendingMessages : null, available ? observed.metrics.pendingBytes : null,
                cache.db.prepare('SELECT coalesce(sum(pending),0) AS count FROM devices').get().count);
            } finally { observationBusy = false; }
          }
        });
      }
      if (!offline && !stopped) for (const tenantIndex of ownTenants) {
        const commandRound = Math.floor((now - runStart) / (baseline.command_interval_seconds * 1000));
        if (commandRound < 0 || now >= runEnd || commandBusy.has(tenantIndex) || now < (nextCommands.get(tenantIndex) ?? 0)) continue;
        const controlled = devices.find(item => item.device.index === firstDevice(tenantIndex));
        if (!controlled?.client?.connected || controlled.trustedTime() === null) continue;
        const record = state.get('device:' + firstDevice(tenantIndex)); const tenant = record.device.tenant_id;
        const id = digest(config.namespace + ':' + runStart + ':command:' + tenantIndex + ':' + commandRound).slice(0, 32);
        const own = cache.db.prepare('SELECT accepted_ms FROM control_requests WHERE id=?').get(id);
        assert.ok(own, 'load_control_plan_missing');
        if (own.accepted_ms !== null) continue;
        nextCommands.set(tenantIndex, now + 5000); commandBusy.add(tenantIndex);
        startWork(async () => {
          try {
            cache.db.prepare("UPDATE control_requests SET attempts=attempts+1,requested_ms=CASE WHEN requested_ms=0 THEN ? ELSE requested_ms END,status='pending' WHERE id=?").run(Date.now(), id);
            const accepted = await request('POST', '/iot/tenants/' + tenant + '/devices/' + record.device.id + '/commands', 'owner:' + tenantIndex, tenant,
              { command_id: id, identifier: 'set_relay', values: { on: commandRound % 2 === 0 } });
            if (stopped) return;
            assert.equal(accepted.data.id, id, 'load_command_identity_changed');
            assert.ok(Number.isSafeInteger(accepted.data.accepted_at) && accepted.data.accepted_at > 0);
            cache.db.prepare('UPDATE control_requests SET accepted_ms=? WHERE id=?').run(accepted.data.accepted_at * 1000, id);
            metrics.increment('command_http_accepted');
          } catch (error) { httpFailure(error, 'command_http_failed'); }
          finally { commandBusy.delete(tenantIndex); }
        });
      }
      if (!offline && !stopped) for (const tenantIndex of ownTenants) {
        if (resultBusy.has(tenantIndex) || now < (nextResults.get(tenantIndex) ?? 0)) continue;
        const record = state.get('device:' + firstDevice(tenantIndex)); const tenant = record.device.tenant_id;
        // 单个旧未知结果不会被最近100条列表挤掉；每租户每5秒只查询最久未观察的一条。
        const own = cache.db.prepare('SELECT * FROM control_requests WHERE device=? AND terminal_ms IS NULL AND attempts>0 ORDER BY coalesce(observed_ms,0),round,id LIMIT 1').get(record.device.id);
        if (!own) continue;
        cache.db.prepare('UPDATE control_requests SET observed_ms=? WHERE id=?').run(now, own.id);
        resultBusy.add(tenantIndex); nextResults.set(tenantIndex, now + 5000);
        startWork(async () => {
          try {
            const path = '/iot/tenants/' + tenant + '/devices/' + record.device.id + '/commands/' + own.id;
            const observed = (await request('GET', path, 'owner:' + tenantIndex, tenant)).data;
            if (stopped) return;
            assert.equal(observed.id, own.id, 'load_command_identity_changed');
            assert.ok(Number.isSafeInteger(observed.accepted_at) && observed.accepted_at > 0);
            if (own.accepted_ms !== null) assert.equal(observed.accepted_at * 1000, own.accepted_ms, 'load_command_acceptance_changed');
            const terminal = ['succeeded', 'failed', 'rejected'].includes(observed.result_status);
            const observedAt = Date.now();
            cache.db.prepare('UPDATE control_requests SET accepted_ms=?,observed_ms=?,status=?,terminal_ms=? WHERE id=?')
              .run(observed.accepted_at * 1000, observedAt, observed.execution ?? observed.result_status ?? 'unknown', terminal ? observedAt : null, own.id);
            if (terminal) {
              metrics.increment('command_result_' + observed.result_status);
              metrics.observe('command_result_visible_ms', observedAt - own.requested_ms);
            } else if (own.query_pending || (observedAt >= observed.accepted_at * 1000 + 300000 && observedAt >= (own.query_at ?? 0) + 300000)) {
              // 接受未知时保留原query_id；只有成功后才推进query_at，下一允许轮次形成新的固定ID。
              const queryRound = own.query_pending ? own.query_round : Math.floor((observedAt - runStart) / 300000);
              const queryId = own.query_pending ? own.query_id : digest('query:' + own.id + ':' + queryRound).slice(0, 32);
              assert.match(queryId, /^[a-f0-9]{32}$/);
              if (!own.query_pending) cache.db.prepare('UPDATE control_requests SET query_id=?,query_round=?,query_pending=1 WHERE id=?').run(queryId, queryRound, own.id);
              const queried = await request('POST', path + '/queries', 'owner:' + tenantIndex, tenant, { query_id: queryId });
              if (stopped) return;
              assert.equal(queried.data.id, queryId, 'load_query_identity_changed');
              assert.equal(queried.data.command_id, own.id, 'load_query_command_changed');
              cache.db.prepare('UPDATE control_requests SET query_at=?,query_pending=0 WHERE id=? AND query_id=?').run(Date.now(), own.id, queryId);
              metrics.increment('command_query_accepted');
            }
          } catch (error) { httpFailure(error, 'command_result_query_failed'); }
          finally { resultBusy.delete(tenantIndex); }
        });
      }
      if (performance.now() >= nextResource) {
        nextResource = performance.now() + 1000; peakRss = Math.max(peakRss, process.memoryUsage().rss);
        assert.ok(process.memoryUsage().rss <= (config.maximum_rss_mib ?? 1024) * 1048576, 'load_generator_memory_limit');
      }
      if (offline && admissions === 0) break;
      await delay(offline ? 0 : 20);
    } while (!stopped && (offline || performance.now() < stopAt));
  } catch (error) { failure = error; }
  finally {
    stopped = true; operationAbort.abort();
    const closed = await Promise.allSettled(devices.map(device => device.close()));
    closed.forEach((item, index) => { if (item.status === 'rejected') cleanup.push({ phase: 'device:' + devices[index].device.index, error: item.reason }); });
    let timer;
    try {
      await Promise.race([Promise.all([...work]), new Promise((_, reject) => {
        timer = setTimeout(() => reject(Object.assign(new Error('load_work_shutdown_timeout'), { code: 'LOAD_WORK_SHUTDOWN_TIMEOUT' })), 15000);
      })]);
    } catch (error) { cleanup.push({ phase: 'pending_work', error }); }
    finally { clearTimeout(timer); }
    failure ??= fatal ?? devices.find(device => device.fatal)?.fatal ?? null;
    loopDelay.disable(); process.off('SIGINT', stop); process.off('SIGTERM', stop);
    try {
      const observedAt = Math.min(Date.now(), runEnd ?? Date.now());
      const controlAccounting = cache?.db.prepare('SELECT count(*) AS planned,coalesce(sum(attempts>0),0) AS requested,coalesce(sum(accepted_ms IS NOT NULL),0) AS accepted,coalesce(sum(?+round*?<=?),0) AS due,coalesce(sum(attempts=0 AND ?+round*?<=?),0) AS missed,coalesce(sum(attempts>0 AND accepted_ms IS NULL),0) AS acceptance_unknown FROM control_requests')
        .get(runStart ?? 0, baseline.command_interval_seconds * 1000, observedAt, runStart ?? 0, baseline.command_interval_seconds * 1000, observedAt);
      if (controlAccounting) {
        for (const key of ['planned', 'requested', 'accepted']) metrics.counters['command_' + key] = controlAccounting[key];
        metrics.counters.command_missed_cycles = controlAccounting.missed;
      }
      const phaseReports = phases.map(phase => ({ ...phase, scheduled_rate_per_second: count * phase.multiplier / 10,
        messages: cache?.db.prepare('SELECT type,count(*) AS generated,coalesce(sum(first_sent_ms IS NOT NULL),0) AS sent,coalesce(sum(publish_attempts),0) AS publish_attempts,coalesce(sum(status=\'accepted\'),0) AS accepted,coalesce(sum(status=\'rejected\'),0) AS rejected,coalesce(sum(status IS NULL),0) AS pending FROM messages WHERE sampled_ms>=? AND sampled_ms<? GROUP BY type')
          .all(runStart + phase.start_ms, runStart + phase.end_ms),
        observations: cache?.db.prepare('SELECT count(*) AS total,coalesce(sum(pending_messages IS NOT NULL),0) AS available,max(pending_messages) AS maximum_pending_messages,max(pending_bytes) AS maximum_pending_bytes,max(local_pending) AS maximum_local_pending FROM load_observations WHERE phase=?').get(phase.name) }));
      const burst = phases.find(phase => phase.name === 'burst' && phase.multiplier === 3);
      const recovery = phases.find(phase => phase.name === 'recovery' && phase.multiplier === 1 && burst && phase.start_ms === burst.end_ms);
      const beforeBurst = burst ? cache?.db.prepare('SELECT * FROM load_observations WHERE observed_ms<? AND pending_messages IS NOT NULL ORDER BY observed_ms DESC LIMIT 1').get(runStart + burst.start_ms) : null;
      const recovered = beforeBurst && recovery ? cache?.db.prepare('SELECT * FROM load_observations WHERE observed_ms>=? AND observed_ms<? AND pending_messages<=? AND pending_bytes<=? AND local_pending<=? ORDER BY observed_ms LIMIT 1')
        .get(runStart + recovery.start_ms, runStart + recovery.end_ms, beforeBurst.pending_messages, beforeBurst.pending_bytes, beforeBurst.local_pending) : null;
      const connectionSummary = cache?.db.prepare('SELECT device,count(*) AS attempts,sum(connack_ms IS NOT NULL) AS connacks,sum(session_present=1) AS session_present,min(subscribed_ms) AS first_ready_ms,max(subscribed_ms) AS latest_ready_ms FROM connections GROUP BY device').all();
      const resumed = disconnect ? cache?.db.prepare('SELECT device,min(subscribed_ms) AS ready_ms,min(session_present) AS session_present,min(restored_subscription) AS restored_subscription FROM connections WHERE started_ms>=? AND subscribed_ms IS NOT NULL GROUP BY device').all(disconnect.eligible_ms) : [];
      result = { status: failure ? 'failed' : interrupted ? 'interrupted' : 'completed', capacity_claim: false, baseline_sha256: baselineHash, offline, start_ms: startMs, shard: { start, count }, verified,
        device_indices: config.device_indices ? selected : null, web_sessions: ownTenants.map(tenant => ({ tenant, kind: webSelection(tenant, 0).kind })),
        phases: phaseReports, backlog_recovery: { baseline: beforeBurst ?? null, recovered: recovered ?? null,
          elapsed_ms: recovered ? recovered.observed_ms - (runStart + recovery.start_ms) : null,
          status: !recovery ? 'not_requested' : !beforeBurst ? 'unverified' : recovered ? 'observed' : 'not_observed', capacity_claim: false },
        reconnect: { seed: reconnectSeed, planned: reconnect, disconnect, devices: connectionSummary,
          resumed, all_ready_ms: resumed.length === count ? Math.max(...resumed.map(item => item.ready_ms)) : null,
          elapsed_ms: resumed.length === count ? Math.max(...resumed.map(item => item.ready_ms)) - disconnect.eligible_ms : null,
          first_post_reconnect_receipt_ms: disconnect ? cache?.db.prepare('SELECT min(receipt_ms) AS value FROM messages WHERE receipt_ms>=?').get(disconnect.eligible_ms).value : null,
          inflight_receipts_after_reconnect: disconnect ? cache?.db.prepare('SELECT count(*) AS count FROM messages WHERE first_sent_ms<? AND receipt_ms>=?').get(disconnect.started_ms, disconnect.eligible_ms).count : null },
        run_start_ms: runStart, run_end_ms: runEnd, configured_seconds: config.seconds, metrics: metrics.report(), http: api.requests,
        cache: cache?.db.prepare('SELECT count(*) AS devices,sum(pending) AS pending,sum(bytes) AS bytes,sum(not_admitted) AS not_admitted FROM devices').get(),
        controls: cache?.db.prepare('SELECT status,count(*) AS count FROM control_requests GROUP BY status ORDER BY status').all(),
        control_accounting: controlAccounting, accounting_observed_ms: observedAt,
        resources: { node: process.version, platform: process.platform, architecture: process.arch, peak_rss_bytes: peakRss,
          cpu_microseconds: process.cpuUsage(cpuStart), event_loop_p95_ms: loopDelay.percentile(95) / 1e6, max_inflight_per_device: 1,
          max_connecting_devices: 32, max_control_http_per_tenant: 2, device_shutdown_timeout_ms: 5000, work_shutdown_timeout_ms: 15000 },
        qualification: 'generator-observation-only; capacity acceptance requires matching service evidence, full scale, full duration and no generator insufficiency' };
    } catch (error) { failure ??= error; cleanup.push({ phase: 'report_snapshot', error }); }
    try { cache?.close(); } catch (error) { cleanup.push({ phase: 'cache', error }); }
    if (cleanup.length) failure = new AggregateError([...(failure ? [failure] : []), ...cleanup.map(item => item.error)], 'load_run_cleanup_failed');
    result ??= { baseline_sha256: baselineHash, offline, shard: { start, count }, configured_seconds: config.seconds, metrics: metrics.report(), http: api.requests };
    result.cleanup = { status: cleanup.length ? 'failed' : 'completed', failures: cleanup.map(item => ({ phase: item.phase, ...errorSummary(item.error) })) };
    if (failure) { result.status = 'failed'; result.error = errorSummary(failure); result.capacity_claim = false; }
    // 保留历次原始报告，最新指针可替换；容量耗尽不妨碍独立JSON失败报告。
    try { saveRunReport(cachePath, result); }
    catch (error) { failure = new AggregateError([...(failure ? [failure] : []), error], 'load_report_write_failed'); }
  }
  if (failure) { failure.report = result; throw failure; }
  return result;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const action = process.argv[2];
  if (action === 'samples') console.log(JSON.stringify(sampleReport(), null, 2));
  else {
    assert.ok(['prepare','run','cache','inspect','seed'].includes(action), 'usage: node tests/iot-load.mjs samples | prepare|run|cache|inspect|seed CONFIG');
    const config = configuration(process.argv[3]);
    let state; let failure; let result;
    try {
      state = new LoadState(config.state, action === 'prepare');
      const api = new LoadApi(config);
      if (action === 'seed') assert.ok(!config.device_indices, 'load_sparse_history_not_supported');
      result = action === 'seed' ? await (await import('./iot-load-history.mjs')).seedHistory(config, state, api, verifyPrepared)
        : await (action === 'prepare' ? prepare(config, state, api) : action === 'inspect' ? inspect(config, state, api) : run(config, state, api, action === 'cache'));
    } catch (error) { failure = error; }
    finally {
      try { state?.close(); }
      catch (cleanup) {
        const report = result ?? failure?.report;
        failure = new AggregateError([...(failure ? [failure] : []), cleanup], 'load_state_exit_failed');
        if (report && ['run', 'cache'].includes(action)) {
          report.status = 'failed'; report.capacity_claim = false;
          report.cleanup.status = 'failed';
          report.cleanup.failures.push({ phase: 'state', name: cleanup.name, code: 'LOAD_STATE_EXIT_FAILED' });
          report.error = { name: failure.name, code: 'LOAD_STATE_EXIT_FAILED' };
          const start = config.shard_start ?? 0; const count = config.shard_count ?? Math.min(config.devices, 250);
          try { saveRunReport(resolve(dirname(config.state), 'load-cache-' + start + '-' + count + '.sqlite'), report); }
          catch (error) { failure = new AggregateError([failure, error], 'load_report_write_failed'); }
          failure.report = report;
        }
      }
    }
    if (failure) throw failure;
    console.log(JSON.stringify(result, null, 2));
  }
}
