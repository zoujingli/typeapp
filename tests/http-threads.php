<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require __DIR__ . '/http-support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\BuildEnvironment;
use Type\Build\BuildPlatform;
use Type\Testing\HttpClient;
use Type\Testing\Process;

$root = dirname(__DIR__);
$work = BuildPlatform::path($argv[1] ?? '');
$verify = ($argv[2] ?? '') === '--verify';
expect($argc === ($verify ? 3 : 2) && BuildPlatform::contains($root . '/build', $work) && !str_contains($work, '..'), '需要 build 下独立消费者目录及可选 --verify');
$runner = new BuildEnvironment();
$environment = $runner->environment((string) getenv('PHP_HOME'), (string) getenv('PHPX_HOME'));
if (!$verify) {
    expect(!file_exists($work) && mkdir($work . '/app', 0700, true), '需要尚不存在的消费者目录');
    $composer = ['name' => 'type-tests/http-threads', 'type' => 'project', 'license' => 'Apache-2.0',
        'require' => ['zoujingli/type-core' => '~1.0.0@dev'],
        'require-dev' => ['zoujingli/type-build' => '~1.0.0@dev', 'swoole/typephp' => '0.9.3', 'swoole/phpx' => '2.9.2'],
        'repositories' => [], 'autoload' => ['classmap' => ['app']], 'minimum-stability' => 'dev', 'prefer-stable' => true,
        'config' => ['allow-plugins' => false]];
    $projectPath = str_repeat('../', substr_count(substr($work, strlen($root) + 1), '/') + 1);
    foreach (['type-core', 'type-runtime', 'type-build'] as $package) {
        $composer['repositories'][] = ['type' => 'path', 'url' => $projectPath . 'plugin/' . $package,
            'options' => ['symlink' => false, 'versions' => ['zoujingli/' . $package => '1.0.x-dev']]];
    }
    $configuration = ['name' => 'http-threads', 'entry' => 'app/main.php', 'sources' => ['app'],
        'output' => (new BuildPlatform())->output('build/native/type-app'), 'build-directory' => 'build/native/compiler',
        'threads' => ['http' => 'HttpThreadProbe::run'], 'runtime' => [PHP_OS_FAMILY => ['extensions' => ['swoole']]],
        'compiler' => ['debug' => true, 'jobs' => 2]];
    foreach (['composer.json' => $composer, 'type-app.json' => $configuration] as $name => $value) {
        file_put_contents($work . '/' . $name, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
    expect(copy($root . '/toolchain.lock.json', $work . '/toolchain.lock.json')
        && copy(__DIR__ . '/fixtures/compiled-http-threads.php', $work . '/app/main.php'), '无法复制独立消费者输入');
    foreach (['thread-exit-gate.cc', 'thread-exit-gate.stub.php'] as $fixture) {
        expect(copy(__DIR__ . '/fixtures/' . $fixture, $work . '/app/' . $fixture), '无法复制最终线程析构夹具');
    }
    $composerBinary = (string) (getenv('COMPOSER_BINARY') ?: trim(successful(['which', 'composer'])));
    expect(is_file($composerBinary), '需要真实 Composer 脚本');
    file_put_contents($work . '/install.log', $runner->run([PHP_BINARY, $composerBinary, 'install', '--no-interaction',
        '--no-scripts', '--no-plugins', '--no-progress'], $work, $environment + ['COMPOSER_HOME' => $work . '/composer-home'], 300));
    expect(!is_link($work . '/vendor/zoujingli/type-core') && !is_link($work . '/vendor/zoujingli/type-runtime'), '消费者不能使用主仓软链接');
    echo "HTTP 线程独立安装完成，开始全量 AOT。\n";
    file_put_contents($work . '/build.log', $runner->run([PHP_BINARY, $work . '/vendor/bin/type', $work . '/type-app.json'], $work, $environment, 900));
}
$binary = (new BuildPlatform())->output($work . '/build/native/type-app');
$report = json_decode(file_get_contents($binary . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
expect(hash_file('sha256', $binary) === $report['sha256'], '产物身份不符');
$packages = array_keys($report['production-packages']);
sort($packages);
expect($packages === ['psr/http-factory', 'psr/http-message', 'psr/http-server-handler', 'psr/http-server-middleware',
    'zoujingli/type-core', 'zoujingli/type-runtime'], '需要全量编译六个生产包');
foreach ($report['sources'] as $source) {
    expect(BuildPlatform::contains($work, $source), '消费者引用主仓生产输入');
}
expect(copy($report['runtime-profile']['ini'], $work . '/run.ini'), '无法保存实际运行配置');
expect(is_dir($work . '/php.d') || mkdir($work . '/php.d', 0700), '无法准备空扫描目录');
$environment['PHPRC'] = $work . '/run.ini';
$environment['PHP_INI_SCAN_DIR'] = $work . '/php.d';
$policy = [];
if (PHP_OS_FAMILY === 'Darwin') {
    $policy = ['sandbox-exec', '-f', __DIR__ . '/fixtures/mqtt-no-source.sb'];
    foreach (['ROOT_APP' => $root . '/app', 'ROOT_PLUGIN' => $root . '/plugin', 'ROOT_EXAMPLE' => $root . '/tests',
        'ROOT_VENDOR' => $root . '/vendor', 'APP' => $work . '/app', 'VENDOR' => $work . '/vendor',
        'COMPILER' => $work . '/build/native/compiler', 'ROOT_COMPOSER' => $root . '/composer.json', 'COMPOSER' => $work . '/composer.json'] as $key => $path) {
        array_push($policy, '-D', $key . '=' . $path);
    }
    $probe = 'foreach (array_slice($argv, 1) as $file) { if (@file_get_contents($file) !== false) throw new RuntimeException("source readable"); } echo "source-denied\n";';
    expect(successful([...$policy, PHP_BINARY, '-n', '-r', $probe, $work . '/app/main.php', $work . '/vendor/autoload.php',
        $root . '/plugin/type-core/src/Http/SwooleServer.php'], $work) === "source-denied\n", '源码禁读没有生效');
}
$runs = [];
for ($round = 0; $round < 3; $round++) {
    $directory = $work . '/run-' . bin2hex(random_bytes(5));
    expect(mkdir($directory, 0700), '无法准备独立运行目录');
    $server = new Process([...$policy, $binary, $directory], $work, $environment);
    $connections = [];
    try {
        foreach (['listener.json', 'ready-1-1.json', 'ready-2-1.json'] as $ready) {
            httpThreadWait($server, $directory . '/' . $ready);
        }
        $port = json_decode(file_get_contents($directory . '/listener.json'), true, flags: JSON_THROW_ON_ERROR)['port'];
        $client = new HttpClient('http://127.0.0.1:' . $port);
        $counts = [1 => 0, 2 => 0];
        for ($request = 0; $request < 100; $request++) {
            $response = $client->request('GET', '/normal', ['X-Test-Token' => (string) $request]);
            $data = $response->json();
            expect($response->status === 200 && $data['token'] === (string) $request && $data['port'] === $port, 'PSR 请求字段不符');
            $counts[$data['worker']]++;
        }
        $inputChecks = httpThreadInputs($port);
        $connection = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 2);
        stream_set_timeout($connection, 2);
        try {
            $identity = null;
            for ($request = 0; $request < 20; $request++) {
                fwrite($connection, "GET /child HTTP/1.1\r\nHost: localhost\r\nX-Test-Connection: keepalive\r\nX-Test-Token: " . $request . "\r\n\r\n");
                [$status, $body] = httpThreadRead($connection);
                $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                expect($status === 200 && $data['token'] === (string) $request, 'keep-alive 请求状态或清理串用');
                $identity ??= $data['thread'];
                expect($identity === $data['thread'], '同一连接迁移了线程');
            }
        } finally {
            fclose($connection);
        }
        $idle = [];
        $idleByWorker = [1 => 0, 2 => 0];
        try {
            for ($index = 0; $index < 16; $index++) {
                $socket = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 2);
                expect(is_resource($socket), '无法建立连接预算观察者');
                stream_set_timeout($socket, 2);
                $idle[] = $socket;
                fwrite($socket, "GET /normal HTTP/1.1\r\nHost: localhost\r\n\r\n");
                [$status, $body] = httpThreadRead($socket);
                expect($status === 200, '已分配连接没有进入 PSR 链');
                $idleByWorker[json_decode($body, true, flags: JSON_THROW_ON_ERROR)['worker']]++;
            }
            expect($idleByWorker === [1 => 8, 2 => 8], '每线程连接份额没有限制总额');
            foreach ([$idle[0], $idle[15]] as $socket) {
                fwrite($socket, "GET /child HTTP/1.1\r\nHost: localhost\r\n\r\n");
                expect(httpThreadRead($socket)[0] === 200, '空闲连接占用了受管子任务预留');
            }
            $extra = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 2);
            expect(is_resource($extra), '无法观察内核等待连接');
            $idle[] = $extra;
            stream_set_blocking($extra, false);
            fwrite($extra, "GET /normal HTTP/1.1\r\nHost: localhost\r\n\r\n");
            usleep(50000);
            expect(fread($extra, 1024) === '' && !feof($extra), '满额仍继续接受新连接');
            fclose($idle[0]);
            unset($idle[0]);
            stream_set_blocking($extra, true);
            stream_set_timeout($extra, 2);
            expect(httpThreadRead($extra)[0] === 200, '连接释放后准入没有恢复');
        } finally {
            foreach ($idle as $socket) {
                fclose($socket);
            }
        }
        foreach (['/capacity' => [503, 'resource_capacity_exceeded'], '/deadline' => [504, 'deadline_exceeded'], '/fail' => [500, 'internal_error']] as $path => [$status, $code]) {
            $response = $client->request('GET', $path);
            expect($response->status === $status && $response->json()['error'] === $code && !str_contains($response->body, 'private-'), '错误映射不符');
            if ($path === '/capacity') {
                expect($response->header('Retry-After') === ['1'], '容量拒绝没有重试提示');
            }
            expect($client->request('GET', '/normal')->status === 200, '错误后无法恢复');
        }
        expect($client->request('HEAD', '/normal')->body === '', 'HEAD 含响应体');
        $post = $client->request('POST', '/post', ['Content-Type' => 'text/plain'], 'actual-body');
        expect($post->status === 200 && $post->json()['body'] === 'actual-body', 'PSR 输入正文丢失');
        $connections = [];
        $accepted = 0;
        $rejected = 0;
        for ($request = 0; $request < 40; $request++) {
            $connections[] = sendHttp($port, 'GET', '/slow');
        }
        foreach ($connections as $pending) {
            [$status] = receiveHttp($pending);
            expect(in_array($status, [200, 503], true), '请求额度产生错误结果');
            $status === 200 ? $accepted++ : $rejected++;
        }
        expect($accepted > 0 && $rejected > 0, '请求额度没有真实拒绝');
        file_put_contents($directory . '/retire', 'retire');
        httpThreadWait($server, $directory . '/retired');
        for ($request = 0; $request < 30; $request++) {
            expect($client->request('GET', '/normal')->json()['worker'] === 2, '退役副本影响了存活监听');
        }
        file_put_contents($directory . '/replace', 'replace');
        httpThreadWait($server, $directory . '/ready-1-2.json');
        $replacement = [1 => 0, 2 => 0];
        for ($request = 0; $request < 100; $request++) {
            $data = $client->request('GET', '/normal')->json();
            expect($data['generation'] === ($data['worker'] === 1 ? 2 : 1), '旧代请求状态串入重建线程');
            $replacement[$data['worker']]++;
        }
        $replacementConnections = httpThreadObserveWorkers($port, [1 => 2, 2 => 1]);
        file_put_contents($directory . '/stop', 'stop');
        $result = $server->wait(3.0);
        expect($result->successful() && $result->stderr === '', 'HTTP 线程没有正常退出');
        $final = json_decode(trim($result->stdout), true, flags: JSON_THROW_ON_ERROR);
        expect($final['exits'] === [0, 0, 0] && $final['active_threads'] === 1 && $final['contracts'] === 26
            && $final['join_checks'] === 6, '线程没有完整 join 或原生契约不符');
        $statistics = [];
        foreach (['1-1', '2-1', '1-2'] as $worker) {
            $state = json_decode(file_get_contents($directory . '/stopped-' . $worker . '.json'), true, flags: JSON_THROW_ON_ERROR);
            expect($state['in_flight'] === 0 && $state['peak_in_flight'] <= 2 && $state['cleanup_failures'] === 0, '请求额度或清理不守恒');
            $statistics[$worker] = $state;
        }
        $released = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error);
        expect(is_resource($released), '监听端口没有释放');
        fclose($released);
        $runs[] = compact('counts', 'replacement', 'replacementConnections', 'accepted', 'rejected', 'statistics', 'final', 'idleByWorker', 'inputChecks');
    } finally {
        foreach ($connections as $pending) {
            if (is_resource($pending)) {
                fclose($pending);
            }
        }
        $stopped = $server->stop();
        file_put_contents($directory . '/stdout.log', $stopped->stdout);
        file_put_contents($directory . '/stderr.log', $stopped->stderr);
        file_put_contents($directory . '/exit.json', json_encode(['exit' => $stopped->exitCode, 'signal' => $stopped->signal,
            'timed_out' => $stopped->timedOut], JSON_THROW_ON_ERROR));
    }
}
$supervision = [];
for ($round = 0; $round < 3; $round++) {
    foreach (['supervised-http', 'supervised-signal', 'partial', 'exit', 'startup-stall', 'stall', 'exit-gate'] as $mode) {
        if ($mode === 'supervised-signal' && PHP_OS_FAMILY === 'Windows') {
            continue; // Windows 控制台事件由该目标实际宿主验收，不伪造 POSIX 信号。
        }
        $directory = $work . '/supervised-' . $mode . '-' . bin2hex(random_bytes(5));
        expect(mkdir($directory, 0700), '无法准备监督验收目录');
        $started = hrtime(true);
        $server = new Process([...$policy, $binary, $directory, $mode], $work, $environment);
        $pending = null;
        $port = null;
        try {
            if (str_starts_with($mode, 'supervised-')) {
                foreach (['listener.json', 'ready-1-1.json', 'ready-2-1.json'] as $ready) {
                    httpThreadWait($server, $directory . '/' . $ready);
                }
                $port = json_decode(file_get_contents($directory . '/listener.json'), true, flags: JSON_THROW_ON_ERROR)['port'];
                $client = new HttpClient('http://127.0.0.1:' . $port);
                expect($client->request('GET', '/child')->status === 200, '监督线程无法完成受管子任务');
                $pending = sendHttp($port, 'GET', '/slow');
                httpThreadWait($server, $directory . '/in-flight');
                if ($mode === 'supervised-signal') {
                    expect(posix_kill($server->pid(), SIGTERM), '无法向实际主控发送停止信号');
                } else {
                    file_put_contents($directory . '/stop', 'stop');
                }
                [$status] = receiveHttp($pending);
                $pending = null;
                expect($status === 200, '停止丢失了已进入业务的响应');
            } elseif ($mode === 'stall') {
                httpThreadWait($server, $directory . '/block-entered');
                httpThreadWait($server, $directory . '/probe-progress-1');
                $progress = (int) file_get_contents($directory . '/probe-progress-1');
                usleep(70000);
                expect((int) file_get_contents($directory . '/probe-progress-1') > $progress, '另一业务线程没有在真实阻塞期间前进');
            }
            $result = $server->wait(3.0);
            $elapsed = (hrtime(true) - $started) / 1000000000.0;
            expect(!$result->timedOut && $result->signal === null && $result->stderr === '', '监督退出超时、收到意外信号或产生错误输出：' . $result->stderr);
            $final = [];
            if (in_array($mode, ['startup-stall', 'stall', 'exit-gate'], true)) {
                expect($result->exitCode === 75 && $elapsed < 3.0 && $result->stdout === '', '主控没有在期限内原生结束不可回收角色');
                if ($mode === 'exit-gate') {
                    expect(is_file($directory . '/gate-entered') && !is_file($directory . '/gate-release'), '未在最终 TLS 析构期间验证回收期限');
                }
                expect(is_file($directory . '/probe-stopped-1'), '主控没有先请求健康线程停止');
            } else {
                expect($result->successful(), '可回收线程组没有正常结束');
                $final = json_decode(trim($result->stdout), true, flags: JSON_THROW_ON_ERROR);
                expect($final['active_threads'] === 1 && $final['signals_restored'] && $final['statistics'] === ['state' => 'stopped', 'owned' => 0, 'ready' => 0,
                    'joined' => $mode === 'partial' ? 1 : 2], '监督没有保留并完整回收全部已启动句柄');
                if ($mode === 'partial' || $mode === 'exit') {
                    expect($final['error'] === ($mode === 'partial' ? 'invalid_startup_json' : 'thread_exit_unexpected')
                        && is_file($directory . '/probe-stopped-1'), '部分启动或异常退出没有停止其余线程');
                } else {
                    expect($final['exits'] === [0, 0], '正常停止出现线程失败');
                    $stopping = json_decode(file_get_contents($directory . '/supervisor-stopping.json'), true, flags: JSON_THROW_ON_ERROR);
                    expect($stopping['ready'] === 0, '停止没有撤销组就绪');
                }
            }
            if ($port !== null) {
                $released = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error);
                expect(is_resource($released), '监督停止后原监听端口没有释放');
                fclose($released);
            }
            $supervision[] = ['round' => $round, 'mode' => $mode, 'exit' => $result->exitCode, 'seconds' => $elapsed, 'final' => $final];
        } finally {
            if (is_resource($pending)) {
                fclose($pending);
            }
            $stopped = $server->stop();
            file_put_contents($directory . '/stdout.log', $stopped->stdout);
            file_put_contents($directory . '/stderr.log', $stopped->stderr);
            file_put_contents($directory . '/exit.json', json_encode(['exit' => $stopped->exitCode, 'signal' => $stopped->signal,
                'timed_out' => $stopped->timedOut], JSON_THROW_ON_ERROR));
        }
    }
}
file_put_contents($work . '/http-thread-evidence.json', json_encode(['build-id' => $report['build-id'], 'sha256' => $report['sha256'],
    'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'source-denied' => $policy !== [], 'runs' => $runs,
    'supervision' => $supervision], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
echo "无源码双线程 HTTP、逐请求清理、请求额度、退役和重建通过。\n";
echo "生产监督的正常停止、信号、部分启动、异常退出与原生阻塞回收通过。\n";

/** 保持满额连接证明两个监听副本实际接收，不依赖内核对短连接的公平调度。 */
function httpThreadObserveWorkers(int $port, array $generations): array
{
    $connections = [];
    $counts = [1 => 0, 2 => 0];
    try {
        for ($index = 0; $index < 16; $index++) {
            $socket = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 2);
            expect(is_resource($socket), '无法建立重建线程观察连接');
            $connections[] = $socket;
            stream_set_timeout($socket, 2);
            fwrite($socket, "GET /normal HTTP/1.1\r\nHost: localhost\r\n\r\n");
            [$status, $body] = httpThreadRead($socket);
            $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            expect($status === 200 && $data['generation'] === $generations[$data['worker']], '共享监听没有进入预期线程代次');
            $counts[$data['worker']]++;
        }
        expect($counts === [1 => 8, 2 => 8], '重建没有恢复两个有界监听副本');
        return $counts;
    } finally {
        foreach ($connections as $socket) {
            fclose($socket);
        }
    }
}

/** 真实 TCP 报文观察原生解析到路由、授权的边界；不复用生产解析器。 */
function httpThreadInputs(int $port): int
{
    $checks = 0;
    $cases = [
        'duplicate-host' => ["GET /normal HTTP/1.1\r\nHost: evil.example\r\nhOsT: localhost\r\n", '', 400, null],
        'duplicate-auth' => ["GET /secure HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer invalid\r\nauthorization: Bearer reader-one\r\n", '', 400, null],
        'duplicate-type' => ["POST /post HTTP/1.1\r\nHost: localhost\r\nContent-Length: 3\r\nContent-Type: text/plain\r\ncontent-type: application/x-www-form-urlencoded\r\n", 'a=1', 400, null],
        'duplicate-length' => ["POST /post HTTP/1.1\r\nHost: localhost\r\nContent-Length: 0\r\nContent-Length: 0\r\n", '', 400, null],
        'length-and-transfer' => ["POST /post HTTP/1.1\r\nHost: localhost\r\nContent-Length: 0\r\nTransfer-Encoding: chunked\r\n", "0\r\n\r\n", 400, null],
        'missing-host' => ["GET /normal HTTP/1.1\r\n", '', 400, 'invalid_host'],
        'untrusted-host' => ["GET /normal HTTP/1.1\r\nHost: evil.example\r\n", '', 400, 'untrusted_host'],
        'duplicate-cookie' => ["GET /normal HTTP/1.1\r\nHost: localhost\r\nCookie: a=1; a=2\r\n", '', 400, 'invalid_cookie'],
        'duplicate-cookie-lines' => ["GET /normal HTTP/1.1\r\nHost: localhost\r\nCookie: a=1\r\nCookie: a=2\r\n", '', 400, 'invalid_cookie'],
        'cookie-limit' => ["GET /normal HTTP/1.1\r\nHost: localhost\r\nCookie: a=1; b=2; c=3; d=4\r\n", '', 413, 'too_many_cookies'],
        'query-duplicate' => ["GET /normal?a=1&a=2 HTTP/1.1\r\nHost: localhost\r\n", '', 400, 'invalid_query'],
        'ambiguous-path' => ["GET /a%2fb HTTP/1.1\r\nHost: localhost\r\n", '', 400, 'ambiguous_path'],
        'unknown-route' => ["GET /unknown HTTP/1.1\r\nHost: localhost\r\n", '', 404, 'not_found'],
        'http2-preface' => ["PRI * HTTP/2.0\r\n", "SM\r\n\r\n", 505, null],
    ];
    foreach ($cases as $name => [$headers, $content, $expected, $code]) {
        $socket = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 2);
        expect(is_resource($socket), '无法建立输入测试连接');
        stream_set_timeout($socket, 2);
        $wire = $name === 'http2-preface' ? $headers . "\r\n" . $content : $headers . "Connection: close\r\n\r\n" . $content;
        expect(fwrite($socket, $wire) === strlen($wire), '输入测试帧没有完整写入');
        [$status, $body] = receiveHttp($socket);
        expect($status === $expected, $name . ' 状态不符：' . $status);
        if ($code !== null) {
            expect(json_decode($body, true, flags: JSON_THROW_ON_ERROR)['error'] === $code, $name . ' 错误码不符');
        }
        $checks++;
    }
    foreach (['1.0', '1.1'] as $version) {
        $socket = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 2);
        stream_set_timeout($socket, 2);
        fwrite($socket, 'GET /normal HTTP/' . $version . "\r\nHost: localhost\r\nConnection: close\r\nX-Values: first\r\nX-Values: second\r\nAccept: text/plain\r\nAccept: application/json\r\nCookie: a=0\r\nCookie: b=test%20value\r\n\r\n");
        [$status, $body] = receiveHttp($socket);
        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        expect($status === 200 && $data['version'] === $version
            && $data['headers']['x-values'] === ['first', 'second']
            && $data['headers']['accept'] === ['text/plain', 'application/json']
            && $data['cookies'] === ['a' => '0', 'b' => 'test value'], 'PSR 多值头、版本或 Cookie 丢失');
        $checks++;
    }
    $socket = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 2);
    stream_set_timeout($socket, 2);
    try {
        foreach (['reader-one' => 200, '' => 401, 'blocked' => 403, 'reader-two' => 200, 'invalid' => 401] as $token => $expected) {
            fwrite($socket, "GET /secure HTTP/1.1\r\nHost: localhost\r\nX-Test-Connection: authenticated\r\n"
                . ($token === '' ? '' : 'Authorization: Bearer ' . $token . "\r\n") . "\r\n");
            [$status, $body] = httpThreadRead($socket);
            $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            expect($status === $expected && ($status !== 200 || $data['identity'] === $token), 'keep-alive 授权或身份串用');
            $checks++;
        }
    } finally {
        fclose($socket);
    }
    return $checks;
}

