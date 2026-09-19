<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\DependencyNotices;

$base = dirname(__DIR__) . '/build/notices-' . bin2hex(random_bytes(6));
expect(mkdir($base . '/app', 0700, true) && mkdir($base . '/library', 0700), '无法创建材料测试夹具');
file_put_contents($base . '/app/composer.json', json_encode(['name' => 'fixture/app', 'license' => 'proprietary'], JSON_THROW_ON_ERROR));
file_put_contents($base . '/app/LICENSE', "Private fixture: no distribution grant.\n");
$original = "Copyright test fixture\r\nLicense wording preserved byte-for-byte.\r\n";
file_put_contents($base . '/library/composer.json', json_encode(['name' => 'fixture/library', 'license' => ['MIT', 'BSD-2-Clause']], JSON_THROW_ON_ERROR));
file_put_contents($base . '/library/LICENSE.txt', $original);
file_put_contents($base . '/native.so', 'native fixture bytes, never executed');
file_put_contents($base . '/native-license.txt', "Native fixture license text.\n");
$packages = [
    'fixture/app' => ['root' => $base . '/app', 'version' => '1.0.0', 'license' => null, 'kind' => 'application'],
    'fixture/library' => ['root' => $base . '/library', 'version' => '2.0.0', 'license' => null, 'kind' => 'composer'],
];
$libraries = [['name' => 'native.so', 'path' => $base . '/native.so', 'sha256' => hash_file('sha256', $base . '/native.so')]];
$declaration = ['require-complete' => true, 'native' => [PHP_OS_FAMILY => ['native.so' => [
    'binary-sha256' => $libraries[0]['sha256'], 'component' => 'fixture-native', 'version' => '3.0.0', 'license' => 'MIT',
    'files' => [['file' => $base . '/native-license.txt', 'sha256' => hash_file('sha256', $base . '/native-license.txt')]],
]]]];
$collector = new DependencyNotices();
$result = $collector->collect($base . '/generated', $packages, $libraries, $declaration);
$index = json_decode(file_get_contents($base . '/generated/dependencies.json'), true, 512, JSON_THROW_ON_ERROR);
expect($index['material-coverage'] === 'complete' && $index['legal-review'] === 'not-assessed'
    && $index['distribution-authorization'] === 'not-assessed', '材料完整被误报为法律结论或许可授权');
expect($index['components'][0]['license-declared'] === 'proprietary' && $index['components'][1]['license-declared'] === ['MIT', 'BSD-2-Clause'], '原许可声明被改写或替代许可被擅自选择');
$document = $index['components'][1]['documents'][0];
expect($document['sha256'] === hash('sha256', $original) && $document['bytes'] === strlen($original), '原文行尾或字节被改动');
expect(!str_contains(file_get_contents($base . '/generated/dependencies.json'), $base), '公开材料索引泄漏了构建绝对路径');
$again = $collector->collect($base . '/again', $packages, $libraries, $declaration);
expect($again['summary'] === $result['summary'], '同一材料输入没有稳定的摘要与状态');
file_put_contents($base . '/library/NOTICE', "New original notice.\n");
$added = $collector->collect($base . '/added', $packages, $libraries, $declaration);
expect($added['summary']['index-sha256'] !== $result['summary']['index-sha256'], '新增材料没有改变身份');
unlink($base . '/library/NOTICE');
$missing = $collector->collect($base . '/missing', $packages, $libraries);
expect($missing['summary']['material-coverage'] === 'incomplete' && isset($missing['summary']['missing']['native:native.so']), '原生库缺失材料被伪装为已完成');
$rejected = false;
try {
    $collector->collect($base . '/strict', $packages, $libraries, ['require-complete' => true]);
} catch (RuntimeException $error) {
    $rejected = str_contains($error->getMessage(), '未完整');
}
expect($rejected, '严格门禁没有拒绝缺失材料');
$unasserted = $declaration;
$unasserted['native'][PHP_OS_FAMILY]['native.so']['license'] = 'NOASSERTION';
$rejected = false;
try {
    $collector->collect($base . '/unasserted', $packages, $libraries, $unasserted);
} catch (RuntimeException) {
    $rejected = true;
}
expect($rejected, '未确认的许可声明绕过了严格材料门禁');
foreach (['binary-sha256', 'text-sha256', 'unknown-package', 'unknown-library'] as $case) {
    $invalid = $declaration;
    if ($case === 'binary-sha256') {
        $invalid['native'][PHP_OS_FAMILY]['native.so']['binary-sha256'] = str_repeat('0', 64);
    }
    if ($case === 'text-sha256') {
        $invalid['native'][PHP_OS_FAMILY]['native.so']['files'][0]['sha256'] = str_repeat('0', 64);
    }
    if ($case === 'unknown-package') {
        $invalid['packages']['unknown/package'] = [];
    }
    if ($case === 'unknown-library') {
        $invalid['native'][PHP_OS_FAMILY]['not-bundled.so'] = $invalid['native'][PHP_OS_FAMILY]['native.so'];
    }
    $rejected = false;
    try {
        $collector->collect($base . '/' . $case, $packages, $libraries, $invalid);
    } catch (RuntimeException) {
        $rejected = true;
    }
    expect($rejected, '错误材料绑定没有拒绝：' . $case);
}
file_put_contents($base . '/library/LICENSE.txt', $original . 'Changed notice.');
$changed = $collector->collect($base . '/changed', $packages, $libraries, $declaration);
expect($changed['summary']['index-sha256'] !== $result['summary']['index-sha256'], '材料变化没有改变构建输入摘要');
foreach (["<?php echo 'business source';", "-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"] as $contents) {
    file_put_contents($base . '/library/LICENSE.txt', $contents);
    $rejected = false;
    try {
        $collector->collect($base . '/unsafe', $packages, $libraries);
    } catch (RuntimeException) {
        $rejected = true;
    }
    expect($rejected, '伪装成材料的业务PHP或秘密没有拒绝');
}
file_put_contents($base . '/library/LICENSE.txt', $original);
file_put_contents($base . '/verification.json', json_encode(['checks' => ['original-declarations', 'unchanged-text-bytes', 'no-legal-conclusion', 'no-build-path',
    'deterministic', 'incomplete-report', 'strict-gate', 'binary-and-text-binding', 'unknown-items-rejected', 'changed-material-identity', 'source-secret-rejection']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
echo '依赖材料原文、声明、绑定、缺失门禁与秘密拒绝通过：' . $base . "/verification.json\n";
