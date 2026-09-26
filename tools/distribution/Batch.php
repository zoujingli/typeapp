<?php

declare(strict_types=1);

namespace TypeApp\Distribution;

/** 批次只由固定 Git 输入决定；报告不能替代远端引用的实际状态。 */
final class Batch
{
    /**
     * 核对同一主仓 SHA、工作流及执行轮次的完整原生验收；单项手动重跑不能用于发布。
     * @throws \RuntimeException 缺少完整成功结果或任务列表不完整。
     */
    public static function nativeEvidence(string $root, string $source): string
    {
        if (!preg_match('/^[a-f0-9]{40}$/D', $source)) {
            throw new \InvalidArgumentException('原生验收需要固定完整 SHA');
        }
        $releaseRun = getenv('TYPE_RELEASE_EVIDENCE_RUN');
        if ($releaseRun !== false && $releaseRun !== '') {
            $attempt = getenv('TYPE_RELEASE_EVIDENCE_ATTEMPT');
            $version = getenv('TYPE_RELEASE_VERSION');
            if (!ctype_digit($releaseRun) || (int) $releaseRun < 1 || !is_string($attempt) || !ctype_digit($attempt) || (int) $attempt < 1
                || !is_string($version) || !preg_match('/^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-rc\.[1-9][0-9]*)?$/D', $version)) {
                throw new \RuntimeException('发布验收需要准确运行、轮次和版本');
            }
            $endpoint = 'repos/zoujingli/typeapp/actions/runs/' . $releaseRun . '/attempts/' . $attempt;
            $run = json_decode(Process::output(['gh', 'api', $endpoint], $root), true, 512, JSON_THROW_ON_ERROR);
            $jobs = json_decode(Process::output(['gh', 'api', $endpoint . '/jobs?per_page=100'], $root), true, 512, JSON_THROW_ON_ERROR);
            self::verifyReleaseEvidence($run, $jobs, $source, $version, (int) $releaseRun, (int) $attempt);
            return 'https://github.com/zoujingli/typeapp/actions/runs/' . $releaseRun . '/attempts/' . $attempt;
        }
        $runs = json_decode(Process::output(['gh', 'api', 'repos/zoujingli/typeapp/actions/workflows/native-command.yml/runs?head_sha=' . $source . '&status=success&per_page=100'], $root), true, 512, JSON_THROW_ON_ERROR);
        foreach ($runs['workflow_runs'] ?? [] as $run) {
            if (($run['head_sha'] ?? '') !== $source || ($run['status'] ?? '') !== 'completed'
                || ($run['conclusion'] ?? '') !== 'success' || !in_array($run['event'] ?? '', ['push', 'workflow_dispatch'], true)
                || ($run['head_branch'] ?? '') !== 'main' || ($run['head_repository']['full_name'] ?? '') !== 'zoujingli/typeapp'
                || ($run['path'] ?? '') !== '.github/workflows/native-command.yml'
                || !is_int($run['id'] ?? null) || $run['id'] < 1 || !is_int($run['run_attempt'] ?? null) || $run['run_attempt'] < 1) {
                continue;
            }
            $jobs = json_decode(Process::output(['gh', 'api', 'repos/zoujingli/typeapp/actions/runs/' . $run['id'] . '/attempts/' . $run['run_attempt'] . '/jobs?per_page=100'], $root), true, 512, JSON_THROW_ON_ERROR);
            $complete = false;
            $successful = is_array($jobs['jobs'] ?? null) && ($jobs['total_count'] ?? 0) === count($jobs['jobs']) && $jobs['total_count'] > 0;
            foreach ($jobs['jobs'] ?? [] as $job) {
                if (($job['head_sha'] ?? '') !== $source || ($job['status'] ?? '') !== 'completed' || ($job['conclusion'] ?? '') !== 'success') {
                    $successful = false;
                }
                $complete = $complete || ($job['name'] ?? '') === 'native-complete';
            }
            if ($successful && $complete) {
                return 'https://github.com/zoujingli/typeapp/actions/runs/' . $run['id'] . '/attempts/' . $run['run_attempt'];
            }
        }
        throw new \RuntimeException('固定提交尚无完整成功的主分支原生 CI');
    }

