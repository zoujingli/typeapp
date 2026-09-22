<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

/** 构建期打包工具；本文件和 Composer 都不进入运行目录。 */
function packageResources(string $artifact, string $sandbox): void
{
    Type\Build\BuildLock::path($sandbox);
    $reader = new Type\Build\ArtifactManifest();
    $snapshot = $sandbox . '/app/type-app';
    if (!copy($artifact, $snapshot) || !chmod($snapshot, 0755)) {
        throw new RuntimeException('无法创建原生产物快照');
    }
    $manifest = $reader->read($snapshot);
    $reader->verifyResources($artifact, $manifest);
    foreach ($manifest['resources'] ?? [] as $resource) {
        $relative = $manifest['resource-generation'] . '/' . $resource['target'];
        $source = $artifact . '.resources/' . $relative;
        if (preg_match('/\.(?:php[0-9]?|phtml|phar|inc)$/i', $resource['target']) || preg_match('/<\?(?:php\s|=)/i', (string) file_get_contents($source))) {
            throw new RuntimeException('部署资源不允许包含 PHP 源码');
        }
        $target = $sandbox . '/app/type-app.resources/' . $relative;
        Type\Build\BuildLock::path($target);
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true)) {
            throw new RuntimeException('无法创建部署资源目录');
        }
        if (!copy($source, $target) || hash_file('sha256', $target) !== $resource['sha256']) {
            throw new RuntimeException('部署资源与构建清单不一致');
        }
        chmod($target, 0644);
    }
}

