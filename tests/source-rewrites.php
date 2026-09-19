<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\SourceSet;
use Type\Build\SourceRewriter;

$root = dirname(__DIR__);
$packageRoot = $root . '/vendor/dragonmantank/cron-expression';
$package = json_decode(file_get_contents($packageRoot . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$provider = json_decode(file_get_contents($root . '/plugin/type-scheduler/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$metadata = $provider['extra']['type']['imports']['dragonmantank/cron-expression'];
$before = hash_file('sha256', $packageRoot . '/src/Cron/AbstractField.php');
$set = (new SourceSet())->describe($packageRoot, $package, $metadata);
$rewriter = new SourceRewriter();
$directory = $root . '/build/source-rewrites-' . bin2hex(random_bytes(6));
$result = $rewriter->apply($set['sources'], [$set], $directory);
expect(count($result['sources']) === count($set['sources']) && count($result['mapping']) === count($metadata['rewrites']), '源码适配遗漏了生产文件');
expect(hash_file('sha256', $packageRoot . '/src/Cron/AbstractField.php') === $before, '源码适配修改了 Composer 安装内容');
$mapping = $result['mapping'][0];
expect($mapping['original-sha256'] === $before && $mapping['generated-sha256'] !== $before && substr_count(file_get_contents($mapping['source']), "\n") === substr_count(file_get_contents($mapping['generated']), "\n"), '适配摘要或行号记录不正确');
$classes = [];
foreach ($result['mapping'] as $entry) {
    $classes['Cron\\' . pathinfo($entry['generated'], PATHINFO_FILENAME)] = $entry['generated'];
}
$loader = '$loader = require ' . var_export($root . '/vendor/autoload.php', true) . ';'
    . 'require ' . var_export($root . '/vendor/swoole/typephp/src/polyfills.php', true) . ';'
    . '$loader->addClassMap(' . var_export($classes, true) . ');';
$exercise = 'foreach (["*", "0", "59", "60", "*/5", "1-10", "1.2", "bad"] as $value) { echo json_encode((new Cron\\MinutesField())->validate($value)), "\\n"; } '
    . 'foreach (["0 0 15 * 1", "*/5 1-5 * * 2-6", "0 0 29 2 *"] as $expression) { foreach ((new Cron\\CronExpression($expression))->getMultipleRunDates(4, "2026-01-01", false, true, "UTC") as $date) { echo $date->format("c"), "\\n"; } }'
    . 'foreach ([new DateTime("2026-01-01 12:30:00"), new DateTimeImmutable("2026-01-01 12:30:00")] as $initial) { foreach ([new Cron\\MinutesField(), new Cron\\HoursField(), new Cron\\DayOfMonthField(), new Cron\\DayOfWeekField(), new Cron\\MonthField()] as $field) { foreach ([false, true] as $invert) { $date = clone $initial; $field->increment($date, $invert); echo get_class($date), ":", $date->format("c"), "\\n"; } } }';
$loader .= $exercise;
$adapted = successful([PHP_BINARY, '-r', $loader]);
$original = successful([PHP_BINARY, '-r', 'require ' . var_export($root . '/vendor/autoload.php', true) . ';' . $exercise]);
expect($adapted === $original, '适配改变了 Cron 的公开校验行为');
$bad = $set;
$bad['rewrites'][0]['sha256'] = str_repeat('0', 64);
$rejected = false;
try {
    $rewriter->apply($set['sources'], [$bad], $directory);
} catch (RuntimeException) {
    $rejected = true;
}
expect($rejected, '错误版本源码仍被套用适配');
$bad = $set;
$bad['rewrites'][0]['replacements'][0]['count'] = 2;
$rejected = false;
try {
    $rewriter->apply($set['sources'], [$bad], $directory);
} catch (RuntimeException) {
    $rejected = true;
}
expect($rejected, '替换次数不符仍生成结果');
echo "固定源码适配：完整覆盖、原包不变、前后哈希、行号、Cron 等价校验及错误输入拒绝通过。\n";
