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
} finally {
    foreach (['generated.php', 'run.php'] as $name) {
        if (is_file($directory . '/' . $name)) {
            unlink($directory . '/' . $name);
        }
    }
    rmdir($directory);
}
