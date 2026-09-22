<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\NativeBuilder;

$root = dirname(__DIR__);
$base = $root . '/build/compiler-tokenizer-' . bin2hex(random_bytes(4));
expect(mkdir($base . '/ext', 0700, true), '无法创建词法模块夹具目录');
$module = $base . '/ext/tokenizer.so';
expect(file_put_contents($module, 'fixture') !== false, '无法写入词法模块夹具');
$resolved = realpath($module);
expect(is_string($resolved), '词法模块夹具路径无效');

expect(NativeBuilder::tokenizerLoadArguments(true, null) === [], '内置 tokenizer 不应追加扩展参数');
expect(NativeBuilder::tokenizerLoadArguments(true, $module) === [], '运行配置已有 tokenizer 时不应重复加载');
expect(
    NativeBuilder::tokenizerLoadArguments(false, $module) === ['-d', 'extension=' . $resolved],
    '缺少词法函数时必须把已有模块传给编译子进程'
);
$rejected = false;
try {
    NativeBuilder::tokenizerLoadArguments(false, $base . '/ext/missing.so');
} catch (RuntimeException $error) {
    $rejected = str_contains($error->getMessage(), 'tokenizer');
}
expect($rejected, '找不到词法模块时必须在编译前失败');

$ini = ";extension={$module}\nextension=curl.so\nextension=tokenizer\n";
expect(NativeBuilder::tokenizerModuleFromIni($ini, $base . '/ext') === $module, '应忽略注释并按扩展目录解析 tokenizer');
$absolute = "extension={$module}\n";
expect(NativeBuilder::tokenizerModuleFromIni($absolute, $base . '/empty') === $module, '绝对路径词法模块应直接使用');
expect(NativeBuilder::tokenizerModuleFromIni("extension=openssl.so\n", $base . '/ext') === $module, '未声明名称时回退到扩展目录中的 tokenizer.so');
expect(NativeBuilder::tokenizerModuleFromIni("extension=openssl.so\n", $base . '/empty') === null, '没有词法模块时不能伪造路径');
unlink($module);
rmdir($base . '/ext');
rmdir($base);

echo "compiler tokenizer ok\n";
