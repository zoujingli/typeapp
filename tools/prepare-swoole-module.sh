#!/usr/bin/env bash
set -euo pipefail
trap 'task_status=$?; echo "Swoole 适配构建在第 ${LINENO} 行失败（退出码 ${task_status}）。" >&2' ERR

# 默认复用仓库内模块；维护者显式选择源码构建时才下载固定上游。
: "${PHP_HOME:?需要锁定的 PHP SDK}"

task_root="${GITHUB_WORKSPACE:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
if [[ "${TYPE_SWOOLE_BUILD_FROM_SOURCE:-0}" != 1 ]]; then
    task_module="$("$PHP_HOME/bin/php" -n "$task_root/tools/select-swoole-module.php")"
    echo "复用项目内置 Swoole：$task_module" >&2
    printf '%s\n' "$task_module"
    exit 0
fi
: "${RUNNER_TEMP:?需要 runner 临时目录}"
: "${SWOOLE_CONFIGURE_OPTS:?需要 Swoole 配置选项}"
# 固定 6.3 开发线的受审提交，运行时报告 6.3.0RC1；不得用浮动分支或发行名称代替源码身份。
task_reference='4aff74a9ac086458d1c5251e71ac6e080f68b390'
task_archive="$RUNNER_TEMP/swoole-src.tar.gz"
task_source="$RUNNER_TEMP/swoole-src-$task_reference"
task_digest='63598eba7d2a36d8820b1501854161e5c326ab30a32a419e3aa0e4d5154936cd'
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
echo '已准备固定 Swoole 源码。' >&2

task_patch="$(mktemp "${TMPDIR:-/tmp}/swoole-patch.XXXXXX.php")"
cat >"$task_patch" <<'PHP'
<?php
declare(strict_types=1);
require $argv[1] . '/plugin/type-build/src/SwooleThreadSource.php';
require $argv[1] . '/plugin/type-build/src/SwooleHttpSource.php';
require $argv[1] . '/plugin/type-build/src/SwooleSocketSource.php';
$directory = $argv[2];
$report = [];
$report['thread'] = (new Type\Build\SwooleThreadSource())->apply($directory);
$report['http'] = (new Type\Build\SwooleHttpSource())->apply($directory);
$report['socket'] = (new Type\Build\SwooleSocketSource())->apply($directory);
$report['tls'] = (new Type\Build\SwooleSocketSource())->applyTls($directory);
fwrite(STDERR, json_encode($report, JSON_THROW_ON_ERROR) . PHP_EOL);
PHP
"$PHP_HOME/bin/php" -n "$task_patch" "$task_root" "$task_source"
rm -f "$task_patch"

read -r -a task_options <<< "$SWOOLE_CONFIGURE_OPTS"
command -v phpize >&2
command -v php-config >&2
(
    cd "$task_source"
    "$(command -v phpize)" >/dev/null
    ./configure --with-php-config="$(command -v php-config)" --enable-swoole=shared "${task_options[@]}" >&2
    make -j2 >&2
)
[[ -f "$task_source/modules/swoole.so" ]] || { echo 'Swoole 适配模块构建失败。' >&2; exit 1; }
mkdir -p "$(dirname "$task_module")"
cp "$task_source/modules/swoole.so" "$task_module"
[[ -f "$task_module" ]] || { echo 'Swoole 适配模块复制失败。' >&2; exit 1; }
echo "Swoole 适配模块已就绪：$task_module" >&2
printf '%s\n' "$task_module"
