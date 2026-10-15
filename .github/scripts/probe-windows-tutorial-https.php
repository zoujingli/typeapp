<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/tests/support.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/tests/windows-native-sandbox.php';

use Type\Testing\Process;

// 复用原产物，逐项记录 HTTPS 的已知测试响应；不编译、不降低 TLS 校验。
expect(PHP_OS_FAMILY === 'Windows' && getenv('GITHUB_ACTIONS') === 'true', '只允许受控 Windows 诊断');
$root = dirname(__DIR__, 2);
$original = $root . '/build/replay-tutorial/tutorial-fixed/sqlite/artifacts';
$program = $original . '/build/type-project.exe';
$digest = hash_file('sha256', $program);
expect($digest === getenv('TYPE_PROBE_PROGRAM_SHA256'), '原程序摘要不符');
$identity = json_decode(file_get_contents($original . '/preservation.json'), true, 512, JSON_THROW_ON_ERROR);
expect($identity['files']['build/type-project.exe']['sha256'] === $digest, '原保全身份不符');
$base = $root . '/build/windows-tutorial-https-diagnosis';
expect(mkdir($base, 0700, true), '无法创建诊断目录');
$work = $root . '/build/tutorial-https-' . bin2hex(random_bytes(6));
expect(mkdir($work . '/package', 0700, true) && mkdir($work . '/runtime/var', 0700, true), '无法创建运行目录');
expect(copy($program, $work . '/package/app.exe') && hash_file('sha256', $work . '/package/app.exe') === $digest, '原程序搬迁改变字节');
// 独立原生探针仅观察同一限制令牌下的文件与令牌访问，不替换应用。
$accessSource = <<<'CPP'
#define NOMINMAX
#include <windows.h>
#include <cstdio>
#include <vector>
int wmain(int argc, wchar_t **argv) {
    if (argc != 2) return 64;
    const DWORD attributes = GetFileAttributesW(argv[1]);
    std::printf("attributes=%lu error=%lu\n", attributes, attributes == INVALID_FILE_ATTRIBUTES ? GetLastError() : 0);
    HANDLE file = CreateFileW(argv[1], GENERIC_READ, FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE,
                              nullptr, OPEN_EXISTING, FILE_ATTRIBUTE_NORMAL, nullptr);
    std::printf("file_read=%d error=%lu\n", file != INVALID_HANDLE_VALUE, file == INVALID_HANDLE_VALUE ? GetLastError() : 0);
    if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
    for (DWORD rights : {static_cast<DWORD>(TOKEN_QUERY), static_cast<DWORD>(TOKEN_QUERY | TOKEN_DUPLICATE), static_cast<DWORD>(TOKEN_ALL_ACCESS)}) {
        HANDLE token = nullptr;
        BOOL opened = OpenProcessToken(GetCurrentProcess(), rights, &token);
        std::printf("token_rights=%lu opened=%d error=%lu\n", rights, opened, opened ? 0 : GetLastError());
        if (opened) {
            HANDLE impersonated = nullptr;
            BOOL duplicated = DuplicateToken(token, SecurityImpersonation, &impersonated);
            std::printf("token_duplicate=%d error=%lu\n", duplicated, duplicated ? 0 : GetLastError());
            if (duplicated) {
                DWORD length = 0;
                const SECURITY_INFORMATION information = OWNER_SECURITY_INFORMATION | GROUP_SECURITY_INFORMATION | DACL_SECURITY_INFORMATION;
                GetFileSecurityW(argv[1], information, nullptr, 0, &length);
                std::vector<BYTE> descriptor(length);
                BOOL security = GetFileSecurityW(argv[1], information, descriptor.data(), length, &length);
                std::printf("file_security=%d error=%lu\n", security, security ? 0 : GetLastError());
                if (security) {
                    for (DWORD desired : {static_cast<DWORD>(FILE_GENERIC_READ), static_cast<DWORD>(FILE_GENERIC_READ | FILE_FLAG_BACKUP_SEMANTICS)}) {
                        GENERIC_MAPPING mapping{FILE_GENERIC_READ, FILE_GENERIC_WRITE, FILE_GENERIC_EXECUTE, FILE_ALL_ACCESS};
                        PRIVILEGE_SET privileges{};
                        DWORD privilegeLength = sizeof(privileges), granted = 0;
                        BOOL allowed = FALSE;
                        BOOL checked = AccessCheck(descriptor.data(), impersonated, desired, &mapping, &privileges, &privilegeLength, &granted, &allowed);
                        std::printf("desired=%lu checked=%d allowed=%d granted=%lu error=%lu\n", desired, checked, allowed, granted, checked ? 0 : GetLastError());
                    }
                }
                CloseHandle(impersonated);
            }
            CloseHandle(token);
        }
    }
    return 0;
}
CPP;
file_put_contents($work . '/access.cpp', $accessSource);
$compiler = new Process(['cl.exe', '/nologo', '/MT', '/EHsc', '/std:c++17', '/utf-8', $work . '/access.cpp',
    '/Fo' . $work . '/access.obj', '/Fe' . $work . '/package/access.exe', '/link', 'advapi32.lib'], $work);
try {
    $compiled = $compiler->wait(60);
    expect($compiled->successful(), '权限探针编译失败：' . $compiled->stdout . $compiled->stderr);
} finally {
    $compiler->stop();
}
$environment = array_replace(getenv(), ['APP_BASE_PATH' => $work . '/runtime', 'APP_ENV' => 'prod',
    'DB_DRIVER' => 'sqlite', 'DB_SQLITE_FILE' => 'var/app.sqlite', 'APP_API_TOKEN' => str_repeat('tutorial-https-test-', 4),
    'TEMP' => $work . '/runtime', 'TMP' => $work . '/runtime']);
