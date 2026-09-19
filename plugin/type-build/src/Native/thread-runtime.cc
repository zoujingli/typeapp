#include <phpx.h>
#include <typephp_runtime.h>

BEGIN_EXTERN_C()
#include "sapi/embed/php_embed.h"
#include "ext/standard/basic_functions.h"
#include "zend_signal.h"
END_EXTERN_C()

#ifdef PHP_WIN32
#include <io.h>
#include <fcntl.h>
#include <direct.h>
#include <stdlib.h>
#else
#include <limits.h>
#include <unistd.h>
#endif

#include <cstdlib>
#include <cstring>
#include <string>

extern "C" void type_app_compiled_process_main(int, char **);
#if !defined(PHP_WIN32)
extern "C" char **save_ps_args(int, char **);
#endif

namespace {
zend_module_entry *application_module = nullptr;
bool embed_started = false;
bool sapi_started = false;
bool tsrm_started = false;
bool module_started = false;
bool request_started = false;

std::string resolve_executable(const char *program) {
    if (!program || *program == '\0') { return {}; }
    auto resolve = [](const std::string &path) -> std::string {
#ifdef PHP_WIN32
        char buffer[_MAX_PATH];
        if (!_fullpath(buffer, path.c_str(), sizeof(buffer))) { return {}; }
#else
        char buffer[PATH_MAX];
        if (!realpath(path.c_str(), buffer)) { return {}; }
#endif
        return buffer;
    };
    if (std::strchr(program, '/') || std::strchr(program, '\\')) {
        return resolve(program);
    }
    const char *path = std::getenv("PATH");
    if (!path) { return {}; }
#ifdef PHP_WIN32
    constexpr char separator = ';';
#else
    constexpr char separator = ':';
#endif
    std::string search(path);
    size_t start = 0;
    while (start <= search.size()) {
        size_t end = search.find(separator, start);
        std::string directory = search.substr(start, end == std::string::npos ? std::string::npos : end - start);
        if (directory.empty()) { directory = "."; }
#ifdef PHP_WIN32
        std::string candidate = directory + "\\" + program;
#else
        std::string candidate = directory + "/" + program;
#endif
        std::string resolved = resolve(candidate);
        if (!resolved.empty()) { return resolved; }
        if (end == std::string::npos) { break; }
        start = end + 1;
    }
    return {};
}

// PHP resolves extension_dir from the current directory, so direct binaries must establish the package root first.
bool prepare_working_directory(char **argv) {
    std::string executable = resolve_executable(argv ? argv[0] : nullptr);
    if (executable.empty()) { return true; }
    size_t separator = executable.find_last_of("/\\");
    if (separator == std::string::npos) { return true; }
    std::string directory = executable.substr(0, separator);
    const std::string binary_directory = directory;
    bool packaged = false;
    size_t parent_separator = directory.find_last_of("/\\");
    if (parent_separator != std::string::npos && directory.substr(parent_separator + 1) == "bin") {
        packaged = true;
        directory.resize(parent_separator);
    }
#ifdef PHP_WIN32
    if (_chdir(directory.c_str()) != 0) { return false; }
    if (packaged && std::getenv("TYPE_APP_RUNTIME_ROOT") == nullptr) {
        if (_putenv_s("TYPE_APP_RUNTIME_ROOT", directory.c_str()) != 0) { return false; }
        if (std::getenv("APP_BASE_PATH") == nullptr && _putenv_s("APP_BASE_PATH", binary_directory.c_str()) != 0) {
            return false;
        }
    }
    return true;
#else
    if (chdir(directory.c_str()) != 0) { return false; }
    if (packaged && std::getenv("TYPE_APP_RUNTIME_ROOT") == nullptr) {
        if (setenv("TYPE_APP_RUNTIME_ROOT", directory.c_str(), 0) != 0) { return false; }
        if (std::getenv("APP_BASE_PATH") == nullptr && setenv("APP_BASE_PATH", binary_directory.c_str(), 0) != 0) {
            return false;
        }
    }
    return true;
#endif
}

// 与 PHP 8.5 embed 同一公开阶段；先结束请求，再拆模块、SAPI、TSRM。
// php_embed_init 的请求失败分支会先拆模块，因而不能用它恢复部分初始化。
void shutdown_embed() {
    if (request_started) {
        request_started = false;
        php_request_shutdown(nullptr);
    }
    if (module_started) {
        module_started = false;
        php_module_shutdown();
    }
    if (sapi_started) {
        sapi_started = false;
        sapi_shutdown();
    }
#ifdef ZTS
    if (tsrm_started) {
        tsrm_started = false;
        tsrm_shutdown();
    }
#endif
    application_module = nullptr;
    embed_started = false;
}

bool startup_embed(int argc, char **argv) {
#if defined(SIGPIPE) && defined(SIG_IGN)
    signal(SIGPIPE, SIG_IGN);
#endif
    if (!prepare_working_directory(argv)) { return false; }
#ifdef ZTS
    if (!php_tsrm_startup()) { return false; }
    tsrm_started = true;
#ifdef PHP_WIN32
    ZEND_TSRMLS_CACHE_UPDATE();
#endif
#endif
    zend_signal_startup();
    sapi_startup(&php_embed_module);
    sapi_started = true;
#ifdef PHP_WIN32
    _fmode = _O_BINARY;
    _setmode(_fileno(stdin), _O_BINARY);
    _setmode(_fileno(stdout), _O_BINARY);
    _setmode(_fileno(stderr), _O_BINARY);
#endif
    static const char defaults[] = "html_errors=0\nimplicit_flush=1\noutput_buffering=0\nmax_execution_time=0\nmax_input_time=-1\n";
    php_embed_module.ini_entries = defaults;
    php_embed_module.executable_location = argv ? argv[0] : nullptr;
    const auto module_result = php_module_startup(&php_embed_module, application_module);
    module_started = php_get_module_initialized();
    if (module_result != SUCCESS) { return false; }
    SG(options) |= SAPI_OPTION_NO_CHDIR;
    SG(request_info).argc = argc;
    SG(request_info).argv = argv;
    // request_startup 即使返回 FAILURE 也已获得执行器和请求内存。
    request_started = true;
    if (php_request_startup() != SUCCESS) { return false; }
    PG(during_request_startup) = false;
    SG(headers_sent) = 1;
    SG(request_info).no_headers = 1;
    php_register_variable("PHP_SELF", "-", nullptr);
    return true;
}

void register_standard_stream(const char *name, const char *path, const char *mode) {
    auto stream = php_stream_open_wrapper_ex(path, mode, 0, nullptr, nullptr);
    if (!stream) { return; }
    stream->flags |= PHP_STREAM_FLAG_NO_RSCR_DTOR_CLOSE;
    zend_constant constant{};
    php_stream_to_zval(stream, &constant.value);
    constant.name = zend_string_init_interned(name, strlen(name), false);
    ZEND_CONSTANT_SET_FLAGS(&constant, CONST_CS, 0);
    zend_register_constant(&constant);
}
}

