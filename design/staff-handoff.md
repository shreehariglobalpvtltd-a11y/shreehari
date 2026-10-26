# Staff onboarding — designer handoff (Canva)

Shree Hari Global · prepared 22 Oct 2026 · for: one A4 handout, one WhatsApp
status image, one bus-side sticker. Everything you need is in this file — please
do not invent text, numbers or promises that are not written here.

---

## 0. Ground rules (read first)

**Realistic only — the owner's standing rule.** Use the real white S Hari Global
coach PHOTO and the real logo. Never a drawn, cartoon, 3D-rendered or
illustrated bus, and never illustrated people. "Make it look better" means
colour, layout and type — not illustration.

### Real assets to use (from the website repo)

| What | File | Size | Notes |
|---|---|---|---|
| Logo mark (emblem) | `assets/img/logo-mark.png` (also `.webp`) | 439 × 228, transparent | Small file. On A4 at 300 dpi keep it ≤ 37 mm wide; on the A3 sticker (150 dpi is fine for 2 m viewing) ≤ 74 mm. Ask the owner for the original vector / high-res logo before printing larger. |
| Logo with ™ wordmark | `assets/img/logo-tm.png` (also `.webp`) | 440 × 321, transparent | Same size limits as above. |
| Wordmark only | `assets/img/logo-wordmark.png` | 641 × 59, transparent | For a slim footer line. |
| Coach, side view, cut-out (faces right) | `assets/img/hd/bus-cutout.webp` | 1600 × 800, transparent | First choice for the sticker and the status image. |
| Coach, hero photo with background | `assets/img/hd/bus-hero.webp` | 1600 × 900 | For a full-bleed photo band. |
| Coach, 3/4 view, cut-out | `assets/img/bus-shg-sm.webp` | 600 × 315, transparent | Small — only for a thumbnail-size use (≤ 50 mm on print). |

Canva imports WebP. If it does not, open the file in a browser and save as PNG.

### Do not

- No cartoon / clip-art / AI-drawn buses, drivers or conductors.
- No stock photos of other companies' or foreign buses; no stock "smiling driver" photos.
- No invented phone numbers, addresses, salaries or staff names. Placeholders stay
  as `[ … — owner fills ]` until the owner gives the real value.
- No promises that are not in the staff agreement (no "guaranteed job",
  "high salary", "free accommodation", "insurance", "bonus").
- No new colours outside the palette below; no neon, no glow effects.
- Do not stretch or recolour the logo. Do not put the logo on a busy photo area.

### Website hero photo slot (not a Canva task — for the owner)

The staff application page has an empty hero slot for **`/img/hero-staff.jpg`**.
It is **not generated**. The owner uploads a **real photo of a smiling driver and
conductor in company uniform**, beside the real coach if possible:
**1600 × 900 px, WebP, ≤ 180 KB**, faces in the middle third (the page crops the
edges on phones). Same photo can be reused on the three artefacts below once it
exists — optional, never replaced by an illustration.

---

## 1. Brand palette and type

Taken from the website's own design tokens (`assets/css/ds.css`, light theme) and
the home-page agent band (`assets/css/liquid.css`, section 11). The owner's brief
mentioned orange **#F7941D** and navy **#0B2A4A** — **neither hex exists in the
site CSS**. The closest existing tokens are `--orange #F07800` and
`--navy #0C306C`; use those so print matches the website.

