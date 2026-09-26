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

/** 使用本地裸仓执行真实模板发布与克隆，GitHub 元数据和 Composer 在测试边界截断。 */
final class TemplateDistributionTest extends TestCase
{
    /** 远端已写入与重新克隆验证是两个独立结果。 */
    public static function publicationCases(): iterable
    {
        yield 'success' => ['success'];
        yield 'clone-failed' => ['clone-failed'];
        yield 'publish-failed' => ['publish-failed'];
        yield 'invalid-batch' => ['invalid-batch'];
        foreach (['dispatch', 'partial-suite', 'failed-job', 'skipped-summary', 'wrong-sha', 'wrong-repository',
            'wrong-branch', 'wrong-workflow', 'pull-request', 'incomplete-jobs'] as $case) {
            yield $case => [$case];
        }
    }

    /** 验证模板发布报告区分准备、发布和检出结果，重复发布保留正确的固定批次身份。 */
    #[DataProvider('publicationCases')]
    public function testPublicationReportIncludesCheckoutOutcome(string $case): void
    {
        $directory = dirname(__DIR__, 2) . '/build/template-distribution-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        try {
            [$source, $environment, $batch] = $this->prepare($directory, false);
            $environment['TYPE_TEST_NATIVE_CASE'] = $case;
            $expectedSuccess = in_array($case, ['success', 'dispatch'], true);
            if ($case === 'clone-failed') {
                $environment['GIT_CONFIG_KEY_1'] = 'url.' . \testGitFileUrl($directory . '/build/missing.git') . '.insteadOf';
            } elseif ($case === 'publish-failed') {
                $environment['GIT_CONFIG_KEY_0'] = 'url.' . \testGitFileUrl($directory . '/build/missing.git') . '.insteadOf';
            } elseif ($case === 'invalid-batch') {
                $batch['id'] = str_repeat('0', 64);
                file_put_contents($directory . '/build/distribution/batch-result.json', json_encode($batch, JSON_THROW_ON_ERROR));
                file_put_contents($directory . '/build/distribution/template.json', '{"status":"published","checkout-verified":true}');
            }
            $result = $this->publish($directory, $source, $environment);
            self::assertSame($expectedSuccess, $result->successful(), $result->stdout . $result->stderr);
            $report = json_decode((string) file_get_contents($directory . '/build/distribution/template.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($source, $report['source']);
            self::assertSame($expectedSuccess, $report['checkout-verified']);
            if ($expectedSuccess) {
                self::assertSame('https://github.com/zoujingli/typeapp/actions/runs/123/attempts/2', $report['native-ci']);
                self::assertSame('published', $report['status']);
                self::assertSame('published', $report['publish-status']);
                self::assertSame($batch['id'], $report['framework-batch']);
                $checkout = trim($result->stdout);
                self::assertSame($report['split'], trim(\successful(['git', 'rev-parse', 'HEAD'], $checkout)));
                self::assertSame($report['tree'], trim(\successful(['git', 'rev-parse', 'HEAD^{tree}'], $checkout)));
                $repeat = $this->publish($directory, $source, $environment);
                self::assertTrue($repeat->successful(), $repeat->stderr);
                $again = json_decode((string) file_get_contents($directory . '/build/distribution/template.json'), true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('already-current', $again['status']);
                self::assertTrue($again['checkout-verified']);
            } else {
                self::assertSame('failed', $report['status']);
                self::assertNotEmpty($report['error']);
                if ($case === 'clone-failed') {
                    self::assertSame('published', $report['publish-status']);
                    self::assertSame('checkout', $report['stage']);
                    self::assertSame($report['split'], trim(\successful(['git', '--git-dir=' . $directory . '/build/template.git', 'rev-parse', 'refs/heads/main'], $directory)));
                } elseif ($case === 'publish-failed') {
                    self::assertSame('failed', $report['publish-status']);
                    self::assertSame('publish', $report['stage']);
                } else {
                    self::assertSame('preparation', $report['stage']);
                    self::assertStringContainsString($case === 'invalid-batch' ? '批次' : '原生 CI', $report['error']);
                    self::assertSame('', trim(\successful(['git', '--git-dir=' . $directory . '/build/template.git', 'for-each-ref', 'refs/heads/'], $directory)));
                }
            }
        } finally {
            $this->remove($directory);
        }
    }

    /** 同批模板、报告和组件都必须准确，标签消费沿用标签模式。 */
    public static function consumerCases(): iterable
    {
        foreach (['branch', 'tag', 'wrong-batch', 'wrong-template-batch', 'failed-checkout', 'dirty-template',
            'ignored-template-file', 'wrong-template-commit', 'missing-template-source'] as $case) {
            yield $case => [$case];
        }
    }

    /** 验证独立模板消费者在配置前拒绝未验证、脏检出或批次不一致的输入。 */
    #[DataProvider('consumerCases')]
    public function testRemoteConsumerRejectsUnverifiedInputsBeforeConfiguration(string $case): void
    {
        $directory = dirname(__DIR__, 2) . '/build/template-distribution-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        try {
            [$source, $environment, $batch] = $this->prepare($directory, $case === 'tag');
            $published = $this->publish($directory, $source, $environment);
            self::assertTrue($published->successful(), $published->stderr);
            $template = trim($published->stdout);
            $environment['TYPE_TEMPLATE_SOURCE'] = $template;
            if ($case === 'wrong-batch') {
                $batch['id'] = str_repeat('0', 64);
                file_put_contents($directory . '/build/distribution/batch-result.json', json_encode($batch, JSON_THROW_ON_ERROR));
            } elseif (in_array($case, ['failed-checkout', 'wrong-template-batch'], true)) {
                $path = $directory . '/build/distribution/template.json';
                $report = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                if ($case === 'failed-checkout') {
                    $report['status'] = 'failed';
                    $report['checkout-verified'] = false;
                } else {
                    $report['framework-batch'] = str_repeat('0', 64);
                }
                file_put_contents($path, json_encode($report, JSON_THROW_ON_ERROR));
            } elseif ($case === 'dirty-template') {
                file_put_contents($template . '/README.md', '未提交的模板内容');
            } elseif ($case === 'ignored-template-file') {
                file_put_contents($template . '/.env', 'TEMPLATE_SENTINEL=local-only');
            } elseif ($case === 'wrong-template-commit') {
                \successful(['git', '-c', 'user.name=模板验收', '-c', 'user.email=test@type-app.invalid', '-c', 'commit.gpgsign=false', 'commit', '--allow-empty', '-m', 'test: 不同模板提交'], $template);
            } elseif ($case === 'missing-template-source') {
                unset($environment['TYPE_TEMPLATE_SOURCE']);
            }
            $process = new Process([PHP_BINARY, $directory . '/tests/application-template.php', 'sqlite', '--remote'], $directory, $environment);
            try {
                $result = $process->wait(30);
            } finally {
                $process->stop();
            }
            self::assertFalse($result->timedOut);
            self::assertFalse($result->successful());
            $output = $result->stdout . $result->stderr;
            $consumers = glob($directory . '/build/template-sqlite-*');
            if (in_array($case, ['branch', 'tag'], true)) {
                self::assertStringContainsString('模板安装哨兵', $output);
                self::assertCount(1, $consumers);
                $composer = json_decode((string) file_get_contents($consumers[0] . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
                foreach ($batch['items'] as $name => $item) {
                    $scope = in_array($name, ['type-build', 'type-testing'], true) ? 'require-dev' : 'require';
                    self::assertSame($case === 'tag' ? 'v1.0.0' : 'dev-main#' . $item['split'], $composer[$scope][$item['package']]);
                }
            } else {
                self::assertMatchesRegularExpression('/批次|模板/u', $output);
                self::assertStringNotContainsString('模板安装哨兵', $output);
                self::assertSame([], $consumers);
            }
        } finally {
            $this->remove($directory);
        }
    }

    /** @return array{string, array<string, string>, array<string, mixed>} 固定源码、隔离命令环境及真实组件回执。 */
    private function prepare(string $directory, bool $tag): array
    {
        $root = dirname(__DIR__, 2);
        foreach (['.github', 'tools/distribution', 'tests', 'bin', 'vendor', 'build/distribution',
            'templates/type-project/app/common/database', 'templates/type-project/scaffold'] as $path) {
            self::assertTrue(mkdir($directory . '/' . $path, 0700, true));
        }
        foreach (['tools/distribute-template.php', 'tools/distribution/Process.php', 'tools/distribution/Batch.php',
            'tools/distribution/Publisher.php', 'tests/application-template.php', 'tests/support.php',
            '.github/template-distribution.json', 'templates/type-project/configure.php', 'LICENSE'] as $file) {
            self::assertTrue(copy($root . '/' . $file, $directory . '/' . $file));
        }
        file_put_contents($directory . '/vendor/autoload.php', '<?php require ' . var_export($root . '/vendor/autoload.php', true) . ';');
        file_put_contents($directory . '/.gitignore', "/build/\n/vendor/\n");
        file_put_contents($directory . '/toolchain.lock.json', '{}');
        $mapping = json_decode((string) file_get_contents($root . '/.github/distribution.json'), true, 512, JSON_THROW_ON_ERROR);
        $mapping['packages'] = array_intersect_key($mapping['packages'], array_flip(['type-runtime', 'type-orm-sqlite', 'type-build', 'type-testing']));
        file_put_contents($directory . '/.github/distribution.json', json_encode($mapping, JSON_THROW_ON_ERROR));
        $composer = ['name' => 'zoujingli/type-project', 'type' => 'project', 'license' => 'Apache-2.0', 'require' => [], 'require-dev' => [], 'repositories' => []];
        foreach ($mapping['packages'] as $name => $package) {
            self::assertTrue(mkdir($directory . '/plugin/' . $name . '/src', 0700, true));
            foreach (['LICENSE', 'NOTICE', 'README.md'] as $file) {
                self::assertTrue(copy($root . '/plugin/' . $name . '/' . $file, $directory . '/plugin/' . $name . '/' . $file));
            }
            file_put_contents($directory . '/plugin/' . $name . '/src/Value.php', '<?php');
            file_put_contents($directory . '/plugin/' . $name . '/composer.json', json_encode([
                'name' => $package['composer-name'], 'type' => 'library', 'license' => 'Apache-2.0',
            ], JSON_THROW_ON_ERROR));
            $composer[in_array($name, ['type-build', 'type-testing'], true) ? 'require-dev' : 'require'][$package['composer-name']] = '~1.0.0@dev';
            $composer['repositories'][] = ['type' => 'git', 'url' => 'https://github.com/' . $package['repository'] . '.git'];
        }
        $template = $directory . '/templates/type-project';
        foreach (['LICENSE', 'NOTICE', 'README.md'] as $file) {
            self::assertTrue(copy($root . '/templates/type-project/' . $file, $template . '/' . $file));
        }
        file_put_contents($template . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
        file_put_contents($template . '/.gitignore', ".env\n");
        file_put_contents($template . '/scaffold/sqlite.php', '<?php');
        file_put_contents($template . '/app/common/database/DatabaseFactory.php', '<?php');
        \writeTestPhpCommand($directory . '/bin/composer', 'fwrite(STDERR, "模板安装哨兵\n"); exit(99);');
        \writeTestPhpCommand($directory . '/bin/gh', <<<'PHP'
$path = $argv[2] ?? '';
$case = getenv('TYPE_TEST_NATIVE_CASE') ?: 'success';
$source = getenv('TYPE_TEST_SOURCE_SHA');
if ($path === 'repos/zoujingli/typeapp/actions/workflows/native-command.yml/runs?head_sha=' . $source . '&status=success&per_page=100') {
    echo json_encode(['workflow_runs' => [['id' => 123, 'run_attempt' => 2, 'status' => 'completed',
        'head_sha' => $case === 'wrong-sha' ? str_repeat('0', 40) : $source, 'conclusion' => 'success',
        'path' => $case === 'wrong-workflow' ? '.github/workflows/other.yml' : '.github/workflows/native-command.yml',
        'event' => $case === 'pull-request' ? 'pull_request' : ($case === 'success' ? 'push' : 'workflow_dispatch'),
        'head_branch' => $case === 'wrong-branch' ? 'feature' : 'main',
        'head_repository' => ['full_name' => $case === 'wrong-repository' ? 'other/typeapp' : 'zoujingli/typeapp']]]]);
} elseif ($path === 'repos/zoujingli/typeapp/actions/runs/123/attempts/2/jobs?per_page=100') {
    $jobs = [['name' => 'Linux x64 原生验收 · foundation', 'head_sha' => $source, 'status' => 'completed',
        'conclusion' => $case === 'failed-job' ? 'failure' : 'success']];
    if ($case !== 'partial-suite') {
        $jobs[] = ['name' => 'native-complete', 'head_sha' => $source, 'status' => 'completed',
            'conclusion' => $case === 'skipped-summary' ? 'skipped' : 'success'];
    }
    echo json_encode(['total_count' => count($jobs) + ($case === 'incomplete-jobs' ? 1 : 0), 'jobs' => $jobs]);
} elseif ($path === 'repos/zoujingli/type-project') {
    echo json_encode(['full_name' => 'zoujingli/type-project', 'private' => false, 'visibility' => 'public', 'archived' => false]);
} else {
    fwrite(STDERR, "未允许的测试元数据请求\n");
    exit(99);
}
PHP);
        foreach ([['git', 'init', '-b', 'main'], ['git', 'config', 'user.name', '模板验收'],
            ['git', 'config', 'user.email', 'test@type-app.invalid'], ['git', 'add', '.'],
            ['git', '-c', 'commit.gpgsign=false', 'commit', '-m', 'test: 固定模板分发']] as $command) {
            \successful($command, $directory);
        }
        $source = trim(\successful(['git', 'rev-parse', 'HEAD'], $directory));
        \successful(['git', 'update-ref', 'refs/remotes/origin/main', $source], $directory);
        $plan = Batch::plan($directory, $source, $tag ? 'tag' : 'branch', $tag ? 'v1.0.0' : '', $mapping);
        $reports = [];
        foreach ($mapping['packages'] as $name => $package) {
            $remote = $directory . '/build/' . $name . '.git';
            \successful(['git', 'init', '--bare', $remote], $directory);
            $reports[$name] = Publisher::publish($directory, $remote, $plan, $name);
        }
        $batch = Batch::collect($plan, $reports);
        self::assertTrue($batch['complete']);
        file_put_contents($directory . '/build/distribution/batch-result.json', json_encode($batch, JSON_THROW_ON_ERROR));
        \successful(['git', 'init', '--bare', '--initial-branch=main', $directory . '/build/template.git'], $directory);
        $environment = getenv();
        unset($environment['TYPE_TEMPLATE_SOURCE'], $environment['TYPE_COMPOSER_PHAR']);
        $environment['TYPE_TEST_NATIVE_CASE'] = 'success';
        $environment['TYPE_TEST_SOURCE_SHA'] = $source;
        $environment = \testCommandEnvironment($directory . '/bin', $environment);
        $environment['COMPOSER_BINARY'] = $directory . '/bin/composer';
        $environment['GIT_CONFIG_COUNT'] = '3';
        $environment['GIT_CONFIG_KEY_0'] = 'url.' . \testGitFileUrl($directory . '/build/template.git') . '.insteadOf';
        $environment['GIT_CONFIG_VALUE_0'] = 'git@github.com:zoujingli/type-project.git';
        $environment['GIT_CONFIG_KEY_1'] = $environment['GIT_CONFIG_KEY_0'];
        $environment['GIT_CONFIG_VALUE_1'] = 'https://github.com/zoujingli/type-project.git';
        $environment['GIT_CONFIG_KEY_2'] = 'protocol.file.allow';
        $environment['GIT_CONFIG_VALUE_2'] = 'always';
        return [$source, $environment, $batch];
    }

    /** 执行实际发布 CLI，所有 Git 远端在该子进程内改写为本轮本地裸仓。 */
    private function publish(string $directory, string $source, array $environment): \Type\Testing\ProcessResult
    {
        $batchFile = $directory . '/build/distribution/batch-result.json';
        $batch = json_decode((string) file_get_contents($batchFile), true, 512, JSON_THROW_ON_ERROR);
        $process = new Process([PHP_BINARY, $directory . '/tools/distribute-template.php', $source, $batchFile, $batch['mode'], $batch['version']], $directory, $environment);
        try {
            $result = $process->wait(30);
            self::assertFalse($result->timedOut);
            return $result;
        } finally {
            $process->stop();
        }
    }

    /** 仅回收本轮拥有的临时目录，不跟随链接。 */
    private function remove(string $directory): void
    {
        \removeTestDirectory($directory);
    }
}
