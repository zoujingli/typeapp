<?php

declare(strict_types=1);

namespace Type\Build;

use Throwable;

/** 只读诊断开发依赖和构建前提；不读dotenv、不连接业务服务，不替代实际验收。 */
final class EnvironmentDoctor
{
    /** @return array<string,mixed> 结构化诊断，不包含业务环境值。 */
    public function inspect(string $configuration): array
    {
        $project = (new BuildProject())->read($configuration);
        $root = $project['root'];
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $vendorSetting = $composer['config']['vendor-dir'] ?? 'vendor';
        $platform = new BuildPlatform();
        $vendor = $platform->absolute($vendorSetting) ? $vendorSetting : $root . '/' . $vendorSetting;
        $installed = is_file($vendor . '/composer/installed.json') ? json_decode((string) file_get_contents($vendor . '/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR) : [];
        $packages = array_column($installed['packages'] ?? [], null, 'name');
        $missing = [];
        $extensions = [];
        $queue = array_keys($composer['require'] ?? []);
        $seen = [];
        while ($queue !== []) {
            $name = array_shift($queue);
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            if (str_starts_with($name, 'ext-')) {
                $extension = substr($name, 4);
                $extensions[$extension] = extension_loaded($extension);
            } elseif (str_contains($name, '/')) {
                if (!isset($packages[$name])) {
                    $missing[] = $name;
                } else {
                    array_push($queue, ...array_keys($packages[$name]['require'] ?? []));
                }
            }
        }
        ksort($extensions);
        sort($missing);
        $development = ['php' => PHP_VERSION, 'framework-php-range' => '>=8.4 <8.6',
            'php-range-matches' => version_compare(PHP_VERSION, '8.4', '>=') && version_compare(PHP_VERSION, '8.6', '<'),
            'composer-installed' => is_file($vendor . '/autoload.php'), 'missing-packages' => $missing, 'extensions' => $extensions];
        $development['ready'] = $development['php-range-matches'] && $development['composer-installed'] && $missing === [] && !in_array(false, $extensions, true);
        $lockFile = $root . '/toolchain.lock.json';
        $lock = is_file($lockFile) ? json_decode((string) file_get_contents($lockFile), true, 512, JSON_THROW_ON_ERROR) : [];
        $build = ['ready' => false, 'toolchain-lock' => $lock !== [], 'php-abi-matches' => ($lock['php'] ?? null) === PHP_VERSION && ($lock['zts'] ?? null) === (bool) PHP_ZTS,
            'php-home' => getenv('PHP_HOME') ?: null, 'phpx-home' => getenv('PHPX_HOME') ?: null, 'issues' => []];
        if (!$build['toolchain-lock']) {
            $build['issues'][] = 'toolchain_lock_missing';
        }
        if (!$build['php-abi-matches']) {
            $build['issues'][] = 'php_abi_mismatch';
        }
        if ($build['php-home'] === null || $build['phpx-home'] === null) {
            $build['issues'][] = 'sdk_not_configured';
        } else {
            try {
                $environment = $platform->environment($build['php-home'], $build['phpx-home']);
                $build['runtime-libraries'] = $platform->runtimeLibraries($build['php-home'], $build['phpx-home']);
                $compiler = PHP_OS_FAMILY === 'Windows' ? 'cl.exe' : (PHP_OS_FAMILY === 'Darwin' ? 'clang++' : 'g++');
                $found = false;
                foreach (explode(PHP_OS_FAMILY === 'Windows' ? ';' : ':', $environment['PATH']) as $directory) {
                    if (is_file($directory . '/' . $compiler)) {
                        $build['compiler'] = $directory . '/' . $compiler;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $build['issues'][] = 'compiler_not_found';
                }
            } catch (Throwable $error) {
                $build['issues'][] = 'sdk_invalid';
                $build['diagnostic'] = $error->getMessage();
            }
        }
        $build['ready'] = $build['issues'] === [] && $development['ready'] && is_file($root . '/composer.lock');
        return ['project' => $root, 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'development' => $development, 'build' => $build,
            'runtime' => ['verified' => false, 'next' => '构建后执行原生产物verify-runtime；本诊断不执行应用'],
            'scope' => '前提检测不是编译/业务/部署通过证明；Composer仍负责完整版本约束校验'];
    }
}