/** 在 5 秒轮询预算内等待线程阶段写入非空哨兵文件，提前退出或超时均失败。 */
function httpThreadWait(Process $process, string $file): void
{
    $deadline = microtime(true) + 5;
    do {
        clearstatcache(true, $file);
        if (is_file($file) && filesize($file) > 0) {
            return;
        }
        expect($process->running(), '服务提前退出：' . $process->stdout() . $process->stderr());
        usleep(10000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('线程阶段没有在预算内完成：' . basename($file));
}

/** 仅供同一测试连接的有界 HTTP/1.1 观察；其余请求复用 type-testing。 */
function httpThreadRead(mixed $connection): array
{
    $headers = '';
    do {
        $line = fgets($connection, 8192);
        expect(is_string($line) && strlen($headers) < 16384, 'keep-alive 响应头不完整或超量');
        $headers .= $line;
    } while ($line !== "\r\n");
    expect(preg_match('/^HTTP\/1\.1 ([0-9]{3})/', $headers, $status) === 1, '响应状态无效');
    $body = '';
    if (str_contains(strtolower($headers), 'transfer-encoding: chunked')) {
        while (true) {
            $line = fgets($connection, 64);
            expect(is_string($line) && preg_match('/^[a-f0-9]+\r\n$/iD', $line) === 1, '分块长度无效');
            $length = hexdec(trim($line));
            expect($length <= 8192 && strlen($body) + $length <= 16384, '响应正文超量');
            $chunk = '';
            while (strlen($chunk) < $length + 2) {
                $bytes = fread($connection, $length + 2 - strlen($chunk));
                expect(is_string($bytes) && $bytes !== '', '响应块提前结束');
                $chunk .= $bytes;
            }
            expect(str_ends_with($chunk, "\r\n"), '响应块分隔不符');
            if ($length === 0) {
                break;
            }
            $body .= substr($chunk, 0, $length);
        }
    } else {
        expect(preg_match('/\r\nContent-Length: ([0-9]+)\r\n/i', $headers, $size) === 1 && (int) $size[1] <= 16384, '响应长度缺失或超量');
        while (strlen($body) < (int) $size[1]) {
            $bytes = fread($connection, (int) $size[1] - strlen($body));
            expect(is_string($bytes) && $bytes !== '', '响应正文提前结束');
            $body .= $bytes;
        }
    }
    return [(int) $status[1], $body];
}
