<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;
use ReflectionClass;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use FilesystemIterator;

/** 共享开发生成模块：源码和声明生成整代代码，不读取运行秘密或执行配置。 */
final class DevelopmentBuilder
{
    /** @var list<string> 本次已审计的原始文件，仅用于当前准备调用的加载。 */
    private array $preparedSources = [];

    /** @var array<string, string> 同一 PHP 进程不能混用同一应用的不同声明代次。 */
    private static array $loaded = [];

    /**
     * 按构建声明选择同一开发代次；不加载应用、不读取运行配置。
     *
     * @return array{generation: string, directory: string, files: list<string>}
     * @throws RuntimeException 项目、生成输入、锁或完整代次校验失败。
     * @throws \JsonException 构建配置不是有效JSON。
     */
    public function prepareConfiguration(string $configuration): array
    {
        $project = (new BuildProject())->read($configuration);
        $development = $project['settings']['development'] ?? [];
        $output = $development['output'] ?? dirname($project['settings']['build-directory']) . '/development';
        $inputs = [];
        foreach (['entry', 'prepare'] as $key) {
            if (isset($development[$key])) {
                $inputs[] = $development[$key];
            }
        }
        return $this->prepare($project['root'], substr($project['file'], strlen($project['root']) + 1), $output, $inputs);
    }

