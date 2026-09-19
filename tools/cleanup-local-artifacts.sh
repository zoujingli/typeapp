#!/usr/bin/env bash
set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
mode="dry-run"

case "${1:-}" in
    '') ;;
    --dry-run) mode="dry-run" ;;
    --apply) mode="apply" ;;
    --help|-h)
        cat <<'EOF'
用法：tools/cleanup-local-artifacts.sh [--dry-run|--apply]

默认只列出可重建的本地产物。--apply 仅清理已登记的 build 临时前缀、根目录验收截图和前端 dist；未知目录、共享缓存、运行包及 .cache 保留。
EOF
        exit 0
        ;;
    *)
        printf '未知参数：%s\n' "$1" >&2
        exit 2
        ;;
esac

git_root="$(git -C "$project_root" rev-parse --show-toplevel 2>/dev/null || true)"
if [[ "$git_root" != "$project_root" ]]; then
    printf '清理目标不是 Git 主仓：%s\n' "$project_root" >&2
    exit 1
fi
if [[ "$(git -C "$project_root" branch --show-current)" != "main" ]]; then
    printf '清理仅允许在 main 工作区执行\n' >&2
    exit 1
fi

build_root="$project_root/build"
declare -a targets=()
declare -a target_labels=()

is_removable_build_name() {
    case "$1" in
        a21-*|app-a21.*|b04-*|b16-*|broker-*|docs-deployment.*|docs-site.*|'config test '*|configuration-test-*|\
        documented-type-validate-*|io-*|iot-*|mqtt-*|notices-*|operation-docs-*|\
        package-test-*|phpunit-cache|recovery-debug*|source-rewrites-*|typephp-0.9-native.*)
            return 0
            ;;
        *)
            return 1
            ;;
    esac
}

add_target() {
    local path="$1"
    local label="$2"
    [[ -e "$path" || -L "$path" ]] || return 0
    [[ -L "$path" ]] && {
        printf '跳过符号链接：%s\n' "${path#"$project_root"/}"
        return 0
    }
    targets+=("$path")
    target_labels+=("$label")
}

if [[ -d "$build_root" ]]; then
    while IFS= read -r -d '' path; do
        name="${path##*/}"
        if [[ "$name" == *.png ]]; then
            add_target "$path" "build/$name"
        elif is_removable_build_name "$name"; then
            add_target "$path" "build/$name"
        else
            printf '保留 build 项：%s\n' "build/$name"
        fi
    done < <(find "$build_root" -mindepth 1 -maxdepth 1 -print0 | sort -z)

    for path in "$build_root"/*.png; do
        [[ -e "$path" ]] || continue
        add_target "$path" "build/${path##*/}"
    done
fi

# These are deterministic frontend outputs; sources and package stores remain intact.
add_target "$project_root/web/dist" "web/dist"
while IFS= read -r -d '' path; do
    add_target "$path" "${path#"$project_root"/}"
done < <(find "$project_root/web/internal" -mindepth 2 -maxdepth 2 -type d -name dist -print0 2>/dev/null | sort -z)

if ((${#targets[@]} == 0)); then
    printf '没有发现已登记的可重建产物。\n'
    exit 0
fi

directory_size_kib() {
    local total=0
    local path
    local -a existing=()
    for path in "$@"; do
        [[ -e "$path" ]] && existing+=("$path")
    done
    if ((${#existing[@]} > 0)); then
        total="$(du -sk "${existing[@]}" 2>/dev/null | awk '{sum += $1} END {print sum + 0}')"
    fi
    printf '%s' "$total"
}

before_kb="$(directory_size_kib "$build_root" "$project_root/web/dist" "$project_root/web/internal")"
printf '模式：%s；候选 %d 项；逻辑大小约 %.1f MiB\n' "$mode" "${#targets[@]}" "$(awk -v kb="$before_kb" 'BEGIN {printf "%.1f", kb / 1024}')"

if [[ "$mode" == "dry-run" ]]; then
    printf '使用 --apply 执行上述精确清理。\n'
    exit 0
fi

receipt_dir="$project_root/.cache/local-cleanup-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$receipt_dir"
receipt_file="$receipt_dir/cleanup.json"

printf '{\n  "mode": "apply",\n  "branch": "main",\n  "targets": [' >"$receipt_file"
for index in "${!targets[@]}"; do
    target="${targets[$index]}"
    label="${target_labels[$index]}"
    case "$target" in
        "$project_root/build"/*|"$project_root/web/dist"|"$project_root/web/internal"/*/dist) ;;
        *)
            printf '\n清理目标越界：%s\n' "$target" >&2
            exit 1
            ;;
    esac
    [[ -L "$target" ]] && { printf '\n拒绝删除符号链接：%s\n' "$target" >&2; exit 1; }
    [[ "$target" == "$project_root" || "$target" == "$project_root/build" || "$target" == "$project_root/web" ]] && {
        printf '\n拒绝删除宽泛目录：%s\n' "$target" >&2
        exit 1
    }
    [[ "$index" -gt 0 ]] && printf ',' >>"$receipt_file"
    printf '\n    %s' "$(printf '%s' "$label" | sed 's/\\/\\\\/g; s/"/\\"/g')" >>"$receipt_file"
done
printf '\n  ],\n  "before_kib": %s\n}\n' "$before_kb" >>"$receipt_file"

for target in "${targets[@]}"; do
    rm -rf -- "$target"
done

after_kb="$(directory_size_kib "$build_root" "$project_root/web/internal")"
printf '%s\n' '{' >"$receipt_file"
printf '  "mode": "apply",\n  "branch": "main",\n  "before_kib": %s,\n  "after_kib": %s,\n  "removed_kib": %s,\n  "targets": [' "$before_kb" "$after_kb" "$((before_kb > after_kb ? before_kb - after_kb : 0))" >>"$receipt_file"
for index in "${!targets[@]}"; do
    [[ "$index" -gt 0 ]] && printf ',' >>"$receipt_file"
    label="${target_labels[$index]}"
    printf '\n    "%s"' "$(printf '%s' "$label" | sed 's/\\/\\\\/g; s/"/\\"/g')" >>"$receipt_file"
done
printf '\n  ]\n}\n' >>"$receipt_file"

printf '已清理 %d 项，约释放 %.1f MiB 逻辑空间。\n' "${#targets[@]}" "$(awk -v kb="$((before_kb > after_kb ? before_kb - after_kb : 0))" 'BEGIN {printf "%.1f", kb / 1024}')"
printf '本地回执：%s\n' "${receipt_file#"$project_root"/}"
