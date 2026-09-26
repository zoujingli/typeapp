<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
expect(in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true) && $argc === 1, '标准应用原生构建需要Unix目标');
// 共用前端身份及临时版本配置，Swoole仍由type-build按已锁定ABI选择。
echo successful([PHP_BINARY, $root . '/tools/build-application.php'], $root);
