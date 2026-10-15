<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Type\Testing\Assert;
use Type\Testing\HttpClient;
use Type\Testing\Process;

// 仅复演已保全的原程序；不生成候选、修改 SDK 或把诊断当作发布验收。
Assert::true(PHP_OS_FAMILY === 'Windows' && getenv('GITHUB_ACTIONS') === 'true');
$root = dirname(__DIR__, 2);
$original = $root . '/build/original-tutorial/tutorial-delivery/sqlite/artifacts';
$program = $original . '/build/type-project.exe';
$digest = hash_file('sha256', $program);
$preserved = json_decode(file_get_contents($original . '/preservation.json'), true, 512, JSON_THROW_ON_ERROR);
Assert::same(getenv('TYPE_PROBE_PROGRAM_SHA256'), $digest, '原程序摘要不符');
Assert::same($digest, $preserved['files']['build/type-project.exe']['sha256']);
$fixed = getenv('TYPE_PROBE_FIXED') === '1';
if ($fixed) {
    $verification = json_decode(file_get_contents($root . '/build/tutorial-fixed/verification.json'), true, 512, JSON_THROW_ON_ERROR);
    Assert::same('passed', $verification['status']);
    Assert::same(getenv('GITHUB_SHA'), $verification['source']);
    $program = $root . '/build/tutorial-fixed/sqlite/app.exe';
    Assert::same(hash_file('sha256', $program), $verification['profiles']['sqlite']['program']['sha256']);
}
$base = $root . '/build/windows-tutorial-diagnosis';
Assert::true(!file_exists($base) && mkdir($base, 0700, true));
$work = $root . '/build/tutorial-stop-' . bin2hex(random_bytes(6));
Assert::true(mkdir($work . '/var', 0700, true));
$environment = array_replace(getenv(), [
    'APP_BASE_PATH' => $work, 'APP_ENV' => 'prod', 'APP_DEBUG' => 'false',
    'APP_LISTEN' => '127.0.0.1', 'DB_DRIVER' => 'sqlite',
    'DB_SQLITE_FILE' => $work . '/var/app.sqlite',
    'APP_API_TOKEN' => str_repeat('tutorial-stop-test-', 4),
]);
unset($environment['GH_TOKEN'], $environment['GITHUB_TOKEN']);
$report = ['source' => getenv('TYPE_PROBE_SOURCE'), 'run' => getenv('TYPE_PROBE_RUN'),
    'sha256' => $digest, 'scope' => 'original tutorial stop diagnosis only', 'cases' => []];
if ($fixed) {
    $report['fixed'] = ['source' => getenv('GITHUB_SHA'), 'sha256' => hash_file('sha256', $program), 'scope' => 'recompiled diagnostic only'];
}

