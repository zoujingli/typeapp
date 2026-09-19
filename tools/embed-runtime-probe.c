#define _GNU_SOURCE 1
#include <stdio.h>
#include <string.h>
#include <php.h>
#include <sapi/embed/php_embed.h>

/* 只探测实际 embed 模块，不执行 PHP 源文件或应用代码。 */
int main(int argc, char **argv)
{
    if (argc > 2 || (argc == 2 && strcmp(argv[1], "--libraries") != 0 && strcmp(argv[1], "--extensions") != 0)) {
        fprintf(stderr, "未知 embed 探测参数\n");
        return 64;
    }
    if (php_embed_init(argc, argv) == FAILURE) {
        return 2;
    }
    if (argc == 2 && strcmp(argv[1], "--libraries") == 0) {
        FILE *maps = fopen("/proc/self/maps", "r");
        if (maps == NULL) { php_embed_shutdown(); return 2; }
        char line[16384];
        while (fgets(line, sizeof(line), maps) != NULL) {
            char *path = strchr(line, '/');
            if (path == NULL || strstr(path, ".so") == NULL) { continue; }
            path[strcspn(path, "\r\n")] = '\0';
            FILE *library = fopen(path, "rb");
            if (library == NULL) { fclose(maps); php_embed_shutdown(); return 2; }
            unsigned char magic[4];
            size_t read = fread(magic, 1, sizeof(magic), library); fclose(library);
            if (read == 4 && memcmp(magic, "\x7f" "ELF", 4) == 0) { printf("%s\n", path); }
        }
        fclose(maps);
    } else if (argc == 2 && strcmp(argv[1], "--extensions") == 0) {
        zend_module_entry *module;
        ZEND_HASH_FOREACH_PTR(&module_registry, module) {
            printf("%s\n", module->name);
        } ZEND_HASH_FOREACH_END();
    } else {
        printf("%d %d %d %d\n",
            zend_hash_str_exists(&module_registry, "pcntl", sizeof("pcntl") - 1),
            zend_hash_str_exists(EG(function_table), "pcntl_fork", sizeof("pcntl_fork") - 1),
            zend_hash_str_exists(EG(function_table), "pcntl_signal", sizeof("pcntl_signal") - 1),
            zend_hash_str_exists(EG(function_table), "pcntl_async_signals", sizeof("pcntl_async_signals") - 1));
    }
    php_embed_shutdown();
    return 0;
}
