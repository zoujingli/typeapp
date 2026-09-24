<?php

declare(strict_types=1);

use Type\Runtime\Arguments;

/**
 * 解析有界重复次数并输出问候，作为无业务依赖的原生入口示例。
 *
 * @param list<string> $argv 程序路径与该示例的显式参数。
 */
function main(int $argc, array $argv): void
{
    try {
        $arguments = new Arguments($argv, ['name', 'repeat'], ['help']);
        if ($arguments->has('help')) {
            echo "用法：type-app [--name 名称] [--repeat 次数] [--help]\n";
            return;
        }

        $name = $arguments->text('name', 'typeapp');
        $repeat = $arguments->integer('repeat', 1, 1, 5);
        for ($index = 0; $index < $repeat; $index++) {
            echo '你好，' . $name . "！\n";
        }
    } catch (InvalidArgumentException $error) {
        fwrite(STDERR, '参数错误：' . $error->getMessage() . PHP_EOL);
        exit(64);
    }
}
