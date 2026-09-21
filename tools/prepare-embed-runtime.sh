#!/usr/bin/env bash
set -euo pipefail

task_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
task_sdk="${PHP_HOME:?需要锁定 PHP SDK}"
task_version="$("$task_sdk/bin/php-config" --version)"
test "$task_version" = 8.5.10
task_work="$task_root/.cache/embed-runtime/$task_version"
mkdir -p "$task_work/php.d"
read -r -a task_includes <<< "$("$task_sdk/bin/php-config" --includes)"
cc "${task_includes[@]}" "$task_root/tools/embed-runtime-probe.c" -L"$task_sdk/lib" -Wl,-rpath,"$task_sdk/lib" -lphp -o "$task_work/probe"
php "$task_root/tools/configure-embed-runtime.php" "$task_work" base >/dev/null

# 仅退出码不足以发现重复模块等启动警告；实际 embed 的两个输出流都纳入门禁。
probe_modules() {
  local task_output
  if ! task_output="$(PHPRC="$task_work/php.ini" PHP_INI_SCAN_DIR="$task_work/php.d" "$task_work/probe" 2>"$task_work/probe.stderr")"; then
    cat "$task_work/probe.stderr" >&2
    printf 'embed 模块探测失败\n' >&2
    return 1
  fi
  if [[ -s "$task_work/probe.stderr" ]]; then
    cat "$task_work/probe.stderr" >&2
    printf 'embed 启动出现警告，拒绝生成可用运行配置\n' >&2
    return 1
  fi
  printf '%s\n' "$task_output"
}

task_modules="$(probe_modules)"
case "$task_modules" in
  '1 1 1 1') printf '%s\n' "$task_work/php.ini"; exit 0;;
  '0 0 0 0') ;;
  *) printf 'embed 信号模块与函数表不一致：%s\n' "$task_modules" >&2; exit 1;;
esac

# 官方 CLI 专有模块不一定静态编入 embed；使用同一版本源码构建共享 pcntl。
if [[ ! -f "$task_work/pcntl.so" ]]; then
  task_archive="$task_work/php-8.5.10.tar.xz"
  curl --fail --location --silent --show-error --retry 3 https://www.php.net/distributions/php-8.5.10.tar.xz --output "$task_archive"
  printf '%s  %s\n' 6a8bebaa4d5a979a38db29a9373e9851f60c6b11f72172c585947e78f3081957 "$task_archive" | sha256sum --check >&2
  tar -xJf "$task_archive" -C "$task_work" php-8.5.10/ext/pcntl
  task_configure="$task_work/php-8.5.10/ext/pcntl"
  (cd "$task_configure" && phpize && ./configure --with-php-config="$task_sdk/bin/php-config" --enable-pcntl=shared && make -j2) >&2
  cp "$task_configure/modules/pcntl.so" "$task_work/pcntl.so"
fi
php "$task_root/tools/configure-embed-runtime.php" "$task_work" shared >/dev/null

# 原生运行配置必须使用本轮核验的 Swoole 与 curl 模块。控制器配置可能仍带有
# setup-php 的旧模块声明；清理后按依赖顺序追加，避免 embed 解析到错误的 ABI。
if [[ -n "${TYPE_CURL_MODULE:-}" || -n "${TYPE_SWOOLE_MODULE:-}" ]]; then
  task_clean="$task_work/php.ini.clean"
  awk 'tolower($0) !~ /^[[:space:]]*extension[[:space:]]*=.*(swoole|curl)/' "$task_work/php.ini" > "$task_clean"
  mv "$task_clean" "$task_work/php.ini"
  if [[ -n "${TYPE_CURL_MODULE:-}" ]]; then
    printf 'extension=%s\n' "$TYPE_CURL_MODULE" >> "$task_work/php.ini"
  fi
  if [[ -n "${TYPE_SWOOLE_MODULE:-}" ]]; then
    printf 'extension=%s\n' "$TYPE_SWOOLE_MODULE" >> "$task_work/php.ini"
  fi
fi
test "$(probe_modules)" = '1 1 1 1'
printf '%s\n' "$task_work/php.ini"
