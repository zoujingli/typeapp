<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 保存依赖声明和原始材料，不推断许可、选择替代许可或给出分发授权。 */
final class DependencyNotices
{
    private int $bytes = 0;
    /**
     * @param array<string,array{root:string, version:string, license:mixed, kind:string}> $packages 应用和实际生产Composer包，不包括纯开发依赖。
     * @param list<array> $libraries 已验证的原生运行库清单。
     * @param array $declaration packages附加文本、按平台映射的native材料及require-complete门禁。
     * @return array{resources:list<array>, files:list<string>, summary:array}
     * @throws RuntimeException 输入/文件不安全、摘要不符、声明未知依赖，或显式完整性门禁未满足。
     */
    public function collect(string $directory, array $packages, array $libraries, array $declaration = []): array
    {
        $this->bytes = 0;
        if (count($packages) > 512 || !array_is_list($libraries) || count($libraries) > 1024) {
            throw new RuntimeException('依赖材料清单数量超限');
        }
        foreach ($libraries as $library) {
            if (!is_array($library) || !is_string($library['name'] ?? null) || !is_string($library['path'] ?? null)
                || !is_string($library['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $library['sha256']) !== 1) {
                throw new RuntimeException('依赖材料需要已验证的原生库身份');
            }
        }
        if (array_diff(array_keys($declaration), ['packages', 'native', 'require-complete']) !== []
            || !is_bool($declaration['require-complete'] ?? false)) {
            throw new RuntimeException('notices声明字段或完整性策略无效');
        }
        $extra = $declaration['packages'] ?? [];
        $nativeByPlatform = $declaration['native'] ?? [];
        if (!is_array($extra) || array_diff(array_keys($extra), array_keys($packages)) !== [] || !is_array($nativeByPlatform)
            || array_diff(array_keys($nativeByPlatform), ['Linux', 'Darwin', 'Windows']) !== []) {
            throw new RuntimeException('许可证材料引用了未知生产包或平台');
        }
        $nativeDeclarations = $nativeByPlatform[PHP_OS_FAMILY] ?? [];
        if (!is_array($nativeDeclarations) || array_diff(array_keys($nativeDeclarations), array_column($libraries, 'name')) !== []) {
            throw new RuntimeException('许可证材料引用了未纳入产物的原生库');
        }
        BuildLock::path($directory);
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new RuntimeException('无法创建依赖材料生成目录');
        }
        $directory = BuildPlatform::resolve($directory);
        $resources = [];
        $inputs = [];
        $components = [];
        $missing = [];
        ksort($packages);
        foreach ($packages as $name => $package) {
            if (!is_string($name) || preg_match('~^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*$~D', $name) !== 1
                || !is_array($package) || !is_string($package['root'] ?? null) || !is_string($package['version'] ?? null)
                || !in_array($package['kind'] ?? '', ['application', 'composer'], true)) {
                throw new RuntimeException('依赖材料的生产包身份无效');
            }
            $root = BuildPlatform::resolve($package['root']);
            $license = $this->license($package['license'] ?? null);
            $metadataFile = $root . '/composer.json';
            if (is_file($metadataFile)) {
                BuildLock::path($metadataFile);
                $metadata = json_decode((string) file_get_contents($metadataFile), true, 512, JSON_THROW_ON_ERROR);
                if (($metadata['name'] ?? null) !== $name) {
                    throw new RuntimeException('材料所属包与实际Composer声明不一致：' . $name);
                }
                $license = $this->license($metadata['license'] ?? null);
                $inputs[$metadataFile] = $metadataFile;
            }
            $documents = [];
            foreach ($this->discover($root) as $file) {
                $documents[] = $this->document($file, substr($file, strlen($root) + 1), null, $resources, $inputs);
            }
            foreach ($this->fileDeclarations($extra[$name] ?? []) as $entry) {
                $candidate = $root . '/' . $entry['file'];
                BuildLock::path($candidate);
                $file = BuildPlatform::resolve($candidate);
                if (!BuildPlatform::contains($root, $file)) {
                    throw new RuntimeException('生产包附加材料不能越出包目录');
                }
                $documents[] = $this->document($file, $entry['file'], $entry['sha256'], $resources, $inputs);
            }
            $id = $package['kind'] . ':' . $name;
            $issues = $this->issues($license, $documents);
            if ($issues !== []) {
                $missing[$id] = $issues;
            }
            $components[] = ['id' => $id, 'name' => $name, 'version' => $package['version'], 'kind' => $package['kind'],
                'license-declared' => $license, 'documents' => $documents, 'material-issues' => $issues];
        }
        foreach ($libraries as $library) {
            $name = $library['name'];
            $id = 'native:' . $name;
            $record = ['id' => $id, 'name' => $name, 'kind' => 'native', 'binary-sha256' => $library['sha256'],
                'bundled' => empty($library['system']), 'component' => null, 'version' => null, 'license-declared' => null, 'documents' => []];
            if (!empty($library['system'])) {
                $record['material-issues'] = ['system-provided-not-copied'];
                $components[] = $record;
                continue;
            }
            if (isset($nativeDeclarations[$name])) {
                $item = $nativeDeclarations[$name];
                if (!is_array($item) || array_diff(array_keys($item), ['binary-sha256', 'component', 'version', 'license', 'files']) !== []
                    || ($item['binary-sha256'] ?? null) !== $library['sha256'] || !is_string($item['component'] ?? null)
                    || $item['component'] === '' || !is_string($item['version'] ?? null) || $item['version'] === '') {
                    throw new RuntimeException('原生库材料必须绑定准确二进制摘要、组件与版本：' . $name);
                }
                if (!hash_equals($library['sha256'], (string) hash_file('sha256', $library['path']))) {
                    throw new RuntimeException('收集材料时原生库已经变化：' . $name);
                }
                $record['component'] = $this->label($item['component']);
                $record['version'] = $this->label($item['version']);
                $record['license-declared'] = $this->license($item['license'] ?? null);
                foreach ($this->fileDeclarations($item['files'] ?? []) as $entry) {
                    if (!(new BuildPlatform())->absolute($entry['file'])) {
                        throw new RuntimeException('原生库材料文件必须使用明确的绝对路径');
                    }
                    BuildLock::path($entry['file']);
                    $file = BuildPlatform::resolve($entry['file']);
                    $record['documents'][] = $this->document($file, basename($file), $entry['sha256'], $resources, $inputs);
                }
            }
            $record['material-issues'] = $this->issues($record['license-declared'], $record['documents']);
            if ($record['component'] === null) {
                $record['material-issues'][] = 'component-metadata-missing';
            }
            if ($record['material-issues'] !== []) {
                $missing[$id] = $record['material-issues'];
            }
            $components[] = $record;
        }
        ksort($missing);
        if (($declaration['require-complete'] ?? false) && $missing !== []) {
            throw new RuntimeException('依赖材料未完整：' . implode(', ', array_keys($missing)));
        }
        $document = ['protocol' => 1, 'scope' => '应用、实际Composer生产依赖及动态运行库清单；不自动发现静态内嵌子依赖或作法律结论',
            'material-coverage' => $missing === [] ? 'complete' : 'incomplete', 'legal-review' => 'not-assessed',
            'distribution-authorization' => 'not-assessed', 'components' => $components, 'missing' => $missing];
        $json = json_encode(BuildIdentity::canonical($document), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $index = $directory . '/dependencies.json';
        $policy = $directory . '/README.txt';
        $policyText = "本目录记录构建输入中的许可声明和原始文本，保留原文与摘要，不改变项目许可。\n"
            . "material-coverage仅指本清单项目的声明/原文是否齐全，不代表静态子依赖、来源真实性、许可兼容性、源码提供义务或分发授权已审查。\n"
            . "材料生成不会新增公开或私有分发授权，应按项目实际许可与授权处理。缺失项目见dependencies.json。\n";
        foreach ([$index => $json, $policy => $policyText] as $file => $contents) {
            BuildLock::path($file);
            if (file_put_contents($file, $contents, LOCK_EX) !== strlen($contents)) {
                throw new RuntimeException('无法完整写入依赖材料');
            }
            chmod($file, 0600);
        }
        foreach ([$index => 'notices/dependencies.json', $policy => 'notices/README.txt'] as $file => $target) {
            $resources[$target] = ['package' => 'type-build:notices', 'source' => $file, 'target' => $target, 'sha256' => hash_file('sha256', $file)];
        }
        ksort($resources);
        return ['resources' => array_values($resources), 'files' => array_values($inputs),
            'summary' => BuildIdentity::canonical(['index' => 'notices/dependencies.json', 'index-sha256' => hash('sha256', $json),
                'material-coverage' => $document['material-coverage'], 'legal-review' => 'not-assessed', 'missing' => $missing, 'components' => count($components)])];
    }

    private function license(mixed $value): string|array|null
    {
        if ($value === null || $value === []) {
            return null;
        }
        if (is_string($value)) {
            return $this->label($value);
        }
        if (!is_array($value) || !array_is_list($value) || count($value) > 32) {
            throw new RuntimeException('许可声明必须保留为字符串或字符串列表');
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new RuntimeException('许可声明元素无效');
            } $this->label($item);
        }
        return $value;
    }

    private function label(string $value): string
    {
        if ($value === '' || strlen($value) > 512 || preg_match('/[\x00-\x1f\x7f]/', $value) || preg_match('//u', $value) !== 1) {
            throw new RuntimeException('依赖材料元数据无效');
        }
        return $value;
    }

    private function issues(mixed $license, array $documents): array
    {
        $issues = [];
        if ($license === null) {
            $issues[] = 'license-declaration-missing';
        } elseif (in_array('NOASSERTION', is_array($license) ? $license : [$license], true)) {
            $issues[] = 'license-declaration-unresolved';
        }
        if ($documents === []) {
            $issues[] = 'original-notice-text-missing';
        }
        return $issues;
    }

    private function fileDeclarations(mixed $entries): array
    {
        if (!is_array($entries) || !array_is_list($entries) || count($entries) > 64) {
            throw new RuntimeException('附加材料必须是有限文件列表');
        }
        foreach ($entries as $entry) {
            if (!is_array($entry) || array_diff(array_keys($entry), ['file', 'sha256']) !== [] || !is_string($entry['file'] ?? null)
                || !is_string($entry['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $entry['sha256']) !== 1) {
                throw new RuntimeException('附加材料必须有文件与准确SHA256');
            }
        }
        return $entries;
    }

    /** 仅在构建时枚举包根声明文件及LICENSES目录；从不以文件名推断许可类型。 */
    private function discover(string $root): array
    {
        $files = [];
        foreach (scandir($root) as $name) {
            if (preg_match('/^(?:LICEN[CS]E|COPYING|NOTICE|COPYRIGHT)(?:[._-].+)?$/iD', $name) === 1 && is_file($root . '/' . $name)) {
                $files[] = $root . '/' . $name;
            }
        }
        if (is_dir($root . '/LICENSES')) {
            BuildLock::path($root . '/LICENSES');
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/LICENSES', \FilesystemIterator::SKIP_DOTS)) as $entry) {
                if ($entry->isFile()) {
                    $files[] = $entry->getPathname();
                }
                if (count($files) > 64) {
                    throw new RuntimeException('包许可证材料文件数量超限');
                }
            }
        }
        if (count($files) > 64) {
            throw new RuntimeException('包许可证材料文件数量超限');
        }
        sort($files);
        return $files;
    }

    private function document(string $file, string $name, ?string $expected, array &$resources, array &$inputs): array
    {
        BuildLock::path($file);
        $file = BuildPlatform::resolve($file);
        if (!is_file($file) || filesize($file) > 1048576 || preg_match('/\.(?:php[0-9]?|phtml|phar|inc)$/iD', $file)
            || preg_match('/^(?:\.env(?:\..*)?|auth\.json|id_rsa|id_ed25519)$/iD', basename($file))) {
            throw new RuntimeException('依赖材料只接受有限的普通文本文件');
        }
        $contents = (string) file_get_contents($file);
        if ($contents === '' || str_contains($contents, "\0") || preg_match('//u', $contents) !== 1
            || str_contains($contents, '<?php') || preg_match('/-----BEGIN[^\r\n]*PRIVATE KEY-----/', $contents)) {
            throw new RuntimeException('依赖材料不能包含二进制、业务PHP或私钥');
        }
        $sha = hash('sha256', $contents);
        if ($expected !== null && !hash_equals($expected, $sha)) {
            throw new RuntimeException('依赖材料原文摘要不一致');
        }
        $target = 'notices/texts/' . $sha . '.txt';
        if (!isset($resources[$target])) {
            $this->bytes += strlen($contents);
        }
        if ($this->bytes > 16777216 || count($resources) > 1024 || count($inputs) > 4096) {
            throw new RuntimeException('依赖材料累计大小或文件数超限');
        }
        $resources[$target] = ['package' => 'type-build:notices', 'source' => $file, 'target' => $target, 'sha256' => $sha];
        $inputs[$file] = $file;
        return ['name' => $name, 'resource' => $target, 'sha256' => $sha, 'bytes' => strlen($contents)];
    }
}
