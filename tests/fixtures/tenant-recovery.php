<?php
declare(strict_types=1);

// 在本轮专用真实数据库中核对统一恢复状态机；不代表物理备份、复制或 MQTT/TLS 验收。
ini_set('zend.exception_ignore_args', '1');
require dirname(__DIR__) . '/support.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use app\center\service\RecoveryService;
use Type\Core\Http\HttpError;
use Type\Orm\Connection;

Type\Runtime\CoroutineRuntime::enableIo();
Type\Runtime\CoroutineRuntime::run(static function (): void {
    $driver = match (getenv('DB_DRIVER')) {
        'pgsql' => new Type\Orm\Pgsql\PgsqlDriver(getenv('DB_HOST'), (int)getenv('DB_PORT'), getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD')),
        'mysql' => new Type\Orm\Mysql\MysqlDriver(getenv('DB_HOST'), (int)getenv('DB_PORT'), getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD')),
        default => new Type\Orm\Sqlite\SqliteDriver(getenv('APP_BASE_PATH') . '/' . getenv('DB_SQLITE_FILE')),
    };
    $database = new Type\Orm\DatabaseManager(['default' => $driver]);
    $scope = new Type\Runtime\ExecutionScope();
    try {
        $connection = $database->connect($scope);
        $snapshot = RecoveryService::snapshot($connection);
        $hashes = static function (array $records, string $kind): array {
            return array_column(array_values(array_filter($records, static fn (array $record): bool => $record['kind'] === $kind)), 'sha256', 'id');
        };
        $user = $connection->table('system_user')->where('login', '=', 'operator')->first();
        expect($user !== null, '缺少待核对的独立账号');
        $role = $connection->table('system_role')->where('tenant_id', '=', $user['tenant_id'])->where('protected', '=', 0)->first();
        expect($role !== null, '缺少待核对的普通角色');
        // 快照自己拥有事务；在专用库提交变异并在 finally 恢复，避免嵌套改变 SQLite 事务模式。
        $mutated = static function (Closure $write, Closure $undo) use ($connection): array {
            $connection->transaction($write);
            try {
                return RecoveryService::snapshot($connection);
            } finally {
                $connection->transaction($undo);
            }
        };
        $bindings = $connection->table('system_user_role')->where('tenant_id', '=', $user['tenant_id'])->where('user_id', '=', $user['id'])->get();
        $changed = $mutated(static function (Connection $transaction) use ($user): void {
            $transaction->table('system_user_role')->where('tenant_id', '=', $user['tenant_id'])->where('user_id', '=', $user['id'])->delete();
        }, static function (Connection $transaction) use ($bindings): void {
            foreach ($bindings as $binding) { $transaction->table('system_user_role')->insert($binding); }
        });
        expect($hashes($snapshot['records'], 'system_user')[$user['id']] !== $hashes($changed['records'], 'system_user')[$user['id']], '账号角色撤销没有进入恢复指纹');
        $changed = $mutated(static function (Connection $transaction) use ($user): void {
            $transaction->table('platform_tenant')->where('id', '=', $user['tenant_id'])->update(['enabled' => 0]);
        }, static function (Connection $transaction) use ($user): void {
            $transaction->table('platform_tenant')->where('id', '=', $user['tenant_id'])->update(['enabled' => 1]);
        });
        expect($hashes($snapshot['records'], 'system_user')[$user['id']] !== $hashes($changed['records'], 'system_user')[$user['id']], '客户停用没有进入独立账号恢复指纹');
        $changed = $mutated(static function (Connection $transaction): void {
            $transaction->table('system_menu')->where('permission', '=', 'center.devices.read')->update(['enabled' => 0]);
        }, static function (Connection $transaction): void {
            $transaction->table('system_menu')->where('permission', '=', 'center.devices.read')->update(['enabled' => 1]);
        });
        expect($hashes($snapshot['records'], 'system_role') !== $hashes($changed['records'], 'system_role'), '公共菜单停用没有进入角色恢复指纹');
        expect(RecoveryService::snapshot($connection) === $snapshot, '指纹变异验证没有完整回滚');
        expect($connection->table('platform_handoff')->aggregate('COUNT') > 0, '恢复前缺少未消费交接码');
        $id = bin2hex(random_bytes(16));
        $state = RecoveryService::begin($connection, $id, 'tenant-test', 'owned-source-stopped');
        expect(RecoveryService::begin($connection, $id, 'tenant-test', 'owned-source-stopped') === $state, '同一恢复请求没有保留进度');
        try {
            RecoveryService::ready($connection);
            throw new RuntimeException('恢复隔离期间启动门仍开放');
        } catch (HttpError $error) {
            expect($error->status() === 503 && $error->errorCode() === 'recovery_isolated', '恢复隔离未返回明确拒绝');
        }
        for ($batch = 0; $state['state'] === 'isolating' && $batch < 100; ++$batch) {
            $state = RecoveryService::isolate($connection, $id);
        }
        expect($state['state'] === 'reviewing', '恢复隔离没有在预算内完成');
        // 外部权威清单不批准一个账号，并拒绝一个角色的旧授权指纹。
        $snapshot['records'] = array_values(array_filter($snapshot['records'], static fn (array $record): bool => !($record['kind'] === 'system_user' && $record['id'] === $user['id'])));
        foreach ($snapshot['records'] as &$record) {
            if ($record['kind'] === 'system_role' && $record['id'] === $role['id']) { $record['sha256'] = str_repeat('0', 64); }
        }
        unset($record);
        $contents = json_encode($snapshot, JSON_THROW_ON_ERROR);
        try {
            RecoveryService::review($connection, $id, $contents, str_repeat('0', 64), 0);
            throw new RuntimeException('恢复清单错误摘要未被拒绝');
        } catch (HttpError $error) {
            expect($error->errorCode() === 'recovery_snapshot_digest', '恢复清单摘要拒绝原因错误');
        }
        $digest = hash('sha256', $contents);
        for ($offset = 0; $offset < count($snapshot['records']); $offset += 100) {
            $state = RecoveryService::review($connection, $id, $contents, $digest, $offset);
        }
        for ($batch = 0; $state['state'] === 'restoring' && $batch < 100; ++$batch) {
            $state = RecoveryService::restore($connection, $id);
        }
        expect($state['state'] === 'ready', '恢复没有在预算内完成');
        expect((int)$connection->table('system_user')->where('id', '=', $user['id'])->first()['recovery_verified'] === 0, '权威清单缺失账号被恢复');
        expect((int)$connection->table('system_role')->where('id', '=', $role['id'])->first()['recovery_verified'] === 0, '指纹不匹配角色被恢复');
        expect($connection->table('system_user')->where('recovery_verified', '=', 1)->aggregate('COUNT') > 0, '匹配的独立账号没有恢复');
        foreach (['platform_session', 'system_session', 'platform_handoff'] as $table) {
            expect((int)$connection->table($table)->aggregate('COUNT') === 0, '恢复后残留旧会话或交接码：' . $table);
        }
        RecoveryService::ready($connection);
        expect(RecoveryService::restore($connection, $id) === $state, '恢复完成后重试不幂等');
        echo json_encode(['state' => $state['state']], JSON_THROW_ON_ERROR) . "\n";
    } finally {
        $scope->close();
        $database->close();
    }
});
