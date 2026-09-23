"""Resolve a release without modifying the checkout or remote."""

import os
import re
import subprocess


VERSION = r"(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?"


def git(*args):
    return subprocess.check_output(["git", *args], text=True).strip()


def resolve(event, ref_type, ref_name, input_tag, sha, heading, tags):
    if event == "workflow_dispatch" or ref_type == "tag":
        tag = input_tag if event == "workflow_dispatch" else ref_name
        if not re.fullmatch("v" + VERSION, tag):
            raise ValueError(f"Invalid release tag: {tag!r}")
        if tag not in tags:
            raise ValueError(f"Tag {tag!r} does not exist on origin")
        automatic = not heading.startswith(f"## [{tag.removeprefix('v')}] - ")
        ref = f"refs/tags/{tag}" if event == "workflow_dispatch" else tag
        return tag, "verify-only", ref, automatic

    versions = {}
    for tag, commit in tags.items():
        version = tag.removeprefix("v")
        if re.fullmatch(VERSION, version):
            versions[tag] = (version, commit)

    # A rerun must repair the release for this commit, never allocate another tag.
    existing = sorted(tag for tag, (_, commit) in versions.items() if commit == sha)
    if existing:
        tag = existing[-1]
        automatic = not heading.startswith(f"## [{tag.removeprefix('v')}] - ")
        return tag, "verify-only", f"refs/tags/{tag}", automatic

    match = re.fullmatch(r"## \[(" + VERSION + r")\] - .+", heading)
    if heading != "## [Unreleased]" and match is None:
        raise ValueError("First changelog heading must be [Unreleased] or a dated version")
    if match and not any(version == match[1] for version, _ in versions.values()):
        return "v" + match[1], "tag", sha, False

    # Accept both historical bare tags and v-prefixed tags. Prereleases do not
    # advance the stable version line.
    stable = [tuple(map(int, version.split("."))) for version, _ in versions.values()
              if "-" not in version]
    major, minor, patch = max(stable, default=(0, 0, 0))
    return f"v{major}.{minor}.{patch + 1}", "tag", sha, True


def main():
    # A failed remote lookup must fail the run, not appear to be an empty tag list.
    refs = git("ls-remote", "--tags", "origin")
    tags = {}
    for line in refs.splitlines():
        commit, ref = line.split()
        tag = ref.removeprefix("refs/tags/")
        if not tag.endswith("^{}"):
            tags[tag] = commit
    for line in refs.splitlines():
        commit, ref = line.split()
        if ref.endswith("^{}"):
            tags[ref.removeprefix("refs/tags/").removesuffix("^{}")] = commit
    source = os.environ["SHA"]
    if os.environ["EVENT_NAME"] == "workflow_dispatch" or os.environ["REF_TYPE"] == "tag":
        tag = (os.environ.get("INPUT_TAG", "") if os.environ["EVENT_NAME"] == "workflow_dispatch"
               else os.environ["REF_NAME"])
        if not re.fullmatch("v" + VERSION, tag) or tag not in tags:
            raise ValueError(f"Requested release tag {tag!r} is invalid or missing on origin")
        source = f"refs/tags/{tag}"
    heading = next(line for line in git("show", f"{source}:CHANGELOG.md").splitlines()
                   if line.startswith("## "))
    tag, needed, ref, automatic = resolve(
        os.environ["EVENT_NAME"], os.environ["REF_TYPE"], os.environ["REF_NAME"],
        os.environ.get("INPUT_TAG", ""), os.environ["SHA"], heading, tags,
    )
    outputs = dict(tag=tag, version=tag.removeprefix("v"), needed=needed, ref=ref,
                   automatic=str(automatic).lower())
    with open(os.environ["GITHUB_OUTPUT"], "a") as output:
        for key, value in outputs.items():
            print(f"{key}={value}", file=output)
            print(f"{key}={value}")


if __name__ == "__main__":
    main()
