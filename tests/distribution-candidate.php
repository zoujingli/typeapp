<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/tools/distribution/Process.php';
require dirname(__DIR__) . '/tools/distribution/Batch.php';
require __DIR__ . '/native-database.php';

use Type\Build\ArtifactManifest;
use Type\Build\BuildPlatform;
use TypeApp\Distribution\Batch;
use TypeApp\Distribution\Process as GitProcess;

/** 只导出已经核对的本地Git对象，不执行push或联系远端仓库。 */
function candidateArchive(string $root, string $split, string $directory): array
{
    expect(preg_match('/^[a-f0-9]{40}$/D', $split) === 1 && !file_exists($directory), '候选快照须有固定Git身份及新目录');
    $expected = [];
    $tree = successful(['git', 'ls-tree', '-r', '-z', $split], $root);
    foreach (explode("\0", rtrim($tree, "\0")) as $entry) {
        expect(preg_match('/^100(?:644|755) blob ([a-f0-9]{40})\t(.+)$/sD', $entry, $match) === 1, 'Git 快照只允许普通文件');
        $expected[$match[2]] = $match[1];
    }
    ksort($expected);
    expect(mkdir($directory, 0700), '无法建立候选包目录');
    $tar = $directory . '.tar';
    GitProcess::output(['git', 'archive', '--format=tar', '--output=' . $tar, $split], $root);
    expect((new PharData($tar))->extractTo($directory), '无法解包候选Git内容');
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        expect($file->isFile() && !$file->isLink(), '候选只允许普通文件');
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($directory) + 1));
        $content = file_get_contents($file->getPathname());
        expect(is_string($content) && isset($expected[$relative]), 'Git 快照文件集合不一致：' . $relative);
        expect(hash('sha1', 'blob ' . strlen($content) . "\0" . $content) === $expected[$relative], 'Git 快照字节不一致：' . $relative);
        $files[$relative] = hash('sha256', $content);
    }
    ksort($files);
    expect(array_keys($files) === array_keys($expected), 'Git 快照文件集合不一致');
    return ['split' => $split, 'archive_sha256' => hash_file('sha256', $tar), 'files' => $files];
}

/**
 * 从完整固定快照安装并验证IoT应用，不改变分发映射或声明最终候选已冻结。
 *
 * @throws RuntimeException 安装、输入审计、完整编译或真实公开行为失败；保留本轮日志和状态。
 */
