<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/distribution/Process.php';

use TypeApp\Distribution\Process;

// 正式前端只构建一次，各平台验证同一清单后全量AOT；版本只写临时配置。
try {
    $root = dirname(__DIR__);
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
    $work = $root . '/build/application-inputs-' . bin2hex(random_bytes(6));
    // 与docs/build-config相同深度，project-root仍指向主仓。
    Process::report($work . '/type-app.json', $settings);
    echo Process::output([PHP_BINARY, $root . '/vendor/bin/type', $work . '/type-app.json'], $root) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
