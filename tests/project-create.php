<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\ProjectCreator;

$root = dirname(__DIR__);
$base = $root . '/build/create-test-' . bin2hex(random_bytes(6));
$template = $base . '/template';
expect(mkdir($template, 0700, true), '无法创建模板夹具');
try {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/templates/type-project', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
        $relative = substr($entry->getPathname(), strlen($root . '/templates/type-project') + 1);
        if ($entry->isDir()) {
            mkdir($template . '/' . $relative, 0700, true);
        } else {
            copy($entry->getPathname(), $template . '/' . $relative);
        }
    }
    mkdir($template . '/tools', 0700);
    file_put_contents($template . '/tools/not-for-users.php', '<?php throw new RuntimeException("not template payload");');
    file_put_contents($template . '/configure.php', '<?php file_put_contents(__DIR__."/executed", "must not execute");');
    $creator = new ProjectCreator();
    foreach (['1.0.x-dev' => 'dev', '1.0.0-rc.99' => 'RC', '1.0.0' => 'stable'] as $constraint => $stability) {
        $templateComposer = json_decode(file_get_contents($template . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (['require', 'require-dev'] as $scope) {
            foreach ($templateComposer[$scope] as $package => $_) {
                if (str_starts_with($package, 'zoujingli/type-')) {
                    $templateComposer[$scope][$package] = $constraint;
                }
            }
        }
        $templateComposer['minimum-stability'] = $stability;
        file_put_contents($template . '/composer.json', json_encode($templateComposer, JSON_THROW_ON_ERROR));
        foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
            $result = $creator->create($template, $base . '/application ' . $stability . '-' . $driver, $driver);
            $directory = $result['directory'];
            $composer = json_decode(file_get_contents($directory . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            foreach (['sqlite', 'mysql', 'pgsql'] as $candidate) {
                expect(isset($composer['require']['zoujingli/type-orm-' . $candidate]) === ($candidate === $driver), '创建结果包含未选择驱动');
            }
            expect($composer['require']['zoujingli/type-orm-' . $driver] === $constraint
                && $composer['minimum-stability'] === $stability && $composer['prefer-stable'] === $templateComposer['prefer-stable']
                && $composer['require-dev'] === $templateComposer['require-dev'], '创建项目重置了模板版本或稳定性策略');
            expect(file_get_contents($directory . '/app/common/database/DatabaseFactory.php') === file_get_contents($template . '/scaffold/' . $driver . '.php'), '驱动业务工厂不匹配');
            expect(!isset($composer['extra']['type-template']) && str_starts_with($composer['name'], 'app/'), '用户项目仍冒充模板包');
            foreach ($composer['repositories'] ?? [] as $repository) {
                expect(str_starts_with($repository['url'], 'https://github.com/zoujingli/'), '创建结果仍要求私有 SSH 读取凭据');
            }
            expect(file_get_contents($directory . '/LICENSE') === file_get_contents($template . '/LICENSE')
                && file_get_contents($directory . '/NOTICE') === file_get_contents($template . '/NOTICE'), '新项目遗漏 Apache-2.0 材料');
            expect(!is_dir($directory . '/tools') && !is_dir($directory . '/vendor') && !is_file($directory . '/executed') && !is_file($template . '/executed'), '创建执行了模板或复制了无关工具');
            $rejected = false;
            try {
                $creator->create($template, $directory, $driver);
            } catch (RuntimeException) {
                $rejected = true;
            }
            expect($rejected, '创建覆盖了已有项目');
            // 用户安装前可以继续选驱动，公开配置入口同样保留原版本；从另一个工作目录运行。
            copy($root . '/templates/type-project/configure.php', $directory . '/configure.php');
            $nextDriver = $driver === 'sqlite' ? 'mysql' : 'sqlite';
            successful([PHP_BINARY, $directory . '/configure.php', $nextDriver], $base);
            $configured = json_decode(file_get_contents($directory . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            expect($configured['require']['zoujingli/type-orm-' . $nextDriver] === $constraint
                && $configured['minimum-stability'] === $stability && $configured['require-dev'] === $composer['require-dev'], '配置驱动重置了组件批次');
            $before = [file_get_contents($directory . '/composer.json'), file_get_contents($directory . '/app/common/database/DatabaseFactory.php')];
            file_put_contents($directory . '/composer.lock', '{}');
            expect(execute([PHP_BINARY, $directory . '/configure.php', $driver], $base)[0] !== 0, '配置工具修改了已安装项目');
            unlink($directory . '/composer.lock');
            expect(execute([PHP_BINARY, $directory . '/configure.php', 'invalid'], $base)[0] !== 0, '配置工具接受无效驱动');
            expect($before === [file_get_contents($directory . '/composer.json'), file_get_contents($directory . '/app/common/database/DatabaseFactory.php')], '拒绝配置后修改了原项目');
            if (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_geteuid') && posix_geteuid() !== 0) {
                $databaseDirectory = $directory . '/app/common/database';
                chmod($databaseDirectory, 0555);
                try {
                    expect(execute([PHP_BINARY, $directory . '/configure.php', $driver], $base)[0] !== 0, '只读目标没有拒绝配置');
                    expect($before === [file_get_contents($directory . '/composer.json'), file_get_contents($directory . '/app/common/database/DatabaseFactory.php')], '写入失败留下了半套驱动配置');
                } finally {
                    chmod($databaseDirectory, 0700);
                }
            }
            expect(glob($directory . '/.configure-*') === [] && glob($directory . '/app/common/database/.configure-*') === [], '配置失败未清理暂存文件');
        }
    }
    $validComposer = file_get_contents($template . '/composer.json');
    foreach ([null, '', 123, ['1.0.0']] as $invalidConstraint) {
        $invalidComposer = json_decode($validComposer, true, 512, JSON_THROW_ON_ERROR);
        $invalidComposer['require']['zoujingli/type-orm-sqlite'] = $invalidConstraint;
        file_put_contents($template . '/composer.json', json_encode($invalidComposer, JSON_THROW_ON_ERROR));
        try {
            $creator->create($template, $base . '/invalid-constraint');
            throw new LogicException('创建工具接受了无效版本策略');
        } catch (RuntimeException $error) {
            expect(str_contains($error->getMessage(), '驱动版本约束'), '无效版本没有明确失败');
        }
        expect(!is_dir($base . '/invalid-constraint'), '无效版本留下新项目');
    }
    file_put_contents($template . '/composer.json', $validComposer);
    file_put_contents($template . '/.env.example', "APP_API_TOKEN=not-for-users\n");
    $rejected = false;
    try {
        $creator->create($template, $base . '/secret-rejected');
    } catch (RuntimeException) {
        $rejected = true;
    }
    expect($rejected && !is_dir($base . '/secret-rejected'), '携密模板被创建');
    $result = json_decode(successful([PHP_BINARY, $root . '/vendor/bin/type', 'create', $root . '/templates/type-project', $base . '/cli-project', 'sqlite']), true, 512, JSON_THROW_ON_ERROR);
    expect(is_file($result['directory'] . '/type-app.json'), '统一创建入口未生成项目');
    echo "模板三库版本策略、工作目录、拒绝覆盖、写入失败、秘密排除与CLI创建通过。\n";
} finally {
    removeTestDirectory($base);
}
