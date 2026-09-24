<?php

declare(strict_types=1);

require __DIR__ . '/build-scenario.php';
require __DIR__ . '/native-database.php';

use Type\Build\BuildPlatform;

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

$root = realpath(dirname(__DIR__));
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
$record = ['status' => 'preparing', 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'source_commits' => $commits,
    'preparer_sha256' => hash_file('sha256', __FILE__),
    'php' => PHP_VERSION, 'zts' => PHP_ZTS, 'php_cli_sha256' => hash_file('sha256', PHP_BINARY),
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
            '2.9.2' => 'd50299d5d39cba259f310113c63c25a93e59275687f25c573b1ecc10d96e9d22'];
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
            $module = getenv('TYPE_SWOOLE_MODULE');
            if ($module !== false) {
                $module = BuildPlatform::resolve($module);
                $modules = $directory . '/benchmark-runtime';
                expect(is_file($module) && mkdir($modules, 0700) && copy($module, $modules . '/swoole.so'), '无法固定显式Swoole运行模块');
                $config['runtime'][PHP_OS_FAMILY]['modules']['swoole'] = [
                    'file' => 'benchmark-runtime/swoole.so', 'sha256' => hash_file('sha256', $modules . '/swoole.so'),
                ];
            }
            $config['output'] = 'build/benchmark/type-app';
            $config['build-directory'] = 'build/benchmark/compiler';
            file_put_contents($directory . '/docs/build-config/benchmark.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            echo $variant . '/' . $role . "：全量编译公共负载。\n";
            nativeDatabaseCommand(
                [PHP_BINARY, $directory . '/vendor/bin/type', $directory . '/docs/build-config/benchmark.json'],
                $environment,
                [],
                $work . '/' . $role . '-build.log',
                1800
            );
            $artifact = $directory . '/build/benchmark/type-app';
            $build = json_decode(file_get_contents($artifact . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
            expect(hash_file('sha256', $artifact) === $build['sha256'], '基准产物与构建记录不符');
            $entry['roles'][$role] = ['artifact' => substr($artifact, strlen($root) + 1), 'sha256' => $build['sha256'],
                'build_id' => $build['build-id'], 'report_sha256' => hash_file('sha256', $artifact . '.build.json'),
                'typephp' => $build['typephp'], 'phpx' => $build['phpx'], 'production_packages' => $build['production-packages']];
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
