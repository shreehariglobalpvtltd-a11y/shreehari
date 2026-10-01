# Shree Hari Global — image list, choices and Claude Code handoff

Prepared: 25 September 2026. Source: this local project, not a live-server audit.

**अहिलेको अवस्था (26 September 2026):** मालिकले **A + S1** छान्नुभएको छ। Codex built-in ImageGen बाट bus, logo, उही CEO portrait, card design concepts, social preview र app-icon master generate भएका छन्। Visiting cards को final candidate generated artwork र मूल card को exact wording/numbers प्रयोग गरेर deterministic रूपमा typeset गरिएको छ। Exact-size candidate files `output/image-refresh/candidate-pack/` मा छन् र overview `output/image-refresh/A-S1-preview.jpg` हो। Website का active images अझै replace भएका छैनन्। Existing `assets/generated/` content belongs to earlier work; do not overwrite it.

## 1. छान्ने विकल्प

| Option | के तयार गर्ने | Scope |
|---|---|---|
| A — Recommended | Bus photo, existing logo cleanup, same CEO photo enhancement, EN/NE visiting cards, social share image, logo-derived app icons | Main brand image pack; current website layout stays the same |
| B | Everything in A + animated side-view bus polish + ticket decoration | Also permits tightly scoped visual code changes; ticket data and behavior stay the same |
| C | List and prompts only | Generate later after selecting individual IDs |

Style choices: **S1** existing blue/white/gold with orange accents, clean premium finish (recommended); **S2** deep navy/gold luxury; **S3** bright white/blue travel style. These choices describe the artwork, not a website-wide theme change.

Example reply: `A + S1; logo ko design ra CEO ko face same rakha.` Or select IDs: `01, 03, 04, 05, 06`.

Optional extras: **08 devotional background**, **09 ticket decoration**, **10 code-drawn scenery/icons**. These are not automatically included in A. A completely new logo is also a separate choice, not implied by cleanup.

## 2. Local image inventory — all 20 files found

Dimensions below are source-file dimensions where verified. Paths are relative to the repository root.

| ID | Existing file(s) | Current size / form | Where used / proposed action |
|---|---|---|---|
| 01 | `assets/img/bus-shg.webp` | 1129 × 593; transparent coach image | Master/reference for the branded coach. Rework lighting, edges and finish while preserving coach and livery. Direct main-page reference to this full-size file was not found in the inspected consumers. |
| 01a | `assets/img/bus-shg-sm.webp` | 600 × 315 | Splash fleet: `app.template.html:312`; ticket bus: `assets/js/07-checkout.js:2055`. Derive from the selected master, not a different generated bus. HTML currently declares 600 × 312; do not silently crop or distort the replacement to hide the mismatch. |
| 01b | `assets/img/bus-shg-sm.png` | 600 × 315 | Existing PNG variant; no direct reference found in the inspected consumers. Keep in the inventory, update only if selected mapping includes it. |
| 02 | `assets/img/bus-side.svg` | 640 × 200; animated vector | Home journey bus, quick-ticket route decoration, tracking overview, journey animation. Polish the SVG directly; preserve wheel animation, direction, framing and reduced-motion behavior. |
| 03 | `assets/img/logo.png` | 440 × 321; transparent PNG | Splash, navbar, cloned footer/checkout logos, browser PDF and server ticket/report headers. Preserve globe, arrow, ship/cargo, food and bus motifs and the exact existing wording. Avoid an unrequested rebrand. |
| 04 | `assets/img/ceo.jpg` | 720 × 1001 | Splash, founder/CEO section and CEO panel. Enhance the supplied person; preserve face, age, skin tone, sunglasses, tilak, scarf, jewelry, tattoos, clothes and namaste pose. |
| 04a | `assets/img/ceo-sm.webp` | Existing small WebP derivative | Intro portrait: `app.template.html:355`. Derive from the same approved portrait, with a crop that also works in a circle. |
| 05 | `assets/img/card-en.jpg` | 1600 × 914 | English visiting card displayed, downloaded and shared from the contact area. Recompose with approved artwork; copy all existing wording/numbers exactly. |
| 05a | `assets/img/card-en-sm.jpg` | 720 × 411 | Responsive English card preview. Derive from the final English master. |
| 06 | `assets/img/card-ne.jpg` | 1600 × 914 | Nepali visiting card displayed, downloaded and shared. Preserve all Devanagari, contacts and company details exactly. |
| 06a | `assets/img/card-ne-sm.jpg` | 720 × 411 | Responsive Nepali card preview. Derive from the final Nepali master. |
| 07 | `assets/img/og-shg.png` | 1200 × 630 | Social link preview, configured by `index.php:128`. Match the chosen bus/logo artwork and preserve existing copy. |
| 03a | `assets/img/icon-192.png` | 192 × 192 | App manifest and install prompt. Derive consistently from approved brand artwork. |
| 03b | `assets/img/icon-512.png` | 512 × 512 | App manifest icon. Same family as 03a. |
| 03c | `assets/img/icon-maskable-512.png` | 512 × 512 | Maskable app icon. Keep the mark within a centered safe circle of radius 40% of the canvas; provide a full-bleed background. |
| 03d | `assets/img/apple-touch-icon.png` | 180 × 180 | iOS home-screen icon. Clear silhouette and suitable solid background. |
| 03e | `assets/img/favicon-32.png` | 32 × 32 | Browser favicon. Simplified recognizable brand symbol, not unreadable tiny company text. |
| 03f | `icon-shg.svg` | 512 × 512 vector | Referenced in HTML and manifest. Preserve a scalable vector version; do not place a PNG inside a fake SVG replacement. |
| 03g | `favicon.ico` | ICO | Root browser fallback asset. Rebuild from approved icon if the icon family is selected. |
| 08 | `assets/generated/ganesh-visarjan-background.png` | Existing raster artwork | Found in the local untracked generated folder. No direct reference found in inspected HTML/JS/CSS. Not proven active; settings may point to it. Keep unless separately selected. |

