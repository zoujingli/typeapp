<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 交付已验收的同一可执行文件，不生成启动器、内部配置、资源目录或运行库副本。 */
final class SingleProgram
{
    /**
     * 原样发布已有产物；目标以独占创建方式出现，不能覆盖其他版本或并发创建的文件。
     *
     * @return array{delivery:string,path:string,sha256:string,bytes:int,build-id:string,system-libraries:list<string>}
     * @throws RuntimeException 产物未静态链接、材料不齐、目标已存在或复制期间输入变化。
     */
    public function create(string $artifact, string $destination): array
    {
        BuildLock::path($artifact);
        $artifact = BuildPlatform::resolve($artifact);
        $sha = (string) hash_file('sha256', $artifact);
        $record = $this->verify($artifact, $sha);
        $parent = BuildPlatform::resolve(dirname($destination));
        $leaf = basename($destination);
        if (in_array($leaf, ['', '.', '..'], true) || preg_match('/[\x00-\x1f\x7f]/', $leaf)) {
            throw new RuntimeException('单程序目标文件名无效');
        }
        $destination = $parent . '/' . $leaf;
        BuildLock::path($destination);
        if (file_exists($destination) || is_link($destination)) {
            throw new RuntimeException('单程序目标已存在，不能覆盖');
        }
        $stage = tempnam($parent, '.type-program-');
        if ($stage === false) {
            throw new RuntimeException('无法创建单程序发布暂存文件');
        }
        try {
            if (!copy($artifact, $stage) || !hash_equals($sha, (string) hash_file('sha256', $stage))
                || !hash_equals($sha, (string) hash_file('sha256', $artifact)) || !chmod($stage, 0755)) {
                throw new RuntimeException('单程序复制失败或原产物已变化');
            }
            // 同文件系统硬链接具有“不替换已有文件”的原子创建语义；随后只移除暂存名。
            if (!@link($stage, $destination)) {
                throw new RuntimeException('单程序目标已存在或无法独占创建');
            }
            return ['path' => $destination] + $record;
        } finally {
            unlink($stage);
        }
    }

    /**
     * 离线核对受信摘要、编译身份及系统加载项，不执行传入的程序。
     * @return array{delivery:string,sha256:string,bytes:int,build-id:string,system-libraries:list<string>}
     * @throws RuntimeException 字节或身份不符，或仍有外置非系统依赖。
     */
    public function verify(string $artifact, string $expectedSha256): array
    {
        BuildLock::path($artifact);
        $artifact = BuildPlatform::resolve($artifact);
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedSha256) !== 1) {
            throw new RuntimeException('单程序校验需要受信 SHA-256');
        }
        $manifest = (new ArtifactManifest())->read($artifact, null, $expectedSha256);
        if (($manifest['runtime-linkage'] ?? null) !== 'static' || ($manifest['native-libraries'] ?? null) !== []
            || ($manifest['extension-modules'] ?? null) !== [] || ($manifest['resources'] ?? null) !== []
            || ($manifest['runtime']['os'] ?? null) !== PHP_OS_FAMILY
            || ($manifest['runtime']['architecture'] ?? null) !== php_uname('m')) {
            throw new RuntimeException('单程序交付需要同平台完整静态产物；仍有外置运行库或资源');
        }
        $notices = $manifest['dependency-notices'] ?? [];
        if (($notices['material-coverage'] ?? '') !== 'complete'
            || !isset($manifest['embedded-resources']['notices/dependencies.json'])
            || ($manifest['embedded-resources']['notices/dependencies.json']['sha256'] ?? '') !== ($notices['index-sha256'] ?? null)) {
            throw new RuntimeException('单程序的依赖许可索引或原文不完整');
        }
        $libraries = StaticRuntimeSdk::verifyArtifact($artifact, new BuildEnvironment(), ['PATH' => (string) getenv('PATH')]);
        return ['delivery' => 'single-executable', 'sha256' => $expectedSha256, 'bytes' => filesize($artifact),
            'build-id' => $manifest['build-id'], 'system-libraries' => $libraries];
    }
}
