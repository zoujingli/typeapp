<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 固定 Swoole Windows 构建适配；共享模块与静态核心共用相同的官方实现。 */
final class SwooleWindowsSource
{
    /**
     * 补齐上游构建清单、依赖发现、IOCP 名称限定、地址初始化和独占绑定。
     * 上游修复对应缺口且 Windows 原生回归通过后撤除。
     *
     * @return array<string,array{before:string,after:string}>
     * @throws RuntimeException 原文摘要、替换次数或文件保存不符。
     */
    public function apply(string $directory): array
    {
        $hashes = [
            'config.w32' => 'a8c2ead0b6d0bee99011b57a18f25503dcf7f714be636f75e1886b077099619f',
            'src/coroutine/iocp.cc' => 'f77f1a5cf38153df491204b84e9a341803f2fbd551de617080c2a5a1f8c0d990',
            'src/network/dns.cc' => '1528f5e6e65569f0497695340c9e762527e83eb554acb3f9bd062d903fe530e1',
            'src/network/address.cc' => 'e0b57b87c89b3b3689e9ec2c5fe01dcda006655f4edc21100199ba056deda352',
            'src/coroutine/socket.cc' => 'ce7e1d08943d29262d77079c7d8b23977bd4f2d04d8f3d3bd3179b15ffb340fc',
            'php_swoole.h' => '66305cdd37bcaf35ee17e7d24ed12b6be4d18a4ad34d0a7dc883954344009e28',
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
        // PHP 的内置模块清单是 C 翻译单元；MSVC 会修饰未声明 C 链接的 C++ 全局变量。
        $sources['php_swoole.h'] = $this->replace(
            $sources['php_swoole.h'],
            'extern zend_module_entry swoole_module_entry;',
            "#ifdef __cplusplus\nextern \"C\" {\n#endif\nextern zend_module_entry swoole_module_entry;\n#ifdef __cplusplus\n}\n#endif"
        );
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
        // Address 是未初始化的聚合对象；IPv6 的 flowinfo/scope_id 不能把栈字节交给 ConnectEx。
        $sources['src/network/address.cc'] = $this->replace(
            $sources['src/network/address.cc'],
            "bool Address::assign(SocketType _type, const std::string &_host, int _port, bool _resolve_name) {\n    type = _type;",
            "bool Address::assign(SocketType _type, const std::string &_host, int _port, bool _resolve_name) {\n    memset(&addr, 0, sizeof(addr));\n    type = _type;"
        );
        // Winsock 的 SO_REUSEADDR 可以抢占他人端口；必须保留调用者在 bind 前设置的独占选项。
        // 未请求独占的 Socket 继续使用上游的地址复用语义，不改变 TCP 服务的既有配置。
        $sources['src/coroutine/socket.cc'] = $this->replace(
            $sources['src/coroutine/socket.cc'],
            '    if (socket->set_reuse_addr() < 0) {',
            <<<'CPP'
    int exclusive = 0;
    if (socket->get_option(SOL_SOCKET, SO_EXCLUSIVEADDRUSE, &exclusive) < 0) {
        set_err();
        return false;
    }
    if (!exclusive && socket->set_reuse_addr() < 0) {
CPP
        );
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
