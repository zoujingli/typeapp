<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 编译子进程只接收工具路径与固定区域设置，不继承业务环境、认证或预加载变量。 */
final class BuildEnvironment
{
    public function environment(string $phpHome = '', string $phpxHome = ''): array
    {
        return (new BuildPlatform())->environment($phpHome, $phpxHome);
    }

    /**
     * @param \Closure():bool|null $cancelled 仅用于显式取消本次受管子进程。
     * @param bool $rejectStderr 探针模式连成功退出的启动警告也拒绝；普通编译器仍可报告非致命诊断。
     */
    public function run(array $command, string $directory, array $environment, float $seconds = 30.0, ?\Closure $cancelled = null, bool $rejectStderr = false): string
    {
        if ($seconds <= 0 || $seconds > 3600) {
            throw new RuntimeException('构建进程预算无效');
        }
        $out = tmpfile();
        $err = tmpfile();
        if ($out === false || $err === false) {
            throw new RuntimeException('无法准备构建进程输出');
        }
        $program = basename((string) $command[0]);
        $group = PHP_OS_FAMILY === 'Linux' && is_executable('/usr/bin/setsid') && function_exists('posix_kill');
        if ($group) {
            array_unshift($command, '/usr/bin/setsid');
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            if (!function_exists('posix_setsid') || !function_exists('pcntl_exec') || !function_exists('posix_kill')) {
                fclose($out);
                fclose($err);
                throw new RuntimeException('macOS构建需要POSIX与PCNTL以保证编译进程组可限时终止');
            }
            $launcher = 'if (posix_setsid() === -1) { exit(126); } pcntl_exec($argv[1], array_slice($argv, 2)); exit(127);';
            $command = [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'auto_append_file=', '-d', 'opcache.enable_cli=0', '-r', $launcher, ...$command];
            $group = true;
        }
        $process = proc_open($command, [0 => ['file', (new BuildPlatform())->nullDevice(), 'r'], 1 => $out, 2 => $err], $pipes, $directory, $environment, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            fclose($out);
            fclose($err);
            throw new RuntimeException('无法启动构建进程');
        }
        $deadline = hrtime(true) / 1e9 + $seconds;
        try {
            do {
                $state = proc_get_status($process);
                if (!$state['running']) {
                    break;
                }
                $wasCancelled = $cancelled !== null && $cancelled();
                if ($wasCancelled || hrtime(true) / 1e9 >= $deadline || fstat($out)['size'] > 16777216 || fstat($err)['size'] > 16777216) {
                    if ($group) {
                        posix_kill(-$state['pid'], 9);
                    } elseif (PHP_OS_FAMILY === 'Windows') {
                        $null = (new BuildPlatform())->nullDevice();
                        $terminator = proc_open(
                            [$environment['SystemRoot'] . '/System32/taskkill.exe', '/PID', (string) $state['pid'], '/T', '/F'],
                            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
                            $cleanupPipes,
                            $directory,
                            $environment,
                            ['bypass_shell' => true]
                        );
                        if (is_resource($terminator)) {
                            proc_close($terminator);
                        }
                    }
                    proc_terminate($process, 9);
                    throw new RuntimeException(($wasCancelled ? '受管子进程已取消：' : '构建子进程超时或输出超过 16 MiB：') . $program . '；stdout字节=' . fstat($out)['size'] . '；stderr字节=' . fstat($err)['size']);
                }
                usleep(10000);
            } while (true);
            rewind($out);
            rewind($err);
            $stdout = (string) stream_get_contents($out);
            $stderr = (string) stream_get_contents($err);
            if ($state['exitcode'] !== 0) {
                throw new RuntimeException('构建子进程失败：' . $program . '，退出码 ' . $state['exitcode'] . "\n" . $stderr . $stdout);
            }
            if ($rejectStderr && $stderr !== '') {
                throw new RuntimeException('原生探针存在启动诊断，不能视为可用：' . $program . "\n" . $stderr);
            }
            return $stdout;
        } finally {
            proc_close($process);
            fclose($out);
            fclose($err);
        }
    }

