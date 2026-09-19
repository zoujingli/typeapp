<?php

declare(strict_types=1);

use Type\Core\Config\Environment;
use Type\Core\Config\Repository;
use TypeApp\Generated\ConfigurationFixture;

/** 配置原生验收入口；业务配置只经生成类和只读环境对象进入进程。 */
function main(int $argc, array $argv): void
{
    if ($argc !== 2) {
        throw new InvalidArgumentException('配置验收必须传入明确的测试 dotenv 文件');
    }
    $environment = Environment::load((string) $argv[1]);
    $configuration = ConfigurationFixture::load($environment);
    if ($configuration->text('app.name') !== '运行时配置' || $configuration->integer('app.port') !== 9527
        || !$configuration->boolean('app.debug') || $configuration->get('app.ratio') !== 1.25
        || !$configuration->has('app.nullable') || $configuration->get('app.nullable', 'fallback') !== null
        || $configuration->has('app.missing') || $configuration->get('app.missing', 'fallback') !== 'fallback'
        || $configuration->array('app.nested') !== ['enabled' => true]) {
        throw new RuntimeException('生成配置的运行时类型或点路径语义不一致');
    }
    $shared = ['name' => '初始快照'];
    $input = ['nested' => &$shared];
    $snapshot = new Repository($input);
    $shared['name'] = '外部修改';
    $copy = $snapshot->array('nested');
    $copy['name'] = '副本修改';
    if ($snapshot->text('nested.name') !== '初始快照') {
        throw new RuntimeException('原生配置快照没有隔离外部引用');
    }
    $traceFile = tempnam(sys_get_temp_dir(), 'type-config-trace-');
    if ($traceFile === false) {
        throw new RuntimeException('无法创建配置调用栈验收文件');
    }
    $secret = 'configuration-secret-canary';
    try {
        file_put_contents($traceFile, 'TOKEN="' . $secret . "\n");
        ini_set('zend.exception_ignore_args', '0');
        ini_set('zend.exception_string_param_max_len', '4096');
        $rejected = false;
        try {
            Environment::load($traceFile);
        } catch (RuntimeException $error) {
            $rejected = !str_contains($error->getMessage(), $secret) && !str_contains($error->getTraceAsString(), $secret);
        }
        if (!$rejected) {
            throw new RuntimeException('原生配置异常调用栈没有脱敏');
        }
    } finally {
        unlink($traceFile);
    }
    echo "运行时配置、不可变快照与点路径类型读取通过。\n";
}
