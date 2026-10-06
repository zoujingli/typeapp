<?php

declare(strict_types=1);

namespace TypeApp\Release;

use Type\Build\ArtifactManifest;
use Type\Build\BuildIdentity;

/** 教程候选与公开消费各自核对同一封存程序；历史基线不能替代新源码验收。 */
final class TutorialEvidence
{
    /**
     * 在候选准入或公开前回读三 profile 的文件、身份与隔离行为证据。
     * @param array<string,array<string,mixed>> $items 同一固定源码的组件与模板拆分计划。
     * @return array<string,mixed> 已核对报告，不触发构建或发布。
     */
    public static function verify(string $file, string $source, string $channel, ?string $version, array $items, ?string $onlyProfile = null): array
    {
        $report = self::read($file);
        if (!in_array($channel, ['fixed-candidate', 'packagist-tag'], true)
            || ($onlyProfile !== null && !in_array($onlyProfile, ['mysql', 'pgsql', 'sqlite'], true))
            || ($report['protocol'] ?? null) !== 1 || ($report['kind'] ?? '') !== ($onlyProfile === null ? 'tutorial-delivery' : 'tutorial-profile-delivery')
            || ($report['status'] ?? '') !== 'passed' || ($report['source'] ?? '') !== $source
            || ($report['temporary-inputs-removed'] ?? false) !== true
            || ($report['channel'] ?? '') !== $channel || ($report['version'] ?? null) !== $version
            || ($report['template-split'] ?? '') !== ($items['type-project']['split'] ?? null)) {
            throw new \RuntimeException('教程验收来源、版本、模板或状态不匹配');
        }
        $profiles = $report['profiles'] ?? [];
        $names = array_keys($profiles);
        sort($names);
        if ($names !== ($onlyProfile === null ? ['mysql', 'pgsql', 'sqlite'] : [$onlyProfile])) {
            throw new \RuntimeException('教程验收缺少三个数据库profile');
        }
        $base = dirname($file);
        $tutorial = null;
        $expectedTutorial = null;
        foreach ($profiles as $driver => $files) {
            $paths = [];
            foreach (['program', 'consumer', 'deployment', 'deployment-log', 'lock', 'build', 'tutorial'] as $kind) {
                $paths[$kind] = self::file($base, $files[$kind] ?? []);
            }
            $expectedTutorial ??= self::sourceFiles(dirname(__DIR__, 2), $source, 'examples/catalog');
            $receipt = self::read($paths['consumer']);
            $deployment = self::read($paths['deployment']);
            $platform = match ($deployment['platform'] ?? '') {
                'Darwin' => ($deployment['architecture'] ?? '') === 'arm64' ? 'macos-arm64' : '',
                'Windows' => in_array(strtolower($deployment['architecture'] ?? ''), ['amd64', 'x86_64'], true) ? 'windows-x64' : '',
                'Linux' => match ($deployment['architecture'] ?? '') {
                    'x86_64' => 'linux-x64', 'aarch64', 'arm64' => 'linux-arm64', default => ''
                },
                default => ''
            };
            if ($platform === '' || ($report['platform'] ?? '') !== $platform) {
                throw new \RuntimeException('教程平台记录与真实部署不一致');
            }
            if (($deployment['initialization']['log-sha256'] ?? '') !== hash_file('sha256', $paths['deployment-log'])) {
                throw new \RuntimeException('隔离教程公开断言日志与部署回执不一致');
            }
            $lock = self::read($paths['lock']);
            $build = self::read($paths['build']);
            $declaration = self::read($paths['tutorial']);
            $identity = (new ArtifactManifest())->read($paths['program'], $receipt['build-id'] ?? '', $receipt['artifact-sha256'] ?? '');
            if (($identity['runtime']['os'] ?? '') !== ($deployment['platform'] ?? null)
                || strtolower($identity['runtime']['architecture'] ?? '') !== strtolower($deployment['architecture'] ?? '')) {
                throw new \RuntimeException('教程程序内嵌目标平台与部署回执不一致');
            }
            self::profile($receipt, $deployment, $identity, $lock, $build, $driver, $channel, $version, $items);
            if (($declaration['lock-sha256'] ?? '') !== hash_file('sha256', $paths['lock'])
                || !is_array($declaration['tutorial-sources'] ?? null) || $declaration['tutorial-sources'] === []) {
                throw new \RuntimeException('教程声明与安装锁身份不一致');
            }
            $tutorial ??= $declaration['tutorial-sources'];
            if ($tutorial !== $declaration['tutorial-sources'] || $tutorial !== $expectedTutorial) {
                throw new \RuntimeException('三个profile没有消费同一套教程源码');
            }
            self::sources($build, $declaration, $paths['tutorial'], $lock, $items);
            foreach (['composer-lock-sha256' => 'lock'] as $key => $kind) {
                if (($identity[$key] ?? '') !== hash_file('sha256', $paths[$kind])) {
                    throw new \RuntimeException('封存程序与安装锁不一致');
                }
            }
        }
        return $report;
    }

