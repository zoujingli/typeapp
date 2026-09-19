#!/usr/bin/env bash
set -euo pipefail
task_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
task_binary="${1:-build/file-http/type-app}"
task_execution="${TYPE_TEST_EXECUTION:-container}"
case "$task_execution" in container|host) ;; *) exit 1;; esac
if [[ "$task_execution" == container ]]; then
  task_sdk="${TYPE_PHPX_SDK:-$task_root/vendor/swoole/phpx}"
  docker run --rm --tmpfs /type-incoming:rw,nosuid,nodev,size=1m --tmpfs /type-storage:rw,nosuid,nodev,size=1m --tmpfs /type-storage-unit:rw,nosuid,nodev,size=64k \
    --mount "type=bind,source=$task_root,target=/workspace" --mount "type=bind,source=$task_sdk,target=/opt/phpx,readonly" \
    --env LD_LIBRARY_PATH=/opt/phpx/lib:/usr/local/lib --env TYPE_HTTP_UPLOAD_TEMP=/type-incoming --env TYPE_UPLOAD_FAULT_DIRECTORY=/type-storage \
    --workdir /workspace "${TYPE_TEST_IMAGE:-type-app-toolchain:redis-local}" bash -c \
    'set -euo pipefail; php tests/file-pressure.php "$1"; TYPE_UPLOAD_FAULT_DIRECTORY=/type-storage-unit php tests/upload-storage.php' -- "$task_binary"
  exit
fi
task_volume="$(mktemp -d "$task_root/build/upload-pressure.XXXXXX")"
mkdir "$task_volume/incoming" "$task_volume/storage" "$task_volume/unit"
task_platform="$(uname -s)"
case "$task_platform" in Linux|Darwin) ;; *) echo 'host模式只支持Linux/macOS原生文件系统。' >&2; exit 2;; esac
task_mounted=()
cleanup() {
  local task_status=$?
  if [[ ${#task_mounted[@]} -gt 0 ]]; then
    for task_mount in "${task_mounted[@]}"; do
      if [[ "$task_platform" == Darwin ]]; then
        hdiutil detach "$task_mount" >/dev/null || task_status=1
      else
        sudo -n umount "$task_mount" || task_status=1
      fi
    done
  fi
  for task_mount in "$task_volume/incoming" "$task_volume/storage" "$task_volume/unit"; do
    rmdir "$task_mount" || task_status=1
  done
  # 映像、挂载日志和失败现场保留在本轮build目录；不删除任何其他设备或数据。
  return "$task_status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
for task_name in incoming storage unit; do
  task_mount="$task_volume/$task_name"
  if [[ "$task_platform" == Darwin ]]; then
    hdiutil create -size 1m -layout NONE -fs MS-DOS -volname TYPEPRESS "$task_volume/$task_name.dmg" >"$task_volume/$task_name-create.log"
    hdiutil attach -nobrowse -mountpoint "$task_mount" "$task_volume/$task_name.dmg" >"$task_volume/$task_name-attach.log"
  else
    task_size=1m
    [[ "$task_name" != unit ]] || task_size=64k
    sudo -n mount -t tmpfs -o "size=$task_size,mode=1777,nosuid,nodev" tmpfs "$task_mount"
  fi
  task_mounted+=("$task_mount")
done
cd "$task_root"
TYPE_HTTP_UPLOAD_TEMP="$task_volume/incoming" TYPE_UPLOAD_FAULT_DIRECTORY="$task_volume/storage" php tests/file-pressure.php "$task_binary"
TYPE_UPLOAD_FAULT_DIRECTORY="$task_volume/unit" php tests/upload-storage.php
printf '原生小容量文件系统压力验证通过：%s\n' "$task_volume"
