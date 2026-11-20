#!/usr/bin/env python3
"""Prepare and verify stable MCSM releases without external dependencies."""

import argparse
import re
import subprocess
import sys
import zipfile
from pathlib import Path, PurePosixPath


VERSION = re.compile(r"(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)")
HEADER = re.compile(r"^( \* Version: )[\S]+[ \t]*$", re.MULTILINE)
CONSTANT = re.compile(r"^(define\( 'MCSM_VERSION', ')[^']+(' \);)$", re.MULTILINE)
CHANGELOG = re.compile(r"^## Changelog[ \t]*\n", re.MULTILINE)
CHANGELOG_ENTRY = re.compile(r"^### ([^\n]+)\n(.*?)(?=^### |\Z)", re.MULTILINE | re.DOTALL)


def normalize(value):
    version = value.strip().removeprefix("v")
    if not VERSION.fullmatch(version):
        raise ValueError("Use a stable version such as 1.4.1, optionally prefixed with v.")
    return version


def plugin_version(source):
    headers = HEADER.findall(source)
    constants = CONSTANT.findall(source)
    if len(headers) != 1 or len(constants) != 1:
        raise ValueError("Expected exactly one Version header and MCSM_VERSION constant.")
    header = HEADER.search(source).group(0).split(":", 1)[1].strip()
    constant = CONSTANT.search(source).group(0).split("'")[3]
    if header != constant or not VERSION.fullmatch(header):
        raise ValueError("Version header and MCSM_VERSION must contain the same stable version.")
    return header


def changelog_start(readme):
    matches = list(CHANGELOG.finditer(readme))
    if len(matches) != 1:
        raise ValueError("Expected exactly one Changelog section in README.md.")
    return matches[0].end()


def changes_since(version, root):
    history = subprocess.run(
        ["git", "log", "--no-merges", "--format=%s", f"refs/tags/v{version}..HEAD", "--"],
        cwd=root,
        capture_output=True,
        encoding="utf-8",
    )
    if history.returncode:
        raise ValueError(f"Cannot read changes since v{version}. Fetch the tag or add notes under Unreleased.")
    subjects = [line.strip() for line in history.stdout.splitlines() if line.strip()]
    return "\n".join(f"- {subject}" for subject in subjects) or "No changes were recorded since the previous release."


def prepare(version, root, resume=False):
    plugin_path = root / "mcsm.php"
    readme_path = root / "README.md"
    source = plugin_path.read_text(encoding="utf-8")
    readme = readme_path.read_text(encoding="utf-8")
    current = plugin_version(source)
    start = changelog_start(readme)
    tail = readme[start:]
    first = CHANGELOG_ENTRY.search(tail)
    if resume:
        if current != version or not first or first.group(1).strip() != version:
            raise ValueError("Existing release commit does not match the requested version/changelog.")
        return first.group(2).strip()

    if tuple(map(int, version.split("."))) <= tuple(map(int, current.split("."))):
        raise ValueError(f"New version {version} must be higher than {current}.")
    if re.search(rf"^### {re.escape(version)}[ \t]*$", tail, re.MULTILINE):
        raise ValueError(f"Changelog already contains version {version}.")

    notes = ""
    if first and first.group(1).strip() == "Unreleased" and not tail[:first.start()].strip():
        notes = first.group(2).strip()
        tail = tail[first.end():]
    if not notes:
        notes = changes_since(current, root)

    source = HEADER.sub(lambda match: match.group(1) + version, source)
    source = CONSTANT.sub(lambda match: match.group(1) + version + match.group(2), source)
    readme = readme[:start] + f"\n### {version}\n\n{notes}\n\n" + tail.lstrip()
    plugin_path.write_text(source, encoding="utf-8")
    readme_path.write_text(readme, encoding="utf-8")
    return notes


def verify(version, package):
    with zipfile.ZipFile(package) as archive:
        if archive.testzip() is not None:
            raise ValueError("ZIP integrity check failed.")
        names = set(archive.namelist())
        required = {"mcsm/mcsm.php", "mcsm/README.md", "mcsm/LICENSE.md", "mcsm/uninstall.php"}
        if not required <= names:
            raise ValueError("Package is missing required plugin files.")
        for name in names:
            path = PurePosixPath(name)
            if path.is_absolute() or ".." in path.parts or path.parts[0] != "mcsm":
                raise ValueError(f"Invalid package path: {name}")
            if len(path.parts) > 1 and path.parts[1] in {
                ".git", ".github", ".gitattributes", ".gitignore", "scripts", "tests"
            }:
                raise ValueError(f"Development file leaked into the package: {name}")
        if plugin_version(archive.read("mcsm/mcsm.php").decode("utf-8")) != version:
            raise ValueError("Packaged plugin version does not match the release tag.")
        readme = archive.read("mcsm/README.md").decode("utf-8")
        first = CHANGELOG_ENTRY.search(readme[changelog_start(readme):])
        if not first or first.group(1).strip() != version:
            raise ValueError("Packaged changelog does not match the release tag.")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    commands.add_parser("current")
    for command in ("normalize", "prepare", "verify"):
        subparser = commands.add_parser(command)
        subparser.add_argument("version")
        if command == "prepare":
            subparser.add_argument("--resume", action="store_true")
        elif command == "verify":
            subparser.add_argument("package", type=Path)
    args = parser.parse_args()
    try:
        if args.command == "current":
            print(plugin_version((Path.cwd() / "mcsm.php").read_text(encoding="utf-8")))
            return 0
        version = normalize(args.version)
        if args.command == "normalize":
            print(version)
        elif args.command == "prepare":
            print(prepare(version, Path.cwd(), args.resume))
        else:
            verify(version, args.package)
    except (ValueError, OSError, zipfile.BadZipFile) as error:
        print(f"Release error: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
