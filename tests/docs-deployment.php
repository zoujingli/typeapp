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

// 发布白名单的顶层条目：文件分支，加上 assets 与 guide 两个目录分支的目录名。
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