/** 不执行传入 ini；逐声明解析以保留扩展装配顺序。 */
function packageIni(string $input, string $sandbox): void
{
    if (!is_file($input)) {
        throw new RuntimeException('缺少原生运行配置');
    }
    $files = [$input];
    foreach (glob(dirname($input) . '/php.d/*.ini') ?: [] as $scan) {
        $files[] = $scan;
    }
    $declarations = [];
    foreach ($files as $file) {
        foreach (preg_split('/\r\n|\r|\n/', (string) file_get_contents($file)) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, ';') || str_starts_with($line, '#') || preg_match('/^\[[A-Za-z0-9 _.-]+\]$/D', $line)) {
                continue;
            }
            if (str_contains($line, '${')) {
                throw new RuntimeException('运行配置不允许环境插值');
            }
            if (!preg_match('/^([A-Za-z_][A-Za-z0-9_.-]*)\s*=/D', $line, $match)) {
                throw new RuntimeException('运行配置包含不支持的声明');
            }
            $parsed = @parse_ini_string($line, false, INI_SCANNER_RAW);
            if ($parsed === false || count($parsed) !== 1 || !is_string(array_values($parsed)[0])) {
                throw new RuntimeException('运行配置声明无法解析');
            }
            $key = strtolower($match[1]);
            $value = array_values($parsed)[0];
            if (in_array($key, ['auto_prepend_file', 'auto_append_file', 'opcache.preload'], true) && $value !== '') {
                throw new RuntimeException('运行配置不允许源码注入');
            }
            if ($value !== '' && preg_match('/(?:password|passwd|default_pw|secret|token|credential|auth|api[_.-]?key)/i', $key)) {
                throw new RuntimeException('运行配置不允许携带凭据');
            }
            $declarations[] = [$key, $value];
        }
    }
    $configuration = ['expose_php' => '0', 'enable_dl' => '0', 'allow_url_include' => '0', 'auto_prepend_file' => '', 'auto_append_file' => '',
        'user_ini.filename' => '', 'include_path' => '', 'opcache.enable' => '0', 'opcache.enable_cli' => '0', 'swoole.enable_library' => 'Off',
        'swoole.enable_fiber_mock' => 'On',
        'display_errors' => 'stderr', 'display_startup_errors' => '1', 'log_errors' => '0', 'memory_limit' => '256M', 'date.timezone' => 'UTC'];
    $extensions = [];
    $declared = [];
    $extensionDirectory = (string) ini_get('extension_dir');
    foreach ($declarations as [$key, $value]) {
        if ($key === 'extension_dir') {
            $extensionDirectory = $value;
        }
    }
    foreach ($declarations as [$key, $value]) {
        if (in_array($key, ['extension', 'zend_extension'], true)) {
            $path = str_starts_with($value, '/') ? $value : rtrim($extensionDirectory, '/') . '/' . $value;
            if (!is_file($path) && !str_ends_with($path, '.so')) {
                $path .= '.so';
            }
            $resolved = realpath($path);
            if ($resolved === false || !is_file($resolved) || file_get_contents($resolved, false, null, 0, 4) !== "\x7fELF" || preg_match('/[\s"$\\\\]/', $resolved)) {
                throw new RuntimeException('运行扩展不是可确认的本地 ELF');
            }
            if (!isset($declared[$key . ':' . $resolved])) {
                $extensions[] = $key . '="' . $resolved . '"';
                $declared[$key . ':' . $resolved] = true;
            }
        } elseif (in_array($key, ['memory_limit', 'post_max_size', 'upload_max_filesize'], true)) {
            if (!preg_match('/^(?:-1|[0-9]+[KMG]?)$/Di', $value)) {
                throw new RuntimeException('运行容量设置无效');
            }
            $configuration[$key] = $value;
        } elseif (in_array($key, ['default_socket_timeout', 'max_execution_time', 'max_input_time', 'max_input_vars', 'max_file_uploads'], true)) {
            if (!preg_match('/^-?[0-9]{1,8}$/D', $value)) {
                throw new RuntimeException('运行数值设置无效');
            }
            $configuration[$key] = $value;
        } elseif ($key === 'date.timezone') {
            if (!in_array($value, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
                throw new RuntimeException('运行时区设置无效');
            }
            $configuration[$key] = $value;
        }
    }
    $caSource = '';
    foreach ($declarations as [$key, $value]) {
        if ($key === 'openssl.cafile' && $value !== '') {
            $caSource = $value;
        }
    }
    if ($caSource === '') {
        foreach (['/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/cert.pem'] as $candidate) {
            if (is_file($candidate)) {
                $caSource = $candidate;
                break;
            }
        }
    }
    if ($caSource !== '') {
        $bundle = is_file($caSource) ? file_get_contents($caSource) : false;
        if (!is_string($bundle) || strlen($bundle) > 16777216 || !preg_match('/-----BEGIN CERTIFICATE-----/', $bundle)
            || preg_match('/-----BEGIN [^\r\n-]*PRIVATE KEY-----|<\?(?:php\s|=)/i', $bundle)) {
            throw new RuntimeException('系统 CA 资源必须是公开证书包');
        }
        $remaining = preg_replace('/-----BEGIN CERTIFICATE-----\s*[A-Za-z0-9+\/=\s]+-----END CERTIFICATE-----/', '', $bundle);
        if (!is_string($remaining) || trim(preg_replace('/^\s*#.*$/m', '', $remaining)) !== '') {
            throw new RuntimeException('系统 CA 资源包含非证书数据');
        }
        if (!is_dir($sandbox . '/app/certs') && !mkdir($sandbox . '/app/certs', 0755)) {
            throw new RuntimeException('无法创建系统 CA 目录');
        }
        if (file_put_contents($sandbox . '/app/certs/ca-certificates.crt', $bundle) !== strlen($bundle)) {
            throw new RuntimeException('无法复制系统 CA 资源');
        }
        chmod($sandbox . '/app/certs/ca-certificates.crt', 0644);
        $configuration['openssl.cafile'] = '/app/certs/ca-certificates.crt';
        $configuration['curl.cainfo'] = '/app/certs/ca-certificates.crt';
    }
    $output = "; 只包含无源码部署所需的运行白名单；不携带开发 ini。\n";
    foreach ($configuration as $key => $value) {
        $output .= $key . '="' . $value . "\"\n";
    }
    $output .= implode("\n", $extensions) . "\n";
    if (file_put_contents($sandbox . '/app/php.ini', $output) !== strlen($output)) {
        throw new RuntimeException('无法生成部署配置');
    }
    chmod($sandbox . '/app/php.ini', 0644);
}

