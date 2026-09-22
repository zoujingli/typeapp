#!/usr/bin/env bash
set -euo pipefail
trap 'task_status=$?; echo "Swoole 适配构建在第 ${LINENO} 行失败（退出码 ${task_status}）。" >&2' ERR

# 同一份固定源码可以产出编译机用的共享模块，或按调用方开关抽出的产品目标文件。
# 默认仍只构建共享模块，标准输出保持模块路径，避免既有 CI 再编一次。
task_root="${TYPE_APP_ROOT:-${GITHUB_WORKSPACE:-}}"
task_temp="${RUNNER_TEMP:-${TMPDIR:-/tmp}}"
if [[ -z "$task_root" || -z "$task_temp" ]]; then
    echo '需要工作区与临时目录。' >&2
    exit 1
fi
: "${PHP_HOME:?需要锁定的 PHP SDK}"
: "${SWOOLE_CONFIGURE_OPTS:?需要 Swoole 配置选项}"

export PATH="$PHP_HOME/bin:$PATH"
task_phpize="$PHP_HOME/bin/phpize"
task_php_config="$PHP_HOME/bin/php-config"
[[ -x "$task_phpize" ]] || task_phpize="$(command -v phpize || true)"
[[ -x "$task_php_config" ]] || task_php_config="$(command -v php-config || true)"
if [[ -z "$task_phpize" || -z "$task_php_config" ]]; then
    echo '需要 phpize 与 php-config。' >&2
    exit 1
fi

task_reference='0f3bee2f0ed8704ce33a336e7feabb0115411dd7'
task_digest='b830fc102797143dd94a7603400a203e0d2228bd222c71a12c27d6fe62dac3ea'
task_artifact="${TYPE_SWOOLE_ARTIFACT:-shared}"
task_source="${TYPE_SWOOLE_SOURCE:-$task_temp/swoole-src-$task_reference}"
task_module="$task_root/.cache/native-modules/swoole.so"
task_opts_file="$task_source/.typeapp-configure-opts"
read -r -a task_options <<< "$SWOOLE_CONFIGURE_OPTS"

if [[ ! -f "$task_source/config.m4" ]]; then
    task_archive="$task_temp/swoole-src.tar.gz"
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
    tar -xzf "$task_archive" -C "$task_temp"
fi
[[ -d "$task_source" ]] || { echo 'Swoole 固定源码目录不存在。' >&2; exit 1; }

if [[ ! -f "$task_source/.typeapp-patches-applied" ]]; then
    "$PHP_HOME/bin/php" -n -r '
    $roots = [$argv[1] . "/plugin/type-build/src", $argv[1] . "/vendor/zoujingli/type-build/src"];
    $sourceRoot = null;
    foreach ($roots as $root) {
        if (is_file($root . "/SwooleThreadSource.php")) { $sourceRoot = $root; break; }
    }
    if ($sourceRoot === null) { fwrite(STDERR, "找不到 Swoole 适配源\n"); exit(1); }
    require $sourceRoot . "/SwooleThreadSource.php";
    require $sourceRoot . "/SwooleHttpSource.php";
    require $sourceRoot . "/SwooleSocketSource.php";
    $directory = $argv[2];
    $report = [];
    $report["thread"] = (new Type\Build\SwooleThreadSource())->apply($directory);
    $report["http"] = (new Type\Build\SwooleHttpSource())->apply($directory);
    $report["socket"] = (new Type\Build\SwooleSocketSource())->apply($directory);
    $report["tls"] = (new Type\Build\SwooleSocketSource())->applyTls($directory);
    fwrite(STDERR, json_encode($report, JSON_THROW_ON_ERROR) . PHP_EOL);
    ' "$task_root" "$task_source"
    printf '%s\n' "$task_reference" > "$task_source/.typeapp-patches-applied"
fi
echo '已准备固定 Swoole 源码。' >&2

task_log() {
    if [[ -n "${TYPE_SWOOLE_LOG:-}" ]]; then
        "$@" >>"$TYPE_SWOOLE_LOG" 2>&1
    else
        "$@" >&2
    fi
}

