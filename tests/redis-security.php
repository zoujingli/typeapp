<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Redis\RedisConfiguration;
use Type\Redis\RedisException;
use Type\Redis\RedisManager;
use Type\Runtime\ExecutionScope;

$host = getenv('TYPE_REDIS_HOST') ?: '127.0.0.1';
$port = (int) (getenv('TYPE_REDIS_PORT') ?: 6379);
$admin = new Redis();
expect($admin->connect($host, $port), '无法连接受控 Redis');
$name = 'type_acl_' . bin2hex(random_bytes(8));
$password = bin2hex(random_bytes(24));
$key = $name . ':value';
$scope = new ExecutionScope();
$manager = new RedisManager(['authorized' => new RedisConfiguration($host, $port, 0, $password, $name),
    'rejected' => new RedisConfiguration($host, $port, 0, 'invalid-test-password', $name)]);
try {
    $admin->rawCommand('ACL', 'SETUSER', $name, 'on', '>' . $password, '~' . $name . ':*', '+@all', '-@dangerous');
    $connection = $manager->connection($scope, 'authorized');
    $connection->command('SET', [$key, '受限用户']);
    expect($connection->command('GET', [$key]) === '受限用户', '命名 ACL 认证没有生效');
    $rejected = false;
    try {
        $manager->connection($scope, 'rejected');
    } catch (RedisException $error) {
        $rejected = $error->outcome() === 'NOT_STARTED';
    }
    expect($rejected && $manager->statistics()['rejected:command']['created'] === 0, '认证失败仍保留连接或开始业务操作');
    $rejected = false;
    try {
        $connection->command('GET', ['other-namespace']);
    } catch (RedisException) {
        $rejected = true;
    }
    expect($rejected, 'ACL 限定被绕过');
    echo "Redis ACL、错误认证与失败容量归还验证通过。\n";
} finally {
    $scope->close();
    $manager->close();
    $admin->del($key);
    $admin->rawCommand('ACL', 'DELUSER', $name);
    $admin->close();
}
