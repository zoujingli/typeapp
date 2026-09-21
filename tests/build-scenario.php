<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\BuildLock;
use Type\Build\BuildPlatform;

/** 仅为主仓组件演示创建独立消费者；生产构建继续使用原有type入口和完整自动加载审计。 */
function buildScenario(string $root, string $configuration, ?string $stage = null, bool $withSwoole = false): void
{
    $root = BuildPlatform::resolve($root);
    $configuration = BuildPlatform::resolve($configuration);
    expect(str_starts_with($configuration, $root . '/docs/build-config/'), '只接受主仓登记的构建场景');
    $settings = json_decode(file_get_contents($configuration), true, 512, JSON_THROW_ON_ERROR);
    if ($withSwoole) {
        expect(in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true), 'Swoole原生场景只接受Unix目标');
        $settings['runtime'][PHP_OS_FAMILY]['extensions'] = array_values(array_unique([
            ...($settings['runtime'][PHP_OS_FAMILY]['extensions'] ?? []), 'sockets', 'swoole',
        ]));
    }
    expect(($settings['project-root'] ?? null) === '../..', '场景必须声明主仓项目根');
    $sources = $settings['sources'] ?? [];
    if (isset($settings['entry'])) {
        $sources[] = $settings['entry'];
    }
    expect($sources !== [] && !in_array('app', $sources, true) && !in_array('app/main.php', $sources, true), '标准应用应直接使用自身完整构建入口');
    $output = (new BuildPlatform())->output($settings['output']);
    expect(str_starts_with($output, 'build/') && dirname($output) !== 'build' && !str_contains($output, '..'), '场景输出必须位于build的专属子目录');
    $work = $root . '/build/scenario-consumer-' . bin2hex(random_bytes(6));
    expect(mkdir($work, 0700, true), '无法创建独立场景消费者');
    $tracked = explode("\0", rtrim(successful(['git', 'ls-files', '-z'], $root), "\0"));
    foreach ($tracked as $relative) {
        expect($relative !== '' && !str_starts_with($relative, '/') && !str_contains($relative, '..'), '源码清单中的路径无效');
        if (is_file($root . '/' . $relative)) {
            scenarioCopy($root . '/' . $relative, $work . '/' . $relative);
        }
    }
    $swooleModule = getenv('TYPE_SWOOLE_MODULE');
    if ($withSwoole && is_string($swooleModule) && $swooleModule !== '') {
        $swooleModule = BuildPlatform::resolve($swooleModule);
        expect(is_file($swooleModule), '指定的 Swoole 模块不存在');
        expect(mkdir($work . '/modules', 0700), '无法创建独立模块目录');
        expect(copy($swooleModule, $work . '/modules/swoole.so'), '无法保全指定的 Swoole 模块');
        $settings['runtime'][PHP_OS_FAMILY]['modules']['swoole'] = [
            'file' => 'modules/swoole.so', 'sha256' => hash_file('sha256', $swooleModule),
        ];
    }
    $composer = json_decode(file_get_contents($work . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $originalName = $composer['name'];
    $composer['name'] = 'type-tests/scenario';
    $composer['autoload'] = ['classmap' => array_values(array_unique($sources))];
    // 命令消费者只安装实际需要的组件；完整集成场景继续安装主仓完整生产依赖。
    $requirements = match (basename($configuration)) {
        'type-foundation.json', 'type-build-identity.json' => ['zoujingli/type-runtime' => '~1.0.0@dev'],
        'type-commands.json' => ['zoujingli/type-core' => '~1.0.0@dev'],
        'type-mqtt.json' => ['zoujingli/type-mqtt' => '~1.0.0@dev'],
        'type-tasks.json' => ['zoujingli/type-orm-mysql' => '~1.0.0@dev', 'ext-swoole' => '^6.2'],
        'type-validation.json' => ['zoujingli/type-validate' => '~1.0.0@dev', 'zoujingli/type-core' => '~1.0.0@dev'],
        'type-trust-http.json' => ['zoujingli/type-core' => '~1.0.0@dev', 'zoujingli/type-validate' => '~1.0.0@dev'],
        'type-file-http.json' => ['zoujingli/type-core' => '~1.0.0@dev'],
        'type-log-http.json' => ['zoujingli/type-core' => '~1.0.0@dev', 'zoujingli/type-log' => '~1.0.0@dev'],
        'type-sqlite.json' => ['zoujingli/type-orm-sqlite' => '~1.0.0@dev'],
        'type-mysql.json' => ['zoujingli/type-orm-mysql' => '~1.0.0@dev'],
        'type-pgsql.json' => ['zoujingli/type-orm-pgsql' => '~1.0.0@dev'],
        'type-redis.json' => ['zoujingli/type-redis' => '~1.0.0@dev'],
        'type-scheduler.json' => ['zoujingli/type-scheduler' => '~1.0.0@dev'],
        'type-tls.json' => ['zoujingli/type-orm-mysql' => '~1.0.0@dev', 'zoujingli/type-orm-pgsql' => '~1.0.0@dev', 'zoujingli/type-redis' => '~1.0.0@dev'],
        'type-log.json', 'type-log-behavior.json', 'type-log-failures.json' => ['zoujingli/type-log' => '~1.0.0@dev'],
        'type-cache.json', 'type-psr-cache.json' => ['zoujingli/type-cache' => '~1.0.0@dev'],
        'type-queue.json', 'type-queue-leases.json', 'type-queue-retries.json' => ['zoujingli/type-queue' => '~1.0.0@dev'],
        'type-query.json' => ['zoujingli/type-orm-mysql' => '~1.0.0@dev', 'zoujingli/type-orm-pgsql' => '~1.0.0@dev', 'zoujingli/type-orm-sqlite' => '~1.0.0@dev', 'zoujingli/type-validate' => '~1.0.0@dev'],
        'type-pagination.json', 'type-read-write.json', 'type-identities.json', 'type-transactions.json', 'type-outcomes.json' => ['zoujingli/type-orm-mysql' => '~1.0.0@dev', 'zoujingli/type-orm-pgsql' => '~1.0.0@dev', 'zoujingli/type-orm-sqlite' => '~1.0.0@dev'],
        'type-models.json', 'type-exact-fields.json', 'type-relations.json', 'type-pivots.json', 'type-lifecycle.json', 'type-optimistic.json' => ['zoujingli/type-orm-mysql' => '~1.0.0@dev', 'zoujingli/type-orm-pgsql' => '~1.0.0@dev', 'zoujingli/type-orm-sqlite' => '~1.0.0@dev'],
        'type-operations.json', 'type-cache-consistency.json' => ['zoujingli/type-orm-mysql' => '~1.0.0@dev', 'zoujingli/type-orm-pgsql' => '~1.0.0@dev', 'zoujingli/type-orm-sqlite' => '~1.0.0@dev', 'zoujingli/type-cache' => '~1.0.0@dev'],
        'type-outbox.json' => ['zoujingli/type-orm-mysql' => '~1.0.0@dev', 'zoujingli/type-orm-pgsql' => '~1.0.0@dev', 'zoujingli/type-orm-sqlite' => '~1.0.0@dev', 'zoujingli/type-queue' => '~1.0.0@dev'],
        'type-tenant-http.json' => ['zoujingli/type-core' => '~1.0.0@dev', 'zoujingli/type-validate' => '~1.0.0@dev', 'zoujingli/type-log' => '~1.0.0@dev', 'zoujingli/type-cache' => '~1.0.0@dev',
            'zoujingli/type-orm-mysql' => '~1.0.0@dev', 'zoujingli/type-orm-pgsql' => '~1.0.0@dev', 'zoujingli/type-orm-sqlite' => '~1.0.0@dev'],
        default => $composer['require'],
    };
    $composer['require'] = ['php' => $composer['require']['php']] + $requirements;
    $toolchain = json_decode(file_get_contents($work . '/toolchain.lock.json'), true, 512, JSON_THROW_ON_ERROR);
    $composer['require-dev'] = ['zoujingli/type-build' => '~1.0.0@dev',
        'swoole/typephp' => $toolchain['typephp']['version'], 'swoole/phpx' => $toolchain['phpx']['version']];
    file_put_contents($work . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    if (isset($settings['application']['enabled'])) {
        $settings['application']['enabled'] = array_map(static fn (string $name): string => $name === $originalName ? $composer['name'] : $name, $settings['application']['enabled']);
    }
    $relativeConfiguration = substr($configuration, strlen($root) + 1);
    file_put_contents($work . '/' . $relativeConfiguration, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    // 使用原锁文件最小化求解并实际安装；不把未安装组件伪装为生产依赖或移入开发依赖。
    $composerPhar = getenv('TYPE_COMPOSER_PHAR');
    $composerCommand = is_string($composerPhar) && $composerPhar !== ''
        ? [PHP_BINARY, BuildPlatform::resolve($composerPhar)] : [getenv('COMPOSER_BINARY') ?: 'composer'];
    successful([...$composerCommand, 'update', '--minimal-changes', '--no-interaction', '--no-scripts', '--no-plugins', '--prefer-dist', '--no-progress'], $work);
    if ($stage !== null) {
        $destination = BuildPlatform::path($stage);
        BuildLock::path($destination);
        expect(BuildPlatform::contains($root . '/build', $destination) && !file_exists($destination), '场景快照必须使用主仓build下的新目录');
        $snapshot = $work . '/build/scenario-inputs';
        $record = json_decode(successful([PHP_BINARY, $work . '/vendor/bin/type', '--stage', $work . '/' . $relativeConfiguration, $snapshot], $work), true, 512, JSON_THROW_ON_ERROR);
        if (!is_dir(dirname($destination))) {
            expect(mkdir(dirname($destination), 0755, true), '无法创建快照父目录');
        }
        expect(rename($snapshot, $destination), '无法安装完整场景快照');
        $record['directory'] = $destination;
        echo json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
        return;
    }
    echo successful([PHP_BINARY, $work . '/vendor/bin/type', $work . '/' . $relativeConfiguration], $work);
    $artifact = $work . '/' . $output;
    (new BuildPlatform())->assertArtifact($artifact);
    $report = json_decode(file_get_contents($artifact . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
    expect(hash_file('sha256', $artifact) === $report['sha256'], '消费者产物摘要不一致');
    if (!is_dir(dirname($root . '/' . $output))) {
        expect(mkdir(dirname($root . '/' . $output), 0755, true), '无法创建主仓场景产物目录');
    }
    // 验证成功后才安装到原有主仓测试产物位置；失败不覆盖旧二进制。
    BuildLock::run($root . '/' . $output . '.lock', static function () use ($root, $output, $artifact): void {
        if (is_dir($artifact . '.resources')) {
            scenarioCopy($artifact . '.resources', $root . '/' . $output . '.resources');
        }
        foreach (['.build.json', ''] as $suffix) {
            $destination = $root . '/' . $output . $suffix;
            BuildLock::path($destination);
            $candidate = $destination . '.candidate-' . bin2hex(random_bytes(6));
            scenarioCopy($artifact . $suffix, $candidate);
            expect(rename($candidate, $destination), '无法安装已验证的场景产物');
        }
    });
    echo '独立场景消费者构建通过：' . substr($work, strlen($root) + 1) . "\n";
}

/** 复制实际输入并保留权限；只重建仓库内部的相对软链接。 */
function scenarioCopy(string $source, string $destination): void
{
    BuildLock::path($destination);
    if (!is_dir(dirname($destination))) {
        expect(mkdir(dirname($destination), 0755, true), '无法创建消费者目录');
    }
    if (is_link($source)) {
        $link = readlink($source);
        expect(is_string($link) && !str_starts_with($link, '/'), '场景输入不接受绝对目录链接');
        if (PHP_OS_FAMILY === 'Windows') {
            scenarioCopy(realpath($source), $destination);
        } else {
            expect(!file_exists($destination) && symlink($link, $destination), '无法重建消费者内部相对链接');
        }
    } elseif (is_dir($source)) {
        if (!is_dir($destination)) {
            expect(mkdir($destination, 0755), '无法创建消费者输入目录');
        }
        foreach (new DirectoryIterator($source) as $entry) {
            if (!$entry->isDot()) {
                scenarioCopy($entry->getPathname(), $destination . '/' . $entry->getFilename());
            }
        }
    } else {
        expect(copy($source, $destination) && chmod($destination, fileperms($source) & 0777), '无法复制消费者输入');
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $withSwoole = ($argv[1] ?? '') === '--with-swoole';
    if ($withSwoole) {
        array_splice($argv, 1, 1);
        $argc--;
    }
    $stage = ($argv[1] ?? '') === '--stage';
    expect($stage ? $argc === 4 : $argc === 2, '用法：php tests/build-scenario.php [--with-swoole] [--stage] <主仓构建场景> [新快照目录]');
    buildScenario(realpath(dirname(__DIR__)), $argv[$stage ? 2 : 1], $stage ? $argv[3] : null, $withSwoole);
}
