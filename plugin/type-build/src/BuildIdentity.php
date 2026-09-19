<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 内容身份只保存文件摘要与声明，不读取目录外的隐式运行配置。 */
final class BuildIdentity
{
    public const PROTOCOL = 1;
    public const GENERATORS = ['application' => 1, 'models' => 2, 'routing' => 1, 'queue' => 1, 'identity' => 3, 'source-adaptations' => 1,
        'configuration' => 1, 'operations' => 1];

    public function create(array $groups, array $facts): array
    {
        $inputs = [];
        foreach ($groups as $name => $paths) {
            if (!is_string($name) || !is_array($paths)) {
                throw new RuntimeException('构建身份输入组无效');
            }
            $inputs[$name] = $this->files($paths);
        }
        $description = self::canonical(['protocol' => self::PROTOCOL, 'generators' => self::GENERATORS, 'inputs' => $inputs, 'facts' => $facts]);
        return ['id' => self::digest($description), 'description' => $description];
    }

    public function files(array $paths, ?array $extensions = null): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (!is_string($path) || realpath($path) === false) {
                throw new RuntimeException('构建身份输入不存在');
            }
            $root = BuildPlatform::resolve($path);
            $entries = is_file($root) ? [$root] : new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($entries as $entry) {
                $file = is_string($entry) ? $entry : $entry->getPathname();
                if (!is_file($file) || ($extensions !== null && !in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $extensions, true))) {
                    continue;
                }
                $real = BuildPlatform::resolve($file);
                if (is_dir($root) && !BuildPlatform::contains($root, $real)) {
                    throw new RuntimeException('构建身份输入链接超出声明目录');
                }
                $hash = hash_file('sha256', $real);
                if ($hash === false) {
                    throw new RuntimeException('无法读取构建输入摘要');
                }
                $files[$real] = ['sha256' => $hash, 'bytes' => filesize($real)];
            }
        }
        ksort($files);
        return $files;
    }

    public function sources(array $paths): array
    {
        return array_keys($this->files($paths, ['php', 'c', 'cc', 'cpp', 'cxx', 's', 'm', 'mm', 'h', 'hh', 'hpp', 'hxx', 'inc', 'inl', 'tcc']));
    }

    public static function digest(mixed $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            $copy = [];
            foreach ($value as $key => $entry) {
                $copy[$key] = self::canonical($entry);
            }
            if (!array_is_list($copy)) {
                ksort($copy);
            }
            return $copy;
        }
        if (!is_scalar($value) && $value !== null) {
            throw new RuntimeException('构建身份只接受 JSON 数据');
        }
        return $value;
    }

    public static function assertValid(array $identity): void
    {
        if (!is_string($identity['id'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $identity['id'])
            || !is_array($identity['description'] ?? null) || ($identity['description']['protocol'] ?? null) !== self::PROTOCOL
            || !hash_equals($identity['id'], self::digest($identity['description']))) {
            throw new RuntimeException('构建身份摘要不一致');
        }
    }
}
