<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 收集Clang或MSVC的真实工具、头文件和动态依赖，不把平台名称当作ABI证明。 */
final class PlatformFingerprint
{
    private BuildEnvironment $runner;
    private array $environment = [];
    private array $systemImages = [];
    private array $cacheFiles = [];
    private array $delayImports = [];
    private array $apiSetPaths = [];
    private array $importTables = [];
    private string $phpHome = '';
    private string $phpxHome = '';

    public function __construct(BuildEnvironment $runner)
    {
        $this->runner = $runner;
    }

    /**
     * 收集macOS/Windows的实际构建环境与应用运行库身份，不执行应用。
     *
     * @param list<string> $extensions 应用明确要求且构建PHP已加载的扩展。
     * @param array<string, string>|null $moduleFiles 扩展名到实际模块文件；null沿用SDK目录推断，空映射明确禁止猜测共享模块。
     * @return array{runtime: array{php: string, zts: bool, architecture: string, os: string, extensions: array<string, string|false>}, native-libraries: list<array<string, mixed>>, system-images: list<array<string, mixed>>, system-cache-files: list<array{name: string, path: string, sha256: string}>, system-cache-policy: string, extension-modules: array<string, string>, delay-imports: list<array<string, mixed>>, build-environment: array<string, string>, tools: array<string, array{path: string, sha256: string, version-sha256?: string}>, tool-dependencies: list<array<string, mixed>>, target-triple: string, extension-abi: string, loaded-extensions: array<string, string|false>, files: list<string>}
     * @throws RuntimeException 平台不支持、SDK/ABI不匹配、工具/库缺失或依赖身份无法完整核对。
     */
    public function collect(string $phpHome, string $phpxHome, array $extensions, ?array $moduleFiles = null): array
    {
        $this->phpHome = $phpHome;
        $this->phpxHome = $phpxHome;
        $platform = new BuildPlatform();
        $this->environment = $platform->environment($phpHome, $phpxHome);
        $this->systemImages = [];
        $this->cacheFiles = [];
        $this->delayImports = [];
        $this->apiSetPaths = [];
        $this->importTables = [];
        $toolFiles = [];
        $headerDirectories = [$phpxHome . '/include', $phpxHome . '/src/misc', $phpxHome . '/thirdparty'];
        $extraFiles = [];
        if ($platform->family() === 'Darwin') {
            $phpConfig = $phpHome . '/bin/php-config';
            if (trim($this->command([$phpConfig, '--version'])) !== PHP_VERSION) {
                throw new RuntimeException('PHP SDK与当前构建PHP版本不一致');
            }
            foreach (['clang', 'clang++', 'ld', 'as'] as $name) {
                $toolFiles[$name] = trim($this->command(['/usr/bin/xcrun', '--find', $name]));
            }
            foreach (['xcrun', 'xcode-select', 'otool', 'dyld_info', 'codesign'] as $name) {
                $toolFiles[$name] = '/usr/bin/' . $name;
            }
            $toolFiles['php-config'] = $phpConfig;
            $sdk = trim($this->command(['/usr/bin/xcrun', '--show-sdk-path']));
            $resource = trim($this->command([$toolFiles['clang++'], '-print-resource-dir']));
            // 编译使用本次已核验的Clang/SDK，不能在指纹后重新读取可变的xcode-select状态。
            $this->environment['PATH'] = dirname($toolFiles['clang++']) . ':' . $this->environment['PATH'];
            $this->environment['SDKROOT'] = $sdk;
            $this->environment['DEVELOPER_DIR'] = trim($this->command(['/usr/bin/xcode-select', '--print-path']));
            array_push($headerDirectories, $phpHome . '/include/php', $sdk, $resource . '/include', '/opt/homebrew/include', '/usr/local/include');
            $extensionDirectory = trim($this->command([$phpConfig, '--extension-dir']));
            $triple = trim($this->command([$toolFiles['clang++'], '-dumpmachine']));
            $version = $this->command([$toolFiles['clang++'], '--version']);
        } elseif ($platform->family() === 'Windows') {
            foreach (['cl.exe', 'link.exe', 'dumpbin.exe', 'rc.exe'] as $name) {
                $toolFiles[$name] = $this->executable($name);
            }
            $imports = (new \TypePhp\Platform\Windows())->detectPhpLibs($phpHome);
            array_push($extraFiles, $imports['embed'], $imports['core'], $phpxHome . '/lib/phpx.lib');
            $headerDirectories[] = $phpHome . '/SDK/include';
            foreach (['INCLUDE', 'LIB', 'LIBPATH'] as $key) {
                foreach (explode(';', $this->environment[$key] ?? '') as $directory) {
                    if ($directory !== '') {
                        $headerDirectories[] = $directory;
                    }
                }
            }
            $vc = $this->environment['VCToolsInstallDir'] ?? '';
            if ($vc === '' || !is_dir($vc . '/bin/Hostx64/x64')) {
                throw new RuntimeException('必须在已初始化的x64 MSVC工具环境构建');
            }
            $extraFiles = array_merge($extraFiles, array_keys((new BuildIdentity())->files([$vc . '/bin/Hostx64/x64'], ['exe', 'dll'])));
            $extensionDirectory = $phpHome . '/ext';
            $triple = 'x86_64-pc-windows-msvc';
            // /?可能被Windows参数通配展开为盘符根目录下的单字符路径。
            $version = $this->command([$toolFiles['cl.exe'], '/HELP']);
            if (PHP_INT_SIZE !== 8 || !in_array(strtolower(php_uname('m')), ['amd64', 'x86_64'], true)) {
                throw new RuntimeException('锁定Windows工具链只接受x64');
            }
            $header = (string) file_get_contents($phpHome . '/SDK/include/main/php_version.h');
            if (!str_contains($header, '"' . PHP_VERSION . '"')) {
                throw new RuntimeException('Windows PHP头文件版本不匹配');
            }
        } else {
            throw new RuntimeException('此收集器仅负责macOS与Windows');
        }
        $toolFiles['php'] = PHP_BINARY;
        $tools = [];
        foreach ($toolFiles as $name => $file) {
            $real = realpath($file);
            if ($real === false || !is_file($real)) {
                throw new RuntimeException('平台工具不存在：' . $name);
            }
            $tools[$name] = ['path' => BuildPlatform::path($real), 'sha256' => hash_file('sha256', $real)];
        }
        $tools[$platform->family() === 'Darwin' ? 'clang++' : 'cl.exe']['version-sha256'] = hash('sha256', $version);
        $toolDependenciesByPath = [];
        foreach ($tools as $tool) {
            // cl/link/rc等是不同进程，允许各自拥有不同版本的同名运行库。
            foreach ($this->dependencies([$tool['path']], dirname($tool['path'])) as $dependency) {
                $toolDependenciesByPath[$dependency['path']] = $dependency;
            }
        }
        ksort($toolDependenciesByPath);
        $toolDependencies = array_values($toolDependenciesByPath);
        $libraries = $platform->runtimeLibraries($phpHome, $phpxHome);
        $required = [];
        $loaded = [];
        $extensionFiles = [];
        $extensionModules = [];
        foreach ($extensions as $extension) {
            if (!is_string($extension) || !extension_loaded($extension)) {
                throw new RuntimeException('产物需要的扩展未加载');
            }
            $required[$extension] = phpversion($extension);
            foreach ($moduleFiles === null ? [$extensionDirectory . '/' . $extension . '.so', $extensionDirectory . '/php_' . $extension . '.dll'] : [] as $candidate) {
                if (is_file($candidate)) {
                    $libraries[] = $candidate;
                    $extensionModules[$extension] = basename($candidate);
                }
            }
        }
        foreach ($moduleFiles ?? [] as $extension => $moduleFile) {
            if (!is_string($extension) || !is_string($moduleFile) || !is_file($moduleFile)) {
                throw new RuntimeException('运行扩展文件声明无效');
            }
            $libraries[] = $moduleFile;
            $extensionModules[$extension] = basename($moduleFile);
        }
        foreach (get_loaded_extensions() as $extension) {
            $loaded[$extension] = phpversion($extension);
        }
        if (is_dir($extensionDirectory)) {
            $extensionFiles = array_keys((new BuildIdentity())->files([$extensionDirectory], ['so', 'dylib', 'dll']));
        }
        // 收集工具时发现的系统映像也进入完整身份；运行验证只要求真正的应用运行依赖。
        $this->systemImages = [];
        $this->delayImports = [];
        $native = $this->dependencies($libraries, $this->phpHome);
        $images = array_values($this->systemImages);
        $headers = $this->headerFiles($headerDirectories);
        $iniFiles = [];
        if (php_ini_loaded_file() !== false) {
            $iniFiles[] = php_ini_loaded_file();
        }
        foreach (explode(',', (string) php_ini_scanned_files()) as $ini) {
            if (trim($ini) !== '') {
                $iniFiles[] = trim($ini);
            }
        }
        ksort($required);
        ksort($loaded);
        ksort($tools);
        $cache = [];
        foreach (array_keys($this->cacheFiles) as $file) {
            $cache[] = ['name' => basename($file), 'path' => $file, 'sha256' => hash_file('sha256', $file)];
        }
        return ['runtime' => ['php' => PHP_VERSION, 'zts' => (bool) PHP_ZTS, 'architecture' => php_uname('m'), 'os' => PHP_OS_FAMILY, 'extensions' => $required],
            'native-libraries' => $native, 'system-images' => $images, 'system-cache-files' => $cache,
            'system-cache-policy' => $platform->family() === 'Darwin' ? 'explicit-deployment-audit' : 'not-applicable',
            'extension-modules' => $extensionModules,
            'delay-imports' => array_values($this->delayImports),
            'build-environment' => $this->environment,
            'tools' => $tools, 'tool-dependencies' => $toolDependencies, 'target-triple' => $triple,
            'extension-abi' => basename($extensionDirectory), 'loaded-extensions' => $loaded,
            'files' => array_values(array_unique(array_merge(
                $headers,
                $extraFiles,
                $extensionFiles,
                $iniFiles,
                array_column($native, 'path'),
                array_column($toolDependencies, 'path'),
                array_column($tools, 'path'),
                array_keys($this->cacheFiles)
            )))];
    }

