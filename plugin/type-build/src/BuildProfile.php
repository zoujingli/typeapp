<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/**
 * 构建期数据库与功能闭包。
 *
 * profile 是产物身份的一部分；运行时只能选择与产物一致的数据库，不能用环境变量
 * 把另一个驱动“打开”。功能列表由应用声明，构建器据此筛选原生扩展，运行时再以清单
 * 里的结果给出稳定错误。
 */
final class BuildProfile
{
    public const DATABASES = ['sqlite', 'mysql', 'pgsql'];

    public const OPTIONAL_FEATURES = ['web', 'mqtt', 'iot', 'alerts', 'exports', 'queue', 'scheduler', 'redis', 'cache', 'dom', 'xml', 'intl', 'zip'];

    /**
     * 将锁定编译器推断的 Zend 启动依赖限定到真实 embed 已验证的模块表。
     * 源码仍全量编译；只有数据库和 Redis 的条件调用允许在目标中不可用。
     * @param list<string> $extensions 真实目标 embed 的完整扩展列表。
     * @internal 由 TypephpCompatibility 在核对上游源码摘要后调用。
     */
    public static function targetModuleDependencies(string $source, array $extensions): string
    {
        if ($extensions === []) {
            throw new RuntimeException('静态目标缺少已验证的模块表');
        }
        $target = array_map('strtolower', $extensions);
        $count = 0;
        $result = preg_replace_callback('/^    ZEND_MOD_REQUIRED\("([a-zA-Z0-9_ ]+)"\)\R/m', static function (array $match) use ($target): string {
            $name = strtolower($match[1]);
            if (in_array($name, $target, true)) {
                return $match[0];
            }
            if (!in_array($name, ['pdo_mysql', 'pdo_pgsql', 'pdo_sqlite', 'redis'], true)) {
                throw new RuntimeException('编译源码引用了静态目标未提供的扩展：' . $name);
            }
            return '';
        }, $source, -1, $count);
        if ($result === null || $count === 0) {
            throw new RuntimeException('静态 profile 模块依赖生成格式发生变化');
        }
        return $result;
    }

    /** 验证真实 embed 的扩展集合，关闭的能力不能因宿主或 SDK 配置泄漏进程序。 */
    public static function assertExtensions(array $extensions, array $profile): void
    {
        $drivers = array_values(array_intersect($extensions, ['pdo_mysql', 'pdo_pgsql', 'pdo_sqlite']));
        if ($drivers !== ['pdo_' . $profile['database']]
            || in_array('redis', $extensions, true) !== in_array('redis', $profile['features'], true)) {
            throw new RuntimeException('真实扩展集合与 profile 数据库或 Redis 能力不一致');
        }
        foreach (['phar', 'xdebug', 'pcov', 'dom', 'xml', 'intl', 'zip'] as $extension) {
            if (in_array($extension, $extensions, true) && (in_array($extension, ['phar', 'xdebug', 'pcov'], true) || !in_array($extension, $profile['features'], true))) {
                throw new RuntimeException('静态 SDK 包含未声明的生产扩展：' . $extension);
            }
        }
    }

