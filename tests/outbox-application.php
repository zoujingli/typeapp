<?php

declare(strict_types=1);

/**
 * 将主仓演练作为独立应用送入标准开发生成，已安装组件沿原 vendor 目录使用。
 * 调用者负责在 finally 删除返回的目录；生产源码全部由该应用声明和包元数据确定。
 * @return array{directory:string, command:list<string>}
 */
function outboxApplication(string $root): array
{
    $directory = $root . '/build/outbox-application-' . bin2hex(random_bytes(6));
    expect(mkdir($directory . '/app', 0700, true), '无法准备Outbox开发应用');
    $composer = ['name' => 'type-tests/outbox-application', 'require' => [
        'zoujingli/type-orm-mysql' => '~1.0.0@dev', 'zoujingli/type-orm-pgsql' => '~1.0.0@dev',
        'zoujingli/type-orm-sqlite' => '~1.0.0@dev', 'zoujingli/type-queue' => '~1.0.0@dev'],
        'config' => ['vendor-dir' => '../../vendor'], 'autoload' => ['classmap' => ['app']]];
    file_put_contents($directory . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
    expect(mkdir($directory . '/vendor', 0700), '无法准备开发加载入口');
    file_put_contents($directory . '/vendor/autoload.php', '<?php return require dirname(__DIR__, 3) . "/vendor/autoload.php";');
    copy($root . '/composer.lock', $directory . '/composer.lock');
    copy($root . '/toolchain.lock.json', $directory . '/toolchain.lock.json');
    foreach (['examples/model/Drivers.php' => 'Drivers.php', 'examples/outbox/Adapters.php' => 'Adapters.php',
        'examples/outbox/Models.php' => 'Models.php', 'examples/outbox-command.php' => 'main.php'] as $source => $target) {
        expect(copy($root . '/' . $source, $directory . '/app/' . $target), '无法复制Outbox应用声明');
    }
    $settings = ['name' => 'outbox-application', 'entry' => 'app/main.php', 'sources' => ['app'],
        'output' => 'build/type-app', 'build-directory' => 'build/compiler'];
    file_put_contents($directory . '/application.json', json_encode($settings, JSON_THROW_ON_ERROR));
    $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true)
        . '; (new Type\\Build\\DevelopmentBuilder())->loadConfiguration(' . var_export($directory . '/application.json', true) . '); main($argc,$argv);';
    return ['directory' => $directory, 'command' => [PHP_BINARY, '-r', $launcher]];
}

/** 只删除本轮随机应用身份派生的队列键与同步屏障。 */
function cleanupOutboxQueue(string $application): void
{
    $redis = new Redis();
    try {
        expect($redis->connect(getenv('TYPE_REDIS_HOST') ?: '127.0.0.1', (int) (getenv('TYPE_REDIS_PORT') ?: 6379)), '无法清理本轮Outbox队列');
        $keys = $redis->keys('type:queue:{' . hash('sha256', $application . "\0default") . '}:*');
        if ($keys !== []) {
            $redis->del($keys);
        }
        $redis->del($application . ':accepted', $application . ':consumed');
    } finally {
        if ($redis->isConnected()) {
            $redis->close();
        }
    }
}
