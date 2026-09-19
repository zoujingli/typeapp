import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';

// 测试输入的唯一来源；按脚本位置解析，控制端不依赖相邻项目或当前工作目录。
export const baselineBytes = readFileSync(new URL('./fixtures/iot-baseline-v1.json', import.meta.url));
export const baseline = JSON.parse(baselineBytes);
export const baselineHash = createHash('sha256').update(baselineBytes).digest('hex');

export function digest(value) {
  return createHash('sha256').update(value).digest('hex');
}

// 与接入契约一样规范化数值字面量及对象键，仅用于本工具自己生成的有限JSON值。
// 第三方报文仍由正式入口严格解析；这里不承诺为任意JSON解析器或生产协议实现。
export function contentHash(value) {
  const string = input => JSON.stringify(input).replaceAll('/', '\\/').replaceAll('\u2028', '\\u2028').replaceAll('\u2029', '\\u2029');
  const canonical = input => {
    if (input === null || typeof input === 'boolean') return JSON.stringify(input);
    if (typeof input === 'string') return string(input);
    if (typeof input === 'number') {
      assert.ok(Number.isFinite(input));
      const [, sign, integer, fraction = '', power = '0'] = JSON.stringify(input).match(/^(-?)([0-9]+)(?:\.([0-9]+))?(?:e([+-]?[0-9]+))?$/);
      const digits = (integer + fraction).replace(/^0+/, '');
      if (digits === '') return '0';
      const trimmed = digits.replace(/0+$/, '');
      return `${sign}${trimmed}e${Number(power) - fraction.length + digits.length - trimmed.length}`;
    }
    if (Array.isArray(input)) return '[' + input.map(canonical).join(',') + ']';
    assert.ok(input && Object.getPrototypeOf(input) === Object.prototype);
    return '{' + Object.keys(input).sort().map(key => `${string(key)}:${canonical(input[key])}`).join(',') + '}';
  };
  return digest(canonical(value));
}

// 无全局随机状态：重启、分片、改变生成顺序都不改变同一设备/轮次/字段的样本。
export function unit(index, round, field) {
  const value = createHash('sha256').update(`${baseline.seed}:${index}:${round}:${field}`).digest().readUInt32BE();
  return value / 4294967296;
}

export function coordinates(index) {
  assert.ok(Number.isInteger(index) && index >= 0 && index < 10000);
  return { tenant: Math.floor(index / 100), product: Math.floor(index / 10) % 10, device: index % 10 };
}

// 小规模只抽取原基线坐标，不把两个租户伪装成100个会话；分片仍按所选数组的位置划分。
export function deviceIndices(config) {
  const indices = config.device_indices ?? Array.from({ length: config.devices }, (_, index) => index);
  assert.equal(indices.length, config.devices);
  indices.forEach((index, offset) => { coordinates(index); assert.ok(offset === 0 || index > indices[offset - 1], 'load_device_indices_order'); });
  return indices;
}

export function ownerLogin(config, tenant) {
  const tenants = [...new Set(deviceIndices(config).map(index => coordinates(index).tenant))];
  return config.owner_logins[tenants.indexOf(tenant)];
}

export function loadPhases(config) {
  const input = config.phases ?? [{ name: 'steady', seconds: config.seconds, multiplier: 1 }];
  assert.ok(Array.isArray(input) && input.length >= 1 && input.length <= 16, 'load_phase_limit');
  let start = 0; const names = new Set();
  const phases = input.map(phase => {
    assert.match(phase.name, /^[a-z][a-z0-9-]{0,31}$/); assert.ok(!names.has(phase.name)); names.add(phase.name);
    assert.ok(Number.isInteger(phase.seconds) && phase.seconds > 0);
    assert.ok(Number.isInteger(phase.multiplier) && phase.multiplier >= 1 && phase.multiplier <= 3);
    const value = { ...phase, start_ms: start, end_ms: start + phase.seconds * 1000 };
    start = value.end_ms; return value;
  });
  assert.equal(start, config.seconds * 1000, 'load_phase_duration_mismatch');
  return phases;
}

// 每阶段重新按原10000台坐标错峰；游标连续、阶段边界不重复。告警轮次另按实际基线时间计算。
export function phaseSample(phases, index, ordinal) {
  let remaining = ordinal;
  for (const phase of phases) {
    const count = Math.max(0, Math.ceil(((phase.end_ms - phase.start_ms) * phase.multiplier - index) / baseline.telemetry_interval_ms));
    if (remaining < count) {
      const offset = phase.start_ms + Math.floor((remaining * baseline.telemetry_interval_ms + index) / phase.multiplier);
      const cycle = Math.floor(offset / 600000);
      const before = phases.reduce((total, item) => total + Math.max(0, Math.ceil((Math.max(0, Math.min(cycle * 600000, item.end_ms) - item.start_ms) * item.multiplier - index) / baseline.telemetry_interval_ms)), 0);
      return { offset_ms: offset, phase: phase.name, signal_round: cycle * 60 + Math.min(59, ordinal - before) };
    }
    remaining -= count;
  }
  return { offset_ms: Infinity, phase: null };
}

