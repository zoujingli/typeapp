<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$base = $argv[1] ?? getenv('DCO_BASE_SHA') ?: '';
$head = $argv[2] ?? getenv('DCO_HEAD_SHA') ?: '';
expect(
    preg_match('/^[a-f0-9]{40}$/D', $base) === 1 && preg_match('/^[a-f0-9]{40}$/D', $head) === 1,
    'DCO 检查需要两个完整提交 SHA'
);
$commits = trim(successful(['git', 'rev-list', $base . '..' . $head], $root));
foreach ($commits === '' ? [] : explode("\n", $commits) as $commit) {
    $message = successful(['git', 'show', '-s', '--format=%B', $commit], $root);
    expect(
        preg_match('/^Signed-off-by:\s+.+\s+<[^<>\s]+@[^<>\s]+>\s*$/mi', $message) === 1,
        '提交缺少有效 Signed-off-by：' . $commit
    );
}
echo "DCO Signed-off-by 检查通过。\n";
