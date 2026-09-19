#!/usr/bin/env bash
set -euo pipefail

docs_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
project_root="$(cd -- "$docs_root/.." && pwd)"
mkdir -p "$project_root/build"
site_output="$(mktemp -d "$project_root/build/docs-site.XXXXXX")"

# 只导出公开使用文档和静态资源；研发协作、规格、任务和验收材料不进入站点目录。
site_files=(index.html README.md _sidebar.md _navbar.md _404.md .nojekyll assets guide LICENSE NOTICE)
for site_file in "${site_files[@]}"; do
  source="$docs_root/$site_file"
  if [[ "$site_file" == "LICENSE" || "$site_file" == "NOTICE" ]]; then
    source="$project_root/$site_file"
  fi
  [[ -e "$source" ]] || { printf '公开站点输入缺失：%s\n' "$source" >&2; exit 1; }
  cp -R "$source" "$site_output/$site_file"
done

printf '%s\n' "$site_output"
