#!/usr/bin/env python3
"""封存与候选 SDK 对应的源码和重新构建材料；此附件不进入部署目录。"""

import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import subprocess
import sys
import tempfile
import zipfile


def digest(path, algorithm="sha256"):
    """流式读取原文件，避免大型静态归档占满控制进程内存。"""
    value = hashlib.new(algorithm)
    with Path(path).open("rb") as source:
        for chunk in iter(lambda: source.read(1024 * 1024), b""):
            value.update(chunk)
    return value.hexdigest()


def command(arguments, directory):
    """构建命令直接传参，失败保留可定位的输出，不经过 shell。"""
    result = subprocess.run(arguments, cwd=directory, text=True, encoding="utf-8", errors="replace", stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, timeout=300, check=False)
    if result.returncode:
        raise RuntimeError(f"重建材料命令失败：{arguments[0]}\n{result.stderr[-4000:]}")
    return result.stdout.strip()


def download(url, destination, checksum=None, algorithm="sha256"):
    """只获取公开 HTTPS 原始源码；有上游摘要时必须逐字节一致。"""
    if not url.startswith("https://"):
        raise ValueError("源码下载必须使用 HTTPS")
    # 固定 GNU 主镜像，避免自动镜像重定向到已失效站点；摘要仍来自实际构建配方。
    url = url.replace("https://ftpmirror.gnu.org/gnu/", "https://ftp.gnu.org/gnu/")
    if url.startswith("https://ftpmirror.gnu.org/"):
        url = url.replace("https://ftpmirror.gnu.org/", "https://ftp.gnu.org/gnu/", 1)
    command(["curl.exe" if os.name == "nt" else "curl", "--fail", "--location", "--silent", "--show-error",
             "--retry", "3", "--connect-timeout", "20", "--max-time", "60", "--output", str(destination), url], destination.parent)
    if checksum is not None and digest(destination, algorithm) != checksum.lower():
        raise ValueError("源码下载摘要与构建输入不符：" + destination.name)


class Bundle:
    """只接受普通文件与相对成员名，每个成员独立记录字节数和摘要。"""

    def __init__(self, archive):
        self.archive = archive
        self.files = {}

    def add(self, source, name):
        """拒绝路径跳转、重复和链接；文件在封存过程中变化也必须失败。"""
        source = Path(source)
        parts = PurePosixPath(name).parts
        if not parts or name.startswith("/") or "\\" in name or ":" in name or any(
                part in ("", ".", "..") for part in name.split("/")) or name in self.files:
            raise ValueError("重建材料成员路径无效或重复：" + name)
        if source.is_symlink() or not source.is_file():
            raise ValueError("重建材料必须为普通文件：" + name)
        before = digest(source)
        self.archive.write(source, name)
        self.files[name] = {"sha256": before, "bytes": source.stat().st_size}
        if digest(source) != before:
            raise ValueError("封存时输入发生变化：" + name)

    def tree(self, directory, prefix):
        """不跟随目录链接；完整保留库源码的文件和许可证。"""
        directory = Path(directory)
        if directory.is_symlink() or not directory.is_dir():
            raise ValueError("重建材料源目录缺失或为链接：" + prefix)
        for current, folders, files in os.walk(directory, followlinks=False):
            for name in sorted(folders + files):
                path = Path(current) / name
                if path.is_symlink():
                    raise ValueError("重建材料不能包含链接：" + str(path.relative_to(directory)))
            for name in sorted(files):
                path = Path(current) / name
                self.add(path, prefix + "/" + path.relative_to(directory).as_posix())


def verify(path, expected):
    """回读每个成员并拒绝多余文件；绑定源码、SDK、版本和平台。"""
    with zipfile.ZipFile(path) as archive:
        manifest = json.loads(archive.read("rebuild/manifest.json"))
        if any(manifest.get(key) != value for key, value in expected.items()):
            raise ValueError("重建材料的来源身份不符")
        names = archive.namelist()
        if len(names) != len(set(names)) or set(names) != set(manifest["files"]) | {"rebuild/manifest.json"}:
            raise ValueError("重建材料成员清单不完整或重复")
        for name, record in manifest["files"].items():
            parts = name.split("/")
            if any(part in ("", ".", "..") for part in parts) or "\\" in name or ":" in name:
                raise ValueError("重建材料含越界成员")
            with archive.open(name) as source:
                value = hashlib.sha256()
                size = 0
                for chunk in iter(lambda: source.read(1024 * 1024), b""):
                    value.update(chunk)
                    size += len(chunk)
            if value.hexdigest() != record["sha256"] or size != record["bytes"]:
                raise ValueError("重建材料成员摘要不符：" + name)
    return manifest


