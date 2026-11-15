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
task_cli_d="$task_runtime_dir/php.d"
mkdir -p "$task_cli_d"
# 这些共享模块统一由下方同一扫描目录提供，避免主 INI 与扫描目录重复加载。
task_clean="$(mktemp)"
awk 'tolower($0) !~ /^[[:space:]]*extension[[:space:]]*=.*(curl|redis|sockets|swoole)([.]so)?/' "$task_ini" > "$task_clean"
mv "$task_clean" "$task_ini"
printf 'extension=%s\n' "$task_socket_module" > "$task_cli_d/00-typeapp-sockets.ini"
task_redis_module="$("$PHP_HOME/bin/php-config" --extension-dir)/redis.so"
[[ -f "$task_redis_module" ]] || { echo "PHP SDK 缺少 redis.so：$task_redis_module" >&2; exit 1; }
printf 'extension=%s\n' "$task_redis_module" > "$task_cli_d/10-typeapp-redis.ini"
task_curl_module="$("$PHP_HOME/bin/php-config" --extension-dir)/curl.so"
[[ -f "$task_curl_module" ]] || { echo "PHP SDK 缺少 curl.so：$task_curl_module" >&2; exit 1; }
printf 'extension=%s\n' "$task_curl_module" > "$task_cli_d/20-typeapp-curl.ini"
printf 'extension=%s\nswoole.enable_fiber_mock=On\n' "$task_swoole_module" > "$task_cli_d/zz-typeapp-swoole.ini"
# task_ini 已经合并了本轮需要的 PDO、curl、Redis、pcntl 等声明；关闭原始
# 扫描目录，确保 sockets 与 Swoole 只在同一目录按 00/zz 顺序装载。
task_scan="$task_cli_d"
printf 'PHP_INI_SCAN_DIR=%s\n' "$task_scan" >> "$GITHUB_ENV"
export PHP_INI_SCAN_DIR="$task_scan"
# Composer 的 bin 入口可能固定使用 setup-php 的解释器；显式包装它，
# 使 Composer 自身及所有 @php 脚本都继承本轮 PHP_HOME 的 ZTS SDK。
task_composer="$(command -v composer)"
[[ "$task_composer" == /* && -f "$task_composer" ]] || { echo '找不到可包装的 Composer 入口。' >&2; exit 1; }
task_bin="$GITHUB_WORKSPACE/.cache/typeapp-bin"
mkdir -p "$task_bin"
cat >"$task_bin/composer" <<EOF
#!/usr/bin/env bash
exec "$PHP_HOME/bin/php" "$task_composer" "\$@"
EOF
chmod 0755 "$task_bin/composer"
# configure-toolchain 可能返回带内容摘要的 SDK 目录，而 setup-php 早先
# 写入的 PATH 仍指向另一份同版本 PHP；后续 Composer/PHP 命令必须和扩展
# 使用同一套 SDK，否则会出现 socket_ce 等 ABI 符号缺失。
printf '%s\n' "$PHP_HOME/bin" >> "$GITHUB_PATH"
printf '%s\n' "$task_bin" >> "$GITHUB_PATH"
export PATH="$task_bin:$PHP_HOME/bin:$PATH"
# GitHub runner 将 GITHUB_PATH 条目追加到当前 PATH；显式写入环境变量，
# 确保下一步 shell 仍把本轮 SDK 放在 setup-php 的同版本目录之前。
printf 'PATH=%s\n' "$task_bin:$PHP_HOME/bin:$PATH" >> "$GITHUB_ENV"
hash -r
printf '受控 PHP：%s\n' "$(command -v php)"
"$PHP_HOME/bin/php" -r 'printf("PHP %s %s\n", PHP_VERSION, PHP_ZTS ? "ZTS" : "NTS");'
# 公开消费者直接安装依赖，不忽略所需扩展；错误在大规模编译前暴露。
composer check-platform-reqs --no-interaction
# Swoole 的协程 PostgreSQL/SQLite 实现已经编入受控模块；AOT 运行配置不能再
# 同时加载对应的 PHP 动态驱动，否则静态入口会重复注册 PDO 常量。CLI 继续沿用
# 原始 PHP 配置和完整驱动集合，Composer 与源码测试仍可验证全部平台依赖。
task_clean="$(mktemp)"
awk 'tolower($0) !~ /^[[:space:]]*extension[[:space:]]*=.*(pdo_pgsql|pdo_sqlite)/' "$task_ini" > "$task_clean"
mv "$task_clean" "$task_ini"
# 后续步骤和 AOT 消费者统一使用去重后的运行配置及受控扫描目录。扫描目录
# 仍需保留 sockets、Redis、curl 与 Swoole 的单一声明；清空它会让 Swoole
# 在加载时找不到 socket_ce，导致后续命令只看到 Composer 已通过而实际退出。
printf 'PHPRC=%s\nPHP_INI_SCAN_DIR=%s\n' "$task_ini" "$task_scan" >> "$GITHUB_ENV"
export PHPRC="$task_ini" PHP_INI_SCAN_DIR="$task_scan"
"$PHP_HOME/bin/php" -m | grep -qx swoole
