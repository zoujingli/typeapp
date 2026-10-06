<?php

declare(strict_types=1);

namespace Type\Build;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RuntimeException;

/** 开发与 AOT 共用的声明生成过程；先审计原源码，再生成完整替换和直接调用入口。 */
final class ApplicationGeneration
{
    /**
     * 只分析已声明的生产实现，不执行应用或读取运行配置。
     *
     * @param list<string> $sources 原始生产源码文件或目录。
     * @param array<string, array<string, mixed>> $sourceSets 已审计的生产包源码及适配声明。
     * @param array<string, array<string, mixed>> $modules 已安装模块的声明，未启用模块仍参与源码审计。
     * @param array<string, string> $included 已安装生产包及版本。
     * @return array<string, mixed> 完整编译输入、生成字节、类映射、源码替换及各声明报告。
     * @throws RuntimeException 生产实现遗漏、声明冲突、生成协议或文件写入失败。
     */
    public function generate(string $root, array $settings, array $sources, array $sourceSets, array $modules, array $included, string $directory): array
    {
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $originals = (new BuildIdentity())->sources($sources);
        $audit = (new SourceSet())->auditAutoload($root, $composer, $originals);
        $adaptation = (new SourceRewriter())->apply($originals, $sourceSets, $directory . '/adapted-sources');
        $effective = $adaptation['sources'];
        $generated = [];
        $replacements = [];
        foreach ($adaptation['mapping'] as $mapping) {
            $relative = substr($mapping['generated'], strlen($directory) + 1);
            $generated[$relative] = (string) file_get_contents($mapping['generated']);
            $replacements[$mapping['source']] = $mapping['generated'];
        }
        $configuration = [];
        if (array_key_exists('config', $settings)) {
            if (!is_array($settings['config']) || !isset($included['zoujingli/type-core'])) {
                throw new RuntimeException('配置工厂需要 config 声明并安装 type-core');
            }
            $configuration = (new ConfigCompiler())->generate($root, $settings['config']);
            if (array_intersect($originals, $configuration['files']) !== []) {
                throw new RuntimeException('配置声明不能同时列为生产执行源码；请只通过 config 编译工厂');
            }
            $effective[] = $this->write($directory, 'generated-config.php', $configuration['code'], $generated);
            unset($configuration['code']);
        }
        $schemas = (new SchemaCompiler())->compile($effective);
        if ($schemas['schemas'] !== []) {
            if (!isset($included['zoujingli/type-orm'])) {
                throw new RuntimeException('生成 Schema 迁移需要安装 type-orm 生产依赖');
            }
            $schemaFile = $this->write($directory, 'generated-schema.php', $schemas['code'], $generated);
            foreach ($originals as $original) {
                if (in_array($replacements[$original] ?? $original, $schemas['originals'], true)) {
                    $replacements[$original] = $schemaFile;
                }
            }
            $effective = array_values(array_diff($effective, $schemas['originals']));
            $effective[] = $schemaFile;
        }
        unset($schemas['code']);
        (new ModelCompiler())->assertConfiguration($settings);
        $models = (new ModelCompiler())->compile($effective);
        if ($models['models'] !== []) {
            if (!isset($included['zoujingli/type-orm'])) {
                throw new RuntimeException('生成模型需要将 type-orm 安装为生产依赖');
            }
            $modelFile = $this->write($directory, 'generated-models.php', $models['code'], $generated);
            foreach ($originals as $original) {
                if (in_array($replacements[$original] ?? $original, $models['originals'], true)) {
                    $replacements[$original] = $modelFile;
                }
            }
            $effective = array_values(array_diff($effective, $models['originals']));
            $effective[] = $modelFile;
        }
        unset($models['code']);
        if (array_key_exists('threads', $settings)) {
            if (!is_array($settings['threads']) || !isset($included['zoujingli/type-runtime'])) {
                throw new RuntimeException('线程入口需要 threads 声明并安装 type-runtime');
            }
            if (!defined('Type\\Runtime\\CoroutineRuntime::THREAD_ENTRY_PROTOCOL')
                || constant('Type\\Runtime\\CoroutineRuntime::THREAD_ENTRY_PROTOCOL') !== BuildIdentity::GENERATORS['threads']
                || !method_exists(\Type\Runtime\CoroutineRuntime::class, 'enterThread')) {
                throw new RuntimeException('type-build 与 type-runtime 的线程消息协议不匹配；请安装同批次兼容组件后重新构建');
            }
            $effective[] = $this->write($directory, 'generated-thread-entries.php', (new ThreadCompiler())->generate($settings['threads']), $generated);
        }
        if (array_key_exists('operations', $settings) && !is_array($settings['operations'])) {
            throw new RuntimeException('operations 旧配置已移除，请直接声明原 Service 方法');
        }
        $operations = (new OperationCompiler())->generate($root, $settings['operations'] ?? [], $effective, $included);
        if ($operations['operations'] !== []) {
            $operationFile = $this->write($directory, 'generated-operations.php', $operations['code'], $generated);
            foreach ($originals as $original) {
                if (in_array($replacements[$original] ?? $original, $operations['originals'], true)) {
                    $replacements[$original] = $operationFile;
                }
            }
            $effective = array_values(array_diff($effective, $operations['originals']));
            $effective[] = $operationFile;
        }
        unset($operations['code']);
        $routing = [];
        if (array_key_exists('routing', $settings) && $settings['routing'] !== [] && !is_string($settings['routing'])) {
            throw new RuntimeException('routing 必须是相对 PHP 文件路径（如 config/route.php）');
        }
        $routeCompiler = new RouteCompiler();
        $routes = $routeCompiler->declarations($root, $settings['routing'] ?? []);
        if ($routes !== []) {
            if (!isset($included['zoujingli/type-core'])) {
                throw new RuntimeException('生成路由需要将 type-core 安装为生产依赖');
            }
            $routing = $routeCompiler->generate($root, $routes, $effective);
            $effective[] = $this->write($directory, 'generated-routes.php', $routing['code'], $generated);
            unset($routing['code']);
        }
        if (array_key_exists('queue', $settings)) {
            throw new RuntimeException('queue 工厂映射已移除，请通过 application.jobs 声明 Job 并使用应用依赖装配');
        }
        $assembly = [];
        if (array_key_exists('application', $settings)) {
            if (!is_array($settings['application']) || array_key_exists('entry', $settings)) {
                throw new RuntimeException('application 装配必须是对象，且不能同时指定手写 entry');
            }
            if (!isset($included['zoujingli/type-core'])) {
                throw new RuntimeException('命令装配需要将 type-core 安装为生产依赖');
            }
            $modules[$composer['name']] = $settings['application'];
            $assembly = (new CommandAssembly())->generate($settings['application'], $modules, $effective, $routing);
            $effective[] = $this->write($directory, 'assembled-application.php', $assembly['code'], $generated);
            unset($assembly['code']);
        }
        $effective = array_values(array_unique($effective));
        $references = [];
        foreach ($originals as $index => $original) {
            $references[$original] = 'source:' . $index;
        }
        $hashes = [];
        foreach ($generated as $relative => $code) {
            $references[$directory . '/' . $relative] = 'generated:' . $relative;
            $hashes[$relative] = hash('sha256', $code);
        }
        $symbols = $this->symbols($effective, $references);
        $replacementMap = [];
        foreach ($replacements as $original => $replacement) {
            $replacementMap[$references[$original]] = $references[$replacement];
        }
        ksort($hashes);
        ksort($replacementMap);
        $metadata = ['protocol' => BuildIdentity::GENERATORS['declarations'], 'platform' => PHP_OS_FAMILY,
            'generators' => BuildIdentity::GENERATORS, 'files' => $hashes, 'symbols' => $symbols, 'replacements' => $replacementMap];
        $sourceHashes = array_map(static fn (string $source): string => hash_file('sha256', $source), $originals);
        $identity = BuildIdentity::digest(['sources' => $sourceHashes, 'generation' => $metadata]);
        return ['sources' => $effective, 'original-sources' => $originals, 'generated' => $generated, 'metadata' => $metadata,
            'identity' => $identity, 'adaptation' => $adaptation, 'models' => $models, 'configuration' => $configuration,
            'routing' => $routing, 'operations' => $operations, 'schemas' => $schemas, 'assembly' => $assembly, 'autoload-inputs' => $audit];
    }

