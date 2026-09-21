<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';

$root = dirname(__DIR__);
$driver = $argv[2] ?? 'sqlite';
expect(in_array($driver, ['mysql', 'pgsql', 'sqlite'], true), '未知主从驱动');
$external = getenv('TYPE_READ_WRITE_EXTERNAL') === '1';
expect(!$external || (PHP_OS_FAMILY === 'Windows' && getenv('GITHUB_ACTIONS') === 'true'
    && getenv('RUNNER_OS') === 'Windows' && $driver !== 'sqlite'), '外部端点只接受 Windows CI 装置自建的独立实例');
$tools = $driver === 'sqlite' || $external ? [] : NativeDatabase::tools($driver, $argv[3] ?? (getenv('TYPE_' . strtoupper($driver) . '_TOOLS') ?: ''));
$work = $root . '/build/read-write-' . bin2hex(random_bytes(6));
expect(mkdir($work, 0700), '无法创建主从验收目录');
$servers = [];
$connections = [];
$report = ['driver' => $driver, 'platform' => PHP_OS_FAMILY, 'external-owned-endpoints' => $external, 'passed' => false];
try {
    $environment = getenv();
    foreach ($driver === 'sqlite' ? ['primary'] : ['primary', 'reader'] as $role) {
        if ($driver === 'sqlite') {
            $name = $work . '/database.sqlite';
            $pdo = new PDO('sqlite:' . $name);
        } else {
            $prefix = 'TYPE_' . strtoupper($driver) . '_';
            if ($external) {
                $prefix = ($role === 'reader' ? 'TYPE_READER_' : 'TYPE_') . strtoupper($driver) . '_';
                $values = $environment;
                foreach (['HOST', 'PORT', 'DATABASE', 'USER', 'PASSWORD'] as $key) {
                    expect(is_string($values[$prefix . $key] ?? null) && $values[$prefix . $key] !== '', '缺少隔离主从端点配置');
                }
                expect($values[$prefix . 'HOST'] === '127.0.0.1', '主从装置只使用本机隔离实例');
                expect($environment['TYPE_' . strtoupper($driver) . '_PORT'] !== $environment['TYPE_READER_' . strtoupper($driver) . '_PORT'], '主从不能共用同一端点');
            } else {
                $server = new NativeDatabase($work . '/' . $role, $driver, $tools);
                $servers[$role] = $server;
                $values = $server->environment();
            }
            $name = $values[$prefix . 'DATABASE'];
            $pdo = new PDO(
                $driver . ':host=' . $values[$prefix . 'HOST'] . ';port=' . $values[$prefix . 'PORT'] . ';dbname=' . $name,
                $values[$prefix . 'USER'],
                $values[$prefix . 'PASSWORD']
            );
            foreach (['HOST', 'PORT', 'DATABASE', 'USER', 'PASSWORD'] as $key) {
                $environment[($role === 'reader' ? 'TYPE_READER_' . strtoupper($driver) . '_' : $prefix) . $key] = $values[$prefix . $key];
            }
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE type_rw_probe (id INTEGER PRIMARY KEY, value VARCHAR(255) NOT NULL, parent_id INTEGER NOT NULL)');
        $pdo->exec($role === 'primary'
            ? "INSERT INTO type_rw_probe VALUES (1, '主库已有', 0), (10, '主库子', 1)"
            : "INSERT INTO type_rw_probe VALUES (1, '副本旧值', 0), (11, '副本子', 1)");
        $connections[$role] = $pdo;
        if ($role === 'primary') {
            $environment['TYPE_PRIMARY_DATABASE'] = $name;
        } else {
            expect($name === $environment['TYPE_PRIMARY_DATABASE'], '主从逻辑库名不一致');
        }
    }
    $generated = (new Type\Build\ModelCompiler())->compile([$root . '/examples/model/Models.php']);
    file_put_contents($work . '/models.php', $generated['code']);
    if (isset($argv[1]) && $argv[1] !== '--php') {
        if (PHP_OS_FAMILY === 'Windows') {
            $artifact = Type\Build\BuildPlatform::resolve($argv[1]);
            (new Type\Build\BuildPlatform())->assertArtifact($artifact);
            $build = json_decode(file_get_contents($artifact . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
            $ini = $build['runtime-profile']['ini'];
            expect(is_file($ini) && is_dir(dirname($ini) . '/php.d'), '原生主从运行配置缺失');
            $environment['PHPRC'] = $ini;
            $environment['PHP_INI_SCAN_DIR'] = dirname($ini) . '/php.d';
            $command = [$artifact];
        } else {
            $command = nativeCommand($argv[1]);
        }
    } else {
        $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; require ' . var_export($work . '/models.php', true)
            . '; require ' . var_export($root . '/examples/read-write-command.php', true) . '; main($argc, $argv);';
        $command = [PHP_BINARY, '-r', $launcher];
    }
    $process = new Type\Testing\Process([...$command, $driver], $root, $environment);
    try {
        $result = $process->wait(60);
        $report['output'] = $result->stdout . $result->stderr;
        expect($result->successful() && $result->stderr === ''
            && $result->stdout === "模型执行期选路、显式主读、事务固定与写后不粘主通过。\n", '主从模型验收失败：' . $report['output']);
    } finally {
        $process->stop();
    }
    if ($driver !== 'sqlite') {
        // 两个真实端点具有受控数据差异，不将独立服务器称为原生复制集群。
        expect($connections['reader']->query('SELECT value FROM type_rw_probe WHERE id = 1')->fetchColumn() === '副本旧值', '写入改变了只读端点数据');
        expect($connections['primary']->query('SELECT value FROM type_rw_probe WHERE id = 1')->fetchColumn() === '从库模型写主', '写入未落在主库');
    }
    $report['passed'] = true;
    echo $result->stdout;
} finally {
    $pdo = null;
    if ($external) {
        foreach ($connections as $connection) {
            $connection->exec('DROP TABLE type_rw_probe');
        }
        $connection = null;
    }
    $connections = [];
    foreach ($servers as $role => $server) {
        $server->close();
        $report[$role] = $server->evidence();
    }
    $evidence = $root . (PHP_OS_FAMILY === 'Windows' ? '/build' : '/.cache') . '/orm-routing-evidence';
    if (!is_dir($evidence)) {
        expect(mkdir($evidence, 0700, true), '无法创建主从验收证据目录');
    }
    $reportContents = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    expect(file_put_contents($evidence . '/' . basename($work) . '.json', $reportContents) === strlen($reportContents), '无法保全主从验收结果，原始目录继续保留');
    removeTestDirectory($work);
}
