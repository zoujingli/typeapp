<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;
use Type\Build\BuildIdentity;
use Type\Build\BuildPlatform;
use TypeApp\Distribution\Process;
use TypeApp\Release\TutorialEvidence;

require_once dirname(__DIR__) . '/support.php';
require_once dirname(__DIR__, 2) . '/tools/distribution/Process.php';
require_once dirname(__DIR__, 2) . '/tools/release/TutorialEvidence.php';

/** 教程发布门槛拒绝历史基线、混合产物和缺失隔离行为；真实原生执行由三库入口验证。 */
final class TutorialEvidenceTest extends TestCase
{
    /** 每个数据库均可独立通过候选或准确版本校验；两种来源不能交换。 */
    public function testProfileBindsItsChannelProgramAndEntireProductionClosure(): void
    {
        foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
            foreach (['fixed-candidate', 'packagist-tag'] as $channel) {
                $arguments = $this->profile($driver, $channel);
                TutorialEvidence::profile(...$arguments);
                self::assertTrue(true);
                $mutations = [
                    [0, ['status'], 'built-not-verified'], [0, ['delivery'], 'native-directory'],
                    [0, ['consumption-mode'], $channel === 'fixed-candidate' ? 'packagist-tag' : 'fixed-candidate'],
                    [0, ['version'], 'v0.9.0'], [0, ['artifact-sha256'], str_repeat('0', 64)],
                    [0, ['verified-splits', 'type-core'], str_repeat('0', 40)],
                    [1, ['artifact-sha256'], str_repeat('0', 64)], [1, ['build-id'], str_repeat('0', 64)],
                    [1, ['initialization', 'tutorial-public-assertions'], false],
                    [2, ['runtime-linkage'], 'dynamic'], [2, ['profile', 'database'], 'wrong'],
                    [2, ['production-packages'], []], [2, ['native-libraries'], ['libphp.so']],
                    [2, ['resources'], ['app.php']], [2, ['static-runtime'], []],
                    [2, ['static-archives'], []], [2, ['runtime-extensions'], []],
                    [3, ['packages', 1], ['name' => 'uncompiled/production']],
                    [4, ['sha256'], str_repeat('0', 64)], [4, ['source-sets'], []],
                    [4, ['source-sets', 'uninstalled/package'], ['exclusions' => []]],
                    [4, ['source-sets', 'zoujingli/type-core', 'exclusions'], ['src/Required.php']],
                    [4, ['identity'], []], [4, ['identity', 'id'], str_repeat('0', 64)],
                    [4, ['identity', 'description', 'facts', 'workspace'], $arguments[4]['identity']['description']['facts']['workspace'] . '/other'],
                    [4, ['original-sources'], []], [4, ['declaration-metadata'], []],
                    [4, ['source-sets', 'zoujingli/type-core', 'sources'], [$arguments[4]['identity']['description']['facts']['workspace'] . '/uncompiled.php']],
                ];
                foreach (['source-and-sdk-read-denied', 'ordinary-start-writes-no-files', 'runtime-profile-enforced',
                    'single-executable-only', 'readonly-program-directory', 'different-cwd'] as $check) {
                    $mutations[] = [1, [$check], false];
                }
                foreach (['declaration-generation', 'toolchain-lock-sha256', 'composer-lock-sha256', 'resource-generation'] as $key) {
                    $mutations[] = [4, ['manifest', $key], str_repeat('0', 64)];
                }
                if ($channel === 'packagist-tag') {
                    $mutations[] = [3, ['packages', 0, 'source', 'reference'], str_repeat('0', 40)];
                    $mutations[] = [3, ['packages', 0, 'version'], 'v0.9.0'];
                    $mutations[] = [3, ['packages', 0, 'dist', 'type'], 'path'];
                } else {
                    $mutations[] = [3, ['packages', 0, 'dist', 'type'], 'zip'];
                }
                foreach ($mutations as [$position, $path, $value]) {
                    $broken = $arguments;
                    $field = &$broken[$position];
                    foreach ($path as $key) {
                        $field = &$field[$key];
                    }
                    $field = $value;
                    unset($field);
                    // 直接改嵌入身份时也更新构建报告，证明字段自身的要求不能被一致伪造绕过。
                    if ($position === 2) {
                        $broken[4]['manifest'] = $broken[2];
                    }
                    $this->rejected(static fn () => TutorialEvidence::profile(...$broken));
                }
                $recomputed = $arguments;
                $recomputed[4]['identity']['description']['facts']['workspace'] .= '/relabeled';
                $recomputed[4]['identity']['id'] = BuildIdentity::digest($recomputed[4]['identity']['description']);
                $this->rejected(static fn () => TutorialEvidence::profile(...$recomputed), '教程构建输入没有绑定封存程序');
            }
        }
    }

    /** 原程序保持不变时，重写教程或候选拆分声明不能冒充新输入。夹具只验证证据读取，不运行原生代码。 */
    public function testOriginalProgramRejectsRelabeledTutorialAndComponentBytes(): void
    {
        $root = dirname(__DIR__, 2);
        $directory = $root . '/build/tutorial-input-proof-' . bin2hex(random_bytes(6));
        $repository = $directory . '/repository';
        mkdir($repository . '/examples/catalog', 0700, true);
        mkdir($repository . '/plugin/type-core/src', 0700, true);
        mkdir($repository . '/plugin/type-build/src', 0700, true);
        mkdir($repository . '/templates/type-project/app', 0700, true);
        $previousGit = getenv('GIT_DIR');
        $previousWorktree = getenv('GIT_WORK_TREE');
        putenv('GIT_DIR');
        putenv('GIT_WORK_TREE');
        try {
            $tutorialBytes = "<?php // original tutorial\n";
            $componentBytes = "<?php // original component\n";
            file_put_contents($repository . '/examples/catalog/test.php', $tutorialBytes);
            file_put_contents($repository . '/plugin/type-core/src/Core.php', $componentBytes);
            file_put_contents($repository . '/plugin/type-core/composer.json', json_encode([
                'name' => 'zoujingli/type-core', 'extra' => ['type' => ['protocol' => 1, 'sources' => ['src']]],
            ], JSON_THROW_ON_ERROR));
            file_put_contents($repository . '/plugin/type-build/composer.json', '{"name":"zoujingli/type-build"}');
            file_put_contents($repository . '/plugin/type-build/src/Compiler.php', "<?php // original compiler\n");
            file_put_contents($repository . '/templates/type-project/composer.json', json_encode([
                'extra' => ['type-template' => ['paths' => ['composer.json', 'app']]],
            ], JSON_THROW_ON_ERROR));
            file_put_contents($repository . '/templates/type-project/app/Application.php', "<?php // original template\n");
            Process::output(['git', 'init', '--quiet', $repository], $root);
            $git = static fn (array $arguments): string => Process::output(['git', '-c', 'commit.gpgsign=false', '-C', $repository, ...$arguments], $root);
            $git(['add', '.']);
            $git(['-c', 'user.name=Tutorial test', '-c', 'user.email=tutorial@example.invalid', 'commit', '--quiet', '-m', 'Original inputs']);
            $source = $git(['rev-parse', 'HEAD']);
            $split = $git(['rev-parse', 'HEAD:plugin/type-core']);
            $toolSplit = $git(['rev-parse', 'HEAD:plugin/type-build']);
            $templateSplit = $git(['rev-parse', 'HEAD:templates/type-project']);
            [$receipt, $deployment, $identity, $lock, $build] = $this->profile('sqlite', 'fixed-candidate');
            $receipt['verified-splits']['type-core'] = $split;
            $receipt['verified-splits']['type-build'] = $toolSplit;
            $lock['packages-dev'] = [['name' => 'zoujingli/type-build', 'version' => 'dev-main', 'dist' => ['type' => 'path']]];
            $workspace = $build['identity']['description']['facts']['workspace'];
            $sourcePath = $build['original-sources'][0];
            $lockBytes = json_encode($lock, JSON_THROW_ON_ERROR);
            $identity['composer-lock-sha256'] = hash('sha256', $lockBytes);
            $declaration = ['tutorial-sources' => ['test.php' => hash('sha256', $tutorialBytes)],
                'lock-sha256' => $identity['composer-lock-sha256'], 'composer-sha256' => hash('sha256', 'consumer composer'),
                'template-source-sha256' => BuildIdentity::digest([
                    'composer.json' => hash_file('sha256', $repository . '/templates/type-project/composer.json'),
                    'app/Application.php' => hash_file('sha256', $repository . '/templates/type-project/app/Application.php'),
                ]),
                'installed-production-inputs' => ['vendor/zoujingli/type-core/src/Core.php' => hash('sha256', $componentBytes)]];
            $declarationBytes = json_encode($declaration, JSON_THROW_ON_ERROR);
            $description = $build['identity']['description'];
            $description['inputs']['original-sources'][$sourcePath] = ['sha256' => hash('sha256', $componentBytes), 'bytes' => strlen($componentBytes)];
            $description['inputs']['locks'][$workspace . '/composer.lock'] = ['sha256' => $identity['composer-lock-sha256'], 'bytes' => strlen($lockBytes)];
            $description['inputs']['declarations'] = [
                $workspace . '/composer.json' => ['sha256' => $declaration['composer-sha256']],
                $workspace . '/vendor/zoujingli/type-core/composer.json' => ['sha256' => hash_file('sha256', $repository . '/plugin/type-core/composer.json')],
            ];
            $description['inputs']['native-inputs'] = [$workspace . '/catalog-candidate.json' => ['sha256' => hash('sha256', $declarationBytes)]];
            foreach (['composer.json', 'src/Compiler.php'] as $path) {
                $description['inputs']['tooling'][$workspace . '/vendor/zoujingli/type-build/' . $path] = ['sha256' => hash_file('sha256', $repository . '/plugin/type-build/' . $path)];
            }
            $identity['declaration-generation'] = BuildIdentity::digest(['sources' => [hash('sha256', $componentBytes)], 'generation' => $build['declaration-metadata']]);
            $description['facts']['declaration-generation'] = $identity['declaration-generation'];
            $description = BuildIdentity::canonical($description);
            $receipt['build-id'] = $deployment['build-id'] = $identity['build-id'] = $build['build-id'] = BuildIdentity::digest($description);
            $build['identity'] = ['id' => $identity['build-id'], 'description' => $description];
            $identity['runtime'] = ['os' => 'Linux', 'architecture' => 'x86_64'];
            $binary = substr_replace(str_pad("\x7fELF\x02\x01", 64, "\0"), pack('v', 2), 16, 2);
            $identity = BuildIdentity::canonical($identity + ['manifest-protocol' => 1, 'binary-format' => 'ELF',
                'elf-bytes' => strlen($binary), 'elf-sha256' => hash('sha256', $binary)]);
            $manifest = json_encode($identity, JSON_THROW_ON_ERROR);
            $program = $binary . $manifest . pack('N', strlen($manifest)) . 'TYPEAPP1';
            $receipt['artifact-sha256'] = $deployment['artifact-sha256'] = $build['sha256'] = hash('sha256', $program);
            $build['manifest'] = $identity;
            $deployment['platform'] = 'Linux';
            $deployment['architecture'] = 'x86_64';
            $deployment['initialization']['log-sha256'] = hash('sha256', 'public assertions');
            $items = ['type-project' => ['split' => $templateSplit], 'type-core' => ['split' => $split], 'type-build' => ['split' => $toolSplit]];
            $report = ['protocol' => 1, 'kind' => 'tutorial-profile-delivery', 'status' => 'passed', 'source' => $source,
                'channel' => 'fixed-candidate', 'version' => null, 'template-split' => $templateSplit, 'platform' => 'linux-x64',
                'temporary-inputs-removed' => true, 'profiles' => ['sqlite' => []]];
            $file = $directory . '/verification.json';
            $write = static function (string $kind, string $bytes) use ($directory, $file, &$report): void {
                file_put_contents($directory . '/' . $kind, $bytes);
                $report['profiles']['sqlite'][$kind] = ['file' => $kind, 'sha256' => hash('sha256', $bytes)];
                file_put_contents($file, json_encode($report, JSON_THROW_ON_ERROR));
            };
            foreach (['program' => $program, 'lock' => $lockBytes, 'tutorial' => $declarationBytes, 'deployment-log' => 'public assertions',
                'consumer' => json_encode($receipt, JSON_THROW_ON_ERROR), 'build' => json_encode($build, JSON_THROW_ON_ERROR),
                'deployment' => json_encode($deployment, JSON_THROW_ON_ERROR)] as $kind => $bytes) {
                $write($kind, $bytes);
            }
            putenv('GIT_DIR=' . $repository . '/.git');
            TutorialEvidence::verify($file, $source, 'fixed-candidate', null, $items, 'sqlite');

            file_put_contents($repository . '/plugin/type-core/src/Core.php', "<?php // changed component\n");
            $git(['add', '.']);
            $git(['-c', 'user.name=Tutorial test', '-c', 'user.email=tutorial@example.invalid', 'commit', '--quiet', '-m', 'New component inputs']);
            $report['source'] = $source = $git(['rev-parse', 'HEAD']);
            $items['type-core']['split'] = $receipt['verified-splits']['type-core'] = $git(['rev-parse', 'HEAD:plugin/type-core']);
            $write('consumer', json_encode($receipt, JSON_THROW_ON_ERROR));
            $this->rejected(static fn () => TutorialEvidence::verify($file, $source, 'fixed-candidate', null, $items, 'sqlite'), '教程编译组件源码不属于固定拆分来源：type-core');

            file_put_contents($repository . '/plugin/type-core/src/Core.php', $componentBytes);
            file_put_contents($repository . '/plugin/type-build/src/Compiler.php', "<?php // changed compiler\n");
            $git(['add', '.']);
            $git(['-c', 'user.name=Tutorial test', '-c', 'user.email=tutorial@example.invalid', 'commit', '--quiet', '-m', 'New compiler inputs']);
            $report['source'] = $source = $git(['rev-parse', 'HEAD']);
            $items['type-core']['split'] = $receipt['verified-splits']['type-core'] = $split;
            $items['type-build']['split'] = $receipt['verified-splits']['type-build'] = $git(['rev-parse', 'HEAD:plugin/type-build']);
            $write('consumer', json_encode($receipt, JSON_THROW_ON_ERROR));
            $this->rejected(static fn () => TutorialEvidence::verify($file, $source, 'fixed-candidate', null, $items, 'sqlite'), '教程编译工具不属于固定拆分来源：type-build');

            file_put_contents($repository . '/templates/type-project/app/Application.php', "<?php // changed template\n");
            $git(['add', '.']);
            $git(['-c', 'user.name=Tutorial test', '-c', 'user.email=tutorial@example.invalid', 'commit', '--quiet', '-m', 'New template inputs']);
            $report['source'] = $source = $git(['rev-parse', 'HEAD']);
            $items['type-project']['split'] = $report['template-split'] = $git(['rev-parse', 'HEAD:templates/type-project']);
            $write('consumer', json_encode($receipt, JSON_THROW_ON_ERROR));
            $this->rejected(static fn () => TutorialEvidence::verify($file, $source, 'fixed-candidate', null, $items, 'sqlite'), '教程模板输入不属于固定拆分来源');

            $newTutorial = "<?php // changed tutorial\n";
            file_put_contents($repository . '/examples/catalog/test.php', $newTutorial);
            $git(['add', '.']);
            $git(['-c', 'user.name=Tutorial test', '-c', 'user.email=tutorial@example.invalid', 'commit', '--quiet', '-m', 'New tutorial inputs']);
            $report['source'] = $source = $git(['rev-parse', 'HEAD']);
            $declaration['tutorial-sources']['test.php'] = hash('sha256', $newTutorial);
            $write('tutorial', json_encode($declaration, JSON_THROW_ON_ERROR));
            $this->rejected(static fn () => TutorialEvidence::verify($file, $source, 'fixed-candidate', null, $items, 'sqlite'), '教程输入声明没有进入原程序构建身份');
            self::assertSame($program, file_get_contents($directory . '/program'));
            self::assertSame($lockBytes, file_get_contents($directory . '/lock'));
            self::assertSame($build, json_decode(file_get_contents($directory . '/build'), true, 512, JSON_THROW_ON_ERROR));
        } finally {
            putenv($previousGit === false ? 'GIT_DIR' : 'GIT_DIR=' . $previousGit);
            putenv($previousWorktree === false ? 'GIT_WORK_TREE' : 'GIT_WORK_TREE=' . $previousWorktree);
            \removeTestDirectory($directory);
        }
    }

    /** 缺失报告、旧公开基线、错误版本及不完整数据库集合不能获得新教程准入。 */
    public function testReportRejectsMissingHistoricalAndMismatchedEvidence(): void
    {
        $root = dirname(__DIR__, 2);
        $directory = $root . '/build/tutorial-evidence-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $file = $directory . '/verification.json';
        $source = Process::output(['git', 'rev-parse', 'HEAD'], $root);
        $items = ['type-project' => ['split' => str_repeat('b', 40)]];
        $report = ['protocol' => 1, 'kind' => 'tutorial-delivery', 'status' => 'passed', 'source' => $source,
            'channel' => 'fixed-candidate', 'version' => null, 'template-split' => $items['type-project']['split'], 'temporary-inputs-removed' => true, 'profiles' => []];
        try {
            $this->rejected(static fn () => TutorialEvidence::verify($file, $source, 'fixed-candidate', null, $items));
            foreach ([['kind' => 'published-template-baseline'], ['kind' => 'tutorial-php'], ['source' => str_repeat('0', 40)],
                ['version' => 'v1.0.0-rc.14'], ['channel' => 'packagist-tag'], ['template-split' => str_repeat('0', 40)], ['temporary-inputs-removed' => false], []] as $change) {
                file_put_contents($file, json_encode(array_replace($report, $change), JSON_THROW_ON_ERROR));
                $this->rejected(static fn () => TutorialEvidence::verify($file, $source, 'fixed-candidate', null, $items));
            }
            $report['profiles'] = array_fill_keys(['mysql', 'pgsql', 'sqlite'], []);
            foreach ([[], ['file' => '../outside', 'sha256' => str_repeat('a', 64)],
                ['file' => 'program', 'sha256' => str_repeat('a', 64)]] as $entry) {
                file_put_contents($directory . '/program', 'actual bytes');
                $report['profiles']['mysql']['program'] = $entry;
                file_put_contents($file, json_encode($report, JSON_THROW_ON_ERROR));
                $this->rejected(static fn () => TutorialEvidence::verify($file, $source, 'fixed-candidate', null, $items));
            }
        } finally {
            \removeTestDirectory($directory);
        }
    }

    /** 缺少任何平台/profile或使用旧run、attempt时，矩阵不能读取单个成功标志放行。 */
    public function testMatrixRejectsIncompleteAndMixedAttempts(): void
    {
        $directory = dirname(__DIR__, 2) . '/build/tutorial-matrix-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $source = str_repeat('a', 40);
        $report = ['protocol' => 1, 'kind' => 'tutorial-delivery-matrix', 'status' => 'passed', 'source' => $source,
            'channel' => 'fixed-candidate', 'version' => null, 'run' => '123', 'attempt' => '2', 'reports' => []];
        foreach (['linux-x64', 'linux-arm64', 'macos-arm64', 'windows-x64'] as $platform) {
            foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
                $report['reports'][$platform . '/' . $driver] = ['file' => 'missing.json', 'sha256' => str_repeat('a', 64)];
            }
        }
        $file = $directory . '/verification.json';
        try {
            foreach (['source' => str_repeat('b', 40), 'channel' => 'packagist-tag', 'version' => 'v1.0.0',
                'run' => '122', 'attempt' => '1', 'kind' => 'tutorial-delivery', 'status' => 'failed'] as $key => $value) {
                file_put_contents($file, json_encode(array_replace($report, [$key => $value]), JSON_THROW_ON_ERROR));
                $this->rejected(static fn () => TutorialEvidence::matrix($file, $source, 'fixed-candidate', null, [], '123', '2'), '教程矩阵来源、版本或执行轮次不一致');
            }
            foreach (array_keys($report['reports']) as $missing) {
                $broken = $report;
                unset($broken['reports'][$missing]);
                file_put_contents($file, json_encode($broken, JSON_THROW_ON_ERROR));
                $this->rejected(static fn () => TutorialEvidence::matrix($file, $source, 'fixed-candidate', null, [], '123', '2'), '教程矩阵缺少四平台十二个profile');
            }
            file_put_contents($file, json_encode($report, JSON_THROW_ON_ERROR));
            $this->rejected(static fn () => TutorialEvidence::matrix($file, $source, 'fixed-candidate', null, [], '123', '2'));
        } finally {
            \removeTestDirectory($directory);
        }
    }

    /** 每个artifact均必须绑定自己的平台、run和attempt，汇总身份一致不能掩盖混入。 */
    public function testEveryMatrixChildRejectsDifferentPlatformRunAndAttempt(): void
    {
        $directory = dirname(__DIR__, 2) . '/build/tutorial-matrix-child-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $source = str_repeat('a', 40);
        $report = ['protocol' => 1, 'kind' => 'tutorial-delivery-matrix', 'status' => 'passed', 'source' => $source,
            'channel' => 'fixed-candidate', 'version' => null, 'run' => '123', 'attempt' => '2', 'reports' => []];
        $children = [];
        foreach (['linux-x64', 'linux-arm64', 'macos-arm64', 'windows-x64'] as $platform) {
            foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
                $key = $platform . '/' . $driver;
                $children[$key] = ['platform' => $platform, 'run' => '123', 'attempt' => '2'];
                $path = $platform . '-' . $driver . '.json';
                file_put_contents($directory . '/' . $path, json_encode($children[$key], JSON_THROW_ON_ERROR));
                $report['reports'][$key] = ['file' => $path, 'sha256' => hash_file('sha256', $directory . '/' . $path)];
            }
        }
        $file = $directory . '/verification.json';
        try {
            foreach ($children as $key => $child) {
                $path = $directory . '/' . $report['reports'][$key]['file'];
                foreach (['platform' => 'other-platform', 'run' => '122', 'attempt' => '1'] as $field => $value) {
                    file_put_contents($path, json_encode(array_replace($child, [$field => $value]), JSON_THROW_ON_ERROR));
                    $broken = $report;
                    $broken['reports'][$key]['sha256'] = hash_file('sha256', $path);
                    file_put_contents($file, json_encode($broken, JSON_THROW_ON_ERROR));
                    $this->rejected(static fn () => TutorialEvidence::matrix($file, $source, 'fixed-candidate', null, [], '123', '2'), '教程profile不属于同一平台或执行轮次：' . $key);
                }
                file_put_contents($path, json_encode($child, JSON_THROW_ON_ERROR));
            }
            // 没有原始封存程序和业务回执，十二份身份一致的标题仍不能成为原生验收。
            file_put_contents($file, json_encode($report, JSON_THROW_ON_ERROR));
            $this->rejected(static fn () => TutorialEvidence::matrix($file, $source, 'fixed-candidate', null, [], '123', '2'), '教程验收来源、版本、模板或状态不匹配');
        } finally {
            \removeTestDirectory($directory);
        }
    }

    /** 重新收集失败时必须保留失败状态，不能留下可被后续误用的旧成功报告。 */
    public function testFailedCollectionInvalidatesPreviousSuccess(): void
    {
        $root = dirname(__DIR__, 2);
        $directory = $root . '/build/tutorial-collection-' . bin2hex(random_bytes(6));
        mkdir($directory . '/inputs', 0700, true);
        file_put_contents($directory . '/verification.json', json_encode(['status' => 'passed', 'source' => 'previous'], JSON_THROW_ON_ERROR));
        try {
            [$status] = Process::run([PHP_BINARY, $root . '/tools/collect-tutorial.php', 'candidate', str_repeat('0', 40), $directory, '123', '2'], $root);
            self::assertNotSame(0, $status);
            $report = json_decode(file_get_contents($directory . '/verification.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('failed', $report['status']);
            self::assertSame(str_repeat('0', 40), $report['source']);
            self::assertSame('123', $report['run']);
            self::assertSame('2', $report['attempt']);
            self::assertNotSame('', $report['failure']);
        } finally {
            \removeTestDirectory($directory);
        }
    }

    /** 安装快照验证完整文件集合与字节，控制器保留原始Git换行再计算摘要。 */
    public function testSnapshotAndCommandBytesCannotSilentlyNormalizeInputs(): void
    {
        $directory = dirname(__DIR__, 2) . '/build/tutorial-snapshot-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $bytes = " \n<?php\r\n\t";
        try {
            file_put_contents($directory . '/source.php', $bytes);
            $expected = ['source.php' => hash('sha256', $bytes)];
            TutorialEvidence::snapshot($directory, $expected);
            [$status, $actual] = Process::run([PHP_BINARY, '-r', 'echo file_get_contents($argv[1]);', 'source.php'], $directory, false);
            self::assertSame(0, $status);
            self::assertSame($bytes, $actual);
            file_put_contents($directory . '/extra.php', '<?php');
            $this->rejected(static fn () => TutorialEvidence::snapshot($directory, $expected));
            unlink($directory . '/extra.php');
            file_put_contents($directory . '/source.php', trim($bytes));
            $this->rejected(static fn () => TutorialEvidence::snapshot($directory, $expected));
            unlink($directory . '/source.php');
            $this->rejected(static fn () => TutorialEvidence::snapshot($directory, $expected));
            if (PHP_OS_FAMILY !== 'Windows') {
                symlink(__FILE__, $directory . '/source.php');
                $this->rejected(static fn () => TutorialEvidence::snapshot($directory, $expected));
                unlink($directory . '/source.php');
            }
        } finally {
            \removeTestDirectory($directory);
        }
    }

    /** @return array 完整有效回执，测试只修改单个已约定边界。 */
    private function profile(string $driver, string $channel): array
    {
        $sha = str_repeat('a', 64);
        $split = str_repeat('b', 40);
        $version = $channel === 'packagist-tag' ? 'v1.2.3' : null;
        $receipt = ['status' => 'passed', 'driver' => $driver, 'tutorial' => true, 'native' => true, 'package-verified' => true,
            'delivery' => 'single-executable', 'profile' => $driver, 'consumption-mode' => $channel, 'version' => $version,
            'build-id' => $sha, 'artifact-sha256' => $sha, 'verified-splits' => ['type-core' => $split]];
        $deployment = ['status' => 'passed', 'tutorial' => true, 'driver' => $driver, 'artifact-sha256' => $sha, 'build-id' => $sha,
            'initialization' => ['tutorial-public-assertions' => true], 'source-and-sdk-read-denied' => true,
            'ordinary-start-writes-no-files' => true, 'runtime-profile-enforced' => true, 'single-executable-only' => true,
            'readonly-program-directory' => true, 'different-cwd' => true];
        $identity = ['build-id' => $sha, 'runtime-linkage' => 'static', 'profile' => ['database' => $driver],
            'native-libraries' => [], 'extension-modules' => [], 'resources' => [], 'static-runtime' => ['sha256' => $sha],
            'static-archives' => ['libswoole.a'], 'runtime-extensions' => ['swoole'], 'production-packages' => ['zoujingli/type-core' => '1.2.3'],
            'declaration-generation' => $sha, 'toolchain-lock-sha256' => $sha, 'composer-lock-sha256' => $sha, 'resource-generation' => $sha];
        $lock = ['packages' => [['name' => 'zoujingli/type-core', 'version' => $version ?? 'dev-main',
            'source' => ['reference' => $split], 'dist' => ['type' => $channel === 'fixed-candidate' ? 'path' : 'zip']]]];
        $workspace = BuildPlatform::path(dirname(__DIR__, 2)) . '/build/tutorial fixture';
        $source = $workspace . '/vendor/zoujingli/type-core/src/Core.php';
        $metadata = ['protocol' => 1, 'files' => []];
        $identity['declaration-generation'] = BuildIdentity::digest(['sources' => [$sha], 'generation' => $metadata]);
        $description = ['protocol' => BuildIdentity::PROTOCOL, 'facts' => ['workspace' => $workspace, 'native' => ['static-runtime' => $identity['static-runtime']]],
            'inputs' => ['original-sources' => [$source => ['sha256' => $sha, 'bytes' => 1]], 'locks' => [
                $workspace . '/composer.lock' => ['sha256' => $sha, 'bytes' => 1],
                $workspace . '/toolchain.lock.json' => ['sha256' => $sha, 'bytes' => 1],
            ]]];
        foreach (['production-packages', 'profile', 'runtime-extensions', 'static-archives', 'declaration-generation', 'resource-generation'] as $key) {
            $description['facts'][$key] = $identity[$key];
        }
        $description = BuildIdentity::canonical($description);
        $receipt['build-id'] = $deployment['build-id'] = $identity['build-id'] = BuildIdentity::digest($description);
        $build = ['build-id' => $identity['build-id'], 'sha256' => $sha, 'manifest' => $identity,
            'identity' => ['id' => $identity['build-id'], 'description' => $description],
            'original-sources' => [$source], 'declaration-metadata' => $metadata,
            'source-sets' => ['zoujingli/type-core' => ['sources' => [$source], 'exclusions' => []]]];
        return [$receipt, $deployment, $identity, $lock, $build, $driver, $channel, $version, ['type-core' => ['split' => $split]]];
    }

    /** 每个负向用例必须因公开契约明确拒绝，而非超时或PHP警告。 */
    private function rejected(\Closure $action, ?string $message = null): void
    {
        try {
            $action();
            self::fail('不完整或身份错误的教程证据获得准入');
        } catch (\RuntimeException $error) {
            self::assertNotSame('', $error->getMessage());
            if ($message !== null) {
                self::assertSame($message, $error->getMessage());
            }
        }
    }
}
