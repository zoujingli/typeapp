<?php

declare(strict_types=1);

/** 验收控制器不参与生产构建；只观察公开 stage 清单、产物报告与容器边界。 */
function isolatedAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function isolatedJson(string $file): array
{
    $value = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    isolatedAssert(is_array($value), '验收记录必须是 JSON 对象：' . $file);
    return $value;
}

function isolatedWriteDenied(string $file): void
{
    $written = @file_put_contents($file, 'type-app-isolation-test-only', LOCK_EX);
    if ($written !== false) {
        unlink($file);
    }
    isolatedAssert($written === false, '隔离边界允许写入：' . dirname($file));
}

function isolatedConfiguration(string $file): array
{
    $settings = isolatedJson($file);
    $jobs = $settings['compiler']['jobs'] ?? 2;
    isolatedAssert(is_int($jobs) && $jobs >= 1 && $jobs <= 2, '隔离验收最多使用两个编译 job');
    isolatedAssert(($settings['output'] ?? null) === 'build/native/type-app' && ($settings['entry'] ?? null) === 'examples/native-command.php', '隔离验收需要既有原生命令配置');
    return ['compiler-jobs' => $jobs, 'output' => $settings['output']];
}

function isolatedSnapshot(string $directory, string $stageFile): array
{
    $directory = realpath($directory) ?: throw new RuntimeException('隔离输入目录不存在');
    $manifest = isolatedJson($directory . '/build-inputs.json');
    $stage = isolatedJson($stageFile);
    isolatedAssert(($manifest['protocol'] ?? null) === 1 && ($manifest['sdk-provided-separately'] ?? null) === true, '输入快照协议不符合约定');
    isolatedAssert(is_array($manifest['files'] ?? null) && count($manifest['files']) === ($stage['files'] ?? null), 'stage 返回文件数与输入清单不一致');
    foreach ($manifest['files'] as $relative => $digest) {
        $file = realpath($directory . '/' . $relative);
        isolatedAssert($file !== false && str_starts_with($file, $directory . '/') && is_file($file), '输入文件缺失或越出快照：' . $relative);
        isolatedAssert(is_string($digest) && hash_equals($digest, (string) hash_file('sha256', $file)), '输入摘要变化：' . $relative);
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    $links = 0;
    $inactiveLinks = 0;
    foreach ($iterator as $entry) {
        $relative = substr($entry->getPathname(), strlen($directory) + 1);
        foreach (explode('/', $relative) as $component) {
            isolatedAssert(!in_array($component, ['.git', '.ssh', 'auth.json', '.env', 'id_rsa', 'id_ed25519', 'credentials.json'], true)
                && !str_starts_with($component, '.env.'), '快照携带认证或运行配置文件：' . $relative);
        }
        if ($entry->isLink()) {
            $target = readlink($entry->getPathname());
            isolatedAssert(is_string($target) && !str_starts_with($target, '/'), '快照包含绝对软链接：' . $relative);
            $parts = explode('/', dirname($relative));
            foreach (explode('/', $target) as $part) {
                if ($part === '..') {
                    isolatedAssert($parts !== [], '快照软链接越出隔离输入：' . $relative);
                    array_pop($parts);
                } elseif ($part !== '.' && $part !== '') {
                    $parts[] = $part;
                }
            }
            $resolved = realpath($entry->getPathname());
            isolatedAssert($resolved === false || str_starts_with($resolved, $directory . '/'), '快照软链接超出隔离输入：' . $relative);
            // Composer 未参与本次生产/构建的 dev 包可保留内部悬空别名；不能因此复制多余源码。
            if ($resolved === false) {
                $inactiveLinks++;
            }
            $links++;
        } elseif ($entry->isFile() && $relative !== 'build-inputs.json' && !isset($manifest['files'][$relative])) {
            isolatedAssert(($manifest['audit-placeholders'][$relative] ?? null) === 'excluded-file' && $entry->getSize() === 0, '输入中出现未声明文件：' . $relative);
        }
    }
    return ['files' => count($manifest['files']), 'internal-links' => $links, 'inactive-internal-links' => $inactiveLinks,
        'manifest-sha256' => hash_file('sha256', $directory . '/build-inputs.json')];
}

function isolatedBoundary(string $originalRoot, int $expectedUid, string $execution): array
{
    isolatedAssert(PHP_OS_FAMILY === 'Linux', '边界检查必须运行在真实 Linux 容器');
    isolatedAssert($expectedUid >= 0 && posix_geteuid() === $expectedUid, '构建进程没有切回明确的调用用户');
    if ($execution === 'host') {
        isolatedAssert(is_readable('/etc/snmp/snmp.conf') && str_contains((string) file_get_contents('/etc/snmp/snmp.conf'), "\nmibs :\n")
            && is_dir('/var/lib/snmp/cert_indexes'), '无凭据的原生模块启动资源对构建用户不可见');
    }
    $state = (string) file_get_contents('/proc/self/status');
    foreach (['CapInh', 'CapPrm', 'CapEff', 'CapBnd', 'CapAmb'] as $capabilitySet) {
        isolatedAssert(preg_match('/^' . $capabilitySet . ':\s+0+$/m', $state) === 1, '隔离进程仍拥有 Linux capability 集合：' . $capabilitySet);
    }
    isolatedAssert(preg_match('/^NoNewPrivs:\s+1$/m', $state) === 1, '容器未启用 no-new-privileges');
    $interfaces = [];
    foreach (file('/proc/net/dev', FILE_IGNORE_NEW_LINES) as $interfaceLine) {
        if (preg_match('/^\s*([^\s:]+):/', $interfaceLine, $match) === 1 && $match[1] !== 'lo') {
            $interfaces[] = $match[1];
        }
    }
    isolatedAssert($interfaces === [], '断网容器仍存在非回环网络接口');
    $socket = @stream_socket_client('tcp://192.0.2.1:9', $errorNumber, $errorMessage, 0.2);
    if (is_resource($socket)) {
        fclose($socket);
    }
    isolatedAssert($socket === false, '断网容器能够连接外部地址');
    foreach (['COMPOSER_AUTH', 'SSH_AUTH_SOCK', 'GITHUB_TOKEN', 'GH_TOKEN', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'TYPE_ISOLATION_SECRET_CANARY'] as $name) {
        isolatedAssert(getenv($name) === false, '构建环境继承了凭据或测试秘密变量：' . $name);
    }
    $workspaceEmpty = !is_dir('/workspace') || array_values(array_diff(scandir('/workspace'), ['.', '..'])) === [];
    isolatedAssert($workspaceEmpty && !file_exists('/root/.ssh') && !file_exists('/root/.composer/auth.json'), '构建环境可见原始仓库或认证目录');
    if ($originalRoot !== '') {
        foreach (['composer.json', 'composer.lock', 'plugin', 'vendor', 'tests', '.git', '.env'] as $source) {
            isolatedAssert(!file_exists($originalRoot . '/' . $source), '隔离构建可读取原始工作区内容：' . $source);
        }
    }
    foreach (file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES) as $mount) {
        $fields = explode(' ', $mount);
        isolatedAssert(($fields[4] ?? '') !== '/workspace' && ($fields[4] ?? '') !== $originalRoot, '隔离构建仍挂载原始仓库目录');
    }
    isolatedWriteDenied('/type-app-isolation-probe');
    isolatedWriteDenied('/input/type-app-isolation-probe');
    isolatedWriteDenied('/opt/phpx/type-app-isolation-probe');
    $phpHome = getenv('PHP_HOME');
    isolatedAssert(is_string($phpHome) && str_starts_with($phpHome, '/'), '缺少固定 PHP SDK');
    isolatedWriteDenied($phpHome . '/type-app-isolation-probe');
    $temporary = tempnam('/input/build', 'isolation-');
    isolatedAssert($temporary !== false && str_starts_with($temporary, '/input/build/'), '独立构建输出目录不可写');
    unlink($temporary);
    isolatedAssert((fileperms('/tmp') & 07777) === 01777, '隔离临时目录必须有 sticky bit 且对当前构建用户可写');
    $scratch = tempnam('/tmp', 'isolation-');
    isolatedAssert($scratch !== false && str_starts_with($scratch, '/tmp/'), '隔离临时目录不可写');
    unlink($scratch);
    return ['network' => 'none', 'root-filesystem' => 'readonly', 'inputs' => 'readonly', 'sdk' => 'readonly',
        'capabilities' => [], 'effective-uid' => posix_geteuid(), 'no-new-privileges' => true, 'credentials' => 'absent', 'original-workspace' => 'not-mounted', 'build-output' => 'writable'];
}

