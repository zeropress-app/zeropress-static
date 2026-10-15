"""Offline release tests: synthetic Git/SVN repositories and a GitHub API double."""

import importlib.util
import io
import os
from pathlib import Path
import shutil
import tempfile
import unittest
from unittest.mock import patch
import urllib.error
import urllib.request
import zipfile

SPEC = importlib.util.spec_from_file_location("wordpress_release", Path(__file__).parents[1] / "scripts/wordpress-release.py")
release = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(release)


class ReleaseTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="wordpress-release-test-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.repo = self.root / "source"
        self.repo.mkdir()
        self.output = self.root / "output"
        # Any unexpected HTTP access is a test failure.
        self.network = patch.object(urllib.request.OpenerDirector, "open", side_effect=AssertionError("Unexpected network request"))
        self.network.start()
        self.addCleanup(self.network.stop)
        self.git("init", "--quiet")
        self.git("config", "user.name", "Release Test")
        self.git("config", "user.email", "release@example.test")
        self.git("config", "commit.gpgsign", "false")
        self.git("config", "tag.gpgsign", "false")
        self.git("config", "core.hooksPath", "/dev/null")
        files = {
            ".distignore": "/.git\n/.github\n/.distignore\n/build\n/dist\n*.zip\n",
            ".github/workflows/test.yml": "name: fixture\n",
            "zeropress-static.php": "<?php\n/**\n * Version: 0.7.0\n * Text Domain: zeropress-static\n */\nconst VERSION = '0.7.0';\n",
            "readme.txt": "Stable tag: 0.7.0\n\n== Changelog ==\n\n= 0.7.0 =\n* Initial release.\n",
            "assets/admin.js": "'use strict';\n",
            "README.md": "# ZeroPress Static\n",
            "languages/zeropress-static-ko_KR.po": '"Project-Id-Version: ZeroPress Static 0.7.0\\n"\n',
            "languages/zeropress-static-ko_KR.mo": "Project-Id-Version: ZeroPress Static 0.7.0\n",
        }
        for name, content in files.items():
            target = self.repo / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(content)
        self.commit()
        self.git("tag", "v0.7.0")

    def git(self, *args):
        return release.command(["git", *args], self.repo).decode().strip()

    def commit(self):
        self.git("add", ".")
        self.git("commit", "--quiet", "-m", "Synthetic release fixture")

    def prepare(self):
        with patch.dict(os.environ, {"GITHUB_OUTPUT": ""}), patch("sys.stdout", new_callable=io.StringIO):
            return release.prepare(self.repo, self.output, tagged=True)

    def test_prepares_committed_files_and_deterministic_zip(self):
        original = (self.repo / "assets/admin.js").read_bytes()
        (self.repo / "assets/admin.js").write_text("local edit")
        (self.repo / "credentials.txt").write_text("untracked")
        index = (self.repo / ".git/index").read_bytes()
        manifest = self.prepare()
        self.assertEqual((self.output / "payload/assets/admin.js").read_bytes(), original)
        self.assertEqual(set(manifest["files"]), {
            "zeropress-static.php", "readme.txt", "README.md", "assets/admin.js",
            "languages/zeropress-static-ko_KR.po", "languages/zeropress-static-ko_KR.mo"})
        self.assertEqual(release.verify_package(self.repo, self.output), manifest)
        second = self.root / "again"
        with patch("sys.stdout", new_callable=io.StringIO):
            release.prepare(self.repo, second, tagged=True)
        for filename in [release.archive_name(manifest), "manifest.json"]:
            self.assertEqual((self.output / filename).read_bytes(), (second / filename).read_bytes())
        self.assertEqual((self.repo / ".git/index").read_bytes(), index)

    def test_tag_must_exist_and_point_to_head(self):
        self.git("tag", "-d", "v0.7.0")
        with self.assertRaises(release.ReleaseError):
            self.prepare()
        self.git("tag", "v0.7.0")
        (self.repo / "README.md").write_text("new commit")
        self.commit()
        with self.assertRaisesRegex(release.ReleaseError, "checked-out commit"):
            self.prepare()
        # Ordinary CI can validate a proposed version before its release tag exists.
        with patch("sys.stdout", new_callable=io.StringIO):
            release.prepare(self.repo, self.output, tagged=False)

    def test_version_translation_and_changelog_must_match(self):
        _, _, files = release.source_files(self.repo)
        for filename in ["zeropress-static.php", "readme.txt", "languages/zeropress-static-ko_KR.po", "languages/zeropress-static-ko_KR.mo"]:
            with self.subTest(filename=filename):
                changed = dict(files)
                changed[filename] = changed[filename].replace(b"0.7.0", b"0.7.1", 1)
                with self.assertRaises(release.ReleaseError):
                    release.plugin_version(changed)
        changed = dict(files)
        changed["readme.txt"] = changed["readme.txt"].replace(b"= 0.7.0 =", b"= 0.6.0 =")
        with self.assertRaisesRegex(release.ReleaseError, "first Changelog"):
            release.plugin_version(changed)

    def test_release_tree_rejects_symlinks(self):
        (self.repo / "linked.php").symlink_to("zeropress-static.php")
        self.commit()
        with self.assertRaisesRegex(release.ReleaseError, "regular Git files"):
            release.manifest_for(self.repo, tagged=False)

    def test_payload_zip_manifest_and_checksum_are_verified_against_git(self):
        manifest = self.prepare()
        for filename in ["payload/assets/admin.js", "manifest.json", release.archive_name(manifest) + ".sha256"]:
            with self.subTest(filename=filename):
                target = self.output / filename
                original = target.read_bytes()
                target.write_bytes(original.replace(b"0.7.0", b"0.7.1") if filename == "manifest.json" else b"changed")
                with self.assertRaises(release.ReleaseError):
                    release.verify_package(self.repo, self.output)
                target.write_bytes(original)
        with zipfile.ZipFile(self.output / release.archive_name(manifest), "a") as zipped:
            zipped.writestr("zeropress-static/additional.php", "<?php")
        with self.assertRaisesRegex(release.ReleaseError, "ZIP differs"):
            release.verify_package(self.repo, self.output)

    def test_unsafe_zip_paths_and_symlinks_are_rejected(self):
        for name, mode in [("../file", 0o100644), ("zeropress-static/../../file", 0o100644), ("zeropress-static/file", 0o120777)]:
            data = io.BytesIO()
            with zipfile.ZipFile(data, "w") as zipped:
                info = zipfile.ZipInfo(name)
                info.external_attr = mode << 16
                zipped.writestr(info, "file")
            with self.assertRaises(release.ReleaseError):
                release.check_zip(data.getvalue(), {})

    def test_svn_new_existing_conflicting_and_historical_versions(self):
        manifest = self.prepare()
        svn = FakeSVN(manifest)
        self.assertEqual(release.svn_status(manifest, svn)["exists"], "false")
        with self.assertRaisesRegex(release.ReleaseError, "not confirmed"):
            release.svn_status(manifest, svn, must_exist=True)
        svn.tags = ["0.7.1"]
        with self.assertRaisesRegex(release.ReleaseError, "backwards"):
            release.svn_status(manifest, svn)
        svn.tags = ["0.7.0"]
        self.assertEqual(release.svn_status(manifest, svn, must_exist=True)["exists"], "true")
        svn.trunk = {"new.php": "different"}
        with self.assertRaisesRegex(release.ReleaseError, "SVN trunk"):
            release.svn_status(manifest, svn)
        svn.tags.append("0.7.1")
        self.assertEqual(release.svn_status(manifest, svn)["exists"], "true")
        svn.tag = {"readme.txt": "different"}
        with self.assertRaisesRegex(release.ReleaseError, "SVN tag"):
            release.svn_status(manifest, svn)
        svn.tags = ["unexpected"]
        with self.assertRaisesRegex(release.ReleaseError, "unsupported release tag"):
            release.svn_status(manifest, svn)

    def test_svn_failure_is_not_treated_as_missing_tag(self):
        svn = FakeSVN(self.prepare())
        with patch.object(svn, "state", side_effect=release.ReleaseError("SVN unavailable")):
            with self.assertRaisesRegex(release.ReleaseError, "unavailable"):
                release.svn_status(svn.manifest, svn)

    @unittest.skipUnless(shutil.which("svn") and shutil.which("svnadmin"), "SVN integration requires svn and svnadmin")
    def test_real_local_svn_export_uses_a_pinned_revision(self):
        manifest = self.prepare()
        database = self.root / "svn"
        release.command(["svnadmin", "create", str(database)])
        url = database.as_uri()
        release.command(["svn", "mkdir", url + "/trunk", url + "/tags", "-m", "Synthetic structure"])
        checkout = self.root / "svn-checkout"
        release.command(["svn", "checkout", url, str(checkout)])
        shutil.copytree(self.output / "payload", checkout / "trunk", dirs_exist_ok=True)
        release.command(["svn", "add", "--force", str(checkout / "trunk")])
        release.command(["svn", "copy", str(checkout / "trunk"), str(checkout / "tags/0.7.0")])
        release.command(["svn", "commit", str(checkout), "-m", "Synthetic release"])
        svn = release.SVN(url)
        self.assertEqual(release.svn_status(manifest, svn, must_exist=True)["exists"], "true")
        revision, _ = svn.state()
        (checkout / "tags/0.7.0/readme.txt").write_text("changed remotely")
        release.command(["svn", "commit", str(checkout), "-m", "Synthetic drift"])
        self.assertEqual(svn.hashes("tags/0.7.0", revision), manifest["files"])
        with self.assertRaisesRegex(release.ReleaseError, "SVN tag"):
            release.svn_status(manifest, svn)

    def test_github_release_upload_and_identical_rerun(self):
        manifest = self.prepare()
        github = FakeGitHub(manifest)
        result = release.github_release(manifest, self.output, github)
        self.assertIn("/releases/tag/v0.7.0", result["release_url"])
        self.assertFalse(github.current["draft"])
        self.assertEqual(set(a["name"] for a in github.stored.values()), {
            release.archive_name(manifest), release.archive_name(manifest) + ".sha256"})
        writes = list(github.writes)
        release.github_release(manifest, self.output, github)
        self.assertEqual(github.writes, writes)

    def test_lost_upload_response_is_recovered_without_resending_existing_asset(self):
        manifest = self.prepare()
        github = FakeGitHub(manifest)
        github.lose_upload_response = True
        with self.assertRaisesRegex(release.ReleaseError, "Response lost"):
            release.github_release(manifest, self.output, github)
        self.assertEqual(len(github.stored), 1)
        self.assertTrue(github.current["draft"])
        release.github_release(manifest, self.output, github)
        self.assertEqual(len(github.stored), 2)
        self.assertFalse(github.current["draft"])

    def test_existing_zip_with_same_files_uses_its_actual_checksum(self):
        manifest = self.prepare()
        github = FakeGitHub(manifest)
        github.current = {"id": 7, "draft": False, "prerelease": False}
        archive = io.BytesIO()
        with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_STORED) as zipped:
            for name in manifest["files"]:
                zipped.write(self.output / "payload" / name, "zeropress-static/" + name)
        github.stored[1] = {"id": 1, "name": release.archive_name(manifest), "state": "uploaded", "data": archive.getvalue()}
        release.github_release(manifest, self.output, github)
        self.assertEqual(github.stored[2]["data"], (release.sha(archive.getvalue()) + "  " + release.archive_name(manifest) + "\n").encode())

    def test_changed_remote_zip_or_checksum_fails_without_overwriting(self):
        manifest = self.prepare()
        github = FakeGitHub(manifest)
        release.github_release(manifest, self.output, github)
        for asset_id in [1, 2]:
            original = github.stored[asset_id]["data"]
            github.stored[asset_id]["data"] = b"changed"
            before = list(github.writes)
            with self.assertRaises((release.ReleaseError, zipfile.BadZipFile)):
                release.github_release(manifest, self.output, github)
            self.assertEqual(before, github.writes)
            github.stored[asset_id]["data"] = original

    def test_remote_tag_changes_stop_release(self):
        manifest = self.prepare()
        github = FakeGitHub(manifest)
        with patch.object(github, "check_tag", side_effect=release.ReleaseError("Remote Git tag differs")):
            with self.assertRaisesRegex(release.ReleaseError, "Remote Git tag"):
                release.github_release(manifest, self.output, github)
        self.assertEqual(github.writes, [])

    def test_lightweight_and_annotated_remote_tags_must_resolve_to_the_commit(self):
        manifest = self.prepare()
        github = release.GitHub("owner/repo", "synthetic-secret")
        for objects in [[{"object": {"type": "commit", "sha": manifest["commit"]}}],
                        [{"object": {"type": "tag", "sha": "a" * 40}},
                         {"object": {"type": "commit", "sha": manifest["commit"]}}]]:
            with patch.object(github, "request", side_effect=objects):
                github.check_tag(manifest)
        with patch.object(github, "request", return_value={"object": {"type": "commit", "sha": "b" * 40}}):
            with self.assertRaisesRegex(release.ReleaseError, "Remote Git tag differs"):
                github.check_tag(manifest)

    def test_github_listing_finds_drafts_and_assets_across_pages(self):
        github = release.GitHub("owner/repo", "synthetic-secret")
        draft = {"id": 12, "tag_name": "v0.7.0", "draft": True}
        with patch.object(github, "request", side_effect=[[{"tag_name": "v0.6.0"}] * 100, [draft]]) as requests:
            self.assertEqual(github.release("v0.7.0"), draft)
            self.assertEqual(requests.call_count, 2)
        with patch.object(github, "request", side_effect=[[{"id": n} for n in range(100)], [{"id": 100}]]):
            self.assertEqual(len(github.assets(12)), 101)

    def test_http_failures_are_distinguished_and_tokens_not_forwarded(self):
        github = release.GitHub("owner/repo", "synthetic-secret")
        for status in [401, 403, 404, 429, 500]:
            error = urllib.error.HTTPError("https://api.github.com", status, "sensitive response", {}, io.BytesIO(b"sensitive body"))
            with patch.object(github.opener, "open", side_effect=error) as request:
                with self.assertRaisesRegex(release.ReleaseError, "HTTP " + str(status)) as result:
                    github.request("POST", "/releases", data={})
                self.assertNotIn("sensitive", str(result.exception))
                self.assertEqual(request.call_count, 1)
        original = urllib.request.Request("https://api.github.com/repos/owner/repo/releases/assets/1", headers={"Authorization": "Bearer synthetic-secret"})
        redirected = release.SafeRedirect().redirect_request(original, None, 302, "Found", {}, "https://release-assets.githubusercontent.com/signed")
        self.assertIsNone(redirected.get_header("Authorization"))
        with self.assertRaises(release.ReleaseError):
            release.SafeRedirect().redirect_request(original, None, 302, "Found", {}, "http://example.test/asset")


