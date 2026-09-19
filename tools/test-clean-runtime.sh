#!/usr/bin/env bash
set -euo pipefail
task_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
task_execution="${TYPE_TEST_EXECUTION:-container}"
case "$task_execution" in container|host) ;; *) exit 1;; esac
task_id="$(date +%s)-$$"
task_image="type-app-clean-test:$task_id"
task_sandbox=''
cleanup() {
  local task_status=$?
  if [[ "$(docker image inspect -f '{{ index .Config.Labels "type-app.clean" }}' "$task_image" 2>/dev/null || true)" == "$task_id" ]]; then docker image rm "$task_image" >/dev/null || true; fi
  if [[ -n "$task_sandbox" ]]; then php -n "$task_root/tools/package-native-runtime.php" discard - "$task_sandbox" >&2 || true; fi
  return "$task_status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
for task_kind in native identity; do
  task_artifact="build/$task_kind/type-app"
  if [[ "$task_execution" == host ]]; then
    task_sandbox="$(bash "$task_root/tools/make-native-sandbox.sh" "$task_root/$task_artifact")"
  else
    task_sdk="${TYPE_PHPX_SDK:-$task_root/vendor/swoole/phpx}"
    task_mounted="$(docker run --rm --mount "type=bind,source=$task_root,target=/workspace" --mount "type=bind,source=$task_sdk,target=/opt/phpx,readonly" \
      --env PHP_HOME=/usr/local --env PHPX_HOME=/opt/phpx --env LD_LIBRARY_PATH=/opt/phpx/lib:/usr/local/lib --workdir /workspace \
      "${TYPE_TEST_IMAGE:-type-app-toolchain:redis-local}" bash tools/make-native-sandbox.sh "/workspace/$task_artifact")"
    case "$task_mounted" in /workspace/build/native-sandbox.*) task_sandbox="$task_root/${task_mounted#/workspace/}";; *) exit 1;; esac
  fi
  docker build --quiet --label "type-app.clean=$task_id" --file "$task_root/tools/runtime.Dockerfile" --tag "$task_image" "$task_sandbox" >/dev/null
  if [[ "$task_kind" == native ]]; then php "$task_root/tests/native.php" --docker-image "$task_image";
  else php "$task_root/tests/build-identity-native.php" --docker-image "$task_image" "$task_root/$task_artifact"; fi
  docker image rm "$task_image" >/dev/null
  php -n "$task_root/tools/package-native-runtime.php" discard - "$task_sandbox"
  task_sandbox=''
done