/** 一次新服务只执行指定请求，比较真实 CTRL_BREAK 后的退出；调试器仅观察崩溃栈。 */
function probeTutorialStop(string $name, array $requests, bool $trace, string $program, string $work, string $base, array $environment, string $test): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    Assert::true(is_resource($socket), '无法选择诊断端口');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $environment['APP_PORT'] = substr(strrchr($address, ':'), 1);
    $environment['APP_ALLOWED_HOSTS'] = $address;
    $server = new Process([$program, 'serve'], $work, $environment);
    $debugger = null;
    $observed = [];
    try {
        $client = new HttpClient('http://' . $address, 5);
        $deadline = microtime(true) + 10;
        $ready = false;
        do {
            try {
                $ready = $client->request('GET', '/readyz')->status === 200;
            } catch (RuntimeException) {
            }
            if ($ready || !$server->running()) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        Assert::true($ready, '原程序未就绪：' . $server->stderr());
        if ($trace) {
            $cdb = getenv('TYPE_PROBE_CDB');
            $lldb = getenv('TYPE_PROBE_LLDB');
            $command = $cdb !== false
                ? [$cdb, '-G', '-p', (string) $server->pid(), '-c', 'sxe -c "gn" 0x40010008;sxe -c ".echo [tutorial-native-crash];.exr -1;kv;lm;u @rip-20 L30;q" av;g']
                : [$lldb, '--batch', '-p', (string) $server->pid(), '-o', 'continue', '-k', 'thread backtrace all', '-k', 'register read', '-k', 'disassemble --pc --count 24', '-k', 'image list', '-k', 'quit'];
            $debugger = new Process($command, $work, $environment);
            // 附加为诊断步骤，短暂等待不参与业务时限或发布验收。
            usleep(1000000);
        }
        $headers = ['Authorization' => 'Bearer ' . $environment['APP_API_TOKEN'], 'Content-Type' => 'application/json', 'X-Tenant' => 'catalog-a'];
        foreach ($requests as [$method, $path, $body]) {
            $response = $client->request($method, $path, $headers, $body);
            $observed[] = ['method' => $method, 'path' => $path, 'status' => $response->status, 'body' => $response->body];
        }
        if (str_starts_with($name, 'concurrent')) {
            $environment['TYPE_CATALOG_URL'] = 'http://' . $address;
            $environment['TYPE_APP_TEST_DIRECTORY'] = dirname(__DIR__, 2);
            $workers = [new Process([PHP_BINARY, $test, '--ensure-request'], $work, $environment), new Process([PHP_BINARY, $test, '--ensure-request'], $work, $environment)];
            try {
                foreach ($workers as $worker) {
                    $reply = $worker->wait(10);
                    Assert::true($reply->successful(), '并发请求失败：' . $reply->stderr);
                    $observed[] = ['concurrent' => true, 'body' => $reply->stdout];
                }
            } finally {
                foreach ($workers as $worker) {
                    $worker->stop();
                }
            }
        }
    } finally {
        $result = $server->stop($trace ? 15 : 5);
        if ($debugger !== null) {
            $debugResult = $debugger->wait(5);
            file_put_contents($base . '/' . $name . '-stack.log', $debugResult->stdout . $debugResult->stderr);
        }
        file_put_contents($base . '/' . $name . '-server.log', str_replace($environment['APP_API_TOKEN'], '<REDACTED>', $result->stdout . $result->stderr));
    }

    return ['requests' => $observed, 'trace' => $trace, 'exit-code' => $result->exitCode,
        'timed-out' => $result->timedOut, 'signal' => $result->signal, 'output-exceeded' => $result->outputExceeded];
}