The image files do not establish who created them or whether they were AI-generated. This is an asset audit, not an attribution claim.

## 3. Graphics generated by code / external images

| ID / category | Location | Treatment |
|---|---|---|
| 09 — On-screen ticket | `assets/js/07-checkout.js`, ticket styles in `assets/css/views.css` and `assets/css/premium.css` | Existing bus/logo can improve through image replacement alone. Additional decoration must fit current bounds. Do not replace the whole ticket with an AI image. |
| 09a — Browser ticket JPG/PNG and PDF | `assets/js/11-pdf-ticket.js` | Canvas draws the fallback ticket; jsPDF draws PDF. Keep live text, booking fields, QR and fallback behavior. Logo is read from `#logoNav`. |
| 09b — Server/WhatsApp ticket PNG and PDF | `includes/ticket.php`, `includes/pdf.php`, `download-ticket.php` | Generated from booking data. Shared logo may update automatically; decorative changes beyond it need exact, selected drawing edits and output checks. Never fabricate a ticket/QR with image generation. |
| 10 — Journey scenery | `assets/js/18-journey.js`, `assets/css/views.css`, `assets/css/journey.css` | Mountains, hills, road, motion and bus are SVG/CSS. Keep code-native and lightweight. Optional vector polish, no animation rewrite. |
| 10a — UI symbols | `app.template.html:244–264` | 21 inline symbols: home, ticket, bus, bed, lock, users, map, pin, clock, phone, building, luggage, compass, headset, calendar, document, bell, list, radar, download, scroll. Leave unchanged unless selected; use vectors if editing. |
| Tracking/map illustrations | `assets/js/10-track.js`, `assets/js/15-nav.js`, `assets/js/16-lazy.js` | Route SVGs, map markers, tile services and elevation tiles are functional map visuals. Preserve routes, coordinates, attribution and providers. |
| Payment QR / receipts | `assets/js/07-checkout.js`, `pay-image.php`, `includes/qr.php` | Keep actual payment/verification content. `assets/js/02-config.js` references `esewa-qr.jpg`, but that file was not found in this checkout. Do not invent a replacement QR. |
| Operational image output | `includes/seatmappng.php`, `includes/challanpng.php`, `includes/chalanipng.php`, `includes/reportchart.php`, `includes/reportpdf.php` | Seats, manifests, challans and charts are data-driven output, outside this image refresh. |
| Uploaded/private images | Payment screenshots, passenger/KYC documents and configured remote photos | Runtime data is not fully visible from this source checkout. Excluded from creative replacement. |