    /**
     * 从 type-app.json 读取并校验 profile 声明。
     *
     * @param array<string,mixed> $settings
     * @return array{selected:?string,profiles:array<string,array{database:string,features:list<string>}>}
     */
    public static function resolve(array $settings): array
    {
        $requested = getenv('TYPEAPP_BUILD_PROFILE');
        $requested = is_string($requested) && $requested !== '' ? $requested : ($settings['build-profile'] ?? null);
        $declarations = $settings['build-profiles'] ?? null;
        if ($declarations === null) {
            if ($requested !== null) {
                throw new RuntimeException('未声明 build-profiles，不能选择 profile');
            }
            return ['selected' => null, 'profiles' => []];
        }
        if (!is_array($declarations) || array_is_list($declarations) || $declarations === [] || count($declarations) > 32) {
            throw new RuntimeException('build-profiles 必须是有限的命名对象');
        }
        $profiles = [];
        foreach ($declarations as $name => $declaration) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $name) !== 1
                || !is_array($declaration) || array_diff(array_keys($declaration), ['database', 'features']) !== []) {
                throw new RuntimeException('构建 profile 名称或声明无效');
            }
            $database = $declaration['database'] ?? null;
            $features = $declaration['features'] ?? null;
            if (!is_string($database) || !in_array($database, self::DATABASES, true)
                || !is_array($features) || !array_is_list($features) || $features === [] || count($features) > 64) {
                throw new RuntimeException('构建 profile 的 database 或 features 无效：' . $name);
            }
            $normalized = [];
            foreach ($features as $feature) {
                if (!is_string($feature) || preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $feature) !== 1) {
                    throw new RuntimeException('构建 profile 功能名称无效：' . $name);
                }
                if (in_array($feature, ['phar', 'xdebug', 'pcov', 'composer'], true)) {
                    throw new RuntimeException('构建或调试工具不能声明为生产功能：' . $feature);
                }
                $normalized[] = $feature;
            }
            $normalized = array_values(array_unique($normalized));
            sort($normalized);
            // 运行能力采用闭包而不是让运行时猜测依赖：通知和导出需要队列，
            // 队列、调度以及缓存连接需要 Redis。闭包结果写入产物身份。
            do {
                $before = count($normalized);
                if (in_array('alerts', $normalized, true) || in_array('exports', $normalized, true)) {
                    $normalized[] = 'queue';
                }
                if (in_array('queue', $normalized, true) || in_array('scheduler', $normalized, true)
                    || in_array('cache', $normalized, true) || in_array('redis', $normalized, true)) {
                    $normalized[] = 'redis';
                }
                $normalized = array_values(array_unique($normalized));
            } while (count($normalized) !== $before);
            sort($normalized);
            $profiles[$name] = ['database' => $database, 'features' => $normalized];
        }
        if ($requested !== null && (!is_string($requested) || !isset($profiles[$requested]))) {
            throw new RuntimeException('未知构建 profile：' . (string) $requested);
        }
        if ($requested === null && count($profiles) === 1) {
            $requested = array_key_first($profiles);
        }
        return ['selected' => $requested, 'profiles' => $profiles];
    }

    /** @param array{selected:?string,profiles:array<string,array{database:string,features:list<string>}>} $resolved */
    public static function selected(array $resolved): ?array
    {
        $name = $resolved['selected'];
        return $name === null ? null : ['name' => $name, ...$resolved['profiles'][$name]];
    }

    /**
     * 过滤生产包声明中的数据库扩展，保持 Composer 包源码仍完整编译。
     * @param list<string> $extensions
     * @param array{name:string,database:string,features:list<string>}|null $profile
     * @return list<string>
     */
    public static function runtimeExtensions(array $extensions, ?array $profile): array
    {
        if ($profile === null) {
            return array_values(array_unique($extensions));
        }
        $databaseExtension = 'pdo_' . $profile['database'];
        // profile 是最终程序的能力边界。即使某个应用包没有把选中驱动或
        // phpredis 作为直接 ext- 要求列出，也必须让真实 embed 探针验证它；
        // 缺失时在构建期失败，不能把错误推迟到业务请求中。
        $extensions[] = $databaseExtension;
        if (in_array('redis', $profile['features'], true)) {
            $extensions[] = 'redis';
        }
        foreach (['dom', 'xml', 'intl', 'zip'] as $optional) {
            if (in_array($optional, $profile['features'], true)) {
                $extensions[] = $optional;
            }
        }
        $result = [];
        foreach (array_unique($extensions) as $extension) {
            if (in_array($extension, ['pdo_mysql', 'pdo_pgsql', 'pdo_sqlite'], true) && $extension !== $databaseExtension) {
                continue;
            }
            if ($extension === 'redis' && !in_array('redis', $profile['features'], true)) {
                continue;
            }
            $result[] = $extension;
        }
        return array_values($result);
    }

    /**
     * 返回本 profile 明确拒绝的可选数据库和运行能力，写入产物身份供发布清单审计。
     * @param array{name:string,database:string,features:list<string>}|null $profile
     * @return list<string>
     */
    public static function rejectedCapabilities(?array $profile): array
    {
        if ($profile === null) {
            return [];
        }
        $rejected = [];
        foreach (self::DATABASES as $database) {
            if ($database !== $profile['database']) {
                $rejected[] = 'database:' . $database;
            }
        }
        foreach (self::OPTIONAL_FEATURES as $feature) {
            if (!in_array($feature, $profile['features'], true)) {
                $rejected[] = $feature;
            }
        }
        sort($rejected);
        return $rejected;
    }
}
