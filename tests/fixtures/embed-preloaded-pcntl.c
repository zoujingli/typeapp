/* 用真实 PCNTL 模块模拟将它预编入 embed 的平台；不替换函数或业务实现。 */
#define main type_embed_probe_main
#include "../../tools/embed-runtime-probe.c"
#undef main

extern zend_module_entry pcntl_module_entry;

static int type_embed_preloaded_startup(sapi_module_struct *sapi)
{
    return php_module_startup(sapi, &pcntl_module_entry);
}

int main(int argc, char **argv)
{
    php_embed_module.startup = type_embed_preloaded_startup;
    return type_embed_probe_main(argc, argv);
}
