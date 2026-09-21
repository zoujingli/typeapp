#!/usr/bin/env bash
set -euo pipefail

# 构建固定上游并应用 TypeApp 已核验的 Swoole 接缝；输出可复制的动态模块路径。
: "${GITHUB_WORKSPACE:?需要 GitHub 工作区}"
: "${RUNNER_TEMP:?需要 runner 临时目录}"
: "${PHP_HOME:?需要锁定的 PHP SDK}"
: "${SWOOLE_CONFIGURE_OPTS:?需要 Swoole 配置选项}"

task_root="$GITHUB_WORKSPACE"
task_reference='0f3bee2f0ed8704ce33a336e7feabb0115411dd7'
task_archive="$RUNNER_TEMP/swoole-src.tar.gz"
task_source="$RUNNER_TEMP/swoole-src-$task_reference"
task_digest='b830fc102797143dd94a7603400a203e0d2228bd222c71a12c27d6fe62dac3ea'
task_module="$task_root/.cache/native-modules/swoole.so"

curl --fail --location --silent --show-error \
    "https://codeload.github.com/swoole/swoole-src/tar.gz/$task_reference" \
    --output "$task_archive"
if command -v sha256sum >/dev/null 2>&1; then
    task_actual_digest="$(sha256sum "$task_archive" | awk '{print $1}')"
else
    task_actual_digest="$(shasum -a 256 "$task_archive" | awk '{print $1}')"
fi
[[ "$task_actual_digest" == "$task_digest" ]] || {
    echo 'Swoole 固定源码摘要不符。' >&2
    exit 1
}
tar -xzf "$task_archive" -C "$RUNNER_TEMP"
[[ -d "$task_source" ]] || { echo 'Swoole 固定源码目录不存在。' >&2; exit 1; }

"$PHP_HOME/bin/php" -n -r '
require $argv[1] . "/plugin/type-build/src/SwooleThreadSource.php";
require $argv[1] . "/plugin/type-build/src/SwooleHttpSource.php";
require $argv[1] . "/plugin/type-build/src/SwooleSocketSource.php";
$directory = $argv[2];
$report = [];
$report["thread"] = (new Type\\Build\\SwooleThreadSource())->apply($directory);
$report["http"] = (new Type\\Build\\SwooleHttpSource())->apply($directory);
$report["socket"] = (new Type\\Build\\SwooleSocketSource())->apply($directory);
$report["tls"] = (new Type\\Build\\SwooleSocketSource())->applyTls($directory);
fwrite(STDERR, json_encode($report, JSON_THROW_ON_ERROR) . PHP_EOL);
' "$task_root" "$task_source"

read -r -a task_options <<< "$SWOOLE_CONFIGURE_OPTS"
(
    cd "$task_source"
    "$(command -v phpize)" >/dev/null
    ./configure --with-php-config="$(command -v php-config)" --enable-swoole=shared "${task_options[@]}" >/dev/null
    make -j2 >/dev/null
)
[[ -f "$task_source/modules/swoole.so" ]] || { echo 'Swoole 适配模块构建失败。' >&2; exit 1; }
mkdir -p "$(dirname "$task_module")"
cp "$task_source/modules/swoole.so" "$task_module"
[[ -f "$task_module" ]] || { echo 'Swoole 适配模块复制失败。' >&2; exit 1; }
printf '%s\n' "$task_module"
