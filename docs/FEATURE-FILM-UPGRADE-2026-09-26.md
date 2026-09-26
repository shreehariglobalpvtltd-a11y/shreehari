# The brand film — what was upgraded (26 Sep 2026)

Brief: *upgrade the existing 18-second feature animation into a premium,
realistic, mobile-friendly S Hari Global brand animation. Do not rebuild the
website. Do not break booking, payment, WhatsApp, GPS, PWA or existing
flows. Only upgrade the current feature animation visually and technically.*

Nothing outside the feature animation was touched. The section structure is
the same — the four chips on the phone brand band (`.bb-feats`) still open
the same panel — and the only files that changed are the film's own three
(`assets/js/20-feature-story.js`, `assets/css/feature-story.css`,
`tests/feature-story-check.js`) plus a music bed added to the app's existing
sound engine (`assets/js/19-premium.js`) and the cache stamps.

---

## Before → after

| | v2 (this morning) | v3 (now) |
|---|---|---|
| Structure | Four separate 18-second films, one per chip, each starting from nothing | **One film, eight acts, ~18 s**, that any chip opens |
| Opening | A stylised globe on the GPS card only | **Earth in space**: atmosphere glow, lit from one side with a night shade, continents, a satellite in orbit — then a **zoom down to India and Nepal** |
| Route | Not shown | **The real projected map** of the corridor (the same coordinates as the splash), the company road **drawing itself Surat → Rupaidiha** with the **coach moving along it**, both flags at the ends |
| AC sleeper | Berth, vent, air, curtain | Kept, re-lit in the brand palette |
| Live GPS | Globe + pings | The route map with **LIVE badge, pulse rings and a coordinate readout at Rupaidiha** — a tracking system, not a sticker |
| USB charging | The coach's wheel and a phone | **A passenger in their seat, charging a phone**: the socket on the armrest, the cable filling with current, the battery rising |
| Safe travel | First-aid box | **A highway in motion** with a **safety shield** that arrives and ticks, plus the 24×7 and first-aid badges |
| Ending | Auto-advanced to the next film | **End card**: logo, the coach driving in, both flags, and the CTA **आजै आफ्नो यात्रा बुक गर्नुहोस्** as a real button that closes the film and takes the visitor to the search card |
| Text | One caption line | **Bold Nepali heading + small subtext** per act, set large enough to read on a phone; brand face on the opening line |
| Palette | Navy / orange / gold | **Deep blue / white / red** (the brief's brand colours). Gold only on the logo itself |
| Voice | Handset voice, one line per beat | Handset voice, one short line per act, **Nepali first**, Hindi and English when the app is switched |
| Music | None | **A synthesised music bed** (three detuned sines, a slow breathing LFO, low-passed, peak ~0.03) and a **soft whoosh per scene change** — no audio file |
| Progress | Dots | **The four facilities as a strip** under the stage, the current one lit red, passed ones ticked; tapping one jumps the film to it. Replay, a clock and a mute |
| Sound off | Worked | Works — every spoken line is on screen, the film is whole without audio |
| Reduced motion | Static scenes | **A film of still frames**: transitions and keyframes off, the road already drawn, the coach at the end of it, the battery full |

## What did not change — deliberately

- **Nothing runs until a chip is tapped**, and `close()` **removes** the panel.
  Verified in the browser: after close, zero panels and zero animations from
  this feature. The home page keeps the stillness the owner asked for on
  25 Sep.
- No library, no WebGL, no Lottie, no video. The film is inline SVG and CSS.
  It loads no image except the two the app already precaches (logo, coach),
  and only on the last act.
- The script is `defer`red and does nothing at load beyond turning four
  `<li>` into buttons. Booking is not delayed by a byte of this.

## About the voice — plainly

The brief asked for a soft, professional Nepali voice-over. That is a
recorded or synthesised voice from a paid service, and there is no key for one
here. The film uses `speechSynthesis` — the voice the handset already has.
It is free, offline, speaks Nepali and Hindi on most Androids, and is plainer
than a studio voice. That is why every line is also set large on screen: the
words carry the film, the voice accompanies it.

If a recording is made, drop the files at
`/assets/audio/story-<act>-<lang>.mp3` (act 0–7, lang ne/hi/en) and set
`SHG_FEATURE_STORY.VOICE.recorded` — no other change is needed.

## Verified

- `node --check` on both JS files; `tests/feature-story-check.js` clean
  (156 paints validated, 38 `<g>` balanced, 7 act groups, 23 animated
  classes all used, eight acts × three languages, the briefed CTA present).
- Played through in a 375 px viewport: Earth → zoom → route with the coach
  → AC → GPS → USB → safe → end card with CTA.
- Console: no errors (only Chrome's vibrate-before-tap notice, which the code
  already guards).

## Two bugs found by looking, both invisible in code

- The opening caption used a class named `brand`. The navbar's `.brand` is
  `display:flex`, so the heading and the subtext sat side by side. Renamed
  `fs-brand`.
- The safety shield sat in the top-left corner of the road: a CSS
  `transform` on an element **replaces** its `transform` attribute, so the
  animated group lost its `translate(150,112)`. The translate now lives on
  an outer group and the animation on an inner one.
