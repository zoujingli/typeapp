<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Core\Config\Environment;
use Type\Core\Config\Repository;
use Type\Build\ConfigCompiler;

/**
 * 要求配置操作抛出包含约定诊断的异常，并验证错误信息未泄漏测试秘密。
 *
 * @param Closure(): mixed $operation
 */
function configurationRejects(Closure $operation, string $expected, string $secret = 'configuration-secret-canary'): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        expect(str_contains($error->getMessage(), $expected), '配置失败没有提供约定诊断：' . $error->getMessage());
        expect(!str_contains($error->getMessage(), $secret), '配置诊断泄漏了秘密值');
        expect(!str_contains($error->getTraceAsString(), $secret), '配置异常调用栈泄漏了秘密值');

        return;
    }
    throw new RuntimeException('配置操作没有拒绝非法输入：' . $expected);
}

$root = dirname(__DIR__);
ini_set('zend.exception_ignore_args', '0');
ini_set('zend.exception_string_param_max_len', '4096');
$work = $root . '/build/configuration-test-' . bin2hex(random_bytes(6));
expect(mkdir($work, 0700, true), '无法创建独立配置测试目录');
$prefix = 'TYPE_CONFIGURATION_' . strtoupper(bin2hex(random_bytes(6))) . '_';
$file = $work . '/dotenv.test';
file_put_contents($file, $prefix . "NAME=dotenv\n" . $prefix . "EMPTY=\n" . $prefix . "PORT=3306\n"
    . $prefix . "DEBUG=false\n" . $prefix . "RATIO=1.25\n");
$nameKey = $prefix . 'NAME';
putenv($nameKey . '=process');
try {
    $environment = Environment::load($file);
    expect($environment->get($nameKey, 'default') === 'process', '实际进程环境没有覆盖 dotenv');
    expect($environment->get($prefix . 'EMPTY', 'default') === '', '存在的空字符串被默认值覆盖');
    expect($environment->get($prefix . 'PORT', 0) === 3306 && $environment->get($prefix . 'DEBUG', true) === false
        && $environment->get($prefix . 'RATIO', 0.0) === 1.25, '环境值没有按默认值类型转换');
    expect($environment->get($prefix . 'MISSING') === null && $environment->get($prefix . 'PORT') === '3306', 'null 默认值没有保留缺失与原始文本的差异');
    expect(getenv($prefix . 'PORT') === false, '读取 dotenv 修改了进程环境');
    expect(Environment::load($work . '/missing')->get($prefix . 'ABSENT', 'default') === 'default', '不存在的显式 dotenv 文件没有保留默认值');
} finally {
    putenv($nameKey);
}

$quoted = $work . '/quoted.test';
file_put_contents($quoted, 'export ' . $prefix . "SINGLE=' a # literal $ HOME ' # comment\n"
    . $prefix . 'DOUBLE="line\\nnext\\t\\"quote\\"\\\\end" # comment' . "\r\n"
    . $prefix . "COMMENT=abc#literal # ignored\n" . $prefix . "ESCAPED=escaped\\ value\\#suffix\n");
$quotedEnvironment = Environment::load($quoted);
expect($quotedEnvironment->get($prefix . 'SINGLE') === ' a # literal $ HOME '
    && $quotedEnvironment->get($prefix . 'DOUBLE') === "line\nnext\t\"quote\"\\end"
    && $quotedEnvironment->get($prefix . 'COMMENT') === 'abc#literal'
    && $quotedEnvironment->get($prefix . 'ESCAPED') === 'escaped value#suffix', 'dotenv 引号、转义或注释解析不正确');

$sharedName = 'snapshot';
$sharedNested = ['enabled' => true, 'port' => 3306, 'name' => &$sharedName, 'nullable' => null, 'list' => [1, 2]];
$source = ['database' => &$sharedNested];
$repository = new Repository($source);
$sharedName = 'changed';
$sharedNested['port'] = 9999;
$copied = $repository->array('database');
$copied['name'] = 'outside';
expect($repository->text('database.name') === 'snapshot' && $repository->integer('database.port') === 3306
    && $repository->boolean('database.enabled') === true && $repository->get('database.list.1') === 2, '配置快照保留了深层外部引用或查询类型错误');
