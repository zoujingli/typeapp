<?php
declare(strict_types=1);

// 模拟受控节点上报，验证真实数据库及人员 HTTP 投影，不作为真实 MQTT/TLS 连接证据。
ini_set('zend.exception_ignore_args', '1');
require dirname(__DIR__, 2) . '/vendor/autoload.php';

Type\Runtime\CoroutineRuntime::enableIo();
Type\Runtime\CoroutineRuntime::run(static function (): void {
    $driver = match (getenv('DB_DRIVER')) {
        'pgsql' => new Type\Orm\Pgsql\PgsqlDriver(getenv('DB_HOST'), (int)getenv('DB_PORT'), getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD')),
        'mysql' => new Type\Orm\Mysql\MysqlDriver(getenv('DB_HOST'), (int)getenv('DB_PORT'), getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD')),
        default => new Type\Orm\Sqlite\SqliteDriver(getenv('APP_BASE_PATH') . '/' . getenv('DB_SQLITE_FILE')),
    };
    $database = new Type\Orm\DatabaseManager(['default' => $driver]);
    $scope = new Type\Runtime\ExecutionScope();
    $records = [];
    $deviceRequest = [];
    try {
        $connection = $database->connect($scope);
        if ($driver->name() === 'pgsql') {
            $run = bin2hex(random_bytes(16));
            $request = ['action' => 'heartbeat', 'node_id' => 'device-observer', 'run_id' => $run,
                'observed_at' => time(), 'initial' => true, 'metrics' => ['connections' => 7, 'quotaRevision' => 0,
                    'durableConfigured' => 1, 'stopping' => false, 'unapproved_secret' => 'not-a-credential']];
            app\center\service\DeviceService::access($connection, $request);
            $deviceRequest = $request;
            $records[] = ['node_id' => $request['node_id'], 'run_id' => $run, 'connections' => 7];
        }
        $node = new app\platform\mqtt\service\NodeAccess($database, 'standalone-observer', 'observer-bootstrap',
            bin2hex(random_bytes(16)), 'observation/', ['host' => '127.0.0.1', 'port' => 18883, 'transport' => 'tls']);
        $stopping = false;
        $node->observe(static function () use (&$stopping): array {
            return ['connections' => 3, 'quotaRevision' => 0, 'stopping' => $stopping];
        });
        $node->heartbeat(time());
        $records[] = ['node_id' => 'standalone-observer', 'connections' => 3];
        if ($deviceRequest !== []) {
            $deviceRequest['action'] = 'stopped';
            $deviceRequest['initial'] = false;
            $deviceRequest['observed_at'] = time();
            $deviceRequest['metrics']['stopping'] = true;
            app\center\service\DeviceService::access($connection, $deviceRequest);
        }
        $stopping = true;
        $node->stopped(time());
        $replacement = bin2hex(random_bytes(16));
        $listener = ['host' => '127.0.0.1', 'port' => 18883, 'transport' => 'tls'];
        $metrics = ['connections' => 5, 'stopping' => false, 'unapproved_secret' => 'not-a-credential'];
        app\platform\mqtt\service\ResourceObservations::observeNode($connection, 'standalone-observer', $replacement, time(), true, false, $listener, $metrics);
        try {
            $node->heartbeat(time());
            throw new RuntimeException('迟到旧运行重新取得了节点观察权');
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'broker_resource_owner_lost') { throw $error; }
        }
        app\platform\mqtt\service\ResourceObservations::observeNode($connection, 'standalone-observer', $replacement, time(), false, true, $listener, $metrics);
        $records[count($records) - 1] = ['node_id' => 'standalone-observer', 'run_id' => $replacement, 'connections' => 5];
        echo json_encode(['records' => $records], JSON_THROW_ON_ERROR) . "\n";
    } finally {
        $scope->close();
        $database->close();
    }
});