    /** 发布必须回读同轮四平台十二个原始profile报告，不能以单平台结果替代矩阵。 */
    public static function matrix(string $file, string $source, string $channel, ?string $version, array $items, string $run, string $attempt): array
    {
        $report = self::read($file);
        if (($report['protocol'] ?? null) !== 1 || ($report['kind'] ?? '') !== 'tutorial-delivery-matrix'
            || ($report['status'] ?? '') !== 'passed' || ($report['source'] ?? '') !== $source || ($report['channel'] ?? '') !== $channel
            || ($report['version'] ?? null) !== $version || ($report['run'] ?? '') !== $run || ($report['attempt'] ?? '') !== $attempt
            || !preg_match('/^[1-9][0-9]*$/D', $run) || !preg_match('/^[1-9][0-9]*$/D', $attempt)) {
            throw new \RuntimeException('教程矩阵来源、版本或执行轮次不一致');
        }
        $expected = [];
        foreach (['linux-x64', 'linux-arm64', 'macos-arm64', 'windows-x64'] as $platform) {
            foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
                $expected[] = $platform . '/' . $driver;
            }
        }
        $actual = array_keys($report['reports'] ?? []);
        sort($expected);
        sort($actual);
        if ($actual !== $expected) {
            throw new \RuntimeException('教程矩阵缺少四平台十二个profile');
        }
        $paths = [];
        foreach ($report['reports'] as $key => $entry) {
            [$platform, $driver] = explode('/', $key);
            $paths[$key] = self::file(dirname($file), $entry);
            $child = self::read($paths[$key]);
            if (($child['platform'] ?? '') !== $platform || ($child['run'] ?? '') !== $run || ($child['attempt'] ?? '') !== $attempt) {
                throw new \RuntimeException('教程profile不属于同一平台或执行轮次：' . $key);
            }
        }
        foreach ($paths as $key => $path) {
            [, $driver] = explode('/', $key);
            self::verify($path, $source, $channel, $version, $items, $driver);
        }
        return $report;
    }

    /** 核对公开边界记录的真实结果，供准入工具及负向契约测试共用。 */
    public static function profile(array $receipt, array $deployment, array $identity, array $lock, array $build, string $driver, string $channel, ?string $version, array $items): void
    {
        if (($receipt['status'] ?? '') !== 'passed' || ($receipt['driver'] ?? '') !== $driver || ($receipt['tutorial'] ?? false) !== true
            || ($receipt['native'] ?? false) !== true || ($receipt['package-verified'] ?? false) !== true
            || ($receipt['delivery'] ?? '') !== 'single-executable' || ($receipt['profile'] ?? '') !== $driver
            || ($receipt['consumption-mode'] ?? '') !== $channel || ($receipt['version'] ?? null) !== $version
            || ($identity['runtime-linkage'] ?? '') !== 'static' || ($identity['profile']['database'] ?? '') !== $driver
            || ($identity['native-libraries'] ?? null) !== [] || ($identity['extension-modules'] ?? null) !== []
            || ($identity['resources'] ?? null) !== [] || ($build['build-id'] ?? '') !== ($identity['build-id'] ?? null)
            || ($build['manifest'] ?? null) !== $identity
            || ($receipt['build-id'] ?? '') !== ($identity['build-id'] ?? null)
            || ($build['sha256'] ?? '') !== ($receipt['artifact-sha256'] ?? null)
            || ($deployment['artifact-sha256'] ?? '') !== ($receipt['artifact-sha256'] ?? null)
            || ($deployment['build-id'] ?? '') !== ($identity['build-id'] ?? null)
            || ($deployment['status'] ?? '') !== 'passed' || ($deployment['tutorial'] ?? false) !== true
            || ($deployment['driver'] ?? '') !== $driver || ($deployment['initialization']['tutorial-public-assertions'] ?? false) !== true) {
            throw new \RuntimeException('教程缺少同一封存单程序的真实业务验收：' . $driver);
        }
        foreach (['source-and-sdk-read-denied', 'ordinary-start-writes-no-files', 'runtime-profile-enforced',
            'single-executable-only', 'readonly-program-directory', 'different-cwd'] as $check) {
            if (($deployment[$check] ?? false) !== true) {
                throw new \RuntimeException('教程隔离部署检查缺失：' . $check);
            }
        }
        foreach (['declaration-generation', 'toolchain-lock-sha256', 'composer-lock-sha256', 'resource-generation'] as $key) {
            if (!is_string($identity[$key] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $identity[$key]) !== 1
                || ($build['manifest'][$key] ?? null) !== $identity[$key]) {
                throw new \RuntimeException('教程构建代次或依赖身份缺失：' . $key);
            }
        }
        BuildIdentity::assertValid($build['identity'] ?? []);
        $description = $build['identity']['description'];
        $facts = $description['facts'] ?? [];
        $workspace = $facts['workspace'] ?? '';
        if ($build['identity']['id'] !== $identity['build-id'] || !is_string($workspace) || $workspace === ''
            || ($facts['native']['static-runtime'] ?? null) !== BuildIdentity::canonical($identity['static-runtime'] ?? null)) {
            throw new \RuntimeException('教程构建输入没有绑定封存程序');
        }
        foreach (['production-packages', 'profile', 'runtime-extensions', 'static-archives', 'declaration-generation', 'resource-generation'] as $key) {
            if (BuildIdentity::digest($facts[$key] ?? null) !== BuildIdentity::digest($identity[$key] ?? null)) {
                throw new \RuntimeException('教程构建事实与封存程序不一致：' . $key);
            }
        }
        foreach (['composer.lock' => 'composer-lock-sha256', 'toolchain.lock.json' => 'toolchain-lock-sha256'] as $name => $key) {
            if (($description['inputs']['locks'][$workspace . '/' . $name]['sha256'] ?? '') !== $identity[$key]) {
                throw new \RuntimeException('教程锁文件没有进入原程序构建身份：' . $name);
            }
        }
        $originals = $description['inputs']['original-sources'] ?? [];
        $declared = $build['original-sources'] ?? [];
        if (!is_array($originals) || $originals === [] || !is_array($declared) || $declared !== array_keys($originals)) {
            throw new \RuntimeException('教程原始生产源码与构建身份不一致');
        }
        $hashes = array_column($originals, 'sha256');
        if (count($hashes) !== count($declared)
            || BuildIdentity::digest(['sources' => $hashes, 'generation' => $build['declaration-metadata'] ?? []]) !== $identity['declaration-generation']) {
            throw new \RuntimeException('教程声明代次不能由原始构建输入回算');
        }
        if (($identity['static-runtime'] ?? []) === [] || ($identity['static-archives'] ?? []) === []
            || ($identity['runtime-extensions'] ?? []) === [] || ($build['source-sets'] ?? []) === []) {
            throw new \RuntimeException('教程缺少静态库、扩展或生产源码审计');
        }
        foreach ($build['source-sets'] as $set) {
            if (($set['exclusions'] ?? null) !== [] || !is_array($set['sources'] ?? null) || $set['sources'] === []) {
                throw new \RuntimeException('教程生产源码不能带排除项');
            }
            foreach ($set['sources'] as $path) {
                if (!is_string($path) || !isset($originals[$path])) {
                    throw new \RuntimeException('教程生产依赖源码没有进入构建身份');
                }
            }
        }
        $production = array_column($lock['packages'] ?? [], 'name');
        $compiled = array_keys($identity['production-packages'] ?? []);
        $audited = array_keys($build['source-sets']);
        sort($production);
        sort($compiled);
        sort($audited);
        if ($production === [] || $production !== $compiled || $production !== $audited) {
            throw new \RuntimeException('教程没有全量编译已安装生产依赖');
        }
        foreach ([...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])] as $package) {
            if (!str_starts_with($package['name'], 'zoujingli/type-')) {
                continue;
            }
            $name = substr($package['name'], strlen('zoujingli/'));
            if (!isset($items[$name]['split']) || ($receipt['verified-splits'][$name] ?? '') !== $items[$name]['split']) {
                throw new \RuntimeException('教程安装组件未匹配固定拆分来源：' . $name);
            }
            if ($channel === 'packagist-tag' && (($package['source']['reference'] ?? '') !== $items[$name]['split']
                    || ltrim($package['version'], 'v') !== ltrim((string) $version, 'v') || ($package['dist']['type'] ?? '') === 'path')) {
                throw new \RuntimeException('教程公开消费版本或默认Packagist来源不一致：' . $name);
            }
            if ($channel === 'fixed-candidate' && ($package['dist']['type'] ?? '') !== 'path') {
                throw new \RuntimeException('教程候选没有使用已核对的固定组件快照：' . $name);
            }
        }
    }

    /** 将已移除安装目录的原始输入回执绑定到封存程序，再对照固定组件Git字节。 */
    private static function sources(array $build, array $declaration, string $file, array $lock, array $items): void
    {
        $description = $build['identity']['description'];
        $workspace = $description['facts']['workspace'];
        $inputs = $description['inputs'];
        if (($inputs['native-inputs'][$workspace . '/catalog-candidate.json']['sha256'] ?? '') !== hash_file('sha256', $file)
            || ($inputs['declarations'][$workspace . '/composer.json']['sha256'] ?? '') !== ($declaration['composer-sha256'] ?? null)) {
            throw new \RuntimeException('教程输入声明没有进入原程序构建身份');
        }
        $installed = $declaration['installed-production-inputs'] ?? [];
        if (!is_array($installed) || $installed === []) {
            throw new \RuntimeException('教程缺少安装后生产源码字节');
        }
        $production = $inputs['original-sources'] + ($inputs['declarations'] ?? []);
        foreach ($installed as $relative => $sha) {
            if (!is_string($relative) || str_starts_with($relative, '/') || str_contains($relative, '\\')
                || in_array('..', explode('/', $relative), true)
                || ($production[$workspace . '/' . $relative]['sha256'] ?? null) !== $sha) {
                throw new \RuntimeException('教程安装源码与原程序构建输入不一致');
            }
        }
        foreach ($inputs['original-sources'] as $path => $entry) {
            if (!str_starts_with($path, $workspace . '/')
                || ($installed[substr($path, strlen($workspace) + 1)] ?? null) !== $entry['sha256']) {
                throw new \RuntimeException('教程回执遗漏原程序生产源码');
            }
        }
        $root = dirname(__DIR__, 2);
        $template = self::sourceFiles($root, $items['type-project']['split'], '');
        $templateMetadata = self::metadata($root, $items['type-project']['split']);
        $templateInputs = self::selectedFiles($template, $templateMetadata['extra']['type-template']['paths'] ?? []);
        if ($templateInputs === [] || ($declaration['template-source-sha256'] ?? '') !== BuildIdentity::digest($templateInputs)) {
            throw new \RuntimeException('教程模板输入不属于固定拆分来源');
        }
        foreach ([...$lock['packages'], ...($lock['packages-dev'] ?? [])] as $package) {
            if (!str_starts_with($package['name'], 'zoujingli/type-')) {
                continue;
            }
            $name = substr($package['name'], strlen('zoujingli/'));
            $split = $items[$name]['split'];
            $fixed = self::sourceFiles($root, $split, '');
            $prefix = $workspace . '/vendor/' . $package['name'] . '/';
            $tooling = [];
            foreach ($inputs['tooling'] ?? [] as $path => $entry) {
                if (str_starts_with($path, $prefix)) {
                    $tooling[substr($path, strlen($prefix))] = $entry['sha256'];
                }
            }
            ksort($tooling);
            if (($tooling !== [] || $name === 'type-build') && $tooling !== $fixed) {
                throw new \RuntimeException('教程编译工具不属于固定拆分来源：' . $name);
            }
            if (!isset($build['source-sets'][$package['name']])) {
                continue;
            }
            if (($inputs['declarations'][$prefix . 'composer.json']['sha256'] ?? '') !== ($fixed['composer.json'] ?? null)) {
                throw new \RuntimeException('教程组件声明不属于固定拆分来源：' . $name);
            }
            $metadata = self::metadata($root, $split);
            $expected = [];
            foreach (self::selectedFiles($fixed, $metadata['extra']['type']['sources'] ?? [], ['php', 'c', 'cc', 'cpp', 'cxx']) as $relative => $sha) {
                $expected[$prefix . $relative] = $sha;
            }
            $actual = [];
            foreach ($build['source-sets'][$package['name']]['sources'] as $path) {
                $actual[$path] = $inputs['original-sources'][$path]['sha256'];
            }
            ksort($expected);
            ksort($actual);
            if ($expected === [] || $actual !== $expected) {
                throw new \RuntimeException('教程编译组件源码不属于固定拆分来源：' . $name);
            }
        }
    }

    /** 只使用固定Git中的声明选择文件，不能由验收回执自行缩小生产范围。 */
    private static function selectedFiles(array $files, array $paths, ?array $extensions = null): array
    {
        $selected = [];
        foreach ($paths as $path) {
            foreach ($files as $relative => $sha) {
                if (($relative === $path || str_starts_with($relative, rtrim($path, '/') . '/'))
                    && ($extensions === null || in_array(pathinfo($relative, PATHINFO_EXTENSION), $extensions, true))) {
                    $selected[$relative] = $sha;
                }
            }
        }
        ksort($selected);
        return $selected;
    }

    private static function metadata(string $root, string $split): array
    {
        [$status, $bytes] = \TypeApp\Distribution\Process::run(['git', 'show', $split . ':composer.json'], $root, false);
        if ($status !== 0) {
            throw new \RuntimeException('无法读取固定组件或模板声明');
        }
        return json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    }

    /** 精确验证固定快照文件集合；不允许链接、遗漏或额外PHP实现混入。 */
    public static function snapshot(string $directory, array $expected): void
    {
        $actual = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $entry) {
            if (!$entry->isFile() || $entry->isLink()) {
                throw new \RuntimeException('固定快照只允许普通文件');
            }
            $actual[str_replace('\\', '/', substr($entry->getPathname(), strlen($directory) + 1))] = hash_file('sha256', $entry->getPathname());
        }
        ksort($actual);
        ksort($expected);
        if ($expected === [] || $actual !== $expected) {
            throw new \RuntimeException('安装内容偏离固定组件或模板快照');
        }
    }

    /** 从固定Git树计算教程输入，报告不能自行把旧教程标成当前源码。 */
    private static function sourceFiles(string $root, string $source, string $prefix): array
    {
        static $snapshots = [];
        $key = $root . ':' . $source . ':' . $prefix;
        if (isset($snapshots[$key])) {
            return $snapshots[$key];
        }
        $files = [];
        [$status, $tree] = \TypeApp\Distribution\Process::run(['git', 'ls-tree', '-r', '-z', $source . ':' . $prefix], $root, false);
        if ($status !== 0) {
            throw new \RuntimeException('无法读取固定教程Git树');
        }
        foreach (explode("\0", rtrim($tree, "\0")) as $entry) {
            if (!preg_match('/^100(?:644|755) blob [a-f0-9]{40}\t(.+)$/sD', $entry, $match)) {
                throw new \RuntimeException('固定教程Git树包含非普通文件');
            }
            [$status, $bytes] = \TypeApp\Distribution\Process::run(['git', 'show', $source . ':' . ($prefix === '' ? '' : $prefix . '/') . $match[1]], $root, false);
            if ($status !== 0) {
                throw new \RuntimeException('无法读取固定教程Git字节');
            }
            $files[$match[1]] = hash('sha256', $bytes);
        }
        ksort($files);
        return $snapshots[$key] = $files;
    }

    /** @return array<string,mixed> 只读取存在的普通JSON证据文件。 */
    private static function read(string $file): array
    {
        if (!is_file($file) || is_link($file)) {
            throw new \RuntimeException('缺少教程验收证据：' . basename($file));
        }
        return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    /** 将证据限定在报告目录内，并回读原始字节摘要。 */
    private static function file(string $base, array $entry): string
    {
        $path = $entry['file'] ?? '';
        if (!is_string($path) || !preg_match('~^[a-zA-Z0-9_./-]+$~D', $path) || str_starts_with($path, '/')
            || in_array('..', explode('/', $path), true) || is_link($base . '/' . $path)
            || !is_file($base . '/' . $path) || str_replace('\\', '/', (string) realpath($base . '/' . $path)) !== str_replace('\\', '/', (string) realpath($base)) . '/' . $path
            || ($entry['sha256'] ?? '') !== hash_file('sha256', $base . '/' . $path)) {
            throw new \RuntimeException('教程验收文件缺失、越界或摘要冲突');
        }
        return $base . '/' . $path;
    }
}