expect($repository->has('database.nullable') && !$repository->has('database.missing')
    && $repository->get('database.nullable', 'fallback') === null && $repository->get('database.missing', 'fallback') === 'fallback', '配置仓库混淆了缺失与 null');
$wrongType = false;
try {
    $repository->text('database.port');
} catch (InvalidArgumentException) {
    $wrongType = true;
}
expect($wrongType, '强类型读取静默转换了错误类型');

mkdir($work . '/config/nested', 0700, true);
$declaration = "<?php\ndeclare(strict_types=1);\nreturn ['name' => env('" . $prefix . "NAME', 'default'), 'enabled' => env('" . $prefix
    . "ENABLED', false), 'port' => env('" . $prefix . "PORT', 9501), 'ratio' => 1.25, 'offset' => -2, 'positive' => +3, 'cast' => (int) '42', 'nullable' => null, 'list' => [true, 'item']];\n";
file_put_contents($work . '/config/app.php', $declaration);
file_put_contents($work . '/config/nested/auth.php', "<?php\ndeclare(strict_types=1);\nreturn ['driver' => 'token'];\n");
$generatedClass = 'TypeTests\\Configuration\\Generated' . bin2hex(random_bytes(5));
$compiled = (new ConfigCompiler())->generate($work, ['class' => $generatedClass, 'files' => ['config/app.php', 'config/nested/auth.php']]);
expect($compiled['class'] === $generatedClass && $compiled['files'] === [$work . '/config/app.php', $work . '/config/nested/auth.php'], '配置编译器没有返回准确输入文件清单');
file_put_contents($work . '/Generated.php', $compiled['code']);
successful([PHP_BINARY, '-l', $work . '/Generated.php']);
require $work . '/Generated.php';
$compiledRepository = $generatedClass::load(Environment::load($file));
expect($compiledRepository->text('app.name') === 'dotenv' && !$compiledRepository->boolean('app.enabled')
    && $compiledRepository->integer('app.port') === 3306 && $compiledRepository->get('app.ratio') === 1.25
    && $compiledRepository->integer('app.offset') === -2 && $compiledRepository->integer('app.positive') === 3
    && $compiledRepository->integer('app.cast') === 42 && $compiledRepository->text('nested.auth.driver') === 'token', '配置 DSL 没有生成可执行的类型化嵌套配置');

file_put_contents($work . '/config/numeric.php', "<?php\ndeclare(strict_types=1);\nreturn ['positive' => ['1' => 'a', 'b'], 'negative' => [-2 => 'a', 'b']];\n");
$numericClass = 'TypeTests\\Configuration\\Numeric' . bin2hex(random_bytes(5));
$numericCompiled = (new ConfigCompiler())->generate($work, ['class' => $numericClass, 'files' => ['config/numeric.php']]);
file_put_contents($work . '/Numeric.php', $numericCompiled['code']);
require $work . '/Numeric.php';
$numericRepository = $numericClass::load(Environment::load());
expect($numericRepository->array('numeric.positive') === [1 => 'a', 2 => 'b']
    && $numericRepository->array('numeric.negative') === [-2 => 'a', -1 => 'b'], '配置 DSL 改变了整数或数字文本数组键的追加规则');

$invalidEnvironment = $work . '/invalid.test';
file_put_contents($invalidEnvironment, $prefix . "NAME=configuration-secret-canary\0\n");
configurationRejects(static fn () => Environment::load($invalidEnvironment), '格式');

