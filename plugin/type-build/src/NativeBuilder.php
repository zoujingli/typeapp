<?php

declare(strict_types=1);

namespace Type\Build;

use Composer\InstalledVersions;
use RuntimeException;

/** 将显式声明的生产源码交给 TypePHP；本类只在构建环境运行。 */
final class NativeBuilder
{
    public function build(string $configuration): array
    {
        $configuration = BuildPlatform::resolve($configuration);
        $settings = $this->json($configuration);
        $settings['output'] = (new BuildPlatform())->output($this->string($settings, 'output'));
        $output = $this->destination($this->projectRoot($configuration, $settings), $this->string($settings, 'output'));
        $this->directory(dirname($output));
        return BuildLock::run($output . '.lock', fn (): array => $this->buildLocked($configuration, null));
    }

    public function stage(string $configuration, string $destination): array
    {
        $configuration = BuildPlatform::resolve($configuration);
        $settings = $this->json($configuration);
        $settings['output'] = (new BuildPlatform())->output($this->string($settings, 'output'));
        $output = $this->destination($this->projectRoot($configuration, $settings), $this->string($settings, 'output'));
        $this->directory(dirname($output));
        return BuildLock::run($output . '.lock', fn (): array => $this->buildLocked($configuration, $destination));
    }

    private function buildLocked(string $configuration, ?string $stage): array
    {
        $configuration = BuildPlatform::resolve($configuration);
        $settings = $this->json($configuration);
        $settings['output'] = (new BuildPlatform())->output($this->string($settings, 'output'));
        $root = $this->projectRoot($configuration, $settings);
        $composer = $this->json($root . '/composer.json');
        $this->json($root . '/composer.lock');
        $toolchain = $this->json($root . '/toolchain.lock.json');
        if (PHP_VERSION !== ($toolchain['php'] ?? null) || (bool) PHP_ZTS !== ($toolchain['zts'] ?? null)) {
            throw new RuntimeException('当前 PHP 与应用锁定的工具链不一致');
        }
        foreach (['typephp', 'phpx'] as $tool) {
            $packageName = 'swoole/' . $tool;
            if (ltrim((string) InstalledVersions::getPrettyVersion($packageName), 'v') !== ($toolchain[$tool]['version'] ?? null)
                || InstalledVersions::getReference($packageName) !== ($toolchain[$tool]['reference'] ?? null)) {
                throw new RuntimeException('编译工具身份与应用锁定不一致：' . $packageName);
            }
        }
        $assembled = array_key_exists('application', $settings);
        if ($assembled && (!is_array($settings['application']) || array_key_exists('entry', $settings))) {
            throw new RuntimeException('application 装配必须是对象，且不能同时指定手写 entry');
        }
        $output = $this->destination($root, $this->string($settings, 'output'));
        $reportFile = $this->destination($root, $this->string($settings, 'output') . '.build.json');
        $buildDirectory = $this->destination($root, $this->string($settings, 'build-directory'));
        $name = $this->string($settings, 'name');
        if (!preg_match('/^[a-z][a-z0-9-]*$/D', $name)) {
            throw new RuntimeException('应用名称只能包含小写字母、数字和短横线');
        }

        $vendorSetting = $composer['config']['vendor-dir'] ?? 'vendor';
        if (!is_string($vendorSetting) || $vendorSetting === '') {
            throw new RuntimeException('Composer 依赖目录必须是非空路径');
        }
        $vendorDirectory = BuildPlatform::resolve((new BuildPlatform())->absolute($vendorSetting) ? $vendorSetting : $root . '/' . $vendorSetting);
        $installed = $this->json($vendorDirectory . '/composer/installed.json');
        $packages = [];
        foreach ($installed['packages'] ?? [] as $package) {
            $packages[$package['name']] = $package;
        }
        $sources = $assembled ? [] : [$this->source($root, $this->string($settings, 'entry'))];
        $applicationSources = $settings['sources'] ?? [];
        if (!is_array($applicationSources) || !array_is_list($applicationSources)) {
            throw new RuntimeException('应用 sources 必须是路径列表');
        }
        foreach ($applicationSources as $source) {
            if (!is_string($source)) {
                throw new RuntimeException('应用源码路径必须是字符串');
            }
            $sources[] = $this->source($root, $source);
        }
        $modules = [];
        $imports = $settings['imports'] ?? [];
        $projectImports = $composer['extra']['type']['imports'] ?? [];
        if (!is_array($imports) || !is_array($projectImports)) {
            throw new RuntimeException('imports 必须是按包名登记的适配映射');
        }
        $imports += $projectImports;
        $sourceSets = [];
        $packageRoots = [];
        $resources = [];
        $included = [];
        $noticePackages = [$composer['name'] => ['root' => $root, 'version' => $settings['version'] ?? '0.0.0-dev',
            'license' => $composer['license'] ?? null, 'kind' => 'application']];
        $selected = $this->productionPackages($composer, $packages);
        $importOrigins = array_fill_keys(array_keys($imports), 'application-import');
        foreach ($selected as $owner => $selectedPackage) {
            $declaredImports = $selectedPackage['extra']['type']['imports'] ?? [];
            if (!is_array($declaredImports)) {
                throw new RuntimeException('依赖适配声明必须是映射：' . $owner);
            }
            foreach ($declaredImports as $importName => $adapter) {
                if (($importOrigins[$importName] ?? '') === 'application-import') {
                    continue;
                }
                if (isset($imports[$importName]) && $imports[$importName] != $adapter) {
                    throw new RuntimeException('多个生产包提供冲突的依赖适配：' . $importName);
                }
                $imports[$importName] = $adapter;
                $importOrigins[$importName] = 'package-import:' . $owner;
            }
        }
        foreach ($selected as $packageName => $package) {
            $metadata = $package['extra']['type'] ?? [];
            $origin = 'package';
            if ($metadata === [] && isset($imports[$packageName])) {
                $metadata = $imports[$packageName];
                if (!is_array($metadata) || ($metadata['version'] ?? null) !== ltrim($package['version'], 'v')) {
                    throw new RuntimeException('第三方适配版本与已安装包不一致：' . $packageName);
                }
                $origin = $importOrigins[$packageName];
            }
            $packageRoot = BuildPlatform::resolve($vendorDirectory . '/composer/' . $this->string($package, 'install-path'));
            $packageRoots[] = $packageRoot;
            $noticePackages[$packageName] = ['root' => $packageRoot, 'version' => $package['version'],
                'license' => $package['license'] ?? null, 'kind' => 'composer'];
            $sourceSet = (new SourceSet())->describe($packageRoot, $package, $metadata);
            array_push($sources, ...$sourceSet['sources']);
            foreach ($sourceSet['resources'] as $resource) {
                if (isset($resources[$resource['target']])) {
                    throw new RuntimeException('资源目标冲突：' . $resource['target']);
                }
                $resources[$resource['target']] = $resource;
            }
            $sourceSets[$packageName] = ['origin' => $origin] + $sourceSet;
            $included[$packageName] = $package['version'];
            $modules[$packageName] = $metadata['module'] ?? [];
        }

        $composerResources = $composer['extra']['type']['resources'] ?? [];
        $applicationResources = $settings['resources'] ?? [];
        if (!is_array($composerResources) || !is_array($applicationResources)) {
            throw new RuntimeException('应用资源必须是声明列表');
        }
        foreach ((new SourceSet())->resources($root, $composer['name'], array_merge($composerResources, $applicationResources)) as $resource) {
            if (isset($resources[$resource['target']])) {
                throw new RuntimeException('资源目标冲突：' . $resource['target']);
            }
            $resources[$resource['target']] = $resource;
        }

        $phpHome = getenv('PHP_HOME') ?: '';
        $phpxHome = getenv('PHPX_HOME') ?: '';
        if (!PHP_ZTS || $phpHome === '' || $phpxHome === '') {
            throw new RuntimeException('原生构建需要锁定的ZTS环境，并设置PHP_HOME与PHPX_HOME');
        }
        if (array_key_exists('threads', $settings)
            && (!is_file($phpxHome . '/include/phpx.h') || !is_file($phpxHome . '/src/core/base.cc') || !is_file($phpxHome . '/src/core/debug.cc')
                || !is_file($phpxHome . '/src/core/native_gc.cc')
                || !is_file($phpxHome . '/src/core/string.cc')
                || hash_file('sha256', $phpxHome . '/include/phpx.h') !== PhpxThreadSource::HEADER_SHA256
                || hash_file('sha256', $phpxHome . '/src/core/base.cc') !== PhpxThreadSource::SOURCE_SHA256
                || hash_file('sha256', $phpxHome . '/src/core/debug.cc') !== PhpxThreadSource::DEBUG_SHA256
                || hash_file('sha256', $phpxHome . '/src/core/native_gc.cc') !== PhpxThreadSource::NATIVE_GC_SHA256
                || hash_file('sha256', $phpxHome . '/src/core/string.cc') !== PhpxThreadSource::STRING_SHA256)) {
            throw new RuntimeException('线程应用需要已适配并重新编译的 PHPX 线程 SDK');
        }
        $platform = new BuildPlatform();
        $libraries = $platform->runtimeLibraries($phpHome, $phpxHome);

        $this->directory(dirname($output));
        $this->directory($buildDirectory);
        if ($platform->family() !== 'Linux') {
            foreach ((new NativeLibraryProbe())->sources() as $filename => $contents) {
                $probeFile = $buildDirectory . '/' . $filename;
                $this->writeText($probeFile, $contents);
                $sources[] = $probeFile;
            }
        }
        $adaptation = (new SourceRewriter())->apply($sources, $sourceSets, $buildDirectory . '/adapted-sources');
        $sources = $adaptation['sources'];
        $configurationFiles = [];
        $configurationGeneration = [];
        if (array_key_exists('config', $settings)) {
            if (!is_array($settings['config']) || !isset($included['zoujingli/type-core'])) {
                throw new RuntimeException('配置工厂需要 config 声明并安装 type-core');
            }
            $configurationGeneration = (new ConfigCompiler())->generate($root, $settings['config']);
            $configurationFiles = $configurationGeneration['files'];
            if (array_intersect((new BuildIdentity())->sources($sources), $configurationFiles) !== []) {
                throw new RuntimeException('配置声明不能同时列为生产执行源码；请只通过 config 编译工厂');
            }
            $configurationFile = $buildDirectory . '/generated-config.php';
            $this->writeText($configurationFile, $configurationGeneration['code']);
            unset($configurationGeneration['code']);
            $sources[] = $configurationFile;
        }
        (new ModelCompiler())->assertConfiguration($settings);
        $modelGeneration = (new ModelCompiler())->compile($sources);
        $modelDeclarations = $modelGeneration['models'];
        if ($modelDeclarations !== []) {
            if (!isset($included['zoujingli/type-orm'])) {
                throw new RuntimeException('生成模型需要将 type-orm 安装为生产依赖');
            }
            $modelFile = $buildDirectory . '/generated-models.php';
            $this->writeText($modelFile, $modelGeneration['code']);
        }
        if (array_key_exists('threads', $settings)) {
            if (!is_array($settings['threads']) || !isset($included['zoujingli/type-runtime'])) {
                throw new RuntimeException('线程入口需要 threads 声明并安装 type-runtime');
            }
            $threadFile = $buildDirectory . '/generated-thread-entries.php';
            $this->writeText($threadFile, (new ThreadCompiler())->generate($settings['threads']));
            $sources[] = $threadFile;
        }
        $operations = [];
        if (array_key_exists('operations', $settings)) {
            if (!is_array($settings['operations'])) {
                throw new RuntimeException('operations 必须是显式服务声明');
            }
            $operations = (new OperationCompiler())->generate($root, $settings['operations'], $sources);
            $operationsFile = $buildDirectory . '/generated-operations.php';
            $this->writeText($operationsFile, $operations['code']);
            unset($operations['code']);
            $sources[] = $operationsFile;
        }
        $routing = [];
        if (array_key_exists('routing', $settings) && $settings['routing'] !== [] && !is_string($settings['routing'])) {
            throw new RuntimeException('routing 必须是相对 PHP 文件路径（如 config/route.php）');
        }
        $routeDeclarations = (new RouteCompiler())->declarations($root, $settings['routing'] ?? []);
        if ($routeDeclarations !== []) {
            if (!isset($included['zoujingli/type-core'])) {
                throw new RuntimeException('生成路由需要将 type-core 安装为生产依赖');
            }
            $routing = (new RouteCompiler())->generate($root, $routeDeclarations, $sources);
            $routeFile = $buildDirectory . '/generated-routes.php';
            $this->writeText($routeFile, $routing['code']);
            unset($routing['code']);
            $sources[] = $routeFile;
        }
        $assembly = [];
        $jobs = $settings['queue'] ?? null;
        if ($jobs !== null) {
            if (!is_array($jobs) || !isset($included['zoujingli/type-queue'])) {
                throw new RuntimeException('任务装配需要声明 queue 并安装 type-queue');
            }
            $jobFile = $buildDirectory . '/generated-jobs.php';
            $this->writeText($jobFile, (new JobCompiler())->generate($jobs));
            $sources[] = $jobFile;
        }
        if ($assembled) {
            if (!isset($included['zoujingli/type-core'])) {
                throw new RuntimeException('命令装配需要将 type-core 安装为生产依赖');
            }
            $modules[$this->string($composer, 'name')] = $settings['application'];
            $assembly = (new CommandAssembly())->generate($settings['application'], $modules, $sources);
            $generatedFile = $buildDirectory . '/assembled-application.php';
            $this->writeText($generatedFile, $assembly['code']);
            unset($assembly['code']);
            $sources[] = $generatedFile;
        }
        $compilerOptions = $settings['compiler'] ?? [];
        if (!is_array($compilerOptions) || array_diff(array_keys($compilerOptions), ['optimize', 'debug', 'jobs']) !== []) {
            throw new RuntimeException('compiler 只支持 optimize、debug 与 jobs');
        }
        $compilerOptions += ['optimize' => 2, 'debug' => false, 'jobs' => 2];
        if (!is_int($compilerOptions['optimize']) || $compilerOptions['optimize'] < 0 || $compilerOptions['optimize'] > 3
            || !is_bool($compilerOptions['debug']) || !is_int($compilerOptions['jobs']) || $compilerOptions['jobs'] < 1 || $compilerOptions['jobs'] > 64) {
            throw new RuntimeException('编译参数超出支持范围');
        }
        $projectFile = $buildDirectory . '/project.yml';
        // entry 可以位于 sources 目录内；按锁定编译器规则展开并去重，不把头文件当作编译单元。
        // 原目录声明仍保留给构建身份与完成前重扫，确保新增文件或头文件变化不会绕过缓存检查。
        $compilerSources = [];
        foreach ($sources as $sourcePath) {
            $entries = is_dir($sourcePath) ? (new \TypePhp\Build\FileScanner($sourcePath))->scan() : [$sourcePath];
            foreach ($entries as $sourceFile) {
                $resolvedSource = BuildPlatform::resolve($sourceFile);
                $compilerSources[$resolvedSource] = $resolvedSource;
            }
        }
        $applicationAuditInputs = (new SourceSet())->auditAutoload($root, $composer, array_values($compilerSources));
        if ($modelDeclarations !== []) {
            foreach ($modelGeneration['originals'] as $originalModelSource) {
                unset($compilerSources[$originalModelSource]);
            }
            $compilerSources[$modelFile] = $modelFile;
            $sources[] = $modelFile;
        }
        $this->uniqueSymbols(array_values($compilerSources));
        // JSON 也是有效 YAML，避免为写出几项配置增加另一层序列化实现。
        $project = ['name' => $name, 'mode' => 'bin', 'sources' => array_values($compilerSources),
            'optimize' => $compilerOptions['optimize'], 'debug' => $compilerOptions['debug']];
        // Composer 代理负责把构建环境的自动加载器传给 TypePHP。
        $binDirectory = $GLOBALS['_composer_bin_dir'] ?? null;
        if (!is_string($binDirectory)) {
            throw new RuntimeException('请通过 Composer 生成的 type 命令执行构建');
        }
        $compiler = $binDirectory . '/type-compiler';
        if (!is_file($compiler)) {
            throw new RuntimeException('TypePHP 编译入口不存在');
        }
        $extensions = [];
        foreach (array_merge([$composer], array_values($selected)) as $package) {
            foreach (array_keys($package['require'] ?? []) as $requirement) {
                if (str_starts_with($requirement, 'ext-')) {
                    $extensions[] = substr($requirement, 4);
                }
            }
        }
        $environment = new BuildEnvironment();
        $runtimeDeclaration = $settings['runtime'] ?? [];
        if (!is_array($runtimeDeclaration)) {
            throw new RuntimeException('runtime必须是按原生平台声明的对象');
        }
        if (array_key_exists('threads', $settings)) {
            if (!is_array($runtimeDeclaration[PHP_OS_FAMILY] ?? []) || !is_array($runtimeDeclaration[PHP_OS_FAMILY]['extensions'] ?? [])) {
                throw new RuntimeException('线程应用的运行声明与 extensions 必须为数组');
            }
            $runtimeDeclaration[PHP_OS_FAMILY]['extensions'][] = 'swoole';
        }
        $profile = (new RuntimeProfile($environment))->prepare(
            $root,
            $buildDirectory . '/runtime-profile',
            $phpHome,
            $phpxHome,
            array_values(array_unique($extensions)),
            $runtimeDeclaration
        );
        if (array_key_exists('threads', $settings)) {
            // 静态 Swoole 在内建模块回调里启动，早于 INI 动态扩展。
            // 这里保留完整依赖名单，让应用模块等到 PDO 等扩展登记之后再启动；
            // Swoole 会在 MINIT 替换的驱动改由同一回调提前登记。
            $project['extension-dependencies'] = array_keys($profile['extensions']);
        }
        $this->writeJson($projectFile, $project);
        $native = $environment->fingerprint($phpHome, $phpxHome, array_values(array_unique($extensions)), $profile['module-files']);
        $native['runtime']['extensions'] = $profile['extensions'];
        $native['runtime']['functions'] = $profile['functions'];
        $native['files'] = array_values(array_unique(array_merge($native['files'], $profile['files'])));
        $libraryHashes = array_column($native['native-libraries'], 'sha256', 'path');
        if (PHP_OS_FAMILY === 'Windows') {
            $libraryHashes = array_change_key_case($libraryHashes, CASE_LOWER);
        }
        foreach ($profile['module-files'] as $extension => $moduleFile) {
            $moduleKey = PHP_OS_FAMILY === 'Windows' ? strtolower($moduleFile) : $moduleFile;
            if (($libraryHashes[$moduleKey] ?? null) !== $profile['module-sha256'][$extension]) {
                throw new RuntimeException('运行扩展在指纹收集中变化：' . $extension);
            }
        }
        $runtimeIni = $profile['ini'];
        $swooleLinkFacts = null;
        $swooleResultFile = null;
        if (array_key_exists('threads', $settings)) {
            $selection = new SwooleFeatureSelection();
            $staticFlags = $selection->select([], [], array_keys($profile['extensions']), true);
            $internalExtensions = [...$selection->sharedModulesBeforeSwoole($staticFlags, $profile['module-files']), 'swoole'];
            foreach ($internalExtensions as $internalExtension) {
                unset($native['extension-modules'][$internalExtension]);
            }
            $native['native-libraries'] = $selection->productLibraries($native['native-libraries']);
            $runtimeIni = dirname($profile['ini']) . '/product.ini';
            $this->writeText($runtimeIni, (new RuntimeIni())->withoutExtensions($profile['ini'], $internalExtensions));
            $swooleResultFile = $buildDirectory . '/swoole-link-result.json';
            $swooleLinkFacts = ['protocol' => 1, 'source' => SwooleFeatureSelection::SOURCE, 'mode' => 'static',
                'flags' => $staticFlags, 'internal-extensions' => $internalExtensions];
        }
        $noticeDeclaration = $settings['notices'] ?? [];
        if (!is_array($noticeDeclaration)) {
            throw new RuntimeException('notices必须是声明对象');
        }
        $notices = (new DependencyNotices())->collect($buildDirectory . '/dependency-notices', $noticePackages, $native['native-libraries'], $noticeDeclaration);
        foreach ($notices['resources'] as $resource) {
            if (isset($resources[$resource['target']])) {
                throw new RuntimeException('依赖材料与资源目标冲突：' . $resource['target']);
            }
            $resources[$resource['target']] = $resource;
        }
        // 材料与其他资源共用内容分代和原文摘要；失败构建不覆盖旧代次。
        ksort($resources);
        $resourceIdentity = hash('sha256', json_encode(array_map(static fn (array $resource): array =>
            ['package' => $resource['package'], 'target' => $resource['target'], 'sha256' => $resource['sha256']], $resources), JSON_THROW_ON_ERROR));
        foreach ($resources as &$resource) {
            $destination = $this->destination($root, $settings['output'] . '.resources/' . $resourceIdentity . '/' . $resource['target']);
            $this->directory(dirname($destination));
            if (is_file($destination)) {
                if (hash_file('sha256', $destination) !== $resource['sha256']) {
                    throw new RuntimeException('已有内容寻址资源与声明不符：' . $resource['target']);
                }
            } else {
                $temporary = tempnam(dirname($destination), '.type_resource_');
                if ($temporary === false) {
                    throw new RuntimeException('无法创建资源临时文件');
                }
                try {
                    if (!copy($resource['source'], $temporary) || hash_file('sha256', $temporary) !== $resource['sha256'] || !rename($temporary, $destination)) {
                        throw new RuntimeException('无法复制或校验运行资源：' . $resource['target']);
                    }
                } finally {
                    if (is_file($temporary)) {
                        unlink($temporary);
                    }
                }
            }
            $resource['output'] = $destination;
        }
        unset($resource);
        $compilerEnvironment = $native['build-environment'] ?? $environment->environment($phpHome, $phpxHome);
        // 原生编译器子进程使用独立的受控 PHP 配置。BuildPlatform 会主动过滤
        // 外部环境，避免认证和业务变量泄漏；这里仅接入调用方明确提供的
        // PHPRC 与 PHP_INI_SCAN_DIR，确保 PHP-Parser、TypePHP 和线程编译器
        // 使用与当前主进程一致的 tokenizer 及其它构建扩展。
        $iniFile = getenv('PHPRC');
        if ($iniFile !== false && $iniFile !== '') {
            if (!is_file($iniFile)) {
                throw new RuntimeException('编译子进程的PHPRC必须指向已存在文件');
            }
            $compilerEnvironment['PHPRC'] = BuildPlatform::resolve($iniFile);
        }
        $iniScan = getenv('PHP_INI_SCAN_DIR');
        if ($iniScan !== false) {
            $separator = PHP_OS_FAMILY === 'Windows' ? ';' : ':';
            $scanDirectories = [];
            foreach (explode($separator, $iniScan) as $directory) {
                if ($directory === '') {
                    continue;
                }
                if (!is_dir($directory)) {
                    throw new RuntimeException('编译子进程的PHP_INI_SCAN_DIR必须指向已存在目录');
                }
                $scanDirectories[] = BuildPlatform::resolve($directory);
            }
            $compilerEnvironment['PHP_INI_SCAN_DIR'] = implode($separator, $scanDirectories);
        }
        $threadCompilerArguments = [];
        if (array_key_exists('threads', $settings)) {
            $threadCompilerArguments = ['-c', $profile['ini'], '-d', 'memory_limit=1G'];
            $compilerEnvironment['PHP_INI_SCAN_DIR'] = dirname($profile['ini']) . '/php.d';
            // -c 会替换 PHPRC。运行探针只装嵌入所需扩展，共享 tokenizer 不会
            // 跟着进去；TypePHP 解析源码仍需要 token_get_all。内置词法模块无需追加。
            if (!$this->runtimeProvidesTokenizer($profile['ini'], $environment, $root, $compilerEnvironment)) {
                $tokenizer = self::tokenizerLoadArguments(false, self::loadedTokenizerModule());
                array_splice($threadCompilerArguments, 2, 0, $tokenizer);
                $loaded = trim($environment->run(
                    [PHP_BINARY, ...$threadCompilerArguments, '-d', 'auto_prepend_file=', '-d', 'auto_append_file=', '-d', 'opcache.enable_cli=0', '-r', 'echo function_exists("token_get_all") ? "1" : "0";'],
                    $root,
                    $compilerEnvironment
                ));
                if ($loaded !== '1') {
                    throw new RuntimeException('编译子进程无法加载 tokenizer');
                }
            }
            $available = $environment->run(
                [PHP_BINARY, ...$threadCompilerArguments, '-r',
                'echo PHP_ZTS && class_exists("Swoole\\\\Thread", false) && method_exists("Swoole\\\\Thread", "startNative") && defined("Swoole\\\\Thread::NATIVE_ENTRY_ABI") && constant("Swoole\\\\Thread::NATIVE_ENTRY_ABI") === 2 && filter_var(ini_get("swoole.enable_fiber_mock"), FILTER_VALIDATE_BOOL) ? "ready" : "missing";'],
                $root,
                $compilerEnvironment
            );
            if ($available !== 'ready') {
                throw new RuntimeException('线程应用需要启用 ZTS Thread 和 startNative 的受控 Swoole 模块');
            }
        }
        $toolPackages = $this->productionPackages(['require' => ['zoujingli/type-build' => '*']], $packages);
        $toolRoots = [];
        foreach ($toolPackages as $package) {
            $toolRoots[] = BuildPlatform::resolve($vendorDirectory . '/composer/' . $package['install-path']);
        }
        $identityBuilder = new BuildIdentity();
        $sourceInputs = $identityBuilder->sources($sources);
        $headers = array_keys($identityBuilder->files(array_merge($packageRoots, array_filter($sources, 'is_dir')), ['h', 'hh', 'hpp', 'hxx', 'inc', 'inl', 'tcc']));
        $extraInputs = $settings['native-inputs'] ?? [];
        if (!is_array($extraInputs) || !array_is_list($extraInputs)) {
            throw new RuntimeException('native-inputs 必须是显式构建输入列表');
        }
        foreach ($extraInputs as &$input) {
            if (!is_string($input)) {
                throw new RuntimeException('构建输入路径无效');
            } $input = $this->source($root, $input);
        }
        unset($input);
        $declarations = array_merge([$configuration, $root . '/composer.json'], $configurationFiles, $notices['files']);
        foreach ($packageRoots as $packageRoot) {
            if (is_file($packageRoot . '/composer.json')) {
                $declarations[] = $packageRoot . '/composer.json';
            }
        }
        foreach (['routing'] as $key) {
            if (is_string($settings[$key] ?? null)) {
                $declarations[] = $this->source($root, $settings[$key]);
            }
        }
        $groups = ['sources' => $sourceInputs, 'original-sources' => array_merge($adaptation['originals'], $modelGeneration['originals']), 'headers' => $headers, 'native-inputs' => $extraInputs, 'resources' => array_column($resources, 'source'),
            'locks' => [$root . '/composer.lock', $root . '/toolchain.lock.json'], 'declarations' => $declarations, 'tooling' => $toolRoots,
            'composer-runtime' => [$vendorDirectory . '/composer', $vendorDirectory . '/autoload.php', $binDirectory], 'native' => $native['files']];
        $version = $settings['version'] ?? '0.0.0-dev';
        if (!is_string($version) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.+-]{0,127}$/D', $version)) {
            throw new RuntimeException('应用版本标识无效');
        }
        $capabilities = (new BuildCapabilities())->collect($settings, $selected);
        $nativeFacts = $native;
        unset($nativeFacts['files']);
        $facts = ['name' => $name, 'version' => $version, 'workspace' => $root, 'settings-sha256' => BuildIdentity::digest($settings),
            'composer-sha256' => BuildIdentity::digest($composer), 'production-packages' => $included, 'native' => $nativeFacts,
            'compiler' => $compilerOptions, 'capabilities' => $capabilities, 'resource-generation' => $resourceIdentity];
        if ($swooleLinkFacts !== null) {
            $facts['swoole-link'] = $swooleLinkFacts;
        }
        $identity = $identityBuilder->create($groups, $facts);
        if ($stage !== null) {
            $auditPaths = $applicationAuditInputs;
            foreach ($sourceSets as $set) {
                array_push($auditPaths, ...$set['autoload-inputs'], ...array_column($set['exclusions'], 'path'));
            }
            return (new BuildWorkspace())->create($root, $stage, $identity['description']['inputs'], array_values(array_unique($auditPaths))) + ['build-id' => $identity['id']];
        }
        $manifest = ['build-id' => $identity['id'], 'application' => $name, 'version' => $version, 'runtime' => $native['runtime'],
            'production-packages' => $included,
            'dependency-notices' => $notices['summary'],
            'extension-modules' => $native['extension-modules'],
            'source-adaptations' => $adaptation['mapping'],
            'native-libraries' => $native['native-libraries'], 'capabilities' => $capabilities, 'generator-protocols' => BuildIdentity::GENERATORS,
            'composer-lock-sha256' => hash_file('sha256', $root . '/composer.lock'), 'toolchain-lock-sha256' => hash_file('sha256', $root . '/toolchain.lock.json'),
            'tools' => $native['tools'], 'tool-dependencies' => $native['tool-dependencies'], 'target-triple' => $native['target-triple'], 'extension-abi' => $native['extension-abi'],
            'resource-generation' => $resourceIdentity,
            'resources' => array_map(static fn (array $resource): array => ['target' => $resource['target'], 'sha256' => $resource['sha256']], array_values($resources))];
        if ($platform->family() !== 'Linux') {
            $manifest['system-images'] = $native['system-images'];
            $manifest['system-cache-files'] = $native['system-cache-files'];
            $manifest['system-cache-policy'] = $native['system-cache-policy'];
            $manifest['delay-imports'] = $native['delay-imports'];
        }
        $identityFile = $buildDirectory . '/generated-build-identity.php';
        $this->writeText($identityFile, (new ArtifactManifest())->accessor($manifest));
        $project['sources'][] = $identityFile;
        if ($platform->family() === 'Darwin') {
            $manifestSource = $buildDirectory . '/native-manifest.cc';
            $this->writeText($manifestSource, (new ArtifactManifest())->machOSource($manifest));
            $project['sources'][] = $manifestSource;
        }
        $this->writeJson($projectFile, $project);
        $cacheDirectory = $this->destination($root, $settings['cache-directory'] ?? 'build/cache/artifacts');
        if ($swooleLinkFacts !== null && is_string($swooleResultFile)) {
            $linkFile = $buildDirectory . '/swoole-link.json';
            $linkRequest = ['runtime-extensions' => array_keys($profile['extensions']), 'module-files' => $profile['module-files'],
                'project' => $projectFile, 'cache' => $buildDirectory . '/swoole-static', 'php-home' => $phpHome, 'phpx-home' => $phpxHome,
                'root' => $root, 'native-ini' => $runtimeIni, 'result' => $swooleResultFile,
                'dynamic-extensions' => array_keys($native['extension-modules'])];
            $preparedSource = getenv('TYPE_SWOOLE_SOURCE');
            if (is_string($preparedSource) && is_file($preparedSource . '/config.m4')) {
                $linkRequest['source'] = BuildPlatform::resolve($preparedSource);
            }
            $this->writeJson($linkFile, $linkRequest);
            $compilerEnvironment['TYPE_APP_SWOOLE_LINK'] = $linkFile;
            if (is_file($swooleResultFile) && !unlink($swooleResultFile)) {
                throw new RuntimeException('无法清理过期的 Swoole 静态链接结果');
            }
        }
        $cache = (new ArtifactCache($cacheDirectory))->materialize(
            $identity,
            $manifest,
            $output,
            function (string $candidate) use (&$manifest, $runtimeIni, $swooleResultFile, $identity, $groups, $facts, $sources, $identityBuilder, $compiler, $projectFile, $buildDirectory, $compilerOptions, $root, $environment, $compilerEnvironment, $threadCompilerArguments): void {
                // 工作目录必须短。声明头位于该目录的 include/ 下，文件名还带源码相对路径。
                // Windows 可用路径上限是 259 个字符；把完整构建身份放进目录后，MSVC 打不开生成头。
                $work = $buildDirectory . '/attempts/' . bin2hex(random_bytes(4));
                $this->directory($work);
                $command = [PHP_BINARY, ...$threadCompilerArguments, '-d', 'auto_prepend_file=', '-d', 'auto_append_file=', '-d', 'opcache.enable_cli=0', '-d', 'opcache.preload=',
                    $compiler, $projectFile, '--mode', 'bin', '--output', $candidate, '--build-dir', $work, '--job', (string) $compilerOptions['jobs'], '--no-progress'];
                // Windows 的独立消费者可能复用不完整的 TypePHP 增量清单；
                // 强制重建声明头，避免生成身份源引用不存在的 *_decl.h。
                if (PHP_OS_FAMILY === 'Windows') {
                    $command[] = '--force';
                }
                echo $environment->run($command, $root, $compilerEnvironment, 1800);
                if (is_string($swooleResultFile)) {
                    $this->applyStaticSwooleResult($manifest, $swooleResultFile, $runtimeIni);
                }
                $groups['sources'] = $identityBuilder->sources($sources);
                if ($identityBuilder->create($groups, $facts)['id'] !== $identity['id']) {
                    throw new RuntimeException('构建过程中输入发生变化，拒绝发布或缓存该产物');
                }
            },
            function () use ($identityBuilder, $groups, $facts, $sources, $identity, $buildDirectory, $noticePackages, $native, $noticeDeclaration, $notices): void {
                $currentNotices = (new DependencyNotices())->collect($buildDirectory . '/dependency-notices-check', $noticePackages, $native['native-libraries'], $noticeDeclaration);
                if ($currentNotices['summary']['index-sha256'] !== $notices['summary']['index-sha256']) {
                    throw new RuntimeException('恢复产物前依赖材料已增加、删除或改变');
                }
                $groups['sources'] = $identityBuilder->sources($sources);
                if ($identityBuilder->create($groups, $facts)['id'] !== $identity['id']) {
                    throw new RuntimeException('恢复产物前输入已经变化');
                }
            }
        );

