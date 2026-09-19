#!/usr/bin/env bash
set -euo pipefail

# 仅为可丢弃runner配置独立SDK；不安装共享模块到原PHP目录。
[[ "${GITHUB_ACTIONS:-}" == true && "${RUNNER_OS:-}" == Linux && "$(uname -s)" == Linux ]] || {
  echo '此入口只接受GitHub Linux原生runner。' >&2; exit 2;
}
task_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
[[ "$task_root" == "${GITHUB_WORKSPACE:?}" ]]
cd "$task_root"
task_sdk="${PHP_HOME:?}"
task_runtime_ini="$(bash tools/prepare-embed-runtime.sh)"
task_module="$(dirname "$task_runtime_ini")/pcntl.so"
# 文件存在不能证明本次选中了共享模块；须同时出现在刚验证的运行INI中。
if [[ -f "$task_module" ]] && grep -Fxq "extension=$task_module" "$task_runtime_ini"; then
  task_sdk="$(php tools/configure-toolchain.php "$task_root/.cache/php-sdk" "$(command -v php-config)" "pcntl=$task_module")"
  printf 'TYPE_CI_PCNTL_MODULE=%s\n' "$task_module" >> "${GITHUB_ENV:?}"
fi
printf 'PHP_HOME=%s\nLD_LIBRARY_PATH=%s\nTYPE_NATIVE_PHP_INI=%s\n' \
  "$task_sdk" "${PHPX_HOME:?}/lib:$task_sdk/lib" "$task_runtime_ini" >> "${GITHUB_ENV:?}"
