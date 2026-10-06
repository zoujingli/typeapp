<?php

declare(strict_types=1);

use Type\Orm\Mysql\MysqlDriver;
use Type\Orm\Pgsql\PgsqlDriver;
use Type\Testing\HttpClient;
use Type\Testing\Process;

/**
 * 目录包和单程序共用同一业务验收，防止两种交付形式悄悄缩减功能检查。
 *
 * @param list<string> $command 已通过隔离探针的启动参数。
 * @param array<string,string> $environment 受控部署环境；专用数据库在本函数创建并在finally删除。
 * @param array<string,array{bytes:int,sha256:string}> $embeddedResources 程序内嵌清单，只安装其中的web前缀。
 * @return array 初始化的预算、时间和真实进程结果。
 */
function verifyNativeApplicationDeployment(string $project, string $package, string $runtime, array $command, array $environment, string $driver, array $embeddedResources, bool $isolated, bool $single = false): array
{
    $root = dirname(__DIR__);
    $base = dirname($runtime);
    $admin = null;
    $databaseCreated = false;
    $database = 'type_package_' . bin2hex(random_bytes(6));
    try {
        if ($driver !== 'sqlite') {
            $prefix = 'TYPE_' . strtoupper($driver) . '_';
            $settings = [];
            foreach (['HOST', 'PORT', 'DATABASE', 'USER', 'PASSWORD'] as $key) {
                $value = getenv($prefix . $key);
                expect(is_string($value) && $value !== '', '发布验收需要专用数据库参数：' . $prefix . $key);
                $settings[$key] = $value;
            }
            expect(ctype_digit($settings['PORT']) && (int) $settings['PORT'] > 0 && (int) $settings['PORT'] < 65536, '发布验收数据库端口无效');
            $databaseDriver = $driver === 'mysql'
                ? new MysqlDriver($settings['HOST'], (int) $settings['PORT'], $settings['DATABASE'], $settings['USER'], $settings['PASSWORD'])
                : new PgsqlDriver($settings['HOST'], (int) $settings['PORT'], $settings['DATABASE'], $settings['USER'], $settings['PASSWORD']);
            $admin = $databaseDriver->connect();
            $admin->exec('CREATE DATABASE ' . $database);
            $databaseCreated = true;
            $environment['DB_HOST'] = $settings['HOST'];
            $environment['DB_PORT'] = $settings['PORT'];
            $environment['DB_DATABASE'] = $database;
            $environment['DB_USERNAME'] = $settings['USER'];
            $environment['DB_PASSWORD'] = $settings['PASSWORD'];
        }
        if ($project !== $root && getenv('TYPE_PACKAGE_TUTORIAL') === '1') {
            expect($single && $isolated && is_file($project . '/tests/tutorial.php'), '教程部署必须使用同一公开断言和隔离单程序');
            $controllerEnvironment = array_replace(getenv(), $environment, [
                'TYPE_APP_TEST_DIRECTORY' => $project,
                'TYPE_APP_COMMAND' => json_encode($command, JSON_THROW_ON_ERROR),
                'PATH' => getenv('PATH') ?: '',
            ]);
            unset($controllerEnvironment['TYPE_APP_STOP_COMMAND'], $controllerEnvironment['TYPE_APP_OBSERVE_COMMAND']);
            $processInfo = PHP_OS_FAMILY === 'Linux' ? $base . '/tutorial-{{test}}-process.json' : null;
            $controllerEnvironment['TYPE_APP_SERVER_COMMAND'] = json_encode($processInfo === null ? $command
                : sandboxPackageCommand($root, $package, [$runtime], $processInfo, 'app'), JSON_THROW_ON_ERROR);
            if ($processInfo !== null) {
                // PHP只在隔离外的验收控制端发送信号，真实业务仍由隔离单程序执行。
                $controllerEnvironment['TYPE_APP_STOP_COMMAND'] = json_encode([PHP_BINARY, '-r',
                    'require $argv[1]."/tests/support.php"; require $argv[1]."/tests/native-package-sandbox.php"; signalPackageProcess($argv[2], $argv[3], "app");',
                    $root, $package, $processInfo], JSON_THROW_ON_ERROR);
            }
            $started = microtime(true);
            $controller = new Process([PHP_BINARY, $project . '/tests/tutorial.php'], $runtime, $controllerEnvironment, 4194304);
            try {
                $result = $controller->wait(120);
                $secrets = array_values(array_filter([$environment['DB_PASSWORD'] ?? '', $environment['APP_API_TOKEN'] ?? ''], static fn (string $value): bool => $value !== ''));
                $identities = [];
                if ($processInfo !== null) {
                    foreach (['smoke', 'catalog'] as $test) {
                        $identity = str_replace('{{test}}', $test, $processInfo);
                        if (is_file($identity) && !is_link($identity)) {
                            $identities[$test] = ['file' => basename($identity), 'sha256' => hash_file('sha256', $identity),
                                'content' => file_get_contents($identity)];
                        }
                    }
                }
                file_put_contents($base . '/tutorial.log', str_replace($secrets, '<REDACTED>', $result->stdout . $result->stderr)
                    . ($identities === [] ? '' : 'server-identities ' . json_encode($identities, JSON_THROW_ON_ERROR) . "\n"));
                expect($result->successful(), '隔离教程公开行为失败，见：' . $base . '/tutorial.log');
                preg_match_all('/^server-stop (.+)$/m', $result->stdout, $matches);
                $stops = [];
                foreach ($matches[1] as $receipt) {
                    $stop = json_decode($receipt, true, 32, JSON_THROW_ON_ERROR);
                    $test = $stop['test'] ?? '';
                    expect(in_array($test, ['smoke', 'catalog'], true) && !isset($stops[$test]), '教程停止回执重复或身份不符');
                    expect($stop['exit-code'] === 0 && $stop['signal'] === null && $stop['timed-out'] === false
                        && $stop['output-exceeded'] === false, '教程服务未正常停止');
                    unset($stop['stdout'], $stop['stderr']);
                    if ($processInfo !== null) {
                        $identity = str_replace('{{test}}', $test, $processInfo);
                        expect(is_file($identity) && !is_link($identity), '教程缺少真实服务身份回执');
                        $stop['process-identity'] = json_decode(file_get_contents($identity), true, 32, JSON_THROW_ON_ERROR);
                        $stop['process-identity-sha256'] = hash_file('sha256', $identity);
                    }
                    $stops[$test] = $stop;
                }
                expect(isset($stops['smoke'], $stops['catalog']) && count($stops) === 2, '教程必须保留两次服务正常停止回执');
                return ['budget-seconds' => 120, 'elapsed-seconds' => round(microtime(true) - $started, 3),
                    'exit-code' => $result->exitCode, 'timed-out' => $result->timedOut, 'signal' => $result->signal,
                    'tutorial-public-assertions' => true, 'server-stops' => $stops,
                    'log-sha256' => hash_file('sha256', $base . '/tutorial.log')];
            } finally {
                $controller->stop();
            }
        }
        $password = bin2hex(random_bytes(16));
        $initialization = $project === $root
            ? [...$command, 'app:install', 'package-admin', '发布管理员', 'package-customer', '发布客户', '发布租户']
            : [...$command, 'migrate', 'run'];
        $initializationStarted = microtime(true);
        $installed = (new Process($initialization, $package, $environment + [
            'APP_ADMIN_PASSWORD' => $password, 'APP_CUSTOMER_PASSWORD' => $password . '-customer',
        ]))->wait(30);
        // Windows PostgreSQL 原候选空库安装实测约22秒；沿用应用测试的30秒预算，超时仍失败。
        $initializationStatus = ['budget-seconds' => 30, 'elapsed-seconds' => round(microtime(true) - $initializationStarted, 3),
            'exit-code' => $installed->exitCode, 'timed-out' => $installed->timedOut,
            'output-exceeded' => $installed->outputExceeded, 'signal' => $installed->signal];
        expect($installed->successful(), '源码不可访问时应用初始化失败 ' . json_encode($initializationStatus, JSON_THROW_ON_ERROR)
            . "：\n" . $installed->stdout . $installed->stderr);
        if ($project === $root) {
            $webFiles = array_filter($embeddedResources, static fn (string $path): bool => str_starts_with($path, 'web/'), ARRAY_FILTER_USE_KEY);
            expect(isset($webFiles['web/index.html'], $webFiles['web/LICENSE']), '物联中心产物没有内嵌完整前端');
            foreach ($webFiles as $path => $file) {
                expect(str_starts_with($path, 'web/') && hash_file('sha256', $runtime . '/public/' . substr($path, 4)) === $file['sha256'], '前端安装字节与归档身份不同');
            }
            mkdir($runtime . '/public/uploads');
            file_put_contents($runtime . '/public/uploads/retained.txt', 'upload');
            foreach ([['web:install'], ['web:install', '--dry-run'], ['web:install', '--force']] as $arguments) {
                $web = (new Process([...$command, ...$arguments], $base, $environment))->wait(30);
                expect($web->successful(), '部署前端重复安装失败：' . $web->stderr);
            }
            expect(file_get_contents($runtime . '/public/uploads/retained.txt') === 'upload', '更新删除了上传文件');
        }

        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        expect(is_resource($listener), '无法选择部署验收端口');
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $environment['APP_PORT'] = substr(strrchr($address, ':'), 1);
        $environment['APP_ALLOWED_HOSTS'] = $address;
        $processInfo = PHP_OS_FAMILY === 'Linux' && $isolated ? $base . '/server-process.json' : null;
        $serverCommand = $processInfo === null ? $command : sandboxPackageCommand($root, $package, [$runtime], $processInfo, $single ? 'app' : 'run');
        $process = new Process([...$serverCommand, 'serve'], $package, $environment);
        $client = new HttpClient('http://' . $address);
        try {
            $ready = false;
            $until = microtime(true) + 10;
            do {
                expect($process->running(), '部署服务提前退出：' . $process->stderr());
                try {
                    $ready = $client->request('GET', '/readyz')->status === 200;
                } catch (RuntimeException) {
                }
                if (!$ready) {
                    usleep(10000);
                }
            } while (!$ready && microtime(true) < $until);
            expect($ready, '部署服务未就绪');
            $headers = ['Authorization' => 'Bearer ' . $environment['APP_API_TOKEN'], 'Content-Type' => 'application/json'];
            if (getenv('TYPE_TEMPLATE_EXPECTED_MESSAGE') !== false) {
                expect($client->request('GET', '/', $headers)->json()['message'] === getenv('TYPE_TEMPLATE_EXPECTED_MESSAGE'), '无源码部署没有运行创建后修改的业务');
            }
            if ($project === $root) {
                $page = $client->request('GET', '/');
                expect($page->status === 200 && hash('sha256', $page->body) === $webFiles['web/index.html']['sha256'], '登录页面与内嵌入口不同');
                $head = $client->request('HEAD', '/');
                expect($head->status === 200 && $head->body === '', 'HEAD页面响应携带了正文');
                expect($head->header('Content-Length') === [(string) $webFiles['web/index.html']['bytes']]
                    && $head->header('Content-Type') === $page->header('Content-Type')
                    && $head->header('ETag') === $page->header('ETag'), 'HEAD页面元数据与GET不一致');
                $cached = $client->request('GET', '/', ['If-None-Match' => '"' . $webFiles['web/index.html']['sha256'] . '"']);
                expect($cached->status === 304 && $cached->body === '', '页面缓存协商失败');
                expect($client->request('POST', '/')->status === 405 && $client->request('GET', '/missing-api')->status === 404, '页面接管了不允许的方法或未知API');
                foreach ($webFiles as $path => $file) {
                    if (str_ends_with($path, '.js')) {
                        $asset = $client->request('GET', '/' . substr($path, 4));
                        expect($asset->status === 200 && hash('sha256', $asset->body) === $file['sha256'], '静态脚本服务字节不同');
                        $assetHead = $client->request('HEAD', '/' . substr($path, 4));
                        expect($assetHead->status === 200 && $assetHead->body === ''
                            && $assetHead->header('Content-Length') === [(string) $file['bytes']]
                            && $assetHead->header('Cache-Control') === $asset->header('Cache-Control'), 'HEAD静态资源长度或缓存策略与GET不一致');
                        break;
                    }
                }
                expect($client->request('GET', '/uploads/retained.txt')->status === 404, '静态入口公开了非托管文件');
                expect($client->request('GET', '/public/site')->json()['data']['name'] === 'TypeApp', '部署缺少默认站点信息');
                $customer = $client->request('POST', '/customer/auth/login', ['Content-Type' => 'application/json'], json_encode([
                    'login' => 'package-customer', 'password' => $password . '-customer',
                ], JSON_THROW_ON_ERROR));
                expect($customer->status === 200 && isset($customer->json()['data']['accessToken']), '部署客户登录失败');
                expect($client->request('GET', '/admin/users')->status === 401, '部署丢失授权');
                $login = $client->request('POST', '/admin/auth/login', ['Content-Type' => 'application/json'], json_encode([
                    'login' => 'package-admin', 'password' => $password,
                ], JSON_THROW_ON_ERROR));
                expect($login->status === 200, '部署管理员登录失败');
                $headers['Authorization'] = 'Bearer ' . $login->json()['data']['accessToken'];
                $user = $client->request('POST', '/admin/users', $headers, json_encode([
                    'login' => 'package-user', 'name' => '包验收用户', 'password' => $password,
                ], JSON_THROW_ON_ERROR));
                expect($user->status === 200, '部署人员创建失败');
                $saved = $user->json()['data'];
                $listed = $client->request('GET', '/admin/users?search=package-user', $headers);
                expect($listed->status === 200 && $listed->json()['data']['total'] === 1, '部署人员查询失败');
                $invalid = $client->request('PATCH', '/admin/users/' . $saved['id'], $headers, json_encode([
                    'version' => 1, 'login' => 'package-user', 'name' => '资料', 'password' => $password,
                ], JSON_THROW_ON_ERROR));
                expect($invalid->status === 422, '部署绕过了人员资料字段白名单');
                $updated = $client->request('PATCH', '/admin/users/' . $saved['id'], $headers, json_encode([
                    'version' => $saved['version'], 'login' => 'package-user', 'name' => '发布更新用户',
                ], JSON_THROW_ON_ERROR));
                expect($updated->status === 200 && $updated->json()['data']['name'] === '发布更新用户', '部署人员更新失败');
                $role = $client->request('POST', '/admin/roles', $headers, '{"name":"发布验收角色","permissions":[]}');
                expect($role->status === 200, '部署角色创建失败');
                $roleId = $role->json()['data']['id'];
                expect($client->request('GET', '/admin/roles/' . $roleId, $headers)->status === 200, '部署角色读取失败');
                $roleUpdated = $client->request('PATCH', '/admin/roles/' . $roleId, $headers, json_encode([
                    'version' => $role->json()['data']['version'], 'name' => '发布更新角色',
                ], JSON_THROW_ON_ERROR));
                expect($roleUpdated->status === 200 && $roleUpdated->json()['data']['name'] === '发布更新角色', '部署角色更新失败');
                $deleted = $client->request('DELETE', '/admin/roles/' . $roleId, $headers, json_encode([
                    'version' => $roleUpdated->json()['data']['version'],
                ], JSON_THROW_ON_ERROR));
                expect($deleted->status === 200 && $client->request('GET', '/admin/roles/' . $roleId, $headers)->status === 404, '部署角色删除闭环失败');
            } else {
                expect($client->request('GET', '/users')->status === 401, '部署丢失授权');
                $user = $client->request('POST', '/users', $headers, '{"name":"包验收用户","age":21}');
                expect($user->status === 201 && $client->request('GET', '/users?sort=age&direction=DESC', $headers)->json()['total'] === 1, '部署业务、排序或数据库写入失败');
                expect($client->request('GET', '/users?sort=email', $headers)->status === 422, '部署绕过了校验白名单');
            }
            $stopped = stopPackageProcess($process, $package, $processInfo, 5, $single ? 'app' : 'bin/app');
            expect($stopped->successful(), '部署进程未正常停止：' . json_encode(['exit' => $stopped->exitCode, 'signal' => $stopped->signal, 'timeout' => $stopped->timedOut]));
        } finally {
            try {
                stopPackageProcess($process, $package, $processInfo, 5, $single ? 'app' : 'bin/app');
            } finally {
                $process->stop();
            }
        }
    } finally {
        if ($admin !== null && $databaseCreated) {
            $admin->exec('DROP DATABASE ' . $database);
        }
        $admin = null;
    }
    return $initializationStatus;
}
