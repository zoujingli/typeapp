<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\ProjectCreator;

$root = dirname(__DIR__);
$base = $root . '/build/create-test-' . bin2hex(random_bytes(6));
$template = $base . '/template';
expect(mkdir($template, 0700, true), '无法创建模板夹具');
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
foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
    $result = $creator->create($template, $base . '/application-' . $driver, $driver);
    $directory = $result['directory'];
    $composer = json_decode(file_get_contents($directory . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach (['sqlite', 'mysql', 'pgsql'] as $candidate) {
        expect(isset($composer['require']['zoujingli/type-orm-' . $candidate]) === ($candidate === $driver), '创建结果包含未选择驱动');
    }
    expect(file_get_contents($directory . '/app/common/database/DatabaseFactory.php') === file_get_contents($template . '/scaffold/' . $driver . '.php'), '驱动业务工厂不匹配');
    expect(!isset($composer['extra']['type-template']) && str_starts_with($composer['name'], 'app/'), '用户项目仍冒充模板包');
    foreach ($composer['repositories'] as $repository) {
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
}
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
echo '模板白名单、三库选择、不执行脚本、秘密拒绝与CLI创建通过：' . $result['directory'] . "\n";
