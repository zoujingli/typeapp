#!/usr/bin/env bash
# Linux 部署入口；只发布 docs/build-site.sh 的静态导出。
set -euo pipefail
umask 022

log() { printf '[%s] %s\n' "$(date -u +%FT%TZ)" "$*"; }
fail() { log "ERROR $*" >&2; exit 1; }
repo=''
publish_root=''
branch=main
while [[ $# -gt 0 ]]; do
    case "$1" in
        --repo|--publish-root|--branch)
            [[ $# -ge 2 && -n "$2" ]] || fail "参数缺少值：$1"
            case "$1" in
                --repo) repo=$2 ;; --publish-root) publish_root=$2 ;; --branch) branch=$2 ;;
            esac
            shift 2 ;;
        *) fail "用法：$0 --repo 源码检出目录 --publish-root 发布目录 [--branch main]" ;;
    esac
done
[[ -n "$repo" && -n "$publish_root" ]] || fail '必须指定源码检出目录和发布目录'
for dependency in git flock timeout realpath tar find sort cmp; do
    command -v "$dependency" >/dev/null || fail "缺少 Linux 部署依赖：$dependency"
done
repo=$(realpath -e -- "$repo")
git -C "$repo" rev-parse --git-dir >/dev/null
git check-ref-format "refs/heads/$branch" >/dev/null
mkdir -p -- "$publish_root"
publish_root=$(realpath -e -- "$publish_root")
[[ "$publish_root" != / && "$repo" != "$publish_root" && "$publish_root/" != "$repo/"* && "$repo/" != "$publish_root/"* ]] || fail '源码检出与发布目录必须相互独立'
[[ ! -e "$publish_root/current" || -L "$publish_root/current" ]] || fail 'current 必须是发布符号链接，不能覆盖已有目录'
cd -- "$repo"

# 锁覆盖拉取、导出、切换和清理；超时会终止整个子进程组，最多 120 秒。
if [[ ${TYPEAPP_DOCS_WORKER:-0} != 1 ]]; then
    exec 9>"$publish_root/.deploy.lock"
    if ! flock -n 9; then log 'SKIP 已有同步任务运行'; exit 0; fi
    script=$(realpath -e -- "${BASH_SOURCE[0]}")
    # runner 由管理员独立安装，不随仓库更新；记录自身摘要，便于与仓库脚本核对版本。
    runner_id=unavailable
    if command -v sha256sum >/dev/null; then runner_id=$(sha256sum -- "$script" | cut -d' ' -f1); fi
    log "CHECK branch=$branch runner=$runner_id"
    status=0
    TYPEAPP_DOCS_WORKER=1 timeout --kill-after=5s 115s bash "$script" \
        --repo "$repo" --publish-root "$publish_root" --branch "$branch" || status=$?
    if [[ "$status" != 0 ]]; then log "ERROR 同步失败或超时 status=$status，保留当前版本" >&2; fi
    exit "$status"
fi

