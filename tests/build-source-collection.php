<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$work = $root . '/build/source-collection-' . bin2hex(random_bytes(6));
successful([PHP_BINARY, $root . '/vendor/bin/type', '--stage', $root . '/docs/build-config/type-app.json', $work . '/project'], $root);
$work .= '/project';
// stage 不包含未参与构建的测试包；二次 stage 前收回其单个悬空开发别名。
$inactiveTesting = $work . '/vendor/zoujingli/type-testing';
if (is_link($inactiveTesting) && !file_exists($inactiveTesting)) {
    expect(unlink($inactiveTesting), '无法移除未参与编译的开发别名');
}
expect(is_dir($work . '/app'), '完整应用快照缺少业务目录');
file_put_contents($work . '/app/main.php', "<?php\ndeclare(strict_types=1);\nfunction main(): void { echo 'source-collection'; }\n");
file_put_contents($work . '/app/Value.php', "<?php\ndeclare(strict_types=1);\nfinal class CollectionValue { public function value(): int { return 1; } }\n");
file_put_contents($work . '/app/value.h', "#define COLLECTION_VALUE 1\n");
file_put_contents($work . '/app/value.cc', "#include \"value.h\"\nint collection_value() { return COLLECTION_VALUE; }\n");
file_put_contents($work . '/app/value.S', ".text\n");
$settings = ['name' => 'source-collection', 'entry' => 'app/main.php', 'sources' => ['app', 'app/main.php', 'app/Value.php'],
    'output' => 'build/native/type-app', 'build-directory' => 'build/compiler'];
file_put_contents($work . '/type-app.json', json_encode($settings, JSON_THROW_ON_ERROR));
$first = json_decode(successful([PHP_BINARY, $work . '/vendor/bin/type', '--stage', $work . '/type-app.json', $work . '/build/input-first'], $work), true, 512, JSON_THROW_ON_ERROR);
$project = json_decode(file_get_contents($work . '/build/compiler/project.yml'), true, 512, JSON_THROW_ON_ERROR);
$sources = $project['sources'];
foreach (['app/main.php', 'app/Value.php', 'app/value.cc', 'app/value.S'] as $source) {
    expect(count(array_keys($sources, $work . '/' . $source, true)) === 1, '实际编译单元必须完整且只出现一次：' . $source);
}
expect(!in_array($work . '/app/value.h', $sources, true), '头文件属于构建身份输入，不能交给 TypePHP 当作 PHP 或独立编译单元');
$snapshot = json_decode(file_get_contents($work . '/build/input-first/build-inputs.json'), true, 512, JSON_THROW_ON_ERROR);
expect(isset($snapshot['files']['app/value.h']), '隔离输入快照不能遗漏业务目录内的头文件');
expect(isset($snapshot['files']['app/value.S']), '编译器已接受的原生源码必须进入隔离快照与构建身份');
file_put_contents($work . '/app/value.h', "#define COLLECTION_VALUE 2\n");
$second = json_decode(successful([PHP_BINARY, $work . '/vendor/bin/type', '--stage', $work . '/type-app.json', $work . '/build/input-second'], $work), true, 512, JSON_THROW_ON_ERROR);
expect($first['build-id'] !== $second['build-id'], '业务头文件变化必须使构建身份失效');
echo '公开构建入口的重叠目录、单入口、完整编译单元与头文件身份验收通过：' . $work . "\n";
