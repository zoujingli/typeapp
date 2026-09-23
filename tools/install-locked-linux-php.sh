#!/usr/bin/env bash
# 在 Linux CI 上安装工具链锁定的 PHP 8.5.10 ZTS + embed。
# setup-php 对 ZTS 只认主版本并拉取 php-builder 当前补丁（现为 8.5.11），
# 与 macOS 固定 Homebrew 瓶同理，这里从官方源码构建锁定补丁。
# 标准输出仅打印安装前缀；构建日志一律写入 stderr，避免被命令替换吞进 PATH。
set -euo pipefail

task_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
task_lock_php='8.5.10'
task_archive_sha='6a8bebaa4d5a979a38db29a9373e9851f60c6b11f72172c585947e78f3081957'
task_prefix="${1:-$task_root/.cache/php-sdk/${task_lock_php}-zts}"
task_work="${TYPE_LOCKED_PHP_WORK:-$task_root/.cache/php-sdk-build/${task_lock_php}-zts}"

if [[ "$(uname -s)" != Linux ]]; then
  printf '仅用于 Linux 锁定 PHP 安装\n' >&2
  exit 1
fi

if [[ -x "$task_prefix/bin/php" && -x "$task_prefix/bin/php-config" ]]; then
  # 清理误写入可缓存前缀的受控 Swoole 声明，避免启动警告污染版本探测。
  rm -f "$task_prefix/etc/php.d/zz-typeapp-swoole.ini"
  printf 'memory_limit=2G\ndisplay_errors=1\n' > "$task_prefix/etc/php.d/zz-typeapp-cli.ini"
  task_version="$("$task_prefix/bin/php" -r 'echo PHP_VERSION, PHP_ZTS ? " zts" : " nts";' 2>/dev/null)"
  if [[ "$task_version" == "${task_lock_php} zts" && -f "$task_prefix/lib/libphp.so" \
    && -f "$("$task_prefix/bin/php-config" --extension-dir)/curl.so" ]] \
    && "$task_prefix/bin/php" -r 'exit(extension_loaded("redis") && phpversion("redis")==="6.3.0" && extension_loaded("curl") && extension_loaded("pdo_mysql") && extension_loaded("pdo_pgsql") && extension_loaded("pdo_sqlite") ? 0 : 1);' 2>/dev/null; then
    printf '%s\n' "$task_prefix"
    exit 0
  fi
fi

mkdir -p "$task_work/src" "$task_prefix"
task_archive="$task_work/php-${task_lock_php}.tar.xz"
if [[ ! -f "$task_archive" ]]; then
  curl --fail --location --silent --show-error --retry 3 \
    "https://www.php.net/distributions/php-${task_lock_php}.tar.xz" \
    --output "$task_archive" >&2
fi
printf '%s  %s\n' "$task_archive_sha" "$task_archive" | sha256sum --check >&2

if [[ ! -d "$task_work/src/php-${task_lock_php}" ]]; then
  tar -xJf "$task_archive" -C "$task_work/src" >&2
fi

sudo apt-get update >&2
sudo apt-get install --yes --no-install-recommends \
  build-essential autoconf bison re2c pkg-config \
  libxml2-dev libssl-dev libcurl4-openssl-dev libsqlite3-dev \
  libpq-dev libonig-dev libzip-dev zlib1g-dev >&2

(
  cd "$task_work/src/php-${task_lock_php}"
  # 与本仓选定 SDK 脚本同口径：ZTS + shared embed + 验收所需 PDO/网络扩展。
  ./configure --prefix="$task_prefix" --disable-all --enable-cli --disable-cgi --disable-phpdbg \
    --enable-embed=shared --enable-zts \
    --with-config-file-path="$task_prefix/etc" --with-config-file-scan-dir="$task_prefix/etc/php.d" \
    --enable-filter --enable-tokenizer --enable-ctype --enable-mbstring --disable-mbregex \
    --enable-session \
    --with-libxml --enable-dom --enable-xml --enable-simplexml --enable-xmlreader --enable-xmlwriter \
    --enable-phar --enable-pdo --enable-mysqlnd --with-pdo-mysql=mysqlnd --with-pdo-pgsql \
    --with-pdo-sqlite --with-sqlite3 --enable-pcntl --enable-posix --enable-sockets \
    --with-openssl --with-curl=shared --with-zlib --with-zip --with-iconv
  make -j"$(nproc)"
  make install
) >&2
mkdir -p "$task_prefix/etc/php.d"
# curl 以 shared 产出，供控制器 / 独立消费显式声明模块路径。
if [[ -f "$("$task_prefix/bin/php-config" --extension-dir)/curl.so" ]]; then
  printf 'extension=curl.so\n' > "$task_prefix/etc/php.d/curl.ini"
fi
printf 'memory_limit=2G\ndisplay_errors=1\n' > "$task_prefix/etc/php.d/zz-typeapp-cli.ini"

task_version="$("$task_prefix/bin/php" -r 'echo PHP_VERSION, PHP_ZTS ? " zts" : " nts";')"
[[ "$task_version" == "${task_lock_php} zts" ]] || {
  printf '锁定 PHP 构建结果不符：%s\n' "$task_version" >&2
  exit 1
}
[[ -f "$task_prefix/lib/libphp.so" ]] || {
  printf '锁定 PHP 缺少 libphp.so\n' >&2
  exit 1
}
[[ -f "$("$task_prefix/bin/php-config" --extension-dir)/curl.so" ]] || {
  printf '锁定 PHP 缺少 curl.so\n' >&2
  exit 1
}

# pecl/redis 用刚装好的 phpize 构建锁定补丁上的 Redis 6.3.0。
if ! "$task_prefix/bin/php" -m 2>/dev/null | grep -qx redis; then
  task_redis_src="$task_work/redis-6.3.0"
  if [[ ! -d "$task_redis_src" ]]; then
    curl --fail --location --silent --show-error --retry 3 \
      https://pecl.php.net/get/redis-6.3.0.tgz --output "$task_work/redis-6.3.0.tgz" >&2
    tar -xzf "$task_work/redis-6.3.0.tgz" -C "$task_work" >&2
  fi
  (
    cd "$task_redis_src"
    "$task_prefix/bin/phpize"
    ./configure --with-php-config="$task_prefix/bin/php-config"
    make -j"$(nproc)"
    make install
  ) >&2
  printf 'extension=redis.so\n' > "$task_prefix/etc/php.d/redis.ini"
fi

"$task_prefix/bin/php" -r 'if (PHP_VERSION !== "8.5.10" || !PHP_ZTS || !extension_loaded("curl") || !extension_loaded("pdo_mysql") || !extension_loaded("pdo_pgsql") || !extension_loaded("pdo_sqlite") || !extension_loaded("redis") || phpversion("redis") !== "6.3.0") { fwrite(STDERR, "锁定 PHP 扩展不完整\n"); exit(1); }' >&2

printf '%s\n' "$task_prefix"
