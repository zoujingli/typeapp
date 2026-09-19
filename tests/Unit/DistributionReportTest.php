<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Type\Testing\Process;
use TypeApp\Distribution\Batch;
use TypeApp\Distribution\Publisher;

require_once dirname(__DIR__) . '/support.php';
require_once dirname(__DIR__, 2) . '/tools/distribution/Process.php';
require_once dirname(__DIR__, 2) . '/tools/distribution/Batch.php';
require_once dirname(__DIR__, 2) . '/tools/distribution/Publisher.php';

/** 经真实 Git 发布得到报告，在下游命令入口验证完整性，外部服务只允许触达拒绝哨兵。 */
final class DistributionReportTest extends TestCase
{
    /** 每个入口都必须独立拒绝被改写的报告，不能只相信完成标志。 */
    public static function reports(): iterable
    {
        foreach (['template', 'consumer'] as $entry) {
            foreach (['valid-branch', 'valid-tag', 'id', 'source', 'protocol', 'mode', 'version',
                'failed-item', 'item-split', 'item-reference', 'item-source', 'missing-item', 'extra-item', 'shallow'] as $change) {
                yield $entry . '-' . $change => [$entry, $change];
            }
        }
        yield 'consumer-valid-workspace' => ['consumer', 'valid-workspace'];
    }

