<?php

declare(strict_types=1);

require(getenv('TYPE_TESTING_AUTOLOAD') ?: dirname(__DIR__) . '/vendor/autoload.php');

use Type\Testing\Assert;
use Type\Testing\AssertionFailed;
use Type\Testing\HttpClient;
use Type\Testing\Process;
use Type\Testing\Suite;

$suite = new Suite();
$suite->test('进程原样传参、退出码及双管道输出', static function (): void {
    $process = new Process([PHP_BINARY, '-r', 'echo $argv[1]; for ($i = 0; $i < 40; $i++) { fwrite(STDOUT, str_repeat("o", 8192)); fwrite(STDERR, str_repeat("e", 8192)); } exit(7);', '$(echo 不应执行)']);
    $result = $process->wait(5);
    Assert::same(7, $result->exitCode);
    Assert::same('$(echo 不应执行)' . str_repeat('o', 327680), $result->stdout);
    Assert::same(str_repeat('e', 327680), $result->stderr);
    Assert::true(!$result->successful());
    Assert::same($result, $process->wait());
});
$suite->test('输出超限与不合作进程截止', static function (): void {
    $process = new Process([PHP_BINARY, '-r', 'while (true) { echo str_repeat("x", 8192); }'], maximumBytes: 1024);
    $result = $process->wait(5);
    Assert::true($result->outputExceeded && strlen($result->stdout) + strlen($result->stderr) === 1024 && !$process->running());
    $started = microtime(true);
    $process = new Process([PHP_BINARY, '-r', 'pcntl_signal(SIGTERM, SIG_IGN); echo "ready"; while (true) { usleep(10000); }']);
    $result = $process->wait(0.1);
    Assert::true($result->timedOut && $result->signal === 9 && microtime(true) - $started < 1.5);
});
$suite->test('套件收集失败与严格类型断言', static function (): void {
    Assert::throws(static fn () => Assert::same(1, '1'), AssertionFailed::class);
    $inner = new Suite();
    $inner->test('失败', static fn () => Assert::true(false))->test('通过', static fn () => Assert::true(true));
    Assert::same(1, $inner->run());
    Assert::same(false, $inner->results()[0]['passed']);
    Assert::same(true, $inner->results()[1]['passed']);
});
$suite->test('真实 HTTP 分块、重复响应头与截断拒绝', static function (): void {
    foreach ([
        ["HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nSet-Cookie: a=1\r\nSet-Cookie: b=2\r\n\r\n7\r\n{\"a\":1}\r\n0\r\n\r\n", true],
        ["HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n7\r\n{\"a\":1}\r\n", false],
        ["HTTP/1.1 200 OK\r\nContent-Length: 10\r\n\r\nshort", false],
        ["HTTP/1.1 200 OK\r\nContent-Length: 1\r\nTransfer-Encoding: chunked\r\n\r\nx", false],
    ] as [$wire, $valid]) {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        Assert::true(is_resource($server));
        $address = stream_socket_get_name($server, false);
        $pid = pcntl_fork();
        Assert::true($pid !== -1);
        if ($pid === 0) {
            $client = stream_socket_accept($server, 2);
            if (!is_resource($client)) {
                exit(2);
            }
            $request = '';
            while (!str_contains($request, "\r\n\r\n")) {
                $request .= fread($client, 1024);
            }
            fwrite($client, $wire);
            fclose($client);
            fclose($server);
            exit(0);
        }
        fclose($server);
        try {
            $client = new HttpClient('http://' . $address);
            if ($valid) {
                $response = $client->request('GET', '/');
                Assert::same(['a' => 1], $response->json());
                Assert::same(['a=1', 'b=2'], $response->header('set-cookie'));
            } else {
                Assert::throws(static fn () => $client->request('GET', '/'), RuntimeException::class);
            }
        } finally {
            pcntl_waitpid($pid, $status);
            Assert::same(0, pcntl_wexitstatus($status));
        }
    }
    Assert::throws(static fn () => (new HttpClient('http://127.0.0.1'))->request('GET', '/', ['Content-Length' => '1']), InvalidArgumentException::class);
});
$exit = $suite->run();
foreach ($suite->results() as $result) {
    echo ($result['passed'] ? '通过' : '失败') . '：' . $result['name'] . ($result['passed'] ? '' : '，' . $result['message']) . "\n";
}
exit($exit);
