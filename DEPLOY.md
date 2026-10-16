# How code reaches shreehariglobal.in

This branch root **is** the webroot layout: `index.php` here is `/var/www/shreehariglobal.in/public_html/index.php` on the VPS. Read this before deploying anything.

## 1. What is live, and how to prove it

Live right now: **`release-20261016-tm2`** (service worker `shg-v227`).

The truth about branches lives in the bare repo **`/var/repos/shg.git` on the VPS**, not on GitHub — the `release-*` branches are not pushed to `origin`, so a local clone cannot tell you what is live.

Never trust an HTTP 200 alone: nginx answers 200 from a stale tree just as happily. Grep a marker:

```bash
curl -s https://www.shreehariglobal.in/sw.js | grep -m1 "var VERSION"
curl -s https://www.shreehariglobal.in/ | grep -oE '\?v=[0-9a-z]+' | sort -u
```

`sw.js`'s `VERSION` and the `?v=` asset stamp are both bumped by `deploy/bump-asset-ver.js` at release time, so they identify the release. To confirm exactly, md5 live against the branch **with CRs stripped** (a Windows copy writes CRLF, git stores LF — line endings are not drift):

```bash
# on the VPS
G='git --git-dir=/var/repos/shg.git'; L=/var/www/shreehariglobal.in/public_html
diff <($G show release-20261016-tm2:sw.js | tr -d '\r') <(tr -d '\r' < $L/sw.js)
```

## 2. The route

```bash
bash deploy-prod.sh <base-branch> <new-ref> <release-name> [--dry-run]
```

Run it **on the VPS, as root** (`root@93.127.167.249`, key `~/.ssh/shg_vps_ed25519`). The copy that is actually invoked is `/root/deploy-prod.sh`; `deploy/deploy-prod.sh` in this tree is the same script under version control. Its header block documents the arguments and the five steps — read it, this file does not repeat it.

- `<base-branch>` is the release that is live now (today: `release-20261016-tm2`).
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
