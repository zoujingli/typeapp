<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\BuildPlatform;
use Type\Testing\Process;

// 每个平台串行编译独立消费者；保留失败原产物和输出，不以重复执行覆盖失败。
$root = BuildPlatform::resolve(dirname(__DIR__));
$work = $root . '/build/toolchain-regressions-' . bin2hex(random_bytes(6));
expect(mkdir($work, 0700, true), '无法创建工具链原生回归目录');
// 根工作流先安装并缓存锁定工具链；独立消费者随后只读同一缓存，避免 codeload 部分下载把网络抖动误报为协议失败。
putenv('COMPOSER_CACHE_DIR=' . ((string) getenv('COMPOSER_CACHE_DIR') ?: $root . '/.cache/composer'));
putenv('COMPOSER_DISABLE_NETWORK=1');
$cases = [
    'threads' => ['compiled-threads.php'],
    'initialization' => ['compiled-threads.php', '--initialization-failure'],
    'resources' => ['compiled-threads.php', '--resources'],
    'http' => ['http-threads.php'],
    'tcp' => ['tcp-consumer.php'],
    'udp' => ['udp-consumer.php'],
];
$report = ['platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'),
    'toolchain-sha256' => hash_file('sha256', $root . '/toolchain.lock.json'), 'cases' => [], 'complete' => false];
try {
    foreach ($cases as $name => $arguments) {
        $script = array_shift($arguments);
        echo '工具链原生回归：' . $name . "\n";
        $started = microtime(true);
        $process = new Process([PHP_BINARY, $root . '/tests/' . $script, $work . '/' . $name, ...$arguments], $root, null, 8 * 1024 * 1024);
        try {
            $result = $process->wait(1500);
        } finally {
            $process->stop();
        }
        file_put_contents($work . '/' . $name . '.stdout.log', $result->stdout);
        file_put_contents($work . '/' . $name . '.stderr.log', $result->stderr);
        $report['cases'][$name] = ['exit' => $result->exitCode, 'signal' => $result->signal,
            'timed-out' => $result->timedOut, 'output-exceeded' => $result->outputExceeded,
            'seconds' => microtime(true) - $started];
        expect($result->successful(), '工具链回归失败：' . $name . '，原始日志见 ' . $work);
        echo $result->stdout;
    }
    $report['complete'] = true;
} finally {
    file_put_contents($work . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
echo '工具链原生回归全部通过：' . substr($work, strlen($root) + 1) . "/verification.json\n";
