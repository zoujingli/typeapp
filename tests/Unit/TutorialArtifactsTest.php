<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Type\Testing\Process;

require_once dirname(__DIR__) . '/support.php';
require_once dirname(__DIR__) . '/tutorial-artifacts.php';

/** 真文件故障验证失败证据先保全、再清理；不编译应用或复制 SDK。 */
final class TutorialArtifactsTest extends TestCase
{
    public function testFailedConsumerPreservesBytesInputsAndInterruptedDeployment(): void
    {
        $root = $this->directory();
        $consumer = $root . '/build/tutorial space sqlite-123456789abc';
        $deployment = $root . '/build/single program-123456789abc';
        $files = ['build/type-project' => "program\0bytes", 'build/type-project.build.json' => '{"status":"failed"}',
            'build/compiler/compile.log' => 'compiler failure', 'build/compiler/project.yml' => '{"sources":[]}',
            'package.log' => 'package failed', 'run.log' => 'run failed', 'composer.lock' => '{"packages":[]}',
            'type-app.json' => '{}', 'app/Main.php' => '<?php // exact source', 'config/app.php' => '<?php return [];'];
        try {
            foreach ($files as $path => $bytes) {
                $this->write($consumer . '/' . $path, $bytes);
            }
            $this->write($consumer . '/vendor/large-sdk.bin', 'must not be copied');
            $this->write($consumer . '/var/app.sqlite', 'must not be copied');
            $this->write($deployment . '/program only/bin/app', "exported\0program");
            $this->write($deployment . '/tutorial.log', 'interrupted deployment');
            $this->write($deployment . '/tutorial-smoke-process.json', '{"pid":110,"start":"smoke-owner"}');
            $this->write($deployment . '/tutorial-catalog-process.json', '{"pid":111,"start":"catalog-owner"}');
            $this->write($consumer . '/deployment-path.json', json_encode(['base' => $deployment, 'project' => $consumer], JSON_THROW_ON_ERROR));
            $record = \archiveTutorialConsumer($consumer, $root . '/evidence', $root);
            self::assertDirectoryDoesNotExist($consumer);
            foreach ($files as $path => $bytes) {
                self::assertSame($bytes, file_get_contents($root . '/evidence/' . $path));
                self::assertSame(hash('sha256', $bytes), $record['files'][$path]['sha256']);
            }
            self::assertFileDoesNotExist($root . '/evidence/vendor/large-sdk.bin');
            self::assertFileDoesNotExist($root . '/evidence/var/app.sqlite');
            self::assertSame("exported\0program", file_get_contents($root . '/evidence/deployment/program only/bin/app'));
            self::assertSame('{"pid":110,"start":"smoke-owner"}', file_get_contents($root . '/evidence/deployment/tutorial-smoke-process.json'));
            self::assertSame('{"pid":111,"start":"catalog-owner"}', file_get_contents($root . '/evidence/deployment/tutorial-catalog-process.json'));
            self::assertDirectoryExists($deployment);
            self::assertSame($deployment, $record['retained-deployment']);
            self::assertSame($record, json_decode(file_get_contents($root . '/evidence/preservation.json'), true, 512, JSON_THROW_ON_ERROR));
        } finally {
            \removeTestDirectory($root);
        }
    }

    public function testPreservationFailureKeepsConsumerAndDoesNotOverwriteEvidence(): void
    {
        $root = $this->directory();
        $consumer = $root . '/consumer';
        try {
            $this->write($consumer . '/build/type-project', 'original program');
            $this->write($consumer . '/package.log', 'failure diagnostics');
            $this->write($root . '/evidence/build/type-project', 'prior attempt program');
            try {
                \archiveTutorialConsumer($consumer, $root . '/evidence', $root);
                self::fail('摘要冲突必须拒绝清理');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('摘要不一致', $error->getMessage());
            }
            self::assertSame('original program', file_get_contents($consumer . '/build/type-project'));
            self::assertSame('failure diagnostics', file_get_contents($consumer . '/package.log'));
            self::assertSame('prior attempt program', file_get_contents($root . '/evidence/build/type-project'));
            self::assertFileDoesNotExist($root . '/evidence/preservation.json');
        } finally {
            \removeTestDirectory($root);
        }
    }

    public static function preservationCases(): iterable
    {
        yield 'preserved-before-cleanup' => [false];
        yield 'preservation-failed-keeps-original' => [true];
    }

