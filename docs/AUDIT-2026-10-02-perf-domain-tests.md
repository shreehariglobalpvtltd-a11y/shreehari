# Reader findings kept for session 2 (perf, domain, tests) — 1 Oct 2026

## Performance (R7) — server is NOT the bottleneck (TTFB live 80-140 ms, local 44-98 ms); bytes + parse are
- Wire per first visit ≈ 549 KB br over 21 requests: HTML 45.5 KB (193 KB raw) + JS 1.06 MB raw / CSS 509 KB raw unminified (222 KB JS comments, 92 KB CSS comments).
- PERF-1 high: esbuild bundle + minify + precompress (.br/.gz) — plan already in docs/UPGRADE-PLAN-2026-09-24.md:61-66, never built. Staff-only 14-counter.js, 11-pdf-ticket.js, 10-track.js and the client routes editor inside 13-admin-routes.js:17 load for every anonymous visitor.
- PERF-2 high: 04-i18n.js ships en+hi+ne (185 KB) to everyone → split per language, lazy-load others.
- PERF-3 high: home HTML carries hidden #homeMore brochure ≈55 KB, view-terms 26 KB, splash 12 KB, indentation 18 KB → lazy partials + strip indentation in index.php:295-298.
- PERF-4 medium: sw.js line 19 is a 42 KB release-notes comment (bump-asset-ver.js:38-39 prepends forever); counters HEAD it every 10 min. Move history to docs/SW-VERSIONS.md.
- PERF-5 medium: occupancy.php = 69 SQL statements for 7 days (booking_seats query twice per schedule: seats.php:737-741 + :1011-1015; routes row ×7). search 17, seats 14. Memo per request (bypass inside transactions).
- PERF-6 medium: SSE api/seat-events.php pins a php-fpm worker 25-60 s, 4 SELECTs/sec (seatversion.php:32-35). No php-fpm/opcache config in repo.
- PERF-7: live nginx drifted from repo conf (brotli on, duplicate Cache-Control+Expires); gzip_static on but no .gz files.
- PERF-9: every anonymous API sets SHGSESSID (bootstrap.php:182-184) despite Cache-Control public.
- PERF-10: admin/bookings.php 9 correlated subqueries/row; booking_legs has no index led by travel_date → `ALTER TABLE booking_legs ADD KEY ix_legs_travel (travel_date, leg_type, booking_id)`.
- PERF-11: logo.png 39.5 KB preloaded LCP → webp; card-*.jpg 220 KB each; icon-512 ~100 KB.
- PERF-12: views.css ~18 KB dead selectors from purged panels (.rolegate, .adm-, .ja-menu, .hist-).
- PERF-13: sw.js PRECACHE pulls 15-nav.js+16-lazy.js (335 KB) on first visit → warm later.
- /api/config.php has no caller in the SPA (SHG_BOOT duplicates it) — do not delete.

## Domain (R2) — one app, one DB, two hostnames; consolidation = nginx redirect + small code fixes
- Live .network: `/` 302 /admin/, but ALSO serves the full SPA, /bus, sitemaps, the real sw.js (shg-v163) and a manifest — staff who used counter mode there may have a .network service worker / PWA.
- Plan: replace live .network server blocks with a pure 301 → https://www.shreehariglobal.in$request_uri (bare / → /admin/), keep acme location, serve sw-reset.js at .network /sw.js (kill switch, same as apex). Snapshot `nginx -T` first. Repo deploy/nginx-shreehariglobal.in.conf has NO .network block and NO apex sw-reset block → drifted; restate it.
- Cookies are host-only (bootstrap.php:174 domain ''), redirects root-relative (response.php:233-241) → only cost is one re-login. No CORS anywhere.
- Code: 15-nav.js:821 toast tells staff to sign in at shreehariglobal.network → change to www/admin. Gate SW register (13-admin-routes.js:2615) + install sheet (17-pwa.js:112) to SHG_BOOT.appUrl origin (allow localhost). Literal domains bypassing APP_URL: legal.php:69, ticket.php:2212, aichart.php:223, aiagent.php:1098, calendar.php:62, 05-router.js:2760, 11-pdf-ticket.js:255/668. Client share links use location.origin (07-checkout.js:1586, 11-pdf-ticket.js:372, 17-pwa.js:356, 09-agent.js:8, 05-router.js:2756).
- Confirm Twilio/Gupshup console callback URLs are www before redirecting (Twilio doesn't follow 301).
- Modularity seams (no rewrite): booking.php create() 618 lines → extract-method steps; ticket.php → ticket-png.php/ticket-pdf.php; notify.php → transports split; aitools.php → traits per banner group; 16-lazy.js → maps/assistant/forms; 05-router.js → 05b-seatlocks.js; 07-checkout.js → status view file; app.template.html → per-view partials concatenated by index.php.

## Tests/CI (R1)
- 489af7d CI failure = 3 suites (sitemap routing w/o router, wa-faq Vadodara/Barauda lookup, ticket-bot sample count) — all fixed by PR #16 commits 17f307b..af0be23, ancestors of HEAD. Nothing to fix there.
- Done session 1 (commit 521800b): contact-layer /tmp jar, devshape env/path + SHG_REQUIRE_DEVSHAPE in CI, automation-test back in CORE (49/49).
- Remaining: boarding-stop-fix-test repair (pick non-women seat, use bootstrap DB, cleanup, add edit_passenger regen assertion); 3 suites hard-code DSN (e2e-booking:83, role-gates:61, boarding-stop-fix:78); gupshup-send-test + meta-cloud-api-test orphaned (not in any list); run-all --http should abort if server not up; docs/CI.md stale.
- Missing coverage to add: counter-flow (agent once, same bus/date), location-parity (route_stops vs JS lists vs AI), ticket-regen per change type, ui-assets (icons exist at declared sizes), canonical-host, schema-index/query budget.