        if ($swooleLinkFacts !== null) {
            $sealedManifest = (new ArtifactManifest())->read($output, $identity['id']);
            $dropped = [];
            foreach (array_keys($profile['module-files']) as $extension) {
                if (!isset($sealedManifest['extension-modules'][$extension])) {
                    $dropped[] = $extension;
                }
            }
            $this->writeText($runtimeIni, (new RuntimeIni())->withoutExtensions($profile['ini'], $dropped));
        }
        $report = [
            'output' => $output,
            'build-id' => $identity['id'],
            'identity' => $identity,
            'cache' => $cache,
            'manifest' => (new ArtifactManifest())->read($output, $identity['id']),
            'sha256' => hash_file('sha256', $output),
            'composer-lock-sha256' => hash_file('sha256', $root . '/composer.lock'),
            'toolchain-lock-sha256' => hash_file('sha256', $root . '/toolchain.lock.json'),
            'php' => PHP_VERSION,
            'zts' => (bool) PHP_ZTS,
            'architecture' => php_uname('m'),
            'build-extensions' => array_map(static fn (string $extension): array => ['name' => $extension, 'version' => phpversion($extension)], get_loaded_extensions()),
            'runtime-profile' => ['ini' => $runtimeIni, 'probe' => $profile['probe'], 'extensions' => $profile['extensions'], 'functions' => $profile['functions']],
            'dependency-notices' => $notices['summary'],
            'typephp' => InstalledVersions::getPrettyVersion('swoole/typephp'),
            'typephp-reference' => InstalledVersions::getReference('swoole/typephp'),
            'phpx' => InstalledVersions::getPrettyVersion('swoole/phpx'),
            'phpx-reference' => InstalledVersions::getReference('swoole/phpx'),
            'production-packages' => $included,
            'sources' => array_values(array_unique($sources)),
            'assembly' => $assembly,
            'models' => $modelDeclarations,
            'queue' => $jobs,
            'routing' => $routing,
            'configuration' => $configurationGeneration,
            'operations' => $operations,
            'source-sets' => $sourceSets,
            'source-adaptations' => $adaptation['mapping'],
            'resources' => array_values($resources),
            'native-libraries' => array_map(static fn (string $library): array => ['path' => $library, 'sha256' => hash_file('sha256', $library)], $libraries),
        ];
        if (is_string($swooleResultFile) && is_file($swooleResultFile)) {
            $linked = json_decode((string) file_get_contents($swooleResultFile), true, 32, JSON_THROW_ON_ERROR);
            if (is_array($linked)) {
                $report['swoole-link'] = $linked;
            }
        }
        $this->writeJson($reportFile, $report);

