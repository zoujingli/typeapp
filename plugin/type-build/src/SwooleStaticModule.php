<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/**
 * 按已选 configure 开关键接一份官方 Swoole 目标文件，并生成进程内模块登记源码。
 *
 * 编译机上的超集共享模块不进入发布包。产品只拿这里产出的 .o/.obj。
 */
final class SwooleStaticModule
{
    private BuildEnvironment $runner;

    private SwooleFeatureSelection $selection;

    public function __construct(?BuildEnvironment $runner = null, ?SwooleFeatureSelection $selection = null)
    {
        $this->runner = $runner ?? new BuildEnvironment();
        $this->selection = $selection ?? new SwooleFeatureSelection();
    }

    /**
     * @param list<string> $classes
     * @param list<string> $functions
     * @return array{objects:list<string>, libraries:list<string>, registrar:string, flags:list<string>, curl:bool, modules-before-swoole:list<string>}
     * @throws RuntimeException 请求、源码、符号或运行声明无法组成一次静态链接。
     */
    public function link(string $requestFile, array $classes, array $functions): array
    {
        $request = $this->request($requestFile);
        $flags = $this->selection->select($classes, $functions, $request['runtime-extensions'], true);
        $this->selection->assertRuntimeCoverage($flags, $request['runtime-extensions']);
        $before = $this->selection->sharedModulesBeforeSwoole($flags, $request['module-files']);
        $built = $this->build($request, $flags);
        $curl = false;
        foreach ($built['objects'] as $object) {
            if (SwooleFeatureSelection::referencesUndefinedSymbol($this->symbols($object, $request), 'curl_multi_ce')) {
                $curl = true;
                break;
            }
        }
        if ($curl) {
            if (!isset($request['module-files']['curl'])) {
                throw new RuntimeException('静态 Swoole 引用了 curl_multi_ce，但没有同 ABI 的 curl 模块');
            }
            $before[] = 'curl';
        }
        $internalize = [];
        foreach ([...$before, 'swoole'] as $name) {
            if (in_array($name, $request['dynamic-extensions'], true)) {
                $internalize[] = $name;
            }
        }
        $registrar = $request['cache'] . '/type-app-static-modules.cc';
        $this->writeRegistrar($registrar, $before);
        $this->writeProjectObjects($request['project'], $built['objects']);
        $moduleFiles = [];
        foreach ($before as $name) {
            $moduleFiles[] = $request['module-files'][$name];
        }
        $libraries = [...$this->moduleTokens($moduleFiles), ...$built['libraries']];
        $objects = [];
        foreach ($built['objects'] as $path) {
            $objects[] = ['path' => $path, 'sha256' => hash_file('sha256', $path)];
        }
        $result = ['protocol' => 1, 'source' => SwooleFeatureSelection::SOURCE, 'flags' => $flags, 'objects' => $objects,
            'curl' => $curl, 'modules-before-swoole' => $before, 'internalize' => $internalize, 'libraries' => $built['libraries']];
        $this->writeJson($request['result'], $result);

        return ['objects' => $built['objects'], 'libraries' => $libraries, 'registrar' => $registrar,
            'flags' => $flags, 'curl' => $curl, 'modules-before-swoole' => $before];
    }