| Role | Token (file) | Hex |
|---|---|---|
| Navy — main dark background, headings on white | `--navy` (ds.css) | `#0C306C` |
| Royal blue — secondary blocks, links | `--royal` (ds.css) | `#0054A8` |
| Royal deep — gradient end | `--royal-strong` (ds.css) | `#003C90` |
| Agent-band gradient (optional, screen only) | liquid.css §11 | `#0A2A8F → #1F4FE0 → #2F6BFF` |
| Orange — call-to-action only | `--orange` (ds.css) | `#F07800` |
| Text ON orange | `--on-orange` (ds.css) | `#16233C` |
| Orange soft — chip backgrounds | `--orange-soft` (ds.css) | `#FFE4C7` |
| Gold — thin accent lines, "verified" seal | `--accent-gold` (ds.css) | `#C9A227` |
| Ink — body text on white | `--ink` (ds.css) | `#16233C` |
| Muted — small print | `--muted` (ds.css) | `#5C6B85` |
| Page — light background | `--page` (ds.css) | `#F4F6FB` |
| Success green — ✓ ticks | `--success` (ds.css) | `#11703F` |
| Danger red — "never" items | `--danger` (ds.css) | `#B42525` |

**Contrast (checked):** white on `#0C306C` ≈ 12:1 ✅ · `#16233C` on white ≈ 15:1 ✅ ·
`#16233C` on `#F07800` ≈ 6:1 ✅ · gold `#C9A227` on navy ≈ 5:1 ✅ (large text / lines
only) · **white on orange `#F07800` ≈ 2.9:1 ❌ — never put white text on orange;
use `#16233C` text on orange buttons.**

**Fonts:** Inter (Latin: English lines, numbers, URLs) + Noto Sans Devanagari
(Nepali). Both are free in Canva. Weights used: 400 Regular, 600 SemiBold,
800 ExtraBold. Nepali line-height 1.45 (matras need room); English 1.3.
Numbers in English digits on every artefact (phone numbers, URLs, ₹ amounts).

**QR codes (all three):** black `#000000` or navy `#0C306C` modules on pure white
`#FFFFFF`, error-correction level **M**, quiet zone **4 modules** of white on every
side (part of the white box, not cropped), never on a gradient or photo, never
recoloured orange. Test-scan every QR from the exported file with two phones
(Android camera + WhatsApp scanner) before printing. Print the target URL in
small text under each QR.

---

## 2. Artefact A — A4 onboarding handout (Nepali)

**Purpose:** given at the office / depot to anyone who wants to work with us;
explains the rules in one page and sends them to the online form.

**Format:** A4 portrait, 210 × 297 mm, **3 mm bleed** each side
(216 × 303 mm canvas), **10 mm safe margin** inside the trim. CMYK PDF for a
print shop, or RGB PDF for office laser printing. Must also read well when
photocopied in black & white — no meaning carried by colour alone (✓ / ✗ symbols
plus words).

### Layout, top to bottom

1. **Header band** (0–55 mm): navy `#0C306C` full-bleed. Logo `logo-tm.png` left,
   ≤ 37 mm wide. Right of it:
   - **हाम्रो टोलीमा सामेल हुनुहोस्** — Noto Sans Devanagari 800, 26 pt, white
   - *Join the S Hari Global team* — Inter 600, 13 pt, `#FFE4C7`
   - Thin gold `#C9A227` line, 1 pt, along the bottom edge of the band.
2. **Roles line** (≈ 8 mm): Noto 600, 12 pt, ink —
   **चालक · सह-चालक / कन्डक्टर · हेल्पर · अफिस · लेखा · मेकानिक · सफाइ · सुरक्षा**
   *Driver · Co-driver / Conductor · Helper · Office · Accounts · Mechanic · Cleaning · Security* — Inter 400, 9.5 pt, muted