function isolatedReport(string $directory, string $image): array
{
    $inputs = $directory . '/inputs';
    $snapshot = isolatedSnapshot($inputs, $directory . '/stage.json');
    isolatedAssert($snapshot === isolatedJson($directory . '/snapshot-before.json'), '编译后只读输入与编译前不一致');
    $configuration = isolatedJson($inputs . '/docs/build-config/type-app.json');
    $relativeOutput = $configuration['output'];
    isolatedAssert(is_string($relativeOutput) && str_starts_with($relativeOutput, 'build/'), '验收输出必须位于独立 build 目录');
    $artifact = $directory . '/output/' . substr($relativeOutput, 6);
    $build = isolatedJson($artifact . '.build.json');
    isolatedAssert(file_get_contents($artifact, false, null, 0, 4) === "\x7fELF" && is_executable($artifact), '隔离构建没有生成可执行 ELF');
    isolatedAssert(($build['cache']['hit'] ?? null) === false, '首次隔离验收必须实际编译，不能用已有产物缓存替代');
    isolatedAssert(($build['sha256'] ?? null) === hash_file('sha256', $artifact), '构建报告与实际 ELF 摘要不一致');
    $composer = isolatedJson($inputs . '/composer.json');
    $installed = isolatedJson($inputs . '/vendor/composer/installed.json');
    $packages = [];
    foreach ($installed['packages'] as $package) {
        $packages[$package['name']] = $package;
    }
    $queue = array_keys($composer['require']);
    $expected = [];
    while ($queue !== []) {
        $name = array_shift($queue);
        if (!str_contains($name, '/') || isset($expected[$name])) {
            continue;
        }
        isolatedAssert(isset($packages[$name]), '隔离输入遗漏已锁定生产包：' . $name);
        $expected[$name] = $packages[$name]['version'];
        array_push($queue, ...array_keys($packages[$name]['require'] ?? []));
    }
    $actual = $build['production-packages'];
    ksort($expected);
    ksort($actual);
    isolatedAssert($expected !== [] && $actual === $expected, '实际编译产物没有覆盖完整生产依赖闭包');
    isolatedAssert(trim((string) file_get_contents($directory . '/native.log')) === '原生命令验证通过，共 9 个输出和错误行为用例。', '九项原生命令验证未完成或出现额外错误输出');
    $sourceCount = count($build['identity']['description']['inputs']['sources']);
    return ['protocol' => 1, 'status' => 'passed', 'scope' => '公开 stage → 断网只读全量 AOT → 既有九项原生命令',
        'execution' => str_starts_with($image, 'sha256:') ? 'container' : 'host-bubblewrap',
        'toolchain-image' => str_starts_with($image, 'sha256:') ? $image : null, 'isolation-tool' => $image,
        'boundary' => isolatedJson($directory . '/boundary.json'), 'snapshot' => $snapshot,
        'production-packages' => $actual, 'source-files' => $sourceCount, 'build-id' => $build['build-id'],
        'artifact-sha256' => $build['sha256'], 'cache-hit' => false, 'native-cases' => 9,
        'native-libraries' => $build['native-libraries'], 'compile-log-sha256' => hash_file('sha256', $directory . '/compile.log')];
}

try {
    $mode = $argv[1] ?? '';
    $result = match ($mode) {
        'configuration' => isolatedConfiguration($argv[2] ?? ''),
        'snapshot' => isolatedSnapshot($argv[2] ?? '', $argv[3] ?? ''),
        'boundary' => isolatedBoundary($argv[2] ?? '', isset($argv[3]) && ctype_digit($argv[3]) ? (int) $argv[3] : -1, $argv[4] ?? ''),
        'report' => isolatedReport($argv[2] ?? '', $argv[3] ?? ''),
        default => throw new RuntimeException('用法：isolated-build.php configuration <配置> | snapshot <目录> <stage记录> | boundary | report <验收目录> <镜像摘要>'),
    };
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, '隔离构建验收失败：' . $error->getMessage() . "\n");
    exit(1);
}
