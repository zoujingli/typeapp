<?php

declare(strict_types=1);

namespace Type\Build;

use Closure;
use RuntimeException;
use Throwable;

/** 仅信任所属构建用户的本地缓存；复用前校验身份、ELF 内容与完整产物摘要。 */
final class ArtifactCache
{
    private string $directory;

    /** 校验或创建仅归构建用户所有的缓存目录；路径不允许经过符号链接。 */
    public function __construct(string $directory)
    {
        BuildLock::path($directory);
        $created = !is_dir($directory);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建构建缓存');
        }
        (new BuildPlatform())->privateCache($directory, $created);
        $this->directory = $directory;
    }

    /**
     * @param Closure(string): void $compile 接收临时候选产物路径。
     * @param (Closure(): void)|null $validateInputs 发布候选产物前零参数复核输入。
     */
    public function materialize(array $identity, array $manifest, string $output, Closure $compile, ?Closure $validateInputs = null): array
    {
        BuildIdentity::assertValid($identity);
        BuildLock::path($output);
        if (($manifest['build-id'] ?? null) !== $identity['id']) {
            throw new RuntimeException('缓存身份与产物清单不一致');
        }
        $id = $identity['id'];
        return BuildLock::run($this->directory . '/' . $id . '.lock', function () use ($identity, $manifest, $output, $compile, $validateInputs, $id): array {
            $entry = $this->directory . '/' . $id;
            $started = hrtime(true);
            $reason = 'absent';
            $record = null;
            if (is_dir($entry)) {
                try {
                    $record = $this->validate($entry, $identity);
                } catch (Throwable $error) {
                    $reason = 'rejected';
                    BuildLock::path($entry);
                    if (!rename($entry, $entry . '.rejected-' . bin2hex(random_bytes(6)))) {
                        throw new RuntimeException('无法隔离损坏的构建缓存');
                    }
                }
            }
            $hit = $record !== null;
            if (!$hit) {
                $temporary = $this->directory . '/' . $id . '.building-' . bin2hex(random_bytes(6));
                if (!mkdir($temporary, 0700)) {
                    throw new RuntimeException('无法创建候选缓存');
                }
                try {
                    $compiled = $temporary . '/artifact' . (new BuildPlatform())->executableSuffix();
                    $compile($compiled);
                    if ($compiled !== $temporary . '/artifact' && !rename($compiled, $temporary . '/artifact')) {
                        throw new RuntimeException('无法归档带平台后缀的候选产物');
                    }
                    $sealed = (new ArtifactManifest())->seal($temporary . '/artifact', $manifest);
                    $record = ['cache-protocol' => 1, 'identity' => $identity, 'artifact-sha256' => hash_file('sha256', $temporary . '/artifact'),
                        'manifest' => $sealed, 'created-at' => gmdate('c')];
                    $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    if (file_put_contents($temporary . '/record.json', $json) !== strlen($json) || !rename($temporary, $entry)) {
                        throw new RuntimeException('无法提交内容寻址缓存');
                    }
                } finally {
                    if (is_dir($temporary)) {
                        foreach (['artifact', 'artifact.exe', 'record.json', 'artifact.rsp', 'artifact.exe.rsp', 'artifact.lib', 'artifact.exp', 'artifact.pdb'] as $file) {
                            if (is_file($temporary . '/' . $file)) {
                                unlink($temporary . '/' . $file);
                            }
                        }
                        if (count(scandir($temporary)) === 2) {
                            rmdir($temporary);
                        }
                    }
                }
            }
            $record = $this->validate($entry, $identity);
            if ($validateInputs !== null) {
                $validateInputs();
            }
            $candidate = tempnam(dirname($output), '.type_artifact_');
            if ($candidate === false) {
                throw new RuntimeException('无法准备输出产物');
            }
            try {
                if (!copy($entry . '/artifact', $candidate) || !chmod($candidate, 0755)
                    || !hash_equals($record['artifact-sha256'], (string) hash_file('sha256', $candidate))) {
                    throw new RuntimeException('无法恢复已验证产物');
                }
                if (!rename($candidate, $output)) {
                    throw new RuntimeException('无法原子替换输出产物');
                }
            } finally {
                if (is_file($candidate)) {
                    unlink($candidate);
                }
            }
            return ['key' => $id, 'hit' => $hit, 'reason' => $hit ? 'verified' : $reason, 'artifact-sha256' => $record['artifact-sha256'],
                'created-at' => $record['created-at'], 'elapsed-ms' => (hrtime(true) - $started) / 1e6];
        });
    }

    private function validate(string $entry, array $identity): array
    {
        BuildLock::path($entry . '/record.json');
        BuildLock::path($entry . '/artifact');
        if (!is_file($entry . '/record.json') || filesize($entry . '/record.json') > 16777216) {
            throw new RuntimeException('缓存记录无效');
        }
        $record = json_decode(file_get_contents($entry . '/record.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($record) || ($record['cache-protocol'] ?? null) !== 1 || ($record['identity'] ?? null) !== $identity
            || !is_string($record['artifact-sha256'] ?? null)) {
            throw new RuntimeException('缓存记录与当前输入不一致');
        }
        (new ArtifactManifest())->read($entry . '/artifact', $identity['id'], $record['artifact-sha256']);
        return $record;
    }
}
