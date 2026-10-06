<?php

declare(strict_types=1);

/** 保留应用准备入口，实现由type-build统一维护。 */
function prepareTypeProject(string $root): array
{
    return typeProjectDevelopmentBuilder($root)->prepareConfiguration($root . '/type-app.json');
}

/** 只定位开发构建器；加载入口自行完成一次完整代次校验。 */
function typeProjectDevelopmentBuilder(string $root): Type\Build\DevelopmentBuilder
{
    $composer = is_file($root . '/composer.json')
        ? json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR) : [];
    $vendor = $composer['config']['vendor-dir'] ?? 'vendor';
    if (!is_string($vendor) || $vendor === '') {
        throw new RuntimeException('Composer vendor-dir 配置无效');
    }
    $vendor = str_starts_with($vendor, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $vendor) === 1
        ? $vendor : $root . '/' . $vendor;
    $autoload = $vendor . '/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('请先执行 composer install');
    }
    $GLOBALS['_composer_autoload_path'] ??= $autoload;
    $GLOBALS['__type_build_dependency_vendor_directory'] ??= $vendor;
    $bootstrap = $vendor . '/zoujingli/type-build/src/bootstrap.php';
    if (!is_file($bootstrap) && is_file($root . '/tooling/src/bootstrap.php')) {
        $GLOBALS['__type_build_source_directory'] = $root . '/tooling';
        $bootstrap = $root . '/tooling/src/bootstrap.php';
    }
    require_once $bootstrap;
    return new Type\Build\DevelopmentBuilder();
}

// 作为脚本直接运行时输出结果；被 dev.php 加载时仅声明上述开发期函数。
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        if (count($argv) > 2 || (isset($argv[1]) && $argv[1] !== '--json')) {
            throw new InvalidArgumentException('用法：prepare.php [--json]');
        }
        $result = prepareTypeProject(__DIR__);
        if (($argv[1] ?? '') === '--json') {
            echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        } else {
            echo '开发代码已生成，代次：' . $result['generation'] . "\n";
            echo "没有读取 .env、连接数据库或执行迁移。\n";
        }
    } catch (Throwable $error) {
        fwrite(STDERR, '开发生成失败：' . $error->getMessage() . "\n");
        exit(1);
    }
}
