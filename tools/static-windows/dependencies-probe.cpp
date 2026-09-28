// 实际引用每组依赖的公开接口，供 PE 导入审计确认链接器没有选择导入库。
// c-ares 引入 Windows 头；禁用其 min/max 宏，保持 GMP 的 numeric_limits 接口。
#define NOMINMAX
#define CURL_STATICLIB
#define CARES_STATICLIB
#define NGHTTP2_STATICLIB
// MSVC 不提供 POSIX ssize_t；使用上游开关隐藏已弃用接口，保留 nghttp2_ssize 接口。
#define NGHTTP2_NO_SSIZE_T
#define LIBXML_STATIC
#define LIBICONV_STATIC
#include <ares.h>
#include <brotli/decode.h>
#include <curl/curl.h>
#include <gmpxx.h>
#include <iconv.h>
#include <libpq-fe.h>
#include <libxml/parser.h>
#include <mpfr.h>
#include <nghttp2/nghttp2.h>
#include <openssl/ssl.h>
#include <sqlite3.h>
#include <zlib.h>
#include <zstd.h>
#include <cstdio>

int main() {
    iconv_t conversion = iconv_open("UTF-8", "UTF-8");
    if (conversion == reinterpret_cast<iconv_t>(-1)) { return 9; }
    if (iconv_close(conversion) != 0) { return 10; }
    mpz_class number("12345678901234567890");
    mpz_class squared = number * number;
    if (squared.get_str() != "152415787532388367501905199875019052100") { return 1; }
    mpfr_t root;
    mpfr_init2(root, 128);
    mpfr_set_ui(root, 144, MPFR_RNDN);
    mpfr_sqrt(root, root, MPFR_RNDN);
    const bool correct = mpfr_cmp_ui(root, 12) == 0;
    mpfr_clear(root);
    if (!correct) { return 2; }
    SSL_CTX *context = SSL_CTX_new(TLS_client_method());
    if (context == nullptr) { return 3; }
    SSL_CTX_free(context);
    sqlite3 *database = nullptr;
    if (sqlite3_open(":memory:", &database) != SQLITE_OK) { return 4; }
    const int status = sqlite3_exec(database, "CREATE TABLE probe(id INTEGER PRIMARY KEY); INSERT INTO probe VALUES(1);", nullptr, nullptr, nullptr);
    sqlite3_close(database);
    if (status != SQLITE_OK) { return 5; }
    if (ares_library_init(ARES_LIB_INIT_ALL) != ARES_SUCCESS) { return 6; }
    ares_library_cleanup();
    xmlInitParser();
    xmlDocPtr document = xmlReadMemory("<probe/>", 8, "probe.xml", nullptr, XML_PARSE_NONET);
    if (document == nullptr) { xmlCleanupParser(); return 7; }
    xmlFreeDoc(document);
    xmlCleanupParser();
    if (curl_global_init(CURL_GLOBAL_DEFAULT) != CURLE_OK) { return 8; }
    std::printf("curl=%s\npgsql=%d\nsqlite=%s\nzlib=%s\nzstd=%u\nbrotli=%u\nnghttp2=%s\n",
        curl_version(), PQlibVersion(), sqlite3_libversion(), zlibVersion(), ZSTD_versionNumber(),
        BrotliDecoderVersion(), nghttp2_version(0)->version_str);
    curl_global_cleanup();
    return 0;
}