    /** @param list<string> $roots 动态库或本机工具闭包的已验证入口。 */
    private function dependencies(array $roots, string $applicationDirectory): array
    {
        $queue = array_map(static fn (string $file): array => [$file, false], $roots);
        $seen = [];
        $libraries = [];
        while ($queue !== []) {
            [$file, $deferred] = array_shift($queue);
            $real = realpath($file);
            if ($real === false) {
                throw new RuntimeException('平台动态依赖路径不存在：' . $file);
            }
            $real = BuildPlatform::path($real);
            if (array_key_exists($real, $seen) && (!$seen[$real] || $deferred)) {
                continue;
            }
            $seen[$real] = $deferred;
            try {
                $format = BuildPlatform::format($real);
            } catch (RuntimeException) {
                continue;
            }
            if (($format !== 'Mach-O' && PHP_OS_FAMILY === 'Darwin') || ($format !== 'PE' && PHP_OS_FAMILY === 'Windows')) {
                throw new RuntimeException('工具或运行库属于其他平台：' . $real);
            }
            $name = basename($file);
            $entry = ['name' => $name, 'path' => $real, 'sha256' => hash_file('sha256', $real)];
            if (PHP_OS_FAMILY === 'Windows') {
                $entry['system'] = BuildPlatform::contains($this->environment['SystemRoot'], $real);
                $entry['deferred'] = $deferred;
            }
            if (isset($libraries[$name]) && $libraries[$name]['sha256'] !== $entry['sha256']) {
                throw new RuntimeException('同名平台运行库存在不同身份：' . $name);
            }
            $libraries[$name] = $entry;
            if (PHP_OS_FAMILY === 'Darwin') {
                $output = $this->command(['/usr/bin/otool', '-L', $real]);
                foreach (explode("\n", $output) as $line) {
                    if (preg_match('/^\s+(.+) \(compatibility version /', $line, $match) !== 1) {
                        continue;
                    }
                    $dependency = $this->macLibrary($match[1], $real);
                    if ($dependency !== null) {
                        $queue[] = [$dependency, $deferred];
                    }
                }
            } else {
                // 导入表按本次实际文件摘要复用，进程级搜索顺序仍分别解析。
                $tableKey = $real . ':' . $entry['sha256'];
                if (!isset($this->importTables[$tableKey])) {
                    $tableOutput = $this->command([$this->executable('dumpbin.exe'), '/DEPENDENTS', $real]);
                    $this->importTables[$tableKey] = ['output' => $tableOutput, 'imports' => (new WindowsImports())->parse($tableOutput)];
                }
                $output = $this->importTables[$tableKey]['output'];
                $imports = $this->importTables[$tableKey]['imports'];
                foreach (['required', 'delayed'] as $kind) {
                    foreach ($imports[$kind] as $dependencyName) {
                        if ($kind === 'delayed') {
                            $this->delayImports[$real . ':' . strtolower($dependencyName)] = ['importer' => $real, 'name' => $dependencyName,
                                'policy' => $entry['system'] ? 'system-managed' : 'bundled-and-verified'];
                            if ($entry['system']) {
                                continue;
                            }
                        }
                        try {
                            $queue[] = [$this->windowsLibrary($dependencyName, $applicationDirectory, basename($real)), $deferred || $kind === 'delayed'];
                        } catch (RuntimeException $error) {
                            throw new RuntimeException($error->getMessage() . "\n父映像依赖表：\n" . $output, 0, $error);
                        }
                    }
                }
            }
        }
        ksort($libraries);
        return array_values($libraries);
    }

