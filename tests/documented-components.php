<?php

declare(strict_types=1);

require __DIR__ . '/build-scenario.php';
require __DIR__ . '/native-database.php';
require __DIR__ . '/native-rollout-redis.php';

use Type\Build\ArtifactManifest;
use Type\Build\BuildPlatform;
use Type\Testing\Process;

/** 通过相同公开入口验证文档声明的参数/配置拒绝，不连接外部业务资源。 */
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
$mapping = json_decode(file_get_contents($root . '/.github/distribution.json'), true, 512, JSON_THROW_ON_ERROR);
expect(isset($mapping['packages'][$name]), '需要明确指定分发映射中的组件');
$readme = file_get_contents($root . '/plugin/' . $name . '/README.md');
expect(preg_match('~```php\n(<\?php\n.*?)```~s', $readme, $match) === 1, '组件缺少完整声明式PHP示例');
$example = $match[1];
preg_match_all('~^composer config repositories\.([a-z0-9-]+) vcs https://github\.com/zoujingli/([a-z0-9-]+)\.git$~m', $readme, $repositoryMatches);
expect($repositoryMatches[1] === $repositoryMatches[2], '文档仓库名称与真实组件不一致');
$documentedRepositories = $repositoryMatches[1];
$required = [$name];
$checked = [];
while ($required !== []) {
    $component = array_pop($required);
    if (isset($checked[$component])) {
        continue;
    }
    expect(in_array($component, $documentedRepositories, true), '安装说明遗漏依赖仓库：' . $component);
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
    'autoload' => ['classmap' => ['app']], 'repositories' => [], 'minimum-stability' => 'dev', 'prefer-stable' => true,
    'config' => ['allow-plugins' => false]];
$composer[$developmentOnly ? 'require-dev' : 'require']['zoujingli/' . $name] = '~1.0.0@dev';
if ($name === 'type-orm') {
    // README的服务函数接收已有连接，测试调用者显式安装SQLite驱动并持有连接。
    $composer['require']['zoujingli/type-orm-sqlite'] = '~1.0.0@dev';
    $example .= <<<'PHP'

/** 调用文档服务函数，通过真实SQLite验证条件查询及事务更新。 */
function main(): void
{
    $database = new \Type\Orm\Database(new \Type\Orm\Sqlite\SqliteDriver(':memory:'), 1, 0);
    $scope = new \Type\Runtime\ExecutionScope();
    try {
        $connection = $database->connect($scope);
        $connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, age INTEGER)');
        $connection->execute('INSERT INTO users VALUES (?, ?, ?)', [1, '文档用户', 21]);
        if (count(findAdultUsers($connection, '文档用户')) !== 1 || renameUser($connection, 1, '已更名') !== 1
            || count(findAdultUsers($connection, '已更名')) !== 1 || findAdultUsers($connection, '缺失') !== []) {
            throw new \RuntimeException('文档查询或事务语义不符');
        }
        echo "ORM文档查询与事务通过。\n";
    } finally {
        try { $scope->close(); } finally { $database->close(); }
    }
}
PHP;
}
// 构建工具是测试控制端的额外开发依赖；业务仓库闭包已独立与README逐项核对。
$repositories = array_unique([...$documentedRepositories, 'type-build', 'type-runtime', ...($name === 'type-orm' ? ['type-orm-sqlite'] : [])]);
foreach ($repositories as $package) {
    $composer['repositories'][] = ['type' => 'path', 'url' => '../../plugin/' . $package,
        'options' => ['symlink' => false, 'versions' => ['zoujingli/' . $package => '1.0.x-dev']]];
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
foreach (array_merge($lock['packages'], $lock['packages-dev']) as $package) {
    expect(!is_link($base . '/vendor/' . $package['name']), '独立安装不能依赖主仓软链接');
}
$production = array_column($lock['packages'], 'name');
expect(!in_array('zoujingli/type-build', $production, true) && !in_array('zoujingli/type-testing', $production, true), '开发工具进入了业务生产依赖');
$database = null;
$redis = null;
$record = ['status' => 'running', 'component' => $name, 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'),
    'readme_sha256' => hash('sha256', $readme), 'example_sha256' => hash('sha256', $match[1]),
    'documented_repositories' => $documentedRepositories,
    'composer_lock_sha256' => hash_file('sha256', $base . '/composer.lock'), 'production_packages' => $production,
    'development_only' => $developmentOnly, 'remote_distribution' => false];
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
    file_put_contents($base . '/dev.php', "<?php\nrequire __DIR__ . '/vendor/autoload.php';\nrequire __DIR__ . '/vendor/swoole/typephp/src/polyfills.php';\nrequire __DIR__ . '/app/main.php';\n" . $entryCall . "\n");
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