def library_sources(bundle, project, sdk, metadata, temporary, dependencies):
    """收集 LGPL 对应源码及实际构建配方；其余库保留可重链接 SDK 和原许可。"""
    sources = {}
    php_checksum = "6a8bebaa4d5a979a38db29a9373e9851f60c6b11f72172c585947e78f3081957"
    recorded_php = metadata["sources"].get("php", {}).get("archive-sha256") or metadata["sources"].get("php.tar.xz", {}).get("sha256")
    if recorded_php != php_checksum:
        raise ValueError("PHP 对应源码摘要与 SDK 来源不一致")
    php = temporary / "php-8.5.10.tar.xz"
    download("https://www.php.net/distributions/php-8.5.10.tar.xz", php, php_checksum)
    bundle.add(php, "rebuild/native-sources/" + php.name)
    sources["PHP/libmbfl"] = {"file": php.name, "version": "8.5.10", "license": "LGPL-2.1-or-later (libmbfl)"}
    family = metadata["os"]
    if family == "Darwin":
        for name in ("gmp", "mpfr"):
            prefix = Path(command(["brew", "--prefix", name], project)).resolve()
            receipt = prefix / "INSTALL_RECEIPT.json"
            identity = metadata["dependency-inputs"][name]
            if prefix.name != identity["version"] or digest(receipt) != identity["receipt-sha256"]:
                raise ValueError("Homebrew 源码配方与已链接库不一致：" + name)
            formula = prefix / ".brew" / (name + ".rb")
            text = formula.read_text(encoding="utf-8")
            url = re.search(r'^  url "(https://[^"\n]+)"$', text, re.M)
            checksum = re.search(r'^  sha256 "([a-f0-9]{64})"$', text, re.M)
            # 这两个固定版本不含附加源码补丁；配方变化要求补齐采集逻辑，不能漏掉补丁。
            if not url or not checksum or re.search(r'^  (?:patch|resource|stable)\b', text, re.M):
                raise ValueError("Homebrew 源码配方需要重新审核：" + name)
            archive = temporary / (name + ".tar.xz")
            download(url[1], archive, checksum[1])
            bundle.add(archive, "rebuild/native-sources/" + archive.name)
            bundle.add(formula, "rebuild/recipes/homebrew/" + name + ".rb")
            bundle.add(receipt, "rebuild/recipes/homebrew/" + name + "-receipt.json")
            sources[name] = {"version": identity["version"], "url": url[1], "sha256": checksum[1]}
    elif family == "Linux":
        for package in ("libgmp-dev", "libmpfr-dev"):
            fields = command(["dpkg-query", "-W", "-f=${Version}\n${source:Package}\n${source:Version}", package], project).splitlines()
            if len(fields) != 3 or fields[0] != metadata["dependency-inputs"][package]["version"]:
                raise ValueError("发行版源码与静态库版本不一致：" + package)
            folder = temporary / package
            folder.mkdir()
            command(["apt-get", "source", "--download-only", "--only-source", fields[1] + "=" + fields[2]], folder)
            if not list(folder.glob("*.dsc")) or not list(folder.glob("*.orig.tar.*")):
                raise ValueError("缺少发行版原始源码或补丁清单：" + package)
            bundle.tree(folder, "rebuild/native-sources/" + package)
            sources[package] = {"package": fields[1], "version": fields[2]}
    elif family == "Windows":
        if dependencies is None:
            raise ValueError("Windows 材料需要本轮 vcpkg 安装目录")
        reference = metadata["preparation"]["dependency-source"]
        if re.fullmatch(r"[a-f0-9]{40}", reference) is None:
            raise ValueError("vcpkg 源码身份无效")
        archive = temporary / "vcpkg.tar.gz"
        download("https://codeload.github.com/microsoft/vcpkg/tar.gz/" + reference, archive)
        bundle.add(archive, "rebuild/recipes/vcpkg.tar.gz")
        for name, library in (("gmp", "gmp.lib"), ("mpfr", "mpfr.lib"), ("libiconv", "iconv.lib")):
            spdx_path = dependencies / "share" / name / "vcpkg.spdx.json"
            spdx = json.loads(spdx_path.read_text(encoding="utf-8-sig"))
            package = spdx["packages"][0]
            if package["name"] != name or package["versionInfo"] != metadata["notices"][library]["version"]:
                raise ValueError("vcpkg 源码版本不一致：" + name)
            if digest(dependencies / "lib" / library) != next(item["sha256"] for item in metadata["archives"] if item["file"] == "lib/" + library):
                raise ValueError("vcpkg 来源归档与本轮 SDK 不一致：" + name)
            resources = [item for item in spdx["packages"] if item.get("downloadLocation", "").startswith(("http://", "https://")) and item.get("checksums")]
            if not resources:
                raise ValueError("缺少 vcpkg 原始源码下载身份：" + name)
            for number, item in enumerate(resources):
                checksum = next(value for value in item["checksums"] if value["algorithm"] in ("SHA512", "SHA256"))
                url = item["downloadLocation"].replace("http://", "https://", 1)
                source = temporary / (name + "-" + str(number) + "-" + url.rsplit("/", 1)[-1])
                download(url, source, checksum["checksumValue"], checksum["algorithm"].lower())
                bundle.add(source, "rebuild/native-sources/" + source.name)
            bundle.add(spdx_path, "rebuild/recipes/vcpkg/" + name + ".spdx.json")
            sources[name] = {"version": package["versionInfo"], "vcpkg": reference}
    else:
        raise ValueError("未知静态 SDK 平台")
    return sources