/** 保留原路径及 SONAME 别名；别名必须指向同一份真实 ELF，不能平铺覆盖。 */
function packageLibraries(string $artifact, string $sandbox): void
{
    $manifest = (new Type\Build\ArtifactManifest())->read($artifact);
    $paths = preg_split('/\r\n|\r|\n/', trim((string) stream_get_contents(STDIN)));
    $aliases = [];
    foreach ($manifest['native-libraries'] ?? [] as $library) {
        if (!is_string($library['path'] ?? null) || !is_string($library['sha256'] ?? null) || !is_string($library['name'] ?? null)
            || !is_file($library['path']) || !hash_equals($library['sha256'], (string) hash_file('sha256', $library['path']))) {
            throw new RuntimeException('部署运行库与构建清单不一致');
        }
        $paths[] = $library['path'];
        $aliases[$library['path']][] = '/lib/' . $library['name'];
    }
    foreach (array_unique($paths) as $path) {
        if ($path === '') {
            continue;
        }
        if (!str_starts_with($path, '/') || preg_match('/[\s\x00]/', $path) || str_contains($path, '/..') || str_contains($path, '/./')) {
            throw new RuntimeException('部署运行库路径无效');
        }
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved) || file_get_contents($resolved, false, null, 0, 4) !== "\x7fELF") {
            throw new RuntimeException('部署运行库不是 ELF 文件');
        }
        $target = $sandbox . $resolved;
        Type\Build\BuildLock::path($target);
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true)) {
            throw new RuntimeException('无法创建运行库目录');
        }
        $hash = (string) hash_file('sha256', $resolved);
        if (file_exists($target)) {
            if (hash_file('sha256', $target) !== $hash) {
                throw new RuntimeException('部署运行库路径冲突');
            }
        } elseif (!copy($resolved, $target) || hash_file('sha256', $target) !== $hash || !chmod($target, 0755)) {
            throw new RuntimeException('无法复制部署运行库');
        }
        foreach (array_unique(array_merge([$path, '/lib/' . basename($path), '/lib/' . basename($resolved)], $aliases[$path] ?? [])) as $alias) {
            if ($alias === $resolved) {
                continue;
            }
            if (!str_starts_with($alias, '/') || preg_match('/[\s\x00]/', $alias) || str_contains($alias, '..')) {
                throw new RuntimeException('部署运行库别名无效');
            }
            $link = $sandbox . $alias;
            if (is_link($link)) {
                $existing = $sandbox . readlink($link);
                if (!is_file($existing) || hash_file('sha256', $existing) !== $hash) {
                    throw new RuntimeException('部署运行库别名冲突');
                }
                continue;
            }
            Type\Build\BuildLock::path($link);
            if (is_file($link)) {
                if (hash_file('sha256', $link) !== $hash) {
                    throw new RuntimeException('部署运行库别名冲突');
                }
                continue;
            }
            if (!is_dir(dirname($link)) && !mkdir(dirname($link), 0755, true)) {
                throw new RuntimeException('无法创建运行库别名目录');
            }
            if (!symlink($resolved, $link)) {
                throw new RuntimeException('无法保存运行库别名');
            }
        }
    }
}

function packageNetwork(string $sandbox): void
{
    if (!is_dir($sandbox . '/etc') && !mkdir($sandbox . '/etc', 0755)) {
        throw new RuntimeException('无法创建网络运行目录');
    }
    foreach (['/etc/hosts', '/etc/resolv.conf'] as $source) {
        if (!is_file($source)) {
            continue;
        }
        $contents = file_get_contents($source);
        if (!is_string($contents) || strlen($contents) > 1048576 || str_contains($contents, "\0")) {
            throw new RuntimeException('名称解析配置无效');
        }
        if (file_put_contents($sandbox . $source, $contents) !== strlen($contents) || !chmod($sandbox . $source, 0644)) {
            throw new RuntimeException('无法复制名称解析配置');
        }
    }
    // 只保留本框架实际使用的 files/DNS，不装入宿主认证、LDAP 或其他 NSS 插件。
    if (file_put_contents($sandbox . '/etc/nsswitch.conf', "hosts: files dns\nnetworks: files\n") === false) {
        throw new RuntimeException('无法保存名称解析协议');
    }
}

