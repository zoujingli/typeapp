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
    /** @return iterable<string, array{string, string, bool}> 真实路径与其允许的构建目录。 */
    public static function candidatePaths(): iterable
    {
        yield 'windows-realpath' => ['C:\\workspace\\app\\build\\unpacked release', 'C:\\workspace\\app/build', true];
        yield 'windows-forward' => ['C:/workspace/app/build/unpacked release', 'C:/workspace/app/build', true];
        yield 'windows-sibling' => ['C:\\workspace\\app\\build-other\\release', 'C:\\workspace\\app/build', false];
        yield 'windows-outside' => ['C:\\workspace\\other\\release', 'C:\\workspace\\app/build', false];
        yield 'windows-unc' => ['\\\\server\\share\\app\\build\\release', '\\\\server\\share\\app/build', true];
        yield 'unix-realpath' => ['/workspace/app/build/unpacked release', '/workspace/app/build', true];
        yield 'unix-sibling' => ['/workspace/app/build-other/release', '/workspace/app/build', false];
        yield 'unix-backslash-is-a-filename' => ['/workspace/app/build\\release', '/workspace/app/build', false];
        yield 'root-is-not-a-candidate' => ['/workspace/app/build', '/workspace/app/build', false];
    }

    /** 验收入口按realpath得到的原生分隔符判定边界，Windows候选也必须能进入真实部署验证。 */
    #[DataProvider('candidatePaths')]
    public function testCandidatePathsAcceptNativeSeparatorsWithoutEscapingTheBuildDirectory(string $path, string $directory, bool $expected): void
    {
        self::assertSame($expected, \testPathIsWithin($path, $directory));
    }

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

    /** 前端身份必须来自实际锁文件，缺失不能被编码成false后继续校验通过。 */
    public function testFrontendIdentityRequiresTheOriginalDependencyLock(): void
    {
        $project = dirname(__DIR__, 2);
        $root = $project . '/build/frontend-identity-' . bin2hex(random_bytes(6));
        mkdir($root . '/tools/distribution', 0700, true);
        mkdir($root . '/vendor', 0700);
        mkdir($root . '/web/dist', 0700, true);
        try {
            copy($project . '/tools/frontend-resources.php', $root . '/tools/frontend-resources.php');
            copy($project . '/tools/distribution/Process.php', $root . '/tools/distribution/Process.php');
            file_put_contents($root . '/vendor/autoload.php', '<?php require ' . var_export($project . '/vendor/autoload.php', true) . ';');
            foreach (['index.html', 'LICENSE', 'NOTICE', 'UPSTREAM.md'] as $name) {
                file_put_contents($root . '/web/dist/' . $name, 'fixture');
            }
            $command = [PHP_BINARY, $root . '/tools/frontend-resources.php'];
            [$code, , $error] = \TypeApp\Distribution\Process::run([...$command, 'record', 'manifest.json'], $root);
            self::assertSame(1, $code);
            self::assertStringContainsString('依赖锁文件', $error);
            self::assertFileDoesNotExist($root . '/manifest.json');
            file_put_contents($root . '/web/pnpm-lock.yaml', 'frozen dependencies');
            self::assertSame(0, \TypeApp\Distribution\Process::run([...$command, 'record', 'manifest.json'], $root)[0]);
            self::assertSame(0, \TypeApp\Distribution\Process::run([...$command, 'verify', 'manifest.json'], $root)[0]);
            file_put_contents($root . '/web/pnpm-lock.yaml', 'changed dependencies');
            self::assertSame(1, \TypeApp\Distribution\Process::run([...$command, 'verify', 'manifest.json'], $root)[0]);
        } finally {
            \removeTestDirectory($root);
        }
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
    if (!in_array('Cache-Control: no-cache', $args, true)) { fwrite(STDERR, '发布列表必须请求重新验证缓存'); exit(5); }
    preg_match('~repos/([^/]+/[^/]+)/releases~', $args[1], $match);
    $repo = $match[1];
}
$key = $state . '/' . str_replace('/', '-', $repo);
$release = is_file($key . '.json') ? json_decode(file_get_contents($key . '.json'), true) : null;
if ($args[0] === 'api') {
    // GitHub写入成功后，列表可能暂时仍是旧视图；只延迟一次真实命令边界。
    if (is_file($key . '-stale.json')) {
        echo file_get_contents($key . '-stale.json');
        unlink($key . '-stale.json');
        $count = is_file($state . '/stale-read-count') ? (int) file_get_contents($state . '/stale-read-count') : 0;
        file_put_contents($state . '/stale-read-count', (string) ($count + 1));
        exit;
    }
    echo json_encode([$release === null ? [] : [$release]]); exit;
}
if (is_file($state . '/fail') && $repo === 'zoujingli/type-core') { fwrite(STDERR, '预期的单项发布失败'); exit(1); }
if ($args[1] === 'create') {
    $target = $args[array_search('--target', $args) + 1];
    $body = file_get_contents($args[array_search('--notes-file', $args) + 1]);
    $release = ['id' => 1, 'tag_name' => $args[2], 'target_commitish' => $target, 'body' => $body, 'draft' => true, 'prerelease' => true, 'assets' => [], 'html_url' => 'https://github.com/' . $repo . '/releases/tag/' . $args[2]];
    if (is_file($state . '/delay-create')) {
        file_put_contents($key . '-stale.json', '[[]]');
        unlink($state . '/delay-create');
    }
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
    if (is_file($state . '/delay-publish')) {
        file_put_contents($key . '-stale.json', json_encode([[$release]]));
        unlink($state . '/delay-publish');
    }
    $release['draft'] = false;
} else { exit(3); }
file_put_contents($key . '.json', json_encode($release));
PHP);
            putenv('PATH=' . $root . '/commands' . PATH_SEPARATOR . $originalPath);
            $api = new GitHub($root);
            $source = str_repeat('a', 40);
            $version = 'v1.0.0-rc.1';
            file_put_contents($root . '/remote/delay-create', 'once');
            file_put_contents($root . '/remote/delay-publish', 'once');
            $api->draft('zoujingli/type-runtime', $version, $source, '运行时');
            $first = $api->publish('zoujingli/type-runtime', $version);
            self::assertSame('2', file_get_contents($root . '/remote/stale-read-count'));
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
