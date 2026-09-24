<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 原生文件尾部身份清单，字节摘要与格式校验独立于运行入口。 */
final class ArtifactManifest
{
    private const MAGIC = 'TYPEAPP1';

    /**
     * 封存候选原生文件的身份；ELF/PE 追加清单，Mach-O 校验已内嵌清单。
     * @param array<string, mixed> $manifest 当前构建生成的身份清单。
     * @return array<string, mixed> 最终清单，不等于受信发布渠道的签名。
     */
    public function seal(string $artifact, array $manifest): array
    {
        BuildLock::path($artifact);
        $format = (new BuildPlatform())->assertArtifact($artifact);
        if (!preg_match('/^[a-f0-9]{64}$/D', $manifest['build-id'] ?? '')) {
            throw new RuntimeException('产物清单缺少合法构建身份');
        }
        if ($format === 'Mach-O') {
            $sealed = $this->readMachO($artifact);
            if ($sealed['build-id'] !== $manifest['build-id']) {
                throw new RuntimeException('Mach-O内嵌身份与当前构建不一致');
            }
            return $sealed;
        }
        $manifest['manifest-protocol'] = 1;
        $manifest['binary-format'] = $format;
        $manifest['elf-sha256'] = hash_file('sha256', $artifact);
        $manifest['elf-bytes'] = filesize($artifact);
        $json = json_encode(BuildIdentity::canonical($manifest), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > 2097152) {
            throw new RuntimeException('产物清单超过大小上限');
        }
        $tail = $json . pack('N', strlen($json)) . self::MAGIC;
        if (file_put_contents($artifact, $tail, FILE_APPEND) !== strlen($tail)) {
            throw new RuntimeException('无法写入产物身份');
        }
        clearstatcache(true, $artifact);
        return $manifest;
    }

