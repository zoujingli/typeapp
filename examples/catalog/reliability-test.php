<?php

declare(strict_types=1);

$root = getenv('TYPE_APP_TEST_DIRECTORY') ?: dirname(__DIR__);
require $root . '/vendor/autoload.php';

use Type\Testing\Assert;
use Type\Testing\Process;
use Type\Testing\Suite;

$command = getenv('TYPE_APP_BINARY') ? [getenv('TYPE_APP_BINARY')] : [PHP_BINARY, $root . '/dev.php'];
if (getenv('TYPE_APP_COMMAND')) {
    $command = json_decode(getenv('TYPE_APP_COMMAND'), true, 512, JSON_THROW_ON_ERROR);
}
Assert::true(is_array($command) && array_is_list($command) && $command !== []);
$environment = getenv();
$data = getenv('APP_BASE_PATH') ?: $root;
$work = $data . '/var/catalog-reliability-' . bin2hex(random_bytes(6));
mkdir($work, 0700, true);
$namespace = 'catalog-test-' . bin2hex(random_bytes(12));
$environment['CATALOG_NAMESPACE'] = $namespace;
$environment['CATALOG_CURSOR'] = $work . '/schedule.json';
$environment['CATALOG_CLOCK'] = '1800000000';
$run = static function (string $mode, array $extra = []) use ($root, $command, $environment): array {
    $process = new Process([...$command, 'catalog', $mode], $root, array_replace($environment, $extra), 1048576);
    try {
        $result = $process->wait(15);
        Assert::true($result->successful(), $mode . '：' . $result->stderr . $result->stdout);
        $lines = array_values(array_filter(explode("\n", trim($result->stdout)), static fn (string $line): bool => $line !== ''));
        $json = array_pop($lines);
        foreach ($lines as $diagnostic) {
            Assert::true($mode === 'https-host' && str_contains($diagnostic, 'Socket::ssl_check_host()')
                && str_contains($diagnostic, 'no match'), '命令出现未声明诊断：' . $diagnostic);
        }
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException($mode . ' 输出不是JSON：' . $result->stdout . $result->stderr, 0, $error);
        }
    } finally {
        $process->stop();
    }
};
$suite = new Suite();
$suite->test('离线检查完整装配且不连接外部服务或输出秘密', static function () use ($root, $environment): void {
    $offline = array_replace($environment, ['TYPE_REDIS_HOST' => 'invalid.invalid', 'TYPE_REDIS_PORT' => '1',
        'DB_HOST' => 'invalid.invalid', 'DB_PASSWORD' => 'catalog-offline-secret-sentinel']);
    foreach ([[], ['--json']] as $options) {
        $process = new Process([PHP_BINARY, $root . '/vendor/bin/type', 'inspect-application', $root . '/type-app.json', ...$options], sys_get_temp_dir(), $offline);
        try {
            $result = $process->wait(15);
            Assert::true($result->successful(), $result->stderr);
            Assert::true(!str_contains($result->stdout, 'catalog-offline-secret-sentinel'));
            if ($options !== []) {
                $report = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
                Assert::same('valid', $report['status']);
                Assert::true(count($report['routes']) > 5 && count($report['models']) >= 4);
                Assert::true(count($report['assembly']['events']) === 1 && count($report['jobs']) === 1 && count($report['schedules']) === 1);
                Assert::true(isset($report['assembly']['services']['catalog.infrastructure']));
            }
        } finally {
            $process->stop();
        }
    }
});
$suite->test('原Service缓存、同源事务、同步事件和Outbox重复消费收敛', static function () use ($run): void {
    $cache = $run('cache');
    Assert::same(7, $cache['loads']);
    Assert::same([true], $cache['sync']);
    Assert::same([false], $cache['after_commit']);
    Assert::true($cache['null_cached'] && !$cache['failure_cached'] && $cache['rollback_discarded']);
    $failed = $run('relay-fail');
    Assert::same('catalog_accepted_without_receipt', $failed['error']);
    Assert::same(false, $failed['retry']);
    $unknown = $run('status');
    Assert::same(false, $unknown['accepted']);
    Assert::same(false, $unknown['consumed']);
    // 明确等待本演练250ms领取租约结束，再由下一次独立命令重新领取。
    usleep(400000);
    Assert::same(1, $run('relay')['accepted']);
    Assert::same(0, $run('relay')['accepted']);
    $consumed = $run('consume');
    Assert::same(2, $consumed['processed']);
    Assert::same(0, $consumed['remaining']);
    Assert::same(1, $consumed['effects']);
    $settled = $run('status');
    Assert::true($settled['accepted'] && $settled['consumed']);
    Assert::same(1, $settled['effects']);
    Assert::same(0, $run('consume')['processed']);
});
$suite->test('持久计划游标跨进程恢复且正常停止', static function () use ($run): void {
    $first = $run('schedule')['records'];
    Assert::same(1, count($first));
    Assert::same('succeeded', $first[0]['state'], json_encode($first, JSON_THROW_ON_ERROR));
    Assert::true($first[0]['result']['products'] > 0);
    Assert::same([], $run('schedule')['records']);
});
$suite->test('生成Job的异常、有限重试、显式取消与清理后确认', static function () use ($run): void {
    foreach (['queue-invalid', 'queue-cancel', 'queue-cleanup'] as $mode) {
        $result = $run($mode);
        Assert::same($mode === 'queue-invalid' ? [1, 2, 3] : [1, 2], array_column($result['observations'], 'attempt'));
        foreach ($result['observations'] as $observation) {
            Assert::same($result['message'], $observation['message']);
            Assert::same('app\\catalog\\job\\DeliverProduct', $observation['role']);
            Assert::same(1, $observation['starts']);
            Assert::same('closed', $observation['scope']);
            Assert::true($observation['closed']);
            Assert::same(0, $observation['before-close']['database-leases']);
            Assert::same(1, $observation['before-close']['queue']['messages']);
            Assert::same(1, $observation['before-close']['queue']['leased']);
            Assert::same(0, $observation['before-close']['queue']['delayed']);
        }
        Assert::same('stopped', $result['stopped']['state']);
        Assert::same(0, $result['stopped']['in_flight']);
        Assert::same(0, $result['queue']['messages']);
        Assert::same(0, $result['queue']['leased']);
        Assert::same(0, $result['queue']['delayed']);
        if ($mode === 'queue-invalid') {
            Assert::same(3, $result['worker']['failed']);
            Assert::same(2, $result['worker']['retried']);
            Assert::same(1, $result['worker']['quarantined']);
            Assert::same(0, $result['worker']['completed']);
            Assert::same(0, $result['effects']);
            Assert::same([['attempt' => 3, 'reason' => 'attempts_exhausted:Type\\Queue\\QueueException']], $result['quarantine']);
        } elseif ($mode === 'queue-cancel') {
            Assert::same(0, $result['first']['effects']);
            Assert::same(0, $result['first']['worker']['completed']);
            Assert::same(1, $result['first']['worker']['failed']);
            Assert::same(1, $result['first']['worker']['retried']);
            Assert::same(1, $result['first']['queue']['delayed']);
            Assert::same(1, $result['worker']['completed']);
            Assert::same(1, $result['effects']);
            Assert::same([], $result['quarantine']);
        } else {
            Assert::same('cleanup_incomplete', $result['error']);
            Assert::same('closing', $result['pending-scope']);
            Assert::same('draining', $result['first']['worker']['state']);
            Assert::same(1, $result['first']['worker']['in_flight']);
            Assert::same(1, $result['first']['worker']['cleanup_failures']);
            foreach (['completed', 'retried', 'quarantined', 'storage_failures'] as $counter) {
                Assert::same(0, $result['first']['worker'][$counter]);
            }
            Assert::same(1, $result['first']['queue']['messages']);
            Assert::same(1, $result['first']['queue']['leased']);
            Assert::same(0, $result['first']['queue']['delayed']);
            Assert::same('stopped', $result['after-cleanup']['state']);
            Assert::same(0, $result['after-cleanup']['in_flight']);
            Assert::same(2, $result['observations'][0]['stops']);
            Assert::same(1, $result['recovery']['completed']);
            Assert::same(1, $result['first']['effects']);
            Assert::same(1, $result['effects']);
        }
    }
});
$suite->test('生成Task的异常、显式取消、未完收尾及持久游标', static function () use ($run, $work): void {
    foreach (['schedule-failure', 'schedule-cancel', 'schedule-cleanup'] as $mode) {
        $options = ['CATALOG_CURSOR' => $work . '/' . $mode . '.json'];
        $result = $run($mode, $options);
        Assert::same(1, count($result['records']));
        $record = $result['records'][0];
        Assert::same('failed', $record['state']);
        $observation = $result['observations'][0];
        Assert::same($record['occurrence_id'], $observation['occurrence']);
        Assert::same('app\\catalog\\task\\CatalogTask', $observation['role']);
        Assert::same(1, $observation['starts']);
        Assert::same('closed', $observation['scope']);
        Assert::true($observation['closed']);
        Assert::same(0, $observation['before-close']['database-leases']);
        Assert::same('running', $observation['before-close']['records'][0]['state']);
        Assert::same(null, $observation['before-close']['records'][0]['finished_at']);
        if ($mode === 'schedule-cleanup') {
            Assert::same(null, $record['finished_at']);
            Assert::true($record['result']['products'] > 0);
            Assert::true(str_contains($record['cleanup_error']['message'], 'catalog_work_cleanup_pending'));
            Assert::same(1, $result['before-recovery']['in_flight']);
            Assert::same('draining', $result['before-recovery']['state']);
            Assert::same(1, $result['before-recovery']['cleanup_failures']);
            Assert::same(2, $observation['stops']);
        } else {
            Assert::same(null, $record['result']);
            Assert::same(1800000000, $record['finished_at']);
            Assert::same($mode === 'schedule-cancel' ? Type\Runtime\TaskException::class : Type\Orm\ModelException::class, $record['error']['type']);
            Assert::same(null, $record['cleanup_error']);
        }
        Assert::same('stopped', $result['stopped']['state']);
        Assert::same(0, $result['after-cleanup']['in_flight']);
        Assert::same([], $run('schedule', $options)['records'], '重启后不应隐式重试同一计划');
        $later = $run('schedule', $options + ['CATALOG_CLOCK' => '1800000060'])['records'];
        Assert::same(1, count($later));
        Assert::same('succeeded', $later[0]['state']);
        Assert::true($later[0]['occurrence_id'] !== $record['occurrence_id']);
        Assert::true($later[0]['result']['products'] > 0);
    }
});
$suite->test('受管Swoole HTTPS校验、截止、取消与正文关闭', static function () use ($root, $work, $environment, $run): void {
    $certificate = $work . '/certificate.pem';
    $key = $work . '/key.pem';
    $openssl = new Process([getenv('OPENSSL_BINARY') ?: 'openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
        '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost', '-keyout', $key, '-out', $certificate], $work);
    try {
        Assert::true($openssl->wait(15)->successful(), '测试TLS证书创建失败');
    } finally {
        $openssl->stop();
    }
    $peer = new Process([getenv('NODE_BINARY') ?: 'node', $root . '/tests/catalog-https-peer.mjs', $certificate, $key], $work, $environment);
    try {
        $deadline = microtime(true) + 5;
        do {
            $line = $peer->stdout();
            if (str_contains($line, "\n") || !$peer->running()) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $ready = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
        $url = 'https://127.0.0.1:' . $ready['port'];
        $tls = ['CATALOG_HTTPS_URL' => $url, 'CATALOG_HTTPS_CA' => $certificate];
        Assert::same(['status' => 200, 'body' => 'catalog-https-ok'], $run('https', $tls));
        Assert::true(isset($run('https-host', $tls)['error']), '错误TLS主机名被接受');
        $slow = array_replace($tls, ['CATALOG_HTTPS_URL' => $url . '/slow']);
        Assert::same('http_client_timeout', $run('https-timeout', $slow)['error']);
        Assert::same('cancelled', $run('https-cancel', $slow)['error']);
    } finally {
        $stopped = $peer->stop(5);
        Assert::true($stopped->signal !== 9, 'TLS对端未正常停止');
    }
});
$status = 1;
try {
    $status = $suite->run();
    foreach ($suite->results() as $result) {
        echo ($result['passed'] ? '通过' : '失败') . '：' . $result['name'] . ($result['passed'] ? '' : '，' . $result['message']) . "\n";
    }
} finally {
    // 精确清理本轮隔离命名空间，永不 FLUSHDB 或清理其他应用键。
    $redis = new Redis();
    try {
        $redis->connect(getenv('TYPE_REDIS_HOST') ?: '127.0.0.1', (int) (getenv('TYPE_REDIS_PORT') ?: 6379), 2);
        $patterns = ['type:queue:{' . hash('sha256', $namespace . "\0catalog") . '}*',
            'type:cache:{' . hash('sha256', json_encode([$namespace, 'tutorial', 'json-data-v1'], JSON_THROW_ON_ERROR)) . '}*'];
        foreach ($patterns as $pattern) {
            foreach ($redis->keys($pattern) as $redisKey) {
                $redis->del($redisKey);
            }
        }
    } finally {
        $redis->close();
        foreach (glob($work . '/*') as $file) {
            unlink($file);
        }
        rmdir($work);
    }
}
exit($status);