foreach ([
    $prefix . "NAME='configuration-secret-canary\n" => '引号未闭合',
    $prefix . "NAME=\"configuration-secret-canary\" unexpected\n" => '引号后',
    $prefix . 'NAME=${configuration-secret-canary}' => '插值',
    $prefix . 'NAME=$(configuration-secret-canary)' => '命令',
    $prefix . 'NAME=`configuration-secret-canary`' => '命令',
    $prefix . "NAME=configuration-secret-canary\n" . $prefix . 'NAME=other' => '重复',
    'not a valid configuration-secret-canary assignment' => '格式',
] as $invalidContents => $expectedError) {
    file_put_contents($invalidEnvironment, $invalidContents);
    configurationRejects(static fn () => Environment::load($invalidEnvironment), $expectedError);
}
file_put_contents($invalidEnvironment, str_repeat('x', 1048577));
configurationRejects(static fn () => Environment::load($invalidEnvironment), '1 MiB');
configurationRejects(static fn () => Environment::load($work), '不可读取');
foreach ([['TRUE'], new stdClass()] as $badDefault) {
    configurationRejects(static fn () => $environment->get($prefix . 'MISSING', $badDefault), '默认值');
}
foreach ([['maybe', false], ['12x', 0], ['99999999999999999999999999999', 0], ['', false], ['', 0], ['1e999', 0.0], ['INF', 0.0]] as [$invalidText, $typedDefault]) {
    file_put_contents($invalidEnvironment, $prefix . 'VALUE=' . $invalidText);
    $invalidValueEnvironment = Environment::load($invalidEnvironment);
    configurationRejects(static fn () => $invalidValueEnvironment->get($prefix . 'VALUE', $typedDefault), '无法转换');
}
foreach (['database.nullable', 'database.missing'] as $missingText) {
    configurationRejects(static fn () => $repository->text($missingText), '配置项');
}
configurationRejects(static fn () => $repository->get('database..name'), '空段');
configurationRejects(static fn () => $repository->get('database..name', 'configuration-secret-canary'), '空段');
configurationRejects(static fn () => new Repository(['object' => new stdClass()]), '数组、标量');
$cycle = [];
$cycle['self'] = &$cycle;
configurationRejects(static fn () => new Repository($cycle), '64 层');
configurationRejects(static fn () => serialize($environment), '不允许序列化');
configurationRejects(static fn () => serialize($repository), '不允许序列化');
configurationRejects(static fn () => unserialize(sprintf('O:%d:"%s":0:{}', strlen(Repository::class), Repository::class)), '不允许反序列化');
configurationRejects(static fn () => unserialize(sprintf('O:%d:"%s":0:{}', strlen(Environment::class), Environment::class)), '不允许反序列化');
$secretRepository = new Repository(['password' => 'configuration-secret-canary']);
ob_start();
var_dump($secretRepository);
$debug = ob_get_clean();
expect(!str_contains($debug, 'configuration-secret-canary') && str_contains($debug, '[REDACTED]'), '默认调试输出包含秘密配置值');

