<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/examples/model/Drivers.php';

/** 保留基础扩展和加载顺序，只移除未选 PDO 驱动；Swoole 是 ORM 运行时硬依赖。 */
function ormRuntimeIni(string $source, string $driver): string
{
    $result = '';
    foreach (preg_split('/\r\n|\r|\n/', $source) as $line) {
        if (preg_match('/^\s*extension\s*=/i', $line)) {
            $directive = parse_ini_string($line, false, INI_SCANNER_NORMAL);
            expect(is_array($directive), '无法解析运行期扩展加载声明');
            $module = strtolower(basename(str_replace('\\', '/', (string) array_values($directive)[0])));
            $module = preg_replace('/^(?:php_)?(.+?)(?:\.so|\.dll)?$/D', '$1', $module);
            if (str_starts_with($module, 'pdo_') && $module !== 'pdo_' . $driver) {
                continue;
            }
        }
        $result .= $line . "\n";
    }
    return $result;
}

function ormWriteRuntimeIni(string $directory, string|false $mainIni, array $scanFiles, string $driver): void
{
    if (!is_dir($directory . '/php.d')) {
        expect(mkdir($directory . '/php.d', 0700, true), '无法创建独立运行配置目录');
    }
    if ($mainIni !== false) {
        expect(is_file($mainIni), '运行期主 PHP 配置不存在');
    }
    $contents = ormRuntimeIni($mainIni === false ? '' : file_get_contents($mainIni), $driver);
    expect(file_put_contents($directory . '/php.ini', $contents) === strlen($contents), '无法保存主运行配置');
    foreach ($scanFiles as $position => $sourceIni) {
        expect(is_file($sourceIni), '已加载的 PHP 配置文件不存在');
        $scanContents = ormRuntimeIni(file_get_contents($sourceIni), $driver);
        expect(file_put_contents($directory . '/php.d/' . sprintf('%04d', $position) . '.ini', $scanContents) === strlen($scanContents), '无法保存扩展运行配置');
    }
    $limits = "date.timezone=UTC\nmemory_limit=256M\nswoole.enable_library=On\n";
    expect(file_put_contents($directory . '/php.d/zz-runtime.ini', $limits) === strlen($limits), '无法保存运行期限制');
}

/** 业务与探针必须同时通过退出码和错误输出，不吞掉扩展重复加载等启动警告。 */
function ormSuccessful(array $command, string $directory): string
{
    $process = new Type\Testing\Process($command, $directory);
    try {
        $result = $process->wait(120);
        expect($result->successful() && $result->stderr === '', '独立 ORM 运行失败或存在错误输出（exit='
            . $result->exitCode . ', signal=' . ($result->signal ?? 'none') . ', timeout='
            . (int) $result->timedOut . ', output_exceeded=' . (int) $result->outputExceeded . '）：' . $result->stdout . $result->stderr);
        return $result->stdout;
    } finally {
        $process->stop();
    }
}

/** 读取所选实际embed探针协议；不拿PHP CLI模块清单代替原生模块。 */
function ormNativeExtensions(string $probe, string $ini, string $scan, string $directory): array
{
    if (!in_array(basename($probe), ['embed-probe', 'embed-probe.exe'], true)) {
        return explode("\n", trim(ormSuccessful(['env', 'PHPRC=' . $ini, 'PHP_INI_SCAN_DIR=' . $scan, $probe, '--extensions'], $directory)));
    }
    $result = json_decode(ormSuccessful([$probe, $ini, $scan], $directory), true, 32, JSON_THROW_ON_ERROR);
    expect(($result['protocol'] ?? null) === 1 && ($result['sapi'] ?? null) === 'embed'
        && ($result['php'] ?? null) === PHP_VERSION && ($result['zts'] ?? null) === (bool) PHP_ZTS
        && is_array($result['extensions'] ?? null), '原生运行配置探针协议或ABI不符');
    $libraries = array_map('realpath', (new Type\Build\BuildPlatform())->runtimeLibraries(getenv('PHP_HOME'), getenv('PHPX_HOME')));
    expect(in_array(realpath($result['core-library'] ?? ''), $libraries, true), '探针没有使用选定SDK的实际核心库');
    return array_keys($result['extensions']);
}

