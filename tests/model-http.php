<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require __DIR__ . '/http-support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/examples/model/Drivers.php';

$root = dirname(__DIR__);
$driverName = $argv[2] ?? 'sqlite';
expect(in_array($driverName, ['mysql', 'sqlite'], true), 'HTTP 模型验证请选择 MySQL 或 SQLite');
$configuration = json_decode(file_get_contents($root . '/docs/build-config/type-model-http.json'), true, 512, JSON_THROW_ON_ERROR);
$compiler = new Type\Build\ModelCompiler();
$generatedFile = tempnam(sys_get_temp_dir(), 'type_http_models_');
$sqliteFile = tempnam(sys_get_temp_dir(), 'type_model_db_');
$log = tmpfile();
expect($generatedFile !== false && $sqliteFile !== false && $log !== false, '无法准备模型请求验证');
file_put_contents($generatedFile, $compiler->compile([$root . '/examples/model/Models.php'])['code']);
$process = null;
$admin = null;
$createdDatabase = false;
$graceful = true;
$testDatabase = 'type_model_http_test_' . bin2hex(random_bytes(6));
$previousDatabase = getenv('TYPE_MYSQL_DATABASE');
$previousSqlite = getenv('TYPE_SQLITE_FILE');
try {
    if ($driverName === 'mysql') {
        $admin = TypeApp\ModelExample\Drivers::create('mysql')->connect();
        // 独立随机数据库只由本次测试创建，退出时只清理自己的实例。
        $admin->exec('CREATE DATABASE `' . $testDatabase . '`');
        $createdDatabase = true;
        putenv('TYPE_MYSQL_DATABASE=' . $testDatabase);
    } else {
        putenv('TYPE_SQLITE_FILE=' . $sqliteFile);
    }
    $pdo = TypeApp\ModelExample\Drivers::create($driverName)->connect();
    $pdo->exec('CREATE TABLE type_model_users (id ' . ($driverName === 'mysql' ? 'BIGINT PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT')
        . ', display_name VARCHAR(255) NOT NULL, age INTEGER NOT NULL, active BOOLEAN NOT NULL, secret VARCHAR(255) NOT NULL, note VARCHAR(255) NULL)');
    $pdo->exec('CREATE TABLE type_model_articles (id INTEGER PRIMARY KEY, user_id INTEGER NULL, title VARCHAR(255) NOT NULL)');
    $pdo->exec('CREATE TABLE type_model_profiles (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL UNIQUE, bio VARCHAR(255) NOT NULL)');
    $pdo->exec('CREATE TABLE type_model_tags (id INTEGER PRIMARY KEY, label VARCHAR(255) NOT NULL, internal VARCHAR(255) NOT NULL)');
    $pdo->exec('CREATE TABLE type_model_article_tag (article_id INTEGER NOT NULL, tag_id INTEGER NOT NULL, position INTEGER NOT NULL DEFAULT 0, '
        . 'PRIMARY KEY (article_id, tag_id), FOREIGN KEY (article_id) REFERENCES type_model_articles(id) ON DELETE CASCADE, '
        . 'FOREIGN KEY (tag_id) REFERENCES type_model_tags(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE TABLE type_model_invoices (id ' . ($driverName === 'mysql' ? 'BIGINT PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT')
        . ', external_id ' . ($driverName === 'mysql' ? 'DECIMAL(30,0)' : 'TEXT') . ' NOT NULL, amount '
        . ($driverName === 'mysql' ? 'DECIMAL(30,2)' : 'TEXT') . ' NOT NULL, happened_at '
        . ($driverName === 'mysql' ? 'DATETIME(6)' : 'TEXT') . " NOT NULL, note VARCHAR(255) NULL, internal VARCHAR(255) NOT NULL DEFAULT '')");
    $pdo->exec('CREATE TABLE type_model_documents (id ' . ($driverName === 'mysql' ? 'BIGINT PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT')
        . ', title VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL, deleted_at ' . ($driverName === 'mysql' ? 'DATETIME(6)' : 'TEXT') . ' NULL, version BIGINT NOT NULL DEFAULT 1)');
    $pdo->exec('CREATE TABLE type_model_counters (id ' . ($driverName === 'mysql' ? 'BIGINT PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT')
        . ', value BIGINT NOT NULL, version BIGINT NOT NULL DEFAULT 1)');
    $pdo = null;
    $address = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect(is_resource($address), '无法分配模型请求端口');
    $port = (int) substr(strrchr(stream_socket_get_name($address, false), ':'), 1);
    fclose($address);
    $environment = getenv();
    $environment['TYPE_MODEL_DRIVER'] = $driverName;
    $environment['TYPE_HTTP_PORT'] = (string) $port;
    if (($argv[1] ?? '--php') === '--php') {
        $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; require ' . var_export($generatedFile, true)
            . '; require ' . var_export($root . '/examples/model/Drivers.php', true)
            . '; require ' . var_export($root . '/examples/model/Handler.php', true)
            . '; require ' . var_export($root . '/examples/model/TransactionExercise.php', true)
            . '; require ' . var_export($root . '/examples/model/DatabaseAction.php', true)
            . '; require ' . var_export($root . '/examples/model/DocumentObserver.php', true)
            . '; require ' . var_export($root . '/examples/model/LifecycleExercise.php', true)
            . '; require ' . var_export($root . '/examples/model/ArticleTags.php', true)
            . '; require ' . var_export($root . '/examples/model/TagHandler.php', true)
            . '; require ' . var_export($root . '/examples/model/InvoiceHandler.php', true)
            . '; require ' . var_export($root . '/examples/model/CounterHandler.php', true)
            . '; require ' . var_export($root . '/examples/model-http-command.php', true) . '; main($argc, $argv);';
        $command = [PHP_BINARY, '-d', 'swoole.enable_library=Off', '-r', $launcher];
    } else {
        $command = nativeCommand($argv[1]);
    }
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $log, 2 => $log], $pipes, null, $environment);
    expect(is_resource($process), '无法启动模型 HTTP 入口');
    $deadline = microtime(true) + 10;
    $ready = false;
    while (microtime(true) < $deadline) {
        expect(proc_get_status($process)['running'], '模型 HTTP 进程提前退出');
        $connection = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.1);
        if (is_resource($connection)) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(10000);
    }
    expect($ready, '模型 HTTP 没有就绪');
    [$status, $body] = httpRequest($port, 'POST', '/counters', '', '{"value":0}');
    $counter = json_decode($body, true)['data'] ?? [];
    expect($status === 201 && $counter['version'] === 1, 'HTTP 版本初始化错误：' . $body);
    [$status, $body] = httpRequest($port, 'PATCH', '/counters?id=' . $counter['id'], '', '{"value":1,"version":1}');
    expect($status === 200 && json_decode($body, true)['data']['version'] === 2, 'HTTP 版本推进错误');
    [$status, $body] = httpRequest($port, 'PATCH', '/counters?id=' . $counter['id'], '', '{"value":2,"version":1}');
    expect($status === 409 && json_decode($body, true) === ['error' => 'optimistic_conflict'], 'HTTP 冲突语义错误');
    [$status, $body] = httpRequest($port, 'GET', '/counters?id=' . $counter['id']);
    expect($status === 200 && json_decode($body, true)['data']['value'] === 1, '冲突请求覆盖了最新值');
    [$status, $body] = httpRequest($port, 'POST', '/lifecycle-check');
    expect($status === 200 && json_decode($body, true) === ['soft_delete' => true, 'restore' => true, 'scopes' => true,
        'events' => true, 'cancelled' => true], 'HTTP 模型生命周期失败：' . $body);
    [$status, $body] = httpRequest($port, 'POST', '/invoices', '', '{"external_id":"92233720368547758081234567890","amount":"9999999999999999999999999999.99","happened_at":"2026-09-09T08:34:56.123456+08:00","note":null}');
    $invoice = json_decode($body, true)['data'] ?? [];
    expect($status === 201 && $invoice['external_id'] === '92233720368547758081234567890'
        && $invoice['amount'] === '9999999999999999999999999999.99' && $invoice['happened_at'] === '2026-09-09T00:34:56.123456Z', 'HTTP 金额、大整数或时间往返错误：' . $body);
    [$status, $body] = httpRequest($port, 'PATCH', '/invoices?id=' . $invoice['id'] . '&fields[]=amount', '', '{"amount":"1.2000"}');
    expect($status === 200 && json_decode($body, true) === ['data' => ['amount' => '1.20']], 'HTTP 精确字段部分更新错误：' . $body);
    [$status, $body] = httpRequest($port, 'GET', '/invoices?id=' . $invoice['id']);
    $updatedInvoice = json_decode($body, true)['data'];
    expect($status === 200 && $updatedInvoice['external_id'] === $invoice['external_id'] && $updatedInvoice['happened_at'] === $invoice['happened_at'], '精确字段 PATCH 覆盖了未加载字段');
    [$status, $body] = httpRequest($port, 'PATCH', '/invoices?id=' . $invoice['id'], '', '{"amount":"1.234"}');
    expect($status === 422 && json_decode($body, true)['error'] === 'scale_exceeded', 'HTTP 隐式舍入没有拒绝');
    [$status, $body] = httpRequest($port, 'POST', '/users', '', '{"name":"开发者","age":20,"active":true,"secret":"不可输出"}');
    $created = json_decode($body, true);
    expect($status === 201 && $created['data']['name'] === '开发者' && $created['data']['note'] === null
        && !isset($created['data']['secret']), '模型 HTTP 新增或安全输出失败：' . $status . ' ' . $body);
    $id = $created['data']['id'];
    [$status, $body] = httpRequest($port, 'POST', '/transaction-check');
    expect($status === 200 && json_decode($body, true) === ['inner_rollback' => true, 'outer_rollback' => true,
        'partial_fields' => true, 'models_invalidated' => true], 'HTTP 事务回滚与模型失效失败：' . $body);
    $fixture = TypeApp\ModelExample\Drivers::create($driverName)->connect();
    $statement = $fixture->prepare('INSERT INTO type_model_articles (id, user_id, title) VALUES (?, ?, ?)');
    $statement->execute([10, $id, '第一篇文章']);
    $statement->execute([11, $id, '第二篇文章']);
    $statement = $fixture->prepare('INSERT INTO type_model_profiles (id, user_id, bio) VALUES (?, ?, ?)');
    $statement->execute([20, $id, '用户简介']);
    $statement = $fixture->prepare('INSERT INTO type_model_tags (id, label, internal) VALUES (?, ?, ?)');
    $statement->execute([100, 'PHP', '不可输出']);
    $statement->execute([101, '框架', '不可输出']);
    $statement = null;
    $fixture = null;
    [$status, $body] = httpRequest($port, 'POST', '/article-tags?id=10', '', '{"tag_id":100,"position":3}');
    expect($status === 200 && json_decode($body, true) === ['data' => [['id' => 100, 'label' => 'PHP', 'pivot' => ['position' => 3]]]], 'HTTP 标签挂载或中间表输出失败：' . $body);
    httpRequest($port, 'POST', '/article-tags?id=11', '', '{"tag_id":100,"position":9}');
    [$status, $body] = httpRequest($port, 'PATCH', '/article-tags?id=10', '', '{"items":[{"id":101,"pivot":{"position":4}}]}');
    expect($status === 200 && json_decode($body, true) === ['data' => [['id' => 101, 'label' => '框架', 'pivot' => ['position' => 4]]]], 'HTTP 标签同步失败：' . $body);
    [$status, $body] = httpRequest($port, 'PATCH', '/article-tags?id=10', '', '{"items":[]}');
    expect($status === 200 && json_decode($body, true) === ['data' => []], 'HTTP 同步空集合失败');
    [$status, $body] = httpRequest($port, 'GET', '/article-tags?id=11');
    expect($status === 200 && json_decode($body, true)['data'][0]['pivot'] === ['position' => 9], 'HTTP 同步误删其他文章的关系');
    [$status, $body] = httpRequest($port, 'DELETE', '/article-tags?id=11', '', '{"tag_id":100}');
    expect($status === 200 && json_decode($body, true) === ['data' => []], 'HTTP 解除标签失败');
    [$status, $body] = httpRequest($port, 'GET', '/users?id=' . $id . '&fields[]=name&with[]=articles&with[]=profile');
    expect($status === 200 && json_decode($body, true) === ['data' => ['name' => '开发者',
        'articles' => [['id' => 10, 'title' => '第一篇文章'], ['id' => 11, 'title' => '第二篇文章']],
        'profile' => ['bio' => '用户简介']]], 'HTTP 关系预加载或输出映射失败：' . $body);
    [$status, $body] = httpRequest($port, 'GET', '/users?id=' . $id . '&fields[]=name');
    expect($status === 200 && json_decode($body, true) === ['data' => ['name' => '开发者']], '字段选择响应不明确');
    [$status, $body] = httpRequest($port, 'PATCH', '/users?id=' . $id . '&fields[]=name', '', '{"age":25}');
    expect($status === 200, '模型 HTTP 部分更新失败：' . $body);
    [$status, $body] = httpRequest($port, 'GET', '/users?id=' . $id);
    $updated = json_decode($body, true)['data'];
    expect($status === 200 && $updated['name'] === '开发者' && $updated['age'] === 25 && $updated['active'] === true, 'HTTP 部分查询后保存覆盖其他字段');
    [$status, $body] = httpRequest($port, 'GET', '/users?fields[]=secret');
    expect($status === 422 && json_decode($body, true)['error'] === 'validation_failed', '隐藏字段被外部选择');
    [$status, $body] = httpRequest($port, 'POST', '/users', '', '{"name":"缺失"}');
    expect($status === 422, '创建模型缺少必需字段未拒绝');
    [$status, $body] = httpRequest($port, 'DELETE', '/users?id=' . $id);
    expect($status === 200 && json_decode($body, true) === ['deleted' => true], 'HTTP 删除没有成功');
    [$status, $body] = httpRequest($port, 'GET', '/users?id=' . $id);
    expect($status === 404 && json_decode($body, true) === ['error' => 'not_found'], 'HTTP 未找到语义错误');
    [$status, $body] = httpRequest($port, 'GET', '/users');
    expect($status === 200 && json_decode($body, true) === ['data' => []], 'HTTP 空集合语义错误');
    echo '模型 HTTP 的真实 ' . $driverName . " CRUD、关系与字段隔离通过。\n";
} finally {
    if (is_resource($process)) {
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 15);
        }
        $deadline = microtime(true) + 10;
        do {
            $state = proc_get_status($process);
            if (!$state['running']) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        if ($state['running']) {
            proc_terminate($process, 9);
        }
        proc_close($process);
        $graceful = !$state['running'];
    }
    if ($createdDatabase) {
        $admin->exec('DROP DATABASE `' . $testDatabase . '`');
    }
    putenv($previousDatabase === false ? 'TYPE_MYSQL_DATABASE' : 'TYPE_MYSQL_DATABASE=' . $previousDatabase);
    putenv($previousSqlite === false ? 'TYPE_SQLITE_FILE' : 'TYPE_SQLITE_FILE=' . $previousSqlite);
    rewind($log);
    $output = stream_get_contents($log);
    fclose($log);
    if ($output !== '') {
        fwrite(STDERR, $output);
    }
    unlink($generatedFile);
    unlink($sqliteFile);
    foreach ([$sqliteFile . '-wal', $sqliteFile . '-shm'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    expect($graceful, '模型 HTTP 未按时停止');
}
