<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

/** 固定当前 ZTS 安装的 SDK，隔离系统前缀下其他同版本 php-config 的干扰。 */
function sdkCommand(string $configuration, string $option): string
{
    $process = proc_open([$configuration, $option], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('无法读取指定 PHP SDK 配置');
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0 || $output === false || trim($output) === '') {
        throw new RuntimeException('PHP SDK 配置没有返回有效值：' . $option);
    }
    return trim($output);
}

function sdkLink(string $source, string $target): void
{
    if (is_link($target)) {
        if (readlink($target) !== $source) {
            throw new RuntimeException('既有 SDK 链接与锁定来源不符');
        }
    } elseif (file_exists($target) || !symlink($source, $target)) {
        throw new RuntimeException('无法创建独立 SDK 链接');
    }
}

/**
 * 保存不可变模块副本，避免扩展目录内的外部软链接越过构建身份边界。
 *
 * @throws RuntimeException 既有目标不匹配、源字节变化或无法无覆盖地发布副本。
 */
function sdkModuleCopy(string $source, string $target, string $sha256): void
{
    if (is_link($target) || (file_exists($target) && (!is_file($target) || hash_file('sha256', $target) !== $sha256))) {
        throw new RuntimeException('既有SDK模块副本已被修改');
    }
    if (is_file($target)) {
        return;
    }
    $temporary = dirname($target) . '/.sdk-module-' . bin2hex(random_bytes(6));
    try {
        if (!copy($source, $temporary) || hash_file('sha256', $temporary) !== $sha256 || !chmod($temporary, 0600)
            || !link($temporary, $target)) {
            throw new RuntimeException('无法原子保存与来源摘要一致的SDK模块');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

/**
 * 为显式补充模块生成独立扩展视图，不安装或覆盖原SDK中的文件。
 *
 * @param list<string> $declarations 扩展名=共享模块绝对路径；同名声明只允许一次。
 * @return array<string,array{file:string,sha256:string}> 扩展文件名到实际字节来源；包含原SDK的其他模块。
 * @throws RuntimeException 声明、原SDK目录、模块格式或文件读取无效。
 */
function sdkExtensions(string $configuration, array $declarations): array
{
    $sourceDirectory = realpath(sdkCommand($configuration, '--extension-dir'));
    if ($sourceDirectory === false || !is_dir($sourceDirectory) || count($declarations) > 128) {
        throw new RuntimeException('需要现有SDK扩展目录和有限数量的显式模块');
    }
    $sources = [];
    $entries = scandir($sourceDirectory);
    if ($entries === false) {
        throw new RuntimeException('无法读取原SDK扩展目录');
    }
    foreach ($entries as $filename) {
        if (str_ends_with($filename, '.so') && is_file($sourceDirectory . '/' . $filename)) {
            $sources[$filename] = $sourceDirectory . '/' . $filename;
        }
    }
    $declared = [];
    foreach ($declarations as $declaration) {
        if (preg_match('/^([a-z_][a-z0-9_]*)=(\/[^\x00-\x1f\x7f]+)$/D', $declaration, $parts) !== 1 || isset($declared[$parts[1]])) {
            throw new RuntimeException('补充模块使用不重复的扩展名=绝对路径');
        }
        $declared[$parts[1]] = true;
        $sources[$parts[1] . '.so'] = $parts[2];
    }
    $files = [];
    foreach ($sources as $filename => $source) {
        $file = realpath($source);
        if (preg_match('/^[A-Za-z0-9_][A-Za-z0-9._+\-]*\.so$/D', $filename) !== 1 || $file === false || !is_file($file)
            || \Type\Build\BuildPlatform::format($file) !== (PHP_OS_FAMILY === 'Darwin' ? 'Mach-O' : 'ELF')) {
            throw new RuntimeException('SDK扩展不是本平台的有效共享模块：' . $filename);
        }
        $hash = hash_file('sha256', $file);
        if (!is_string($hash)) {
            throw new RuntimeException('无法读取SDK扩展摘要');
        }
        $files[$filename] = ['file' => $file, 'sha256' => $hash];
    }
    ksort($files);
    return $files;
}

try {
    $target = $argv[1] ?? '';
    $configuration = isset($argv[2]) ? realpath($argv[2]) : false;
    if ($argc < 3 || !str_starts_with($target, '/') || str_contains($target, "\0") || $configuration === false || !is_executable($configuration)) {
        throw new InvalidArgumentException('用法：php tools/configure-toolchain.php <SDK 缓存绝对目录> <当前 php-config 绝对路径> [扩展名=模块绝对路径 ...]');
    }
    $lock = json_decode(file_get_contents(dirname(__DIR__) . '/toolchain.lock.json'), true, 512, JSON_THROW_ON_ERROR);
    if (!in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true) || PHP_VERSION !== $lock['php'] || (bool) PHP_ZTS !== $lock['zts'] || sdkCommand($configuration, '--version') !== PHP_VERSION) {
        throw new RuntimeException('当前 PHP 与 SDK 必须匹配锁定的 Unix ZTS 工具链');
    }
    $libraryName = PHP_OS_FAMILY === 'Darwin' ? 'libphp.dylib' : 'libphp.so';
    $prefix = sdkCommand($configuration, '--prefix');
    $include = realpath(sdkCommand($configuration, '--include-dir'));
    $library = realpath($prefix . '/lib/' . $libraryName);
    $binary = realpath(PHP_BINARY);
    if ($include === false || $library === false || $binary === false || !is_file($include . '/main/php_config.h')
        || !preg_match('/^#define ZTS 1$/m', file_get_contents($include . '/main/php_config.h'))) {
        throw new RuntimeException('指定 SDK 缺少当前 ZTS 头文件或 embed 共享库');
    }
    $identity = ['protocol' => 1, 'platform' => PHP_OS_FAMILY, 'php' => PHP_VERSION, 'binary' => $binary, 'configuration' => $configuration,
        'configuration-sha256' => hash_file('sha256', $configuration), 'library' => $library, 'library-sha256' => hash_file('sha256', $library), 'include' => $include];
    $extensionFiles = $argc > 3 ? sdkExtensions($configuration, array_slice($argv, 3)) : [];
    if ($extensionFiles !== []) {
        $identity['extension-directory'] = realpath(sdkCommand($configuration, '--extension-dir'));
        $identity['extension-files'] = $extensionFiles;
    }
    $json = json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    $directory = rtrim($target, '/') . '/' . hash('sha256', $json);
    foreach (['bin', 'lib', 'include'] as $part) {
        if (!is_dir($directory . '/' . $part) && !mkdir($directory . '/' . $part, 0700, true)) {
            throw new RuntimeException('无法创建独立 SDK 目录');
        }
    }
    sdkLink($binary, $directory . '/bin/php');
    sdkLink($library, $directory . '/lib/' . $libraryName);
    sdkLink($include, $directory . '/include/php');
    $extensionCase = '';
    if ($extensionFiles !== []) {
        $extensionDirectory = $directory . '/lib/php/extensions/' . basename($identity['extension-directory']);
        if (!is_dir($extensionDirectory) && !mkdir($extensionDirectory, 0700, true)) {
            throw new RuntimeException('无法创建独立SDK扩展目录');
        }
        foreach ($extensionFiles as $filename => $extensionFile) {
            sdkModuleCopy($extensionFile['file'], $extensionDirectory . '/' . $filename, $extensionFile['sha256']);
        }
        $extensionCase = '  --extension-dir) printf \'%s\\n\' ' . escapeshellarg($extensionDirectory) . ";;\n";
    }
    $wrapper = "#!/bin/sh\ncase \"\$1\" in\n"
        . '  --prefix) printf \'%s\\n\' ' . escapeshellarg($directory) . ";;\n"
        . '  --lib-dir) printf \'%s\\n\' ' . escapeshellarg($directory . '/lib') . ";;\n"
        . '  --lib-embed) printf \'%s\\n\' ' . escapeshellarg($directory . '/lib/' . $libraryName) . ";;\n"
        . $extensionCase
        . '  *) exec ' . escapeshellarg($configuration) . " \"\$@\";;\nesac\n";
    foreach (['bin/php-config' => $wrapper, 'identity.json' => $json] as $relative => $contents) {
        $file = $directory . '/' . $relative;
        if (is_file($file)) {
            if (file_get_contents($file) !== $contents) {
                throw new RuntimeException('已有 SDK 配置被修改');
            }
        } elseif (file_put_contents($file, $contents) !== strlen($contents)) {
            throw new RuntimeException('无法保存独立 SDK 配置');
        }
    }
    if (!chmod($directory . '/bin/php-config', 0700)) {
        throw new RuntimeException('无法设置 SDK 配置的执行权限');
    }
    putenv('PHP_HOME=' . $directory);
    $platform = PHP_OS_FAMILY === 'Darwin' ? new TypePhp\Platform\Macos() : new TypePhp\Platform\Linux();
    $resolved = $platform->detectPhpLibs($directory);
    if (realpath($resolved['embed'] ?? '') !== $library) {
        throw new RuntimeException('TypePHP 与当前 PHP 选中了不同的 embed 库');
    }
    foreach ($extensionFiles as $extensionFile) {
        if (hash_file('sha256', $extensionFile['file']) !== $extensionFile['sha256']) {
            throw new RuntimeException('SDK配置期间共享模块发生变化');
        }
    }
    echo $directory . "\n";
} catch (Throwable $failure) {
    fwrite(STDERR, '工具链配置失败：' . $failure->getMessage() . "\n");
    exit(1);
}
