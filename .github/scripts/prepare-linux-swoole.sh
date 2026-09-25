#!/usr/bin/env bash
set -euo pipefail

# 只为当前 GitHub Linux runner 准备受控模块；共享 SDK 前缀保持可复用。
[[ "${GITHUB_ACTIONS:-}" == true && "${RUNNER_OS:-}" == Linux && "$(uname -s)" == Linux ]] || {
  echo '此入口只接受GitHub Linux原生runner。' >&2; exit 2;
}
task_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
[[ "$task_root" == "${GITHUB_WORKSPACE:?}" ]]
cd "$task_root"
# 仅显式源码重建使用这些选项；默认复用组件内置模块。
export SWOOLE_CONFIGURE_OPTS="${SWOOLE_CONFIGURE_OPTS:---enable-swoole-thread --enable-swoole-pgsql --enable-swoole-sqlite}"
task_built="$(bash tools/prepare-swoole-module.sh)"
echo "prepare-swoole 返回：$task_built" >&2
task_runtime_dir="$(dirname "${TYPE_NATIVE_PHP_INI:?}")"
cp "$task_built" "$task_runtime_dir/swoole.so"
task_swoole_module="$task_runtime_dir/swoole.so"
printf 'TYPE_SWOOLE_MODULE=%s\n' "$task_swoole_module" >> "$GITHUB_ENV"
task_ini="$TYPE_NATIVE_PHP_INI"
task_clean="$(mktemp)"
awk 'tolower($0) !~ /^[[:space:]]*extension[[:space:]]*=.*swoole/ && tolower($0) !~ /^[[:space:]]*swoole.enable_fiber_mock/' "$task_ini" > "$task_clean"
printf 'extension=%s\nswoole.enable_fiber_mock=On\n' "$task_swoole_module" >> "$task_clean"
mv "$task_clean" "$task_ini"
# 探针走已写好的运行 ini（含 PDO 等），不要 php -n 再去拼不存在的 pdo.so。
task_php_probe=(env "PHPRC=$task_ini" "PHP_INI_SCAN_DIR=" php -d display_startup_errors=1 -d display_errors=1)
task_probe_script="$(mktemp)"
printf '%s\n' '<?php' \
  'echo (PHP_ZTS' \
  '    && class_exists(\Swoole\Thread::class, false)' \
  '    && method_exists(\Swoole\Thread::class, "startNative")' \
  '    && defined(\Swoole\Thread::class . "::NATIVE_ENTRY_ABI")' \
  '    && constant(\Swoole\Thread::class . "::NATIVE_ENTRY_ABI") === 2) ? "ready" : "missing";' \
  >"$task_probe_script"
set +e
task_probe="$("${task_php_probe[@]}" "$task_probe_script" 2>"$task_probe_script.err")"
task_probe_status=$?
set -e
if [[ -s "$task_probe_script.err" ]]; then
  cat "$task_probe_script.err" >&2
fi
rm -f "$task_probe_script" "$task_probe_script.err"
printf '受控 Swoole 探针：%s (exit %s)\n' "$task_probe" "$task_probe_status"
[[ "$task_probe" == ready && "$task_probe_status" -eq 0 ]]
task_hooks_script="$(mktemp)"
printf '%s\n' '<?php' \
  'echo defined("SWOOLE_HOOK_PDO_PGSQL") && defined("SWOOLE_HOOK_PDO_SQLITE") ? "hooks" : "missing";' \
  >"$task_hooks_script"
task_hooks="$("${task_php_probe[@]}" "$task_hooks_script")"
rm -f "$task_hooks_script"
printf '受控 Swoole PDO 钩子：%s\n' "$task_hooks"
[[ "$task_hooks" == hooks ]]
# 受控模块写入工作区扫描目录，避免污染可缓存的 PHP 前缀。
task_cli_d="$GITHUB_WORKSPACE/.cache/typeapp-php.d"
mkdir -p "$task_cli_d"
printf 'extension=%s\nswoole.enable_fiber_mock=On\n' "$task_swoole_module" > "$task_cli_d/zz-typeapp-swoole.ini"
task_default_scan="$(php-config --prefix)/etc/php.d"
printf 'PHP_INI_SCAN_DIR=%s:%s\n' "$task_cli_d" "$task_default_scan" >> "$GITHUB_ENV"
export PHP_INI_SCAN_DIR="$task_cli_d:$task_default_scan"
php -m | grep -qx swoole
# 公开消费者直接安装依赖，不忽略所需扩展；错误在大规模编译前暴露。
composer check-platform-reqs --no-interaction
