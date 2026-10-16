# How code reaches shreehariglobal.in

This branch root **is** the webroot layout: `index.php` here is `/var/www/shreehariglobal.in/public_html/index.php` on the VPS. Read this before deploying anything.

## 1. What is live, and how to prove it

The live branch is whichever `release-*` branch in **`/var/repos/shg.git` on the VPS** matches the webroot. Work it out — do not take it from a name someone handed you, including this file: releases land several times a day.

Three things that are *not* evidence:

- **GitHub.** The `release-*` branches of the current series *are* mirrored to `origin` (since 16 Oct 2026), but the mirror is a copy pushed by hand after the fact - it can lag, and older series were never pushed at all. A branch being on GitHub is not evidence it is live.
- **The newest commit date.** A release branch can be cut and never deployed.
- **The release name.** The same name can point at different commits in the two repos — today `release-20261016-tm3` is `7ff1c49` on GitHub but `dbc396d` in `/var/repos/shg.git`. **`deploy-prod.sh` resolves names in `/var/repos/shg.git`, so that is the one that counts.**

Find it by comparing the recent candidates against the webroot (run on the VPS):

```bash
G='git --git-dir=/var/repos/shg.git'; L=/var/www/shreehariglobal.in/public_html
for b in $($G for-each-ref --sort=-committerdate --format='%(refname:short)' refs/heads/release-* | head -5); do
  d=0
  for f in $($G ls-tree -r --name-only $b | grep -vE '^(docs|tests|\.github)/'); do
    [ -e "$L/$f" ] || continue
    a=$($G show $b:$f | tr -d '\r' | md5sum | cut -c1-32)
    c=$(tr -d '\r' < "$L/$f" | md5sum | cut -c1-32)
    [ "$a" = "$c" ] || d=$((d+1))
  done
  echo "$b: $d file(s) differ from live"
done
```

The lowest count is your base. It is rarely zero — files an unlanded PR touches, and `tools/` and `database/` files that are never deployed, both show up. Read the list, do not just trust the number.

At the time of writing (16 Oct 2026, ~20:15 IST) that was **`release-20261016-tm5`** (`c3aeeba`, service worker `shg-v227`), and only `.gitignore`, `deploy/VPS-SETUP.md` and `deploy/navdata/02-build.sh` differed — none of them served. It was `tm4` fifteen minutes earlier. Run the loop; do not reuse that name.

Never trust an HTTP 200 alone: nginx answers 200 from a stale tree just as happily. Grep a marker:

```bash
curl -s https://www.shreehariglobal.in/sw.js | grep -m1 "var VERSION"
curl -s https://www.shreehariglobal.in/ | grep -oE '\?v=[0-9a-z]+' | sort -u
```

`sw.js`'s `VERSION` and the `?v=` asset stamp are both bumped by `deploy/bump-asset-ver.js` at release time, so they identify the release. To confirm exactly, md5 live against the branch **with CRs stripped** (a Windows copy writes CRLF, git stores LF — line endings are not drift):

```bash
# on the VPS
G='git --git-dir=/var/repos/shg.git'; L=/var/www/shreehariglobal.in/public_html
diff <($G show <live-branch>:sw.js | tr -d '\r') <(tr -d '\r' < $L/sw.js)
```

## 1a. Claiming the next release name

Release names are allocated in **`/var/repos/shg.git` on the VPS** - not on GitHub, not in a
worktree. A name is taken the moment a branch of that name exists there.

**Check both repos before you claim one.** This must print nothing:

```bash
NAME=release-20261016-x1
git ls-remote origin "refs/heads/$NAME"
ssh -i ~/.ssh/shg_vps_ed25519 root@93.127.167.249 \
  "git --git-dir=/var/repos/shg.git show-ref --verify refs/heads/$NAME" 2>/dev/null
```

Any output at all, on either side, means the name is taken - take the next number. Checking only
one repo is exactly what produced two different `release-20261016-tm3` commits on 16 Oct 2026, one
on GitHub and one on the VPS, when two sessions each took "the next number after tm2".

`deploy-prod.sh` now refuses a name that is already taken there by a different commit, and warns if
GitHub has it on a different commit, so you do not have to rely on remembering this. Re-running the
same release with the same ref is allowed, so a failed deploy can be retried.

Three rules that go with it:

- **A deployed `release-*` branch is immutable.** Never merge into one, never force-update one,
  never reuse its name. A backup directory is named after it, and other people's work is based on
  it. Open PRs should target a stable branch, not a release - and a PR based on a release branch
  must never be merged on GitHub, or that name starts meaning two commits.
- **The series letter is per topic, not per session** (`tm` brand/trademark, `ui` SmartBook polish,
  `w` WhatsApp, `p`/`q`/`u` earlier the same way). A new topic starts a new letter, so two people
  working on different things on the same day never contend for the same number.
