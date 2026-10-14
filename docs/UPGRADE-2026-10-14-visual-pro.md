# Visual pro upgrade — release-20261014-vp1 (14 Oct 2026)

Owner (10 Oct 2026, voice-typed): professional default colours + a white-blue and a golden-orange
version from the logo; smooth 165 Hz-feel motion and scrolling, touch for Android / iPhone / TV;
S Hari Nav "pro" like the new Google Maps (inside-vehicle 3D view per mode incl. flight, low-latency
GPS, use the phone's CPU / RAM / storage); live bus GPS + CCTV "coming soon"; business portfolio
(import-export, truck & bulk, packaging, shop-to-Nepal) as coming soon; 3-4 demo tour packages
(solo / group); YouTube / Facebook + the shreehariglobal.in mailbox; agreements fed by live data;
replace the photos with the HD pack (SHG-HD-Visual-Pack.zip). 14 Oct: "bato dekhaunuhos click garda
route draw hudaina" + vehicle animation along the drawn route.

Base: release-20261010-wr1. Asset stamp 20261014v, service worker shg-v211.

## Packages (each built in its own clone, independently reviewed, fixed, verified)

| Package | Doc | What |
|---|---|---|
| themes | UPGRADE-2026-10-10-themes.md | Professional (new default), White & Blue, Golden & Orange - light / dark / AMOLED, contrast-tested |
| hd | UPGRADE-2026-10-10-hd.md | HD WebP art (assets/img/hd), sprite icons, #homeLive (live tracking + "Fleet GPS + CCTV coming soon"), place / tour images, new OG image; calm coach hero STAGED (hidden) |
| perf | (commit messages) | perf.css (touch-action, hover guards, 48 px targets, content-visibility, dvh, TV), html[data-tier] device tiers, passive / rAF-throttled listeners, hidden-tab tickers |
| nav | UPGRADE-2026-10-10-nav.md | 26-navpro.js: cockpit view per mode, flight mode (great-circle, no booking promise), device-tiered map cache / offline quota, GPS cadence + compass, TV / keyboard, honest live rows, window.SHG_NAV.onBusFix hook |
| biz | UPGRADE-2026-10-10-biz.md | /business (+ /hi, /ne): Bus + Tours LIVE, 4 coming-soon services with "notify me" (enquiries source = business); #homeBiz; tour audience chips (4 demo packages featured) |
| trust | (commit messages) | company_email_alt + social URL settings (empty = hidden), {{f:html:social}}, sameAs JSON-LD, agreements read live facts, statutory lines on tickets |

## 14 Oct nav fix (this release)
- The From / To place list opened under the phone keyboard, so nothing could be picked and Get
  directions stayed disabled. It now measures the visual viewport and opens above the field.
- Text typed but not picked is looked up in our own place index when Get directions is pressed.
- After a route is drawn (sheet collapses, Focus mode, full-screen map - unchanged) the chosen vehicle
  glides along the road at its mode speed (bus 60 km/h, time-lapsed). A preview, never a position.

## Live steps
1. `deploy-prod.sh release-20261010-wr1 vp-20261014 release-20261014-vp1`
2. Apply by hand, in order: database/upgrade-2026-10-10-themes.sql, -hd.sql, -biz.sql, -trust.sql.

## Tests
All suites touched by the packages pass on the merged tree. The full battery shows 14 failing suites;
every one fails identically on the base commit (test-DB state and sub-PHP spawns without the ini),
so none is caused by this release.

## Owner decisions open
- Show the staged calm coach hero (#top)? (hidden today - booking-first layout)
- Paste YouTube / Facebook / Instagram / TikTok URLs and the domain mailbox in Admin -> Settings -> Company.
- Registered office address: Settings vs agreements differ - confirm one.
- Confirm wording of the coming-soon services and the "Fleet GPS + CCTV" line.
