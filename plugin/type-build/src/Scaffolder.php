<?php

declare(strict_types=1);

namespace Type\Build;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use RuntimeException;

/** 在明确项目内生成可编辑源码；复用 PSR-4、构建声明和既有 Schema/任务装配协议。 */
final class Scaffolder
{
    /**
     * 关联源码与声明完整校验后一次写入；生成文件不覆盖，既有声明按原字节核对并可恢复。
     *
     * @param array<string,string> $options 显式表、路由、迁移版本、依赖类或任务身份。
     * @return array{kind:string, class:string, files:list<string>} 相对于项目根的写入清单。
     * @throws RuntimeException 名称、依赖、路径、覆盖、声明冲突或写入失败。
     */
    public function make(string $configuration, string $kind, string $class, array $options = []): array
    {
        $project = (new BuildProject())->read($configuration);
        return BuildLock::run($project['root'] . '/.type-make.lock', fn (): array => $this->generate($configuration, $kind, $class, $options));
    }

    private function generate(string $configuration, string $kind, string $class, array $options): array
    {
        if (!in_array($kind, ['module', 'model', 'input', 'service', 'controller', 'migration', 'command', 'job', 'task'], true)) {
            throw new RuntimeException('make 类型只接受 module/model/input/service/controller/migration/command/job/task');
        }
        $project = (new BuildProject())->read($configuration);
        $root = $project['root'];
        BuildLock::path($root);
        BuildLock::path($project['file']);
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $settings = $project['settings'];
        $allowed = ['table', 'route', 'role', 'version', 'model', 'service', 'input', 'migration-registry', 'name', 'type', 'interval', 'cron', 'timezone'];
        foreach ($options as $key => $value) {
            if (!in_array($key, $allowed, true) || !is_string($value) || $value === '' || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new RuntimeException('make 选项无效：' . (string) $key);
            }
        }
        $parts = $this->classParts($class);
        $requirements = ['zoujingli/type-core'];
        if (in_array($kind, ['module', 'model', 'migration', 'controller'], true) || ($kind === 'service' && isset($options['model']))) {
            $requirements[] = 'zoujingli/type-orm';
        }
        if (in_array($kind, ['module', 'input', 'controller'], true)) {
            $requirements[] = 'zoujingli/type-validate';
        }
        if (in_array($kind, ['job', 'task'], true)) {
            $requirements[] = $kind === 'job' ? 'zoujingli/type-queue' : 'zoujingli/type-scheduler';
        }
        foreach ($requirements as $package) {
            if (!isset($composer['require'][$package])) {
                throw new RuntimeException('make 需要显式生产依赖：' . $package);
            }
        }
        if (!is_array($settings['application'] ?? null) || !in_array($composer['name'], $settings['application']['enabled'] ?? [], true)) {
            throw new RuntimeException('make 需要以当前 Composer 包名启用的唯一 application 声明');
        }
        $files = [];
        $originals = [];
        $variables = $parts;
        $controller = null;
        if ($kind === 'module') {
            foreach (['table', 'route', 'role', 'version', 'migration-registry'] as $key) {
                $this->required($options, $key);
            }
            $base = $parts['namespace'];
            $name = $parts['name'];
            $classes = ['model' => $base . '\\model\\' . $name, 'input' => $base . '\\input\\' . $name . 'Input',
                'service' => $base . '\\service\\' . $name . 'Service', 'controller' => $base . '\\controller\\' . $name . 'Controller',
                'migration' => $base . '\\database\\Create' . $name];
            $variables += ['model' => $classes['model'], 'modelName' => $name, 'service' => $classes['service'], 'serviceName' => $name . 'Service',
                'input' => $classes['input'], 'inputName' => $name . 'Input'];
            $variables = $this->variables($variables, $options);
            $operations = [['action' => 'create', 'table' => $variables['table'], 'columns' => [
                'id' => ['type' => 'integer', 'auto' => true], 'name' => ['type' => 'string', 'length' => 100],
                'version' => ['type' => 'integer', 'default' => 1]], 'primary' => ['id']]];
            $variables['operations'] = var_export($operations, true);
            foreach ($classes as $type => $target) {
                $path = $this->classFile($root, $composer, $target);
                $files[$path] = $this->render($type, array_replace($variables, $this->classParts($target)));
            }
            $controller = $this->classFile($root, $composer, $classes['controller']);
            $registry = $this->classFile($root, $composer, $options['migration-registry']);
            $originals[$registry] = $this->existing($registry);
            $files[$registry] = $this->appendMigration($originals[$registry], $classes['migration']);
        } else {
            foreach (['model', 'service', 'input'] as $dependency) {
                if (isset($options[$dependency])) {
                    $dependencyParts = $this->classParts($options[$dependency]);
                    $path = $this->classFile($root, $composer, $options[$dependency]);
                    if (!is_file($path)) {
                        throw new RuntimeException('make 依赖源码不存在：' . $options[$dependency]);
                    }
                    $variables[$dependency] = $options[$dependency];
                    $variables[$dependency . 'Name'] = $dependencyParts['name'];
                }
            }
            if (in_array($kind, ['controller', 'command', 'job', 'task'], true)) {
                $this->required($options, 'service');
                if ($kind !== 'controller') {
                    $this->validateService($this->existing($this->classFile($root, $composer, $options['service'])));
                }
            }
            if ($kind === 'controller') {
                foreach (['input', 'route', 'role'] as $key) {
                    $this->required($options, $key);
                }
            }
            if ($kind === 'model') {
                $this->required($options, 'table');
            }
            if ($kind === 'migration') {
                $this->required($options, 'version');
            }
            $variables = $this->variables($variables, $options);
            $path = $this->classFile($root, $composer, $class);
            $files[$path] = $this->render($kind === 'service' && !isset($options['model']) ? 'plain-service' : $kind, $variables);
            if ($kind === 'controller') {
                $controller = $path;
            }
            if ($kind === 'command') {
                $name = $this->required($options, 'name');
                if (!preg_match('/^[a-z][a-z0-9_.:-]*$/D', $name) || in_array($name, ['help', 'check'], true)) {
                    throw new RuntimeException('make command 名称无效');
                }
                $this->append($settings['application'], 'commands', ['name' => $name, 'class' => $class], 'name');
            } elseif ($kind === 'job') {
                $version = $this->positive($this->required($options, 'version'));
                $row = ['type' => $this->required($options, 'type'), 'version' => $version, 'class' => $class];
                $settings['application']['jobs'] = (new JobCompiler())->validate([...($settings['application']['jobs'] ?? []), $row]);
            } elseif ($kind === 'task') {
                if ((isset($options['cron'])) === (isset($options['interval']))) {
                    throw new RuntimeException('make task 必须明确选择 --cron 或 --interval');
                }
                $schedule = isset($options['cron']) ? ['cron' => $options['cron'], 'timezone' => $this->required($options, 'timezone')]
                    : ['interval' => $this->positive($options['interval']), 'anchor' => 0];
                $row = ['id' => $this->required($options, 'name'), 'class' => $class, 'schedule' => $schedule];
                $settings['application']['schedules'] = (new ScheduleCompiler())->validate([...($settings['application']['schedules'] ?? []), $row]);
            }
        }
        if ($controller !== null) {
            if (!is_string($settings['routing'] ?? null)) {
                throw new RuntimeException('make HTTP 需要已有的显式 routing 文件');
            }
            $routing = (new RouteCompiler())->declarations($root, $settings['routing']);
            $path = $this->relativeFile($root, $settings['routing']);
            if (($routing['attributes'] ?? null) !== true) {
                $routing['attributes'] = array_values(array_unique([...($routing['attributes'] ?? []), substr($controller, strlen($root) + 1)]));
                // 原声明默认选择全部 Attribute 时保持同样范围，不移除原控制器。
                if (!isset((new RouteCompiler())->declarations($root, $settings['routing'])['attributes']) && ($routing['routes'] ?? []) === []) {
                    $routing['attributes'] = true;
                }
                $originals[$path] = $this->existing($path);
                $files[$path] = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($routing, true) . ";\n";
            }
        }
        foreach ($files as $path => $source) {
            if (!isset($originals[$path])) {
                $relative = substr($path, strlen($root) + 1);
                $covered = false;
                foreach ($settings['sources'] ?? [] as $selected) {
                    $covered = $covered || $relative === $selected || str_starts_with($relative, rtrim($selected, '/') . '/');
                }
                if (!$covered) {
                    $settings['sources'][] = $relative;
                }
            }
        }
        $originals[$project['file']] = $this->existing($project['file']);
        $files[$project['file']] = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        foreach ($files as $path => $contents) {
            BuildLock::path($path);
            if (!isset($originals[$path]) && (file_exists($path) || is_link($path))) {
                throw new RuntimeException('make 默认拒绝覆盖：' . substr($path, strlen($root) + 1));
            }
            if (str_ends_with($path, '.php')) {
                $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse($contents) ?? [];
                (new \PhpParser\NodeTraverser(new \PhpParser\NodeVisitor\NameResolver()))->traverse($nodes);
            }
        }
        $this->publish($root, $files, $originals, $settings, $composer, $routing ?? null);
        return ['kind' => $kind, 'class' => $class, 'files' => array_map(static fn (string $path): string => substr($path, strlen($root) + 1), array_keys($files))];
    }

