#!/usr/bin/env bash
set -euo pipefail

docs_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
project_root="$(cd -- "$docs_root/.." && pwd)"
repository="$project_root"
source_commit=''
source_state='working-tree'
while [[ $# -gt 0 ]]; do
  case "$1" in
    --repository) [[ $# -ge 2 ]] || exit 1; repository=$2; shift 2 ;;
    --source) [[ $# -ge 2 ]] || exit 1; source_commit=$2; source_state='commit'; shift 2 ;;
    *) printf '未知导出参数：%s\n' "$1" >&2; exit 1 ;;
  esac
done
fail() { printf '站点导出失败：%s\n' "$*" >&2; exit 1; }
hash_file() {
  if command -v sha256sum >/dev/null; then sha256sum "$1" | cut -d' ' -f1;
  else shasum -a 256 "$1" | cut -d' ' -f1; fi
}
[[ -n "$source_commit" ]] || source_commit=$(git -C "$repository" rev-parse HEAD)
[[ "$source_commit" =~ ^[0-9a-f]{40}$ ]] || fail '开发文档必须绑定完整提交'
git -C "$repository" cat-file -e "$source_commit^{commit}"
mkdir -p "$project_root/build"
site_output="$(mktemp -d "$project_root/build/docs-site.XXXXXX")"
source_output="$(mktemp -d "$project_root/build/docs-input.XXXXXX")"
complete=0
cleanup() {
  rm -rf -- "$source_output"
  if [[ "$complete" != 1 ]]; then rm -rf -- "$site_output"; fi
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
next_source=$project_root
if [[ "$source_state" == commit ]]; then
  mkdir "$source_output/next"
  git -C "$repository" archive "$source_commit" docs LICENSE NOTICE | tar -x -C "$source_output/next"
  next_source="$source_output/next"
  cmp -s "$docs_root/build-site.sh" "$next_source/docs/build-site.sh" || fail '导出器与指定开发提交不一致'
fi
release_version='' product_commit='' documentation_commit='' component_batch=''
while IFS='=' read -r key value; do
  case "$key" in
    version) [[ -z "$release_version" ]] || fail '重复版本'; release_version=$value ;;
    product) [[ -z "$product_commit" ]] || fail '重复产品提交'; product_commit=$value ;;
    documentation) [[ -z "$documentation_commit" ]] || fail '重复文档提交'; documentation_commit=$value ;;
    batch) [[ -z "$component_batch" ]] || fail '重复组件批次'; component_batch=$value ;;
    *) fail "发布来源字段无效：$key" ;;
  esac
done < "$next_source/docs/site-release"
[[ "$release_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-rc\.[0-9]+)?$ && "$component_batch" == "$release_version" ]] || fail '发布版本与组件批次无效'
[[ "$product_commit" =~ ^[0-9a-f]{40}$ && "$documentation_commit" =~ ^[0-9a-f]{40}$ ]] || fail '发布来源必须是完整提交'
actual_product=$(git -C "$repository" rev-parse --verify "refs/tags/v$release_version^{commit}")
[[ "$actual_product" == "$product_commit" ]] || fail '产品 tag 与固定提交不一致'
git -C "$repository" cat-file -e "$documentation_commit^{commit}"

# 发布文档修正必须基于同一产品代码；不允许从带有新接口的开发提交提取旧版本指南。
while IFS= read -r changed; do
  case "$changed" in
    docs/*|*.md|LICENSE|NOTICE) ;;
    *) fail "文档提交已改变产品输入：$changed" ;;
  esac
done < <(git -C "$repository" diff --name-only "$product_commit" "$documentation_commit")
mkdir "$source_output/product" "$source_output/release" "$site_output/next"
git -C "$repository" archive "$product_commit" docs | tar -x -C "$source_output/product"
git -C "$repository" archive "$documentation_commit" docs LICENSE NOTICE | tar -x -C "$source_output/release"

# 只导出公开使用文档和静态资源；研发协作、规格、任务和验收材料不进入站点目录。
site_files=(index.html README.md _sidebar.md _navbar.md _404.md .nojekyll assets guide LICENSE NOTICE)
for channel in release next; do
  channel_source="$source_output/release"
  channel_output="$site_output"
  if [[ "$channel" == next ]]; then channel_source="$next_source"; channel_output="$site_output/next"; fi
  for site_file in "${site_files[@]}"; do
    source="$channel_source/docs/$site_file"
    if [[ "$site_file" == LICENSE || "$site_file" == NOTICE ]]; then source="$channel_source/$site_file"; fi
    [[ -e "$source" && ! -L "$source" ]] || fail "公开站点输入缺失或是符号链接：$site_file"
    [[ -z $(find "$source" -type l -print -quit) ]] || fail "公开站点包含符号链接：$site_file"
    cp -R "$source" "$channel_output/$site_file"
  done
  # 主题和通道导航可独立修正；产品示例、图文资源仍取自该通道的固定来源。
  cp "$next_source/docs/index.html" "$channel_output/index.html"
  cp "$next_source/docs/assets/site.js" "$next_source/docs/assets/site.css" "$channel_output/assets/"
  while IFS= read -r -d '' entry; do
    relative=${entry#"$channel_output/"}
    [[ "$relative" != next && "$relative" != next/* ]] || continue
    [[ ! -L "$entry" && ( -f "$entry" || -d "$entry" ) ]] || fail "拒绝非普通文件：$relative"
    case "$relative" in
      index.html|README.md|_sidebar.md|_navbar.md|_404.md|.nojekyll|LICENSE|NOTICE) [[ -f "$entry" ]] || fail "必须是文件：$relative" ;;
      assets|guide|assets/*|guide/*)
        [[ "$relative" != */.* && "$relative" =~ ^[a-zA-Z0-9_./-]+$ ]] || fail "拒绝隐藏或无效路径：$relative"
        if [[ -f "$entry" ]]; then
          case "$relative" in
            *.md|*.js|*.css|*.svg|*.png|*.jpg|*.jpeg|*.webp|*.ico|*.woff|*.woff2|*.txt|*/LICENSE|*/LICENSE.*|*/NOTICE) ;;
            *) fail "拒绝未允许的静态文件：$relative" ;;
          esac
        fi ;;
      *) fail "拒绝导出白名单之外的内容：$relative" ;;
    esac
  done < <(find "$channel_output" -mindepth 1 -print0)
