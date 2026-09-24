<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$source = $root . '/examples/operations/UserService.php';
$compiler = new Type\Build\OperationCompiler();
$result = $compiler->generate($root, ['classes' => ['TypeApp\\Operations\\UserOperations' => 'TypeApp\\Operations\\UserService']], [$source]);
expect(is_array($result['operations'] ?? null), '公开生成结果缺少操作声明');

/** 将非法操作声明写入本轮临时文件，验证生成器按约定拒绝，最后删除文件。 */
function rejectedOperation(string $declaration, string $message): void
{
    $file = tempnam(sys_get_temp_dir(), 'type_operation_invalid_');
    expect($file !== false, '无法创建非法声明样例');
    $phpFile = $file . '.php';
    rename($file, $phpFile);
    try {
        file_put_contents($phpFile, "<?php\ndeclare(strict_types=1);\nnamespace TypeApp\\InvalidOperation;\n" . $declaration);
        $failed = false;
        try {
            (new Type\Build\OperationCompiler())->generate(dirname(__DIR__), ['classes' => ['TypeApp\\InvalidOperation\\Generated' => 'TypeApp\\InvalidOperation\\Service']], [$phpFile]);
        } catch (RuntimeException) {
            $failed = true;
        }
        expect($failed, '非法操作声明未拒绝：' . $message);
    } finally {
        unlink($phpFile);
    }
}

$invalidCases = [
    ['final class Service { public static function run(): void {} }', '静态方法'],
    ['abstract class Service { abstract public function run(): void; }', '抽象类'],
    ['final class Service { public function run($value): void {} }', '未 typed 参数'],
    ['final class Service { public function run(int $value) {} }', '未 typed 返回'],
    ['final class Service { public function run(): never { throw new \\RuntimeException(); } }', 'never 返回'],
    ['final class Service { public function run(int &$value): void {} }', '引用参数'],
    ['final class Service { public function run(int ...$values): void {} }', 'variadic 参数'],
    ['final class Service { public function &run(): int {} }', '引用返回'],
    ['final class Service { #[\\Type\\Orm\\Attribute\\Transactional(database: "invalid name")] public function run(int $value): void {} }', '非法数据源名称'],
    ['final class Service { #[\\Type\\Orm\\Attribute\\Transactional(foo: "connection")] public function run(\\Type\\Orm\\Connection $connection): void {} }', '未知 Attribute 参数'],
    ['final class Service { #[\\Type\\Orm\\Attribute\\Transactional("default", database: "default")] public function run(\\Type\\Orm\\Connection $connection): void {} }', '重复 Attribute 参数'],
    ['final class Service { #[\\Type\\Orm\\Attribute\\Transactional] #[\\Type\\Orm\\Attribute\\Transactional] public function run(\\Type\\Orm\\Connection $connection): void {} }', '重复 Attribute'],
    ['final class Service { #[\\Type\\Cache\\Attribute\\Cacheable(cache: "cache", key: "x:{id}")] public function run(\\Type\\Cache\\TypedCache $cache, int $id, string $tenant): string { return ""; } }', '遗漏缓存标量参数'],
    ['final class Service { #[\\Type\\Cache\\Attribute\\Cacheable(cache: "cache", key: "x:{value}")] public function run(\\Type\\Cache\\TypedCache $cache, array $value): string { return ""; } }', '数组缓存键'],
    ['final class Service { #[\\Type\\Cache\\Attribute\\Cacheable(cache: "missing", key: "x")] public function run(): string { return ""; } }', '缺失缓存实例'],
    ['final class Service { #[\\Type\\Cache\\Attribute\\Cacheable(cache: "cache", key: "x", ttlMilliseconds: 0)] public function run(\\Type\\Cache\\TypedCache $cache): string { return ""; } }', '无限缓存 TTL'],
    ['final class Service { #[\\Type\\Cache\\Attribute\\Cacheable(cache: "cache", key: "x")] #[\\Type\\Orm\\Attribute\\Transactional] public function run(\\Type\\Cache\\TypedCache $cache, \\Type\\Orm\\Connection $connection): string { return ""; } }', '缓存与事务组合'],
    ['final class Service { #[\\Type\\Cache\\Attribute\\CacheEvict(cache: "cache", key: "x", all: true)] public function run(\\Type\\Cache\\TypedCache $cache): void {} }', '混合单键和全部失效'],
    ['final class Service { #[\\Type\\Orm\\Attribute\\Transactional] private function run(\\Type\\Orm\\Connection $connection): void {} }', '私有方法属性'],
    ['#[\\Type\\Orm\\Attribute\\Transactional] final class Service { public function run(): void {} }', '类属性误用'],
    ['final class Service { #[\\Type\\Orm\\Attribute\\Transactional] public function __construct(\\Type\\Orm\\Connection $connection) {} }', '构造器属性误用'],
    ['trait Shared { public function run(): void {} } final class Service { use Shared; }', 'Trait 方法不能被静默漏掉'],
    ['class ParentService { public function run(): void {} } final class Service extends ParentService {}', '继承方法不能被静默漏掉'],
    ['final class Service { #[\\Type\\Orm\\Attribute\\Transactional] public string $name; public function run(): void {} }', '字段上的方法属性'],
    ['final class Service { public function run(#[\\Type\\Cache\\Attribute\\CacheEvict(cache: "cache", all: true)] int $value): void {} }', '形参上的方法属性'],
    ['if (false) { final class Service { public function run(): void {} } }', '条件声明类'],
    ['final class Service { #[\\Type\\Cache\\Attribute\\Cacheable(cache: "cache", key: "x")] public function run(\\Type\\Cache\\TypedCache $cache, object $value): string { return ""; } }', '对象参数不能从缓存身份逃逸'],
    ['final class Service { #[\\Type\\Cache\\Attribute\\Cacheable(cache: "cache", key: "x:{cache}")] public function run(\\Type\\Cache\\TypedCache $cache): string { return ""; } }', '缓存连接不是标量key'],
    ['final class Service { #[\\Type\\Cache\\Attribute\\Cacheable(cache: "cache", key: "x")] public function run(\\Type\\Cache\\TypedCache $cache): void {} }', '缓存void'],
    ['final class Service { #[\\Type\\Cache\\Attribute\\Cacheable(cache: "cache", key: "x")] #[\\Type\\Cache\\Attribute\\CacheEvict(cache: "cache", all: true)] public function run(\\Type\\Cache\\TypedCache $cache): string { return ""; } }', '缓存与清理组合'],
    ['final class Service { #[\\Type\\Cache\\Attribute\\CacheEvict(cache: "cache")] public function run(\\Type\\Cache\\TypedCache $cache): void {} }', '失效缺少key'],
    ['final class Service { #[\\Type\\Cache\\Attribute\\Cacheable(cache: "cache", key: "x:{id")] public function run(\\Type\\Cache\\TypedCache $cache, int $id): string { return ""; } }', '未闭合key模板'],
];
foreach ($invalidCases as [$declaration, $message]) {
    rejectedOperation($declaration, $message);
}
$missingRejected = false;
try {
    $compiler->generate($root, ['classes' => ['TypeApp\\Generated\\Unknown' => 'TypeApp\\Missing\\Service']], [$source]);
} catch (RuntimeException) {
    $missingRejected = true;
}
expect($missingRejected, '未纳入生产源码的业务类被接受');
$collisionRejected = false;
try {
    $compiler->generate($root, ['classes' => ['TypeApp\\Operations\\UserService' => 'TypeApp\\Operations\\UserService']], [$source]);
} catch (RuntimeException) {
    $collisionRejected = true;
}
expect($collisionRejected, '生成类覆盖了实际生产类');
$unknownRejected = false;
try {
    $compiler->generate($root, ['classes' => [], 'autoload' => true], [$source]);
} catch (RuntimeException) {
    $unknownRejected = true;
}
expect($unknownRejected, '未知生成器配置被忽略');
expect($compiler->generate($root, ['classes' => []], [$source])['operations'] === [], '空显式映射意外生成了业务包装');
expect(
    $compiler->generate($root, ['classes' => ['TypeApp\\Operations\\UserOperations' => 'TypeApp\\Operations\\UserService']], [$source]) === $result,
    '相同声明的生成输出不确定'
);
$sourceCode = file_get_contents($source);
expect(is_string($sourceCode) && str_contains($result['code'], '中文说明和默认参数在公开组合对象上保持可见。'), '生成操作没有保留方法PHPDoc');

