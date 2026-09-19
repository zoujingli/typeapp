<?php

declare(strict_types=1);

// 开发验收工具的JSON行接口；只允许显式指定身份的隔离PostgreSQL库，不属于生产角色。
$connection = null;
$definition = null;
$job = '';
$lock = '';
$devices = [];
$statements = [];
$failed = false;
try {
    while (($line = fgets(STDIN, 2097154)) !== false) {
        if (!str_ends_with($line, "\n") || strlen($line) > 2097152) {
            throw new RuntimeException('load_history_request_limit');
        }
        $request = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($request) || array_is_list($request)) {
            throw new RuntimeException('load_history_request_invalid');
        }
        $action = $request['action'] ?? '';
        if ($action === 'open') {
            if ($connection !== null) {
                throw new RuntimeException('load_history_already_open');
            }
            $definition = $request['definition'] ?? null;
            if (!is_array($definition) || count($definition) !== 12) {
                throw new RuntimeException('load_history_definition_invalid');
            }
            foreach (['database', 'system_identifier', 'namespace', 'source', 'baseline_sha256'] as $field) {
                if (!is_string($definition[$field] ?? null)) {
                    throw new RuntimeException('load_history_definition_invalid');
                }
            }
            foreach (['start', 'count', 'end_utc', 'raw_seconds', 'aggregate_seconds', 'raw_rows', 'aggregate_rows'] as $field) {
                if (!is_int($definition[$field] ?? null)) {
                    throw new RuntimeException('load_history_definition_invalid');
                }
            }
            if (!preg_match('/^(?:type_app_test|type_iot_load_[a-z0-9_]{1,40})$/D', $definition['database'])
                || !preg_match('/^[0-9]{10,30}$/D', $definition['system_identifier'])
                || !preg_match('/^[a-z][a-z0-9-]{2,31}$/D', $definition['namespace'])
                || $definition['source'] !== 'synthetic-history-no-receipt-proof'
                || $definition['baseline_sha256'] !== hash_file('sha256', __DIR__ . '/fixtures/iot-baseline-v1.json')
                || $definition['count'] < 1 || $definition['count'] > 1000
                || $definition['start'] < 0 || $definition['start'] + $definition['count'] > 10000
                || $definition['end_utc'] < 1 || $definition['end_utc'] % 60 !== 0 || $definition['end_utc'] > time() - 60
                || $definition['raw_seconds'] < 60 || $definition['raw_seconds'] > 7 * 86400 || $definition['raw_seconds'] % 60 !== 0
                || $definition['aggregate_seconds'] < $definition['raw_seconds'] || $definition['aggregate_seconds'] > 90 * 86400 || $definition['aggregate_seconds'] % 60 !== 0
                || $definition['raw_rows'] !== $definition['count'] * intdiv($definition['raw_seconds'], 10)
                || $definition['aggregate_rows'] !== $definition['count'] * intdiv($definition['aggregate_seconds'], 60)) {
                throw new RuntimeException('load_history_target_invalid');
            }
            $host = (string) getenv('TYPE_LOAD_SEED_HOST');
            $port = (string) getenv('TYPE_LOAD_SEED_PORT');
            if (!preg_match('/^[a-zA-Z0-9.:-]{1,253}$/D', $host) || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
                throw new RuntimeException('load_history_endpoint_invalid');
            }
            $ssl = in_array($host, ['127.0.0.1', '::1'], true) ? 'prefer' : 'verify-full';
            $connection = new PDO(
                'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $definition['database'] . ';sslmode=' . $ssl . ';connect_timeout=10',
                (string) getenv('TYPE_LOAD_SEED_USER'),
                (string) getenv('TYPE_LOAD_SEED_PASSWORD'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $actual = $connection->query('SELECT current_database() AS name, system_identifier::text AS identifier FROM pg_control_system()')->fetch(PDO::FETCH_ASSOC);
            if ($actual['name'] !== $definition['database'] || $actual['identifier'] !== $definition['system_identifier']) {
                throw new RuntimeException('load_history_target_identity_mismatch');
            }
            $connection->exec("SET statement_timeout='60s'; SET lock_timeout='5s'; SET application_name='typeapp-load-history'");
            $lock = $definition['namespace'];
            $guard = $connection->prepare('SELECT pg_try_advisory_lock(hashtextextended(?,0))');
            $guard->execute(['typeapp-load-history:' . $lock]);
            if (!$guard->fetchColumn()) {
                throw new RuntimeException('load_history_target_in_use');
            }
            $connection->exec('CREATE TABLE IF NOT EXISTS iot_load_seed_jobs (id VARCHAR(64) PRIMARY KEY, definition TEXT NOT NULL, raw_cursor BIGINT NOT NULL DEFAULT 0, aggregate_cursor BIGINT NOT NULL DEFAULT 0)');
            $lookup = $connection->prepare('SELECT id,tenant_id,product_id,ownership_id,model_version,name FROM iot_devices WHERE id=?');
            if (!is_array($request['devices'] ?? null) || !array_is_list($request['devices']) || count($request['devices']) !== $definition['count']) {
                throw new RuntimeException('load_history_device_count');
            }
            foreach ($request['devices'] as $offset => $device) {
                if (!is_array($device) || ($device['index'] ?? null) !== $definition['start'] + $offset || ($device['model_version'] ?? null) !== 1) {
                    throw new RuntimeException('load_history_device_identity_mismatch');
                }
                foreach (['id', 'tenant_id', 'product_id', 'ownership_id'] as $field) {
                    if (!is_string($device[$field] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $device[$field])) {
                        throw new RuntimeException('load_history_device_identity_mismatch');
                    }
                }
                if (isset($devices[$device['id']])) {
                    throw new RuntimeException('load_history_device_count');
                }
                $lookup->execute([$device['id']]);
                $stored = $lookup->fetch(PDO::FETCH_ASSOC);
                foreach (['id', 'tenant_id', 'product_id', 'ownership_id', 'model_version'] as $field) {
                    if ($stored === false || (string) $stored[$field] !== (string) $device[$field]) {
                        throw new RuntimeException('load_history_device_identity_mismatch');
                    }
                }
                if ($stored['name'] !== $definition['namespace'] . '-device-' . $device['index']) {
                    throw new RuntimeException('load_history_device_namespace_mismatch');
                }
                $devices[$device['id']] = $device;
            }
            $encoded = json_encode($definition, JSON_THROW_ON_ERROR);
            $job = hash('sha256', $definition['namespace'] . ':' . $definition['start'] . ':' . $definition['count']);
            // 同namespace已由advisory lock串行；不同分片不得重叠，避免部分提交后才遇到唯一键冲突。
            $existingJobs = $connection->prepare("SELECT id,definition FROM iot_load_seed_jobs WHERE definition::jsonb->>'namespace'=?");
            $existingJobs->execute([$definition['namespace']]);
            foreach ($existingJobs->fetchAll(PDO::FETCH_ASSOC) as $existingJob) {
                if ($existingJob['id'] === $job) {
                    if ($existingJob['definition'] !== $encoded) {
                        throw new RuntimeException('load_history_definition_changed');
                    }
                    continue;
                }
                $other = json_decode($existingJob['definition'], true, 16, JSON_THROW_ON_ERROR);
                if ($definition['start'] < $other['start'] + $other['count'] && $other['start'] < $definition['start'] + $definition['count']) {
                    throw new RuntimeException('load_history_shard_overlap');
                }
            }
            $open = $connection->prepare('INSERT INTO iot_load_seed_jobs(id,definition) VALUES (?,?) ON CONFLICT(id) DO NOTHING');
            $open->execute([$job, $encoded]);
            $saved = $connection->prepare('SELECT * FROM iot_load_seed_jobs WHERE id=?');
            $saved->execute([$job]);
            $progress = $saved->fetch(PDO::FETCH_ASSOC);
            // synthetic不是accepted；零nonce不能被当作同步持久证明。完成标记仅防止后台再次消费预置数据。
            $statements['ledger'] = $connection->prepare("INSERT INTO iot_ingestion(message_id,tenant_id,device_id,ownership_id,sequence,content_hash,status,code,received_at,receipt_proof_nonce,receipt_requested_at) VALUES (?,?,?,?,?,?,'synthetic','load_history_seed',?,'',0)");
            $statements['raw'] = $connection->prepare("INSERT INTO iot_ingestion_facts(message_id,tenant_id,device_id,ownership_id,product_id,model_version,sequence,type,identifier,sampled_at,received_at,values_json,current_advanced) VALUES (?,?,?,?,?,?,?,'telemetry','',?,?,?,0)");
            $statements['completed'] = $connection->prepare('INSERT INTO iot_ingestion_completed(message_id,consumer,completed_at) VALUES (?,?,?)');
            $statements['aggregate'] = $connection->prepare('INSERT INTO iot_minute_aggregates(id,tenant_id,device_id,product_id,model_version,ownership_id,window_start,window_end,fields_json,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $response = ['status' => 'ok', 'raw' => (int) $progress['raw_cursor'], 'aggregate' => (int) $progress['aggregate_cursor']];
        } elseif ($connection === null) {
            throw new RuntimeException('load_history_not_open');
        } elseif ($action === 'batch') {
            $stage = $request['stage'];
            if (!in_array($stage, ['raw', 'aggregate'], true) || !is_int($request['cursor'] ?? null) || $request['cursor'] < 0
                || !is_array($request['rows'] ?? null) || !array_is_list($request['rows']) || count($request['rows']) < 1 || count($request['rows']) > 128) {
                throw new RuntimeException('load_history_batch_invalid');
            }
            $connection->beginTransaction();
            $cursorQuery = $connection->prepare('SELECT raw_cursor,aggregate_cursor FROM iot_load_seed_jobs WHERE id=? FOR UPDATE');
            $cursorQuery->execute([$job]);
            $current = $cursorQuery->fetch(PDO::FETCH_ASSOC);
            if ((int) $current[$stage . '_cursor'] !== $request['cursor'] || $request['cursor'] + count($request['rows']) > $definition[$stage . '_rows']) {
                throw new RuntimeException('load_history_cursor_mismatch');
            }
            foreach ($request['rows'] as $offset => $row) {
                $device = $devices[$row['device_id']] ?? null;
                foreach (['tenant_id', 'product_id', 'model_version', 'ownership_id'] as $field) {
                    if ($device === null || $row[$field] !== $device[$field]) {
                        throw new RuntimeException('load_history_row_scope_mismatch');
                    }
                }
                $perDevice = intdiv($definition[$stage === 'raw' ? 'raw_seconds' : 'aggregate_seconds'], $stage === 'raw' ? 10 : 60);
                $position = $request['cursor'] + $offset;
                if ($device['index'] !== $definition['start'] + intdiv($position, $perDevice)) {
                    throw new RuntimeException('load_history_row_position_mismatch');
                }
                if ($stage === 'raw') {
                    if ($row['sampled_at'] < $definition['end_utc'] - $definition['raw_seconds'] || $row['sampled_at'] >= $definition['end_utc']) {
                        throw new RuntimeException('load_history_raw_time_invalid');
                    }
                    $statements['ledger']->execute([$row['message_id'], $row['tenant_id'], $row['device_id'], $row['ownership_id'], $row['sequence'], $row['content_hash'], $row['received_at']]);
                    $statements['raw']->execute([$row['message_id'], $row['tenant_id'], $row['device_id'], $row['ownership_id'], $row['product_id'], $row['model_version'], $row['sequence'], $row['sampled_at'], $row['received_at'], $row['values_json']]);
                    foreach (['current', 'aggregate', 'alarm'] as $consumer) {
                        $statements['completed']->execute([$row['message_id'], $consumer, $definition['end_utc']]);
                    }
                } else {
                    if ($row['window_start'] % 60 !== 0 || $row['window_end'] !== $row['window_start'] + 60
                        || $row['window_start'] < $definition['end_utc'] - $definition['aggregate_seconds'] || $row['window_end'] > $definition['end_utc']) {
                        throw new RuntimeException('load_history_minute_time_invalid');
                    }
                    $statements['aggregate']->execute([$row['id'], $row['tenant_id'], $row['device_id'], $row['product_id'], $row['model_version'], $row['ownership_id'], $row['window_start'], $row['window_end'], $row['fields_json'], $row['updated_at']]);
                }
            }
            $next = $request['cursor'] + count($request['rows']);
            $advance = $connection->prepare($stage === 'raw' ? 'UPDATE iot_load_seed_jobs SET raw_cursor=? WHERE id=?' : 'UPDATE iot_load_seed_jobs SET aggregate_cursor=? WHERE id=?');
            $advance->execute([$next, $job]);
            $connection->commit();
            $response = ['status' => 'ok', 'cursor' => $next];
        } elseif ($action === 'report') {
            $query = $connection->prepare('SELECT raw_cursor,aggregate_cursor FROM iot_load_seed_jobs WHERE id=?');
            $query->execute([$job]);
            $cursors = array_map('intval', $query->fetch(PDO::FETCH_ASSOC));
            $relations = $connection->query("SELECT relname,pg_table_size(oid) AS table_bytes,pg_indexes_size(oid) AS index_bytes,pg_total_relation_size(oid) AS total_bytes FROM pg_class WHERE relnamespace=current_schema()::regnamespace AND relname IN ('iot_ingestion','iot_ingestion_facts','iot_ingestion_completed','iot_minute_aggregates','iot_load_seed_jobs') ORDER BY relname")->fetchAll(PDO::FETCH_ASSOC);
            $indexes = $connection->query("SELECT tablename,indexname,indexdef FROM pg_indexes WHERE schemaname=current_schema() AND tablename IN ('iot_ingestion','iot_ingestion_facts','iot_ingestion_completed','iot_minute_aggregates','iot_load_seed_jobs') ORDER BY tablename,indexname")->fetchAll(PDO::FETCH_ASSOC);
            $response = ['status' => 'ok', 'job' => $job, 'cursors' => $cursors,
                'complete' => $cursors['raw_cursor'] === $definition['raw_rows'] && $cursors['aggregate_cursor'] === $definition['aggregate_rows'],
                'relations' => $relations, 'indexes' => $indexes, 'physical_scope' => 'whole-isolated-relations-including-existing-fixture-rows',
                'auxiliary_rows' => ['synthetic_ledger' => $cursors['raw_cursor'], 'consumer_markers' => $cursors['raw_cursor'] * 3],
                'receipt_proof' => false];
        } elseif ($action === 'close') {
            echo json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR) . "\n";
            break;
        } else {
            throw new RuntimeException('load_history_action_invalid');
        }
        echo json_encode($response, JSON_THROW_ON_ERROR) . "\n";
        fflush(STDOUT);
    }
} catch (Throwable $failure) {
    if ($connection !== null && $connection->inTransaction()) {
        $connection->rollBack();
    }
    $message = $failure->getMessage();
    $safe = preg_match('/^load_history_[a-z_]+$/D', $message) ? $message : 'load_history_database_or_input_error';
    echo json_encode(['status' => 'failed', 'error' => $safe], JSON_THROW_ON_ERROR) . "\n";
    $failed = true;
} finally {
    // 关闭实际数据库会话会释放advisory lock；进程被杀时PostgreSQL同样回滚未完成事务。
    $statements = [];
    $connection = null;
}
exit($failed ? 1 : 0);
