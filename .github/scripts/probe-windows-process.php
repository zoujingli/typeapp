<?php

declare(strict_types=1);

// -n 对照必须先分派，避免 Composer 及额外扩展影响单变量实验。
if (in_array($argv[1] ?? '', ['--environment-only', '--environment-child'], true)) {
    ini_set('zend.exception_ignore_args', '1');
    /**
     * 仅诊断 TYPE_APP_NAME；不加载框架，不打印完整进程环境。
     *
     * @return array<string, mixed> 当前代码页与同一环境键的三种读取字节。
     */
    function environmentProbeSample(): array
    {
        $codepage = function_exists('sapi_windows_cp_get') ? [
            'current' => sapi_windows_cp_get(),
            'ansi' => sapi_windows_cp_get('ansi'),
            'oem' => sapi_windows_cp_get('oem'),
        ] : null;
        $normal = getenv('TYPE_APP_NAME');
        $local = getenv('TYPE_APP_NAME', true);
        $all = getenv();
        $enumerated = $all['TYPE_APP_NAME'] ?? false;
        unset($all);

        return [
            'codepage' => $codepage,
            'normal-hex' => $normal === false ? null : bin2hex($normal),
            'local-hex' => $local === false ? null : bin2hex($local),
            'enumerated-hex' => $enumerated === false ? null : bin2hex($enumerated),
        ];
    }

    /**
     * 与 tests/support.php 的 execute 相同：数组命令、临时文件捕获、null 环境继承。
     *
     * @param list<string> $command 直接执行的程序与参数。
     * @return array{int, string, string} 退出码、标准输出和标准错误原始字节。
     * @throws RuntimeException 临时捕获文件或子进程无法创建。
     */
    function environmentProbeExecute(array $command): array
    {
        $stdout = tmpfile();
        $stderr = tmpfile();
        if ($stdout === false || $stderr === false) {
            throw new RuntimeException('无法创建环境诊断输出文件。');
        }
        try {
            $process = proc_open($command, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => $stdout, 2 => $stderr], $pipes, null, null);
            if (!is_resource($process)) {
                throw new RuntimeException('无法启动环境诊断子进程。');
            }
            $status = proc_close($process);
            rewind($stdout);
            rewind($stderr);

            return [$status, stream_get_contents($stdout), stream_get_contents($stderr)];
        } finally {
            fclose($stdout);
            fclose($stderr);
        }
    }

    /**
     * 记录运行身份，不能仅凭 PHP 版本串推断实际扩展与配置。
     *
     * @return array<string, mixed> PHP、加载配置及扩展身份。
     */
    function environmentProbeMetadata(): array
    {
        $ini = php_ini_loaded_file();
        $dll = dirname(PHP_BINARY) . '/php8ts.dll';
        $extensions = get_loaded_extensions();
        sort($extensions);

        return [
            'platform' => PHP_OS_FAMILY,
            'php' => PHP_VERSION,
            'zts' => (bool) PHP_ZTS,
            'sapi' => PHP_SAPI,
            'php-binary' => PHP_BINARY,
            'php-binary-sha256' => hash_file('sha256', PHP_BINARY),
            'php8ts-sha256' => is_file($dll) ? hash_file('sha256', $dll) : null,
            'ini' => $ini,
            'ini-sha256' => is_string($ini) ? hash_file('sha256', $ini) : null,
            'scanned-ini' => php_ini_scanned_files(),
            'default-charset' => ini_get('default_charset'),
            'internal-encoding' => ini_get('internal_encoding'),
            'input-encoding' => ini_get('input_encoding'),
            'output-encoding' => ini_get('output_encoding'),
            'extensions' => $extensions,
            'swoole' => phpversion('swoole'),
            'swoole-thread-class' => class_exists('Swoole\\Thread', false),
            'probe-source-sha256' => hash_file('sha256', __FILE__),
        ];
    }

    $mode = $argv[1] === '--environment-child' ? 'child' : 'parent';
    $input = '运行配置';
    $expectedHex = 'e8bf90e8a18ce9858de7bdae';
    if (bin2hex($input) !== $expectedHex || $input !== hex2bin($expectedHex)) {
        throw new RuntimeException('环境诊断中文原文不是预期的 UTF-8 字节。');
    }

    if ($mode === 'child') {
        $before = environmentProbeSample();
        $snapshot = (string) getenv('TYPE_APP_NAME');
        $putenv = putenv('TYPE_APP_NAME=后来的环境值');
        $report = [
            'schema' => 1,
            'role' => 'child',
            'metadata' => environmentProbeMetadata(),
            'before' => $before,
            'mutation-return' => $putenv,
            'after-mutation' => environmentProbeSample(),
            'snapshot-hex' => bin2hex($snapshot . ':' . $snapshot . "\n"),
        ];
        echo json_encode($report, JSON_THROW_ON_ERROR), "\n";
        exit(0);
    }

    $label = $argv[2] ?? 'prepared-ini';
    $expectedSwoole = match ($label) {
        'prepared-ini', 'dependencies-swoole', 'prepared-ini-explicit-utf8' => true,
        'bare', 'dependencies' => false,
        default => null,
    };
    $childOptions = json_decode($argv[3] ?? '[]', true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($childOptions) || !array_is_list($childOptions)) {
        throw new RuntimeException('环境诊断的子进程 PHP 选项必须是 JSON 列表。');
    }
    foreach ($childOptions as $option) {
        if (!is_string($option) || str_contains($option, "\0")) {
            throw new RuntimeException('环境诊断的子进程 PHP 选项无效。');
        }
    }
    $previous = getenv('TYPE_APP_NAME');
    $report = [
        'schema' => 1,
        'role' => 'parent',
        'label' => $label,
        'metadata' => environmentProbeMetadata(),
        'child-options' => $childOptions,
        'literal-hex' => bin2hex($input),
        'expected-hex' => $expectedHex,
        'initial' => environmentProbeSample(),
    ];
    try {
        $report['unset-return'] = putenv('TYPE_APP_NAME');
        $report['after-unset'] = environmentProbeSample();
        $report['putenv-return'] = putenv('TYPE_APP_NAME=' . $input);
        $report['immediately-after-putenv'] = environmentProbeSample();
        [$status, $stdout, $stderr] = environmentProbeExecute([PHP_BINARY, ...$childOptions, __FILE__, '--environment-child']);
        $report['immediately-after-php-child'] = environmentProbeSample();
        $report['php-child'] = [
            'exit' => $status,
            'stdout-hex' => bin2hex($stdout),
            'stderr-hex' => bin2hex($stderr),
            'json' => json_decode($stdout, true),
        ];
        if (PHP_OS_FAMILY === 'Windows') {
            // .NET 从继承的 Windows 宽环境读取，只输出此键的 UTF-8 hex。
            $script = '$v=[Environment]::GetEnvironmentVariable("TYPE_APP_NAME"); if ($null -eq $v) {[Console]::Out.Write("absent")} else {[Console]::Out.Write(([BitConverter]::ToString([Text.Encoding]::UTF8.GetBytes($v))).Replace("-", "").ToLowerInvariant())}';
            $encoded = base64_encode(implode('', array_map(static fn (string $character): string => $character . "\0", str_split($script))));
            $powershell = (string) getenv('SystemRoot') . '/System32/WindowsPowerShell/v1.0/powershell.exe';
            [$status, $stdout, $stderr] = environmentProbeExecute([$powershell, '-NoLogo', '-NoProfile', '-NonInteractive', '-EncodedCommand', $encoded]);
            $report['wide-child'] = ['exit' => $status, 'utf8-hex' => $stdout, 'stderr-hex' => bin2hex($stderr)];
            $report['after-wide-child'] = environmentProbeSample();
        }
        $report['zero-return'] = putenv('TYPE_APP_NAME=0');
        $report['after-zero'] = environmentProbeSample();
        $report['delete-return'] = putenv('TYPE_APP_NAME');
        $report['after-delete'] = environmentProbeSample();
    } finally {
        $report['restore-return'] = putenv($previous === false ? 'TYPE_APP_NAME' : 'TYPE_APP_NAME=' . $previous);
        $report['after-restore'] = environmentProbeSample();
    }
    $childMetadata = $report['php-child']['json']['metadata'] ?? [];
    $report['preconditions'] = [
        'expected-swoole' => $expectedSwoole,
        'swoole-loaded-as-expected' => $expectedSwoole === null || extension_loaded('swoole') === $expectedSwoole,
        'swoole-thread-available' => $expectedSwoole !== true || $report['metadata']['swoole-thread-class'],
        'same-parent-child-runtime' => ($childMetadata['php-binary-sha256'] ?? null) === $report['metadata']['php-binary-sha256']
            && ($childMetadata['php8ts-sha256'] ?? null) === $report['metadata']['php8ts-sha256']
            && ($childMetadata['extensions'] ?? null) === $report['metadata']['extensions'],
    ];
    $report['passed'] = $report['preconditions']['swoole-loaded-as-expected']
        && $report['preconditions']['swoole-thread-available']
        && $report['preconditions']['same-parent-child-runtime']
        && $report['putenv-return']
        && $report['immediately-after-putenv']['normal-hex'] === $expectedHex
        && $report['immediately-after-putenv']['local-hex'] === $expectedHex
        && $report['immediately-after-php-child']['normal-hex'] === $expectedHex
        && $report['php-child']['exit'] === 0
        && ($report['php-child']['json']['before']['normal-hex'] ?? null) === $expectedHex
        && ($report['php-child']['json']['snapshot-hex'] ?? null) === bin2hex($input . ':' . $input . "\n")
        && $report['after-zero']['normal-hex'] === '30'
        && $report['after-delete']['normal-hex'] === null;
    if (isset($report['wide-child'])) {
        $report['passed'] = $report['passed'] && $report['wide-child']['exit'] === 0 && $report['wide-child']['utf8-hex'] === $expectedHex;
    }
    echo json_encode($report, JSON_THROW_ON_ERROR), "\n";
    exit($report['passed'] ? 0 : 1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Type\Build\BuildEnvironment;
use Type\Build\BuildPlatform;

if (PHP_OS_FAMILY !== 'Windows') {
    throw new RuntimeException('This probe requires Windows.');
}
$platform = new BuildPlatform();
$minimal = $platform->environment('', '');
$profile = $minimal;
foreach (['USERPROFILE', 'HOMEDRIVE', 'HOMEPATH', 'APPDATA', 'LOCALAPPDATA', 'ComSpec', 'SystemDrive', 'windir'] as $key) {
    $value = getenv($key);
    if (is_string($value) && $value !== '') {
        $profile[$key] = $value;
    }
}
$program = $minimal['SystemRoot'] . '/System32/WindowsPowerShell/v1.0/powershell.exe';
$script = "[Console]::Out.WriteLine('probe-ok'); [Console]::Out.Flush()";
$encoded = base64_encode(implode('', array_map(static fn (string $character): string => $character . "\0", str_split($script))));
$cases = [['minimal-command', $minimal, $program, ['-Command', $script]], ['profile-command', $profile, $program, ['-Command', $script]],
    ['minimal-encoded', $minimal, $program, ['-EncodedCommand', $encoded]],
    ['builtin-modules-writeoutput', $minimal, $program, ['-Command', "Write-Output 'probe-ok'"]],
    ['builtin-modules-acl', $minimal, $program, ['-Command', "Get-Acl -LiteralPath \$env:TEMP | Out-Null; [Console]::Out.WriteLine('acl-read-ok')"]]];
$modern = (string) getenv('ProgramFiles') . '/PowerShell/7/pwsh.exe';
if (is_file($modern)) {
    $cases[] = ['powershell7', $minimal, $modern, ['-EncodedCommand', $encoded]];
}
$failed = false;
foreach ($cases as [$name, $environment, $executable, $arguments]) {
    $started = microtime(true);
    try {
        $output = (new BuildEnvironment())->run([$executable, '-NoLogo', '-NoProfile', '-NonInteractive', ...$arguments], getcwd(), $environment, 35);
        echo json_encode(['probe' => $name, 'seconds' => microtime(true) - $started, 'output' => trim($output)], JSON_THROW_ON_ERROR) . "\n";
    } catch (RuntimeException $error) {
        $failed = true;
        echo json_encode(['probe' => $name, 'seconds' => microtime(true) - $started, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }
}
exit($failed ? 1 : 0);