    private function macLibrary(string $name, string $loader): ?string
    {
        $executableDirectory = $this->phpHome . '/bin';
        try {
            (new BuildPlatform('Darwin'))->assertArtifact($loader);
            $executableDirectory = dirname($loader);
        } catch (RuntimeException) {
        }
        $candidates = [$name];
        if (str_starts_with($name, '@loader_path/')) {
            $candidates = [dirname($loader) . substr($name, strlen('@loader_path'))];
        }
        if (str_starts_with($name, '@executable_path/')) {
            $candidates = [$executableDirectory . '/' . substr($name, strlen('@executable_path/'))];
        }
        if (str_starts_with($name, '@rpath/')) {
            $suffix = substr($name, strlen('@rpath/'));
            $paths = [$this->phpxHome . '/lib', $this->phpHome . '/lib', dirname($loader), '/opt/homebrew/lib', '/usr/local/lib'];
            $commands = $this->command(['/usr/bin/otool', '-l', $loader]);
            if (preg_match_all('/cmd LC_RPATH\s+cmdsize [0-9]+\s+path (.+) \(offset [0-9]+\)/', $commands, $matches)) {
                foreach ($matches[1] as $rpath) {
                    $paths[] = str_replace(['@loader_path', '@executable_path'], [dirname($loader), $executableDirectory], $rpath);
                }
            }
            $candidates = array_map(static fn (string $directory): string => $directory . '/' . $suffix, $paths);
        }
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        if (!str_starts_with($name, '/usr/lib/') && !str_starts_with($name, '/System/Library/')) {
            throw new RuntimeException('无法解析Mach-O动态依赖：' . $name);
        }
        $output = $this->command(['/usr/bin/dyld_info', '-uuid', $name]);
        if (preg_match('/\b([A-F0-9]{8}(?:-[A-F0-9]{4}){3}-[A-F0-9]{12})\b/i', $output, $match) !== 1) {
            throw new RuntimeException('无法确认系统dyld共享缓存中的运行库：' . $name);
        }
        $this->systemImages[$name] = ['path' => $name, 'uuid' => strtoupper($match[1])];
        if ($this->cacheFiles === []) {
            $architecture = php_uname('m') === 'arm64' ? 'arm64e' : 'x86_64';
            foreach (['/System/Volumes/Preboot/Cryptexes/OS/System/Library/dyld', '/System/Library/dyld'] as $directory) {
                foreach (glob($directory . '/dyld_shared_cache_' . $architecture . '*') ?: [] as $file) {
                    if (is_file($file) && !preg_match('/\.(?:map|atlas|symbols)$/', $file)) {
                        $this->cacheFiles[$file] = true;
                    }
                }
            }
            if ($this->cacheFiles === []) {
                throw new RuntimeException('无法定位当前架构dyld缓存字节，拒绝不完整身份');
            }
        }
        return null;
    }

