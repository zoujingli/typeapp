<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/plugin/type-build/src/BuildProfile.php';

use Type\Build\BuildProfile;

// SDK 和应用编译共用同一份声明与闭包；不由 shell 维护第二套默认功能表。
try {
    if ($argc !== 2) {
        throw new InvalidArgumentException('用法：php tools/build-profile.php <sqlite|mysql|pgsql|all>');
    }
    $configuration = getenv('TYPEAPP_BUILD_CONFIGURATION') ?: dirname(__DIR__) . '/docs/build-config/type-app.json';
    $settings = json_decode((string) file_get_contents($configuration), true, 64, JSON_THROW_ON_ERROR);
    $resolved = BuildProfile::resolve($settings);
    $name = $argv[1];
    if ($name === 'all') {
        $features = array_values(array_unique(array_merge(...array_column($resolved['profiles'], 'features'))));
        sort($features);
    } else {
        $profile = $resolved['profiles'][$name] ?? throw new RuntimeException('未知 SDK profile：' . $name);
        if ($profile['database'] !== $name) {
            throw new RuntimeException('当前 SDK 制备入口要求 profile 名称与数据库一致');
        }
        $features = $profile['features'];
    }
    if (array_intersect($features, ['dom', 'xml', 'intl', 'zip']) !== []) {
        throw new RuntimeException('内置 SDK 制备尚不支持 DOM/XML/intl/zip；需要提供并验收声明这些扩展的自定义静态 SDK');
    }
    $override = getenv('TYPEAPP_BUILD_FEATURES');
    if ($override !== false && $override !== '') {
        $declared = explode(',', $override);
        sort($declared);
        if ($declared !== $features) {
            throw new RuntimeException('TYPEAPP_BUILD_FEATURES 与应用声明的功能闭包冲突');
        }
    }
    echo implode(',', $features) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
