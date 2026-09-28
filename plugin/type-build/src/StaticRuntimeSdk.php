<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 构建期静态运行库清单；仅接收真实归档，部署过程不读取 SDK 或释放运行库。 */
final class StaticRuntimeSdk
{
    private string $manifest;
    private array $archives = [];
    private array $notices = [];
    private array $noticeFiles = [];
    private array $headers = [];
    private array $identity;

    /**
     * @throws RuntimeException SDK 清单、ABI、源码适配或归档字节不符。
     */
    public function __construct(string $manifest)
    {
        BuildLock::path($manifest);
        $this->manifest = BuildPlatform::resolve($manifest);
        $root = dirname($this->manifest);
        $data = json_decode((string) file_get_contents($this->manifest), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['protocol'] ?? null) !== 1 || ($data['php'] ?? null) !== PHP_VERSION
            || ($data['zts'] ?? null) !== (bool) PHP_ZTS || ($data['os'] ?? null) !== PHP_OS_FAMILY
            || ($data['debug'] ?? null) !== (bool) PHP_DEBUG || ($data['integer-size'] ?? null) !== PHP_INT_SIZE
            || ($data['architecture'] ?? null) !== php_uname('m')
            || !is_array($data['archives'] ?? null) || !array_is_list($data['archives']) || $data['archives'] === []
            || count($data['archives']) > 128) {
            throw new RuntimeException('静态运行 SDK 的协议、平台或 PHP ABI 不一致');
        }
        $patches = ['SwooleThreadSource', 'SwooleHttpSource', 'SwooleSocketSource', 'SwooleStaticSource', 'PhpxThreadSource'];
        if (PHP_OS_FAMILY === 'Windows') {
            $patches[] = 'SwooleWindowsSource';
        }
        foreach ($patches as $patch) {
            if (($data['patches'][$patch] ?? null) !== hash_file('sha256', __DIR__ . '/' . $patch . '.php')) {
                throw new RuntimeException('静态运行 SDK 的源码适配已过期：' . $patch);
            }
        }
        if (!is_array($data['headers'] ?? null) || !array_is_list($data['headers']) || count($data['headers']) > 8192) {
            throw new RuntimeException('静态运行 SDK 缺少目标头文件身份');
        }
        foreach ($data['headers'] as $header) {
            if (!is_array($header) || !is_string($header['file'] ?? null)
                || preg_match('~^include/(?:php|phpx|dependencies)/[a-zA-Z0-9_+./-]+\.(?:h|hh|hpp|inl|inc)$~D', $header['file']) !== 1
                || in_array('..', explode('/', $header['file']), true)
                || !is_string($header['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $header['sha256']) !== 1
                || isset($this->headers[$header['file']])) {
                throw new RuntimeException('静态运行 SDK 头文件路径、摘要或唯一性无效');
            }
            $path = $root . '/' . $header['file'];
            BuildLock::path($path);
            if (!is_file($path) || !hash_equals($header['sha256'], (string) hash_file('sha256', $path))) {
                throw new RuntimeException('静态运行 SDK 目标头文件缺失或摘要不一致');
            }
            $this->headers[$header['file']] = $path;
        }
        $configuration = PHP_OS_FAMILY === 'Windows' ? 'main/config.w32.h' : 'main/php_config.h';
        foreach (['main/php.h', $configuration, 'Zend/zend.h', 'TSRM/TSRM.h'] as $header) {
            if (!isset($this->headers['include/php/' . $header])) {
                throw new RuntimeException('静态运行 SDK 缺少目标核心头文件：' . $header);
            }
        }
        $archivePattern = PHP_OS_FAMILY === 'Windows' ? '~^lib/[a-zA-Z0-9][a-zA-Z0-9._+-]*\.lib$~D' : '~^lib/[a-zA-Z0-9][a-zA-Z0-9._+-]*\.a$~D';
        foreach ($data['archives'] as $archive) {
            if (!is_array($archive) || !is_string($archive['file'] ?? null)
                || preg_match($archivePattern, $archive['file']) !== 1
                || !is_string($archive['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $archive['sha256']) !== 1) {
                throw new RuntimeException('静态运行 SDK 归档声明无效');
            }
            $path = $root . '/' . $archive['file'];
            BuildLock::path($path);
            if (!is_file($path) || file_get_contents($path, false, null, 0, 8) !== "!<arch>\n"
                || !hash_equals($archive['sha256'], (string) hash_file('sha256', $path))) {
                throw new RuntimeException('静态运行 SDK 归档缺失、不是静态库或摘要不一致：' . $archive['file']);
            }
            if (isset($this->archives[$archive['file']])) {
                throw new RuntimeException('静态运行 SDK 归档重复');
            }
            $this->archives[$archive['file']] = $path;
        }
        $notices = $data['notices'] ?? [];
        if (!is_array($notices) || array_diff(array_keys($notices), array_map('basename', array_keys($this->archives))) !== []) {
            throw new RuntimeException('静态运行 SDK 的许可材料引用了未知归档');
        }
        foreach ($notices as $name => $notice) {
            if (!is_array($notice) || !is_array($notice['files'] ?? null) || !array_is_list($notice['files'])) {
                throw new RuntimeException('静态运行 SDK 的许可材料声明无效');
            }
            $files = [];
            foreach ($notice['files'] as $file) {
                if (!is_array($file) || !is_string($file['file'] ?? null)
                    || preg_match('~^licenses/[a-zA-Z0-9@._+/-]+$~D', $file['file']) !== 1
                    || in_array('..', explode('/', $file['file']), true)
                    || !is_string($file['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $file['sha256']) !== 1) {
                    throw new RuntimeException('静态运行 SDK 的许可路径或摘要无效');
                }
                $path = $root . '/' . $file['file'];
                BuildLock::path($path);
                if (!is_file($path) || !hash_equals($file['sha256'], (string) hash_file('sha256', $path))) {
                    throw new RuntimeException('静态运行 SDK 的许可原文缺失或摘要不一致');
                }
                $files[] = ['file' => $path, 'sha256' => $file['sha256']];
                $this->noticeFiles[$path] = $path;
            }
            $notice['files'] = $files;
            $notice['binary-sha256'] = hash_file('sha256', $this->archives['lib/' . $name]);
            $this->notices[$name] = $notice;
        }
        $this->identity = $data;
    }

    /** 未指定静态 SDK 时保留现有开发构建；指定后任何验证失败都不能回退到共享库。 */
    public static function selected(): ?self
    {
        $manifest = getenv('TYPE_STATIC_RUNTIME');
        return is_string($manifest) && $manifest !== '' ? new self($manifest) : null;
    }

    /** @return list<string> 按已验证顺序传给链接器的准确归档路径。 */
    public function archives(): array
    {
        return array_values($this->archives);
    }

    /** @return list<string> 只允许操作系统提供的链接项；第三方依赖必须列入归档。 */
    public function systemFlags(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return ['kernel32.lib', 'user32.lib', 'advapi32.lib', 'shell32.lib', 'ws2_32.lib', 'ole32.lib', 'oleaut32.lib',
                'dnsapi.lib', 'psapi.lib', 'bcrypt.lib', 'pathcch.lib', 'iphlpapi.lib', 'crypt32.lib', 'normaliz.lib',
                'secur32.lib', 'wldap32.lib', 'winmm.lib', 'synchronization.lib'];
        }
        return PHP_OS_FAMILY === 'Darwin'
            ? ['-lresolv', '-lpthread', '-lxml2', '-lz', '-lcurl', '-liconv', '-framework', 'CoreFoundation', '-framework', 'Security', '-lc++']
            : ['-ldl', '-lpthread', '-lm', '-lresolv', '-lstdc++'];
    }

    /** Unix 归档允许相互引用，GNU 链接器须在归档组内重新查找未解析符号。 */
    public function linkFlags(): array
    {
        $archives = $this->archives();
        if (PHP_OS_FAMILY === 'Linux') {
            $archives = ['-Wl,--start-group', ...$archives, '-Wl,--end-group'];
        }
        return [...$archives, ...$this->systemFlags()];
    }

    /** 目标 PHP 头文件随静态 SDK 校验；宿主 PHP 只负责执行编译器。 */
    public function includeDirectory(): string
    {
        return dirname($this->manifest) . '/include/php';
    }

    /** Windows 的 PHPX 与数值依赖使用目标 SDK 头文件，避免误用宿主动态 CRT 版本。 */
    public function windowsIncludeDirectories(): array
    {
        $root = dirname($this->manifest) . '/include';
        return [$root . '/phpx', $root . '/phpx/misc', $root . '/dependencies', $root . '/dependencies/libxml2'];
    }

    /** 只传递 SDK 明确声明的目标系统版本，不能继承构建机的默认部署版本。 */
    public function buildEnvironment(): array
    {
        $minimum = $this->identity['preparation']['minimum-macos'] ?? null;
        if (PHP_OS_FAMILY !== 'Darwin' || $minimum === null) {
            return [];
        }
        if (!is_string($minimum) || preg_match('/^[1-9][0-9]*\.[0-9]+(?:\.[0-9]+)?$/D', $minimum) !== 1) {
            throw new RuntimeException('静态 SDK 的 macOS 最低版本声明无效');
        }
        return ['MACOSX_DEPLOYMENT_TARGET' => $minimum];
    }

    /** SDK 原始清单与所有归档都进入应用构建身份，防止缓存跨静态依赖复用。 */
    public function files(): array
    {
        return [$this->manifest, ...$this->archives(), ...array_values($this->headers), ...array_values($this->noticeFiles)];
    }

    /** @return array<string,array> 绑定归档摘要的原始许可材料，路径只在构建期解析。 */
    public function notices(): array
    {
        return $this->notices;
    }

    /** 原样保留准备方记录的来源与字节身份，运行校验不把 SDK 路径当部署依赖。 */
    public function identity(): array
    {
        return $this->identity;
    }

    /** 向 TypePHP 的受限编译环境显式传递已验证的 SDK 位置。 */
    public function manifestPath(): string
    {
        return $this->manifest;
    }

    /**
     * 检查最终可执行文件的直接加载项；不因文件后缀或归档名称宣称静态链接。
     * @return list<string> 由目标系统提供的加载项。
     */
    public static function verifyArtifact(string $artifact, BuildEnvironment $runner, array $environment): array
    {
        (new BuildPlatform())->assertArtifact($artifact);
        if (PHP_OS_FAMILY === 'Windows') {
            $output = $runner->run(['dumpbin.exe', '/nologo', '/dependents', $artifact], dirname($artifact), $environment);
            preg_match_all('/^\s+([A-Za-z0-9_.-]+\.dll)\s*$/mi', $output, $matches);
            $libraries = array_values(array_unique(array_map('strtolower', $matches[1])));
            $allowed = ['kernel32.dll', 'user32.dll', 'advapi32.dll', 'shell32.dll', 'ws2_32.dll', 'ole32.dll', 'oleaut32.dll',
                'shlwapi.dll', 'dnsapi.dll', 'psapi.dll', 'bcrypt.dll', 'iphlpapi.dll', 'crypt32.dll', 'normaliz.dll',
                'secur32.dll', 'wldap32.dll', 'winmm.dll', 'api-ms-win-core-path-l1-1-0.dll', 'api-ms-win-core-synch-l1-2-0.dll'];
            if ($libraries === [] || array_diff($libraries, $allowed) !== []) {
                throw new RuntimeException('单程序仍依赖非系统 DLL 或无法确认 PE 加载项');
            }
            return $libraries;
        }
        if (!in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true)) {
            throw new RuntimeException('此平台的单程序加载审计尚未实现');
        }
        if (PHP_OS_FAMILY === 'Darwin') {
            $output = $runner->run(['/usr/bin/otool', '-L', $artifact], dirname($artifact), $environment);
            $libraries = [];
            foreach (array_slice(explode("\n", trim($output)), 1) as $line) {
                $library = trim(explode(' (', $line, 2)[0]);
                if (!str_starts_with($library, '/usr/lib/') && !str_starts_with($library, '/System/Library/')) {
                    throw new RuntimeException('单程序仍依赖非系统运行库：' . $library);
                }
                $libraries[] = $library;
            }
            if ($libraries === []) {
                throw new RuntimeException('无法确认单程序的系统加载项');
            }
            return $libraries;
        }
        $output = $runner->run(['readelf', '-d', $artifact], dirname($artifact), $environment);
        preg_match_all('/\(NEEDED\).*\[([^\]]+)\]/', $output, $matches);
        foreach ($matches[1] as $library) {
            // glibc 的 TLS 符号可能使系统加载器同时出现在 DT_NEEDED；仅接受两种已支持架构的准确名称。
            if (preg_match('/^(?:libc\.so\.6|libm\.so\.6|libdl\.so\.2|libpthread\.so\.0|libresolv\.so\.2|libgcc_s\.so\.1|libstdc\+\+\.so\.6|ld-linux-aarch64\.so\.1|ld-linux-x86-64\.so\.2)$/D', $library) !== 1) {
                throw new RuntimeException('单程序仍依赖非系统运行库：' . $library);
            }
        }
        return $matches[1];
    }
}
