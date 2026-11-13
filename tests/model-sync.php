<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';

use Type\Build\ModelCompiler;

// 专用 MySQL 进程与临时项目由本入口拥有，不连接用户数据库。
$root = dirname(__DIR__);
expect(count($argv) === 2, '用法：php tests/model-sync.php <MySQL工具根目录>');
$work = $root . '/build/model-sync-' . bin2hex(random_bytes(6));
expect(mkdir($work, 0700), '无法建立模型同步验证目录');
$database = null;
$pdo = null;
try {
    $database = new NativeDatabase($work . '/database', 'mysql', NativeDatabase::tools('mysql', $argv[1]));
    $environment = $database->environment();
    $endpoint = ['host' => $environment['TYPE_MYSQL_HOST'], 'port' => (int)$environment['TYPE_MYSQL_PORT'],
        'database' => $environment['TYPE_MYSQL_DATABASE'], 'username' => $environment['TYPE_MYSQL_USER'], 'password' => $environment['TYPE_MYSQL_PASSWORD']];
    $credentials = $work . '/connection.json';
    file_put_contents($credentials, json_encode($endpoint, JSON_THROW_ON_ERROR));
    chmod($credentials, 0600);
    $pdo = new PDO('mysql:host=' . $endpoint['host'] . ';port=' . $endpoint['port'] . ';dbname=' . $endpoint['database'] . ';charset=utf8mb4', $endpoint['username'], $endpoint['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE center_reading (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, tenant_id CHAR(32) NOT NULL COMMENT '客户标识', amount DECIMAL(18,6) NOT NULL COMMENT '精确读数', sequence BIGINT UNSIGNED NOT NULL COMMENT '设备序号', signed_sequence BIGINT NOT NULL DEFAULT 0, memo VARCHAR(100) NULL COMMENT '备注', base INT NOT NULL DEFAULT 7, total INT GENERATED ALWAYS AS (base * 2) STORED, UNIQUE KEY tenant_identity (tenant_id,id)) COMMENT='设备读数'");
    mkdir($work . '/app');
    file_put_contents($work . '/app/Command.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace SyncApp;
final class Command implements \Type\Core\Command {
    public function run(\Type\Core\Configuration $configuration, array $arguments): int { return 0; }
}
PHP);
    $manifest = ['name' => 'tests/model-sync', 'require' => ['zoujingli/type-orm' => '*', 'zoujingli/type-core' => '*', 'zoujingli/type-orm-mysql' => '*'],
        'autoload' => ['psr-4' => ['SyncApp\\' => 'app/']], 'config' => ['vendor-dir' => $root . '/vendor']];
    file_put_contents($work . '/composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    $configuration = $work . '/type-app.json';
    file_put_contents($configuration, json_encode(['sources' => ['app'], 'application' => ['enabled' => ['tests/model-sync'],
        'commands' => [['name' => 'verify', 'class' => 'SyncApp\Command']]]], JSON_THROW_ON_ERROR));
    $command = [PHP_BINARY, $root . '/vendor/bin/type', 'model:sync', $configuration, 'SyncApp\model\Reading', '--table=center_reading', '--connection=' . $credentials];
    $run = static function (array $extra = []) use ($command, $root): array {
        return json_decode(successful([...$command, ...$extra], $root), true, 64, JSON_THROW_ON_ERROR);
    };
    $preview = $run();
    $model = $work . '/app/model/Reading.php';
    expect($preview['changed'] && !$preview['written'] && !file_exists($model), '默认预览修改了模型文件');
    expect($run(['--write'])['written'], '显式写入未生成模型');
    expect(!$run(['--write'])['changed'], '相同结构重复同步不幂等');
    $source = file_get_contents($model);
    $method = "    /** 业务展示保持维护者的原始格式。 */\n    public function label(): string { return 'reading=' . \$this->amount; }\n";
    file_put_contents($model, substr($source, 0, strrpos($source, '}')) . $method . "}\n");
    $pdo->exec("ALTER TABLE center_reading MODIFY amount DECIMAL(20,8) NOT NULL COMMENT '高精度读数', ADD quality VARCHAR(20) NOT NULL DEFAULT 'new' COMMENT '质量'");
    $changed = $run(['--write']);
    expect($changed['added'] === ['quality'] && str_contains(file_get_contents($model), $method), '结构更新没有保留业务方法');
    expect(str_contains(file_get_contents($model), '@property-read int|null $total') && str_contains(file_get_contents($model), '@property string $amount 高精度读数'), '缺少字段注释或生成列 IDE 只读提示');
    $pdo->exec('ALTER TABLE center_reading DROP COLUMN memo');
    $before = file_get_contents($model);
    expect(execute([...$command, '--write'], $root)[0] !== 0 && file_get_contents($model) === $before, '字段删除没有保全原模型');
    expect($run(['--write', '--drop-missing'])['removed'] === ['memo'], '显式字段移除未生效');
    $pdo->exec('ALTER TABLE center_reading ADD unsupported DOUBLE NULL');
    $before = file_get_contents($model);
    expect(execute([...$command, '--write'], $root)[0] !== 0 && file_get_contents($model) === $before, '不支持的类型没有在写入前拒绝');
    $pdo->exec('ALTER TABLE center_reading DROP COLUMN unsupported');
    $injected = $command;
    $injected[5] = '--table=center_reading;DROP TABLE center_reading';
    expect(execute($injected, $root)[0] !== 0, '非法表名没有拒绝');
    $generated = (new ModelCompiler())->compile([$model, $root . '/plugin/type-orm/src/Model.php']);
    file_put_contents($work . '/generated.php', $generated['code']);
    $program = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; require __DIR__ . "/generated.php";' . <<<'PHP'
$endpoint = json_decode(file_get_contents($argv[1]), true, 16, JSON_THROW_ON_ERROR);
Type\Runtime\CoroutineRuntime::enableIo();
Type\Runtime\CoroutineRuntime::run(static function () use ($endpoint): void {
    $manager = new Type\Orm\DatabaseManager(['default' => new Type\Orm\Mysql\MysqlDriver($endpoint['host'], $endpoint['port'], $endpoint['database'], $endpoint['username'], $endpoint['password'])]);
    Type\Orm\Db::configure($manager);
    $scope = new Type\Runtime\ExecutionScope();
    try {
        $id = $scope->run(static function (Type\Runtime\ExecutionScope $current): int {
            $created = SyncApp\model\Reading::create(['amount' => '123456789012.12345678', 'sequence' => '18446744073709551615', 'signed_sequence' => '-9223372036854775808']);
            $model = SyncApp\model\Reading::find($created->id);
            if ($model->label() !== 'reading=123456789012.12345678' || $model->sequence !== '18446744073709551615' || $model->signed_sequence !== '-9223372036854775808' || $model->base !== 7 || $model->total !== 14 || $model->quality !== 'new') {
                throw new RuntimeException('真实 MySQL 默认值、生成列或精确数值错误');
            }
            $model->base = 9;
            $model->save();
            if (SyncApp\model\Reading::find($model->id)->total !== 18) { throw new RuntimeException('对象属性修改未持久化'); }
            try { $model->total = 1; throw new RuntimeException('生成列允许赋值'); }
            catch (Type\Orm\ModelException $error) { if ($error->errorCode() !== 'field_not_fillable') { throw $error; } }
            foreach (['18446744073709551616', '-1'] as $invalid) {
                try { $model->sequence = $invalid; throw new RuntimeException('无符号整数越界未拒绝'); }
                catch (Type\Orm\ModelException $error) { if ($error->errorCode() !== 'integer_out_of_range') { throw $error; } }
            }
            return $model->id;
        }, ['tenant_id' => str_repeat('a', 32)]);
        $scope->run(static function (Type\Runtime\ExecutionScope $current) use ($id): void {
            if (SyncApp\model\Reading::find($id) !== null) { throw new RuntimeException('模型跨 Tenant 返回记录'); }
        }, ['tenant_id' => str_repeat('b', 32)]);
        $scope->run(static function (Type\Runtime\ExecutionScope $current) use ($id): void {
            if (!SyncApp\model\Reading::find($id)->delete() || SyncApp\model\Reading::find($id) !== null) { throw new RuntimeException('模型删除失败'); }
        }, ['tenant_id' => str_repeat('a', 32)]);
    } finally { $scope->close(); $manager->close(); }
});
echo "mysql schema CRUD passed\n";
PHP;
    file_put_contents($work . '/verify.php', "<?php\n" . $program);
    expect(str_contains(successful([PHP_BINARY, $work . '/verify.php', $credentials], $root), 'mysql schema CRUD passed'), '生成模型未通过真实数据库 CRUD');
    expect(glob($work . '/.type-make-*') === [], '模型同步遗留暂存目录');
    echo "真实 MySQL：预览、生成、重复同步、注释、默认值、生成列、字段增删、非法类型/表名拒绝、方法保留与 Tenant 隔离 CRUD 通过。\n";
} finally {
    $pdo = null;
    $database?->close();
    removeTestDirectory($work);
}
