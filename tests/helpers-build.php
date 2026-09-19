<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\ArtifactManifest;
use Type\Build\BuildPlatform;

// 借用本仓已安装的锁定依赖；这是定向完整源码构建，不冒充空目录安装验收。
$root = BuildPlatform::resolve(dirname(__DIR__));
$directory = $root . '/build/helpers-native-' . bin2hex(random_bytes(6));
expect(mkdir($directory, 0700), '无法创建快捷接口原生验收目录');
$composer = ['name' => 'type-tests/helpers-native', 'require' => [
    'zoujingli/type-orm-sqlite' => '1.0.x-dev', 'zoujingli/type-validate' => '1.0.x-dev',
], 'require-dev' => ['zoujingli/type-build' => '1.0.x-dev'],
    'config' => ['vendor-dir' => '../../vendor', 'allow-plugins' => false]];
file_put_contents($directory . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
copy($root . '/composer.lock', $directory . '/composer.lock');
copy($root . '/toolchain.lock.json', $directory . '/toolchain.lock.json');
$original = (string) file_get_contents($root . '/examples/helpers/main.php');
$scenario = str_replace('function main(int $argc, array $argv): void', 'function helpersMain(int $argc, array $argv): void', $original, $replaced);
expect($replaced === 1, '快捷接口示例入口签名已变化，必须明确更新验收装配');
file_put_contents($directory . '/scenario.php', $scenario);
file_put_contents($directory . '/main.php', <<<'PHP'
<?php

declare(strict_types=1);

/** AOT验收先确认真实运行依赖，再调用与PHP模式相同的业务场景。 */
function main(int $argc, array $argv): void
{
    \Type\Generated\BuildIdentity::verifyRuntime();
    helpersMain($argc, $argv);
}
PHP);
$configuration = ['name' => 'type-helpers', 'entry' => 'main.php', 'sources' => ['scenario.php'],
    'output' => 'build/native/type-app', 'build-directory' => 'build/native/compiler',
    'compiler' => ['optimize' => 2, 'debug' => false, 'jobs' => 2]];
file_put_contents($directory . '/build.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
[$status, $stdout, $stderr] = execute([PHP_BINARY, $root . '/vendor/bin/type', $directory . '/build.json'], $directory);
file_put_contents($directory . '/compile.log', $stdout . $stderr);
expect($status === 0, '快捷接口原生构建失败，日志：' . $directory . '/compile.log' . "\n" . substr($stderr, -4000));
$artifact = (new BuildPlatform())->output($directory . '/build/native/type-app');
$report = json_decode((string) file_get_contents($artifact . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
$selected = array_keys($report['production-packages']);
sort($selected);
expect($selected === ['zoujingli/type-orm', 'zoujingli/type-orm-sqlite', 'zoujingli/type-runtime', 'zoujingli/type-validate'], '定向构建没有完整包含预期四个生产组件');
expect($report['cache']['hit'] === false, '新建验收目录不能以旧缓存代替首次编译');
$manifest = (new ArtifactManifest())->read($artifact, $report['build-id'], $report['sha256']);
$phpOutput = successful([PHP_BINARY, $root . '/tests/helpers.php', '--php']);
$nativeOutput = successful([PHP_BINARY, $root . '/tests/helpers.php', $artifact]);
expect($phpOutput === $nativeOutput, '快捷接口开发与编译产物业务行为不一致');
$evidence = ['runtime' => $manifest['runtime'], 'binary-format' => $manifest['binary-format'],
    'build-id' => $report['build-id'], 'sha256' => $report['sha256'], 'typephp-reference' => $report['typephp-reference'],
    'phpx-reference' => $report['phpx-reference'], 'production-packages' => $selected,
    'scenario-sha256' => hash('sha256', $original), 'compile-log-sha256' => hash_file('sha256', $directory . '/compile.log'),
    'php-output' => $phpOutput, 'native-output' => $nativeOutput,
    'scope' => '四组件完整源码、真实SQLite、校验与白名单排序；非整应用、非干净部署'];
file_put_contents($directory . '/verification.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo $nativeOutput . '原生对照证据：' . $directory . "/verification.json\n";
