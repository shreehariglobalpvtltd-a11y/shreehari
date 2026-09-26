/* ================================================================
   [JS] 20. THE BRAND FILM — the four facility chips, as one story.
   v3, 26 Sep 2026.

   THE BRIEF
   ---------
   "Upgrade the existing 18-second feature animation into a premium,
   realistic, mobile-friendly S Hari Global brand animation … start
   with a realistic glowing Earth in space, zoom toward India and
   Nepal, show a branded route line and moving bus, transition into
   each feature, end with the logo, bus and the CTA 'आजै आफ्नो यात्रा
   बुक गर्नुहोस्' … red, blue, white … soft Nepali voice-over if
   possible, light background music; must work perfectly without
   sound … lightweight, lazy, reduced-motion, must not delay booking."

   WHAT CHANGED FROM v2
   --------------------
   v2 was four separate eighteen-second films, one per chip, each
   restarting from nothing. v3 is ONE film of eight acts that any chip
   opens: Earth, the zoom, the road with the coach on it, then the
   four facilities in turn, then the end card with the logo, the coach
   and the booking button. The projector writes data-act on the stage
   and the stylesheet does the directing. Voice-over, a synthesised
   music bed and a soft whoosh per scene change come from the app's
   own sound engine (19-premium.js), all silenced by its Sound switch.

   THE RULE THAT DID NOT CHANGE
   ----------------------------
   Nothing here exists until a chip is tapped. The panel is built on
   tap and REMOVED on close, so no animation, timer, voice or music can
   outlive it. The home page stays as still as it was.

   Self-contained: injects its own stylesheet with the cache stamp
   from its own <script> tag, upgrades the chips it finds, and does
   nothing if the band is not on the page.
================================================================ */
(function () {
  'use strict';
  if (window.SHG_FEATURE_STORY) { return; }

  var doc = document;

  /* Where was I loaded from? -> asset base + the ?v= stamp. */
  var me = doc.currentScript || (function () {
    var s = doc.getElementsByTagName('script');
    return s[s.length - 1] || null;
  })();
  var m = /^(.*\/assets\/)js\/[^\/?#]+(\?[^#]*)?/.exec((me && me.src) || '');
  var base = m ? m[1] : '/assets/';
  var stamp = (m && m[2]) || '';

  function reduced() {
    try { return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); }
    catch (e) { return false; }
  }

  /* ================================================================
     THE SCRIPT — eight acts, about eighteen seconds.
     `at` is the second the act starts. `head` is the bold line over
     the picture, `sub` the small one under it, `voice` what is read
     aloud — short, because a handset voice is slower than a person.
  ================================================================ */
  var SCRIPT = [
    { at: 0.0, act: 0, brand: true,
      head:  { ne: 'S HARI GLOBAL', hi: 'S HARI GLOBAL', en: 'S HARI GLOBAL' },
      sub:   { ne: 'भारत ⇄ नेपाल यात्रा सेवा', hi: 'भारत ⇄ नेपाल यात्रा सेवा', en: 'India ⇄ Nepal travel service' },
      voice: { ne: 'एस हरि ग्लोबल — भारत र नेपाल जोड्ने यात्रा।', hi: 'एस हरि ग्लोबल — भारत और नेपाल को जोड़ती यात्रा।', en: 'S Hari Global. The journey that joins India and Nepal.' } },
    { at: 2.4, act: 1,
      head:  { ne: 'भारतदेखि नेपालसम्म', hi: 'भारत से नेपाल तक', en: 'From India to Nepal' },
      sub:   { ne: 'एउटै बस, एउटै टिकट', hi: 'एक ही बस, एक ही टिकट', en: 'One coach, one ticket' },
      voice: { ne: 'भारतदेखि नेपालसम्म — एउटै बस।', hi: 'भारत से नेपाल तक — एक ही बस।', en: 'India to Nepal, on one coach.' } },
    { at: 4.6, act: 2,
      head:  { ne: 'गुजरात ⇄ रुपैडिहा', hi: 'गुजरात ⇄ रुपैडिहा', en: 'Gujarat ⇄ Rupaidiha' },
      sub:   { ne: 'सूरत · वडोदरा · अहमदाबाद बाट सिधा', hi: 'सूरत · वडोदरा · अहमदाबाद से सीधा', en: 'Direct from Surat, Vadodara, Ahmedabad' },
      voice: { ne: 'गुजरातबाट रुपैडिहा — सिधा बस।', hi: 'गुजरात से रुपैडिहा — सीधी बस।', en: 'Gujarat to Rupaidiha, direct.' } },
    { at: 6.8, act: 3,
      head:  { ne: 'आरामदायी एसी स्लीपर', hi: 'आरामदायक एसी स्लीपर', en: 'Comfortable AC sleeper' },
      sub:   { ne: 'सफा ओढ्ने, सिरानी, आफ्नै पर्दा', hi: 'साफ़ कंबल, तकिया, अपना परदा', en: 'Clean blanket, pillow, your own curtain' },
      voice: { ne: 'आरामदायी एसी स्लीपर।', hi: 'आरामदायक एसी स्लीपर।', en: 'A comfortable AC sleeper.' } },
    { at: 9.6, act: 4,
      head:  { ne: 'लाइभ GPS', hi: 'लाइव GPS', en: 'Live GPS' },
      sub:   { ne: 'बस कहाँ छ, फोनमै देखिन्छ', hi: 'बस कहाँ है, फोन पर दिखता है', en: 'Where the bus is, on your phone' },
      voice: { ne: 'लाइभ जी पी एस — बस कहाँ छ, फोनमै।', hi: 'लाइव जी पी एस — बस कहाँ है, फोन पर।', en: 'Live GPS. Where the bus is, on your phone.' } },
    { at: 12.4, act: 5,
      head:  { ne: 'USB चार्जिङ सुविधा', hi: 'USB चार्जिंग सुविधा', en: 'USB charging' },
      sub:   { ne: 'हरेक सिटमा, रातभरि', hi: 'हर सीट पर, रातभर', en: 'At every berth, all night' },
      voice: { ne: 'हरेक सिटमा यू एस बी चार्जिङ।', hi: 'हर सीट पर यू एस बी चार्जिंग।', en: 'USB charging at every berth.' } },
    { at: 15.0, act: 6,
      head:  { ne: 'सुरक्षित यात्रा', hi: 'सुरक्षित यात्रा', en: 'Safe travel' },
      sub:   { ne: 'औषधी, अनुभवी चालक, २४ घण्टा सहयोग', hi: 'दवा, अनुभवी ड्राइवर, 24 घंटे सहायता', en: 'First aid, experienced drivers, 24-hour help' },
      voice: { ne: 'सुरक्षित यात्रा — चौबीसै घण्टा सहयोग।', hi: 'सुरक्षित यात्रा — चौबीस घंटे सहायता।', en: 'Safe travel, with help around the clock.' } },
    { at: 17.4, act: 7,
      head:  { ne: 'आजै आफ्नो यात्रा बुक गर्नुहोस्', hi: 'आज ही अपनी यात्रा बुक करें', en: 'Book your journey today' },
      sub:   { ne: 'S Hari Global Pvt Ltd', hi: 'S Hari Global Pvt Ltd', en: 'S Hari Global Pvt Ltd' },
      voice: { ne: 'आजै आफ्नो यात्रा बुक गर्नुहोस्।', hi: 'आज ही अपनी यात्रा बुक करें।', en: 'Book your journey today.' } }
  ];
  var FILM_SECONDS = 19.6;

  /* the strip under the stage: four facilities -> the act each lives in */
  var STRIP = [
    { act: 3, ic: '❄️', ne: 'एसी स्लीपर', hi: 'एसी स्लीपर', en: 'AC sleeper' },
    { act: 4, ic: '📍', ne: 'लाइभ GPS',   hi: 'लाइव GPS',   en: 'Live GPS' },
    { act: 5, ic: '⚡', ic2: '', ne: 'USB चार्जिङ', hi: 'USB चार्जिंग', en: 'USB charging' },
    { act: 6, ic: '🛡️', ne: 'सुरक्षित यात्रा', hi: 'सुरक्षित यात्रा', en: 'Safe travel' }
  ];
  /* which chip opens which act, when a viewer taps a facility rather
     than watching from the top */
  var CHIP_ACT = { ac: 3, gps: 4, charge: 5, safe: 6, comfort: 3 };

  /* ================================================================
     THE STAGE — one SVG, every act drawn and hidden, 360 x 220.
     Coordinates that come from the splash map are the real projected
     outlines (India, Nepal, and the company road Surat -> Rupaidiha)
     in their 540 x 310 box, placed here with one transform so the
     corridor fills the frame.
  ================================================================ */
  var MAP_IND = 'M190.1,17.1L196.7,10L213.7,9L233.6,20.2L237.4,34.3L232.6,51.6L253.4,62.7L244.9,76.9L253.4,83L267.6,89.1L281.8,91.1L298.9,99.2L310.2,101.3L320.6,97.2L328.2,92.1L330.1,97.2L343.3,97.2L357.5,96.2L371.7,91.1L385.9,86.1L400.1,80L408.6,83L405.7,94.2L401,100.3L387.8,105.3L382.1,113.4L379.3,124.6L370.7,134.7L363.2,145.9L360.3,128.6L353.7,123.6L349.9,134.7L337.6,145.9L328.2,148.9L312.1,150.9L305.5,165.1L292.2,171.2L281.8,183.4L265.7,197.6L252.5,207.7L245.9,236.1L243,252.3L237.4,264.5L233.6,274.6L227,278.7L220.3,287.1L214.7,278.7L208,265.5L203.3,250.3L195.7,237.1L190.1,218.9L184.4,210.8L176.8,186.4L174.9,165.1L176.8,158L174,149.9L171.2,146.9L168.3,152L158.9,157L149.4,158L140.9,149.9L139,141.8L147.5,139.8L155.1,136.8L157.9,133.7L149.4,130.7L137.1,129.7L132.4,126.6L138,122.6L149.4,122.6L157.9,115.5L159.8,87.1L170.2,76.9L181.6,68.8L192,54.6L192.9,39.4Z';
  var MAP_NP  = 'M244.6,63.2L252.5,62.7L262,60.7L270.5,63.7L278,72.9L283.7,71.9L292.2,79L300.7,84L307.4,84L311.1,87.1L321.6,86.1L321.6,97.2L310.2,101.3L298.9,99.2L287.5,95.2L275.2,91.1L262,86.1L252.5,79L244.5,76.9Z';
  var MAP_RT  = 'M176.2,154.3L173.8,135.5L193.2,119.7L204.2,96.1L225.2,93.4L253,96.7L259.3,84.4';

  function stage() {
    var motion = reduced()
      ? ''
      : '<animateMotion dur="7s" begin="0s" repeatCount="indefinite" rotate="auto" keyPoints="0;1" keyTimes="0;1" calcMode="linear"><mpath href="#fsRoad"/></animateMotion>';

    return '<svg viewBox="0 0 360 220" role="img" aria-label="S Hari Global">'
      + '<defs>'
      +   '<radialGradient id="fsSpace" cx="50%" cy="40%" r="80%"><stop offset="0%" stop-color="#0B2A6B"/><stop offset="100%" stop-color="#03071A"/></radialGradient>'
      +   '<radialGradient id="fsGlobe" cx="36%" cy="32%" r="78%"><stop offset="0%" stop-color="#5FA8E8"/><stop offset="46%" stop-color="#1E5FA8"/><stop offset="82%" stop-color="#0A2A5C"/><stop offset="100%" stop-color="#03112B"/></radialGradient>'
      +   '<radialGradient id="fsAtm" cx="50%" cy="50%" r="50%"><stop offset="78%" stop-color="#6FC0FF" stop-opacity="0"/><stop offset="94%" stop-color="#6FC0FF" stop-opacity=".5"/><stop offset="100%" stop-color="#6FC0FF" stop-opacity="0"/></radialGradient>'
      +   '<radialGradient id="fsNight" cx="30%" cy="28%" r="80%"><stop offset="55%" stop-color="#000" stop-opacity="0"/><stop offset="100%" stop-color="#000" stop-opacity=".6"/></radialGradient>'
      +   '<linearGradient id="fsBlanket" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#2E6FCF"/><stop offset="100%" stop-color="#1B4A95"/></linearGradient>'
      +   '<linearGradient id="fsRoadG" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#1B2C55"/><stop offset="100%" stop-color="#0D1A38"/></linearGradient>'
      +   '<radialGradient id="fsRed" cx="50%" cy="50%" r="50%"><stop offset="0%" stop-color="#FF3B4A" stop-opacity=".55"/><stop offset="100%" stop-color="#FF3B4A" stop-opacity="0"/></radialGradient>'
      +   '<clipPath id="fsBall"><circle cx="180" cy="110" r="70"/></clipPath>'
      + '</defs>'
      + '<rect width="360" height="220" fill="url(#fsSpace)"/>'

      /* ---- stars, always ---- */
      + '<g class="act act-stars">'
      +   '<circle class="fs-star" cx="24" cy="30" r="1.3" fill="#fff"/><circle class="fs-star" cx="330" cy="38" r="1.1" fill="#fff"/>'
      +   '<circle class="fs-star" cx="52" cy="180" r="1" fill="#fff"/><circle class="fs-star" cx="312" cy="170" r="1.4" fill="#fff"/>'
      +   '<circle class="fs-star" cx="110" cy="18" r="1" fill="#fff"/><circle class="fs-star" cx="250" cy="196" r="1.2" fill="#fff"/>'
      +   '<circle class="fs-star" cx="70" cy="90" r=".9" fill="#fff"/><circle class="fs-star" cx="300" cy="100" r="1" fill="#fff"/>'
      +   '<circle class="fs-star" cx="150" cy="200" r=".9" fill="#fff"/><circle class="fs-star" cx="200" cy="16" r="1.1" fill="#fff"/>'
      + '</g>'

      /* ---- act 0/1: Earth ---- */
      + '<g class="act act-earth">'
      +   '<circle cx="180" cy="110" r="79" fill="url(#fsAtm)"/>'
      +   '<circle cx="180" cy="110" r="70" fill="url(#fsGlobe)"/>'
      +   '<g clip-path="url(#fsBall)"><g class="fs-globe">'
      +     '<g fill="none" stroke="rgba(120,190,255,.28)" stroke-width="1">'
      +       '<ellipse cx="180" cy="110" rx="70" ry="70"/><ellipse cx="180" cy="110" rx="23" ry="70"/><ellipse cx="180" cy="110" rx="47" ry="70"/>'
      +       '<line x1="110" y1="110" x2="250" y2="110"/><ellipse cx="180" cy="110" rx="70" ry="36"/><ellipse cx="180" cy="110" rx="70" ry="16"/>'
      +     '</g>'
      +     '<g fill="rgba(64,190,140,.55)" stroke="rgba(150,255,210,.5)" stroke-width=".8">'
      +       '<path d="M108 60q30-16 62-10t52 7 38-4 25 11-8 19-27 8-21-3-17 6-19 0-24-8-21 3-17-7-5-18z"/>'
      +       '<path d="M112 98q17-8 32 0t17 17-8 19-21 7-21-9-7-19z"/>'
      +       '<path d="M222 126q15-2 22 7t-5 19-19 2-7-15z"/>'
      +       '<path d="M232 162q11-3 17 5t-5 13-15 1-3-11z"/>'
      +     '</g>'
      +     '<path d="M183 86q19-4 32 7t3 26l-10 21-10 26-10-24-13-19-7-21z" fill="rgba(255,120,70,.78)" stroke="#FFD0B8" stroke-width="1"/>'
      +     '<path d="M181 84q17-6 30-1t22 9" fill="none" stroke="#FFF6E2" stroke-width="2" stroke-linecap="round" opacity=".95"/>'
      +   '</g></g>'
      +   '<circle cx="180" cy="110" r="70" fill="url(#fsNight)"/>'
      +   '<circle cx="180" cy="110" r="70" fill="none" stroke="rgba(170,220,255,.55)" stroke-width="1.4"/>'
      +   '<g class="fs-sat"><g transform="translate(180,22)"><rect x="-7" y="-4" width="14" height="8" rx="2" fill="#DCE8FF"/><rect x="-17" y="-2.5" width="9" height="5" rx="1" fill="#E11D2E"/><rect x="8" y="-2.5" width="9" height="5" rx="1" fill="#E11D2E"/></g></g>'
      + '</g>'

      /* ---- act 2 / 4: the map, the road, the coach ---- */
      + '<g class="act act-map"><g transform="translate(-166,-82) scale(1.6)">'
      +   '<path d="' + MAP_IND + '" fill="rgba(46,95,168,.28)" stroke="rgba(190,215,255,.7)" stroke-width="1" stroke-linejoin="round"/>'
      +   '<path d="' + MAP_NP + '" fill="rgba(225,29,46,.32)" stroke="#FF8A94" stroke-width="1" stroke-linejoin="round"/>'
      +   '<path id="fsRoad" class="fs-route" pathLength="1" d="' + MAP_RT + '" fill="none" stroke="#E11D2E" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>'
      /* the two ends: Gujarat, and the border post */
      +   '<circle cx="176.2" cy="154.3" r="3.2" fill="#fff" stroke="#E11D2E" stroke-width="1.6"/>'
      +   '<circle cx="259.3" cy="84.4" r="3.2" fill="#fff" stroke="#E11D2E" stroke-width="1.6"/>'
      +   '<g transform="translate(160,158)"><rect width="12" height="8" rx="1.2" fill="#FF9933"/><rect y="2.7" width="12" height="2.7" fill="#fff"/><rect y="5.3" width="12" height="2.7" fill="#138808"/><circle cx="6" cy="4" r="1.1" fill="none" stroke="#0A3A8C" stroke-width=".6"/></g>'
      +   '<g transform="translate(264,74)"><path d="M0 0l9 5H0zM0 5l9 5H0zM0 0v11" fill="#DC143C" stroke="#10338A" stroke-width=".9" stroke-linejoin="round"/></g>'
      +   '<g class="fs-gpsui" transform="translate(259.3,84.4)">'
      +     '<circle class="fs-ping" r="6" fill="none" stroke="#FF8A94" stroke-width="1.6"/><circle class="fs-ping fs-ping2" r="6" fill="none" stroke="#FF8A94" stroke-width="1.6"/><circle class="fs-ping fs-ping3" r="6" fill="none" stroke="#FF8A94" stroke-width="1.6"/>'
      +     '<path d="M-11 0h6M5 0h6M0-11v6M0 5v6" stroke="#fff" stroke-width="1" opacity=".9"/>'
      +   '</g>'
      /* the coach on the road */
      +   '<g>' + motion
      +     '<g transform="translate(-6,-3.5)"><rect width="12" height="7" rx="2" fill="#fff" stroke="#E11D2E" stroke-width="1"/><rect x="1.5" y="1.5" width="3" height="2.4" fill="#5FA8E8"/><rect x="5.5" y="1.5" width="3" height="2.4" fill="#5FA8E8"/><circle cx="3" cy="7.3" r="1.2" fill="#0B1428"/><circle cx="9" cy="7.3" r="1.2" fill="#0B1428"/></g>'
      +   '</g>'
      + '</g>'
      +   '<g class="fs-gpsui" font-family="ui-monospace,Menlo,monospace" font-size="8" fill="#BFD2F5">'
      +     '<g transform="translate(16,16)"><rect width="62" height="20" rx="10" fill="rgba(225,29,46,.22)" stroke="rgba(255,120,120,.6)"/><circle class="fs-live" cx="13" cy="10" r="4" fill="#FF4D4D"/><text x="25" y="14" fill="#FFDCDC" font-size="10" font-weight="700" font-family="system-ui,sans-serif">LIVE</text></g>'
      +     '<g transform="translate(272,150)"><text y="0">28.06 N</text><text y="10">81.61 E</text><text y="22" fill="#fff" font-weight="700">रुपैडिहा</text></g>'
      +   '</g>'
      + '</g>'

      /* ---- act 3: the sleeper ---- */
      + '<g class="act act-ac"><g transform="translate(30,6)">'
      +   '<rect x="0" y="0" width="300" height="210" fill="#0A1B3C"/>'
      +   '<rect x="28" y="56" width="244" height="112" rx="14" fill="#0E2A56" stroke="#32507F" stroke-width="2"/>'
      +   '<rect x="28" y="56" width="244" height="14" rx="7" fill="#16305C"/>'
      +   '<circle cx="62" cy="76" r="5" fill="#FFD89B"/><path d="M62 81l-14 26h28z" fill="#FFD89B" opacity=".18"/>'
      +   '<g><rect x="150" y="62" width="66" height="10" rx="4" fill="#1E3E70" stroke="#3E67A8" stroke-width="1.2"/><path d="M158 64v6M168 64v6M178 64v6M188 64v6M198 64v6M208 64v6" stroke="#6B93D6" stroke-width="1.4"/></g>'
      +   '<g stroke="#9BD8FF" stroke-width="2" stroke-linecap="round" fill="none" opacity=".85"><path class="fs-puff" d="M164 76q-5 9 0 18t-3 16"/><path class="fs-puff" d="M184 76q-5 9 0 18t-3 16"/><path class="fs-puff" d="M204 76q-5 9 0 18t-3 16"/></g>'
      +   '<rect x="40" y="128" width="220" height="30" rx="8" fill="#DFE8F8"/>'
      +   '<rect x="48" y="112" width="44" height="22" rx="9" fill="#F4F8FF"/>'
      +   '<g class="fs-breathe"><path d="M92 132q34-16 74-12t82 12v14q-46-8-82-6t-74 6z" fill="url(#fsBlanket)"/><circle cx="104" cy="120" r="12" fill="#F0C9A4"/><path d="M92 118q6-14 20-12t14 12z" fill="#2B3A55"/></g>'
      +   '<g class="fs-curtain"><rect x="224" y="56" width="48" height="112" rx="10" fill="#0E2854" opacity=".96"/><path d="M232 60v104M244 60v104M256 60v104" stroke="#2A4A85" stroke-width="2"/></g>'
      +   '<g fill="#BBD9FF" font-family="system-ui,sans-serif" font-weight="700"><text class="fs-zzz" x="120" y="108" font-size="13">z</text><text class="fs-zzz" x="120" y="108" font-size="11">z</text><text class="fs-zzz" x="120" y="108" font-size="15">z</text></g>'
      + '</g></g>'

      /* ---- act 5: a passenger charging a phone ---- */
      + '<g class="act act-usb"><g transform="translate(30,6)">'
      +   '<rect x="0" y="0" width="300" height="210" fill="#08142E"/>'
      +   '<rect x="30" y="34" width="240" height="70" rx="10" fill="#0D2450" stroke="#2B4A85" stroke-width="1.5"/>'
      +   '<circle cx="230" cy="52" r="9" fill="#F2F6FF" opacity=".9"/><circle cx="226" cy="49" r="9" fill="#0D2450"/>'
      +   '<circle class="fs-star" cx="60" cy="50" r="1.2" fill="#fff"/><circle class="fs-star" cx="110" cy="44" r="1" fill="#fff"/><circle class="fs-star" cx="170" cy="56" r="1.1" fill="#fff"/>'
      /* the seat and the person in it */
      +   '<rect x="40" y="110" width="150" height="70" rx="12" fill="#1B3766"/><rect x="40" y="96" width="150" height="22" rx="10" fill="#22437A"/>'
      +   '<circle cx="100" cy="92" r="16" fill="#F0C9A4"/><path d="M84 88q6-16 22-14t12 14z" fill="#2B3A55"/>'
      +   '<path d="M72 130q10-24 28-24t28 24v34H72z" fill="#3B6DB5"/>'
      +   '<path d="M118 132q14-4 24 8l4 12-10 4-8-9z" fill="#F0C9A4"/>'
      /* the phone in the hand, battery filling */
      +   '<g transform="translate(140,120)"><rect width="34" height="60" rx="6" fill="#0E1C38" stroke="#5B7CB5" stroke-width="1.6"/><rect x="4" y="5" width="26" height="50" rx="3" fill="#07132B"/>'
      +     '<rect x="9" y="16" width="16" height="28" rx="3" fill="none" stroke="#7FE7A8" stroke-width="1.6"/><rect x="14" y="13" width="6" height="3" rx="1.5" fill="#7FE7A8"/>'
      +     '<rect class="fs-batt-fill" x="11" y="18" width="12" height="24" rx="1.5" fill="#4BD07E"/>'
      +     '<g class="fs-bolt"><path d="M18 22l-5 9h4l-2 8 6-10h-4z" fill="#08331C"/></g></g>'
      /* the socket on the armrest, the cable, the current */
      +   '<rect x="206" y="150" width="18" height="12" rx="3" fill="#0B1428" stroke="#5B7CB5" stroke-width="1.4"/><rect x="211" y="153" width="8" height="6" rx="1" fill="#2B4A85"/>'
      +   '<path d="M206 156q-22 4-30 0t-6-18" fill="none" stroke="#3A4E78" stroke-width="4" stroke-linecap="round"/>'
      +   '<path class="fs-fill-wire" d="M206 156q-22 4-30 0t-6-18" fill="none" stroke="#FFD76B" stroke-width="2.4" stroke-linecap="round"/>'
      +   '<g class="fs-glow"><circle cx="215" cy="156" r="10" fill="url(#fsRed)"/></g>'
      +   '<text x="150" y="196" fill="#BFD2F5" font-size="11" font-weight="700" text-anchor="middle" font-family="system-ui,sans-serif">USB · Type-C · हरेक सिटमा</text>'
      + '</g></g>'

      /* ---- act 6: the highway and the shield ---- */
      + '<g class="act act-safe"><g transform="translate(30,6)">'
      +   '<rect x="0" y="0" width="300" height="210" fill="#08142E"/>'
      +   '<rect x="0" y="0" width="300" height="96" fill="#0B1F45"/>'
      +   '<path d="M0 96q60-18 150-18t150 18v114H0z" fill="url(#fsRoadG)"/>'
      +   '<path d="M150 96L30 210M150 96l120 114" stroke="rgba(255,255,255,.35)" stroke-width="2"/>'
      +   '<path class="fs-lane" d="M150 96v114" stroke="#FFD76B" stroke-width="3" stroke-linecap="round"/>'
      /* translate on the outer group, the animated class on the inner:
         a CSS transform on the same element REPLACES the attribute, and
         the shield landed in the top-left corner of the road. */
      +   '<g transform="translate(150,112)"><g class="fs-shield-in">'
      +     '<circle class="fs-ring" r="34" fill="none" stroke="#4BD07E" stroke-width="2"/><circle class="fs-ring fs-ring2" r="34" fill="none" stroke="#4BD07E" stroke-width="2"/>'
      +     '<path d="M0-40l32 13v22C32 15 18 31 0 42-18 31-32 15-32-5v-22z" fill="#12472E" stroke="#4BD07E" stroke-width="2.5"/>'
      +     '<path class="fs-check" d="M-13 0l9 9 18-20" fill="none" stroke="#fff" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>'
      +   '</g></g>'
      +   '<g transform="translate(22,150)"><rect width="64" height="22" rx="11" fill="rgba(75,208,126,.16)" stroke="#4BD07E"/><text x="32" y="15" fill="#9CF0BE" font-size="11" font-weight="800" text-anchor="middle" font-family="system-ui,sans-serif">24 × 7</text></g>'
      +   '<g transform="translate(214,150)"><rect width="66" height="22" rx="11" fill="rgba(225,29,46,.18)" stroke="#FF8A94"/><text x="33" y="15" fill="#FFD3D6" font-size="10.5" font-weight="800" text-anchor="middle" font-family="system-ui,sans-serif">First aid</text></g>'
      + '</g></g>'

      /* ---- act 7: the end card ---- */
      + '<g class="act act-end">'
      +   '<path d="M20 190q120-40 320 0" fill="none" stroke="#E11D2E" stroke-width="2" stroke-dasharray="4 6" opacity=".7"/>'
      +   '<image class="fs-end-logo" href="' + base + 'img/logo.png' + stamp + '" x="122" y="14" width="116" height="85" preserveAspectRatio="xMidYMid meet"/>'
      +   '<image class="fs-end-bus" href="' + base + 'img/bus-shg-sm.webp' + stamp + '" x="88" y="104" width="184" height="96" preserveAspectRatio="xMidYMid meet"/>'
      +   '<g transform="translate(150,206)"><rect width="12" height="8" rx="1.2" fill="#FF9933"/><rect y="2.7" width="12" height="2.7" fill="#fff"/><rect y="5.3" width="12" height="2.7" fill="#138808"/></g>'
      +   '<g transform="translate(198,204)"><path d="M0 0l9 5H0zM0 5l9 5H0zM0 0v11" fill="#DC143C" stroke="#10338A" stroke-width=".9" stroke-linejoin="round"/></g>'
      + '</g>'
      + '</svg>';
  }

  /* ================================================================
     THE VOICE — the handset's own. Honest about what it is: the brief
     asked for ElevenLabs-quality narration, which is a paid service
     with a key, and there is none here. speechSynthesis is free,
     offline, speaks Nepali and Hindi on most Android handsets, and is
     plainer. Every line is also on screen, so the film is whole
     without it. A real recording drops in at
     /assets/audio/story-<act>-<lang>.mp3 and this plays it instead.
  ================================================================ */
  var VOICE = (function () {
    var on = true;
    try { on = localStorage.getItem('shg:storyVoice') !== '0'; } catch (e) {}
    function voiceFor(l) {
      try {
        if (!window.speechSynthesis) { return null; }
        var want = l === 'ne' ? ['ne-NP', 'ne', 'hi-IN'] : l === 'hi' ? ['hi-IN', 'hi'] : ['en-IN', 'en-GB', 'en-US', 'en'];
        var all = window.speechSynthesis.getVoices() || [];
        for (var w = 0; w < want.length; w++) {
          for (var i = 0; i < all.length; i++) {
            if (String(all[i].lang || '').toLowerCase().indexOf(want[w].toLowerCase()) === 0) { return all[i]; }
          }
        }
      } catch (e) {}
      return null;
    }
    var audio = null;
    return {
      get enabled() { return on; },
      set enabled(v) { on = !!v; if (!v) { VOICE.stop(); } },
      stop: function () {
        try { if (window.speechSynthesis) { window.speechSynthesis.cancel(); } } catch (e) {}
        try { if (audio) { audio.pause(); audio = null; } } catch (e) {}
      },
      say: function (text, l, actIndex) {
        if (!on || !text) { return; }
        VOICE.stop();
        /* a recording, if one was ever made */
        try {
          var src = base + 'audio/story-' + actIndex + '-' + l + '.mp3';
          if (VOICE.recorded && VOICE.recorded[src]) {
            audio = new Audio(src);
            audio.volume = 0.95;
            audio.play().catch(function () {});
            return;
          }
        } catch (e) {}
        try {
          if (!window.speechSynthesis || !window.SpeechSynthesisUtterance) { return; }
          var u = new window.SpeechSynthesisUtterance(text);
          var v = voiceFor(l);
          if (v) { u.voice = v; }
          u.lang = v ? v.lang : (l === 'ne' ? 'ne-NP' : l === 'hi' ? 'hi-IN' : 'en-IN');
          u.rate = 0.92;
          u.pitch = 1.0;
          window.speechSynthesis.speak(u);
        } catch (e) {}
      },
      recorded: null
    };
  })();
  try { if (window.speechSynthesis) { window.speechSynthesis.onvoiceschanged = function () {}; } } catch (e) {}

  function feel(kind) { try { if (window.SHGFeel) { window.SHGFeel.fire(kind); } } catch (e) {} }
  function bed(onOff) { try { if (window.SHGFeel && window.SHGFeel.bed) { window.SHGFeel.bed[onOff ? 'start' : 'stop'](); } } catch (e) {} }

  function lang() {
    try {
      var l = (typeof LANG === 'string' && LANG) || doc.documentElement.lang || 'ne';
      l = String(l).slice(0, 2);
      return (l === 'ne' || l === 'hi' || l === 'en') ? l : 'ne';
    } catch (e) { return 'ne'; }
  }

  /* ---- stylesheet ------------------------------------------------ */
  if (!doc.querySelector('link[href*="feature-story.css"]')) {
    var link = doc.createElement('link');
    link.rel = 'stylesheet';
    link.href = base + 'css/feature-story.css' + stamp;
    doc.head.appendChild(link);
  }

  /* ================================================================
     THE PANEL
  ================================================================ */
  var wrap = null, timers = [], playing = false, startedAt = 0, clock = null;

  function render() {
    var l = lang();
    var strip = STRIP.map(function (s) {
      return '<button type="button" data-act="' + s.act + '"><i aria-hidden="true">' + s.ic + '</i>' + s[l] + '</button>';
    }).join('');
    var cta = SCRIPT[SCRIPT.length - 1].head[l];
    return '<div class="fs-card" role="dialog" aria-modal="true" aria-label="S Hari Global">'
      + '<button type="button" class="fs-close" aria-label="Close">&times;</button>'
      + '<div class="fs-stage" data-act="0">' + stage()
      +   '<div class="fs-caption" aria-live="polite"><b></b><small></small></div>'
      +   '<div class="fs-bar"><i></i></div>'
      + '</div>'
      + '<div class="fs-body">'
      +   '<div class="fs-strip">' + strip + '</div>'
      +   '<button type="button" class="fs-cta">' + cta + ' →</button>'
      +   '<div class="fs-nav">'
      +     '<button type="button" class="fs-replay" aria-label="Replay">↻</button>'
      +     '<span class="fs-time">0:00</span>'
      +     '<button type="button" class="fs-mute" aria-label="Sound">' + (VOICE.enabled ? '🔊' : '🔇') + '</button>'
      +   '</div>'
      + '</div></div>';
  }

  function stopFilm() {
    playing = false;
    while (timers.length) { clearTimeout(timers.pop()); }
    if (clock) { clearInterval(clock); clock = null; }
    VOICE.stop();
  }

  function showAct(i) {
    if (!wrap) { return; }
    var a = SCRIPT[i], l = lang();
    var st = wrap.querySelector('.fs-stage');
    var cap = wrap.querySelector('.fs-caption');
    st.setAttribute('data-act', String(a.act));
    /* fs-brand, not brand: the navbar's .brand is display:flex and
       would lay the two lines side by side. It did. */
    cap.classList.toggle('fs-brand', !!a.brand);
    cap.querySelector('b').textContent = a.head[l];
    cap.querySelector('small').textContent = a.sub[l];
    cap.classList.remove('in');
    void cap.offsetWidth;
    cap.classList.add('in');
    var card = wrap.querySelector('.fs-card');
    card.classList.toggle('ended', a.act === 7);
    var btns = wrap.querySelectorAll('.fs-strip button');
    for (var b = 0; b < btns.length; b++) {
      var act = parseInt(btns[b].getAttribute('data-act'), 10);
      btns[b].classList.toggle('on', act === a.act);
      btns[b].classList.toggle('done', act < a.act);
    }
    if (i > 0) { feel('whoosh'); }
    VOICE.say(a.voice[l], l, i);
  }

  /* Play from a given act (0 = the top). Every timer is kept so close()
     can stop the film dead. */
  function play(fromAct) {
    stopFilm();
    if (!wrap) { return; }
    fromAct = fromAct || 0;
    var startIdx = 0;
    for (var i = 0; i < SCRIPT.length; i++) { if (SCRIPT[i].act === fromAct) { startIdx = i; } }
    var offset = SCRIPT[startIdx].at;
    playing = true;
    startedAt = Date.now() - offset * 1000;

    var bar = wrap.querySelector('.fs-bar i');
    if (bar) {
      bar.style.transition = 'none';
      bar.style.width = (offset / FILM_SECONDS * 100) + '%';
      timers.push(setTimeout(function () {
        bar.style.transition = 'width ' + (FILM_SECONDS - offset) + 's linear';
        bar.style.width = '100%';
      }, 40));
    }
    var time = wrap.querySelector('.fs-time');
    clock = setInterval(function () {
      if (!wrap) { return; }
      var s = Math.min(FILM_SECONDS, (Date.now() - startedAt) / 1000);
      if (time) { time.textContent = '0:' + (s < 10 ? '0' : '') + Math.floor(s); }
    }, 250);

    for (var k = startIdx; k < SCRIPT.length; k++) {
      (function (idx) {
        var delay = Math.max(0, (SCRIPT[idx].at - offset) * 1000);
        timers.push(setTimeout(function () { if (playing) { showAct(idx); } }, delay));
      })(k);
    }
    timers.push(setTimeout(function () { playing = false; if (clock) { clearInterval(clock); clock = null; } }, (FILM_SECONDS - offset) * 1000 + 200));
  }

  function close() {
    if (!wrap) { return; }
    stopFilm();
    bed(false);
    var w = wrap;
    wrap = null;
    doc.removeEventListener('keydown', onKey);
    /* Remove, never hide: a hidden panel keeps every animation in the
       stylesheet running for as long as the tab is open. */
    if (w.parentNode) { w.parentNode.removeChild(w); }
    try { doc.body.style.overflow = ''; } catch (e) {}
  }
  function onKey(e) { if (e.key === 'Escape') { close(); } }

  function goBook() {
    close();
    feel('select');
    var a = doc.querySelector('[data-scroll="search-anchor"]');
    if (a) { a.click(); return; }
    try { location.hash = '#/'; } catch (e) {}
    var el = doc.getElementById('search-anchor');
    if (el && el.scrollIntoView) { try { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e2) {} }
  }

  function open(key) {
    var from = CHIP_ACT[key] || 0;
    if (!wrap) {
      wrap = doc.createElement('div');
      wrap.className = 'fs-wrap';
      wrap.innerHTML = render();
      wrap.addEventListener('click', function (e) {
        if (e.target === wrap || e.target.closest('.fs-close')) { close(); feel('tap'); return; }
        if (e.target.closest('.fs-cta')) { goBook(); return; }
        var go = e.target.closest('.fs-strip button');
        if (go) { feel('tap'); play(parseInt(go.getAttribute('data-act'), 10)); return; }
        if (e.target.closest('.fs-replay')) { feel('tap'); play(0); return; }
        var mute = e.target.closest('.fs-mute');
        if (mute) {
          VOICE.enabled = !VOICE.enabled;
          mute.textContent = VOICE.enabled ? '🔊' : '🔇';
          try { localStorage.setItem('shg:storyVoice', VOICE.enabled ? '1' : '0'); } catch (e2) {}
          feel('tap');
          return;
        }
        /* a tap on the picture pauses the narration without closing */
        if (e.target.closest('.fs-stage')) { stopFilm(); feel('tap'); }
      });
      doc.body.appendChild(wrap);
      try { doc.body.style.overflow = 'hidden'; } catch (e) {}
      doc.addEventListener('keydown', onKey);
      var c = wrap.querySelector('.fs-close');
      if (c && c.focus) { try { c.focus(); } catch (e) {} }
      bed(true);
    }
    /* A chip names a facility; the film still opens on the Earth, as
       briefed, and the strip lets a viewer jump straight to it. */
    play(0);
    if (from) {
      var btn = wrap.querySelector('.fs-strip button[data-act="' + from + '"]');
      if (btn) { btn.classList.add('done'); }
    }
  }

  /* ---- turn the four chips into buttons -------------------------- */
  /* 26 Sep 2026: the band's four chips are AC Sleeper, Mobile Charging,
     Safe Travel and Travel Comfort (the owner's list), so the third chip is
     now Safe Travel and the fourth is Comfort. 'comfort' opens act 3 - the
     AC-sleeper scene, whose own copy is the clean blanket, the pillow and
     your own curtain, which is exactly what comfort means on this coach.
     'gps' is still here and still narrated inside the film (act 4); it is
     just no longer one of the four chips, because Track Bus already leads
     with it. */
  var ORDER = ['ac', 'charge', 'safe', 'comfort'];
  function upgrade() {
    var list = doc.querySelectorAll('.bb-feats > li');
    if (!list.length || list[0].querySelector('.bb-open')) { return; }
    for (var i = 0; i < list.length && i < ORDER.length; i++) {
      (function (li, key) {
        var btn = doc.createElement('button');
        btn.type = 'button';
        btn.className = 'bb-open';
        while (li.firstChild) { btn.appendChild(li.firstChild); }
        li.appendChild(btn);
        btn.addEventListener('click', function () { open(key); });
      })(list[i], ORDER[i]);
    }
  }
  if (doc.readyState === 'loading') { doc.addEventListener('DOMContentLoaded', upgrade); }
  else { upgrade(); }

  window.SHG_FEATURE_STORY = { open: open, close: close, play: play, upgrade: upgrade, SCRIPT: SCRIPT };
})();
