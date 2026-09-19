<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\BuildEnvironment;
use Type\Build\BuildPlatform;

$root = BuildPlatform::resolve(dirname(__DIR__));
$work = BuildPlatform::path($argv[1] ?? '');
$artifactRelative = (new BuildPlatform())->output('build/native/type-app');
$options = array_slice($argv, 2);
expect(array_diff($options, ['--verify', '--initialization-failure', '--resources', '--files']) === [] && count(array_unique($options)) === count($options), '只接受 --verify、--resources、--files 与 --initialization-failure，且参数不能重复');
$verifyOnly = in_array('--verify', $options, true);
$initializationFailure = in_array('--initialization-failure', $options, true);
$resources = in_array('--resources', $options, true);
$files = in_array('--files', $options, true);
expect(!$files || (!$resources && !$initializationFailure), '文件消费者不与其他场景混用');
expect(!$resources || !$initializationFailure, '资源消费者不与声明初始化故障混用');
if ($verifyOnly) {
    expect(BuildPlatform::contains($root . '/build', $work) && !str_contains($work, '..') && is_file($work . '/' . $artifactRelative . '.build.json'), '需要本场景的完整消费者产物');
    if ($files) {
        verifyCompiledFileThreads($work);
    } else {
        $resources ? verifyCompiledResourceThreads($work) : verifyCompiledThreads($work, $initializationFailure);
    }
    exit(0);
}
expect(BuildPlatform::contains($root . '/build', $work) && !str_contains($work, '..') && !file_exists($work), '需要 build 下尚不存在的独立消费目录');
expect(mkdir($work . '/app', 0700, true), '无法创建独立消费者');
$settings = [
    'name' => 'compiled-threads', 'entry' => 'app/main.php',
    'output' => $artifactRelative, 'build-directory' => 'build/native/compiler',
    'threads' => ['probe' => $files ? 'FileProbe::run' : ($resources ? 'PoolProbe::run' : 'ThreadProbe::run')],
    'runtime' => [PHP_OS_FAMILY => ['extensions' => ['swoole'], 'functions' => ['Swoole\\Coroutine\\run']]],
    'compiler' => ['debug' => true, 'jobs' => 2],
];
$composer = [
    'name' => 'type-tests/compiled-threads', 'type' => 'project', 'license' => 'Apache-2.0',
    'require' => ['zoujingli/type-runtime' => '~1.0.0@dev'],
    'require-dev' => ['zoujingli/type-build' => '~1.0.0@dev', 'swoole/typephp' => '0.9.0', 'swoole/phpx' => '2.9.0'],
    'repositories' => [
        ['type' => 'path', 'url' => $root . '/plugin/type-runtime', 'options' => ['symlink' => false, 'versions' => ['zoujingli/type-runtime' => '1.0.x-dev']]],
        ['type' => 'path', 'url' => $root . '/plugin/type-build', 'options' => ['symlink' => false, 'versions' => ['zoujingli/type-build' => '1.0.x-dev']]],
    ],
    'autoload' => ['classmap' => ['app']],
    'minimum-stability' => 'dev', 'prefer-stable' => true,
    'config' => ['allow-plugins' => false],
];
if ($resources) {
    foreach (['type-orm', 'type-orm-sqlite'] as $package) {
        $composer['require']['zoujingli/' . $package] = '~1.0.0@dev';
        $composer['repositories'][] = ['type' => 'path', 'url' => $root . '/plugin/' . $package,
            'options' => ['symlink' => false, 'versions' => ['zoujingli/' . $package => '1.0.x-dev']]];
    }
}
if ($files) {
    $settings['threads']['supervised'] = 'FileProbe::supervised';
    $composer['require']['zoujingli/type-core'] = '~1.0.0@dev';
    $composer['repositories'][] = ['type' => 'path', 'url' => $root . '/plugin/type-core',
        'options' => ['symlink' => false, 'versions' => ['zoujingli/type-core' => '1.0.x-dev']]];
}
foreach (['composer.json' => $composer, 'type-app.json' => $settings] as $file => $value) {
    file_put_contents($work . '/' . $file, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
expect(copy($root . '/tests/fixtures/' . ($files ? 'compiled-files.php' : ($resources ? 'compiled-resources.php' : 'compiled-threads.php')), $work . '/app/main.php'), '无法复制线程消费者');
if ($initializationFailure) {
    $input = (string) file_get_contents($work . '/app/main.php');
    $input = str_replace("const THREAD_TEXT = 'thread-string';", "const THREAD_TEXT = 'thread-string';\nuse varint_types;\nconst THREAD_INITIALIZATION_FAILURE = 1 / 0;", $input, $replacements);
    expect($replacements === 1 && file_put_contents($work . '/app/main.php', $input) === strlen($input), '无法建立部分初始化失败的真实声明');
}
expect(copy($root . '/toolchain.lock.json', $work . '/toolchain.lock.json'), '无法复制工具链约束');
$runner = new BuildEnvironment();
$environment = $runner->environment((string) getenv('PHP_HOME'), (string) getenv('PHPX_HOME'));
$composerBinary = getenv('COMPOSER_BINARY');
expect(PHP_OS_FAMILY !== 'Windows' || (is_string($composerBinary) && is_file($composerBinary)), 'Windows 需要显式 COMPOSER_BINARY 指向 Composer PHP 脚本');
$composerBinary = $composerBinary ?: trim(successful(['which', 'composer']));
$composerEnvironment = $environment + ['COMPOSER_HOME' => $work . '/composer-home'];
file_put_contents($work . '/install.log', $runner->run([PHP_BINARY, $composerBinary, 'install', '--no-interaction', '--no-scripts', '--no-plugins', '--no-progress'], $work, $composerEnvironment, 300));
expect(!is_link($work . '/vendor/zoujingli/type-runtime') && !is_link($work . '/vendor/zoujingli/type-build'), '消费者不能依赖主仓组件软链接');
echo '已安装完整独立消费者：' . $work . "\n";
file_put_contents($work . '/build.log', $runner->run([PHP_BINARY, $work . '/vendor/bin/type', $work . '/type-app.json'], $work, $environment, 900));
echo '线程消费者完整 AOT 编译成功' . "\n";
if ($files) {
    verifyCompiledFileThreads($work);
} else {
    $resources ? verifyCompiledResourceThreads($work) : verifyCompiledThreads($work, $initializationFailure);
}

/** 同一完整产物验证真实文件 I/O、跨线程容量、迟到完成和进程终止。 */
function verifyCompiledFileThreads(string $work): void
{
    $runner = new BuildEnvironment();
    $environment = $runner->environment((string) getenv('PHP_HOME'), (string) getenv('PHPX_HOME'));
    $artifact = (new BuildPlatform())->output($work . '/build/native/type-app');
    $report = json_decode((string) file_get_contents($artifact . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
    expect(hash_file('sha256', $artifact) === $report['sha256'], '消费者产物摘要不一致');
    $packages = array_keys($report['production-packages']);
    sort($packages);
    expect($packages === ['psr/http-factory', 'psr/http-message', 'psr/http-server-handler', 'psr/http-server-middleware',
        'zoujingli/type-core', 'zoujingli/type-runtime'], '必须全量编译文件消费者的六个生产包');
    foreach ($report['sources'] as $source) {
        expect(BuildPlatform::contains($work, $source), '消费者仍引用主仓生产源码');
    }
    expect(copy($work . '/build/native/compiler/runtime-profile/native.ini', $work . '/run.ini'), '无法保留运行配置');
    expect(is_dir($work . '/php.d') || mkdir($work . '/php.d', 0700), '无法建立空扫描目录');
    $environment['PHPRC'] = $work . '/run.ini';
    $environment['PHP_INI_SCAN_DIR'] = $work . '/php.d';
    $moved = [];
    $results = [];
    try {
        foreach (['app', 'vendor', 'build/native/compiler'] as $relative) {
            $source = $work . '/' . $relative;
            $backup = $source . '.source-backup';
            expect(!file_exists($backup) && rename($source, $backup), '无法移走消费源码');
            $moved[$source] = $backup;
        }
        foreach (['normal', 'normal', 'normal', 'buffers', 'buffer-capacity', 'capacity', 'watchdog', 'stop',
            'supervised-watchdog', 'supervised-stop', 'supervised-watchdog', 'supervised-stop', 'supervised-watchdog', 'supervised-stop'] as $mode) {
            $directory = $work . '/files-' . $mode . '-' . bin2hex(random_bytes(5));
            expect(mkdir($directory, 0700), '无法创建文件观察目录');
            $execution = runThreadArtifact([$artifact, $directory, $mode], $work, $directory, $environment);
            if ($mode === 'supervised-watchdog' || $mode === 'supervised-stop') {
                expect(str_contains($execution->stdout, 'heartbeat-with-pending:blocked')
                    && str_contains($execution->stdout, 'heartbeat-with-pending:healthy'), '必须观察到两个线程心跳与真实文件等待重叠');
                expect(!$execution->timedOut && !$execution->outputExceeded && $execution->signal === null
                    && $execution->stderr === '' && $execution->exitCode === ($mode === 'supervised-watchdog' ? 75 : 0), '生产主控未正确监督真实文件操作');
                $supervised = ['mode' => $mode, 'exit' => $execution->exitCode];
                if ($mode === 'supervised-stop') {
                    $lines = explode("\n", trim($execution->stdout));
                    $final = json_decode($lines[count($lines) - 1], true, flags: JSON_THROW_ON_ERROR);
                    expect($final['exits'] === [0, 0] && $final['statistics']['owned'] === 0
                        && $final['statistics']['joined'] === 2 && $final['source_free'], '停止后的真实 join 或源码禁读未通过');
                    $supervised['final'] = $final;
                }
                $results[] = $supervised;
                continue;
            }
            if ($mode === 'watchdog' || $mode === 'stop') {
                expect($execution->exitCode === ($mode === 'watchdog' ? 75 : 200) && !$execution->timedOut
                    && !$execution->outputExceeded && $execution->signal === null, '不合作文件操作必须由独立监督或线程宿主终止进程');
                $results[] = ['mode' => $mode, 'exit' => $execution->exitCode];
                continue;
            }
            expect($execution->successful() && $execution->stderr === '', '文件线程运行失败，见 ' . $directory . '/execution.json');
            $result = json_decode(trim($execution->stdout), true, 512, JSON_THROW_ON_ERROR);
            expect($result['exits'] === [0, 0] && $result['active_threads'] === 1 && $result['source_free'], '文件业务线程未完整退出');
            expect($result['stats']['aio_process_pending'] === 0 && $result['stats']['aio_process_bytes'] === 0
                && $result['stats']['aio_process_peak_pending'] <= $result['stats']['aio_max_pending']
                && $result['stats']['aio_process_peak_bytes'] <= $result['stats']['aio_max_bytes'], '共享容量没有守恒');
            $threads = [];
            foreach (['left', 'right'] as $role) {
                $thread = json_decode((string) file_get_contents($directory . '/' . $role . '/result.json'), true, 512, JSON_THROW_ON_ERROR);
                expect($thread['process'] === $result['process'] && $thread['native_id'] !== $result['main_thread'], '文件必须在真实业务线程执行');
                if ($mode === 'normal') {
                    expect($thread['ticks'] >= 11 && $thread['checks'] >= 275, '文件、锁或迟到收尾验证不完整');
                } elseif ($mode === 'buffers') {
                    expect($thread['checks'] >= 14 && count($thread['buffers']) === 3, '整文件缓冲与拒绝恢复验证不完整');
                }
                $threads[] = $thread;
            }
            expect($threads[0]['native_id'] !== $threads[1]['native_id'], '两个业务线程身份相同');
            $results[] = ['mode' => $mode, 'result' => $result, 'threads' => $threads];
        }
    } finally {
        foreach (array_reverse($moved, true) as $source => $backup) {
            expect(rename($backup, $source), '无法恢复消费者构建输入');
        }
    }
    file_put_contents($work . '/file-evidence.json', json_encode(['build-id' => $report['build-id'], 'sha256' => $report['sha256'],
        'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'runs' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    echo "无源码文件线程验收通过：大文件、metadata、锁、上传、取消、共享容量和独立监督。\n";
}

/** 同一独立产物执行三轮双线程 SQLite、排队与清理；不改原线程生命周期场景。 */
function verifyCompiledResourceThreads(string $work): void
{
    $runner = new BuildEnvironment();
    $environment = $runner->environment((string) getenv('PHP_HOME'), (string) getenv('PHPX_HOME'));
    $artifact = (new BuildPlatform())->output($work . '/build/native/type-app');
    $report = json_decode((string) file_get_contents($artifact . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
    expect(hash_file('sha256', $artifact) === $report['sha256'], '消费者产物摘要不一致');
    $packages = array_keys($report['production-packages']);
    sort($packages);
    expect($packages === ['zoujingli/type-orm', 'zoujingli/type-orm-sqlite', 'zoujingli/type-runtime'], '必须完整编译三个生产组件');
    foreach ($report['sources'] as $source) {
        expect(BuildPlatform::contains($work, $source), '消费者仍引用主仓生产源码');
    }
    expect(copy($work . '/build/native/compiler/runtime-profile/native.ini', $work . '/run.ini'), '无法保留运行配置');
    expect(is_dir($work . '/php.d') || mkdir($work . '/php.d', 0700), '无法建立空扫描目录');
    expect((glob($work . '/php.d/*') ?: []) === [], '扫描目录必须为空');
    $environment['PHPRC'] = $work . '/run.ini';
    $environment['PHP_INI_SCAN_DIR'] = $work . '/php.d';
    $moved = [];
    $results = [];
    try {
        foreach (['app', 'vendor', 'build/native/compiler'] as $relative) {
            $source = $work . '/' . $relative;
            $backup = $source . '.source-backup';
            expect(!file_exists($backup) && rename($source, $backup), '无法移走消费源码');
            $moved[$source] = $backup;
        }
        for ($run = 0; $run < 3; ++$run) {
            $directory = $work . '/resources-' . bin2hex(random_bytes(5));
            expect(mkdir($directory, 0700), '无法创建资源观察目录');
            $execution = runThreadArtifact([$artifact, $directory], $work, $directory, $environment);
            expect($execution->successful() && $execution->stderr === '', '资源线程运行失败，见 ' . $directory . '/execution.json');
            $result = json_decode(trim($execution->stdout), true, 512, JSON_THROW_ON_ERROR);
            expect($result['exits'] === [0, 0] && $result['active_threads'] === 1 && $result['source_free'] === true, '业务线程必须全部 join 且无源码');
            $threads = [];
            foreach (['left', 'right'] as $role) {
                $thread = json_decode((string) file_get_contents($directory . '/' . $role . '.json'), true, 512, JSON_THROW_ON_ERROR);
                expect($thread['checks'] >= 40 && $thread['remaining_coroutines'] === 0 && $thread['allocated'] === 0, '资源行为或收尾不完整');
                expect($thread['native_id'] !== $result['main_thread'] && $thread['process'] === $result['process'], '必须是同一进程内的真实业务线程');
                $threads[] = $thread;
            }
            expect($threads[0]['native_id'] !== $threads[1]['native_id'], '两个线程身份必须不同');
            $results[] = $result + ['threads' => $threads];
        }
    } finally {
        foreach (array_reverse($moved, true) as $source => $backup) {
            expect(rename($backup, $source), '无法恢复消费者构建输入');
        }
    }
    file_put_contents($work . '/resource-evidence.json', json_encode(['build-id' => $report['build-id'], 'sha256' => $report['sha256'],
        'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'source-free' => true, 'runs' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    echo "无源码资源线程验收通过：双线程、真实 SQLite、预算、协程等待与清理，重复三轮。\n";
}

/** 从同一完整产物验证线程重建；原始报告与运行结果留给本任务统一保全回收。 */
function verifyCompiledThreads(string $work, bool $initializationFailure = false): void
{
    $runner = new BuildEnvironment();
    $environment = $runner->environment((string) getenv('PHP_HOME'), (string) getenv('PHPX_HOME'));
    $artifact = (new BuildPlatform())->output($work . '/build/native/type-app');
    $report = json_decode((string) file_get_contents($artifact . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
    expect(hash_file('sha256', $artifact) === $report['sha256'], '消费者产物摘要不一致');
    expect(array_keys($report['production-packages']) === ['zoujingli/type-runtime'], '消费者完整生产依赖不符');
    expect(in_array('swoole\\coroutine\\run', $report['runtime-profile']['functions'], true), '官方库公开入口必须纳入运行身份');
    foreach ($report['sources'] as $source) {
        expect(BuildPlatform::contains($work, $source), '消费者仍引用主仓生产源码');
    }
    $compiler = $work . '/build/native/compiler';
    expect(copy($compiler . '/runtime-profile/native.ini', $work . '/run.ini'), '无法保留本机运行配置');
    expect(is_dir($work . '/php.d') || mkdir($work . '/php.d', 0700), '无法创建空运行扫描目录');
    expect((glob($work . '/php.d/*') ?: []) === [], '运行扫描目录必须为空');
    $environment['PHPRC'] = $work . '/run.ini';
    $environment['PHP_INI_SCAN_DIR'] = $work . '/php.d';
    $moved = [];
    $results = [];
    try {
        foreach (['app', 'vendor', 'build/native/compiler'] as $relative) {
            $source = $work . '/' . $relative;
            $backup = $source . '.source-backup';
            expect(!file_exists($backup) && rename($source, $backup), '无法移走消费源码：' . $relative);
            $moved[$source] = $backup;
        }
        if ($initializationFailure) {
            $directory = $work . '/initialization-failure-' . bin2hex(random_bytes(5));
            expect(mkdir($directory, 0700), '无法创建部分初始化观察目录');
            $execution = runThreadArtifact([$artifact, $directory], $work, $directory, $environment);
            expect(
                $execution->exitCode === 1 && !$execution->timedOut && !$execution->outputExceeded && $execution->signal === null
                && str_contains($execution->stdout . "\n" . $execution->stderr, 'Fatal error: Division by zero') && !is_file($directory . '/main-count'),
                '声明初始化失败必须在进入业务之前准确退出'
            );
            file_put_contents($work . '/thread-evidence.json', json_encode(['build-id' => $report['build-id'], 'sha256' => $report['sha256'],
                'source-free' => true, 'initialization-failure' => true, 'kind' => 'declaration-bailout', 'main-started' => false, 'exit' => $execution->exitCode], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
            echo "无源码部分初始化失败验证通过\n";
            return;
        }
        $scopeDirectory = $work . '/scope-' . bin2hex(random_bytes(5));
        expect(mkdir($scopeDirectory, 0700), '无法创建非协程权限观察目录');
        $scopeExecution = runThreadArtifact([$artifact, $scopeDirectory, 'scope-only'], $work, $scopeDirectory, $environment);
        expect($scopeExecution->successful() && $scopeExecution->stderr === '', '非协程权限检查失败');
        expect(json_decode(trim($scopeExecution->stdout), true, 512, JSON_THROW_ON_ERROR) === ['scope' => true, 'source-free' => true], '非协程权限观察不符');
        for ($run = 0; $run < 3; ++$run) {
            $directory = $work . '/run-' . bin2hex(random_bytes(5));
            expect(mkdir($directory, 0700), '无法创建专用线程观察目录');
            $execution = runThreadArtifact([$artifact, $directory], $work, $directory, $environment, true);
            expect($execution->successful(), '线程运行失败，见 ' . $directory . '/execution.json');
            $stdioRoles = ['a', 'again-0', 'again-1', 'again-2', 'again-3', 'again-4', 'again-5', 'again-6', 'again-7', 'exit', 'b', 'main'];
            $stdioOutput = implode('', array_map(static fn (string $role): string => 'stdio:' . $role . "\n", $stdioRoles));
            expect(str_starts_with($execution->stdout, $stdioOutput) && $execution->stderr === $stdioOutput, '线程重建后标准流输出丢失、重复或产生诊断');
            $stdout = substr($execution->stdout, strlen($stdioOutput));
            $result = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
            expect($result['main-count'] === 1 && $result['statuses'] === [7, 0, 0, 0, 0, 0, 0, 0, 0]
                && $result['exit'] === 9 && $result['b'] === 0, '线程返回值与清理状态不符');
            $a = json_decode((string) file_get_contents($directory . '/a.json'), true, 512, JSON_THROW_ON_ERROR);
            $b = json_decode((string) file_get_contents($directory . '/b.json'), true, 512, JSON_THROW_ON_ERROR);
            expect($a['native-id'] !== $b['native-id'] && $a['native-id'] !== $result['main-native-id']
                && $b['native-id'] !== $result['main-native-id'], '两个业务线程必须具有不同原生线程身份');
            $identities = [];
            $generations = ['b' => 1, 'a' => 2, 'again-0' => 3, 'again-1' => 4, 'again-2' => 5, 'again-3' => 6,
                'again-4' => 7, 'again-5' => 8, 'again-6' => 9, 'again-7' => 10, 'exit' => 11];
            foreach ($generations as $role => $generation) {
                expect(file_get_contents($directory . '/' . $role . '.cleanup') === 'cleaned', '线程请求析构未完成：' . $role);
                $strings = json_decode((string) file_get_contents($directory . '/' . $role . '.ready'), true, 512, JSON_THROW_ON_ERROR);
                expect($strings['constant-text'] === 'thread-string' && $strings['literal-text'] === 'shared-thread-literal', '共享字符串被原地改写：' . $role);
                expect($strings['official-library'] === '@swoole/library/core/Coroutine/functions.php'
                    && $strings['compiled-entry'] === true, '官方库与编译业务入口的来源不符：' . $role);
                $last = json_decode((string) file_get_contents($directory . '/' . $role . '.json'), true, 512, JSON_THROW_ON_ERROR);
                expect($last === $strings && $last['role'] === $role && $last['generation'] === $generation
                    && $last['process-id'] === $result['process-id'] && $last['native-id'] > 0
                    && $last['native-id'] !== $result['main-native-id'], '启动代次或首尾线程身份不符：' . $role);
                $status = $role === 'exit' ? $result['exit'] : ($role === 'b' ? $result['b'] : $result['statuses'][$generation - 2]);
                $identities[] = ['generation' => $generation, 'role' => $role, 'process-id' => $last['process-id'],
                    'native-id' => $last['native-id'], 'thread-exit' => $status];
            }
            $coroutines = [];
            foreach (['a', 'b'] as $role) {
                expect(file_get_contents($directory . '/' . $role . '.shutdown') === "shutdown\n", '线程关闭回调没有恰好执行一次');
                $observation = json_decode((string) file_get_contents($directory . '/' . $role . '.coroutines.json'), true, 512, JSON_THROW_ON_ERROR);
                expect($observation['remaining-coroutines'] === 0 && $observation['source-free'] === true
                    && $observation['ids']['left'] !== $observation['ids']['right']
                    && $observation['generation'] === $generations[$role] && $observation['process-id'] === $result['process-id']
                    && $observation['native-id'] === ($role === 'a' ? $a['native-id'] : $b['native-id']), '协程身份或调度收尾不符');
                expect(
                    $observation['native-gc']['automatic'] === true && $observation['native-gc']['garbage-finalized'] > 0
                    && $observation['native-gc']['left-finalized'] === 1 && $observation['native-gc']['right-finalized'] === 1
                    && $observation['native-gc']['exception-finalized'] === 1,
                    '挂起根的原生 GC 观察不符'
                );
                expect(count($observation['events']) === 12 && $observation['results']['left']['nested-unpacked-callback'] === 'left:7'
                    && $observation['results']['right']['nested-unpacked-callback'] === 'right:9', '协程交错或私有回调不完整');
                $coroutines[$role] = $observation;
            }
            $results[] = $result + ['a' => $a, 'b-result' => $b, 'generations' => $identities, 'coroutines' => $coroutines,
                'stdio-roles' => $stdioRoles, 'resources' => verifyThreadResources($directory)];
        }
        $failures = [];
        foreach (['throw', 'fatal', 'exit', 'shutdown', 'destructor', 'native-finalizer', 'shutdown-bailout'] as $failure) {
            $mode = 'failure-' . $failure;
            $directory = $work . '/' . $mode . '-' . bin2hex(random_bytes(5));
            expect(mkdir($directory, 0700), '无法创建线程故障观察目录');
            $execution = runThreadArtifact([$artifact, $directory, $mode], $work, $directory, $environment);
            expect($execution->successful(), '故障未隔离在线程请求：' . $mode . '，见 execution.json');
            $output = parseThreadFailureOutput($execution->stdout);
            $observation = $output['control'];
            expect(
                count($observation) === 4 && isset($observation['mode'], $observation['thread-exit'], $observation['active-threads'], $observation['source-free'])
                && $observation['mode'] === $mode && $observation['active-threads'] === 1 && $observation['source-free'] === true,
                '故障线程未 join 或加载了源码'
            );
            expect($observation['thread-exit'] === ($failure === 'exit' ? 17 : ($failure === 'shutdown-bailout' ? 95 : 255)), '故障线程退出码丢失：' . $mode);
            // PHP 8.5 的 E_USER_ERROR 明确将 Zend 对象标为已析构；不可把用户析构当作可靠清理钩子。
            expect($failure === 'fatal' ? !file_exists($directory . '/' . $mode . '.cleanup')
                : file_get_contents($directory . '/' . $mode . '.cleanup') === 'cleaned', '故障请求的对象析构行为不符');
            expect(file_get_contents($directory . '/' . $mode . '.shutdown') === "shutdown\n", '故障请求没有完成关闭回调');
            if ($failure === 'destructor') {
                expect(file_get_contents($directory . '/' . $mode . '.destructor') === "destructor\n", '异常析构重复或未执行');
            }
            if ($failure === 'native-finalizer') {
                $finalizers = file($directory . '/' . $mode . '.native', FILE_IGNORE_NEW_LINES);
                sort($finalizers);
                expect($finalizers === ['failed', 'survived'], 'Native finalizer bailout 中断了剩余对象释放');
            }
            expect(
                in_array($failure, ['exit', 'shutdown-bailout'], true)
                    ? $output['diagnostics'] === '' && $execution->stderr === ''
                    : str_contains($output['diagnostics'] . "\n" . $execution->stderr, 'thread:' . ($failure === 'throw' ? 'uncaught' : $failure)),
                '故障诊断丢失或正常 exit 产生诊断'
            );
            $failures[] = $observation;
        }
        $directory = $work . '/unjoined-' . bin2hex(random_bytes(5));
        expect(mkdir($directory, 0700), '无法创建未 join 观察目录');
        $unjoined = runThreadArtifact([$artifact, $directory, 'failure-unjoined'], $work, $directory, $environment);
        expect($unjoined->exitCode === 200 && !$unjoined->timedOut && !$unjoined->outputExceeded && $unjoined->signal === null
            && str_contains($unjoined->stdout . "\n" . $unjoined->stderr, 'cannot exit safely'), '未 join 必须终止整个进程，不能伪装成正常请求关闭');
        $failures[] = ['mode' => 'failure-unjoined', 'process-exit' => 200];
    } finally {
        foreach (array_reverse($moved, true) as $source => $backup) {
            expect(rename($backup, $source), '无法恢复消费者构建输入');
        }
    }
    $evidence = ['build-id' => $report['build-id'], 'sha256' => $report['sha256'],
        'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'source-free' => true, 'runs' => $results, 'failures' => $failures];
    file_put_contents($work . '/thread-evidence.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    echo '无源码线程验证通过：两个真实业务线程、存活线程隔离、非零返回、exit 与 8 次重建，重复 3 轮。' . "\n";
}

/**
 * 故障诊断可能来自标准输出；只接受一条完整控制 JSON，其余文本作为诊断核对。
 * 原始双流已由 runThreadArtifact 保存，本函数不重写或裁剪验收材料。
 *
 * @return array{control: array<string, mixed>, diagnostics: string}
 */
function parseThreadFailureOutput(string $stdout): array
{
    $control = null;
    $diagnostics = [];
    foreach (explode("\n", $stdout) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (!json_validate($line)) {
            $diagnostics[] = $line;
            continue;
        }
        $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        expect($control === null && is_array($decoded) && !array_is_list($decoded), '故障输出必须只有一条完整控制 JSON 对象');
        $control = $decoded;
    }
    expect($control !== null, '故障输出缺少完整控制 JSON 行');
    return ['control' => $control, 'diagnostics' => implode("\n", $diagnostics)];
}

/** 有界运行同一原生产物；观察失败也先保存原始输出和真实退出结果，再向调用者报错。 */
function runThreadArtifact(array $command, string $work, string $directory, array $environment, bool $observeResources = false): \Type\Testing\ProcessResult
{
    foreach (['stdout.json', 'stderr.log', 'execution.json'] as $file) {
        expect(!file_exists($directory . '/' . $file), '运行目录已有原始证据，不能覆盖：' . $file);
    }
    if ($observeResources) {
        writeThreadObservation($directory . '/observe-resources', "enabled\n");
    }
    $samples = [];
    $failure = null;
    $started = microtime(true);
    $process = new \Type\Testing\Process($command, $work, $environment);
    try {
        if ($observeResources) {
            foreach (threadResourcePhases() as $phase) {
                $ready = $directory . '/resource-' . $phase . '.ready';
                $deadline = min($started + 60, microtime(true) + 15);
                while (!is_file($ready)) {
                    expect($process->running(), '资源检查点之前进程退出：' . $phase);
                    expect(microtime(true) < $deadline, '等待资源检查点超时：' . $phase);
                    usleep(1000);
                    clearstatcache(true, $ready);
                }
                $checkpoint = json_decode((string) file_get_contents($ready), true, 512, JSON_THROW_ON_ERROR);
                $pid = $process->pid();
                expect($pid !== null && $checkpoint === ['phase' => $phase, 'pid' => $pid], '资源检查点不属于当前子进程');
                $samples[$phase] = sampleThreadResources($pid, $directory, $phase);
                writeThreadObservation($directory . '/resource-' . $phase . '.release', "sampled\n");
            }
        }
        $result = $process->wait(max(0.0, 60.0 - (microtime(true) - $started)));
    } catch (\Throwable $error) {
        $failure = $error;
        $result = $process->stop();
    } finally {
        $process->stop();
    }
    writeThreadObservation($directory . '/stdout.json', $result->stdout);
    writeThreadObservation($directory . '/stderr.log', $result->stderr);
    writeThreadObservation($directory . '/execution.json', json_encode(get_object_vars($result), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    if ($observeResources) {
        writeThreadObservation($directory . '/resources.json', json_encode(
            ['platform' => PHP_OS_FAMILY,
            'status' => $failure === null ? 'observed' : 'failed', 'error' => $failure?->getMessage(), 'samples' => $samples],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n");
    }
    if ($failure !== null) {
        throw $failure;
    }
    return $result;
}

/** 每个重建检查点均已 join 并释放对应线程对象，只有 b 角色继续存活。 */
function threadResourcePhases(): array
{
    return ['cold', 'survivor', 'again-0', 'again-1', 'again-2', 'again-3', 'again-4', 'again-5', 'again-6', 'again-7', 'joined'];
}

/** 原始证据只创建一次，短写或已存在都不能被覆盖成成功材料。 */
function writeThreadObservation(string $path, string $contents): void
{
    expect(!file_exists($path), '不能覆盖原始线程证据：' . $path);
    $stream = fopen($path, 'xb');
    expect($stream !== false, '无法创建线程观察记录');
    try {
        expect(fwrite($stream, $contents) === strlen($contents), '线程观察记录写入不完整');
    } finally {
        fclose($stream);
    }
}

/** 使用现有进程所有者限制工具运行时间和双流；解析前保存包含双流的原始结果。 */
function threadResourceCommand(array $command, string $directory, string $name): string
{
    $process = new \Type\Testing\Process($command, $directory, null, 65536);
    try {
        $result = $process->wait(3);
    } finally {
        $process->stop();
    }
    writeThreadObservation($directory . '/' . $name . '.json', json_encode(
        get_object_vars($result),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . "\n");
    expect($result->successful() && $result->stderr === '', '资源观察工具失败：' . $name);
    return $result->stdout;
}

/** 外部观察正在检查点等待的目标，记录当前 RSS 而非进程历史峰值。 */
function sampleThreadResources(int $pid, string $directory, string $phase): array
{
    $prefix = 'resource-' . $phase;
    if (PHP_OS_FAMILY === 'Linux') {
        $status = file_get_contents('/proc/' . $pid . '/status', false, null, 0, 65537);
        writeThreadObservation($directory . '/' . $prefix . '-status.txt', $status === false ? '' : $status);
        expect(is_string($status) && strlen($status) <= 65536, '无法有界读取 Linux 进程状态');
        $paths = glob('/proc/' . $pid . '/fd/[0-9]*', GLOB_NOSORT);
        $descriptors = [];
        foreach (array_slice($paths === false ? [] : $paths, 0, 4097) as $path) {
            $descriptors[basename($path)] = readlink($path);
        }
        ksort($descriptors, SORT_NUMERIC);
        writeThreadObservation($directory . '/' . $prefix . '-fds.json', json_encode(
            $descriptors,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n");
        expect(is_array($paths) && count($paths) > 0 && count($paths) <= 4096 && !in_array(false, $descriptors, true), '无法有界读取 Linux 文件描述符');
        expect(preg_match('/^VmRSS:\s+([0-9]+) kB$/m', $status, $rss) === 1
            && preg_match('/^Threads:\s+([0-9]+)$/m', $status, $threads) === 1, 'Linux RSS 或线程数缺失');
        $sample = ['method' => 'proc-status-and-fd', 'handles' => count($descriptors), 'threads' => (int) $threads[1], 'rss-bytes' => (int) $rss[1] * 1024];
    } elseif (PHP_OS_FAMILY === 'Darwin') {
        $rssText = threadResourceCommand(['ps', '-p', (string) $pid, '-o', 'rss='], $directory, $prefix . '-rss');
        $threadText = threadResourceCommand(['ps', '-M', '-p', (string) $pid], $directory, $prefix . '-threads');
        // NUL 字段边界避免文件名中的换行被误算为额外 FD。
        $fdText = threadResourceCommand(['/usr/sbin/lsof', '-nP', '-a', '-p', (string) $pid, '-F0fn'], $directory, $prefix . '-fds');
        $descriptors = [];
        $observedPid = false;
        foreach (explode("\0", $fdText) as $field) {
            $field = trim($field, "\n");
            $observedPid = $observedPid || $field === 'p' . $pid;
            if (preg_match('/^f([0-9]+)$/D', $field, $fd) === 1) {
                $descriptors[(int) $fd[1]] = true;
            }
        }
        // ps -M 的后续线程行省略 USER 列，但仍保留目标 PID。
        $threadCount = preg_match_all('/^[ \t]*(?:\S+[ \t]+)?' . $pid . '[ \t]+/m', $threadText);
        expect(preg_match('/^[0-9]+$/D', trim($rssText)) === 1 && $threadCount > 0
            && $observedPid && $descriptors !== [], 'macOS RSS、线程或数字 FD 观察不完整');
        $sample = ['method' => 'ps-and-lsof', 'handles' => count($descriptors), 'threads' => $threadCount, 'rss-bytes' => (int) trim($rssText) * 1024];
    } elseif (PHP_OS_FAMILY === 'Windows') {
        $script = '$ErrorActionPreference="Stop"; $observedProcess=[System.Diagnostics.Process]::GetProcessById(' . $pid . '); '
            . 'try {$observedProcess.Refresh(); @{pid=$observedProcess.Id; handles=$observedProcess.HandleCount; '
            . 'threads=$observedProcess.Threads.Count; rss=$observedProcess.WorkingSet64} | ConvertTo-Json -Compress} '
            . 'finally {$observedProcess.Dispose()}';
        $raw = threadResourceCommand(['powershell.exe', '-NoLogo', '-NoProfile', '-NonInteractive', '-Command', $script], $directory, $prefix . '-process');
        $windows = json_decode(trim($raw), true, 512, JSON_THROW_ON_ERROR);
        expect(($windows['pid'] ?? null) === $pid && is_int($windows['handles'] ?? null)
            && is_int($windows['threads'] ?? null) && is_int($windows['rss'] ?? null), 'Windows 进程资源观察不完整');
        $sample = ['method' => 'powershell-process', 'handles' => $windows['handles'], 'threads' => $windows['threads'], 'rss-bytes' => $windows['rss']];
    } else {
        throw new RuntimeException('当前平台没有线程资源观察实现');
    }
    expect($sample['handles'] > 0 && $sample['threads'] > 0 && $sample['rss-bytes'] > 0, '线程资源观察不能以零代替缺失数据');
    return ['phase' => $phase, 'pid' => $pid] + $sample;
}

/** 相同存活角色下重建不能积累句柄或 OS 线程；RSS 保留真实差值，不强求 allocator 归还全部缓存。 */
function verifyThreadResources(string $directory): array
{
    $report = json_decode((string) file_get_contents($directory . '/resources.json'), true, 512, JSON_THROW_ON_ERROR);
    $samples = $report['samples'];
    expect($report['status'] === 'observed' && array_keys($samples) === threadResourcePhases(), '线程资源检查点不完整');
    $baseline = $samples['survivor'];
    foreach ($samples as $phase => $sample) {
        expect($sample['pid'] === $baseline['pid'], '资源观察混入了其他进程');
        if (str_starts_with($phase, 'again-')) {
            expect($sample['handles'] <= $baseline['handles'] && $sample['threads'] <= $baseline['threads'], '重复线程回收后仍累积句柄或 OS 线程：' . $phase);
        }
    }
    expect($samples['joined']['threads'] <= $samples['cold']['threads'] && $samples['joined']['handles'] <= $samples['cold']['handles'], '全部 join 并释放对象后仍遗留线程资源');
    return $report + ['rebuild-resources-stable' => true,
        'joined-rss-delta-from-cold' => $samples['joined']['rss-bytes'] - $samples['cold']['rss-bytes'],
        'joined-rss-delta-from-survivor' => $samples['joined']['rss-bytes'] - $baseline['rss-bytes']];
}