function ormAssertExtensions(array $build, array $runtime, array $static, string $driver): void
{
    expect(in_array('swoole', array_map('strtolower', $runtime), true)
        || in_array('swoole', array_map('strtolower', $static), true), '独立 ORM 运行环境缺少 Swoole');
    foreach ($build as $extension) {
        $normalized = strtolower($extension);
        $pdoDriver = str_starts_with($normalized, 'pdo_');
        if (!$pdoDriver || $normalized === 'pdo_' . $driver || in_array($extension, $static, true)) {
            expect(in_array($extension, $runtime, true), '运行配置丢失所需扩展：' . $extension);
        } else {
            expect(!in_array($extension, $runtime, true), '运行配置仍加载未选择的动态驱动：' . $extension);
        }
    }
}

/** @return array{loaded: bool, version: string, loading: string} */
function ormSwooleReport(array $runtime, array $static): array
{
    $runtimeNames = array_map('strtolower', $runtime);
    $staticNames = array_map('strtolower', $static);
    $version = phpversion('swoole');
    expect(extension_loaded('swoole') && is_string($version) && version_compare($version, '6.2', '>=')
        && version_compare($version, '7.0', '<'), 'Swoole 版本必须满足 >=6.2 <7');
    return ['loaded' => true, 'version' => $version,
        'loading' => in_array('swoole', $staticNames, true) ? 'static' : (in_array('swoole', $runtimeNames, true) ? 'dynamic' : 'missing')];
}

