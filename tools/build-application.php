<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/distribution/Process.php';

use TypeApp\Distribution\Process;

// 正式前端只构建一次，各平台验证同一清单后全量AOT；版本只写临时配置。
try {
    $root = dirname(__DIR__);
    if ($argc > 2 || ($argc === 2 && $argv[1] !== '--shared-development')) {
        throw new InvalidArgumentException('用法：php tools/build-application.php [--shared-development]');
    }
    // 正常交付必须静态构建；共享库只用于既有开发与回归场景，不能进入发布候选。
    if (($argv[1] ?? '') !== '--shared-development' && Type\Build\StaticRuntimeSdk::selected() === null) {
        throw new RuntimeException('单程序构建需要目标平台的 TYPE_STATIC_RUNTIME 清单；不会回退为目录包。制备与验收范围见 docs/guide/deployment.md。');
    }
    $frontend = getenv('TYPE_FRONTEND_MANIFEST');
    if ($frontend === false || $frontend === '') {
        $phar = getenv('TYPE_COMPOSER_PHAR');
        $composer = is_string($phar) && $phar !== '' ? [PHP_BINARY, $phar] : [getenv('COMPOSER_BINARY') ?: 'composer'];
        echo Process::output([...$composer, 'web:build'], $root) . "\n";
        $frontend = $root . '/build/frontend/manifest.json';
        echo Process::output([PHP_BINARY, $root . '/tools/frontend-resources.php', 'record', $frontend], $root) . "\n";
    }
    echo Process::output([PHP_BINARY, $root . '/tools/frontend-resources.php', 'verify', $frontend], $root) . "\n";
    $settings = json_decode((string) file_get_contents($root . '/docs/build-config/type-app.json'), true, 64, JSON_THROW_ON_ERROR);
    $version = getenv('TYPE_RELEASE_VERSION');
    if (is_string($version) && $version !== '') {
        if (!preg_match('/^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-rc\.[1-9][0-9]*)?$/D', $version)) {
            throw new RuntimeException('发布版本格式无效');
        }
        $settings['version'] = substr($version, 1);
    }
    // 构建配置路径参与缓存身份；相同配置复用路径，不因随机临时目录强制全量重编译。
    $work = $root . '/build/application-inputs-' . hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR));
    // 与docs/build-config相同深度，project-root仍指向主仓。
    Process::report($work . '/type-app.json', $settings);
    $started = hrtime(true);
    echo Process::output([PHP_BINARY, $root . '/vendor/bin/type', $work . '/type-app.json'], $root) . "\n";
    $seconds = (hrtime(true) - $started) / 1e9;
    // 仅由原候选性能验收请求记录；计时绑定这次真实编译，不为补证据重新编译产物。
    if (getenv('TYPE_BENCHMARK_BUILD_TIMING') === '1') {
        $runtime = Type\Build\StaticRuntimeSdk::selected(getenv('TYPEAPP_BUILD_PROFILE') ?: null);
        $artifact = (new Type\Build\BuildPlatform())->output($root . '/' . $settings['output']);
        $report = json_decode((string) file_get_contents($artifact . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
        if ($runtime === null || ($report['cache']['hit'] ?? true) !== false || ($report['sha256'] ?? '') !== hash_file('sha256', $artifact)
            || ($report['manifest']['runtime-linkage'] ?? '') !== 'static') {
            throw new RuntimeException('原候选编译计时需要本轮真实静态编译；缓存命中不能作为冷编译结果');
        }
        Process::report($root . '/build/static-benchmark-build.json', [
            'protocol' => 1, 'source' => Process::output(['git', 'rev-parse', 'HEAD'], $root),
            'profile' => getenv('TYPEAPP_BUILD_PROFILE'), 'sha256' => $report['sha256'], 'build_id' => $report['build-id'],
            'build_seconds' => $seconds, 'timing_scope' => 'vendor/bin/type process; frontend and SDK preparation excluded',
            'php_memory_limit' => ini_get('memory_limit'),
            'controller_ini_sha256' => php_ini_loaded_file() === false ? null : hash_file('sha256', php_ini_loaded_file()),
            'report_sha256' => hash_file('sha256', $artifact . '.build.json'),
            'sdk_manifest_sha256' => hash_file('sha256', $runtime->manifestPath()),
        ]);
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
