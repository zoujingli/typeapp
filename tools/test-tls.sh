#!/usr/bin/env bash
set -euo pipefail
task_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
task_binary="${1:---php}"
task_execution="${TYPE_TEST_EXECUTION:-container}"
case "$task_execution" in container|host) ;; *) exit 1;; esac
task_id="type-tls-$(date +%s)-$$"
task_directory="$(mktemp -d "$task_root/build/tls-check.XXXXXX")"
task_network="$task_id-network"
task_mysql="$task_id-mysql"
task_pgsql="$task_id-pgsql"
task_redis="$task_id-redis"
mkdir -p "$task_directory/private" "$task_directory/server" "$task_directory/client"
chmod 700 "$task_directory/private"
task_log="$task_directory/certificates.log"
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$task_directory/private/ca.key" -out "$task_directory/client/ca.pem" -subj /CN=Type-Test-CA -days 2 >"$task_log" 2>&1
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$task_directory/private/wrong-ca.key" -out "$task_directory/client/wrong-ca.pem" -subj /CN=Type-Wrong-CA -days 2 >>"$task_log" 2>&1
openssl req -newkey rsa:2048 -nodes -keyout "$task_directory/server/server.key" -out "$task_directory/private/server.csr" -subj /CN=tls-mysql \
  -addext 'subjectAltName=DNS:tls-mysql,DNS:tls-pgsql,DNS:tls-redis,IP:127.0.0.20,IP:127.0.0.21,IP:127.0.0.22' >>"$task_log" 2>&1
openssl x509 -req -in "$task_directory/private/server.csr" -CA "$task_directory/client/ca.pem" -CAkey "$task_directory/private/ca.key" \
  -CAcreateserial -days 2 -copy_extensions copy -out "$task_directory/server/server.pem" >>"$task_log" 2>&1
