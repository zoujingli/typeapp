<?php

declare(strict_types=1);

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
    if (is_string($value) && $value !== '') { $profile[$key] = $value; }
}
$program = $minimal['SystemRoot'] . '/System32/WindowsPowerShell/v1.0/powershell.exe';
$script = "[Console]::Out.WriteLine('probe-ok'); [Console]::Out.Flush()";
$encoded = base64_encode(implode('', array_map(static fn (string $character): string => $character . "\0", str_split($script))));
$cases = [['minimal-command', $minimal, $program, ['-Command', $script]], ['profile-command', $profile, $program, ['-Command', $script]],
    ['minimal-encoded', $minimal, $program, ['-EncodedCommand', $encoded]],
    ['builtin-modules-writeoutput', $minimal, $program, ['-Command', "Write-Output 'probe-ok'"]],
    ['builtin-modules-acl', $minimal, $program, ['-Command', "Get-Acl -LiteralPath \$env:TEMP | Out-Null; [Console]::Out.WriteLine('acl-read-ok')"]]];
$modern = (string) getenv('ProgramFiles') . '/PowerShell/7/pwsh.exe';
if (is_file($modern)) { $cases[] = ['powershell7', $minimal, $modern, ['-EncodedCommand', $encoded]]; }
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
