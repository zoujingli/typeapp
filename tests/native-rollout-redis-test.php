<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-rollout-redis.php';

$server = $argv[1] ?? '';
$base = dirname(__DIR__) . '/build/native-rollout-redis-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建原生Redis生命周期测试目录');
$first = new NativeRolloutRedis($base . '/first', $server);
$second = null;
try {
    $second = new NativeRolloutRedis($base . '/second', $server);
    $firstEnvironment = $first->environment();
    $secondEnvironment = $second->environment();
    $firstEvidence = $first->evidence();
    expect($firstEvidence['servers']['queue']['pid'] !== $firstEvidence['servers']['cache']['pid'], '两个用途共用了进程');
    $first->close();
    $first->close();
    foreach (['TYPE_REDIS_PORT', 'TYPE_ROLLOUT_CACHE_PORT'] as $key) {
        $closed = false;
        $client = new Redis();
        try {
            $closed = !$client->connect('127.0.0.1', (int) $firstEnvironment[$key], 0.2);
        } catch (RedisException) {
            $closed = true;
        }
        expect($closed, '已关闭的实例仍接受连接');
        $other = new Redis();
        expect($other->connect('127.0.0.1', (int) $secondEnvironment[$key], 0.2) && $other->ping() !== false, '关闭影响了其他所有者的实例');
        $other->close();
    }
    foreach ([[$base . '/first', $server], [$base . '/invalid', __FILE__], [$base . '/failed-start', PHP_BINARY]] as [$directory, $binary]) {
        $rejected = false;
        try {
            new NativeRolloutRedis($directory, $binary);
        } catch (RuntimeException) {
            $rejected = true;
        }
        expect($rejected, '既有目录、非原生服务器或启动失败没有拒绝');
    }
    expect(!file_exists($base . '/invalid'), '拒绝服务器后仍创建了数据目录');
} finally {
    try {
        $first->close();
    } finally {
        $second?->close();
    }
}
file_put_contents($base . '/verification.json', json_encode(['redis' => $firstEvidence,
    'checks' => ['actual-redis-storage-policy', 'independent-processes', 'idempotent-close', 'owned-ports-closed', 'other-owner-unaffected', 'invalid-server-rejected', 'failed-start-rejected', 'no-overwrite']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
echo '原生Redis存储策略、独立进程、所有权与清理通过：' . $base . "/verification.json\n";