try {
    $migration = new Process([$program, 'migrate', 'run'], $work, $environment);
    try {
        $result = $migration->wait(15);
        Assert::true($result->successful(), '原程序迁移失败：' . $result->stderr);
    } finally {
        $migration->stop();
    }
    $cases = [
        'ready' => [],
        'query' => [['GET', '/products', '']],
        'create' => [['POST', '/products', '{"code":"first","name":"诊断商品"}']],
        'validation' => [['POST', '/products', '{"code":"bad","name":null}']],
        'missing' => [['GET', '/products/999999', '']],
        'relation' => [['POST', '/products/1/labels', '{}']],
        'upsert' => [['POST', '/products/import', '{}'], ['POST', '/products/import', '{}']],
        'concurrent' => [],
        'ensure' => [['POST', '/products/ensure', '{"code":"contended","name":"竞争创建"}']],
    ];
    foreach ($cases as $name => $requests) {
        $report['cases'][$name] = probeTutorialStop($name, $requests, false, $program, $work, $base, $environment, $original . '/tests/catalog.php');
        echo $name . ': ' . $report['cases'][$name]['exit-code'] . "\n";
    }
    foreach ($cases as $name => $requests) {
        if ($report['cases'][$name]['exit-code'] === -1073741819) {
            $report['cases'][$name . '-trace'] = probeTutorialStop($name . '-trace', $requests, true, $program, $work, $base, $environment, $original . '/tests/catalog.php');
            break;
        }
    }
    // 原测试三次有一次访问冲突；复制到本轮目录附加调试器，不改保全原件。
    $testSource = file_get_contents($original . '/tests/catalog.php');
    Assert::same($preserved['files']['tests/catalog.php']['sha256'], hash('sha256', $testSource));
    $attach = <<<'PHP'
    $cdb = getenv('TYPE_PROBE_CDB');
    $lldb = getenv('TYPE_PROBE_LLDB');
    $debugCommand = $cdb !== false
        ? [$cdb, '-G', '-p', (string) $server->pid(), '-c', 'sxe -c "gn" 0x40010008;sxe -c ".echo [tutorial-native-crash];.exr -1;kv;lm;u @rip-20 L30;q" av;g']
        : [$lldb, '--batch', '-p', (string) $server->pid(), '-o', 'continue', '-k', 'thread backtrace all', '-k', 'register read', '-k', 'disassemble --pc --count 24', '-k', 'image list', '-k', 'quit'];
    $probeDebugger = new Process($debugCommand, $root, $settings);
    usleep(1000000);
PHP;
    $start = '$server = new Process([...catalogCommand($command, $settings, true), \'serve\'], $root, $settings);';
    Assert::same(1, substr_count($testSource, $start));
    if (!$fixed) {
        $testSource = str_replace($start, $start . "\n" . $attach, $testSource);
    }
    $finish = <<<'PHP'
        try {
            catalogStop($server, $root, $settings);
        } finally {
            $trace = $probeDebugger->wait(5);
            file_put_contents(getenv('TYPE_PROBE_TRACE_FILE'), $trace->stdout . $trace->stderr);
        }
PHP;
    Assert::same(1, substr_count($testSource, 'catalogStop($server, $root, $settings);'));
    if (!$fixed) {
        $testSource = str_replace('catalogStop($server, $root, $settings);', $finish, $testSource);
    }
    file_put_contents($work . '/catalog-trace.php', $testSource);
    // 每轮使用新数据库；只在完整断言之后发送 CTRL_BREAK，保持原停止预算。
    for ($attempt = 1; $attempt <= 10; ++$attempt) {
        $settings = array_replace($environment, ['TYPE_APP_BINARY' => $program,
            'TYPE_APP_TEST_DIRECTORY' => $root, 'DB_SQLITE_FILE' => $work . '/var/full-' . $attempt . '.sqlite',
            'TYPE_PROBE_TRACE_FILE' => $base . '/full-' . $attempt . '-stack.log']);
        $process = new Process([PHP_BINARY, $work . '/catalog-trace.php'], $work, $settings);
        try {
            $result = $process->wait(60);
            file_put_contents($base . '/full-' . $attempt . '.log', $result->stdout . $result->stderr);
            $report['full'][$attempt] = ['exit-code' => $result->exitCode, 'timed-out' => $result->timedOut];
            foreach (explode("\n", $result->stdout) as $line) {
                if (str_starts_with($line, 'server-stop ')) {
                    $stop = json_decode(substr($line, 12), true, 512, JSON_THROW_ON_ERROR);
                    unset($stop['stdout'], $stop['stderr']);
                    $report['full'][$attempt]['server'] = $stop;
                }
            }
            echo 'full-' . $attempt . ': ' . json_encode($report['full'][$attempt], JSON_THROW_ON_ERROR) . "\n";
        } finally {
            $process->stop();
        }
    }
    if ($fixed) {
        foreach ($report['cases'] as $case) {
            Assert::same(0, $case['exit-code'], '重建程序的缩减场景停服失败');
        }
        foreach ($report['full'] as $case) {
            Assert::same(0, $case['exit-code'], '重建程序的原始完整教程失败');
            Assert::same(0, $case['server']['exit-code'], '重建程序未正常停止');
        }
    }
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        Assert::true(!$file->isLink(), '诊断清理不能跟随链接');
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($work);
    $report['temporary-data-removed'] = !is_dir($work);
    file_put_contents($base . '/verification.json', json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
}