3. **"काम गर्दा मान्नुपर्ने नियम" box** (≈ 135 mm): white card, 1 pt `#E4E9F1`
   border, 4 mm radius, heading
   **काम गर्दा मान्नुपर्ने मुख्य नियम** / *Main rules on duty* (Noto 800 15 pt / Inter 600 10 pt).
   Ten numbered lines, Noto 400 11 pt, each with its English line beneath in
   Inter 400 8.5 pt muted. Two columns on A4 is fine; keep the numbering.
   1. ड्युटी समयभन्दा ३० मिनेट अगाडि आइपुग्नुहोस्। नआए ₹५०० कटौती र चेतावनी।
      *Report 30 minutes before duty. No-show = ₹500 cut and a warning.*
   2. कम्पनीको सर्ट, देखिने गरी परिचयपत्र र बन्द जुत्ता — ड्युटीमा चप्पल होइन।
      *Company shirt, visible ID card, closed shoes — no slippers on duty.*
   3. ड्युटीमा रक्सी, सुर्ती, चुरोट वा लागूपदार्थ — शून्य। भेटिए तुरुन्तै निष्कासन।
      *Zero alcohol, tobacco or drugs on duty — immediate dismissal.*
   4. निजी नगद होइन — भाडा, पार्सल, कमिसन सबै कम्पनीको एपबाट मात्र।
      *No private cash — fares, parcels and commissions only through the company app.*
   5. चालक: बस चलेको बेला हातमा फोन होइन (स्पिकर मात्र)। कन्डक्टर: यात्रु चढाउँदा निजी कल होइन।
      *Driver: no handheld phone while moving (speaker only). Conductor: no personal calls during boarding.*
   6. पहिले नमस्ते। चिच्याउनु, जात / लिङ्ग / ठाउँ लिएर गाली — कहिल्यै होइन। वृद्ध र महिलालाई सामान बोक्न सघाउनुहोस्।
      *Namaste first. Never shout or use caste, gender or regional slurs. Help elderly passengers and women with luggage.*
   7. यात्राअघि एपमा सुरक्षा जाँच (ब्रेक, टायर, बत्ती, हर्न, अग्निनियन्त्रक, प्राथमिक उपचार)। लगातार ४ घण्टा चलाएपछि २० मिनेट आराम अनिवार्य।
      *Pre-trip safety check in the app (brakes, tyres, lights, horn, extinguisher, first aid). After 4 hours of driving, a 20-minute break is compulsory.*
   8. यात्रुको फोन नम्बर, बुकिङ वा कम्पनीको योजना कसैलाई नदिनुहोस्; कम्पनीबारे सामाजिक सञ्जालमा पोस्ट गर्नु अघि अफिसको अनुमति लिनुहोस्।
      *Never share passenger numbers, bookings or company plans; no social-media posts about the company without office approval.*
   9. बिदा ४८ घण्टा अगाडि माग्नुहोस् (बिरामी बाहेक)। बिना सूचना ३ पटक अनुपस्थित = तलब कटौती र समीक्षा।
      *Ask for leave 48 hours ahead (except illness). Three unplanned absences = pay cut and review.*
   10. तलब हरेक महिनाको ७ गते बैंक / UPI मा मात्र; पेस्की स्टाफ खातामा लेखिन्छ।
       *Salary on the 7th of every month, by bank / UPI only; advances are recorded in the staff ledger.*
   Under the list, one muted line, Noto 400 9 pt:
   **पूरा सम्झौता (१४ बुँदा) अनलाइन पढ्नुहोस्: www.shreehariglobal.in/agreements/staff-agreement.html**
   *Read the full 14-point agreement online at the address above.*
4. **"कसरी आवेदन दिने" — How to apply** (≈ 60 mm), left 60 % of the width:
   four steps in circles numbered १–४ (navy circle, white digit), Noto 600 11.5 pt
   + Inter 400 8.5 pt muted:
   1. **QR स्क्यान गर्नुहोस्** — *Scan the QR code*
   2. **फोनबाटै फारम भर्नुहोस् — फोटो र परिचयपत्र सहित** — *Fill the form on your phone, with your photo and ID*
   3. **WhatsApp मा आएको कोड हाल्नुहोस्** — *Enter the code sent on WhatsApp*
   4. **अफिसले हेरेर तपाईंलाई फोन गर्छ** — *The office checks it and calls you*
   Right 40 %: **QR code, 45 × 45 mm** (including quiet zone) in a white box with
   a 1 pt gold border, caption under it in Inter 400 8 pt:
   `www.shreehariglobal.in/agreements/staff.html`
   **QR target:** `https://www.shreehariglobal.in/agreements/staff.html?src=handout`