    private function windowsLibrary(string $name, string $loader, string $importer): string
    {
        // API Set 先由系统加载器解析；SDK 同名转发文件不代表实际加载的宿主映像。
        if (preg_match('/^(?:api|ext)-ms-[A-Za-z0-9_.-]+\.dll$/i', $name) !== 1) {
            foreach (array_merge([$loader, $this->phpHome, $this->phpxHome . '/build', $this->phpxHome . '/lib'], explode(';', $this->environment['PATH'])) as $directory) {
                $candidate = $directory . '/' . $name;
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
            throw new RuntimeException('无法解析Windows动态依赖：' . $name);
        }
        $apiKey = strtolower($name);
        if (isset($this->apiSetPaths[$apiKey])) {
            return $this->apiSetPaths[$apiKey];
        }
        // API-set是系统转发名；只让Windows加载器解析系统目录，不搜索项目或当前目录。
        $script = <<<'POWERSHELL'
$ErrorActionPreference='Stop'
Add-Type -TypeDefinition 'using System; using System.Runtime.InteropServices; using System.Text; public static class TypeAppLibrary { [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)] public static extern IntPtr LoadLibraryEx(string n, IntPtr h, uint f); [DllImport("kernel32.dll", CharSet=CharSet.Unicode)] public static extern uint GetModuleFileName(IntPtr h, StringBuilder s, int c); }'
$h=[TypeAppLibrary]::LoadLibraryEx($env:TYPE_BUILD_DEPENDENCY,[IntPtr]::Zero,0x800)
if($h -eq [IntPtr]::Zero){throw ('API-set resolution failed: '+$env:TYPE_BUILD_DEPENDENCY+'; importer='+$env:TYPE_BUILD_IMPORTER+'; Win32='+[Runtime.InteropServices.Marshal]::GetLastWin32Error())}
$s=New-Object Text.StringBuilder 32768
if([TypeAppLibrary]::GetModuleFileName($h,$s,$s.Capacity) -eq 0){throw 'API-set path failed'}
$s.ToString()
POWERSHELL;
        $environment = $this->environment;
        $environment['TYPE_BUILD_DEPENDENCY'] = $name;
        $environment['TYPE_BUILD_IMPORTER'] = $importer;
        $resolved = trim($this->runner->run([$this->executable('powershell.exe'), '-NoProfile', '-NonInteractive', '-Command', $script], $this->phpHome, $environment));
        if (!is_file($resolved) || !BuildPlatform::contains($environment['SystemRoot'] . '/System32', $resolved)) {
            throw new RuntimeException('API-set未解析到受信系统目录');
        }
        $this->apiSetPaths[$apiKey] = $resolved;
        return $resolved;
    }

    private function executable(string $name): string
    {
        foreach (explode(PHP_OS_FAMILY === 'Windows' ? ';' : ':', $this->environment['PATH']) as $directory) {
            if (is_file($directory . '/' . $name)) {
                return $directory . '/' . $name;
            }
        }
        throw new RuntimeException('平台缺少必需工具：' . $name);
    }

    private function command(array $command): string
    {
        return $this->runner->run($command, $this->phpHome, $this->environment, 60);
    }

    /**
     * 系统SDK的公开include目录允许Homebrew/Framework链接；只记录实际目标并避免环。
     * 应用生产目录仍由BuildIdentity保留禁止越界链接的原约束。
     *
     * @param list<string> $directories 已经确定的系统/SDK搜索路径。
     * @return list<string> 实际头文件、接口描述及导入库。
     */
    private function headerFiles(array $directories): array
    {
        $queue = $directories;
        $visited = [];
        $files = [];
        $extensions = ['', 'h', 'hh', 'hpp', 'hxx', 'inc', 'inl', 'tcc', 'c', 'cc', 'cpp', 'tbd', 'lib'];
        while ($queue !== []) {
            $directory = array_pop($queue);
            $real = realpath($directory);
            if ($real === false || !is_dir($real) || isset($visited[$real])) {
                continue;
            }
            $visited[$real] = true;
            foreach (new \DirectoryIterator($real) as $entry) {
                if ($entry->isDot()) {
                    continue;
                }
                if ($entry->isDir()) {
                    $queue[] = $entry->getPathname();
                } elseif ($entry->isFile() && in_array(strtolower($entry->getExtension()), $extensions, true)) {
                    $file = $entry->getRealPath();
                    if ($file === false) {
                        throw new RuntimeException('SDK头文件链接目标不存在');
                    }
                    $files[BuildPlatform::path($file)] = true;
                }
            }
        }
        ksort($files);
        return array_keys($files);
    }
}
