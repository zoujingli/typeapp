<?php

declare(strict_types=1);

require __DIR__ . '/build-scenario.php';
require __DIR__ . '/native-database.php';
require __DIR__ . '/native-rollout-redis.php';

use Type\Build\ArtifactManifest;
use Type\Build\BuildPlatform;
use Type\Testing\Process;

/**
 * 通过相同公开入口验证文档声明的参数/配置拒绝，不连接外部业务资源。
 *
 * @param array<string, list<string>> $commands 执行模式到实际入口命令的映射。
 * @param array<string, string> $environment 传给测试进程的显式环境。
 * @return list<array{mode: string, exit_code: int|null, rejected: bool}>
 */
function documentedFailures(string $name, string $base, array $commands, array $environment): array
{
    $arguments = match ($name) {
        'type-runtime' => ['--times', '0'],
        'type-orm-sqlite' => ['relative.sqlite'],
        default => [],
    };
    $key = match ($name) {
        'type-core' => 'APP_PORT',
        'type-cache', 'type-queue', 'type-redis' => 'REDIS_PORT',
        'type-orm-mysql', 'type-orm-pgsql' => 'DB_PORT',
        default => null,
    };
    if ($key !== null) {
        $environment[$key] = 'invalid-readme-value';
    }
    if (!in_array($name, ['type-runtime', 'type-core', 'type-cache', 'type-queue', 'type-redis',
        'type-orm-mysql', 'type-orm-pgsql', 'type-orm-sqlite', 'type-scheduler', 'type-testing', 'type-build'], true)) {
        return [];
    }
    if ($name === 'type-build') {
        $commands = ['build-cli' => [PHP_BINARY, $base . '/vendor/bin/type', 'build', $base . '/missing-readme-config.json']];
    }
    $checks = [];
    foreach ($commands as $mode => $command) {
        $result = (new Process([...$command, ...$arguments], $base, $environment))->wait(10);
        expect(!$result->successful() && !$result->timedOut && $result->signal === null, '文档非法输入没有通过公开入口明确拒绝：' . $name . '/' . $mode);
        $checks[] = ['mode' => $mode, 'exit_code' => $result->exitCode, 'rejected' => true];
    }
    return $checks;
}