class FakeSVN:
    def __init__(self, manifest):
        self.manifest = manifest
        self.tags = []
        self.tag = manifest["files"]
        self.trunk = manifest["files"]

    def state(self):
        return "123", self.tags

    def hashes(self, relative, revision):
        assert revision == "123"
        return self.trunk if relative == "trunk" else self.tag


class FakeGitHub:
    repository = "owner/repo"

    def __init__(self, manifest):
        self.manifest = manifest
        self.current = None
        self.stored = {}
        self.writes = []
        self.lose_upload_response = False

    def check_tag(self, manifest):
        assert manifest == self.manifest

    def release(self, tag):
        assert tag == self.manifest["tag"]
        return self.current

    def assets(self, release_id):
        return list(self.stored.values())

    def request(self, method, route, data=None, binary=False, upload=False):
        if method != "GET":
            self.writes.append((method, route))
        if method == "POST" and route == "/releases":
            assert data["draft"] and data["target_commitish"] == self.manifest["commit"]
            self.current = {"id": 7, "draft": True, "prerelease": False}
            return self.current
        if method == "GET" and route.startswith("/releases/assets/"):
            assert binary
            return self.stored[int(route.rsplit("/", 1)[1])]["data"]
        if upload:
            asset_id = len(self.stored) + 1
            self.stored[asset_id] = {"id": asset_id, "name": route.split("?name=")[1], "state": "uploaded", "data": data}
            if self.lose_upload_response:
                self.lose_upload_response = False
                raise release.ReleaseError("Response lost")
            return self.stored[asset_id]
        if method == "PATCH" and route == "/releases/7":
            self.current.update(data)
            return self.current
        raise AssertionError("Unexpected GitHub request: " + method + " " + route)


if __name__ == "__main__":
    unittest.main()
