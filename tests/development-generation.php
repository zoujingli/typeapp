<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Testing\Process;

/** 通过公开准备命令取得代次；只为本轮 fixture 使用主仓已安装的构建依赖。 */
function preparedGeneration(string $root, array $environment): array
{
    $process = new Process([PHP_BINARY, $root . '/bin/typeapp-prepare', '--json'], $root, $environment);
    try {
        $result = $process->wait(15);
        expect($result->successful(), '独立开发准备失败：' . $result->stderr);
        return json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
    } finally {
        $process->stop();
    }
}

/**
 * 复现首次并发发布或连接处理的两段子进程链；共用三十秒预算并检查同一公开代次。
 * @return array{generation:array, seconds:array<int,float>}
 */
function concurrentGenerations(string $root, array $environment, ?array $expected, int $totalSteps = 2): array
{
    $processes = [];
    $steps = array_fill(0, 8, 0);
    $completed = [];
    $started = microtime(true);
    try {
        for ($slot = 0; $slot < 8; $slot++) {
            $processes[$slot] = new Process([PHP_BINARY, $root . '/bin/typeapp-prepare', '--json'], $root, $environment);
        }
        do {
            foreach ($processes as $slot => $process) {
                if ($process->running()) {
                    continue;
                }
                $result = $process->wait(0);
                expect($result->successful(), '并发开发准备失败：' . json_encode(['exit' => $result->exitCode,
                    'signal' => $result->signal, 'timeout' => $result->timedOut, 'output-exceeded' => $result->outputExceeded,
                    'stdout' => $result->stdout, 'stderr' => $result->stderr], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                $generation = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
                $expected ??= $generation;
                expect($generation === $expected, '并发子进程加载了不同或不完整代次');
                $steps[$slot]++;
                if ($steps[$slot] === $totalSteps) {
                    $completed[$slot] = microtime(true) - $started;
                    unset($processes[$slot]);
                } else {
                    $processes[$slot] = new Process([PHP_BINARY, $root . '/bin/typeapp-prepare', '--json'], $root, $environment);
                }
            }
            expect(microtime(true) - $started < 30.0, '开发worker准备链超出三十秒预算：' . json_encode($steps, JSON_THROW_ON_ERROR));
            if ($processes !== []) {
                usleep(1000);
            }
        } while ($processes !== []);
        return ['generation' => $expected, 'seconds' => $completed];
    } finally {
        foreach ($processes as $process) {
            $process->stop(0.2);
        }
    }
}

/** 公开准备入口必须失败，不返回旧代次；异常时同样收回准确子进程。 */
function rejectedGeneration(string $root, array $environment, string $reason): void
{
    $process = new Process([PHP_BINARY, $root . '/bin/typeapp-prepare', '--json'], $root, $environment);
    try {
        $failure = $process->wait(5.0);
        expect(!$failure->successful() && str_contains($failure->stderr, $reason), '无效开发输入或代次未被拒绝：' . $failure->stderr);
    } finally {
        $process->stop(0.2);
    }
}

$root = dirname(__DIR__);
$work = $root . '/build/development-generation-' . bin2hex(random_bytes(6));
expect(mkdir($work, 0700), '无法准备开发生成测试目录');
try {
    foreach (['app', 'config', 'bin'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            $relative = substr($entry->getPathname(), strlen($root) + 1);
            expect(!$entry->isLink(), '测试只复制明确普通源码');
            if ($entry->isDir()) {
                if (!is_dir($work . '/' . $relative)) {
                    mkdir($work . '/' . $relative, 0700, true);
                }
            } else {
                if (!is_dir(dirname($work . '/' . $relative))) {
                    mkdir(dirname($work . '/' . $relative), 0700, true);
                }
                expect(copy($entry->getPathname(), $work . '/' . $relative), '复制开发源码失败');
            }
        }
    }
    mkdir($work . '/vendor', 0700);
    mkdir($work . '/docs/build-config', 0700, true);
    foreach (['composer.json', 'composer.lock', 'docs/build-config/type-app.json'] as $file) {
        expect(copy($root . '/' . $file, $work . '/' . $file), '复制明确声明失败');
    }
    $composer = json_decode(file_get_contents($work . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $composer['config']['vendor-dir'] = $root . '/vendor';
    file_put_contents($work . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
    mkdir($work . '/tooling', 0700);
    foreach (glob($root . '/plugin/type-build/src/*.php') as $toolFile) {
        expect(copy($toolFile, $work . '/tooling/' . basename($toolFile)), '复制本轮生成器失败');
    }
    // 只覆盖本轮生成器副本以检查其内容变化；其余依赖使用主仓已安装版本。
    file_put_contents($work . '/vendor/autoload.php', <<<'PHP'
<?php
$loader = require dirname(__DIR__, 3) . '/vendor/autoload.php';
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Type\\Build\\')) {
        $file = dirname(__DIR__) . '/tooling/' . substr($class, strlen('Type\\Build\\')) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
}, true, true);
return $loader;
PHP);
    $environment = getenv();
    $secret = 'generation-secret-' . bin2hex(random_bytes(12));
    $environment['APP_API_TOKEN'] = $secret;
    $environment['APP_BASE_PATH'] = $work;
    file_put_contents($work . '/.env', 'deliberately invalid dotenv with ' . $secret);
    $cold = concurrentGenerations($work, $environment, null, 1);
    $first = $cold['generation'];
    $second = preparedGeneration($work, $environment);
    expect($first === $second, '相同输入没有复用完整代次');
    $concurrent = concurrentGenerations($work, $environment, $first);
    $manifest = file_get_contents($first['directory'] . '/manifest.json');
    expect(!str_contains($manifest, $secret) && !str_contains($manifest, $work)
        && !str_contains($manifest, '.env'), '开发代次混入秘密、绝对路径或dotenv内容');
    $environment['APP_API_TOKEN'] = $secret . '-changed';
    file_put_contents($work . '/.env', 'another invalid dotenv ' . $secret);
    expect(preparedGeneration($work, $environment) === $first, '启动环境不应改变编译声明代次');

    $config = $work . '/config/app.php';
    $original = file_get_contents($config);
    file_put_contents($config, "<?php\ndeclare(strict_types=1);\nreturn ['name' => 'generation-changed'];\n");
    $changed = preparedGeneration($work, $environment);
    expect($changed['generation'] !== $first['generation'] && is_file($first['directory'] . '/config.php')
        && str_contains(file_get_contents($changed['directory'] . '/config.php'), 'generation-changed'), '配置修改没有生成新代码或覆盖了在用代次');
    file_put_contents($config, $original);
    expect(preparedGeneration($work, $environment) === $first, '恢复源声明不能复用原本完整代次');

    $source = $work . '/app/common/service/IdentityService.php';
    $originalSource = file_get_contents($source);
    $sourceTime = filemtime($source);
    file_put_contents($source, $originalSource . "\n// 本轮隔离业务修改，用于验证输入身份。\n");
    touch($source, $sourceTime);
    $modifiedSource = preparedGeneration($work, $environment);
    expect($modifiedSource['generation'] !== $first['generation'], '业务源变化未进入开发代次身份');
    file_put_contents($source, $originalSource);
    expect(preparedGeneration($work, $environment) === $first, '业务源码恢复后的代次不稳定');
    file_put_contents($source, '<?php function invalid( {');
    rejectedGeneration($work, $environment, 'Syntax error');
    file_put_contents($source, $originalSource);

    foreach ([$work . '/config/route.php', $work . '/tooling/ModelCompiler.php'] as $changing) {
        $originalInput = file_get_contents($changing);
        $inputTime = filemtime($changing);
        file_put_contents($changing, $originalInput . "\n");
        touch($changing, $inputTime);
        expect(preparedGeneration($work, $environment)['generation'] !== $first['generation'], '声明或生成器内容变化未进入代次身份');
        file_put_contents($changing, $originalInput);
        expect(preparedGeneration($work, $environment) === $first, '声明或生成器恢复后没有复用原代次');
    }

    $reference = null;
    foreach (glob(dirname($first['directory']) . '/.inputs-*') as $candidate) {
        if (file_get_contents($candidate) === $first['generation']) {
            expect($reference === null, '同一完整输入不应产生重复索引');
            $reference = $candidate;
        }
    }
    expect($reference !== null, '缺少本轮已验证代次的完整输入索引');
    file_put_contents($reference, $changed['generation']);
    rejectedGeneration($work, $environment, '损坏');
    file_put_contents($reference, $first['generation']);
    file_put_contents($first['directory'] . '/manifest.json', '{}');
    rejectedGeneration($work, $environment, '损坏');
    file_put_contents($first['directory'] . '/manifest.json', $manifest);

    // 损坏只发生于本次fixture的准确代次，不能让启动器静默加载旧/部分生成文件。
    $generatedConfig = file_get_contents($first['directory'] . '/config.php');
    file_put_contents($first['directory'] . '/config.php', '<?php /* broken generation */');
    rejectedGeneration($work, $environment, '损坏');
    unlink($reference);
    rejectedGeneration($work, $environment, '损坏');
    file_put_contents($first['directory'] . '/config.php', $generatedConfig);
    expect(preparedGeneration($work, $environment) === $first, '完整旧代次没有恢复输入索引');
    echo "开发代次首次并发发布、两段复用、源码/声明/生成器更新、环境隔离和损坏拒绝通过。\n";
    echo json_encode(['workers' => 8, 'steps' => 2, 'cold_maximum_seconds' => max($cold['seconds']),
        'warm_maximum_seconds' => max($concurrent['seconds'])], JSON_THROW_ON_ERROR), "\n";
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($work);
}
