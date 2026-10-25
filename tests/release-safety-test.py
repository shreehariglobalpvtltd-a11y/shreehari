#!/usr/bin/env python3
"""Offline release integration tests: real Git/files, stubbed VPS services.

Runs a sandbox copy of the actual deployer with its four fixed storage paths
replaced. Never loads app config, contacts production or connects to a DB.
"""
import fcntl
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]


class ReleaseSafety(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix="shg-release-test-")
        self.addCleanup(self.tmp.cleanup)
        self.home = Path(self.tmp.name)
        self.repo = self.home / "repo"
        self.repo.mkdir()
        self.git("init", "-q", "-b", "base")
        self.git("config", "user.name", "Release test")
        self.git("config", "user.email", "release@test.invalid")
        (self.repo / "app.txt").write_text("base\n")
        (self.repo / "obsolete.txt").write_text("keep until reviewed\n")
        self.base = self.commit("base")
        self.git("switch", "-qc", "candidate")
        (self.repo / "app.txt").write_text("target\n")
        (self.repo / "added.txt").write_text("new file\n")
        (self.repo / "obsolete.txt").unlink()
        self.target = self.commit("target")
        self.release = "release-20261026-safety1"
        self.gitdir = self.repo / ".git"
        self.live = self.home / "live"
        self.live.mkdir()
        (self.live / "app.txt").write_text("base\n")
        (self.live / "obsolete.txt").write_text("keep until reviewed\n")
        self.lock = self.home / "deploy.lock"
        self.backups = self.home / "backups"
        self.scripts = self.home / "deploy"
        self.scripts.mkdir()
        source = (ROOT / "deploy/deploy-prod.sh").read_text()
        substitutions = {
            "GIT_DIR=/var/repos/shg.git": f"GIT_DIR={self.gitdir}",
            "LIVE=/var/www/shreehariglobal.in/public_html": f"LIVE={self.live}",
            "LOCK=/var/lock/shg-deploy.lock": f"LOCK={self.lock}",
            "BK=/root/backups/pre-deploy-$REL-$TS": f"BK={self.backups}/pre-deploy-$REL-$TS",
        }
        for old, new in substitutions.items():
            self.assertEqual(source.count(old), 1, "sandbox path mapping must be exact")
            source = source.replace(old, new)
        self.deployer = self.scripts / "deploy-prod.sh"
        self.deployer.write_text(source)
        shutil.copyfile(ROOT / "deploy/release-preflight.sh", self.scripts / "release-preflight.sh")
        self.bin = self.home / "bin"
        self.bin.mkdir()
        stubs = {
            "chown": "exit 0\n",
            "systemctl": "exit 0\n",
            "curl": "printf '%s' \"${SHG_TEST_HTTP:-200}\"\nexit \"${SHG_TEST_CURL_EXIT:-0}\"\n",
        }
        # The hook is a script FILE executed by this stub, never eval'd.
        stubs["mysqldump"] = 'printf "synthetic backup only\\n"\nif [[ -n "${SHG_TEST_HOOK:-}" ]]; then bash "$SHG_TEST_HOOK"; fi\n'
        for name, body in stubs.items():
            p = self.bin / name
            p.write_text("#!/usr/bin/env bash\nset -euo pipefail\n" + body)
            p.chmod(0o755)
        self.env = dict(os.environ, PATH=str(self.bin) + os.pathsep + os.environ["PATH"])
        self.env.pop("SHG_TEST_HOOK", None)
        self.env.pop("SHG_TEST_HTTP", None)
        self.env.pop("SHG_TEST_CURL_EXIT", None)

    def git(self, *args):
        return subprocess.run(["git", "-C", str(self.repo), *args], check=True,
                              capture_output=True, text=True).stdout.strip()

    def commit(self, message):
        self.git("add", "-A")
        self.git("commit", "-qm", message)
        return self.git("rev-parse", "HEAD")

    def plan(self, base="base", target="candidate", release=None):
        return subprocess.run(["bash", str(ROOT / "deploy/release-preflight.sh"),
                               str(self.gitdir), base, target, release or self.release],
                              capture_output=True, text=True)

    def deploy(self, *extra, env=None):
        return subprocess.run(["bash", str(self.deployer), "base", "candidate", self.release, *extra],
                              env=env or self.env, capture_output=True, text=True, timeout=15)

    def hook(self, code):
        path = self.home / "during-backup.sh"
        path.write_text("set -euo pipefail\n" + code)
        return dict(self.env, SHG_TEST_HOOK=str(path))

    def test_descendant_plan_has_exact_commits(self):
        result = self.plan()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.splitlines(), [self.base, self.target])

    def test_full_commit_and_annotated_tag_supported(self):
        self.git("tag", "-am", "reviewed", "reviewed", self.target)
        self.assertEqual(self.plan(self.base, "reviewed").stdout.splitlines(), [self.base, self.target])

    def test_older_target_rejected(self):
        result = self.plan("candidate", "base")
        self.assertEqual(result.returncode, 2)
        self.assertIn("downgrade", result.stderr)

    def test_divergent_target_rejected(self):
        self.git("switch", "-qc", "other", self.base)
        (self.repo / "app.txt").write_text("other history\n")
        self.commit("diverged")
        self.assertEqual(self.plan("candidate", "other").returncode, 2)

    def test_unknown_ref_rejected(self):
        self.assertEqual(self.plan(target="does-not-exist").returncode, 2)

    def test_option_ref_rejected(self):
        self.assertEqual(self.plan(target="--help").returncode, 2)

    def test_unsafe_release_names_rejected(self):
        for name in ["../../escape", "main", "release-20261026-../escape", "release-20261026-a.lock", "-danger"]:
            with self.subTest(name=name):
                self.assertEqual(self.plan(release=name).returncode, 2)

    def test_existing_release_never_overwritten(self):
        self.git("branch", self.release, self.base)
        self.assertEqual(self.plan().returncode, 2)
        self.assertEqual(self.git("rev-parse", self.release), self.base)

    def test_plan_stays_pinned_after_branch_moves(self):
        ids = self.plan().stdout.splitlines()
        self.git("update-ref", "refs/heads/candidate", self.base)
        self.assertEqual(self.git("show", ids[1] + ":app.txt"), "target")

    def test_dry_run_preserves_live_and_refs(self):
        result = self.deploy("--dry-run")
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("dry run: nothing written", result.stdout)
        self.assertEqual((self.live / "app.txt").read_text(), "base\n")
        self.assertFalse((self.live / "added.txt").exists())
        self.assertFalse(self.backups.exists())
        self.assertEqual(self.git("for-each-ref", "refs/heads/" + self.release), "")

    def test_unknown_optional_argument_fails_closed(self):
        result = self.deploy("--dry-rnu")
        self.assertEqual(result.returncode, 2)
        self.assertFalse(self.backups.exists())

    def test_live_drift_prevents_writes(self):
        (self.live / "app.txt").write_text("other session\n")
        result = self.deploy()
        self.assertEqual(result.returncode, 3, result.stdout + result.stderr)
        self.assertEqual((self.live / "app.txt").read_text(), "other session\n")
        self.assertFalse(self.backups.exists())

    def test_lock_blocks_concurrent_deploy(self):
        with self.lock.open("w") as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            result = self.deploy("--dry-run")
        self.assertEqual(result.returncode, 2)
        self.assertIn("another deployment", result.stderr)

    def test_direct_edit_during_backup_is_preserved(self):
        env = self.hook(f"printf 'concurrent edit\\n' > '{self.live}/app.txt'\n")
        result = self.deploy(env=env)
        self.assertEqual(result.returncode, 3, result.stdout + result.stderr)
        self.assertEqual((self.live / "app.txt").read_text(), "concurrent edit\n")
        self.assertFalse((self.live / "added.txt").exists())

    def test_release_name_claimed_during_backup_is_preserved(self):
        env = self.hook(f"git --git-dir='{self.gitdir}' branch '{self.release}' '{self.base}'\n")
        result = self.deploy(env=env)
        self.assertEqual(result.returncode, 2, result.stdout + result.stderr)
        self.assertEqual(self.git("rev-parse", self.release), self.base)
        self.assertEqual((self.live / "app.txt").read_text(), "base\n")

    def test_branch_move_during_backup_cannot_change_payload(self):
        env = self.hook(f"git --git-dir='{self.gitdir}' update-ref refs/heads/candidate '{self.base}'\n")
        result = self.deploy(env=env)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual((self.live / "app.txt").read_text(), "target\n")
        self.assertEqual(self.git("rev-parse", self.release), self.target)

    def test_sandbox_release_and_code_rollback_record(self):
        result = self.deploy()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual((self.live / "added.txt").read_text(), "new file\n")
        self.assertTrue((self.live / "obsolete.txt").exists(), "deletions remain manual")
        backup = next(self.backups.iterdir())
        self.assertEqual((backup / "app.txt").read_text(), "base\n")
        rollback = (backup / "ROLLBACK.txt").read_text()
        self.assertIn(self.base, rollback)
        self.assertIn(self.target, rollback)
        self.assertIn("Do not restore", rollback)
        self.assertEqual(self.git("rev-parse", self.release), self.target)

    def test_failed_health_check_is_not_success(self):
        result = self.deploy(env=dict(self.env, SHG_TEST_HTTP="503"))
        self.assertEqual(result.returncode, 5, result.stdout + result.stderr)
        self.assertIn("DEPLOY NOT HEALTHY", result.stdout)

    def test_health_transport_failure_reports_unhealthy(self):
        result = self.deploy(env=dict(self.env, SHG_TEST_CURL_EXIT="28"))
        self.assertEqual(result.returncode, 5, result.stdout + result.stderr)
        self.assertIn("home -> 000", result.stdout)
        self.assertIn("DEPLOY NOT HEALTHY", result.stdout)


if __name__ == "__main__":
    unittest.main(verbosity=2)
