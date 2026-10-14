# S Hari Nav pro — cockpit view, flight, device tier, GPS, TV, honest live status — 10 Oct 2026

Owner (10 Oct 2026): *"S Hari Nav should be a pro version like the new Google Maps features: 3D
inside-vehicle view when you pick car / bike / bus, also flight; low-latency GPS; use the device's
processor, RAM and storage so the map is quick and compact; smooth advanced map, professional like
Google Maps / Apple Maps; works on a TV and every device; ready for the live bus animation from a GPS
tracking system (coming soon) and CCTV live (coming soon)."*

Built on the existing navigator (15-nav.js, MapLibre GL, own tiles + Valhalla on the VPS). One new
bundle, no new dependency, no migration, no settings key, no payment / booking / refund code touched.
Package `vis-nav` of the 10 Oct visual / pro upgrade; the integrator bumps the `?v=` stamp and
sw.js VERSION once for all packages.

## Files
| File | Change |
|---|---|
| `assets/js/26-navpro.js` | NEW (~590 lines). Cockpit view, flight mode, device profile, GPS cadence / heading fusion, TV + keyboard, honest status rows, `window.SHG_NAV.onBusFix`. |
| `assets/js/15-nav.js` | Small typeof-guarded hooks, each marked `10 Oct 2026 (navpro)`: map options, motion-loop camera target, look-ahead, heading fusion, GPS cell, flight guard in `snRoute()`, offline-save quota, `SN.busFixSink`, `SN.stopCompass`, helper hand-off, `snProInit()` / `snProAttach()` / `snProCleanup()`. |
| `assets/js/13-admin-routes.js` | `snNavLoad()` chains `26-navpro.js` after `15-nav.js` (same `?v=`); a failed pro load is not fatal. |
| `app.template.html` | `#snCockpitLayer`, `#snCockpit` button, `#snLyCockpit`, the flight chip, `#snFlightCard`, the `#snGps` HUD cell, the two status rows (track card + Layers), the "This device" row. |
| `assets/css/views.css` | One block at the end of the nav section: cockpit overlay, flight card, `.sn-soon` badge, status rows, `.sn-tv` tier, reduced-motion rules. |
| `assets/js/04-i18n.js` | 42 new `sn*` keys in en / hi / ne. |
| `sw.js` | `26-navpro.js` precached next to `15-nav.js`. |
| `tests/nav-pro-test.php` | NEW — 100+ static pins (see below), registered in `tests/run-all.php`. |

## 1. Cockpit (inside the vehicle)
- 🚘 button in the right stack + "Cockpit view" switch in Layers (remembered per phone, `shg:navCockpit`).
  Default ON on a fast device with the full map, OFF in Fast mode. In Fast mode on a mid / fast device
  the toast says "untick Fast mode"; on a basic / weak device (data saver, reduce motion, low RAM) it
  says plainly that the cockpit is not available there. On a basic (low-tier) device the 🚘 button and
  the switch are hidden.
- While a route is followed with GPS the driving camera takes a per-vehicle preset through the same
  time constants as before (`SN_TAU_*`): car pitch 62 / zoom 17, motorcycle 66 / 17.5, bus 55 / 16 and
  a longer look-ahead, cycle and walk 45 / 18, flight 30 / 9. Faster = a little further out.
- `#snCockpitLayer` (pointer-events none, aria-hidden) draws the interior silhouette as inline SVG
  with a CSS perspective tilt — car dashboard + wheel, motorcycle bars + mirrors, bus windscreen with
  the two big mirrors, cycle bars, flight HUD frame — plus speed, heading and the next-turn arrow in
  the mode colour. Shown only while the camera is actually inside the vehicle (route follow or the
  flight arc); hidden on drag, in a hidden tab, in Fast mode. The flight arc is a planner view, so it
  shows no speed / heading gauges. The map's attribution and scale are lifted above the dashboard band.
  On a phone the HUD stays at its top position (only wide screens move it above the band).
- Sway only above 5 km/h, transform only, off under `prefers-reduced-motion` and
  `html[data-motion="reduced"]`, paused while the tab is hidden.
- The look-ahead is read live for the current mode, so the S Hari preset route (which sets bus
  without a chip tap) and a restored offline route use the right preset.

