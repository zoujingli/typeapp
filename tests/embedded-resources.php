<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\ArtifactManifest;
use Type\Build\BuildPlatform;
use Type\Build\NativePackage;
use Type\Testing\Process;

$root = dirname(__DIR__);
$work = $root . '/build/embedded-resources-' . bin2hex(random_bytes(6));
expect(mkdir($work . '/dist/assets', 0700, true), '无法创建内嵌资源测试目录');
$bytes = str_repeat("native\0bytes\xff", 10000);
file_put_contents($work . '/dist/index.html', '<html>embedded</html>');
file_put_contents($work . '/dist/assets/payload.bin', $bytes);
file_put_contents($work . '/dist/empty.txt', '');
$composer = ['name' => 'type-tests/embedded', 'require' => ['zoujingli/type-core' => '1.0.x-dev'],
    'require-dev' => ['zoujingli/type-build' => '1.0.x-dev'], 'config' => ['vendor-dir' => '../../vendor', 'allow-plugins' => false],
    'autoload' => ['psr-4' => ['app\\common\\service\\' => 'app/']]];
mkdir($work . '/app');
foreach (['FrontendAssets', 'FrontendPages', 'FrontendException'] as $name) {
    copy($root . '/app/common/service/' . $name . '.php', $work . '/app/' . $name . '.php');
}
file_put_contents($work . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
copy($root . '/composer.lock', $work . '/composer.lock');
copy($root . '/toolchain.lock.json', $work . '/toolchain.lock.json');
file_put_contents($work . '/main.php', <<<'PHP'
<?php

declare(strict_types=1);

function main(int $argc, array $argv): void
{
    \Type\Generated\BuildIdentity::verifyRuntime();
    $assets = \app\common\service\FrontendAssets::application($argv[1], false);
    $assets->install(in_array('--force', $argv, true));
    $assets->verifyInstalled();
    $pages = new \app\common\service\FrontendPages($assets);
    $factory = new \Type\Core\Http\Message\Factory();
    $response = $pages->respond($factory->createServerRequest('GET', 'http://localhost/'), $factory);
    if ($response === null || (string) $response->getBody() !== '<html>embedded</html>') {
        throw new RuntimeException('原生页面响应不正确');
    }
    echo hash_file('sha256', $assets->file('assets/payload.bin')) . "\n";
}
PHP);
$settings = ['name' => 'embedded-test', 'entry' => 'main.php', 'sources' => ['app'],
    'embedded-resources' => [['source' => 'dist', 'target' => 'web']], 'output' => 'build/type-app',
    'build-directory' => 'build/compiler', 'compiler' => ['optimize' => 2, 'debug' => false, 'jobs' => 2]];
file_put_contents($work . '/build.json', json_encode($settings, JSON_THROW_ON_ERROR));
[$status, $output, $error] = execute([PHP_BINARY, $root . '/vendor/bin/type', $work . '/build.json'], $work);
file_put_contents($work . '/compile.log', $output . $error);
expect($status === 0, '内嵌资源原生编译失败，见 ' . $work . '/compile.log' . "\n" . substr($error, -3000));
$artifact = (new BuildPlatform())->output($work . '/build/type-app');
$manifest = (new ArtifactManifest())->read($artifact);
expect($manifest['embedded-resources']['web/assets/payload.bin']['sha256'] === hash('sha256', $bytes), '内嵌资源未绑定构建身份');
$package = (new NativePackage())->create($artifact, $work . '/release');
rename($work . '/dist', $work . '/unavailable-dist');
mkdir($work . '/deployment with spaces');
$environment = getenv();
$environment['TYPE_APP_RELEASE_SHA256'] = $package['manifest-sha256'];
$launcher = $package['directory'] . (PHP_OS_FAMILY === 'Windows' ? '/run.cmd' : '/run');
foreach ([[], [], ['--force']] as $options) {
    $result = (new Process([$launcher, $work . '/deployment with spaces', ...$options], $root, $environment))->wait(30);
    expect($result->successful() && trim($result->stdout) === hash('sha256', $bytes), '原生资源安装或页面读取失败：' . $result->stderr);
}
expect(file_get_contents($work . '/deployment with spaces/public/assets/payload.bin') === $bytes, '二进制资源字节发生变化');
file_put_contents($work . '/verification.json', json_encode(['source-unavailable' => true, 'artifact-sha256' => hash_file('sha256', $artifact),
    'manifest-sha256' => $package['manifest-sha256'], 'checks' => ['binary-bytes', 'empty-file', 'chunk-boundary', 'install', 'repeat', 'force', 'spaces', 'different-cwd', 'page']], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
echo '内嵌资源AOT、安装和页面读取通过：' . $work . "\n";