    /** 同一生成过程登记完整字节；输出文件不承载运行秘密。 */
    private function write(string $directory, string $name, string $code, array &$generated): string
    {
        $target = $directory . '/' . $name;
        BuildLock::path($target);
        if (file_put_contents($target, $code, LOCK_EX) !== strlen($code)) {
            throw new RuntimeException('无法完整写入生成源码：' . $name);
        }
        $generated[$name] = $code;
        return $target;
    }

    /** 核对生成后的完整符号表；开发类映射与 AOT 重复声明拒绝使用同一结果。 */
    private function symbols(array $sources, array $references): array
    {
        $symbols = ['classes' => [], 'functions' => []];
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new NodeFinder();
        foreach ($sources as $file) {
            if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }
            $nodes = (new NodeTraverser(new NameResolver()))->traverse($parser->parse((string) file_get_contents($file)) ?? []);
            foreach ($finder->find($nodes, static fn (Node $node): bool => $node instanceof Node\Stmt\ClassLike || $node instanceof Node\Stmt\Function_) as $node) {
                if ($node->name === null) {
                    continue;
                }
                $name = $node->namespacedName?->toString() ?? $node->name->toString();
                $group = $node instanceof Node\Stmt\Function_ ? 'functions' : 'classes';
                $key = strtolower($name);
                if (isset($symbols[$group][$key])) {
                    throw new RuntimeException('生产源码与生成声明存在重复符号：' . $name);
                }
                $symbols[$group][$key] = ['name' => $name, 'file' => $references[$file]];
            }
        }
        ksort($symbols['classes']);
        ksort($symbols['functions']);
        return $symbols;
    }
}
