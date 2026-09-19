<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\ArtifactCache;
use Type\Build\ArtifactManifest;
use Type\Build\BuildIdentity;
use Type\Build\BuildEnvironment;

$root = dirname(__DIR__);
$work = $root . '/build/cache-check-' . bin2hex(random_bytes(5));
expect(mkdir($work . '/src', 0755, true), '无法创建缓存行为验证目录');
file_put_contents($work . '/src/main.php', '<?php function example(): int { return 1; }');
file_put_contents($work . '/src/value.h', '#define VALUE 1');
file_put_contents($work . '/resource.txt', 'resource-one');
file_put_contents($work . '/composer.lock', '{"packages":[]}');
file_put_contents($work . '/generator.php', '<?php const GENERATION = 1;');
$builder = new BuildIdentity();
$facts = ['abi' => ['php' => PHP_VERSION, 'zts' => (bool) PHP_ZTS, 'architecture' => php_uname('m')],
    'parameters' => ['optimize' => 2], 'capabilities' => ['messages' => ['example' => [1]], 'schema' => ['main' => [1]]]];
$snapshot = static fn (array $overrides = []): array => $builder->create([
    'sources' => $builder->sources([$work . '/src']), 'resources' => [$work . '/resource.txt'],
    'locks' => [$work . '/composer.lock'], 'generator' => [$work . '/generator.php'],
], array_replace($facts, $overrides));
$initial = $snapshot();
touch($work . '/src/main.php', 1000000000);
file_put_contents($work . '/src/README.md', '无关文档');
expect($snapshot()['id'] === $initial['id'], '时间戳或非源码文档破坏了安全复用');
foreach (['src/main.php' => '<?php function example(): int { return 2; }', 'src/value.h' => '#define VALUE 2',
    'resource.txt' => 'resource-two', 'composer.lock' => '{"packages":[1]}', 'generator.php' => '<?php const GENERATION = 2;'] as $file => $changed) {
    $original = file_get_contents($work . '/' . $file);
    $mtime = filemtime($work . '/' . $file);
    file_put_contents($work . '/' . $file, $changed);
    touch($work . '/' . $file, $mtime);
    expect($snapshot()['id'] !== $initial['id'], '内容变化没有使缓存失效：' . $file);
    file_put_contents($work . '/' . $file, $original);
    touch($work . '/' . $file, $mtime);
}
foreach ([['abi' => ['php' => 'changed']], ['parameters' => ['optimize' => 3]], ['capabilities' => ['schema' => ['main' => [2]]]]] as $change) {
    expect($snapshot($change)['id'] !== $initial['id'], 'ABI、参数或能力变化没有使缓存失效');
}
file_put_contents($work . '/src/added.php', '<?php function added(): void {}');
expect($snapshot()['id'] !== $initial['id'], '新增生产源码没有进入身份');
unlink($work . '/src/added.php');
expect($snapshot()['id'] === $initial['id'], '回到相同输入后身份不能复现');
$capabilities = (new Type\Build\BuildCapabilities())->collect(['capabilities' => ['schema' => ['main' => [2, 1]]],
    'queue' => ['jobs' => [['type' => 'message', 'version' => 1], ['type' => 'message', 'version' => 2]]]], []);
expect($capabilities['schema']['main'] === [1, 2] && $capabilities['messages']['message'] === [1, 2], '声明与真实任务注册没有形成版本能力集合');
$rejected = false;
try {
    (new Type\Build\BuildCapabilities())->collect(
        ['capabilities' => ['schema' => ['main' => [1]]]],
        [['extra' => ['type' => ['capabilities' => ['schema' => ['main' => [2]]]]]]]
    );
} catch (RuntimeException $error) {
    $rejected = true;
}
expect($rejected, '冲突的 schema 能力被静默覆盖');
file_put_contents($work . '/.env', 'TYPE_PASSWORD=not-for-build');
$staged = (new Type\Build\BuildWorkspace())->create($work, $work . '/build/isolated', $initial['description']['inputs']);
expect(is_file($staged['directory'] . '/src/main.php') && !is_file($staged['directory'] . '/.env'), '隔离输入遗漏源码或混入运行秘密');
$secretIdentity = $builder->create(['inputs' => [$work . '/.env']], []);
$rejected = false;
try {
    (new Type\Build\BuildWorkspace())->create($work, $work . '/build/rejected', $secretIdentity['description']['inputs']);
} catch (RuntimeException $error) {
    $rejected = true;
}
expect($rejected, '被显式误选的秘密文件没有拒绝');

