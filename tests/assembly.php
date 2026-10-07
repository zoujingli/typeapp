<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\CommandAssembly;
use Type\Core\Configuration;

$root = dirname(__DIR__);
$settings = json_decode(file_get_contents($root . '/docs/build-config/type-commands.json'), true, 512, JSON_THROW_ON_ERROR);
$application = $settings['application'];
$optional = ['services' => [['id' => 'unused', 'class' => 'TypeApp\\Example\\UnusedCommand']],
    'commands' => [['name' => 'unavailable', 'service' => 'unused']]];
$sources = [$root . '/plugin/type-core/src', $root . '/plugin/type-runtime/src', $root . '/examples/commands'];
$generator = new CommandAssembly();
$result = $generator->generate($application, ['zoujingli/typeapp' => $application, 'optional-module' => $optional], $sources);
$directory = $root . '/build/assembly-check-' . bin2hex(random_bytes(4));
expect(mkdir($directory, 0755, true), '无法创建装配测试目录');
try {
    file_put_contents($directory . '/generated.php', $result['code']);
    successful([PHP_BINARY, '-l', $directory . '/generated.php']);
    $launcher = "<?php\nrequire " . var_export($root . '/vendor/autoload.php', true) . ";\nrequire " . var_export($root . '/examples/commands/Commands.php', true)
        . ";\nrequire __DIR__ . '/generated.php';\nmain(\$argc, \$argv);\n";
    file_put_contents($directory . '/run.php', $launcher);
    echo successful([PHP_BINARY, __DIR__ . '/assembled-native.php', $directory . '/run.php', '--php']);

    $invalid = [];
    $value = $application;
    $value['services'][1]['arguments'][0] = ['service' => 'missing'];
    $invalid[] = [$value, '缺失服务绑定'];
    $value = $application;
    $value['services'][0]['arguments'][0] = ['service' => 'greet'];
    $invalid[] = [$value, '依赖循环'];
    $value = $application;
    $value['services'][] = $value['services'][0];
    $invalid[] = [$value, '重复服务注册'];
    $value = $application;
    $value['commands'][] = $value['commands'][0];
    $invalid[] = [$value, '重复命令注册'];
    $value = $application;
    $value['services'][0]['lifetime'] = 'execution';
    $value['services'][1]['lifetime'] = 'singleton';
    $invalid[] = [$value, '单例不能持有执行作用域服务'];
    $value = $application;
    $value['enabled'][] = 'not-installed';
    $invalid[] = [$value, '启用的模块未安装'];
    $value = $application;
    $value['services'][0]['class'] = 'TypeApp\\Example\\MissingClass';
    $invalid[] = [$value, '找不到服务类'];
    $value = $application;
    $value['services'][0]['arguments'] = [];
    $invalid[] = [$value, '构造参数数量'];
    $value = $application;
    $value['commands'][0]['service'] = 'greeting';
    $invalid[] = [$value, '未实现要求的接口'];
    $value = $application;
    $value['services'][0]['arguments'][0] = ['config' => 'missing'];
    $invalid[] = [$value, '缺失配置绑定'];
    $value = $application;
    $value['commands'][1]['resources'] = ['greeting'];
    $invalid[] = [$value, '未实现要求的接口'];
    $value = $application;
    $value['enabled'][] = 'zoujingli/typeapp';
    $invalid[] = [$value, '不重复的模块列表'];
    foreach ($invalid as [$value, $message]) {
        $rejected = false;
        try {
            $generator->generate($value, ['zoujingli/typeapp' => $value, 'optional-module' => $optional], $sources);
        } catch (RuntimeException $error) {
            $rejected = str_contains($error->getMessage(), $message);
            expect($rejected, '诊断不符合预期：' . $error->getMessage());
        }
        expect($rejected, '无效装配没有拒绝：' . $message);
    }
    $name = '初始值';
    $values = ['name' => &$name];
    $configuration = new Configuration($values);
    $name = '外部修改';
    expect($configuration->text('name') === '初始值', '配置快照保留了外部可变引用');
    echo '装配声明拒绝检查通过，共 ' . count($invalid) . " 个用例；配置快照引用隔离通过。\n";

    $namespace = 'TypeApp\\AssemblyFixture\\';
    $component = ['services' => [['id' => 'prefix', 'class' => $namespace . 'ComponentPrefix']],
        'bindings' => [['type' => $namespace . 'Prefix', 'service' => 'prefix'], ['type' => $namespace . 'Label', 'service' => 'message']]];
    $constructorApplication = ['enabled' => ['application', 'component'],
        'services' => [['id' => 'prefix', 'class' => $namespace . 'ApplicationPrefix'],
            ['id' => 'message', 'class' => $namespace . 'Message', 'factory' => ['class' => $namespace . 'MessageFactory', 'method' => 'create']]],
        'bindings' => [['type' => $namespace . 'Settings::$name', 'value' => '构造器']],
        'commands' => [['name' => 'show', 'class' => $namespace . 'ShowCommand']]];
    $fixtureSources = [$root . '/plugin/type-core/src', $root . '/plugin/type-runtime/src', __DIR__ . '/fixtures/constructor-assembly.php'];
    $generated = $generator->generate($constructorApplication, ['application' => $constructorApplication, 'component' => $component], $fixtureSources);
    file_put_contents($directory . '/generated.php', $generated['code']);
    file_put_contents($directory . '/run.php', '<?php require ' . var_export($root . '/vendor/autoload.php', true)
        . '; require ' . var_export(__DIR__ . '/fixtures/constructor-assembly.php', true) . '; require __DIR__ . "/generated.php"; main($argc, $argv);');
    expect(successful([PHP_BINARY, $directory . '/run.php', 'show']) === "应用:构造器\n", '构造器、具名工厂和组件默认覆盖没有执行真实行为');
    expect($generated['services']['prefix']['overrides'] === 'component:prefix', '装配报告遗漏覆盖来源');
    expect(count($generated['services']['message']['dependencies']) === 2, '具名工厂依赖未进入完整服务图');
    $constructorInvalid = [];
    $value = $constructorApplication;
    $value['services'][1]['factory']['method'] = 'unknown';
    $constructorInvalid[] = [$value, '工厂返回类型'];
    $value = $constructorApplication;
    $value['services'][1]['factory']['method'] = 'hidden';
    $constructorInvalid[] = [$value, '全局取值'];
    $value = $constructorApplication;
    $value['services'][1]['lifetime'] = 'singleton';
    $constructorInvalid[] = [$value, '单例不能持有'];
    $value = $constructorApplication;
    $value['bindings'][] = $value['bindings'][0];
    $constructorInvalid[] = [$value, '重复显式绑定'];
    $value = $constructorApplication;
    $value['services'][1]['arguments'] = [['value' => '错误类型'], ['service' => 'prefix']];
    $constructorInvalid[] = [$value, '构造常量类型不匹配'];
    foreach ($constructorInvalid as [$value, $message]) {
        $rejected = false;
        try {
            $generator->generate($value, ['application' => $value, 'component' => $component], $fixtureSources);
        } catch (RuntimeException $error) {
            $rejected = true;
            expect(str_contains($error->getMessage(), $message), '构造器拒绝诊断不符合预期：' . $error->getMessage());
        }
        expect($rejected, '无效构造器装配没有拒绝：' . $message);
    }
    echo "构造器多层推导、接口与抽象绑定、具名工厂、应用覆盖及 5 项拒绝验证通过。\n";

    $symbolProbe = $directory . '/nested-symbols.php';
    $symbolSource = <<<'PHP'
<?php
namespace TypeApp\NestedAssemblyProbe;
use Type\Core\Command as CommandContract;
use Type\Core\Configuration as Settings;
final class Product {}
final class Factory {
    public static function create(): Product {
        return new Product();
    }
}
abstract class ParentRunner implements CommandContract {
    public function __construct(Product $product) {}
    public function unused(): void { if (false) { class Local {} } }
}
final class Runner extends ParentRunner {
    public function run(Settings $configuration, array $arguments): int { return 0; }
}
namespace TypeApp\OtherAssemblyProbe;
final class Factory { public static function create(): void { global $value; } }
PHP;
    $symbolApplication = ['enabled' => ['application'], 'services' => [['id' => 'product', 'class' => 'TypeApp\\NestedAssemblyProbe\\Product',
        'factory' => ['class' => 'TypeApp\\NestedAssemblyProbe\\Factory', 'method' => 'create']]],
        'commands' => [['name' => 'test', 'class' => 'TypeApp\\NestedAssemblyProbe\\Runner']]];
    file_put_contents($symbolProbe, $symbolSource);
    $symbolResult = $generator->generate($symbolApplication, ['application' => $symbolApplication], [$symbolProbe]);
    expect($symbolResult['services']['auto.typeapp.nestedassemblyprobe.runner']['dependencies'] === ['product'], '继承构造器或完整工厂类名没有进入服务图');
    foreach (['global $value;', '$capture = fn () => null;', '\\Locator::current();'] as $unsafeBody) {
        file_put_contents($symbolProbe, str_replace('return new Product();', '$unused = new class { public function hidden(): void { ' . $unsafeBody . ' } }; return new Product();', $symbolSource));
        $unsafeRejected = false;
        try {
            $generator->generate($symbolApplication, ['application' => $symbolApplication], [$symbolProbe]);
        } catch (RuntimeException $error) {
            $unsafeRejected = str_contains($error->getMessage(), '全局取值、服务定位器或闭包捕获');
        }
        expect($unsafeRejected, '具名工厂嵌套类中的不安全取值未被拒绝：' . $unsafeBody);
    }
    file_put_contents($symbolProbe, $symbolSource . "\nnamespace TypeApp\\NestedAssemblyProbe; class Local {}\n");
    $duplicateRejected = false;
    try {
        $generator->generate($symbolApplication, ['application' => $symbolApplication], [$symbolProbe]);
    } catch (RuntimeException $error) {
        $duplicateRejected = str_contains($error->getMessage(), '重复源码符号');
    }
    expect($duplicateRejected, '签名裁剪遗漏了方法内具名类重名检查');
} finally {
    foreach (['generated.php', 'run.php', 'nested-symbols.php'] as $name) {
        if (is_file($directory . '/' . $name)) {
            unlink($directory . '/' . $name);
        }
    }
    rmdir($directory);
}
