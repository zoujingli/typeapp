#!/usr/bin/env bash
# 重新编译 PHP 内置模块与 PHPX；动态宿主仅运行 TypePHP，不进入部署程序。
set -euo pipefail
trap 'task_status=$?; echo "静态 SDK 制备在第 ${LINENO} 行失败（退出码 ${task_status}），保留本轮目录供诊断。" >&2' ERR

task_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
: "${PHP_HOME:?需要锁定的构建宿主 PHP SDK}"
[[ "$(uname -s)" == Darwin && "$(uname -m)" == arm64 ]] || { echo '此制备入口只适用于 macOS ARM64。' >&2; exit 1; }
[[ $# == 2 && "$1" == /* && "$2" == /* ]] || { echo '用法：prepare-static-macos.sh <新的工作目录> <PostgreSQL静态SDK根目录>' >&2; exit 1; }
task_work="$1"
task_pgsql="$2"
[[ ! -e "$task_work" && ! -L "$task_work" ]] || { echo '工作目录必须尚不存在，不能覆盖已有SDK。' >&2; exit 1; }
for task_name in libpq.a libpgcommon_shlib.a libpgport_shlib.a; do
    [[ -f "$task_pgsql/lib/$task_name" ]] || { echo "缺少 PostgreSQL 静态归档：$task_name" >&2; exit 1; }
done
[[ "$("$task_pgsql/bin/pg_config" --version)" == 'PostgreSQL 17.11' ]] || { echo '需要固定 PostgreSQL 17.11。' >&2; exit 1; }
mkdir -p "$task_work/src" "$task_work/downloads"
task_work="$(cd "$task_work" && pwd)"
task_sdk="$task_work/sdk"
task_php="$PHP_HOME/bin/php"
export MACOSX_DEPLOYMENT_TARGET="${TYPE_STATIC_MINIMUM_MACOS:-15.0}"
[[ "$MACOSX_DEPLOYMENT_TARGET" =~ ^[1-9][0-9]*\.[0-9]+(\.[0-9]+)?$ ]] || { echo 'macOS 最低版本格式无效。' >&2; exit 1; }

source_archive() {
    local task_supplied="$1" task_name="$2" task_url="$3" task_sha="$4" task_file
    task_file="${task_supplied:-$task_work/downloads/$task_name}"
    if [[ ! -f "$task_file" ]]; then
        [[ -z "$task_supplied" ]] || { echo "指定的源码归档不存在：$task_name" >&2; return 1; }
        curl --fail --location --silent --show-error --retry 3 "$task_url" --output "$task_file"
    fi
    printf '%s  %s\n' "$task_sha" "$task_file" | shasum -a 256 --check >&2
    tar -xf "$task_file" -C "$task_work/src"
}
source_archive "${TYPE_STATIC_PHP_ARCHIVE:-}" php-8.5.10.tar.xz https://www.php.net/distributions/php-8.5.10.tar.xz 6a8bebaa4d5a979a38db29a9373e9851f60c6b11f72172c585947e78f3081957
source_archive "${TYPE_STATIC_REDIS_ARCHIVE:-}" redis-6.3.0.tgz https://pecl.php.net/get/redis-6.3.0.tgz 0d5141f634bd1db6c1ddcda053d25ecf2c4fc1c395430d534fd3f8d51dd7f0b5
source_archive "${TYPE_STATIC_SWOOLE_ARCHIVE:-}" swoole.tar.gz https://codeload.github.com/swoole/swoole-src/tar.gz/0f3bee2f0ed8704ce33a336e7feabb0115411dd7 b830fc102797143dd94a7603400a203e0d2228bd222c71a12c27d6fe62dac3ea
task_source="$task_work/src/php-8.5.10"
mv "$task_work/src/redis-6.3.0" "$task_source/ext/redis"
mv "$task_work/src/swoole-src-0f3bee2f0ed8704ce33a336e7feabb0115411dd7" "$task_source/ext/swoole"
# Composer 锁定的原始 PHPX 复制到任务内适配；不改共享 vendor 或宿主 SDK。
cp -R "$task_root/vendor/swoole/phpx" "$task_work/src/phpx"
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

task_os_sdk="$(xcrun --show-sdk-path)"
task_openssl="$(brew --prefix openssl@3)"
task_gmp="$(brew --prefix gmp)"
task_mpfr="$(brew --prefix mpfr)"
task_pkgconfig="$task_pgsql/lib/pkgconfig"
for task_formula in openssl@3 sqlite c-ares brotli; do
    task_prefix="$(brew --prefix "$task_formula")"
    task_pkgconfig="$task_pkgconfig:$task_prefix/lib/pkgconfig"
done
export PKG_CONFIG_PATH="$task_pkgconfig"
(
    cd "$task_source"
    ./buildconf --force
    ./configure --prefix="$task_sdk" --disable-all --disable-cli --disable-cgi --disable-phpdbg \
        --enable-embed=static --enable-zts --enable-zend-signals \
        --enable-filter --enable-tokenizer --enable-ctype --enable-mbstring --disable-mbregex --enable-session \
        --with-libxml --enable-dom --enable-xml --enable-simplexml --enable-xmlreader --enable-xmlwriter \
        --enable-phar --enable-pdo --enable-mysqlnd --with-pdo-mysql=mysqlnd --with-pdo-pgsql \
        --with-pdo-sqlite --with-sqlite3 --enable-pcntl --enable-posix --enable-sockets \
        --with-openssl --with-curl --with-zlib --with-iconv="$task_os_sdk/usr" \
        --enable-redis --enable-swoole --enable-swoole-thread --enable-cares \
        --enable-swoole-pgsql --enable-swoole-sqlite --enable-swoole-curl --with-openssl-dir="$task_openssl" \
        "LIBXML_CFLAGS=-I$task_os_sdk/usr/include/libxml2" LIBXML_LIBS=-lxml2 \
        "CURL_CFLAGS=-I$task_os_sdk/usr/include" CURL_LIBS=-lcurl
    make -j2
    make install
) >&2
# PHP 8.5.10 已包含该 C++ 转换；旧 SDK 才需要补丁，禁止 patch 自动反向应用。
if ! grep -Fq 'char *base = (char *) ecalloc(1, ops->context_size + align);' "$task_sdk/include/php/ext/hash/php_hash.h"; then
    patch --forward --batch --directory="$task_sdk/include/php" --strip=1 < "$task_root/tools/php-hash-cxx.patch" >&2
fi
cmake -S "$task_work/src/phpx/sapi-static" -B "$task_work/phpx-build" \
    -DPHPX_ROOT="$task_work/src/phpx" -DPHPX_PHP_PREFIX="$task_sdk" -DCMAKE_BUILD_TYPE=Release \
    "-DCMAKE_CXX_FLAGS=-I$task_gmp/include -I$task_mpfr/include" "-DCMAKE_OSX_DEPLOYMENT_TARGET=$MACOSX_DEPLOYMENT_TARGET" >&2
cmake --build "$task_work/phpx-build" --parallel 2 >&2
cp "$task_work/phpx-build/lib/libphpx.a" "$task_sdk/lib/libphpx.a"
"$task_php" "$task_root/tools/static-runtime-manifest.php" "$task_work" "$task_pgsql" >&2
printf '%s\n' "$task_sdk/manifest.json"