export function reconnectDelay(seed, index, attempt, failures) {
  assert.equal(typeof seed, 'string'); assert.ok(seed.length >= 1 && seed.length <= 128);
  assert.ok(Number.isSafeInteger(attempt) && attempt >= 1);
  const jitter = Number.parseInt(digest(seed + ':' + index + ':' + attempt).slice(0, 8), 16) % 501;
  return { jitter_ms: jitter, delay_ms: Math.min(10000, 200 * 2 ** Math.min(failures, 5)) + jitter };
}

export function values(index, round, alarmRound = round) {
  assert.ok(Number.isSafeInteger(round) && round >= 0);
  const point = coordinates(index);
  const result = {};
  for (const field of baseline.model.properties) {
    if (field.type === 'number' || field.type === 'integer') {
      const [offset, span] = baseline.signals[field.identifier];
      const generated = offset + span * unit(index, round, field.identifier);
      result[field.identifier] = field.type === 'integer' ? Math.floor(generated) : Math.round(generated * 1000) / 1000;
    }
  }
  // 全量恰好100台设备，每600秒开头连续三条越界；接下来的三条回到恢复闭区间。
  if (index % baseline.alarm_device_modulus === 0 && alarmRound % 60 < 3) {
    for (const rule of baseline.rules) result[rule.field] = rule.excursion;
  }
  result.relay = unit(index, round, 'relay') >= 0.5;
  result.maintenance = unit(index, round, 'maintenance') >= 0.9;
  result.mode = ['automatic', 'manual', 'standby'][Math.floor(unit(index, round, 'mode') * 3)];
  result.fault = ['normal', 'sensor_warning', 'communication_warning'][Math.floor(unit(index, round, 'fault') * 3)];
  result.firmware = `typeapp-device/1.0.${index % 8}+baseline.20260914`;
  result.location = `中国/华东区域/园区${String(point.tenant + 1).padStart(3, '0')}/生产车间${String(point.product + 1).padStart(2, '0')}/自动化产线/设备机柜${String(point.device + 1).padStart(2, '0')}/现场采集终端`;
  return result;
}

export function envelope(device, sequence, sampledAt, round, type = 'telemetry', alarmRound = round) {
  assert.match(device.id, /^[a-f0-9]{32}$/);
  assert.match(device.ownership_id, /^[a-f0-9]{32}$/);
  assert.match(sequence, /^[1-9][0-9]{0,37}$/);
  assert.ok(Number.isSafeInteger(sampledAt) && sampledAt > 0);
  assert.ok(type === 'telemetry' || type === 'event');
  const data = { app_version: 1, type, device_id: device.id, ownership_id: device.ownership_id,
    model_version: device.model_version, sequence, sampled_at: sampledAt,
    values: type === 'telemetry' ? values(device.index, round, alarmRound) : { code: round % 101, detail: `例行巡检第${round}轮：设备数据与连接状态检查` } };
  if (type === 'event') data.identifier = 'inspection';
  const payload = JSON.stringify(data);
  assert.ok(Buffer.byteLength(payload) <= 16384);
  return payload;
}

export function samplingTime(startMs, index, round) {
  coordinates(index);
  assert.ok(Number.isSafeInteger(startMs) && Number.isSafeInteger(round) && round >= 0);
  return startMs + round * baseline.telemetry_interval_ms + index;
}

// 固定100个会话，前50个列表、后50个曲线。相同周期可独立重建所选设备。
export function webSelection(session, cycle) {
  assert.ok(Number.isInteger(session) && session >= 0 && session < 100);
  const device = Math.floor(unit(session, cycle, 'web-device') * 100);
  return { session, tenant: session, kind: session < 50 ? 'list' : 'curve', device: session * 100 + device, interval_ms: baseline.web_interval_ms };
}

export function sampleReport() {
  const samples = [];
  let bytes = 0;
  let minimum = Infinity;
  let maximum = 0;
  const distribution = {};
  for (let index = 0; index < 10000; index++) {
    const device = { index, id: digest(`sample-device:${index}`).slice(0, 32), ownership_id: digest(`sample-ownership:${index}`).slice(0, 32), model_version: 1 };
    const payload = envelope(device, '1', 1800000000 + Math.floor(index / 1000), 0);
    const length = Buffer.byteLength(payload);
    minimum = Math.min(minimum, length);
    maximum = Math.max(maximum, length);
    bytes += length;
    const bucket = Math.floor(length / 25) * 25;
    distribution[bucket] = (distribution[bucket] ?? 0) + 1;
    if (index < 3) samples.push(payload);
  }
  return { baseline: baseline.name, baseline_sha256: baselineHash, seed: baseline.seed,
    sample_epoch: 1800000000, devices: 10000, numeric_properties: 20, nonnumeric_properties: 6, rules: 40000,
    bytes: { count: 10000, mean: bytes / 10000, minimum, maximum, histogram_bucket_bytes: 25, histogram: distribution }, samples,
    targets: { raw_records_7_days: 604800000, aggregate_device_minutes_90_days: 1296000000,
      aggregate_rows_90_days: 1296000000, aggregate_numeric_values_90_days: 25920000000,
      aggregate_layout: 'iot_minute_aggregates: one device/ownership/product/model/UTC-minute row; fields_json contains all numeric statistics',
      cached_telemetry_records_24_hours: 86400000, cached_event_records_24_hours: 1440000 },
    web: Array.from({ length: 100 }, (_, index) => webSelection(index, 0)),
    coverage: 'deterministic-input-only' };
}
