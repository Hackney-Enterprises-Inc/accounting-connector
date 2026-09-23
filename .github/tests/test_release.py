import importlib.util
import os
import subprocess
import tempfile
import unittest
from pathlib import Path


SCRIPT = Path(__file__).resolve().parents[1] / "scripts/resolve-release.py"
spec = importlib.util.spec_from_file_location("release", SCRIPT)
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class ReleaseTest(unittest.TestCase):
    def resolve(self, heading="## [Unreleased]", tags=None, **kwargs):
        args = dict(event="push", ref_type="branch", ref_name="main", input_tag="",
                    sha="new", heading=heading, tags=tags or {},
                    is_ancestor=lambda older, newer: False)
        args.update(kwargs)
        return release.resolve(**args)

    def test_first_release(self):
        self.assertEqual(self.resolve(), ("v0.0.1", "tag", "new", True))

    def test_patch_after_bare_tag(self):
        self.assertEqual(self.resolve(tags={"0.2.0": "old"}),
                         ("v0.2.1", "tag", "new", True))

    def test_numeric_order_and_ignore_prereleases(self):
        tags = {"v0.2.9": "a", "v0.2.10": "b", "v1.0.0-rc1": "c", "other": "d"}
        self.assertEqual(self.resolve(tags=tags)[0], "v0.2.11")

    def test_explicit_minor_and_major(self):
        for version in ["0.3.0", "1.0.0", "1.0.0-rc1"]:
            self.assertEqual(self.resolve(f"## [{version}] - 2026-09-23", {"0.2.0": "old"}),
                             (f"v{version}", "tag", "new", False))

    def test_old_changelog_heading_still_releases(self):
        self.assertEqual(self.resolve("## [0.2.0] - 2026-09-20", {"0.2.0": "old"})[0],
                         "v0.2.1")

    def test_rerun_reuses_commit_tag_even_with_newer_tags(self):
        self.assertEqual(self.resolve(tags={"v0.2.1": "new", "v0.2.2": "later"}),
                         ("v0.2.1", "verify-only", "refs/tags/v0.2.1", True))

    def test_explicit_rerun_keeps_changelog_notes(self):
        self.assertEqual(self.resolve("## [0.3.0] - 2026-09-23", {"v0.3.0": "new"})[-1], False)

    def test_descendant_tag_prevents_automatic_and_explicit_versions(self):
        tags = {"0.2.0": "later"}
        is_ancestor = lambda older, newer: (older, newer) == ("new", "later")
        for heading in ("## [Unreleased]", "## [0.3.0] - 2026-09-23"):
            with self.assertRaisesRegex(ValueError, "existing version tag 0.2.0"):
                self.resolve(heading, tags, is_ancestor=is_ancestor)

    def test_unrelated_tag_does_not_prevent_release(self):
        self.assertEqual(self.resolve(tags={"v0.2.0": "unrelated"}),
                         ("v0.2.1", "tag", "new", True))

    def test_manual_dispatch_qualifies_tag(self):
        self.assertEqual(self.resolve(tags={"v0.2.1": "old"}, event="workflow_dispatch",
                                      input_tag="v0.2.1")[2], "refs/tags/v0.2.1")

    def test_tag_push_ref_preserved(self):
        self.assertEqual(self.resolve(tags={"v0.2.1": "old"}, ref_type="tag",
                                      ref_name="v0.2.1")[2], "v0.2.1")

    def test_invalid_or_missing_manual_tag(self):
        for tag in ["v0.2.1", "main", "v1.2.3;echo bad", ""]:
            with self.assertRaises(ValueError):
                self.resolve(event="workflow_dispatch", input_tag=tag)

    def test_invalid_heading(self):
        with self.assertRaises(ValueError):
            self.resolve("## typo")

    def test_real_remote_branch_is_not_tag_and_annotated_tag_is_peeled(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            def git(*args):
                return subprocess.check_output(["git", *args], cwd=root, text=True,
                                               stderr=subprocess.DEVNULL).strip()
            git("init")
            git("config", "user.name", "Test")
            git("config", "user.email", "test@example.invalid")
            (root / "CHANGELOG.md").write_text("## [Unreleased]\n")
            git("add", "CHANGELOG.md")
            git("commit", "-m", "fixture")
            git("branch", "v0.2.1")
            git("remote", "add", "origin", str(root))
            env = dict(os.environ, EVENT_NAME="workflow_dispatch", REF_TYPE="branch",
                       REF_NAME="main", INPUT_TAG="v0.2.1", SHA=git("rev-parse", "HEAD"),
                       GITHUB_OUTPUT=str(root / "output"))
            result = subprocess.run(["python3", str(SCRIPT)], cwd=root, env=env, capture_output=True)
            self.assertNotEqual(result.returncode, 0)
            self.assertFalse((root / "output").exists())
            git("tag", "-a", "v0.2.1", "-m", "release")
            env["EVENT_NAME"] = "push"
            env["INPUT_TAG"] = ""
            subprocess.run(["python3", str(SCRIPT)], cwd=root, env=env, check=True, capture_output=True)
            self.assertIn("needed=verify-only\n", (root / "output").read_text())
            self.assertIn("ref=refs/tags/v0.2.1\n", (root / "output").read_text())
            # Dispatch reads the requested tag's changelog, not the default branch's.
            (root / "CHANGELOG.md").write_text("## [0.2.1] - 2026-09-23\n")
            git("add", "CHANGELOG.md")
            git("commit", "-m", "later main")
            (root / "output").unlink()
            env.update(EVENT_NAME="workflow_dispatch", INPUT_TAG="v0.2.1",
                       SHA=git("rev-parse", "HEAD"))
            subprocess.run(["python3", str(SCRIPT)], cwd=root, env=env, check=True, capture_output=True)
            self.assertIn("automatic=true\n", (root / "output").read_text())

    def test_main_fetches_remote_tags_and_rejects_older_commit(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            checkout = root / "checkout"
            checkout.mkdir()
            remote = root / "origin.git"

            def git(*args):
                return subprocess.check_output(["git", *args], cwd=checkout, text=True,
                                               stderr=subprocess.DEVNULL).strip()

            git("init")
            git("config", "user.name", "Test")
            git("config", "user.email", "test@example.invalid")
            (checkout / "CHANGELOG.md").write_text("## [Unreleased]\n")
            git("add", "CHANGELOG.md")
            git("commit", "-m", "old")
            old = git("rev-parse", "HEAD")
            (checkout / "CHANGELOG.md").write_text("## [0.2.0] - 2026-09-23\n")
            git("commit", "-am", "release")
            git("tag", "-a", "v0.2.0", "-m", "release")
            git("init", "--bare", str(remote))
            git("remote", "add", "origin", str(remote))
            git("push", "origin", "v0.2.0")
            git("tag", "-d", "v0.2.0")
            env = dict(os.environ, EVENT_NAME="push", REF_TYPE="branch", REF_NAME="main",
                       SHA=old, GITHUB_OUTPUT=str(root / "output"))
            result = subprocess.run(["python3", str(SCRIPT)], cwd=checkout, env=env,
                                    capture_output=True, text=True)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn("existing version tag v0.2.0", result.stderr)
            self.assertFalse((root / "output").exists())
            self.assertEqual(git("rev-parse", "v0.2.0^{}"), git("rev-parse", "HEAD"))

            (checkout / "CHANGELOG.md").write_text("## [Unreleased]\n")
            git("commit", "-am", "after release")
            env["SHA"] = git("rev-parse", "HEAD")
            subprocess.run(["python3", str(SCRIPT)], cwd=checkout, env=env,
                           check=True, capture_output=True)
            self.assertIn("tag=v0.2.1\n", (root / "output").read_text())


if __name__ == "__main__":
    unittest.main()
