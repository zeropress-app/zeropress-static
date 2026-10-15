#!/usr/bin/env python3
"""Prepare and verify public-repository releases using Python's standard library."""

import argparse
import fnmatch
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
import zipfile

SLUG = "zeropress-static"
SVN_URL = "https://plugins.svn.wordpress.org/" + SLUG
VERSION = r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)"
MAX_DOWNLOAD = 32 * 1024 * 1024


class ReleaseError(Exception):
    pass


def require(condition, message):
    if not condition:
        raise ReleaseError(message)


def command(args, cwd=None):
    result = subprocess.run(args, cwd=cwd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    require(result.returncode == 0, "{} failed (exit {}).".format(args[0], result.returncode))
    return result.stdout


def sha(data):
    return hashlib.sha256(data).hexdigest()


def safe_path(name):
    path = PurePosixPath(name)
    return (bool(name) and not path.is_absolute() and str(path) == name
            and all(part not in (".", "..") for part in path.parts)
            and not any(ord(c) < 32 or c == "\\" for c in name))


def excluded(name, rules):
    for rule in rules:
        if rule.startswith("/"):
            prefix = rule.strip("/")
            if name == prefix or name.startswith(prefix + "/"):
                return True
        elif any(fnmatch.fnmatchcase(part, rule) for part in name.split("/")):
            return True
    return False


def source_files(repo):
    def git(*args):
        return command(["git", *args], repo)

    commit = git("rev-parse", "HEAD^{commit}").decode().strip()
    rules = git("show", commit + ":.distignore").decode().splitlines()
    rules = [r.strip() for r in rules if r.strip() and not r.lstrip().startswith("#")]
    require(all(not r.startswith("!") and (not r.startswith("/") or not any(c in r for c in "*?["))
                for r in rules), "Unsupported .distignore rule; use root paths or basename globs.")
    files = {}
    for record in git("ls-tree", "-rz", "--full-tree", commit).split(b"\0"):
        if not record:
            continue
        metadata, raw_name = record.split(b"\t", 1)
        name = raw_name.decode("utf-8")
        require(safe_path(name), "Unsafe Git file path.")
        if excluded(name, rules):
            continue
        mode, kind, object_id = metadata.decode().split()
        require(kind == "blob" and mode in ("100644", "100755"), "Release files must be regular Git files: " + name)
        files[name] = git("cat-file", "blob", object_id)
    timestamp = int(git("show", "-s", "--format=%ct", commit))
    return commit, timestamp, files


def plugin_version(files):
    header = files.get(SLUG + ".php", b"").decode()
    readme = files.get("readme.txt", b"").decode()
    version = re.search(r"^ \* Version: (" + VERSION + r")$", header, re.M)
    require(version is not None, "A numeric x.y.z plugin Version is required.")
    version = version.group(1)
    require(re.search(r"^const VERSION\s*=\s*'" + re.escape(version) + r"';$", header, re.M)
            and re.search(r"^Stable tag: " + re.escape(version) + r"$", readme, re.M),
            "Plugin Version, VERSION constant and Stable tag must agree.")
    require(" * Text Domain: " + SLUG in header, "Unexpected plugin text domain.")
    translation = ("Project-Id-Version: ZeroPress Static " + version).encode()
    require(translation + b"\\n" in files.get("languages/" + SLUG + "-ko_KR.po", b"")
            and translation + b"\n" in files.get("languages/" + SLUG + "-ko_KR.mo", b""),
            "Translation versions must match the plugin.")
    changelog(readme, version)
    return version


def changelog(readme, version):
    section = readme.split("== Changelog ==", 1)
    require(len(section) == 2, "A Changelog section is required.")
    match = re.match(r"\s*= " + re.escape(version) + r" =\s*\n(.*?)(?=\n=|\Z)", section[1], re.S)
    require(match is not None and match.group(1).strip(), "The first Changelog entry must describe this version.")
    return match.group(1).strip()


def require_tag(repo, version, commit):
    tagged = command(["git", "rev-parse", "--verify", "refs/tags/v" + version + "^{commit}"], repo).decode().strip()
    require(tagged == commit, "v" + version + " must point to the checked-out commit.")


def manifest_for(repo, tagged):
    commit, timestamp, files = source_files(repo)
    version = plugin_version(files)
    if tagged:
        require_tag(repo, version, commit)
    manifest = {"format": 1, "slug": SLUG, "version": version, "tag": "v" + version,
                "commit": commit, "files": {name: sha(data) for name, data in sorted(files.items())}}
    return manifest, timestamp, files


def emit(values):
    if os.environ.get("GITHUB_OUTPUT"):
        with open(os.environ["GITHUB_OUTPUT"], "a") as output:
            for key, value in values.items():
                require("\n" not in str(value), "Invalid workflow output.")
                output.write("{}={}\n".format(key, value))
    print(json.dumps(values, sort_keys=True))


def archive_name(manifest):
    return SLUG + "-v" + manifest["version"] + ".zip"


def prepare(repo, output, tagged=False):
    repo, output = Path(repo).resolve(), Path(output).resolve()
    require(repo != output and repo not in output.parents, "Release output must be outside the source checkout.")
    require(not output.exists(), "Choose a new output directory.")
    manifest, timestamp, files = manifest_for(repo, tagged)
    output.mkdir(parents=True)
    payload = output / "payload"
    payload.mkdir()
    date = time.gmtime(max(timestamp, 315532800))[:6]
    archive = output / archive_name(manifest)
    with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as zipped:
        for name, data in sorted(files.items()):
            target = payload / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(data)
            target.chmod(0o644)
            info = zipfile.ZipInfo(SLUG + "/" + name, date)
            info.create_system = 3
            info.external_attr = (stat.S_IFREG | 0o644) << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            zipped.writestr(info, data)
    (output / (archive.name + ".sha256")).write_text(sha(archive.read_bytes()) + "  " + archive.name + "\n")
    (output / "manifest.json").write_text(json.dumps(manifest, indent=2, sort_keys=True) + "\n")
    emit({key: manifest[key] for key in ("version", "tag", "commit")})
    return manifest


def tree_hashes(root):
    root = Path(root)
    result = {}
    for item in sorted(root.rglob("*")):
        require(not item.is_symlink(), "A release tree contains a symlink.")
        if item.is_dir():
            continue
        require(item.is_file(), "A release tree contains a non-regular file.")
        result[item.relative_to(root).as_posix()] = sha(item.read_bytes())
    return result


def compare_files(actual, expected, label):
    changed = sorted(name for name in set(actual) | set(expected) if actual.get(name) != expected.get(name))
    require(not changed, label + " differs from the release: " + ", ".join(changed[:8]))


def check_zip(data, expected):
    require(len(data) <= MAX_DOWNLOAD, "ZIP exceeds the release size limit.")
    with zipfile.ZipFile(io.BytesIO(data)) as archive:
        result = {}
        total = 0
        for info in archive.infolist():
            total += info.file_size
            require(total <= MAX_DOWNLOAD, "Expanded ZIP exceeds the release size limit.")
            name = info.filename
            require(name.startswith(SLUG + "/"), "Unexpected ZIP root directory.")
            name = name[len(SLUG) + 1:]
            if info.is_dir():
                require(not name or safe_path(name.rstrip("/")), "Unsafe ZIP directory.")
                continue
            require(safe_path(name) and name not in result
                    and not stat.S_ISLNK(info.external_attr >> 16), "Unsafe or duplicate ZIP file.")
            result[name] = sha(archive.read(info))
        compare_files(result, expected, "ZIP")


def verify_package(repo, output, tagged=True):
    output = Path(output)
    manifest, _, _ = manifest_for(repo, tagged)
    require(json.loads((output / "manifest.json").read_text()) == manifest, "Release manifest differs from Git.")
    compare_files(tree_hashes(output / "payload"), manifest["files"], "Payload")
    archive = output / archive_name(manifest)
    data = archive.read_bytes()
    check_zip(data, manifest["files"])
    require((output / (archive.name + ".sha256")).read_text() == sha(data) + "  " + archive.name + "\n",
            "ZIP checksum differs.")
    return manifest


def version_tuple(value):
    require(re.fullmatch(VERSION, value), "SVN contains an unsupported release tag: " + value)
    return tuple(map(int, value.split(".")))


class SVN:
    def __init__(self, url=SVN_URL):
        self.url = url

    def run(self, *args):
        return command(["svn", "--non-interactive", *args])

    def state(self):
        root = ET.fromstring(self.run("info", "--xml", self.url))
        revision = root.find("entry").get("revision")
        require(revision and revision.isdigit(), "SVN revision is unavailable.")
        listing = ET.fromstring(self.run("list", "--xml", "-r", revision, self.url + "/tags@" + revision))
        entries = listing.findall("./list/entry")
        require(all(e.get("kind") == "dir" for e in entries), "SVN tags must be directories.")
        tags = [e.findtext("name") for e in entries]
        for tag in tags:
            version_tuple(tag)
        return revision, tags

    def hashes(self, relative, revision):
        with tempfile.TemporaryDirectory(prefix="wordpress-release-svn-") as temp:
            target = Path(temp) / "export"
            self.run("export", "--quiet", "--ignore-externals", "--native-eol", "LF", "-r", revision,
                     self.url + "/" + relative + "@" + revision, str(target))
            return tree_hashes(target)


def svn_status(manifest, svn, must_exist=False):
    revision, tags = svn.state()
    version = manifest["version"]
    exists = version in tags
    newer = any(version_tuple(tag) > version_tuple(version) for tag in tags)
    require(exists or not must_exist, "SVN release tag is not confirmed. Inspect WordPress.org before rerunning.")
    require(exists or not newer, "A newer SVN version exists; refusing to move trunk backwards.")
    if exists:
        compare_files(svn.hashes("tags/" + version, revision), manifest["files"], "SVN tag")
        if not newer:
            compare_files(svn.hashes("trunk", revision), manifest["files"], "SVN trunk")
    return {"exists": str(exists).lower(), "svn_revision": revision}


class SafeRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        # Asset downloads can redirect to a signed CDN URL. Never forward the API token.
        require(request.get_method() == "GET" and urllib.parse.urlsplit(newurl).scheme == "https",
                "Unexpected GitHub redirect.")
        redirected = super().redirect_request(request, fp, code, msg, headers, newurl)
        redirected.remove_header("Authorization")
        return redirected


class GitHub:
    def __init__(self, repository, token):
        require(re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repository), "Invalid GitHub repository.")
        require(token, "GITHUB_TOKEN is required.")
        self.repository = repository
        self.token = token
        self.opener = urllib.request.build_opener(SafeRedirect())

    def request(self, method, route, data=None, binary=False, upload=False):
        host = "uploads.github.com" if upload else "api.github.com"
        url = "https://" + host + "/repos/" + self.repository + route
        headers = {"Authorization": "Bearer " + self.token, "User-Agent": SLUG + "-release",
                   "Accept": "application/octet-stream" if binary else "application/vnd.github+json",
                   "X-GitHub-Api-Version": "2022-11-28"}
        if data is not None:
            headers["Content-Type"] = "application/octet-stream" if upload else "application/json"
            if not upload:
                data = json.dumps(data).encode()
        request = urllib.request.Request(url, data=data, headers=headers, method=method)
        try:
            with self.opener.open(request, timeout=60) as response:
                body = response.read(MAX_DOWNLOAD + 1)
        except urllib.error.HTTPError as error:
            error.close()
            raise ReleaseError("GitHub {} failed (HTTP {}). Inspect the release before rerunning.".format(method, error.code)) from None
        except (urllib.error.URLError, TimeoutError):
            raise ReleaseError("GitHub response unavailable. Inspect the release before rerunning.") from None
        require(len(body) <= MAX_DOWNLOAD, "GitHub response exceeds the size limit.")
        return body if binary else (json.loads(body) if body else None)

    def check_tag(self, manifest):
        ref = self.request("GET", "/git/ref/tags/" + manifest["tag"])["object"]
        for _ in range(5):
            if ref["type"] != "tag":
                break
            ref = self.request("GET", "/git/tags/" + ref["sha"])["object"]
        require(ref["type"] == "commit" and ref["sha"] == manifest["commit"], "Remote Git tag differs from the validated commit.")

    def release(self, tag):
        # Listing with authentication also finds a draft left by an interrupted run.
        for page in range(1, 101):
            releases = self.request("GET", "/releases?per_page=100&page=" + str(page))
            matches = [r for r in releases if r["tag_name"] == tag]
            require(len(matches) <= 1, "Multiple GitHub releases have the same tag.")
            if matches:
                return matches[0]
            if len(releases) < 100:
                return None
        raise ReleaseError("Release listing limit reached.")

    def assets(self, release_id):
        result = []
        for page in range(1, 101):
            items = self.request("GET", "/releases/{}/assets?per_page=100&page={}".format(release_id, page))
            result.extend(items)
            if len(items) < 100:
                return result
        raise ReleaseError("Release asset listing limit reached.")