    /**
     * @param array{runtime-extensions:list<string>, module-files:array<string, string>, project:string, cache:string, php-home:string, phpx-home:string, root:string, native-ini:string, result:string, dynamic-extensions:list<string>, source?:string} $request
     * @param list<string> $flags
     * @return array{objects:list<string>, libraries:list<string>}
     */
    private function build(array $request, array $flags): array
    {
        $key = hash('sha256', json_encode(['source' => SwooleFeatureSelection::SOURCE, 'flags' => $flags,
            'php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'arch' => php_uname('m')], JSON_THROW_ON_ERROR));
        $directory = $request['cache'] . '/' . $key;
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建 Swoole 静态构建目录');
        }
        $manifestFile = $directory . '/manifest.json';
        $manifest = $this->reusable($manifestFile, $flags);
        if ($manifest === null) {
            if (PHP_OS_FAMILY === 'Windows') {
                throw new RuntimeException('Windows 产品静态 Swoole 需要已有 .obj 清单；当前构建没有这份清单');
            }
            $environment = (new BuildPlatform())->environment($request['php-home'], $request['phpx-home']);
            $environment['TYPE_APP_ROOT'] = $request['root'];
            $environment['TYPE_SWOOLE_ARTIFACT'] = 'static';
            $environment['TYPE_SWOOLE_STATIC_DIR'] = $directory;
            $environment['TYPE_SWOOLE_LOG'] = $directory . '/build.log';
            $environment['SWOOLE_CONFIGURE_OPTS'] = implode(' ', $flags);
            $environment['RUNNER_TEMP'] = $request['cache'] . '/source';
            if (isset($request['source'])) {
                $environment['TYPE_SWOOLE_SOURCE'] = $request['source'];
            }
            if (!is_dir($environment['RUNNER_TEMP']) && !mkdir($environment['RUNNER_TEMP'], 0700, true) && !is_dir($environment['RUNNER_TEMP'])) {
                throw new RuntimeException('无法创建 Swoole 源码目录');
            }
            $produced = trim($this->runner->run(['bash', $this->script($request['root'])], $request['root'], $environment, 1800));
            if ($produced !== $manifestFile || !is_file($manifestFile)) {
                throw new RuntimeException('Swoole 静态构建没有返回清单');
            }
            $manifest = $this->reusable($manifestFile, $flags);
            if ($manifest === null) {
                $decoded = json_decode((string) file_get_contents($manifestFile), true);
                if (!is_array($decoded)) {
                    throw new RuntimeException('Swoole 静态清单无效');
                }
                $decoded['libraries'] = $this->nativeLibraries((string) ($decoded['shared'] ?? ''), $environment);
                $this->writeJson($manifestFile, $decoded);
                $manifest = $this->reusable($manifestFile, $flags);
            }
        }
        if ($manifest === null) {
            throw new RuntimeException('Swoole 静态清单无效');
        }

        return ['objects' => $manifest['objects'], 'libraries' => $manifest['libraries']];
    }

    /** @param list<string> $flags @return array{objects:list<string>, libraries:list<string>}|null */
    private function reusable(string $manifest, array $flags): ?array
    {
        if (!is_file($manifest)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($manifest), true);
        if (!is_array($data) || ($data['flags'] ?? null) !== $flags || ($data['source'] ?? null) !== SwooleFeatureSelection::SOURCE
            || !is_array($data['objects'] ?? null) || $data['objects'] === [] || !is_array($data['libraries'] ?? null)) {
            return null;
        }
        $objects = [];
        foreach ($data['objects'] as $object) {
            if (!is_string($object) || !is_file($object) || !in_array(strtolower(pathinfo($object, PATHINFO_EXTENSION)), ['o', 'obj'], true)) {
                return null;
            }
            $objects[] = $object;
        }
        $libraries = [];
        foreach ($data['libraries'] as $library) {
            if (!is_string($library) || !is_file($library)) {
                return null;
            }
            $libraries[] = $library;
        }

        return ['objects' => $objects, 'libraries' => $libraries];
    }

    /** @param array<string, mixed> $request @return array{runtime-extensions:list<string>, module-files:array<string, string>, project:string, cache:string, php-home:string, phpx-home:string, root:string, native-ini:string, result:string, dynamic-extensions:list<string>, source?:string} */
    private function request(string $file): array
    {
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            throw new RuntimeException('Swoole 静态链接请求无效');
        }
        foreach (['runtime-extensions', 'dynamic-extensions'] as $key) {
            if (!is_array($data[$key] ?? null)) {
                throw new RuntimeException('Swoole 静态链接请求无效');
            }
            foreach ($data[$key] as $name) {
                if (!is_string($name) || $name === '') {
                    throw new RuntimeException('Swoole 静态链接请求无效');
                }
            }
        }
        if (!is_array($data['module-files'] ?? null)) {
            throw new RuntimeException('Swoole 静态链接请求无效');
        }
        $modules = [];
        foreach ($data['module-files'] as $name => $path) {
            if (!is_string($name) || !is_string($path) || !is_file($path)) {
                throw new RuntimeException('Swoole 静态链接请求无效');
            }
            $modules[$name] = $path;
        }
        foreach (['project', 'cache', 'php-home', 'phpx-home', 'root', 'native-ini', 'result'] as $key) {
            if (!is_string($data[$key] ?? null) || $data[$key] === '') {
                throw new RuntimeException('Swoole 静态链接请求无效');
            }
        }
        if (isset($data['source']) && (!is_string($data['source']) || !is_file($data['source'] . '/config.m4'))) {
            throw new RuntimeException('Swoole 静态链接请求无效');
        }
        $data['module-files'] = $modules;

        return $data;
    }

    private function script(string $root): string
    {
        $candidates = [$root . '/tools/prepare-swoole-module.sh', dirname(__DIR__, 3) . '/tools/prepare-swoole-module.sh'];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        throw new RuntimeException('缺少 Swoole 静态构建脚本');
    }

    /** @param array<string, mixed> $request */
    private function symbols(string $object, array $request): string
    {
        $nm = is_file('/usr/bin/nm') ? '/usr/bin/nm' : 'nm';
        $environment = (new BuildPlatform())->environment($request['php-home'], $request['phpx-home']);

        return $this->runner->run([$nm, '-u', $object], dirname($object), $environment, 30);
    }

    /** @return list<string> */
    private function nativeLibraries(string $shared, array $environment): array
    {
        if ($shared === '' || !is_file($shared)) {
            return [];
        }
        if (PHP_OS_FAMILY === 'Darwin') {
            $output = $this->runner->run(['/usr/bin/otool', '-L', $shared], dirname($shared), $environment, 30);
        } else {
            $output = $this->runner->run(['/usr/bin/ldd', $shared], dirname($shared), $environment, 30);
            if (str_contains($output, 'not found')) {
                throw new RuntimeException('静态 Swoole 缺少传递依赖');
            }
        }
        $libraries = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('~=>\s*(/\S+)~', $line, $match) !== 1 && preg_match('~^\s*(/\S+)~', $line, $match) !== 1) {
                continue;
            }
            $path = $match[1];
            if (!is_file($path) || $this->ignoredLibrary($path) || realpath($path) === realpath($shared)) {
                continue;
            }
            $libraries[] = $path;
        }

