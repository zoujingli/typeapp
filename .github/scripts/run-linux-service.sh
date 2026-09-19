#!/usr/bin/env bash
set -euo pipefail

# 用户管理器准备只发生在可丢弃的GitHub宿主，不用于开发者机器或容器。
[[ "${GITHUB_ACTIONS:-}" == true && "${RUNNER_OS:-}" == Linux && "$(uname -s)" == Linux ]] || {
  echo '此入口只接受GitHub Linux原生runner。' >&2; exit 2;
}
[[ "$(cat /proc/1/comm)" == systemd ]] || { echo '需要真实systemd宿主，不能用容器替代。' >&2; exit 2; }
task_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
[[ "$task_root" == "${GITHUB_WORKSPACE:?}" ]]
cd "$task_root"
task_uid="$(id -u)"
task_user="$(id -un)"
[[ "$task_uid" != 0 ]] || { echo '服务验收必须由非root运行账号执行。' >&2; exit 2; }
sudo apt-get update
sudo apt-get install --yes dbus-user-session
export XDG_RUNTIME_DIR="/run/user/$task_uid"
export DBUS_SESSION_BUS_ADDRESS="unix:path=$XDG_RUNTIME_DIR/bus"
if ! systemctl --user show-environment >/dev/null 2>&1; then
  sudo systemctl start "user@$task_uid.service"
fi
systemctl --user show-environment >/dev/null
test -S "$XDG_RUNTIME_DIR/systemd/private"
umask 077
mkdir -p "$task_root/build"
task_work="$(mktemp -d "$task_root/build/service-linux-ci-XXXXXX")"
php tests/systemd-prepare.php "$task_work" "$task_user"
# PHP变量由PHP解释，不能由shell展开。
# shellcheck disable=SC2016
task_digest="$(php -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);echo $r["service-sha256"];' "$task_work/preparation.json")"
php tests/native-service-systemd.php "$task_work/definition/service.json" "$task_digest"
