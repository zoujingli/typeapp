#!/usr/bin/env python3
"""重建材料的离线完整性回归；真实平台来源采集由候选验收运行。"""

import importlib.util
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch
import zipfile

spec = importlib.util.spec_from_file_location("rebuild_materials", Path(__file__).resolve().parents[1] / "tools/release/rebuild-materials.py")
materials = importlib.util.module_from_spec(spec)
spec.loader.exec_module(materials)


class RebuildMaterialsTest(unittest.TestCase):
    """以实际归档读取验证来源、篡改与路径边界，不依赖远端服务。"""

    @classmethod
    def setUpClass(cls):
        """全新检出尚无生成目录，测试自行准备父目录，不依赖先运行应用构建。"""
        cls.task_root = Path(__file__).resolve().parents[1] / "build"
        cls.task_root.mkdir(exist_ok=True)

    def test_vcpkg_github_source_reference(self):
        """Zstd 的真实 SPDX 使用 git+https 引用；恢复带摘要的源码归档，不执行 Git 地址。"""
        self.assertEqual(materials.vcpkg_source_url("git+https://github.com/facebook/zstd@v1.5.7"),
                         "https://github.com/facebook/zstd/archive/v1.5.7.tar.gz")
        self.assertEqual(materials.vcpkg_source_url("http://www.mpfr.org/mpfr-4.2.2/mpfr-4.2.2.tar.xz"),
                         "https://www.mpfr.org/mpfr-4.2.2/mpfr-4.2.2.tar.xz")
        for location in ("git+https://github.com/facebook/zstd", "git+https://github.com/facebook/zstd@../../secret",
                         "git+https://unreviewed.example/repository@v1", "file:///local/source", "NONE"):
            with self.assertRaises(ValueError):
                materials.vcpkg_source_url(location)

    def test_formula_mirror_download_and_digest(self):
        """主站连接失败后使用配方镜像；真实文件摘要决定是否接受，候选去重。"""
        with tempfile.TemporaryDirectory(prefix="rebuild-download-", dir=self.task_root) as task:
            output = Path(task) / "gmp.tar.xz"
            content = b"locked original GNU source\n"
            source = "https://ftpmirror.gnu.org/gnu/gmp/gmp-6.3.0.tar.xz"
            mirror = "https://gmplib.org/download/gmp/gmp-6.3.0.tar.xz"
            calls = []

            def transfer(arguments, directory, timeout):
                calls.append(arguments[-1])
                self.assertLessEqual(timeout, 60)
                self.assertEqual(arguments[arguments.index("--proto-redir") + 1], "=https")
                if len(calls) == 1:
                    output.write_bytes(b"incomplete")
                    raise RuntimeError("curl: connection timeout")
                self.assertFalse(output.exists())
                output.write_bytes(content)
                return ""

            with patch.object(materials, "command", side_effect=transfer):
                selected = materials.download(source, output, hashlib.sha256(content).hexdigest(), mirrors=[mirror, mirror])
            self.assertEqual(selected, mirror)
            self.assertEqual(calls, ["https://ftp.gnu.org/gnu/gmp/gmp-6.3.0.tar.xz", mirror])
            self.assertEqual(output.read_bytes(), content)

    def test_corrupt_source_is_not_hidden_by_mirror(self):
        """下载完成但摘要错误时立即失败，不尝试另一个来源掩盖内容冲突。"""
        with tempfile.TemporaryDirectory(prefix="rebuild-download-", dir=self.task_root) as task:
            output = Path(task) / "source.tar.xz"

            def transfer(arguments, directory, timeout):
                output.write_bytes(b"wrong source")
                return ""

            with patch.object(materials, "command", side_effect=transfer) as command:
                with self.assertRaisesRegex(ValueError, "摘要与构建输入不符"):
                    materials.download("https://ftp.gnu.org/gnu/mpfr/mpfr-4.2.2.tar.xz", output, "a" * 64,
                                       mirrors=["https://ftpmirror.gnu.org/gnu/mpfr/mpfr-4.2.2.tar.xz"])
            self.assertEqual(command.call_count, 1)
            self.assertFalse(output.exists())

    def test_gnu_origin_outage_uses_independent_verified_mirror(self):
        """GNU 与配方来源同时连接失败后，独立镜像仍须交付配方锁定的完全相同字节。"""
        with tempfile.TemporaryDirectory(prefix="rebuild-download-", dir=self.task_root) as task:
            output = Path(task) / "source.tar.xz"
            content = b"locked GNU source archive\n"
            cases = (
                ("gmp/gmp-6.3.0.tar.xz", "https://ftpmirror.gnu.org/gnu/", "sha256", ["https://gmplib.org/download/gmp/gmp-6.3.0.tar.xz"]),
                ("mpfr/mpfr-4.2.2.tar.xz", "https://ftpmirror.gnu.org/gnu/", "sha256", ["https://ftp.gnu.org/gnu/mpfr/mpfr-4.2.2.tar.xz"]),
                ("gmp/gmp-6.3.0.tar.xz", "https://ftpmirror.gnu.org/", "sha512", []),
            )
            for path, origin, algorithm, mirrors in cases:
                source = origin + path
                independent = "https://mirrors.ocf.berkeley.edu/gnu/" + path
                calls = []

                def transfer(arguments, directory, timeout):
                    calls.append(arguments[-1])
                    if arguments[-1] != independent:
                        output.write_bytes(b"partial")
                        raise RuntimeError("curl: (28) connection timeout")
                    self.assertFalse(output.exists())
                    output.write_bytes(content)
                    return ""

                with self.subTest(source=source, algorithm=algorithm), patch.object(materials, "command", side_effect=transfer):
                    selected = materials.download(source, output, hashlib.new(algorithm, content).hexdigest(), algorithm, mirrors=mirrors)
                    self.assertEqual(selected, independent)
                    self.assertEqual(calls, list(dict.fromkeys(["https://ftp.gnu.org/gnu/" + path, *mirrors, source, independent])))
                    self.assertEqual(output.read_bytes(), content)
                output.unlink(missing_ok=True)

    def test_download_budget_and_original_gnu_source(self):
        """失败来源共享总预算；配方原GNU入口仍可尝试，超时留下明确故障且清理残片。"""
        with tempfile.TemporaryDirectory(prefix="rebuild-download-", dir=self.task_root) as task:
            output = Path(task) / "mpfr.tar.xz"
            source = "https://ftpmirror.gnu.org/gnu/mpfr/mpfr-4.2.2.tar.xz"
            primary = "https://ftp.gnu.org/gnu/mpfr/mpfr-4.2.2.tar.xz"
            with patch.object(materials, "command", side_effect=RuntimeError("curl: connection timeout")) as command:
                with self.assertRaisesRegex(RuntimeError, "源码下载失败"):
                    materials.download(source, output, "a" * 64, mirrors=[primary])
                self.assertEqual([call.args[0][-1] for call in command.call_args_list],
                                 [primary, source, "https://mirrors.ocf.berkeley.edu/gnu/mpfr/mpfr-4.2.2.tar.xz"])
            output.write_bytes(b"partial")
            with patch.object(materials.time, "monotonic", side_effect=[0, 0, 31]), \
                 patch.object(materials, "command", side_effect=subprocess.TimeoutExpired("curl", 30)) as command:
                with self.assertRaisesRegex(RuntimeError, "总预算30秒"):
                    materials.download(source, output, "a" * 64, timeout=30)
                self.assertEqual(command.call_count, 1)
                self.assertEqual(command.call_args.kwargs["timeout"], 30)
            self.assertFalse(output.exists())

    def test_unchecked_download_keeps_single_source(self):
        """没有上游摘要的既有GitHub下载不扩展来源，明文镜像和无效摘要在联网前拒绝。"""
        with tempfile.TemporaryDirectory(prefix="rebuild-download-", dir=self.task_root) as task:
            output = Path(task) / "vcpkg.tar.gz"
            source = "https://codeload.github.com/microsoft/vcpkg/tar.gz/" + "a" * 40
            with patch.object(materials, "command", side_effect=RuntimeError("curl: unavailable")) as command:
                with self.assertRaisesRegex(RuntimeError, "源码下载失败"):
                    materials.download(source, output)
                self.assertEqual(command.call_count, 1)
                self.assertEqual(command.call_args.args[0][-1], source)
            unchecked_gnu = "https://ftpmirror.gnu.org/gnu/gmp/gmp-6.3.0.tar.xz"
            with patch.object(materials, "command", side_effect=RuntimeError("curl: unavailable")) as command:
                with self.assertRaisesRegex(RuntimeError, "源码下载失败"):
                    materials.download(unchecked_gnu, output)
                self.assertEqual(command.call_count, 1)
                self.assertEqual(command.call_args.args[0][-1], unchecked_gnu)
            for options in ({"mirrors": ["https://ftp.gnu.org/gnu/source"]},
                            {"checksum": "a" * 64, "mirrors": ["http://ftp.gnu.org/gnu/source"]},
                            {"checksum": "wrong"}, {"timeout": 0}, {"timeout": 301}):
                with self.subTest(options=options), patch.object(materials, "command") as command:
                    with self.assertRaises(ValueError):
                        materials.download(source, output, **options)
                    command.assert_not_called()

    def test_roundtrip_and_corruption(self):
        """保留真实字节；错误源码身份和篡改记录均不得通过。"""
        with tempfile.TemporaryDirectory(prefix="rebuild-test-", dir=self.task_root) as task:
            root = Path(task)
            source = root / "中文 source.txt"
            source.write_bytes(b"original source\r\n")
            output = root / "materials.zip"
            identity = {"source": "a" * 40, "sdk-manifest-sha256": "b" * 64}
            with zipfile.ZipFile(output, "w") as archive:
                bundle = materials.Bundle(archive)
                bundle.add(source, "rebuild/source.txt")
                record = {**identity, "files": bundle.files}
                archive.writestr("rebuild/manifest.json", json.dumps(record))
            self.assertEqual(materials.verify(output, identity)["files"], record["files"])
            with self.assertRaises(ValueError):
                materials.verify(output, {**identity, "source": "c" * 40})
            for content, name in ((b"changed", "rebuild/source.txt"), (b"extra", "rebuild/unlisted.txt")):
                broken = root / "broken.zip"
                with zipfile.ZipFile(broken, "w") as archive:
                    archive.writestr("rebuild/manifest.json", json.dumps(record))
                    archive.writestr(name, content)
                with self.assertRaises(ValueError):
                    materials.verify(broken, identity)

    def test_paths_duplicates_missing_and_links(self):
        """收集阶段即拒绝越界、重复、缺失目录与文件系统链接。"""
        with tempfile.TemporaryDirectory(prefix="rebuild-test-", dir=self.task_root) as task:
            root = Path(task)
            source = root / "source.txt"
            source.write_text("original")
            with zipfile.ZipFile(root / "materials.zip", "w") as archive:
                bundle = materials.Bundle(archive)
                for name in ("/absolute", "../outside", "a/../outside", "C:/outside", "a\\outside", "a//empty"):
                    with self.assertRaises(ValueError):
                        bundle.add(source, name)
                bundle.add(source, "rebuild/source.txt")
                with self.assertRaises(ValueError):
                    bundle.add(source, "rebuild/source.txt")
                with self.assertRaises(ValueError):
                    bundle.tree(root / "missing", "rebuild/missing")
                link = root / "link.txt"
                try:
                    link.symlink_to(source)
                except OSError:
                    return  # Windows 未开启开发模式时，其他断言仍已执行。
                with self.assertRaises(ValueError):
                    bundle.add(link, "rebuild/link.txt")


if __name__ == "__main__":
    unittest.main()