    /** 任务模板需要明确的同步业务契约，拒绝留下调用不存在方法的源码。 */
    private function validateService(string $source): void
    {
        $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse($source) ?? [];
        $methods = (new NodeFinder())->find($nodes, static fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod && $node->name->toString() === 'execute');
        if (count($methods) !== 1 || !$methods[0]->isPublic() || $methods[0]->isStatic() || count($methods[0]->params) !== 1
            || (string) $methods[0]->params[0]->type !== 'string' || (string) $methods[0]->returnType !== 'array') {
            throw new RuntimeException('make 任务服务需要 public execute(string $marker): array 契约');
        }
    }

    private function variables(array $variables, array $options): array
    {
        foreach (['table' => '/^[a-z][a-z0-9_]{0,62}$/D', 'version' => '/^[0-9][A-Za-z0-9_.-]{0,63}$/D',
            'route' => '#^/[a-z][a-z0-9_/-]*$#D', 'role' => '/^[a-z][a-z0-9_.:-]*$/D'] as $key => $pattern) {
            if (isset($options[$key]) && !preg_match($pattern, $options[$key])) {
                throw new RuntimeException('make ' . $key . ' 无效');
            }
        }
        return $variables + ['table' => $options['table'] ?? '', 'version' => $options['version'] ?? '', 'route' => $options['route'] ?? '',
            'routeName' => str_replace('/', '.', trim($options['route'] ?? '', '/')), 'role' => $options['role'] ?? '',
            'description' => '创建' . ($options['table'] ?? $variables['name']), 'operations' => '[]'];
    }