def create(project, sdk, output, version, platform, dependencies=None):
    """在任务目录内组装，全部回读成功才把附件移动到最终位置。"""
    if output.exists() or output.is_symlink():
        raise ValueError("不能覆盖已有重建材料")
    source = command(["git", "rev-parse", "HEAD"], project)
    metadata_path = sdk / "manifest.json"
    metadata = json.loads(metadata_path.read_text(encoding="utf-8-sig"))
    target = {"Darwin": "macos-arm64", "Windows": "windows-x64", "Linux": "linux-arm64" if metadata["architecture"] in ("arm64", "aarch64") else "linux-x64"}
    if target.get(metadata["os"]) != platform or metadata["php"] != "8.5.10" or metadata["zts"] is not True:
        raise ValueError("重建材料平台或 PHP ABI 与 SDK 不一致")
    for name, notice in metadata["notices"].items():
        license_text = json.dumps(notice["license"])
        if "GPL" in license_text and notice["component"] not in ("gmp", "mpfr", "libiconv", "libgmp-dev", "libmpfr-dev"):
            raise ValueError("需要先增加对应原生库的源码采集规则：" + name)
    identity = {"protocol": 1, "source": source, "version": version, "platform": platform,
                "sdk-manifest-sha256": digest(metadata_path)}
    with tempfile.TemporaryDirectory(prefix="rebuild-materials-", dir=project / "build") as directory:
        temporary = Path(directory)
        pending = temporary / "materials.zip"
        with zipfile.ZipFile(pending, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=6) as archive:
            bundle = Bundle(archive)
            for item in metadata["archives"] + metadata["headers"]:
                path = sdk / item["file"]
                if not path.resolve().is_relative_to(sdk) or digest(path) != item["sha256"]:
                    raise ValueError("SDK 输入摘要或路径不符")
            bundle.tree(sdk, "rebuild/sdk")
            application = temporary / "typeapp.tar"
            command(["git", "archive", "--format=tar", "--output=" + str(application), source], project)
            bundle.add(application, "rebuild/typeapp.tar")
            lock = json.loads((project / "composer.lock").read_text(encoding="utf-8"))
            # 公开组件的源码已包含在主仓；保留实际安装的第三方生产实现和编译工具源码。
            for package in lock["packages"] + [p for p in lock["packages-dev"] if p["name"] in ("swoole/phpx", "swoole/typephp")]:
                if package["name"].startswith("zoujingli/type-"):
                    continue
                bundle.tree(project / "vendor" / package["name"], "rebuild/vendor/" + package["name"])
            native = library_sources(bundle, project, sdk, metadata, temporary, dependencies)
            bundle.add(project / "docs/development/rebuild.md", "rebuild/README.md")
            archive.writestr("rebuild/manifest.json", json.dumps({**identity, "native-sources": native, "files": bundle.files}, ensure_ascii=False, indent=2) + "\n")
        verify(pending, identity)
        if digest(metadata_path) != identity["sdk-manifest-sha256"]:
            raise ValueError("封存过程中 SDK 清单变化")
        output.parent.mkdir(parents=True, exist_ok=True)
        pending.rename(output)
    return {**identity, "file": output.name, "sha256": digest(output), "bytes": output.stat().st_size}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--sdk", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--version", required=True)
    parser.add_argument("--platform", required=True, choices=("linux-x64", "linux-arm64", "macos-arm64", "windows-x64"))
    parser.add_argument("--dependencies", type=Path)
    options = parser.parse_args()
    if not re.fullmatch(r"v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-rc\.[1-9][0-9]*)?", options.version):
        raise ValueError("重建材料版本无效")
    project = Path(__file__).resolve().parents[2]
    record = create(project, options.sdk.resolve(), options.output.resolve(), options.version,
                    options.platform, options.dependencies.resolve() if options.dependencies else None)
    options.output.with_suffix(".json").write_text(json.dumps(record, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print("重建材料封存并回读通过：" + record["file"])


if __name__ == "__main__":
    sys.stdout.reconfigure(encoding="utf-8")
    sys.stderr.reconfigure(encoding="utf-8")
    main()
