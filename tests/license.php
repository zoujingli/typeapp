<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$license = file_get_contents($root . '/LICENSE');
$notice = file_get_contents($root . '/NOTICE');
expect(is_string($license) && strlen($license) > 10000, '根 LICENSE 不完整');
expect(is_string($notice) && str_contains($notice, 'Copyright 2026 Anyon')
    && str_contains($notice, 'https://iots.top'), 'NOTICE 缺少作者或官方网站');

$manifests = [$root . '/composer.json', $root . '/templates/type-project/composer.json', ...glob($root . '/plugin/type-*/composer.json')];
expect($manifests !== [] && count($manifests) === 17, '第一方 Composer 清单数量不符');
foreach ($manifests as $manifest) {
    $package = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
    expect(($package['license'] ?? null) === 'Apache-2.0', '第一方 Composer 清单未使用 Apache-2.0：' . str_replace($root . '/', '', $manifest));
}

$componentLicenses = glob($root . '/plugin/type-*/LICENSE');
expect(count($componentLicenses) === 15, '组件独立 LICENSE 数量不符');
foreach ($componentLicenses as $componentLicense) {
    expect(file_get_contents($componentLicense) === $license, '组件 LICENSE 与根文本不一致：' . str_replace($root . '/', '', $componentLicense));
}
expect(file_get_contents($root . '/templates/type-project/LICENSE') === $license, '模板 LICENSE 与根文本不一致');
foreach ([...glob($root . '/plugin/type-*'), $root . '/templates/type-project'] as $packageDirectory) {
    $packageNotice = file_get_contents($packageDirectory . '/NOTICE');
    expect(is_string($packageNotice) && str_contains($packageNotice, 'Copyright 2026 Anyon')
        && str_contains($packageNotice, 'Apache'), '独立仓库缺少第一方版权与许可通知');
}
$webLicense = file_get_contents($root . '/web/LICENSE');
expect(is_string($webLicense) && str_contains($webLicense, $license) && str_contains($webLicense, '../NOTICE')
    && str_contains($webLicense, 'UPSTREAM.md'), 'Web LICENSE 未保留完整文本及上游引用');
expect(file_get_contents($root . '/plugin/type-build/NOTICE') === $notice, '构建组件 NOTICE 与根文本不一致');

$lock = json_decode((string) file_get_contents($root . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
$localPackages = [];
foreach ([...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])] as $package) {
    if (is_string($package['name'] ?? null) && str_starts_with($package['name'], 'zoujingli/type-')) {
        $localPackages[$package['name']] = $package['license'] ?? null;
    }
    if (($package['name'] ?? null) === 'swoole/typephp') {
        expect(($package['license'] ?? null) === ['GPL-3.0-only'], 'swoole/typephp 的 GPL-3.0-only 声明被改写');
    }
}
expect(
    count($localPackages) === 15 && count(array_filter($localPackages, static fn (mixed $value): bool => $value !== ['Apache-2.0'])) === 0,
    'Composer lock 中第一方本地包许可证未同步'
);

// 第三方规范版权原文与验证门禁的负向测试输入。
$allowlist = [
    'docs/development/mqtt-conformance-matrix.json',
    'tests/dependency-notices.php',
    'tests/fixtures/build-plugin/composer.json',
    'tests/fixtures/imported-library/composer.json',
];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getPathname() === __FILE__) {
        continue;
    }
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (str_starts_with($relative, '.git/') || str_starts_with($relative, '.cache/') || str_starts_with($relative, 'vendor/')
        || str_starts_with($relative, 'build/') || str_starts_with($relative, '.workbuddy/') || str_starts_with($relative, 'node_modules/') || str_contains($relative, '/node_modules/')) {
        continue;
    }
    $contents = file_get_contents($file->getPathname());
    if (!is_string($contents) || str_contains($contents, "\0") || !str_contains($contents, 'proprietary')) {
        continue;
    }
    expect(in_array($relative, $allowlist, true), '当前第一方内容残留未允许的 proprietary：' . $relative);
}

echo "Apache-2.0 第一方清单、独立材料、第三方边界和测试材料白名单 校验通过。\n";