$rejectedClass = 'TypeTests\\Configuration\\Rejected' . bin2hex(random_bytes(5));
$invalidConfig = $work . '/config/invalid.php';
$compiler = new ConfigCompiler();
$declarationPrefix = "<?php\ndeclare(strict_types=1);\n";
$marker = $work . '/must-not-execute';
$rejectedSources = [
    '<?php return [];' => '只能包含',
    $declarationPrefix . "file_put_contents('" . $marker . "', 'configuration-secret-canary'); return [];" => '只能包含',
    $declarationPrefix . 'function hidden() {} return [];' => '只能包含',
    $declarationPrefix . 'return []; return [];' => '只能包含',
    $declarationPrefix . "return ['name' => getcwd()];" => '只允许 env',
    $declarationPrefix . "return ['name' => env('A' . 'B', 'configuration-secret-canary')];" => '只接受标量',
    $declarationPrefix . "return ['name' => env(123, 'configuration-secret-canary')];" => '静态环境变量名',
    $declarationPrefix . "return ['name' => env('CONFIG_NAME', ['configuration-secret-canary'])];" => '只接受标量',
    $declarationPrefix . "return ['name' => env(key: 'CONFIG_NAME')];" => '命名参数',
    $declarationPrefix . "return ['name' => getenv('CONFIG_NAME')];" => '只允许 env',
    $declarationPrefix . "return ['name' => __DIR__];" => '只接受标量',
    $declarationPrefix . "return ['name' => new stdClass()];" => '只接受标量',
    $declarationPrefix . "return ['name' => fn () => 'configuration-secret-canary'];" => '只接受标量',
    $declarationPrefix . "return ['name' => 'first', 'name' => 'configuration-secret-canary'];" => '重复键',
    $declarationPrefix . "return ['1' => 'first', 1 => 'configuration-secret-canary'];" => '重复键',
    $declarationPrefix . "return [null => 'configuration-secret-canary'];" => '数组键',
    $declarationPrefix . "return ['a.b' => 'configuration-secret-canary'];" => '数组键',
    $declarationPrefix . "return [...['configuration-secret-canary']];" => '展开',
    $declarationPrefix . "return ['infinite' => 1e999];" => '有限',
    $declarationPrefix . "return ['invalid' => (int) 'configuration-secret-canary'];" => '整数转换',
];
foreach ($rejectedSources as $sourceText => $expectedError) {
    file_put_contents($invalidConfig, $sourceText);
    configurationRejects(static fn () => $compiler->generate($work, ['class' => $rejectedClass, 'files' => ['config/invalid.php']]), $expectedError);
    expect(!file_exists($marker), '配置编译执行了被拒绝的 PHP 表达式');
}
file_put_contents($invalidConfig, $declarationPrefix . 'return [; // configuration-secret-canary');
configurationRejects(static fn () => $compiler->generate($work, ['class' => $rejectedClass, 'files' => ['config/invalid.php']]), 'PHP 语法');
configurationRejects(static fn () => $compiler->generate($work, ['class' => $rejectedClass, 'files' => ['config/app.php'], 'extra' => true]), '只接受');
configurationRejects(static fn () => $compiler->generate($work, ['class' => $generatedClass, 'files' => ['config/app.php']]), '重名');
foreach (['../config/app.php', '/config/app.php', 'config/../app.php', 'config\\app.php', 'config/app.json', 'app.php'] as $invalidPath) {
    configurationRejects(static fn () => $compiler->generate($work, ['class' => $rejectedClass, 'files' => [$invalidPath]]), '配置');
}
configurationRejects(static fn () => $compiler->generate($work, ['class' => $rejectedClass, 'files' => ['config/app.php', 'config/app.php']]), '重复');
mkdir($work . '/config/app');
file_put_contents($work . '/config/app/child.php', $declarationPrefix . 'return [];');
configurationRejects(static fn () => $compiler->generate($work, ['class' => $rejectedClass, 'files' => ['config/app.php', 'config/app/child.php']]), '前缀');
expect(symlink($work . '/config/app.php', $work . '/config/link.php'), '无法创建限定的符号链接拒绝用例');
configurationRejects(static fn () => $compiler->generate($work, ['class' => $rejectedClass, 'files' => ['config/link.php']]), '符号链接');

$runtimeKey = $prefix . 'RUNTIME';
putenv($runtimeKey . '=configuration-secret-canary');
try {
    file_put_contents($work . '/.env', 'invalid configuration-secret-canary file');
    file_put_contents($work . '/config/casts.php', $declarationPrefix . "return ['name' => env('" . $runtimeKey . "', 'default'), 'port' => (int) env('"
        . $prefix . "PORT', '9501'), 'debug' => (bool) env('" . $prefix . "DEBUG', 'true'), 'ratio' => (float) env('" . $prefix . "RATIO', '0.1')];");
    $runtimeClass = 'TypeTests\\Configuration\\Runtime' . bin2hex(random_bytes(5));
    $runtimeCompiled = $compiler->generate($work, ['class' => $runtimeClass, 'files' => ['config/casts.php']]);
    expect(!str_contains($runtimeCompiled['code'], 'configuration-secret-canary'), '构建时秘密进入了生成代码');
    file_put_contents($work . '/Runtime.php', $runtimeCompiled['code']);
    require $work . '/Runtime.php';
    putenv($runtimeKey . '=runtime-value');
    $runtimeRepository = $runtimeClass::load(Environment::load($file));
    expect($runtimeRepository->text('casts.name') === 'runtime-value' && $runtimeRepository->integer('casts.port') === 3306
        && !$runtimeRepository->boolean('casts.debug') && $runtimeRepository->get('casts.ratio') === 1.25, '配置读取时点或标量 env 转换错误');
} finally {
    putenv($runtimeKey);
}

