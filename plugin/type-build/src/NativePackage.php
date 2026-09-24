<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/**
 * 发布目录的唯一构造与校验入口。只复制已封装身份中的原生库与资源，不执行应用。
 *
 * 发布目录不是可信来源本身；分发方必须在独立受信记录中保留返回的清单SHA-256。
 */
final class NativePackage
{
    /**
     * 顶层 LICENSE/NOTICE 来自构建身份绑定的应用材料，依赖原文保留在各自的资源索引中。
     *
     * @return array{directory:string, manifest-sha256:string, build-id:string, files:int}
     * @throws RuntimeException 输入不完整、依赖被改动、配置携密或目标已存在。
     */
    public function create(string $artifact, string $destination, ?string $configurationExample = null): array
    {
        if (PHP_OS_FAMILY === 'Windows' && !is_file($artifact)) {
            $artifact = (new BuildPlatform())->output($artifact);
        }
        $artifact = BuildPlatform::resolve($artifact);
        $platform = new BuildPlatform();
        $platform->assertArtifact($artifact);
        $reader = new ArtifactManifest();
        $manifest = $reader->read($artifact);
        if (($manifest['runtime']['os'] ?? null) !== PHP_OS_FAMILY || ($manifest['generator-protocols']['identity'] ?? 0) < 3
            || !is_array($manifest['extension-modules'] ?? null)) {
            throw new RuntimeException('发布需要同平台身份生成协议3产物，请先重新构建');
        }
        $reader->verifyResources($artifact, $manifest);
        $applicationMaterials = $this->applicationMaterials($artifact, $manifest);
        // 协议3的原生校验固定要求这两个文件；不能伪造材料以迁就旧启动器。
        if (($manifest['generator-protocols']['identity'] ?? 0) < 4 && !isset($applicationMaterials['LICENSE'], $applicationMaterials['NOTICE'])) {
            throw new RuntimeException('旧产物的发布校验要求 LICENSE 和 NOTICE；应用材料不齐时请使用身份生成协议4重新构建');
        }
        $parent = BuildPlatform::resolve(dirname($destination));
        $leaf = basename($destination);
        if ($leaf === '' || $leaf === '.' || $leaf === '..' || preg_match('/[\x00-\x1f\x7f]/', $leaf)) {
            throw new RuntimeException('发布目录名称无效');
        }
        $destination = $parent . '/' . $leaf;
        BuildLock::path($destination);
        if (file_exists($destination) || is_link($destination)) {
            throw new RuntimeException('发布目录已存在，不能覆盖已部署版本');
        }
        $example = $configurationExample === null ? "APP_ENV=production\nAPP_DEBUG=false\nAPP_API_TOKEN=\n" : $this->example($configurationExample);
        $libraries = [];
        $libraryDirectory = PHP_OS_FAMILY === 'Windows' ? 'bin' : 'lib';
        foreach ($manifest['native-libraries'] ?? [] as $library) {
            if (!is_array($library) || !is_string($library['name'] ?? null) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._+\-]*$/D', $library['name']) !== 1
                || !is_string($library['path'] ?? null) || !is_string($library['sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $library['sha256']) !== 1) {
                throw new RuntimeException('发布运行库声明无效');
            }
            if (!empty($library['system'])) {
                if (PHP_OS_FAMILY !== 'Windows' || !BuildPlatform::contains((string) getenv('SystemRoot'), $library['path'])) {
                    throw new RuntimeException('系统库标记与操作系统目录不一致');
                }
                continue;
            }
            if (BuildPlatform::format($library['path']) !== $manifest['binary-format']) {
                throw new RuntimeException('运行库不是目标平台的原生格式');
            }
            if (isset($libraries[$library['name']])) {
                throw new RuntimeException('发布运行库名称重复');
            }
            $libraries[$library['name']] = $library;
        }
        if ($libraries === []) {
            throw new RuntimeException('发布缺少已验证的应用运行库');
        }
        $extensions = $manifest['extension-modules'];
        foreach ($extensions as $extension => $filename) {
            if (!is_string($extension) || preg_match('/^[a-z_][a-z0-9_]*$/iD', $extension) !== 1
                || !is_string($filename) || !isset($libraries[$filename])) {
                throw new RuntimeException('运行扩展未包含在原生库闭包中');
            }
        }
        $binary = 'bin/app' . $platform->executableSuffix();
        $interpreter = $this->interpreter($artifact, $manifest, $libraries);
        $stage = $destination . '.building-' . bin2hex(random_bytes(6));
        if (!mkdir($stage, 0700)) {
            throw new RuntimeException('无法创建发布暂存目录');
        }
        $files = [];
        try {
            $platform->privateCache($stage, true);
            $this->copy($artifact, $stage, $binary, (string) hash_file('sha256', $artifact), 'application', $files, 0755);
            foreach ($libraries as $library) {
                $this->copy($library['path'], $stage, $libraryDirectory . '/' . $library['name'], $library['sha256'], 'native-library', $files, 0755);
            }
            foreach ($manifest['resources'] ?? [] as $resource) {
                $relative = $manifest['resource-generation'] . '/' . $resource['target'];
                $source = $artifact . '.resources/' . $relative;
                if (preg_match('/\.(?:php[0-9]?|phtml|phar|inc)$/iD', $resource['target'])) {
                    throw new RuntimeException('发布资源不允许包含PHP源码或私钥');
                }
                $this->assertData($source);
                $this->copy($source, $stage, $binary . '.resources/' . $relative, $resource['sha256'], 'resource', $files);
            }
            $this->write($stage, 'config/env.example', $example, 'configuration-example', $files);
            $ini = (new RuntimeIni())->generate($extensions, $libraryDirectory);
            $this->write($stage, 'runtime/php.ini', $ini, 'runtime-configuration', $files);
            $this->directory($stage . '/runtime/empty');
            // SDK 常静态预置 SNMP；关闭隐式 MIB 搜索，避免宿主/Homebrew 路径污染启动标准流。
            $this->directory($stage . '/runtime/snmp');
            $this->directory($stage . '/runtime/snmp/persist');
            $this->write($stage, 'runtime/snmp/snmp.conf', "# 不加载宿主 MIB 或认证配置；专用 SNMP 业务须显式提供其运行资源。\nmibs :\n", 'runtime-configuration', $files);
            $launcher = PHP_OS_FAMILY === 'Windows' ? 'run.cmd' : 'run';
            $this->write($stage, $launcher, $this->launcher($binary, $interpreter), 'launcher', $files, 0755);
            $instructions = "# 原生发布目录\n\n本目录不包含业务PHP源码、Composer或编译工具链。\n\n1. 从独立受信渠道核对release.json的SHA-256及发布来源。\n2. 将config/env.example复制为.env并填写实际配置，或通过外部环境/APP_BASE_PATH指定配置。秘密不应回写发布包。\n3. 执行./run verify-runtime（Windows为run.cmd verify-runtime）完成目标机审计，再按应用help执行迁移与启动。\n4. 数据、日志和.env由部署环境维护；升级采用新的版本目录，不覆盖旧目录。数据库变更的回滚能力由迁移计划决定。\n\n启动时检查文件字节与实际加载库，不替代发布渠道的真实性验证或操作系统安全。构建报告中的系统版本/映像要求仍适用；未经对应平台验收不得宣称支持。\n";
            $this->write($stage, 'DEPLOY.md', $instructions, 'deployment-instructions', $files);
            $operations = file_get_contents(dirname(__DIR__) . '/docs/operations.md');
            if (!is_string($operations) || $operations === '' || strlen($operations) > 262144) {
                throw new RuntimeException('构建组件缺少完整且有界的部署恢复操作手册');
            }
            $this->write($stage, 'OPERATIONS.md', $operations, 'deployment-instructions', $files);
            foreach ($applicationMaterials as $firstPartyMaterial => $contents) {
                $this->write($stage, $firstPartyMaterial, $contents, 'first-party-license-material', $files);
            }
            $notices = $manifest['dependency-notices'] ?? null;
            if (is_array($notices)) {
                $index = $binary . '.resources/' . $manifest['resource-generation'] . '/' . $notices['index'];
                if (!isset($files[$index]) || $files[$index]['sha256'] !== ($notices['index-sha256'] ?? null)) {
                    throw new RuntimeException('发布缺少构建内绑定的依赖材料索引');
                }
                $this->write($stage, 'NOTICES.md', "# 依赖声明与原始材料\n\n索引：`" . $index . "`。\n\n材料覆盖状态：" . $notices['material-coverage']
                    . "。缺失项保留在索引中，不等于许可兼容性或分发授权已审查。原始文本按摘要保存，未改写许可。\n", 'dependency-notices-guide', $files);
            }
            ksort($files);
            $release = ['protocol' => 1, 'application' => $manifest['application'], 'version' => $manifest['version'],
                'artifact' => ['path' => $binary, 'build-id' => $manifest['build-id'], 'sha256' => $files[$binary]['sha256']],
                'runtime' => $manifest['runtime'], 'native-libraries' => $manifest['native-libraries'],
                'system-images' => $manifest['system-images'] ?? [], 'system-cache-policy' => $manifest['system-cache-policy'] ?? 'not-applicable',
                'delay-imports' => $manifest['delay-imports'] ?? [],
                'extension-modules' => $extensions, 'production-packages' => $manifest['production-packages'] ?? [],
                'dependency-notices' => $notices,
                'files' => $files];
            $json = json_encode(BuildIdentity::canonical($release), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (file_put_contents($stage . '/release.json', $json, LOCK_EX) !== strlen($json)) {
                throw new RuntimeException('无法写入发布清单');
            }
            $digest = hash('sha256', $json);
            $this->verify($stage, $digest);
            if (!rename($stage, $destination)) {
                throw new RuntimeException('无法原子发布新目录');
            }
            return ['directory' => $destination, 'manifest-sha256' => $digest, 'build-id' => $manifest['build-id'], 'files' => count($files)];
        } catch (\Throwable $error) {
            $this->removeStage($stage);
            throw $error;
        }
    }

    /**
     * 按外部受信摘要读取发布清单，核对载荷与内嵌身份，不执行应用。
     *
     * @return array{protocol: int, version: string, artifact: array{path: string, build-id: string, sha256: string}, runtime: array{php: string, zts: bool, architecture: string, os: string, extensions: array<string, string|false>, ...}, files: array<string, array{sha256: string, bytes: int, ...}>, ...}
     * @throws RuntimeException 清单摘要、载荷路径/字节/权限或内嵌发布身份不一致。
     * @throws \JsonException 发布清单不是有效JSON。
     */
    public function verify(string $directory, string $expectedManifestSha256): array
    {
        $directory = BuildPlatform::resolve($directory);
        $descriptor = $directory . '/release.json';
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedManifestSha256) !== 1 || !is_file($descriptor)
            || is_link($descriptor) || filesize($descriptor) > 4194304
            || !hash_equals($expectedManifestSha256, (string) hash_file('sha256', $descriptor))) {
            throw new RuntimeException('发布清单与受信摘要不一致');
        }
        $release = json_decode((string) file_get_contents($descriptor), true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($release) || ($release['protocol'] ?? null) !== 1 || !is_array($release['files'] ?? null)
            || count($release['files']) < 2 || count($release['files']) > 4096) {
            throw new RuntimeException('发布清单结构无效');
        }
        foreach ($release['files'] as $relative => $entry) {
            if (!is_string($relative) || !is_array($entry) || !is_string($entry['sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $entry['sha256']) !== 1 || !is_int($entry['bytes'] ?? null)) {
                throw new RuntimeException('发布文件声明无效');
            }
            $file = $this->file($directory, $relative);
            if (filesize($file) !== $entry['bytes'] || !hash_equals($entry['sha256'], (string) hash_file('sha256', $file))) {
                throw new RuntimeException('发布文件被修改：' . $relative);
            }
        }
        $binary = $release['artifact']['path'] ?? '';
        if (!in_array($binary, ['bin/app', 'bin/app.exe'], true) || !isset($release['files'][$binary])) {
            throw new RuntimeException('发布缺少原生产物');
        }
        $launcher = ($release['runtime']['os'] ?? '') === 'Windows' ? 'run.cmd' : 'run';
        foreach ([$launcher, 'runtime/php.ini', 'config/env.example', 'DEPLOY.md'] as $requiredFile) {
            if (!isset($release['files'][$requiredFile])) {
                throw new RuntimeException('发布清单遗漏必需文件：' . $requiredFile);
            }
        }
        if (($release['runtime']['os'] ?? '') !== 'Windows' && (!is_executable($this->file($directory, $binary)) || !is_executable($this->file($directory, 'run')))) {
            throw new RuntimeException('发布程序或启动器缺少执行权限');
        }
        $manifest = (new ArtifactManifest())->read($this->file($directory, $binary), $release['artifact']['build-id'], $release['artifact']['sha256']);
        if (($release['dependency-notices'] ?? null) !== ($manifest['dependency-notices'] ?? null)) {
            throw new RuntimeException('发布的依赖材料状态与编译身份不一致');
        }
        if (is_array($manifest['dependency-notices'] ?? null)) {
            $noticeIndex = $binary . '.resources/' . $manifest['resource-generation'] . '/' . $manifest['dependency-notices']['index'];
            if (!isset($release['files']['NOTICES.md'], $release['files'][$noticeIndex])
                || $release['files'][$noticeIndex]['sha256'] !== $manifest['dependency-notices']['index-sha256']) {
                throw new RuntimeException('发布遗漏或改变了依赖材料索引');
            }
        }
        if ($release['version'] !== $manifest['version'] || $release['runtime'] !== $manifest['runtime']) {
            throw new RuntimeException('发布元数据与编译产物不一致');
        }
        foreach ($manifest['native-libraries'] as $library) {
            if (!empty($library['system'])) {
                continue;
            }
            $name = (($manifest['runtime']['os'] ?? '') === 'Windows' ? 'bin/' : 'lib/') . $library['name'];
            if (($release['files'][$name]['sha256'] ?? null) !== $library['sha256']) {
                throw new RuntimeException('发布运行库不属于编译身份');
            }
        }
        (new ArtifactManifest())->verifyResources($directory . '/' . $binary, $manifest);
        $applicationMaterials = $this->applicationMaterials($directory . '/' . $binary, $manifest);
        foreach (['LICENSE', 'NOTICE'] as $name) {
            $expected = isset($applicationMaterials[$name]) ? hash('sha256', $applicationMaterials[$name]) : null;
            if (($release['files'][$name]['sha256'] ?? null) !== $expected) {
                throw new RuntimeException('发布第一方材料不属于编译应用：' . $name);
            }
        }
        return $release;
    }

    /**
     * 从已校验的产物资源读取应用原始材料，不依赖工作目录、应用源码或构建工具的许可证。
     *
     * @param array<string, mixed> $manifest 当前产物的内嵌身份，调用前已核对全部资源摘要。
     * @return array<string, string> 实际存在的 LICENSE 和 NOTICE 原文；完整性策略沿用构建时 notices.require-complete。
     * @throws RuntimeException 应用材料归属含糊、资源声明或内容与构建身份不一致。
     */
    private function applicationMaterials(string $artifact, array $manifest): array
    {
        $notices = $manifest['dependency-notices'] ?? null;
        if ($notices === null) {
            return [];
        }
        if (!is_array($notices) || !is_string($notices['index'] ?? null) || !is_string($notices['index-sha256'] ?? null)) {
            throw new RuntimeException('产物缺少应用许可证材料索引，请重新构建');
        }
        $resources = array_column($manifest['resources'], null, 'target');
        $resourceRoot = $artifact . '.resources/' . $manifest['resource-generation'];
        $index = $this->file($resourceRoot, $notices['index']);
        if (($resources[$notices['index']]['sha256'] ?? null) !== $notices['index-sha256'] || filesize($index) > 4194304
            || !hash_equals($notices['index-sha256'], (string) hash_file('sha256', $index))) {
            throw new RuntimeException('应用许可证材料索引与构建身份不一致');
        }
        $metadata = json_decode((string) file_get_contents($index), true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($metadata) || ($metadata['protocol'] ?? null) !== 1 || !is_array($metadata['components'] ?? null)) {
            throw new RuntimeException('应用许可证材料索引结构无效');
        }
        $applications = array_values(array_filter($metadata['components'], static fn (mixed $component): bool => is_array($component) && ($component['kind'] ?? null) === 'application'));
        if (count($applications) !== 1 || !is_array($applications[0]['documents'] ?? null)) {
            throw new RuntimeException('产物需要唯一的应用许可证材料归属');
        }
        $materials = [];
        foreach ($applications[0]['documents'] as $document) {
            if (!is_array($document) || !in_array($document['name'] ?? null, ['LICENSE', 'NOTICE'], true)) {
                continue;
            }
            $name = $document['name'];
            $resource = $document['resource'] ?? null;
            $sha = $document['sha256'] ?? null;
            if (isset($materials[$name]) || !is_string($resource) || !is_string($sha)
                || preg_match('/^[a-f0-9]{64}$/D', $sha) !== 1 || ($resources[$resource]['sha256'] ?? null) !== $sha
                || !is_int($document['bytes'] ?? null) || $document['bytes'] < 1 || $document['bytes'] > 1048576) {
                throw new RuntimeException('应用许可证材料声明无效：' . $name);
            }
            $file = $this->file($resourceRoot, $resource);
            if (filesize($file) !== $document['bytes'] || !hash_equals($sha, (string) hash_file('sha256', $file))) {
                throw new RuntimeException('应用许可证材料原文与构建身份不一致：' . $name);
            }
            $materials[$name] = (string) file_get_contents($file);
        }
        return $materials;
    }

    private function example(string $file): string
    {
        return (new ConfigurationExample())->read($file);
    }

    private function interpreter(string $artifact, array $manifest, array $libraries): string
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return '';
        }
        // 分析工具可随当前PHP安装在私有前缀；不要求修改系统/usr/bin，也不继承业务PATH。
        $environment = (new BuildPlatform())->phpEnvironment();
        $output = (new BuildEnvironment())->run(['readelf', '-l', $artifact], dirname($artifact), $environment);
        if (preg_match('/Requesting program interpreter:\s*([^\]]+)\]/', $output, $match) !== 1) {
            throw new RuntimeException('当前发布布局需要明确的动态ELF加载器');
        }
        $name = basename(trim($match[1]));
        if (!isset($libraries[$name])) {
            throw new RuntimeException('ELF加载器没有进入构建运行库闭包');
        }
        return $name;
    }