5. **Footer** (≈ 12 mm, inside safe area): `logo-wordmark.png` left (≤ 50 mm),
   right: **S HARI GLOBAL PRIVATE LIMITED** Inter 600 8 pt muted. No phone number
   unless the owner gives one: `[OFFICE PHONE — owner fills]`.

**Export:** PDF Print, CMYK if the shop asks, crop marks + bleed on, fonts
embedded. Also a PNG 2480 × 3508 (300 dpi) for WhatsApp sharing.

**Accessibility:** smallest Nepali text 9 pt, smallest English 8 pt; body text
ink `#16233C` on white; no text over the coach photo (if a photo band is used,
keep it between sections 2 and 3, 30 mm tall, text-free).

---

## 3. Artefact B — WhatsApp status image (1080 × 1920)

**Purpose:** office staff and agents post it as their WhatsApp status to recruit.

**Format:** 1080 × 1920 px, RGB, sRGB. **Safe zones:** keep the top **250 px**
and the bottom **340 px** free of key text, logo and QR (WhatsApp draws the name
bar on top and the reply bar at the bottom). Side margin 64 px.

### Layout, top to bottom

- **Background:** navy `#0C306C` → royal deep `#003C90` vertical gradient
  (top → bottom). Optional soft orange glow top-right `#F07800` at 20 % opacity,
  large radius — no hard shapes.