cp "$task_directory/client/ca.pem" "$task_directory/server/ca.pem"
cleanup() {
  local task_status=$?
  for task_container in "$task_mysql" "$task_pgsql" "$task_redis"; do
    if [[ "$(docker inspect -f '{{ index .Config.Labels "type-app.tls" }}' "$task_container" 2>/dev/null || true)" == "$task_id" ]]; then docker rm --force "$task_container" >/dev/null; fi
  done
  if [[ "$(docker network inspect -f '{{ index .Labels "type-app.tls" }}' "$task_network" 2>/dev/null || true)" == "$task_id" ]]; then docker network rm "$task_network" >/dev/null; fi
  return "$task_status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
task_mysql_ports=(--expose 3306); task_pgsql_ports=(--expose 5432); task_redis_ports=(--expose 6379)
if [[ "$task_execution" == host ]]; then
  task_mysql_ports=(-p 127.0.0.20:3306:3306 -p 127.0.0.30:3306:3306)
  task_pgsql_ports=(-p 127.0.0.21:5432:5432 -p 127.0.0.31:5432:5432)
  task_redis_ports=(-p 127.0.0.22:6379:6379 -p 127.0.0.32:6379:6379)
fi
docker network create --label "type-app.tls=$task_id" "$task_network" >/dev/null
docker run -d --name "$task_mysql" --network "$task_network" --network-alias tls-mysql --network-alias wrong-mysql "${task_mysql_ports[@]}" \
  --label "type-app.tls=$task_id" --mount "type=bind,source=$task_directory/server,target=/input-tls,readonly" --env MYSQL_ROOT_PASSWORD=type-tls-test-only --env MYSQL_DATABASE=type_app_test \
  --entrypoint sh mysql:8.4.11 -c 'mkdir /tmp/type-tls; cp /input-tls/* /tmp/type-tls/; chown -R mysql:mysql /tmp/type-tls; chmod 600 /tmp/type-tls/server.key; exec docker-entrypoint.sh mysqld --ssl-ca=/tmp/type-tls/ca.pem --ssl-cert=/tmp/type-tls/server.pem --ssl-key=/tmp/type-tls/server.key --require-secure-transport=ON' >/dev/null
docker run -d --name "$task_pgsql" --network "$task_network" --network-alias tls-pgsql --network-alias wrong-pgsql "${task_pgsql_ports[@]}" \
  --label "type-app.tls=$task_id" --mount "type=bind,source=$task_directory/server,target=/input-tls,readonly" --env POSTGRES_USER=type_app --env POSTGRES_PASSWORD=type-tls-test-only --env POSTGRES_DB=type_app_test \
  --entrypoint sh postgres:17-bookworm -c 'mkdir /tmp/type-tls; cp /input-tls/* /tmp/type-tls/; chown -R postgres:postgres /tmp/type-tls; chmod 600 /tmp/type-tls/server.key; exec docker-entrypoint.sh postgres -c ssl=on -c ssl_ca_file=/tmp/type-tls/ca.pem -c ssl_cert_file=/tmp/type-tls/server.pem -c ssl_key_file=/tmp/type-tls/server.key' >/dev/null
docker run -d --name "$task_redis" --network "$task_network" --network-alias tls-redis --network-alias wrong-redis "${task_redis_ports[@]}" \
  --label "type-app.tls=$task_id" --mount "type=bind,source=$task_directory/server,target=/input-tls,readonly" \
  --entrypoint sh redis:8.10.1 -c 'mkdir /tmp/type-tls; cp /input-tls/* /tmp/type-tls/; chown -R redis:redis /tmp/type-tls; chmod 600 /tmp/type-tls/server.key; exec docker-entrypoint.sh redis-server --port 0 --tls-port 6379 --tls-cert-file /tmp/type-tls/server.pem --tls-key-file /tmp/type-tls/server.key --tls-ca-cert-file /tmp/type-tls/ca.pem --tls-auth-clients no --unixsocket /tmp/type-redis.sock --save ""' >/dev/null
task_ready=0
for ((task_attempt=0; task_attempt<200; task_attempt++)); do
  if docker exec "$task_mysql" mysqladmin --host=127.0.0.1 --password=type-tls-test-only --ssl-mode=REQUIRED ping --silent >/dev/null 2>&1 \
    && docker exec "$task_pgsql" pg_isready -h 127.0.0.1 -U type_app -d type_app_test >/dev/null 2>&1 \
    && [[ "$(docker exec "$task_redis" redis-cli -s /tmp/type-redis.sock ping 2>/dev/null || true)" == PONG ]]; then task_ready=1; break; fi
  sleep 0.25
done
if [[ "$task_ready" != 1 ]]; then for task_container in "$task_mysql" "$task_pgsql" "$task_redis"; do docker logs "$task_container" >&2; done; exit 1; fi
for task_driver in mysql pgsql redis; do
  if [[ "$task_execution" == host ]]; then
    case "$task_driver" in mysql) task_address=127.0.0.20; task_wrong=127.0.0.30;; pgsql) task_address=127.0.0.21; task_wrong=127.0.0.31;; redis) task_address=127.0.0.22; task_wrong=127.0.0.32;; esac
    TYPE_TLS_HOST="$task_address" TYPE_TLS_WRONG_HOST="$task_wrong" TYPE_TLS_CA="$task_directory/client/ca.pem" TYPE_TLS_WRONG_CA="$task_directory/client/wrong-ca.pem" TYPE_TLS_PASSWORD=type-tls-test-only \
      php "$task_root/tests/tls.php" "$task_binary" "$task_driver"
  else
    docker run --rm --network "$task_network" --mount "type=bind,source=$task_root,target=/workspace" --mount "type=bind,source=$task_directory/client,target=/tls-client,readonly" \
      --mount "type=bind,source=${TYPE_PHPX_SDK:-$task_root/vendor/swoole/phpx},target=/opt/phpx,readonly" --env LD_LIBRARY_PATH=/opt/phpx/lib:/usr/local/lib \
      --env "TYPE_TLS_HOST=tls-$task_driver" --env "TYPE_TLS_WRONG_HOST=wrong-$task_driver" --env TYPE_TLS_CA=/tls-client/ca.pem --env TYPE_TLS_WRONG_CA=/tls-client/wrong-ca.pem --env TYPE_TLS_PASSWORD=type-tls-test-only \
      --workdir /workspace "${TYPE_TEST_IMAGE:-type-app-toolchain:redis-local}" php tests/tls.php "$task_binary" "$task_driver"
  fi
done
