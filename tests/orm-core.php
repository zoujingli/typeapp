<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';

$root = dirname(__DIR__);
$driver = $argv[1] ?? 'sqlite';
expect(in_array($driver, ['mysql', 'pgsql', 'sqlite'], true), '请选择 mysql、pgsql 或 sqlite');
$tools = $driver === 'sqlite' ? [] : NativeDatabase::tools($driver, $argv[2] ?? '');
$work = $root . '/build/orm-core-' . bin2hex(random_bytes(6));
expect(mkdir($work, 0700), '无法创建核心 ORM 验证目录');
$database = null;
try {
    $database = new NativeDatabase($work . '/database', $driver, $tools);
    $environment = array_replace(getenv(), $database->environment());
    if ($driver === 'sqlite') {
        $environment['TYPE_SQLITE_FILE'] = $work . '/business.sqlite';
    }
    $models = (new Type\Build\ModelCompiler())->compile([$root . '/examples/orm-suite/Models.php']);
    file_put_contents($work . '/models.php', $models['code']);
    $launch = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; require ' . var_export($work . '/models.php', true) . ';';
    foreach (['drivers/' . $driver . '.php', 'Schema.php', 'ArticleObserver.php', 'CoreExercise.php', 'Suite.php'] as $file) {
        $launch .= 'require ' . var_export($root . '/examples/orm-suite/' . $file, true) . ';';
    }
    $launch .= 'Type\\Runtime\\CoroutineRuntime::enableIo(); echo json_encode(TypeApp\\OrmSuite\\Suite::run(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), PHP_EOL;';
    $process = new Type\Testing\Process([PHP_BINARY, '-r', $launch], $root, $environment);
    try {
        $result = $process->wait(60);
        file_put_contents($work . '/result.log', $result->stdout . $result->stderr);
        expect($result->successful() && $result->stderr === '', '核心 ORM 验证失败：' . $result->stdout . $result->stderr);
    } finally {
        $process->stop();
    }
    echo $driver . ' 属性模型、组合查询、关系计算、分页和诊断通过。' . PHP_EOL;
} finally {
    $database?->close();
    $evidence = $root . '/.cache/orm-core-evidence';
    if (!is_dir($evidence)) {
        mkdir($evidence, 0700, true);
    }
    file_put_contents($evidence . '/' . basename($work) . '.json', json_encode([
        'driver' => $driver, 'swoole' => phpversion('swoole'),
        'database' => $database?->evidence(),
        'output' => is_file($work . '/result.log') ? file_get_contents($work . '/result.log') : null,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    removeTestDirectory($work);
}
