<?php

declare(strict_types=1);

namespace TypeApp\Release;

use TypeApp\Distribution\Process;

/** GitHub Release的受限写入：标签不移动，附件不覆盖，重试逐项回读真实字节。 */
final class GitHub
{
    public function __construct(private string $root)
    {
    }

    /** 包括当前令牌可见的草稿；API失败不能当作Release不存在。 */
    public function find(string $repository, string $version): ?array
    {
        $this->scope($repository, $version);
        $pages = json_decode(Process::output(['gh', 'api', 'repos/' . $repository . '/releases?per_page=100', '--paginate', '--slurp'], $this->root), true, 128, JSON_THROW_ON_ERROR);
        foreach ($pages as $page) {
            foreach ($page as $release) {
                if ($release['tag_name'] === $version) {
                    return $release;
                }
            }
        }
        return null;
    }

    /** 保存可恢复草稿；既有Release的标签、源码、预发布状态与说明均须一致。 */
    public function draft(string $repository, string $version, string $source, string $body): array
    {
        $this->scope($repository, $version);
        $existing = $this->find($repository, $version);
        if ($existing !== null) {
            if ($existing['target_commitish'] !== $source || $existing['prerelease'] !== str_contains($version, '-rc.')
                || trim($existing['body']) !== trim($body)) {
                throw new \RuntimeException('既有Release身份或说明冲突：' . $repository);
            }
            return $existing;
        }
        $directory = $this->root . '/build/release-api';
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $file = tempnam($directory, 'body-');
        try {
            file_put_contents($file, $body);
            $command = ['gh', 'release', 'create', $version, '--repo', $repository, '--verify-tag', '--target', $source,
                '--title', $version, '--notes-file', $file, '--draft'];
            if (str_contains($version, '-rc.')) {
                $command[] = '--prerelease';
            }
            Process::output($command, $this->root);
        } finally {
            unlink($file);
        }
        return $this->find($repository, $version) ?? throw new \RuntimeException('创建Release后无法回读');
    }

    /** 只下载已看到的明确附件名；不接受目录跳转或通配符。 */
    public function download(string $repository, string $version, string $name, string $directory): string
    {
        $this->scope($repository, $version);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $name)) {
            throw new \RuntimeException('Release附件名无效');
        }
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        Process::output(['gh', 'release', 'download', $version, '--repo', $repository, '--pattern', $name, '--dir', $directory], $this->root);
        return $directory . '/' . $name;
    }

    /** 同名同摘要为幂等成功；不同摘要立即停止，永不使用--clobber。 */
    public function asset(string $repository, string $version, string $file): void
    {
        $release = $this->find($repository, $version) ?? throw new \RuntimeException('上传附件前缺少候选草稿');
        $names = array_column($release['assets'], 'name');
        if (!in_array(basename($file), $names, true)) {
            if (!$release['draft']) {
                throw new \RuntimeException('公开Release缺少附件，拒绝向既有完整版本补写新产物');
            }
            Process::output(['gh', 'release', 'upload', $version, $file, '--repo', $repository], $this->root);
        }
        $temporary = $this->root . '/build/release-readback-' . bin2hex(random_bytes(6));
        try {
            $downloaded = $this->download($repository, $version, basename($file), $temporary);
            if (hash_file('sha256', $downloaded) !== hash_file('sha256', $file)) {
                throw new \RuntimeException('同名Release附件摘要冲突：' . basename($file));
            }
        } finally {
            $path = $temporary . '/' . basename($file);
            if (is_file($path)) {
                unlink($path);
            }
            if (is_dir($temporary)) {
                rmdir($temporary);
            }
        }
    }

    /** RC不会成为稳定最新版，已公开同身份版本重复执行成功。 */
    public function publish(string $repository, string $version): array
    {
        $release = $this->find($repository, $version) ?? throw new \RuntimeException('缺少待发布Release');
        if ($release['draft']) {
            Process::output(['gh', 'release', 'edit', $version, '--repo', $repository, '--draft=false',
                str_contains($version, '-rc.') ? '--latest=false' : '--latest=true'], $this->root);
        }
        $actual = $this->find($repository, $version);
        if ($actual === null || $actual['draft'] || $actual['prerelease'] !== str_contains($version, '-rc.')) {
            throw new \RuntimeException('Release公开状态回读不一致：' . $repository);
        }
        return ['repository' => $repository, 'version' => $version, 'id' => $actual['id'], 'url' => $actual['html_url'], 'prerelease' => $actual['prerelease'], 'status' => 'published'];
    }

    /** 目标由固定映射限定，调用方不能把发布令牌用于其他仓库。 */
    private function scope(string $repository, string $version): void
    {
        Plan::version($version);
        $mapping = json_decode((string) file_get_contents($this->root . '/.github/distribution.json'), true, 64, JSON_THROW_ON_ERROR);
        $allowed = [...array_column($mapping['packages'], 'repository'), 'zoujingli/type-project', 'zoujingli/typeapp'];
        if (!in_array($repository, $allowed, true)) {
            throw new \RuntimeException('Release仓库不属于固定映射');
        }
    }
}
