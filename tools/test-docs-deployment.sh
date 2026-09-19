#!/usr/bin/env bash
# 仅操作 mktemp 下的独立 Git 仓库与静态发布目录，不连接应用或数据库。
set -euo pipefail
project_root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
# 部署器复用 Linux 的 flock/timeout/realpath；macOS 仍可验证静态导出，完整切换验收在 Linux runner 执行。
for dependency in git flock timeout realpath tar find sort cmp; do
    if ! command -v "$dependency" >/dev/null; then
        printf 'SKIP 文档部署隔离测试需要 Linux 依赖：%s\n' "$dependency"
        exit 0
    fi
done
mkdir -p "$project_root/build"
test_root=$(mktemp -d "$project_root/build/docs-deployment.XXXXXXXX")
trap 'result=$?; printf "隔离验证目录：%s（退出码 %s）\n" "$test_root" "$result"' EXIT
# 本轮隔离目录在项目 build/ 下；容器 bind mount 时宿主 UID 可能与容器用户不一致。
# 只用本轮临时 gitconfig，不改用户全局配置。
printf '[safe]\n\tdirectory = *\n' > "$test_root/gitconfig"
export GIT_CONFIG_GLOBAL="$test_root/gitconfig"
origin="$test_root/origin.git"
source_repo="$test_root/source repo"
checkout="$test_root/private checkout"
publish="$test_root/public site"
runner="$project_root/tools/deploy-docs-site.sh"
git init -q --bare "$origin"
git init -q -b main "$source_repo"
git -C "$source_repo" config user.name 'Deployment Test'
git -C "$source_repo" config user.email 'deployment@example.invalid'
mkdir -p "$source_repo/docs" "$source_repo/tools"
cp -R "$project_root/docs/." "$source_repo/docs/"
cp "$project_root/LICENSE" "$source_repo/LICENSE"
cp "$project_root/NOTICE" "$source_repo/NOTICE"
cp "$runner" "$source_repo/tools/deploy-docs-site.sh"
git -C "$source_repo" add docs LICENSE NOTICE tools/deploy-docs-site.sh
git -C "$source_repo" commit -qm 'fixture'
git -C "$source_repo" remote add origin "$origin"
git -C "$source_repo" push -qu origin main
git clone -q --branch main "$origin" "$checkout"
deploy() { bash "$runner" --repo "$checkout" --publish-root "$publish" --branch main; }
commit() { git -C "$source_repo" add docs; git -C "$source_repo" commit -qm "$1"; git -C "$source_repo" push -q origin main; }
expect_failure() {
    if deploy >"$test_root/$1.log" 2>&1; then echo "未拒绝：$1" >&2; exit 1; fi
    [[ $(readlink "$publish/current") == "$previous" ]]
}
deploy >"$test_root/first.log" 2>&1
[[ -s "$publish/current/index.html" && -s "$publish/current/README.md" ]]
[[ -s "$publish/current/LICENSE" && -s "$publish/current/NOTICE" ]]
[[ ! -e "$publish/current/site-maintenance.md" && ! -e "$publish/current/.git" && ! -e "$publish/current/adr" ]]
previous=$(readlink "$publish/current")
deploy >"$test_root/unchanged.log" 2>&1
[[ $(readlink "$publish/current") == "$previous" ]]
grep -q UNCHANGED "$test_root/unchanged.log"
printf '\n部署隔离测试更新\n' >> "$source_repo/docs/README.md"
commit update
cp "$runner" "$test_root/stale-runner.sh"
printf '\n# stale-runner-fixture\n' >> "$test_root/stale-runner.sh"
if bash "$test_root/stale-runner.sh" --repo "$checkout" --publish-root "$publish" --branch main >"$test_root/stale-runner.log" 2>&1; then
    echo '未拒绝：stale-runner' >&2
    exit 1
