<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 本机构建的路径、二进制和工具链约定；目标差异在此集中校验。 */
final class BuildPlatform
{
    private string $family;

    /** @throws RuntimeException 目标不是已经定义原生构建约定的平台。 */
    public function __construct(string $family = PHP_OS_FAMILY)
    {
        if (!in_array($family, ['Linux', 'Darwin', 'Windows'], true)) {
            throw new RuntimeException('不支持的原生构建平台：' . $family);
        }
        $this->family = $family;
    }

    /** 返回PHP定义的平台族名称，写入构建身份时不转换为营销名称。 */
    public function family(): string
    {
        return $this->family;
    }

    /** Windows源码和身份使用统一斜线；不自动消解..或符号链接。 */
    public static function path(string $path): string
    {
        return PHP_OS_FAMILY === 'Windows' ? str_replace('\\', '/', $path) : $path;
    }

    /** 解析已存在文件或目录并统一分隔符，失败时不制造有效路径。 */
    public static function resolve(string $path): string
    {
        $resolved = realpath($path);
        if ($resolved === false) {
            throw new RuntimeException('构建输入路径不存在');
        }
        return self::path($resolved);
    }

    /** 判断是否为本地绝对路径，不接受URL、盘符相对路径或UNC网络目录。 */
    public function absolute(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '://')) {
            return false;
        }
        return $this->family === 'Windows'
            ? preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1
            : str_starts_with($path, '/');
    }

    /** 路径归属按目录边界检查，Windows使用其大小写不敏感的盘符文件语义。 */
    public static function contains(string $root, string $file): bool
    {
        $directory = rtrim(self::path($root), '/') . '/';
        $candidate = self::path($file);
        return PHP_OS_FAMILY === 'Windows'
            ? str_starts_with(strtolower($candidate), strtolower($directory))
            : str_starts_with($candidate, $directory);
    }

    /** PHP进程标准输入的空设备，不能硬编码Unix路径。 */
    public function nullDevice(): string
    {
        return $this->family === 'Windows' ? 'NUL' : '/dev/null';
    }

    /** Windows执行候选必须保持.exe后缀，否则上游会另写文件而绕过本次缓存发布。 */
    public function executableSuffix(): string
    {
        return $this->family === 'Windows' ? '.exe' : '';
    }

    /** 获取统一的最终产物文件名。 */
    public function output(string $file): string
    {
        return $this->family === 'Windows' && !str_ends_with(strtolower($file), '.exe') ? $file . '.exe' : $file;
    }

    /**
     * 只继承平台工具链必需配置，不继承认证、业务变量或任意编译开关。
     *
     * @return array<string, string> 子进程的完整环境。
     */
    public function environment(string $phpHome, string $phpxHome): array
    {
        foreach ([$phpHome, $phpxHome] as $directory) {
            if ($directory !== '' && (!$this->absolute($directory) || preg_match('/[\x00-\x1f\x7f]/', $directory)
                || str_contains($directory, $this->family === 'Windows' ? ';' : ':'))) {
                throw new RuntimeException('工具链路径必须是有效的本地绝对路径');
            }
        }
        $environment = ['LANG' => 'C.UTF-8', 'LC_ALL' => 'C.UTF-8', 'TZ' => 'UTC', 'SOURCE_DATE_EPOCH' => '0'];
        if ($phpHome !== '') {
            $environment['PHP_HOME'] = $phpHome;
        }
        if ($phpxHome !== '') {
            $environment['PHPX_HOME'] = $phpxHome;
        }
        if ($this->family === 'Windows') {
            $system = getenv('SystemRoot');
            if (!is_string($system) || !$this->absolute($system) || !is_dir($system . '/System32')) {
                throw new RuntimeException('Windows工具链缺少明确的SystemRoot');
            }
            $environment['SystemRoot'] = $system;
            // NoProfile不会禁止模块自动发现；限定内建模块，避免扫描用户/Azure等未声明模块。
            $environment['PSModulePath'] = $system . '/System32/WindowsPowerShell/v1.0/Modules';
            $environment['VSLANG'] = '1033';
            $directories = [$phpHome, $phpxHome . '/build', $phpxHome . '/lib', $system . '/System32', $system . '/System32/WindowsPowerShell/v1.0'];
            foreach (['VCToolsInstallDir', 'WindowsSdkDir', 'WindowsSDKVersion', 'INCLUDE', 'LIB', 'LIBPATH'] as $key) {
                $value = getenv($key);
                if ($value !== false && $value !== '') {
                    if (preg_match('/[\x00-\x1f\x7f]/', $value)) {
                        throw new RuntimeException('Windows工具链环境包含控制字符');
                    }
                    $environment[$key] = $value;
                }
            }
            foreach (['VCToolsInstallDir', 'WindowsSdkDir'] as $key) {
                if (isset($environment[$key]) && (!$this->absolute($environment[$key]) || !is_dir($environment[$key]))) {
                    throw new RuntimeException('Windows SDK和MSVC目录必须是明确的本地绝对路径');
                }
            }
            if (isset($environment['WindowsSDKVersion']) && preg_match('/^[0-9]+(?:\.[0-9]+){3}[\\\\\/]?$/D', $environment['WindowsSDKVersion']) !== 1) {
                throw new RuntimeException('Windows SDK版本目录无效');
            }
            foreach (['INCLUDE', 'LIB', 'LIBPATH'] as $key) {
                foreach (explode(';', $environment[$key] ?? '') as $searchDirectory) {
                    if ($searchDirectory !== '' && (!$this->absolute($searchDirectory) || !is_dir($searchDirectory))) {
                        throw new RuntimeException('MSVC搜索目录必须是存在的本地绝对路径');
                    }
                }
            }
            if (isset($environment['VCToolsInstallDir'])) {
                $directories[] = rtrim($environment['VCToolsInstallDir'], '\\/') . '/bin/Hostx64/x64';
            }
            if (isset($environment['WindowsSdkDir'], $environment['WindowsSDKVersion'])) {
                $directories[] = rtrim($environment['WindowsSdkDir'], '\\/') . '/bin/' . trim($environment['WindowsSDKVersion'], '\\/') . '/x64';
            }
            $temporary = realpath(sys_get_temp_dir());
            if ($temporary === false) {
                throw new RuntimeException('Windows缺少可用临时目录');
            }
            $environment['TEMP'] = $temporary;
            $environment['TMP'] = $temporary;
            $environment['PATH'] = implode(';', array_filter($directories, static fn (string $path): bool => $path !== '' && is_dir($path)));
        } else {
            $temporary = getenv('TMPDIR');
            if ($temporary !== false && $temporary !== '') {
                if (!$this->absolute($temporary) || preg_match('/[\x00-\x1f\x7f]/', $temporary)
                    || !is_dir($temporary) || !is_writable($temporary)) {
                    throw new RuntimeException('Unix工具临时目录必须是明确、可写的本地目录');
                }
                // 编译器和链接器也必须留在调用方的私有临时目录，不能绕到宿主默认目录。
                $environment['TMPDIR'] = self::resolve($temporary);
            }
            $directories = [$phpHome === '' ? '' : $phpHome . '/bin', '/usr/local/bin', '/usr/bin', '/bin'];
            if ($this->family === 'Darwin') {
                $directories[] = '/opt/homebrew/bin';
            }
            $environment['PATH'] = implode(':', array_filter($directories, static fn (string $path): bool => $path !== ''));
            if ($phpHome !== '' && $phpxHome !== '') {
                $environment[$this->family === 'Darwin' ? 'DYLD_LIBRARY_PATH' : 'LD_LIBRARY_PATH'] = $phpxHome . '/lib:' . $phpHome . '/lib';
            }
        }
        return $environment;
    }

    /**
     * 为当前PHP及相邻工具提供最小运行环境，不继承业务、认证、预加载或任意PATH。
     *
     * 运行前缀不等于已经配置构建SDK，因此不向子进程注入PHP_HOME/PHPX_HOME。
     * @return array<string,string> 当前平台已验证路径组成的完整子进程环境。
     * @throws RuntimeException 尝试用于非当前平台或PHP安装路径无效。
     */
    public function phpEnvironment(): array
    {
        if ($this->family !== PHP_OS_FAMILY) {
            throw new RuntimeException('当前PHP进程环境不能用于另一平台');
        }
        $binaryDirectory = dirname(self::resolve(PHP_BINARY));
        $prefix = $this->family === 'Windows' ? $binaryDirectory : dirname($binaryDirectory);
        $environment = $this->environment($prefix, '');
        unset($environment['PHP_HOME'], $environment['PHPX_HOME']);
        if ($this->family !== 'Windows') {
            $environment[$this->family === 'Darwin' ? 'DYLD_LIBRARY_PATH' : 'LD_LIBRARY_PATH'] = $prefix . '/lib';
        }
        return $environment;
    }

    /** @return list<string> 上游平台实际要求的PHP与PHPX运行库。 */
    public function runtimeLibraries(string $phpHome, string $phpxHome): array
    {
        if ($this->family === 'Windows') {
            $core = $phpHome . '/' . (PHP_ZTS ? 'php8ts.dll' : 'php8.dll');
            // 上游v0.7.0发行包保留phpx/lib导入库，但把运行DLL放在PHP_HOME顶层。
            $runtime = $this->existing([$phpxHome . '/build/phpx.dll', $phpxHome . '/lib/phpx.dll', $phpxHome . '/bin/phpx.dll', $phpHome . '/phpx.dll']);
            $platform = new \TypePhp\Platform\Windows();
            $imports = $platform->detectPhpLibs($phpHome);
            if ($imports['is_zts'] !== (bool) PHP_ZTS || !is_file($phpxHome . '/lib/phpx.lib')) {
                throw new RuntimeException('Windows PHP/PHPX导入库或ZTS身份不匹配');
            }
            $libraries = [$core, $runtime];
        } else {
            $platform = $this->family === 'Darwin' ? new \TypePhp\Platform\Macos() : new \TypePhp\Platform\Linux();
            $detected = $platform->detectPhpLibs($phpHome);
            $core = $detected['embed'] ?? null;
            if (!is_string($core)) {
                throw new RuntimeException('当前构建需要共享PHP embed运行库，不能混用静态SDK');
            }
            $libraries = [$core, $phpxHome . '/lib/libphpx' . $platform->getSharedLibraryExtension()];
        }
        foreach ($libraries as $library) {
            if (!is_file($library)) {
                throw new RuntimeException('缺少平台原生运行库：' . $library);
            }
        }
        return $libraries;
    }

    /**
     * Unix检查所有者和写权限；Windows以真实NTFS ACL限制缓存为当前用户。
     *
     * @throws RuntimeException 既有缓存所有者或写权限不可信。
     */
    public function privateCache(string $directory, bool $created): void
    {
        if ($this->family !== 'Windows') {
            if ((fileperms($directory) & 0022) !== 0 || (function_exists('posix_geteuid') && fileowner($directory) !== posix_geteuid())) {
                throw new RuntimeException('构建缓存必须由当前用户拥有且禁止其他用户写入');
            }
            return;
        }
        $script = <<<'POWERSHELL'
$ErrorActionPreference='Stop'
$path=$env:TYPE_BUILD_CACHE_DIRECTORY
$sid=[Security.Principal.WindowsIdentity]::GetCurrent().User
if($env:TYPE_BUILD_CACHE_CREATED -eq '1') {
 $acl=New-Object Security.AccessControl.DirectorySecurity
 $acl.SetOwner($sid)
 $acl.SetAccessRuleProtection($true,$false)
 $rule=New-Object Security.AccessControl.FileSystemAccessRule($sid,'FullControl','ContainerInherit,ObjectInherit','None','Allow')
 $acl.AddAccessRule($rule)
 Set-Acl -LiteralPath $path -AclObject $acl
}
$acl=Get-Acl -LiteralPath $path
$owner=$acl.GetOwner([Security.Principal.SecurityIdentifier]).Value
if($owner -ne $sid.Value){throw 'Cache owner does not match current user'}
$writers=[Security.AccessControl.FileSystemRights]'Write,Delete,DeleteSubdirectoriesAndFiles,ChangePermissions,TakeOwnership'
foreach($rule in $acl.GetAccessRules($true,$true,[Security.Principal.SecurityIdentifier])) {
 if($rule.AccessControlType -eq 'Allow' -and ($rule.FileSystemRights -band $writers) -ne 0 -and $rule.IdentityReference.Value -notin @($sid.Value,'S-1-5-18','S-1-5-32-544')){throw 'Cache grants write access to another principal'}
}
'private-cache-ok'
POWERSHELL;
        $environment = $this->environment('', '');
        $environment['TYPE_BUILD_CACHE_DIRECTORY'] = $directory;
        $environment['TYPE_BUILD_CACHE_CREATED'] = $created ? '1' : '0';
        $program = $environment['SystemRoot'] . '/System32/WindowsPowerShell/v1.0/powershell.exe';
        $output = (new BuildEnvironment())->run([$program, '-NoProfile', '-NonInteractive', '-Command', $script], $directory, $environment);
        if (trim($output) !== 'private-cache-ok') {
            throw new RuntimeException('无法确认Windows缓存ACL');
        }
    }

    /** @param list<string> $paths 明确的官方SDK候选位置。 */
    private function existing(array $paths): string
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        throw new RuntimeException('官方SDK约定位置没有PHPX运行库');
    }

    /**
     * 不执行文件，只解析二进制结构和可执行类型。
     *
     * @return string ELF、Mach-O或PE。
     * @throws RuntimeException 文件不是有效的本机格式可执行文件。
     */
    public function assertArtifact(string $file): string
    {
        if (!is_file($file) || (PHP_OS_FAMILY !== 'Windows' && !is_executable($file))) {
            throw new RuntimeException('产物不是可执行文件');
        }
        $format = self::format($file, true);
        $expected = ['Linux' => 'ELF', 'Darwin' => 'Mach-O', 'Windows' => 'PE'][$this->family];
        if ($format !== $expected) {
            throw new RuntimeException('产物格式与构建平台不一致：' . $format . ' / ' . $this->family);
        }
        return $format;
    }

    /** @throws RuntimeException 二进制头缺失、长度越界或文件类型不符合要求。 */
    public static function format(string $file, bool $executable = false): string
    {
        $header = is_file($file) ? file_get_contents($file, false, null, 0, 4096) : false;
        if (!is_string($header) || strlen($header) < 32) {
            throw new RuntimeException('原生产物头部不完整');
        }
        if (str_starts_with($header, "\x7fELF")) {
            $kind = unpack(ord($header[5]) === 2 ? 'n' : 'v', substr($header, 16, 2))[1];
            if ($executable && !in_array($kind, [2, 3], true)) {
                throw new RuntimeException('ELF类型不是可执行文件');
            }
            return 'ELF';
        }
        $magic = substr($header, 0, 4);
        if (in_array($magic, ["\xcf\xfa\xed\xfe", "\xce\xfa\xed\xfe", "\xfe\xed\xfa\xcf", "\xfe\xed\xfa\xce"], true)) {
            $kind = unpack($magic[0] === "\xfe" ? 'N' : 'V', substr($header, 12, 4))[1];
            if ($executable && $kind !== 2) {
                throw new RuntimeException('Mach-O类型不是可执行文件');
            }
            return 'Mach-O';
        }
        if ($magic === "\xca\xfe\xba\xbe") {
            $count = unpack('N', substr($header, 4, 4))[1];
            if ($count < 1 || $count > 16 || strlen($header) < 8 + $count * 20) {
                throw new RuntimeException('通用Mach-O架构表无效');
            }
            for ($index = 0; $index < $count; $index++) {
                $slice = unpack('Noffset/Nsize', substr($header, 16 + $index * 20, 8));
                if ($slice['size'] < 32 || $slice['offset'] + $slice['size'] > filesize($file)) {
                    throw new RuntimeException('通用Mach-O架构范围越界');
                }
                $image = file_get_contents($file, false, null, $slice['offset'], 32);
                if (!in_array(substr($image, 0, 4), ["\xcf\xfa\xed\xfe", "\xce\xfa\xed\xfe"], true)
                    || ($executable && unpack('V', substr($image, 12, 4))[1] !== 2)) {
                    throw new RuntimeException('通用Mach-O包含无效架构');
                }
            }
            return 'Mach-O';
        }
        if (str_starts_with($header, 'MZ') && strlen($header) >= 64) {
            $offset = unpack('V', substr($header, 60, 4))[1];
            if ($offset < 64 || $offset > filesize($file) - 26) {
                throw new RuntimeException('PE签名位置无效');
            }
            $image = file_get_contents($file, false, null, $offset, 26);
            $flags = unpack('v', substr($image, 22, 2))[1];
            $optional = unpack('v', substr($image, 24, 2))[1];
            if (substr($image, 0, 4) !== "PE\0\0" || !in_array($optional, [0x10b, 0x20b], true)
                || ($executable && (($flags & 0x2) === 0 || ($flags & 0x2000) !== 0))) {
                throw new RuntimeException('PE头部不是可执行映像');
            }
            return 'PE';
        }
        throw new RuntimeException('无法识别原生产物格式');
    }
}
