#!/usr/bin/env bash
set -euo pipefail

task_root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$task_root"
: "${PHP_HOME:?需要设置 PHP_HOME}"
: "${PHPX_HOME:?需要设置 PHPX_HOME}"

case "$(uname -s)" in
  Linux) task_platform=linux; task_library_extension=so;;
  Darwin) task_platform=macos; task_library_extension=dylib;;
  *) echo '此准备入口仅用于Linux/macOS原生工具链。' >&2; exit 1;;
esac
php tools/verify-toolchain.php "$task_platform"
test "$("$PHP_HOME/bin/php-config" --version)" = "$(php -r 'echo PHP_VERSION;')"
test -f "$PHP_HOME/lib/libphp.$task_library_extension"

# 与固定版本 TypePHP 的官方 CI 使用相同的 PHP 头文件修正。
task_include_dir="$("$PHP_HOME/bin/php-config" --include-dir)"
task_hash_header="$task_include_dir/ext/hash/php_hash.h"
if grep -Fq 'char *base = ecalloc(' "$task_hash_header"; then
    if test -w "$task_hash_header"; then
        patch --directory="$task_include_dir" --strip=1 < tools/php-hash-cxx.patch
    else
        # 当前用户读取仓库补丁，sudo只用于选定SDK的头文件写入。
        # shellcheck disable=SC2024
        sudo -n patch --directory="$task_include_dir" --strip=1 < tools/php-hash-cxx.patch
    fi
fi

cmake -S "$PHPX_HOME" -B "$PHPX_HOME/build" \
    -D CMAKE_BUILD_TYPE=Release -D BUILD_TESTS=OFF -D BUILD_EXT=OFF \
    -D GITHUB_ACTION=ON -D php_dir="$PHP_HOME"
cmake --build "$PHPX_HOME/build" --target phpx --parallel 2
test -f "$PHPX_HOME/lib/libphpx.$task_library_extension"
echo 'PHPX 原生运行库构建完成。'
