<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Composer\InstalledVersions;

$lock = json_decode(file_get_contents(dirname(__DIR__) . '/toolchain.lock.json'), true, 512, JSON_THROW_ON_ERROR);
$platforms = ['linux' => 'Linux', 'macos' => 'Darwin', 'windows' => 'Windows'];
$requested = $argv[1] ?? $lock['platform'];
if ($argc > 2 || !isset($platforms[$requested]) || PHP_OS_FAMILY !== $platforms[$requested] || PHP_VERSION !== $lock['php'] || (bool) PHP_ZTS !== $lock['zts']) {
    throw new RuntimeException('PHP 平台或版本与工具链锁定不一致：' . PHP_OS_FAMILY . ' ' . PHP_VERSION);
}
foreach (['typephp', 'phpx'] as $name) {
    $package = 'swoole/' . $name;
    if (ltrim(InstalledVersions::getPrettyVersion($package), 'v') !== $lock[$name]['version']
        || InstalledVersions::getReference($package) !== $lock[$name]['reference']) {
        throw new RuntimeException('工具链依赖身份不一致：' . $package);
    }
}
echo '工具链源码身份验证通过：PHP ' . PHP_VERSION . ' ZTS / ' . php_uname('m') . PHP_EOL;