$sideEffect = tempnam(sys_get_temp_dir(), 'type_operation_side_effect_');
expect($sideEffect !== false, '无法准备业务源码不执行验证');
$sideEffectSource = $sideEffect . '.php';
try {
    file_put_contents($sideEffectSource, '<?php namespace TypeApp\\SideEffect; file_put_contents(' . var_export($sideEffect, true)
        . ', "executed"); final class Service { /** @Transactional */ public function run(): void {} }');
    $withoutImplicit = $compiler->generate($root, ['classes' => ['TypeApp\\SideEffect\\Generated' => 'TypeApp\\SideEffect\\Service']], [$sideEffectSource]);
    expect(file_get_contents($sideEffect) === '', '生成器执行了业务源码');
    expect($withoutImplicit['operations'][0]['transaction'] === null, '生成器隐式解释了DocBlock注解');
} finally {
    unlink($sideEffectSource);
    unlink($sideEffect);
}
$generated = tempnam(sys_get_temp_dir(), 'type_operations_');
expect($generated !== false, '无法创建显式操作生成文件');
try {
    file_put_contents($generated, $result['code']);
    if (isset($argv[1]) && $argv[1] !== '--php') {
        $command = nativeCommand($argv[1]);
    } else {
        $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; require ' . var_export($source, true)
            . '; require ' . var_export($root . '/examples/model/Drivers.php', true)
            . '; require ' . var_export($generated, true) . '; require ' . var_export($root . '/examples/operations/main.php', true) . '; main($argc, $argv);';
        $command = [PHP_BINARY, '-r', $launcher];
    }
    $mode = $argv[3] ?? 'normal';
    [$status, $stdout, $stderr] = execute([...$command, $argv[2] ?? 'sqlite', $mode]);
    $expected = $mode === 'unknown' ? "{\"outcome\":\"UNKNOWN\",\"cached\":\"before\",\"calls\":1}\n" : "显式生成操作的事务、缓存、回滚保护与最外层提交后失效通过。\n";
    expect($status === 0 && $stdout === $expected && $stderr === '', '显式生成操作验收失败：' . $stdout . $stderr);
    echo $stdout;
} finally {
    unlink($generated);
}
