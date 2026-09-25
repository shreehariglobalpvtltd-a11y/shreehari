/* ================================================================
   [JS] 20. FEATURE STORY — the four facility chips, told properly.

   Owner brief, 26 Sep 2026: "4 ota ko ekdam dark background ma
   colorful animated … live GPS le kasari tracking garcha … GPS ma
   earth pura load bhaye jasto real jasto animation … charger ma bus
   ko wheel bata rati ko bela power aayera mobile charge bhayeko …
   AC sleeper: blanket odera private coach ma mast suteko … safe
   travel bhanale first aid, 24/7 help, call available, problem
   sundine manche … marketing garne hisab le."

   WHY IT OPENS INSTEAD OF PLAYING
   -------------------------------
   The same owner asked on 25 Sep for the home page animations to go,
   because the phone hung. Both asks are right and they fit together
   one way: the home page stays still, and none of this exists until a
   finger opens it. The panel is built on tap and REMOVED on close, so
   the animations stop rather than ticking away off-screen.

   Four scenes, drawn as SVG with CSS animation — no images, no
   library, no network call. The sound is the app's own engine
   (window.SHGFeel), so the Sound switch in the menu silences it like
   everything else.

   Self-contained: it upgrades the chips it finds, injects its own
   stylesheet with the cache stamp from its own <script> tag, and does
   nothing at all if the band is not on the page.
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

  /* ---- copy ------------------------------------------------------
     Written here rather than in 04-i18n.js because it is four whole
     paragraphs of marketing, not interface labels, and keeping it
     beside the scene it describes is what stops the two drifting.
     t() is still used for the chip labels themselves. */
  var COPY = {
    gps: {
      ne: { title: '📍 लाइभ GPS — बस कहाँ छ, थाहा हुन्छ',
            lead: 'बसमा राखिएको GPS ले हरेक केही सेकेन्डमा आफ्नो ठाउँ पठाउँछ। तपाईं र घरपरिवार दुवैले नक्सामा हेर्न सक्नुहुन्छ।',
            points: ['<b>हरेक मिनेट अपडेट</b> — बस कति टाढा छ, कति बेरमा आइपुग्छ',
                     '<b>घरमा पठाउनुहोस्</b> — लिंक पठाएपछि उहाँहरूले पनि हेर्न पाउनुहुन्छ',
                     '<b>पिकअपमा पर्खनु पर्दैन</b> — बस नजिक आएपछि निस्किए पुग्छ'] },
      hi: { title: '📍 लाइव GPS — बस कहाँ है, पता चलता है',
            lead: 'बस में लगा GPS हर कुछ सेकंड में अपनी जगह भेजता है। आप और घरवाले दोनों नक्शे पर देख सकते हैं।',
            points: ['<b>हर मिनट अपडेट</b> — बस कितनी दूर है, कब पहुँचेगी',
                     '<b>घर भेजें</b> — लिंक भेजने पर वे भी देख सकते हैं',
                     '<b>पिकअप पर इंतज़ार नहीं</b> — बस पास आने पर निकलें'] },
      en: { title: '📍 Live GPS — you always know where the bus is',
            lead: 'The GPS on the coach reports its position every few seconds. You and your family can both watch it on the map.',
            points: ['<b>Updated every minute</b> — how far the bus is, and when it arrives',
                     '<b>Send it home</b> — share the link and they can follow it too',
                     '<b>No waiting at the pickup</b> — leave when the bus is close'] }
    },
    charge: {
      ne: { title: '⚡ USB चार्जिङ — रातभरि फोन अन',
            lead: 'बस गुड्दै गर्दा नै बिजुली बन्छ। हरेक सिटमा USB र Type-C पोइन्ट छ, त्यसैले लामो बाटोमा पनि फोन मर्दैन।',
            points: ['<b>हरेक सिटमा आफ्नै पोइन्ट</b> — कसैसँग भाग लगाउनु पर्दैन',
                     '<b>USB र Type-C दुवै</b> — आफ्नै तार ल्याए पुग्छ',
                     '<b>रातभरि चल्छ</b> — बिहान फोन फुल चार्ज, नक्सा र टिकट दुवै हातमा'] },
      hi: { title: '⚡ USB चार्जिंग — रातभर फोन चालू',
            lead: 'बस चलते-चलते ही बिजली बनती है। हर सीट पर USB और Type-C पॉइंट है, इसलिए लंबे रास्ते में भी फोन बंद नहीं होता।',
            points: ['<b>हर सीट पर अपना पॉइंट</b> — किसी से बाँटना नहीं पड़ता',
                     '<b>USB और Type-C दोनों</b> — अपनी केबल लाएँ',
                     '<b>रातभर चालू</b> — सुबह फोन फुल, नक्शा और टिकट दोनों हाथ में'] },
      en: { title: '⚡ USB charging — your phone stays on all night',
            lead: 'The coach makes its own power as it runs. Every berth has a USB and a Type-C point, so a long night never ends with a dead phone.',
            points: ['<b>A point at every berth</b> — nothing to share',
                     '<b>USB and Type-C</b> — bring your own cable',
                     '<b>Runs all night</b> — full battery by morning, map and ticket in hand'] }
    },
    ac: {
      ne: { title: '❄️ AC स्लिपर — ओछ्यान जस्तै',
            lead: 'सिट होइन, सुत्ने बर्थ। पर्दा तान्नुहोस्, आफ्नै ठाउँ हुन्छ — सफा ब्ल्याङ्केट र सिरानीसँग।',
            points: ['<b>सफा ब्ल्याङ्केट र सिरानी</b> — हरेक यात्रापछि फेरिन्छ',
                     '<b>पर्दा तान्दा आफ्नै कोठा</b> — बत्ती र हावा आफैं मिलाउनुहोस्',
                     '<b>रातभरि AC</b> — गर्मी होस् कि जाडो, भित्र उस्तै'] },
      hi: { title: '❄️ AC स्लीपर — बिस्तर जैसा',
            lead: 'सीट नहीं, सोने की बर्थ। परदा खींचिए, अपनी जगह बन जाती है — साफ़ कंबल और तकिये के साथ।',
            points: ['<b>साफ़ कंबल और तकिया</b> — हर यात्रा के बाद बदला जाता है',
                     '<b>परदा खींचो, अपना कमरा</b> — लाइट और हवा अपने हिसाब से',
                     '<b>रातभर AC</b> — गर्मी हो या सर्दी, अंदर एक जैसा'] },
      en: { title: '❄️ AC sleeper — a bed, not a seat',
            lead: 'A berth you lie down in. Draw the curtain and the space is yours, with a clean blanket and a pillow.',
            points: ['<b>Clean blanket and pillow</b> — changed after every journey',
                     '<b>Curtain drawn, your own room</b> — your light, your air',
                     '<b>AC through the night</b> — the same inside whatever the weather'] }
    },
    safe: {
      ne: { title: '🛡️ सुरक्षित यात्रा — मान्छे सधैं छ',
            lead: 'बसमा फर्स्ट-एड बाकस, र फोनमा मान्छे। बाटोमा जे भए पनि कोही न कोही उठाउँछ।',
            points: ['<b>फर्स्ट-एड बाकस बसमै</b> — औषधी, ब्यान्डेज, आधारभूत सामान',
                     '<b>२४ घण्टा फोन</b> — रातको २ बजे पनि कार्यालयले उठाउँछ',
                     '<b>अनुभवी चालक</b> — यही बाटो, यही सिमाना, वर्षौंदेखि'] },
      hi: { title: '🛡️ सुरक्षित यात्रा — आदमी हमेशा मौजूद',
            lead: 'बस में फर्स्ट-एड बॉक्स, और फोन पर आदमी। रास्ते में कुछ भी हो, कोई न कोई उठाता है।',
            points: ['<b>फर्स्ट-एड बॉक्स बस में</b> — दवा, पट्टी, ज़रूरी सामान',
                     '<b>24 घंटे फोन</b> — रात 2 बजे भी ऑफिस उठाता है',
                     '<b>अनुभवी ड्राइवर</b> — यही रास्ता, यही बॉर्डर, सालों से'] },
      en: { title: '🛡️ Safe travel — somebody is always there',
            lead: 'A first-aid box on the coach, and a person on the phone. Whatever happens on the road, someone picks up.',
            points: ['<b>First-aid box on board</b> — medicines, bandages, the basics',
                     '<b>24-hour phone</b> — the office answers at 2am too',
                     '<b>Drivers who know the road</b> — this route, this border, for years'] }
    }
  };

  /* ---- the scenes ------------------------------------------------
     One SVG each, 300x210, drawn so that every element is meaningful
     at rest: a phone that asks for reduced motion still sees a globe,
     a wheel, a berth and a first-aid box. */
  var SCENES = {
    gps: '<svg viewBox="0 0 300 210" role="img" aria-label="Live GPS tracking">'
      + '<defs><radialGradient id="fsGlow" cx="50%" cy="45%" r="55%">'
      +   '<stop offset="0%" stop-color="#2E6FCF" stop-opacity=".95"/>'
      +   '<stop offset="70%" stop-color="#123A78" stop-opacity=".9"/>'
      +   '<stop offset="100%" stop-color="#071633" stop-opacity="1"/></radialGradient>'
      + '<clipPath id="fsBall"><circle cx="150" cy="105" r="62"/></clipPath></defs>'
      /* stars */
      + '<circle class="fs-star" cx="36" cy="30" r="1.4" fill="#fff"/><circle class="fs-star" cx="264" cy="44" r="1.2" fill="#fff"/>'
      + '<circle class="fs-star" cx="52" cy="174" r="1.1" fill="#fff"/><circle class="fs-star" cx="252" cy="160" r="1.5" fill="#fff"/>'
      /* the globe */
      + '<circle cx="150" cy="105" r="62" class="fs-glow"/>'
      + '<g clip-path="url(#fsBall)"><g class="fs-globe">'
      +   '<ellipse class="fs-grid" cx="150" cy="105" rx="62" ry="62"/>'
      +   '<ellipse class="fs-grid" cx="150" cy="105" rx="22" ry="62"/>'
      +   '<ellipse class="fs-grid" cx="150" cy="105" rx="44" ry="62"/>'
      +   '<line class="fs-grid" x1="88" y1="105" x2="212" y2="105"/>'
      +   '<ellipse class="fs-grid" cx="150" cy="105" rx="62" ry="30"/>'
      /* a suggestion of land — the subcontinent, lit */
      /* Asia, roughly, with the subcontinent picked out in gold and the
         Himalaya where the two countries meet. Not a survey map - a
         recognisable one, which is what the owner asked for: the shape
         a person knows, not four blobs. */
      +   '<path class="fs-land" d="M92 66q22-12 48-8t42 6 34-2 20 8-6 16-22 6-18-2-14 4-16 0-20-6-18 2-14-6-4-14z"/>'
      +   '<path class="fs-land" d="M96 96q14-6 26 0t14 14-6 16-18 6-18-8-6-16z"/>'
      +   '<path class="fs-land-in" d="M152 84q16-4 27 5t3 22l-9 18-8 22-9-20-10-16-6-18z"/>'
      +   '<path class="fs-land" d="M186 118q12-2 18 6t-4 16-16 2-6-12z"/>'
      +   '<path d="M150 82q14-5 26-1t18 7" fill="none" stroke="#FFF3D6" stroke-width="1.6" stroke-linecap="round" opacity=".9"/>'
      + '</g></g>'
      + '<circle cx="150" cy="105" r="62" fill="none" stroke="rgba(150,210,255,.5)" stroke-width="1.5"/>'
      /* the satellite and its beam down to the marker */
      + '<g class="fs-sat"><g transform="translate(150,26)">'
      +   '<rect x="-7" y="-4" width="14" height="8" rx="2" fill="#D9E6FF"/>'
      +   '<rect x="-16" y="-2.5" width="8" height="5" rx="1" fill="#FFB547"/>'
      +   '<rect x="8" y="-2.5" width="8" height="5" rx="1" fill="#FFB547"/>'
      + '</g></g>'
      + '<line class="fs-beam" x1="150" y1="40" x2="172" y2="92"/>'
      /* the marker on the route, pinging */
      + '<g transform="translate(172,92)">'
      +   '<circle class="fs-ping" r="7" fill="none" stroke="#FFC978" stroke-width="2"/>'
      +   '<circle class="fs-ping fs-ping2" r="7" fill="none" stroke="#FFC978" stroke-width="2"/>'
      +   '<circle class="fs-ping fs-ping3" r="7" fill="none" stroke="#FFC978" stroke-width="2"/>'
      +   '<circle r="4.5" fill="#FF8A2B" stroke="#fff" stroke-width="1.6"/>'
      + '</g>'
      /* the live badge */
      + '<g transform="translate(18,18)">'
      +   '<rect width="62" height="20" rx="10" fill="rgba(255,60,60,.22)" stroke="rgba(255,120,120,.6)"/>'
      +   '<circle class="fs-live" cx="13" cy="10" r="4" fill="#FF4D4D"/>'
      +   '<text x="24" y="14" fill="#FFD9D9" font-size="10" font-weight="700" font-family="system-ui,sans-serif">LIVE</text>'
      + '</g>'
      + '</svg>',

    charge: '<svg viewBox="0 0 300 210" role="img" aria-label="USB charging from the coach">'
      + '<defs><linearGradient id="fsNight" x1="0" y1="0" x2="0" y2="1">'
      +   '<stop offset="0%" stop-color="#050D24"/><stop offset="100%" stop-color="#0B1E44"/></linearGradient></defs>'
      + '<rect width="300" height="210" fill="url(#fsNight)"/>'
      + '<circle class="fs-star" cx="40" cy="26" r="1.3" fill="#fff"/><circle class="fs-star" cx="120" cy="18" r="1" fill="#fff"/>'
      + '<circle class="fs-star" cx="210" cy="30" r="1.4" fill="#fff"/><circle class="fs-star" cx="268" cy="20" r="1.1" fill="#fff"/>'
      + '<circle cx="246" cy="40" r="15" fill="#F2F6FF" opacity=".85"/><circle cx="240" cy="36" r="15" fill="#0A1A3C"/>'
      /* the road */
      + '<rect x="0" y="168" width="300" height="42" fill="#12203F"/>'
      + '<g class="fs-road-dash"><rect x="0" y="186" width="324" height="3" fill="none"/>'
      +   '<rect x="0" y="186" width="14" height="3" rx="1.5" fill="#F6C744"/><rect x="24" y="186" width="14" height="3" rx="1.5" fill="#F6C744"/>'
      +   '<rect x="48" y="186" width="14" height="3" rx="1.5" fill="#F6C744"/><rect x="72" y="186" width="14" height="3" rx="1.5" fill="#F6C744"/>'
      +   '<rect x="96" y="186" width="14" height="3" rx="1.5" fill="#F6C744"/><rect x="120" y="186" width="14" height="3" rx="1.5" fill="#F6C744"/>'
      +   '<rect x="144" y="186" width="14" height="3" rx="1.5" fill="#F6C744"/><rect x="168" y="186" width="14" height="3" rx="1.5" fill="#F6C744"/>'
      +   '<rect x="192" y="186" width="14" height="3" rx="1.5" fill="#F6C744"/><rect x="216" y="186" width="14" height="3" rx="1.5" fill="#F6C744"/>'
      +   '<rect x="240" y="186" width="14" height="3" rx="1.5" fill="#F6C744"/><rect x="264" y="186" width="14" height="3" rx="1.5" fill="#F6C744"/>'
      + '</g>'
      /* the coach body, cut off at the left: we are looking at its rear wheel */
      + '<rect x="18" y="88" width="128" height="58" rx="11" fill="#EDF3FF"/>'
      + '<rect x="18" y="88" width="128" height="15" rx="11" fill="#123A78"/>'
      + '<rect x="28" y="109" width="24" height="17" rx="3" fill="#9CC4F5" opacity=".9"/>'
      + '<rect x="58" y="109" width="24" height="17" rx="3" fill="#9CC4F5" opacity=".9"/>'
      + '<rect x="88" y="109" width="24" height="17" rx="3" fill="#9CC4F5" opacity=".9"/>'
      + '<rect x="18" y="134" width="128" height="5" fill="#F07800"/>' + '<rect x="118" y="109" width="24" height="17" rx="3" fill="#9CC4F5" opacity=".9"/>'
      /* the wheel that makes the power */
      + '<circle cx="128" cy="150" r="9" fill="#0B1428" stroke="#34507F" stroke-width="2.5"/>' + '<circle cx="64" cy="150" r="19" fill="#0B1428" stroke="#3C5A90" stroke-width="4"/>'
      /* Six spokes drawn as one path from the hub. The first version
         used four rects, two of them carrying rotate(45 64 150) - and a
         rect rotated about a point outside itself lands nowhere near
         where it reads in the source. One path, one transform, no
         guessing. */
      + '<g class="fs-wheel">'
      +   '<path d="M64 132v36M48.4 141v18M79.6 141v18" stroke="#5C82C4" stroke-width="2.4" stroke-linecap="round" transform="rotate(0 64 150)"/>'
      +   '<path d="M64 132v36" stroke="#5C82C4" stroke-width="2.4" stroke-linecap="round" transform="rotate(60 64 150)"/>'
      +   '<path d="M64 132v36" stroke="#5C82C4" stroke-width="2.4" stroke-linecap="round" transform="rotate(120 64 150)"/>'
      +   '<circle cx="64" cy="150" r="7" fill="#2B4A82"/>'
      + '</g>'
      + '<g class="fs-spark"><path d="M84 142l7-10-3 9 6-1-9 11 3-9z" fill="#FFD24D"/></g>'
      /* the current running from the wheel to the phone */
      + '<path class="fs-flow" d="M86 148q46 4 62-22t44-18" fill="none" stroke="#FFC24D" stroke-width="2.5" stroke-linecap="round"/>'
      /* the phone, filling */
      + '<g transform="translate(206,66)">'
      +   '<rect x="0" y="0" width="52" height="92" rx="9" fill="#0E1C38" stroke="#5B7CB5" stroke-width="2"/>'
      +   '<rect x="5" y="7" width="42" height="78" rx="5" fill="#07132B"/>'
      +   '<rect x="12" y="24" width="28" height="44" rx="4" fill="none" stroke="#7FE7A8" stroke-width="2"/>'
      +   '<rect x="21" y="20" width="10" height="4" rx="2" fill="#7FE7A8"/>'
      +   '<rect class="fs-batt-fill" x="14" y="26" width="24" height="40" rx="2" fill="#4BD07E"/>'
      +   '<g class="fs-bolt"><path d="M28 34l-8 13h6l-3 11 9-14h-6z" fill="#08331C"/></g>'
      + '</g>'
      + '<text x="232" y="176" fill="#BBD2FF" font-size="11" font-weight="700" text-anchor="middle" font-family="system-ui,sans-serif">USB · Type-C</text>'
      + '</svg>',

    ac: '<svg viewBox="0 0 300 210" role="img" aria-label="AC sleeper berth">'
      + '<defs><linearGradient id="fsWarm" x1="0" y1="0" x2="0" y2="1">'
      +   '<stop offset="0%" stop-color="#0A1B3C"/><stop offset="100%" stop-color="#132A52"/></linearGradient>'
      + '<linearGradient id="fsBlanket" x1="0" y1="0" x2="1" y2="1">'
      +   '<stop offset="0%" stop-color="#2E6FCF"/><stop offset="100%" stop-color="#1B4A95"/></linearGradient></defs>'
      + '<rect width="300" height="210" fill="url(#fsWarm)"/>'
      /* cool air drifting down */
      + '<g fill="#9BD8FF" opacity=".75">'
      +   '<text class="fs-flake" x="42" y="0" font-size="11">❄</text>'
      +   '<text class="fs-flake" x="96" y="0" font-size="9">❄</text>'
      +   '<text class="fs-flake" x="168" y="0" font-size="12">❄</text>'
      +   '<text class="fs-flake" x="232" y="0" font-size="9">❄</text>'
      +   '<text class="fs-flake" x="266" y="0" font-size="11">❄</text>'
      + '</g>'
      /* the berth shell */
      + '<rect x="28" y="56" width="244" height="112" rx="14" fill="#0B1C3E" stroke="#32507F" stroke-width="2"/>'
      + '<rect x="28" y="56" width="244" height="14" rx="7" fill="#16305C"/>'
      /* reading light */
      + '<circle cx="62" cy="76" r="5" fill="#FFD89B"/>'
      + '<path d="M62 81l-14 26h28z" fill="#FFD89B" opacity=".18"/>'
      /* mattress, pillow, blanket, sleeper */
      + '<rect x="40" y="128" width="220" height="30" rx="8" fill="#DFE8F8"/>'
      + '<rect x="48" y="112" width="44" height="22" rx="9" fill="#F4F8FF"/>'
      + '<g class="fs-breathe">'
      +   '<path d="M92 132q34-16 74-12t82 12v14q-46-8-82-6t-74 6z" fill="url(#fsBlanket)"/>'
      +   '<circle cx="104" cy="120" r="12" fill="#F0C9A4"/>'
      +   '<path d="M92 118q6-14 20-12t14 12z" fill="#2B3A55"/>'
      + '</g>'
      /* the curtain, half drawn — privacy */
      + '<g class="fs-curtain">'
      +   '<rect x="224" y="56" width="48" height="112" rx="10" fill="#0E2854" opacity=".96"/>'
      +   '<path d="M232 60v104M244 60v104M256 60v104" stroke="#2A4A85" stroke-width="2"/>'
      + '</g>'
      /* sleeping */
      + '<g fill="#BBD9FF" font-family="system-ui,sans-serif" font-weight="700">'
      +   '<text class="fs-zzz" x="120" y="108" font-size="13">z</text>'
      +   '<text class="fs-zzz" x="120" y="108" font-size="11">z</text>'
      +   '<text class="fs-zzz" x="120" y="108" font-size="15">z</text>'
      + '</g>'
      + '<text x="150" y="186" fill="#BBD2FF" font-size="11" font-weight="700" text-anchor="middle" font-family="system-ui,sans-serif">AC · पर्दा · ब्ल्याङ्केट</text>'
      + '</svg>',

    safe: '<svg viewBox="0 0 300 210" role="img" aria-label="First aid and 24 hour help">'
      + '<defs><linearGradient id="fsSafe" x1="0" y1="0" x2="0" y2="1">'
      +   '<stop offset="0%" stop-color="#07182F"/><stop offset="100%" stop-color="#0C2A4E"/></linearGradient></defs>'
      + '<rect width="300" height="210" fill="url(#fsSafe)"/>'
      /* the shield, steady behind everything */
      + '<g transform="translate(150,96)">'
      +   '<path class="fs-shield-glow" d="M0-62l50 20v34C50 22 28 46 0 62-28 46-50 22-50-8v-34z" fill="#1F7A4D" opacity=".35"/>'
      +   '<path d="M0-54l42 17v29C42 18 24 38 0 52-24 38-42 18-42-8v-29z" fill="none" stroke="#4BD07E" stroke-width="2"/>'
      + '</g>'
      /* the first-aid box, lid opening */
      + '<g transform="translate(0,0)">'
      +   '<rect x="104" y="108" width="92" height="56" rx="8" fill="#F2F6FF"/>'
      +   '<rect x="104" y="108" width="92" height="10" fill="#D8E4F7"/>'
      +   '<g class="fs-lid"><rect x="104" y="92" width="92" height="18" rx="6" fill="#E53946"/>'
      +     '<rect x="140" y="96" width="20" height="4" rx="2" fill="#B31F2B"/></g>'
      +   '<g class="fs-cross"><rect x="141" y="124" width="18" height="6" rx="1.5" fill="#E53946"/>'
      +     '<rect x="147" y="118" width="6" height="18" rx="1.5" fill="#E53946"/></g>'
      +   '<g class="fs-pill"><rect x="112" y="142" width="16" height="7" rx="3.5" fill="#6FA8F5"/></g>'
      +   '<g class="fs-pill"><rect x="172" y="144" width="14" height="6" rx="3" fill="#F5C36F"/></g>'
      + '</g>'
      /* the phone that is always answered */
      + '<g transform="translate(246,58)">'
      +   '<circle class="fs-ring" r="16" fill="none" stroke="#4BD07E" stroke-width="2"/>'
      +   '<circle class="fs-ring fs-ring2" r="16" fill="none" stroke="#4BD07E" stroke-width="2"/>'
      +   '<circle r="18" fill="#12472E" stroke="#4BD07E" stroke-width="2"/>'
      +   '<path d="M-7-5a16 16 0 0 0 12 12l3-4 6 3-2 6a20 20 0 0 1-22-22l6-2 3 6z" fill="#8CF3B4"/>'
      + '</g>'
      + '<g transform="translate(20,52)">'
      +   '<rect width="66" height="24" rx="12" fill="rgba(75,208,126,.16)" stroke="#4BD07E"/>'
      +   '<text x="33" y="16" fill="#9CF0BE" font-size="11" font-weight="800" text-anchor="middle" font-family="system-ui,sans-serif">24 × 7</text>'
      + '</g>'
      + '<text x="150" y="188" fill="#BBD2FF" font-size="11" font-weight="700" text-anchor="middle" font-family="system-ui,sans-serif">First aid · 24 घण्टा फोन</text>'
      + '</svg>'
  };

  var ORDER = ['ac', 'gps', 'charge', 'safe'];

  /* Each facility has its own voice in the app's sound engine (see the
     VOICES table in 19-premium.js): a radar ping for GPS, a current
     surge for charging, a warm fifth for the berth, a calm two-note
     confirmation for safety. Four facilities should not sound like one
     thing happening four times. The menu's Sound switch silences them
     with everything else. */
  var SOUND = { gps: 'storyGps', charge: 'storyCharge', ac: 'storyAc', safe: 'storySafe' };

  /* ---- stylesheet ------------------------------------------------ */
  if (!doc.querySelector('link[href*="feature-story.css"]')) {
    var link = doc.createElement('link');
    link.rel = 'stylesheet';
    link.href = base + 'css/feature-story.css' + stamp;
    doc.head.appendChild(link);
  }

  function lang() {
    try {
      var l = (typeof LANG === 'string' && LANG) || doc.documentElement.lang || 'ne';
      return COPY.gps[l] ? l : (COPY.gps[String(l).slice(0, 2)] ? String(l).slice(0, 2) : 'en');
    } catch (e) { return 'ne'; }
  }

  function feel(kind) {
    try { if (window.SHGFeel) { window.SHGFeel.fire(kind); } } catch (e) {}
  }

  var wrap = null, current = '';

  function close() {
    if (!wrap) { return; }
    var w = wrap;
    wrap = null;
    current = '';
    doc.removeEventListener('keydown', onKey);
    /* Remove it, do not hide it: a hidden panel keeps every animation
       in this file running for as long as the tab is open. */
    if (w.parentNode) { w.parentNode.removeChild(w); }
    try { doc.body.style.overflow = ''; } catch (e) {}
  }

  function onKey(e) {
    if (e.key === 'Escape') { close(); }
  }

  function render(key) {
    var copy = (COPY[key] || {})[lang()] || COPY[key].en;
    var idx = ORDER.indexOf(key);
    var dots = ORDER.map(function (k, i) {
      return '<button type="button" class="fs-dot' + (i === idx ? ' on' : '') + '" data-go="' + k + '" aria-label="' + k + '"></button>';
    }).join('');

    return '<div class="fs-card" role="dialog" aria-modal="true" aria-label="' + String(copy.title).replace(/"/g, '') + '">'
      + '<button type="button" class="fs-close" aria-label="Close">&times;</button>'
      + '<div class="fs-stage">' + SCENES[key] + '</div>'
      + '<div class="fs-body">'
      +   '<h3 class="fs-title">' + copy.title + '</h3>'
      +   '<p class="fs-lead">' + copy.lead + '</p>'
      +   '<ul class="fs-points">' + copy.points.map(function (p) { return '<li><span aria-hidden="true">✓</span><span>' + p + '</span></li>'; }).join('') + '</ul>'
      +   '<div class="fs-nav">' + dots + '</div>'
      + '</div></div>';
  }

  function open(key) {
    if (!SCENES[key]) { return; }
    if (wrap) {
      /* already open — swap the scene without rebuilding the backdrop */
      current = key;
      wrap.innerHTML = render(key);
      feel(SOUND[key] || 'select');
      return;
    }
    current = key;
    wrap = doc.createElement('div');
    wrap.className = 'fs-wrap';
    wrap.innerHTML = render(key);
    wrap.addEventListener('click', function (e) {
      if (e.target === wrap || e.target.closest('.fs-close')) { close(); feel('tap'); return; }
      var go = e.target.closest('[data-go]');
      if (go) { open(go.getAttribute('data-go')); }
    });
    doc.body.appendChild(wrap);
    try { doc.body.style.overflow = 'hidden'; } catch (e) {}
    doc.addEventListener('keydown', onKey);
    var c = wrap.querySelector('.fs-close');
    if (c && c.focus) { try { c.focus(); } catch (e) {} }
    feel(SOUND[key] || 'notify');
  }

  /* ---- turn the four chips into buttons -------------------------- */
  function upgrade() {
    var list = doc.querySelectorAll('.bb-feats > li');
    if (!list.length || list[0].querySelector('.bb-open')) { return; }
    for (var i = 0; i < list.length && i < ORDER.length; i++) {
      (function (li, key) {
        var btn = doc.createElement('button');
        btn.type = 'button';
        btn.className = 'bb-open';
        /* Move the existing icon + label inside the button rather than
           rewriting them, so data-i18n keeps working and applyLang()
           still translates the chip on a language switch. */
        while (li.firstChild) { btn.appendChild(li.firstChild); }
        li.appendChild(btn);
        btn.addEventListener('click', function () { open(key); });
      })(list[i], ORDER[i]);
    }
  }

  if (doc.readyState === 'loading') {
    doc.addEventListener('DOMContentLoaded', upgrade);
  } else {
    upgrade();
  }

  window.SHG_FEATURE_STORY = { open: open, close: close, upgrade: upgrade };
})();
