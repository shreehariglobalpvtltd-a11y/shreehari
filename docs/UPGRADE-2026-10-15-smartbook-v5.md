# SmartBook v5 — release notes (15 Oct 2026)

Owner's "Final Prompt v5": one screen, one tap, zero-mistake booking.

## What shipped

| Phase | What | Where |
|---|---|---|
| 2 | One cancellation policy (≥48 h 75 %, 24–48 h 50 %, <24 h 0, 5–7 days), 1,375 km everywhere, Rajkot in every list; reschedule = the Terms' 96-hour rule; journey time from the timetable | `Fare::refundSlabs/refundDays/refundPolicyLine/rescheduleLine`, `SiteFacts` policy.* / route.* |
| 3 | One booking engine: next departure, points per mode, smart defaults, AutoBerth (H1–H5 + S1–S7), server price, idempotent Cash / Online sale | `includes/smartbook.php`, `includes/autoberth.php`, `includes/defaultrules.php`, `api/smartbook.php` |
| 4 | SmartBook card leads the home page; Journey Summary rail (desktop) / sticky bar (phone); fits 360×640 | `assets/js/27-smartbook.js`, `assets/css/smartbook.css`, `app.template.html` |
| 5 | Cash = instant pay-at-boarding ticket on the home page (PDF auto-download, PNG, share, print, copy, track); Online = 15-min hold → QR page; PaymentAdapter; Cash book | `smartpay.php`, `includes/payadapter.php`, `admin/cash-book.php` |
| 6 | QuickBot line + Sahayak "Card ma herau" → the same card | `includes/smartbookbot.php`, `16-lazy.js` |
| 7 | Staff SmartBook (agent / counter / admin), 80 mm receipt, SmartBook rules page; no code entry on the customer site | `admin/smartbook.php`, `admin/ticket-80mm.php`, `admin/smart-rules.php`, `index.php` `<!--staff-->` |

## Migrations (apply by hand, in order)

1. `database/upgrade-2026-10-15-smartbook-facts.sql` — refund slabs (D8), refund days, route km
2. `database/upgrade-2026-10-15-rajkot-reschedule.sql` — Rajkot beside Ahmedabad, office phone, reschedule settings
3. `database/upgrade-2026-10-15-smartbook-engine.sql` — `smartbook_requests`, `route_stops.visible_to`, seat_rules, COD caps, online hold, default_rules
4. `database/upgrade-2026-10-15-smartbook-fingerprint.sql` — `smartbook_requests.fingerprint` (a key answers only the same request)

All additive; apply with `mysql --default-character-set=utf8mb4 shari < file`.

## Owner decisions taken as defaults

- Cash auto-cancel before boarding: setting exists, **off** (`cod_autocancel_minutes = 0`) — Cash is paid at boarding and the owner settles cancels by hand.
- Agent Cash: the existing ledger (agent collects, hands over) with commission; no prepaid wallet.
- Sharing tiers: one price; private Single / Double from the cabin price list.
- Reporting time on the card / 80 mm receipt: pickup − 60 min (`report_before_min`).
- Online payment: QR + UTR / screenshot, verified by the office. A gateway (ICICI under `/payment/`, or another) is switched on with `payment_gateway` — nothing auto-confirms.

## Tests

`tests/smartbook-facts-test.php`, `tests/smartbook-engine-test.php` (owner scenarios, 20 parallel holds = 1 win, double tap = same PNR, lost answer replayed), `tests/smartbook-ui-test.php`.

## Rollback

`deploy/deploy-prod.sh` keeps every release's touched files + a DB dump in `/root/backups/pre-deploy-<release>-<ts>/` with `ROLLBACK.txt`. The card fails safe: if `/api/smartbook.php` cannot boot, the classic search card shows again.
