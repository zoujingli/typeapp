<?php

declare(strict_types=1);

// 仅在任务内源码树应用固定适配；不读取或修改宿主 PHP SDK。
if ($argc !== 3 || PHP_VERSION !== '8.5.10' || !PHP_ZTS) {
    throw new InvalidArgumentException('需要锁定 PHP 8.5.10 ZTS、目标 PHP 源码目录和报告路径');
}
$root = dirname(__DIR__, 2);
$source = $argv[1];
$report = [];
foreach (['SwooleThreadSource', 'SwooleHttpSource', 'SwooleSocketSource', 'SwooleStaticSource', 'SwooleWindowsSource'] as $name) {
    require $root . '/plugin/type-build/src/' . $name . '.php';
    $class = 'Type\\Build\\' . $name;
    $report[$name] = (new $class())->apply($source . '/ext/swoole');
}
$report['tls'] = (new Type\Build\SwooleSocketSource())->applyTls($source . '/ext/swoole');

// 当前 curl 的固定功能集没有 SSH；PHP 官方配置无条件检查 libssh2，
// 这里只移除多余的构建检查，实际传递依赖仍由真实链接与 PE 审计核对。
$file = $source . '/ext/curl/config.w32';
$before = '152aa3db1a170d049a69592536b5262c7ea27477ac0ca056d46f52ba8d678c19';
$text = (string) file_get_contents($file);
$needle = 'CHECK_LIB("libssh2.lib", "curl", PHP_CURL) &&';
if (hash_file('sha256', $file) !== $before || substr_count($text, $needle) !== 1) {
    throw new RuntimeException('PHP curl 配置原文不符');
}
$text = str_replace($needle, '/* The pinned static curl was built without SSH. */', $text);
if (file_put_contents($file, $text) !== strlen($text)) {
    throw new RuntimeException('PHP curl 静态配置保存失败');
}
$report['php-curl'] = ['before' => $before, 'after' => hash('sha256', $text)];
file_put_contents($argv[2], json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