if (PHP_OS_FAMILY !== 'Linux') {
    echo "源码、头文件、锁、生成器、资源、ABI 和参数的内容身份验证通过；ELF 缓存在 Linux 验证。\n";
    exit(0);
}
// 使用系统已有的真实 ELF 验证缓存字节，不将该夹具称为 TypePHP 应用编译验收。
$fixture = '/usr/bin/true';
$runtimeLibrary = null;
foreach (file('/proc/self/maps') as $mapping) {
    if (preg_match('~(/[^\s]+/libc\.so\.[^\s]+)$~', trim($mapping), $match)) {
        $runtimeLibrary = $match[1];
        break;
    }
}
expect($runtimeLibrary !== null, '无法找到当前进程真实加载的 libc');
$manifest = ['build-id' => $initial['id'], 'application' => 'cache-fixture', 'version' => 'test',
    'runtime' => ['php' => PHP_VERSION, 'zts' => (bool) PHP_ZTS, 'architecture' => php_uname('m'), 'os' => PHP_OS_FAMILY, 'extensions' => ['json' => phpversion('json')]],
    'native-libraries' => [['name' => 'fixture-library', 'path' => $runtimeLibrary, 'sha256' => hash_file('sha256', $runtimeLibrary)]], 'capabilities' => $facts['capabilities']];
$cache = new ArtifactCache($work . '/cache');
$builds = 0;
$compile = static function (string $candidate) use ($fixture, &$builds): void {
    $builds++;
    copy($fixture, $candidate);
    chmod($candidate, 0755);
};
$one = $cache->materialize($initial, $manifest, $work . '/app', $compile);
$two = $cache->materialize($snapshot(), $manifest, $work . '/app', $compile);
expect(!$one['hit'] && $two['hit'] && $builds === 1 && $one['artifact-sha256'] === $two['artifact-sha256'], '重复构建没有跳过产物生成或身份不稳定');
expect(successful([$work . '/app']) === '', '附带清单的真实 ELF 无法运行');
$reader = new ArtifactManifest();
$read = $reader->read($work . '/app', $initial['id'], $one['artifact-sha256']);
expect(json_decode(successful([PHP_BINARY, $root . '/vendor/bin/type', '--inspect', $work . '/app']), true)['build-id'] === $initial['id'], 'Composer 构建工具无法读取 ELF 清单');
expect(str_contains(successful([PHP_BINARY, $root . '/vendor/bin/type', '--verify', $work . '/app', $one['artifact-sha256']]), '校验通过'), '部署检查入口没有核对固定产物');
expect($read['capabilities'] === $facts['capabilities'] && $read['version'] === 'test', '产物清单没有保存版本或能力');
$reader->verifyRuntime($read);
$generation = hash('sha256', 'resources');
mkdir($work . '/app.resources/' . $generation, 0755, true);
copy($work . '/resource.txt', $work . '/app.resources/' . $generation . '/message.txt');
$resourceManifest = $read + ['resource-generation' => $generation, 'resources' => [['target' => 'message.txt', 'sha256' => hash_file('sha256', $work . '/resource.txt')]]];
$reader->verifyResources($work . '/app', $resourceManifest);
file_put_contents($work . '/app.resources/' . $generation . '/message.txt', 'changed');
$rejected = false;
try {
    $reader->verifyResources($work . '/app', $resourceManifest);
} catch (RuntimeException $error) {
    $rejected = true;
}
expect($rejected, '部署资源与原生产物不一致没有拒绝');
$changed = $read;
$changed['runtime']['zts'] = !$changed['runtime']['zts'];
$rejected = false;
try {
    $reader->verifyRuntime($changed);
} catch (RuntimeException $error) {
    $rejected = true;
}
expect($rejected, '运行 ABI 不匹配没有拒绝');
$rejected = false;
try {
    $reader->verifyRuntime($read, ['fixture-library' => $work . '/resource.txt']);
} catch (RuntimeException $error) {
    $rejected = true;
}
expect($rejected, '部署运行库内容不同没有拒绝');
$rejected = false;
try {
    $reader->read($work . '/app', null, str_repeat('0', 64));
} catch (RuntimeException $error) {
    $rejected = true;
}
expect($rejected, '受信任发布摘要不一致没有拒绝');
file_put_contents($work . '/identity.php', $reader->accessor($manifest));
require $work . '/identity.php';
expect(Type\Generated\BuildIdentity::info()['build-id'] === $initial['id'], '生成访问器没有绑定相同构建身份');
Type\Generated\BuildIdentity::verifyRuntime();
file_put_contents($work . '/identity.json', json_encode(['identity' => $initial, 'manifest' => $manifest], JSON_THROW_ON_ERROR));
$processes = [];
for ($index = 0; $index < 2; $index++) {
    $out = tmpfile();
    $err = tmpfile();
    $process = proc_open([PHP_BINARY, __DIR__ . '/build-cache-worker.php', $work], [0 => ['file', '/dev/null', 'r'], 1 => $out, 2 => $err], $pipes);
    expect(is_resource($process), '无法启动并发缓存验证');
    $processes[] = [$process, $out, $err];
}
$results = [];
foreach ($processes as [$process, $out, $err]) {
    $status = proc_close($process);
    rewind($out);
    rewind($err);
    $stdout = stream_get_contents($out);
    $stderr = stream_get_contents($err);
    fclose($out);
    fclose($err);
    expect($status === 0 && $stderr === '', '并发缓存构建失败：' . $stderr);
    $results[] = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
}
expect(count(array_filter($results, static fn (array $result): bool => $result['hit'])) === 1
    && $results[0]['sha256'] === $results[1]['sha256'] && file_get_contents($work . '/compiled.txt') === "compiled\n", '并发构建没有互斥或复用了未提交产物');