done

# 旧产品文档允许纠正文案；可执行示例与具名接口不允许借此升级。新增教程走 next。
contracts() {
  local contract_root=$1
  (cd "$contract_root"; find README.md guide -type f -name '*.md' | LC_ALL=C sort | while IFS= read -r file; do
    printf '%s\n' "$file"
    awk '
      /^```/ { if (fenced) { fenced=0 } else { fenced=1; language=$0 }; next }
      fenced && language != "```mermaid" { print; next }
      !fenced { line=$0; while (match(line, /`[^`]+`/)) { token=substr(line,RSTART,RLENGTH); if (token ~ /\\|::|\(\)/) print token; line=substr(line,RSTART+RLENGTH) } }
    ' "$file"
  done)
}
contracts "$source_output/product/docs" > "$source_output/product-contracts"
contracts "$source_output/release/docs" > "$source_output/release-contracts"
cmp -s "$source_output/product-contracts" "$source_output/release-contracts" || fail '发布文档改变了产品示例或具名接口，请放入开发通道'

# 发布指南引用的主仓源码同样固定版本，不随 main 的后续接口变化。
while IFS= read -r -d '' markdown; do
  sed "s@https://github.com/zoujingli/typeapp/blob/main/@https://github.com/zoujingli/typeapp/blob/$documentation_commit/@g; s@https://github.com/zoujingli/typeapp/tree/main/@https://github.com/zoujingli/typeapp/tree/$documentation_commit/@g" "$markdown" > "$source_output/linked.md"
  mv "$source_output/linked.md" "$markdown"
done < <(find "$site_output" -path "$site_output/next" -prune -o -type f -name '*.md' -print0)

channel_pages() {
  local page_root=$1
  printf '["/"'
  while IFS= read -r page; do
    [[ "$page" =~ ^guide/[a-z0-9_/-]+\.md$ ]] || fail "页面名称无效：$page"
    printf ',"/%s"' "${page%.md}"
  done < <(cd "$page_root"; find guide -type f -name '*.md' | LC_ALL=C sort)
  printf ']'
}
channel_identity() {
  local identity_root=$1
  (cd "$identity_root"; find . -type f ! -path './next/*' | LC_ALL=C sort | while IFS= read -r file; do
    printf '%s %s\n' "$(hash_file "$file")" "$file"
  done) > "$source_output/identity-files"
  hash_file "$source_output/identity-files"
}
release_pages=$(channel_pages "$site_output")
next_pages=$(channel_pages "$site_output/next")
release_identity=$(channel_identity "$site_output")
next_identity=$(channel_identity "$site_output/next")
for channel in release next; do
  channel_output="$site_output" channel_commit="\"$documentation_commit\"" channel_identity=$release_identity
  channel_product="\"$product_commit\"" channel_tag="\"v$release_version\"" channel_batch=$component_batch channel_state='commit'
  channel_label='发布版' channel_version=$release_version peer_base='next/' peer_pages=$next_pages
  if [[ "$channel" == next ]]; then
    channel_output="$site_output/next" channel_commit=null channel_identity=$next_identity
    [[ "$source_state" != commit ]] || channel_commit="\"$source_commit\""
    channel_product=null channel_tag=null channel_batch='dev-main' channel_state=$source_state
    channel_label='开发版' channel_version='main（未发布）' peer_base='../' peer_pages=$release_pages
  fi
  cat > "$channel_output/channel.js" <<EOF
window.TYPEAPP_DOCS = {"channel":"$channel","label":"$channel_label","version":"$channel_version","productTag":$channel_tag,"productCommit":$channel_product,"documentationCommit":$channel_commit,"sourceCommit":"$source_commit","sourceState":"$channel_state","componentBatch":"$channel_batch","contentIdentity":"$channel_identity","peerBase":"$peer_base","peerPages":$peer_pages};
EOF
done
cat > "$site_output/site-manifest.json" <<EOF
{"protocol":1,"release":{"tag":"v$release_version","productCommit":"$product_commit","documentationCommit":"$documentation_commit","componentBatch":"$component_batch","contentIdentity":"$release_identity"},"next":{"sourceCommit":"$source_commit","sourceState":"$source_state","componentBatch":"dev-main","contentIdentity":"$next_identity"}}
EOF

complete=1
printf '%s\n' "$site_output"
