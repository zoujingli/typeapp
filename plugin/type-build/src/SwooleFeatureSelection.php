<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/**
 * 把本次编译用到的 Swoole 符号和已有运行声明收成官方 configure 开关。
 *
 * 超集共享模块只供编译机反射。这里不扫描 PHP 源码，只消费调用方已经拿到的类名、函数名和扩展清单。
 */
final class SwooleFeatureSelection
{
    public const SOURCE = '0f3bee2f0ed8704ce33a336e7feabb0115411dd7';

    /** @var list<string> */
    public const SUPERSET = [
        '--enable-sockets',
        '--enable-mysqlnd',
        '--enable-cares',
        '--enable-swoole-thread',
        '--enable-swoole-pgsql',
        '--enable-swoole-sqlite',
    ];

    /** @var list<string> */
    private const ORDER = [
        '--enable-sockets',
        '--enable-mysqlnd',
        '--enable-cares',
        '--enable-swoole-thread',
        '--enable-swoole-pgsql',
        '--enable-swoole-sqlite',
    ];

    /**
     * 合并三类输入。线程应用即使源码没写 Swoole\Thread 也打开 thread。
     *
     * 统计里的类或函数若属于已加载的 swoole 扩展，才映射到可选块；超集模块没有的可选符号直接失败。
     * Swoole\ConnectionPool 这类官方 PHP 库不是 configure 块。curl 不在这里按名称猜测。
     *
     * @param list<string> $classes 编译统计中的类名。
     * @param list<string> $functions 编译统计中的函数名。
     * @param list<string> $runtimeExtensions 本次运行声明里已经加载的扩展。
     * @param array<string, string|false|null>|null $catalog 测试注入的符号归属；null 时读取当前进程的反射。false 表示已加载但不是扩展符号。
     * @return list<string> 稳定顺序的 configure 开关；没有 Swoole 使用且不是线程应用时为空。
     * @throws RuntimeException 超集模块没有被引用的可选符号，或目录项无效。
     */
    public function select(array $classes, array $functions, array $runtimeExtensions, bool $threaded, ?array $catalog = null): array
    {
        $selected = [];
        $sawSwoole = false;
        if ($threaded) {
            $selected['--enable-swoole-thread'] = true;
        }
        foreach ($classes as $class) {
            if (!is_string($class) || $class === '') {
                throw new RuntimeException('编译统计中的类名无效');
            }
            $class = ltrim($class, '\\');
            $owner = $this->owner($class, true, $catalog);
            $flag = $this->optionalFlag($class);
            if ($owner === 'swoole') {
                $sawSwoole = true;
                if ($flag === null) {
                    throw new RuntimeException('超集模块没有该符号：' . $class);
                }
                if (is_string($flag)) {
                    $selected[$flag] = true;
                }
                continue;
            }
            if ($owner === null && !$this->isLibraryClass($class) && ($flag !== false || str_starts_with(strtolower($class), 'swoole\\'))) {
                throw new RuntimeException('超集模块没有该符号：' . $class);
            }
        }
        foreach ($functions as $function) {
            if (!is_string($function) || $function === '') {
                throw new RuntimeException('编译统计中的函数名无效');
            }
            $function = strtolower(ltrim($function, '\\'));
            $owner = $this->owner($function, false, $catalog);
            if ($owner === 'swoole') {
                $sawSwoole = true;
                $flag = $this->functionFlag($function);
                if ($flag === null) {
                    throw new RuntimeException('超集模块没有该符号：' . $function);
                }
                if (is_string($flag)) {
                    $selected[$flag] = true;
                }
            } elseif ($owner === null && $this->functionFlag($function) !== false) {
                throw new RuntimeException('超集模块没有该符号：' . $function);
            }
        }
        $runtime = [];
        foreach ($runtimeExtensions as $extension) {
            if (!is_string($extension) || $extension === '') {
                throw new RuntimeException('运行扩展名称无效');
            }
            $runtime[] = strtolower($extension);
        }
        if (in_array('pdo_pgsql', $runtime, true)) {
            $selected['--enable-swoole-pgsql'] = true;
        }
        if (in_array('pdo_sqlite', $runtime, true)) {
            $selected['--enable-swoole-sqlite'] = true;
        }
        if (in_array('pdo_mysql', $runtime, true) || in_array('mysqlnd', $runtime, true)) {
            $selected['--enable-mysqlnd'] = true;
        }
        if (!$threaded && !$sawSwoole && !isset($selected['--enable-swoole-pgsql']) && !isset($selected['--enable-swoole-sqlite']) && !isset($selected['--enable-mysqlnd'])) {
            return [];
        }
        $selected['--enable-sockets'] = true;
        $selected['--enable-cares'] = true;
        $flags = [];
        foreach (self::ORDER as $flag) {
            if (isset($selected[$flag])) {
                $flags[] = $flag;
            }
        }

        return $flags;
    }

    /**
     * pgsql/sqlite 会在 Swoole MINIT 里登记 PDO 驱动，对应的共享模块必须先注册。
     *
     * 只返回模块文件清单里真实存在的项，不把已经内置的 PDO 再登记一次。
     *
     * @param list<string> $flags
     * @param array<string, string> $moduleFiles 扩展名到共享模块路径。
     * @return list<string>
     */
    public function sharedModulesBeforeSwoole(array $flags, array $moduleFiles): array
    {
        $pgsql = in_array('--enable-swoole-pgsql', $flags, true);
        $sqlite = in_array('--enable-swoole-sqlite', $flags, true);
        $names = [];
        if (($pgsql || $sqlite) && isset($moduleFiles['pdo'])) {
            $names[] = 'pdo';
        }
        if ($pgsql && isset($moduleFiles['pdo_pgsql'])) {
            $names[] = 'pdo_pgsql';
        }
        if ($sqlite && isset($moduleFiles['pdo_sqlite'])) {
            $names[] = 'pdo_sqlite';
        }

        return $names;
    }

