<?php

declare(strict_types=1);

require __DIR__ . '/build-scenario.php';
require __DIR__ . '/native-database.php';

use Type\Build\BuildPlatform;
use Type\Build\BuildIdentity;
use Type\Build\ArtifactManifest;
use Type\Build\StaticRuntimeSdk;
use Type\Build\NativePackage;
use Type\Build\PackageArchive;

/** 只复制构建所需PHPX源文件；旧SDK和安装目录保持原样。 */
function benchmarkPhpxSources(string $source, string $target): void
{
    expect(mkdir($target, 0700), '无法创建独立PHPX目录');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
        $relative = substr($file->getPathname(), strlen($source) + 1);
        if (preg_match('~^(?:\.git|build|lib)(?:/|$)~', $relative) || preg_match('/\.(?:o|obj|so|dylib|dll|a|lib)$/D', $relative)) {
            continue;
        }
        expect($file->isFile() && !$file->isLink(), 'PHPX构建输入必须是普通文件');
        scenarioCopy($file->getPathname(), $target . '/' . $relative);
    }
}

/**
 * 保全本次实际测量的原程序和原生闭包；不修改程序字节，也不归档编译中间文件。
 *
 * 归档恢复后用自己的运行库完成完整性校验。原 INI 和构建报告单独保留身份，
 * 恢复包使用现有发布器生成的相对路径 INI；这不是静态部署或性能通过的证据。
 *
 * @param array<string,mixed> $build 原程序的已核验构建报告。
 * @return array<string,mixed> 归档、原配置、构建身份和实际恢复结果。
 */