unset($environment['GH_TOKEN'], $environment['GITHUB_TOKEN']);
$report = ['source' => getenv('TYPE_PROBE_SOURCE'), 'run' => getenv('TYPE_PROBE_RUN'), 'sha256' => $digest,
    'scope' => 'same original executable; temporary HTTPS diagnosis', 'cases' => []];
$sandbox = null;
try {
    foreach (['direct', 'sandbox'] as $mode) {
        if ($mode === 'sandbox') {
            $sandbox = new WindowsNativeSandbox($root, $work . '/package', $work . '/runtime');
        }
        $directory = $work . '/runtime/var/' . $mode;
        expect(mkdir($directory, 0700), '无法创建 TLS 输入目录');
        $certificate = $directory . '/certificate.pem';
        $key = $directory . '/key.pem';
        $openssl = new Process([getenv('OPENSSL_BINARY'), 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
            '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost', '-keyout', $key, '-out', $certificate], $directory);
        try {
            expect($openssl->wait(15)->successful(), 'TLS 夹具证书创建失败');
        } finally {
            $openssl->stop();
        }
        $peer = new Process([getenv('NODE_BINARY'), $root . '/examples/catalog/https-peer.mjs', $certificate, $key], $directory, $environment);
        try {
            $deadline = microtime(true) + 5;
            do {
                $line = $peer->stdout();
                if (str_contains($line, "\n") || !$peer->running()) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $ready = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
            $variants = [$mode];
            foreach ($variants as $variant) {
                $command = $sandbox === null ? [$work . '/package/app.exe'] : $sandbox->command();
                $accessCommand = $sandbox === null ? [$work . '/package/access.exe', $certificate]
                    : [$command[0], $command[1], $work . '/package/access.exe', $certificate];
                $access = new Process($accessCommand, $directory, $environment);
                try {
                    $accessResult = $access->wait(15);
                    $report['native-access'][$variant] = ['exit' => $accessResult->exitCode,
                        'stdout' => $accessResult->stdout, 'stderr' => $accessResult->stderr];
                } finally {
                    $access->stop();
                }
                foreach (['https', 'https-host', 'https-timeout', 'https-cancel'] as $operation) {
                    $settings = array_replace($environment, ['CATALOG_HTTPS_URL' => 'https://127.0.0.1:' . $ready['port']
                        . (in_array($operation, ['https-timeout', 'https-cancel'], true) ? '/slow' : ''), 'CATALOG_HTTPS_CA' => $certificate]);
                    $process = new Process([...$command, 'catalog', $operation], $directory, $settings);
                    try {
                        $result = $process->wait(15);
                        $record = ['exit' => $result->exitCode, 'timed-out' => $result->timedOut,
                            'stdout' => $result->stdout, 'stderr' => $result->stderr];
                        $report['cases'][$variant][$operation] = $record;
                        echo $variant . '/' . $operation . ': ' . json_encode($record, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
                        expect($result->successful(), '原程序 HTTPS 命令未正常完成');
                        $lines = explode("\n", trim($result->stdout));
                        $reply = json_decode(array_pop($lines), true, 512, JSON_THROW_ON_ERROR);
                        $expected = match ($operation) {
                            'https' => ['status' => 200, 'body' => 'catalog-https-ok'],
                            'https-host' => ['error' => 'http_client_request_failed'],
                            'https-timeout' => ['error' => 'http_client_timeout'],
                            'https-cancel' => ['error' => 'cancelled'],
                        };
                        expect($reply === $expected, '原程序 HTTPS 结果不符：' . $variant . '/' . $operation);
                    } finally {
                        $process->stop();
                    }
                }
            }
        } finally {
            $peer->stop(5);
            unlink($key);
        }
    }
    $sandbox?->close();
    $sandbox = null;
    $testSource = file_get_contents($original . '/tests/catalog.php');
    expect(hash('sha256', $testSource) === $identity['files']['tests/catalog.php']['sha256'], '原教程断言摘要不符');
    file_put_contents($work . '/catalog.php', $testSource);
    // 原停服故障是间歇性访问冲突；使用原教程全部断言与五秒停止预算重复十轮。
    for ($attempt = 1; $attempt <= 10; ++$attempt) {
        $settings = array_replace($environment, ['TYPE_APP_BINARY' => $work . '/package/app.exe',
            'TYPE_APP_TEST_DIRECTORY' => $root, 'APP_LISTEN' => '127.0.0.1',
            'DB_SQLITE_FILE' => $work . '/runtime/var/stop-' . $attempt . '.sqlite']);
        $process = new Process([PHP_BINARY, $work . '/catalog.php'], $work, $settings);
        try {
            $result = $process->wait(60);
            file_put_contents($base . '/stop-' . $attempt . '.log', $result->stdout . $result->stderr);
            $record = ['exit' => $result->exitCode, 'timed-out' => $result->timedOut];
            foreach (explode("\n", $result->stdout) as $line) {
                if (str_starts_with($line, 'server-stop ')) {
                    $record['server'] = json_decode(substr($line, 12), true, 512, JSON_THROW_ON_ERROR);
                    unset($record['server']['stdout'], $record['server']['stderr']);
                }
            }
            $report['stop'][$attempt] = $record;
            echo 'stop-' . $attempt . ': ' . json_encode($record, JSON_THROW_ON_ERROR) . "\n";
            expect($result->successful() && ($record['server']['exit-code'] ?? null) === 0, '同一程序完整教程或停服失败');
        } finally {
            $process->stop();
        }
    }
} finally {
    file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    $sandbox?->close();
    removeTestDirectory($work);
}
