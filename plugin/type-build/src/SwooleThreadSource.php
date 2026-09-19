<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 为固定上游补充编译线程入口、退出通知与进程级中断钩子；线程创建、请求及回收仍由 Swoole 承担。 */
final class SwooleThreadSource
{
    public const REFERENCE = '0f3bee2f0ed8704ce33a336e7feabb0115411dd7';

    /**
     * 只修改显式提供的隔离源码副本；原文摘要或替换次数不匹配时拒绝。
     *
     * @return array<string, array{before: string, after: string}>
     * @throws RuntimeException 源码不是受审版本或无法保存适配结果。
     */
    public function apply(string $directory): array
    {
        $hashes = [
            'ext-src/swoole_thread.cc' => '962f0a3fa23700c512d5833dc48dbd3d180a246f8419c54219b5dd95094aa6a8',
            'ext-src/php_swoole_thread.h' => '002c8cd826170c254a8aa2c974982b94aee26dc7bd56f6278aff58b1835355fa',
            'ext-src/stubs/php_swoole_thread.stub.php' => '3099a42713d5822dc2ce7ff4a1f12de47ca0f374f37aa212ebfae135a2db4c18',
            'ext-src/swoole_coroutine.cc' => '46346877709b3ef3981802abd07022cf81d2f7d606ad56d86b855d2c822f8831',
            'ext-src/php_swoole.cc' => '2700bba0b0e823e59482f5d812aaaf9b94302908923d7bf787deac3d68ace29c',
            'ext-src/php_swoole_private.h' => 'c310a42529fa90eabad77edd55c6e3bd903e36cd7caad98f25c2b6fd18a67d07',
        ];
        $contents = [];
        foreach ($hashes as $file => $hash) {
            $path = $directory . '/' . $file;
            if (!is_file($path) || is_link($path) || hash_file('sha256', $path) !== $hash) {
                throw new RuntimeException('Swoole 线程适配需要固定原文：' . $file);
            }
            $contents[$file] = (string) file_get_contents($path);
        }
        $source = $contents['ext-src/swoole_thread.cc'];
        // 原生 ArrayItem 没有填充 null，to_array 的未初始化槽会重复前值；控制参数必须保留明确空槽。
        $source = $this->replace($source, "void ArrayItem::fetch(zval *return_value) const {\n    switch (type) {", <<<'CPP'
void ArrayItem::fetch(zval *return_value) const {
    switch (type) {
    case IS_NULL:
        RETVAL_NULL();
        break;
CPP);
        $source = $this->replace($source, '#include <atomic>', "#include <atomic>\n#include <future>");
        $source = $this->replace(
            $source,
            '    std::shared_ptr<Thread> thread;',
            "    std::shared_ptr<Thread> thread;\n    std::shared_future<void> typeapp_completion;"
        );
        $source = $this->replace($source, 'static thread_local JMP_BUF *thread_bailout = nullptr;', <<<'CPP'
static thread_local JMP_BUF *thread_bailout = nullptr;
static thread_local bool native_stdio_owned = false;

bool php_swoole_thread_owns_stdio() {
    return native_stdio_owned;
}
CPP);
        $source = $this->replace(
            $source,
            '    zend_declare_class_constant_string(swoole_thread_ce, ZEND_STRL("API_NAME"), tsrm_api_name());',
            "    zend_declare_class_constant_long(swoole_thread_ce, ZEND_STRL(\"NATIVE_ENTRY_ABI\"), 2);\n"
            . "    zend_declare_class_constant_long(swoole_thread_ce, ZEND_STRL(\"TYPEAPP_SOCKET_ARGUMENT_ABI\"), 1);\n"
            . "    zend_declare_class_constant_long(swoole_thread_ce, ZEND_STRL(\"TYPEAPP_JOIN_ABI\"), 1);\n"
            . "    zend_declare_class_constant_long(swoole_thread_ce, ZEND_STRL(\"TYPEAPP_CONTROL_ARGUMENT_ABI\"), 1);\n"
            . '    zend_declare_class_constant_string(swoole_thread_ce, ZEND_STRL("API_NAME"), tsrm_api_name());'
        );
        $source = $this->replace($source, <<<'CPP'
    if (php_request_startup() != SUCCESS) {
        EG(exit_status) = 1;
        goto _startup_error;
    }
CPP, <<<'CPP'
    if (php_request_startup() != SUCCESS) {
        // 应用负责清理自己的半初始化状态；请求执行器与内存仍由宿主回收。
        php_request_shutdown(nullptr);
        EG(exit_status) = 1;
        goto _startup_error;
    }
CPP);
        $source = $this->replace($source, 'static PHP_METHOD(swoole_thread, __construct);', <<<'CPP'
static PHP_METHOD(swoole_thread, __construct);
static PHP_METHOD(swoole_thread, startNative);
static PHP_METHOD(swoole_thread, joinWithin);

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_swoole_thread_join_within, 0, 1, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, milliseconds, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_swoole_thread_start_native, 0, 2, Swoole\\Thread, 0)
    ZEND_ARG_TYPE_INFO(0, entry, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, payload, IS_STRING, 0)
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, socket, Swoole\\Coroutine\\Socket, 1, "null")
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, control, Swoole\\Thread\\Map, 1, "null")
ZEND_END_ARG_INFO()
CPP);
        $source = $this->replace($source, 'static const zend_function_entry swoole_thread_methods[] = {', <<<'CPP'
static const zend_function_entry swoole_thread_methods[] = {
    PHP_ME(swoole_thread, startNative, arginfo_swoole_thread_start_native, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    PHP_ME(swoole_thread, joinWithin, arginfo_swoole_thread_join_within, ZEND_ACC_PUBLIC)
CPP);
        $source = $this->replace($source, 'static PHP_METHOD(swoole_thread, isAlive) {', <<<'CPP'
// 子请求重新查找原生函数名；可选套接字通过上游 ZendArray 复制描述符，不共享 Zend 对象。
static PHP_METHOD(swoole_thread, startNative) {
    zend_string *entry;
    zval *payload;
    zval *socket = nullptr;
    zval *control = nullptr;
    ZEND_PARSE_PARAMETERS_START(2, 4)
        Z_PARAM_STR(entry)
        Z_PARAM_ZVAL(payload)
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJECT_OF_CLASS_OR_NULL(socket, swoole_socket_coro_ce)
        Z_PARAM_OBJECT_OF_CLASS_OR_NULL(control, swoole_thread_map_ce)
    ZEND_PARSE_PARAMETERS_END();
    if (Z_TYPE_P(payload) != IS_STRING || Z_STRLEN_P(payload) > 1048576
        || ZSTR_LEN(entry) == 0 || ZSTR_LEN(entry) > 255) {
        zend_value_error("native thread requires an entry and a string payload of at most 1 MiB");
        RETURN_THROWS();
    }
    auto function = static_cast<zend_function *>(zend_hash_find_ptr(EG(function_table), entry));
    if (!function || function->type != ZEND_INTERNAL_FUNCTION) {
        zend_value_error("native thread entry must be a registered internal function");
        RETURN_THROWS();
    }
    object_init_ex(return_value, swoole_thread_ce);
    auto pt = thread_get_php_thread(return_value);
    auto file = zend_string_init(ZSTR_VAL(entry), ZSTR_LEN(entry), true);
    auto argv = new ZendArray();
    argv->append(payload);
    if ((socket && Z_TYPE_P(socket) != IS_NULL) || (control && Z_TYPE_P(control) != IS_NULL)) {
        // 控制 Map 固定在下标 2；无 Socket 时保留一个 null 槽位。
        zval no_socket;
        ZVAL_NULL(&no_socket);
        if (!socket) { socket = &no_socket; }
        argv->append(socket);
        if (EG(exception)) {
            argv->del_ref();
            zend_string_release_ex(file, true);
            RETURN_THROWS();
        }
    }
    if (control && Z_TYPE_P(control) != IS_NULL) {
        // 上游 ZendArray 只增加 ThreadResource 引用；没有共享 Zend 对象指针。
        argv->append(control);
        if (EG(exception)) {
            argv->del_ref();
            zend_string_release_ex(file, true);
            RETURN_THROWS();
        }
    }
    try {
        auto thread = pt->thread;
        auto completion = std::make_shared<std::promise<void>>();
        pt->typeapp_completion = completion->get_future().share();
        thread->start([thread, file, argv, completion]() {
            php_swoole_thread_start(thread, file, argv, true);
            // 标记在线程局部析构完成后才就绪；不把入口返回或 living=false 当作可回收。
            completion->set_value_at_thread_exit();
        });
    } catch (const std::exception &error) {
        argv->del_ref();
        zend_string_release_ex(file, true);
        zend_throw_exception(swoole_exception_ce, error.what(), SW_ERROR_SYSTEM_CALL_FAIL);
        RETURN_THROWS();
    }
    zend::object_set(return_value, ZEND_STRL("id"), (zend_long) pt->thread->get_id());
}

static PHP_METHOD(swoole_thread, joinWithin) {
    zend_long milliseconds;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(milliseconds)
    ZEND_PARSE_PARAMETERS_END();
    if (milliseconds < 0 || milliseconds > 60000) {
        zend_value_error("joinWithin requires 0..60000 milliseconds");
        RETURN_THROWS();
    }
    auto pt = thread_get_php_thread(ZEND_THIS);
    if (!pt->typeapp_completion.valid()) {
        zend_value_error("joinWithin requires a native-entry thread");
        RETURN_THROWS();
    }
    if (!pt->thread->joinable()) {
        RETURN_FALSE;
    }
    if (pt->typeapp_completion.wait_for(std::chrono::milliseconds(milliseconds)) != std::future_status::ready) {
        RETURN_FALSE;
    }
    RETURN_BOOL(pt->join());
}

static PHP_METHOD(swoole_thread, isAlive) {
CPP);
        $source = $this->replace(
            $source,
            'void php_swoole_thread_start(std::shared_ptr<Thread> thread, zend_string *file, ZendArray *argv) {',
            'void php_swoole_thread_start(std::shared_ptr<Thread> thread, zend_string *file, ZendArray *argv, bool native_entry) {'
        );
        $source = $this->replace($source, "    zend_stream_init_filename(&file_handle, ZSTR_VAL(file));\n    file_handle.primary_script = true;", <<<'CPP'
    if (!native_entry) {
        zend_stream_init_filename(&file_handle, ZSTR_VAL(file));
        file_handle.primary_script = true;
    }
CPP);
        $source = $this->replace($source, "            argv->del_ref();\n        }\n        thread_register_stdio_file_handles(true);\n        php_execute_script(&file_handle);", <<<'CPP'
            argv->del_ref();
            argv = nullptr;
        }
        // embed 的 php:// 标准流是当前请求拥有的 dup 副本，必须随请求关闭。
        // 其他 SAPI 和脚本线程仍保留上游保护进程标准流的契约。
        native_stdio_owned = native_entry && strcmp(sapi_module.name, "embed") == 0;
        thread_register_stdio_file_handles(!native_stdio_owned);
        if (native_entry) {
            auto function = static_cast<zend_function *>(zend_hash_find_ptr(EG(function_table), file));
            zval result;
            ZVAL_UNDEF(&result);
            if (!function || function->type != ZEND_INTERNAL_FUNCTION) {
                zend_throw_exception(swoole_exception_ce, "native thread entry missing in child runtime", 0);
            } else {
                zval *payload = zend_hash_index_find(Z_ARRVAL(thread_argv), 0);
                zend_call_known_function(function, nullptr, nullptr, &result, 1, payload, nullptr);
                if (!EG(exception)) {
                    if (Z_TYPE(result) != IS_LONG || Z_LVAL(result) < 0 || Z_LVAL(result) > 255) {
                        zend_throw_exception(swoole_exception_ce, "native thread entry must return an exit status (0..255)", 0);
                    } else {
                        EG(exit_status) = static_cast<int>(Z_LVAL(result));
                    }
                }
            }
            zval_ptr_dtor(&result);
            if (EG(exception)) {
                if (zend_is_graceful_exit(EG(exception))) {
                    zend_clear_exception();
                } else {
                    zend_exception_error(EG(exception), E_ERROR);
                }
            }
        } else {
            php_execute_script(&file_handle);
        }
CPP);
        $source = $this->replace($source, '    zend_destroy_file_handle(&file_handle);', '    if (!native_entry) { zend_destroy_file_handle(&file_handle); }');
        $source = $this->replace($source, "    php_request_shutdown(nullptr);\n    file_handle.filename = nullptr;", "    // 业务 setjmp 已结束；shutdown 使用各清理阶段自己的 bailout 边界。\n    thread_bailout = nullptr;\n    php_request_shutdown(nullptr);\n    file_handle.filename = nullptr;");
        $source = $this->replace($source, "_startup_error:\n    zend_string_release(file);", "_startup_error:\n    if (argv) { argv->del_ref(); }\n    zend_string_release_ex(file, true);");
        $contents['ext-src/swoole_thread.cc'] = $source;
        $contents['ext-src/php_swoole_thread.h'] = $this->replace(
            $contents['ext-src/php_swoole_thread.h'],
            'void php_swoole_thread_start(std::shared_ptr<swoole::Thread> thread, zend_string *file, ZendArray *argv);',
            "bool php_swoole_thread_owns_stdio();\n"
            . 'void php_swoole_thread_start(std::shared_ptr<swoole::Thread> thread, zend_string *file, ZendArray *argv, bool native_entry = false);'
        );
        $contents['ext-src/stubs/php_swoole_thread.stub.php'] = $this->replace(
            $contents['ext-src/stubs/php_swoole_thread.stub.php'],
            '        public function join(): bool {}',
            "        public static function startNative(string \$entry, string \$payload, ?\\Swoole\\Coroutine\\Socket \$socket = null, ?\\Swoole\\Thread\\Map \$control = null): Thread {}\n\n        public function joinWithin(int \$milliseconds): bool {}\n\n        public function join(): bool {}"
        );
        $contents['ext-src/swoole_coroutine.cc'] = $this->coroutineInterruptSource($contents['ext-src/swoole_coroutine.cc']);
        // 首次激活也必须服从显式关闭选项；默认开启时沿用官方请求初始化加载。
        $contents['ext-src/swoole_coroutine.cc'] = $this->replace(
            $contents['ext-src/swoole_coroutine.cc'],
            '    if (enable_library == nullptr || !zval_is_true(enable_library)) {',
            '    if (SWOOLE_G(enable_library) && (enable_library == nullptr || !zval_is_true(enable_library))) {'
        );
        $contents['ext-src/php_swoole_private.h'] = $this->replace(
            $contents['ext-src/php_swoole_private.h'],
            'void php_swoole_coroutine_minit(int module_number);',
            "void php_swoole_coroutine_minit(int module_number);\nvoid php_swoole_coroutine_mshutdown();"
        );
        $contents['ext-src/php_swoole.cc'] = $this->replace(
            $contents['ext-src/php_swoole.cc'],
            '    php_swoole_runtime_mshutdown();',
            "    php_swoole_coroutine_mshutdown();\n    php_swoole_runtime_mshutdown();"
        );
        $contents['ext-src/php_swoole.cc'] = $this->replace($contents['ext-src/php_swoole.cc'], <<<'CPP'
    auto php_swoole_set_stdio_no_close = [](const char *name, size_t name_len) {
CPP, <<<'CPP'
    auto php_swoole_set_stdio_no_close = [](const char *name, size_t name_len) {
#ifdef SW_THREAD
        // 原生 embed 线程的标准流只拥有自身副本，RSHUTDOWN 不再禁止关闭。
        if (php_swoole_thread_owns_stdio()) {
            return;
        }
#endif
CPP);
        $report = [];
        foreach ($contents as $file => $content) {
            if (file_put_contents($directory . '/' . $file, $content) !== strlen($content)) {
                throw new RuntimeException('无法保存 Swoole 线程适配：' . $file);
            }
            $report[$file] = ['before' => $hashes[$file], 'after' => hash('sha256', $content)];
        }
        return $report;
    }

    /** Zend 中断回调属于进程；在线程激活时反复交换会把原回调保存为自身。 */
    private function coroutineInterruptSource(string $source): string
    {
        $source = $this->replace($source, <<<'CPP'
static void coro_interrupt_function(zend_execute_data *execute_data) {
    PHPContext *task = PHPCoroutine::get_context();
CPP, <<<'CPP'
static void coro_interrupt_function(zend_execute_data *execute_data) {
    // 进程钩子也覆盖尚未激活或已经关闭协程的请求，只调度当前线程的活动协程。
    PHPContext *task = PHPCoroutine::is_activated() ? PHPCoroutine::get_context() : nullptr;
CPP);
        $source = $this->replace($source, <<<'CPP'
    /* replace interrupt function */
    orig_interrupt_function = zend_interrupt_function;
    zend_interrupt_function = coro_interrupt_function;
CPP, <<<'CPP'
#ifndef SW_THREAD
    /* replace interrupt function */
    orig_interrupt_function = zend_interrupt_function;
    zend_interrupt_function = coro_interrupt_function;
#endif
CPP);
        $source = $this->replace(
            $source,
            '    zend_interrupt_function = orig_interrupt_function;',
            "#ifndef SW_THREAD\n    zend_interrupt_function = orig_interrupt_function;\n#endif"
        );
        $source = $this->replace($source, 'void php_swoole_coroutine_minit(int module_number) {', <<<'CPP'
void php_swoole_coroutine_minit(int module_number) {
#ifdef SW_THREAD
    // MINIT 先于业务线程启动；运行期间只读取该进程级回调链。
    orig_interrupt_function = zend_interrupt_function;
    zend_interrupt_function = coro_interrupt_function;
#endif
CPP);
        return $this->replace($source, 'void php_swoole_coroutine_rinit() {', <<<'CPP'
void php_swoole_coroutine_mshutdown() {
#ifdef SW_THREAD
    // 仅移除自己仍持有的钩子，避免覆盖之后登记的其他扩展。
    if (zend_interrupt_function == coro_interrupt_function) {
        zend_interrupt_function = orig_interrupt_function;
    }
    orig_interrupt_function = nullptr;
#endif
}

void php_swoole_coroutine_rinit() {
CPP);
    }

    private function replace(string $source, string $before, string $after): string
    {
        if (substr_count($source, $before) !== 1) {
            throw new RuntimeException('Swoole 原文替换位置不唯一，拒绝继续');
        }
        return str_replace($before, $after, $source);
    }
}