file_put_contents($work . '/cache/' . $initial['id'] . '/artifact', 'corrupt', FILE_APPEND);
$repaired = $cache->materialize($initial, $manifest, $work . '/app', $compile);
expect(!$repaired['hit'] && $repaired['reason'] === 'rejected' && $builds === 2, '损坏缓存被复用或没有隔离重建');
$protected = hash_file('sha256', $work . '/app');
$other = $snapshot(['parameters' => ['optimize' => 0]]);
$otherManifest = $manifest;
$otherManifest['build-id'] = $other['id'];
$failed = false;
try {
    $cache->materialize($other, $otherManifest, $work . '/app', static function (string $candidate): void {
        file_put_contents($candidate, 'partial');
        throw new RuntimeException('生成失败');
    });
} catch (RuntimeException $error) {
    $failed = true;
}
expect($failed && hash_file('sha256', $work . '/app') === $protected, '失败生成覆盖了原有产物');
symlink($work . '/app', $work . '/linked-output');
$rejected = false;
try {
    $cache->materialize($initial, $manifest, $work . '/linked-output', $compile);
} catch (RuntimeException $error) {
    $rejected = true;
}
expect($rejected && hash_file('sha256', $work . '/app') === $protected, '缓存恢复写入了输出软链接');

$previous = getenv('TYPE_BUILD_TEST_SECRET');
putenv('TYPE_BUILD_TEST_SECRET=must-not-inherit');
try {
    $environment = new BuildEnvironment();
    $answer = $environment->run([PHP_BINARY, '-r', 'echo getenv("TYPE_BUILD_TEST_SECRET") === false && getenv("COMPOSER_AUTH") === false && getenv("LD_PRELOAD") === false ? "clean" : "leaked";'], $work, $environment->environment());
    expect($answer === 'clean', '构建子进程继承了非白名单环境');
    $timeout = false;
    $started = microtime(true);
    try {
        $environment->run(['/bin/sleep', '2'], $work, $environment->environment(), 0.05);
    } catch (RuntimeException $error) {
        $timeout = str_contains($error->getMessage(), '超时');
    }
    expect($timeout && microtime(true) - $started < 0.5, '构建子进程没有遵守取消预算');
} finally {
    $previous === false ? putenv('TYPE_BUILD_TEST_SECRET') : putenv('TYPE_BUILD_TEST_SECRET=' . $previous);
}
echo "构建内容身份、ELF 复用证据、损坏隔离、失败保护、运行库校验与环境隔离通过。\n";
