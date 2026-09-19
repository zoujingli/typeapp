#!/usr/bin/env bash
set -euo pipefail

task_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
task_composer="${TYPE_COMPOSER_PHAR:?请设置 Composer PHAR 绝对路径}"
task_execution="${TYPE_TEST_EXECUTION:-container}"
case "$task_execution" in container|host) ;; *) exit 1;; esac
task_id="type-reliability-$(date +%s)-$$"
task_network="${task_id}-network"
task_volume="${task_id}-data"
task_reliable="${task_id}-reliable"
task_cache="${task_id}-cache"
task_reliable_address="$task_reliable"
task_cache_address="$task_cache"
task_reliable_ports=(--expose 6379)
task_cache_ports=(--expose 6379)
if [[ "$task_execution" == host ]]; then
  task_reliable_address=127.0.0.12
  task_cache_address=127.0.0.13
  task_reliable_ports=(--publish "$task_reliable_address:6379:6379")
  task_cache_ports=(--publish "$task_cache_address:6379:6379")
fi
task_toolchain="${TYPE_TEST_IMAGE:-type-app-toolchain:redis-local}"
task_cache_dir="${TYPE_COMPOSER_CACHE:-${task_root}/.cache/composer}"
task_native="${TYPE_RELIABILITY_NATIVE:-0}"
task_extra=(--env TYPE_RELIABILITY_NATIVE=0)
task_mode=(--php)
if [[ "$task_native" == 1 ]]; then
  if [[ "$task_execution" == container ]]; then
    task_sdk="${TYPE_PHPX_SDK:?原生验收需要已准备的 PHPX SDK 绝对路径}"
    task_extra=(--mount "type=bind,source=$task_sdk,target=/opt/phpx,readonly" --env PHP_HOME=/usr/local --env PHPX_HOME=/opt/phpx --env LD_LIBRARY_PATH=/opt/phpx/lib:/usr/local/lib)
  fi
  task_mode=(--native)
fi
mkdir -p "$task_cache_dir"

cleanup() {
  local task_exit_status=$?
  for task_container in "$task_reliable" "$task_cache"; do
    if [[ "$(docker inspect -f '{{ index .Config.Labels "type-app.reliability" }}' "$task_container" 2>/dev/null || true)" == "$task_id" ]]; then
      docker rm --force "$task_container" >/dev/null
    fi
  done
  if [[ "$(docker volume inspect -f '{{ index .Labels "type-app.reliability" }}' "$task_volume" 2>/dev/null || true)" == "$task_id" ]]; then docker volume rm "$task_volume" >/dev/null; fi
  if [[ "$(docker network inspect -f '{{ index .Labels "type-app.reliability" }}' "$task_network" 2>/dev/null || true)" == "$task_id" ]]; then docker network rm "$task_network" >/dev/null; fi
  return "$task_exit_status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
docker network create --label "type-app.reliability=$task_id" "$task_network" >/dev/null
docker volume create --label "type-app.reliability=$task_id" "$task_volume" >/dev/null
docker run --detach --name "$task_reliable" --network "$task_network" "${task_reliable_ports[@]}" --label "type-app.reliability=$task_id" --mount "type=volume,source=$task_volume,target=/data" redis:8.10.1 redis-server --appendonly yes --appendfsync always --save '' --maxmemory 16mb --maxmemory-policy noeviction >/dev/null
docker run --detach --name "$task_cache" --network "$task_network" "${task_cache_ports[@]}" --label "type-app.reliability=$task_id" --tmpfs /data redis:8.10.1 redis-server --appendonly no --save '' --maxmemory 4mb --maxmemory-policy allkeys-lru >/dev/null

wait_redis() {
  for ((task_attempt=0; task_attempt<100; task_attempt++)); do
    if [[ "$(docker exec "$1" redis-cli ping 2>/dev/null || true)" == PONG ]]; then return; fi
    sleep 0.05
  done
  return 1
}
wait_redis "$task_reliable"
wait_redis "$task_cache"
run_container() {
  if [[ "$task_execution" == host ]]; then
    COMPOSER_BINARY="$task_composer" COMPOSER_CACHE_DIR="$task_cache_dir" TYPE_RELIABLE_HOST="$task_reliable_address" \
      TYPE_CACHE_HOST="$task_cache_address" TYPE_REDIS_HOST="$task_reliable_address" TYPE_REDIS_PORT=6379 "$@"
    return
  fi
  docker run --rm --network "$task_network" --mount "type=bind,source=$task_root,target=/workspace" --mount "type=bind,source=$task_composer,target=/usr/local/bin/composer,readonly" --mount "type=bind,source=$task_cache_dir,target=/composer-cache" --workdir /workspace --env COMPOSER_CACHE_DIR=/composer-cache --env "TYPE_RELIABLE_HOST=$task_reliable" --env "TYPE_CACHE_HOST=$task_cache" --env "TYPE_REDIS_HOST=$task_reliable" "${task_extra[@]}" "$task_toolchain" "$@"
}
run_php() { run_container php "$@"; }
if task_consumer="$(run_php tests/task-reliability-consumer.php "${task_mode[@]}")"; then
  case "$task_consumer" in /workspace/build/reliability-consumer-*|"$task_root"/build/reliability-consumer-*) ;;
    *) printf '无法识别独立消费者输出：%s\n' "$task_consumer" >&2; exit 1;; esac
else
  task_status=$?
  printf '%s\n' "$task_consumer" >&2
  exit "$task_status"
fi
run_task() {
  if [[ "$task_native" == 1 && -n "${TYPE_NATIVE_PHP_INI:-}" ]]; then
    run_container env "PHPRC=$TYPE_NATIVE_PHP_INI" "PHP_INI_SCAN_DIR=$(dirname "$TYPE_NATIVE_PHP_INI")/php.d" "$task_consumer/build/type-app" "$@"
  elif [[ "$task_native" == 1 ]]; then run_container "$task_consumer/build/type-app" "$@";
  else run_php "$task_consumer/run.php" "$@"; fi
}
run_task seed
docker kill --signal KILL "$task_reliable" >/dev/null
test "$(docker inspect -f '{{.State.ExitCode}} {{.State.OOMKilled}}' "$task_reliable")" = '137 false'
docker start "$task_reliable" >/dev/null
wait_redis "$task_reliable"
run_task recover
run_task pressure
run_php tests/task-reliability-process.php "$task_consumer" "${task_mode[@]}"
run_php tests/scheduler.php "$task_consumer/vendor/autoload.php"
run_php tests/task-reliability-regressions.php "$task_consumer"