# 与 SwooleFeatureSelection::canonicalFlags 一致：只比较官方块名字。
canonical_opts() {
    local task_opt
    local task_names=()
    for task_opt in "$@"; do
        [[ -n "$task_opt" ]] || continue
        task_names+=("${task_opt%%=*}")
    done
    if [[ ${#task_names[@]} -eq 0 ]]; then
        printf '\n'
        return
    fi
    printf '%s\n' "${task_names[@]}" | LC_ALL=C sort | paste -sd' ' -
}

same_configure_opts() {
    [[ -f "$task_opts_file" ]] || return 1
    local task_existing
    read -r -a task_existing <<< "$(cat "$task_opts_file")"
    [[ "$(canonical_opts "${task_existing[@]}")" == "$(canonical_opts "${task_options[@]}")" ]]
}

compile_tree() {
    local task_kind="$1"
    local task_had_marker=0
    [[ -f "$task_source/.typeapp-patches-applied" ]] && task_had_marker=1
    if [[ -f "$task_source/Makefile" ]]; then
        task_log make -C "$task_source" distclean || true
    fi
    if [[ "$task_had_marker" == 1 && ! -f "$task_source/.typeapp-patches-applied" ]]; then
        printf '%s\n' "$task_reference" > "$task_source/.typeapp-patches-applied"
    fi
    [[ -f "$task_source/.typeapp-patches-applied" ]] || { echo 'Swoole 补丁未应用。' >&2; exit 1; }
    if ! task_log bash -c 'cd "$1" && "$2" && ./configure --with-php-config="$3" --enable-swoole="$4" "${@:5}" && make -j2' \
        bash "$task_source" "$task_phpize" "$task_php_config" "$task_kind" "${task_options[@]}"; then
        return 1
    fi
    printf '%s\n' "$SWOOLE_CONFIGURE_OPTS" > "$task_opts_file"
}

collect_objects() {
    # phpize 把每个编译单元放到对应目录的 .libs，例如 ext-src/.libs、src/os/.libs、
    # thirdparty/php85/pdo_pgsql/.libs。未打开的可选块根本不会生成这些 .o。
    # 根目录 .libs/swoole.o 是整模块再定位目标，不能和分文件 .o 一起 ld -r。
    local task_members=()
    local task_member
    while IFS= read -r task_member; do
        [[ -n "$task_member" ]] && task_members+=("$task_member")
    done < <(find "$task_source" -type f -path '*/.libs/*.o' ! -name 'swoole.o' | sort)
    if [[ ${#task_members[@]} -eq 0 && -f "$task_source/.libs/swoole.o" ]]; then
        task_members+=("$task_source/.libs/swoole.o")
    fi
    [[ ${#task_members[@]} -gt 0 ]] || return 1
    mkdir -p "$task_static_dir"
    if [[ ${#task_members[@]} -eq 1 ]]; then
        cp "${task_members[0]}" "$task_static_dir/swoole.o"
    else
        ld -r -o "$task_static_dir/swoole.o" "${task_members[@]}"
    fi
    [[ -s "$task_static_dir/swoole.o" ]] || return 1
    # phpize 默认 -g；调试段会把主程序胀到发布归档在 128 MiB 限制下无法完成。
    # --strip-debug / Darwin -S 只去掉 DWARF，保留链接所需的 swoole_module_entry。
    command -v strip >/dev/null || { echo '静态 Swoole 需要 strip 去掉调试段。' >&2; return 1; }
    if [[ "$(uname -s)" == Darwin ]]; then
        strip -S "$task_static_dir/swoole.o"
    else
        strip --strip-debug "$task_static_dir/swoole.o"
    fi
    [[ -s "$task_static_dir/swoole.o" ]]
}

dump_build_log() {
    if [[ -n "${TYPE_SWOOLE_LOG:-}" && -f "$TYPE_SWOOLE_LOG" ]]; then
        echo '最近的 Swoole 构建日志：' >&2
        tail -n 80 "$TYPE_SWOOLE_LOG" >&2 || true
    fi
}

build_shared() {
    echo "$task_phpize" >&2
    echo "$task_php_config" >&2
    compile_tree shared
    [[ -f "$task_source/modules/swoole.so" ]] || { echo 'Swoole 适配模块构建失败。' >&2; exit 1; }
    mkdir -p "$(dirname "$task_module")"
    cp "$task_source/modules/swoole.so" "$task_module"
    [[ -f "$task_module" ]] || { echo 'Swoole 适配模块复制失败。' >&2; exit 1; }
}

build_static() {
    : "${TYPE_SWOOLE_STATIC_DIR:?静态产物需要 TYPE_SWOOLE_STATIC_DIR}"
    task_static_dir="$TYPE_SWOOLE_STATIC_DIR"
    echo "$task_phpize" >&2
    echo "$task_php_config" >&2
    if same_configure_opts && [[ -f "$task_source/modules/swoole.so" ]] && collect_objects; then
        echo '复用已按相同开关编译的 Swoole 目标。' >&2
    elif ! compile_tree static || ! collect_objects; then
        echo 'Swoole 静态配置没有留下可链接目标，改为同一开关的共享编译并抽出 .o。' >&2
        if ! compile_tree shared || ! collect_objects; then
            dump_build_log
            echo '静态 Swoole 没有目标文件。' >&2
            exit 1
        fi
    fi
    if [[ ! -f "$task_source/modules/swoole.so" ]]; then
        compile_tree shared
        [[ -f "$task_source/modules/swoole.so" ]] || { echo '无法得到 Swoole 依赖清单。' >&2; exit 1; }
    fi
    task_curl=false
    if nm -u "$task_static_dir/swoole.o" | awk '{print $NF}' | sed 's/^_//' | grep -qx 'curl_multi_ce'; then
        task_curl=true
    fi
    "$PHP_HOME/bin/php" -n -r '
    $payload = ["objects" => [$argv[1]], "shared" => $argv[2], "flags" => ($argv[3] === "" ? [] : explode(" ", $argv[3])), "curl-multi" => $argv[4] === "true", "source" => $argv[5]];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($argv[6], $json) !== strlen($json)) { fwrite(STDERR, "无法写入静态清单\n"); exit(1); }
    ' "$task_static_dir/swoole.o" "$task_source/modules/swoole.so" "$SWOOLE_CONFIGURE_OPTS" "$task_curl" "$task_reference" "$task_static_dir/manifest.json"
    printf '%s\n' "$task_static_dir/manifest.json"
}

case "$task_artifact" in
    shared)
        build_shared
        printf '%s\n' "$task_module"
        ;;
    static)
        build_static
        ;;
    both)
        build_shared
        build_static >/dev/null
        printf '%s\n' "$task_module"
        ;;
    *)
        echo 'Swoole 产物类型无效。' >&2
        exit 1
        ;;
esac

if [[ -n "${GITHUB_ENV:-}" ]]; then
    printf 'TYPE_SWOOLE_SOURCE=%s\n' "$task_source" >> "$GITHUB_ENV"
fi
