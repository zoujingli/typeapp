<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/distribution/Process.php';
require __DIR__ . '/distribution/Batch.php';
require __DIR__ . '/release/Plan.php';
require __DIR__ . '/release/GitHub.php';
require __DIR__ . '/release/Packagist.php';
require __DIR__ . '/release/Evidence.php';
require __DIR__ . '/release/Candidate.php';
require __DIR__ . '/release/SizeGate.php';

use TypeApp\Distribution\Batch;
use TypeApp\Distribution\Process;
use TypeApp\Release\GitHub;
use TypeApp\Release\Packagist;
use TypeApp\Release\Plan;
use TypeApp\Release\Evidence;
use TypeApp\Release\Candidate;

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
    if ($operation === 'prepare') {
        if (count($argv) !== 3) {
            throw new InvalidArgumentException('用法：php tools/release.php prepare <尚未创建的版本tag>');
        }
        echo json_encode(Plan::prepareTemplate($root, $version), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }
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
        $records = $manifest['programs'] ?? $manifest['platforms'] ?? [];
        if (($manifest['protocol'] ?? null) === 3) {
            if (($manifest['delivery'] ?? '') !== 'single-executable' || ($manifest['profiles'] ?? []) !== Candidate::PROFILES
                || !is_array($records)) {
                throw new RuntimeException('已封存候选缺少完整四平台×三 profile 矩阵');
            }
            Candidate::assertMatrix($records);
        }
        foreach ($records as $key => $record) {
            foreach (Candidate::attachments($record, false) as $attachment) {
                $archive = $api->download('zoujingli/typeapp', $version, $attachment['file'], $assets);
                if (hash_file('sha256', $archive) !== $attachment['sha256'] || filesize($archive) !== $attachment['bytes']) {
                    throw new RuntimeException('回读候选附件与封存摘要不同：' . $key);
                }
            }
            Process::report($assets . '/' . $key . '.json', $record);
        }
        $sums = '';
        foreach ($records as $record) {
            foreach (Candidate::attachments($record, false) as $attachment) {
                $sums .= $attachment['sha256'] . '  ' . $attachment['file'] . "\n";
            }
        }
        $sums .= hash_file('sha256', $file) . "  release-manifest.json\n";
        $checksums = $api->download('zoujingli/typeapp', $version, 'SHA256SUMS', $assets);
        if (file_get_contents($checksums) !== $sums) {
            throw new RuntimeException('候选SHA256SUMS与原始附件不一致');
        }
    } elseif ($operation === 'candidate') {
        Evidence::tutorialCandidate($root, $plan);
        Batch::nativeEvidence($root, $source);
        $run = (string) getenv('TYPE_RELEASE_EVIDENCE_RUN');
        $attempt = (string) getenv('TYPE_RELEASE_EVIDENCE_ATTEMPT');
        $assets = $work . '/assets';
        $sealedFile = $assets . '/release-manifest.json';
        $sealed = is_file($sealedFile) ? json_decode((string) file_get_contents($sealedFile), true, 128, JSON_THROW_ON_ERROR) : null;
        $manifest = ['protocol' => 3, 'source' => $source, 'version' => $version, 'candidate-run' => $run, 'candidate-attempt' => $attempt,
            'delivery' => 'single-executable', 'native-libraries-statically-linked' => true, 'profiles' => Candidate::PROFILES, 'programs' => []];
        $sums = '';
        $frontend = null;
        foreach (Candidate::PLATFORMS as $platform) {
            foreach (Candidate::PROFILES as $profile) {
                $key = $platform . '-' . $profile;
                $recordFile = $assets . '/' . $key . '.json';
                if (!is_file($recordFile) || is_link($recordFile)) {
                    throw new RuntimeException('缺少 profile 候选回执：' . $key);
                }
                $record = json_decode((string) file_get_contents($recordFile), true, 64, JSON_THROW_ON_ERROR);
                Candidate::verify($record, $assets, $source, $version, $platform, $profile);
                $frontend ??= $record['frontend-manifest-sha256'];
                if ($frontend !== $record['frontend-manifest-sha256']) {
                    throw new RuntimeException('12个 profile 前端不是同一冻结构建');
                }
                $manifest['programs'][$key] = $record;
                foreach (Candidate::attachments($record, false) as $attachment) {
                    $sums .= $attachment['sha256'] . '  ' . $attachment['file'] . "\n";
                }
            }
        }
        Candidate::assertMatrix($manifest['programs']);
        if ($sealed !== null) {
            if (($sealed['source'] ?? null) !== $source || ($sealed['version'] ?? null) !== $version
                || ($sealed['candidate-run'] ?? null) !== $run || ($sealed['candidate-attempt'] ?? null) !== $attempt
                || ($sealed['programs'] ?? null) !== $manifest['programs'] || !isset($sealed['size-gate'])) {
                throw new RuntimeException('恢复的候选与原始封存清单不一致');
            }
            $manifest = $sealed;
        } else {
            $artifacts = $api->candidateArtifacts($run, $attempt, $source);
            foreach ($artifacts as $key => $artifact) {
                $manifest['programs'][$key]['rebuild']['artifact'] = $artifact;
            }
            $baseline = $api->sizeBaseline($version);
            $policy = json_decode((string) file_get_contents($root . '/.github/release-size-policy.json'), true, 64, JSON_THROW_ON_ERROR);
            $manifest['size-gate'] = \TypeApp\Release\SizeGate::verify($manifest['programs'], $baseline['manifest'] ?? null, $policy, $version);
            $manifest['size-gate']['baseline-manifest-sha256'] = $baseline['sha256'] ?? null;
            $manifest['size-gate']['baseline-release-id'] = $baseline['release-id'] ?? null;
        }
        Process::report($assets . '/release-manifest.json', $manifest);
        $sums .= hash_file('sha256', $assets . '/release-manifest.json') . "  release-manifest.json\n";
        file_put_contents($assets . '/SHA256SUMS', $sums);
        $body = "TypeApp 物联中心 {$version}\n\n每个平台部署只需一个可执行程序及外置配置。TypePHP全量编译应用；PHP、PHPX、Swoole与非系统运行库静态链接，普通启动不释放运行库。\n\n"
            . "下载对应平台和数据库 profile 的程序，Unix系统赋予执行权限，准备外置配置后执行app:install；页面从程序安装到public。后续使用web:install --force更新托管页面。MySQL/PostgreSQL由外部服务提供，SQLite使用本地数据文件。默认告警、导出、队列和调度保留外部 Redis；DB_DRIVER 不匹配时程序拒绝启动。设备 MQTT 持久接入要求 pgsql profile 和相应同步后端。许可证由licenses命令读取。安装说明：https://iots.top/#/guide/deployment\n\n"
            . "源码：{$source}。下载后先核对SHA256SUMS，四平台×三数据库 profile 的验收身份见release-manifest.json。\n\n"
            . "重建 SDK、源码和许可证材料保存在 Actions Artifact，不属于部署下载项；部署只需对应 profile 的一个程序和外置配置。\n\n"
            . "<!-- typeapp-candidate source={$source} run={$run} attempt={$attempt} -->\n";
        $api->draft('zoujingli/typeapp', $version, $source, $body);
        foreach ([...array_column(array_merge(...array_map(static fn (array $record): array => Candidate::attachments($record, false), array_values($manifest['programs']))), 'file'), 'SHA256SUMS', 'release-manifest.json'] as $name) {
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
                if ($plan['prerelease'] && $name !== 'type-project') {
                    // 传递依赖的RC版本也受根项目稳定性约束，直接指定组件RC仍需允许其依赖。
                    $install = "composer config minimum-stability RC\ncomposer config prefer-stable true\n" . $install;
                }
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
        $records = $manifest['programs'] ?? $manifest['platforms'] ?? [];
        foreach ([...array_column(array_merge(...array_map(static fn (array $record): array => Candidate::attachments($record, false), array_values($records))), 'file'), 'SHA256SUMS', 'release-manifest.json'] as $name) {
            $api->asset('zoujingli/typeapp', $version, $work . '/assets/' . $name);
        }
        $release = $api->find('zoujingli/typeapp', $version) ?? throw new RuntimeException('缺少候选 Release');
        $expected = [...array_column($records, 'file'), 'SHA256SUMS', 'release-manifest.json'];
        $actual = array_column($release['assets'], 'name');
        sort($expected);
        sort($actual);
        if ($actual !== $expected) {
            throw new RuntimeException('公开 Release 只能包含 12 个主程序、SHA256SUMS 和 release-manifest.json');
        }
        Process::report($work . '/published.json', $api->publish('zoujingli/typeapp', $version) + ['source' => $source, 'children' => $receipt['items']]);
    } else {
        throw new InvalidArgumentException('用法：php tools/release.php <prepare|resolve|restore|candidate|packagist|verify-consumption|children|publish> <版本tag>');
    }
    echo '版本发布步骤完成：' . $operation . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, '版本发布失败：' . $error->getMessage() . "\n");
    exit(1);
}