file_put_contents($invalidEnvironment, $prefix . 'VALUE=kept\\ ' . "\n");
expect(Environment::load($invalidEnvironment)->get($prefix . 'VALUE') === 'kept ', 'dotenv 丢失了显式转义的尾随空格');

$fixture = $work . '/fixture';
mkdir($fixture . '/config', 0700, true);
file_put_contents($fixture . '/config/app.php', $declarationPrefix . "return ['name' => env('TYPE_CONFIGURATION_DEMO_NAME', 'default'), 'port' => env('TYPE_CONFIGURATION_DEMO_PORT', 9501), "
    . "'debug' => env('TYPE_CONFIGURATION_DEMO_DEBUG', false), 'ratio' => env('TYPE_CONFIGURATION_DEMO_RATIO', 0.0), 'nullable' => null, 'nested' => ['enabled' => true]];\n");
$fixtureCompiled = $compiler->generate($fixture, ['class' => 'TypeApp\\Generated\\ConfigurationFixture', 'files' => ['config/app.php']]);
file_put_contents($fixture . '/Generated.php', $fixtureCompiled['code']);
file_put_contents($fixture . '/runtime.test', "TYPE_CONFIGURATION_DEMO_NAME='运行时配置'\nTYPE_CONFIGURATION_DEMO_PORT=9527\nTYPE_CONFIGURATION_DEMO_DEBUG=true\nTYPE_CONFIGURATION_DEMO_RATIO=1.25\n");
$relativeWork = substr($work, strlen($root) + 1);
$nativeSettings = ['name' => 'configuration-native', 'project-root' => '../..', 'entry' => 'examples/configuration-command.php',
    'sources' => [$relativeWork . '/fixture/Generated.php'], 'native-inputs' => [$relativeWork . '/fixture/config/app.php'],
    'output' => $relativeWork . '/native/type-app', 'build-directory' => $relativeWork . '/compiler'];
file_put_contents($work . '/native.json', json_encode($nativeSettings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
$mode = $argv[1] ?? '--php';
if ($mode === '--prepare-native') {
    echo json_encode(['configuration' => $work . '/native.json', 'binary' => $work . '/native/type-app', 'dotenv' => $fixture . '/runtime.test',
        'generated' => $fixture . '/Generated.php'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}
$fixtureNames = ['TYPE_CONFIGURATION_DEMO_NAME', 'TYPE_CONFIGURATION_DEMO_PORT', 'TYPE_CONFIGURATION_DEMO_DEBUG', 'TYPE_CONFIGURATION_DEMO_RATIO'];
$previousFixtureEnvironment = [];
foreach ($fixtureNames as $fixtureName) {
    $previousFixtureEnvironment[$fixtureName] = getenv($fixtureName);
    putenv($fixtureName);
}
try {
    if ($mode === '--php') {
        $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; require ' . var_export($fixture . '/Generated.php', true)
            . '; require ' . var_export($root . '/examples/configuration-command.php', true) . '; main($argc,$argv);';
        $fixtureCommand = [PHP_BINARY, '-r', $launcher, $fixture . '/runtime.test'];
    } else {
        $fixtureCommand = [...nativeCommand($mode), $fixture . '/runtime.test'];
    }
    [$fixtureStatus, $fixtureOutput, $fixtureError] = execute($fixtureCommand);
    expect($fixtureStatus === 0 && $fixtureOutput === "运行时配置、不可变快照与点路径类型读取通过。\n" && $fixtureError === '', '配置应用入口没有通过或产生了额外诊断：' . $fixtureError);
} finally {
    foreach ($previousFixtureEnvironment as $restoreName => $restoreValue) {
        putenv($restoreValue === false ? $restoreName : $restoreName . '=' . $restoreValue);
    }
}
echo '配置环境、不可变点路径仓库、声明式配置编译与脱敏拒绝通过：' . $work . "\n";