    /** 发布链允许分发任务继续运行，但固定轮次的四平台完整验收必须已全部成功。 */
    public static function verifyReleaseEvidence(array $run, array $jobs, string $source, string $version, int $id, int $attempt): void
    {
        if (($run['id'] ?? null) !== $id || ($run['run_attempt'] ?? null) !== $attempt
            || ($run['head_sha'] ?? '') !== $source || ($run['head_branch'] ?? '') !== $version
            || ($run['head_repository']['full_name'] ?? '') !== 'zoujingli/typeapp'
            || ($run['path'] ?? '') !== '.github/workflows/release.yml'
            || !in_array($run['event'] ?? '', ['push', 'workflow_dispatch'], true)
            || !is_array($jobs['jobs'] ?? null) || ($jobs['total_count'] ?? 0) !== count($jobs['jobs'])) {
            throw new \RuntimeException('发布原生验收的源码、标签、工作流或执行轮次不一致');
        }
        $required = ['release-native-complete', 'linux-x64 / native-complete', 'macos-arm64 / macos-complete',
            'linux-arm64 / linux-arm64-complete', 'windows-x64 / windows'];
        foreach (['foundation', 'http', 'drivers', 'queries', 'models', 'data', 'cache', 'queue', 'scheduler', 'consumers',
            'reliability', 'rollout', 'integration', 'tls', 'isolated-build', 'app', 'delivery', 'packaged-rollout', 'services'] as $suite) {
            $required[] = 'linux-x64 / Linux x64 原生验收 · ' . $suite;
        }
        foreach (['contracts', 'application', 'deployment', 'rollout', 'recovery', 'http', 'orm', 'reliable'] as $suite) {
            $required[] = 'macos-arm64 / macOS ARM64 · ' . $suite;
        }
        foreach (['contracts', 'orm', 'database', 'http', 'redis', 'tasks', 'application', 'recovery', 'rollout'] as $suite) {
            $required[] = 'linux-arm64 / Linux ARM64 · ' . $suite;
        }
        $seen = [];
        foreach ($jobs['jobs'] as $job) {
            $name = $job['name'] ?? '';
            if (!in_array($name, $required, true)) {
                continue;
            }
            if (isset($seen[$name]) || ($job['head_sha'] ?? '') !== $source || ($job['status'] ?? '') !== 'completed'
                || ($job['conclusion'] ?? '') !== 'success') {
                throw new \RuntimeException('发布原生验收任务未成功或重复：' . $name);
            }
            $seen[$name] = true;
        }
        if (array_diff($required, array_keys($seen)) !== []) {
            throw new \RuntimeException('发布缺少完整四平台原生验收任务');
        }
    }

    /** 从固定提交核对全部组件，计划身份不受工作区补写内容影响。 */
    public static function plan(string $root, string $source, string $mode, string $version, array $mapping): array
    {
        if (!preg_match('/^[a-f0-9]{40}$/D', $source) || !in_array($mode, ['branch', 'tag'], true)
            || ($mode === 'branch' ? $version !== '' : preg_match('/^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-rc\.[1-9][0-9]*)?$/D', $version) !== 1)) {
            throw new \InvalidArgumentException('批次需要固定完整 SHA；分支同步不带版本，标签分发使用明确 SemVer');
        }
        if (($mapping['protocol'] ?? null) !== 1 || ($mapping['source-repository'] ?? '') !== 'zoujingli/typeapp'
            || !is_array($mapping['packages'] ?? null) || $mapping['packages'] === []) {
            throw new \RuntimeException('分发映射无效');
        }
        $packages = $mapping['packages'];
        ksort($packages);
        $items = [];
        foreach (array_keys($packages) as $name) {
            $items[$name] = self::package($root, $source, $name, $packages);
        }
        $plan = ['protocol' => 1, 'source' => $source, 'mode' => $mode, 'version' => $version,
            'toolchain-git-blob' => Process::output(['git', 'rev-parse', $source . ':toolchain.lock.json'], $root),
            'git-version' => Process::output(['git', '--version'], $root), 'items' => $items];
        $plan['id'] = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return $plan;
    }

