<?php

declare(strict_types=1);

// 通过真实 CLI 观察模块；完整 embed 与业务 AOT 由原生矩阵另外验收。
require __DIR__ . '/../plugin/type-build/src/SwooleThreadSource.php';

$module = $argv[1] ?? '';
if ($argc !== 2 || !is_file($module) || is_link($module) || PHP_VERSION !== '8.5.10' || !PHP_ZTS || PHP_DEBUG || PHP_INT_SIZE !== 8
    || !extension_loaded('swoole') || swoole_version() !== '6.3.0RC1'
    || Swoole\Thread::NATIVE_ENTRY_ABI !== 2 || !method_exists(Swoole\Thread::class, 'startNative')
    || !defined('SWOOLE_HOOK_PDO_PGSQL') || !defined('SWOOLE_HOOK_PDO_SQLITE')) {
    throw new RuntimeException('Swoole 模块、PHP ABI 或原生入口不符合固定契约');
}
$sqlite = false;
$pgsql = false;
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_PDO_PGSQL | SWOOLE_HOOK_PDO_SQLITE);
Swoole\Coroutine\run(static function () use (&$sqlite, &$pgsql): void {
    $database = new PDO('sqlite::memory:');
    $database->exec('CREATE TABLE verification (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
    $database->exec("INSERT INTO verification(value) VALUES ('native')");
    $sqlite = $database->query('SELECT value FROM verification')->fetchColumn() === 'native';
    try {
        new PDO('pgsql:host=127.0.0.1;port=1;connect_timeout=1;dbname=typeapp_probe', 'probe', 'probe');
    } catch (PDOException $exception) {
        $pgsql = str_contains(strtolower($exception->getMessage()), 'connect');
    }
});
if (!$sqlite || !$pgsql) {
    throw new RuntimeException('协程 SQLite 或 PostgreSQL 失败路径没有通过');
}
echo json_encode(['status' => 'passed', 'php' => PHP_VERSION, 'zts' => (bool) PHP_ZTS,
    'swoole' => swoole_version(), 'source' => Type\Build\SwooleThreadSource::REFERENCE,
    'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'native_entry_abi' => Swoole\Thread::NATIVE_ENTRY_ABI,
    'coroutine_and_sqlite' => $sqlite, 'pgsql_connection_failure' => $pgsql,
    'sha256' => hash_file('sha256', $module), 'bytes' => filesize($module)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