    private function render(string $kind, array $variables): string
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/resources/scaffolds/' . $kind . '.stub');
        $replacements = [];
        foreach ($variables as $key => $value) {
            $replacements['{{' . $key . '}}'] = $value;
        }
        $source = strtr($source, $replacements);
        if (str_contains($source, '{{')) {
            throw new RuntimeException('make 模板缺少显式关联类型');
        }
        return $source;
    }

    private function classParts(string $class): array
    {
        if (!preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+[A-Z][A-Za-z0-9_]*$/D', $class)) {
            throw new RuntimeException('make 类名必须是有效命名空间和大写开头的具名类');
        }
        $parts = explode('\\', $class);
        $name = array_pop($parts);
        return ['namespace' => implode('\\', $parts), 'name' => $name];
    }

    private function classFile(string $root, array $composer, string $class): string
    {
        $this->classParts($class);
        $mappings = $composer['autoload']['psr-4'] ?? [];
        uksort($mappings, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
        foreach ($mappings as $prefix => $directory) {
            if ($prefix !== '' && str_starts_with($class, $prefix)) {
                if (!is_string($directory)) {
                    throw new RuntimeException('make 需要唯一目录的 PSR-4 映射');
                }
                return $this->relativeFile($root, rtrim($directory, '/') . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php');
            }
        }
        throw new RuntimeException('make 类没有当前项目 PSR-4 映射：' . $class);
    }

    private function relativeFile(string $root, string $relative): string
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $relative) || $relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\') || str_contains($relative, ':')) {
            throw new RuntimeException('make 目标必须是项目内规范相对路径');
        }
        $path = $root . '/' . $relative;
        BuildLock::path($path);
        if (!BuildPlatform::contains($root, $path)) {
            throw new RuntimeException('make 目标越界');
        }
        return $path;
    }

    private function required(array $options, string $key): string
    {
        return $options[$key] ?? throw new RuntimeException('make 缺少 --' . $key . '=值');
    }

    private function positive(string $value): int
    {
        if (!preg_match('/^[1-9][0-9]{0,8}$/D', $value)) {
            throw new RuntimeException('make 版本或间隔必须是有界正整数');
        }
        return (int) $value;
    }

    private function append(array &$application, string $collection, array $row, string $key): void
    {
        foreach ($application[$collection] ?? [] as $existing) {
            if (($existing[$key] ?? null) === $row[$key]) {
                throw new RuntimeException('make 声明身份已存在：' . $row[$key]);
            }
        }
        $application[$collection][] = $row;
    }

    private function existing(string $file): string
    {
        BuildLock::path($file);
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException('make 关联声明不是可读的普通文件');
        }
        return (string) file_get_contents($file);
    }

    /** 只在明确 migrations 方法的唯一直接数组 return 中追加，不重写历史 SQL 或迁移字节。 */
    private function appendMigration(string $source, string $class): string
    {
        $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse($source) ?? [];
        $methods = (new NodeFinder())->find($nodes, static fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod && $node->name->toString() === 'migrations');
        if (count($methods) !== 1 || !$methods[0]->isPublic() || !$methods[0]->isStatic()) {
            throw new RuntimeException('make migration-registry 需要唯一 public static migrations 方法');
        }
        $returns = array_values(array_filter($methods[0]->stmts ?? [], static fn (Node $node): bool => $node instanceof Node\Stmt\Return_ && $node->expr instanceof Node\Expr\Array_));
        if (count($returns) !== 1 || count($methods[0]->params) !== 1 || $methods[0]->params[0]->var->name !== 'driver'
            || (string) $methods[0]->params[0]->type !== 'string' || (string) $methods[0]->returnType !== 'array') {
            throw new RuntimeException('make 迁移登记需要明确 migrations(string $driver) 的直接数组 return');
        }
        $array = $returns[0]->expr;
        $position = $array->getEndFilePos();
        if ($source[$position] !== ']') {
            throw new RuntimeException('make 迁移登记只接受短数组，不能猜测其他表达式');
        }
        $last = $array->items === [] ? null : end($array->items);
        $comma = $last === null || str_contains(substr($source, $last->getEndFilePos() + 1, $position - $last->getEndFilePos() - 1), ',') ? '' : ',';
        return substr($source, 0, $position) . $comma . '\\' . $class . '::migration($driver)' . substr($source, $position);
    }

    /** 完整暂存并在每次发布前复核路径和原字节；失败精确回滚本轮写入。 */
    private function publish(string $root, array $files, array $originals, array $settings, array $composer, ?array $routing): void
    {
        $stage = $root . '/.type-make-' . bin2hex(random_bytes(8));
        BuildLock::path($stage);
        if (!mkdir($stage, 0700)) {
            throw new RuntimeException('make 无法建立暂存目录');
        }
        $published = [];
        $directories = [];
        $preserve = false;
        try {
            foreach ($files as $path => $contents) {
                $parent = dirname($path);
                while (!is_dir($parent)) {
                    $parent = dirname($parent);
                }
                if (!is_writable($parent) || (isset($originals[$path]) && !is_writable($path))) {
                    throw new RuntimeException('make 目标不可写');
                }
                if (isset($originals[$path]) && file_put_contents($stage . '/' . hash('sha256', $path) . '.backup', $originals[$path], LOCK_EX) !== strlen($originals[$path])) {
                    throw new RuntimeException('make 无法暂存恢复副本');
                }
                $temporary = $stage . '/' . hash('sha256', $path) . '.php';
                if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
                    throw new RuntimeException('make 无法完整暂存源码');
                }
            }
            $dependencies = (new SourceSet())->productionSources($root, $settings);
            $selected = [];
            foreach ($settings['sources'] ?? [] as $source) {
                $source = $this->relativeFile($root, $source);
                if (file_exists($source)) {
                    $selected[] = $source;
                }
            }
            $sources = array_values(array_diff((new BuildIdentity())->sources([...$selected, ...$dependencies['sources']]), array_keys($files)));
            $sources = array_values(array_filter($sources, static fn (string $source): bool => !str_starts_with($source, $stage . '/')));
            foreach ($files as $path => $contents) {
                if (str_ends_with($path, '.php')) {
                    $sources[] = $stage . '/' . hash('sha256', $path) . '.php';
                }
            }
            $routeCompiler = new RouteCompiler();
            $routing ??= $routeCompiler->declarations($root, $settings['routing'] ?? []);
            if (is_array($routing['attributes'] ?? null)) {
                foreach ($routing['attributes'] as &$attribute) {
                    $absolute = $root . '/' . $attribute;
                    if (isset($files[$absolute])) {
                        $attribute = basename($stage) . '/' . hash('sha256', $absolute) . '.php';
                    }
                }
                unset($attribute);
            }
            $routes = $routing === [] ? [] : $routeCompiler->generate($root, $routing, $sources);
            $modules = $dependencies['modules'];
            $modules[$composer['name']] = $settings['application'];
            (new CommandAssembly())->generate($settings['application'], $modules, $sources, $routes);
            foreach ($files as $path => $contents) {
                BuildLock::path($path);
                if (isset($originals[$path])) {
                    if ($this->existing($path) !== $originals[$path]) {
                        throw new RuntimeException('make 关联声明已变化，拒绝覆盖');
                    }
                } elseif (file_exists($path) || is_link($path)) {
                    throw new RuntimeException('make 目标已存在，拒绝覆盖');
                }
                $missing = [];
                $parent = dirname($path);
                while (!is_dir($parent)) {
                    $missing[] = $parent;
                    $parent = dirname($parent);
                }
                foreach (array_reverse($missing) as $directory) {
                    if (!mkdir($directory, 0755)) {
                        throw new RuntimeException('make 无法建立源码目录');
                    }
                    $directories[] = $directory;
                }
                $temporary = $stage . '/' . hash('sha256', $path) . '.php';
                // 新源码使用排他硬链接发布，避免预检查与 rename 之间覆盖并发创建文件。
                $written = isset($originals[$path]) ? rename($temporary, $path) : link($temporary, $path);
                if (!$written) {
                    throw new RuntimeException('make 发布文件失败');
                }
                $published[] = $path;
            }
        } catch (\Throwable $error) {
            foreach (array_reverse($published) as $path) {
                if (!is_file($path) || file_get_contents($path) !== $files[$path]) {
                    $preserve = true;
                    continue;
                }
                $restored = isset($originals[$path])
                    ? rename($stage . '/' . hash('sha256', $path) . '.backup', $path) : unlink($path);
                $preserve = $preserve || !$restored;
            }
            foreach (array_reverse($directories) as $directory) {
                if (is_dir($directory) && count(scandir($directory)) === 2) {
                    rmdir($directory);
                }
            }
            if ($preserve) {
                throw new RuntimeException('make 写入失败且目标已变化；恢复副本保留于 ' . basename($stage), 0, $error);
            }
            throw $error;
        } finally {
            if (!$preserve) {
                foreach (glob($stage . '/*') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($stage);
            }
        }
    }
}
