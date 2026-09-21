<?php

declare(strict_types=1);

// 构建工具只加载自己的依赖闭包，应用的 autoload.files 留给 AOT 检查。
if (isset($GLOBALS['__type_build_vendor_directory'])) {
    return $GLOBALS['__type_build_vendor_directory'];
}
$proxyAutoload = $GLOBALS['_composer_autoload_path'] ?? null;
if (!is_string($proxyAutoload) || !is_file($proxyAutoload)) {
    throw new RuntimeException('构建工具需要 Composer 命令代理提供依赖目录');
}
$vendorDirectory = dirname($proxyAutoload);
if (PHP_OS_FAMILY === 'Windows') {
    $vendorDirectory = str_replace('\\', '/', $vendorDirectory);
}
$installed = json_decode(file_get_contents($vendorDirectory . '/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
$packages = [];
foreach ($installed['packages'] ?? [] as $package) {
    $packages[$package['name']] = $package;
}
$queue = ['zoujingli/type-build'];
$allowed = [];
$functionFiles = [];
while ($queue !== []) {
    $name = array_shift($queue);
    if (!str_contains($name, '/') && (in_array($name, ['php', 'php-64bit', 'php-zts', 'php-debug', 'php-ipv6', 'hhvm', 'composer', 'composer-runtime-api', 'composer-plugin-api'], true)
        || str_starts_with($name, 'ext-') || str_starts_with($name, 'lib-'))) {
        continue;
    }
    if (isset($allowed[$name])) {
        continue;
    }
    $package = $packages[$name] ?? throw new RuntimeException('构建依赖未安装：' . $name);
    $directory = realpath($vendorDirectory . '/composer/' . $package['install-path']);
    if ($directory === false) {
        throw new RuntimeException('构建依赖路径不存在：' . $name);
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $directory = str_replace('\\', '/', $directory);
    }
    $allowed[$name] = $directory;
    foreach ($package['autoload']['files'] ?? [] as $file) {
        $resolved = realpath($directory . '/' . $file);
        if (PHP_OS_FAMILY === 'Windows' && $resolved !== false) {
            $resolved = str_replace('\\', '/', $resolved);
        }
        if ($resolved === false || !str_starts_with($resolved, $directory . '/')) {
            throw new RuntimeException('构建函数文件超出所属包：' . $name);
        }
        $functionFiles[$resolved] = true;
    }
    array_push($queue, ...array_keys($package['require'] ?? []));
}
$owns = static function (string $file) use ($allowed): bool {
    $resolved = realpath($file);
    if ($resolved === false) {
        return false;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $resolved = str_replace('\\', '/', $resolved);
    }
    foreach ($allowed as $directory) {
        if ($resolved === $directory || str_starts_with($resolved, $directory . '/')) {
            return true;
        }
    }

    return false;
};
require_once $vendorDirectory . '/composer/ClassLoader.php';
$loader = new Composer\Autoload\ClassLoader($vendorDirectory);
foreach (require $vendorDirectory . '/composer/autoload_psr4.php' as $prefix => $directories) {
    $directories = array_values(array_filter($directories, $owns));
    if ($directories !== []) {
        $loader->addPsr4($prefix, $directories);
    }
}
foreach (require $vendorDirectory . '/composer/autoload_namespaces.php' as $prefix => $directories) {
    $directories = array_values(array_filter($directories, $owns));
    if ($directories !== []) {
        $loader->add($prefix, $directories);
    }
}
$classMap = [];
foreach (require $vendorDirectory . '/composer/autoload_classmap.php' as $class => $file) {
    if ($class === 'Composer\\InstalledVersions' || $owns($file)) {
        $classMap[$class] = $file;
    }
}
$loader->addClassMap($classMap);
$loader->register(true);
$GLOBALS['__type_build_vendor_directory'] = $vendorDirectory;
$GLOBALS['__type_build_loader'] = $loader;

// Composer 的 autoload.files 在隔离消费者或锁文件来自旧生成器时可能没有被
// 重放；构建入口必须先恢复 PHP-Parser 使用的 T_* 常量，再开始任何解析工作。
require_once __DIR__ . '/PhpTokenCompatibility.php';

foreach (array_keys($functionFiles) as $file) {
    require_once $file;
}

return $vendorDirectory;