    /**
     * 在应用类加载前核验并装入完整代次；只供 PHP 开发入口使用。
     *
     * 已加载的原类、其他代次或重复定义不能被静默保留；生产程序不调用此加载器。
     * @return array{generation:string, directory:string, files:list<string>, declaration-generation:string}
     * @throws RuntimeException 原源码已提前加载、输入变化或代次完整性检查失败。
     */
    public function loadConfiguration(string $configuration): array
    {
        $project = (new BuildProject())->read($configuration);
        $result = $this->prepareConfiguration($configuration);
        $root = $project['root'];
        if (isset(self::$loaded[$root])) {
            if (self::$loaded[$root] !== $result['generation']) {
                throw new RuntimeException('同一开发进程不能加载不同代次，请重新启动');
            }
            return $result;
        }
        $manifest = json_decode((string) file_get_contents($result['directory'] . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $declarations = $manifest['declarations'];
        $classes = [];
        $loadFiles = [];
        foreach (['classes', 'functions'] as $group) {
            foreach ($declarations['symbols'][$group] as $key => $symbol) {
                $file = $this->resolveReference($symbol['file'], $result['directory'], $declarations['files']);
                $exists = $group === 'classes'
                    ? class_exists($symbol['name'], false) || interface_exists($symbol['name'], false) || trait_exists($symbol['name'], false)
                    : function_exists($symbol['name']);
                if ($exists) {
                    $reflection = $group === 'classes' ? new ReflectionClass($symbol['name']) : new \ReflectionFunction($symbol['name']);
                    if ($reflection->getFileName() === false || BuildPlatform::path($reflection->getFileName()) !== BuildPlatform::path($file)) {
                        throw new RuntimeException('开发声明已在准备前加载，拒绝混用原源码或其他代次：' . $symbol['name']);
                    }
                }
                if ($group === 'classes') {
                    $classes[$key] = $file;
                } else {
                    $loadFiles[$file] = true;
                }
            }
        }
        $autoload = static function (string $class) use ($classes): void {
            $file = $classes[strtolower($class)] ?? null;
            if ($file !== null) {
                require_once $file;
            }
        };
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $vendor = $composer['config']['vendor-dir'] ?? 'vendor';
        $vendor = BuildPlatform::resolve((new BuildPlatform())->absolute($vendor) ? $vendor : $root . '/' . $vendor);
        // 生产适配源码与业务声明共用 TypePHP 语义；PHP 开发进程加载官方配套实现。
        require_once $vendor . '/swoole/typephp/src/polyfills.php';
        spl_autoload_register($autoload, true, true);
        try {
            $composerFiles = is_file($vendor . '/composer/autoload_files.php')
                ? require $vendor . '/composer/autoload_files.php' : [];
            if (!is_array($composerFiles)) {
                throw new RuntimeException('Composer files 清单损坏，拒绝加载开发代次');
            }
            $composerFilePaths = [];
            foreach ($composerFiles as $id => $file) {
                if (!is_string($id) || !is_string($file)) {
                    throw new RuntimeException('Composer files 清单包含无效入口');
                }
                $composerFilePaths[BuildPlatform::resolve($file)] = $id;
            }
            $replaced = [];
            foreach ($declarations['replacements'] as $original => $replacement) {
                $replaced[$this->resolveReference($original, $result['directory'], $declarations['files'])] = true;
            }
            // 先安装全部类映射再加载声明文件，保证同文件多声明及跨文件父类也使用正确代次。
            foreach ($declarations['symbols']['classes'] as $symbol) {
                if (str_starts_with($symbol['file'], 'generated:')) {
                    $loadFiles[$this->resolveReference($symbol['file'], $result['directory'], $declarations['files'])] = true;
                }
            }
            // Composer 的 files 由 Composer 自己按唯一标识加载；提前 require 会绕过
            // Composer 的 once 保护，尤其在开发代次保留原函数文件时会造成重复声明。
            foreach (array_keys($loadFiles) as $file) {
                if (isset($composerFilePaths[BuildPlatform::resolve($file)])) {
                    if (!isset($replaced[BuildPlatform::resolve($file)])) {
                        unset($loadFiles[$file]);
                        continue;
                    }
                    $GLOBALS['__composer_autoload_files'][$composerFilePaths[BuildPlatform::resolve($file)]] = true;
                }
                require_once $file;
            }
            require_once $vendor . '/autoload.php';
            // Composer 注册后继续让这份完整、已审计类映射优先，覆盖同文件原类的自动加载路径。
            spl_autoload_unregister($autoload);
            spl_autoload_register($autoload, true, true);
            self::$loaded[$root] = $result['generation'];
            return $result;
        } catch (\Throwable $error) {
            spl_autoload_unregister($autoload);
            throw $error;
        }
    }

    /**
     * 只在开发进程生成不可变的完整应用声明；与原生构建共用分析和源码替换。
     *
     * 不读取 .env，不执行 config 源码；按完整输入身份复用已校验代次，不使用可变化的 current 指针。
     *
     * @param list<string> $entryFiles 需要与同一代次绑定的开发入口，相对项目根。
     * @return array{generation: string, directory: string, files: list<string>}
     * @throws RuntimeException 生成输入变化、锁超时、文件损坏或无法完整发布。
     * @throws \JsonException 构建配置不是有效JSON。
     */
    public function prepare(string $root, string $configuration, string $output, array $entryFiles = []): array
    {
        if (!is_file($root . '/vendor/autoload.php')) {
            throw new RuntimeException('请先执行 composer install');
        }
        $root = BuildPlatform::resolve($root);
        if (!str_starts_with($output, 'build/')) {
            throw new RuntimeException('开发输出必须位于build目录');
        }
        $directory = $root . '/' . $output;
        BuildLock::path($directory);
        if (file_exists($directory) && !is_dir($directory)) {
            throw new RuntimeException('开发生成目标不是目录');
        }
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建开发生成目录');
        }
        $buildSource = (string) file_get_contents($this->input($root, $configuration));
        $build = json_decode($buildSource, true, 512, JSON_THROW_ON_ERROR);
        (new ModelCompiler())->assertConfiguration($build);
        $sources = [];
        foreach ($build['sources'] ?? [] as $source) {
            $sources[] = $this->input($root, $source);
        }
        if (is_string($build['entry'] ?? null)) {
            $sources[] = $this->input($root, $build['entry']);
        }
        $production = (new SourceSet())->productionSources($root, $build);
        array_push($sources, ...$production['sources']);
        $sources = array_values(array_unique($sources));
        $this->preparedSources = (new BuildIdentity())->sources($sources);
        $inputs = $this->inputs($root, $build, $sources, $configuration, $entryFiles, $production['declarations']);
        if (($inputs['project/' . str_replace('\\', '/', $configuration)] ?? '') !== hash('sha256', $buildSource)) {
            throw new RuntimeException('读取开发声明期间输入发生变化，请重新启动');
        }
        $reference = $directory . '/.inputs-' . hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $cached = $this->reuse($directory, $reference, $inputs);
        if ($cached !== null) {
            if ($inputs !== $this->inputs($root, $build, $sources, $configuration, $entryFiles, $production['declarations'])) {
                throw new RuntimeException('复用开发代次期间输入发生变化，请重新启动');
            }
            return $cached;
        }
        $lockPath = $directory . '/.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('开发生成锁不能使用符号链接');
        }
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false) {
            throw new RuntimeException('无法打开开发生成锁');
        }
        $deadline = hrtime(true) / 1e9 + 30.0;
        try {
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (hrtime(true) / 1e9 >= $deadline) {
                    throw new RuntimeException('开发生成锁等待超时');
                }
                usleep(10000);
            }
            if ($inputs !== $this->inputs($root, $build, $sources, $configuration, $entryFiles, $production['declarations'])) {
                throw new RuntimeException('等待开发生成期间输入发生变化，请重新启动');
            }
            // 等锁时可能已有同输入进程发布；只有真正缺少代次才解析语法和生成。
            $cached = $this->reuse($directory, $reference, $inputs);
            if ($cached !== null) {
                if ($inputs !== $this->inputs($root, $build, $sources, $configuration, $entryFiles, $production['declarations'])) {
                    throw new RuntimeException('复用开发代次期间输入发生变化，请重新启动');
                }
                return $cached;
            }
            $staging = $directory . '/.pending-' . bin2hex(random_bytes(12));
            if (!mkdir($staging, 0700)) {
                throw new RuntimeException('无法创建本次开发生成临时目录');
            }
            try {
                $result = (new ApplicationGeneration())->generate(
                    $root,
                    $build,
                    $sources,
                    $production['source-sets'],
                    $production['modules'],
                    $production['included'],
                    $staging
                );
                if ($inputs !== $this->inputs($root, $build, $sources, $configuration, $entryFiles, $production['declarations'])) {
                    throw new RuntimeException('开发生成期间源码或配置发生变化，请重新启动；没有加载旧代代码');
                }
                $manifest = ['protocol' => 2, 'inputs' => $inputs, 'declarations' => $result['metadata'], 'declaration-generation' => $result['identity']];
                $encoded = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $generation = hash('sha256', $encoded);
                $target = $directory . '/' . $generation;
                if (is_dir($target)) {
                    $existing = $this->verifyGeneration($directory, $generation, $inputs);
                    $this->publishReference($reference, $generation);
                    return $existing;
                }
                if (file_exists($target) || is_link($target)) {
                    throw new RuntimeException('开发代次目标不是普通目录');
                }
                if (file_put_contents($staging . '/manifest.json', $encoded, LOCK_EX) !== strlen($encoded)) {
                    throw new RuntimeException('无法完整写入开发生成清单');
                }
                if ($inputs !== $this->inputs($root, $build, $sources, $configuration, $entryFiles, $production['declarations'])) {
                    throw new RuntimeException('发布开发代次前输入发生变化，请重新启动');
                }
                if (!rename($staging, $target)) {
                    throw new RuntimeException('无法发布本次完整开发代次');
                }
            } finally {
                if (is_dir($staging)) {
                    // 只清理本次私有暂存树，不跟随链接或触碰其他代次。
                    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($staging, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                        if ($entry->isDir() && !$entry->isLink()) {
                            rmdir($entry->getPathname());
                        } else {
                            unlink($entry->getPathname());
                        }
                    }
                    rmdir($staging);
                }
            }