if (($argv[1] ?? '') === '--failures') {
    $root = realpath(dirname(__DIR__));
    $base = realpath($argv[2] ?? '');
    expect(is_string($base) && str_starts_with($base, $root . '/build/documented-'), '只验证既有独立文档消费者');
    $record = json_decode(file_get_contents($base . '/verification.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($record['status'] === 'passed', '消费者必须先通过正向验证');
    $commands = ['php' => [PHP_BINARY, $base . '/dev.php']];
    if (isset($record['artifact_sha256'])) {
        $artifact = (new BuildPlatform())->output($base . '/build/type-app');
        expect(hash_file('sha256', $artifact) === $record['artifact_sha256'], '文档产物身份变化');
        $commands['native'] = [$artifact];
    }
    $environment = (new BuildPlatform())->environment(getenv('PHP_HOME') ?: '', getenv('PHPX_HOME') ?: '');
    $environment['PHPRC'] = getenv('PHPRC') ?: '';
    $environment['PHP_INI_SCAN_DIR'] = $base . '/php.d';
    $checks = documentedFailures($record['component'], $base, $commands, $environment);
    $file = $base . '/failure-verification-' . bin2hex(random_bytes(6)) . '.json';
    file_put_contents($file, json_encode(['component' => $record['component'], 'checks' => $checks,
        'positive_report_sha256' => hash_file('sha256', $base . '/verification.json')], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    echo ($checks === [] ? '固定示例无额外参数拒绝场景：' : '文档拒绝路径通过：') . $file . "\n";
    exit(0);
}

$root = realpath(dirname(__DIR__));
$name = $argv[1] ?? '';
$native = in_array('--native', $argv, true);
$packagist = in_array('--packagist', $argv, true);
$mapping = json_decode(file_get_contents($root . '/.github/distribution.json'), true, 512, JSON_THROW_ON_ERROR);
expect(isset($mapping['packages'][$name]), '需要明确指定分发映射中的组件');
$readme = file_get_contents($root . '/plugin/' . $name . '/README.md');
expect(preg_match('~```php\n(<\?php\n.*?)```~s', $readme, $match) === 1, '组件缺少完整声明式PHP示例');
$example = $match[1];
$required = [$name, 'type-build', ...($name === 'type-orm' ? ['type-orm-sqlite'] : [])];
$checked = [];
while ($required !== []) {
    $component = array_pop($required);
    if (isset($checked[$component])) {
        continue;
    }
    expect(isset($mapping['packages'][$component]), '第一方依赖没有分发映射：' . $component);
    $checked[$component] = true;
    $metadata = json_decode(file_get_contents($root . '/plugin/' . $component . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach (array_keys($metadata['require'] ?? []) as $dependency) {
        if (str_starts_with($dependency, 'zoujingli/type-')) {
            $required[] = substr($dependency, strlen('zoujingli/'));
        }
    }
}
$base = $root . '/build/documented-' . $name . '-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700) && mkdir($base . '/app', 0700) && mkdir($base . '/php.d', 0700), '无法创建独立文档消费者');
$developmentOnly = in_array($name, ['type-build', 'type-testing'], true);
$composer = ['name' => 'type-tests/documented-' . $name, 'type' => 'project', 'license' => 'Apache-2.0',
    'require' => ['php' => '>=8.4 <8.6'], 'require-dev' => ['zoujingli/type-build' => '~1.0.0@dev'],
    'autoload' => ['classmap' => ['app']], 'minimum-stability' => 'dev', 'prefer-stable' => true,
    'config' => ['allow-plugins' => false]];
$composer[$developmentOnly ? 'require-dev' : 'require']['zoujingli/' . $name] = '~1.0.0@dev';
if ($name === 'type-orm') {
    // 以真实声明式模型和作用域装配调用 README 服务；SQLite 仅用于本次消费者。
    $composer['require']['zoujingli/type-orm-sqlite'] = '~1.0.0@dev';
    expect(mkdir($base . '/app/model', 0700), '无法创建文档模型目录');
    expect(preg_match_all('~```php\n(<\?php\n.*?)```~s', $readme, $modelExamples) >= 2, 'ORM 文档缺少实际模型声明');
    file_put_contents($base . '/app/model/User.php', $modelExamples[1][1]);
    $example .= <<<'PHP'

/** 调用文档服务函数，通过真实SQLite验证条件查询及事务更新。 */
function main(): void
{
    \Type\Runtime\CoroutineRuntime::enableIo();
    \Type\Runtime\CoroutineRuntime::run(static function (): void {
        $database = new \Type\Orm\DatabaseManager(['default' => new \Type\Orm\Sqlite\SqliteDriver(':memory:')], 1, 0);
        \Type\Orm\Db::configure($database);
        $scope = new \Type\Runtime\ExecutionScope();
        try {
            $scope->run(static function (\Type\Runtime\ExecutionScope $current): void {
                $connection = \Type\Orm\Db::connection('default', true);
                $connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, age INTEGER NOT NULL)');
                $created = \app\model\User::create(['name' => '文档用户', 'age' => 21]);
                if (findAdultUsers('文档用户') !== [['id' => $created->id, 'name' => '文档用户', 'age' => 21]]
                    || renameUser($created->id, '已更名') !== 'updated'
                    || findAdultUsers('已更名') !== [['id' => $created->id, 'name' => '已更名', 'age' => 21]]
                    || findAdultUsers('缺失') !== []) {
                    throw new \RuntimeException('文档模型查询或事务语义不符');
                }
                echo "ORM文档查询与事务通过。\n";
            });
        } finally {
            try { $scope->close(); } finally { $database->close(); }
        }
    });
}
PHP;
}
// 本地模式从真实 Composer 清单求闭包；公开模式不声明 repositories，也不回退 VCS/path。
if (!$packagist) {
    foreach (array_keys($checked) as $package) {
        $composer['repositories'][] = ['type' => 'path', 'url' => '../../plugin/' . $package,
            'options' => ['symlink' => false, 'versions' => ['zoujingli/' . $package => '1.0.x-dev']]];
    }
}
file_put_contents($base . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
file_put_contents($base . '/app/main.php', $example);
copy($root . '/toolchain.lock.json', $base . '/toolchain.lock.json');
file_put_contents($base . '/type-app.json', json_encode(['name' => 'documented-' . $name, 'entry' => 'app/main.php', 'sources' => ['app'],
    'output' => 'build/type-app', 'build-directory' => 'build/compiler'], JSON_THROW_ON_ERROR) . "\n");
$environment = (new BuildPlatform())->environment(getenv('PHP_HOME') ?: '', getenv('PHPX_HOME') ?: '');
$environment['PHPRC'] = getenv('PHPRC') ?: '';
$environment['PHP_INI_SCAN_DIR'] = $base . '/php.d';
$environment['COMPOSER_HOME'] = $base . '/composer-home';
$environment['COMPOSER_CACHE_DIR'] = $root . '/.cache/composer';
$composerPhar = realpath(getenv('TYPE_COMPOSER_PHAR') ?: '');
expect(is_string($composerPhar) && is_file($composerPhar), '需要显式TYPE_COMPOSER_PHAR');
nativeDatabaseCommand(
    [PHP_BINARY, $composerPhar, '--working-dir=' . $base, 'install', '--no-scripts', '--no-plugins', '--no-interaction', '--prefer-dist', '--no-progress'],
    $environment,
    [],
    $base . '/install.log',
    180
);
$lock = json_decode(file_get_contents($base . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
$installed = [];
foreach (array_merge($lock['packages'], $lock['packages-dev']) as $package) {
    expect(!is_link($base . '/vendor/' . $package['name']), '独立安装不能依赖主仓软链接');
    if (str_starts_with($package['name'], 'zoujingli/type-')) {
        if ($packagist) {
            expect(($package['dist']['type'] ?? '') !== 'path' && ($package['source']['type'] ?? '') === 'git', '公开安装没有取得真实 Git 分发来源');
        }
        $installed[$package['name']] = ['version' => $package['version'], 'source' => $package['source'] ?? null, 'dist' => $package['dist'] ?? null];
    }
}
$production = array_column($lock['packages'], 'name');
expect(!in_array('zoujingli/type-build', $production, true) && !in_array('zoujingli/type-testing', $production, true), '开发工具进入了业务生产依赖');
$database = null;
$redis = null;
$record = ['status' => 'running', 'component' => $name, 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'),
    'readme_sha256' => hash('sha256', $readme), 'example_sha256' => hash('sha256', $match[1]),
    'first_party_closure' => array_keys($checked), 'installed_components' => $installed,
    'composer_lock_sha256' => hash_file('sha256', $base . '/composer.lock'), 'production_packages' => $production,
    'development_only' => $developmentOnly, 'installation' => $packagist ? 'packagist' : 'local-path',
    'remote_distribution' => $packagist];
try {
    if (in_array($name, ['type-orm-mysql', 'type-orm-pgsql'], true)) {
        $driver = substr($name, strlen('type-orm-'));
        $tools = NativeDatabase::tools($driver, getenv($driver === 'mysql' ? 'TYPE_MYSQL_TOOLS' : 'TYPE_PGSQL_TOOLS') ?: '');
        $database = new NativeDatabase($base . '/database', $driver, $tools);
        $databaseEnvironment = $database->environment();
        foreach (['HOST' => 'HOST', 'PORT' => 'PORT', 'DATABASE' => 'DATABASE', 'USER' => 'USERNAME', 'PASSWORD' => 'PASSWORD'] as $from => $to) {
            $environment['DB_' . $to] = $databaseEnvironment['TYPE_' . strtoupper($driver) . '_' . $from];
        }
    }
    if (in_array($name, ['type-cache', 'type-queue', 'type-redis'], true)) {
        $redis = new NativeRolloutRedis($base . '/redis', getenv('TYPE_REDIS_SERVER') ?: '');
        $redisEnvironment = $redis->environment();
        $environment['REDIS_HOST'] = $redisEnvironment['TYPE_REDIS_HOST'];
        $environment['REDIS_PORT'] = $redisEnvironment['TYPE_REDIS_PORT'];
    }
    $arguments = match ($name) {
        'type-runtime' => ['--name', '文档示例', '--times', '2'],
        'type-scheduler' => [$base . '/schedule.json'],
        'type-testing' => [realpath(getenv('TYPE_DOCUMENTED_TEST_BINARY') ?: '') ?: ''],
        default => [],
    };
    expect($name !== 'type-testing' || $arguments[0] !== '', '测试组件示例需要真实TYPE_DOCUMENTED_TEST_BINARY');
    $entryCall = str_contains($example, 'function main(int $argc') ? 'main($argc, $argv);' : 'main();';
    $preparation = $name === 'type-orm' ? <<<'PHP'
$generation = (new \Type\Build\DevelopmentBuilder())->prepareConfiguration(__DIR__ . '/type-app.json');
foreach ($generation['files'] as $file) {
    require $generation['directory'] . '/' . $file;
}

PHP : '';
    file_put_contents($base . '/dev.php', "<?php\nrequire __DIR__ . '/vendor/autoload.php';\nrequire __DIR__ . '/vendor/swoole/typephp/src/polyfills.php';\n"
        . $preparation . "require __DIR__ . '/app/main.php';\n" . $entryCall . "\n");
    $commands = ['php' => [PHP_BINARY, $base . '/dev.php']];
    if ($native && $name !== 'type-testing') {
        echo $name . "：编译独立文档示例。\n";
        nativeDatabaseCommand([PHP_BINARY, $base . '/vendor/bin/type', 'build', $base . '/type-app.json'], $environment, [], $base . '/build.log', 1200);
        $artifact = (new BuildPlatform())->output($base . '/build/type-app');
        $manifest = (new ArtifactManifest())->read($artifact);
        $actual = array_keys($manifest['production-packages']);
        sort($actual);
        $expected = $production;
        sort($expected);
        expect($actual === $expected, 'README消费者遗漏已安装生产依赖');
        $commands['native'] = [$artifact];
        $record['artifact_sha256'] = hash_file('sha256', $artifact);
        $record['build_id'] = $manifest['build-id'];
        $record['build_report_sha256'] = hash_file('sha256', $artifact . '.build.json');
        $project = json_decode(file_get_contents($base . '/build/compiler/project.yml'), true, 512, JSON_THROW_ON_ERROR);
        $record['compiled_inputs'] = count($project['sources']);
    }
    foreach ($commands as $mode => $command) {
        $result = (new Process([...$command, ...$arguments], $base, $environment))->wait(30);
        $secrets = array_filter([$environment['DB_PASSWORD'] ?? '']);
        expect($result->successful() && $result->stderr === '', '文档示例执行失败：' . str_replace($secrets, '<REDACTED>', $result->stdout . $result->stderr));
        $output = $result->stdout;
        if (in_array($name, ['type-orm-mysql', 'type-orm-pgsql', 'type-orm-sqlite'], true)) {
            expect((int) json_decode($output, true, 512, JSON_THROW_ON_ERROR)[0]['value'] === 7, '文档参数绑定查询结果不符');
        } elseif ($name === 'type-cache') {
            expect(json_decode($output, true, 512, JSON_THROW_ON_ERROR) === ['id' => 7, 'name' => '示例'], '文档缓存值不符');
        } elseif ($name === 'type-queue') {
            expect(preg_match('/^readme-[a-f0-9]{16}\n$/D', $output) === 1, '文档任务没有实际执行');
        } elseif ($name === 'type-redis') {
            expect(trim($output) === 'type-redis-readme', '文档Redis回显探测失败');
        } elseif ($name === 'type-runtime') {
            expect($output === "文档示例\n文档示例\n", '文档参数或作用域行为不符');
        } elseif ($name === 'type-core') {
            expect($output === "type-example:9501\n", '文档配置快照不符');
        } elseif ($name === 'type-validate') {
            expect(json_decode($output, true, 512, JSON_THROW_ON_ERROR) === ['name' => '示例用户', 'age' => 20, 'email' => null, 'page' => 1], '文档校验结果不符');
        } elseif ($name === 'type-build') {
            expect($output === "Type 应用已启动。\n", '文档构建产物输出不符');
        } elseif ($name === 'type-orm') {
            expect($output === "ORM文档查询与事务通过。\n", '文档服务函数没有执行');
        } elseif ($name === 'type-log') {
            expect(str_contains($output, '数据同步') && str_contains($output, '访问被拒绝'), '文档日志示例没有输出两个通道');
        } elseif ($name === 'type-mqtt') {
            expect(
                json_decode($output, true, 512, JSON_THROW_ON_ERROR) === ['protocol' => 5, 'client_id' => 'guide-client',
                'topic' => 'example/up', 'qos' => 0, 'packet_bytes' => 33, 'publish_header' => 48, 'invalid_topic_rejected' => true],
                '文档 MQTT 离线报文构建、解码或非法主题拒绝不符'
            );
            $record['mqtt_scope'] = 'offline-public-contract';
            $record['network_protocol_verified'] = false;
        } else {
            expect(is_array(json_decode($output, true, 512, JSON_THROW_ON_ERROR)), '文档调度或测试结果无效');
        }
        $record['executed'][] = $mode;
    }
    $record['failure_checks'] = documentedFailures($name, $base, $commands, $environment);
    $record['status'] = 'passed';
} finally {
    $cleanupFailures = [];
    foreach ([$database, $redis] as $resource) {
        try {
            $resource?->close();
        } catch (Throwable $failure) {
            $cleanupFailures[] = $failure->getMessage();
        }
    }
    $record['resources_closed'] = $cleanupFailures === [];
    if ($record['status'] !== 'passed' || $cleanupFailures !== []) {
        $record['status'] = 'failed';
    }
    file_put_contents($base . '/verification.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    expect($cleanupFailures === [], '文档消费者资源未正常关闭：' . implode('；', $cleanupFailures));
}
echo '组件README独立消费通过：' . $base . "/verification.json\n";