/** 原生运行前保全并移除本消费环境的 PHP 输入，逐文件验证压缩包可恢复。 */
function ormRemoveSources(string $consumer): array
{
    $archive = $consumer . '/source-inputs.tar';
    $snapshot = new PharData($archive);
    $hashes = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($consumer, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && !$file->isLink() && strtolower($file->getExtension()) === 'php') {
            $relative = Type\Build\BuildPlatform::path(substr($file->getPathname(), strlen($consumer) + 1));
            $hashes[$relative] = hash_file('sha256', $file->getPathname());
            $snapshot->addFile($file->getPathname(), $relative);
        }
    }
    expect($hashes !== [], '消费环境没有可保全的 PHP 输入');
    $snapshot->compress(Phar::GZ);
    unset($snapshot);
    $verified = new PharData($archive . '.gz');
    foreach ($hashes as $relative => $hash) {
        expect(isset($verified[$relative]) && hash('sha256', $verified[$relative]->getContent()) === $hash, '原始源码归档回读不符：' . $relative);
    }
    unset($verified);
    unlink($archive);
    foreach (['app', 'vendor', 'build/compiler'] as $directory) {
        removeTestDirectory($consumer . '/' . $directory);
    }
    foreach ($hashes as $relative => $hash) {
        $file = $consumer . '/' . $relative;
        if (is_file($file)) {
            expect(unlink($file), '无法移除已归档源码：' . $relative);
        }
    }
    $record = ['archive' => 'source-inputs.tar.gz', 'sha256' => hash_file('sha256', $archive . '.gz'), 'sources' => $hashes];
    file_put_contents($consumer . '/source-inputs.json', json_encode($record, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return ['archive_sha256' => $record['sha256'], 'removed_php_files' => count($hashes)];
}

$comment = '; TypePHP 原生进程的共享信号模块';
expect(ormRuntimeIni($comment . "\r\nextension=pdo_mysql\nextension=pdo_pgsql\nextension=swoole\n", 'mysql')
    === $comment . "\nextension=pdo_mysql\nextension=swoole\n\n", '中文注释或非选定驱动配置过滤错误');

$root = Type\Build\BuildPlatform::resolve(dirname(__DIR__));
$sourceIdentity = [
    'commit' => trim(successful(['git', 'rev-parse', 'HEAD'], $root)),
    'dirty' => trim(successful(['git', 'status', '--porcelain=v1', '--untracked-files=normal'], $root)) !== '',
    'toolchain_lock_sha256' => hash_file('sha256', $root . '/toolchain.lock.json'),
];
$driver = $argv[1] ?? 'sqlite';
$mode = $argv[2] ?? '--php';
expect(in_array($mode, ['--php', '--native'], true), '验收模式必须显式为 --php 或 --native');
$native = $mode === '--native';
expect(in_array($driver, ['mysql', 'pgsql', 'sqlite'], true), '未知业务矩阵驱动');
$consumer = $root . '/build/orm-suite-' . $driver . '-' . bin2hex(random_bytes(5));
expect(mkdir($consumer . '/app', 0755, true) && mkdir($consumer . '/php.d'), '无法创建独立业务消费项目');
$artifact = (new Type\Build\BuildPlatform())->output($consumer . '/build/type-app');
$repositories = [];
foreach (['type-runtime', 'type-orm', 'type-orm-' . $driver, 'type-build'] as $package) {
    $repositories[] = ['type' => 'path', 'url' => '../../plugin/' . $package,
        'options' => ['symlink' => false, 'versions' => ['zoujingli/' . $package => '1.0.x-dev']]];
}
$forbidden = [];
foreach (['mysql', 'pgsql', 'sqlite'] as $candidate) {
    if ($candidate !== $driver) {
        $forbidden['ext-pdo_' . $candidate] = false;
    }
}
$composer = ['name' => 'type-tests/orm-suite-' . $driver, 'type' => 'project', 'license' => 'Apache-2.0',
    'require' => ['zoujingli/type-orm-' . $driver => '~1.0.0@dev'],
    'require-dev' => ['zoujingli/type-build' => '~1.0.0@dev', 'swoole/typephp' => '0.9.3', 'swoole/phpx' => '2.9.2'],
    'repositories' => $repositories, 'minimum-stability' => 'dev', 'prefer-stable' => true,
    'config' => ['allow-plugins' => false, 'platform' => $forbidden]];
file_put_contents($consumer . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
foreach (['main.php', 'Suite.php', 'CoreExercise.php', 'MutationExercise.php', 'Schema.php', 'ArticleObserver.php', 'Models.php'] as $file) {
    expect(copy($root . '/examples/orm-suite/' . $file, $consumer . '/app/' . $file), '无法复制独立业务文件');
}
expect(copy($root . '/examples/orm-suite/drivers/' . $driver . '.php', $consumer . '/app/DriverFactory.php'), '无法复制所选驱动工厂');
expect(copy($root . '/examples/orm-suite/application.json', $consumer . '/application.json')
    && copy($root . '/toolchain.lock.json', $consumer . '/toolchain.lock.json'), '无法复制编译与工具链配置');
$swooleModule = getenv('TYPE_SWOOLE_MODULE');
if ($native && is_string($swooleModule) && $swooleModule !== '') {
    $swooleModule = Type\Build\BuildPlatform::resolve($swooleModule);
    expect(is_file($swooleModule) && mkdir($consumer . '/modules', 0700), '无法准备指定的 Swoole 模块');
    $moduleName = PHP_OS_FAMILY === 'Windows' ? 'php_swoole.dll' : 'swoole.so';
    expect(copy($swooleModule, $consumer . '/modules/' . $moduleName), '无法保全指定的 Swoole 模块');
    $configuration = json_decode(file_get_contents($consumer . '/application.json'), true, 512, JSON_THROW_ON_ERROR);
    $configuration['runtime'][PHP_OS_FAMILY]['modules']['swoole'] = [
        'file' => 'modules/' . $moduleName, 'sha256' => hash_file('sha256', $swooleModule),
    ];
    file_put_contents($consumer . '/application.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

// 安装与编译沿用完整构建环境；运行期才切换配置，保留 PDO 基础和标准扩展。
$staticExtensions = json_decode(successful([PHP_BINARY, '-n', '-r', 'echo json_encode(get_loaded_extensions());']), true, 32, JSON_THROW_ON_ERROR);
$buildExtensions = get_loaded_extensions();
$nativeIni = getenv('TYPE_NATIVE_PHP_INI');
$scanned = php_ini_scanned_files();
$scanFiles = $scanned === false || trim($scanned) === '' ? [] : preg_split('/,\s*/', trim($scanned));
expect(is_array($scanFiles), '无法读取运行期 PHP 扫描目录');
ormWriteRuntimeIni($consumer, php_ini_loaded_file(), $scanFiles, $driver);
$nativeRuntimeExtensions = [];
$nativeStaticExtensions = [];
$nativeBuildReport = null;
if ($native) {
    expect($nativeIni !== false && is_file($nativeIni), '原生独立消费者需要先准备实际 embed 运行配置');
    $embedProbe = (new Type\Build\BuildPlatform())->output(dirname($nativeIni) . '/embed-probe');
    if (!is_executable($embedProbe)) {
        $embedProbe = (new Type\Build\BuildPlatform())->output(dirname($nativeIni) . '/probe');
    }
    expect(is_executable($embedProbe), '实际 embed 模块探针不存在');
    $nativeScanFiles = glob(dirname($nativeIni) . '/php.d/*.ini');
    expect(is_array($nativeScanFiles), '无法读取原生扩展扫描目录');
    $nativeDirectory = $consumer . '/native-runtime';
    ormWriteRuntimeIni($nativeDirectory, $nativeIni, $nativeScanFiles, $driver);
    $emptyDirectory = $consumer . '/static-runtime';
    ormWriteRuntimeIni($emptyDirectory, false, [], $driver);
    $nativeStaticExtensions = ormNativeExtensions($embedProbe, $emptyDirectory . '/php.ini', $emptyDirectory . '/php.d', $consumer);
    $nativeBuildExtensions = ormNativeExtensions($embedProbe, $nativeIni, dirname($nativeIni) . '/php.d', $consumer);
    $nativeRuntimeExtensions = ormNativeExtensions($embedProbe, $nativeDirectory . '/php.ini', $nativeDirectory . '/php.d', $consumer);
    ormAssertExtensions($nativeBuildExtensions, $nativeRuntimeExtensions, $nativeStaticExtensions, $driver);
}
$previousScan = getenv('PHP_INI_SCAN_DIR');
$previousIni = getenv('PHPRC');
$previousNativeIni = $nativeIni;
$variable = $driver === 'sqlite' ? 'TYPE_SQLITE_FILE' : 'TYPE_' . strtoupper($driver) . '_DATABASE';
$previousDatabase = getenv($variable);
$previousBarrier = getenv('TYPE_SUITE_BARRIER');
$admin = null;
$databaseCreated = false;
$databaseName = 'type_suite_test_' . bin2hex(random_bytes(6));
$children = [];
try {
    if ($driver === 'sqlite') {
        putenv($variable . '=' . $consumer . '/business.sqlite');
    } else {
        $admin = TypeApp\ModelExample\Drivers::create($driver)->connect();
        $admin->exec('CREATE DATABASE ' . $databaseName);
        $databaseCreated = true;
        putenv($variable . '=' . $databaseName);
    }
    $composerCommand = [getenv('COMPOSER_BINARY') ?: 'composer'];
    $composerPhar = getenv('TYPE_COMPOSER_PHAR');
    if (is_string($composerPhar) && $composerPhar !== '') {
        expect(is_file($composerPhar), '显式 Composer PHAR 不存在');
        $composerCommand = [PHP_BINARY, Type\Build\BuildPlatform::resolve($composerPhar)];
    }
    successful([...$composerCommand, 'install', '--no-interaction', '--no-scripts', '--no-plugins', '--prefer-dist', '--no-progress'], $consumer);
    $lock = json_decode(file_get_contents($consumer . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
    $packages = array_column($lock['packages'], 'name');
    sort($packages);
    expect($packages === ['zoujingli/type-orm', 'zoujingli/type-orm-' . $driver, 'zoujingli/type-runtime'], '生产依赖混入未选驱动、HTTP、Redis 或其他包');
    foreach ($packages as $package) {
        expect(!is_link($consumer . '/vendor/' . $package), '独立消费者不能链接回开发主仓源码');
    }
    $generated = $consumer . '/models-development.php';
    $generate = 'require ' . var_export($consumer . '/vendor/autoload.php', true) . '; $compiler = new Type\\Build\\ModelCompiler(); file_put_contents('
        . var_export($generated, true) . ', $compiler->compile([' . var_export($consumer . '/app/Models.php', true) . '])["code"]);';
    successful([PHP_BINARY, '-r', $generate], $consumer);
    if ($native) {
        [$buildStatus, $buildOutput, $buildError] = execute([PHP_BINARY, $consumer . '/vendor/bin/type', $consumer . '/application.json'], $consumer);
        file_put_contents($consumer . '/build.log', $buildOutput . $buildError);
        expect($buildStatus === 0, '独立 ORM 编译失败，完整输出见 ' . $consumer . '/build.log');
        $report = json_decode(file_get_contents($artifact . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
        $nativeBuildReport = $report;
        $actual = array_keys($report['production-packages']);
        sort($actual);
        expect($actual === $packages, '编译产物混入其他生产驱动');
        foreach ($report['sources'] as $source) {
            expect(Type\Build\BuildPlatform::contains($consumer, $source), '原生产物仍然编译主仓文件');
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $platformEnvironment = (new Type\Build\BuildPlatform())->environment(getenv('PHP_HOME'), getenv('PHPX_HOME'));
            $importTable = (new Type\Build\BuildEnvironment())->run(['dumpbin.exe', '/DEPENDENTS', $artifact], $consumer, $platformEnvironment, 60);
            $imports = (new Type\Build\WindowsImports())->parse($importTable);
            $recordedLibraries = array_map('strtolower', array_column($report['manifest']['native-libraries'], 'name'));
            foreach (array_merge($imports['required'], $imports['delayed']) as $libraryName) {
                // API-set由受限系统加载器映射为宿主DLL；其他导入必须直接进入清单。
                if (preg_match('/^(?:api|ext)-ms-[A-Za-z0-9_.-]+\.dll$/i', $libraryName) !== 1) {
                    expect(in_array(strtolower($libraryName), $recordedLibraries, true), '原生产物导入未进入运行库清单：' . $libraryName);
                }
            }
        }
        $release = (new Type\Build\NativePackage())->create($artifact, $consumer . '/release');
        (new Type\Build\NativePackage())->verify($release['directory'], $release['manifest-sha256']);
    }
    // 运行时移除全部构建工具，确保业务仅消费三个生产包。
    successful([...$composerCommand, 'install', '--no-dev', '--no-interaction', '--no-scripts', '--no-plugins', '--no-progress'], $consumer);
    putenv('PHP_INI_SCAN_DIR=' . $consumer . '/php.d');
    putenv('PHPRC=' . $consumer . '/php.ini');
    if ($native) {
        putenv('TYPE_NATIVE_PHP_INI=' . $consumer . '/native-runtime/php.ini');
    }
    $runtimeExtensions = json_decode(ormSuccessful([PHP_BINARY, '-r', 'echo json_encode(get_loaded_extensions());'], $consumer), true, 32, JSON_THROW_ON_ERROR);
    ormAssertExtensions($buildExtensions, $runtimeExtensions, $staticExtensions, $driver);
    $swoole = ormSwooleReport($runtimeExtensions, $staticExtensions);
    // 此命令忽略 Composer platform 覆盖，检查真正加载的运行期扩展；不能用忽略参数伪装通过。
    successful([...$composerCommand, 'check-platform-reqs', '--no-dev'], $consumer);
    $installed = json_decode(file_get_contents($consumer . '/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
    $actual = array_column($installed['packages'], 'name');
    sort($actual);
    expect($actual === $packages, '运行环境仍含构建工具依赖');
    $launcher = 'require ' . var_export($consumer . '/vendor/autoload.php', true) . '; require ' . var_export($generated, true) . ';';
    foreach (['DriverFactory.php', 'Schema.php', 'ArticleObserver.php', 'CoreExercise.php', 'MutationExercise.php', 'Suite.php', 'main.php'] as $file) {
        $launcher .= ' require ' . var_export($consumer . '/app/' . $file, true) . ';';
    }
    $consumerPrefix = PHP_OS_FAMILY === 'Windows' ? strtolower($consumer . '/') : $consumer . '/';
    $launcher .= ' foreach (get_included_files() as $file) { $resolved = realpath($file);'
        . ' if ($resolved === false || !str_starts_with(PHP_OS_FAMILY === "Windows" ? strtolower(str_replace(chr(92), "/", $resolved)) : $resolved, '
        . var_export($consumerPrefix, true)
        . ')) { throw new RuntimeException("业务加载了消费环境之外的 PHP 文件"); } } main($argc, $argv);';
    $command = $native ? [$consumer . '/release/' . (PHP_OS_FAMILY === 'Windows' ? 'run.cmd' : 'run')] : [PHP_BINARY, '-r', $launcher];
    $sourceRemoval = $native ? ormRemoveSources($consumer) : [];
    $result = json_decode(ormSuccessful([...$command, 'run'], $consumer), true, 32, JSON_THROW_ON_ERROR);
    // 分阶段保存原始结果；后续并发失败不能抹去已完成场景的证据。
    file_put_contents($consumer . '/behavior.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    expect($result['driver'] === $driver && in_array($driver, $result['pdo_extensions'], true), '业务运行了错误驱动');
    $businessStaticExtensions = $native ? $nativeStaticExtensions : $staticExtensions;
    $businessRuntimeExtensions = $native ? $nativeRuntimeExtensions : $runtimeExtensions;
    foreach (['mysql', 'pgsql', 'sqlite'] as $candidate) {
        if ($candidate !== $driver && !in_array('pdo_' . $candidate, $businessStaticExtensions, true)) {
            expect(!in_array('pdo_' . $candidate, $businessRuntimeExtensions, true), '未选动态驱动扩展仍在加载');
            // 官方 Swoole 可在 MINIT 注册编入扩展的 PDO 驱动；它不是独立的 pdo_* 模块。
            expect(!in_array($candidate, $result['pdo_extensions'], true)
                || in_array($candidate, $result['swoole_pdo_drivers'], true), '存在来源未记录的 PDO 驱动');
        }
    }
    foreach (['models', 'relations', 'soft_delete', 'events', 'scopes', 'pagination', 'optimistic_lock', 'migrations', 'strong_read', 'core_queries', 'tenant_isolation', 'model_mutations'] as $behavior) {
        expect($result[$behavior] === true, '业务矩阵缺少验收项：' . $behavior);
    }
    expect($result['scope_checks'] === ['binding-restore', 'snapshot', 'child-transaction', 'connection-owner', 'closed-connection',
        'parent-cancel', 'parent-close', 'deadline', 'late-release', 'database-io-wait'], '独立消费者缺少上下文与租约专项结果');
    $sessionChecks = ['crud', 'query', 'execute', 'raw', 'raw-query', 'session-isolation', 'error-retirement'];
    if ($driver === 'pgsql') {
        $sessionChecks[] = 'reset-failure-retirement';
    }
    if ($driver !== 'sqlite') {
        $sessionChecks[] = 'disconnect-retirement';
    }
    $sessionChecks[] = 'credential-generation';
    expect($result['sessions']['checks'] === $sessionChecks, '独立消费者缺少物理会话故障与代次验收');
    $barrier = $consumer . '/race-barrier';
    file_put_contents($barrier, '');
    putenv('TYPE_SUITE_BARRIER=' . $barrier);
    for ($index = 0; $index < 2; $index++) {
        $output = tmpfile();
        expect($output !== false, '无法准备并发进程输出');
        $process = proc_open([...$command, 'race', (string) $result['race_id']], [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => $output, 2 => $output], $pipes, $consumer);
        expect(is_resource($process), '无法启动真实并发文章更新');
        $children[] = [$process, $output];
    }
    $outcomes = [];
    $raceStatuses = [];
    foreach ($children as $index => [$process, $output]) {
        $status = proc_close($process);
        rewind($output);
        $text = stream_get_contents($output);
        file_put_contents($consumer . '/race-' . $index . '.log', $text);
        $raceStatuses[] = $status;
        $outcomes[] = trim($text);
    }
    file_put_contents($consumer . '/race.json', json_encode(['statuses' => $raceStatuses, 'outputs' => $outcomes], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    expect($raceStatuses === [0, 0], '并发文章更新失败，详见 ' . $consumer . '/race.json');
    sort($outcomes);
    expect($outcomes === ['conflict', 'updated'], '两个独立进程没有产生唯一成功与明确冲突，详见 ' . $consumer . '/race.json');
    expect(json_decode(ormSuccessful([...$command, 'verify', (string) $result['race_id']], $consumer), true, 8, JSON_THROW_ON_ERROR) === ['views' => 1, 'version' => 2], '并发落库版本错误');
    file_put_contents($barrier, '');
    $atomicChildren = [];
    for ($index = 0; $index < 2; $index++) {
        $output = tmpfile();
        expect($output !== false, '无法准备原子更新输出');
        $process = proc_open([...$command, 'increment', (string) $result['race_id']], [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => $output, 2 => $output], $pipes, $consumer);
        expect(is_resource($process), '无法启动原子更新进程');
        $atomicChildren[] = [$process, $output];
        $children[] = [$process, $output];
    }
    $atomicStatuses = [];
    $atomicOutcomes = [];
    foreach ($atomicChildren as $index => [$process, $output]) {
        $status = proc_close($process);
        rewind($output);
        $text = stream_get_contents($output);
        file_put_contents($consumer . '/increment-' . $index . '.log', $text);
        $atomicStatuses[] = $status;
        $atomicOutcomes[] = trim($text);
    }
    file_put_contents($consumer . '/increment.json', json_encode(['statuses' => $atomicStatuses, 'outputs' => $atomicOutcomes], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    expect($atomicStatuses === [0, 0] && $atomicOutcomes === ['incremented', 'incremented'], '并发原子更新失败，详见 ' . $consumer . '/increment.json');
    $result['atomic_increment'] = json_decode(ormSuccessful([...$command, 'verify-increment', (string) $result['race_id']], $consumer), true, 8, JSON_THROW_ON_ERROR);
    $result['mode'] = $native ? 'native' : 'php';
    if ($native) {
        $result['deployment'] = ['manifest_sha256' => $release['manifest-sha256'], 'business_source_files' => 0, 'source_removal' => $sourceRemoval];
    }
    $result['production_packages'] = $packages;
    $result['race'] = $outcomes;
    $result['disabled_platform_extensions'] = array_keys($forbidden);
    $result['runtime_extensions'] = $runtimeExtensions;
    $result['static_extensions'] = $staticExtensions;
    $result['native_runtime_extensions'] = $nativeRuntimeExtensions;
    $result['native_static_extensions'] = $nativeStaticExtensions;
    if ($native) {
        $nativeVersion = $nativeBuildReport['runtime-profile']['extensions']['swoole'] ?? null;
        expect(
            is_string($nativeVersion) && version_compare($nativeVersion, '6.2', '>=') && version_compare($nativeVersion, '7', '<'),
            '原生构建报告缺少有效 Swoole 版本'
        );
        $nativeStaticNames = array_map('strtolower', $nativeStaticExtensions);
        $nativeRuntimeNames = array_map('strtolower', $nativeRuntimeExtensions);
        $swoole = ['loaded' => in_array('swoole', $nativeStaticNames, true) || in_array('swoole', $nativeRuntimeNames, true),
            'version' => $nativeVersion,
            'loading' => in_array('swoole', $nativeStaticNames, true) ? 'static' : (in_array('swoole', $nativeRuntimeNames, true) ? 'dynamic' : 'missing')];
        expect($swoole['loaded'] === true, '原生运行环境缺少 Swoole');
    }
    $result['swoole'] = $swoole;
    $result['source'] = $sourceIdentity;
    file_put_contents($consumer . '/verification.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    echo $driver . ' 独立 Composer 用户、文章、标签业务与双进程并发通过；报告：' . $consumer . "/verification.json\n";
} finally {
    foreach ($children as [$process, $output]) {
        if (is_resource($process)) {
            proc_terminate($process, 15);
            proc_close($process);
        }
        if (is_resource($output)) {
            fclose($output);
        }
    }
    if ($databaseCreated) {
        $admin->exec('DROP DATABASE ' . $databaseName . ($driver === 'pgsql' ? ' WITH (FORCE)' : ''));
    }
    putenv($previousDatabase === false ? $variable : $variable . '=' . $previousDatabase);
    putenv($previousScan === false ? 'PHP_INI_SCAN_DIR' : 'PHP_INI_SCAN_DIR=' . $previousScan);
    putenv($previousIni === false ? 'PHPRC' : 'PHPRC=' . $previousIni);
    putenv($previousNativeIni === false ? 'TYPE_NATIVE_PHP_INI' : 'TYPE_NATIVE_PHP_INI=' . $previousNativeIni);
    putenv($previousBarrier === false ? 'TYPE_SUITE_BARRIER' : 'TYPE_SUITE_BARRIER=' . $previousBarrier);
    foreach ([$consumer . '/business.sqlite', $consumer . '/business.sqlite-wal', $consumer . '/business.sqlite-shm',
        $consumer . '/business.sqlite.type-migration.lock', $consumer . '/race-barrier'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}
