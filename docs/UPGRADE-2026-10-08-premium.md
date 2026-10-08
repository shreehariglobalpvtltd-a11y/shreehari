# Premium customer experience upgrade — 8 Oct 2026

Owner prompt: "ULTIMATE CUSTOMER EXPERIENCE & PREMIUM 3D/4D APP TRANSFORMATION" (18 phases).
Built on the existing PHP + vanilla-JS app — nothing replaced, no schema change, no new dependencies.

## Releases
| Release | What |
|---|---|
| release-20261008-pm1 | Hotfix: the walk-in placeholder 0000000000 is never proof of owning a ticket |
| release-20261008-pr1 | The premium upgrade below (assets ?v=20261008p, service worker shg-v208) |

## How it was built
1. Audit — 9 dimensions (design/themes, type/a11y, home/nav/content, booking/login, seat map, ticket,
   voice/smart, perf/security, admin) with a skeptic verify pass per finding.
2. Wave 1 — five parallel branches: SEC (identity + payments), TKT (ticket, trips, tracking, public pages),
   ADM (admin/agent/counter consistency), FA (seat map), FB (home, nav, shell, performance).
3. Wave 2 — FD (checkout, sign-in, My bookings, ticket screen, Trip Companion) and FC (dialog a11y,
   voice guide, theme, type floor).
4. Adversarial pre-deploy review of the merged diff (6 dimensions, each finding re-checked by a skeptic):
   13 findings, 8 confirmed, all but one fixed before deploy (see "Known issues").

## Identity rule (owner, 8 Oct 2026 — overrides the 4 Sep "name + mobile is the sign-in")
Booking without an OTP stays possible. Private access (booking history, keyless ticket download, cancel,
the AI's private tools, keyless tracking, push alerts for a whole number) needs ONE of:
- the keyed link (`k`) from the ticket message,
- a session that passed the WhatsApp code (`CustomerProfile::verifiedUserId()`),
- the PNR created in this browser session (`Auth::rememberOwnPnr`, `$_SESSION['shg_own_pnrs']`),
- staff (unchanged rules).
One check: `Auth::customerMayOpen($booking)`. The walk-in placeholder is never proof (`isPlaceholderPhone`).

## Customer-facing changes (highlights)
- Home: booking-first hero (official logo, "INDIA ⇄ NEPAL PREMIUM AC SLEEPER BUS", promise line, three
  raised keys BOOK / TRACK MY BUS / MY TICKET), search card right after, 8-icon facilities strip, remembered
  last search, offices/team/animation moved below. Bottom bar HOME | BOOK | TRACK | TICKETS | HELP.
- Seat map: hold countdown in the fixed bar, expiry that runs once, foreign holds dropped, women-only refused
  for "Man" on tap, patient/elderly front lower rows, emergency berths striped 🚨, held ⏳, screen-reader labels,
  gold-edged private cabins, 44px berths.
- Checkout: never a silent dead button (`#coWhy` reason line), labelled fields, draft per route+date, Nepal
  number question, proof upload retry + resend band, COD "pay at counter" step, refund chip from live slabs.
- Ticket screen: WhatsApp ✅ only when delivered, keyed PNG/PDF/offline copy, verify QR, Directions from
  official coordinates, correction request, delay / trip-cancelled banners (no refund promise).
- Tracking: track-only key, live only when really live, "last seen … not live", "bus has left" only after
  the passenger's own pickup; Trip Companion counts to the passenger's own pickup.
- Trip notices: cancelled trips reach passengers, delays re-notify, reminders timed from the passenger's
  pickup (retimed and extra buses included).
- Accessibility: sign-in sheet is a real dialog (inert behind, Tab loop, focus back), type tokens + floor,
  theme follows the phone until a choice is saved, #/nav fully localized, GPS only on tap.
- Voice guide: optional, OFF by default, one short line per screen in ne/hi/en, never names/phones/PNRs.
- Admin: amber caution / red danger buttons, AA trip-status pills, dark-mode text on tokens, phone chrome,
  KPI two-up, calendar on phones, "UTR / screenshot also on SHG-…" pills (display only, never blocks).

## Tests added
identity-gate, placeholder-phone, ticket-premium, pickup-retime, admin-consistency, seatmap-premium (php+js),
home-premium, a11y-voice, checkout-ticket-premium (php+js). Battery: no suite newer-failing than the
16-suite baseline (test-data drift, unchanged).

## Deploy / rollback
`bash /root/deploy-prod.sh release-20261008-pm1 <branch> release-20261008-pr1` (drift guard, file + DB backup
in /root/backups/pre-deploy-release-20261008-pr1-<ts>/ with ROLLBACK.txt, php -l, atomic write, php-fpm
reload, md5 + curl verify). Rollback: follow ROLLBACK.txt in that folder (restores the previous files).
No migration ran; no data was changed by the deploy.

## Known issues / owner decisions
- Push alerts for a device that is NOT OTP-verified follow one ticket at a time (a second ticket's alerts
  replace the first's). Fix needs a small link table — next release.
- Owner decisions listed in the release report (office numbers, report time, Terms 5% line, journey time,
  office hours, luggage, capacity caps, sound default, easy-access berth L3, 6 pickups without coordinates,
  real bus photo, DASHAIN10 deleted from admin at 11:37 on 8 Oct, AiSensy wallet for OTP delivery).
