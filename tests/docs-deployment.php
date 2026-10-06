<?php

declare(strict_types=1);

/**
 * 文档站发布边界一致性检查。
 *
 * 静态导出清单定义在 docs/build-site.sh 的 site_files，发布白名单独立于
 * tools/deploy-docs-site.sh（部署 runner 由管理员单独安装，不会随仓库更新）。
 * 两处一旦漂移，线上同步会拒绝白名单之外的内容并保留旧版本。本检查在提交前
 * 拦截漂移，避免只有到服务器同步时才暴露。
 */

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$builder = (string) file_get_contents($root . '/docs/build-site.sh');
$runner = (string) file_get_contents($root . '/tools/deploy-docs-site.sh');

/** 读取括号内的导出清单。 */
$exported = [];
if (preg_match('/site_files=\(([^)]*)\)/', $builder, $matched) !== 1) {
    expect(false, 'docs/build-site.sh 缺少 site_files 导出清单');
}
foreach (preg_split('/\s+/', trim((string) $matched[1])) ?: [] as $entry) {
    if ($entry !== '') {
        $exported[] = $entry;
    }
}

// 部署脚本的两处 required 清单：第一处为必须存在的站点条目，第二处为必须非空的文件。
$required = [];
if (preg_match_all('/for required in ([^;]+); do/', $runner, $matched) < 2) {
    expect(false, 'tools/deploy-docs-site.sh 缺少两处 required 站点清单');
}
foreach ([0, 1] as $index) {
    $entries = [];
    foreach (preg_split('/\s+/', trim((string) $matched[1][$index])) ?: [] as $entry) {
        if ($entry !== '') {
            $entries[] = $entry;
        }
    }
    $required[] = $entries;
}

// 每个通道共用公开内容清单，额外包含导出时生成的身份脚本。
$exported[] = 'channel.js';
if (preg_match('/^\s+(index\.html\|[^)\n]+)\)/m', $runner, $matched) !== 1) {
    expect(false, 'tools/deploy-docs-site.sh 缺少发布白名单文件条目');
}
$whitelist = explode('|', (string) $matched[1]);
if (preg_match('/^\s+((?:assets|guide)\|[^)\n]*)\)/m', $runner, $matched) !== 1) {
    expect(false, 'tools/deploy-docs-site.sh 缺少发布白名单目录条目');
}
foreach (explode('|', (string) $matched[1]) as $entry) {
    if (!str_contains($entry, '*')) {
        $whitelist[] = $entry;
    }
}

$failures = [];
$describe = static function (array $entries): string {
    sort($entries);

    return implode(' ', $entries);
};

if ($describe($whitelist) !== $describe($exported)) {
    $failures[] = sprintf(
        '发布白名单与导出清单不一致：白名单 %s；导出 %s',
        $describe($whitelist),
        $describe($exported)
    );
}
if ($describe($required[0]) !== $describe($exported)) {
    $failures[] = sprintf(
        '必需站点清单与导出清单不一致：必需 %s；导出 %s',
        $describe($required[0]),
        $describe($exported)
    );
}
foreach ($required[1] as $entry) {
    if (!in_array($entry, $exported, true)) {
        $failures[] = '非空站点文件不在导出清单中：' . $entry;
    }
}
foreach ($exported as $entry) {
    if ($entry === 'channel.js') {
        continue;
    }
    // 根许可证与归属文件从项目根复制，其余条目来自 docs/。
    $sources = in_array($entry, ['LICENSE', 'NOTICE'], true)
        ? [$root . '/' . $entry]
        : [$root . '/docs/' . $entry];
    foreach ($sources as $source) {
        if (!file_exists($source)) {
            $failures[] = '导出清单条目不存在：' . $entry;
        }
    }
}

expect(
    $failures === [],
    sprintf("文档站发布边界不一致，共 %d 处：\n%s", count($failures), implode("\n", $failures))
);

printf("文档站发布边界一致性检查通过：%s\n", $describe($exported));

// 指定真实导出后，核对每个通道自己的身份、许可证、导航和正文链接。
// Windows 的基础检查仍不依赖 Bash；导出与 runner 行为由隔离脚本验证。
$output = $argv[1] ?? null;
if ($output !== null) {
    $output = realpath($output);
    expect(is_string($output) && is_file($output . '/site-manifest.json'), '需要真实的双通道导出目录');
    $manifest = json_decode(file_get_contents($output . '/site-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($manifest['protocol'] === 1, '未知站点身份协议');
    foreach (['release' => '', 'next' => '/next'] as $channel => $suffix) {
        $base = $output . $suffix;
        $metadata = file_get_contents($base . '/channel.js');
        expect(preg_match('/^window.TYPEAPP_DOCS = (\{.*\});\s*$/D', $metadata, $match) === 1, '通道身份脚本无效');
        $metadata = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        expect($metadata['channel'] === $channel && $metadata['contentIdentity'] === $manifest[$channel]['contentIdentity'], '通道身份不一致');
        expect($channel !== 'next' || ($metadata['productCommit'] === null && $metadata['productTag'] === null && $metadata['componentBatch'] === 'dev-main'), '开发文档冒充已发布产品');
        foreach ($exported as $entry) {
            expect(file_exists($base . '/' . $entry), '通道缺少公开内容：' . $channel . '/' . $entry);
        }
        $files = [$base . '/README.md', $base . '/_sidebar.md', $base . '/_navbar.md', $base . '/_404.md'];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base . '/guide', FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'md') {
                $files[] = $file->getPathname();
            }
        }
        foreach ($files as $file) {
            $text = file_get_contents($file);
            preg_match_all('/\]\(([^)\s]+)\)/', $text, $links);
            foreach ($links[1] as $target) {
                if (preg_match('/^(?:https?:|mailto:|#)/', $target) === 1) {
                    continue;
                }
                $target = preg_split('/[?#]/', $target)[0];
                if ($target === '/') {
                    $target = '/README.md';
                }
                $path = realpath((str_starts_with($target, '/') ? $base : dirname($file)) . '/' . ltrim($target, '/'));
                expect(is_string($path) && str_starts_with($path, $base . '/') && is_file($path), '公开链接未随通道导出：' . substr($file, strlen($output) + 1) . ' -> ' . $target);
            }
        }
        $hashes = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = substr($file->getPathname(), strlen($base) + 1);
            if (str_starts_with($relative, 'next/') || in_array($relative, ['channel.js', 'site-manifest.json'], true)) {
                continue;
            }
            expect(!$file->isLink() && $file->isFile(), '导出含非普通文件');
            $hashes['./' . $relative] = hash_file('sha256', $file->getPathname());
        }
        ksort($hashes, SORT_STRING);
        $identity = '';
        foreach ($hashes as $path => $hash) {
            $identity .= $hash . ' ' . $path . "\n";
        }
        expect(hash('sha256', $identity) === $metadata['contentIdentity'], '实际站点内容与封存摘要不符：' . $channel);
    }
    printf("双通道真实导出、内容摘要及公开链接检查通过\n");
}
