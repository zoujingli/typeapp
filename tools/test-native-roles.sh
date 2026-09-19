#!/usr/bin/env bash
set -euo pipefail

task_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$task_root"
task_php="${TYPE_TEST_PHP:-php}"
task_identity="$("$task_php" -r 'echo bin2hex(random_bytes(8));')"
task_label="type-native-roles=$task_identity"
task_network="type-native-roles-$task_identity"
task_redis="type-native-roles-redis-$task_identity"
task_volume="type-native-roles-data-$task_identity"
task_outbox_tag="type-native-roles:outbox-$task_identity"
task_scheduler_tag="type-native-roles:scheduler-$task_identity"
mkdir -p "$task_root/build"
task_work="$(mktemp -d "$task_root/build/native-roles-XXXXXX")"
task_network_created=0 task_volume_created=0
task_images=()
cleanup_resources() {
    local task_failed=0 task_containers
    task_containers="$(docker ps -a --quiet --filter "label=$task_label")" || task_failed=1
    if [[ -n "$task_containers" ]]; then
        while IFS= read -r task_container; do
            [[ "$task_container" =~ ^[a-f0-9]{12,64}$ ]] || { task_failed=1; continue; }
            docker rm --force "$task_container" >/dev/null || task_failed=1
        done <<< "$task_containers"
    fi
    if [[ "$task_volume_created" == 1 ]]; then docker volume rm "$task_volume" >/dev/null && task_volume_created=0 || task_failed=1; fi
    if [[ "$task_network_created" == 1 ]]; then docker network rm "$task_network" >/dev/null && task_network_created=0 || task_failed=1; fi
    if [[ ${#task_images[@]} -gt 0 ]]; then
        for task_image_tag in "${task_images[@]}"; do docker image rm "$task_image_tag" >/dev/null || task_failed=1; done
    fi
    task_images=()
    return "$task_failed"
}
on_exit() {
    local task_status=$?
    trap - EXIT
    cleanup_resources || task_status=1
    if [[ "$task_status" != 0 ]]; then echo "独立角色部署未通过，现场保留：$task_work" >&2; fi
    exit "$task_status"
}
trap on_exit EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

task_prefix=()
task_build_php="$task_php"
if [[ "${TYPE_TEST_EXECUTION:-container}" != host ]]; then
    task_sdk="${TYPE_PHPX_SDK:-$task_root/vendor/swoole/phpx}"
    task_sdk="$(cd "$task_sdk" && pwd)"
    task_builder="$(docker image inspect --format '{{.Id}}' "${TYPE_TEST_IMAGE:-type-app-toolchain:redis-local}")"
    task_prefix=(docker run --rm --pull=never --label "$task_label" --network=none --cpus=2
        --mount "type=bind,source=$task_root,target=/workspace" --mount "type=bind,source=$task_sdk,target=/opt/phpx,readonly"
        --env PHP_HOME=/usr/local --env PHPX_HOME=/opt/phpx --env LD_LIBRARY_PATH=/opt/phpx/lib:/usr/local/lib --workdir /workspace "$task_builder")
    task_build_php=php
fi
for task_role in outbox scheduler; do
    # 使用既有配置和公开构建命令；完整输入身份决定真实重建或已核验缓存命中。
    "${task_prefix[@]}" "$task_build_php" tests/build-scenario.php "docs/build-config/type-$task_role.json" > "$task_work/build-$task_role.log" 2>&1
    "${task_prefix[@]}" bash tools/make-native-sandbox.sh "build/$task_role/type-app" > "$task_work/sandbox-$task_role.txt"
    task_sandbox="$(tail -n 1 "$task_work/sandbox-$task_role.txt")"
    if [[ ${#task_prefix[@]} -gt 0 ]]; then task_sandbox="$task_root/${task_sandbox#/workspace/}"; fi
    [[ "$task_sandbox" == "$task_root"/build/native-sandbox.* && -f "$task_sandbox/app/type-app" ]] || { echo '独立角色隔离目录无效。' >&2; exit 1; }
    task_tag="$task_outbox_tag"
    if [[ "$task_role" == scheduler ]]; then task_tag="$task_scheduler_tag"; fi
    docker build --file tools/runtime.Dockerfile --label "$task_label" --tag "$task_tag" "$task_sandbox" > "$task_work/image-$task_role.log" 2>&1
    task_images+=("$task_tag")
done
task_outbox_image="$(docker image inspect --format '{{.Id}}' "$task_outbox_tag")"
task_scheduler_image="$(docker image inspect --format '{{.Id}}' "$task_scheduler_tag")"
task_redis_image="$(docker image inspect --format '{{.Id}}' "${TYPE_TEST_REDIS_IMAGE:-redis:8.10.1}")"
docker network create --label "$task_label" "$task_network" >/dev/null
task_network_created=1
docker volume create --label "$task_label" "$task_volume" >/dev/null
task_volume_created=1
# 本例只证明跨独立角色处理同一条消息；不把临时 Redis 当作持久化故障恢复证据。
docker run --detach --name "$task_redis" --pull=never --label "$task_label" --network "$task_network" \
    --user redis --read-only --cap-drop=ALL --security-opt=no-new-privileges --memory=128m --tmpfs '/data:rw,nosuid,nodev,size=32m,mode=1777' \
    "$task_redis_image" redis-server --save '' --appendonly no --protected-mode no --maxmemory 16mb --maxmemory-policy noeviction >/dev/null
task_ready=0
for ((task_attempt = 0; task_attempt < 50; task_attempt++)); do
    if [[ "$(docker exec "$task_redis" redis-cli ping 2>/dev/null || true)" == PONG ]]; then task_ready=1; break; fi
    sleep 0.1
done
[[ "$task_ready" == 1 ]] || { echo '本轮独立 Redis 未就绪。' >&2; exit 1; }
"$task_php" tests/native-roles.php verify "$task_work" "$task_identity" "$task_network" "$task_redis" "$task_volume" "$task_outbox_image" "$task_scheduler_image"
cleanup_resources
"$task_php" tests/native-roles.php complete "$task_work"
