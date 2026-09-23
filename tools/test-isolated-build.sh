#!/usr/bin/env bash
set -euo pipefail

task_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
task_php="${TYPE_TEST_PHP:-php}"
task_execution="${TYPE_TEST_EXECUTION:-container}"
task_sdk="${TYPE_PHPX_SDK:-$task_root/vendor/swoole/phpx}"
task_sdk="$(cd "$task_sdk" && pwd)"
[[ -f "$task_sdk/lib/libphpx.so" ]] || { echo '固定 PHPX SDK 缺少原生运行库。' >&2; exit 1; }
host_parent_directories() {
  local task_parent="${1%/*}" task_prefix='' task_part
  local task_parts=()
  IFS=/ read -r -a task_parts <<< "${task_parent#/}"
  for task_part in "${task_parts[@]}"; do
    [[ -n "$task_part" ]] || continue
    task_prefix+="/$task_part"
    task_host_common+=(--perms 0755 --dir "$task_prefix")
  done
}
case "$task_execution" in
  container)
    task_source_image="${TYPE_TEST_IMAGE:-type-app-toolchain:redis-local}"
    task_image="$(docker image inspect --format '{{.Id}}' "$task_source_image")"
    [[ "$task_image" =~ ^sha256:[0-9a-f]{64}$ ]] || { echo '必须提供已经准备且受信任的本地工具链镜像，不自动拉取。' >&2; exit 1; }
    task_isolator="$task_image"
    task_php_home=/usr/local
    ;;
  host)
    [[ "$(uname -s)" == Linux ]] || { echo 'host 隔离构建必须在 Linux 使用真实 bubblewrap，不回退到普通宿主编译。' >&2; exit 1; }
    task_php_home="${PHP_HOME:?host 隔离需要已经验证的 PHP SDK}"
    [[ "$task_php_home" == /* && -x "$task_php_home/bin/php" && -f "$task_php_home/lib/libphp.so" ]] || { echo '固定 PHP SDK 缺失。' >&2; exit 1; }
    task_bwrap="$(command -v bwrap)"
    [[ -x /usr/bin/setpriv ]] || { echo 'host 隔离需要 util-linux 的 setpriv。' >&2; exit 1; }
    task_isolator="host:$($task_bwrap --version)"
    task_user="$(id -u)"
    task_group="$(id -g)"
    task_supervisor=()
    if [[ "$task_user" != 0 ]]; then task_supervisor=(sudo -n); fi
    # 只有 namespace 设置器暂时拥有切换身份所需能力；任何 PHP/C++ 之前全部移除。
    task_host_common=("$task_bwrap" --die-with-parent --new-session --unshare-pid --unshare-ipc --unshare-uts --unshare-net
      --cap-drop ALL --cap-add CAP_SETUID --cap-add CAP_SETGID --cap-add CAP_SETPCAP
      --ro-bind /usr /usr --symlink usr/bin /bin --symlink usr/sbin /sbin)
    # bwrap 自动补齐的父目录默认仅 root 可遍历；仅让空挂载点可遍历，不放宽任何输入源权限。
    for task_mount_parent in /etc/snmp/snmp.conf /opt/phpx /app/type-app /var/lib/snmp; do host_parent_directories "$task_mount_parent"; done
    for task_system_library in /lib /lib64; do
      if [[ -e "$task_system_library" ]]; then task_host_common+=(--ro-bind "$task_system_library" "$task_system_library"); fi
    done
    for task_system_configuration in /etc/php /etc/alternatives /etc/ld.so.cache /etc/localtime /etc/locale.alias; do
      if [[ -e "$task_system_configuration" ]]; then task_host_common+=(--ro-bind "$task_system_configuration" "$task_system_configuration"); fi
    done
    # CI 的 PHP_HOME 可位于工作区内的独立 SDK 缓存：只绑定这一精确目录，不绑定其父目录。
    if [[ "$task_php_home" != /usr && "$task_php_home" != /usr/* ]]; then
      host_parent_directories "$task_php_home"
      task_host_common+=(--ro-bind "$task_php_home" "$task_php_home")
    fi
    task_host_common+=(--ro-bind "$task_sdk" /opt/phpx --proc /proc --dev /dev --perms 1777 --size 268435456 --tmpfs /tmp --clearenv)
    task_drop_privileges=(/usr/bin/setpriv --reuid "$task_user" --regid "$task_group" --clear-groups
      --bounding-set=-all --inh-caps=-all --ambient-caps=-all --no-new-privs)
    ;;
  *) echo 'TYPE_TEST_EXECUTION 仅支持 container 或 host。' >&2; exit 1;;
esac
mkdir -p "$task_root/build"
task_work="$(mktemp -d "$task_root/build/isolated-build-XXXXXX")"
task_relative="${task_work#"$task_root/"}"
if [[ "$task_execution" == host ]]; then
  # 某些锁定 SDK 静态预置 SNMP；只绑定自生成的无凭据启动配置与空目录，不绑定宿主 /etc/snmp。
  mkdir "$task_work/runtime"
  printf '%s\n' snmp | "$task_php" -n "$task_root/tools/package-native-runtime.php" runtime - "$task_work/runtime"
  task_host_common+=(--ro-bind "$task_work/runtime/etc/snmp/snmp.conf" /etc/snmp/snmp.conf
    --ro-bind "$task_work/runtime/var/lib/snmp" /var/lib/snmp)
fi
"$task_php" -n "$task_root/tests/isolated-build.php" configuration "$task_root/docs/build-config/type-foundation.json" > "$task_work/configuration.json"
task_common=(--rm --pull=never --network=none --read-only --cap-drop=ALL --security-opt=no-new-privileges
  --cpus=2 --tmpfs '/tmp:rw,nosuid,nodev,size=256m')
task_environment=(env -i "PATH=$task_php_home/bin:/usr/local/bin:/usr/bin:/bin" LANG=C.UTF-8 LC_ALL=C.UTF-8 TZ=UTC
  "PHP_HOME=$task_php_home" PHPX_HOME=/opt/phpx "LD_LIBRARY_PATH=/opt/phpx/lib:$task_php_home/lib")
task_compile_mounts=(--mount "type=bind,source=$task_work/inputs,target=/input,readonly"
  --mount "type=bind,source=$task_work/output,target=/input/build"
  --mount "type=bind,source=$task_sdk,target=/opt/phpx,readonly" --workdir /input)
report_failure() {
  local task_status=$?
  if [[ "$task_status" != 0 ]]; then echo "隔离构建未通过，现场保留：$task_work" >&2; fi
  return "$task_status"
}
trap report_failure EXIT

run_isolated_compiler() {
  if [[ "$task_execution" == container ]]; then
    docker run -i "${task_common[@]}" "${task_compile_mounts[@]}" "$task_image" "${task_environment[@]}" "$@"
  else
    "${task_supervisor[@]}" "${task_host_common[@]}" --ro-bind "$task_work/inputs" /input --bind "$task_work/output" /input/build \
      --chdir / --remount-ro / -- "${task_drop_privileges[@]}" "${task_environment[@]}" \
      sh -c 'cd /input && exec "$@"' type-isolated-build "$@"
  fi
}

run_isolated_native() {
  if [[ "$task_execution" == container ]]; then
    docker run -i "${task_common[@]}" \
      --mount "type=bind,source=$task_work/output/native/type-app,target=/app/type-app,readonly" \
      --mount "type=bind,source=$task_sdk,target=/opt/phpx,readonly" --workdir /app \
      "$task_image" "${task_environment[@]}" bash -c \
        'set -euo pipefail; tar --no-same-owner -xf - -C /tmp; php /tmp/tests/native.php /app/type-app'
  else
    local task_runtime_mounts=() task_runtime_environment=()
    if [[ -n "${TYPE_NATIVE_PHP_INI:-}" ]]; then
      local task_runtime_directory
      task_runtime_directory="$(cd "$(dirname "$TYPE_NATIVE_PHP_INI")" && pwd)"
      [[ -f "$task_runtime_directory/php.ini" && -d "$task_runtime_directory/php.d" ]] || { echo '缺少明确的 embed 运行配置。' >&2; return 1; }
      host_parent_directories "$task_runtime_directory/php.ini"
      task_runtime_mounts=(--ro-bind "$task_runtime_directory/php.ini" "$task_runtime_directory/php.ini"
        --ro-bind "$task_runtime_directory/php.d" "$task_runtime_directory/php.d")
      if [[ -f "$task_runtime_directory/pcntl.so" ]]; then
        task_runtime_mounts+=(--ro-bind "$task_runtime_directory/pcntl.so" "$task_runtime_directory/pcntl.so")
      fi
      if [[ -f "$task_runtime_directory/swoole.so" ]]; then
        task_runtime_mounts+=(--ro-bind "$task_runtime_directory/swoole.so" "$task_runtime_directory/swoole.so")
      fi
      task_runtime_environment=("TYPE_NATIVE_PHP_INI=$task_runtime_directory/php.ini")
    fi
    "${task_supervisor[@]}" "${task_host_common[@]}" "${task_runtime_mounts[@]}" \
      --ro-bind "$task_work/output/native/type-app" /app/type-app --chdir /app --remount-ro / -- \
      "${task_drop_privileges[@]}" "${task_environment[@]}" "${task_runtime_environment[@]}" bash -c \
        'set -euo pipefail; tar --no-same-owner -xf - -C /tmp; php /tmp/tests/native.php /app/type-app'
  fi
}

# 依赖已安装；stage 只读取主仓，并把公开构建器产生的输入快照写入 build。
# 准备阶段不编译，也不给容器继承宿主认证环境。
if [[ "$task_execution" == container ]]; then
  docker run "${task_common[@]}" \
    --mount "type=bind,source=$task_root,target=/workspace,readonly" \
    --mount "type=bind,source=$task_root/build,target=/workspace/build" \
    --mount "type=bind,source=$task_sdk,target=/opt/phpx,readonly" --workdir /workspace \
    "$task_image" "${task_environment[@]}" php tests/build-scenario.php --stage docs/build-config/type-foundation.json "/workspace/$task_relative/inputs" \
    | tee "$task_work/stage.json"
else
  (cd "$task_root" && env -i "PATH=$task_php_home/bin:/usr/bin:/bin" LANG=C.UTF-8 LC_ALL=C.UTF-8 TZ=UTC \
    "COMPOSER_HOME=$task_work/composer-home" \
    "PHP_HOME=$task_php_home" "PHPX_HOME=$task_sdk" "LD_LIBRARY_PATH=$task_sdk/lib:$task_php_home/lib" \
    "$task_php_home/bin/php" tests/build-scenario.php --stage docs/build-config/type-foundation.json "$task_work/inputs") | tee "$task_work/stage.json"
fi
"$task_php" -n "$task_root/tests/isolated-build.php" snapshot "$task_work/inputs" "$task_work/stage.json" > "$task_work/snapshot-before.json"
mkdir "$task_work/output"
if [[ -d "$task_work/inputs/build" ]]; then cp -R "$task_work/inputs/build/." "$task_work/output/"; fi

# 测试控制器通过标准输入送入临时进程，不挂载原始仓库或测试目录。
# 无害哨兵只在宿主环境中，探针必须确认它和认证变量均未进入容器。
TYPE_ISOLATION_SECRET_CANARY=type-app-test-only run_isolated_compiler php /dev/stdin boundary "$task_root" "${task_user:-0}" "$task_execution" \
  < "$task_root/tests/isolated-build.php" > "$task_work/boundary.json"

# 这是完整 TypePHP→C++→ELF 构建，不传 --dry，也不绑定原仓、宿主 home 或缓存。
run_isolated_compiler php vendor/bin/type docs/build-config/type-foundation.json 2>&1 | tee "$task_work/compile.log"

# 复用已有九项公共行为用例；执行容器只挂最终 ELF 和固定 SDK，不再挂生产输入。
tar -C "$task_root" -cf - tests/native.php tests/support.php plugin/type-build/src/BuildPlatform.php | run_isolated_native 2>&1 | tee "$task_work/native.log"
"$task_php" -n "$task_root/tests/isolated-build.php" report "$task_work" "$task_isolator" > "$task_work/report.json"
echo "断网只读全量构建与九项原生命令验收通过：$task_work/report.json"
