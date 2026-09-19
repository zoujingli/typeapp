#ifndef _GNU_SOURCE
#define _GNU_SOURCE 1
#endif
#include <php.h>
#include <sapi/embed/php_embed.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#ifdef PHP_WIN32
#include <windows.h>
#else
#include <dlfcn.h>
#endif

static const char *core_library(void) {
#ifdef PHP_WIN32
    static wchar_t path[32768];
    static char utf8[131072];
    HMODULE handle = NULL;
    if (!GetModuleHandleExW(GET_MODULE_HANDLE_EX_FLAG_FROM_ADDRESS | GET_MODULE_HANDLE_EX_FLAG_UNCHANGED_REFCOUNT,
            (LPCWSTR) (void *) zend_get_constant_str, &handle)) { return ""; }
    DWORD size = GetModuleFileNameW(handle, path, 32768);
    if (size == 0 || size >= 32768 || !WideCharToMultiByte(CP_UTF8, WC_ERR_INVALID_CHARS, path, -1, utf8, 131072, NULL, NULL)) { return ""; }
    return utf8;
#else
    Dl_info info = {0};
    return dladdr((void *) zend_get_constant_str, &info) && info.dli_fname ? info.dli_fname : "";
#endif
}

/* 只读取已初始化embed的常量、模块及函数表；不执行PHP脚本或应用代码。 */
static void json_string(const char *value) {
    putchar('"');
    if (value != NULL) {
        for (const unsigned char *p = (const unsigned char *) value; *p; ++p) {
            if (*p == '"' || *p == '\\') { putchar('\\'); putchar(*p); }
            else if (*p < 32) { printf("\\u%04x", (unsigned int) *p); }
            else { putchar(*p); }
        }
    }
    putchar('"');
}

static int profile_environment(const char *ini, const char *scan) {
#ifdef PHP_WIN32
    return _putenv_s("PHPRC", ini) || _putenv_s("PHP_INI_SCAN_DIR", scan);
#else
    return setenv("PHPRC", ini, 1) || setenv("PHP_INI_SCAN_DIR", scan, 1);
#endif
}

int main(int argc, char **argv) {
    if (argc < 3 || argc > 131 || profile_environment(argv[1], argv[2]) != 0) { return 64; }
    /* PHP进程组启动器不消费这个INI；只在真正的embed进程内设置运行配置。 */
    if (php_embed_init(1, argv) == FAILURE) { return 2; }
    zval *version = zend_get_constant_str("PHP_VERSION", sizeof("PHP_VERSION") - 1);
    zval *zts = zend_get_constant_str("PHP_ZTS", sizeof("PHP_ZTS") - 1);
    if (version == NULL || Z_TYPE_P(version) != IS_STRING || zts == NULL) { php_embed_shutdown(); return 3; }
    printf("{\"protocol\":1,\"php\":"); json_string(Z_STRVAL_P(version));
    printf(",\"zts\":%s,\"sapi\":", zend_is_true(zts) ? "true" : "false"); json_string(sapi_module.name);
    printf(",\"core-library\":"); json_string(core_library());
    printf(",\"extensions\":{");
    int first = 1;
    zend_module_entry *module;
    ZEND_HASH_FOREACH_PTR(&module_registry, module) {
        if (!first) { putchar(','); } first = 0;
        json_string(module->name); putchar(':');
        if (module->version != NULL) { json_string(module->version); } else { printf("false"); }
    } ZEND_HASH_FOREACH_END();
    printf("},\"functions\":{");
    for (int index = 3; index < argc; ++index) {
        if (index > 3) { putchar(','); }
        json_string(argv[index]); putchar(':');
        printf("%s", zend_hash_str_exists(EG(function_table), argv[index], strlen(argv[index])) ? "true" : "false");
    }
    printf("}}\n");
    php_embed_shutdown();
    return ferror(stdout) ? 4 : 0;
}