### External destination photos

`assets/js/16-lazy.js:295–400` defines **35 Wikimedia photo URLs**, used in map/landmark popups. These are third-party photo references, not local generated images. URL availability and licenses were not checked in this local audit. Keep them by default. For a later photo refresh, verify the source and usage terms; do not substitute invented landmark photos as documentary images.

1. Somnath Temple
2. Dwarka Temple
3. Ambaji Temple
4. Akshardham Gandhinagar
5. Rann of Kutch
6. Mehrangarh Fort
7. Hawa Mahal Jaipur
8. Jaisalmer Fort
9. Udaipur City Palace
10. Karni Mata Temple
11. Ajmer Dargah Sharif
12. Chittorgarh Fort
13. Red Fort Delhi
14. Qutub Minar
15. Taj Mahal Agra
16. Mathura Krishna Janmabhoomi
17. Varanasi Ghats
18. Ayodhya Ram Mandir
19. Sarnath
20. Bodh Gaya Mahabodhi
21. Haridwar Har Ki Pauri
22. Rishikesh Laxman Jhula
23. Valley of Flowers
24. Pashupatinath Temple
25. Boudhanath Stupa
26. Swayambhunath (Monkey Temple)
27. Lumbini Buddha Birthplace
28. Phewa Lake Pokhara
29. Everest Base Camp
30. Annapurna Base Camp
31. Manakamana Temple
32. Muktinath Temple
33. Chitwan National Park
34. Bardiya National Park
35. Bageshwari Temple Nepalgunj

## 4. कुन काम image generation ले, कुन code ले?

This is a practical division for this project, not a measured claim that Codex beats Claude in every task.

| Work | Recommended method |
|---|---|
| Coach photography, edge cleanup, light, realistic materials | Codex built-in image generation/editing with the existing coach as reference |
| Same-person CEO enhancement | Reference-based image edit; check likeness before accepting |
| Consistent decorative artwork and alternative visual styles | Codex image generation with a shared brief |
| Existing logo identity, spelling and small icons | Preserve the original mark; conservative cleanup and deterministic/vector exports |
| Visiting-card contact details, CIN and Nepali text | Exact typesetting over approved artwork; verify against originals |
| Animated bus and small interface symbols | SVG/CSS edits, not raster image generation |
| Ticket QR, totals, dates, seat labels and passenger text | Existing code and real booking data only |
| Installing selected assets | Claude Code can apply the explicit replacement map and inspect the focused diff |

## 5. Ready prompts for the selected pack

Shared direction: one coherent travel brand; retain source brand palette and approved style S1/S2/S3; realistic details, clean edges, good readability at phone sizes. Generate each distinct artwork separately. Do not return a collage as the production asset. Make thumbnails/icons from the same approved master. Save staged outputs separately; do not overwrite active files during generation.

### 01 — Coach photo edit

Use case: precise-object-edit. Edit target: existing `assets/img/bus-shg.webp`. Supporting brand reference: `assets/img/logo.png`. Create a premium, realistic studio-quality refinement of this same white/navy/gold coach, retaining its current front-three-quarter direction, proportions, wheel arrangement, window/door geometry and livery. Improve lighting, reflections, edge quality and material detail. Preserve the exact visible company name. No invented route, plate, model, capacity, fleet claim or new slogan. Entire vehicle visible, no cropped mirrors or wheels, genuine transparent background with clean alpha and a restrained contact shadow. Preserve the original approximately 1.904:1 frame ratio for final export; pad rather than stretch if necessary. If small branding text is unreliable, keep/composite the original approved lettering in final production instead of accepting misspellings.

