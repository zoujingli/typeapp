<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Orm\Mysql\MysqlDriver;
use Type\Orm\Pgsql\PgsqlDriver;
use Type\Testing\Assert;
use Type\Testing\HttpClient;
use Type\Testing\Process;

$root = dirname(__DIR__);
$driver = $argv[1];
$native = ($argv[2] ?? '') === '--native';
$environment = getenv();
$work = $root . '/build/integration-run-' . bin2hex(random_bytes(6));
Assert::true(mkdir($work, 0700, true), '无法创建集成测试专属目录');
$admin = null;
$created = false;
$http = null;
try {
    $isolated = ($argv[3] ?? '') === '--chroot';
    $sandbox = $isolated ? realpath($argv[4] ?? '') : null;
    Assert::true(!$isolated || ($native && is_string($sandbox) && is_file($sandbox . '/app/type-app')), '隔离集成需要已打包的原生产物');
    $environment['TYPE_MODEL_DRIVER'] = $driver;
    $environment['TYPE_INTEGRATION_APP'] = 'integration-' . bin2hex(random_bytes(6));
    $environment['TYPE_INTEGRATION_LOG'] = $work . '/integration.log';
    $environment['TYPE_INTEGRATION_HMAC'] = bin2hex(random_bytes(32));
    $environment['TYPE_INTEGRATION_TOKEN'] = bin2hex(random_bytes(24));
    $logFile = $environment['TYPE_INTEGRATION_LOG'];
    if (!is_dir($root . '/build')) {
        mkdir($root . '/build', 0700);
    }
    if ($isolated) {
        Assert::true(!is_dir($sandbox . '/app/vendor') && !is_file($sandbox . '/usr/local/bin/php'), '隔离目录不应有 Composer 或 PHP CLI');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sandbox, FilesystemIterator::SKIP_DOTS)) as $entry) {
            Assert::true(!$entry->isFile() || strtolower($entry->getExtension()) !== 'php', '隔离部署夹带了 PHP 源码');
        }
        $environment['PHPRC'] = '/app/php.ini';
        $environment['PHP_INI_SCAN_DIR'] = '/app/php.d';
        $environment['TYPE_INTEGRATION_LOG'] = '/tmp/' . basename($work) . '.log';
        $logFile = $sandbox . $environment['TYPE_INTEGRATION_LOG'];
        $command = ['chroot', $sandbox, '/app/type-app'];
        if (posix_geteuid() !== 0) {
            $keys = ['PHPRC', 'PHP_INI_SCAN_DIR', 'TYPE_MODEL_DRIVER', 'TYPE_HTTP_PORT', 'TYPE_SQLITE_FILE',
                'TYPE_INTEGRATION_APP', 'TYPE_INTEGRATION_LOG', 'TYPE_INTEGRATION_HMAC', 'TYPE_INTEGRATION_TOKEN',
                'TYPE_REDIS_HOST', 'TYPE_REDIS_PORT', 'TYPE_INTEGRATION_CACHE_HOST', 'TYPE_INTEGRATION_CACHE_PORT', 'TYPE_MYSQL_HOST', 'TYPE_MYSQL_PORT', 'TYPE_MYSQL_DATABASE',
                'TYPE_MYSQL_USER', 'TYPE_MYSQL_PASSWORD', 'TYPE_PGSQL_HOST', 'TYPE_PGSQL_PORT', 'TYPE_PGSQL_DATABASE', 'TYPE_PGSQL_USER', 'TYPE_PGSQL_PASSWORD'];
            $command = ['sudo', '-n', '--preserve-env=' . implode(',', $keys), ...$command];
        }
    } elseif ($native) {
        $command = [$root . '/build/integration/type-app'];
        $nativeIni = getenv('TYPE_NATIVE_PHP_INI');
        if ($nativeIni !== false) {
            Assert::true(is_file($nativeIni) && is_dir(dirname($nativeIni) . '/php.d'), '显式原生运行配置缺失');
            $environment['PHPRC'] = $nativeIni;
            $environment['PHP_INI_SCAN_DIR'] = dirname($nativeIni) . '/php.d';
        }
    } else {
        $models = new Type\Build\ModelCompiler();
        $file = $work . '/models.php';
        file_put_contents($file, $models->compile([$root . '/examples/orm-suite/Models.php'])['code']);
        $launch = 'require ' . var_export($root . '/vendor/autoload.php', true) . ';require ' . var_export($file, true) . ';';
        foreach (['examples/model/Drivers.php', 'examples/orm-suite/Schema.php', 'examples/outbox/Adapters.php', 'examples/coordination/QueueDispatchTask.php'] as $source) {
            $launch .= 'require_once ' . var_export($root . '/' . $source, true) . ';';
        }
        foreach (glob($root . '/examples/integration/*.php') as $source) {
            $launch .= 'require_once ' . var_export($source, true) . ';';
        }
        $launch .= 'require ' . var_export($root . '/examples/integration-command.php', true) . ';main($argc,$argv);';
        $command = [PHP_BINARY, '-d', 'swoole.enable_library=On', '-r', $launch];
    }
    $database = 'type_full_' . bin2hex(random_bytes(6));
    if ($driver === 'sqlite') {
        $environment['TYPE_SQLITE_FILE'] = $isolated ? '/tmp/' . basename($work) . '.sqlite' : $work . '/database.sqlite';
    } elseif ($driver === 'mysql') {
        $admin = (new MysqlDriver(getenv('TYPE_MYSQL_HOST') ?: '127.0.0.1', (int) (getenv('TYPE_MYSQL_PORT') ?: 3306), getenv('TYPE_MYSQL_DATABASE') ?: 'type_app_test', getenv('TYPE_MYSQL_USER') ?: 'root', getenv('TYPE_MYSQL_PASSWORD') ?: ''))->connect();
        $admin->exec('CREATE DATABASE ' . $database);
        $created = true;
        $environment['TYPE_MYSQL_DATABASE'] = $database;
    } else {
        $admin = (new PgsqlDriver(getenv('TYPE_PGSQL_HOST') ?: '127.0.0.1', (int) (getenv('TYPE_PGSQL_PORT') ?: 5432), getenv('TYPE_PGSQL_DATABASE') ?: 'type_app_test', getenv('TYPE_PGSQL_USER') ?: 'type_app', getenv('TYPE_PGSQL_PASSWORD') ?: ''))->connect();
        $admin->exec('CREATE DATABASE ' . $database);
        $created = true;
        $environment['TYPE_PGSQL_DATABASE'] = $database;
    }
    $process = new Process([...$command, 'scenario'], $root, $environment);
    try {
        $result = $process->wait(120);
        Assert::true($result->successful(), '集成场景失败（exit=' . $result->exitCode . ', timeout=' . (int) $result->timedOut . '）：'
            . $result->stderr . ($result->stdout !== '' ? "\n" . $result->stdout : ''));
        $report = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
    } finally {
        $process->stop();
    }
    Assert::same('audited', $report['article']['status']);
    Assert::same(1, $report['article']['views']);
    Assert::same(3, $report['worker']['completed']);
    Assert::same(0, $report['queue']['backlog']);
    Assert::true(str_contains(file_get_contents($logFile), '集成业务完成'));
    Assert::same(500, $report['measurements']['iterations']);
    Assert::same(10, count($report['measurements']['samples']));
    if ($native && !$isolated) {
        foreach ($report['measurements']['samples'] as $sample) {
            Assert::true(is_int($sample['rss_bytes']) && $sample['rss_bytes'] > 0, '真实原生性能基线缺少可观察 RSS，不能把 null 当作已测量');
        }
        Assert::true($report['measurements']['environment']['logical_cpus'] > 0
            && $report['measurements']['environment']['system_memory_bytes'] > 0, '原生性能基线缺少实际 CPU 或内存环境');
    }
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $environment['TYPE_HTTP_PORT'] = substr(strrchr($address, ':'), 1);
    $http = new Process([...$command, 'serve'], $root, $environment);
    $client = new HttpClient('http://' . $address);
    $ready = false;
    $deadline = microtime(true) + 5;
    do {
        Assert::true($http->running(), '集成 HTTP 提前退出：' . $http->stderr());
        try {
            if ($client->request('GET', '/readyz')->status === 200) {
                $ready = true;
                break;
            }
        } catch (RuntimeException) {
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    Assert::true($ready, '集成 HTTP 没有就绪');
    Assert::same(401, $client->request('GET', '/article?id=' . $report['article_id'])->status);
    $view = $client->request('GET', '/article?id=' . $report['article_id'], ['Authorization' => 'Bearer ' . $environment['TYPE_INTEGRATION_TOKEN']]);
    Assert::same(200, $view->status);
    Assert::same($report['article'], $view->json());
    Assert::true($http->stop(5)->signal !== 9, '集成 HTTP 没有正常停止');
    $report['native'] = $native;
    $report['http'] = true;
    $report['deployment'] = $isolated ? 'chroot-without-php-source' : 'development-runtime';
    $reportFile = $root . '/.cache/integration-evidence/' . basename($work) . '.json';
    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--report=')) {
            $reportFile = substr($argument, 9);
        }
    }
    if (!is_dir(dirname($reportFile))) {
        Assert::true(mkdir(dirname($reportFile), 0700, true), '无法创建集成报告目录');
    }
    file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    echo $driver . ' 用户文章、关系、精确值、缓存、Outbox、任务、Cron、日志与 HTTP 贯通，查询 ' . round($report['measurements']['operations_per_second'], 1) . " 次/秒。\n";
} finally {
    try {
        $http?->stop(2);
        if ($admin !== null && $created) {
            $admin->exec('DROP DATABASE ' . $database);
        }
    } finally {
        if (isset($logFile) && is_file($logFile)) {
            $evidence = $root . '/.cache/integration-evidence';
            if (!is_dir($evidence)) {
                mkdir($evidence, 0700, true);
            }
            Assert::true(copy($logFile, $evidence . '/' . basename($work) . '.log'), '无法保全集成日志');
        }
        if (($isolated ?? false) && is_string($sandbox)) {
            foreach (['.log', '.sqlite', '.sqlite-wal', '.sqlite-shm', '.sqlite.type-migration.lock'] as $suffix) {
                $owned = $sandbox . '/tmp/' . basename($work) . $suffix;
                if (is_file($owned)) {
                    unlink($owned);
                }
            }
        }
        removeTestDirectory($work);
    }
}
