<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\CommandAssembly;

$root = dirname(__DIR__);
$work = $root . '/build/business-events-' . bin2hex(random_bytes(6));
mkdir($work, 0700, true);
$complete = false;
try {
    $namespace = 'TypeApp\\BusinessEventFixture\\';
    $sources = [$root . '/plugin/type-core/src', $root . '/plugin/type-runtime/src', __DIR__ . '/fixtures/business-events.php'];
    $application = ['enabled' => ['type-tests/business-events'], 'bootstrap' => ['class' => $namespace . 'Application', 'method' => 'run'], 'services' => [['id' => 'first', 'class' => $namespace . 'First'],
        ['id' => 'second', 'class' => $namespace . 'Second'], ['id' => 'reject', 'class' => $namespace . 'Reject'],
        ['id' => 'state', 'class' => $namespace . 'State']],
        'commands' => [['name' => 'events', 'class' => $namespace . 'Scenario', 'resources' => ['state']], ['name' => 'scopes', 'class' => $namespace . 'ScopeScenario']],
        'events' => [
            ['class' => $namespace . 'Saved', 'listeners' => [['service' => 'first', 'method' => 'saved'], ['service' => 'second', 'method' => 'saved']]],
            ['class' => $namespace . 'Ignored'],
            ['class' => $namespace . 'Failed', 'listeners' => [['service' => 'first', 'method' => 'failed'], ['service' => 'reject', 'method' => 'failed'], ['service' => 'second', 'method' => 'failed']]],
        ]];
    $assembly = (new CommandAssembly())->generate($application, ['type-tests/business-events' => $application], $sources);
    expect(count($assembly['events']) === 3 && $assembly['events'][0]['listeners'][1]['service'] === 'second', '统一报告没有保留业务事件顺序');
    mkdir($work . '/app');
    copy(__DIR__ . '/fixtures/business-events.php', $work . '/app/Events.php');
    copy($root . '/toolchain.lock.json', $work . '/toolchain.lock.json');
    $repositories = [];
    foreach (['type-core', 'type-runtime', 'type-orm', 'type-orm-sqlite', 'type-build'] as $package) {
        $repositories[] = ['type' => 'path', 'url' => $root . '/plugin/' . $package, 'options' => ['symlink' => false, 'versions' => ['zoujingli/' . $package => '1.0.x-dev']]];
    }
    $composer = ['name' => 'type-tests/business-events', 'require' => ['zoujingli/type-core' => '1.0.x-dev', 'zoujingli/type-orm-sqlite' => '1.0.x-dev'],
        'require-dev' => ['zoujingli/type-build' => '1.0.x-dev', 'swoole/typephp' => testToolchainVersion('typephp'), 'swoole/phpx' => testToolchainVersion('phpx')],
        'autoload' => ['classmap' => ['app']], 'minimum-stability' => 'dev', 'prefer-stable' => true,
        'repositories' => [...$repositories, ...localComposerRepositories($root), ['packagist.org' => false]], 'config' => ['allow-plugins' => false]];
    file_put_contents($work . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
    file_put_contents($work . '/type-app.json', json_encode(['name' => 'business-events', 'sources' => ['app'], 'application' => $application,
        'output' => 'build/business-events', 'build-directory' => 'build/compiler'], JSON_THROW_ON_ERROR));
    $composerCommand = getenv('TYPE_COMPOSER_PHAR') ? [PHP_BINARY, getenv('TYPE_COMPOSER_PHAR')] : [getenv('COMPOSER_BINARY') ?: 'composer'];
    successful([...$composerCommand, 'install', '--no-scripts', '--no-plugins', '--no-interaction', '--no-progress'], $work);
    $native = in_array('--native', $argv, true);
    if ($native) {
        successful([PHP_BINARY, $work . '/vendor/bin/type', 'build', $work . '/type-app.json'], $work);
        $command = nativeCommand((new Type\Build\BuildPlatform())->output($work . '/build/business-events'));
    } else {
        $command = [PHP_BINARY, $work . '/vendor/bin/type', 'dev', $work . '/type-app.json'];
    }
    $run = static function (string $mode) use ($command, $work): array {
        return json_decode(successful([...$command, 'events', $mode, $work . '/' . $mode . '.sqlite'], $work), true, 512, JSON_THROW_ON_ERROR);
    };
    expect($run('ordered') === ['trace' => ['first:ordered', 'second:ordered', 'first:ordered', 'second:ordered'], 'calls' => 4], '监听顺序或同作用域实例复用错误');
    expect($run('empty') === ['calls' => 0], '无监听事件没有明确无操作');
    expect($run('failure') === ['trace' => ['first'], 'calls' => 1, 'error' => 'listener_failed'], '监听异常没有传播或未中断后续监听');
    expect($run('commit') === ['trace' => ['first:commit', 'second:commit'], 'calls' => 2, 'transactions' => [false], 'committed' => true, 'rows' => 1], '提交后事件在确认提交前运行或状态错误');
    expect($run('sync') === ['trace' => ['first:sync', 'second:sync'], 'calls' => 2, 'transactions' => [true], 'committed' => true, 'rows' => 1], '同步事件没有保留当前事务边界');
    expect($run('rollback') === ['trace' => [], 'calls' => 0, 'transactions' => [], 'committed' => false, 'rows' => 0], '回滚未丢弃提交后事件');
    expect($run('after-failure') === ['trace' => [], 'calls' => 1, 'transactions' => [], 'committed' => true, 'rows' => 1], '提交后异常被误报为事务回滚');
    expect(str_contains(successful([...$command, 'scopes'], $work), 'scope checks passed'), '事件作用域隔离失败');
    foreach (['duplicate-event', 'duplicate-listener', 'method', 'binding', 'lifetime', 'cycle'] as $invalid) {
        $value = $application;
        if ($invalid === 'duplicate-event') {
            $value['events'][] = $value['events'][0];
        } elseif ($invalid === 'duplicate-listener') {
            $value['events'][0]['listeners'][] = $value['events'][0]['listeners'][0];
        } elseif ($invalid === 'method') {
            $value['events'][0]['listeners'][0]['method'] = 'failed';
        } elseif ($invalid === 'binding') {
            $value['events'][0]['listeners'][0]['service'] = 'missing';
        } elseif ($invalid === 'lifetime') {
            $value['services'][0]['lifetime'] = 'singleton';
        } else {
            $value['events'][0]['listeners'][] = ['class' => $namespace . 'Cyclic', 'method' => 'saved'];
        }
        try {
            (new CommandAssembly())->generate($value, ['application' => $value], $sources);
            throw new LogicException('无效业务事件没有拒绝：' . $invalid);
        } catch (RuntimeException $error) {
            expect($error->getMessage() !== '', '业务事件拒绝没有诊断');
        }
    }
    $report = ['protocol' => 1, 'mode' => $native ? 'native' : 'php', 'fixture-sha256' => hash_file('sha256', __DIR__ . '/fixtures/business-events.php'),
        'lock-sha256' => hash_file('sha256', $work . '/composer.lock'), 'configuration-sha256' => hash_file('sha256', $work . '/type-app.json'),
        'checks' => ['ordered', 'empty', 'failure', 'commit', 'sync', 'rollback', 'after-failure', 'scopes', 'six-build-rejections'], 'passed' => true];
    if ($native) {
        $report['binary-sha256'] = hash_file('sha256', (new Type\Build\BuildPlatform())->output($work . '/build/business-events'));
    }
    $evidence = $work . '.evidence';
    expect(mkdir($evidence, 0700), '无法保全独立事件验收身份');
    $preserved = ['composer.json' => 'composer.json', 'composer.lock' => 'composer.lock', 'toolchain.lock.json' => 'toolchain.lock.json',
        'type-app.json' => 'type-app.json', 'app/Events.php' => 'Events.php'];
    if ($native) {
        $preserved['build/compiler/project.yml'] = 'project.yml';
        $preserved['build/compiler/assembled-application.php'] = 'assembled-application.php';
        $preserved[substr((new Type\Build\BuildPlatform())->output($work . '/build/business-events'), strlen($work) + 1) . '.build.json'] = 'build.json';
    }
    foreach ($preserved as $source => $destination) {
        expect(copy($work . '/' . $source, $evidence . '/' . $destination)
            && hash_file('sha256', $work . '/' . $source) === hash_file('sha256', $evidence . '/' . $destination), '独立事件验收材料保全失败：' . $source);
    }
    $report['evidence'] = substr($evidence, strlen($root) + 1);
    file_put_contents($root . '/build/business-events-' . ($native ? 'native' : 'php') . '-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    $complete = true;
    echo "业务事件通过：同步顺序、无监听、异常中断、同scope复用、四子任务隔离、真实SQLite提交/回滚/提交后失败、资源归还及六项构建拒绝。\n";
} catch (Throwable $error) {
    file_put_contents($work . '/failure.txt', get_class($error) . ': ' . $error->getMessage() . "\n");
    fwrite(STDERR, '业务事件失败复现保留：' . $work . "\n");
    throw $error;
} finally {
    if ($complete) {
        removeTestDirectory($work);
    }
}
