import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { mkdirSync, mkdtempSync, readFileSync, rmdirSync, writeFileSync } from 'node:fs';
import { dirname, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { LoadState, LoadApi, run } from './iot-load.mjs';
import { LoadCache } from './iot-load-device.mjs';
import { baselineHash, deviceIndices, loadPhases, phaseSample, reconnectDelay, values } from './iot-load-model.mjs';

// 仅检查发生器自身资源；合成设备不会连接被测平台，不作为服务端容量或协议验收。
const root = fileURLToPath(new URL('../', import.meta.url));
mkdirSync(resolve(root, 'build'), { recursive: true });
const base = mkdtempSync(resolve(root, 'build/iot-load-resource-'));
const children = [];
const deadline = async (operation, milliseconds, message) => {
  let timer;
  try { return await Promise.race([operation, new Promise((_, reject) => { timer = setTimeout(() => reject(new Error(message)), milliseconds); })]); }
  finally { clearTimeout(timer); }
};
const stop = async child => {
  if (child.exitCode !== null || child.signalCode !== null) return;
  const closed = once(child, 'close'); child.kill('SIGKILL');
  await deadline(closed, 5000, 'load_resource_child_shutdown_timeout');
};
const hold = async (kind, file) => {
  const module = new URL(kind === 'state' ? './iot-load.mjs' : './iot-load-device.mjs', import.meta.url).href;
  const code = `const m=await import(${JSON.stringify(module)});const value=${kind === 'state'
    ? 'new m.LoadState(process.argv[1],false)' : 'new m.LoadCache(process.argv[1],{namespace:"guard"},32)'};process.stdout.write('ready');setInterval(()=>{},1000);`;
  const child = spawn(process.execPath, ['--input-type=module', '-e', code, file], { cwd: base, stdio: ['ignore', 'pipe', 'pipe'] });
  children.push(child); let stdout = ''; let stderrBytes = 0;
  child.stderr.on('data', bytes => { stderrBytes += bytes.length; if (stderrBytes > 65536) child.kill('SIGKILL'); });
  const ready = new Promise((resolveReady, reject) => {
    child.once('error', reject);
    child.once('close', () => reject(new Error('load_resource_child_early_exit')));
    child.stdout.on('data', bytes => {
      stdout += bytes.toString();
      if (stdout === 'ready') resolveReady();
      else if (stdout.length > 64) reject(new Error('load_resource_child_output_limit'));
    });
  });
  await deadline(ready, 5000, 'load_resource_child_start_timeout');
  return child;
};

let result; let failure;
try {
  const stateFile = resolve(base, 'state.sqlite'); new LoadState(stateFile).close();
  const readerA = await hold('state', stateFile); const readerB = await hold('state', stateFile);
  assert.throws(() => new LoadState(stateFile), error => error.code?.startsWith('ERR_SQLITE'));
  await stop(readerA);
  assert.throws(() => new LoadState(stateFile), error => error.code?.startsWith('ERR_SQLITE'));
  await stop(readerB); new LoadState(stateFile).close();

  const cacheFile = resolve(base, 'cache.sqlite'); const owner = await hold('cache', cacheFile);
  assert.throws(() => new LoadCache(cacheFile, { namespace: 'guard' }, 32), error => error.code?.startsWith('ERR_SQLITE'));
  await stop(owner);
  assert.throws(() => new LoadCache(cacheFile, { namespace: 'changed' }, 32), /load_cache_identity_mismatch/);
  new LoadCache(cacheFile, { namespace: 'guard' }, 32).close();
  const invalid = resolve(base, 'directory'); mkdirSync(invalid);
  assert.throws(() => new LoadState(invalid)); rmdirSync(invalid); new LoadState(invalid).close();

  const device = { id: 'a'.repeat(32), ownership_id: 'b'.repeat(32), tenant_id: 'c'.repeat(32), product_id: 'd'.repeat(32), model_version: 1, index: 0 };
  const config = { baseline: 'iot-baseline-v1', namespace: 'resource-check', devices: 1, platform_login: 'unused',
    owner_logins: ['unused'], state: resolve(base, 'failure/state.sqlite'), http: 'http://127.0.0.1:1',
    seconds: 86400, maximum_rss_mib: 1, cache_disk_mib: 32 };
  const state = new LoadState(config.state);
  try {
    state.set('identity', { baseline_sha256: baselineHash }); state.set('prepared_devices', 1);
    state.set('device:0', { device, index: 0, credential: {} });
    await assert.rejects(run(config, state, new LoadApi(config), true), /load_generator_memory_limit/);
  } finally { state.close(); }
  const failed = JSON.parse(readFileSync(resolve(dirname(config.state), 'load-cache-0-1.sqlite.report.json')));
  assert.equal(failed.status, 'failed'); assert.equal(failed.capacity_claim, false);
  assert.equal(failed.cleanup.status, 'completed');
  const resumedState = new LoadState(config.state);
  try {
    await assert.rejects(run({ ...config, reconnect_seed: 'changed-seed' }, resumedState, new LoadApi(config), true), /load_plan_changed/);
  } finally { resumedState.close(); }
  const changed = JSON.parse(readFileSync(resolve(dirname(config.state), 'load-cache-0-1.sqlite.report.json')));
  assert.equal(changed.status, 'failed'); assert.equal(changed.cleanup.status, 'completed');

  const phases = loadPhases({ seconds: 90, phases: [
    { name: 'steady', seconds: 30, multiplier: 1 }, { name: 'burst', seconds: 10, multiplier: 3 }, { name: 'recovery', seconds: 50, multiplier: 1 },
  ] });
  for (const index of deviceIndices({ devices: 2, device_indices: [0, 5000] })) {
    const samples = Array.from({ length: 11 }, (_, ordinal) => phaseSample(phases, index, ordinal));
    assert.deepEqual(phases.map(phase => samples.filter(sample => sample.phase === phase.name).length), [3, 3, 5]);
    assert.ok(samples.every((sample, ordinal) => ordinal === 0 || sample.offset_ms > samples[ordinal - 1].offset_ms));
    assert.equal(phaseSample(phases, index, 11).offset_ms, Infinity);
    assert.deepEqual(samples.map(sample => values(index, sample.signal_round).ambient_temperature > 40), [true, true, true, false, false, false, false, false, false, false, false]);
  }
  const longPhases = loadPhases({ seconds: 1200, phases: [{ name: 'burst', seconds: 1200, multiplier: 3 }] });
  const excursions = Array.from({ length: 360 }, (_, ordinal) => phaseSample(longPhases, 0, ordinal))
    .filter(sample => values(0, sample.signal_round).ambient_temperature > 40);
  assert.deepEqual(excursions.map(sample => sample.offset_ms), [0, 3333, 6666, 600000, 603333, 606666]);
  const backoffs = Array.from({ length: 100 }, (_, index) => reconnectDelay('fixed-check', index, 3, 30));
  assert.ok(backoffs.every(value => value.delay_ms >= 6400 && value.delay_ms <= 6900));
  assert.deepEqual(backoffs, Array.from({ length: 100 }, (_, index) => reconnectDelay('fixed-check', index, 3, 30)));
  assert.ok(new Set(backoffs.map(value => value.jitter_ms)).size > 50);

  const capped = new LoadCache(resolve(base, 'capped.sqlite'), { namespace: 'capacity-check' }, 32);
  try {
    const sampledMs = 1789257600000;
    assert.equal(capped.enqueue(device, 0, sampledMs, 'event'), true);
    const original = capped.pending(device.id);
    for (let round = 1; round < 10000; round++) assert.equal(capped.enqueue(device, round, sampledMs + round * 1000, 'event'), true);
    assert.equal(capped.enqueue(device, 10000, sampledMs + 10000000, 'event'), false);
    const counts = capped.db.prepare('SELECT pending,not_admitted FROM devices WHERE id=?').get(device.id);
    assert.equal(counts.pending, 10000); assert.equal(counts.not_admitted, 1);
    assert.deepEqual(capped.pending(device.id), original);
  } finally { capped.close(); }
  result = { status: 'passed', baseline_sha256: baselineHash, scope: 'generator-resource-only', capacity_claim: false,
    shared_readers: 2, exclusive_prepare_refused: true, process_death_releases_locks: true,
    constructor_failure_releases_locks: true, failed_run_report_saved: true, cache_capacity: 10000,
    unconfirmed_original_preserved: true, changed_reconnect_seed_refused: true, phased_counts: [3, 3, 5],
    phase_alarm_ten_minute_cadence_preserved: true, deterministic_bounded_reconnect_jitter: true };
} catch (error) { failure = error; }
finally {
  const stopped = await Promise.allSettled(children.map(stop));
  const cleanup = stopped.filter(item => item.status === 'rejected').map(item => item.reason);
  if (cleanup.length) failure = new AggregateError([...(failure ? [failure] : []), ...cleanup], 'load_resource_cleanup_failed');
  result ??= { status: 'failed', scope: 'generator-resource-only', capacity_claim: false };
  if (failure) result.status = 'failed';
  result.children_stopped = cleanup.length === 0;
  result.report = relative(root, resolve(base, 'verification.json'));
  writeFileSync(resolve(base, 'verification.json'), JSON.stringify(result, null, 2), { mode: 0o600, flush: true });
}
console.log(JSON.stringify(result, null, 2));
if (failure) throw failure;
