#!/usr/bin/env bash
# 为内置 Swoole 准备同配置的 PHP ZTS + embed；Homebrew php-zts 关闭 Zend signals，不能混用。
# 依赖由调用者安装；标准输出只返回 SDK 前缀，构建日志写入 stderr。
set -euo pipefail

task_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
task_version=8.5.10
task_prefix="${1:-$task_root/.cache/macos-locked-php/$task_version-zts}"
task_work="${TYPE_LOCKED_PHP_WORK:-$task_root/.cache/macos-locked-php-build/$task_version-zts}"
[[ "$(uname -s)" == Darwin && "$(uname -m)" == arm64 ]] || { echo '此 SDK 入口仅支持 macOS ARM64。' >&2; exit 1; }
[[ "$task_prefix" == /* && "$task_work" == /* ]] || { echo 'SDK 与构建目录需要明确的绝对路径。' >&2; exit 1; }
export MACOSX_DEPLOYMENT_TARGET=15.0
task_sdk="$(xcrun --show-sdk-path)"
[[ -f "$task_sdk/usr/include/iconv.h" && -f "$task_sdk/usr/lib/libiconv.tbd" ]] || { echo 'macOS SDK 缺少系统 iconv 声明。' >&2; exit 1; }
# 每次都解析依赖前缀，避免从另一台 runner 的缓存继承已经不存在的 Cellar 路径。
task_packages=(libxml2 openssl@3 curl sqlite libpq libzip)
task_pkgconfig=""
for task_package in "${task_packages[@]}"; do
  task_dependency="$(brew --prefix "$task_package")"
  task_pkgconfig="$task_dependency/lib/pkgconfig${task_pkgconfig:+:$task_pkgconfig}"
done
export PKG_CONFIG_PATH="$task_pkgconfig${PKG_CONFIG_PATH:+:$PKG_CONFIG_PATH}"
task_identity="$( { shasum -a 256 "${BASH_SOURCE[0]}"; brew list --versions "${task_packages[@]}"; } | shasum -a 256 | awk '{print $1}')"

verify_sdk() {
  [[ -x "$task_prefix/bin/php" && -f "$task_prefix/lib/libphp.dylib" ]] || return 1
  grep -qx '#define ZEND_SIGNALS 1' "$task_prefix/include/php/main/php_config.h" || return 1
  otool -L "$task_prefix/lib/libphp.dylib" | awk '{print $1}' | grep -qx '/usr/lib/libiconv.2.dylib' || return 1
  env -u PHPRC PHP_INI_SCAN_DIR="$task_prefix/etc/php.d" "$task_prefix/bin/php" -r '
    if (PHP_VERSION !== "8.5.10" || !PHP_ZTS || PHP_DEBUG || PHP_INT_SIZE !== 8) { exit(1); }
    foreach (["ctype", "tokenizer", "dom", "mbstring", "iconv", "curl", "pdo_mysql", "pdo_pgsql", "pdo_sqlite", "redis", "sockets"] as $extension) {
        if (!extension_loaded($extension)) { exit(1); }
    }
    exit(phpversion("redis") === "6.3.0" && iconv("UTF-8", "UTF-8", "原生") === "原生" ? 0 : 1);' >&2
}
if [[ -f "$task_prefix/.build-identity" && "$(cat "$task_prefix/.build-identity")" == "$task_identity" ]] && verify_sdk; then
  printf '%s\n' "$task_prefix"
  exit 0
fi

mkdir -p "$task_work" "$task_prefix/etc/php.d"
task_archive="$task_work/php-$task_version.tar.xz"
if [[ ! -f "$task_archive" ]]; then
  curl --fail --location --silent --show-error --retry 3 "https://www.php.net/distributions/php-$task_version.tar.xz" --output "$task_archive"
fi
printf '%s  %s\n' 6a8bebaa4d5a979a38db29a9373e9851f60c6b11f72172c585947e78f3081957 "$task_archive" | shasum -a 256 --check >&2
if [[ ! -d "$task_work/php-$task_version" ]]; then tar -xJf "$task_archive" -C "$task_work"; fi
(
  cd "$task_work/php-$task_version"
  # GNU libiconv 与系统库同名但导出符号不同；目录包的库搜索路径会使 libpsl 等误载。
  # 通过当前 SDK 的头文件和 tbd 链接系统 iconv，不额外携带同名 GNU 运行库。
  ./configure --prefix="$task_prefix" --disable-all --enable-cli --disable-cgi --disable-phpdbg \
    --enable-embed=shared --enable-zts --enable-zend-signals \
    --with-config-file-path="$task_prefix/etc" --with-config-file-scan-dir="$task_prefix/etc/php.d" \
    --enable-filter --enable-tokenizer --enable-ctype --enable-mbstring --disable-mbregex --enable-session \
    --with-libxml --enable-dom --enable-xml --enable-simplexml --enable-xmlreader --enable-xmlwriter \
    --enable-phar --enable-pdo --enable-mysqlnd --with-pdo-mysql=mysqlnd --with-pdo-pgsql \
    --with-pdo-sqlite --with-sqlite3 --enable-pcntl --enable-posix --enable-sockets \
    --with-openssl --with-curl=shared --with-zlib --with-zip --with-iconv="$task_sdk/usr"
  make -j2
  make install
) >&2
printf 'extension=curl.so\n' > "$task_prefix/etc/php.d/curl.ini"
printf 'memory_limit=2G\ndisplay_errors=1\ndate.timezone=UTC\n' > "$task_prefix/etc/php.d/cli.ini"

task_redis_archive="$task_work/redis-6.3.0.tgz"
if [[ ! -f "$task_redis_archive" ]]; then
  curl --fail --location --silent --show-error --retry 3 https://pecl.php.net/get/redis-6.3.0.tgz --output "$task_redis_archive"
fi
printf '%s  %s\n' 0d5141f634bd1db6c1ddcda053d25ecf2c4fc1c395430d534fd3f8d51dd7f0b5 "$task_redis_archive" | shasum -a 256 --check >&2
if [[ ! -d "$task_work/redis-6.3.0" ]]; then tar -xzf "$task_redis_archive" -C "$task_work"; fi
(
  cd "$task_work/redis-6.3.0"
  "$task_prefix/bin/phpize"
  ./configure --with-php-config="$task_prefix/bin/php-config"
  make -j2
  make install
) >&2
printf 'extension=redis.so\n' > "$task_prefix/etc/php.d/redis.ini"
verify_sdk
printf '%s\n' "$task_identity" > "$task_prefix/.build-identity"
printf '%s\n' "$task_prefix"
