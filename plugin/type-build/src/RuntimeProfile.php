<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 真实embed依赖模块：声明、内置/共享选择、严格加载探针与身份输入集中在同一接口。 */
final class RuntimeProfile
{
    private BuildEnvironment $runner;

    public function __construct(?BuildEnvironment $runner = null)
    {
        $this->runner = $runner ?? new BuildEnvironment();
    }

    /**
     * @param list<string> $requirements Composer生产依赖要求的扩展。
     * @param array<string,array> $platforms 以Linux/Darwin/Windows为键的运行声明；非当前平台不访问文件。
     * @return array{extensions:array<string,string|false>, functions:list<string>, 'module-files':array<string,string>, 'module-sha256':array<string,string>, files:list<string>, ini:string, probe:string}
     * @throws RuntimeException 声明/摘要无效，真实embed缺扩展/函数、ABI不符或产生启动警告。
     */
    public function prepare(string $root, string $directory, string $phpHome, string $phpxHome, array $requirements, array $platforms = []): array
    {
        $root = BuildPlatform::resolve($root);
        if ($phpHome === '' || $phpxHome === '') {
            throw new RuntimeException('真实embed探针需要明确的PHP_HOME和PHPX_HOME');
        }
        if (array_diff(array_keys($platforms), ['Linux', 'Darwin', 'Windows']) !== []) {
            throw new RuntimeException('runtime只接受明确的Linux/Darwin/Windows平台声明');
        }
        foreach ($platforms as $declaration) {
            if (!is_array($declaration) || array_diff(array_keys($declaration), ['extensions', 'functions', 'modules']) !== []) {
                throw new RuntimeException('runtime平台声明只接受extensions/functions/modules');
            }
        }
        $selected = $platforms[PHP_OS_FAMILY] ?? [];
        $extra = $this->names($selected['extensions'] ?? [], false);
        $functions = $this->names($selected['functions'] ?? [], true);
        $fallbacks = $selected['modules'] ?? [];
        if (!is_array($fallbacks) || ($fallbacks !== [] && array_is_list($fallbacks)) || count($fallbacks) > 128) {
            throw new RuntimeException('runtime.modules必须是按扩展名登记的有限映射');
        }
        foreach ($fallbacks as $name => $fallback) {
            if (!is_string($name) || $this->names([$name], false) !== [$name] || !is_array($fallback)
                || array_diff(array_keys($fallback), ['file', 'sha256']) !== [] || !is_string($fallback['file'] ?? null)
                || !is_string($fallback['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $fallback['sha256']) !== 1) {
                throw new RuntimeException('运行扩展候选需要规范名称、文件与准确SHA256');
            }
        }
        $required = $this->names(array_values(array_unique(array_merge($requirements, $extra, array_keys($fallbacks)))), false);
        BuildLock::path($directory);
        $leaf = basename($directory);
        $directory = BuildPlatform::resolve(dirname($directory)) . '/' . $leaf;
        if ($leaf === '.' || $leaf === '..' || $leaf === '' || !BuildPlatform::contains($root . '/build', $directory)) {
            throw new RuntimeException('运行探针必须位于项目独立build目录');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new RuntimeException('无法创建原生运行探针目录');
        }
        $directory = BuildPlatform::resolve($directory);
        if (!BuildPlatform::contains($root, $directory) || !BuildPlatform::contains($root . '/build', $directory)) {
            throw new RuntimeException('运行探针必须位于项目独立build目录');
        }
        $scan = $directory . '/php.d';
        BuildLock::path($scan);
        if (!is_dir($scan) && !mkdir($scan, 0700)) {
            throw new RuntimeException('无法建立空INI扫描目录');
        }
        if (array_values(array_diff(scandir($scan), ['.', '..'])) !== []) {
            throw new RuntimeException('原生探针INI扫描目录必须为空');
        }
        $environment = (new BuildPlatform())->environment($phpHome, $phpxHome);
        $source = __DIR__ . '/Native/embed-probe.c';
        $probe = (new BuildPlatform())->output($directory . '/embed-probe');
        $extensionDirectory = $this->compile($source, $probe, $directory, $phpHome, $environment);
        $baseIni = $directory . '/base.ini';
        $this->write($baseIni, (new RuntimeIni())->generate([]));
        $base = $this->probe($probe, $baseIni, $scan, $directory, $environment, $functions);
        $modules = [];
        $hashes = [];
        foreach ($required as $name) {
            if (array_key_exists($name, $base['extensions'])) {
                continue;
            }
            if (isset($fallbacks[$name])) {
                $input = $fallbacks[$name]['file'];
                $absolute = (new BuildPlatform())->absolute($input) ? $input : $root . '/' . $input;
                BuildLock::path($absolute);
                $file = BuildPlatform::resolve($absolute);
                if (!hash_equals($fallbacks[$name]['sha256'], (string) hash_file('sha256', $file))) {
                    throw new RuntimeException('运行扩展候选摘要不一致：' . $name);
                }
            } else {
                $candidate = $extensionDirectory . '/' . (PHP_OS_FAMILY === 'Windows' ? 'php_' . $name . '.dll' : $name . '.so');
                if (!is_file($candidate)) {
                    throw new RuntimeException('实际embed缺少运行扩展：' . $name . '；请提供匹配SDK模块或runtime.modules候选');
                }
                $file = BuildPlatform::resolve($candidate);
            }
            if (!is_file($file) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._+\-]*$/D', basename($file)) !== 1
                || BuildPlatform::format($file) !== (PHP_OS_FAMILY === 'Windows' ? 'PE' : (PHP_OS_FAMILY === 'Darwin' ? 'Mach-O' : 'ELF'))) {
                throw new RuntimeException('运行扩展不是同平台的有效原生模块：' . $name);
            }
            $modules[$name] = $file;
            $hashes[$name] = (string) hash_file('sha256', $file);
        }
        ksort($modules);
        ksort($hashes);
        $ini = $directory . '/native.ini';
        $this->write($ini, (new RuntimeIni())->generate($modules));
        $actual = $this->probe($probe, $ini, $scan, $directory, $environment, $functions);
        $versions = [];
        foreach ($required as $name) {
            if (!array_key_exists($name, $actual['extensions'])) {
                throw new RuntimeException('模块加载后真实embed仍缺少扩展：' . $name);
            }
            $versions[$name] = $actual['extensions'][$name];
        }
        foreach ($functions as $function) {
            if (($actual['functions'][$function] ?? false) !== true) {
                throw new RuntimeException('实际embed缺少运行函数：' . $function);
            }
        }
        foreach ($modules as $name => $file) {
            if (!hash_equals($hashes[$name], (string) hash_file('sha256', $file))) {
                throw new RuntimeException('探测期间运行模块发生变化：' . $name);
            }
        }
        $evidence = $directory . '/profile.json';
        $this->write($evidence, json_encode(['protocol' => 1, 'platform' => PHP_OS_FAMILY, 'php' => $actual['php'], 'zts' => $actual['zts'],
            'extensions' => $versions, 'functions' => $functions, 'modules' => $modules, 'module-sha256' => $hashes,
            'base-extensions' => $base['extensions']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        return ['extensions' => $versions, 'functions' => $functions, 'module-files' => $modules, 'module-sha256' => $hashes,
            'files' => [$source, $probe, $baseIni, $ini, $evidence, ...array_values($modules)], 'ini' => $ini, 'probe' => $probe];
    }

    private function names(mixed $values, bool $functions): array
    {
        if (!is_array($values) || !array_is_list($values) || count($values) > 128) {
            throw new RuntimeException('运行扩展/函数必须是最多128项的名称列表');
        }
        $result = [];
        foreach ($values as $value) {
            $pattern = $functions ? '/^[a-z_][a-z0-9_\\\\]{0,127}$/iD' : '/^[a-z_][a-z0-9_]{0,127}$/iD';
            if (!is_string($value) || preg_match($pattern, $value) !== 1) {
                throw new RuntimeException('运行扩展或函数名称无效');
            }
            $result[] = strtolower($value);
        }
        $result = array_values(array_unique($result));
        sort($result);
        return $result;
    }

    private function compile(string $source, string $probe, string $directory, string $phpHome, array $environment): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $include = $phpHome . '/SDK/include';
            $libraries = (new \TypePhp\Platform\Windows())->detectPhpLibs($phpHome);
            $flags = [];
            foreach (['', '/main', '/Zend', '/TSRM', '/win32'] as $part) {
                $flags[] = '/I' . $include . $part;
            }
            $this->runner->run(['cl.exe', '/nologo', '/MD', '/std:c11', '/DWIN32', '/DPHP_WIN32', '/DZEND_WIN32', '/DZTS',
                ...$flags, $source, '/Fo' . $directory . '/embed-probe.obj', '/Fe' . $probe, '/link', '/INCREMENTAL:NO', '/Brepro', $libraries['embed'], $libraries['core']], $directory, $environment, 120);
            return $phpHome . '/ext';
        }
        $config = $phpHome . '/bin/php-config';
        if (trim($this->runner->run([$config, '--version'], $directory, $environment)) !== PHP_VERSION) {
            throw new RuntimeException('运行探针SDK版本与构建PHP不一致');
        }
        $include = trim($this->runner->run([$config, '--include-dir'], $directory, $environment));
        $flags = [];
        foreach (['', '/main', '/Zend', '/TSRM'] as $part) {
            $flags[] = '-I' . $include . $part;
        }
        $this->runner->run([PHP_OS_FAMILY === 'Darwin' ? '/usr/bin/clang' : 'gcc', '-std=c11', '-D_POSIX_C_SOURCE=200809L', ...$flags,
            $source, '-L' . $phpHome . '/lib', '-Wl,-rpath,' . $phpHome . '/lib', '-lphp', ...(PHP_OS_FAMILY === 'Linux' ? ['-ldl'] : []), '-o', $probe], $directory, $environment, 120);
        return trim($this->runner->run([$config, '--extension-dir'], $directory, $environment));
    }

    private function probe(string $probe, string $ini, string $scan, string $directory, array $environment, array $functions): array
    {
        $output = $this->runner->run([$probe, $ini, $scan, ...$functions], $directory, $environment, 30, null, true);
        try {
            $data = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            // 一些PHP启动警告走stdout；严格协议同样拒绝，不能只检查stderr和退出码。
            throw new RuntimeException('原生探针输出包含启动诊断或不是完整JSON协议', 0, $error);
        }
        if (!is_array($data) || ($data['protocol'] ?? null) !== 1 || ($data['sapi'] ?? null) !== 'embed'
            || ($data['php'] ?? null) !== PHP_VERSION || ($data['zts'] ?? null) !== (bool) PHP_ZTS
            || !is_array($data['extensions'] ?? null) || !is_array($data['functions'] ?? null)) {
            throw new RuntimeException('实际embed探针结构、版本或ZTS身份不一致');
        }
        $observedLibrary = $data['core-library'] ?? null;
        if (!is_string($observedLibrary) || $observedLibrary === '') {
            throw new RuntimeException('无法识别实际embed加载的核心库');
        }
        $expectedLibraries = array_map([BuildPlatform::class, 'resolve'], (new BuildPlatform())->runtimeLibraries($environment['PHP_HOME'], $environment['PHPX_HOME']));
        $observed = BuildPlatform::resolve($observedLibrary);
        $matches = false;
        foreach ($expectedLibraries as $expected) {
            if (PHP_OS_FAMILY === 'Windows' ? strcasecmp($observed, $expected) === 0 : $observed === $expected) {
                $matches = true;
            }
        }
        if (!$matches) {
            throw new RuntimeException('实际embed核心库不是已选择的SDK');
        }
        $extensions = [];
        foreach ($data['extensions'] as $name => $version) {
            if (!is_string($name) || (!is_string($version) && $version !== false)) {
                throw new RuntimeException('真实embed返回了无效扩展版本');
            }
            $extensions[strtolower($name)] = $version;
        }
        ksort($extensions);
        $data['extensions'] = $extensions;
        return $data;
    }

    private function write(string $file, string $contents): void
    {
        BuildLock::path($file);
        if (file_put_contents($file, $contents, LOCK_EX) !== strlen($contents)) {
            throw new RuntimeException('无法完整写入原生运行配置');
        }
    }
}