    /**
     * 统计要求某个 PDO 钩子时，运行声明里必须已经有对应驱动。
     *
     * @param list<string> $flags
     * @param list<string> $runtimeExtensions
     * @throws RuntimeException 运行声明缺少 pdo_pgsql 或 pdo_sqlite。
     */
    public function assertRuntimeCoverage(array $flags, array $runtimeExtensions): void
    {
        $runtime = [];
        foreach ($runtimeExtensions as $extension) {
            if (!is_string($extension)) {
                throw new RuntimeException('运行扩展名称无效');
            }
            $runtime[] = strtolower($extension);
        }
        foreach (['--enable-swoole-pgsql' => 'pdo_pgsql', '--enable-swoole-sqlite' => 'pdo_sqlite'] as $flag => $extension) {
            if (in_array($flag, $flags, true) && !in_array($extension, $runtime, true)) {
                throw new RuntimeException('运行声明缺少 ' . $extension);
            }
        }
    }

    /**
     * 产品运行库保留 Swoole 共享模块的传递依赖，但不再携带 swoole.so 本身。
     *
     * @param list<array<string, mixed>> $libraries
     * @return list<array<string, mixed>>
     * @throws RuntimeException 运行库缺少名称。
     */
    public function productLibraries(array $libraries): array
    {
        $kept = [];
        foreach ($libraries as $library) {
            if (!is_array($library) || !is_string($library['name'] ?? null) || $library['name'] === '') {
                throw new RuntimeException('运行库声明无效');
            }
            $name = $library['name'];
            if ($name === 'swoole.so' || strcasecmp($name, 'php_swoole.dll') === 0) {
                continue;
            }
            $kept[] = $library;
        }

        return $kept;
    }

    /**
     * 只认未定义符号表里的独立记号，避免 php_curl_multi_ce 被当成 curl_multi_ce。
     *
     * Mach-O 会给 C 符号加一个下划线，因此 _curl_multi_ce 与 curl_multi_ce 是同一个符号。
     */
    public static function referencesUndefinedSymbol(string $listing, string $symbol): bool
    {
        foreach (preg_split('/\R/', $listing) ?: [] as $line) {
            if (preg_match('/(?:^|\s)U(?:\s|$)/', $line) !== 1) {
                continue;
            }
            $tokens = preg_split('/\s+/', trim($line)) ?: [];
            foreach ($tokens as $token) {
                if ($token === $symbol || $token === '_' . $symbol) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, string|false|null>|null $catalog */
    private function owner(string $name, bool $class, ?array $catalog): string|false|null
    {
        if ($catalog !== null) {
            if (!array_key_exists($name, $catalog)) {
                return null;
            }
            $owner = $catalog[$name];
            if ($owner === null) {
                return null;
            }
            if ($owner === false) {
                return false;
            }
            if (!is_string($owner) || $owner === '') {
                throw new RuntimeException('符号目录无效：' . $name);
            }

            return strtolower($owner);
        }
        if ($class) {
            if (!class_exists($name, false) && !interface_exists($name, false) && !trait_exists($name, false)) {
                return null;
            }
            try {
                $extension = (new \ReflectionClass($name))->getExtensionName();
            } catch (\ReflectionException) {
                return null;
            }
        } else {
            if (!function_exists($name)) {
                return null;
            }
            try {
                $extension = (new \ReflectionFunction($name))->getExtensionName();
            } catch (\ReflectionException) {
                return null;
            }
        }
        if (!is_string($extension) || $extension === '') {
            return false;
        }

        return strtolower($extension);
    }

    /** @return string|null|false 字符串是开关，null 是超集不提供的可选符号，false 不是可选块。 */
    private function optionalFlag(string $class): string|null|false
    {
        $class = strtolower($class);
        if (str_starts_with($class, 'swoole\\thread')) {
            return '--enable-swoole-thread';
        }

        return match ($class) {
            'swoole\\coroutine\\postgresql' => '--enable-swoole-pgsql',
            'swoole\\coroutine\\sqlite' => '--enable-swoole-sqlite',
            'swoole\\coroutine\\mysql' => '--enable-mysqlnd',
            'swoole\\coroutine\\redis' => null,
            default => false,
        };
    }

    /** @return string|null|false */
    private function functionFlag(string $function): string|null|false
    {
        if (!str_starts_with($function, 'swoole_')) {
            return false;
        }
        if (str_contains($function, 'pgsql')) {
            return '--enable-swoole-pgsql';
        }
        if (str_contains($function, 'sqlite')) {
            return '--enable-swoole-sqlite';
        }
        if (str_contains($function, 'mysql')) {
            return '--enable-mysqlnd';
        }
        if (str_contains($function, 'redis')) {
            return null;
        }

        return false;
    }

    private function isLibraryClass(string $class): bool
    {
        $class = strtolower($class);

        return $class === 'swoole\\connectionpool'
            || str_starts_with($class, 'swoole\\database\\')
            || str_starts_with($class, 'swoole\\nameresolver');
    }
}
