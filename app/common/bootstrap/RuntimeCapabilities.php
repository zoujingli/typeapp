<?php

declare(strict_types=1);

namespace app\common\bootstrap;

use InvalidArgumentException;

/**
 * 读取编译产物声明的运行能力，并在业务开始建立外部连接前拒绝不匹配配置。
 * 开发模式没有生成身份时不把构建环境变量当成已验证的运行身份，避免仅为
 * 选择构建 profile 而误报所有业务能力关闭。
 */
final class RuntimeCapabilities
{
    /** @return array{name:?string,database:?string,features:list<string>,verified:bool} */
    public static function profile(): array
    {
        $info = null;
        if (class_exists('Type\\Generated\\BuildIdentity') && method_exists('Type\\Generated\\BuildIdentity', 'info')) {
            $candidate = \Type\Generated\BuildIdentity::info();
            $info = is_array($candidate) ? ($candidate['profile'] ?? null) : null;
        }
        if (!is_array($info) || ($info['name'] ?? null) === null) {
            return ['name' => null, 'database' => null, 'features' => [], 'verified' => false];
        }
        $name = is_string($info['name'] ?? null) ? $info['name'] : null;
        $database = is_string($info['database'] ?? null) ? $info['database'] : null;
        $features = is_array($info['features'] ?? null) ? array_values(array_filter($info['features'], 'is_string')) : [];
        return ['name' => $name, 'database' => $database, 'features' => $features, 'verified' => true];
    }

    /** @throws InvalidArgumentException profile 只允许使用编译时选择的数据库。 */
    public static function assertDatabase(string $database): void
    {
        $profile = self::profile();
        if ($profile['database'] !== null && $profile['database'] !== $database) {
            throw new RuntimeCapabilityException('runtime_profile_database_mismatch', 'profile=' . (string) $profile['name'] . ', database=' . $database);
        }
    }

    /** 开发模式保留已有能力；原生产物只接受编译期声明。 */
    public static function hasFeature(string $feature): bool
    {
        $profile = self::profile();
        return !$profile['verified'] || in_array($feature, $profile['features'], true);
    }

    /** @throws InvalidArgumentException 关闭的构建能力不能在运行时启用。 */
    public static function requireFeature(string $feature): void
    {
        $profile = self::profile();
        if ($profile['verified'] && !in_array($feature, $profile['features'], true)) {
            throw new RuntimeCapabilityException('feature_unavailable', $feature);
        }
    }
}
