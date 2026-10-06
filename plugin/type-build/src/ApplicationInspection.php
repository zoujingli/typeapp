<?php

declare(strict_types=1);

namespace Type\Build;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/** 复用构建分析的离线应用检查；报告只选择声明身份，不包含配置值或业务源码。 */
final class ApplicationInspection
{
    /**
     * 在私有临时目录运行与 PHP/AOT 相同的生成分析，所有临时字节在退出时回收。
     *
     * @return array<string, mixed> 协议 1 的声明报告；不是编译或部署成功证明。
     * @throws RuntimeException 项目输入、声明、类型或依赖不符合构建契约。
     */
    public function inspect(string $configuration): array
    {
        $project = (new BuildProject())->read($configuration);
        $root = $project['root'];
        $settings = $project['settings'];
        $sources = [];
        $selected = [...($settings['sources'] ?? []), ...(isset($settings['entry']) ? [$settings['entry']] : [])];
        foreach ($selected as $source) {
            if (!is_string($source) || str_contains($source, "\0")) {
                throw new RuntimeException('应用检查需要有效的源码路径');
            }
            $file = BuildPlatform::resolve($root . '/' . $source);
            if (!BuildPlatform::contains($root, $file)) {
                throw new RuntimeException('应用检查源码必须属于项目');
            }
            $sources[] = $file;
        }
        $production = (new SourceSet())->productionSources($root, $settings);
        $sources = array_values(array_unique([...$sources, ...$production['sources']]));
        $build = $root . '/build';
        BuildLock::path($build);
        if (!is_dir($build) && !mkdir($build, 0700, true) && !is_dir($build)) {
            throw new RuntimeException('无法建立离线检查工作目录');
        }
        $directory = $build . '/.application-inspection-' . bin2hex(random_bytes(12));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('无法建立离线检查临时目录');
        }
        try {
            $result = (new ApplicationGeneration())->generate(
                $root,
                $settings,
                $sources,
                $production['source-sets'],
                $production['modules'],
                $production['included'],
                $directory
            );
            $models = [];
            foreach ($result['models']['models'] ?? [] as $model) {
                $models[] = array_intersect_key($model, array_flip(['class', 'table', 'database', 'primary']));
            }
            $routes = [];
            foreach ($result['routing']['routes'] ?? [] as $route) {
                $routes[] = array_intersect_key($route, array_flip(['methods', 'path', 'name', 'handler', 'middleware']));
            }
            $assembly = $result['assembly'];
            unset($assembly['code']);
            return ['protocol' => 1, 'kind' => 'application-declarations', 'status' => 'valid',
                'generation' => $result['identity'], 'packages' => $production['included'],
                'configuration' => ['class' => $result['configuration']['class'] ?? null,
                    'files' => $settings['config']['files'] ?? [], 'application-keys' => array_keys($settings['application']['config'] ?? [])],
                'routes' => $routes, 'models' => $models, 'schemas' => $result['schemas']['schemas'] ?? [], 'assembly' => $assembly,
                'jobs' => $assembly['jobs'] ?? [], 'schedules' => $assembly['schedules'] ?? [],
                'capabilities' => $this->capabilities($settings)];
        } finally {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                if ($entry->isDir() && !$entry->isLink()) {
                    rmdir($entry->getPathname());
                } else {
                    unlink($entry->getPathname());
                }
            }
            rmdir($directory);
        }
    }

    /** 人读输出仍只消费上述公开报告，不另行发现源码或服务。 */
    public function describe(array $report): string
    {
        $lines = ['应用声明检查通过（离线；未执行构造器、工厂或角色）。', '生成身份：' . $report['generation'],
            '命令：' . implode('、', $report['assembly']['commands'] ?? []), '路由：' . count($report['routes']),
            'Model：' . count($report['models']), 'Job：' . count($report['jobs']), '计划：' . count($report['schedules'])];
        foreach ($report['assembly']['services'] ?? [] as $id => $service) {
            $lines[] = $id . ' [' . $service['lifetime'] . '] ' . $service['class'] . ' <- ' . $service['origin']
                . ($service['overrides'] === null ? '' : '，覆盖 ' . $service['overrides'])
                . ($service['dependencies'] === [] ? '' : '，依赖 ' . implode(', ', $service['dependencies']));
        }
        foreach ($report['assembly']['bindings'] ?? [] as $type => $binding) {
            $lines[] = '绑定 ' . $type . ' [' . $binding['kind'] . '] <- ' . $binding['origin']
                . ($binding['overrides'] === null ? '' : '，覆盖 ' . $binding['overrides']);
        }
        foreach ($report['assembly']['events'] ?? [] as $event) {
            $listeners = array_map(static fn (array $listener): string => $listener['service'] . '::' . $listener['method'], $event['listeners']);
            $lines[] = '事件 ' . $event['class'] . ' -> ' . ($listeners === [] ? '无监听' : implode('、', $listeners));
        }
        foreach ($report['routes'] as $route) {
            $lines[] = implode('|', $route['methods']) . ' ' . $route['path'] . ' -> ' . implode('::', $route['handler'])
                . ($route['middleware'] === [] ? '' : '，中间件 ' . implode(', ', $route['middleware']));
        }
        foreach ($report['models'] as $model) {
            $lines[] = 'Model ' . $model['class'] . ' -> ' . $model['database'] . ':' . $model['table'];
        }
        foreach ($report['schemas'] ?? [] as $schema) {
            $lines[] = 'Schema ' . $schema['class'] . ' [' . $schema['version'] . '] ' . $schema['sha256'];
        }
        foreach ($report['jobs'] as $job) {
            $lines[] = 'Job ' . $job['type'] . ':' . $job['version'] . ' -> ' . $job['class'] . ' [' . $job['service'] . ']';
        }
        foreach ($report['schedules'] as $schedule) {
            $lines[] = '计划 ' . $schedule['id'] . ' -> ' . $schedule['class'] . ' [' . $schedule['service'] . ']';
        }
        foreach ($report['capabilities'] as $kind => $names) {
            $lines[] = '能力 ' . $kind . '：' . implode(', ', $names);
        }
        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /** 运行能力只报告已声明的名称；任意自定义值不得借检查入口泄露。 */
    private function capabilities(array $settings): array
    {
        $result = [];
        foreach ($settings['capabilities'] ?? [] as $kind => $values) {
            $result[$kind] = is_array($values) ? array_keys($values) : [];
        }
        return $result;
    }
}