    /**
     * 记录实际SDK、编译器、默认头文件和运行依赖，不从宿主固定目录猜测私有sysroot。
     *
     * @param list<string> $extensions 产物要求的扩展。
     * @param array<string,string>|null $moduleFiles 已选择的共享模块；null时使用原扩展目录查找规则。
     * @return array<string,mixed> 供BuildIdentity使用的文件清单与可序列化工具链事实。
     * @throws RuntimeException 工具诊断、目录、扩展或库身份无法可靠确认。
     */
    public function fingerprint(string $phpHome, string $phpxHome, array $extensions, ?array $moduleFiles = null): array
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return (new PlatformFingerprint($this))->collect($phpHome, $phpxHome, $extensions, $moduleFiles);
        }
        $environment = $this->environment($phpHome, $phpxHome);
        $phpConfig = $phpHome . '/bin/php-config';
        $version = trim($this->run([$phpConfig, '--version'], $phpHome, $environment));
        if ($version !== PHP_VERSION) {
            throw new RuntimeException('PHP SDK 与当前构建 PHP 版本不一致');
        }
        $tools = [];
        foreach (['g++', 'gcc', 'ld', 'as'] as $tool) {
            $file = $this->executable($tool, $environment['PATH']);
            $tools[$tool] = ['path' => $file, 'sha256' => hash_file('sha256', $file), 'version-sha256' => hash('sha256', $this->run([$file, '--version'], $phpHome, $environment))];
        }
        foreach (['cc1', 'cc1plus', 'collect2'] as $tool) {
            $file = trim($this->run([$tools['g++']['path'], '-print-prog-name=' . $tool], $phpHome, $environment));
            if (!is_file($file)) {
                throw new RuntimeException('无法解析编译器内部工具：' . $tool);
            }
            $tools[$tool] = ['path' => realpath($file), 'sha256' => hash_file('sha256', $file)];
        }
        $gnu = [];
        foreach (['gcc' => 'c', 'g++' => 'c++'] as $compiler => $language) {
            $gnu[$compiler] = $this->gnuCompiler($tools[$compiler]['path'], $language, $phpHome, $environment);
            $driver = $gnu[$compiler]['driver'];
            $tools[$compiler . '-driver'] = ['path' => $driver, 'sha256' => hash_file('sha256', $driver)];
        }
        $tools['php'] = ['path' => realpath(PHP_BINARY), 'sha256' => hash_file('sha256', PHP_BINARY)];
        $tools['php-config'] = ['path' => $phpConfig, 'sha256' => hash_file('sha256', $phpConfig)];
        $toolDependencies = [];
        foreach ($tools as $tool) {
            if (file_get_contents($tool['path'], false, null, 0, 4) !== "\x7fELF") {
                continue;
            }
            $linked = $this->run(['/usr/bin/ldd', $tool['path']], $phpHome, $environment);
            if (str_contains($linked, 'not found')) {
                throw new RuntimeException('编译工具缺少动态依赖');
            }
            foreach (explode("\n", $linked) as $line) {
                if (preg_match('~(?:=>\s*)?(/[^\s]+)\s+\(0x[a-f0-9]+\)~i', trim($line), $match)) {
                    $this->library($toolDependencies, $match[1]);
                }
            }
        }
        ksort($toolDependencies);
        $libraries = (new BuildPlatform())->runtimeLibraries($phpHome, $phpxHome);
        $native = [];
        foreach ($libraries as $library) {
            $this->library($native, $library);
            $output = $this->run(['/usr/bin/ldd', $library], $phpHome, $environment);
            if (str_contains($output, 'not found')) {
                throw new RuntimeException('工具链运行库缺少传递依赖');
            }
            foreach (explode("\n", $output) as $line) {
                if (preg_match('~(?:=>\s*)?(/[^\s]+)\s+\(0x[a-f0-9]+\)~i', trim($line), $match)) {
                    $this->library($native, $match[1]);
                }
            }
        }
        ksort($native);
        $required = [];
        foreach ($extensions as $extension) {
            if (!is_string($extension) || !extension_loaded($extension)) {
                throw new RuntimeException('产物需要的扩展未加载');
            }
            $required[$extension] = phpversion($extension);
        }
        ksort($required);
        $loaded = [];
        foreach (get_loaded_extensions() as $extension) {
            $loaded[$extension] = phpversion($extension);
        }
        ksort($loaded);
        $headers = [];
        foreach (array_unique([$phpHome . '/include/php', $phpxHome . '/include', $phpxHome . '/src/misc', $phpxHome . '/thirdparty',
            ...$gnu['gcc']['include-directories'], ...$gnu['g++']['include-directories']]) as $directory) {
            if (is_dir($directory)) {
                $headers = array_merge($headers, array_keys((new BuildIdentity())->files([$directory], ['', 'h', 'hpp', 'hh', 'hxx', 'inc', 'inl', 'tcc', 'c', 'cc', 'cpp'])));
            }
        }
        $extensionDirectory = trim($this->run([$phpConfig, '--extension-dir'], $phpHome, $environment));
        $extensionFiles = is_dir($extensionDirectory) ? array_keys((new BuildIdentity())->files([$extensionDirectory], ['so'])) : [];
        $extensionModules = [];
        if ($moduleFiles === null) {
            $moduleFiles = [];
            foreach (array_keys($required) as $extension) {
                if (is_file($extensionDirectory . '/' . $extension . '.so')) {
                    $moduleFiles[$extension] = $extensionDirectory . '/' . $extension . '.so';
                }
            }
        }
        foreach ($moduleFiles as $extension => $moduleFile) {
            if (!is_string($extension) || !is_string($moduleFile) || !is_file($moduleFile)) {
                throw new RuntimeException('运行扩展文件声明无效');
            }
            $this->library($native, $moduleFile);
            $extensionModules[$extension] = basename($moduleFile);
            $extensionDependencies = $this->run(['/usr/bin/ldd', $moduleFile], $phpHome, $environment);
            if (str_contains($extensionDependencies, 'not found')) {
                throw new RuntimeException('运行扩展缺少传递依赖');
            }
            foreach (explode("\n", $extensionDependencies) as $dependencyLine) {
                if (preg_match('~(?:=>\s*)?(/.+?)\s+\(0x[a-f0-9]+\)~i', trim($dependencyLine), $dependencyMatch)) {
                    $this->library($native, $dependencyMatch[1]);
                }
            }
        }
        ksort($native);
        $iniFiles = [];
        if (php_ini_loaded_file() !== false) {
            $iniFiles[] = php_ini_loaded_file();
        }
        foreach (explode(',', (string) php_ini_scanned_files()) as $ini) {
            if (trim($ini) !== '') {
                $iniFiles[] = trim($ini);
            }
        }
        $runtime = ['php' => PHP_VERSION, 'zts' => (bool) PHP_ZTS, 'architecture' => php_uname('m'), 'os' => PHP_OS_FAMILY, 'extensions' => $required];
        return ['runtime' => $runtime, 'native-libraries' => array_values($native), 'tools' => $tools, 'tool-dependencies' => array_values($toolDependencies),
            'gnu-compilers' => $gnu,
            'extension-modules' => $extensionModules,
            'target-triple' => trim($this->run([$tools['g++']['path'], '-dumpmachine'], $phpHome, $environment)),
            'extension-abi' => basename($extensionDirectory), 'loaded-extensions' => $loaded,
            'files' => array_values(array_unique(array_merge($headers, $gnu['gcc']['support-files'], $gnu['g++']['support-files'], $extensionFiles, $iniFiles, array_column($native, 'path'), array_column($toolDependencies, 'path'), array_column($tools, 'path'))))];
    }

    /**
     * 在同一受限环境中预处理空输入，读取实际驱动和C/C++默认include顺序。
     *
     * shell内容是固定的重定向，路径与语言作为独立参数引用，不插值成命令。
     * 不枚举整个sysroot或任意用户目录；收集到的include目录继续走原有越界检查。
     * @param array<string,string> $environment 已去除业务变量的构建环境。
     * @return array{driver:string,sysroot:string,'include-directories':list<string>,'support-files':list<string>}
     * @throws RuntimeException GCC诊断缺失、含歧义或路径无效，不退回硬编码目录。
     */
    private function gnuCompiler(string $compiler, string $language, string $directory, array $environment): array
    {
        $output = $this->run(['/bin/sh', '-c', 'exec "$1" -E -x "$2" - -v 2>&1', 'type-compiler-inputs', $compiler, $language], $directory, $environment);
        if (preg_match_all('/^COLLECT_GCC=(.+)$/m', $output, $matches) !== 1) {
            throw new RuntimeException('无法从GCC诊断确认实际编译驱动');
        }
        $reported = $matches[1][0];
        $driver = $this->compilerPath(str_starts_with($reported, '/') ? $reported : $this->executable($reported, $environment['PATH']), false);
        if (!is_executable($driver) || BuildPlatform::format($driver) !== 'ELF') {
            throw new RuntimeException('GCC实际驱动不是有效的本机工具');
        }
        $includes = [];
        $reading = false;
        $complete = false;
        foreach (explode("\n", $output) as $line) {
            if ($line === '#include "..." search starts here:' || $line === '#include <...> search starts here:') {
                $reading = true;
            } elseif ($reading && $line === 'End of search list.') {
                $complete = true;
                break;
            } elseif ($reading && $line !== '') {
                $includes[] = $this->compilerPath(trim($line), true);
            }
        }
        if (!$complete || $includes === []) {
            throw new RuntimeException('GCC没有返回完整头文件搜索顺序');
        }
        $sysroot = trim($this->run([$compiler, '-print-sysroot'], $directory, $environment));
        if ($sysroot !== '') {
            $sysroot = $this->compilerPath($sysroot, true);
        }
        $support = [];
        // 默认启动对象、运行支持及specs也影响链接；只读取GCC实际解析到的文件。
        foreach (['specs', 'liblto_plugin.so', 'libgcc.a', 'libgcc_eh.a', 'libgcc_s.so', 'libstdc++.so', 'libc_nonshared.a', 'crt1.o', 'crti.o', 'crtn.o', 'crtbegin.o', 'crtbeginS.o', 'crtend.o', 'crtendS.o'] as $name) {
            $path = trim($this->run([$compiler, '-print-file-name=' . $name], $directory, $environment));
            if ($path === $name) {
                continue;
            }
            $support[] = $this->compilerPath($path, false);
        }
        return ['driver' => $driver, 'sysroot' => $sysroot, 'include-directories' => array_values(array_unique($includes)), 'support-files' => array_values(array_unique($support))];
    }

    /** GCC诊断常含绝对路径中的../；仅在拒绝控制字符后解析真实路径，不放宽用户配置的BuildLock约束。 */
    private function compilerPath(string $path, bool $directory): string
    {
        if (!str_starts_with($path, '/') || preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
            throw new RuntimeException('GCC诊断路径不是无歧义的绝对路径');
        }
        $resolved = realpath($path);
        if ($resolved === false || ($directory ? !is_dir($resolved) : !is_file($resolved))) {
            throw new RuntimeException('GCC诊断路径不存在或类型不符');
        }
        BuildLock::path($resolved);
        return $resolved;
    }

    public function installLocked(string $project, string $composer): void
    {
        $project = realpath($project) ?: throw new RuntimeException('安装项目不存在');
        if (!is_file($project . '/composer.lock')) {
            throw new RuntimeException('隔离安装必须已有 composer.lock，不进行隐式依赖升级');
        }
        if (is_file($project . '/auth.json')) {
            throw new RuntimeException('隔离安装项目不能携带 Composer 认证文件');
        }
        $configuration = json_decode(file_get_contents($project . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (['http-basic', 'bearer', 'github-oauth', 'gitlab-token', 'gitlab-oauth', 'bitbucket-oauth'] as $key) {
            if (isset($configuration['config'][$key])) {
                throw new RuntimeException('隔离安装配置不能内嵌认证信息');
            }
        }
        $work = $project . '/build/isolated-composer';
        BuildLock::path($work);
        if (!is_dir($work) && !mkdir($work, 0700, true) && !is_dir($work)) {
            throw new RuntimeException('无法准备隔离 Composer 目录');
        }
        $environment = $this->environment(dirname(dirname(PHP_BINARY)));
        $environment['COMPOSER_HOME'] = $work;
        $environment['COMPOSER_ALLOW_SUPERUSER'] = '1';
        $environment['GIT_CONFIG_NOSYSTEM'] = '1';
        $environment['GIT_CONFIG_GLOBAL'] = (new BuildPlatform())->nullDevice();
        $environment['GIT_TERMINAL_PROMPT'] = '0';
        $environment['GIT_SSH_COMMAND'] = 'false';
        $this->run([PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'auto_append_file=', $composer, 'install', '--no-interaction', '--no-scripts', '--no-plugins', '--prefer-dist', '--no-progress'], $project, $environment, 600);
    }

    private function executable(string $name, string $path): string
    {
        foreach (explode(':', $path) as $directory) {
            if (is_executable($directory . '/' . $name)) {
                return realpath($directory . '/' . $name);
            }
        }
        throw new RuntimeException('工具链缺少可执行文件：' . $name);
    }

    private function library(array &$libraries, string $file): void
    {
        $real = realpath($file);
        if ($real === false || !is_file($real)) {
            throw new RuntimeException('工具链运行库不存在');
        }
        $name = basename($file);
        $entry = ['name' => $name, 'path' => $real, 'sha256' => hash_file('sha256', $real)];
        if (isset($libraries[$name]) && $libraries[$name]['sha256'] !== $entry['sha256']) {
            throw new RuntimeException('同名运行库出现不同身份：' . $name);
        }
        $libraries[$name] = $entry;
    }
}
