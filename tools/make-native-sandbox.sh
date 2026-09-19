#!/usr/bin/env bash
set -euo pipefail

task_binary="$(realpath "${1:?需要原生程序路径}")"
task_root="$(cd "$(dirname "$0")/.." && pwd)"
mkdir -p "$task_root/build"
task_sandbox="$(mktemp -d "$task_root/build/native-sandbox.XXXXXX")"
task_complete=0
cleanup() {
    local task_status=$?
    if [[ "$task_complete" == 0 ]]; then php -n "$task_root/tools/package-native-runtime.php" discard - "$task_sandbox" >&2 || true; fi
    return "$task_status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
mkdir -p "$task_sandbox/app/php.d" "$task_sandbox/lib" "$task_sandbox/tmp"
chmod 1777 "$task_sandbox/tmp"
# 先核对真实 ELF 的封装身份与资源，任何外部探测都必须晚于清单验证。
php -n "$task_root/tools/package-native-runtime.php" resources "$task_binary" "$task_sandbox"
task_ini="${TYPE_NATIVE_PHP_INI:-}"
if [[ -z "$task_ini" ]]; then task_ini="$(bash "$task_root/tools/prepare-embed-runtime.sh")"; fi
php -n "$task_root/tools/package-native-runtime.php" ini "$task_ini" "$task_sandbox"
task_probe="$(dirname "$task_ini")/probe"
test -x "$task_probe"
task_extensions="$(PHPRC="$task_sandbox/app/php.ini" PHP_INI_SCAN_DIR="$task_sandbox/app/php.d" "$task_probe" --extensions)"
printf '%s\n' "$task_extensions" | php -n "$task_root/tools/package-native-runtime.php" runtime - "$task_sandbox"
task_modules="$(PHPRC="$task_sandbox/app/php.ini" PHP_INI_SCAN_DIR="$task_sandbox/app/php.d" "$task_probe" --libraries)"

task_dependencies="$(ldd "$task_sandbox/app/type-app")"
if [[ "$task_dependencies" == *'not found'* ]]; then
    echo '原生程序缺少动态运行库，不能建立隔离目录。' >&2
    exit 1
fi
# maps 只给真实版本文件；逐个补齐动态模块依赖的 SONAME，不能仅看主 ELF 的 ldd。
task_module_dependencies=''
while IFS= read -r task_module; do
    if [[ -z "$task_module" ]]; then continue; fi
    task_linked="$(ldd "$task_module")"
    if [[ "$task_linked" == *'not found'* ]]; then
        echo '原生扩展缺少传递运行库，不能建立隔离目录。' >&2
        exit 1
    fi
    task_module_dependencies+=$'\n'"$task_linked"
done < <(printf '%s\n' "$task_modules" | sort -u)
task_nss="$(find /usr/lib /lib -maxdepth 3 -type f \( -name 'libnss_files.so.2' -o -name 'libnss_dns.so.2' \) -print)"
{ printf '%s\n' "$task_dependencies" "$task_module_dependencies" | awk '/=> \// { print $3 } /^[[:space:]]*\// { print $1 }'; printf '%s\n' "$task_modules" "$task_nss"; } \
  | php -n "$task_root/tools/package-native-runtime.php" libraries "$task_sandbox/app/type-app" "$task_sandbox"

# 某些 PHP SDK 使用系统时区数据库，它属于原生运行资源。
if test -d /usr/share/zoneinfo; then
    mkdir -p "$task_sandbox/usr/share"
    cp -a /usr/share/zoneinfo "$task_sandbox/usr/share/zoneinfo"
fi

task_php_source="$(find "$task_sandbox" -type f -name '*.php' -print -quit)"
if [[ -n "$task_php_source" ]]; then
    echo '隔离产物意外包含 PHP 源码。' >&2
    exit 1
fi

# PHP CLI、Composer 与业务 PHP 源码均不进入隔离目录。
task_complete=1
printf '%s\n' "$task_sandbox"
