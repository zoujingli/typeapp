<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\ApplicationInspection;
use Type\Build\DevelopmentBuilder;
use Type\Testing\Process;

$root = dirname(__DIR__);
$work = $root . '/build/inspection space ' . bin2hex(random_bytes(6));
mkdir($work, 0700, true);
try {
    mkdir($work . '/src', 0700);
    mkdir($work . '/vendor', 0700);
    file_put_contents($work . '/vendor/autoload.php', '<?php');
    $source = <<<'PHP'
<?php
namespace InspectionFixture;
final class Value { public function __construct(public string $name) {} }
final class Factory {
    public static function create(string $name): Value {
        file_put_contents(__DIR__ . '/../factory-executed', 'unexpected');
        throw new \RuntimeException('factory_executed');
    }
}
final class Show implements \Type\Core\Command {
    public function __construct(private Value $value) {}
    public function run(\Type\Core\Configuration $configuration, array $arguments): int { echo $this->value->name; return 0; }
}
final class Notice {}
final class Observe {
    public function __construct(private Value $value) {}
    public function handle(Notice $event): void { throw new \RuntimeException('listener_executed'); }
}
PHP;
    file_put_contents($work . '/src/Application.php', $source);
    file_put_contents($work . '/composer.json', json_encode(['name' => 'example/inspection',
        'require' => ['zoujingli/type-core' => '*'], 'autoload' => ['psr-4' => ['InspectionFixture\\' => 'src']],
        'config' => ['vendor-dir' => $root . '/vendor']], JSON_THROW_ON_ERROR));
    copy($root . '/composer.lock', $work . '/composer.lock');
    $settings = ['sources' => ['src'], 'build-directory' => 'build/compiler', 'application' => [
        'enabled' => ['example/inspection'], 'config' => ['name' => ['env' => 'INSPECTION_SECRET', 'default' => 'secret-default-sentinel']],
        'services' => [['id' => 'value', 'class' => 'InspectionFixture\\Value', 'factory' => ['class' => 'InspectionFixture\\Factory', 'method' => 'create']]],
        'bindings' => [['type' => 'InspectionFixture\\Value::$name', 'config' => 'name']],
        'commands' => [['name' => 'show', 'class' => 'InspectionFixture\\Show']],
        'events' => [['class' => 'InspectionFixture\\Notice', 'listeners' => [['class' => 'InspectionFixture\\Observe', 'method' => 'handle']]]]],
        'capabilities' => ['schema' => ['users' => [1]], 'messages' => [], 'cache' => []]];
    $file = $work . '/type-app.json';
    file_put_contents($file, json_encode($settings, JSON_THROW_ON_ERROR));
    $environment = getenv();
    $environment['INSPECTION_SECRET'] = 'secret-environment-sentinel';
    $environment['DATABASE_URL'] = 'mysql://unreachable.invalid:1/secret-url-sentinel';
    $invoke = static function (array $arguments) use ($root, $environment): \Type\Testing\ProcessResult {
        $process = new Process([PHP_BINARY, $root . '/vendor/bin/type', ...$arguments], sys_get_temp_dir(), $environment);
        try {
            return $process->wait(15);
        } finally {
            $process->stop();
        }
    };
    $json = $invoke(['inspect-application', $file, '--json']);
    expect($json->successful(), '离线 JSON 检查失败：' . $json->stderr);
    $report = json_decode($json->stdout, true, 512, JSON_THROW_ON_ERROR);
    expect($report['protocol'] === 1 && $report['kind'] === 'application-declarations'
        && $report['assembly']['commands'] === ['show'] && $report['assembly']['services']['value']['lifetime'] === 'execution', '离线报告缺少装配事实');
    expect($report['assembly']['events'][0]['listeners'][0]['class'] === 'InspectionFixture\\Observe', '离线报告缺少事件监听根');
    foreach (['secret-default-sentinel', 'secret-environment-sentinel', 'secret-url-sentinel', 'file_put_contents'] as $secret) {
        expect(!str_contains($json->stdout, $secret), '离线报告泄露配置值或工厂源码');
    }
    $human = $invoke(['inspect-application', $file]);
    expect($human->successful() && str_contains($human->stdout, '未执行构造器、工厂或角色') && str_contains($human->stdout, 'value [execution]'), '人读报告缺少范围或生命周期');
    $prepared = (new DevelopmentBuilder())->prepareConfiguration($file);
    expect($prepared['declaration-generation'] === $report['generation'], '检查与开发生成身份不同');
    expect(!file_exists($work . '/factory-executed'), '离线检查或开发生成执行了业务工厂');
    $invalid = $invoke(['inspect-application', $file, '--unknown']);
    expect(!$invalid->successful() && !$invalid->timedOut && str_contains($invalid->stderr, '用法'), '检查没有拒绝无效参数');
    $settings['application']['bindings'] = [];
    file_put_contents($file, json_encode($settings, JSON_THROW_ON_ERROR));
    $diagnostics = [];
    foreach ([static fn () => (new ApplicationInspection())->inspect($file), static fn () => (new DevelopmentBuilder())->prepareConfiguration($file)] as $operation) {
        try {
            $operation();
            throw new RuntimeException('缺失绑定没有拒绝');
        } catch (RuntimeException $error) {
            $diagnostics[] = $error->getMessage();
        }
    }
    expect($diagnostics[0] === $diagnostics[1] && str_contains($diagnostics[0], '缺失绑定'), '检查与开发构建没有共享缺失绑定诊断');
    expect(glob($work . '/build/.application-inspection-*') === [], '检查成功或失败后留下临时分析目录');
    echo "离线应用检查通过：公开 CLI、人读/JSON、含空格路径、不同工作目录、秘密脱敏、不执行工厂、开发身份与错误一致。\n";
} finally {
    removeTestDirectory($work);
}
