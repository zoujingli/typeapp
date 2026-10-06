<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-rollout-redis.php';

use Type\Build\ProjectCreator;

$root = dirname(__DIR__);
$work = $root . '/build/scaffolding space ' . bin2hex(random_bytes(6));
$redis = null;
try {
    (new ProjectCreator())->create($root . '/templates/type-project', $work, 'sqlite');
    $composer = json_decode(file_get_contents($work . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $composer['require']['zoujingli/type-queue'] = '*';
    $composer['require']['zoujingli/type-scheduler'] = '*';
    $composer['repositories'] = [];
    foreach (glob($root . '/plugin/type-*/composer.json') as $manifest) {
        $package = json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
        $composer['repositories'][] = ['type' => 'path', 'url' => dirname($manifest), 'options' => ['symlink' => false, 'versions' => [$package['name'] => '1.0.x-dev']]];
    }
    $composer['repositories'] = [...$composer['repositories'], ...localComposerRepositories($root), ['packagist.org' => false]];
    file_put_contents($work . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
    $composerCommand = getenv('TYPE_COMPOSER_PHAR') ? [PHP_BINARY, getenv('TYPE_COMPOSER_PHAR')] : [getenv('COMPOSER_BINARY') ?: 'composer'];
    successful([...$composerCommand, 'install', '--no-scripts', '--no-plugins', '--no-interaction', '--no-progress'], $work);
    $config = $work . '/type-app.json';
    $make = static fn (array $arguments): string => successful([PHP_BINARY, $work . '/vendor/bin/type', 'make', $config, ...$arguments], sys_get_temp_dir());
    // 比对调用者可见的源码与声明；make 互斥锁允许存在，但失败不能留下半个角色。
    $snapshot = static function () use ($work): array {
        $files = [];
        foreach (['composer.json', 'composer.lock', 'type-app.json'] as $file) {
            $files[$file] = hash_file('sha256', $work . '/' . $file);
        }
        foreach (['app', 'config'] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $entry) {
                if ($entry->isFile()) {
                    $files[substr($entry->getPathname(), strlen($work) + 1)] = hash_file('sha256', $entry->getPathname());
                }
            }
        }
        ksort($files);
        return $files;
    };
    $make(['module', 'app\\catalog\\Product', '--table=products', '--route=/products', '--role=users', '--version=002_products', '--migration-registry=app\\common\\database\\Schema']);
    successful([PHP_BINARY, $work . '/vendor/bin/type', 'schema:prepare', $work . '/app/catalog/database/CreateProduct.php'], sys_get_temp_dir());
    $make(['service', 'app\\work\\Marker']);
    $make(['command', 'app\\work\\PrintMarker', '--service=app\\work\\Marker', '--name=marker']);
    $make(['job', 'app\\work\\MarkerJob', '--service=app\\work\\Marker', '--type=marker', '--version=1']);
    $make(['task', 'app\\work\\MarkerTask', '--service=app\\work\\Marker', '--name=marker', '--interval=60']);
    $inspection = json_decode(successful([PHP_BINARY, $work . '/vendor/bin/type', 'inspect-application', $config, '--json'], sys_get_temp_dir()), true, 512, JSON_THROW_ON_ERROR);
    expect(in_array('marker', $inspection['assembly']['commands'], true) && count($inspection['routes']) >= 4, '生成结果未接入统一声明');
    $result = successful([PHP_BINARY, $work . '/vendor/bin/type', 'dev', $config, 'marker', 'scaffold-ok'], sys_get_temp_dir());
    expect(str_contains($result, 'scaffold-ok'), '命令没有经过标准入口执行业务服务');
    $composerSource = file_get_contents($work . '/composer.json');
    foreach ([
        ['zoujingli/type-queue', ['job', 'app\\work\\MissingQueueJob', '--service=app\\work\\Marker', '--type=missing-queue', '--version=1']],
        ['zoujingli/type-scheduler', ['task', 'app\\work\\MissingSchedulerTask', '--service=app\\work\\Marker', '--name=missing-scheduler', '--interval=60']],
    ] as [$package, $arguments]) {
        // 已安装或可自动加载的包不能代替应用显式声明的生产依赖。
        $missing = json_decode($composerSource, true, 512, JSON_THROW_ON_ERROR);
        unset($missing['require'][$package]);
        file_put_contents($work . '/composer.json', json_encode($missing, JSON_THROW_ON_ERROR));
        try {
            $beforeFiles = $snapshot();
            [$status, $stdout, $stderr] = execute([PHP_BINARY, $work . '/vendor/bin/type', 'make', $config, ...$arguments], sys_get_temp_dir());
            expect($status !== 0 && str_contains($stdout . $stderr, 'make 需要显式生产依赖：' . $package), '缺少生产依赖没有明确拒绝：' . $package);
            expect($snapshot() === $beforeFiles, '缺依赖拒绝后改变已有文件或留下角色源码');
        } finally {
            file_put_contents($work . '/composer.json', $composerSource);
        }
    }
    $before = file_get_contents($config);
    $beforeFiles = $snapshot();
    foreach ([['model', 'app\\catalog\\model\\Product', '--table=other'], ['model', 'app\\..\\Invalid', '--table=invalid'],
        ['command', 'app\\work\\Duplicate', '--service=app\\work\\Marker', '--name=marker'],
        ['job', 'app\\work\\DuplicateJob', '--service=app\\work\\Marker', '--type=marker', '--version=1'],
        ['task', 'app\\..\\InvalidTask', '--service=app\\work\\Marker', '--name=invalid-task', '--interval=60'],
        ['task', 'app\\work\\InvalidTaskName', '--service=app\\work\\Marker', '--name=invalid/name', '--interval=60'],
        ['task', 'app\\work\\InvalidTask', '--service=app\\work\\Marker', '--name=invalid', '--cron=invalid', '--timezone=UTC']] as $arguments) {
        expect(execute([PHP_BINARY, $work . '/vendor/bin/type', 'make', $config, ...$arguments], sys_get_temp_dir())[0] !== 0, '无效脚手架没有失败');
        expect(file_get_contents($config) === $before, '拒绝后改变原声明');
        expect($snapshot() === $beforeFiles, '无效脚手架改变已有文件或留下角色源码');
    }
    echo "脚手架缺少队列/调度生产依赖与错误Task名均已拒绝，已有源码和声明摘要未变化。\n";
    $environment = getenv();
    $environment['APP_API_TOKEN'] = str_repeat('a', 40);
    $environment['DB_SQLITE_FILE'] = 'data/scaffolding.sqlite';
    successful([PHP_BINARY, $work . '/vendor/bin/type', 'dev', $config, 'migrate', 'run'], $work, $environment);
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $environment['APP_PORT'] = substr(strrchr($address, ':'), 1);
    $environment['APP_ALLOWED_HOSTS'] = $address;
    $process = new Type\Testing\Process([PHP_BINARY, $work . '/vendor/bin/type', 'dev', $config, 'serve'], $work, $environment);
    try {
        $client = new Type\Testing\HttpClient('http://' . $address, 2);
        $deadline = microtime(true) + 10;
        $ready = false;
        do {
            try {
                $ready = $client->request('GET', '/readyz')->status === 200;
            } catch (RuntimeException) {
            }
            if ($ready || !$process->running()) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        expect($ready, '脚手架HTTP未就绪：' . $process->stderr());
        $headers = ['Authorization' => 'Bearer ' . $environment['APP_API_TOKEN'], 'Content-Type' => 'application/json'];
        expect($client->request('GET', '/products')->status === 401, '生成模块遗漏鉴权');
        $created = $client->request('POST', '/products', $headers, '{"name":"First","id":999,"tenant":"forged"}');
        expect($created->status === 201, '模块创建失败：' . $created->body);
        $record = $created->json();
        expect($record['name'] === 'First' && $record['id'] !== 999 && !isset($record['tenant']), '响应或身份字段没有白名单');
        $id = $record['id'];
        expect($client->request('GET', '/products/' . $id, $headers)->status === 200, '模块查询失败');
        $patched = $client->request('PATCH', '/products/' . $id, $headers, '{}');
        expect($patched->status === 200 && $patched->json()['name'] === 'First', 'PATCH缺失字段没有保留：' . $patched->body);
        $changed = $client->request('PATCH', '/products/' . $id, $headers, '{"name":"Changed"}');
        expect($changed->status === 200 && $changed->json()['name'] === 'Changed', 'PATCH没有更新业务字段');
        expect($client->request('PATCH', '/products/' . $id, $headers, '{"name":null}')->status === 422, 'PATCH明确null没有拒绝');
        expect($client->request('GET', '/products/99999', $headers)->status === 404, '模块缺失记录没有稳定错误');
    } finally {
        $stopped = $process->stop(5);
        expect($stopped->signal !== 9, '生成模块服务器没有正常停止');
    }
    copy($root . '/tests/fixtures/scaffolding-work.php', $work . '/verify-work.php');
    $workEnvironment = getenv();
    if (!isset($workEnvironment['TYPE_REDIS_PORT']) && isset($workEnvironment['TYPE_REDIS_SERVER'])) {
        // 无常驻测试 Redis 的 CI 复用既有进程所有者，资源只属于本轮独立消费者。
        $redis = new NativeRolloutRedis($work . '/test-redis', $workEnvironment['TYPE_REDIS_SERVER']);
        $workEnvironment = array_replace($workEnvironment, $redis->environment());
    }
    $queueOutput = successful([PHP_BINARY, $work . '/verify-work.php', 'queue'], $work, $workEnvironment);
    expect(str_contains($queueOutput, 'queue-scaffold-ok') && str_contains($queueOutput, 'queue-after-stop'), '生成Job没有真实消费或正常停止恢复');
    $firstTick = json_decode(successful([PHP_BINARY, $work . '/verify-work.php', 'schedule'], $work), true, 512, JSON_THROW_ON_ERROR);
    $nextTick = json_decode(successful([PHP_BINARY, $work . '/verify-work.php', 'schedule'], $work), true, 512, JSON_THROW_ON_ERROR);
    expect(count($firstTick) === 1 && $firstTick[0]['state'] === 'succeeded' && isset($firstTick[0]['result']['marker']) && $nextTick === [], '生成Task没有保留跨进程游标');
    $make(['model', 'app\\notes\\Note', '--table=notes']);
    $make(['input', 'app\\notes\\NoteInput']);
    $make(['service', 'app\\notes\\NoteService', '--model=app\\notes\\Note']);
    $make(['controller', 'app\\notes\\NoteController', '--service=app\\notes\\NoteService', '--input=app\\notes\\NoteInput', '--route=/notes', '--role=users']);
    $make(['migration', 'app\\notes\\CreateNotes', '--version=003_notes']);
    $before = file_get_contents($config);
    $registry = file_get_contents($work . '/app/common/database/Schema.php');
    expect(execute([PHP_BINARY, $work . '/vendor/bin/type', 'make', $config, 'module', 'app\\duplicate\\Product', '--table=duplicate', '--route=/products', '--role=users', '--version=004_duplicate', '--migration-registry=app\\common\\database\\Schema'])[0] !== 0, '重复路由未在发布前拒绝');
    expect(!is_dir($work . '/app/duplicate') && file_get_contents($config) === $before && file_get_contents($work . '/app/common/database/Schema.php') === $registry, '重复路由留下半个模块');
    if (PHP_OS_FAMILY !== 'Windows') {
        symlink($work . '/app/notes', $work . '/app/linked');
        try {
            expect(execute([PHP_BINARY, $work . '/vendor/bin/type', 'make', $config, 'model', 'app\\linked\\Escape', '--table=escape'])[0] !== 0, '链接目标没有拒绝');
        } finally {
            unlink($work . '/app/linked');
        }
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            chmod($work . '/app/common/database/Schema.php', 0444);
            try {
                expect(execute([PHP_BINARY, $work . '/vendor/bin/type', 'make', $config, 'module', 'app\\readonly\\Product', '--table=readonly', '--route=/readonly', '--role=users', '--version=004_readonly', '--migration-registry=app\\common\\database\\Schema'])[0] !== 0, '只读关联文件没有拒绝');
                expect(!is_dir($work . '/app/readonly') && file_get_contents($config) === $before, '只读目标留下半个模块');
            } finally {
                chmod($work . '/app/common/database/Schema.php', 0644);
            }
        }
    }
    expect(glob($work . '/.type-make-*') === [], '脚手架遗留暂存目录');
    echo "脚手架模块、任务声明、标准命令入口、真实SQLite CRUD/PATCH/鉴权、Redis消费、持久调度恢复与写入拒绝通过。\n";
} finally {
    $redis?->close();
    removeTestDirectory($work);
}