    private function launcher(string $binary, string $interpreter): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return "@echo off\r\nsetlocal\r\ncd /d \"%~dp0\"\r\nset \"TYPE_APP_RUNTIME_ROOT=%~dp0\"\r\nset \"PHPRC=%~dp0runtime\\php.ini\"\r\nset \"PHP_INI_SCAN_DIR=%~dp0runtime\\empty\"\r\nset \"SNMPCONFPATH=%~dp0runtime\\snmp\"\r\nset \"SNMP_PERSISTENT_DIR=%~dp0runtime\\snmp\\persist\"\r\nset \"PHP_HOME=\"\r\nset \"PHPX_HOME=\"\r\nset \"PATH=%~dp0bin;%SystemRoot%\\System32\"\r\n\"%~dp0bin\\app.exe\" %*\r\nexit /b %errorlevel%\r\n";
        }
        $prefix = <<<'SH'
#!/bin/sh
set -eu
# 隔离运行时只挂入少量 /usr/bin 程序，不能调用 dirname。
root=$(CDPATH='' cd -- "${0%/*}" && pwd -P)
cd "$root"
unset LD_PRELOAD LD_AUDIT DYLD_INSERT_LIBRARIES DYLD_FRAMEWORK_PATH DYLD_FALLBACK_FRAMEWORK_PATH DYLD_VERSIONED_LIBRARY_PATH DYLD_FALLBACK_LIBRARY_PATH PHP_HOME PHPX_HOME
TYPE_APP_RUNTIME_ROOT="$root"
PHPRC="$root/runtime/php.ini"
PHP_INI_SCAN_DIR="$root/runtime/empty"
SNMPCONFPATH="$root/runtime/snmp"
SNMP_PERSISTENT_DIR="$root/runtime/snmp/persist"
export TYPE_APP_RUNTIME_ROOT PHPRC PHP_INI_SCAN_DIR SNMPCONFPATH SNMP_PERSISTENT_DIR
SH;
        if (PHP_OS_FAMILY === 'Darwin') {
            return $prefix . "\nDYLD_LIBRARY_PATH=\"\$root/lib\"\nexport DYLD_LIBRARY_PATH\nexec \"\$root/bin/app\" \"\$@\"\n";
        }
        return $prefix . "\nLD_LIBRARY_PATH=\"\$root/lib\"\nexport LD_LIBRARY_PATH\nexec \"\$root/lib/" . $interpreter . "\" --library-path \"\$root/lib\" \"\$root/bin/app\" \"\$@\"\n";
    }

    private function file(string $root, string $relative): string
    {
        if (preg_match('~^[A-Za-z0-9][A-Za-z0-9._/+\-]{0,511}$~D', $relative) !== 1
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $relative) || str_contains($relative, '//')
            || preg_match('/\.(?:php[0-9]?|phtml|phar|inc)$/iD', $relative)) {
            throw new RuntimeException('发布文件路径无效');
        }
        BuildLock::path($root . '/' . $relative);
        $file = BuildPlatform::resolve($root . '/' . $relative);
        if (!BuildPlatform::contains($root, $file) || !is_file($file)) {
            throw new RuntimeException('发布文件越界');
        }
        return $file;
    }

    private function copy(string $source, string $root, string $relative, string $expected, string $kind, array &$files, int $mode = 0644): void
    {
        if (isset($files[$relative])) {
            throw new RuntimeException('发布文件目标重复');
        }
        if (!is_file($source) || !hash_equals($expected, (string) hash_file('sha256', $source))) {
            throw new RuntimeException('打包源与身份不一致');
        }
        $target = $root . '/' . $relative;
        $this->directory(dirname($target));
        if (!copy($source, $target) || !hash_equals($expected, (string) hash_file('sha256', $target)) || !chmod($target, $mode)) {
            throw new RuntimeException('发布文件复制或校验失败');
        }
        $files[$relative] = ['sha256' => $expected, 'bytes' => filesize($target), 'kind' => $kind];
    }

    private function write(string $root, string $relative, string $contents, string $kind, array &$files, int $mode = 0644): void
    {
        if (isset($files[$relative])) {
            throw new RuntimeException('发布文件目标重复');
        }
        $target = $root . '/' . $relative;
        $this->directory(dirname($target));
        if (file_put_contents($target, $contents, LOCK_EX) !== strlen($contents) || !chmod($target, $mode)) {
            throw new RuntimeException('无法写入发布文件');
        }
        $files[$relative] = ['sha256' => hash('sha256', $contents), 'bytes' => strlen($contents), 'kind' => $kind];
    }

    private function directory(string $directory): void
    {
        BuildLock::path($directory);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建发布子目录');
        }
    }

    /** 有界流式检查，不能为检查大型资源把整个文件载入内存。 */
    private function assertData(string $source): void
    {
        $stream = fopen($source, 'rb');
        if ($stream === false) {
            throw new RuntimeException('无法读取发布资源');
        }
        $tail = '';
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) {
                    throw new RuntimeException('发布资源读取失败');
                }
                $window = $tail . $chunk;
                if (preg_match('/<\?(?:php\s|=)|-----BEGIN [A-Z ]*PRIVATE KEY-----/i', $window)) {
                    throw new RuntimeException('发布资源不允许包含PHP源码');
                }
                $tail = substr($window, -64);
            }
        } finally {
            fclose($stream);
        }
    }

    /** 只回收本次唯一暂存目录，不碰既有发布目录或原始输入。 */
    private function removeStage(string $stage): void
    {
        if (!is_dir($stage)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($stage);
    }
}
