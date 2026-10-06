<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require __DIR__ . '/http-support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\CommandAssembly;
use Type\Build\RouteCompiler;
use Type\Testing\Process;

$root = dirname(__DIR__);
$native = in_array('--native', $argv, true);
$receipt = ['protocol' => 1, 'mode' => $native ? 'native' : 'php', 'passed' => false];
$work = $root . '/build/http-assembly-' . bin2hex(random_bytes(6));
mkdir($work, 0700, true);
$process = null;
$pending = [];
try {
    $namespace = 'TypeApp\\HttpAssemblyFixture\\';
    $sources = [$root . '/plugin/type-core/src', $root . '/plugin/type-runtime/src', __DIR__ . '/fixtures/http-assembly.php'];
    $declarations = ['class' => 'TypeApp\\HttpAssemblyFixture\\Routes', 'routes' => []];
    foreach (['value', 'hold', 'metrics', 'release', 'fail'] as $action) {
        $declarations['routes'][] = ['methods' => ['GET'], 'path' => '/' . $action, 'handler' => [$namespace . 'Controller', $action],
            'middleware' => in_array($action, ['value', 'hold', 'fail'], true) ? ['identity'] : []];
    }
    $declarations['routes'][] = ['methods' => ['GET'], 'path' => '/broken', 'handler' => [$namespace . 'BrokenController', 'value']];
    $routing = (new RouteCompiler())->generate($root, $declarations, $sources);
    $component = ['services' => [['id' => 'message', 'class' => $namespace . 'Message', 'arguments' => [['value' => 'component']]]]];
    $application = ['enabled' => ['type-tests/http-component', 'type-tests/http-assembly'],
        'bootstrap' => ['class' => $namespace . 'Application', 'method' => 'run'], 'services' => [
        ['id' => 'message', 'class' => $namespace . 'Message', 'arguments' => [['value' => 'application']]],
        ['id' => 'identity', 'class' => $namespace . 'Identify']],
        'commands' => [['name' => 'show', 'class' => $namespace . 'Show'], ['name' => 'scopes', 'class' => $namespace . 'ChildScopes']], 'http' => ['middleware' => ['identity' => 'identity']]];
    $assembly = (new CommandAssembly())->generate($application, ['type-tests/http-assembly' => $application, 'type-tests/http-component' => $component], $sources, $routing);
    expect($assembly['services']['message']['overrides'] === 'type-tests/http-component:message', 'HTTP 与命令未共享覆盖图');
    mkdir($work . '/src', 0700);
    mkdir($work . '/config', 0700);
    mkdir($work . '/component', 0700);
    mkdir($work . '/component/src', 0700);
    file_put_contents($work . '/component/src/Component.php', '<?php declare(strict_types=1); namespace TypeApp\\HttpAssemblyComponent; final class Component {}');
    copy(__DIR__ . '/fixtures/http-assembly.php', $work . '/src/Application.php');
    copy($root . '/toolchain.lock.json', $work . '/toolchain.lock.json');
    file_put_contents($work . '/component/composer.json', json_encode(['name' => 'type-tests/http-component',
        'type' => 'library', 'license' => 'Apache-2.0', 'autoload' => ['classmap' => ['src']],
        'extra' => ['type' => ['protocol' => 1, 'sources' => ['src'], 'module' => $component]]], JSON_THROW_ON_ERROR));
    $repositories = [];
    foreach (['type-core', 'type-runtime', 'type-build'] as $package) {
        $repositories[] = ['type' => 'path', 'url' => '../../plugin/' . $package,
            'options' => ['symlink' => false, 'versions' => ['zoujingli/' . $package => '1.0.x-dev']]];
    }
    $repositories[] = ['type' => 'path', 'url' => 'component',
        'options' => ['symlink' => false, 'versions' => ['type-tests/http-component' => '1.0.x-dev']]];
    file_put_contents($work . '/composer.json', json_encode(['name' => 'type-tests/http-assembly',
        'type' => 'project', 'license' => 'Apache-2.0',
        'require' => ['zoujingli/type-core' => '1.0.x-dev', 'type-tests/http-component' => '1.0.x-dev'],
        'require-dev' => ['zoujingli/type-build' => '1.0.x-dev', 'swoole/typephp' => testToolchainVersion('typephp'), 'swoole/phpx' => testToolchainVersion('phpx')],
        'autoload' => ['classmap' => ['src']], 'minimum-stability' => 'dev', 'prefer-stable' => true,
        'repositories' => [...$repositories, ...localComposerRepositories($root), ['packagist.org' => false]],
        'config' => ['allow-plugins' => false]], JSON_THROW_ON_ERROR));
    file_put_contents($work . '/config/route.php', '<?php declare(strict_types=1); return ' . var_export($declarations, true) . ';');
    file_put_contents($work . '/type-app.json', json_encode(['name' => 'http-assembly', 'sources' => ['src'], 'application' => $application,
        'routing' => 'config/route.php', 'output' => 'build/http-assembly', 'build-directory' => 'build/compiler'], JSON_THROW_ON_ERROR));
    $composerCommand = getenv('TYPE_COMPOSER_PHAR') ? [PHP_BINARY, getenv('TYPE_COMPOSER_PHAR')] : [getenv('COMPOSER_BINARY') ?: 'composer'];
    successful([...$composerCommand, 'install', '--no-interaction', '--no-scripts', '--no-plugins', '--no-progress'], $work);
    if ($native) {
        successful([PHP_BINARY, $work . '/vendor/bin/type', 'build', $work . '/type-app.json'], $work);
        $binary = (new Type\Build\BuildPlatform())->output($work . '/build/http-assembly');
        $command = nativeCommand($binary);
        $report = json_decode(file_get_contents($binary . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
        expect(($report['assembly']['services']['message']['overrides'] ?? '') === 'type-tests/http-component:message', '原生入口没有应用组件覆盖');
        foreach ($report['sources'] as $source) {
            expect(str_starts_with($source, $work . '/'), '独立装配仍依赖主仓源码');
        }
        $receipt['binary-sha256'] = hash_file('sha256', $binary);
        $receipt['build-report-sha256'] = hash_file('sha256', $binary . '.build.json');
        $receipt['production-packages'] = $report['production-packages'];
    } else {
        $command = [PHP_BINARY, $work . '/vendor/bin/type', 'dev', $work . '/type-app.json'];
    }
    $cli = successful([...$command, 'show'], sys_get_temp_dir());
    expect(trim($cli) === 'application', '标准生成入口没有执行共同装配命令');
    expect(trim(successful([...$command, 'scopes'], sys_get_temp_dir())) === 'child scopes passed', '子任务作用域验证没有完成');
    $receipt['fixture-sha256'] = hash_file('sha256', $work . '/src/Application.php');
    $receipt['lock-sha256'] = hash_file('sha256', $work . '/composer.lock');
    $receipt['configuration-sha256'] = hash_file('sha256', $work . '/type-app.json');
    $address = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect(is_resource($address), '无法分配 HTTP 装配端口');
    $port = (int) substr(strrchr(stream_socket_get_name($address, false), ':'), 1);
    fclose($address);
    $process = new Process([...$command, 'serve', (string) $port], sys_get_temp_dir(), getenv());
    $deadline = microtime(true) + 5;
    do {
        if (!$process->running()) {
            $failure = $process->wait(0);
            throw new RuntimeException('装配服务启动失败：' . $failure->stdout . $failure->stderr);
        }
        $connection = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.1);
        if (is_resource($connection)) {
            fclose($connection);
            break;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    [$status, $body] = httpRequest($port, 'GET', '/value', 'first');
    expect($status === 200 && json_decode($body, true) === ['message' => trim($cli), 'marker' => 'first'], 'HTTP 与 CLI 业务值或请求内实例复用不一致：' . $body);
    for ($index = 0; $index < 8; $index++) {
        $pending[$index] = sendHttp($port, 'GET', '/hold', 'request-' . $index);
    }
    $deadline = microtime(true) + 3;
    do {
        [$status, $body] = httpRequest($port, 'GET', '/metrics');
        $metrics = json_decode($body, true);
        if ($metrics['entered'] === 8) {
            break;
        }
        usleep(1000);
    } while (microtime(true) < $deadline);
    expect($metrics === ['active' => 8, 'entered' => 8], '请求未真实同时进入执行作用域');
    httpRequest($port, 'GET', '/release');
    foreach ($pending as $index => $connection) {
        unset($pending[$index]);
        [$status, $body] = receiveHttp($connection);
        expect($status === 200 && json_decode($body, true) === ['message' => 'application', 'marker' => 'request-' . $index], '并发请求串用 execution 状态：' . $body);
    }
    foreach (['/fail', '/broken'] as $path) {
        [$status, $body] = httpRequest($port, 'GET', $path, 'failure');
        expect($status === 500 && !str_contains($body, 'secret-'), 'HTTP 构造或动作失败没有经过既有错误边界');
    }
    [$status, $body] = httpRequest($port, 'GET', '/metrics');
    expect(json_decode($body, true)['active'] === 0, '正常或异常请求没有归还执行资源');
    [$status, $body] = httpRequest($port, 'GET', '/value', 'after');
    expect($status === 200 && json_decode($body, true)['marker'] === 'after', '后续请求继承了旧身份');
    foreach (['missing', 'singleton', 'wrong-interface'] as $invalid) {
        $value = $application;
        if ($invalid === 'missing') {
            $value['http']['middleware'] = [];
        } elseif ($invalid === 'singleton') {
            $value['services'][1]['lifetime'] = 'singleton';
        } else {
            $value['http']['middleware']['identity'] = 'message';
        }
        try {
            (new CommandAssembly())->generate($value, ['type-tests/http-assembly' => $value, 'type-tests/http-component' => $component], $sources, $routing);
            throw new LogicException('无效 HTTP 装配没有拒绝：' . $invalid);
        } catch (RuntimeException $error) {
            expect($error->getMessage() !== '', 'HTTP 装配拒绝没有诊断');
        }
    }
    $evidence = $work . '.evidence';
    expect(mkdir($evidence, 0700), '无法保全独立装配验收身份');
    $preserved = ['composer.json' => 'composer.json', 'composer.lock' => 'composer.lock', 'toolchain.lock.json' => 'toolchain.lock.json',
        'type-app.json' => 'type-app.json', 'src/Application.php' => 'Application.php', 'config/route.php' => 'route.php',
        'component/composer.json' => 'component-composer.json', 'component/src/Component.php' => 'Component.php'];
    if ($native) {
        $preserved['build/compiler/project.yml'] = 'project.yml';
        $preserved['build/compiler/assembled-application.php'] = 'assembled-application.php';
        $preserved[substr($binary, strlen($work) + 1) . '.build.json'] = 'build.json';
    }
    foreach ($preserved as $source => $destination) {
        expect(copy($work . '/' . $source, $evidence . '/' . $destination)
            && hash_file('sha256', $work . '/' . $source) === hash_file('sha256', $evidence . '/' . $destination), '独立装配验收材料保全失败：' . $source);
    }
    $receipt['evidence'] = substr($evidence, strlen($root) + 1);
    $receipt['passed'] = true;
    $receipt['checks'] = ['standard-entry', 'component-override', 'cli-http-shared-value', 'child-scopes', 'eight-concurrent-requests', 'constructor-action-failure', 'resource-cleanup', 'three-build-rejections'];
    echo "HTTP/CLI 共同装配通过：标准生成入口、应用覆盖、请求内复用、子任务与八请求真实并发隔离、构造/动作失败脱敏、资源归还及三项构建拒绝。\n";
} catch (Throwable $error) {
    file_put_contents($work . '/failure.txt', get_class($error) . ': ' . $error->getMessage() . "\n");
    fwrite(STDERR, 'HTTP 装配失败复现保留：' . $work . "\n");
    throw $error;
} finally {
    foreach ($pending as $connection) {
        fclose($connection);
    }
    $process?->stop();
    file_put_contents($root . '/build/http-assembly-' . ($native ? 'native' : 'php') . '-report.json', json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    if ($receipt['passed']) {
        removeTestDirectory($work);
    }
}