function benchmarkPreserveRuntime(string $root, string $work, string $artifact, array $build): array
{
    $package = $work . '/runtime-preservation';
    $restored = $work . '/restored runtime';
    $data = $work . '/runtime-check-data';
    $record = ['protocol' => 1, 'status' => 'preserving', 'artifact_sha256' => $build['sha256'], 'build_id' => $build['build-id']];
    try {
        expect(hash_file('sha256', $artifact) === $build['sha256'], '保全前的基准程序已变化');
        foreach (['runtime-original.ini' => $build['runtime-profile']['ini'], 'runtime-build.json' => $artifact . '.build.json'] as $name => $source) {
            scenarioCopy($source, $work . '/' . $name);
            expect(hash_file('sha256', $source) === hash_file('sha256', $work . '/' . $name), '基准原配置或构建报告复制不一致');
            $record['original_inputs'][$name] = hash_file('sha256', $source);
        }
        $publisher = new NativePackage();
        $created = $publisher->create($artifact, $package);
        $archive = (new PackageArchive())->create($package, $work . '/runtime.tar.gz', $created['manifest-sha256']);
        $record['archive'] = array_replace($archive, ['file' => substr($archive['file'], strlen($root) + 1)]);
        expect(mkdir($restored, 0700) && mkdir($data, 0700), '无法创建基准恢复校验目录');
        nativeDatabaseCommand(['tar', '-xzf', $archive['file'], '-C', $restored], ['PATH' => '/usr/bin:/bin'], [], $work . '/runtime-restore.log', 60);
        $release = $publisher->verify($restored, $created['manifest-sha256']);
        expect($release['artifact']['sha256'] === $build['sha256'] && $release['artifact']['build-id'] === $build['build-id'], '恢复包不属于原基准程序');
        $output = nativeDatabaseCommand([$restored . '/run', 'verify-runtime'], [
            'PATH' => '/usr/bin:/bin', 'TYPE_APP_RELEASE_SHA256' => $created['manifest-sha256'],
            'APP_BASE_PATH' => $data, 'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_CACHE_ENABLED' => 'false',
            'DB_DRIVER' => 'sqlite', 'DB_SQLITE_FILE' => 'data/benchmark.sqlite',
        ], [], $work . '/runtime-verify.log', 90);
        expect($output === "运行环境完整性校验通过。\n" && hash_file('sha256', $artifact) === $build['sha256'], '恢复后运行库审计未通过或原程序发生变化');
        $record['files'] = count($release['files']);
        $record['status'] = 'restored-and-verified';
        return $record;
    } finally {
        if ($record['status'] === 'preserving') {
            $record['status'] = 'failed';
        }
        file_put_contents($work . '/runtime-preservation.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        // 原程序、归档和失败回执保留；临时解包与重复库不进入长期证据。
        foreach ([$package, $restored, $data] as $temporary) {
            if (is_dir($temporary)) {
                removeTestDirectory($temporary);
            }
        }
    }
}

/**
 * 从各版本自身的公开 prepare 入口测量冷生成与完整代次复用；不执行应用或改变生产声明。
 *
 * @param array<string,string> $environment 本版本的已核验构建环境。
 * @return array<string,mixed> 三轮原始耗时及同一生成清单的内容身份。
 */
function benchmarkGeneration(string $project, array $environment, string $work): array
{
    $output = $project . '/build/benchmark/development';
    $record = ['repetitions' => 3, 'reuse_warmup' => 1, 'cold_seconds' => [], 'reuse_seconds' => [],
        'timing_scope' => '公开 type prepare 子进程的完整耗时，包含启动、输入审计和生成或代次完整性核验；冷指无生成目录，不指操作系统文件缓存。'];
    for ($round = 0; $round < 3; $round++) {
        if (is_dir($output)) {
            removeTestDirectory($output);
        }
        foreach (['cold', 'reuse-warmup', 'reuse'] as $phase) {
            $started = hrtime(true);
            $text = nativeDatabaseCommand(
                [PHP_BINARY, '-d', 'memory_limit=2G', $project . '/vendor/bin/type', 'prepare', $project . '/docs/build-config/benchmark.json'],
                $environment,
                [],
                $work . '/generation-' . $round . '-' . $phase . '.log',
                60
            );
            $elapsed = (hrtime(true) - $started) / 1e9;
            $prepared = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            $generation = $prepared['generation'] ?? '';
            expect(is_string($generation) && preg_match('/^[a-f0-9]{64}$/D', $generation) === 1
                && BuildPlatform::resolve($prepared['directory'] ?? '') === $output . '/' . $generation, '生成报告不属于本轮专用目录');
            $manifest = (string) file_get_contents($output . '/' . $generation . '/manifest.json');
            expect(hash('sha256', $manifest) === $generation, '生成清单与公开入口身份不符');
            $decoded = json_decode($manifest, true, 512, JSON_THROW_ON_ERROR);
            $files = $decoded['declarations']['files'] ?? $decoded['files'] ?? null;
            expect(in_array($decoded['protocol'] ?? null, [1, 2], true) && is_array($files), '生成清单缺少受审文件');
            $bytes = 0;
            foreach ($files as $name => $hash) {
                $file = $output . '/' . $generation . '/' . $name;
                expect(is_file($file) && !is_link($file) && hash_file('sha256', $file) === $hash, '生成文件与清单不符');
                $bytes += filesize($file);
            }
            $identity = ['generation' => $generation, 'declaration_generation' => $prepared['declaration-generation'] ?? null,
                'generated_bytes' => $bytes, 'manifest' => $decoded];
            expect(!isset($record['identity']) || $record['identity'] === $identity, '冷生成与代次复用没有保持同一输入和生成字节');
            $record['identity'] = $identity;
            if ($phase !== 'reuse-warmup') {
                $record[$phase . '_seconds'][] = $elapsed;
            }
        }
    }
    return $record;
}

/**
 * 比较共享 SDK 所依赖的工具链与制备源码；两个版本还必须各自验证其 ABI、补丁和归档。
 *
 * @return array<string,string> 本轮实际复用的源码摘要，不把可变 SDK 路径当作身份。
 */
function benchmarkStaticInputs(string $project, string $current, array $sdk): array
{
    $files = ['toolchain.lock.json', 'plugin/type-build/src/BuildProfile.php', 'plugin/type-build/src/StaticRuntimeSdk.php'];
    foreach (array_keys($sdk['patches'] ?? []) as $patch) {
        expect(preg_match('/^(?:Swoole|Phpx)[A-Za-z]+Source$/D', $patch) === 1, '静态 SDK 补丁名称无效');
        $files[] = 'plugin/type-build/src/' . $patch . '.php';
    }
    $preparation = $sdk['preparation'] ?? [];
    if (PHP_OS_FAMILY === 'Windows') {
        $scripts = $preparation['scripts'] ?? [];
        expect(count($scripts) === 5, '静态 SDK 缺少完整 Windows 制备脚本身份');
        foreach ($scripts as $script => $hash) {
            expect(
                preg_match('~^tools/(probe-static-windows[.]ps1|static-windows/(build-phpx[.]ps1|phpx/CMakeLists[.]txt|prepare-extensions[.]php|export-sdk[.]php))$~D', $script) === 1,
                '静态 SDK 制备脚本路径无效'
            );
            expect(hash_file('sha256', $current . '/' . $script) === $hash, '静态 SDK 制备脚本已过期');
            $files[] = $script;
        }
        foreach (['dependency-manifest-sha256' => 'tools/static-windows/vcpkg.json',
            'dependency-triplet-sha256' => 'tools/static-windows/x64-typeapp-static.cmake'] as $key => $file) {
            expect(($preparation[$key] ?? null) === hash_file('sha256', $current . '/' . $file), '静态 SDK 的依赖配置已变化');
            $files[] = $file;
        }
    } else {
        foreach (['script-sha256' => 'tools/prepare-static-' . (PHP_OS_FAMILY === 'Darwin' ? 'macos' : 'linux') . '.sh',
            'manifest-script-sha256' => 'tools/static-runtime-manifest.php', 'php-header-patch-sha256' => 'tools/php-hash-cxx.patch'] as $key => $file) {
            expect(($preparation[$key] ?? null) === hash_file('sha256', $current . '/' . $file), '静态 SDK 的制备源码已变化');
            $files[] = $file;
        }
    }
    $identities = [];
    foreach ($files as $file) {
        expect(is_file($project . '/' . $file) && !is_link($project . '/' . $file)
            && hash_file('sha256', $project . '/' . $file) === hash_file('sha256', $current . '/' . $file), '新旧源码的静态 SDK 输入不同，不能复用：' . $file);
        $identities[$file] = hash_file('sha256', $project . '/' . $file);
    }
    // 编译器和 PHPX 必须来自同一锁定提交；框架包源码差异是本轮比较对象。
    $locked = [];
    foreach ([$current, $project] as $directory) {
        $lock = json_decode((string) file_get_contents($directory . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
        $packages = [];
        foreach (array_merge($lock['packages'], $lock['packages-dev']) as $package) {
            if (in_array($package['name'], ['swoole/typephp', 'swoole/phpx'], true)) {
                $packages[$package['name']] = [$package['version'], $package['source']['reference'] ?? null, $package['dist']['reference'] ?? null];
            }
        }
        expect(count($packages) === 2, '基准锁文件缺少 TypePHP 或 PHPX 身份');
        $locked[] = $packages;
    }
    expect($locked[0] === $locked[1], '新旧工具链锁不同，不能作为同 SDK 的框架性能比较');
    return $identities;
}

/**
 * 在原静态候选封存后构建旧端；新端只读原附件。每轮只接受当前数据库 profile。
 *
 * 生成计时仍走两个版本自身的 prepare；SDK 和前端共用经过摘要核验的原输入，
 * 不把静态程序的无源码部署证明冒称为性能采样本身也处于同一沙箱。
 */
function prepareStaticBenchmarks(string $root, array $arguments): void
{
    require_once dirname(__DIR__) . '/tools/release/Plan.php';
    require_once dirname(__DIR__) . '/tools/release/Candidate.php';
    $commits = ['old' => $arguments[1], 'new' => $arguments[2]];
    $profile = $arguments[5];
    expect(
        in_array($profile, ['sqlite', 'mysql', 'pgsql'], true) && getenv('TYPEAPP_BUILD_PROFILE') === $profile,
        '静态基准需要与候选一致的数据库 profile'
    );
    $repository = BuildPlatform::resolve(getenv('TYPE_BENCHMARK_SOURCE_REPOSITORY') ?: $root);
    foreach ($commits as $commit) {
        expect(preg_match('/^[a-f0-9]{40}$/D', $commit) === 1
            && trim(successful(['git', 'rev-parse', $commit . '^{commit}'], $repository)) === $commit, '需要可读取的固定 Git 提交');
    }
    expect($commits['old'] !== $commits['new'] && trim(successful(['git', 'rev-parse', 'HEAD'], $root)) === $commits['new'], '新端必须来自当前固定源码的原候选');
    successful(['git', 'merge-base', '--is-ancestor', $commits['old'], $commits['new']], $repository);
    $composer = BuildPlatform::resolve($arguments[3]);
    $runtime = StaticRuntimeSdk::selected($profile);
    expect($runtime !== null, '静态性能比较必须显式选择本轮 SDK');
    $sdkPath = $runtime->manifestPath();
    $sdk = $runtime->identity();
    $platform = match (PHP_OS_FAMILY) {
        'Darwin' => 'macos-arm64', 'Windows' => 'windows-x64',
        'Linux' => in_array(php_uname('m'), ['aarch64', 'arm64'], true) ? 'linux-arm64' : 'linux-x64',
    };
    $candidateFile = $root . '/build/release-candidate/attachments/' . $platform . '-' . $profile . '.json';
    expect(is_file($candidateFile) && !is_link($candidateFile), '须先完成原静态候选 finish 封存');
    $candidate = json_decode((string) file_get_contents($candidateFile), true, 512, JSON_THROW_ON_ERROR);
    $filename = \TypeApp\Release\Candidate::verify($candidate, dirname($candidateFile), $commits['new'], (string) getenv('TYPE_RELEASE_VERSION'), $platform, $profile);
    $newArtifact = dirname($candidateFile) . '/' . $filename;
    $newReport = (new BuildPlatform())->output($root . '/build/app/type-app') . '.build.json';
    $timingFile = $root . '/build/static-benchmark-build.json';
    expect(is_file($timingFile) && !is_link($timingFile), '原候选缺少本轮编译计时，不能重新编译补造');
    $timing = json_decode((string) file_get_contents($timingFile), true, 512, JSON_THROW_ON_ERROR);
    expect(($timing['protocol'] ?? null) === 1 && ($timing['source'] ?? null) === $commits['new'] && ($timing['profile'] ?? null) === $profile
        && ($timing['sha256'] ?? null) === $candidate['sha256'] && ($timing['build_id'] ?? null) === $candidate['build-id']
        && ($timing['report_sha256'] ?? null) === hash_file('sha256', $newReport)
        && ($timing['sdk_manifest_sha256'] ?? null) === hash_file('sha256', $sdkPath)
        && ($timing['php_memory_limit'] ?? null) === ini_get('memory_limit')
        && ($timing['controller_ini_sha256'] ?? null) === (php_ini_loaded_file() === false ? null : hash_file('sha256', php_ini_loaded_file()))
        && ($candidate['sdk-manifest-sha256'] ?? null) === $timing['sdk_manifest_sha256'], '原候选的源码、SDK、编译计时或程序身份不符');
    // 协议3使用短任务根，限制 Windows 编译器生成文件的路径长度；不是源码裁剪。
    $base = $root . '/build/pb-' . bin2hex(random_bytes(6));
    expect(mkdir($base, 0700), '无法创建静态成对准备目录');
    scenarioCopy($candidateFile, $base . '/new/candidate.json');
    scenarioCopy($timingFile, $base . '/new/build-timing.json');
    $record = ['protocol' => 3, 'status' => 'preparing', 'delivery' => 'static-profile-benchmark', 'static_profile' => $profile,
        'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'source_commits' => $commits,
        'sdk_manifest_sha256' => hash_file('sha256', $sdkPath), 'sdk_identity' => $sdk,
        'candidate' => ['file' => substr($base, strlen($root) + 1) . '/new/candidate.json',
            'original_file' => substr($candidateFile, strlen($root) + 1), 'sha256' => hash_file('sha256', $candidateFile), 'record' => $candidate],
        'candidate_build' => ['file' => substr($base, strlen($root) + 1) . '/new/build-timing.json',
            'original_file' => substr($timingFile, strlen($root) + 1), 'sha256' => hash_file('sha256', $timingFile), 'record' => $timing],
        'preparer_sha256' => hash_file('sha256', __FILE__), 'composer_sha256' => hash_file('sha256', $composer),
        'php' => PHP_VERSION, 'php_cli_sha256' => hash_file('sha256', PHP_BINARY), 'php_memory_limit' => ini_get('memory_limit'),
        'controller_ini_sha256' => $timing['controller_ini_sha256'], 'variants' => []];
    try {
        $resources = (new \Type\Build\EmbeddedResourceCompiler())->collect($root, [['source' => 'web/dist', 'target' => 'web']]);
        $frontend = (new \Type\Build\EmbeddedResourceCompiler())->manifest($resources);
        // 前端必须逐字节一致；各版本自己的依赖许可仍由完整原生清单和程序摘要绑定。
        $candidateFrontend = array_filter($candidate['embedded-resources'], static fn (string $path): bool => str_starts_with($path, 'web/'), ARRAY_FILTER_USE_KEY);
        expect($frontend !== [] && $frontend === $candidateFrontend, '静态基准前端与封存候选不同');
        foreach ($commits as $variant => $commit) {
            $work = $base . '/' . $variant;
            $project = $work . '/project';
            expect(mkdir($project, 0700, true), '无法创建固定静态基准源码目录');
            successful(['git', 'archive', '--format=tar', '--output=' . $work . '/source.tar', $commit], $repository);
            expect((new PharData($work . '/source.tar'))->extractTo($project), '无法恢复静态基准源码');
            $sdkInputs = benchmarkStaticInputs($project, $root, $sdk);
            $environment = getenv();
            $environment['COMPOSER_HOME'] = $work . '/composer-home';
            $environment['COMPOSER_CACHE_DIR'] = $root . '/.cache/composer';
            unset($environment['TYPEAPP_BUILD_CONFIGURATION'], $environment['TYPE_BENCHMARK_BUILD_TIMING']);
            nativeDatabaseCommand([PHP_BINARY, $composer, '--working-dir=' . $project, 'install', '--no-scripts', '--no-plugins',
                '--no-interaction', '--prefer-dist', '--no-progress'], $environment, [], $work . '/install.log', 600);
            $inspection = json_decode(nativeDatabaseCommand(
                [PHP_BINARY, '-r',
                'require $argv[1]; echo json_encode(["sdk" => (new Type\\Build\\StaticRuntimeSdk($argv[2], $argv[3]))->identity(), "profile" => Type\\Build\\BuildProfile::selected(Type\\Build\\BuildProfile::resolve(json_decode(file_get_contents($argv[4]), true, 512, JSON_THROW_ON_ERROR)))], JSON_THROW_ON_ERROR);',
                $project . '/vendor/autoload.php', $sdkPath, $profile, $project . '/docs/build-config/type-app.json'],
                $environment,
                [],
                $work . '/sdk-inputs.json',
                60
            ), true, 512, JSON_THROW_ON_ERROR);
            expect($inspection['sdk'] === $sdk && ($inspection['profile']['name'] ?? null) === $profile
                && ($inspection['profile']['database'] ?? null) === $profile && ($inspection['profile']['features'] ?? null) === $candidate['features']
                && ($sdk['features'] ?? null) === $candidate['features'], '静态基准源码的 SDK 或功能闭包与原候选不符');
            foreach ($resources as $path => $resource) {
                scenarioCopy($resource['source'], $project . '/web/dist/' . substr($path, 4));
            }
            $settings = json_decode((string) file_get_contents($project . '/docs/build-config/type-app.json'), true, 512, JSON_THROW_ON_ERROR);
            $settings['version'] = substr($candidate['version'], 1);
            $settings['output'] = 'build/b/type-app';
            $settings['build-directory'] = 'build/b/c';
            $settings['development']['output'] = 'build/benchmark/development';
            file_put_contents($project . '/docs/build-config/benchmark.json', json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            $generation = benchmarkGeneration($project, $environment, $work);
            if (PHP_OS_FAMILY === 'Windows' && $variant === 'old') {
                // 使用本版本自己的生产源码枚举；路径长度按锁定 TypePHP 声明头规则作保守上界核验。
                // 源码与生成声明均保留，超长时明确失败，不能通过漏编文件规避 MSVC 限制。
                $pathProbe = <<<'PHP'
require $argv[1];
$project = $argv[2];
$settings = json_decode(file_get_contents($project . '/docs/build-config/benchmark.json'), true, 512, JSON_THROW_ON_ERROR);
$production = (new Type\Build\SourceSet())->productionSources($project, $settings);
$inputs = array_merge($production['sources'], array_map(static fn (string $path): string => $project . '/' . $path, $settings['sources']));
if (isset($settings['entry'])) { $inputs[] = $project . '/' . $settings['entry']; }
$files = (new Type\Build\BuildIdentity())->sources($inputs);
$generation = json_decode(file_get_contents($argv[3]), true, 512, JSON_THROW_ON_ERROR);
foreach (array_keys($generation['declarations']['files'] ?? $generation['files']) as $file) { $files[] = $project . '/build/b/c/' . $file; }
$longest = '';
foreach ($files as $file) {
    $relative = substr(str_replace(['.stub.php', '.php'], '', str_replace('\\', '/', $file)), strlen($project) + 1);
    $name = str_replace(['/', '\\', '-', ':', '<', '>', '"', '|', '?', '*'], '_', $relative);
    $path = $project . '/build/b/c/attempts/00000000/include/php_artifact_' . $name . '_0000000000_decl.h';
    if (strlen($path) > strlen($longest)) { $longest = $path; }
}
if ($longest === '' || strlen($longest) > 259) { throw new RuntimeException('MSVC 生成声明路径超过259字节上界：' . $longest); }
echo json_encode(['maximum_bytes' => strlen($longest), 'limit' => 259, 'longest_declaration' => $longest, 'inputs' => count($files),
    'header_rule_sha256' => hash_file('sha256', $project . '/vendor/swoole/typephp/src/Translator.php')], JSON_THROW_ON_ERROR);
PHP;
                nativeDatabaseCommand(
                    [PHP_BINARY, '-r', $pathProbe, $project . '/vendor/autoload.php', $project,
                    $project . '/build/benchmark/development/' . $generation['identity']['generation'] . '/manifest.json'],
                    $environment,
                    [],
                    $work . '/windows-paths.json',
                    60
                );
            }
            $artifact = $newArtifact;
            $reportFile = $newReport;
            $seconds = $timing['build_seconds'];
            if ($variant === 'old') {
                echo "old：以自身源码和声明全量编译同 profile 静态基准。\n";
                $started = hrtime(true);
                nativeDatabaseCommand(
                    [PHP_BINARY, $project . '/vendor/bin/type', $project . '/docs/build-config/benchmark.json'],
                    $environment,
                    [],
                    $work . '/project-build.log',
                    1800
                );
                $seconds = (hrtime(true) - $started) / 1e9;
                $artifact = (new BuildPlatform())->output($project . '/build/b/type-app');
                $reportFile = $artifact . '.build.json';
            }
            $build = json_decode((string) file_get_contents($reportFile), true, 512, JSON_THROW_ON_ERROR);
            $manifest = (new ArtifactManifest())->read($artifact);
            expect(hash_file('sha256', $artifact) === $build['sha256'] && $manifest === $build['manifest'] && ($build['cache']['hit'] ?? true) === false
                && ($manifest['runtime-linkage'] ?? null) === 'static' && BuildIdentity::digest($manifest['static-runtime'] ?? null) === BuildIdentity::digest($sdk)
                && ($manifest['profile']['name'] ?? null) === $profile && ($manifest['features'] ?? null) === $candidate['features']
                && array_filter($manifest['embedded-resources'] ?? [], static fn (string $path): bool => str_starts_with($path, 'web/'), ARRAY_FILTER_USE_KEY) === $frontend
                && ($build['declaration-generation'] ?? null) === $generation['identity']['declaration_generation'], '静态基准的原程序、能力、前端或生成身份不符');
            $record['variants'][$variant] = ['source_archive_sha256' => hash_file('sha256', $work . '/source.tar'),
                'sdk_inputs' => $sdkInputs, 'sdk_check_sha256' => hash_file('sha256', $work . '/sdk-inputs.json'), 'frontend' => $frontend,
                'roles' => ['project' => ['artifact' => substr($artifact, strlen($root) + 1), 'sha256' => $build['sha256'],
                    'report' => substr($reportFile, strlen($root) + 1), 'report_sha256' => hash_file('sha256', $reportFile),
                    'build_id' => $build['build-id'], 'build_seconds' => $seconds, 'bytes' => filesize($artifact),
                    'timing_scope' => $timing['timing_scope'], 'generation' => $generation, 'runtime_linkage' => 'static', 'runtime_ini_sha256' => null,
                    'sdk_manifest_sha256' => $record['sdk_manifest_sha256'], 'sdk_identity_sha256' => BuildIdentity::digest($manifest['static-runtime']),
                    'profile' => $manifest['profile'],
                    'runtime_extensions' => $build['runtime-extensions'], 'static_archives' => $build['static-archives'], 'system_libraries' => $build['system-libraries'],
                    'typephp' => $build['typephp'], 'phpx' => $build['phpx'], 'production_packages' => $build['production-packages']]]];
        }
        expect(hash_file('sha256', $newArtifact) === $candidate['sha256'], '静态性能准备改变了原候选');
        $record['status'] = 'prepared-not-measured';
    } finally {
        if ($record['status'] === 'preparing') {
            $record['status'] = 'failed';
        }
        file_put_contents($base . '/preparation.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    }
    echo '平台新旧基准准备完成：' . substr($base, strlen($root) + 1) . "/preparation.json\n";
}

$root = BuildPlatform::resolve(dirname(__DIR__));
if ($argc === 6 && $argv[4] === '--static-profile') {
    prepareStaticBenchmarks($root, $argv);
    exit(0);
}
expect(in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true) && $argc === 4, '用法：PHP tests/prepare-platform-benchmarks.php <旧源码完整SHA> <新源码完整SHA> <Composer PHAR>');
$repository = realpath(getenv('TYPE_BENCHMARK_SOURCE_REPOSITORY') ?: $root);
expect(is_string($repository) && is_dir($repository), '需要可读取的基准源码Git仓库');
$commits = ['old' => $argv[1], 'new' => $argv[2]];
foreach ($commits as $commit) {
    expect(preg_match('/^[a-f0-9]{40}$/D', $commit) === 1 && trim(successful(['git', 'rev-parse', $commit . '^{commit}'], $repository)) === $commit, '需要可读取的固定Git提交');
}
$composer = realpath($argv[3]);
$phpHome = realpath(getenv('PHP_HOME') ?: '');
expect(is_string($composer) && is_file($composer) && is_string($phpHome), '需要Composer PHAR及已核验PHP_HOME');
$base = $root . '/build/platform-benchmarks-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700) && mkdir($base . '/php.d', 0700), '无法创建独立基准工作根');
$record = ['protocol' => 2, 'status' => 'preparing', 'delivery' => 'shared-runtime-benchmark',
    'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'source_commits' => $commits,
    'preparer_sha256' => hash_file('sha256', __FILE__),
    'php' => PHP_VERSION, 'zts' => PHP_ZTS, 'php_cli_sha256' => hash_file('sha256', PHP_BINARY), 'php_memory_limit' => '2G',
    'composer_sha256' => hash_file('sha256', $composer),
    'variants' => []];
try {
    foreach ($commits as $variant => $commit) {
        $work = $base . '/' . $variant;
        $project = $work . '/project';
        expect(mkdir($project, 0700, true), '无法创建版本快照');
        successful(['git', 'archive', '--format=tar', '--output=' . $work . '/source.tar', $commit], $repository);
        expect((new PharData($work . '/source.tar'))->extractTo($project), '无法恢复固定版本源码');
        // PHPX尚未构建时仍须保留当前PHP工具自身的运行库，不能依赖系统全局库路径。
        $environment = array_replace((new BuildPlatform())->phpEnvironment(), (new BuildPlatform())->environment($phpHome, ''));
        $environment['PATH'] = getenv('PATH') ?: $environment['PATH'];
        $environment['PHPRC'] = getenv('PHPRC') ?: '';
        $environment['PHP_INI_SCAN_DIR'] = $base . '/php.d';
        $environment['COMPOSER_HOME'] = $work . '/composer-home';
        $environment['COMPOSER_CACHE_DIR'] = $root . '/.cache/composer';
        if (PHP_OS_FAMILY === 'Darwin') {
            // brew --prefix要求真实用户HOME；只继承现有账号位置，不改为项目或临时目录。
            $account = posix_getpwuid(posix_geteuid());
            expect(is_array($account) && is_dir($account['dir']), '无法定位构建账号');
            $environment['HOME'] = $account['dir'];
            $environment['HOMEBREW_NO_AUTO_UPDATE'] = '1';
        }
        echo $variant . "：安装固定依赖。\n";
        nativeDatabaseCommand([PHP_BINARY, $composer, '--working-dir=' . $project, 'install', '--no-scripts', '--no-plugins',
            '--no-interaction', '--prefer-dist', '--no-progress'], $environment, [], $work . '/install.log', 600);
        $phpx = $work . '/phpx';
        benchmarkPhpxSources($project . '/vendor/swoole/phpx', $phpx);
        $cmake = file_get_contents($phpx . '/CMakeLists.txt');
        $cmakeSha256 = hash('sha256', $cmake);
        $toolchain = json_decode(file_get_contents($project . '/toolchain.lock.json'), true, 512, JSON_THROW_ON_ERROR);
        $cmakeHashes = ['2.7.0' => 'e86aae53348307c34484b29644c2406a7fe51427dc90d7c58aaa55dc6ae35451',
            '2.8.1' => 'a9949224931b9904c21de8a6f15c156cc0fb93253016971794ca2130ff59fcb9',
            '2.9.0' => 'a9949224931b9904c21de8a6f15c156cc0fb93253016971794ca2130ff59fcb9',
            '2.9.2' => 'd50299d5d39cba259f310113c63c25a93e59275687f25c573b1ecc10d96e9d22',
            '2.9.3' => 'd50299d5d39cba259f310113c63c25a93e59275687f25c573b1ecc10d96e9d22'];
        expect(($cmakeHashes[$toolchain['phpx']['version']] ?? null) === $cmakeSha256, 'PHPX版本与受审构建规则摘要不符');
        $cmake = str_replace(
            'list(FILTER MPDEC_C_SOURCES EXCLUDE REGEX "bench")',
            'list(FILTER MPDEC_C_SOURCES EXCLUDE REGEX "/bench[.]c$")',
            $cmake,
            $replacements
        );
        expect($replacements === 1, 'PHPX的mpdecimal构建规则已变化，须重新核对');
        file_put_contents($phpx . '/CMakeLists.txt', $cmake);
        $environment['PHP_HOME'] = $phpHome;
        $environment['PHPX_HOME'] = $phpx;
        // 每个版本使用自己的受审适配，不能拿新版补丁或模块冒充 RC 基线。
        nativeDatabaseCommand([PHP_BINARY, '-n', '-r',
            'require $argv[1]; echo json_encode((new Type\\Build\\PhpxThreadSource())->apply($argv[2]), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);',
            $project . '/plugin/type-build/src/PhpxThreadSource.php', $phpx], $environment, [], $work . '/phpx-adaptations.json', 30);
        $swoole = json_decode(successful([PHP_BINARY, '-n', '-r',
            'require $argv[1]; echo json_encode((new Type\\Build\\BundledSwoole())->select(), JSON_THROW_ON_ERROR);',
            $project . '/plugin/type-build/src/BundledSwoole.php'], $project, $environment), true, 32, JSON_THROW_ON_ERROR);
        $bundle = json_decode(file_get_contents($swoole['manifest']), true, 32, JSON_THROW_ON_ERROR);
        // 前端仅构建一次；两个程序嵌入相同真实资源，避免资产差异混入工具链对照。
        $resources = (new \Type\Build\EmbeddedResourceCompiler())->collect($root, [['source' => 'web/dist', 'target' => 'web']]);
        foreach ($resources as $path => $resource) {
            scenarioCopy($resource['source'], $project . '/web/dist/' . substr($path, 4));
        }
        $frontend = (new \Type\Build\EmbeddedResourceCompiler())->manifest($resources);
        echo $variant . "：在同一PHP SDK构建独立PHPX。\n";
        nativeDatabaseCommand(['cmake', '-S', $phpx, '-B', $phpx . '/build', '-DCMAKE_BUILD_TYPE=Release',
            '-DBUILD_TESTS=OFF', '-DBUILD_EXT=OFF', '-Dphp_dir=' . $phpHome], $environment, [], $work . '/cmake.log', 120);
        nativeDatabaseCommand(
            ['cmake', '--build', $phpx . '/build', '--target', 'phpx', '--parallel', '2'],
            $environment,
            [],
            $work . '/phpx-build.log',
            1200
        );
        $environment = array_replace($environment, (new BuildPlatform())->environment($phpHome, $phpx));
        $entry = ['source_archive_sha256' => hash_file('sha256', $work . '/source.tar'),
            'swoole' => ['reference' => $bundle['source']['reference'], 'version' => $bundle['swoole'], 'sha256' => $swoole['sha256']],
            'frontend' => $frontend, 'phpx_adaptations_sha256' => hash_file('sha256', $work . '/phpx-adaptations.json'),
            'phpx_cmake_adaptation' => ['original_sha256' => $cmakeSha256, 'result_sha256' => hash('sha256', $cmake),
                'replacements' => 1, 'reason' => '仅排除基准程序bench.c，不能因父目录包含bench而漏编整个mpdecimal实现。'], 'roles' => []];
        foreach (['project' => 'type-app.json'] as $role => $configuration) {
            $directory = $work . '/' . $role;
            $sourceConfiguration = $directory . '/docs/build-config/' . $configuration;
            if (!is_file($sourceConfiguration)) {
                // 输入版本未包含负载声明时，使用同一控制器的声明并记录其身份。
                scenarioCopy($root . '/docs/build-config/' . $configuration, $sourceConfiguration);
            }
            $config = json_decode(file_get_contents($sourceConfiguration), true, 512, JSON_THROW_ON_ERROR);
            $config['runtime'][PHP_OS_FAMILY]['extensions'] = array_values(array_unique([...($config['runtime'][PHP_OS_FAMILY]['extensions'] ?? []), 'swoole']));
            $modules = $directory . '/benchmark-runtime';
            expect(mkdir($modules, 0700) && copy($swoole['file'], $modules . '/swoole.so')
                && hash_file('sha256', $modules . '/swoole.so') === $swoole['sha256'], '无法固定本版本 Swoole 运行模块');
            $config['runtime'][PHP_OS_FAMILY]['modules']['swoole'] = ['file' => 'benchmark-runtime/swoole.so', 'sha256' => $swoole['sha256']];
            $config['output'] = 'build/benchmark/type-app';
            $config['build-directory'] = 'build/benchmark/compiler';
            $config['development']['output'] = 'build/benchmark/development';
            file_put_contents($directory . '/docs/build-config/benchmark.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            $generation = benchmarkGeneration($directory, $environment, $work);
            echo $variant . '/' . $role . "：全量编译公共负载。\n";
            $buildStarted = hrtime(true);
            nativeDatabaseCommand(
                [PHP_BINARY, '-d', 'memory_limit=2G', $directory . '/vendor/bin/type', $directory . '/docs/build-config/benchmark.json'],
                $environment,
                [],
                $work . '/' . $role . '-build.log',
                1800
            );
            $artifact = $directory . '/build/benchmark/type-app';
            $build = json_decode(file_get_contents($artifact . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
            expect(hash_file('sha256', $artifact) === $build['sha256']
                && ($build['manifest']['runtime-linkage'] ?? 'shared') === 'shared'
                && ($build['manifest']['static-archives'] ?? null) === [] && !isset($build['manifest']['static-runtime'])
                && ($build['static-archives'] ?? null) === [], '基准产物与共享运行库构建记录不符');
            expect(($build['declaration-generation'] ?? null) === $generation['identity']['declaration_generation'], '开发生成与全量 AOT 的声明身份不同');
            $entry['roles'][$role] = ['artifact' => substr($artifact, strlen($root) + 1), 'sha256' => $build['sha256'],
                'build_seconds' => (hrtime(true) - $buildStarted) / 1e9, 'bytes' => filesize($artifact),
                'generation' => $generation, 'runtime_linkage' => 'shared',
                'runtime_ini_sha256' => hash_file('sha256', $build['runtime-profile']['ini']),
                'build_id' => $build['build-id'], 'report_sha256' => hash_file('sha256', $artifact . '.build.json'),
                'typephp' => $build['typephp'], 'phpx' => $build['phpx'], 'production_packages' => $build['production-packages']];
            // 编译计时已结束；恢复审计和压缩不混入编译耗时或请求测量窗口。
            $entry['roles'][$role]['preservation'] = benchmarkPreserveRuntime($root, $work, $artifact, $build);
        }
        $record['variants'][$variant] = $entry;
    }
    $record['status'] = 'prepared-not-measured';
} finally {
    if ($record['status'] === 'preparing') {
        $record['status'] = 'failed';
    }
    file_put_contents($base . '/preparation.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
}
echo '平台新旧基准准备完成：' . substr($base, strlen($root) + 1) . "/preparation.json\n";
