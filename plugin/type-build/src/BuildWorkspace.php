<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 将已核验输入复制到独立目录，运行配置、Git 认证和未声明文件不进入构建挂载。 */
final class BuildWorkspace
{
    /**
     * 复制已核验输入到项目 build 下的新目录，SDK 独立提供，秘密与认证文件拒绝进入。
     * @param array<string, array<string, array{sha256: string, bytes: int}>> $groups 构建身份中的文件组。
     * @param list<string> $auditPaths 保留自动加载审计关系所需的占位路径。
     * @return array{directory: string, files: int, inputs-sha256: string}
     */
    public function create(string $root, string $destination, array $groups, array $auditPaths = []): array
    {
        $root = BuildPlatform::resolve($root);
        $destination = BuildPlatform::path($destination);
        BuildLock::path($destination);
        if (!BuildPlatform::contains($root . '/build', $destination) || file_exists($destination)) {
            throw new RuntimeException('隔离输入必须使用项目 build 下尚不存在的目录');
        }
        if (!mkdir($destination, 0700, true)) {
            throw new RuntimeException('无法创建隔离构建目录');
        }
        $files = [];
        foreach ($groups as $group => $inputs) {
            if ($group === 'native') {
                continue;
            } // SDK 与系统头文件来自另行固定身份的只读工具链。
            foreach ($inputs as $file => $expected) {
                if (!BuildPlatform::contains($root, $file)) {
                    throw new RuntimeException('隔离构建输入位于项目外，请先复制安装路径依赖');
                }
                $relative = substr($file, strlen($root) + 1);
                foreach (explode('/', $relative) as $component) {
                    $sensitiveName = strtolower($component);
                    if ($sensitiveName === '.git' || $sensitiveName === 'auth.json' || $sensitiveName === '.env' || str_starts_with($sensitiveName, '.env.')
                        || in_array($sensitiveName, ['id_rsa', 'id_ed25519', 'credentials.json'], true)) {
                        throw new RuntimeException('隔离输入包含运行秘密或认证文件');
                    }
                }
                $target = $destination . '/' . $relative;
                BuildLock::path($target);
                if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true)) {
                    throw new RuntimeException('无法准备隔离输入目录');
                }
                if (!copy($file, $target) || !hash_equals($expected['sha256'], (string) hash_file('sha256', $target))) {
                    throw new RuntimeException('隔离输入复制期间内容变化');
                }
                chmod($target, is_executable($file) ? 0755 : 0644);
                $files[$relative] = $expected['sha256'];
            }
        }
        $placeholders = [];
        foreach ($auditPaths as $path) {
            if (!BuildPlatform::contains($root, $path)) {
                throw new RuntimeException('自动加载审计路径超出隔离项目');
            }
            $relative = substr($path, strlen($root) + 1);
            $target = $destination . '/' . $relative;
            BuildLock::path($target);
            if (file_exists($target)) {
                continue;
            }
            if (is_dir($path)) {
                mkdir($target, 0755, true);
                $placeholders[$relative] = 'directory';
            } else {
                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0755, true);
                }
                file_put_contents($target, '');
                $placeholders[$relative] = 'excluded-file';
            }
        }
        // Composer 的 path 包只允许引用当前项目内部的包，重建对应的相对链接。
        $composerFile = $root . '/composer.json';
        if (is_file($composerFile)) {
            $composer = json_decode(file_get_contents($composerFile), true, 512, JSON_THROW_ON_ERROR);
            $vendor = $composer['config']['vendor-dir'] ?? 'vendor';
            $installedFile = $root . '/' . $vendor . '/composer/installed.json';
            if (is_file($installedFile)) {
                $installed = json_decode(file_get_contents($installedFile), true, 512, JSON_THROW_ON_ERROR);
                foreach ($installed['packages'] ?? [] as $package) {
                    $alias = $root . '/' . $vendor . '/' . $package['name'];
                    if (!is_link($alias)) {
                        continue;
                    }
                    $real = BuildPlatform::resolve($alias);
                    if (!BuildPlatform::contains($root, $real)) {
                        throw new RuntimeException('隔离安装不能保留项目外的 Composer 包链接');
                    }
                    $relative = substr($alias, strlen($root) + 1);
                    $target = $destination . '/' . $relative;
                    if (!is_dir(dirname($target))) {
                        mkdir(dirname($target), 0755, true);
                    }
                    $link = str_repeat('../', count(explode('/', dirname($relative)))) . substr($real, strlen($root) + 1);
                    if (PHP_OS_FAMILY === 'Windows') {
                        // Windows不要求开发者授予创建符号链接权限；只复制已经声明并校验的包输入。
                        foreach ($files as $known => $digest) {
                            $packagePrefix = substr($real, strlen($root) + 1) . '/';
                            if (!str_starts_with($known, $packagePrefix)) {
                                continue;
                            }
                            $copyRelative = $relative . '/' . substr($known, strlen($packagePrefix));
                            $copyTarget = $destination . '/' . $copyRelative;
                            BuildLock::path($copyTarget);
                            if (!is_dir(dirname($copyTarget))) {
                                mkdir(dirname($copyTarget), 0755, true);
                            }
                            if (!copy($destination . '/' . $known, $copyTarget) || hash_file('sha256', $copyTarget) !== $digest) {
                                throw new RuntimeException('Windows路径包输入复制失败');
                            }
                            $files[$copyRelative] = $digest;
                        }
                    } elseif (!file_exists($target) && !symlink($link, $target)) {
                        throw new RuntimeException('无法重建隔离 Composer 包链接');
                    }
                }
            }
        }
        ksort($files);
        $record = ['protocol' => 1, 'files' => $files, 'audit-placeholders' => $placeholders, 'sdk-provided-separately' => true];
        file_put_contents($destination . '/build-inputs.json', json_encode($record, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        return ['directory' => $destination, 'files' => count($files), 'inputs-sha256' => BuildIdentity::digest($record)];
    }
}
