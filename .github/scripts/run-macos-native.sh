#!/usr/bin/env bash
set -euo pipefail

# 只在可丢弃的GitHub macOS runner准备数据库；不能直接用于开发者机器。
[[ "${GITHUB_ACTIONS:-}" == true && "${RUNNER_OS:-}" == macOS && "$(uname -s)" == Darwin ]] || {
  echo '此入口只接受GitHub macOS原生runner，不在本机启动或修改数据库服务。' >&2; exit 2;
}
task_suite="${1:-}"
case "$task_suite" in contracts|application|deployment|rollout|recovery|http|orm|reliable|tls|benchmark) ;; *) exit 2;; esac
task_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$task_root"
task_work="$(mktemp -d "${RUNNER_TEMP:?}/type-native.XXXXXX")"
chmod 0700 "$task_work"
task_mysql="$(brew --prefix mysql@8.4)/bin"
task_pgsql="$(brew --prefix postgresql@17)/bin"
task_redis="$(brew --prefix redis)/bin"
task_children=()
task_pg_started=0
cleanup() {
  local task_status=$? task_pid task_attempt
  trap - EXIT
  if [[ "$task_pg_started" == 1 ]]; then
    "$task_pgsql/pg_ctl" -D "$task_work/pgsql" -m fast -t 20 -w stop >/dev/null || task_status=1
  fi
  if [[ ${#task_children[@]} -gt 0 ]]; then
    for task_pid in "${task_children[@]}"; do
      if jobs -pr | grep -Fxq "$task_pid"; then
        kill -TERM "$task_pid" 2>/dev/null || true
        for ((task_attempt=0; task_attempt<100; task_attempt++)); do
          if ! jobs -pr | grep -Fxq "$task_pid"; then break; fi
          sleep 0.1
        done
        if jobs -pr | grep -Fxq "$task_pid"; then kill -KILL "$task_pid" 2>/dev/null || true; task_status=1; fi
      fi
      wait "$task_pid" 2>/dev/null || true
    done
  fi
  # 仅删除本次明确创建的凭据文件；其余失败现场留在runner临时目录。
  for task_secret in "$task_work/pg-password" "$task_work/mysql-setup.sql"; do
    if [[ -f "$task_secret" ]]; then unlink "$task_secret"; fi
  done
  exit "$task_status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
if [[ "$task_suite" == benchmark ]]; then
  task_source="$(git rev-parse HEAD)"
  php tests/prepare-platform-benchmarks.php "${TYPE_BASE_SOURCE:?请指定用于比较的完整基准提交}" "$task_source" "$(command -v composer)" | tee "$task_work/benchmark-prepare.log"
  # 仅从本轮成功准备输出解析相对目录，不接收可执行命令或外部路径。
  task_pair="$(sed -n 's/^平台新旧基准准备完成：\(build\/platform-benchmarks-[a-f0-9]*\)\/preparation.json$/\1/p' "$task_work/benchmark-prepare.log")"
  [[ -n "$task_pair" && -f "$task_pair/preparation.json" ]]
  php tests/benchmark-pairs.php "$task_pair/old" "$task_pair/new" "$(dirname "$task_mysql")" "$(dirname "$task_pgsql")" | tee "$task_work/benchmark-measure.log"
  task_measurement="$(sed -n 's/^成对正式测量完成：\(build\/benchmark-pairs-[a-f0-9]*\/verification.json\)$/\1/p' "$task_work/benchmark-measure.log")"
  [[ -n "$task_measurement" && -f "$task_measurement" ]]
  php tests/benchmark-compare.php "$task_measurement"
  exit 0
fi
# PHP变量必须保留给PHP解释器，不能由shell展开。
# shellcheck disable=SC2016
port() { php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$n,$e);if(!$s)exit(1);echo substr(strrchr(stream_socket_get_name($s,false),":"),1);'; }
task_mysql_port="$(port)"
task_mysql_password="$(php -r 'echo bin2hex(random_bytes(24));')"
task_pgsql_password="$(php -r 'echo bin2hex(random_bytes(24));')"
echo "::add-mask::$task_mysql_password"
echo "::add-mask::$task_pgsql_password"
"$task_mysql/mysqld" --no-defaults --initialize-insecure --datadir="$task_work/mysql" >"$task_work/mysql-init.log" 2>&1
"$task_mysql/mysqld" --no-defaults --datadir="$task_work/mysql" --bind-address=127.0.0.1 --port="$task_mysql_port" \
  --socket="$task_work/mysql.sock" --mysqlx=OFF --log-error="$task_work/mysql.log" >"$task_work/mysql.stdout" 2>&1 &
task_children+=("$!")
task_ready=0
for ((task_attempt=0; task_attempt<60; task_attempt++)); do
  if MYSQL_PWD='' "$task_mysql/mysqladmin" --no-defaults --protocol=socket --socket="$task_work/mysql.sock" --user=root ping >/dev/null 2>&1; then task_ready=1; break; fi
  sleep 1
done
[[ "$task_ready" == 1 ]] || { echo '专用MySQL没有就绪。' >&2; exit 1; }
umask 077
printf "CREATE DATABASE type_app_test;\nALTER USER 'root'@'localhost' IDENTIFIED BY '%s';\n" "$task_mysql_password" >"$task_work/mysql-setup.sql"
MYSQL_PWD='' "$task_mysql/mysql" --no-defaults --protocol=socket --socket="$task_work/mysql.sock" --user=root <"$task_work/mysql-setup.sql"
unlink "$task_work/mysql-setup.sql"
task_pgsql_port="$(port)"
printf '%s\n' "$task_pgsql_password" >"$task_work/pg-password"
"$task_pgsql/initdb" -D "$task_work/pgsql" -U type_app --auth-local=trust --auth-host=scram-sha-256 --pwfile="$task_work/pg-password" >"$task_work/pgsql-init.log"
unlink "$task_work/pg-password"
task_pg_started=1
"$task_pgsql/pg_ctl" -D "$task_work/pgsql" -l "$task_work/pgsql.log" -o "-h 127.0.0.1 -p $task_pgsql_port -k $task_work" -t 60 -w start
PGPASSWORD="$task_pgsql_password" "$task_pgsql/createdb" -h 127.0.0.1 -p "$task_pgsql_port" -U type_app type_app_test
task_redis_port="$(port)"
mkdir "$task_work/redis"
"$task_redis/redis-server" --bind 127.0.0.1 --protected-mode yes --port "$task_redis_port" --save '' --appendonly no --dir "$task_work/redis" >"$task_work/redis.log" 2>&1 &
task_children+=("$!")
task_ready=0
for ((task_attempt=0; task_attempt<60; task_attempt++)); do
  if [[ "$("$task_redis/redis-cli" -h 127.0.0.1 -p "$task_redis_port" ping 2>/dev/null || true)" == PONG ]]; then task_ready=1; break; fi
  sleep 0.1
done
[[ "$task_ready" == 1 ]] || { echo '专用Redis没有就绪。' >&2; exit 1; }
export TYPE_MYSQL_HOST=127.0.0.1 TYPE_MYSQL_PORT="$task_mysql_port" TYPE_MYSQL_DATABASE=type_app_test TYPE_MYSQL_USER=root TYPE_MYSQL_PASSWORD="$task_mysql_password"
export TYPE_PGSQL_HOST=127.0.0.1 TYPE_PGSQL_PORT="$task_pgsql_port" TYPE_PGSQL_DATABASE=type_app_test TYPE_PGSQL_USER=type_app TYPE_PGSQL_PASSWORD="$task_pgsql_password"
export TYPE_REDIS_HOST=127.0.0.1 TYPE_REDIS_PORT="$task_redis_port"
export TYPE_REDIS_SERVER="$task_redis/redis-server"
"$task_mysql/mysqld" --version
"$task_pgsql/pg_ctl" --version
"$task_redis/redis-server" --version
case "$task_suite" in
  contracts)
    composer check
    composer cs-check
    composer test:unit
    composer test:embedded-resources
    composer test:testing-portable
    composer test:configuration
    composer test:helpers
    composer test:helpers-native
    composer test:operations
    composer build:operations
    composer test:operations-native
    composer test:process-signals
    composer test:development-watch
    php tests/development-generation.php
    php tests/developer-commands.php
    composer test:build-platform-native
    composer test:build-source-collection
    composer test:imports
    composer check:assembly
    composer build:commands
    composer test:commands
    composer test:assembly-consumer
    ;;
  application)
    composer typeapp:prepare
    php tests/build-native-application.php
    php tests/native-database-application.php build/app/type-app "$(dirname "$task_mysql")" "$(dirname "$task_pgsql")"
    for task_driver in mysql pgsql sqlite; do php tests/application-template.php "$task_driver" --onboarding --native --package; done
    ;;
  deployment)
    composer typeapp:build
    php tests/native-package.php build/app/type-app --archive
    composer test:service-definition
    /bin/launchctl print "gui/$(id -u)" >/dev/null
    composer test:service-launchd
    php tests/application-template.php sqlite --onboarding --native --package
    if [[ -n "${TYPE_RELEASE_VERSION:-}" ]]; then
      php tests/release-candidate.php prepare
      for task_driver in mysql pgsql sqlite; do
        php tests/release-candidate.php test "$task_driver" "$(dirname "$task_mysql")" "$(dirname "$task_pgsql")"
      done
      php tests/release-candidate.php finish
    fi
    ;;
  rollout)
    php tests/native-rollout-redis-test.php "$TYPE_REDIS_SERVER"
    task_identity="$(php -r 'echo bin2hex(random_bytes(6));')"
    php tests/prepare-packaged-rollout.php "$task_identity"
    for task_driver in mysql pgsql sqlite; do
      php tests/packaged-rollout.php "build/packaged-rollout-build-$task_identity/preparation.json" "$task_driver" --native-services
    done
    ;;
  recovery)
    export PATH="$task_mysql:$task_pgsql:$PATH"
    composer typeapp:build
    task_release="$(mktemp -d "$task_root/build/macos-recovery-ci-XXXXXX")"
    php vendor/bin/type package build/app/type-app "$task_release/release" .env.example > "$task_release/preparation.json"
    # 保留PHP变量，不让shell展开。
    # shellcheck disable=SC2016
    task_digest="$(php -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);echo $r["manifest-sha256"];' "$task_release/preparation.json")"
    for task_driver in mysql pgsql sqlite; do
      php tests/native-package-recovery.php "$task_release/release" "$task_digest" "$task_driver"
    done
    ;;
  http)
    for task_config in type-file-http type-trust-http type-log-http type-http-message type-http type-routing-http type-routing-attributes; do
      php tests/build-scenario.php --with-swoole "docs/build-config/$task_config.json"
    done
    for task_config in type-routing type-validation type-log type-log-behavior type-log-failures; do
      php tests/build-scenario.php "docs/build-config/$task_config.json"
    done
    php tests/http-message-native.php build/http-message/type-app
    php tests/http-native.php --php
    php tests/http-native.php build/http/type-app
    php tests/routing.php --php
    php tests/routing.php build/routing/type-app
    php tests/routing-http.php --php explicit
    php tests/routing-http.php --php attributes
    php tests/routing-http.php build/routing-http/type-app explicit
    php tests/routing-http.php build/routing-attributes/type-app attributes
    php tests/validation.php
    php tests/validation.php build/validation/type-app
    for task_test in log log-behavior log-failures; do
      php "tests/$task_test.php" --php
      php "tests/$task_test.php" "build/$task_test/type-app"
    done
    php tests/log-consumer.php
    php tests/log-consumer.php --native
    for task_mode in --php native; do
      if [[ "$task_mode" == native ]]; then
        php tests/file-http.php build/file-http/type-app
        php tests/http-trust.php build/trust-http/type-app
        php tests/log-http.php build/log-http/type-app
      else
        php tests/file-http.php --php
        php tests/http-trust.php --php
        php tests/log-http.php --php
      fi
    done
    TYPE_TEST_EXECUTION=host bash tools/test-file-pressure.sh build/file-http/type-app
    ;;
  orm)
    for task_config in type-query type-pagination type-models type-exact-fields type-relations type-pivots type-lifecycle type-optimistic type-identities type-read-write type-transactions type-outcomes type-migrations type-migrations-core; do
      php tests/build-scenario.php "docs/build-config/$task_config.json"
    done
    for task_group in query models lifecycle connections transactions migrations; do
      php tests/native-database-failures.php build "$(dirname "$task_mysql")" "$(dirname "$task_pgsql")" "$task_group"
    done
    for task_driver in mysql pgsql sqlite; do
      php tests/orm-suite-consumer.php "$task_driver" --native
    done
    ;;
  reliable)
    for task_config in type-operations type-cache-consistency type-outbox type-queue-leases type-queue-retries; do
      php tests/build-scenario.php "docs/build-config/$task_config.json"
    done
    php tests/build-scenario.php --with-swoole docs/build-config/type-tenant-http.json
    for task_group in combinations outbox tenant; do
      php tests/native-database-failures.php build "$(dirname "$task_mysql")" "$(dirname "$task_pgsql")" "$task_group"
    done
    php tests/native-database-failures.php build "$(dirname "$task_mysql")" "$(dirname "$task_pgsql")" tenant
    php tests/redis.php --php
    php tests/redis-security.php
    for task_consumer in redis cache queue; do
      php "tests/$task_consumer-consumer.php"
      php "tests/$task_consumer-consumer.php" --native
    done
    php tests/queue-leases.php build/queue-leases/type-app
    php tests/queue-retries.php build/queue-retries/type-app
    php tests/scheduler-consumer.php --native
    php tests/scheduler-coordination-consumer.php --native
    php tests/native-task-reliability.php "$TYPE_REDIS_SERVER"
    for task_config in type-tasks type-task-http type-backpressure-http type-tls; do
      php tests/build-scenario.php --with-swoole "docs/build-config/$task_config.json"
    done
    for task_group in tasks task-http pressure; do
      php tests/native-database-failures.php build "$(dirname "$task_mysql")" "$(dirname "$task_pgsql")" "$task_group" mysql
    done
    ;;
  tls)
    php tests/build-scenario.php --with-swoole docs/build-config/type-tls.json
    ;;
esac
# 定向入口和可靠性全量组执行完全相同的 TLS、证书拒绝与资源清理断言。
if [[ "$task_suite" == reliable || "$task_suite" == tls ]]; then
  php tests/native-database-tls.php mysql "$(dirname "$task_mysql")" build/tls/type-app
  php tests/native-database-tls.php pgsql "$(dirname "$task_pgsql")" build/tls/type-app
  php tests/native-redis-tls.php "$TYPE_REDIS_SERVER" build/tls/type-app
fi
