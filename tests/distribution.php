<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$entry = $root . '/tools/distribute-plugin.php';
$cases = [
    [['invalid', 'type-runtime'], '用法'],
    [[str_repeat('0', 40), 'type-not-registered'], '插件未登记'],
    [[str_repeat('0', 40), 'type-runtime'], '当前检出必须'],
];
foreach ($cases as [$arguments, $expectedError]) {
    [$status, , $stderr] = execute([PHP_BINARY, $entry, ...$arguments], $root);
    expect($status === 1 && str_contains($stderr, $expectedError), '分发入口没有在外部操作前拒绝错误输入：' . $stderr);
}
echo "分发入口拒绝检查通过，共 3 个用例。\n";