extern "C" int typephp_runtime_start(typephp_module_getter get_module, int argc, char **argv) {
    if (embed_started) { return 0; }
    if (php::typeAppThreadAbi() != 2) { return 1; }
    application_module = get_module();
    if (!startup_embed(argc, argv)) {
        shutdown_embed();
        return 1;
    }
    // 主入口返回非零时同样拥有活请求，必须执行关闭。
    embed_started = true;
#if !defined(PHP_WIN32)
    // 该函数会改写原始 argv 槽，业务入口必须使用它返回的参数副本。
    argv = save_ps_args(argc, argv);
#endif
    register_standard_stream("STDIN", "php://stdin", "rb");
    register_standard_stream("STDOUT", "php://stdout", "wb");
    register_standard_stream("STDERR", "php://stderr", "wb");
    static char entry_path[] = "typeapp-aot";
    SG(request_info).path_translated = entry_path;
    zend_first_try {
        try {
            type_app_compiled_process_main(argc, argv);
        } catch (zend_object *error) {
            if (zend_is_graceful_exit(error)) {
                zend_clear_exception();
            } else {
                CG(unclean_shutdown) = 1;
                zend_exception_error(error, E_ERROR);
            }
        }
    } zend_end_try();
    return EG(exit_status);
}

extern "C" void typephp_runtime_stop(void) {
    if (!embed_started) { return; }
    // 所有 Thread 必须先由所有者 join；正常关闭在应用 RSHUTDOWN 前运行析构和回调。
    shutdown_embed();
}