/** 只生成明确的原生模块启动资源，绝不复制宿主 SNMP 认证或持久状态。 */
function packageRuntime(string $sandbox): void
{
    $extensions = preg_split('/\r\n|\r|\n/', trim((string) stream_get_contents(STDIN)));
    if (in_array('snmp', $extensions, true)) {
        foreach (['/etc/snmp', '/var/lib/snmp/cert_indexes'] as $directory) {
            if (!is_dir($sandbox . $directory) && !mkdir($sandbox . $directory, 0755, true)) {
                throw new RuntimeException('无法创建 SNMP 启动资源目录');
            }
        }
        // 某些锁定 SDK 静态预置 SNMP；框架不使用 MIB，不隐式搜寻未声明的数据包。
        $configuration = "# 不加载宿主 MIB 或认证配置；专用 SNMP 业务须显式提供其运行资源。\nmibs :\n";
        if (file_put_contents($sandbox . '/etc/snmp/snmp.conf', $configuration) !== strlen($configuration)
            || !chmod($sandbox . '/etc/snmp/snmp.conf', 0644)) {
            throw new RuntimeException('无法保存 SNMP 启动配置');
        }
    }
}

/** 只清理本工具创建的单个目录，不跟随运行库符号链接。 */
function packageDiscard(string $sandbox): void
{
    $parent = realpath(dirname($sandbox));
    $expected = realpath(dirname(__DIR__) . '/build');
    if ($parent === false || $parent !== $expected || !preg_match('/^native-sandbox\.[A-Za-z0-9]+$/D', basename($sandbox)) || is_link($sandbox)) {
        throw new RuntimeException('拒绝清理非本轮隔离目录');
    }
    $sandbox = $parent . '/' . basename($sandbox);
    if (!is_dir($sandbox)) {
        return;
    }
    $mounts = PHP_OS_FAMILY === 'Linux' ? @file('/proc/self/mountinfo') : [];
    if ($mounts === false) {
        throw new RuntimeException('无法确认隔离目录的挂载状态，拒绝清理');
    }
    foreach ($mounts as $mount) {
        $parts = explode(' ', trim($mount));
        $point = strtr($parts[4] ?? '', ['\\040' => ' ', '\\011' => "\t", '\\012' => "\n", '\\134' => '\\']);
        if ($point === $sandbox || str_starts_with($point, $sandbox . '/')) {
            throw new RuntimeException('隔离目录仍有挂载，不能清理');
        }
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sandbox, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        if (is_link($path) || !$item->isDir()) {
            if (!unlink($path)) {
                throw new RuntimeException('无法清理本轮隔离文件');
            }
        } elseif (!rmdir($path)) {
            throw new RuntimeException('无法清理本轮隔离目录');
        }
    }
    if (!rmdir($sandbox)) {
        throw new RuntimeException('无法收回本轮隔离目录');
    }
}

try {
    if ($argc !== 4 || !in_array($argv[1], ['resources', 'ini', 'libraries', 'runtime', 'discard'], true)) {
        throw new InvalidArgumentException('用法：package-native-runtime.php <resources|ini|libraries|runtime|discard> <输入> <隔离目录>');
    }
    if ($argv[1] === 'resources') {
        Type\Build\BuildLock::run($argv[2] . '.lock', static function () use ($argv): void {
            packageResources($argv[2], $argv[3]);
        });
    } elseif ($argv[1] === 'ini') {
        packageIni($argv[2], $argv[3]);
    } elseif ($argv[1] === 'libraries') {
        packageLibraries($argv[2], $argv[3]);
        packageNetwork($argv[3]);
    } elseif ($argv[1] === 'runtime') {
        packageRuntime($argv[3]);
    } else {
        packageDiscard($argv[3]);
    }
} catch (Throwable $failure) {
    fwrite(STDERR, '原生打包失败：' . $failure->getMessage() . "\n");
    exit(1);
}
