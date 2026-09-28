// 在同一静态映像内调用 PHPX 的值操作、数值库和请求生命周期，不解释 PHP 脚本。
#include <phpx.h>
#include <phpx_big_int.h>
#include <phpx_decimal.h>
extern "C" {
#include <sapi/embed/php_embed.h>
}
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include "native-libraries.cc"

static bool verify_native_identity() {
    auto program = php_type_app_native_embedded_core();
    auto directory = php_type_app_native_system_directory();
    auto images = php_type_app_native_loaded_images();
    if (program.empty() || directory.empty() || images.count() == 0) { return false; }
    wchar_t executable[32768];
    const DWORD length = GetModuleFileNameW(nullptr, executable, 32768);
    if (length == 0 || length >= 32768) { return false; }
    auto expected = type_app_windows_path(executable, static_cast<int>(length));
    if (_stricmp(program.toCString(), expected.toCString()) != 0) { return false; }
    const char *previous = std::getenv("SystemRoot");
    const std::string original = previous ? previous : "";
    if (_putenv_s("SystemRoot", "X:\\untrusted-environment")) { return false; }
    auto trusted = php_type_app_native_system_directory();
    const bool restored = _putenv_s("SystemRoot", original.c_str()) == 0;
    return restored && std::strcmp(directory.toCString(), trusted.toCString()) == 0;
}

static bool verify_values() {
    php::Array values;
    values.set("answer", 42);
    if (values["answer"].toInt() != 42) { return false; }
    auto integer = php::toBigInt(php::String("12345678901234567890"));
    auto squared = php::BigInt::mul(integer, integer);
    if (std::strcmp(php::BigInt::toString(squared).toCString(), "152415787532388367501905199875019052100") != 0) {
        return false;
    }
    auto sum = php::Decimal::add(php::toDecimal(php::String("0.1")), php::toDecimal(php::String("0.2")));
    return std::strcmp(php::Decimal::toString(sum).toCString(), "0.3") == 0;
}

int main(int argc, char **argv) {
    if (argc != 3 || _putenv_s("PHPRC", argv[1]) || _putenv_s("PHP_INI_SCAN_DIR", argv[2])) { return 64; }
    if (php_embed_init(1, argv) == FAILURE) { return 1; }
    php::request_init();
    const bool passed = verify_values() && verify_native_identity();
    php::request_shutdown();
    php_embed_shutdown();
    if (!passed) { return 2; }
    std::puts("PHPX static values, request lifecycle and native image identity passed");
    return 0;
}
