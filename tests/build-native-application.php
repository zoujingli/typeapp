<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\BuildPlatform;

$root = BuildPlatform::resolve(dirname(__DIR__));
expect(in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true) && $argc === 1, '标准应用Swoole原生产物构建需要Unix目标');
$base = $root . '/build/application-inputs-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建本轮应用构建声明');
$settings = json_decode(file_get_contents($root . '/docs/build-config/type-app.json'), true, 512, JSON_THROW_ON_ERROR);
// 声明目录和docs/build-config同处项目根下两层，原相对项目根及生产源码范围不变。
$settings['runtime'][PHP_OS_FAMILY]['extensions'] = array_values(array_unique([
    ...($settings['runtime'][PHP_OS_FAMILY]['extensions'] ?? []), 'swoole',
]));
$module = getenv('TYPE_SWOOLE_MODULE');
if ($module !== false) {
    $module = BuildPlatform::resolve($module);
    $destination = $base . '/swoole.so';
    expect(is_file($module) && copy($module, $destination), '无法固定本轮显式Swoole模块');
    $settings['runtime'][PHP_OS_FAMILY]['modules']['swoole'] = [
        'file' => substr($destination, strlen($root) + 1), 'sha256' => hash_file('sha256', $destination),
    ];
}
$configuration = $base . '/type-app.json';
file_put_contents($configuration, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo successful([PHP_BINARY, $root . '/vendor/bin/type', $configuration], $root);
