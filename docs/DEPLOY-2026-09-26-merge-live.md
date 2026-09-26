# LIVE deploy — the counter / NPR / advance-offer branch, merged with the live lineage

Ran 26 Sep 2026, 18:22 IST, on the owner's "Yes" of the same afternoon.

## What is live now

| | |
|---|---|
| Commit | **`d309b31`** on `release/merge-live-20260926-20260926-182250` |
| Asset stamp / service worker | `v=20260926m` / `shg-v175` |
| Backup taken first | `/root/backups/pre-merge-live-20260926-20260926-182250` (122 MB: code, database, nginx, crontab) |
| Rollback | `bash deploy/go-live.sh --rollback` |
| Same commit elsewhere | bare `/root/shg-site.git`: `nepal-counter`, `merge-live-20260926`, `wip`; GitHub `release/merge-live-20260926` |

`deploy/go-live.sh merge-live-20260926` reported **nothing removed** from live and
23 commits added. Every page it checks answered 200; the five private upload
folders answered 404.

## How the two lineages were joined

Live had forked again on the afternoon of 26 Sep: a cloud session deployed
GitHub PR #5 + #6 (WhatsApp ticket codes, payment webhook, office alerts,
local-first AI client, new bus artwork) through its own live-sync path, so
`67ed313` (live) and `54520e7` (this branch) shared only `51744e3`.

- `5a63ba1` — `git merge 67ed313` in a throwaway worktree. Seven files
  conflicted, all on the asset stamp only; the whole tree was re-stamped
  `20260926m` / `shg-v175`.
- **The review found one lost hunk.** Those seven conflicts had been resolved
  with the branch's whole file (`git checkout --ours`), which also threw away
  the two *non-conflicting* live hunks in `assets/js/07-checkout.js`:
  `id="waGetBtn"` on the ticket anchor and the 34-line click handler that
  asks `api/wa-ticket-code.php` for a one-time code. Server, table, admin
  block and PHP suite had all survived, so the battery stayed green.
- `d309b31` — that one file re-merged three-way (`git merge-file`, base
  `51744e3`, ours `5a63ba1`, theirs `67ed313`); only the stamp line
  conflicted. A line-by-line sweep of every file live had touched (lines live
  added minus lines in the merge, stamps normalised) showed nothing else lost.
  The sw.js merge note now names all of live's v173 items.
- The lesson is written down in the memory note *merge-whole-file-ours-trap*
  and guarded by a new check in `tests/wa-chat-token-test.php` (section 11:
  the ticket page must still carry the button and the call).

## Test evidence (`/root/shg-test`, `shari_test`, refreshed from `wip` = `d309b31`)

| Check | Result |
|---|---|
| `php tests/run-all.php --http` (dev server `:8899` behind the nginx-like router) | **112 passed, 1 failed** — `home-entry-test.php` 54/3, the known baseline that is red on live's own commit too |
| `node tests/i18n-check.js`, `offline-ticket-test.js`, `lazy-retry-test.js`, `seat-label-parity.js` (Windows, node v24; the VPS has no node) | all exit 0 |
| `node --check assets/js/07-checkout.js sw.js` | clean |
| Adversarial merge review (21 agents: 14 files against both parents, refuters, whole-tree critic) | one blocker (above, fixed before deploy); everything else clean |

## Database changes applied by hand after the checkout

All idempotent (`CREATE TABLE IF NOT EXISTS`, guarded `ALTER`, `INSERT IGNORE`):

```
php tests/apply-sql.php database/upgrade-2026-09-26-payment-engine.sql
php tests/apply-sql.php database/upgrade-2026-09-26-wa-chat-tokens.sql
php tests/apply-sql.php database/upgrade-2026-09-26-desk-direction.sql
php tests/apply-sql.php database/upgrade-2026-09-26-login-otp.sql
php tests/apply-sql.php database/upgrade-2026-09-counter-desks.sql
php database/upgrade-2026-09-vip-advance.php
```

The last one **changed prices**, as the owner confirmed on 26 Sep: the board is
now the setting (`fare_rules`).

| Journey | Before | Now |
|---|---|---|
| Surat / Kamrej / Ankleshwar / Bharuch / Vadodara / Anand / Nadiad / Emli Bhupal → Rupaidiha | ₹2,000 | **₹2,200** |
| Ahmedabad (S Hari Parking, Nana Chiloda) → Rupaidiha | ₹2,000 | ₹2,000 |
| Rupaidiha → Ahmedabad | ₹1,800 | ₹1,800 |
| Rupaidiha → any other Gujarat town | ₹1,800 | **₹2,200** |

Tickets already sold keep their stored figures. Verified after the run:
`bookings.advance_discount` present, `counter_locations.default_direction`
present, `wa_chat_tokens` and `webhooks_received` present.

## Switches — nothing new is on

| Setting | Value | Meaning |
|---|---|---|
| `advance_offer_on` | 0 | the 24 h / 10 % festival offer stays off until the owner turns it on in Admin → Fares & offers |
| `wa_rules_control` | 0 | fare changes by WhatsApp stay off; `wa_rules_numbers` is blank — the owner's two office numbers still have to be confirmed digit by digit |
| `npr_per_inr` | 1.6 | unchanged |

## What the cloud live-sync will see

Its `expect_live` still names `67ed313`. Its next deploy request will fail its
own gate until it re-snapshots the live commit — that is the gate doing its
job, not a fault.

## Follow-ups

- `docs/NEXT-PROMPTS-2026-09-27.md` prompts 2–10 are still open.
- The owner's evening asks (motion and speed, WhatsApp bot verification and
  scheduled reports, one staff booking link, switch audit) start from this
  commit.
