# AMOLED pop-out foundation

The existing light/dark interfaces use largely soft elevation. This opt-in
foundation adds a black canvas, readable raised faces, lit rims, stacked lower
edges, inset fields and buttons that visibly press into their frame. The preview
contains customer, admin, agent, counter and map/help views with sample data.

## Scope

- assets/css/amoled-depth.css: scoped tokens, four accents, component
  primitives and conservative adapters for classes inspected in the PHP app.
- assets/js/20-display.js: independent display preference and labelled-select
  binding. No network, booking, money, GPS, audio or database operation.
- demos/amoled-depth/index.html: standalone five-role design preview with 46
  selectable admin menu options and representative category layouts.
- demos/amoled-depth/foundation.html: direct component/preference preview.

The existing customer/admin loaders, backend and database are unchanged.
The five-role demo is a design model, not a production booking replacement.
It is self-contained and deliberately uses local sample state. Its optional
audio/haptic demonstration must not be pasted as another production engine.

## Baseline and audit

Audited source: 489af7d674ef0f07df55bf69008dba43d216812d.
Recorded deploy: claude/project-thread-rg55gt, workflow run 36787444277,
successful on 30 September 2026. The default branch differs:
codex/ai-company-manager-20260921.
No claim is made about the current VPS checkout or uncommitted server changes.

PR 16 (af0be233de56288dacd1472c84f2386183429325) had successful Application
checks on 1 October. PR 17 run 36885555287 failed the MariaDB booking battery
while PHP/Node checks passed. Reconcile current source, pending changes and
required checks before any release; do not deploy a default branch by assumption.

Inspected integration points: app.template.html/index.php, app.css/premium.css,
19-premium.js, 14-counter.js, admin/_guard.php, agent.php/map.php and asset/CI/
deployment definitions. This is targeted source inspection, not a complete
security audit or authenticated end-to-end audit.

The admin map uses MapLibre GL 4.7.1/OpenFreeMap with external Google Maps links.
The map/help demo displays route controls, not a basemap, real GPS or ETA.
The existing SHGFeel engine owns production sound/haptic preferences.
The deploy workflow is manual workflow_dispatch, default dry; push does not deploy.

## Integration contract

Load CSS after the existing styles. Load the preference script at the shared
shell's documented boot point, then expose labelled native selectors:

~~~html
<label for="display-choice">Display</label>
<select id="display-choice" data-shg-display-control>
  <option value="standard">Standard</option>
  <option value="amoled">AMOLED depth</option>
</select>
~~~

SHGDisplay.set('amoled') enables data-shg-display="amoled" on html;
SHGDisplay.set('standard') removes it. The preference key is shg:display.
The script does not alter data-theme, business state or existing theme storage.
When integrating, explicitly reconcile the customer/admin theme controls so
choosing light/standard has one clear meaning. Do not add competing toggles.

Add .shg-depth-panel to selected major surfaces and .shg-depth-action to
native controls; add .shg-primary only to the primary action. Review each
page's hardcoded surfaces and colour semantics. Adapters are not a guarantee
that every inline legacy rule is fixed. Disabled/held/booked/approval states
must retain their real backend meaning.

No large surface motion or animation loops are introduced by the foundation.
Only small transform transitions are used for direct presses; reduced motion
removes those while retaining static depth. Do not rotate table text, finance,
map canvases or form fields. Forced-colour/print fallback needs per-page QA.

## Next category work

Customer: search/modes, results, actual seat layout/holds, review/proof/ticket,
My Bookings, reschedule/refund/status, chrome/help and PWA.
Admin: Dashboard; Tickets/QuickBot/Shift/Offline/Seats/Manifest/Chalan/Scan;
Agents/Staff; Customers/Support; Fleet/Schedule/Trips/Crew; Routes; Payments/
Refunds/Offers/Handover/Promo; Reports/AI; Settings/Logs/Health/Documents; Map.
Agent: own data, salary/deposit/advance/commission/cash, statement/receipt,
payout request, KYC/offline states. Counter: parser/review, seat recheck,
offline queue/conflicts, receipt, shift/cash. Secondary/detail/export pages
need separate coverage rows.

## Validation and release

Local validation of the preview: 145 logic checks in a DOM simulation;
preference code: 50 checks including saved/invalid values, blocked storage,
two selectors, delayed binding and repeat loading. JavaScript syntax passed.
Ten declared foreground/surface pairs met 4.5:1; this does not certify every
legacy page. Real browser layout, physical AMOLED, delivered audio/haptic,
GPS, authenticated role flows and backend/export regression remain to test.
The local preview file could not be opened in the cloud browser because its
URL policy blocks file URLs. No browser/device screenshot claim is made.

The GitHub application checks should be examined on this exact PR revision.
Before production integration/release, complete the repository's PHP/Node and
MariaDB booking battery, category/role/device QA, cache and print/export checks.
Use tests/asset-version-test.php and consistently update sw.js ASSET_VER/VERSION
when adding live asset loading. Do not clear working offline records to fix
cache state. Verify the current VPS, backups and the exact release target.
Use the existing deployment workflow and rollback procedure; do not bypass
environment gates, disable tests or claim deployment from a push.

No fixed battery-saving percentage, burn-in prevention or display refresh-rate
guarantee is made. Canva/Claude/model-version credits have not been verified.

References:
- https://developer.android.com/develop/ui/views/theming/darktheme
- https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html
- https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/At-rules/@media/prefers-reduced-motion
