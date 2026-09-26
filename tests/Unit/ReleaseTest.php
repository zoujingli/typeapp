<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeApp\Distribution\Batch;
use TypeApp\Release\Evidence;
use TypeApp\Release\GitHub;
use TypeApp\Release\Plan;

require_once dirname(__DIR__) . '/support.php';
require_once dirname(__DIR__, 2) . '/tools/distribution/Process.php';
require_once dirname(__DIR__, 2) . '/tools/distribution/Batch.php';
require_once dirname(__DIR__, 2) . '/tools/release/Plan.php';
require_once dirname(__DIR__, 2) . '/tools/release/Evidence.php';
require_once dirname(__DIR__, 2) . '/tools/release/GitHub.php';

/** 发布资格测试使用完整任务证据；Release外部边界使用保存真实文件的命令哨兵。 */
final class ReleaseTest extends TestCase
{
    public static function invalidVersions(): iterable
    {
        foreach (['1.0.0', 'v01.0.0', 'v1.0', 'v1.0.0-beta.1', 'v1.0.0-rc.0', 'v1.0.0-rc.01', "v1.0.0\n", 'main', 'v1.0.0+local'] as $version) {
            yield [$version];
        }
    }

    #[DataProvider('invalidVersions')]
    public function testOnlyImmutableSupportedVersionFormsAreAccepted(string $version): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Plan::version($version);
    }

    public function testReleaseMustSatisfyEveryFirstPartyDependency(): void
    {
        Plan::version('v1.0.0');
        Plan::version('v1.0.0-rc.1');
        $package = ['name' => 'example/app', 'require' => ['zoujingli/type-core' => '~1.0.0@dev']];
        Plan::dependencies($package, 'v1.0.0-rc.1', ['zoujingli/type-core']);
        $this->expectException(\RuntimeException::class);
        Plan::dependencies($package, 'v2.0.0', ['zoujingli/type-core']);
    }

    public static function evidenceCases(): iterable
    {
        foreach (['native', 'consumption'] as $kind) {
            foreach (['valid', 'sha', 'tag', 'attempt', 'workflow', 'missing', 'failed', 'duplicate', 'pagination'] as $change) {
                yield $kind . '-' . $change => [$kind, $change];
            }
        }
    }

    /** 正在执行的发布流程只接受已完成且完整的前置任务，失败任务不能被汇总掩盖。 */
    #[DataProvider('evidenceCases')]
    public function testEvidenceIsBoundToSourceTagAttemptAndCompleteJobs(string $kind, string $change): void
    {
        $source = str_repeat('a', 40);
        $run = ['id' => 123, 'run_attempt' => 2, 'head_sha' => $source, 'head_branch' => 'v1.0.0-rc.1',
            'head_repository' => ['full_name' => 'zoujingli/typeapp'], 'path' => '.github/workflows/release.yml', 'event' => 'push', 'status' => 'in_progress'];
        $names = ['distribute / plan', 'distribute / collect', 'distribute / consume', 'template / template'];
        if ($kind === 'native') {
            $names = ['release-native-complete', 'linux-x64 / native-complete', 'macos-arm64 / macos-complete', 'linux-arm64 / linux-arm64-complete', 'windows-x64 / windows'];
            foreach (['foundation', 'http', 'drivers', 'queries', 'models', 'data', 'cache', 'queue', 'scheduler', 'consumers', 'reliability', 'rollout', 'integration', 'tls', 'isolated-build', 'app', 'delivery', 'packaged-rollout', 'services'] as $suite) {
                $names[] = 'linux-x64 / Linux x64 原生验收 · ' . $suite;
            }
            foreach (['contracts', 'application', 'deployment', 'rollout', 'recovery', 'http', 'orm', 'reliable'] as $suite) {
                $names[] = 'macos-arm64 / macOS ARM64 · ' . $suite;
            }
            foreach (['contracts', 'orm', 'database', 'http', 'redis', 'tasks', 'application', 'recovery', 'rollout'] as $suite) {
                $names[] = 'linux-arm64 / Linux ARM64 · ' . $suite;
            }
        }
        $jobs = ['total_count' => count($names), 'jobs' => array_map(static fn (string $name): array => ['name' => $name, 'head_sha' => $source, 'status' => 'completed', 'conclusion' => 'success'], $names)];
        switch ($change) {
            case 'sha': $run['head_sha'] = str_repeat('b', 40);
                break;
            case 'tag': $run['head_branch'] = 'main';
                break;
            case 'attempt': $run['run_attempt'] = 1;
                break;
            case 'workflow': $run['path'] = '.github/workflows/native-command.yml';
                break;
            case 'missing': array_pop($jobs['jobs']);
                $jobs['total_count']--;
                break;
            case 'failed': $jobs['jobs'][2]['conclusion'] = 'failure';
                break;
            case 'duplicate': $jobs['jobs'][] = $jobs['jobs'][0];
                $jobs['total_count']++;
                break;
            case 'pagination': $jobs['total_count']++;
                break;
        }
        if ($change !== 'valid') {
            $this->expectException(\RuntimeException::class);
        }
        if ($kind === 'native') {
            Batch::verifyReleaseEvidence($run, $jobs, $source, 'v1.0.0-rc.1', 123, 2);
        } else {
            Evidence::verifyConsumptionJobs($run, $jobs, $source, 'v1.0.0-rc.1', 123, 2);
        }
        self::assertSame('valid', $change);
    }

    /** 中途失败后保留成功项；同名附件同摘要可重复执行，改变字节必须拒绝。 */
    public function testReleaseRetriesPreservePublishedItemsAndRejectAssetConflicts(): void
    {
        $root = dirname(__DIR__, 2) . '/build/release-api-test-' . bin2hex(random_bytes(6));
        mkdir($root . '/.github', 0700, true);
        mkdir($root . '/commands', 0700);
        mkdir($root . '/remote', 0700);
        copy(dirname(__DIR__, 2) . '/.github/distribution.json', $root . '/.github/distribution.json');
        $originalPath = getenv('PATH');
        try {
            \writeTestPhpCommand($root . '/commands/gh', <<<'PHP'
$state = getcwd() . '/remote';
$args = array_slice($argv, 1);
$repo = '';
foreach ($args as $i => $value) { if ($value === '--repo') { $repo = $args[$i + 1]; } }
if ($args[0] === 'api') {
    preg_match('~repos/([^/]+/[^/]+)/releases~', $args[1], $match);
    $repo = $match[1];
}
$key = $state . '/' . str_replace('/', '-', $repo);
$release = is_file($key . '.json') ? json_decode(file_get_contents($key . '.json'), true) : null;
if ($args[0] === 'api') { echo json_encode([$release === null ? [] : [$release]]); exit; }
if (is_file($state . '/fail') && $repo === 'zoujingli/type-core') { fwrite(STDERR, '预期的单项发布失败'); exit(1); }
if ($args[1] === 'create') {
    $target = $args[array_search('--target', $args) + 1];
    $body = file_get_contents($args[array_search('--notes-file', $args) + 1]);
    $release = ['id' => 1, 'tag_name' => $args[2], 'target_commitish' => $target, 'body' => $body, 'draft' => true, 'prerelease' => true, 'assets' => [], 'html_url' => 'https://github.com/' . $repo . '/releases/tag/' . $args[2]];
} elseif ($args[1] === 'upload') {
    $file = $args[3]; $name = basename($file);
    copy($file, $key . '-' . $name);
    $release['assets'][] = ['name' => $name];
} elseif ($args[1] === 'download') {
    $name = $args[array_search('--pattern', $args) + 1];
    $directory = $args[array_search('--dir', $args) + 1];
    copy($key . '-' . $name, $directory . '/' . $name);
} elseif ($args[1] === 'edit') {
    if (!in_array('--latest=false', $args, true)) { exit(2); }
    $release['draft'] = false;
} else { exit(3); }
file_put_contents($key . '.json', json_encode($release));
PHP);
            putenv('PATH=' . $root . '/commands' . PATH_SEPARATOR . $originalPath);
            $api = new GitHub($root);
            $source = str_repeat('a', 40);
            $version = 'v1.0.0-rc.1';
            $api->draft('zoujingli/type-runtime', $version, $source, '运行时');
            $first = $api->publish('zoujingli/type-runtime', $version);
            file_put_contents($root . '/remote/fail', 'fail');
            try {
                $api->draft('zoujingli/type-core', $version, $source, '核心');
                self::fail('故障没有传播');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('预期的单项发布失败', $error->getMessage());
            }
            unlink($root . '/remote/fail');
            self::assertSame($first, $api->publish('zoujingli/type-runtime', $version));
            $api->draft('zoujingli/type-core', $version, $source, '核心');
            self::assertSame('published', $api->publish('zoujingli/type-core', $version)['status']);
            $api->draft('zoujingli/typeapp', $version, $source, '候选');
            file_put_contents($root . '/payload.zip', 'verified bytes');
            $api->asset('zoujingli/typeapp', $version, $root . '/payload.zip');
            $api->asset('zoujingli/typeapp', $version, $root . '/payload.zip');
            file_put_contents($root . '/payload.zip', 'different bytes');
            $this->expectExceptionMessage('同名Release附件摘要冲突');
            $api->asset('zoujingli/typeapp', $version, $root . '/payload.zip');
        } finally {
            putenv($originalPath === false ? 'PATH' : 'PATH=' . $originalPath);
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($root);
        }
    }
}
