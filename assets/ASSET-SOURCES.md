# Asset sources

Where every outside asset in this product came from, and under which licence.
Started 26 Nov 2026 (fx1 brand pass). Facts in the font tables were read from
each file's own `name` table and from the licence files beside it, not from
memory.

**The rule:** nothing from outside (font, photo, illustration, icon, sound,
animation, video, 3D model) goes into the repo without a row here: what it is,
where it came from, the licence, the date, who added it. If the source or the
licence is not known, the row says "unknown" and the asset is not used on a
customer page until it is.

Brand rules that go with it: realistic only (the official white coach, real
photos), no cartoon buses, no invented or redrawn logos, no new brand colours
(colours come from `assets/css/fx1-tokens.css`).

## Fonts

### `assets/fonts/` (the product's own type)

| File | Bytes | Font, version | Licence | Source | Derived from / used by |
|---|---:|---|---|---|---|
| `NotoSansDevanagari.ttf` | 647,144 | Noto Sans Devanagari 2.006, **variable** (wght 100-900, wdth 62.5-100), 1,117 glyphs | SIL OFL 1.1. Copyright 2022 The Noto Project Authors (https://github.com/notofonts/devanagari). No Reserved Font Name. | notofonts/devanagari (the Google Fonts build) | Original. Read by the PDF / PNG renderers (`includes/chalanipdf.php`, `agentstatement.php`, `aichart.php`, `assets/js/11-pdf-ticket.js`, AgentPro cards). Do not modify. |
| `NotoSansDevanagari-gid.ttf` | 645,832 | same font, cmap extended so every glyph has a code point | SIL OFL 1.1 (same copyright) | built by `deploy/build-gid-font.js` from `NotoSansDevanagari.ttf` | Modified version. Used by the server-side shaper for ticket images. Re-run the script if the source TTF ever changes. |
| `OFL-NotoSansDevanagari.txt` | 4,385 | licence text | - | added 26 Nov 2026 (fx1): the SIL OFL 1.1 text, body byte-identical to `OFL-Poppins.txt`, with the copyright line taken from the font's own name table | It was missing until now although the font has been in the repo since at least 26 Oct 2026. |
| `Poppins-Regular.ttf` | 160,316 | Poppins 4.004, weight 400 (static) | SIL OFL 1.1. Copyright 2020 The Poppins Project Authors (https://github.com/itfoundry/Poppins). No Reserved Font Name. | itfoundry/Poppins (the Google Fonts build) | Original. Ticket PNG, AgentPro cards and forms. |
| `Poppins-Medium.ttf` | 158,576 | Poppins 4.004, weight 500 | same | same | Original. |
| `Poppins-SemiBold.ttf` | 157,312 | Poppins 4.004, weight 600 | same | same | Original. |
| `Poppins-Bold.ttf` | 155,996 | Poppins 4.004, weight 700 | same | same | Original. |
| `OFL-Poppins.txt` | 4,385 | licence text | - | shipped with the font | - |
| `cinzel-latin.woff2` | 25,888 | Cinzel 2.000, variable (wght 400-900), Latin subset | SIL OFL 1.1. Copyright 2020 The Cinzel Project Authors (https://github.com/NDISCOVER/Cinzel). | Google Fonts "latin" subset file | The wordmark face; `@font-face` is inline in `app.template.html`. **No licence text file beside it: add `OFL-Cinzel.txt`.** |
| `cinzel-latin-ext.woff2` | 14,536 | Cinzel 2.000, variable, Latin Extended subset | same | Google Fonts "latin-ext" subset file | same |
| `fx1-deva-400.woff2` | 48,484 | Noto Sans Devanagari 2.006, static instance wght 400, wdth 100; 794 glyphs | SIL OFL 1.1 (copyright and licence strings kept in the file) | **derived 26 Nov 2026 (fx1) from `NotoSansDevanagari.ttf`** | Web font "FX Deva" / "FX Num" in `assets/css/fx1-fonts.css`. |
| `fx1-deva-500.woff2` | 51,800 | same, wght 500 | same | same | same |
| `fx1-deva-600.woff2` | 52,048 | same, wght 600 | same | same | same |
| `fx1-deva-700.woff2` | 52,060 | same, wght 700 | same | same | same |
| `fx1-latin-400.woff2` | 8,848 | Poppins 4.004 Regular, Latin subset; 247 glyphs | SIL OFL 1.1 (strings kept) | **derived 26 Nov 2026 (fx1) from `Poppins-Regular.ttf`** | Web font "FX Latin" in `assets/css/fx1-fonts.css`. |
| `fx1-latin-500.woff2` | 8,664 | Poppins Medium, Latin subset | same | from `Poppins-Medium.ttf` | same |
| `fx1-latin-600.woff2` | 9,004 | Poppins SemiBold, Latin subset | same | from `Poppins-SemiBold.ttf` | same |
| `fx1-latin-700.woff2` | 8,756 | Poppins Bold, Latin subset | same | from `Poppins-Bold.ttf` | same |

The eight `fx1-*.woff2` files are 239,664 bytes together. They are "Modified
Versions" under the OFL: allowed, stay under the OFL, cannot be sold on their
own, and neither font declares a Reserved Font Name, so keeping the original
family names inside the files is allowed. The CSS family names are our own
("FX Deva", "FX Latin", "FX Num").

#### How the fx1 files were made (repeatable)

Tool: Python `fontTools` 4.69.1 + `brotli` (PyPI), on the developer machine. No
online service was used.

- Devanagari: for each weight W in 400, 500, 600, 700:
  `fontTools.varLib.instancer.instantiateVariableFont(font, {"wght": W, "wdth": 100})`,
  set `OS/2.usWeightClass = W`, then `fontTools.subset` with
  - unicodes `U+0900-097F, U+1CD0-1CFF, U+200C-200D, U+20B9, U+25CC, U+A8E0-A8FF`
    plus `U+0020-0040, U+005B-0060, U+007B-007E, U+00A0, U+00B7, U+2013-2014, U+2018-2019, U+201C-201D, U+2022, U+2026`
    (space, digits, basic punctuation: these are what "FX Num" uses),
  - `layout_features = ["*"]`, `name_IDs = [0,1,2,3,4,5,6,13,14]`, `notdef_outline = True`,
    `glyph_names = False`, flavor `woff2`.
- Latin: each Poppins TTF through `fontTools.subset` with unicodes
  `U+0020-007E, U+00A0-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+2013-2014, U+2018-201A, U+201C-201E, U+2020-2022, U+2026, U+2030, U+2039-203A, U+2044, U+20AC, U+20B9, U+2122, U+2190-2193, U+2212, U+2215`,
  same options, `DSIG` dropped. Poppins has no glyph for U+02BB or the arrows
  U+2190-2193, so those are not in the files (arrows fall back to the system font).

What was checked after subsetting (structurally, with fontTools; no shaping
engine and no browser was run):

- Every Devanagari shaping feature of the source is still in the subset:
  `abvs akhn blwf blws cjct half nukt pres psts rkrf rphf` (GSUB) and
  `abvm blwm dist kern mark` (GPOS). 419 of 427 ligature rules remain; the 8
  that went need glyphs outside the kept ranges.
- For 18 test clusters (क्ष त्र ज्ञ श्र द्ध ट्र प्र, reph, half forms, ङ्ग, द्य, ह्म, रू, रु ...)
  the subset holds exactly the same substitution rules as the source.
- Three features were dropped by the subsetter and that is correct: `ccmp`
  (only composes Latin A/E/I/U with combining accents), `ordn` (empty of
  Devanagari), `mkmk` (its two lookups only stack Devanagari marks on Latin
  combining diacritics U+0300-030C / U+0326-0328, used for transliteration;
  Devanagari mark-on-mark positioning lives in `abvm` / `blwm`, which are kept).
- Digits: all ten ASCII digits are 551 units wide at 400 and 567 at 700 in the
  Noto subset (one width = real tabular numbers). Poppins digits run from 320
  to 635 units and the font has no `tnum` feature, which is why "FX Num" exists.

Eyes-on check still to do by a person: open `_audit/brand/font-test.html` (not
committed) in Chrome and on an Android phone, in light and dark.

### `assets/agentpro/fonts/` (AgentPro cards and forms only)

| File | Bytes | Font, version | Licence | Licence file |
|---|---:|---|---|---|
| `Outfit-Regular.ttf`, `Outfit-Bold.ttf` | 54,912 / 55,392 | Outfit 1.100 | SIL OFL 1.1. Copyright 2021 The Outfit Project Authors | `Outfit-OFL.txt` present |
| `GeistMono-Regular.ttf`, `GeistMono-Bold.ttf` | 78,232 / 78,304 | Geist Mono 1.401 | SIL OFL 1.1. Copyright 2024 The Geist Project Authors | `GeistMono-OFL.txt` present |
| `ArsenalSC-Regular.ttf` | 165,848 | Arsenal SC 2.001 | SIL OFL 1.1. Copyright 2012 The Arsenal Project Authors | `ArsenalSC-OFL.txt` present |
| `Italiana-Regular.ttf` | 27,184 | Italiana 1.001 | SIL OFL 1.1. Copyright (c) 2011 Santiago Orozco, **Reserved Font Name "Italiana"** | `Italiana-OFL.txt` present |
| `Lora-BoldItalic.ttf` | 140,332 | Lora 3.008 | SIL OFL 1.1. Copyright 2011 The Lora Project Authors, **Reserved Font Name "Lora"** | `Lora-OFL.txt` present |

Italiana and Lora have reserved names: if either is ever subset or converted,
the result must not be called "Italiana" / "Lora" inside the font file.

### `assets/vendor/pdfjs/standard_fonts/` (part of pdf.js)

| File | Bytes | Font, version | Licence | Note |
|---|---:|---|---|---|
| `LiberationSans-Regular.ttf`, `-Bold.ttf`, `-Italic.ttf`, `-BoldItalic.ttf` | 139,512 / 137,052 / 162,036 / 135,124 | Liberation Sans 1.07.4. Copyright (c) 2007 Red Hat, Inc. | "Liberation Fonts license" per the file's own name table (for the 1.x line that is GPL v2 with a font-embedding exception, not the OFL) | Came with pdf.js. **No licence file is shipped beside them: add the `LICENSE_LIBERATION` file from the pdf.js distribution.** |

### Open points on fonts (for the lead / owner)

1. Add `assets/fonts/OFL-Cinzel.txt` (same OFL 1.1 body, first line
   "Copyright 2020 The Cinzel Project Authors (https://github.com/NDISCOVER/Cinzel)").
2. Add the Liberation licence file beside the pdf.js fonts.
3. `NotoSansDevanagari.ttf` and its `-gid` twin are 1.29 MB together and are
   fetched by the browser in two places today (`assets/agentpro/ap.css:12`,
   `includes/agentpro-form.php:882`, and lazily by `assets/js/11-pdf-ticket.js`).
   The two CSS uses could point at `fx1-deva-400.woff2` (48 KB) instead; the
   jsPDF use needs the full TTF.

## Images / sounds / animations

Every future outside asset gets a row: **file, what it shows, source (URL or
"shot by ..."), licence, date added, who added it.** A generated image (AI or
otherwise) says so in the source column, names the tool, and is never a logo
or a coach that does not exist.

What is known today (26 Nov 2026). "Unknown" rows are the fix list. The "Added" column is the first commit that holds the file; this git lineage starts on 26 Oct 2026, so "on or before 26 Oct 2026" means the real date is older and not recorded.

| Files | What | Source | Licence | Added | By |
|---|---|---|---|---|---|
| `assets/img/logo*.png`, `logo*.webp`, `icon-*.png`, `mgmt-*.png`, `favicon-32.png`, `apple-touch-icon.png`, `badge-96.png`, `mask-icon.svg`, `/icon-shg.svg` | The S Hari Global emblem, wordmark and app icons | The company's own logo; the TM / white / nav sizes are cut from it by `tools/make-logo-tm.php` and `tools/make-mark-tm.php` | Company property (trade mark) | on or before 26 Oct 2026 | owner + dev sessions |
| `assets/img/flag-in.svg`, `flag-np.svg` (and the same shapes in `includes/brand.php`, `assets/js/23-flags.js`) | Flags of India and Nepal, drawn to the official geometry | Drawn in code for this product | National flags: no copyright; drawn by us | on or before 26 Oct 2026 | dev session |
| `assets/img/places/bageshwari.jpg`, `pashupatinath.jpg`, `sabarmati.jpg` | Three places on the route (ticket art, NAV) | Wikimedia Commons; the ticket prints the credit "Photos: R. Dhungana, N. K. Sapkota, Snehrashmi" (`includes/ticket.php:1390`) | CC BY-SA 4.0 (attribution + share-alike). **The credit line must stay wherever the photos are shown. Which photographer made which photo, and the three source URLs, are not recorded: add them.** | 19 Nov 2026 | dev session |
| `assets/img/hd/*.webp` (50 files: bus-hero, bus-cutout, nepal-heritage, lumbini, bardiya, pokhara, gujarat-city, gautam-buddha, nepalgunj-temple, family-travel, sharing-sleeper, private-sleeper, quick-ticket, support-guide, track-journey, logo-hd-proposal) | "HD pack" artwork; the code treats `/assets/img/hd/` as illustration and labels it so (`includes/contentpages.php:401-411`) | **Unknown: not recorded in the repo.** Described in code as renders / illustrations from the owner's pack | **Unknown** | on or before 26 Oct 2026 | owner's pack |
| `assets/img/anim/*` (12 files) | Home animation: highway, scenery, coach, map | "the owner's premium animation pack" (`assets/js/20-anim.js:4`) | **Unknown** | on or before 26 Oct 2026 | owner's pack |
| `assets/img/card-en.jpg`, `card-ne.jpg` (+ `-sm`) | Company visiting card, English and Nepali | Company's own card | Company property | on or before 26 Oct 2026 | owner |
| `assets/img/ceo.jpg` | Photo of the director | Supplied by the owner; replaceable in Admin > Settings | Company / personal; used with consent | on or before 26 Oct 2026 | owner |
| `assets/img/og-shg.png` | 1200x630 link-preview card | Built by `tools/make-og.php` from the logo and `hd/bus-hero.webp` | Follows its inputs | on or before 26 Oct 2026 | dev session |
| `assets/img/ticket-coach.png`, `nepal-band.*` | Ticket decoration | **Unknown: not recorded** | **Unknown** | on or before 26 Oct 2026 | dev session |
| `assets/img/shot-*.webp`, `mgmt-shot-*.webp` | App-store / install-sheet screenshots | Screenshots of this product | Company property | 16 Nov 2026 | dev session |
| `assets/img/icons/*.svg` (31), `assets/icons/*.svg` (9) | Line icons | "the pack" (`app.template.html:740-745`) | **Unknown: record whether they were drawn for us or come from an icon set** | on or before 26 Oct 2026 | owner's pack |
| `assets/cards/canva-company-base.png` | A card background; the name says it was exported from Canva; nothing in the code uses it | Canva (by the file name) | **Unknown. Canva elements come under Canva's own content licence, which does not allow redistributing a stock element as a stand-alone file. Do not publish it; remove it or record the design it came from.** | on or before 26 Oct 2026 | unknown |
| `assets/navvoice/ne-leda/*.mp3` (102 clips + `index.json`), `silence.mp3`, `phrases.json` | Nepali turn-by-turn voice for SHARI NAV | Generated with Google Gemini TTS, voice "Leda", by `tools/navvoice/build-voice.php` from our own phrase list | Output of a generative service under the owner's API account (Google's terms for generated audio apply); words are ours | on or before 26 Oct 2026 | dev session on the VPS |
| `downloads/*.apk` (not in git) | The Android apps | Built from this repo | Company property | Nov 2026 | dev session |

### Before a new asset is added

1. Realistic only: a real photo of the real coach, or art the owner supplied.
2. Write the row above first. No row, no commit.
3. Photos of people: only with their say-so.
4. CC BY / CC BY-SA: the credit must be visible where the picture is shown, and
   the row records author, title, URL and licence version.
5. Prefer WebP (or AVIF) for photos, SVG for icons; keep a hero under 150 KB and
   an icon under 30 KB (see `_audit/brand/image-audit.md` from the fx1 pass for
   today's offenders).
6. Never fetch a font or an image from a CDN at runtime: self-host it under
   `/assets/`.