    #[DataProvider('preservationCases')]
    public function testSingleProgramPreparationFailureIsPreservedBeforeCleanup(bool $conflict): void
    {
        $root = $this->directory();
        $project = $root . '/consumer';
        $deployment = null;
        try {
            $this->write($project . '/.env.example', 'APP_ENV=production');
            // 打包命令写入部分目标后失败；无需执行伪造原生文件即可观察准备阶段的真实收尾。
            $this->write($project . '/vendor/bin/type', <<<'PHP'
<?php
file_put_contents($argv[3], 'partial-program');
if (getenv('TYPE_TEST_PRESERVATION_CONFLICT') === '1') {
    $target = dirname(__DIR__, 2) . '/deployment-evidence/' . basename(dirname($argv[3], 3)) . '/program only/bin';
    mkdir($target, 0700, true);
    file_put_contents($target . '/' . basename($argv[3]), 'prior evidence');
}
fwrite(STDERR, 'package-preparation-failed');
exit(9);
PHP);
            $binary = substr_replace(str_pad("\x7fELF\x02\x01", 64, "\0"), pack('v', 2), 16, 2);
            $manifest = json_encode(['manifest-protocol' => 1, 'binary-format' => 'ELF', 'elf-bytes' => strlen($binary),
                'elf-sha256' => hash('sha256', $binary), 'build-id' => str_repeat('a', 64), 'runtime-linkage' => 'static',
                'native-libraries' => [], 'extension-modules' => [], 'resources' => [], 'profile' => ['database' => 'sqlite']], JSON_THROW_ON_ERROR);
            $this->write($root . '/program', $binary . $manifest . pack('N', strlen($manifest)) . 'TYPEAPP1');
            $environment = array_replace(getenv(), ['TYPE_PACKAGE_PROJECT' => $project, 'TYPE_PACKAGE_DRIVER' => 'sqlite',
                'TYPE_PACKAGE_TUTORIAL' => '1', 'TYPE_BWRAP_BINARY' => 'unused-preparation-only',
                'TYPE_TEST_PRESERVATION_CONFLICT' => $conflict ? '1' : '0']);
            $result = (new Process([PHP_BINARY, dirname(__DIR__) . '/native-single-program.php', $root . '/program'], dirname(__DIR__, 2), $environment))->wait(15);
            self::assertFalse($result->successful());
            self::assertStringContainsString($conflict ? '摘要不一致' : 'package-preparation-failed', $result->stdout . $result->stderr);
            $receipt = json_decode(file_get_contents($project . '/deployment-path.json'), true, 32, JSON_THROW_ON_ERROR);
            $deployment = $receipt['base'];
            self::assertSame($project, $receipt['project']);
            $evidence = $project . '/deployment-evidence/' . basename($deployment);
            $filename = PHP_OS_FAMILY === 'Windows' ? 'app.exe' : 'app';
            self::assertSame($conflict ? 'prior evidence' : 'partial-program', file_get_contents($evidence . '/program only/bin/' . $filename));
            $record = json_decode(file_get_contents($deployment . '/verification.json'), true, 32, JSON_THROW_ON_ERROR);
            self::assertSame('failed', $record['status']);
            self::assertStringContainsString('package-preparation-failed', $record['failure']);
            if ($conflict) {
                self::assertFileDoesNotExist($evidence . '/preservation.json');
                self::assertSame('partial-program', file_get_contents($deployment . '/program only/bin/' . $filename));
                self::assertDirectoryExists($deployment . '/runtime data');
            } else {
                self::assertFileExists($evidence . '/preservation.json');
                self::assertDirectoryDoesNotExist($deployment . '/program only');
                self::assertDirectoryDoesNotExist($deployment . '/runtime data');
            }
        } finally {
            if ($deployment === null && is_file($project . '/deployment-path.json')) {
                $deployment = json_decode(file_get_contents($project . '/deployment-path.json'), true, 32, JSON_THROW_ON_ERROR)['base'];
            }
            if ($deployment !== null && is_dir($deployment)) {
                \removeTestDirectory($deployment);
            }
            \removeTestDirectory($root);
        }
    }

    private function directory(): string
    {
        $root = \Type\Build\BuildPlatform::resolve(dirname(__DIR__, 2)) . '/build/tutorial-artifacts-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root, 0700));
        return $root;
    }

    private function write(string $path, string $bytes): void
    {
        if (!is_dir(dirname($path))) {
            self::assertTrue(mkdir(dirname($path), 0700, true));
        }
        self::assertSame(strlen($bytes), file_put_contents($path, $bytes));
    }
}
