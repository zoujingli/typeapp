#!/usr/bin/env bash
set -euo pipefail

# 只使用调用者显式配置的本机原生工具；测试器为每次执行建立专属服务。
task_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$task_root"
[[ "$(uname -s)" == Linux && "$(id -u)" != 0 ]] || exit 2
task_suite="${1:-}"
task_build="${2:-build}"
[[ "$task_build" == build || "$task_build" == reuse ]] || exit 2
case "$task_suite" in
  contracts|orm|benchmark) task_scenes=();;
  database) task_scenes=(query pagination models exact-fields relations pivots lifecycle optimistic identities read-write transactions outcomes operations cache-consistency outbox tenant-http migrations migrations-core);;
  http) task_scenes=(http-message http validation routing routing-http routing-attributes trust-http file-http log log-behavior log-failures log-http);;
  redis) task_scenes=(redis cache psr-cache queue queue-leases queue-retries);;
  tasks) task_scenes=(tasks task-http backpressure-http tls);;
  application|recovery|rollout) task_scenes=();;
  *) echo '用法：run-linux-regression.sh <contracts|orm|database|http|redis|tasks|application|recovery|rollout|benchmark> [build|reuse]' >&2; exit 2;;
esac
mkdir -p "$task_root/build"
if [[ "$task_build" == build ]]; then
  for task_scene in "${task_scenes[@]}"; do
    task_options=()
    case "$task_scene" in http|routing-http|routing-attributes|trust-http|file-http|log-http|task-http|tasks|backpressure-http|tenant-http) task_options=(--with-swoole);; esac
    php tests/build-scenario.php "${task_options[@]}" "docs/build-config/type-$task_scene.json"
  done
fi
case "$task_suite" in
  contracts)
    task_composer="${COMPOSER_BINARY:-$(command -v composer)}"
    "$task_composer" check
    "$task_composer" cs-check
    "$task_composer" test:unit
    "$task_composer" test:testing-portable
    "$task_composer" test:configuration
    "$task_composer" test:helpers
    "$task_composer" test:helpers-native
    "$task_composer" test:process-signals
    "$task_composer" test:development-watch
    php tests/development-generation.php
    php tests/developer-commands.php
    "$task_composer" test:build-platform-native
    "$task_composer" test:build-source-collection
    "$task_composer" test:imports
    "$task_composer" check:assembly
    "$task_composer" build:commands
    "$task_composer" test:commands
    "$task_composer" test:assembly-consumer
    ;;
  orm)
    php tests/native-database-orm.php "${TYPE_MYSQL_TOOLS:?}" "${TYPE_PGSQL_TOOLS:?}" "${COMPOSER_BINARY:-$(command -v composer)}"
    ;;
  database)
    for task_group in query models lifecycle connections transactions combinations outbox tenant migrations; do
      php tests/native-database-failures.php build "${TYPE_MYSQL_TOOLS:?}" "${TYPE_PGSQL_TOOLS:?}" "$task_group"
    done
    ;;
  http)
    php tests/native-linux-regression.php http
    TYPE_HTTP_DRIVER=swoole TYPE_TEST_EXECUTION=host bash tools/test-file-pressure.sh build/file-http/type-app
    ;;
  redis)
    php tests/native-linux-regression.php redis
    php tests/native-task-reliability.php "${TYPE_REDIS_SERVER:?}"
    ;;
  tasks)
    php tests/native-database-failures.php build "${TYPE_MYSQL_TOOLS:?}" "${TYPE_PGSQL_TOOLS:?}" tasks mysql
    php tests/native-database-failures.php build "$TYPE_MYSQL_TOOLS" "$TYPE_PGSQL_TOOLS" pressure mysql
    php tests/native-database-failures.php build "$TYPE_MYSQL_TOOLS" "$TYPE_PGSQL_TOOLS" task-http mysql
    php tests/native-database-tls.php mysql "$TYPE_MYSQL_TOOLS" build/tls/type-app
    php tests/native-database-tls.php pgsql "$TYPE_PGSQL_TOOLS" build/tls/type-app
    php tests/native-redis-tls.php "${TYPE_REDIS_SERVER:?}" build/tls/type-app
    ;;
  application)
    if [[ "$task_build" == build ]]; then php tests/build-native-application.php; fi
    TYPE_HTTP_DRIVER=swoole php tests/native-database-application.php build/app/type-app "${TYPE_MYSQL_TOOLS:?}" "${TYPE_PGSQL_TOOLS:?}"
    ;;
  recovery)
    if [[ "$task_build" == build ]]; then php tests/build-native-application.php; fi
    task_work="$(mktemp -d "$task_root/build/linux-recovery-ci-XXXXXXXX")"
    php vendor/bin/type package build/app/type-app "$task_work/release" .env.example >"$task_work/preparation.json"
    php tests/native-database-recovery.php "$task_work/preparation.json" "${TYPE_MYSQL_TOOLS:?}" "${TYPE_PGSQL_TOOLS:?}"
    ;;
  rollout)
    task_identity="$(php -r 'echo bin2hex(random_bytes(6));')"
    php tests/prepare-packaged-rollout.php "$task_identity"
    php tests/native-database-rollout.php "build/packaged-rollout-build-$task_identity/preparation.json" "${TYPE_MYSQL_TOOLS:?}" "${TYPE_PGSQL_TOOLS:?}" "${TYPE_REDIS_SERVER:?}"
    ;;
  benchmark)
    task_work="$(mktemp -d "$task_root/build/linux-benchmark-ci-XXXXXXXX")"
    if [[ "$task_build" == build ]]; then
      task_source="${TYPE_NEW_SOURCE:-$(git -C "${TYPE_BENCHMARK_SOURCE_REPOSITORY:-$task_root}" rev-parse HEAD)}"
      php tests/prepare-platform-benchmarks.php "${TYPE_BASE_SOURCE:?请指定用于比较的完整基准提交}" "$task_source" \
        "${COMPOSER_BINARY:-$(command -v composer)}" | tee "$task_work/prepare.log"
      # 只解析本轮准备器返回的相对目录；所有编译结束后才进入串行测量。
      task_pair="$(sed -n 's/^平台新旧基准准备完成：\(build\/platform-benchmarks-[a-f0-9]*\)\/preparation.json$/\1/p' "$task_work/prepare.log")"
    else
      task_pair="${TYPE_PAIR_ROOT:?复用测量需要已准备的旧版及新版目录根}"
    fi
    [[ -n "$task_pair" && -f "$task_pair/preparation.json" ]]
    php tests/benchmark-pairs.php "$task_pair/old" "$task_pair/new" "${TYPE_MYSQL_TOOLS:?}" "${TYPE_PGSQL_TOOLS:?}" | tee "$task_work/measure.log"
    task_measurement="$(sed -n 's/^成对正式测量完成：\(build\/benchmark-pairs-[a-f0-9]*\/verification.json\)$/\1/p' "$task_work/measure.log")"
    [[ -n "$task_measurement" && -f "$task_measurement" ]]
    php tests/benchmark-compare.php "$task_measurement" | tee "$task_work/compare.log"
    ;;
esac
