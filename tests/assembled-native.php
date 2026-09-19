<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

if (($argv[1] ?? '') === '--chroot') {
    $command = nativeCommand($argv[2] ?? '', true, ['TYPE_APP_NAME']);
} elseif (($argv[2] ?? '') === '--php') {
    $binary = realpath($argv[1] ?? '');
    expect($binary !== false, '装配应用不存在');
    $command = [PHP_BINARY, $binary];
} else {
    $command = nativeCommand($argv[1] ?? '');
}
$previous = getenv('TYPE_APP_NAME');
putenv('TYPE_APP_NAME');
try {
    $cases = [
        [['check'], 0, "离线配置检查通过。\n", ''],
        [['greet'], 0, "你好，typeapp！\n", ''],
        [['greet', 'status-23'], 23, "你好，typeapp！\n", ''],
        [['lifecycle'], 0, "开启：A\n开启：B\n事件：一:ready\n事件：二:ready\n你好，typeapp！\n事件：一:completed\n关闭：B\n关闭：A\n", ''],
        [['start-failure'], 70, "开启：A\n开启：B\n关闭：B\n关闭：A\n", '启动失败：B'],
        [['listener-failure'], 70, "开启：A\n开启：B\n事件：故障:ready\n关闭：B\n关闭：A\n", '监听失败：故障'],
        [['cleanup-failure'], 70, "开启：A\n开启：B\n关闭：B\n关闭：A\n", '业务命令失败；资源清理失败：停止失败：B'],
        [['batch'], 0, "1:2\n1:3\n", ''],
        [['scope'], 0, "开启：scope\n关闭：scope\n取消、截止、关闭拒绝与子任务预算恢复通过。\n", ''],
        [['unavailable'], 70, '', '未知命令：unavailable'],
    ];
    foreach ($cases as [$arguments, $expectedStatus, $expectedOutput, $expectedError]) {
        [$status, $stdout, $stderr] = execute([...$command, ...$arguments]);
        expect($status === $expectedStatus && $stdout === $expectedOutput, '装配命令行为不符：' . implode(' ', $arguments) . "\n" . $stdout . $stderr);
        expect($expectedError === '' ? $stderr === '' : str_contains($stderr, $expectedError), '装配命令错误语义不符：' . $stderr);
    }
    [$status, $stdout, $stderr] = execute([...$command, 'help']);
    expect($status === 0 && str_contains($stdout, '可用命令：') && !str_contains($stdout, 'unavailable')
        && !str_contains($stdout, '开启：') && $stderr === '', '帮助启动了不需要的资源或包含禁用命令');
    putenv('TYPE_APP_NAME=运行配置');
    expect(successful([...$command, 'snapshot']) === "运行配置:运行配置\n", '配置快照被运行中的环境修改');
    putenv('TYPE_APP_NAME=0');
    expect(successful([...$command, 'greet']) === "你好，0！\n", '合法的零字符串配置被默认值覆盖');
} finally {
    putenv($previous === false ? 'TYPE_APP_NAME' : 'TYPE_APP_NAME=' . $previous);
}
echo "装配命令验证通过，共 13 个命令、配置与清理行为用例。\n";
