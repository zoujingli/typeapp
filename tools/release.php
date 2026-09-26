<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/distribution/Process.php';
require __DIR__ . '/distribution/Batch.php';
require __DIR__ . '/release/Plan.php';
require __DIR__ . '/release/GitHub.php';
require __DIR__ . '/release/Packagist.php';
require __DIR__ . '/release/Evidence.php';

use TypeApp\Distribution\Batch;
use TypeApp\Distribution\Process;
use TypeApp\Release\GitHub;
use TypeApp\Release\Packagist;
use TypeApp\Release\Plan;
use TypeApp\Release\Evidence;

/** 输出固定Actions参数；不接收自由格式换行或脚本。 */
function releaseOutput(string $key, string $value): void
{
    if (preg_match('/[\r\n]/', $key . $value)) {
        throw new RuntimeException('发布输出不能包含换行');
    }
    $output = getenv('GITHUB_OUTPUT');
    if (is_string($output) && $output !== '') {
        file_put_contents($output, $key . '=' . $value . "\n", FILE_APPEND);
    }
}

try {
    $root = dirname(__DIR__);
    $operation = $argv[1] ?? '';
    $version = $argv[2] ?? '';
    Plan::version($version);
    $api = new GitHub($root);
    $plan = Plan::create($root, $version);
    $source = $plan['source'];
    if (Process::output(['git', 'rev-parse', 'HEAD'], $root) !== $source) {
        throw new RuntimeException('发布必须检出所选版本的固定SHA');
    }
    $work = $root . '/build/release';
    Process::report($work . '/plan.json', $plan);
    if ($operation === 'resolve') {
        // 手动入口选择对应tag作为workflow ref，防止混用其他提交的工作流与凭据。
        if (getenv('GITHUB_SHA') !== $source || getenv('GITHUB_REF') !== 'refs/tags/' . $version) {
            throw new RuntimeException('请选择该版本tag作为工作流ref，不能从main重试其他源码版本');
        }
        $existing = $api->find('zoujingli/typeapp', $version);
        $run = (string) getenv('GITHUB_RUN_ID');
        $attempt = (string) getenv('GITHUB_RUN_ATTEMPT');
        if ($existing !== null) {
            if (!preg_match('/<!-- typeapp-candidate source=([a-f0-9]{40}) run=([1-9][0-9]*) attempt=([1-9][0-9]*) -->/', $existing['body'], $match)
                || $match[1] !== $source || $existing['target_commitish'] !== $source) {
                throw new RuntimeException('既有Release缺少本工具封存的候选身份，拒绝重编译替换');
            }
            $run = $match[2];
            $attempt = $match[3];
        }
        foreach (['source' => $source, 'version' => $version, 'resume' => $existing === null ? 'false' : 'true',
            'sealed' => $existing !== null && in_array('release-manifest.json', array_column($existing['assets'], 'name'), true) ? 'true' : 'false',
            'candidate_run' => $run, 'candidate_attempt' => $attempt] as $key => $value) {
            releaseOutput($key, $value);
        }
    } elseif ($operation === 'restore') {
        $assets = $work . '/assets';
        $file = $api->download('zoujingli/typeapp', $version, 'release-manifest.json', $assets);
        $manifest = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        if (($manifest['source'] ?? '') !== $source || ($manifest['version'] ?? '') !== $version
            || ($manifest['candidate-run'] ?? '') !== getenv('TYPE_RELEASE_EVIDENCE_RUN')
            || ($manifest['candidate-attempt'] ?? '') !== getenv('TYPE_RELEASE_EVIDENCE_ATTEMPT')) {
            throw new RuntimeException('既有候选身份不属于本次重试');
        }
        foreach (['linux-x64', 'linux-arm64', 'macos-arm64', 'windows-x64'] as $platform) {
            $record = $manifest['platforms'][$platform] ?? throw new RuntimeException('既有候选缺少平台');
            $archive = $api->download('zoujingli/typeapp', $version, $record['archive'], $assets);
            if (hash_file('sha256', $archive) !== $record['sha256'] || filesize($archive) !== $record['bytes']) {
                throw new RuntimeException('回读候选附件与封存摘要不同：' . $platform);
            }
            Process::report($assets . '/' . $platform . '.json', $record);
        }
        $sums = '';
        foreach ($manifest['platforms'] as $record) {
            $sums .= $record['sha256'] . '  ' . $record['archive'] . "\n";
        }
        $sums .= hash_file('sha256', $file) . "  release-manifest.json\n";
        $checksums = $api->download('zoujingli/typeapp', $version, 'SHA256SUMS', $assets);
        if (file_get_contents($checksums) !== $sums) {
            throw new RuntimeException('候选SHA256SUMS与原始附件不一致');
        }
    } elseif ($operation === 'candidate') {
        Batch::nativeEvidence($root, $source);
        $run = (string) getenv('TYPE_RELEASE_EVIDENCE_RUN');
        $attempt = (string) getenv('TYPE_RELEASE_EVIDENCE_ATTEMPT');
        $assets = $work . '/assets';
        $manifest = ['protocol' => 1, 'source' => $source, 'version' => $version, 'candidate-run' => $run, 'candidate-attempt' => $attempt,
            'delivery' => 'native-directory-archive', 'native-libraries-statically-linked' => false, 'platforms' => []];
        $sums = '';
        $frontend = null;
        foreach (['linux-x64', 'linux-arm64', 'macos-arm64', 'windows-x64'] as $platform) {
            $record = json_decode((string) file_get_contents($assets . '/' . $platform . '.json'), true, 64, JSON_THROW_ON_ERROR);
            $name = 'typeapp-iot-' . substr($version, 1) . '-' . $platform . ($platform === 'windows-x64' ? '.zip' : '.tar.gz');
            if (($record['status'] ?? '') !== 'passed' || ($record['source'] ?? '') !== $source || ($record['version'] ?? '') !== $version
                || ($record['platform'] ?? '') !== $platform || ($record['archive'] ?? '') !== $name
                || hash_file('sha256', $assets . '/' . $name) !== $record['sha256'] || filesize($assets . '/' . $name) !== $record['bytes']) {
                throw new RuntimeException('候选附件与验收身份不一致：' . $platform);
            }
            foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
                $test = $record['acceptance'][$driver] ?? [];
                if (($test['status'] ?? '') !== 'passed' || ($test['frontend-source-removed'] ?? false) !== true
                    || ($test['archive-sha256'] ?? '') !== $record['sha256']
                    || ($test['artifact-sha256'] ?? '') !== $record['artifact-sha256']) {
                    throw new RuntimeException('候选缺少同一产物三库验收：' . $platform . '/' . $driver);
                }
            }
            $frontend ??= $record['frontend-manifest-sha256'];
            if ($frontend !== $record['frontend-manifest-sha256']) {
                throw new RuntimeException('四平台前端不是同一冻结构建');
            }
            $manifest['platforms'][$platform] = $record;
            $sums .= $record['sha256'] . '  ' . $name . "\n";
        }
        Process::report($assets . '/release-manifest.json', $manifest);
        $sums .= hash_file('sha256', $assets . '/release-manifest.json') . "  release-manifest.json\n";
        file_put_contents($assets . '/SHA256SUMS', $sums);
        $body = "TypeApp 物联中心 {$version}\n\nTypePHP全量编译；Swoole为随包交付的内置原生运行库。当前附件为原生目录归档，完整静态单程序目标尚未完成。\n\n"
            . "解压对应平台附件，按DEPLOY.md配置后执行app:install；页面从程序安装到public。后续使用web:install --force更新托管页面，普通启动不释放资源。MySQL/PostgreSQL由外部服务提供，SQLite使用本地数据文件。\n\n"
            . "源码：{$source}。下载后先核对SHA256SUMS，四平台与三库验收身份见release-manifest.json。\n\n"
            . "<!-- typeapp-candidate source={$source} run={$run} attempt={$attempt} -->\n";
        $api->draft('zoujingli/typeapp', $version, $source, $body);
        foreach ([...array_column($manifest['platforms'], 'archive'), 'SHA256SUMS', 'release-manifest.json'] as $name) {
            $api->asset('zoujingli/typeapp', $version, $assets . '/' . $name);
        }
    } elseif ($operation === 'packagist') {
        Process::report($work . '/packagist.json', ['source' => $source, 'version' => $version,
            'items' => Packagist::wait($plan['items'], $version)]);
    } elseif ($operation === 'verify-consumption') {
        Evidence::consumption($root, $plan);
        Process::report($work . '/consumption.json', ['source' => $source, 'version' => $version,
            'run' => getenv('GITHUB_RUN_ID'), 'attempt' => getenv('GITHUB_RUN_ATTEMPT'), 'status' => 'passed']);
    } elseif ($operation === 'children') {
        Evidence::reports($root, $plan);
        $consumption = json_decode((string) file_get_contents($work . '/consumption.json'), true, 32, JSON_THROW_ON_ERROR);
        if (($consumption['source'] ?? '') !== $source || ($consumption['version'] ?? '') !== $version
            || ($consumption['run'] ?? '') !== getenv('GITHUB_RUN_ID') || ($consumption['attempt'] ?? '') !== getenv('GITHUB_RUN_ATTEMPT')
            || ($consumption['status'] ?? '') !== 'passed') {
            throw new RuntimeException('公开子仓前需要主仓令牌核验本轮消费任务');
        }
        $batch = json_decode((string) file_get_contents($root . '/build/distribution/batch-result.json'), true, 64, JSON_THROW_ON_ERROR);
        $mapping = json_decode((string) file_get_contents($root . '/.github/distribution.json'), true, 64, JSON_THROW_ON_ERROR);
        Batch::verifyReport($root, $source, $batch, $mapping);
        if ($batch['version'] !== $version || $batch['mode'] !== 'tag') {
            throw new RuntimeException('子仓Release缺少本版本分发回执');
        }
        $receipts = [];
        foreach ($plan['items'] as $name => $item) {
            try {
                $install = $name === 'type-project'
                    ? 'composer create-project ' . $item['package'] . ' my-app ' . substr($version, 1) . ' --no-install'
                    : 'composer require ' . (in_array($name, ['type-build', 'type-testing'], true) ? '--dev ' : '') . $item['package'] . ':' . substr($version, 1);
                $body = $item['description'] . "\n\n```sh\n" . $install . "\n```\n\n主仓版本：https://github.com/zoujingli/typeapp/releases/tag/" . $version
                    . "\n\n主仓提交：" . $source . "\n组件提交：" . $item['split'] . "\n\n组件用于开发和TypePHP构建，运行应用不需要Composer。\n";
                $api->draft($item['repository'], $version, $item['split'], $body);
                $receipts[$name] = $api->publish($item['repository'], $version) + ['source' => $source, 'split' => $item['split']];
            } catch (Throwable $error) {
                $receipts[$name] = ['status' => 'failed', 'error' => $error->getMessage()];
                throw $error;
            } finally {
                Process::report($work . '/children.json', ['source' => $source, 'version' => $version, 'items' => $receipts]);
            }
        }
    } elseif ($operation === 'publish') {
        Evidence::consumption($root, $plan);
        $receipt = json_decode((string) file_get_contents($work . '/children.json'), true, 64, JSON_THROW_ON_ERROR);
        if (($receipt['source'] ?? '') !== $source || ($receipt['version'] ?? '') !== $version || count($receipt['items'] ?? []) !== 16) {
            throw new RuntimeException('主仓公开前缺少完整16子仓回执');
        }
        foreach ($plan['items'] as $name => $item) {
            $entry = $receipt['items'][$name] ?? [];
            $remote = $api->find($item['repository'], $version);
            if (($entry['status'] ?? '') !== 'published' || ($entry['split'] ?? '') !== $item['split'] || $remote === null || $remote['draft']
                || $remote['target_commitish'] !== $item['split'] || $remote['prerelease'] !== $plan['prerelease']) {
                throw new RuntimeException('子仓Release尚未完整公开：' . $name);
            }
        }
        // 公开前再次回读全部附件；candidate使用最初封存的artifact，禁止重新构建替换。
        $manifest = json_decode((string) file_get_contents($work . '/assets/release-manifest.json'), true, 64, JSON_THROW_ON_ERROR);
        foreach ([...array_column($manifest['platforms'], 'archive'), 'SHA256SUMS', 'release-manifest.json'] as $name) {
            $api->asset('zoujingli/typeapp', $version, $work . '/assets/' . $name);
        }
        Process::report($work . '/published.json', $api->publish('zoujingli/typeapp', $version) + ['source' => $source, 'children' => $receipt['items']]);
    } else {
        throw new InvalidArgumentException('用法：php tools/release.php <resolve|restore|candidate|packagist|verify-consumption|children|publish> <版本tag>');
    }
    echo '版本发布步骤完成：' . $operation . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, '版本发布失败：' . $error->getMessage() . "\n");
    exit(1);
}