### 03 — Logo refinement

Use case: precise-object-edit. Edit target: `assets/img/logo.png`. Conservatively refine this existing identity, keeping all motifs, relative arrangement, palette and exact text `S HARI GLOBAL` and `PRIVATE LIMITED`. Improve edge clarity and cleanliness on a transparent background. Do not replace the identity with a different monogram or remove business motifs. Retain the original 440:321 aspect ratio for drop-in use. Compare at original size as well as enlarged size; keep the original if refinement changes spelling or identity. Derive favicon/app exports from the accepted mark using deterministic rendering rather than independently generating seven different icons.

### 04 — CEO portrait edit

Use case: identity-preserve. Edit target: `assets/img/ceo.jpg`. Enhance this exact person's supplied portrait for the company website. Preserve identity, age, facial structure, expression, sunglasses, red tilak, orange religious scarf and writing, white shirt, jewelry, tattoos, hand anatomy and namaste pose. Improve exposure, gentle sharpness and background cleanup; retain natural skin texture and existing white background. No new face, clothing replacement, invented eyes behind glasses or beauty-filter skin. Keep 720:1001 portrait framing and enough clearance for the existing circular crops. Export one accepted portrait and derive the small WebP from it.

### 05 / 06 — English and Nepali visiting cards

Use case: ads-marketing. Reference: the corresponding existing card, plus approved logo and coach. Produce a polished composition within the existing 1600:914 ratio, matching the chosen visual style. Keep every existing phone number, company name, director name, address, CIN, URL, service statement and language unchanged. Improve hierarchy, whitespace and contrast while retaining all information. Use the approved logo and the same bus. Decorative art may be generated; contact details and Nepali text must be typeset exactly and checked against the source. Do not accept generated misspellings or fabricated facts. Export final 1600×914 JPG and 720×411 preview for each language.

### 07 — Social preview

Use case: ads-marketing. References: `assets/img/og-shg.png` and approved coach/logo. Refine the existing 1200×630 social preview into a cohesive premium travel-brand composition. Preserve existing company, route, service and contact wording exactly. Strong brand recognition, bus clearly visible, readable hierarchy at small preview sizes. Use generated decoration only as needed and deterministic final text. Keep all content within comfortable margins. Export 1200×630 PNG.

### 03a — App icon master

Use case: logo-brand. Reference: the selected logo. Create a compact square app icon derived from the globe, orange travel arrow and simplified white/navy coach. Use a full-bleed deep-navy to royal-blue background with a subtle gold rim and keep the emblem inside the safe area. No wordmark, letters or tiny details. It must stay recognizable at 32 px. Derive 512, 192, 180, 32 and ICO variants deterministically from this one accepted master; inset the maskable variant inside its safe region.

### 08 — Optional devotional background

Use case: illustration-story. Reference: inspect the existing Ganesh artwork first if selected. Produce a respectful, subtle devotional background matching the chosen site palette, with a quiet central region for existing page text. No logos, new slogans or embedded text. Preserve the selected subject and purpose. This does not authorize enabling or changing the admin setting that controls the background.

### 09 — Optional ticket decoration

Use case: ads-marketing. Asset type: decorative ticket background only. First inspect the current ticket renderer and establish exact decorative bounds. Create a restrained navy/blue, white and gold travel motif using the approved bus/brand where appropriate. Keep all booking-text and QR regions plain and high-contrast. No generated QR, barcode, passenger, PNR, date, seat, price, payment status or signature. Do not make a complete ticket image. Export artwork only at the measured bounds, and let the existing renderer draw all real content unchanged.

### 02 / 10 — Vector polish instruction

Edit the existing SVG/CSS source directly. Keep each viewBox, intrinsic dimensions, hooks, classes, wheel direction, animation timing and reduced-motion support. Improve detail and alignment within the same bounds and brand palette. Do not replace the SVG with a raster image, add libraries, or redesign interaction/layout.

## 6. Exact handoff contract for Claude Code

