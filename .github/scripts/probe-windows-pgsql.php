<?php

declare(strict_types=1);

/** 直接验证 PDO 与官方 Swoole hook 的接缝，不加载应用、ORM 或生成模型。 */
function pgsqlProbeStep(string $stage): void
{
    echo '[pdo-pgsql-probe] ', $stage, PHP_EOL;
    flush();
}

function pgsqlProbeConnection(): void
{
    pgsqlProbeStep('connect');
    $pdo = new PDO(
        'pgsql:host=' . getenv('TYPE_PGSQL_HOST') . ';port=' . getenv('TYPE_PGSQL_PORT')
            . ';dbname=' . getenv('TYPE_PGSQL_DATABASE') . ';connect_timeout=5;sslmode=disable',
        getenv('TYPE_PGSQL_USER'),
        getenv('TYPE_PGSQL_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    pgsqlProbeStep('connected');
    $pdo->exec("SET TIME ZONE 'UTC'");
    $pdo->beginTransaction();
    pgsqlProbeStep('prepare');
    $statement = $pdo->prepare('SELECT CAST(? AS INTEGER) AS value');
    $statement->execute([7]);
    if ($statement->fetchColumn() !== 7) {
        throw new RuntimeException('PDO PostgreSQL 参数或结果不符');
    }
    $statement->closeCursor();
    unset($statement);
    $pdo->rollBack();
    pgsqlProbeStep('reset');
    $pdo->exec('DISCARD ALL');
    unset($pdo);
    pgsqlProbeStep('closed');
}

$mode = $argv[1] ?? '';
if (PHP_OS_FAMILY !== 'Windows' || !in_array($mode, ['sync', 'hook', 'runtime'], true)) {
    throw new RuntimeException('探针需要 Windows 与显式 sync、hook 或 runtime 模式');
}
if (!extension_loaded('swoole') || !extension_loaded('pdo_pgsql') || !defined('SWOOLE_HOOK_PDO_PGSQL')) {
    throw new RuntimeException('探针缺少 Swoole PostgreSQL hook 或 PDO PostgreSQL');
}
pgsqlProbeStep($mode);
if ($mode === 'sync') {
    pgsqlProbeConnection();
} else {
    $flags = SWOOLE_HOOK_PDO_PGSQL;
    if ($mode === 'runtime') {
        require dirname(__DIR__, 2) . '/vendor/autoload.php';
        \Type\Runtime\CoroutineRuntime::enableIo();
        $flags = \Swoole\Runtime::getHookFlags();
    }
    $scheduler = new \Swoole\Coroutine\Scheduler();
    $scheduler->set(['hook_flags' => $flags]);
    if ($scheduler->add(static function (): void {
        pgsqlProbeConnection();
    }) === false || !$scheduler->start()) {
        throw new RuntimeException('PostgreSQL 探针协程未完成');
    }
}
pgsqlProbeStep('passed');