    /**
     * 不执行产物即可读取并校验格式及字节身份；受信摘要须由外部交付记录提供。
     * @return array<string, mixed>
     * @throws RuntimeException 清单缺失、格式非法或与预期身份不一致。
     */
    public function read(string $artifact, ?string $expectedBuildId = null, ?string $expectedSha256 = null): array
    {
        clearstatcache(true, $artifact);
        $format = BuildPlatform::format($artifact, true);
        if ($format === 'Mach-O') {
            $manifest = $this->readMachO($artifact);
            if ($expectedBuildId !== null && $manifest['build-id'] !== $expectedBuildId) {
                throw new RuntimeException('产物不是要求的构建身份');
            }
            if ($expectedSha256 !== null && !hash_equals($expectedSha256, (string) hash_file('sha256', $artifact))) {
                throw new RuntimeException('产物摘要与受信任发布记录不一致');
            }
            return $manifest;
        }
        $size = filesize($artifact);
        if ($size < 16) {
            throw new RuntimeException('产物缺少身份清单');
        }
        $handle = fopen($artifact, 'rb');
        if ($handle === false) {
            throw new RuntimeException('无法读取产物身份');
        }
        try {
            fseek($handle, -12, SEEK_END);
            $trailer = fread($handle, 12);
            if (strlen($trailer) !== 12 || substr($trailer, 4) !== self::MAGIC) {
                throw new RuntimeException('产物缺少身份清单');
            }
            $length = unpack('Nlength', substr($trailer, 0, 4))['length'];
            if ($length < 2 || $length > 2097152 || $length + 12 >= $size) {
                throw new RuntimeException('产物身份长度无效');
            }
            fseek($handle, -12 - $length, SEEK_END);
            $manifest = json_decode((string) stream_get_contents($handle, $length), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($manifest) || ($manifest['manifest-protocol'] ?? null) !== 1 || ($manifest['elf-bytes'] ?? null) !== $size - $length - 12
                || !is_string($manifest['build-id'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $manifest['build-id'])) {
                throw new RuntimeException('产物身份格式无效');
            }
            if (($manifest['binary-format'] ?? 'ELF') !== $format) {
                throw new RuntimeException('二进制格式与产物清单不一致');
            }
            rewind($handle);
            $hash = hash_init('sha256');
            hash_update_stream($hash, $handle, $manifest['elf-bytes']);
            if (!hash_equals((string) ($manifest['elf-sha256'] ?? ''), hash_final($hash))) {
                throw new RuntimeException('原生字节与产物身份不一致');
            }
            if ($expectedBuildId !== null && !hash_equals($expectedBuildId, $manifest['build-id'])) {
                throw new RuntimeException('产物不是要求的构建身份');
            }
            if ($expectedSha256 !== null && !hash_equals($expectedSha256, (string) hash_file('sha256', $artifact))) {
                throw new RuntimeException('产物摘要与受信任发布记录不一致');
            }
            return $manifest;
        } finally {
            fclose($handle);
        }
    }

    /** 在部署环境对照已安装文件，调用方提供实际加载路径；不执行待检查 ELF。 */
    public function verifyRuntime(array $manifest, array $libraryPaths = []): void
    {
        $runtime = $manifest['runtime'] ?? [];
        foreach (['php' => PHP_VERSION, 'zts' => (bool) PHP_ZTS, 'architecture' => php_uname('m'), 'os' => PHP_OS_FAMILY] as $key => $value) {
            if (($runtime[$key] ?? null) !== $value) {
                throw new RuntimeException('运行环境与构建 ABI 不一致：' . $key);
            }
        }
        foreach ($runtime['extensions'] ?? [] as $extension => $version) {
            if (!extension_loaded($extension) || phpversion($extension) !== $version) {
                throw new RuntimeException('运行扩展与构建身份不一致：' . $extension);
            }
        }
        foreach ($runtime['functions'] ?? [] as $function) {
            if (!function_exists($function)) {
                throw new RuntimeException('运行环境缺少声明函数：' . $function);
            }
        }
        foreach ($manifest['native-libraries'] ?? [] as $library) {
            $name = $library['name'];
            $path = $libraryPaths[$name] ?? $library['path'];
            if (!is_file($path) || !hash_equals($library['sha256'], (string) hash_file('sha256', $path))) {
                throw new RuntimeException('运行库与构建身份不一致：' . $name);
            }
        }
        foreach ($manifest['system-cache-files'] ?? [] as $cache) {
            if (!is_file($cache['path']) || !hash_equals($cache['sha256'], (string) hash_file('sha256', $cache['path']))) {
                throw new RuntimeException('系统共享缓存与构建身份不一致');
            }
        }
    }

    /**
     * 校验产物旁资源代次的相对路径及摘要，拒绝越界和符号链接。
     * @param array<string, mixed> $manifest 已验证的产物身份清单。
     */
    public function verifyResources(string $artifact, array $manifest): void
    {
        $generation = $manifest['resource-generation'] ?? null;
        foreach ($manifest['resources'] ?? [] as $resource) {
            if (!is_string($generation) || !preg_match('/^[a-f0-9]{64}$/D', $generation) || !is_string($resource['target'] ?? null)
                || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._\/-]*$/D', $resource['target'])) {
                throw new RuntimeException('产物资源清单无效');
            }
            foreach (explode('/', $resource['target']) as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    throw new RuntimeException('产物资源目标越界');
                }
            }
            $file = $artifact . '.resources/' . $generation . '/' . $resource['target'];
            BuildLock::path($file);
            if (!is_file($file) || !hash_equals($resource['sha256'], (string) hash_file('sha256', $file))) {
                throw new RuntimeException('部署资源与构建清单不一致');
            }
        }
    }

    /**
     * 生成与应用一起编译的身份查询和运行校验类；返回 PHP 声明，不执行它。
     * @param array<string, mixed> $manifest 已确定的本次产物清单。
     */
    public function accessor(array $manifest): string
    {
        if (($manifest['runtime']['os'] ?? 'Linux') !== 'Linux') {
            return $this->portableAccessor($manifest);
        }
        $literal = var_export($manifest, true);
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace Type\\Generated;\n\nfinal class BuildIdentity\n{\n"
            . "    public static function info(): array { return {$literal}; }\n"
            . "    public static function verifyRuntime(array \$libraries = []): void\n    {\n"
            . "        \$manifest = self::info();\n"
            . "        \$packageRoot = self::packageRoot(\$manifest);\n"
            . "        foreach (['php' => PHP_VERSION, 'zts' => (bool) PHP_ZTS, 'architecture' => php_uname('m'), 'os' => PHP_OS_FAMILY] as \$key => \$actual) {\n"
            . "            if (\$manifest['runtime'][\$key] !== \$actual) { throw new \\RuntimeException('运行 ABI 与构建身份不一致：' . \$key); }\n        }\n"
            . "        foreach (\$manifest['runtime']['extensions'] as \$name => \$version) {\n"
            . "            if (!extension_loaded(\$name) || phpversion(\$name) !== \$version) { throw new \\RuntimeException('运行扩展身份不一致：' . \$name); }\n        }\n"
            . "        foreach (\$manifest['runtime']['functions'] ?? [] as \$functionName) {\n"
            . "            if (!function_exists(\$functionName)) { throw new \\RuntimeException('运行环境缺少声明函数：' . \$functionName); }\n        }\n"
            . "        \$mappings = @file('/proc/self/maps') ?: [];\n"
            . "        foreach (\$manifest['native-libraries'] as \$library) {\n"
            . "            \$path = self::libraryPath(\$library, \$libraries, \$packageRoot);\n"
            . "            if (!is_file(\$path) || hash_file('sha256', \$path) !== \$library['sha256']) { throw new \\RuntimeException('运行库身份不一致：' . \$library['name']); }\n"
            . "            \$loaded = false; \$checked = [];\n"
            . "            foreach (\$mappings as \$mapping) {\n"
            . "                if (preg_match('~(/[^\\n]+)$~', trim(\$mapping), \$match) && basename(\$match[1]) === basename((string) realpath(\$path))) {\n"
            . "                    if (isset(\$checked[\$match[1]])) { continue; }\n"
            . "                    if (!is_file(\$match[1]) || hash_file('sha256', \$match[1]) !== \$library['sha256']) { throw new \\RuntimeException('实际加载的运行库身份不一致'); }\n"
            . "                    \$loaded = true; \$checked[\$match[1]] = true;\n                }\n            }\n"
            . "            if (!\$loaded) { throw new \\RuntimeException('无法确认运行库已经按声明加载：' . \$library['name']); }\n        }\n    }\n"
            . "    /** Linux没有dyld共享缓存，部署审计复用全部实际运行库的字节校验。 */\n"
            . "    public static function verifyDeployment(array \$libraries = []): void { self::verifyRuntime(\$libraries); }\n"
            . (new PackageRuntime())->methods() . "\n}\n";
    }

    private function portableAccessor(array $manifest): string
    {
        $code = <<<'PHP'
<?php

declare(strict_types=1);

namespace Type\Generated;

/** 本次编译身份和系统加载器证据；不存在时明确失败。 */
final class BuildIdentity
{
    public static function info(): array { return TYPE_MANIFEST_LITERAL; }

    public static function verifyRuntime(array $libraries = []): void
    {
        $manifest = self::info();
        $packageRoot = self::packageRoot($manifest);
        foreach (['php' => PHP_VERSION, 'zts' => (bool) PHP_ZTS, 'architecture' => php_uname('m'), 'os' => PHP_OS_FAMILY] as $key => $actual) {
            if ($manifest['runtime'][$key] !== $actual) { throw new \RuntimeException('运行ABI与构建身份不一致：' . $key); }
        }
        foreach ($manifest['runtime']['extensions'] as $extension => $version) {
            if (!extension_loaded($extension) || phpversion($extension) !== $version) { throw new \RuntimeException('运行扩展身份不一致：' . $extension); }
        }
        foreach ($manifest['runtime']['functions'] ?? [] as $functionName) {
            if (!function_exists($functionName)) { throw new \RuntimeException('运行环境缺少声明函数：' . $functionName); }
        }
        $images = \type_app_native_loaded_images();
        if ($images === []) { throw new \RuntimeException('无法读取当前进程的原生加载器映像'); }
        foreach ($manifest['native-libraries'] as $library) {
            $path = self::libraryPath($library, $libraries, $packageRoot);
            if (!is_file($path) || (PHP_OS_FAMILY === 'Darwin' ? \type_app_native_file_sha256($path) : hash_file('sha256', $path)) !== $library['sha256']) { throw new \RuntimeException('运行库身份不一致：' . $library['name']); }
            $expectedPath = (string) realpath($path);
            $expectedKey = PHP_OS_FAMILY === 'Windows' ? strtolower(str_replace('\\', '/', $expectedPath)) : $expectedPath;
            $loaded = false;
            foreach ($images as $image) {
                $parts = explode("\n", $image, 2);
                $imagePath = $parts[0];
                if (!is_file($imagePath)) { continue; }
                $actualPath = (string) realpath($imagePath);
                $actualKey = PHP_OS_FAMILY === 'Windows' ? strtolower(str_replace('\\', '/', $actualPath)) : $actualPath;
                if ($actualKey === $expectedKey) {
                    if ((PHP_OS_FAMILY === 'Darwin' ? \type_app_native_file_sha256($actualPath) : hash_file('sha256', $actualPath)) !== $library['sha256']) { throw new \RuntimeException('实际加载运行库的字节不一致'); }
                    $loaded = true;
                }
            }
            // 延迟依赖（如 libmpdec++）常在导入表中但不映射；同名副本若从旁路目录映射也不按缺载失败。
            if (!$loaded && !($library['deferred'] ?? false)) { throw new \RuntimeException('运行库未按声明路径实际加载：' . $library['name']); }
        }
        $packagedNames = [];
        foreach ($manifest['native-libraries'] as $library) {
            $packagedNames[basename((string) $library['path'])] = true;
            $packagedNames[(string) $library['name']] = true;
        }
        foreach ($manifest['system-images'] as $systemImage) {
            $found = false;
            $declaredPath = (string) $systemImage['path'];
            $declaredUuid = strtoupper((string) $systemImage['uuid']);
            $declaredBase = basename($declaredPath);
            foreach ($images as $image) {
                $parts = explode("\n", $image, 2);
                $imagePath = $parts[0];
                $imageUuid = strtoupper($parts[1] ?? '');
                // dyld 可能以 Cryptex/沙箱前缀报告同一系统库；UUID 优先，路径后缀兜底。
                if ($declaredUuid !== '' && $imageUuid === $declaredUuid) { $found = true; break; }
                if ($imagePath === $declaredPath || str_ends_with($imagePath, $declaredPath)) { $found = true; break; }
                // 打包进产物的同名运行库会遮蔽系统桩（如 Homebrew libsqlite3 替代 /usr/lib/libsqlite3.dylib）。
                if (isset($packagedNames[$declaredBase]) && basename($imagePath) === $declaredBase) { $found = true; break; }
            }
            if (!$found) { throw new \RuntimeException('系统共享映像未以声明UUID加载：' . $declaredPath); }
        }
    }

    /** 部署或系统更新后显式执行完整审计；系统缓存摘要不充当每次启动的I/O负担。 */
    public static function verifyDeployment(array $libraries = []): void
    {
        self::verifyRuntime($libraries);
        $manifest = self::info();
        foreach ($manifest['system-cache-files'] as $cache) {
            $digest = PHP_OS_FAMILY === 'Darwin' ? \type_app_native_file_sha256($cache['path']) : hash_file('sha256', $cache['path']);
            if (!is_file($cache['path']) || $digest !== $cache['sha256']) { throw new \RuntimeException('系统dyld共享缓存字节不一致'); }
        }
    }
    // TYPE_PACKAGE_RUNTIME_METHODS
}
PHP;
        return str_replace(['TYPE_MANIFEST_LITERAL', '    // TYPE_PACKAGE_RUNTIME_METHODS'], [var_export($manifest, true), (new PackageRuntime())->methods($manifest['runtime']['os'])], $code);
    }

    /** Mach-O清单在链接之前进入独立段，最终Apple代码签名覆盖清单与全部代码。 */
    public function machOSource(array $manifest): string
    {
        $manifest['manifest-protocol'] = 2;
        $manifest['binary-format'] = 'Mach-O';
        $json = json_encode(BuildIdentity::canonical($manifest), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > 2097152) {
            throw new RuntimeException('产物清单超过大小上限');
        }
        return '__attribute__((used, section("__DATA,__typeapp"))) static const unsigned char type_app_manifest[] = {'
            . implode(',', array_values(unpack('C*', $json))) . "};\n";
    }

    /** 仅静态读取专用Mach-O段；codesign验证覆盖所有页，不执行待验收文件。 */
    private function readMachO(string $artifact): array
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            throw new RuntimeException('Mach-O签名完整性须在macOS验证器中核对');
        }
        $header = (string) file_get_contents($artifact, false, null, 0, 32);
        if (substr($header, 0, 4) !== "\xcf\xfa\xed\xfe") {
            throw new RuntimeException('当前Mach-O身份协议要求单架构64位产物');
        }
        $commands = unpack('Vcount/Vbytes', substr($header, 16, 8));
        if ($commands['count'] > 65536 || $commands['bytes'] > 16777216 || 32 + $commands['bytes'] > filesize($artifact)) {
            throw new RuntimeException('Mach-O载入命令范围无效');
        }
        $table = (string) file_get_contents($artifact, false, null, 32, $commands['bytes']);
        $cursor = 0;
        $json = null;
        for ($index = 0; $index < $commands['count']; $index++) {
            if ($cursor + 8 > strlen($table)) {
                throw new RuntimeException('Mach-O命令头越界');
            }
            $command = unpack('Vtype/Vsize', substr($table, $cursor, 8));
            if ($command['size'] < 8 || $cursor + $command['size'] > strlen($table)) {
                throw new RuntimeException('Mach-O命令长度无效');
            }
            if ($command['type'] === 0x19) {
                if ($command['size'] < 72) {
                    throw new RuntimeException('Mach-O段命令不完整');
                }
                $sections = unpack('V', substr($table, $cursor + 64, 4))[1];
                if (72 + 80 * $sections > $command['size']) {
                    throw new RuntimeException('Mach-O段表越界');
                }
                for ($section = 0; $section < $sections; $section++) {
                    $position = $cursor + 72 + 80 * $section;
                    if (rtrim(substr($table, $position, 16), "\0") !== '__typeapp') {
                        continue;
                    }
                    $length = unpack('P', substr($table, $position + 40, 8))[1];
                    $offset = unpack('V', substr($table, $position + 48, 4))[1];
                    if ($json !== null || $length < 2 || $length > 2097152 || $offset + $length > filesize($artifact)) {
                        throw new RuntimeException('Mach-O身份段重复或越界');
                    }
                    $json = file_get_contents($artifact, false, null, $offset, $length);
                }
            }
            $cursor += $command['size'];
        }
        if (!is_string($json)) {
            throw new RuntimeException('Mach-O缺少编译内身份段');
        }
        $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['manifest-protocol'] ?? null) !== 2 || ($manifest['binary-format'] ?? null) !== 'Mach-O'
            || !preg_match('/^[a-f0-9]{64}$/D', $manifest['build-id'] ?? '')) {
            throw new RuntimeException('Mach-O身份格式无效');
        }
        (new BuildEnvironment())->run(['/usr/bin/codesign', '--verify', '--strict', $artifact], dirname($artifact), (new BuildPlatform())->environment('', ''), 30);
        $manifest['binary-sha256'] = hash_file('sha256', $artifact);
        $manifest['binary-bytes'] = filesize($artifact);
        return $manifest;
    }

}