            $this->publishReference($reference, $generation);
            return $this->verifyGeneration($directory, $generation, $inputs);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** 按输入摘要读取不可变索引；已存在但损坏时拒绝，不重新生成来掩盖损坏。 */
    private function reuse(string $directory, string $reference, array $inputs): ?array
    {
        if (!file_exists($reference) && !is_link($reference)) {
            return null;
        }
        if (!is_file($reference) || is_link($reference) || filesize($reference) !== 64) {
            throw new RuntimeException('开发代次索引损坏，拒绝使用');
        }
        $generation = (string) file_get_contents($reference);
        if (preg_match('/^[a-f0-9]{64}$/D', $generation) !== 1) {
            throw new RuntimeException('开发代次索引损坏，拒绝使用');
        }
        return $this->verifyGeneration($directory, $generation, $inputs);
    }

    /** 输入、清单内容身份和生成文件逐项相符才可加载；索引本身不授予信任。 */
    private function verifyGeneration(string $directory, string $generation, array $inputs): array
    {
        $target = $directory . '/' . $generation;
        if (!is_dir($target) || is_link($target) || !is_file($target . '/manifest.json') || is_link($target . '/manifest.json')) {
            throw new RuntimeException('已存在的开发代次清单损坏，拒绝使用');
        }
        $encoded = (string) file_get_contents($target . '/manifest.json');
        if (hash('sha256', $encoded) !== $generation) {
            throw new RuntimeException('已存在的开发代次清单损坏，拒绝使用');
        }
        $manifest = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        $declarations = $manifest['declarations'] ?? null;
        if (!is_array($manifest) || ($manifest['protocol'] ?? null) !== 2 || ($manifest['inputs'] ?? null) !== $inputs
            || !is_array($declarations) || ($declarations['protocol'] ?? null) !== BuildIdentity::GENERATORS['declarations']
            || ($declarations['generators'] ?? null) !== BuildIdentity::GENERATORS || ($declarations['platform'] ?? null) !== PHP_OS_FAMILY
            || !is_array($declarations['files'] ?? null) || !is_array($declarations['symbols'] ?? null) || !is_array($declarations['replacements'] ?? null)) {
            throw new RuntimeException('已存在的开发代次清单损坏，拒绝使用');
        }
        foreach ($declarations['files'] as $name => $hash) {
            if (!is_string($name) || preg_match('~^(?:generated-(?:config|models|schema|thread-entries|operations|routes|jobs)\.php|assembled-application\.php|adapted-sources/[a-f0-9]{64}/[A-Za-z0-9_.-]+\.php)$~D', $name) !== 1) {
                throw new RuntimeException('开发代次文件路径损坏，拒绝使用');
            }
            BuildLock::path($target . '/' . $name);
            if (!is_file($target . '/' . $name) || is_link($target . '/' . $name)
                || hash_file('sha256', $target . '/' . $name) !== $hash) {
                throw new RuntimeException('已存在的开发代次代码损坏，拒绝使用：' . $name);
            }
        }
        foreach (['classes', 'functions'] as $group) {
            if (!is_array($declarations['symbols'][$group] ?? null)) {
                throw new RuntimeException('开发代次符号映射损坏，拒绝使用');
            }
            foreach ($declarations['symbols'][$group] as $key => $symbol) {
                if (!is_array($symbol) || !is_string($symbol['name'] ?? null) || strtolower($symbol['name']) !== $key) {
                    throw new RuntimeException('开发代次符号映射损坏，拒绝使用');
                }
                $this->resolveReference($symbol['file'] ?? null, $target, $declarations['files']);
            }
        }
        foreach ($declarations['replacements'] as $original => $replacement) {
            if (!str_starts_with((string) $original, 'source:') || !is_string($replacement) || !str_starts_with($replacement, 'generated:')) {
                throw new RuntimeException('开发代次源码替换映射损坏，拒绝使用');
            }
            $this->resolveReference($original, $target, $declarations['files']);
            $this->resolveReference($replacement, $target, $declarations['files']);
        }
        $sourceHashes = array_map(static fn (string $source): string => hash_file('sha256', $source), $this->preparedSources);
        if (($manifest['declaration-generation'] ?? null) !== BuildIdentity::digest(['sources' => $sourceHashes, 'generation' => $declarations])) {
            throw new RuntimeException('开发声明身份损坏或输入变化，拒绝使用');
        }
        return ['generation' => $generation, 'directory' => $target, 'files' => array_keys($declarations['files']),
            'declaration-generation' => $manifest['declaration-generation']];
    }

