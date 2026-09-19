<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

if (($argv[1] ?? '') === '--docker-image') {
    $command = cleanRuntimeCommand($argv[2] ?? '');
} elseif (($argv[1] ?? '') === '--chroot') {
    $command = nativeCommand($argv[2] ?? '', true);
} else {
    $command = nativeCommand($argv[1] ?? '');
}

$cases = [
    [[], 0, "你好，typeapp！\n", ''],
    [['--name', '开发者', '--repeat=2'], 0, "你好，开发者！\n你好，开发者！\n", ''],
    [['--name=甲 乙'], 0, "你好，甲 乙！\n", ''],
    [['--help'], 0, "用法：type-app [--name 名称] [--repeat 次数] [--help]\n", ''],
    [['--repeat=0'], 64, '', '选项必须是范围内的整数'],
    [['--repeat=99999999999999999999999999'], 64, '', '选项必须是范围内的整数'],
    [['--name'], 64, '', '选项缺少值'],
    [['--name=a', '--name=b'], 64, '', '选项不能重复'],
    [['--unknown'], 64, '', '未知选项'],
];
foreach ($cases as [$arguments, $expectedStatus, $expectedOutput, $expectedError]) {
    [$status, $stdout, $stderr] = execute([...$command, ...$arguments]);
    expect($status === $expectedStatus, '退出码不符合约定：' . json_encode($arguments) . "\n" . $stdout . $stderr);
    expect($stdout === $expectedOutput, '标准输出不符合约定：' . json_encode($arguments) . "\n" . $stdout . $stderr);
    expect($expectedError === '' ? $stderr === '' : str_contains($stderr, $expectedError), '错误输出不符合约定：' . $stderr);
}
echo '原生命令验证通过，共 ' . count($cases) . " 个输出和错误行为用例。\n";
