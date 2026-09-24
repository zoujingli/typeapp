<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$project = $root . '/build/rejected-build-' . bin2hex(random_bytes(5));
expect(mkdir($project . '/build/compiler', 0755, true), '无法创建构建拒绝测试目录');
foreach (['composer.json', 'composer.lock', 'toolchain.lock.json'] as $file) {
    expect(copy($root . '/' . $file, $project . '/' . $file), '无法准备测试输入');
}
expect(copy($root . '/examples/native-command.php', $project . '/entry.php'), '无法准备入口');
$consumerComposer = json_decode(file_get_contents($project . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$consumerComposer['name'] = 'type-tests/rejected-build';
$consumerComposer['autoload'] = ['files' => ['entry.php']];
file_put_contents($project . '/composer.json', json_encode($consumerComposer, JSON_THROW_ON_ERROR));
expect(symlink($root . '/vendor', $project . '/vendor'), '无法准备已安装的测试依赖');
$settings = ['name' => 'rejected-build', 'entry' => 'entry.php', 'output' => 'build/program', 'build-directory' => 'build/compiler'];
$settingsFile = $project . '/application.json';
$protectedFile = $project . '/must-remain.txt';
file_put_contents($protectedFile, '应当保持不变');
file_put_contents($project . '/build/program', '原有产物不能被失败构建覆盖');

foreach (['build/compiler/project.yml', 'build/program.build.json'] as $link) {
    expect(symlink($protectedFile, $project . '/' . $link), '无法建立路径拒绝用例');
    file_put_contents($settingsFile, json_encode($settings, JSON_THROW_ON_ERROR));
    [$status, $stdout, $stderr] = execute([PHP_BINARY, $root . '/vendor/bin/type', $settingsFile], $root);
    expect($status !== 0 && str_contains($stderr, '符号链接'), '派生文件符号链接未被明确拒绝：' . $stdout . $stderr);
    expect(file_get_contents($protectedFile) === '应当保持不变', '构建写入了范围外的文件');
    expect(file_get_contents($project . '/build/program') === '原有产物不能被失败构建覆盖', '失败检查覆盖了原有产物');
    unlink($project . '/' . $link);
}

$settings['output'] = '../outside';
file_put_contents($settingsFile, json_encode($settings, JSON_THROW_ON_ERROR));
[$status, , $stderr] = execute([PHP_BINARY, $root . '/vendor/bin/type', $settingsFile], $root);
expect($status !== 0 && str_contains($stderr, 'build 目录'), '越界输出路径未被拒绝');
$settings['output'] = 'build/program';
file_put_contents($settingsFile, json_encode($settings, JSON_THROW_ON_ERROR));

$toolchain = json_decode(file_get_contents($project . '/toolchain.lock.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ([['typephp', 'version', '0.7.0'], ['typephp', 'reference', str_repeat('0', 40)],
    ['phpx', 'version', '2.7.0'], ['phpx', 'reference', str_repeat('0', 40)],
    [null, 'php', '8.4.14'], [null, 'zts', false]] as [$tool, $field, $invalidValue]) {
    $invalidToolchain = $toolchain;
    if ($tool === null) {
        $invalidToolchain[$field] = $invalidValue;
    } else {
        $invalidToolchain[$tool][$field] = $invalidValue;
    }
    file_put_contents($project . '/toolchain.lock.json', json_encode($invalidToolchain, JSON_THROW_ON_ERROR));
    [$status, $stdout, $stderr] = execute([PHP_BINARY, $root . '/vendor/bin/type', $settingsFile], $root);
    $expectedError = $tool === null ? '当前 PHP 与应用锁定的工具链不一致' : '编译工具身份与应用锁定不一致：swoole/' . $tool;
    expect($status !== 0 && str_contains($stderr, $expectedError), '旧版或不匹配的工具链未被拒绝：' . $stdout . $stderr);
    expect(file_get_contents($project . '/build/program') === '原有产物不能被失败构建覆盖', '工具链身份拒绝覆盖了原有产物');
}
file_put_contents($project . '/toolchain.lock.json', json_encode($toolchain, JSON_THROW_ON_ERROR));

$composer = json_decode(file_get_contents($project . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
// php-parser 确实由构建工具安装，但没有生产编译声明；PSR-3 已有 type-log 的合法适配。
$installed = json_decode(file_get_contents($project . '/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
$packages = array_column($installed['packages'], null, 'name');
expect(isset($packages['nikic/php-parser']) && !isset($packages['nikic/php-parser']['extra']['type']), '拒绝用例必须使用真实已安装且没有自身编译声明的依赖');
$composer['require']['nikic/php-parser'] = '^5.8';
file_put_contents($project . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
[$status, , $stderr] = execute([PHP_BINARY, $root . '/vendor/bin/type', $settingsFile], $root);
expect($status !== 0 && str_contains($stderr, '生产依赖缺少支持的编译声明：nikic/php-parser'), '没有编译声明的依赖被静默忽略：' . $stderr);
expect(file_get_contents($project . '/build/program') === '原有产物不能被失败构建覆盖', '拒绝依赖时覆盖了原有产物');

// 编译器升级不能把匿名类悄悄嵌入 opcode，生成成功也不等于全量 AOT。
$anonymousSource = $project . '/anonymous.php';
file_put_contents($anonymousSource, '<?php function main(): void { $value = new class { public function name(): string { return "fallback"; } }; echo $value->name(); }');
[$status, $stdout, $stderr] = execute([PHP_BINARY, $root . '/vendor/bin/type-compiler', $anonymousSource,
    '--dry', '--mode', 'bin', '--build-dir', $project . '/anonymous-build', '--output', $project . '/anonymous'], $root);
expect($status !== 0 && str_contains($stdout . $stderr, '全量 AOT 不支持匿名类解释回退'), '匿名类解释回退未被明确拒绝：' . $stdout . $stderr);
expect(!is_file($project . '/anonymous'), '被拒绝的源码不能生成产物');

echo "构建入口的路径、工具链身份、依赖与解释回退拒绝检查通过，共 11 个用例。\n";
