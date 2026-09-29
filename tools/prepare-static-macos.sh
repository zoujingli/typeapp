#!/usr/bin/env bash
# 重新编译 PHP 内置模块与 PHPX；动态宿主仅运行 TypePHP，不进入部署程序。
set -euo pipefail
trap 'echo "静态 SDK 制备在第 ${LINENO} 行失败（退出码 $?），保留本轮目录供诊断。" >&2' ERR

task_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
: "${PHP_HOME:?需要锁定的构建宿主 PHP SDK}"
[[ "$(uname -s)" == Darwin && "$(uname -m)" == arm64 ]] || { echo '此制备入口只适用于 macOS ARM64。' >&2; exit 1; }
[[ ( $# == 1 || $# == 2 || $# == 3 ) && "$1" == /* ]] || { echo '用法：prepare-static-macos.sh <新的工作目录> [PostgreSQL静态SDK根目录] [sqlite|mysql|pgsql|all]' >&2; exit 1; }
task_work="$1"
task_pgsql="${2:-$task_work/dependencies}"
task_profile="${3:-all}"
if [[ $# == 2 && "$2" =~ ^(sqlite|mysql|pgsql|all)$ ]]; then
    task_profile="$2"
    task_pgsql="$task_work/dependencies"
fi
case "$task_profile" in sqlite|mysql|pgsql|all) ;; *) echo 'profile 必须是 sqlite、mysql、pgsql 或 all。' >&2; exit 1 ;; esac
task_features="$("$PHP_HOME/bin/php" -n "$task_root/tools/build-profile.php" "$task_profile")"
export TYPEAPP_BUILD_FEATURES="$task_features"
[[ "$task_features" =~ ^[a-z][a-z0-9_-]*(,[a-z][a-z0-9_-]*)*$ ]] || { echo 'TYPEAPP_BUILD_FEATURES 必须是逗号分隔的功能名称。' >&2; exit 1; }
task_redis_enabled=0
case ",$task_features," in *,redis,*) task_redis_enabled=1 ;; esac
[[ ! -e "$task_work" && ! -L "$task_work" ]] || { echo '工作目录必须尚不存在，不能覆盖已有SDK。' >&2; exit 1; }
if [[ ( "$task_profile" == all || "$task_profile" == pgsql ) && $# -ge 2 && ! ( $# == 2 && "$2" =~ ^(sqlite|mysql|pgsql|all)$ ) ]]; then
    for task_name in libpq.a libpgcommon_shlib.a libpgport_shlib.a; do
        [[ -f "$task_pgsql/lib/$task_name" ]] || { echo "缺少 PostgreSQL 静态归档：$task_name" >&2; exit 1; }
    done
    [[ "$("$task_pgsql/bin/pg_config" --version)" == 'PostgreSQL 17.11' ]] || { echo '需要固定 PostgreSQL 17.11。' >&2; exit 1; }
fi
mkdir -p "$task_work/src" "$task_work/downloads"
task_work="$(cd "$task_work" && pwd)"
task_sdk="$task_work/sdk"
task_php="$PHP_HOME/bin/php"
if [[ "$task_profile" != all && "$task_profile" != pgsql ]]; then
    mkdir -p "$task_pgsql/lib"
fi
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
if [[ "$task_redis_enabled" == 1 ]]; then
    source_archive "${TYPE_STATIC_REDIS_ARCHIVE:-}" redis-6.3.0.tgz https://pecl.php.net/get/redis-6.3.0.tgz 0d5141f634bd1db6c1ddcda053d25ecf2c4fc1c395430d534fd3f8d51dd7f0b5
fi
source_archive "${TYPE_STATIC_SWOOLE_ARCHIVE:-}" swoole.tar.gz https://codeload.github.com/swoole/swoole-src/tar.gz/0f3bee2f0ed8704ce33a336e7feabb0115411dd7 b830fc102797143dd94a7603400a203e0d2228bd222c71a12c27d6fe62dac3ea
task_source="$task_work/src/php-8.5.10"
if [[ "$task_redis_enabled" == 1 ]]; then
    mv "$task_work/src/redis-6.3.0" "$task_source/ext/redis"
fi
mv "$task_work/src/swoole-src-0f3bee2f0ed8704ce33a336e7feabb0115411dd7" "$task_source/ext/swoole"
# Composer 锁定的原始 PHPX 复制到任务内适配；不改共享 vendor 或宿主 SDK。
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

task_os_sdk="$(xcrun --show-sdk-path)"
task_openssl="$(brew --prefix openssl@3)"
task_gmp="$(brew --prefix gmp)"
task_mpfr="$(brew --prefix mpfr)"
task_pkgconfig="$task_pgsql/lib/pkgconfig"
for task_formula in openssl@3 c-ares brotli; do
    task_prefix="$(brew --prefix "$task_formula")"
    task_pkgconfig="$task_pkgconfig:$task_prefix/lib/pkgconfig"
done
if [[ "$task_profile" == all || "$task_profile" == sqlite ]]; then
    task_prefix="$(brew --prefix sqlite)"
    task_pkgconfig="$task_pkgconfig:$task_prefix/lib/pkgconfig"
fi
export PKG_CONFIG_PATH="$task_pkgconfig"
# CI 不复用开发机已有的 PostgreSQL 归档；在声明的最低系统版本上构建同一源码。
if [[ "$task_profile" == all || "$task_profile" == pgsql ]]; then
    # 传入第二参数为 profile 时，PostgreSQL 仍需在任务内从固定源码生成；只有显式
    # 传入第三参数形式的 SDK 根目录才复用已验证的归档。
    if [[ "$task_pgsql" == "$task_work/dependencies" ]]; then
        source_archive "${TYPE_STATIC_PGSQL_ARCHIVE:-}" postgresql-17.11.tar.bz2 https://ftp.postgresql.org/pub/source/v17.11/postgresql-17.11.tar.bz2 dd27f2b3c59e73ed14aa3324901242bf69a032a6347805f274e6260322d42979
        (
            cd "$task_work/src/postgresql-17.11"
            # PostgreSQL 17 的 OpenSSL 检查不读取 pkg-config；keg-only 安装必须显式传入头文件和链接目录。
            ./configure --prefix="$task_pgsql" --without-readline --without-icu --without-zlib --without-gssapi --without-ldap --with-ssl=openssl \
                "CPPFLAGS=-I$task_openssl/include" "LDFLAGS=-L$task_openssl/lib"
            make -C src/interfaces/libpq -j2
            make -C src/interfaces/libpq install
            make -C src/bin/pg_config -j2
            make -C src/bin/pg_config install
            make -C src/include install
            cp src/common/libpgcommon_shlib.a src/port/libpgport_shlib.a "$task_pgsql/lib/"
        ) >&2
    fi
fi
(
    cd "$task_source"
    ./buildconf --force
    task_db_flags=()
    if [[ "$task_profile" == all || "$task_profile" == mysql ]]; then task_db_flags+=(--enable-mysqlnd --with-pdo-mysql=mysqlnd); fi
    if [[ "$task_profile" == all || "$task_profile" == pgsql ]]; then task_db_flags+=(--with-pdo-pgsql --enable-swoole-pgsql); fi
    if [[ "$task_profile" == all || "$task_profile" == sqlite ]]; then task_db_flags+=(--with-pdo-sqlite --enable-swoole-sqlite); fi
    # 系统 Bash 3 在 set -u 下拒绝展开空数组，必需的 Swoole 开关始终存在。
    task_feature_flags=(--enable-swoole --enable-swoole-thread --enable-cares)
    # 全量 SDK 保留历史接口；应用 profile 只需要 PDO、数据库会话及编译期解析。
    if [[ "$task_profile" == all ]]; then task_feature_flags+=(--with-sqlite3 --enable-session --enable-tokenizer); fi
    if [[ "$task_redis_enabled" == 1 ]]; then
        task_feature_flags+=(--enable-redis)
        if [[ "$task_profile" != all ]]; then task_feature_flags+=(--disable-redis-session); fi
    fi
    # RuntimeIni 固定关闭 OPcache；AOT 程序不需要另带 PHP JIT 编译器。
    CFLAGS="${CFLAGS:-} -O2 -g0 -ffunction-sections -fdata-sections" \
    CXXFLAGS="${CXXFLAGS:-} -O2 -g0 -ffunction-sections -fdata-sections" \
    LDFLAGS="${LDFLAGS:-} -Wl,-dead_strip" \
    ./configure --prefix="$task_sdk" --disable-all --disable-cli --disable-cgi --disable-phpdbg --disable-opcache-jit \
        --enable-embed=static --enable-zts --enable-zend-signals \
        --enable-filter --enable-ctype --enable-mbstring --disable-mbregex \
        --enable-pdo "${task_db_flags[@]}" --enable-pcntl --enable-posix --enable-sockets \
        --with-openssl --with-curl --with-zlib --with-iconv="$task_os_sdk/usr" \
        "${task_feature_flags[@]}" \
        --enable-swoole-curl --with-openssl-dir="$task_openssl" \
        "CURL_CFLAGS=-I$task_os_sdk/usr/include" CURL_LIBS=-lcurl
    # Swoole 的 configure 会重设 CFLAGS/CXXFLAGS，发布参数须在实际 make
    # 编译命令末尾生效；保留 PHP 自身的 ABI、线程与可见性参数。
    task_release_flags=(-j2 'EXTRA_CFLAGS=-O2 -g0 -ffunction-sections -fdata-sections' 'EXTRA_CXXFLAGS=-O2 -g0 -ffunction-sections -fdata-sections')
    make "${task_release_flags[@]}"
    make "${task_release_flags[@]}" install
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
TYPEAPP_BUILD_FEATURES="$task_features" "$task_php" "$task_root/tools/static-runtime-manifest.php" "$task_work" "$task_pgsql" "$task_profile" >&2
printf '%s\n' "$task_sdk/manifest.json"