fi
grep -q '请先更新已安装 runner' "$test_root/stale-runner.log"
[[ $(readlink "$publish/current") == "$previous" ]]
deploy >"$test_root/update.log" 2>&1
[[ $(readlink "$publish/current") != "$previous" ]]
grep -q '部署隔离测试更新' "$publish/current/README.md"
previous=$(readlink "$publish/current")
git -C "$checkout" remote set-url origin "$test_root/missing.git"
expect_failure fetch-failure
git -C "$checkout" remote set-url origin "$origin"
cp "$source_repo/docs/build-site.sh" "$test_root/build-site.sh"
printf '#!/usr/bin/env bash\nexit 42\n' > "$source_repo/docs/build-site.sh"
commit broken-export
expect_failure export-failure
cp "$test_root/build-site.sh" "$source_repo/docs/build-site.sh"
ln -s ../site-maintenance.md "$source_repo/docs/guide/private.md"
commit symlink
expect_failure source-symlink
git -C "$source_repo" rm -q docs/guide/private.md
printf '\nprintf "private" > "$site_output/internal.md"\n' >> "$source_repo/docs/build-site.sh"
commit unlisted-output
expect_failure unlisted-output
cp "$test_root/build-site.sh" "$source_repo/docs/build-site.sh"
printf '\n部署后续更新\n' >> "$source_repo/docs/README.md"
commit recovery
(
    exec 8>"$publish/.deploy.lock"
    flock -n 8
    deploy >"$test_root/overlap.log" 2>&1
)
grep -q SKIP "$test_root/overlap.log"
[[ $(readlink "$publish/current") == "$previous" ]]
deploy >"$test_root/recovery.log" 2>&1
for version in 1 2; do
    printf '\n保留版本检查 %s\n' "$version" >> "$source_repo/docs/README.md"
    commit "retention-$version"
    deploy >"$test_root/retention-$version.log" 2>&1
done
[[ $(find "$publish/releases" -mindepth 1 -maxdepth 1 -type d | wc -l) -eq 3 ]]
[[ $(readlink "$publish/current") == *-"$(git -C "$source_repo" rev-parse HEAD)" ]]
# 宝塔通过 runuser 降权时可能继承 /root；在无权访问的原工作目录下仍须正常运行。
if [[ $(id -u) == 0 ]] && command -v runuser >/dev/null && id nobody >/dev/null 2>&1; then
    chown -R nobody "$test_root"
    chmod 755 "$test_root"
    mkdir "$test_root/root-only"
    chmod 700 "$test_root/root-only"
    printf '\n降权用户工作目录检查\n' >> "$source_repo/docs/README.md"
    runuser -u nobody -- git -C "$source_repo" add docs
    runuser -u nobody -- git -C "$source_repo" commit -qm inherited-directory
    runuser -u nobody -- git -C "$source_repo" push -q origin main
    (cd "$test_root/root-only"; runuser -u nobody -- bash "$runner" --repo "$checkout" --publish-root "$publish" --branch main) >"$test_root/inherited-directory.log" 2>&1
    grep -q PUBLISHED "$test_root/inherited-directory.log"
    certificate_release=$(readlink "$publish/current")
    # 真实 root 写入验证副本，后续仍由无特权部署用户回收该旧版本。
    printf 'certificate-validation-fixture\n' > "$publish/current/.well-known/acme-challenge/fixture"
    for version in 1 2 3; do
        printf '\n证书副本回收检查 %s\n' "$version" >> "$source_repo/docs/README.md"
        runuser -u nobody -- git -C "$source_repo" add docs
        runuser -u nobody -- git -C "$source_repo" commit -qm "certificate-retention-$version"
        runuser -u nobody -- git -C "$source_repo" push -q origin main
        runuser -u nobody -- bash "$runner" --repo "$checkout" --publish-root "$publish" --branch main >"$test_root/certificate-retention-$version.log" 2>&1
    done
    [[ ! -e "$publish/$certificate_release" ]]
    [[ $(find "$publish/releases" -mindepth 1 -maxdepth 1 -type d | wc -l) -eq 3 ]]
fi
printf 'PASS 首次发布、更新、无变化、拉取失败、导出失败、符号链接、发布白名单、过期 runner、并发跳过、恢复、三个版本保留、降权工作目录与证书副本回收\n'
