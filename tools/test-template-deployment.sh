#!/usr/bin/env bash
# 内嵌 PHP 代码故意使用单引号，不能让 shell 展开 PHP 变量。
# shellcheck disable=SC2016
set -euo pipefail

task_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
task_php="${TYPE_TEST_PHP:-php}"
task_driver="${1:?用法：test-template-deployment.sh mysql|pgsql|sqlite [--host] [--remote]}"
shift
case "$task_driver" in mysql|pgsql|sqlite) ;; *) echo '模板部署驱动无效。' >&2; exit 1 ;; esac
task_host=0
task_remote=()
while [[ $# -gt 0 ]]; do
    case "$1" in --host) task_host=1 ;; --remote) task_remote=(--remote) ;; *) echo '未知模板部署参数。' >&2; exit 1 ;; esac
    shift
done
mkdir -p "$task_root/build"
task_work="$(mktemp -d "$task_root/build/template-deployment-$task_driver-XXXXXX")"
task_relative="${task_work#"$task_root/"}"
task_tag="type-template-test:$(date +%s)-${task_work##*-}"
task_image_created=0
cleanup() {
    local task_status=$?
    if [[ "$task_image_created" == 1 ]]; then docker image rm "$task_tag" >/dev/null || true; fi
    if [[ "$task_status" != 0 ]]; then echo "模板无源码部署尚未通过，现场保留：$task_work" >&2; fi
    return "$task_status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

task_database_keys=(TYPE_MYSQL_HOST TYPE_MYSQL_PORT TYPE_MYSQL_DATABASE TYPE_MYSQL_USER TYPE_MYSQL_PASSWORD
    TYPE_PGSQL_HOST TYPE_PGSQL_PORT TYPE_PGSQL_DATABASE TYPE_PGSQL_USER TYPE_PGSQL_PASSWORD)
if [[ "$task_host" == 1 ]]; then
    # Linux CI 直接使用当前已经固定的工具链与 Composer 配置；仍重新独立安装模板。
    export TYPE_TEMPLATE_NETWORK="${TYPE_TEMPLATE_NETWORK:-host}"
    TYPE_TEMPLATE_PREPARED_FILE="$task_work/consumer.json" "$task_php" "$task_root/tests/application-template.php" "$task_driver" --native --build-only "${task_remote[@]}" \
        2>&1 | tee "$task_work/build.log"
    task_consumer="$("$task_php" -r '$record=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $record["consumer"];' "$task_work/consumer.json")"
    bash "$task_root/tools/make-native-sandbox.sh" "$task_consumer/build/type-project" > "$task_work/sandbox.txt"
else
    # 本地 macOS 用既有 Linux 工具链构建；运行镜像完全独立，不挂 SDK 或主仓。
    [[ ${#task_remote[@]} == 0 ]] || { echo '公开模板消费请在已准备分发报告与模板检出的 Linux CI 使用 --host --remote。' >&2; exit 1; }
    task_source_image="${TYPE_TEST_IMAGE:-type-app-toolchain:redis-local}"
    task_builder="$(docker image inspect --format '{{.Id}}' "$task_source_image")"
    task_sdk="${TYPE_PHPX_SDK:-$task_root/vendor/swoole/phpx}"
    task_sdk="$(cd "$task_sdk" && pwd)"
    task_composer="${COMPOSER_BINARY:-$(command -v composer)}"
    task_cache="${COMPOSER_CACHE_DIR:-$task_root/.cache/composer}"
    mkdir -p "$task_cache"
    export TYPE_TEMPLATE_NETWORK="${TYPE_TEMPLATE_NETWORK:-type-app-tests}"
    export TYPE_MYSQL_HOST="${TYPE_MYSQL_HOST:-type-app-mysql-test}" TYPE_PGSQL_HOST="${TYPE_PGSQL_HOST:-type-app-pgsql-test}"
    task_mounts=(--mount "type=bind,source=$task_root,target=/workspace" --mount "type=bind,source=$task_sdk,target=/opt/phpx,readonly"
        --mount "type=bind,source=$task_composer,target=/usr/local/bin/composer,readonly" --mount "type=bind,source=$task_cache,target=/task-composer-cache")
    task_builder_env=(--env PHP_HOME=/usr/local --env PHPX_HOME=/opt/phpx --env LD_LIBRARY_PATH=/opt/phpx/lib:/usr/local/lib
        --env COMPOSER_CACHE_DIR=/task-composer-cache --env COMPOSER_MAX_PARALLEL_HTTP=1)
    docker run --rm --pull=never --network "$TYPE_TEMPLATE_NETWORK" --cpus=2 "${task_mounts[@]}" "${task_builder_env[@]}" \
        --env "TYPE_TEMPLATE_PREPARED_FILE=/workspace/$task_relative/consumer.json" --workdir /workspace "$task_builder" \
        php tests/application-template.php "$task_driver" --native --build-only 2>&1 | tee "$task_work/build.log"
    task_consumer="$("$task_php" -r '$record=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $record["consumer"];' "$task_work/consumer.json")"
    docker run --rm --pull=never --network=none --cpus=2 "${task_mounts[@]}" "${task_builder_env[@]}" --workdir /workspace "$task_builder" \
        bash tools/make-native-sandbox.sh "$task_consumer/build/type-project" > "$task_work/sandbox.txt"
    task_admin=(docker run --rm --pull=never --network "$TYPE_TEMPLATE_NETWORK" --read-only --cap-drop=ALL --security-opt=no-new-privileges
        --tmpfs '/tmp:rw,nosuid,nodev,size=16m' --mount "type=bind,source=$task_root,target=/workspace,readonly" --workdir /workspace)
    for task_key in "${task_database_keys[@]}"; do task_admin+=(--env "$task_key"); done
    task_admin+=("$task_builder" php tests/template-deployment.php)
    TYPE_TEMPLATE_ADMIN_COMMAND="$("$task_php" -r 'echo json_encode(array_slice($argv,1),JSON_THROW_ON_ERROR);' -- "${task_admin[@]}")"
    export TYPE_TEMPLATE_ADMIN_COMMAND
fi

task_sandbox="$(tail -n 1 "$task_work/sandbox.txt")"
if [[ "$task_host" == 0 ]]; then task_sandbox="$task_root/${task_sandbox#/workspace/}"; fi
[[ "$task_sandbox" == "$task_root"/build/native-sandbox.* && -f "$task_sandbox/app/type-app" ]] || { echo '无源码打包目录无效。' >&2; exit 1; }
docker build --file "$task_root/tools/runtime.Dockerfile" --tag "$task_tag" "$task_sandbox" 2>&1 | tee "$task_work/image.log"
task_image_created=1
task_image="$(docker image inspect --format '{{.Id}}' "$task_tag")"
"$task_php" "$task_root/tests/template-deployment.php" run "$task_work/consumer.json" "$task_image" "$task_work"