## 2. Flight mode
Sixth chip ✈️. There is no air routing: a 64-point great-circle arc is drawn as a dashed line, the card
says the straight-line distance ("as the crow flies") and a cruise time at 750 km/h **labelled
estimate**. It makes **no booking promise**: the owner has not decided on a flight product, so the
earlier "Flight booking — coming soon" line was removed in review. `snRoute()` returns before the
engine fetch in flight mode; `SN.routeGeo` stays null so turn-by-turn never starts. Any road route
drawn earlier is taken off the map.

## 3. Device-tiered engine
`snDeviceProfile()` (cached): cores, memory, network (effectiveType / saveData), the WebGL renderer
string (guarded), `window.SHG_TIER` if the perf package set it, `navigator.storage.estimate()`
(async) → tier low / mid / high. Applied where `maplibregl.Map` is built: tile cache 60 / 150 / 400,
pixel-ratio cap 1.5 / 2 / device, antialias high only, cross-fade 0 / 150 / 250 ms, terrain / 3D mid+.
A low tier forces Fast mode: the Fast-mode switch is greyed and ticked with the note "Basic device:
Fast mode only", and the ⚡ pill only says so (before the review fix, unticking it said "Full map on",
reloaded, and came back in Fast mode). "Save this map area for offline" is capped at 25 % of the free
quota and the toast says the MB before anything downloads. Layers shows "This device: cores · GB RAM ·
tier · MB site data on this device" (memory reads "8+" at the browser's cap of 8; the storage figure is
the whole site's data, not only saved maps). MapLibre's own keyboard handler is off (`keyboard:false`)
so the navigator's key handler is the only one.

## 4. Low-latency GPS
`watchPosition` already asks `enableHighAccuracy:true, maximumAge:0`. New: the HUD cell "GPS 1.0 s ·
±8 m" (the cadence is the MEASURED gap between two real fixes; until one exists the cell shows
accuracy only, gaps under 200 ms are ignored and the measurement restarts on every visit; orange
"GPS 6 s ago" when a fix is stale); below 2 m/s the
compass heading is low-passed and the last good heading is held, so the arrow never spins at a
standstill. The iOS sensor permission is still asked only from the 📍 / 🧭 tap. `cleanupNav()` now
removes the compass listeners (they used to stay on for the life of the page).

## 5. TV + keyboard
`html.shg-tv`, or a viewport ≥ 1800 px with no hover (or ≥ 1200 px with no pointer at all), gives
`#view-nav` the `sn-tv` class — a mouse desktop on a Full-HD monitor keeps the normal layout. 1.5× controls, 20 px type,
a strong gold focus ring, one-time keys hint. Keys while the navigator is active and no field has
focus: arrows pan, + / − zoom, R / L rotate, 3 = 3D, C = cockpit, Enter = Get directions (both fields
set), Esc = close Layers / exit focus / back. Removed in `cleanupNav()`.

## 6. Honest live status
Track card and Layers both carry: "Live bus — driver's phone sharing" (🟢 live · 12 s ago when the kv
feed is real; 🟢 "this phone is sharing" only on a signed-in staff phone whose GPS watch runs and whose
last publish the server accepted in the last 30 s; ⚪ "this browser only" when a non-staff phone taps
the driver toggle; ⚪ not live otherwise) and "Fleet GPS unit + CCTV live view" with a **Coming soon**
badge. No fake position, no fake video.

Future fleet feed: `window.SHG_NAV.onBusFix({lat, lng, bearing, spd, ts})` pushes into the SAME path
the driver feed uses (marker, glide, trail, ETA, stops, follow camera). Documented at the top of
26-navpro.js.

## Tests
`php tests/nav-pro-test.php` (new), `node tests/i18n-check.js`, `php tests/asset-version-test.php`,
`php tests/a11y-voice-test.php`, `php tests/site-facts-test.php`, `php tests/helpdesk-campaign-nav-test.php`,
`node --check` on both bundles.

## Owner still decides
- When the fleet GPS unit / CCTV supplier is chosen, its feed calls `SHG_NAV.onBusFix` — nothing else
  changes in the navigator; the "Coming soon" badge then comes off.
- Flight booking: the card makes no booking promise. If a flight product is ever approved, a
  "coming soon" line can be added back then.
