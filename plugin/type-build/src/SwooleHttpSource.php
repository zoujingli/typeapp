<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 显式协程 HTTP 候选：复用已绑定套接字、原生接受循环和解析器，不进入默认线程适配。 */
final class SwooleHttpSource
{
    public const REFERENCE = '0f3bee2f0ed8704ce33a336e7feabb0115411dd7';

    /**
     * 仅修改隔离原文；上游提供等价套接字入口并处理共享监听 EAGAIN 后撤除。
     * fromSocket 保持当前线程的 Socket 引用，由调用者在 start 结束后关闭并释放副本。
     *
     * @return array<string, array{before: string, after: string}>
     * @throws RuntimeException 固定原文、唯一替换或保存失败；失败副本不可用于构建。
     */
    public function apply(string $directory): array
    {
        $hashes = [
            'ext-src/swoole_http_server_coro.cc' => '711fa3732141b3b90bf098157936729003bcaebe74f7bc9fa0b8e130a98e47d2',
            'ext-src/stubs/php_swoole_http_server_coro.stub.php' => 'c656a32f9758ac831d2213ed6fe64aac9dffc3e1211f90f853786cfa2edc992a',
            'ext-src/swoole_http_request.cc' => '33719a36f822333338d46a686a59de89db2f7872b2d73ca442e6a912d74cb9a2',
            'ext-src/php_swoole_http.h' => 'aa879b1cb2dcab56ee53956d4242cfdb65c4a7030d977db158ae21289d8a8b05',
        ];
        $contents = [];
        foreach ($hashes as $file => $hash) {
            $path = $directory . '/' . $file;
            if (!is_file($path) || is_link($path) || hash_file('sha256', $path) !== $hash) {
                throw new RuntimeException('Swoole HTTP 适配需要固定原文：' . $file);
            }
            $contents[$file] = (string) file_get_contents($path);
        }
        $source = $contents['ext-src/swoole_http_server_coro.cc'];
        $source = $this->replace($source, '    zval zclients;', "    zval zclients;\n    zval zlistener;");
        $source = $this->replace($source, <<<'CPP'
    explicit HttpServer(SocketType type) {
        socket = new SocketImpl(type);
CPP, <<<'CPP'
    explicit HttpServer(SocketType type, zval *listener = nullptr) {
        ZVAL_UNDEF(&zlistener);
        if (listener) {
            ZVAL_COPY(&zlistener, listener);
            socket = php_swoole_get_socket(listener);
        } else {
            socket = new SocketImpl(type);
        }
CPP);
        $source = $this->replace($source, '        delete socket;', <<<'CPP'
        if (Z_ISUNDEF(zlistener)) {
            delete socket;
        } else {
            zval_ptr_dtor(&zlistener);
        }
CPP);
        $source = $this->replace($source, 'static PHP_METHOD(swoole_http_server_coro, __construct);', <<<'CPP'
static PHP_METHOD(swoole_http_server_coro, __construct);
static PHP_METHOD(swoole_http_server_coro, fromSocket);
CPP);
        $source = $this->replace($source, 'SW_EXTERN_C_END', <<<'CPP'
SW_EXTERN_C_END

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_http_server_from_socket, 0, 1, Swoole\\Coroutine\\Http\\Server, 0)
    ZEND_ARG_OBJ_INFO(0, socket, Swoole\\Coroutine\\Socket, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, backlog, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()
CPP);
        $source = $this->replace($source, <<<'CPP'
    PHP_ME(swoole_http_server_coro, __construct, arginfo_class_Swoole_Coroutine_Http_Server___construct, ZEND_ACC_PUBLIC)
CPP, <<<'CPP'
    PHP_ME(swoole_http_server_coro, __construct, arginfo_class_Swoole_Coroutine_Http_Server___construct, ZEND_ACC_PUBLIC)
    PHP_ME(swoole_http_server_coro, fromSocket, arginfo_http_server_from_socket, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
CPP);
        $source = $this->replace($source, '    swoole_http_server_coro_ce->ce_flags |= ZEND_ACC_FINAL;', <<<'CPP'
    swoole_http_server_coro_ce->ce_flags |= ZEND_ACC_FINAL;
    zend_declare_class_constant_long(swoole_http_server_coro_ce, ZEND_STRL("TYPEAPP_LISTENER_ABI"), 1);
CPP);
        $source = $this->replace($source, <<<'CPP'
    HttpServerObject *hsc = http_server_coro_fetch_object(Z_OBJ_P(ZEND_THIS));
    std::string host_str(host, l_host);
CPP, <<<'CPP'
    HttpServerObject *hsc = http_server_coro_fetch_object(Z_OBJ_P(ZEND_THIS));
    if (hsc->server) {
        zend_throw_error(nullptr, "HTTP server is already initialized");
        RETURN_THROWS();
    }
    std::string host_str(host, l_host);
CPP);
        $source = $this->replace($source, 'static PHP_METHOD(swoole_http_server_coro, handle) {', <<<'CPP'
static PHP_METHOD(swoole_http_server_coro, fromSocket) {
    zval *listener;
    zend_long backlog = 0;
    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_OBJECT_OF_CLASS(listener, swoole_socket_coro_ce)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(backlog)
    ZEND_PARSE_PARAMETERS_END();
    SocketImpl *sock = php_swoole_get_socket(listener);
    if (!sock || sock->is_closed() || (sock->get_type() != SW_SOCK_TCP && sock->get_type() != SW_SOCK_TCP6)
        || sock->has_bound() || !sock->getsockname() || sock->get_port() == 0 || backlog < 0 || backlog > INT_MAX) {
        zend_value_error("HTTP server requires an idle, open, bound TCP socket and valid backlog");
        RETURN_THROWS();
    }
    // 原生 listen 也恢复线程复制后本地 Socket 的服务端属性，保留 TLS accept 语义。
    if (!sock->listen((int) backlog)) {
        zend_throw_exception_ex(swoole_exception_ce, sock->errCode, "listen() failed");
        RETURN_THROWS();
    }
    object_init_ex(return_value, swoole_http_server_coro_ce);
    auto *hsc = http_server_coro_fetch_object(Z_OBJ_P(return_value));
    hsc->server = new HttpServer(sock->get_type(), listener);
    zend_update_property_long(swoole_http_server_coro_ce, Z_OBJ_P(return_value), ZEND_STRL("fd"), sock->get_fd());
    zend_update_property_long(swoole_http_server_coro_ce, Z_OBJ_P(return_value), ZEND_STRL("port"), sock->get_port());
    zend_update_property_string(swoole_http_server_coro_ce, Z_OBJ_P(return_value), ZEND_STRL("host"), sock->get_addr());
}

static PHP_METHOD(swoole_http_server_coro, handle) {
CPP);
        $source = $this->replace($source, <<<'CPP'
            } else if (sock->errCode == ETIMEDOUT || sock->errCode == SW_ERROR_SSL_BAD_CLIENT) {
CPP, <<<'CPP'
            } else if (sock->errCode == ETIMEDOUT || sock->errCode == SW_ERROR_SSL_BAD_CLIENT || sock->errCode == EAGAIN) {
CPP);
        $contents['ext-src/swoole_http_server_coro.cc'] = $this->http1Input($this->connectionAdmission($source));
        $contents['ext-src/php_swoole_http.h'] = $this->replace(
            $contents['ext-src/php_swoole_http.h'],
            '    uchar parse_cookie : 1;',
            "    uchar parse_cookie : 1;\n    uchar typeapp_http1_input : 1;"
        );
        $contents['ext-src/swoole_http_request.cc'] = $this->requestHeaders($contents['ext-src/swoole_http_request.cc']);
        $contents['ext-src/stubs/php_swoole_http_server_coro.stub.php'] = $this->replace(
            $contents['ext-src/stubs/php_swoole_http_server_coro.stub.php'],
            "\t\tpublic function __destruct() {}",
            "\t\tpublic static function fromSocket(\\Swoole\\Coroutine\\Socket \$socket, int \$backlog = 0): Server {}\n\t\tpublic function __destruct() {}"
        );
        $report = [];
        foreach ($contents as $file => $content) {
            if (file_put_contents($directory . '/' . $file, $content) !== strlen($content)) {
                throw new RuntimeException('无法保存 Swoole HTTP 适配：' . $file);
            }
            $report[$file] = ['before' => $hashes[$file], 'after' => hash('sha256', $content)];
        }
        return $report;
    }

    /** 显式 HTTP/1 输入候选；协议解析与错误响应仍由上游拥有。 */
    private function http1Input(string $source): string
    {
        $source = $this->replace($source, '    bool parse_cookie;', "    bool parse_cookie;\n    bool typeapp_http1_input = false;");
        $source = $this->replace(
            $source,
            '        ctx->parse_cookie = parse_cookie;',
            "        ctx->parse_cookie = parse_cookie;\n        ctx->typeapp_http1_input = typeapp_http1_input;"
        );
        $source = $this->replace($source, '    // parse cookie header', <<<'CPP'
    zval *input_option = zend_hash_str_find(vht, ZEND_STRL("typeapp_http1_input"));
    if (input_option) {
        if (Z_TYPE_P(input_option) != IS_TRUE && Z_TYPE_P(input_option) != IS_FALSE) {
            zend_value_error("typeapp_http1_input must be a boolean");
            RETURN_THROWS();
        }
        hs->typeapp_http1_input = zend_is_true(input_option);
    }
    // parse cookie header
CPP);
        $source = $this->replace($source, '            buffer->offset += header_length;', <<<'CPP'
            if (hs->typeapp_http1_input && (ctx->parser.http_major != 1 || ctx->parser.http_minor > 1)) {
                ctx->response.status = SW_HTTP_VERSION_NOT_SUPPORTED;
                break;
            }
            buffer->offset += header_length;
CPP);
        return $this->replace($source, '    swoole_http_server_coro_ce->ce_flags |= ZEND_ACC_FINAL;', <<<'CPP'
    swoole_http_server_coro_ce->ce_flags |= ZEND_ACC_FINAL;
    zend_declare_class_constant_long(swoole_http_server_coro_ce, ZEND_STRL("TYPEAPP_HTTP1_INPUT_ABI"), 1);
CPP);
    }

    /** 在上游覆盖单值头前拒绝歧义，其余头复用已有多值数组；不复制或二次解析原始报文。 */
    private function requestHeaders(string $source): string
    {
        $source = $this->replace($source, '    if (ctx->parse_cookie && SW_STRCASEEQ(header_name, header_len, "cookie")) {', <<<'CPP'
    if (ctx->typeapp_http1_input &&
        ((SW_STRCASEEQ(header_name, header_len, "host") &&
          zend_hash_exists(Z_ARR_P(zheader), SW_ZSTR_KNOWN(SW_ZEND_STR_HOST))) ||
         (SW_STRCASEEQ(header_name, header_len, "authorization") &&
          zend_hash_exists(Z_ARR_P(zheader), SW_ZSTR_KNOWN(SW_ZEND_STR_AUTHORIZATION))) ||
         (SW_STRCASEEQ(header_name, header_len, "content-type") &&
          zend_hash_exists(Z_ARR_P(zheader), SW_ZSTR_KNOWN(SW_ZEND_STR_CONTENT_TYPE))))) {
        return -1;
    }
    if (ctx->parse_cookie && SW_STRCASEEQ(header_name, header_len, "cookie")) {
CPP);
        return $this->replace($source, '    if (SW_STRCASEEQ(header_name, header_len, "host")) {', <<<'CPP'
    if (ctx->typeapp_http1_input) {
        char *name = estrndup(header_name, header_len);
        zend_str_tolower_copy(name, header_name, header_len);
        zend::array_add_or_merge(zheader, name, header_len, &tmp);
        efree(name);
    } else if (SW_STRCASEEQ(header_name, header_len, "host")) {
CPP);
    }

    /** 只为显式连接预算衔接原生连接表、Channel 与 FD defer；解析与事件调度保持上游实现。 */
    private function connectionAdmission(string $source): string
    {
        $source = $this->replace($source, '#include <string>', "#include <string>\n#include \"swoole_coroutine_channel.h\"");
        $source = $this->replace($source, '    zval zlistener;', <<<'CPP'
    zval zlistener;
    size_t typeapp_max_connections = 0;
    bool typeapp_close_failed = false;
    std::unique_ptr<Channel> typeapp_admission;

    void typeapp_release_client(zval *server, zend_ulong cid) {
        if (!typeapp_admission) {
            zend_hash_index_del(Z_ARRVAL(zclients), cid);
            return;
        }
        zend_object *owner = Z_OBJ_P(server);
        GC_ADDREF(owner);
        // 先等连接协程释放参数，再丢弃原生连接表的引用；保留空槽直到 FD defer 完成。
        swoole_event_defer([this, owner, cid](void *) {
            zval *client = zend_hash_index_find(Z_ARRVAL(zclients), cid);
            if (!client || Z_TYPE_P(client) != IS_OBJECT || GC_REFCOUNT(Z_OBJ_P(client)) != 1) {
                php_swoole_error(E_WARNING, "bounded HTTP handler retained its native connection");
                typeapp_close_failed = true;
                zend_update_property_long(swoole_http_server_coro_ce, owner, ZEND_STRL("errCode"), EBUSY);
                zend_update_property_string(swoole_http_server_coro_ce, owner, ZEND_STRL("errMsg"),
                                            "bounded HTTP handler retained its native connection");
                running = false;
                typeapp_admission->close();
                socket->cancel(SW_EVENT_READ);
                OBJ_RELEASE(owner);
                return;
            }
            zval held;
            ZVAL_COPY_VALUE(&held, client);
            ZVAL_NULL(client);
            zval_ptr_dtor(&held);
            // 上游 Socket::free 已将实际 close 排在同一原生 defer 队列的前面。
            swoole_event_defer([this, owner, cid](void *) {
                zend_hash_index_del(Z_ARRVAL(zclients), cid);
                if (!typeapp_admission->is_closed() && !typeapp_admission->is_full()) {
                    typeapp_admission->push_data(this);
                }
                OBJ_RELEASE(owner);
            }, nullptr);
        }, nullptr);
    }
CPP);
        $source = $this->replace(
            $source,
            '    zend_declare_class_constant_long(swoole_http_server_coro_ce, ZEND_STRL("TYPEAPP_LISTENER_ABI"), 1);',
            "    zend_declare_class_constant_long(swoole_http_server_coro_ce, ZEND_STRL(\"TYPEAPP_LISTENER_ABI\"), 1);\n"
            . '    zend_declare_class_constant_long(swoole_http_server_coro_ce, ZEND_STRL("TYPEAPP_CONNECTION_LIMIT_ABI"), 1);'
        );
        $source = $this->replace($source, <<<'CPP'
static PHP_METHOD(swoole_http_server_coro, start) {
    HttpServer *hs = http_server_coro_get_object(Z_OBJ_P(ZEND_THIS));
CPP, <<<'CPP'
static PHP_METHOD(swoole_http_server_coro, start) {
    HttpServer *hs = http_server_coro_get_object(Z_OBJ_P(ZEND_THIS));
    if (hs->typeapp_admission) {
        zend_throw_error(nullptr, "bounded HTTP server can only be started once");
        RETURN_THROWS();
    }
CPP);
        $source = $this->replace($source, <<<'CPP'
    HashTable *vht = Z_ARRVAL_P(zsettings);
    zval *ztmp;
CPP, <<<'CPP'
    HashTable *vht = Z_ARRVAL_P(zsettings);
    zval *ztmp;
    if ((ztmp = zend_hash_str_find(vht, ZEND_STRL("typeapp_max_connections")))) {
        if (Z_TYPE_P(ztmp) != IS_LONG || Z_LVAL_P(ztmp) < 1 || Z_LVAL_P(ztmp) > 100000) {
            zend_value_error("typeapp_max_connections must be an integer between 1 and 100000");
            RETURN_THROWS();
        }
        if (hs->socket->has_bound() || zend_hash_num_elements(Z_ARRVAL(hs->zclients)) != 0) {
            zend_throw_error(nullptr, "connection limit must be configured before HTTP server starts");
            RETURN_THROWS();
        }
        hs->typeapp_max_connections = Z_LVAL_P(ztmp);
        hs->typeapp_admission = std::make_unique<swoole::coroutine::Channel>(1);
    }
CPP);
        $source = $this->replace($source, <<<'CPP'
    while (hs->running) {
        auto conn = sock->accept();
CPP, <<<'CPP'
    while (hs->running) {
        while (hs->running && hs->typeapp_admission
               && zend_hash_num_elements(Z_ARRVAL(hs->zclients)) >= hs->typeapp_max_connections) {
            if (!hs->typeapp_admission->pop()) {
                hs->running = false;
            }
        }
        if (!hs->running) {
            break;
        }
        auto conn = sock->accept();
CPP);
        $source = $this->replace($source, <<<'CPP'
    RETURN_TRUE;
}

static PHP_METHOD(swoole_http_server_coro, onAccept) {
CPP, <<<'CPP'
    RETURN_BOOL(!hs->typeapp_close_failed);
}

static PHP_METHOD(swoole_http_server_coro, onAccept) {
CPP);
        $source = $this->replace($source, <<<'CPP'
            } else if (sock->errCode == ECANCELED) {
                http_server_coro_set_error(ZEND_THIS, sock);
CPP, <<<'CPP'
            } else if (sock->errCode == ECANCELED) {
                if (!hs->typeapp_close_failed) {
                    http_server_coro_set_error(ZEND_THIS, sock);
                }
CPP);
        $source = $this->replace($source, <<<'CPP'
    if (sock->ssl_is_enable() && !sock->ssl_handshake()) {
        RETURN_NULL();
    }
    zend::array_set(&hs->zclients, co->get_cid(), zconn);
CPP, <<<'CPP'
    zend::array_set(&hs->zclients, co->get_cid(), zconn);
    ON_SCOPE_EXIT { hs->typeapp_release_client(ZEND_THIS, co->get_cid()); };
    if (sock->ssl_is_enable() && !sock->ssl_handshake()) {
        RETURN_NULL();
    }
CPP);
        $source = $this->replace($source, '    zend_hash_index_del(Z_ARRVAL_P(&hs->zclients), co->get_cid());', '');
        $source = $this->replace($source, <<<'CPP'
    hs->running = false;
    hs->socket->cancel(SW_EVENT_READ);
CPP, <<<'CPP'
    hs->running = false;
    if (hs->typeapp_admission) {
        hs->typeapp_admission->close();
    }
    hs->socket->cancel(SW_EVENT_READ);
CPP);
        $source = $this->replace($source, <<<'CPP'
        zend_hash_move_forward(Z_ARRVAL_P(&hs->zclients));
        SocketImpl *sock = php_swoole_get_socket(zconn);
        if (sock->get_socket()->recv_wait) {
            sock->cancel(SW_EVENT_READ);
            zend_hash_index_del(Z_ARRVAL_P(&hs->zclients), index);
        }
CPP, <<<'CPP'
        zend_hash_move_forward(Z_ARRVAL_P(&hs->zclients));
        if (Z_TYPE_P(zconn) != IS_OBJECT) {
            continue;
        }
        SocketImpl *sock = php_swoole_get_socket(zconn);
        if (sock->get_socket()->recv_wait || (hs->typeapp_admission && sock->has_bound(SW_EVENT_READ))) {
            sock->cancel(SW_EVENT_READ);
            if (!hs->typeapp_admission) {
                zend_hash_index_del(Z_ARRVAL_P(&hs->zclients), index);
            }
        }
CPP);
        return $source;
    }

    private function replace(string $source, string $original, string $replacement): string
    {
        if (substr_count($source, $original) !== 1) {
            throw new RuntimeException('Swoole HTTP 适配位置不唯一');
        }
        return str_replace($original, $replacement, $source);
    }
}