        return array_values(array_unique($libraries));
    }

    private function ignoredLibrary(string $path): bool
    {
        $base = strtolower(basename($path));
        foreach (['linux-vdso', 'ld-linux', 'libc.so', 'libm.so', 'libdl.so', 'libpthread.so', 'librt.so', 'libgcc_s.so', 'libstdc++.so', 'libphp.so', 'libsystem.'] as $prefix) {
            if (str_starts_with($base, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $files @return list<string> */
    private function moduleTokens(array $files): array
    {
        $tokens = [];
        $directories = [];
        foreach ($files as $file) {
            if (!is_file($file)) {
                throw new RuntimeException('静态登记需要同 ABI 的共享模块');
            }
            $directory = dirname($file);
            if (!isset($directories[$directory])) {
                $directories[$directory] = true;
                $tokens[] = PHP_OS_FAMILY === 'Darwin' ? '-Wl,-rpath,' . $directory : '-Wl,--enable-new-dtags,-rpath,' . $directory;
                if (PHP_OS_FAMILY !== 'Darwin') {
                    $tokens[] = '-L' . $directory;
                }
            }
            // GNU ld 用 -l: 保留 SONAME。Apple ld 不接受这个写法，直接交给绝对路径。
            $tokens[] = PHP_OS_FAMILY === 'Darwin' ? $file : '-l:' . basename($file);
        }

        return $tokens;
    }

    /** @param list<string> $modules */
    private function writeRegistrar(string $path, array $modules): void
    {
        $entries = ['pdo' => 'pdo_module_entry', 'pdo_pgsql' => 'pdo_pgsql_module_entry', 'pdo_sqlite' => 'pdo_sqlite_module_entry',
            'curl' => 'curl_module_entry', 'swoole' => 'swoole_module_entry'];
        if (!in_array('swoole', $modules, true)) {
            $modules[] = 'swoole';
        }
        $externs = '';
        $calls = '';
        foreach ($modules as $name) {
            if (!isset($entries[$name])) {
                throw new RuntimeException('没有可登记的模块入口：' . $name);
            }
            $symbol = $entries[$name];
            $externs .= 'extern zend_module_entry ' . $symbol . ";\n";
            $calls .= '    if (type_app_register_one("' . $name . '", sizeof("' . $name . '") - 1, &' . $symbol . ") != SUCCESS) {\n        return FAILURE;\n    }\n";
        }
        $source = "extern \"C\" {\n#include <php.h>\n}\n\n" . $externs . "\nstatic int type_app_register_one(const char *name, size_t length, zend_module_entry *entry)\n{\n"
            . "    if (zend_hash_str_exists(&module_registry, name, length)) {\n        return SUCCESS;\n    }\n"
            . "    return zend_register_internal_module(entry) == nullptr ? FAILURE : SUCCESS;\n}\n\n"
            . "extern \"C\" int type_app_register_static_modules(void)\n{\n" . $calls . "    return SUCCESS;\n}\n";
        $this->writeText($path, $source);
    }

    /** @param list<string> $objects */
    private function writeProjectObjects(string $project, array $objects): void
    {
        $data = json_decode((string) file_get_contents($project), true);
        if (!is_array($data) || $objects === []) {
            throw new RuntimeException('无法把 Swoole 目标写入项目配置');
        }
        $cacheRoot = dirname(dirname($objects[0]));
        $existing = [];
        foreach ($data['objects'] ?? [] as $object) {
            if (is_string($object) && $object !== '' && !str_starts_with($object, $cacheRoot . '/')) {
                $existing[] = $object;
            }
        }
        $data['objects'] = array_values(array_unique([...$existing, ...$objects]));
        $this->writeJson($project, $data);
    }

    /** @param array<string, mixed> $value */
    private function writeJson(string $file, array $value): void
    {
        $this->writeText($file, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }

    private function writeText(string $file, string $text): void
    {
        $directory = dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建 Swoole 静态构建目录');
        }
        if (file_put_contents($file, $text, LOCK_EX) !== strlen($text)) {
            throw new RuntimeException('无法写入 Swoole 静态构建文件');
        }
    }
}