        return $report;
    }

    /**
     * 编译期间才知道的进程内模块，从发布清单和原生 INI 中去掉，避免再 dlopen 一次。
     *
     * @param array<string, mixed> $manifest
     */
    private function applyStaticSwooleResult(array &$manifest, string $resultFile, string $nativeIni): void
    {
        if (!is_file($resultFile)) {
            throw new RuntimeException('线程应用没有产生 Swoole 静态链接结果');
        }
        $result = json_decode((string) file_get_contents($resultFile), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($result) || !is_array($result['internalize'] ?? null) || !is_array($manifest['extension-modules'] ?? null)) {
            throw new RuntimeException('Swoole 静态链接结果无效');
        }
        $internalize = [];
        foreach ($result['internalize'] as $extension) {
            if (!is_string($extension) || $extension === '') {
                throw new RuntimeException('Swoole 静态链接结果无效');
            }
            unset($manifest['extension-modules'][$extension]);
            $internalize[] = $extension;
        }
        if ($internalize !== []) {
            $this->writeText($nativeIni, (new RuntimeIni())->withoutExtensions($nativeIni, $internalize));
        }
    }

    private function platformPackage(string $name): bool
    {
        return !str_contains($name, '/') && (str_starts_with($name, 'ext-') || str_starts_with($name, 'lib-')
            || in_array($name, ['php', 'php-64bit', 'php-zts', 'php-debug', 'php-ipv6', 'hhvm', 'composer', 'composer-runtime-api', 'composer-plugin-api'], true));
    }

    private function productionPackages(array $composer, array $installed): array
    {
        $queue = array_keys($composer['require'] ?? []);
        $selected = [];
        while ($queue !== []) {
            $name = array_shift($queue);
            if ($this->platformPackage($name)) {
                if (str_starts_with($name, 'ext-') && !extension_loaded(substr($name, 4))) {
                    throw new RuntimeException('构建环境缺少生产依赖声明的扩展：' . $name);
                }
                continue;
            }
            if (isset($selected[$name])) {
                continue;
            }
            $package = $installed[$name] ?? throw new RuntimeException('生产依赖未安装：' . $name);
            $selected[$name] = $package;
            array_push($queue, ...array_keys($package['require'] ?? []));
        }

        return $selected;
    }

    private function json(string $file): array
    {
        $text = is_file($file) ? file_get_contents($file) : false;
        if ($text === false) {
            throw new RuntimeException('无法读取配置：' . $file);
        }
        $value = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new RuntimeException('配置必须是对象：' . $file);
        }

        return $value;
    }

    private function string(array $value, string $key): string
    {
        if (!isset($value[$key]) || !is_string($value[$key]) || $value[$key] === '') {
            throw new RuntimeException('缺少有效配置：' . $key);
        }

        return $value[$key];
    }

    /** 嵌套配置显式选择所属项目；不依赖当前工作目录，也不自动向上搜索其他项目。 */
    private function projectRoot(string $configuration, array $settings): string
    {
        return (new BuildProject())->root($configuration, $settings);
    }

    private function source(string $root, string $relative): string
    {
        $resolved = realpath($root . '/' . $relative);
        if ($resolved === false || !BuildPlatform::contains($root, $resolved)) {
            throw new RuntimeException('源码不存在或超出所属项目：' . $relative);
        }

        return BuildPlatform::path($resolved);
    }

    /** 编译前核对手写及各生成器类名，避免后续输出覆盖或隐藏重复声明。 */
    private function uniqueSymbols(array $sources): void
    {
        $names = [];
        $parser = (new \PhpParser\ParserFactory())->createForNewestSupportedVersion();
        foreach ($sources as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }
            $nodes = $parser->parse((string) file_get_contents($file));
            $traverser = new \PhpParser\NodeTraverser(new \PhpParser\NodeVisitor\NameResolver());
            $nodes = $traverser->traverse($nodes ?? []);
            $finder = new \PhpParser\NodeFinder();
            foreach ($finder->findInstanceOf($nodes, \PhpParser\Node\Stmt\ClassLike::class) as $node) {
                if ($node->name === null || !isset($node->namespacedName)) {
                    continue;
                }
                $name = strtolower($node->namespacedName->toString());
                if (isset($names[$name])) {
                    throw new RuntimeException('生产源码与生成声明存在重复类：' . $node->namespacedName->toString());
                }
                $names[$name] = true;
            }
        }
    }

    private function destination(string $root, string $relative): string
    {
        if (!str_starts_with($relative, 'build/') || str_contains($relative, '..') || str_contains($relative, '\\')) {
            throw new RuntimeException('产物必须位于项目 build 目录内');
        }
        $cursor = $root;
        foreach (explode('/', $relative) as $segment) {
            $cursor .= '/' . $segment;
            if (is_link($cursor)) {
                throw new RuntimeException('产物路径不能经过符号链接');
            }
        }

        $target = $root . '/' . $relative;
        BuildLock::path($target);
        return $target;
    }

    /**
     * 运行配置已经提供 token_get_all 时不追加模块；否则要求当前进程能指出一个已存在的词法模块。
     *
     * @return list<string>
     */
    public static function tokenizerLoadArguments(bool $runtimeHasTokenizer, ?string $moduleFile): array
    {
        if ($runtimeHasTokenizer) {
            return [];
        }
        if (!is_string($moduleFile) || !is_file($moduleFile)) {
            throw new RuntimeException('编译子进程缺少 tokenizer，当前运行配置没有可加载的词法模块');
        }

        return ['-d', 'extension=' . BuildPlatform::resolve($moduleFile)];
    }

    /** 从已加载的 INI 文本解析 tokenizer 模块；相对名称按扩展目录补齐，注释行不生效。 */
    public static function tokenizerModuleFromIni(string $ini, string $extensionDirectory): ?string
    {
        $found = null;
        $matched = preg_match_all('/^[ \t]*extension[ \t]*=[ \t]*"?([^"\r\n;#]+)"?[ \t]*$/mi', $ini, $matches);
        if ($matched === false || $matches[1] === []) {
            return self::tokenizerModuleInDirectory($extensionDirectory);
        }
        foreach ($matches[1] as $value) {
            $value = trim($value);
            $base = strtolower(basename(str_replace('\\', '/', $value)));
            if (!in_array($base, ['tokenizer', 'tokenizer.so', 'php_tokenizer.dll'], true)) {
                continue;
            }
            $candidate = self::existingTokenizerFile($value, $extensionDirectory, $base);
            if ($candidate !== null) {
                $found = $candidate;
            }
        }

        return $found ?? self::tokenizerModuleInDirectory($extensionDirectory);
    }

    private function runtimeProvidesTokenizer(string $runtimeIni, BuildEnvironment $environment, string $root, array $compilerEnvironment): bool
    {
        $present = trim($environment->run(
            [PHP_BINARY, '-c', $runtimeIni, '-d', 'auto_prepend_file=', '-d', 'auto_append_file=', '-d', 'opcache.enable_cli=0', '-r', 'echo function_exists("token_get_all") ? "1" : "0";'],
            $root,
            $compilerEnvironment
        ));

        return $present === '1';
    }

    private static function loadedTokenizerModule(): ?string
    {
        $chunks = [];
        $loaded = php_ini_loaded_file();
        if (is_string($loaded) && $loaded !== '' && is_file($loaded)) {
            $text = file_get_contents($loaded);
            if (is_string($text)) {
                $chunks[] = $text;
            }
        }
        foreach (explode(',', (string) php_ini_scanned_files()) as $file) {
            $file = trim($file);
            if ($file === '' || !is_file($file)) {
                continue;
            }
            $text = file_get_contents($file);
            if (is_string($text)) {
                $chunks[] = $text;
            }
        }
        $directory = ini_get('extension_dir');

        return self::tokenizerModuleFromIni(implode("\n", $chunks), is_string($directory) ? $directory : '');
    }

    private static function existingTokenizerFile(string $value, string $extensionDirectory, string $base): ?string
    {
        $normalized = str_replace('\\', '/', $value);
        $absolute = str_starts_with($normalized, '/')
            || str_starts_with($value, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $value) === 1;
        $candidates = [];
        if ($absolute) {
            $candidates[] = $value;
        } else {
            $directory = rtrim(str_replace('\\', '/', $extensionDirectory), '/');
            if ($directory !== '') {
                $candidates[] = $directory . '/' . basename($normalized);
                if ($base === 'tokenizer') {
                    $candidates[] = $directory . '/tokenizer.so';
                    $candidates[] = $directory . '/php_tokenizer.dll';
                }
            }
        }
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function tokenizerModuleInDirectory(string $extensionDirectory): ?string
    {
        $directory = rtrim(str_replace('\\', '/', $extensionDirectory), '/');
        if ($directory === '') {
            return null;
        }
        foreach (['tokenizer.so', 'php_tokenizer.dll'] as $name) {
            $candidate = $directory . '/' . $name;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function directory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建构建目录：' . $directory);
        }
    }

    private function writeJson(string $file, array $value): void
    {
        $this->writeText($file, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    }

    private function writeText(string $file, string $text): void
    {
        if (is_link($file)) {
            throw new RuntimeException('构建配置或报告不能写入符号链接');
        }
        $temporary = tempnam(dirname($file), '.type_build_');
        if ($temporary === false) {
            throw new RuntimeException('无法创建构建配置或报告临时文件');
        }
        try {
            if (file_put_contents($temporary, $text) !== strlen($text) || !rename($temporary, $file)) {
                throw new RuntimeException('无法写入构建配置或报告');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