- **y 250–420:** logo `logo-tm.png`, centred, 300 px wide max (do not upscale
  past 440 px — it is the file's real size).
- **y 440–700:** headline, centred:
  - **काम गर्न आउनुहोस्** — Noto Sans Devanagari 800, 92 px, white
  - **— Shree Hari Global** — Inter 800, 54 px, `#FFE4C7`
  - *Come and work with us* — Inter 600, 34 px, `#B9C9E6`
- **y 720–800:** roles line, centred, Noto 600, 34 px, white, line-height 1.45:
  **चालक · सह-चालक / कन्डक्टर · हेल्पर · अफिस · मेकानिक · सफाइ · सुरक्षा**
- **y 820–1180:** the real coach `assets/img/hd/bus-cutout.webp`, 960 px wide,
  centred, soft shadow under the wheels (black 25 %, blur 40). No text over it.
- **y 1200–1370:** three benefit chips, stacked (each a rounded pill, 24 px
  radius, `#FFE4C7` fill, `#16233C` text, Noto 600 34 px, padding 18 × 32 px,
  12 px gap) — modest, and all are in the staff agreement:
  1. **समयमै तलब — बैंक / UPI मा** · *Salary on time, by bank / UPI*
  2. **वर्दी र परिचयपत्र कम्पनीले दिन्छ** · *Uniform and ID card from the company*
  3. **सम्मानजनक, सुरक्षित काम** · *Respectful, safe work*
  (English lines may be dropped on the status image if space is tight — keep Nepali.)
- **y 1390–1580:** CTA block, two columns:
  - Left (≈ 600 px): orange `#F07800` rounded button shape (28 px radius) with
    text in **`#16233C`** (never white): **फोनबाटै ५ मिनेटमा फारम भर्नुहोस्**
    (Noto 800, 38 px). Under it, white Inter 600 26 px:
    `www.shreehariglobal.in/agreements/staff.html`
  - Right: **QR 300 × 300 px** incl. quiet zone, white box, 16 px radius on the
    box (not on the QR). **QR target:**
    `https://www.shreehariglobal.in/agreements/staff.html?src=wa-status`
- **y 1580–1920:** safe zone — background only.

**Export:** PNG, 1080 × 1920, under 1 MB (JPG 90 % if larger). Check it on a
phone: post it to your own status first.

**Accessibility:** headline ≥ 80 px; nothing smaller than 26 px; chips use ink
on orange-soft (≈ 13:1).

---

## 4. Artefact C — bus-side sticker (A3 landscape)

**Purpose:** on the inside of the door / side window of every coach so
passengers know the crew is company-verified and how to complain.

**Format:** A3 landscape, 420 × 297 mm, **3 mm bleed** (426 × 303 mm canvas),
**12 mm safe margin**. Print on **outdoor vinyl with UV-resistant gloss (or
matt anti-glare) laminate**; 150 dpi effective is enough for 2 m reading
distance. **Minimum text sizes for reading from 2 m:** headline ≥ 25 mm cap
height (≈ 72 pt), main text ≥ 10 mm (≈ 30 pt), smallest line ≥ 6 mm (≈ 18 pt).

### Layout

- **Left band (0–150 mm wide):** navy `#0C306C`, full height. Logo
  `logo-tm.png` at the top, ≤ 74 mm wide. Below it the real coach
  `assets/img/hd/bus-cutout.webp`, ≈ 130 mm wide (≈ 1600 px / 150 dpi = 271 mm
  max, so no upscaling issue). Gold `#C9A227` 2 pt line on the band's right edge.
- **Right area (white `#FFFFFF`):**
  1. Headline, Noto Sans Devanagari 800, ≈ 72 pt, navy:
     **यस बसको चालक / सह-चालक: शाखा-प्रमाणित**
     English, Inter 600, ≈ 32 pt, ink:
     *This bus's driver & co-driver are company-verified*
     A small gold seal shape with a ✓ (simple circle, gold `#C9A227` stroke,
     green `#11703F` tick) beside the headline — flat, not 3D.
  2. **Crew panel — replaceable** (≈ 230 × 105 mm): two equal slots side by
     side, each with a white card, 1.5 pt `#D5DCE8` border, 6 mm radius:
     - photo box 35 × 45 mm (real passport-style photo, printed per bus)
     - **चालक · Driver** / **सह-चालक · Co-driver** label, Noto 600 22 pt
     - `[NAME — printed per bus]` Inter 800 26 pt ink
     - `Staff ID: [SHG-STF-#### — printed per bus]` Inter 600 20 pt muted
     Design this panel as a **separate layer / separate small sticker** (a
     230 × 105 mm insert) so the office can reprint only the crew panel when the
     crew changes, without reprinting the whole sticker.
  3. Complaint line, Noto 600 30 pt + Inter 600 22 pt:
     **गुनासो / Complaint: WhatsApp the owner — [OFFICE WHATSAPP — owner fills]**
     (leave this placeholder visibly marked; do not type any number we have not been given)
  4. **QR 70 × 70 mm** incl. quiet zone, bottom-right, white box; caption
     Inter 600 18 pt: *Read the staff rules* / **स्टाफ नियम पढ्नुहोस्**
     **QR target:** `https://www.shreehariglobal.in/agreements/staff-agreement.html?src=sticker`
  5. Footer line, Inter 600 18 pt muted: **S HARI GLOBAL PRIVATE LIMITED**

**Export:** PDF Print, CMYK, bleed + crop marks, fonts embedded, images at
≥ 150 dpi effective. Separate PDF for the crew-panel insert.

**Accessibility:** navy on white ≈ 12:1; the complaint line must never sit on
the photo; test-scan the QR through the window glass after fixing one sample.

---

## 5. Checklist before sending to print

- [ ] Every QR scans from the exported file and opens the right page (`?src=` tag kept).
- [ ] No placeholder left unfilled except the ones the owner still has to give
      (`[OFFICE WHATSAPP]`, `[OFFICE PHONE]`, crew names / IDs).
- [ ] Only the real coach photo and the real logo; nothing drawn.
- [ ] Only the palette in section 1; no white text on orange.
- [ ] Nepali spelling checked by someone at the office (conjuncts like क्ष, त्र, ज्ञ render correctly in Noto Sans Devanagari).
