<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Type\Testing\Process;
use TypeApp\Distribution\Batch;

require_once dirname(__DIR__) . '/support.php';
require_once dirname(__DIR__, 2) . '/tools/distribution/Process.php';
require_once dirname(__DIR__, 2) . '/tools/distribution/Batch.php';

/** 从真实 Git 对象核对分发材料，工作区副本不能补足提交中的缺项。 */
final class DistributionPackageTest extends TestCase
{
    /** 同一组真实提交分别经过单组件入口和批次计划检查。 */
    public static function packageContents(): iterable
    {
        yield 'complete' => ['', null, ''];
        yield 'missing-license' => ['LICENSE', null, '缺少分发文件'];
        yield 'missing-notice' => ['NOTICE', null, '缺少分发文件'];
        yield 'missing-readme' => ['README.md', null, '缺少分发文件'];
        yield 'truncated-license' => ['LICENSE', 'Apache-2.0', '完整许可文本'];
        yield 'empty-notice' => ['NOTICE', " \n", '分发说明不能为空'];
        yield 'empty-readme' => ['README.md', "\n", '分发说明不能为空'];
        yield 'unapproved-file' => ['auth.json', '{}', '分发包包含未允许内容'];
        yield 'unmapped-dependency' => ['composer.json', json_encode([
            'name' => 'zoujingli/type-runtime', 'type' => 'library', 'license' => 'Apache-2.0',
            'require' => ['zoujingli/type-unmapped' => '^1.0'],
        ], JSON_THROW_ON_ERROR), '插件依赖尚未映射'];
    }

    #[DataProvider('packageContents')]
    public function testPlanRequiresCompleteCommittedPackageMaterials(string $changed, ?string $content, string $failure): void
    {
        $root = dirname(__DIR__, 2);
        $directory = $root . '/build/distribution-package-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        try {
            foreach (['plugin/type-runtime/src', '.github', 'tools/distribution', 'bin'] as $path) {
                self::assertTrue(mkdir($directory . '/' . $path, 0700, true));
            }
            foreach (['tools/distribute-plugin.php', 'tools/distribution/Process.php', 'tools/distribution/Batch.php'] as $file) {
                self::assertTrue(copy($root . '/' . $file, $directory . '/' . $file));
            }
            $mapping = json_decode((string) file_get_contents($root . '/.github/distribution.json'), true, 512, JSON_THROW_ON_ERROR);
            $mapping['packages'] = ['type-runtime' => $mapping['packages']['type-runtime']];
            file_put_contents($directory . '/.github/distribution.json', json_encode($mapping, JSON_THROW_ON_ERROR));
            // 外部操作哨兵确保本地拒绝回归不会接触真实 GitHub。
            \writeTestPhpCommand($directory . '/bin/gh', 'fwrite(STDERR, "分发外部操作哨兵\n"); exit(99);');
            $package = $directory . '/plugin/type-runtime';
            $license = (string) file_get_contents($root . '/LICENSE');
            file_put_contents($directory . '/LICENSE', $license);
            file_put_contents($directory . '/toolchain.lock.json', '{}');
            file_put_contents($package . '/LICENSE', $license);
            file_put_contents($package . '/NOTICE', file_get_contents($root . '/NOTICE'));
            file_put_contents($package . '/README.md', '# 独立组件');
            file_put_contents($package . '/src/Value.php', '<?php');
            file_put_contents($package . '/composer.json', json_encode([
                'name' => 'zoujingli/type-runtime', 'type' => 'library', 'license' => 'Apache-2.0',
            ], JSON_THROW_ON_ERROR));
            if ($changed !== '') {
                if ($content === null) {
                    unlink($package . '/' . $changed);
                } else {
                    file_put_contents($package . '/' . $changed, $content);
                }
            }
            foreach ([['git', 'init', '-b', 'main'], ['git', 'config', 'user.name', '分发验收'],
                ['git', 'config', 'user.email', 'test@type-app.invalid'], ['git', 'add', '.'],
                ['git', '-c', 'commit.gpgsign=false', 'commit', '-m', 'test: 分发材料']] as $command) {
                \successful($command, $directory);
            }
            $source = trim(\successful(['git', 'rev-parse', 'HEAD'], $directory));
            \successful(['git', 'update-ref', 'refs/remotes/origin/main', $source], $directory);
            $environment = getenv();
            $environment = \testCommandEnvironment($directory . '/bin', $environment);
            $process = new Process([PHP_BINARY, $directory . '/tools/distribute-plugin.php', $source, 'type-runtime'], $directory, $environment);
            try {
                $result = $process->wait(30);
            } finally {
                $process->stop();
            }
            self::assertFalse($result->timedOut);
            self::assertSame(1, $result->exitCode);
            self::assertStringContainsString($failure === '' ? '分发外部操作哨兵' : $failure, $result->stderr);
            if ($failure !== '') {
                self::assertStringNotContainsString('分发外部操作哨兵', $result->stderr);
            }
            if ($changed !== '') {
                file_put_contents($package . '/' . $changed, $changed === 'LICENSE' ? $license : '工作区补齐不能改变固定提交');
            }
            if ($failure !== '') {
                $this->expectException(RuntimeException::class);
                $this->expectExceptionMessage($failure);
            }
            $plan = Batch::plan($directory, $source, 'branch', '', $mapping);
            self::assertSame(['type-runtime'], array_keys($plan['items']));
            self::assertSame(trim(\successful(['git', 'rev-parse', $source . ':plugin/type-runtime'], $directory)), $plan['items']['type-runtime']['tree']);
        } finally {
            \removeTestDirectory($directory);
        }
    }
}
