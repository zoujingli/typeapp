<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 隔离 PHPX 2.9.2 的线程及协程执行状态，保留请求级原生对象根链。 */
final class PhpxThreadSource
{
    /** 适配后的源码摘要；apply() 内部仍严格校验固定 2.9.2 原文摘要。 */
    public const HEADER_SHA256 = '3408f394e49587d4e6393b491dcfa2503010fa5f74269c474684560190254218';
    public const SOURCE_SHA256 = 'ec340def9aa5c08d30a446f78633892862642a66ab03a91ba94f05a2f2314a50';
    public const DEBUG_SHA256 = '1098c689db6deb8b87937f4d57160369da289ecb1dd643c22da97fc5eda614c2';
    public const NATIVE_GC_SHA256 = '22702f78430fa59f4367d24dfae881e2c12f42d171e55b55847843d5d04c42aa';
    public const STRING_SHA256 = 'df26c5aad50de70d684c67db25c80a5e993fda8089e5d3c15b964621788a4bc5';
    /**
     * 只适配显式提供的固定版本源码副本；适配后必须重新编译整份 PHPX 库。
     *
     * @return array<string, array{before: string, after: string}>
     * @throws RuntimeException 原文摘要、替换位置或写入结果不符。
     */
    public function apply(string $directory): array
    {
        $hashes = [
            'include/phpx.h' => '3a29ec6614b891417152a541442b3bcf24cb64ed599e135f10af24c6265c80eb',
            'src/core/base.cc' => '8a042a3cbf6540af6103eac22b529c971ac7868892a5b3b2de3b9d4b05079240',
            'src/core/debug.cc' => '50cf6c0b1a50ab58fa360e4edcab6bf3958cd908cc195f3516252d3c300e3d46',
            'src/core/native_gc.cc' => 'a1a40dd926fbcfef9cbc18456a29dd092ca24c4501d275eea9403ca2204c5680',
            'src/core/string.cc' => '3ef612e78db649ea488e606b8b9067b0ef65f6c817c3e3c6a6404eaf990387a2',
        ];
        $contents = [];
        foreach ($hashes as $file => $hash) {
            $path = $directory . '/' . $file;
            if (!is_file($path) || is_link($path) || hash_file('sha256', $path) !== $hash) {
                throw new RuntimeException('PHPX 线程适配需要固定原文：' . $file);
            }
            $contents[$file] = (string) file_get_contents($path);
        }
        $contents['include/phpx.h'] = $this->replace(
            $contents['include/phpx.h'],
            'extern DebugInfo debug_info;',
            "extern THREAD_LOCAL DebugInfo debug_info;\nPHPX_API int typeAppThreadAbi();\nPHPX_API bool typeAppRunFinalizer(void (*finalizer)(void *), void *object);"
        );
        $contents['include/phpx.h'] = $this->replace($contents['include/phpx.h'], <<<'CPP'
            ZVAL_NEW_STR(&val, zend_string_init(str, len, persistent));
            /**
             * There is a flaw in PHP's design: persistent strings cause assertion failures upon destruction.
             * By incrementing the reference count once, these strings are never released,
             * and are instead reclaimed automatically by the operating system when the process terminates.
             */
            addRef();
            /**
             * Persistent string must have its hash value precomputed. This string may be used for hash lookups in a
             * multi-threaded environment. If the hash value is null and requires calculation, it may lead to
             * multi-thread contention.
             */
            zend_string_hash_val(Z_STR(val));
CPP, <<<'CPP'
            // 保留原有进程寿命；不可变标记同时阻止跨线程引用计数写入和原地改写。
            auto value = zend_string_init(str, len, true);
            zend_string_hash_val(value);
            GC_ADD_FLAGS(value, IS_STR_INTERNED | IS_STR_PERMANENT);
            ZVAL_INTERNED_STR(&val, value);
CPP);
        // SEPARATE_STRING 只处理有引用计数的字符串；共享 interned 字节必须先取得可写副本。
        $contents['include/phpx.h'] = $this->replace(
            $contents['include/phpx.h'],
            '        SEPARATE_STRING(zv());',
            '        ZVAL_STR(zv(), zend_string_separate(Z_STR_P(zv()), false));'
        );
        $contents['src/core/string.cc'] = $this->replace(
            $contents['src/core/string.cc'],
            '            SEPARATE_STRING(zv);',
            '            ZVAL_STR(zv, zend_string_separate(Z_STR_P(zv), false));'
        );
        $source = $this->replace(
            $contents['src/core/base.cc'],
            'const char *box_res_name = "php::box";',
            "int typeAppThreadAbi() { return 2; }\n\nconst char *box_res_name = \"php::box\";"
        );
        $source = $this->replace($source, '#include <zend_observer.h>', "#include <zend_observer.h>\n#include <unordered_map>");
        $source = $this->replace($source, 'thread_local zend_class_entry *lexical_call_scope = nullptr;', '');
        $source = $this->replace($source, 'static void initializeProcessState() noexcept {', <<<'CPP'
namespace {
thread_local zend_class_entry *lexical_call_scope = nullptr;

struct CoroutineExecutionState {
    zend_class_entry *lexical = nullptr;
    const zend_class_entry *fake = nullptr;
    DebugInfo debug{};
};

thread_local std::unordered_map<zend_fiber_context *, CoroutineExecutionState> coroutine_states;

void coroutine_switch(zend_fiber_context *from, zend_fiber_context *to) {
    if (!request_active) { return; }
    coroutine_states[from] = {lexical_call_scope, EG(fake_scope), debug_info};
    auto found = coroutine_states.find(to);
    CoroutineExecutionState next{};
    // 新协程只有调试开关，没有父协程的权限或活动栈；恢复使用它自己的状态。
    next.debug.enable = debug_info.enable;
    if (found != coroutine_states.end()) { next = found->second; }
    lexical_call_scope = next.lexical;
    EG(fake_scope) = next.fake;
    debug_info = next.debug;
}

void coroutine_destroy(zend_fiber_context *context) {
    coroutine_states.erase(context);
}

void clearCoroutineState() {
    coroutine_states.clear();
    lexical_call_scope = nullptr;
    EG(fake_scope) = nullptr;
    debug_info = DebugInfo{};
}
}

// finalizer 的 longjmp 不能越过 Wren sweep；保存执行状态，让堆完成逐对象释放。
bool typeAppRunFinalizer(void (*finalizer)(void *), void *object) {
    auto previous_frame = EG(current_execute_data);
    auto previous_scope = EG(fake_scope);
    auto previous_lexical = lexical_call_scope;
    auto previous_depth = debug_info.depth;
    auto previous_debug = debug_info.enable;
    bool bailed = false;
    std::exception_ptr failure;
    zend_try {
        try { finalizer(object); }
        catch (...) { failure = std::current_exception(); }
    } zend_catch { bailed = true; } zend_end_try();
    EG(current_execute_data) = previous_frame;
    EG(fake_scope) = previous_scope;
    lexical_call_scope = previous_lexical;
    debug_info.depth = previous_depth;
    debug_info.enable = previous_debug;
    if (failure) { std::rethrow_exception(failure); }
    return bailed;
}

static void initializeProcessState() noexcept {
CPP);
        $source = $this->replace($source, '        initializeBoxResource();', <<<'CPP'
        initializeBoxResource();
        zend_observer_fiber_switch_register(coroutine_switch);
        zend_observer_fiber_destroy_register(coroutine_destroy);
CPP);
        $source = $this->replace($source, '    nativeGcRequestInit();', "    clearCoroutineState();\n    nativeGcRequestInit();");
        $source = $this->replace($source, '    nativeGcRequestShutdown();', "    nativeGcRequestShutdown();\n    clearCoroutineState();");
        $contents['src/core/base.cc'] = $source;
        $debug = $this->replace(
            $contents['src/core/debug.cc'],
            'DebugInfo debug_info{',
            'THREAD_LOCAL DebugInfo debug_info{'
        );
        $debug = $this->replace(
            $debug,
            'if (!debug_info.enable || debug_info.depth == 0 || !EG(exception)) {',
            'if (!debug_info.enable || debug_info.depth == 0 || !EG(exception) || zend_is_graceful_exit(EG(exception))) {'
        );
        $contents['src/core/debug.cc'] = $debug;
        $gc = $this->replace(
            $contents['src/core/native_gc.cc'],
            'THREAD_LOCAL std::exception_ptr pending_cpp_exception;',
            "THREAD_LOCAL std::exception_ptr pending_cpp_exception;\nTHREAD_LOCAL bool pending_bailout = false;"
        );
        $gc = $this->replace($gc, '        type->finalize(object);', <<<'CPP'
        if (typeAppRunFinalizer(type->finalize, object)) {
            pending_bailout = true;
            // longjmp 跳过的根帧已失效；旧栈稍后展开时以 epoch 避免再次摘链。
            native_root_top = nullptr;
            native_container_root_top = nullptr;
            native_root_request_epoch++;
        }
CPP);
        $gc = $this->replace($gc, 'void rethrowFinalizerException() {', "void rethrowFinalizerException() {\n    if (pending_bailout) { pending_bailout = false; zend_bailout(); }");
        $gc = $this->replace($gc, 'void discardPendingFinalizerException() noexcept {', "void discardPendingFinalizerException() noexcept {\n    pending_bailout = false;");
        $gc = $this->replace($gc, <<<'CPP'
    try {
        rethrowFinalizerException();
CPP, <<<'CPP'
    if (pending_bailout) {
        // 尚未 placement-construct 的新存储不能交给 descriptor destroy。
        wren_gc_abandon(native_heap, object);
        rethrowFinalizerException();
    }
    try {
        rethrowFinalizerException();
CPP);
        $contents['src/core/native_gc.cc'] = $gc;
        $report = [];
        foreach ($contents as $file => $content) {
            if (file_put_contents($directory . '/' . $file, $content) !== strlen($content)) {
                throw new RuntimeException('无法保存 PHPX 线程适配：' . $file);
            }
            $report[$file] = ['before' => $hashes[$file], 'after' => hash('sha256', $content)];
        }
        return $report;
    }

    private function replace(string $source, string $before, string $after): string
    {
        if (substr_count($source, $before) !== 1) {
            throw new RuntimeException('PHPX 原文替换位置不唯一，拒绝继续');
        }
        return str_replace($before, $after, $source);
    }
}
