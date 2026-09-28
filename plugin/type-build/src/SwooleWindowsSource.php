<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 固定 Swoole Windows 构建适配；共享模块与静态核心共用相同的官方实现。 */
final class SwooleWindowsSource
{
    /**
     * 补齐上游构建清单、依赖发现、IOCP 名称限定和 Windows 套接字类型。
     * 上游修复对应缺口且 Windows 原生回归通过后撤除。
     *
     * @return array<string,array{before:string,after:string}>
     * @throws RuntimeException 原文摘要、替换次数或文件保存不符。
     */
    public function apply(string $directory): array
    {
        $hashes = [
            'config.w32' => '02a801b07d5bb38edea0f88465271454f54d4625d6e71e0c15db3935bef789ac',
            'src/coroutine/iocp.cc' => 'f77f1a5cf38153df491204b84e9a341803f2fbd551de617080c2a5a1f8c0d990',
            'src/network/dns.cc' => 'c52deca9b1f24f8921ce24669e276818d2c06072b7998f50758d55b0345e5835',
        ];
        $sources = [];
        foreach ($hashes as $file => $digest) {
            $path = $directory . '/' . $file;
            if (!is_file($path) || is_link($path) || hash_file('sha256', $path) !== $digest) {
                throw new RuntimeException('Swoole Windows 适配需要固定原文：' . $file);
            }
            $sources[$file] = (string) file_get_contents($path);
        }
        $replacements = [
            'CHECK_LIB("libpq.lib", "swoole", PHP_PGSQL_DIR)' => 'CHECK_LIB("libpq.lib;pq.lib", "swoole", null)',
            'CHECK_HEADER_ADD_INCLUDE("libpq-fe.h", "CFLAGS_SWOOLE", PHP_PGSQL_DIR)' => 'CHECK_HEADER_ADD_INCLUDE("libpq-fe.h", "CFLAGS_SWOOLE", PHP_PHP_BUILD + "\\\\include\\\\libpq;" + PHP_PHP_BUILD + "\\\\include\\\\postgresql")',
            'CHECK_LIB("sqlite3.lib", "swoole", null)' => 'CHECK_LIB("libsqlite3.lib;sqlite3.lib", "swoole", null)',
            'CHECK_LIB("libzstd.lib", "swoole", null)' => 'CHECK_LIB("libzstd_a.lib;libzstd.lib;zstd.lib", "swoole", null)',
            'CHECK_LIB("cares.lib", "swoole", PHP_CARES_DIR)' => 'CHECK_LIB("cares.lib", "swoole", null)',
            'CHECK_HEADER_ADD_INCLUDE("ares.h", "CFLAGS_SWOOLE", PHP_CARES_DIR)' => 'CHECK_HEADER_ADD_INCLUDE("ares.h", "CFLAGS_SWOOLE", null)',
            'swoole_source_files += PHP_THIRDPARTY_DIR + "\\\\pdo_pgsql\\\\pgsql_driver.c ";' => 'swoole_source_files += "ext-src\\\\swoole_pgsql.cc ";' . "\n\t\t" . 'swoole_source_files += PHP_THIRDPARTY_DIR + "\\\\pdo_pgsql\\\\pgsql_driver.c ";',
            'swoole_source_files += "thirdparty\\\\pdo_sqlite\\\\sqlite_driver.c ";' => 'swoole_source_files += "ext-src\\\\swoole_sqlite.cc ";' . "\n\t\t" . 'swoole_source_files += "thirdparty\\\\pdo_sqlite\\\\sqlite_driver.c ";',
        ];
        foreach ($replacements as $before => $after) {
            $sources['config.w32'] = $this->replace($sources['config.w32'], $before, $after);
        }
        $sources['src/coroutine/iocp.cc'] = $this->replace(
            $sources['src/coroutine/iocp.cc'],
            '#include "win32/ioutil.h"',
            '#include "Zend/zend_portability.h"' . "\n" . '#include "win32/ioutil.h"'
        );
        // poll 宏同时改名成员方法；必须调用 WinSock 全局函数，避免递归耗尽协程栈。
        $sources['src/coroutine/iocp.cc'] = $this->replace(
            $sources['src/coroutine/iocp.cc'],
            'int retval = WSAPoll(fds, nfds, 0);',
            'int retval = ::WSAPoll(fds, nfds, 0);'
        );
        // c-ares 的回调在 Windows 使用 UINT_PTR；Swoole 的 Socket 也使用 swSocketFd。
        // 回调和索引均保留官方 ares_socket_t，不能为通过编译而截断 64 位句柄。
        $sources['src/network/dns.cc'] = $this->replace(
            $sources['src/network/dns.cc'],
            'std::unordered_map<int, network::Socket *> sockets;',
            'std::unordered_map<ares_socket_t, network::Socket *> sockets;'
        );
        $sources['src/network/dns.cc'] = $this->replace(
            $sources['src/network/dns.cc'],
            'ctx.ares_opts.sock_state_cb = [](void *arg, int fd, int readable, int writable) {',
            'ctx.ares_opts.sock_state_cb = [](void *arg, ares_socket_t fd, int readable, int writable) {'
        );
        foreach ([
            '"[sock_state_cb], fd=%d, readable=%d, writable=%d", fd, readable, writable' => '"[sock_state_cb], fd=%llu, readable=%d, writable=%d", static_cast<unsigned long long>(fd), readable, writable',
            '"error events, fd=%d", fd' => '"error events, fd=%llu", static_cast<unsigned long long>(fd)',
            '"[del event], fd=%d", fd' => '"[del event], fd=%llu", static_cast<unsigned long long>(fd)',
            '"[set event] fd=%d, events=%d", fd, events' => '"[set event] fd=%llu, events=%d", static_cast<unsigned long long>(fd), events',
            '"[add event] fd=%d, events=%d", fd, events' => '"[add event] fd=%llu, events=%d", static_cast<unsigned long long>(fd), events',
        ] as $before => $after) {
            $sources['src/network/dns.cc'] = $this->replace($sources['src/network/dns.cc'], $before, $after);
        }
        $report = [];
        foreach ($sources as $file => $source) {
            if (file_put_contents($directory . '/' . $file, $source) !== strlen($source)) {
                throw new RuntimeException('无法保存 Swoole Windows 源码适配：' . $file);
            }
            $report[$file] = ['before' => $hashes[$file], 'after' => hash('sha256', $source)];
        }
        return $report;
    }

    private function replace(string $source, string $before, string $after): string
    {
        if (substr_count($source, $before) !== 1) {
            throw new RuntimeException('Swoole Windows 源码适配位置不唯一');
        }
        return str_replace($before, $after, $source);
    }
}
