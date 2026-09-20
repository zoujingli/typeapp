<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use app\common\model\CustomerMember;
use app\common\model\Tenant;
use Type\Build\ModelCompiler;
use Type\Orm\DatabaseManager;
use Type\Orm\Db;
use Type\Orm\Helper\QueryHelper;
use Type\Orm\ModelException;
use Type\Orm\Sqlite\SqliteDriver;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\ExecutionScope;

$root = dirname(__DIR__);
$work = $root . '/build/app-models-' . bin2hex(random_bytes(6));
expect(mkdir($work, 0700, true), '无法创建应用模型测试目录');
$generated = $work . '/models.php';

try {
    file_put_contents($generated, (new ModelCompiler())->compile([$root . '/app'])['code']);
    require $generated;
    CoroutineRuntime::run(static function () use ($work): void {
        $database = new DatabaseManager(['default' => new SqliteDriver($work . '/models.sqlite')], 1, 0);
        Db::configure($database);
        $scope = new ExecutionScope(null, ['tenant_id' => 'tenant-a']);
        try {
            $scope->run(static function (ExecutionScope $current): void {
                $connection = Db::connection('default', true);
                $connection->raw('CREATE TABLE iot_tenants (id VARCHAR(32) PRIMARY KEY, name VARCHAR(100) NOT NULL, enabled INTEGER NOT NULL, version INTEGER NOT NULL, created_at INTEGER NOT NULL)');
                $connection->raw('CREATE TABLE customer_members (id VARCHAR(32) PRIMARY KEY, tenant_id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, name VARCHAR(100) NOT NULL, enabled INTEGER NOT NULL, recovery_verified INTEGER NOT NULL, version INTEGER NOT NULL, created_at INTEGER NOT NULL)');
                $tenant = new Tenant(['id' => 'tenant-a', 'name' => '租户甲', 'enabled' => true, 'created_at' => time()]);
                expect($tenant->save() === 'created', '租户目录新增需要不必要的租户或连接');
                try {
                    CustomerMember::query()->get();
                    throw new RuntimeException('普通关联元数据被误认为可信租户身份');
                } catch (ModelException $error) {
                    expect($error->errorCode() === 'tenant_scope_required', '缺少可信租户错误码不符');
                }
                $current->run(static function (ExecutionScope $bound): void {
                    $member = new CustomerMember([
                        'id' => 'member-a', 'user_id' => 'user-a', 'name' => '成员甲',
                        'enabled' => true, 'recovery_verified' => true, 'created_at' => time(),
                    ]);
                    expect($member->save() === 'created' && $member->getTenantId() === 'tenant-a', '成员归属没有自动填充');
                    $helper = CustomerMember::search(['enabled' => true, 'keyword' => '甲']);
                    expect($helper instanceof QueryHelper, '静态 search 没有返回查询助手');
                    $rows = $helper->equal('enabled')->like(['keyword' => 'name'])->query()->master()->get();
                    expect(count($rows) === 1 && $rows[0] instanceof CustomerMember && $rows[0]->getId() === 'member-a', 'search 链式筛选或模型水合失败');
                    $member->setName('成员甲（已修改）');
                    expect($member->save() === 'updated' && $member->getVersion() === 2, '成员修改未遵守版本契约');
                    $bound->run(static function (ExecutionScope $other) use ($member): void {
                        expect(CustomerMember::query()->master()->find('member-a') === null, '模型查询泄露其他租户成员');
                        try {
                            $member->save();
                            throw new RuntimeException('模型随上下文变化切换了归属');
                        } catch (ModelException $error) {
                            expect($error->errorCode() === 'tenant_context_changed', '跨租户持久化错误码不符');
                        }
                    }, ['tenant_id' => 'tenant-b']);
                    expect(CustomerMember::query()->master()->find('member-a')->getName() === '成员甲（已修改）', '嵌套工作没有恢复原租户');
                }, ['tenant_id' => 'tenant-a']);
                expect($current->binding('tenant_id') === null, '退出工作后残留可信租户身份');
            });
        } finally {
            $scope->close();
            $database->close();
        }
    });
    echo "身份与租户 Model 无连接 CRUD、search 和可信上下文隔离通过。\n";
    if (($argv[1] ?? '') === '--pgsql-sync') {
        require __DIR__ . '/native-database.php';
        require __DIR__ . '/postgres-sync.php';
        $tools = NativeDatabase::tools('pgsql', $argv[2] ?? '');
        $primary = null;
        $standby = null;
        $evidence = ['status' => 'running'];
        try {
            $primary = new NativeDatabase($work . '/primary', 'pgsql', $tools);
            $standby = new PostgresSync($primary, $work . '/standby', $tools);
            $environment = $primary->environment();
            $driver = new Type\Orm\Pgsql\PgsqlDriver(
                $environment['TYPE_PGSQL_HOST'],
                (int) $environment['TYPE_PGSQL_PORT'],
                $environment['TYPE_PGSQL_DATABASE'],
                $environment['TYPE_PGSQL_USER'],
                $environment['TYPE_PGSQL_PASSWORD']
            );
            $store = new Type\Mqtt\PostgresStore($driver);
            $admin = $standby->connection();
            $admin->exec('CREATE TABLE iot_tenants (id VARCHAR(32) PRIMARY KEY, name VARCHAR(100) NOT NULL, enabled INTEGER NOT NULL, version INTEGER NOT NULL, created_at INTEGER NOT NULL)');
            CoroutineRuntime::run(static function () use ($store, $admin): void {
                $committed = $store->transaction(str_repeat('a', 32), static function (Type\Orm\Connection $transaction): array {
                    expect(Db::connection('default', true) === $transaction, '持久回调与 Model 使用了不同租约');
                    expect(ExecutionScope::current()->binding('tenant_id') === null, '持久消息未经认证即授予租户身份');
                    return Db::transaction(static function (): array {
                        $tenant = new Tenant(['id' => 'committed', 'name' => '同步持久', 'enabled' => true, 'created_at' => time()]);
                        $tenant->save();
                        return $tenant->toArray();
                    });
                });
                expect($committed->state === 'committed' && $committed->released && $committed->value['id'] === 'committed', 'Model 写入未取得同步持久证明或未释放');
                $rejected = $store->transaction(str_repeat('b', 32), static function (Type\Orm\Connection $transaction): array {
                    $tenant = new Tenant(['id' => 'rollback', 'name' => '须回滚', 'enabled' => true, 'created_at' => time()]);
                    $tenant->save();
                    throw new Type\Mqtt\ProtocolError(0x87);
                });
                expect($rejected->state === 'rejected' && $rejected->released && $rejected->reason === 0x87, '业务异常没有按原合同拒绝并清理');
                expect($admin->query("SELECT COUNT(*) FROM iot_tenants WHERE id = 'rollback'")->fetchColumn() === 0, 'Model 写入逃逸原持久事务');
                try {
                    ExecutionScope::current();
                    throw new RuntimeException('持久回调结束后遗留当前作用域');
                } catch (Type\Runtime\TaskException $error) {
                    expect($error->errorCode() === 'scope_missing', '回调退出后的作用域错误不符');
                }
            });
            expect($standby->standby()->query('SELECT COUNT(*) FROM iot_tenants')->fetchColumn() === 1, '同步备库没有相同提交结果');
            $admin = null;
            $evidence['status'] = 'passed';
            echo "同步持久 Worker 的当前作用域、Model 同事务及异常回滚通过。\n";
        } finally {
            $standby?->close();
            $primary?->close();
            $evidence['replication'] = $standby?->evidence();
            $evidence['database'] = $primary?->evidence();
            $directory = $root . '/.cache/app-models-evidence';
            if (!is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            file_put_contents($directory . '/' . basename($work) . '.json', json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        }
    }
} finally {
    removeTestDirectory($work);
}