    /**
     * 单组件与批次共用固定提交的内容检查，全部通过后才生成拆分提交。
     *
     * @param array<string, array<string, string>> $packages 全部组件映射，用于核对第一方依赖。
     * @return array{package: string, repository: string, branch: string, split: string, tree: string, write-secret: string}
     * @throws \InvalidArgumentException 未指定完整提交 SHA。
     * @throws \RuntimeException 映射、分发材料、依赖或拆分内容不符合公开发布约定。
     */
    public static function package(string $root, string $source, string $name, array $packages): array
    {
        if (!preg_match('/^[a-f0-9]{40}$/D', $source)) {
            throw new \InvalidArgumentException('组件分发需要固定完整 SHA');
        }
        $package = $packages[$name] ?? throw new \RuntimeException('插件未登记，不允许分发');
        if (!preg_match('/^type-[a-z0-9]+(?:-[a-z0-9]+)*$/D', $name)
            || ($package['prefix'] ?? '') !== 'plugin/' . $name || ($package['repository'] ?? '') !== 'zoujingli/' . $name
            || ($package['composer-name'] ?? '') !== 'zoujingli/' . $name || ($package['branch'] ?? '') !== 'main'
            || ($package['visibility'] ?? '') !== 'public') {
            throw new \RuntimeException('插件映射超出受控范围：' . $name);
        }
        $composer = json_decode(Process::output(['git', 'show', $source . ':' . $package['prefix'] . '/composer.json'], $root), true, 512, JSON_THROW_ON_ERROR);
        if (($composer['name'] ?? '') !== $package['composer-name'] || ($composer['license'] ?? '') !== 'Apache-2.0' || ($composer['type'] ?? '') !== 'library') {
            throw new \RuntimeException('插件包声明不完整：' . $name);
        }
        $paths = explode("\n", Process::output(['git', 'ls-tree', '-r', '--name-only', $source . ':' . $package['prefix']], $root));
        $entries = explode("\n", Process::output(['git', 'ls-tree', '-r', $source . ':' . $package['prefix']], $root));
        foreach ($entries as $entry) {
            if (!str_starts_with($entry, '100644 ') && !str_starts_with($entry, '100755 ')) {
                throw new \RuntimeException('分发内容只能是普通受控文件');
            }
        }
        foreach ($paths as $path) {
            $allowedManual = $name === 'type-build' && in_array($path, ['docs/operations.md', 'NOTICE'], true);
            if ((!$allowedManual && !preg_match('~^(?:\.gitattributes|composer\.json|README\.md|LICENSE(?:\.md)?|NOTICE|(?:src|bin|stubs|resources)/[A-Za-z0-9_./@-]+)$~D', $path))
                || preg_match('~(?:^|/)(?:\.env(?:\.[^/]+)?|auth\.json|id_rsa|id_ed25519|vendor|build|\.git)(?:/|$)~', $path)) {
                throw new \RuntimeException('分发包包含未允许内容：' . $name . '/' . $path);
            }
        }
        foreach (['README.md', 'LICENSE', 'NOTICE'] as $required) {
            if (!in_array($required, $paths, true)) {
                throw new \RuntimeException('缺少分发文件：' . $name . '/' . $required);
            }
        }
        if (Process::output(['git', 'rev-parse', $source . ':' . $package['prefix'] . '/LICENSE'], $root)
            !== Process::output(['git', 'rev-parse', $source . ':LICENSE'], $root)) {
            throw new \RuntimeException('分发 LICENSE 必须与同一提交的完整许可文本一致：' . $name);
        }
        foreach (['README.md', 'NOTICE'] as $description) {
            if (Process::output(['git', 'show', $source . ':' . $package['prefix'] . '/' . $description], $root) === '') {
                throw new \RuntimeException('分发说明不能为空：' . $name . '/' . $description);
            }
        }
        foreach (array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []) as $dependency => $constraint) {
            if (str_starts_with($dependency, 'zoujingli/type-') && !isset($packages[substr($dependency, strlen('zoujingli/'))])) {
                throw new \RuntimeException('插件依赖尚未映射：' . $name . ' → ' . $dependency);
            }
        }
        $split = Process::output(['git', 'subtree', 'split', '--prefix=' . $package['prefix'], '--ignore-joins', $source], $root);
        if (!preg_match('/^[a-f0-9]{40}$/D', $split)) {
            throw new \RuntimeException('拆分未得到固定提交');
        }
        $tree = Process::output(['git', 'rev-parse', $source . ':' . $package['prefix']], $root);
        if (Process::output(['git', 'rev-parse', $split . '^{tree}'], $root) !== $tree) {
            throw new \RuntimeException('拆分内容与主仓不一致');
        }
        return ['package' => $composer['name'], 'repository' => $package['repository'], 'branch' => 'main', 'split' => $split, 'tree' => $tree,
            'write-secret' => strtoupper(str_replace('-', '_', $name)) . '_DEPLOY_KEY'];
    }

    /**
     * 下游从完整 Git 历史重建计划，并重新核对每项回执，完成标记不能替代校验。
     *
     * @param array<string, mixed> $report 上游汇总报告。
     * @param array<string, mixed> $mapping 固定提交中的组件映射。
     * @throws \InvalidArgumentException 报告中的批次模式或版本无效。
     * @throws \RuntimeException 报告不完整、身份不一致或缺少完整 Git 历史。
     */
    public static function verifyReport(string $root, string $source, array $report, array $mapping): void
    {
        if (($report['protocol'] ?? null) !== 1 || ($report['complete'] ?? null) !== true
            || ($report['source'] ?? null) !== $source || ($report['atomic-across-repositories'] ?? null) !== false
            || !is_string($report['mode'] ?? null) || !is_string($report['version'] ?? null)
            || !is_array($report['items'] ?? null)) {
            throw new \RuntimeException('批次报告必须完整且对应当前固定源码');
        }
        if (Process::output(['git', 'rev-parse', '--is-shallow-repository'], $root) !== 'false') {
            throw new \RuntimeException('核对批次报告需要完整 Git 历史');
        }
        $plan = self::plan($root, $source, $report['mode'], $report['version'], $mapping);
        if (($report['id'] ?? null) !== $plan['id']) {
            throw new \RuntimeException('批次报告身份与固定源码及当前工具不一致');
        }
        $names = array_keys($report['items']);
        sort($names);
        if ($names !== array_keys($plan['items']) || !self::collect($plan, $report['items'])['complete']) {
            throw new \RuntimeException('批次报告未包含全部准确且成功的分发回执');
        }
    }

    /**
     * 逐项匹配计划身份和回执；任一缺失、错配或失败都会使整个批次 incomplete。
     * @param array<string, mixed> $plan 固定源码生成的计划。
     * @param array<string, array<string, mixed>> $reports 组件名到实际分发回执。
     * @return array<string, mixed> 汇总结果；跨仓发布本身不具有原子性。
     */
    public static function collect(array $plan, array $reports): array
    {
        $items = [];
        $complete = true;
        foreach ($plan['items'] as $name => $item) {
            $report = $reports[$name] ?? null;
            $reference = $plan['mode'] === 'branch' ? 'refs/heads/' . $item['branch'] : 'refs/tags/' . $plan['version'];
            if (!is_array($report) || ($report['batch'] ?? '') !== $plan['id'] || ($report['split'] ?? '') !== $item['split']
                || ($report['repository'] ?? '') !== $item['repository'] || ($report['source'] ?? '') !== $plan['source']
                || ($report['package'] ?? '') !== $item['package'] || ($report['mode'] ?? '') !== $plan['mode']
                || ($report['version'] ?? null) !== $plan['version']
                || (in_array($report['status'] ?? '', ['published', 'already-current'], true) && ($report['reference'] ?? '') !== $reference)) {
                $report = ['status' => 'missing', 'error' => '尚无本批次的有效分发结果'];
                $complete = false;
            } elseif (!in_array($report['status'] ?? '', ['published', 'already-current'], true)) {
                $complete = false;
            }
            $items[$name] = $report;
        }
        return ['protocol' => 1, 'id' => $plan['id'], 'source' => $plan['source'], 'mode' => $plan['mode'], 'version' => $plan['version'],
            'complete' => $complete, 'atomic-across-repositories' => false, 'items' => $items];
    }
}
