// 在同一静态映像内调用 PHPX 的值操作、数值库和请求生命周期，不解释 PHP 脚本。
#include <phpx.h>
#include <phpx_big_int.h>
#include <phpx_decimal.h>
extern "C" {
#include <sapi/embed/php_embed.h>
}
#include <cstdio>
#include <cstring>

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
    const bool passed = verify_values();
    php::request_shutdown();
    php_embed_shutdown();
    if (!passed) { return 2; }
    std::puts("PHPX static values and request lifecycle passed");
    return 0;
}