function candidateIot(string $root, string $source): void
{
    expect(PHP_OS_FAMILY === 'Darwin', 'IoT候选目前复用macOS内核禁源码装置；Linux完整候选另行验收');
    $base = $root . '/build/iot-candidate-' . bin2hex(random_bytes(6));
    expect(mkdir($base, 0700) && mkdir($base . '/php.d', 0700), '无法创建IoT候选目录');
    $consumer = $base . '/consumer';
    $record = ['status' => 'running', 'kind' => 'local-iot-candidate', 'source' => $source,
        'harness_sha256' => hash_file('sha256', __FILE__),
        'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'final_candidate_frozen' => false,
        'remote_distribution' => false, 'actions_executed' => false, 'frontend_deployed' => false,
        'checks' => [], 'unverified' => ['Linux-x64完整候选', '完整协议条款与互操作汇合', '完整真实页面走查',
            '容量及长期运行场景', '最终候选冻结和任务全部验收标准']];
    try {
        $record['snapshot'] = candidateArchive($root, $source, $consumer);
        $record['checks'][] = 'fixed-complete-git-snapshot';
        $composer = realpath(getenv('TYPE_COMPOSER_PHAR') ?: '');
        expect(is_string($composer) && is_file($composer), '需要显式TYPE_COMPOSER_PHAR');
        $environment = array_replace(getenv(), (new BuildPlatform())->environment(getenv('PHP_HOME') ?: '', getenv('PHPX_HOME') ?: ''));
        $environment['PHPRC'] = getenv('PHPRC') ?: '';
        $environment['PHP_INI_SCAN_DIR'] = $base . '/php.d';
        $environment['COMPOSER_HOME'] = $base . '/composer-home';
        $environment['COMPOSER_CACHE_DIR'] = $root . '/.cache/composer';
        $environment['TYPE_COMPOSER_PHAR'] = $composer;
        $record['composer_lock_sha256'] = hash_file('sha256', $consumer . '/composer.lock');
        $record['toolchain_lock_sha256'] = hash_file('sha256', $consumer . '/toolchain.lock.json');
        $record['web_lock_sha256'] = hash_file('sha256', $consumer . '/web/pnpm-lock.yaml');
        echo "IoT完整快照已导出，正在按原锁独立安装。\n";
        nativeDatabaseCommand([PHP_BINARY, $composer, '--working-dir=' . $consumer, 'install', '--no-scripts', '--no-plugins',
            '--no-interaction', '--prefer-dist', '--no-progress'], $environment, [], $base . '/install.log', 300);
        expect(hash_file('sha256', $consumer . '/composer.lock') === $record['composer_lock_sha256'], '安装改变了候选Composer锁');
        // 原锁显式要求symlink=true；仅允许新快照内部的相对链接，不借用主仓已安装vendor。
        $record['internal_links'] = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($consumer . '/vendor', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            if (!$entry->isLink()) {
                continue;
            }
            $resolved = $entry->getRealPath();
            expect(is_string($resolved) && str_starts_with($resolved, $consumer . '/') && !str_starts_with(readlink($entry->getPathname()), '/'), '安装链接越出独立快照');
            $record['internal_links'][substr($entry->getPathname(), strlen($consumer) + 1)] = substr($resolved, strlen($consumer) + 1);
        }
        $lock = json_decode(file_get_contents($consumer . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
        foreach ([...$lock['packages'], ...$lock['packages-dev']] as $package) {
            if (($package['dist']['type'] ?? '') !== 'path') {
                continue;
            }
            $prefix = $package['dist']['url'] . '/';
            expect(str_starts_with($prefix, 'plugin/') && !str_contains($prefix, '..'), '候选组件不是快照内部路径');
            foreach ($record['snapshot']['files'] as $relative => $digest) {
                if (str_starts_with($relative, $prefix)) {
                    $installed = $consumer . '/vendor/' . $package['name'] . '/' . substr($relative, strlen($prefix));
                    expect(is_file($installed) && hash_file('sha256', $installed) === $digest, '安装字节偏离固定组件：' . $relative);
                }
            }
        }
        $record['checks'][] = 'locked-install-and-internal-link-byte-audit';
        $web = json_decode(file_get_contents($consumer . '/web/package.json'), true, 512, JSON_THROW_ON_ERROR);
        $record['node'] = trim(nativeDatabaseCommand(['node', '--version'], $environment, [], $base . '/node.log', 10));
        $record['pnpm'] = trim(nativeDatabaseCommand(['pnpm', '--dir', $consumer . '/web', '--version'], $environment, [], $base . '/pnpm.log', 30));
        expect($web['packageManager'] === 'pnpm@' . $record['pnpm'], 'pnpm与Web锁定工具不符');
        foreach (['install' => ['install', '--frozen-lockfile'], 'typecheck' => ['typecheck'], 'build' => ['build']] as $step => $arguments) {
            nativeDatabaseCommand(['pnpm', '--dir', $consumer . '/web', ...$arguments], $environment, [], $base . '/web-' . $step . '.log', 300);
            $record['checks'][] = 'web-' . $step;
        }
        expect(hash_file('sha256', $consumer . '/web/pnpm-lock.yaml') === $record['web_lock_sha256'], '前端验证改变了冻结锁');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($consumer . '/web/dist', FilesystemIterator::SKIP_DOTS)) as $asset) {
            expect($asset->isFile() && !$asset->isLink(), '前端构建产物必须为普通文件');
            $record['web_artifacts'][substr($asset->getPathname(), strlen($consumer) + 1)] = hash_file('sha256', $asset->getPathname());
        }
        echo "IoT独立安装与Vben检查通过，正在完整应用AOT。\n";
        nativeDatabaseCommand(
            [PHP_BINARY, $consumer . '/vendor/bin/type', $consumer . '/docs/build-config/type-app.json'],
            $environment,
            [],
            $base . '/build.log',
            1800
        );
        $artifact = (new BuildPlatform())->output($consumer . '/build/app/type-app');
        $manifest = (new ArtifactManifest())->read($artifact);
        $built = json_decode(file_get_contents($artifact . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
        $names = array_column($lock['packages'], 'name');
        $compiled = array_keys($manifest['production-packages']);
        sort($names);
        sort($compiled);
        expect($names === $compiled && in_array('zoujingli/type-mqtt', $names, true)
            && $manifest['composer-lock-sha256'] === $record['composer_lock_sha256'], 'IoT完整生产依赖或锁定身份不匹配');
        foreach ($built['source-sets'] as $set) {
            expect($set['exclusions'] === [], 'IoT完整候选不能排除生产文件');
        }
        $record['artifact_sha256'] = hash_file('sha256', $artifact);
        $record['build_id'] = $manifest['build-id'];
        $record['build_report_sha256'] = hash_file('sha256', $artifact . '.build.json');
        $record['production_packages'] = $names;
        $record['dependency_notices'] = $manifest['dependency-notices'];
        $record['source_sets'] = count($built['source-sets']);
        $record['source_inputs'] = count($built['sources']);
        $record['checks'][] = 'complete-iot-production-aot';
        $environment['TYPE_NATIVE_PHP_INI'] = $built['runtime-profile']['ini'];
        $mysql = (string) getenv('TYPE_MYSQL_TOOLS');
        $pgsql = (string) getenv('TYPE_PGSQL_TOOLS');
        $scenarios = [
            'package' => ['native-package.php', [], 180],
            'framework-databases' => ['native-database-application.php', [$mysql, $pgsql], 600],
            'iot-databases' => ['iot-identity-databases.php', [$mysql, $pgsql, '--no-source', '--app', '--audit', '--products', '--devices', '--history', '--aggregate', '--alarms', '--exports', '--operations'], 1500],
            'iot-mqtt' => ['iot-device-mqtt.php', ['--ingestion', '--no-source'], 720],
        ];
        foreach ($scenarios as $name => [$script, $arguments, $seconds]) {
            echo '正在运行同一IoT产物公开验收：' . $name . "\n";
            $output = nativeDatabaseCommand(
                [PHP_BINARY, $consumer . '/tests/' . $script, $artifact, ...$arguments],
                $environment,
                [],
                $base . '/' . $name . '.log',
                $seconds
            );
            $record['checks'][] = $name;
            $record['scenario_logs'][$name] = ['file' => $name . '.log', 'sha256' => hash_file('sha256', $base . '/' . $name . '.log')];
            if (preg_match_all('#(?:通过|证据)：([^\r\n]*?/verification\.json)#u', $output, $matches) > 0) {
                foreach ($matches[1] as $report) {
                    expect(str_starts_with($report, $consumer . '/build/') && is_file($report), '场景报告越出独立消费目录');
                    $record['scenario_reports'][substr($report, strlen($base) + 1)] = hash_file('sha256', $report);
                }
            }
        }
        foreach ($record['snapshot']['files'] as $relative => $digest) {
            expect(is_file($consumer . '/' . $relative) && hash_file('sha256', $consumer . '/' . $relative) === $digest, '验收改变了固定源码：' . $relative);
        }
        $record['checks'][] = 'fixed-source-unchanged-after-verification';
        $record['status'] = 'passed';
    } catch (Throwable $failure) {
        $record['failure'] = str_replace([$consumer, $root], ['consumer', 'project'], $failure->getMessage());
        throw $failure;
    } finally {
        if ($record['status'] !== 'passed') {
            $record['status'] = 'failed';
        }
        foreach (glob($base . '/*.log') as $log) {
            $record['logs'][basename($log)] = hash_file('sha256', $log);
        }
        file_put_contents($base . '/verification.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
    echo 'IoT本机局部候选通过，最终交付未冻结：' . $base . "/verification.json\n";
}

$root = realpath(dirname(__DIR__));
$source = $argv[1] ?? '';
expect(array_diff(array_slice($argv, 2), ['--iot']) === [], '用法：php tests/distribution-candidate.php <当前完整SHA> [--iot]');
expect(preg_match('/^[a-f0-9]{40}$/D', $source) === 1 && GitProcess::output(['git', 'rev-parse', 'HEAD'], $root) === $source, '候选只接受当前完整提交SHA');
if (in_array('--iot', $argv, true)) {
    candidateIot($root, $source);
    exit(0);
}
$mapping = json_decode(GitProcess::output(['git', 'show', $source . ':.github/distribution.json'], $root), true, 512, JSON_THROW_ON_ERROR);
$plan = Batch::plan($root, $source, 'branch', '', $mapping);
$templateMap = json_decode(GitProcess::output(['git', 'show', $source . ':.github/template-distribution.json'], $root), true, 512, JSON_THROW_ON_ERROR);
expect($templateMap['prefix'] === 'templates/type-project' && $templateMap['repository'] === 'zoujingli/type-project'
    && $templateMap['visibility'] === 'public', '模板映射超出已声明范围');
$base = $root . '/build/distribution-candidate-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建候选验证目录');
$consumer = $base . '/consumer';
$record = ['status' => 'running', 'kind' => 'local-candidate', 'source' => $source, 'plan_id' => $plan['id'],
    'remote_distribution' => false, 'actions_executed' => false, 'components' => []];
try {
    expect(mkdir($base . '/packages', 0700) && mkdir($consumer, 0700) && mkdir($base . '/php.d', 0700), '无法创建候选验证子目录');
    foreach ($plan['items'] as $name => $item) {
        $record['components'][$name] = candidateArchive($root, $item['split'], $base . '/packages/' . $name);
        $record['components'][$name]['tree'] = $item['tree'];
    }
    $templateSplit = GitProcess::output(['git', 'subtree', 'split', '--prefix=templates/type-project', '--ignore-joins', $source], $root);
    expect(GitProcess::output(['git', 'rev-parse', $templateSplit . '^{tree}'], $root)
        === GitProcess::output(['git', 'rev-parse', $source . ':templates/type-project'], $root), '模板split内容与候选源码不一致');
    $record['template'] = candidateArchive($root, $templateSplit, $base . '/template');
    $composer = ['name' => 'type-tests/local-candidate', 'type' => 'project', 'license' => 'Apache-2.0',
        'require' => ['php' => '>=8.4 <8.6'], 'require-dev' => [], 'repositories' => [], 'minimum-stability' => 'dev', 'prefer-stable' => true,
        'autoload' => ['classmap' => ['app']], 'config' => ['allow-plugins' => false]];
    foreach ($mapping['packages'] as $name => $package) {
        $composer[in_array($name, ['type-build', 'type-testing'], true) ? 'require-dev' : 'require'][$package['composer-name']] = '~1.0.0@dev';
        $composer['repositories'][] = ['type' => 'path', 'url' => '../packages/' . $name, 'options' => ['symlink' => false,
            'versions' => [$package['composer-name'] => '1.0.x-dev']]];
    }
    expect(mkdir($consumer . '/app', 0700), '无法准备候选应用入口');
    foreach (['examples/native-command.php' => 'app/main.php', 'toolchain.lock.json' => 'toolchain.lock.json'] as $input => $destination) {
        $content = successful(['git', 'show', $source . ':' . $input], $root);
        expect(file_put_contents($consumer . '/' . $destination, $content) === strlen($content), '无法保存固定候选输入：' . $input);
    }
    file_put_contents($consumer . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    file_put_contents($consumer . '/type-app.json', json_encode(['name' => 'local-candidate', 'entry' => 'app/main.php', 'sources' => ['app'],
        'output' => 'build/type-app', 'build-directory' => 'build/compiler'], JSON_THROW_ON_ERROR) . "\n");
    $environment = (new BuildPlatform())->environment(getenv('PHP_HOME') ?: '', getenv('PHPX_HOME') ?: '');
    $environment['PHPRC'] = getenv('PHPRC') ?: '';
    $environment['PHP_INI_SCAN_DIR'] = $base . '/php.d';
    $environment['COMPOSER_HOME'] = $base . '/composer-home';
    $environment['COMPOSER_CACHE_DIR'] = $root . '/.cache/composer';
    $composerPhar = realpath(getenv('TYPE_COMPOSER_PHAR') ?: '');
    expect(is_string($composerPhar), '需要显式TYPE_COMPOSER_PHAR');
    $environment['TYPE_COMPOSER_PHAR'] = $composerPhar;
    nativeDatabaseCommand([PHP_BINARY, $composerPhar, '--working-dir=' . $consumer, 'install', '--no-scripts', '--no-plugins',
        '--no-interaction', '--prefer-dist', '--no-progress'], $environment, [], $base . '/install.log', 240);
    foreach ($record['components'] as $name => $component) {
        $installed = $consumer . '/vendor/zoujingli/' . $name;
        expect(!is_link($installed), '候选消费不能依靠主仓软链接');
        foreach ($component['files'] as $relative => $digest) {
            expect(is_file($installed . '/' . $relative) && hash_file('sha256', $installed . '/' . $relative) === $digest, '安装内容偏离候选split：' . $name . '/' . $relative);
        }
    }
    echo count($record['components']) . " 个组件候选已独立安装，正在全量编译。\n";
    nativeDatabaseCommand(
        [PHP_BINARY, $consumer . '/vendor/bin/type', $consumer . '/type-app.json'],
        $environment,
        [],
        $base . '/build.log',
        1800
    );
    $artifact = (new BuildPlatform())->output($consumer . '/build/type-app');
    $manifest = (new ArtifactManifest())->read($artifact);
    $lock = json_decode(file_get_contents($consumer . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
    $names = array_column($lock['packages'], 'name');
    $compiled = array_keys($manifest['production-packages']);
    sort($names);
    sort($compiled);
    expect($names === $compiled && !in_array('zoujingli/type-build', $names, true) && !in_array('zoujingli/type-testing', $names, true), '候选没有编译全部生产依赖或混入开发工具');
    echo nativeDatabaseCommand([PHP_BINARY, $root . '/tests/native.php', $artifact], $environment, [], $base . '/native.log', 60);
    $record['artifact_sha256'] = hash_file('sha256', $artifact);
    $record['build_id'] = $manifest['build-id'];
    $record['build_report_sha256'] = hash_file('sha256', $artifact . '.build.json');
    $record['composer_lock_sha256'] = hash_file('sha256', $consumer . '/composer.lock');
    $record['production_packages'] = $names;
    $record['dependency_notices'] = $manifest['dependency-notices'];
    $templateEnvironment = $environment;
    $templateEnvironment['TYPE_TEMPLATE_SOURCE'] = $base . '/template';
    $templateEnvironment['TYPE_TEMPLATE_COMPONENT_DIRECTORY'] = $base . '/packages';
    echo "正在从候选模板创建并验证独立SQLite业务。\n";
    $output = nativeDatabaseCommand(
        [PHP_BINARY, $root . '/tests/application-template.php', 'sqlite', '--onboarding', '--native', '--package'],
        $templateEnvironment,
        [],
        $base . '/template.log',
        1800
    );
    expect(preg_match('#应用模板独立 sqlite 消费验证通过：([^\\r\\n]+)#u', $output, $matches) === 1, '候选模板没有返回实际消费结果');
    $templateConsumer = realpath(trim($matches[1]));
    expect(is_string($templateConsumer) && str_starts_with($templateConsumer, $root . '/build/template-sqlite-'), '模板消费者不属于本轮范围');
    foreach ($record['components'] as $name => $component) {
        $installed = $templateConsumer . '/vendor/zoujingli/' . $name;
        if (!is_dir($installed)) {
            continue;
        }
        foreach ($component['files'] as $relative => $digest) {
            expect(is_file($installed . '/' . $relative) && hash_file('sha256', $installed . '/' . $relative) === $digest, '模板安装没有使用候选split内容');
        }
    }
    $record['template_consumer'] = substr($templateConsumer, strlen($root) + 1);
    $record['template_report_sha256'] = hash_file('sha256', $templateConsumer . '/verification.json');
    $record['checks'] = ['fixed-git-source', 'exact-component-splits', 'exact-template-split', 'no-root-business-or-secrets',
        'isolated-installed-bytes-match', 'complete-production-aot', 'native-command-cases', 'template-create-php-aot-relocate-archive'];
    $record['status'] = 'passed';
} catch (Throwable $failure) {
    $record['failure'] = str_replace([$consumer, $root], ['consumer', 'project'], $failure->getMessage());
    throw $failure;
} finally {
    if ($record['status'] !== 'passed') {
        $record['status'] = 'failed';
    }
    file_put_contents($base . '/verification.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
echo '本地候选审计与独立消费通过：' . $base . "/verification.json\n";