- **The release is not finished until it is on GitHub.** The VPS has no GitHub credentials and must
  not be given any, so `deploy-prod.sh` prints the `git push` for you at the end - run it from your
  own machine. Skipping it leaves GitHub a partial mirror, which is worse than no mirror: a reader
  seeing `tm2 -> tm3 -> tm5` infers a chain that the `tm3` they can see is not part of.

Because `deploy-prod.sh` filters `tests/`, `docs/` and `.github/` out of the file list, a release
touching only those writes 0 files and **creates no release branch at all**. Test-only work has to
ride along with a release that carries a web file, or it sits unreleased indefinitely with nothing
complaining - which is how a test revert went unnoticed for hours on 16 Oct 2026. So release
numbers are **not dense**: a gap is not evidence that a release was skipped or lost.

### The rule runs in both directions

Checking both repos before claiming a name catches only half of the problem. The other half is work
that is real on one side and **unrecorded on the side that governs releases**, and on 16 Oct 2026
that happened three times in one day:

- brand markup was live on the filesystem but untracked in git (`live-logo-20261016c` captured it);
- `assets/img/logo-tm.png` / `.webp` were served by live but in no branch at all;
- a merged PR's commit existed only on GitHub, with no copy in `/var/repos/shg.git`.

Only the third is about naming. The shape they share is that **`/var/repos/shg.git` is the only
repository a deploy can see**, so anything that is not in it cannot be released, however finished it
looks elsewhere. Two rules follow:

- **Merging a PR on GitHub does not land it.** The merge commit has to be pushed to
  `/var/repos/shg.git` as well, and then ride a release, before it is anywhere a deploy can reach.
  Nothing prompts either step - so do both in the same sitting as the merge, or say in the PR where
  the work now lives.
- **Never merge a PR into a `release-*` branch.** It moves a name that the VPS has already fixed to
  a different commit, which is how `release-20261016-tm5` came to mean `c3aeeba` on the VPS and a
  merge commit on GitHub, within an hour of `tm3` doing the same thing. Base PRs on a stable branch
  and land them through a release.

## 2. The route

```bash
bash deploy-prod.sh <base-branch> <new-ref> <release-name> [--dry-run]
```

Run it **on the VPS, as root** (`root@93.127.167.249`, key `~/.ssh/shg_vps_ed25519`). The copy that is actually invoked is `/root/deploy-prod.sh`; `deploy/deploy-prod.sh` in this tree is the same script under version control. Its header block documents the arguments and the five steps — read it, this file does not repeat it.

- `<base-branch>` is the release that is live now — work it out per §1, do not assume.
- `<new-ref>` must already be **pushed to `/var/repos/shg.git`** — push your branch there first, not just to GitHub. The script aborts if the ref is unknown, or if `<new-ref>` is not built on `<base-branch>`.
- `<new-ref>` must contain the `bump-asset-ver.js` bump, otherwise browsers keep the old cached bundle.
- **Always run `--dry-run` first.** It prints the exact file list and any `database/` files, and writes nothing.

The drift guard is the important part: if anyone wrote to live since `<base-branch>`, it aborts before touching a single file. That guard exists because of the 4 Oct 2026 outage, when a session copied months-old files over live. Do not work around it — find out who changed live and why.

Not the route, under any circumstances:

- **`deploy.sh` at the repo root** — it does not exist on this branch, and the copy on the other lineage is a retired tombstone pinned to a `public_html/` layout that does not match this tree. Running it would overwrite live with months-old unrelated code.
- **The GitHub "Deploy to VPS" workflow** — not on this branch either (only `checks.yml` is), but it exists on the other lineage.
  It clones from GitHub, which does not carry the `release-*` branches, and it swaps the whole webroot. Do not revive it.

The script deliberately does not do these; they are yours:

- `database/*.sql` migrations — listed for you, applied **by hand**, in order.
- Files deleted by the release — listed, left on live; remove by hand after checking.
- `config/config.php` is never touched, and nginx is never reloaded.

## 3. If the script cannot run — manual fallback, in this order

1. **Prove live == base**, per file, CR-stripped: `diff <($G show <base>:$f | tr -d '\r') <(tr -d '\r' < $L/$f)`. Any difference means someone else changed live — stop.
2. `php -l` every PHP file you are about to ship.
3. **Back up** the files you will overwrite, plus `mysqldump --single-transaction --routines --triggers shari | gzip > …`, into `/root/backups/`.
4. Ship with `tar` over ssh (never `scp` a whole tree over live — that is how the 4 Oct outage happened).
5. `chown www-data:www-data` every file you wrote.
6. `systemctl reload php8.3-fpm`.
7. **Verify**: `curl` the home page *and* the admin login for 200 — the script checks
   `https://www.shreehariglobal.in/` and `https://shreehariglobal.network/admin/login.php` (two different domains, both served
   from this webroot) — then grep the marker from §1 to confirm the new release is actually being served.

Rollback: every deploy leaves `/root/backups/pre-deploy-<release>-<timestamp>/` with the original files, a DB dump and a `ROLLBACK.txt` spelling out the steps.
