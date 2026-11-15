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
# Swoole 的 socket_ce 来自 PHP sockets 扩展；重新整理声明并固定在 Swoole
# 之前，避免 embed 关闭扫描目录后只剩下 CLI 的隐式模块状态。
task_socket_module="$("$PHP_HOME/bin/php-config" --extension-dir)/sockets.so"
if [[ -f "$task_socket_module" ]]; then
    task_clean="$(mktemp)"
    awk 'tolower($0) !~ /^[[:space:]]*extension[[:space:]]*=.*sockets([.]so)?/' "$task_ini" > "$task_clean"
    printf 'extension=%s\n' "$task_socket_module" >> "$task_clean"
    mv "$task_clean" "$task_ini"
else
    "$PHP_HOME/bin/php" -n -r 'exit(extension_loaded("sockets") ? 0 : 1);' || {
        echo "PHP SDK 缺少 sockets 扩展：$task_socket_module" >&2
        exit 1
    }
fi
if ! PHPRC="$task_ini" PHP_INI_SCAN_DIR='' "$PHP_HOME/bin/php" -r 'exit(extension_loaded("sockets") ? 0 : 1);'; then
    echo 'embed 运行配置未能加载 sockets 扩展。' >&2
    grep -Ein '^[[:space:]]*extension[[:space:]]*=.*sockets' "$task_ini" >&2 || true
    if command -v nm >/dev/null 2>&1; then
        nm -D "$task_socket_module" 2>/dev/null | grep -E 'socket_ce|socket_create_object' >&2 || true
    fi
    exit 1
fi
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
# 受控模块写入独立扫描目录；保留 setup-php 原扫描目录中的 PDO 等基础扩展。
task_cli_d="$GITHUB_WORKSPACE/.cache/typeapp-php.d"
mkdir -p "$task_cli_d"
printf 'extension=%s\n' "$task_socket_module" > "$task_cli_d/00-typeapp-sockets.ini"
printf 'extension=%s\nswoole.enable_fiber_mock=On\n' "$task_swoole_module" > "$task_cli_d/zz-typeapp-swoole.ini"
# task_ini 已经合并了本轮需要的 PDO、curl、Redis、pcntl 等声明；关闭原始
# 扫描目录，确保 sockets 与 Swoole 只在同一目录按 00/zz 顺序装载。
task_scan="$task_cli_d"
printf 'PHP_INI_SCAN_DIR=%s\n' "$task_scan" >> "$GITHUB_ENV"
export PHP_INI_SCAN_DIR="$task_scan"
# 公开消费者直接安装依赖，不忽略所需扩展；错误在大规模编译前暴露。
composer check-platform-reqs --no-interaction
# Swoole 的协程 PostgreSQL/SQLite 实现已经编入受控模块；AOT 运行配置不能再
# 同时加载对应的 PHP 动态驱动，否则静态入口会重复注册 PDO 常量。CLI 继续沿用
# 原始 PHP 配置和完整驱动集合，Composer 与源码测试仍可验证全部平台依赖。
task_clean="$(mktemp)"
awk 'tolower($0) !~ /^[[:space:]]*extension[[:space:]]*=.*(swoole|pdo_pgsql|pdo_sqlite)/' "$task_ini" > "$task_clean"
printf 'extension=%s\nswoole.enable_fiber_mock=On\n' "$task_swoole_module" >> "$task_clean"
mv "$task_clean" "$task_ini"
# 后续步骤和 AOT 消费者统一使用去重后的运行配置；扫描目录置空，避免再次
# 叠加含 Swoole 的临时 CLI 声明。Composer 已在过滤前完成完整平台核验。
printf 'PHPRC=%s\nPHP_INI_SCAN_DIR=\n' "$task_ini" >> "$GITHUB_ENV"
export PHPRC="$task_ini" PHP_INI_SCAN_DIR=
php -m | grep -qx swoole
