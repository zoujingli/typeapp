import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { createInterface } from 'node:readline';
import { writeFileSync, renameSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { baseline, baselineHash, values, digest, contentHash } from './iot-load-model.mjs';

// 合成历史只用于查询/容量预置，不生成平台持久接收证明。UTC分钟边界和种子固定。
export function historyDefinition(config) {
  const seed = config.history;
  assert.ok(seed && Number.isSafeInteger(seed.end_utc) && seed.end_utc % 60 === 0 && seed.end_utc <= Math.floor(Date.now() / 1000) - 60);
  const start = config.shard_start ?? 0; const count = config.shard_count ?? Math.min(config.devices, 250);
  assert.ok(Number.isInteger(start) && Number.isInteger(count) && start >= 0 && count >= 1 && count <= 1000 && start + count <= config.devices);
  const raw = seed.raw_seconds ?? 7 * 86400; const aggregate = seed.aggregate_seconds ?? 90 * 86400;
  assert.ok(Number.isInteger(raw) && raw >= 60 && raw <= 7 * 86400 && raw % 60 === 0);
  assert.ok(Number.isInteger(aggregate) && aggregate >= raw && aggregate <= 90 * 86400 && aggregate % 60 === 0);
  assert.match(seed.database, /^(?:type_app_test|type_iot_load_[a-z0-9_]{1,40})$/);
  assert.match(seed.system_identifier, /^[0-9]{10,30}$/);
  return { baseline_sha256: baselineHash, namespace: config.namespace, start, count, end_utc: seed.end_utc,
    raw_seconds: raw, aggregate_seconds: aggregate, database: seed.database, system_identifier: seed.system_identifier,
    raw_rows: count * raw / 10, aggregate_rows: count * aggregate / 60, source: 'synthetic-history-no-receipt-proof' };
}

function sample(device, definition, sampledAt) {
  const round = Math.floor((sampledAt - (definition.end_utc - definition.aggregate_seconds)) / 10);
  const sequence = String(100000000000000000000n + BigInt(sampledAt) * 10000n + BigInt(device.index));
  const payload = { app_version: 1, type: 'telemetry', device_id: device.id, ownership_id: device.ownership_id,
    model_version: device.model_version, sequence, sampled_at: sampledAt, values: values(device.index, round) };
  return { sequence, payload, message: digest(`${device.id}:${device.ownership_id}:${sequence}`) };
}

export function historyRow(device, definition, stage, offset) {
  const base = { tenant_id: device.tenant_id, device_id: device.id, product_id: device.product_id,
    ownership_id: device.ownership_id, model_version: device.model_version };
  if (stage === 'raw') {
    const sampled = definition.end_utc - definition.raw_seconds + offset * 10 + Math.floor(device.index / 1000);
    const item = sample(device, definition, sampled);
    return { ...base, message_id: item.message, sequence: item.sequence, sampled_at: sampled, received_at: sampled + 1,
      content_hash: contentHash(item.payload), values_json: JSON.stringify(item.payload.values) };
  }
  assert.equal(stage, 'aggregate');
  const start = definition.end_utc - definition.aggregate_seconds + offset * 60; const fields = {};
  for (let tick = 0; tick < 6; tick++) {
    const sampled = start + tick * 10 + Math.floor(device.index / 1000); const item = sample(device, definition, sampled);
    for (const field of baseline.model.properties.filter(value => ['number','integer'].includes(value.type))) {
      const value = item.payload.values[field.identifier]; const previous = fields[field.identifier];
      fields[field.identifier] = { count: tick + 1, min: previous ? Math.min(previous.min, value) : value,
        max: previous ? Math.max(previous.max, value) : value, sum: (previous?.sum ?? 0) + value, last: value,
        last_sampled_at: sampled, last_sequence: item.sequence };
    }
  }
  return { ...base, id: digest([base.tenant_id,base.device_id,base.product_id,base.model_version,base.ownership_id,start].join(':')),
    window_start: start, window_end: start + 60, fields_json: JSON.stringify(fields), updated_at: definition.end_utc };
}

export async function seedHistory(config, state, api, verifyPrepared) {
  const definition = historyDefinition(config);
  await verifyPrepared(config, state, api, definition.start, definition.count);
  const devices = Array.from({ length: definition.count }, (_, offset) => {
    const record = state.get(`device:${definition.start + offset}`);
    return { ...record.device, index: record.index };
  });
  const worker = spawn(process.env.TYPE_LOAD_PHP ?? 'php', [fileURLToPath(new URL('./iot-load-history.php', import.meta.url))], { stdio: ['pipe','pipe','pipe'] });
  const lines = createInterface({ input: worker.stdout }); const responses = lines[Symbol.asyncIterator]();
  let stopped = false; const stop = () => { stopped = true; }; let stderrBytes = 0; let responseBytes = 0; let workerFailure = null;
  worker.on('error', () => { workerFailure = 'load_history_worker_start_failed'; });
  worker.stdout.on('data', bytes => {
    responseBytes += bytes.length;
    if (responseBytes > 2097152) { workerFailure = 'load_history_response_limit'; worker.kill('SIGKILL'); }
  });
  worker.stderr.on('data', bytes => {
    stderrBytes += bytes.length;
    if (stderrBytes > 2097152) { workerFailure = 'load_history_stderr_limit'; worker.kill('SIGKILL'); }
  });
  worker.stdin.on('error', () => {});
  // close在所有stdio结束后发生；spawn失败同样走close，不留下无人处理的Promise rejection。
  const exited = new Promise(resolveExit => worker.once('close', (code, signal) => resolveExit({ code, signal })));
  let workerClosed = false; exited.then(() => { workerClosed = true; });
  process.on('SIGINT', stop); process.on('SIGTERM', stop);
  const exchange = async data => {
    const payload = JSON.stringify(data) + '\n'; assert.ok(Buffer.byteLength(payload) <= 2097152);
    responseBytes = 0; let timer; const cancellation = new AbortController();
    try {
      const operation = async () => {
        if (!worker.stdin.write(payload)) await once(worker.stdin, 'drain', { signal: cancellation.signal });
        return responses.next();
      };
      // 写入背压、读响应及子进程退出共用一个截止，不能在drain之前无限等候。
      const response = await Promise.race([operation(), exited.then(() => ({ done: true })), new Promise((_, reject) => {
        timer = setTimeout(() => reject(new Error('load_history_worker_timeout')), 120000);
      })]);
      if (workerFailure) throw new Error(workerFailure);
      assert.ok(!response.done, 'load_history_worker_exit');
      const result = JSON.parse(response.value); assert.equal(result.status, 'ok', result.error ?? 'load_history_worker_error');
      return result;
    } finally { clearTimeout(timer); cancellation.abort(); }
  };
  const waitForExit = async milliseconds => {
    let timer;
    try { return await Promise.race([exited, new Promise(resolveWait => { timer = setTimeout(() => resolveWait(null), milliseconds); })]); }
    finally { clearTimeout(timer); }
  };
  let result;
  try {
    const progress = await exchange({ action: 'open', definition, devices });
    let batches = 0; const maxBatches = config.history.max_batches ?? 100000000;
    assert.ok(Number.isInteger(maxBatches) && maxBatches >= 1 && maxBatches <= 100000000);
    for (const stage of ['raw','aggregate']) {
      const perDevice = definition[stage === 'raw' ? 'raw_seconds' : 'aggregate_seconds'] / (stage === 'raw' ? 10 : 60);
      let cursor = progress[stage]; const total = perDevice * definition.count;
      while (cursor < total && !stopped && batches < maxBatches) {
        const length = Math.min(128, total - cursor); const rows = [];
        for (let i = 0; i < length; i++) {
          const position = cursor + i;
          rows.push(historyRow(devices[Math.floor(position / perDevice)], definition, stage, position % perDevice));
        }
        const saved = await exchange({ action: 'batch', stage, cursor, rows });
        assert.equal(saved.cursor, cursor + length); cursor = saved.cursor; batches++;
      }
    }
    result = await exchange({ action: 'report' });
    result = { ...result, status: stopped ? 'interrupted' : result.complete ? 'completed' : 'checkpointed', definition,
      batches_this_run: batches, peak_rss_bytes: process.resourceUsage().maxRSS * 1024, stderr_bytes: stderrBytes,
      capacity_claim: false };
    await exchange({ action: 'close' }); worker.stdin.end();
    const closed = await waitForExit(5000);
    assert.ok(closed, 'load_history_worker_close_timeout'); assert.equal(closed.code, 0, 'load_history_worker_failed');
    return result;
  } catch (error) {
    result = { status: 'failed', definition, stderr_bytes: stderrBytes, error: workerFailure ?? 'load_history_run_failed', capacity_claim: false };
    throw error;
  } finally {
    process.off('SIGINT', stop); process.off('SIGTERM', stop); lines.close();
    worker.stdin.destroy();
    if (!workerClosed) worker.kill('SIGTERM');
    let closed = await waitForExit(5000);
    if (!closed) { worker.kill('SIGKILL'); closed = await waitForExit(5000); }
    if (result) {
      result.worker_stopped = closed !== null;
      if (!closed) result.status = 'failed';
      const report = resolve(dirname(config.state), `history-seed-${definition.start}-${definition.count}.report.json`);
      const temporary = `${report}.${process.pid}.tmp`;
      writeFileSync(temporary, JSON.stringify(result, null, 2), { mode: 0o600, flush: true }); renameSync(temporary, report);
    }
    assert.ok(closed, 'load_history_worker_cleanup_timeout');
  }
}
