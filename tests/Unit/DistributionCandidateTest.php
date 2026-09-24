<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Type\Testing\Process;

require_once dirname(__DIR__) . '/support.php';

/** 经真实 Git 快照执行候选准备，在安装前验证固定输入及失败报告。 */
final class DistributionCandidateTest extends TestCase
{
    /** 导出属性可能删减或改写归档，固定候选必须拒绝这种字节偏离。 */
    public static function archiveAttributes(): iterable
    {
        yield 'committed-inputs' => ['', '需要显式TYPE_COMPOSER_PHAR'];
        yield 'automatic-crlf' => ['', '需要显式TYPE_COMPOSER_PHAR', true];
        yield 'export-ignore' => ["src/Value.php export-ignore\n", 'Git 快照文件集合不一致'];
        yield 'export-subst' => ["src/Value.php export-subst\n", 'Git 快照字节不一致'];
    }

    /** 验证候选构建读取固定 Git 输入，并在 Git 属性或后续准备失败时保全对应证据。 */
    #[DataProvider('archiveAttributes')]
    public function testCandidatePreparationKeepsGitInputsAndFailureEvidence(string $attributes, string $expectedFailure, bool $autocrlf = false): void
    {
        $root = dirname(__DIR__, 2);
        $directory = $root . '/build/candidate-preparation-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        try {
            foreach (['tests', 'tools/distribution', '.github', 'plugin/type-runtime/src', 'templates/type-project', 'examples', 'vendor', 'build'] as $path) {
                self::assertTrue(mkdir($directory . '/' . $path, 0700, true));
            }
            foreach (['tests/distribution-candidate.php', 'tests/support.php', 'tests/native-database.php',
                'tools/distribution/Process.php', 'tools/distribution/Batch.php'] as $file) {
                self::assertTrue(copy($root . '/' . $file, $directory . '/' . $file));
            }
            file_put_contents($directory . '/vendor/autoload.php', '<?php require ' . var_export($root . '/vendor/autoload.php', true) . ';');
            file_put_contents($directory . '/.gitignore', "/vendor/\n/build/\n");
            $mapping = json_decode((string) file_get_contents($root . '/.github/distribution.json'), true, 512, JSON_THROW_ON_ERROR);
            $mapping['packages'] = ['type-runtime' => $mapping['packages']['type-runtime']];
            file_put_contents($directory . '/.github/distribution.json', json_encode($mapping, JSON_THROW_ON_ERROR));
            self::assertTrue(copy($root . '/.github/template-distribution.json', $directory . '/.github/template-distribution.json'));
            file_put_contents($directory . '/plugin/type-runtime/composer.json', json_encode([
                'name' => 'zoujingli/type-runtime', 'type' => 'library', 'license' => 'Apache-2.0',
            ], JSON_THROW_ON_ERROR));
            file_put_contents($directory . '/plugin/type-runtime/src/Value.php', '<?php /* $Format:%H$ */');
            foreach (['LICENSE', 'NOTICE'] as $file) {
                self::assertTrue(copy($root . '/' . $file, $directory . '/' . $file));
                self::assertTrue(copy($root . '/' . $file, $directory . '/plugin/type-runtime/' . $file));
            }
            file_put_contents($directory . '/plugin/type-runtime/README.md', '# 独立组件');
            file_put_contents($directory . '/templates/type-project/composer.json', '{"name":"zoujingli/type-project"}');
            $entry = "<?php\nfunction main(): void {}\n";
            $toolchain = "{\"fixture\":\"committed-toolchain\"}\n";
            file_put_contents($directory . '/examples/native-command.php', $entry);
            file_put_contents($directory . '/toolchain.lock.json', $toolchain);
            foreach ([['git', 'init', '-b', 'main'], ['git', 'config', 'user.name', '候选验收'],
                ['git', 'config', 'core.autocrlf', $autocrlf ? 'true' : 'false'],
                ['git', 'config', 'user.email', 'test@type-app.invalid'], ['git', 'add', '.'],
                ['git', '-c', 'commit.gpgsign=false', 'commit', '-m', 'test: 固定候选输入']] as $command) {
                \successful($command, $directory);
            }
            $source = trim(\successful(['git', 'rev-parse', 'HEAD'], $directory));
            file_put_contents($directory . '/.git/info/attributes', $attributes);
            file_put_contents($directory . '/examples/native-command.php', '<?php /* uncommitted-entry */');
            file_put_contents($directory . '/toolchain.lock.json', '{"fixture":"uncommitted-toolchain"}');
            $environment = getenv();
            $environment['TYPE_COMPOSER_PHAR'] = $directory . '/missing-composer.phar';
            $process = new Process([PHP_BINARY, $directory . '/tests/distribution-candidate.php', $source], $directory, $environment);
            try {
                $result = $process->wait(30);
            } finally {
                $process->stop();
            }
            self::assertFalse($result->timedOut);
            self::assertFalse($result->successful());
            self::assertStringContainsString($expectedFailure, $result->stdout . $result->stderr);
            $candidates = glob($directory . '/build/distribution-candidate-*');
            self::assertCount(1, $candidates);
            $candidate = $candidates[0];
            self::assertFileExists($candidate . '/verification.json');
            $report = json_decode((string) file_get_contents($candidate . '/verification.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('failed', $report['status']);
            self::assertSame($source, $report['source']);
            self::assertStringContainsString($expectedFailure, $report['failure']);
            if ($attributes === '') {
                self::assertSame($entry, file_get_contents($candidate . '/consumer/app/main.php'));
                self::assertSame($toolchain, file_get_contents($candidate . '/consumer/toolchain.lock.json'));
                self::assertSame(['type-runtime'], array_keys($report['components']));
            } else {
                self::assertSame([], $report['components']);
            }
        } finally {
            \removeTestDirectory($directory);
        }
    }
}
