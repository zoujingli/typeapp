#!/usr/bin/env bash
set -euo pipefail

task_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
task_driver="${1:-sqlite}"
case "$task_driver" in mysql|pgsql|sqlite) ;; *) exit 1;; esac
task_execution="${TYPE_TEST_EXECUTION:-container}"
case "$task_execution" in container|host) ;; *) exit 1;; esac
task_suite="${TYPE_TEST_SUITE:-rollout}"
case "$task_suite" in rollout) task_script=tests/rollout.php;; integration) task_script=tests/integration-consumer.php;; *) exit 1;; esac
task_tools=(--env "TYPE_TEST_SUITE=$task_suite")
if [[ "$task_suite" == integration && "$task_execution" == container ]]; then
  task_composer="${TYPE_COMPOSER_PHAR:?完整消费需要 Composer PHAR 绝对路径}"
  task_composer_cache="${TYPE_COMPOSER_CACHE:-$task_root/.cache/composer}"
  mkdir -p "$task_composer_cache"
  task_tools+=(--mount "type=bind,source=$task_composer,target=/usr/local/bin/composer,readonly" --mount "type=bind,source=$task_composer_cache,target=/task-composer-cache" --env COMPOSER_CACHE_DIR=/task-composer-cache --env COMPOSER_MAX_PARALLEL_HTTP=1)
fi
task_network="${TYPE_TEST_NETWORK:-type-app-tests}"
task_network_options=(--network "$task_network")
if [[ "$task_execution" == container ]]; then docker network inspect "$task_network" >/dev/null;
else task_network_options=(--network bridge); fi
task_id="type-rollout-$(date +%s)-$$"
task_queue="${task_id}-queue"
task_cache="${task_id}-cache"
task_queue_address="$task_queue"
task_cache_address="$task_cache"
task_queue_ports=(--expose 6379)
task_cache_ports=(--expose 6379)
if [[ "$task_execution" == host ]]; then
  # CI 每个任务使用独立 Linux runner；仅向本机回环地址发布测试 Redis。
  task_queue_address=127.0.0.10
  task_cache_address=127.0.0.11
  task_queue_ports=(--publish "$task_queue_address:6379:6379")
  task_cache_ports=(--publish "$task_cache_address:6379:6379")
fi
task_image="${TYPE_TEST_IMAGE:-type-app-toolchain:redis-local}"
task_redis="$(docker image inspect redis:8.10.1 --format '{{.Id}}')"
task_mode=(--php)
task_extra=(--env TYPE_ROLLOUT_NATIVE=0)
if [[ "${TYPE_ROLLOUT_NATIVE:-0}" == 1 ]]; then
  task_mode=(--native)
  if [[ "$task_execution" == container ]]; then
    task_sdk="${TYPE_PHPX_SDK:?原生演练需要 PHPX SDK 绝对路径}"
    task_extra=(--mount "type=bind,source=$task_sdk,target=/opt/phpx,readonly" --env PHP_HOME=/usr/local --env PHPX_HOME=/opt/phpx --env LD_LIBRARY_PATH=/opt/phpx/lib:/usr/local/lib)
  fi
fi
if [[ "$task_suite" == integration && "${TYPE_INTEGRATION_REMOTE:-0}" == 1 ]]; then task_mode+=(--remote); fi
if [[ "$task_suite" == integration && "${TYPE_INTEGRATION_ISOLATED:-0}" == 1 ]]; then task_mode+=(--isolated); fi
cleanup() {
  local task_exit_status=$?
  for task_container in "$task_queue" "$task_cache"; do
    if [[ "$(docker inspect -f '{{ index .Config.Labels "type-app.rollout" }}' "$task_container" 2>/dev/null || true)" == "$task_id" ]]; then
      docker rm --force "$task_container" >/dev/null
    fi
  done
  return "$task_exit_status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
docker run --detach --name "$task_queue" "${task_network_options[@]}" "${task_queue_ports[@]}" --label "type-app.rollout=$task_id" --tmpfs /data "$task_redis" redis-server --appendonly yes --appendfsync always --save '' --maxmemory 16mb --maxmemory-policy noeviction >/dev/null
docker run --detach --name "$task_cache" "${task_network_options[@]}" "${task_cache_ports[@]}" --label "type-app.rollout=$task_id" --tmpfs /data "$task_redis" redis-server --appendonly no --save '' --maxmemory 4mb --maxmemory-policy allkeys-lru >/dev/null
for task_container in "$task_queue" "$task_cache"; do
  task_ready=0
  for ((task_attempt=0; task_attempt<100; task_attempt++)); do
    if [[ "$(docker exec "$task_container" redis-cli ping 2>/dev/null || true)" == PONG ]]; then task_ready=1; break; fi
    sleep 0.05
  done
  test "$task_ready" == 1
done
if [[ "$task_execution" == host ]]; then
  TYPE_REDIS_HOST="$task_queue_address" TYPE_REDIS_PORT=6379 TYPE_ROLLOUT_CACHE_HOST="$task_cache_address" TYPE_INTEGRATION_CACHE_HOST="$task_cache_address" \
    php "$task_root/$task_script" "$task_driver" "${task_mode[@]}"
  exit
fi
docker run --rm --network "$task_network" --mount "type=bind,source=$task_root,target=/workspace" \
  --env "TYPE_REDIS_HOST=$task_queue" --env "TYPE_ROLLOUT_CACHE_HOST=$task_cache" --env "TYPE_INTEGRATION_CACHE_HOST=$task_cache" \
  --env "TYPE_MYSQL_HOST=${TYPE_MYSQL_HOST:-type-app-mysql-test}" --env "TYPE_MYSQL_PASSWORD=${TYPE_MYSQL_PASSWORD:-type-app-test-only}" \
  --env "TYPE_PGSQL_HOST=${TYPE_PGSQL_HOST:-type-app-pgsql-test}" --env "TYPE_PGSQL_PASSWORD=${TYPE_PGSQL_PASSWORD:-type-app-test-only}" \
  "${task_extra[@]}" "${task_tools[@]}" "$task_image" php "$task_script" "$task_driver" "${task_mode[@]}"
