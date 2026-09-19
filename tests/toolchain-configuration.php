<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$configuration = $argv[1] ?? (getenv('PHP_HOME') ?: '') . '/bin/php-config';
expect(in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true) && is_file($configuration), '需要本机锁定Unix SDK的php-config');
$target = $root . '/build/toolchain-configuration-' . bin2hex(random_bytes(6));
expect(mkdir($target, 0700), '无法创建SDK选择测试目录');
$command = [PHP_BINARY, $root . '/tools/configure-toolchain.php', $target, $configuration];
$selected = trim(successful($command, $root));
expect(trim(successful($command, $root)) === $selected, '重复SDK选择不稳定');
$record = json_decode(file_get_contents($selected . '/identity.json'), true, 512, JSON_THROW_ON_ERROR);
$extension = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
expect($record['platform'] === PHP_OS_FAMILY && $record['php'] === PHP_VERSION, 'SDK选择遗漏实际平台/版本');
expect(realpath($selected . '/bin/php') === realpath(PHP_BINARY), 'SDK没有选择当前PHP二进制');
expect(realpath($selected . '/lib/libphp.' . $extension) === $record['library'], 'SDK指向错误的embed库');
expect(trim(successful([$selected . '/bin/php-config', '--prefix'])) === $selected, '配置包装器没有固定SDK前缀');
expect(realpath(trim(successful([$selected . '/bin/php-config', '--lib-embed']))) === $record['library'], '配置包装器返回错误库');
$platform = PHP_OS_FAMILY === 'Darwin' ? 'macos' : 'linux';
echo successful([PHP_BINARY, $root . '/tools/verify-toolchain.php', $platform]);
[$status] = execute([PHP_BINARY, $root . '/tools/verify-toolchain.php', $platform === 'macos' ? 'linux' : 'macos']);
expect($status !== 0, '错误平台被视为已核验');
file_put_contents($selected . '/identity.json', '{}');
[$status] = execute($command, $root);
expect($status !== 0, '已有SDK身份被修改后仍可复用');
file_put_contents($target . '/verification.json', json_encode(['platform' => PHP_OS_FAMILY, 'php' => PHP_VERSION,
    'checks' => ['actual-sdk-selection', 'same-binary-and-embed', 'repeatable-wrapper', 'source-lock', 'wrong-platform-rejected', 'tamper-rejected']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo '原生SDK选择与工具链锁校验通过：' . $target . "/verification.json\n";
