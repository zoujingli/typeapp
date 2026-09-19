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
     * 只在开发进程生成不可变的一整套配置、模型、路由与操作包装。
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
        $inputs = $this->inputs($root, $build, $sources, $configuration, $entryFiles, $production['declarations']);
        if (($inputs['project/' . str_replace('\\', '/', $configuration)] ?? '') !== hash('sha256', $buildSource)) {
            throw new RuntimeException('读取开发声明期间输入发生变化，请重新启动');
        }
        $files = ['models.php'];
        foreach (['routing' => 'routes.php', 'config' => 'config.php', 'operations' => 'operations.php'] as $key => $name) {
            if (isset($build[$key])) {
                $files[] = $name;
            }
        }
        $reference = $directory . '/.inputs-' . hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $cached = $this->reuse($directory, $reference, $inputs, $files);
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
            $cached = $this->reuse($directory, $reference, $inputs, $files);
            if ($cached !== null) {
                if ($inputs !== $this->inputs($root, $build, $sources, $configuration, $entryFiles, $production['declarations'])) {
                    throw new RuntimeException('复用开发代次期间输入发生变化，请重新启动');
                }
                return $cached;
            }
            $this->validateSyntax($sources);
            $adaptation = (new SourceRewriter())->apply($sources, $production['source-sets'], $directory . '/adapted-sources');
            $modelGeneration = (new ModelCompiler())->compile($adaptation['sources']);
            $generated = ['models.php' => $modelGeneration['code']];
            if (isset($build['routing'])) {
                $routes = new RouteCompiler();
                $generated['routes.php'] = $routes->generate($root, $routes->declarations($root, $build['routing']), $sources)['code'];
            }
            if (isset($build['config'])) {
                $generated['config.php'] = (new ConfigCompiler())->generate($root, $build['config'])['code'];
            }
            if (isset($build['operations'])) {
                $generated['operations.php'] = (new OperationCompiler())->generate($root, $build['operations'], $sources)['code'];
            }
            if ($inputs !== $this->inputs($root, $build, $sources, $configuration, $entryFiles, $production['declarations'])) {
                throw new RuntimeException('开发生成期间源码或配置发生变化，请重新启动；没有加载旧代代码');
            }
            $hashes = [];
            foreach ($generated as $name => $code) {
                $hashes[$name] = hash('sha256', $code);
            }
            $manifest = ['protocol' => 1, 'inputs' => $inputs, 'files' => $hashes];
            $encoded = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $generation = hash('sha256', $encoded);
            $target = $directory . '/' . $generation;
            if (is_dir($target)) {
                $existing = $this->verifyGeneration($directory, $generation, $inputs, $files);
                $this->publishReference($reference, $generation);
                return $existing;
            }
            if (file_exists($target) || is_link($target)) {
                throw new RuntimeException('开发代次目标不是普通目录');
            }
            $staging = $directory . '/.pending-' . bin2hex(random_bytes(12));
            if (!mkdir($staging, 0700)) {
                throw new RuntimeException('无法创建本次开发生成临时目录');
            }
            try {
                foreach ($generated + ['manifest.json' => $encoded] as $name => $code) {
                    if (file_put_contents($staging . '/' . $name, $code, LOCK_EX) !== strlen($code)) {
                        throw new RuntimeException('无法完整写入开发生成代码：' . $name);
                    }
                }
                if ($inputs !== $this->inputs($root, $build, $sources, $configuration, $entryFiles, $production['declarations'])) {
                    throw new RuntimeException('发布开发代次前输入发生变化，请重新启动');
                }
                if (!rename($staging, $target)) {
                    throw new RuntimeException('无法发布本次完整开发代次');
                }
            } finally {
                if (is_dir($staging)) {
                    // 仅清理本次独立临时目录内的五个已知文件，不触碰其他代次或运行数据。
                    foreach (array_merge($files, ['manifest.json']) as $name) {
                        if (is_file($staging . '/' . $name)) {
                            unlink($staging . '/' . $name);
                        }
                    }
                    rmdir($staging);
                }
            }

            $this->publishReference($reference, $generation);
            return ['generation' => $generation, 'directory' => $target, 'files' => $files];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** 按输入摘要读取不可变索引；已存在但损坏时拒绝，不重新生成来掩盖损坏。 */
    private function reuse(string $directory, string $reference, array $inputs, array $files): ?array
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
        return $this->verifyGeneration($directory, $generation, $inputs, $files);
    }

    /** 输入、清单内容身份和生成文件逐项相符才可加载；索引本身不授予信任。 */
    private function verifyGeneration(string $directory, string $generation, array $inputs, array $files): array
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
        if (!is_array($manifest) || ($manifest['protocol'] ?? null) !== 1 || ($manifest['inputs'] ?? null) !== $inputs
            || !is_array($manifest['files'] ?? null) || array_keys($manifest['files']) !== $files) {
            throw new RuntimeException('已存在的开发代次清单损坏，拒绝使用');
        }
        foreach ($files as $name) {
            if (!is_file($target . '/' . $name) || is_link($target . '/' . $name)
                || hash_file('sha256', $target . '/' . $name) !== $manifest['files'][$name]) {
                throw new RuntimeException('已存在的开发代次代码损坏，拒绝使用：' . $name);
            }
        }
        return ['generation' => $generation, 'directory' => $target, 'files' => $files];
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

    /** 准备阶段检查业务语法，不执行业务源文件；无效修改不得替换在用进程。 */
    private function validateSyntax(array $sources): void
    {
        $parser = (new \PhpParser\ParserFactory())->createForNewestSupportedVersion();
        foreach ($sources as $source) {
            $files = is_file($source) ? [$source] : new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $entry) {
                $file = is_string($entry) ? $entry : $entry->getPathname();
                if (is_file($file) && pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                    if (is_link($file)) {
                        throw new RuntimeException('开发源码不能使用符号链接');
                    }
                    $parser->parse((string) file_get_contents($file));
                }
            }
        }
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