def github_release(manifest, output, github):
    output = Path(output)
    github.check_tag(manifest)
    release = github.release(manifest["tag"])
    if release is None:
        notes = changelog((output / "payload/readme.txt").read_text(), manifest["version"])
        release = github.request("POST", "/releases", {
            "tag_name": manifest["tag"], "target_commitish": manifest["commit"],
            "name": "ZeroPress Static " + manifest["version"], "draft": True,
            "body": notes + "\n\nSource commit: `" + manifest["commit"] + "`.\n"
                    "The installation ZIP contains the verified WordPress.org SVN tag files.\n"})
    require(not release["prerelease"], "Expected a stable GitHub release.")
    release_id = release["id"]
    assets = github.assets(release_id)
    name = archive_name(manifest)
    expected = {name, name + ".sha256"}
    matches = [a for a in assets if a["name"] in expected]
    require(len({a["name"] for a in matches}) == len(matches), "Duplicate release asset names.")
    assets = {a["name"]: a for a in matches}
    for asset in assets.values():
        require(asset["state"] == "uploaded", "Incomplete release asset; inspect it before rerunning.")
    data = (output / name).read_bytes()
    if name in assets:
        data = github.request("GET", "/releases/assets/" + str(assets[name]["id"]), binary=True)
    check_zip(data, manifest["files"])
    checksum = (sha(data) + "  " + name + "\n").encode()
    # Check all existing assets before uploading any missing asset.
    if name + ".sha256" in assets:
        remote = github.request("GET", "/releases/assets/" + str(assets[name + ".sha256"]["id"]), binary=True)
        require(remote == checksum, "Existing release checksum differs; assets were not overwritten.")
    for filename, content in ((name, data), (name + ".sha256", checksum)):
        if filename not in assets:
            asset = github.request("POST", "/releases/{}/assets?name={}".format(release_id, urllib.parse.quote(filename)),
                                   data=content, upload=True)
            received = github.request("GET", "/releases/assets/" + str(asset["id"]), binary=True)
            require(received == content, "Uploaded release asset differs.")
    github.check_tag(manifest)
    if release["draft"]:
        # Let GitHub select Latest by version/date, including when repairing an older release.
        github.request("PATCH", "/releases/" + str(release_id), data={"draft": False, "make_latest": "legacy"})
    return {"release_url": "https://github.com/" + github.repository + "/releases/tag/" + manifest["tag"]}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=("prepare", "verify-package", "svn-status", "verify-svn", "github-release"))
    parser.add_argument("--repo", default=".")
    parser.add_argument("--output", required=True)
    parser.add_argument("--require-tag", action="store_true")
    args = parser.parse_args()
    if args.action == "prepare":
        prepare(args.repo, args.output, args.require_tag)
        return
    manifest = verify_package(args.repo, args.output)
    if args.action == "verify-package":
        emit({"verified_commit": manifest["commit"]})
    elif args.action in ("svn-status", "verify-svn"):
        emit(svn_status(manifest, SVN(), args.action == "verify-svn"))
    else:
        # Verify again immediately before publishing/repairing GitHub artifacts.
        svn_status(manifest, SVN(), must_exist=True)
        emit(github_release(manifest, args.output, GitHub(os.environ.get("GITHUB_REPOSITORY", ""),
                                                        os.environ.get("GITHUB_TOKEN", ""))))


if __name__ == "__main__":
    try:
        main()
    except (ReleaseError, OSError, ValueError, KeyError, AttributeError, ET.ParseError, zipfile.BadZipFile) as error:
        # Network errors are translated above; never print API response bodies or credentials.
        print("Release check failed: " + str(error), file=sys.stderr)
        sys.exit(1)