    #[DataProvider('reports')]
    public function testDownstreamChecksTheEntireCommittedBatch(string $entry, string $change): void
    {
        $root = dirname(__DIR__, 2);
        $directory = $root . '/build/distribution-report-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        try {
            foreach (['plugin/type-runtime/src', '.github', 'tools/distribution', 'tests', 'examples', 'bin', 'build'] as $path) {
                self::assertTrue(mkdir($directory . '/' . $path, 0700, true));
            }
            foreach (['tools/distribute-template.php', 'tools/distribution/Process.php', 'tools/distribution/Batch.php',
                'tools/distribution/Publisher.php', 'tests/batch-consumer.php', 'tests/support.php'] as $file) {
                self::assertTrue(copy($root . '/' . $file, $directory . '/' . $file));
            }
            $mapping = json_decode((string) file_get_contents($root . '/.github/distribution.json'), true, 512, JSON_THROW_ON_ERROR);
            $mapping['packages'] = ['type-runtime' => $mapping['packages']['type-runtime']];
            file_put_contents($directory . '/.github/distribution.json', json_encode($mapping, JSON_THROW_ON_ERROR));
            foreach (['gh', 'composer'] as $tool) {
                file_put_contents($directory . '/bin/' . $tool, "#!/bin/sh\nprintf '%s\\n' '下游外部操作哨兵' >&2\nexit 99\n");
                self::assertTrue(chmod($directory . '/bin/' . $tool, 0755));
            }
            file_put_contents($directory . '/.gitignore', "/build/\n");
            $toolchain = "{\"fixture\":\"fixed-toolchain\"}\n";
            $example = "<?php\nfunction main(): void {}\n";
            file_put_contents($directory . '/toolchain.lock.json', $toolchain);
            file_put_contents($directory . '/examples/native-command.php', $example);
            file_put_contents($directory . '/LICENSE', file_get_contents($root . '/LICENSE'));
            foreach (['LICENSE', 'NOTICE', 'README.md'] as $file) {
                self::assertTrue(copy($root . '/plugin/type-runtime/' . $file, $directory . '/plugin/type-runtime/' . $file));
            }
            file_put_contents($directory . '/plugin/type-runtime/src/Value.php', '<?php');
            file_put_contents($directory . '/plugin/type-runtime/composer.json', json_encode([
                'name' => 'zoujingli/type-runtime', 'type' => 'library', 'license' => 'Apache-2.0',
            ], JSON_THROW_ON_ERROR));
            foreach ([['git', 'init', '-b', 'main'], ['git', 'config', 'user.name', '报告验收'],
                ['git', 'config', 'user.email', 'test@type-app.invalid'], ['git', 'add', '.'],
                ['git', '-c', 'commit.gpgsign=false', 'commit', '-m', 'test: 固定下游批次']] as $command) {
                \successful($command, $directory);
            }
            $source = trim(\successful(['git', 'rev-parse', 'HEAD'], $directory));
            \successful(['git', 'update-ref', 'refs/remotes/origin/main', $source], $directory);
            $remote = $directory . '/build/type-runtime.git';
            \successful(['git', 'init', '--bare', $remote], $directory);
            $tag = $change === 'valid-tag';
            $plan = Batch::plan($directory, $source, $tag ? 'tag' : 'branch', $tag ? 'v1.0.0' : '', $mapping);
            $report = Batch::collect($plan, ['type-runtime' => Publisher::publish($directory, $remote, $plan, 'type-runtime')]);
            self::assertTrue($report['complete']);
            switch ($change) {
                case 'id': $report['id'] = str_repeat('0', 64);
                    break;
                case 'source': $report['source'] = str_repeat('0', 40);
                    break;
                case 'protocol': $report['protocol'] = 2;
                    break;
                case 'mode': $report['mode'] = 'tag';
                    break;
                case 'version': $report['version'] = 'v1.0.0';
                    break;
                case 'failed-item': $report['items']['type-runtime']['status'] = 'failed';
                    break;
                case 'item-split': $report['items']['type-runtime']['split'] = str_repeat('0', 40);
                    break;
                case 'item-reference': $report['items']['type-runtime']['reference'] = 'refs/heads/other';
                    break;
                case 'item-source': $report['items']['type-runtime']['source'] = str_repeat('0', 40);
                    break;
                case 'missing-item': $report['items'] = [];
                    break;
                case 'extra-item': $report['items']['type-extra'] = $report['items']['type-runtime'];
                    break;
            }
            if ($change === 'valid-workspace') {
                file_put_contents($directory . '/.github/distribution.json', '{}');
                file_put_contents($directory . '/toolchain.lock.json', '{"fixture":"uncommitted"}');
                file_put_contents($directory . '/examples/native-command.php', '<?php /* uncommitted */');
            }
            $checkout = $directory;
            if ($change === 'shallow') {
                $checkout = $directory . '/build/shallow';
                \successful(['git', 'clone', '--depth=1', 'file://' . $directory, $checkout], $directory);
                self::assertTrue(mkdir($checkout . '/build', 0700));
            }
            $reportPath = $checkout . '/build/batch-result.json';
            file_put_contents($reportPath, json_encode($report, JSON_THROW_ON_ERROR));
            $environment = getenv();
            $environment['PATH'] = $directory . '/bin' . PATH_SEPARATOR . ($environment['PATH'] ?? '');
            $environment['COMPOSER_BINARY'] = $directory . '/bin/composer';
            $command = $entry === 'template'
                ? [PHP_BINARY, $checkout . '/tools/distribute-template.php', $source, $reportPath]
                : [PHP_BINARY, $checkout . '/tests/batch-consumer.php', $reportPath];
            $process = new Process($command, $checkout, $environment);
            try {
                $result = $process->wait(30);
            } finally {
                $process->stop();
            }
            self::assertFalse($result->timedOut);
            self::assertFalse($result->successful());
            $output = $result->stdout . $result->stderr;
            if (str_starts_with($change, 'valid-')) {
                self::assertStringContainsString('下游外部操作哨兵', $output);
                if ($entry === 'consumer') {
                    $consumers = glob($checkout . '/build/batch-consumer-*');
                    self::assertCount(1, $consumers);
                    $consumer = $consumers[0];
                    $composer = json_decode((string) file_get_contents($consumer . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
                    self::assertSame(['zoujingli/type-runtime' => $tag ? 'v1.0.0' : 'dev-main#' . $plan['items']['type-runtime']['split']], $composer['require']);
                    self::assertFileExists($consumer . '/main.php');
                    self::assertSame($example, file_get_contents($consumer . '/main.php'));
                    self::assertSame($toolchain, file_get_contents($consumer . '/toolchain.lock.json'));
                }
            } else {
                self::assertStringContainsString('批次', $output);
                self::assertStringNotContainsString('下游外部操作哨兵', $output);
                self::assertSame([], glob($checkout . '/build/batch-consumer-*'));
            }
        } finally {
            $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($entries as $file) {
                if ($file->isDir() && !$file->isLink()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($directory);
        }
    }
}
