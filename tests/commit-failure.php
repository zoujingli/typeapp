<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/examples/model/Drivers.php';

$root = dirname(__DIR__);
$backendHost = getenv('TYPE_MYSQL_HOST') ?: '127.0.0.1';
$backendPort = getenv('TYPE_MYSQL_PORT') ?: '3306';
$originalDatabase = getenv('TYPE_MYSQL_DATABASE');
$testDatabase = 'type_outcome_test_' . bin2hex(random_bytes(6));
$generated = tempnam(sys_get_temp_dir(), 'type_outcome_models_');
expect($generated !== false, '无法准备故障模型');
$compiler = new Type\Build\ModelCompiler();
file_put_contents($generated, $compiler->compile([$root . '/examples/model/Models.php'])['code']);
$admin = TypeApp\ModelExample\Drivers::create('mysql')->connect();
$admin->exec('CREATE DATABASE `' . $testDatabase . '`');
putenv('TYPE_MYSQL_DATABASE=' . $testDatabase);
$probeScope = new Type\Runtime\ExecutionScope();
$probeDatabase = new Type\Orm\Database(TypeApp\ModelExample\Drivers::create('mysql'), 1, 0);
try {
    $pdo = TypeApp\ModelExample\Drivers::create('mysql')->connect();
    $pdo->exec('CREATE TABLE type_model_users (id BIGINT PRIMARY KEY AUTO_INCREMENT, display_name VARCHAR(255), age INTEGER, active BOOLEAN, secret VARCHAR(255), note VARCHAR(255) NULL)');
    $probe = $probeDatabase->connect($probeScope);
    if (isset($argv[1]) && $argv[1] !== '--php') {
        $command = nativeCommand($argv[1]);
    } else {
        $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; require ' . var_export($generated, true)
            . '; require ' . var_export($root . '/examples/model/Drivers.php', true)
            . '; require ' . var_export($root . '/examples/outcome-command.php', true) . '; main($argc, $argv);';
        $command = [PHP_BINARY, '-r', $launcher];
    }
    foreach (['begin', 'before', 'after'] as $mode) {
        $pdo->exec('DELETE FROM type_model_users');
        $log = tmpfile();
        expect($log !== false, '无法准备故障日志');
        $environment = getenv();
        $environment['TYPE_PROXY_HOST'] = $backendHost;
        $environment['TYPE_PROXY_PORT'] = $backendPort;
        $environment['TYPE_PROXY_MODE'] = $mode;
        $proxy = proc_open([PHP_BINARY, $root . '/tests/mysql-commit-proxy.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => $log], $pipes, null, $environment);
        expect(is_resource($proxy), '无法启动提交故障代理');
        try {
            stream_set_timeout($pipes[1], 5);
            $port = (int) fgets($pipes[1]);
            expect($port > 0 && $port <= 65535, '故障代理未监听');
            putenv('TYPE_MYSQL_HOST=127.0.0.1');
            putenv('TYPE_MYSQL_PORT=' . $port);
            [$status, $stdout, $stderr] = execute([...$command, 'fault-' . $mode]);
            expect($status === 0 && $stderr === '', '故障应用没有按协议返回：' . $stdout . $stderr);
            $expected = ['outcome' => $mode === 'begin' ? 'NOT_STARTED' : 'UNKNOWN', 'calls' => $mode === 'begin' ? 0 : 1,
                'callbacks' => 0, 'model_invalid' => $mode !== 'begin'];
            expect(json_decode($stdout, true) === $expected, '事务体被重试、回调被误执行或模型没有失效：' . $stdout);
            $marker = trim(stream_get_contents($pipes[1]));
            expect($marker === ['begin' => 'dropped_begin', 'before' => 'dropped_before_commit', 'after' => 'commit_confirmed_and_dropped'][$mode], '没有发生指定故障');
            expect((int) $probe->table('type_model_users')->aggregate('COUNT') === ($mode === 'after' ? 1 : 0), '故障后的真实持久化次数错误');
        } finally {
            putenv('TYPE_MYSQL_HOST=' . $backendHost);
            putenv('TYPE_MYSQL_PORT=' . $backendPort);
            fclose($pipes[1]);
            if (proc_get_status($proxy)['running']) {
                proc_terminate($proxy, 15);
            }
            proc_close($proxy);
            rewind($log);
            $error = stream_get_contents($log);
            fclose($log);
            if ($error !== '') {
                fwrite(STDERR, $error);
            }
        }
    }
    echo "真实 MySQL 开始失败、提交前断线、提交确认丢失与单次写入验证通过。\n";
} finally {
    $pdo = null;
    $probeScope->close();
    $probeDatabase->close();
    $admin->exec('DROP DATABASE `' . $testDatabase . '`');
    putenv($originalDatabase === false ? 'TYPE_MYSQL_DATABASE' : 'TYPE_MYSQL_DATABASE=' . $originalDatabase);
    unlink($generated);
}
