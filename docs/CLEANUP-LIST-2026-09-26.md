# Cleanup list — 26 September 2026

Owner ask: *"extra live nabhayeko, conflict bhayeko k k ho list dinu, malai
delete gardinu."*

**Nothing in this file has been deleted.** It is the list to approve first.
Deleting a branch deletes the only copy of work nobody has merged, so each row
says what would actually be lost.

---

## 1. What IS live now

| | |
|---|---|
| Branch on the server | `release/local-brain-20260926` |
| Commit | `cd55870` |
| Asset stamp / service worker | `v=20260926a` / `shg-v163` |
| Backup taken before the switch | `/root/backups/pre-local-brain-20260926-012433` (104 MB code + database + nginx + crontab) |
| Rollback | `bash deploy/go-live.sh --rollback` |

The branch that was live before, `claude/shreehari-global-upgrade-myl45u`
(`e0515e4`), is **still on GitHub and still in the backup**. Its one change
this lineage did not have — the uploads security fix — was carried across by
hand before the switch, so nothing of value is waiting in it.

---

## 2. Branches in the bare repo `/root/shg-site.git`

| Branch | What it is | Safe to delete? |
|---|---|---|
| `main` | the 25 Sep integration lineage — the base of everything current | **KEEP** |
| `deploy-local-brain` | what is live now | **KEEP** |
| `wip` | scratch branch the test copy is refreshed from; **also holds the other session's payment engine**, which is not finished and is not live | **KEEP for now** — see §4 |
| `deploy` | older deploy branch, superseded | delete once §1 has run a few days |
| `int-wip` | the 25 Sep integration scratch | delete once §1 has run a few days |
| `restore21` | from the 22 Sep rollback | **KEEP** until the owner is sure nothing from 21 Sep is missing |

## 3. Branches in this Windows checkout (local only — deleting costs nothing
as long as the work is also on the VPS or GitHub)

Already merged into `main`, so deleting them loses nothing:

- `integrate/all-2026-09-24`
- `phase-b-home-cards-20260923`, `phase-b-home-cards-20260924`
- `seatmap-two-floor-20260923`
- `claude/hari-global-whatsapp-ops-c8iiof`
- `claude/gifted-napier-859cdd`, `claude/quirky-yonath-f5b04c`
- `codex/ai-company-manager-20260921`, `codex/rollback-ai-manager-20260921`
- `codex/whatsapp-meta-recovery`, `codex/main-before-wa-realign`
- `rebase-try`, `backup-before-rebase-2026-09-21`

## 4. The one real conflict — the other session's payment engine

While this work was going on, a second Claude session was building a
ticket-link payment engine in the **same folder**. Because `git commit`
without a path commits whatever is staged, some of its files were swept into
commits here. That is why this release was rebuilt file-by-file instead of
merged.

Its work — **not live, not finished, not deleted**:

```
includes/ticketpaymentservice.php      737 lines
includes/ticketaccesstoken.php         262
includes/paymentcommandadapter.php     217
includes/paymentsession.php            148
admin/payment-timeline.php             (never committed)
database/upgrade-2026-09-26-ticket-payment-engine.sql
tests/ticket-payment-engine-test.php
t.php                                  (a new file at the web root)
.htaccess, deploy/nginx-…conf, cron/expire.php, tests/run-all.php   (edited)
```

It lives on `upgrade/ticket-link-payment-engine-20260926` and on `wip`.

**Do not delete it without asking that session's owner.** It is money-handling
code: if it is finished it should be reviewed and deployed properly; if it was
abandoned it can go. Either way it should not have shared a folder with this
work, and that is the thing actually worth fixing — **one session per folder.**

## 5. Files on the server that are not needed

| Path | What it is | Verdict |
|---|---|---|
| `/var/www/.../public_html/backup/` | 8.5 MB of **full database dumps** (23 Sep) sitting under the web root | **verified not exposed** — nginx line 78 denies `/backup/`, and a direct request for a dump returns 404. Still worth moving to `/root/backups/`: the protection is one config line away from being lost, and the folder carries every booking and phone number. |
| `/etc/nginx/sites-available/shreehariglobal.in.bak*` | four old nginx configs | keep the newest, delete the rest |
| `/opt/shg-brain/models/qwen3-1.7b-q4_k_m.gguf` | 1.1 GB — the small model that was tested and rejected (it thinks instead of answering) | delete unless a non-thinking small model is wanted later |
| `/root/backups/pre-*` older than the 22 Sep rollback | old snapshots | keep the last three, delete older ones — disk is 91 GB free, so there is no hurry |

---

## 6. Recommended order

1. Let the release run for a day. Nothing below is urgent.
2. Ask about the payment engine (§4) — that is the only decision with a real
   consequence.
3. Delete the local branches in §3 — no risk.
4. Move `public_html/backup/` off the web root (§5). It is protected today,
   but it is protected by one nginx line rather than by not being there.
5. Leave `restore21` and the backups alone.