**Current replacement status: A + S1 CANDIDATE PACK GENERATED; ACTIVE WEBSITE UNCHANGED.** The candidate files are in `output/image-refresh/candidate-pack/`, and `output/image-refresh/replacement-map.json` records the source, destination and review state. `approved: false` means the file must not be installed yet. The AI card concepts remain in `output/image-refresh/candidates/` for reference; installable card candidates are the separately typeset files in `candidate-pack/`.

Each mapping entry must identify: asset ID; actual staged source path; exact destination path; output format; width; height; approved status. Only entries with approved status and an existing, inspected source file may be installed. Verify actual encoding, alpha and dimensions; renaming an extension is not conversion.

1. Inspect repository instructions and the current diff. Preserve unrelated and untracked work. Do not read/copy credentials or use production data to make artwork.
2. Read the selected scope and replacement map. If missing, report that the pack is pending; do not invent files or treat the inventory as permission to replace everything.
3. Keep backups of only replaced assets in a non-public location. For existing asset slots, prefer same-path replacements with the original format and aspect ratio. Keep transparent logos/coaches transparent.
4. Allowed active-file changes: approved image destinations; selected vector artwork; only the minimal image-reference, intrinsic-dimension, image-fit and cache-version changes needed to use those assets. Do not change wording, layout, global theme, routes, booking flow, payment, database, auth, APIs, tracking, seat logic, translations, dependencies or unrelated files.
5. Ticket scope: replace its approved decorative assets/logo only. Do not flatten the ticket to an image, change its data, reposition QR/data regions or alter ticket-generation behavior. If an optional new decoration cannot be integrated within the agreed scope, leave that addition pending and complete the other mappings.
6. Preserve existing responsive `srcset`, lazy loading, fetch priorities and download/share behavior. Keep output file sizes close to existing delivery sizes where quality permits; avoid serving huge generation masters to phones.
7. Inspect cache/version handling in `deploy/bump-asset-ver.js` and `sw.js`. Apply only the minimal established cache/version adjustment for changed assets; no service-worker behavior rewrite.
8. Check desktop/mobile views, light/dark backgrounds, splash, navbar/footer, CEO circles, contact cards, ticket preview and share metadata. Check ticket PNG/PDF/offline fallback if affected using test fixtures, not real bookings. Scan a rendered test QR when its surrounding rendering changed. Run relevant existing checks only; report anything unavailable.
9. Show an exact changed-file summary and any remaining items. No unrequested deployment, commit, broad cleanup, refactor or feature work.

### Existing overrides to account for (read-only findings)

- `includes/ticket.php:549` and `includes/reportpdf.php:137` prefer configured `company_logo` over the default logo. `admin/manifest.php` and `admin/agent-receipt.php` have similar fallback logic. Do not change settings to force a replacement; report a conflicting override.
- `assets/js/13-admin-routes.js:1937` reads localStorage key `shg:ceoPhoto`, which can override `#ceoHeroImg` and `#ceoPhoto`. Do not clear browser storage silently. Check both default and override cases.
- `assets/js/02-config.js:1075` applies `bhagwanImg` to the background and also assigns it to `og:image`. Therefore a new static OG image alone may not control every runtime preview. Report the existing behavior; fixing it is separate scope.
- The Ganesh image has no direct source reference found; an admin-stored URL could still activate it. Do not assume it is unused on the live website.
- Source files declare 600×312 for the small bus, but the current image is 600×315. Preserve actual image proportions; any correction must remain a minimal image-dimension change.

## 7. Short copy-paste prompt for Claude Code

```text
Read docs/IMAGE-REFRESH-HANDOFF.md and output/image-refresh/replacement-map.json. Use only entries whose approved field is true. Copy their supplied candidate files to the exact mapped destinations. Preserve the existing layout, content and all booking/payment/QR/seat/tracking behavior. Preserve CEO and logo identity, transparency, aspect ratios, responsive variants and the typeset card wording. Make only necessary image-reference, intrinsic-dimension and cache-version edits. Preserve unrelated work, inspect mobile/desktop and affected ticket output, and report exact changed files. Do not generate substitute assets, redesign, refactor, deploy or alter any approved flag.
```
