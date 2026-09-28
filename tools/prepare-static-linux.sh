#!/usr/bin/env bash
# Linux x64/ARM64 的目标静态 SDK；宿主 PHP 仅执行构建，不进入交付文件。
set -euo pipefail
trap 'echo "Linux 静态 SDK 制备失败（第 ${LINENO} 行，退出码 $?）；保留任务目录供诊断。" >&2' ERR

task_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
: "${PHP_HOME:?需要锁定的构建宿主 PHP SDK}"
[[ "$(uname -s)" == Linux && "$(uname -m)" =~ ^(x86_64|aarch64)$ ]] || { echo '只接受 Linux x64/ARM64 原生构建。' >&2; exit 1; }
[[ $# == 1 && "$1" == /* && ! -e "$1" && ! -L "$1" ]] || { echo '用法：prepare-static-linux.sh <尚不存在的绝对工作目录>' >&2; exit 1; }
task_php="$PHP_HOME/bin/php"
[[ "$("$task_php" -n -r 'echo PHP_VERSION, PHP_ZTS ? " zts" : " nts";')" == '8.5.10 zts' ]] || { echo '需要 PHP 8.5.10 ZTS 构建宿主。' >&2; exit 1; }
for task_tool in gcc g++ cmake make pkg-config autoconf bison re2c flex curl patch; do
    command -v "$task_tool" >/dev/null || { echo "缺少静态 SDK 构建工具：$task_tool" >&2; exit 1; }
done
task_work="$1"
mkdir -p "$task_work/src" "$task_work/downloads"
task_work="$(cd "$task_work" && pwd)"
task_sdk="$task_work/sdk"
task_dependencies="$task_work/dependencies"

# 接受已下载的同摘要源码，离线复用时不再次访问远端。
source_archive() {
    local task_supplied="$1" task_name="$2" task_url="$3" task_sha="$4" task_file
    task_file="${task_supplied:-$task_work/downloads/$task_name}"
    if [[ ! -f "$task_file" ]]; then
        [[ -z "$task_supplied" ]] || { echo "指定源码不存在：$task_name" >&2; return 1; }
        curl --fail --location --silent --show-error --retry 3 "$task_url" --output "$task_file"
    fi
    printf '%s  %s\n' "$task_sha" "$task_file" | sha256sum --check >&2
    tar -xf "$task_file" -C "$task_work/src"
}
source_archive "${TYPE_STATIC_PHP_ARCHIVE:-}" php-8.5.10.tar.xz https://www.php.net/distributions/php-8.5.10.tar.xz 6a8bebaa4d5a979a38db29a9373e9851f60c6b11f72172c585947e78f3081957
source_archive "${TYPE_STATIC_REDIS_ARCHIVE:-}" redis-6.3.0.tgz https://pecl.php.net/get/redis-6.3.0.tgz 0d5141f634bd1db6c1ddcda053d25ecf2c4fc1c395430d534fd3f8d51dd7f0b5
source_archive "${TYPE_STATIC_SWOOLE_ARCHIVE:-}" swoole.tar.gz https://codeload.github.com/swoole/swoole-src/tar.gz/0f3bee2f0ed8704ce33a336e7feabb0115411dd7 b830fc102797143dd94a7603400a203e0d2228bd222c71a12c27d6fe62dac3ea
source_archive "${TYPE_STATIC_PGSQL_ARCHIVE:-}" postgresql-17.11.tar.bz2 https://ftp.postgresql.org/pub/source/v17.11/postgresql-17.11.tar.bz2 dd27f2b3c59e73ed14aa3324901242bf69a032a6347805f274e6260322d42979
source_archive "${TYPE_STATIC_CURL_ARCHIVE:-}" curl-8.22.0.tar.xz https://github.com/curl/curl/releases/download/curl-8_22_0/curl-8.22.0.tar.xz f7ef3ae8a22e521f289803fe93543eb64c329b58aa73a9e224dfd915a2a5f4f7
source_archive "${TYPE_STATIC_CARES_ARCHIVE:-}" c-ares-1.34.8.tar.gz https://github.com/c-ares/c-ares/releases/download/v1.34.8/c-ares-1.34.8.tar.gz c222b6d681096f9444d2c4863d2c1174019e27cacca0a4a5c114d36dd7d7bf78

# Ubuntu 的 libc-ares-dev 不提供静态归档；从固定官方源码生成，不能偷偷链接 .so。
cmake -S "$task_work/src/c-ares-1.34.8" -B "$task_work/cares-build" \
    -DCMAKE_INSTALL_PREFIX="$task_dependencies" -DCMAKE_INSTALL_LIBDIR=lib -DCMAKE_BUILD_TYPE=Release \
    -DCARES_STATIC=ON -DCARES_SHARED=OFF -DCARES_BUILD_TOOLS=OFF -DCMAKE_POSITION_INDEPENDENT_CODE=ON >&2
cmake --build "$task_work/cares-build" --parallel 2 >&2
cmake --install "$task_work/cares-build" >&2

# libpq 和 curl 使用明确功能集，避免发行版共享客户端隐式引入 LDAP/GSS/SSH 动态库。
(
    cd "$task_work/src/postgresql-17.11"
    ./configure --prefix="$task_dependencies" --without-readline --without-icu --without-zlib --without-gssapi --without-ldap --with-ssl=openssl --with-pic
    make -C src/interfaces/libpq -j2
    make -C src/interfaces/libpq install
    make -C src/bin/pg_config -j2
    make -C src/bin/pg_config install
    make -C src/include install
    cp src/common/libpgcommon_shlib.a src/port/libpgport_shlib.a "$task_dependencies/lib/"
) >&2
(
    cd "$task_work/src/curl-8.22.0"
    ./configure --prefix="$task_dependencies" --disable-shared --enable-static --with-pic --with-openssl \
        --enable-ares="$task_dependencies" --with-brotli --with-nghttp2 --without-zstd --without-libpsl --without-libidn2 \
        --without-libssh2 --without-libssh --without-gssapi --disable-ldap --disable-ldaps
    make -j2
    make install
) >&2

task_source="$task_work/src/php-8.5.10"
mv "$task_work/src/redis-6.3.0" "$task_source/ext/redis"
mv "$task_work/src/swoole-src-0f3bee2f0ed8704ce33a336e7feabb0115411dd7" "$task_source/ext/swoole"
cp -R "$task_root/vendor/swoole/phpx" "$task_work/src/phpx"
# shellcheck disable=SC2016
"$task_php" -n -r '
require $argv[1] . "/vendor/autoload.php";
if (Composer\InstalledVersions::getReference("swoole/phpx") !== "0dfa613d2057dcd4aa319ec9b6816f68df2403e4") {
    throw new RuntimeException("PHPX 来源与锁定版本不符");
}
$report = [];
foreach (["Thread", "Http", "Socket", "Static"] as $kind) {
    $class = "Type\\Build\\Swoole" . $kind . "Source";
    $report[$kind] = (new $class())->apply($argv[2] . "/ext/swoole");
}
$report["Tls"] = (new Type\Build\SwooleSocketSource())->applyTls($argv[2] . "/ext/swoole");
$report["PHPX"] = (new Type\Build\PhpxThreadSource())->apply($argv[3]);
file_put_contents($argv[4], json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
' "$task_root" "$task_source" "$task_work/src/phpx" "$task_work/adaptations.json"
export PKG_CONFIG_PATH="$task_dependencies/lib/pkgconfig${PKG_CONFIG_PATH:+:$PKG_CONFIG_PATH}"
# Swoole 官方 nghttp2-dir 开关让两个 HTTP/2 调用者共用同一归档，避免内置副本重复定义。
(
    cd "$task_source"
    ./buildconf --force
    ./configure --prefix="$task_sdk" --disable-all --disable-cli --disable-cgi --disable-phpdbg \
        --enable-embed=static --enable-zts --enable-zend-signals \
        --enable-filter --enable-tokenizer --enable-ctype --enable-mbstring --disable-mbregex --enable-session \
        --with-libxml --enable-dom --enable-xml --enable-simplexml --enable-xmlreader --enable-xmlwriter \
        --enable-phar --enable-pdo --enable-mysqlnd --with-pdo-mysql=mysqlnd --with-pdo-pgsql \
        --with-pdo-sqlite --with-sqlite3 --enable-pcntl --enable-posix --enable-sockets \
        --with-openssl --with-curl --with-zlib --with-iconv \
        --enable-redis --enable-swoole --enable-swoole-thread --enable-cares --with-nghttp2-dir=/usr \
        --enable-swoole-pgsql --enable-swoole-sqlite --enable-swoole-curl
    make -j2
    make install
) >&2
if ! grep -Fq 'char *base = (char *) ecalloc(1, ops->context_size + align);' "$task_sdk/include/php/ext/hash/php_hash.h"; then
    patch --forward --batch --directory="$task_sdk/include/php" --strip=1 < "$task_root/tools/php-hash-cxx.patch" >&2
fi
cmake -S "$task_work/src/phpx/sapi-static" -B "$task_work/phpx-build" \
    -DPHPX_ROOT="$task_work/src/phpx" -DPHPX_PHP_PREFIX="$task_sdk" -DCMAKE_BUILD_TYPE=Release >&2
cmake --build "$task_work/phpx-build" --parallel 2 >&2
cp "$task_work/phpx-build/lib/libphpx.a" "$task_sdk/lib/libphpx.a"
"$task_php" "$task_root/tools/static-runtime-manifest.php" "$task_work" "$task_dependencies" >&2
printf '%s\n' "$task_sdk/manifest.json"