export GIT_TERMINAL_PROMPT=0
git -C "$repo" fetch --no-tags origin "refs/heads/$branch"
commit=$(git -C "$repo" rev-parse --verify 'FETCH_HEAD^{commit}')
current=$(readlink -- "$publish_root/current" || true)
if [[ -d "$publish_root/current" && "$current" == releases/*-"$commit" ]]; then
    log "UNCHANGED commit=$commit"
    exit 0
fi

work=$(mktemp -d "$publish_root/.deploy.XXXXXXXX")
release=''
cleanup() {
    status=$?
    # 只清理本轮 mktemp 目录和未成为 current 的本轮版本。
    if [[ "$work" == "$publish_root"/.deploy.* && -d "$work" ]]; then rm -rf -- "$work"; fi
    if [[ -n "$release" && -d "$publish_root/releases/$release" && $(readlink -- "$publish_root/current" || true) != "releases/$release" ]]; then
        rm -rf -- "$publish_root/releases/$release"
    fi
    return "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
mkdir -- "$work/source"
# 从确定的提交解包，不改动检出目录，也不从正在运行的脚本拉取覆盖自身。
git -C "$repo" archive "$commit" | tar -x -C "$work/source"
# 仍执行已安装副本，不采用仓库脚本的逻辑；只核对其内容是否已由维护者安装到本机。
installed=$(realpath -e -- "${BASH_SOURCE[0]}")
archived="$work/source/tools/deploy-docs-site.sh"
[[ -f "$archived" && ! -L "$archived" ]] || fail '当前提交缺少 tools/deploy-docs-site.sh，无法核对已安装 runner'
if ! cmp -s -- "$installed" "$archived"; then
    fail '已安装的文档发布 runner 与当前提交中的 tools/deploy-docs-site.sh 不一致，请先更新已安装 runner 后再同步'
fi
[[ -z $(find "$work/source/docs" -type l -print -quit) ]] || fail '文档源包含符号链接'
# 固定产品 tag 单独获取，不用 --force 移动已有标签。文档修正提交可独立于当前分支。
release_version='' documentation_commit=''
while IFS='=' read -r key value; do
    case "$key" in version) release_version=$value ;; documentation) documentation_commit=$value ;; esac
done < "$work/source/docs/site-release"
[[ "$release_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-rc\.[0-9]+)?$ && "$documentation_commit" =~ ^[0-9a-f]{40}$ ]] || fail '发布文档来源无效'
git -C "$repo" fetch --no-tags origin "refs/tags/v$release_version:refs/tags/v$release_version"
if ! git -C "$repo" cat-file -e "$documentation_commit^{commit}" 2>/dev/null; then
    git -C "$repo" fetch --no-tags origin "$documentation_commit"
fi
output=$(bash "$work/source/docs/build-site.sh" --repository "$repo" --source "$commit")
[[ "$output" == "$work/source/build/docs-site."* && -d "$output" && ! -L "$output" ]] || fail '导出目录无效'
[[ $(realpath -e -- "$output") == "$output" ]] || fail '导出目录包含间接路径'

# 独立检查发布边界，防止导出脚本将来变更时意外发布研发资料或可执行文件。
[[ -d "$output/next" && -s "$output/site-manifest.json" ]] || fail '缺少开发通道或站点身份'
for channel in "$output" "$output/next"; do
    for required in index.html README.md _sidebar.md _navbar.md _404.md .nojekyll assets guide LICENSE NOTICE channel.js; do
        [[ -e "$channel/$required" ]] || fail "缺少站点文件：$required"
    done
    for required in index.html README.md _sidebar.md _navbar.md _404.md channel.js LICENSE NOTICE; do
        [[ -s "$channel/$required" ]] || fail "站点文件为空：$required"
    done
    [[ -d "$channel/assets" && -d "$channel/guide" ]] || fail '资源和指南必须是目录'
done
manifest=$(cat "$output/site-manifest.json")
[[ "$manifest" == *"\"sourceCommit\":\"$commit\",\"sourceState\":\"commit\""* ]] || fail '站点源码身份不匹配'
while IFS= read -r -d '' entry; do
    relative=${entry#"$output/"}
    [[ ! -L "$entry" && ( -f "$entry" || -d "$entry" ) ]] || fail "拒绝非普通文件：$relative"
    if [[ "$relative" == next ]]; then [[ -d "$entry" ]] || fail '开发通道必须是目录'; continue; fi
    if [[ "$relative" == site-manifest.json ]]; then [[ -f "$entry" ]] || fail '站点身份必须是文件'; continue; fi
    relative=${relative#next/}
    case "$relative" in
        index.html|README.md|_sidebar.md|_navbar.md|_404.md|.nojekyll|LICENSE|NOTICE|channel.js) [[ -f "$entry" ]] || fail "必须是文件：$relative" ;;
        assets|guide|assets/*|guide/*)
            [[ "$relative" != */.* && "$relative" =~ ^[a-zA-Z0-9_./-]+$ ]] || fail "拒绝隐藏或无效路径：$relative"
            if [[ -f "$entry" ]]; then
                case "$relative" in
                    *.md|*.js|*.css|*.svg|*.png|*.jpg|*.jpeg|*.webp|*.ico|*.woff|*.woff2|*.txt|*/LICENSE|*/LICENSE.*|*/NOTICE) ;;
                    *) fail "拒绝未允许的静态文件：$relative" ;;
                esac
            fi ;;
        *) fail "拒绝发布白名单之外的内容：$relative" ;;
    esac
done < <(find "$output" -mindepth 1 -print0)

# 宝塔还会在站点内写验证文件副本。由部署用户预建空目录，避免 root 创建目录后
# 使旧版本无法回收；实际 ACME 请求应映射到版本之外的宝塔原生验证目录。
mkdir -p -- "$output/.well-known/acme-challenge"
find "$output" -type d -exec chmod 755 {} +
find "$output" -type f -exec chmod 644 {} +
mkdir -p -- "$publish_root/releases"
candidate_release="$(date -u +%Y%m%dT%H%M%S%N)-$commit"
[[ ! -e "$publish_root/releases/$candidate_release" ]] || fail '版本目录已存在，请检查发布记录'
release=$candidate_release
mv -- "$output" "$publish_root/releases/$release"
ln -s "releases/$release" "$work/current"
mv -Tf -- "$work/current" "$publish_root/current"
log "PUBLISHED commit=$commit release=releases/$release"

# 版本名只含时间与提交号；保留本次及最近两次成功版本。
kept=0
while IFS= read -r candidate; do
    [[ "$candidate" =~ ^[0-9]{8}T[0-9]{15}-[0-9a-f]{40,64}$ ]] || continue
    [[ ! -L "$publish_root/releases/$candidate" ]] || continue
    kept=$((kept + 1))
    if [[ "$kept" -gt 3 && "$candidate" != "$release" ]]; then
        rm -rf -- "$publish_root/releases/$candidate"
    fi
done < <(find "$publish_root/releases" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort -r)