    /** 将清单中的逻辑文件引用恢复到本次已审计路径，不接受任意绝对路径或目录跳转。 */
    private function resolveReference(mixed $reference, string $directory, array $files): string
    {
        if (is_string($reference) && preg_match('/^source:(0|[1-9][0-9]*)$/D', $reference, $match) === 1
            && isset($this->preparedSources[(int) $match[1]])) {
            return $this->preparedSources[(int) $match[1]];
        }
        if (is_string($reference) && str_starts_with($reference, 'generated:') && isset($files[substr($reference, 10)])) {
            return $directory . '/' . substr($reference, 10);
        }
        throw new RuntimeException('开发代次文件引用损坏，拒绝使用');
    }

    /** 仅持生成锁时发布完整索引；原子替换临时文件，其他进程不读取半写记录。 */
    private function publishReference(string $reference, string $generation): void
    {
        $temporary = tempnam(dirname($reference), '.pending-index-');
        if ($temporary === false) {
            throw new RuntimeException('无法准备开发代次索引');
        }
        $temporary = BuildPlatform::path($temporary);
        try {
            if (dirname($temporary) !== dirname($reference)
                || file_put_contents($temporary, $generation, LOCK_EX) !== strlen($generation) || !rename($temporary, $reference)) {
                throw new RuntimeException('无法发布完整开发代次索引');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * 解析必须留在项目内的明确输入，避免把 .env 或项目外目录当作隐式构建依赖。
     *
     * @throws RuntimeException 路径非字符串、不存在、经过符号链接或越出应用根。
     */
    private function input(string $root, mixed $path): string
    {
        if (!is_string($path) || $path === '' || str_contains($path, "\0")) {
            throw new RuntimeException('开发生成输入路径无效');
        }
        $basename = basename(str_replace('\\', '/', $path));
        if (preg_match('/^\.env(?:\.|$)/D', $basename) === 1 || $basename === 'auth.json') {
            throw new RuntimeException('开发生成不接受 dotenv 或 Composer 认证文件作为输入');
        }
        $local = $root . '/' . $path;
        $resolved = realpath($local);
        $prefix = rtrim(str_replace('\\', '/', (string) realpath($root)), '/') . '/';
        if ($resolved === false || is_link($local) || !str_starts_with(str_replace('\\', '/', $resolved), $prefix)) {
            throw new RuntimeException('开发生成输入必须是项目内的普通文件或目录');
        }

        return $resolved;
    }

    /**
     * 对源码、声明和生成器取可重现内容身份；逻辑名称不含绝对项目根或运行环境值。
     *
     * @param array<string, mixed> $build 当前明确的构建声明。
     * @param list<string> $sources 解析后的生产源码入口。
     * @return array<string, string> 稳定相对身份到 SHA-256 的有序映射。
     */
    private function inputs(string $root, array $build, array $sources, string $configuration, array $entryFiles, array $dependencyDeclarations): array
    {
        $inputs = [];
        foreach ((new SchemaCompiler())->inputs($sources) as $index => $snapshot) {
            $inputs['schema-snapshot-' . $index] = hash_file('sha256', $snapshot);
        }
        foreach ($dependencyDeclarations as $index => $declaration) {
            $inputs['production-declaration-' . $index] = hash_file('sha256', $declaration);
        }
        $groups = [
            'tooling' => dirname((new ReflectionClass(ModelCompiler::class))->getFileName()),
            'parser' => dirname((new ReflectionClass(\PhpParser\ParserFactory::class))->getFileName()),
        ];
        foreach ($sources as $index => $source) {
            $groups['source-' . $index] = $source;
        }
        foreach ($groups as $group => $path) {
            $resolved = realpath($path);
            if ($resolved === false) {
                throw new RuntimeException('开发生成器输入不存在');
            }
            $prefix = rtrim(str_replace('\\', '/', $resolved), '/');
            $entries = is_file($resolved) ? [$resolved]
                : new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS));
            foreach ($entries as $entry) {
                $filename = is_string($entry) ? $entry : $entry->getPathname();
                if (!is_file($filename) || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'php') {
                    continue;
                }
                $real = realpath($filename);
                $normalized = str_replace('\\', '/', (string) $real);
                if ($real === false || is_link($filename) || (!is_file($resolved) && !str_starts_with($normalized, $prefix . '/'))) {
                    throw new RuntimeException('开发生成源码不能越出声明目录');
                }
                $hash = hash_file('sha256', $real);
                if ($hash === false) {
                    throw new RuntimeException('无法读取开发生成源码摘要');
                }
                $relative = is_file($resolved) ? basename($real) : substr($normalized, strlen($prefix) + 1);
                $inputs[$group . '/' . $relative] = $hash;
            }
        }
        $files = ['composer.json', 'composer.lock', $configuration, ...$entryFiles];
        foreach (['routing'] as $key) {
            if (!array_key_exists($key, $build) || $build[$key] === [] || $build[$key] === null) {
                continue;
            }
            if (!is_string($build[$key]) || !str_ends_with($build[$key], '.php')) {
                throw new RuntimeException('开发路由声明必须为 PHP 文件');
            }
            $files[] = $build[$key];
        }
        foreach ($build['config']['files'] ?? [] as $configurationFile) {
            if (!is_string($configurationFile) || !str_ends_with($configurationFile, '.php')) {
                throw new RuntimeException('开发配置声明必须为 PHP 文件');
            }
            $files[] = $configurationFile;
        }
        foreach (array_unique($files) as $file) {
            $local = $this->input($root, $file);
            if (!is_file($local)) {
                throw new RuntimeException('开发生成声明必须为文件');
            }
            $hash = hash_file('sha256', $local);
            if ($hash === false) {
                throw new RuntimeException('无法读取开发生成声明摘要');
            }
            $inputs['project/' . str_replace('\\', '/', $file)] = $hash;
        }
        ksort($inputs);

        return $inputs;
    }
}
