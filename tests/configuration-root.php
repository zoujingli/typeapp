<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$directory = $root . '/build/configuration-root-' . bin2hex(random_bytes(6));
$project = $directory . '/project';
mkdir($directory, 0700, true);
$relativeDirectory = substr($directory, strlen($root) + 1);
$initialFile = $directory . '/initial.json';
$application = json_decode(file_get_contents($root . '/docs/build-config/type-app.json'), true, 512, JSON_THROW_ON_ERROR);
unset($application['development']);
file_put_contents($initialFile, json_encode(array_replace($application, ['name' => 'configuration-root-initial', 'project-root' => '../..',
    'output' => $relativeDirectory . '/initial-program/type-app', 'build-directory' => $relativeDirectory . '/initial-compiler']), JSON_THROW_ON_ERROR));
$initial = json_decode(successful([PHP_BINARY, $root . '/vendor/bin/type', '--stage', $initialFile, $project], $root), true, 512, JSON_THROW_ON_ERROR);
expect($initial['directory'] === $project, '集中配置没有在主仓 build 中创建独立快照');
// 未参与构建的 testing 开发包不会进入快照，收回这一准确悬空别名后验证快照再次导出。
$inactive = $project . '/vendor/zoujingli/type-testing';
if (is_link($inactive) && !file_exists($inactive)) {
    expect(unlink($inactive), '无法回收非构建开发包的单个悬空别名');
}
$nested = $project . '/docs/build-config/root-check.json';
if (!is_dir(dirname($nested))) {
    mkdir(dirname($nested), 0755, true);
}
$settings = array_replace($application, ['name' => 'configuration-root', 'project-root' => '../..',
    'output' => 'build/program/type-app', 'build-directory' => 'build/program/compiler']);
file_put_contents($nested, json_encode($settings, JSON_THROW_ON_ERROR));
$outside = $directory . '/unrelated';
mkdir($outside, 0700);
file_put_contents($outside . '/composer.json', '{"name":"type-tests/unrelated"}');
file_put_contents($outside . '/keep.txt', '不得写入无关工作目录');
$snapshot = $project . '/build/nested-snapshot';
$result = json_decode(successful([PHP_BINARY, $project . '/vendor/bin/type', '--stage', $nested, $snapshot], $outside), true, 512, JSON_THROW_ON_ERROR);
expect($result['directory'] === $snapshot && is_file($project . '/build/program/type-app.lock'), '构建锁或快照没有归属显式项目根');
expect(!is_dir($outside . '/build') && !is_dir($project . '/docs/build-config/build'), '嵌套配置错误地使用调用目录或配置目录作为项目根');
$generated = json_decode(file_get_contents($project . '/build/program/compiler/project.yml'), true, 512, JSON_THROW_ON_ERROR);
expect(in_array($project . '/app/main.php', $generated['sources'], true), 'entry 没有相对显式项目根解析');
$inputs = json_decode(file_get_contents($snapshot . '/build-inputs.json'), true, 512, JSON_THROW_ON_ERROR);
expect(isset($inputs['files']['docs/build-config/root-check.json'], $inputs['files']['composer.json'], $inputs['files']['app/main.php']), '隔离快照没有保留完整嵌套配置与项目声明');
$restaged = $snapshot . '/build/restaged';
successful([PHP_BINARY, $snapshot . '/vendor/bin/type', '--stage', $snapshot . '/docs/build-config/root-check.json', $restaged], $outside);
expect(is_file($restaged . '/docs/build-config/root-check.json'), '相对 project-root 在快照内不能再次解析');
$legacy = $settings;
unset($legacy['project-root']);
file_put_contents($project . '/legacy.json', json_encode($legacy, JSON_THROW_ON_ERROR));
successful([PHP_BINARY, $project . '/vendor/bin/type', '--stage', $project . '/legacy.json', $project . '/build/legacy-snapshot'], $outside);
expect(is_file($project . '/build/legacy-snapshot/legacy.json'), '未声明 project-root 的旧根配置失去兼容性');

$protected = $project . '/build/program/type-app';
file_put_contents($protected, '此前产物不能被非法配置覆盖');
foreach ([null, 42, [], '', '/', $project, '../../missing', '../../../unrelated', "../..\0", '..\\..', 'file://../..'] as $invalidRoot) {
    $invalid = $settings;
    $invalid['project-root'] = $invalidRoot;
    file_put_contents($nested, json_encode($invalid, JSON_THROW_ON_ERROR));
    [$status, $stdout, $stderr] = execute([PHP_BINARY, $project . '/vendor/bin/type', $nested], $outside);
    expect($status !== 0 && str_contains($stderr, 'project-root'), '非法根未在任何编译前明确拒绝：' . $stdout . $stderr);
    expect(file_get_contents($protected) === '此前产物不能被非法配置覆盖' && file_get_contents($outside . '/keep.txt') === '不得写入无关工作目录', '非法根检查修改了既有产物或其他项目');
}
$invalid = $settings;
$invalid['output'] = '../outside';
file_put_contents($nested, json_encode($invalid, JSON_THROW_ON_ERROR));
[$status, , $stderr] = execute([PHP_BINARY, $project . '/vendor/bin/type', $nested], $outside);
expect($status !== 0 && str_contains($stderr, 'build 目录') && file_get_contents($protected) === '此前产物不能被非法配置覆盖', 'project-root 放宽了既有输出目录约束');
file_put_contents($nested, json_encode($settings, JSON_THROW_ON_ERROR));
echo '公开构建入口的嵌套配置、工作目录隔离、快照重建、旧配置兼容和非法根拒绝通过：' . $directory . "\n";
