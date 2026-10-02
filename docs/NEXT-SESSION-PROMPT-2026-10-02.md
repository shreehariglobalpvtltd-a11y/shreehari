Continue the S Hari Global (shreehariglobal.in) work from 2 Oct 2026. Reply to me in my Nepali/Hindi mix, keep questions few, and verify + deploy live in the same pass.

## Where things stand (read first)
- Live = VPS bare repo `/var/repos/shg.git` branch `release-20261002-s10` (commit dac47ed) for everything this session shipped. It is NOT on GitHub — a GitHub workflow deploy would revert it. SSH: `ssh -i ~/.ssh/shg_vps_ed25519 root@93.127.167.249`, webroot `/var/www/shreehariglobal.in/public_html`, DB `shari`.
- ANOTHER session deployed "discount switches" at 16:28 on 2 Oct (fare.php, offers.php, settings.php, 02-config/03-accounts/06-results/07-checkout.js, asset stamp 20261003b, migration upgrade-2026-10-02-discount-switches.sql, backup /root/backups/pre-discount-switch-). Find its branch/commit and build on top of BOTH lines — never deploy a file without first md5-comparing live against the branch you build on.
- Record of today: `docs/UPGRADE-2026-10-02-agent-realtime.md` (rounds 1–7). Memory: project-agent-realtime-sync-2026-10-02.
- Worktrees are cut from the stale 86-file `master` — `git ls-files | wc -l` first; reset onto the newest `origin/release-2026…` branch.
- Local test: MariaDB :3307, own DB via `SHG_DB_NAME=<x>_test bash tests/ci-setup.sh`, `export PHPRC=<dir with php-dev.ini as php.ini>`, `SHG_TEST_BASE=http://localhost:<port> php tests/run-all.php --http`. role-gates / e2e-booking hard-code dbname=shari_test (run a sed'd copy). Never symlink live folders into a scratch dir on the VPS (2 Oct incident).

## Done today (do not redo)
Agent panel by journey date + LiveSync auto-refresh + seat detail card + Bus 1/2/3 tabs; bulk agent-assign guard (code 07 back to 16 जना); Agents register one line per agent; /agreements/ restored (Agent + Partner, ne/hi/en/gu, v2.2 from my 22222.docx, Shop No. 9, 12, 13) with editable points in Admin → Agents → 📜 Agreement points; Hindi agreement PDF uses 10% not "10 गुना"; office WhatsApp report back (07:00/13:00/20:00).

## Remaining work, in order
1. Check the office report really went out at 20:00 on 2 Oct (logs/office-report.log, messages log, delivery status). If the WhatsApp driver failed, tell me exactly why.
2. Advance / Dashain-Tihar offer: settings `advance_offer_on=1`, text says "save 10%" but `advance_offer_percent=5`. Coordinate with the discount-switch work; ask me which percent, then make the offer actually apply at checkout (customer app, counter, QuickBot) with tests.
3. Web bookings where the customer typed an agent code (e.g. SHG-2026-00474, code 06) get ₹0 commission while desk sales get ₹200/pax. Show me the rule in the code and ask: should online agent-code bookings earn commission?
4. 1 Oct had an empty extra schedule (id 2216, slot 2, 0 seats). Offer to cancel/hide such empty extra buses from the Bus 1/2/3 tabs and reports.
5. Agreement form: my docx marks "स्थायी ठेगाना *" (permanent address) as required — make it required on the form and in api/agreement-apply.php validation, all 4 languages.
6. Get today's VPS-only releases onto GitHub (no push credentials on this machine — prepare the branch/patch and tell me the one command or the PR to make).
7. Only if I say yes: the other 27 Sep features still only in `/root/shg-site.git` branch `release-agreements-v21b` (VIP/point-to-point fare board in admin/pricing.php, NPR counter desks, Google sign-in, payment webhook, WhatsApp ticket codes, AI usage page). All were unused on live — list what each would change before porting any.

Owner-only items to remind me about: Twilio SMS account inactive (401 "status 4"), Meta WhatsApp account health.
