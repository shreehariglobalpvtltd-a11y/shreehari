/* =====================================================================
   SHARI NAV · assets/nav/beta/nav-live.js  (22 Oct 2026, Phase 2 "live" - BETA copy, navigate-beta.html only)

   Add-on for navigate.html, through window.SHGNAV_X (the page's bridge).
     A · Live trip share  - "share trip progress": a 128-bit follow link,
                            the phone posts its position every 3 s while
                            navigating; ?follow=<token> = the live view
     B · S Hari coaches   - every coach on the road now (the real coach
                            photo), next stop + minutes, Book / Track
     C · Road reports     - accident, police, jam, landslide, border queue
                            ...: one tap or by voice, 👍 / 👎, shown within
                            30 km, spoken 1 km ahead on the route
   Push: wss://<host>/live/ (live/shg-live.php); when a network blocks
   WebSockets, /api/nav-live.php long-poll. Without the page's hook()
   (older pages) A and the route alerts are off, B and C still show.
===================================================================== */
(function () {
'use strict';

var X = null;
var QS = new URLSearchParams(location.search);

/* ---------------------------------------------------------------------
   TEXT (follows the page language: ne | hi | en)
--------------------------------------------------------------------- */
var TX = {
  liveH: { ne: 'लाइभ', hi: 'लाइव', en: 'Live' },
  shareTrip: { ne: 'लाइभ यात्रा सेयर गर्नुस्', hi: 'लाइव यात्रा शेयर करें', en: 'Share live trip' },
  sharing: { ne: 'यात्रा लाइभ सेयर हुँदैछ', hi: 'यात्रा लाइव शेयर हो रही है', en: 'Sharing your trip live' },
  shareOn: { ne: 'यो link भएका जो कोहीले तपाईं कहाँ पुग्नुभयो र कति बजे पुग्नुहुन्छ देख्न सक्छन्। पुगेपछि वा ४ घण्टामा आफैँ बन्द हुन्छ।',
             hi: 'इस link वाला कोई भी देख सकता है कि आप कहाँ पहुँचे और कब पहुँचेंगे। पहुँचने पर या 4 घंटे में अपने-आप बंद।',
             en: 'Anyone with this link sees where you are and when you arrive. It stops by itself on arrival or after 4 hours.' },
  shareMsg: { ne: 'मेरो यात्रा लाइभ हेर्नुहोस् → {d} · पुग्ने समय {e}', hi: 'मेरी यात्रा लाइव देखें → {d} · पहुँचने का समय {e}', en: 'Follow my trip live → {d} · arriving {e}' },
  wa: { ne: 'WhatsApp', hi: 'WhatsApp', en: 'WhatsApp' },
  more: { ne: 'अरू एप', hi: 'अन्य ऐप', en: 'More apps' },
  copy: { ne: 'Copy', hi: 'Copy', en: 'Copy' },
  copied: { ne: 'Link copy भयो', hi: 'Link copy हो गया', en: 'Link copied' },
  stop: { ne: 'सेयर बन्द', hi: 'शेयर बंद करें', en: 'Stop sharing' },
  stopped: { ne: 'लाइभ सेयर बन्द भयो', hi: 'लाइव शेयर बंद हुआ', en: 'Live sharing stopped' },
  needNav: { ne: 'पहिले नेभिगेसन सुरु गर्नुस्', hi: 'पहले नेविगेशन शुरू करें', en: 'Start navigation first' },
  failed: { ne: 'अहिले सकिएन — फेरि प्रयास गर्नुस्', hi: 'अभी नहीं हो पाया — फिर कोशिश करें', en: 'Could not do that now — try again' },
  fLive: { ne: 'लाइभ यात्रा', hi: 'लाइव यात्रा', en: 'Live trip' },
  fArr: { ne: 'पुग्ने', hi: 'पहुँचेंगे', en: 'arrives' },
  fLeft: { ne: 'बाँकी', hi: 'बाकी', en: 'left' },
  fAgo: { ne: '{n} अघि अपडेट', hi: '{n} पहले अपडेट', en: 'updated {n} ago' },
  fWeak: { ne: 'सिग्नल कमजोर — अन्तिम थाहा भएको ठाउँ', hi: 'सिग्नल कमज़ोर — आख़िरी पता जगह', en: 'Weak signal — last known place' },
  fDone: { ne: '{d} मा पुग्नुभयो ✓', hi: '{d} पहुँच गए ✓', en: 'Arrived at {d} ✓' },
  fEnd: { ne: 'यो यात्रा अब सेयर भइरहेको छैन', hi: 'यह यात्रा अब शेयर नहीं हो रही', en: 'This trip is no longer shared' },
  fWait: { ne: 'यात्रा खोज्दैछु…', hi: 'यात्रा खोज रहा हूँ…', en: 'Finding the trip…' },
  fCenter: { ne: 'केन्द्रमा', hi: 'बीच में', en: 'Re-center' },
  fOpen: { ne: 'SHARI NAV खोल्नुस्', hi: 'SHARI NAV खोलें', en: 'Open SHARI NAV' },
  notifyMe: { ne: '🔔 पुग्दा सूचना पाउनुस्', hi: '🔔 पहुँचने पर सूचना पाएं', en: '🔔 Notify me on arrival' },
  notifyOn: { ne: 'पुग्ने बित्तिकै यो फोनमा सूचना आउनेछ', hi: 'पहुँचते ही इस फ़ोन पर सूचना आएगी', en: 'This phone will be notified on arrival' },
  notifyOff: { ne: '🔔 सूचना चालु', hi: '🔔 सूचना चालू', en: '🔔 Notification on' },
  notifyNo: { ne: 'यो फोन / ब्राउजरमा सूचना मिलेन — अनुमति हेर्नुस्', hi: 'इस फ़ोन / ब्राउज़र पर सूचना नहीं मिली — अनुमति देखें', en: 'Notifications are not available here — check the permission' },
  arrivedT: { ne: '{d} मा पुग्नुभयो ✓', hi: '{d} पहुँच गए ✓', en: 'Arrived at {d} ✓' },
  arrivedP: { ne: 'लाइभ सेयर बन्द भयो। तपाईंलाई पर्खिरहेकालाई खबर पठाउनुस्:', hi: 'लाइव शेयर बंद हो गया। इंतज़ार करने वालों को खबर भेजें:', en: 'Live sharing has stopped. Tell the people waiting for you:' },
  arrivedMsg: { ne: 'म {d} मा सुरक्षित पुगें ✓ (SHARI NAV)', hi: 'मैं {d} सुरक्षित पहुँच गया ✓ (SHARI NAV)', en: 'I reached {d} safely ✓ (SHARI NAV)' },
  sec: { ne: 'सेकेन्ड', hi: 'सेकंड', en: 's' }, min: { ne: 'मिनेट', hi: 'मिनट', en: 'min' },
  busL: { ne: 'S Hari बस (लाइभ)', hi: 'S Hari बस (लाइव)', en: 'S Hari coaches (live)' },
  repL: { ne: 'बाटोका रिपोर्ट', hi: 'रास्ते की रिपोर्ट', en: 'Road reports' },
  busNext: { ne: 'अर्को स्टप', hi: 'अगला स्टॉप', en: 'Next stop' },
  busBook: { ne: 'सिट बुक गर्नुस्', hi: 'सीट बुक करें', en: 'Book a seat' },
  busTrack: { ne: 'मेरो बस ट्र्याक', hi: 'मेरी बस ट्रैक', en: 'Track my bus' },
  busAge: { ne: '{n} अघिको स्थान', hi: '{n} पहले की जगह', en: 'position {n} old' },
  report: { ne: 'बाटोको रिपोर्ट', hi: 'रास्ते की रिपोर्ट', en: 'Report on the road' },
  repQ: { ne: 'बाटोमा के छ?', hi: 'रास्ते में क्या है?', en: 'What is on the road?' },
  repVoice: { ne: 'बोलेर भन्नुस्', hi: 'बोलकर बताएं', en: 'Say it' },
  repThanks: { ne: 'धन्यवाद! अरू यात्रुले पनि देख्नेछन्', hi: 'धन्यवाद! दूसरे यात्री भी देखेंगे', en: 'Thanks! Other riders will see it' },
  repLoc: { ne: 'रिपोर्ट गर्न तपाईंको लोकेसन चाहिन्छ', hi: 'रिपोर्ट के लिए आपकी लोकेशन चाहिए', en: 'Your location is needed to report' },
  repMany: { ne: 'एक घण्टामा १० रिपोर्ट मात्र', hi: 'एक घंटे में केवल 10 रिपोर्ट', en: 'Only 10 reports an hour' },
  repStill: { ne: 'अझै छ', hi: 'अभी भी है', en: 'Still there' },
  repGone: { ne: 'छैन', hi: 'नहीं है', en: 'Not there' },
  repVoted: { ne: 'जवाफको लागि धन्यवाद', hi: 'जवाब के लिए धन्यवाद', en: 'Thanks for answering' },
  repAgo: { ne: '{n} अघि रिपोर्ट', hi: '{n} पहले रिपोर्ट', en: 'reported {n} ago' },
  repSure: { ne: '{k} रिपोर्ट गर्ने?', hi: '{k} रिपोर्ट करें?', en: 'Report {k}?' },
  yes: { ne: 'हो, पठाउनुस्', hi: 'हाँ, भेजें', en: 'Yes, send' },
  noHeard: { ne: 'बुझिएन — तल छान्नुस्', hi: 'समझ नहीं आया — नीचे चुनें', en: 'Did not catch that — pick below' },
  listen: { ne: 'सुन्दैछु…', hi: 'सुन रहा हूँ…', en: 'Listening…' },
  ahead: { ne: 'सावधान! करिब {d} अगाडि {k} रिपोर्ट गरिएको छ', hi: 'सावधान! लगभग {d} आगे {k} की रिपोर्ट है', en: 'Caution: {k} reported about {d} ahead' },
  km1: { ne: 'एक किलोमिटर', hi: 'एक किलोमीटर', en: '1 km' }, mtr: { ne: 'मिटर', hi: 'मीटर', en: 'm' },
  close: { ne: 'बन्द', hi: 'बंद', en: 'Close' },
  syncT: { ne: 'फोन र कम्प्युटरमा sync', hi: 'फ़ोन और कंप्यूटर में sync', en: 'Sync phone and computer' },
  syncWhat: { ne: 'Saved ठाउँ, घर / काम, यात्रा, पार्किङ र भ्रमण — तपाईंका सबै फोन र कम्प्युटरमा एउटै।', hi: 'Saved जगहें, घर / काम, यात्राएं, पार्किंग और विज़िट — आपके सभी फ़ोन और कंप्यूटर में एक जैसे।', en: 'Saved places, home / work, trips, parking and visits — the same on all your phones and computers.' },
  syncLogin: { ne: 'WhatsApp मा आउने OTP बाट login गर्नुस्', hi: 'WhatsApp पर आने वाले OTP से login करें', en: 'Log in with the OTP sent on WhatsApp' },
  syncOn: { ne: 'Sync चालु', hi: 'Sync चालू', en: 'Sync is on' },
  syncLast: { ne: 'अन्तिम sync {n}', hi: 'आख़िरी sync {n}', en: 'last synced {n}' },
  syncNow: { ne: 'अहिले sync गर्नुस्', hi: 'अभी sync करें', en: 'Sync now' },
  synced: { ne: 'Sync भयो ✓', hi: 'Sync हो गया ✓', en: 'Synced ✓' },
  syncNever: { ne: 'अहिलेसम्म भएको छैन', hi: 'अभी तक नहीं', en: 'not yet' },
  phone: { ne: 'मोबाइल नम्बर', hi: 'मोबाइल नंबर', en: 'Mobile number' },
  sendOtp: { ne: 'WhatsApp मा OTP पठाउनुस्', hi: 'WhatsApp पर OTP भेजें', en: 'Send OTP on WhatsApp' },
  otpSent: { ne: '{p} मा WhatsApp code पठाइयो', hi: '{p} पर WhatsApp code भेजा गया', en: 'WhatsApp code sent to {p}' },
  otpCode: { ne: 'Code', hi: 'Code', en: 'Code' },
  verify: { ne: 'Login गर्नुस्', hi: 'Login करें', en: 'Log in' },
  otpFail: { ne: 'अहिले code पठाउन सकिएन — केही बेरपछि प्रयास गर्नुस्', hi: 'अभी code नहीं भेज पाए — थोड़ी देर बाद कोशिश करें', en: 'Could not send the code now — try again shortly' },
  badPhone: { ne: '१० अङ्कको मोबाइल नम्बर लेख्नुस्', hi: '10 अंकों का मोबाइल नंबर लिखें', en: 'Enter a 10-digit mobile number' },
  inIndia: { ne: 'भारत +91', hi: 'भारत +91', en: 'India +91' }, inNepal: { ne: 'नेपाल +977', hi: 'नेपाल +977', en: 'Nepal +977' },
  myBusT: { ne: 'मेरो बस', hi: 'मेरी बस', en: 'My bus' },
  pnrPh: { ne: 'PNR (जस्तै SHG-2026-123)', hi: 'PNR (जैसे SHG-2026-123)', en: 'PNR (e.g. SHG-2026-123)' },
  pnrGo: { ne: 'बस देखाउनुस्', hi: 'बस दिखाएं', en: 'Show my bus' },
  pnrNo: { ne: 'यो PNR भेटिएन वा तपाईंको login सँग जोडिएको छैन। Ticket बुक गरेको नम्बरबाट OTP login गर्नुस् वा WhatsApp को track link खोल्नुस्।',
           hi: 'यह PNR नहीं मिला या आपके login से जुड़ा नहीं है। Ticket वाले नंबर से OTP login करें या WhatsApp का track link खोलें।',
           en: 'This PNR was not found or is not linked to your login. Log in with the number that booked it, or open the track link from WhatsApp.' },
  pnrLive: { ne: 'लाइभ', hi: 'लाइव', en: 'Live' },
  pnrStop: { ne: 'तपाईंको स्टप {s} सम्म', hi: 'आपके स्टॉप {s} तक', en: 'to your stop {s}' },
  pnrLast: { ne: 'अन्तिम थाहा भएको ठाउँ · {n} अघि', hi: 'आख़िरी पता जगह · {n} पहले', en: 'last known place · {n} ago' },
  pnrOff: { ne: 'बन्द गर्नुस्', hi: 'बंद करें', en: 'Stop' },
  pnrYour: { ne: 'तपाईंको स्टप', hi: 'आपका स्टॉप', en: 'Your stop' },
  trackPage: { ne: 'पूरा ट्र्याक', hi: 'पूरा ट्रैक', en: 'Full tracking' }
};
/* the owner's list; minutes each lives = NavLive::KINDS on the server */
var KINDS = [
  ['accident', '💥', { ne: 'दुर्घटना', hi: 'दुर्घटना', en: 'Accident' }, /दुर्घटना|एक्सिडेन्ट|एक्सीडेंट|accident|ठोकिय|टक्कर/i],
  ['police', '👮', { ne: 'प्रहरी जाँच', hi: 'पुलिस चेकिंग', en: 'Police' }, /प्रहरी|पुलिस|police|चेकिङ|चेकिंग|checking/i],
  ['jam', '🚗', { ne: 'जाम', hi: 'जाम', en: 'Traffic jam' }, /जाम|ट्राफिक|ट्रैफिक|jam|traffic/i],
  ['roadwork', '🚧', { ne: 'सडक निर्माण', hi: 'सड़क निर्माण', en: 'Road work' }, /निर्माण|मर्मत|काम भइरहेको|road ?work|construction/i],
  ['closed', '⛔', { ne: 'बाटो बन्द', hi: 'रास्ता बंद', en: 'Road closed' }, /बाटो बन्द|सडक बन्द|रास्ता बंद|सड़क बंद|closed|बन्द छ|बंद है/i],
  ['flood', '🌊', { ne: 'बाढी', hi: 'बाढ़', en: 'Flood' }, /बाढी|बाढ़|पानी भरि|flood/i],
  ['landslide', '⛰️', { ne: 'पहिरो', hi: 'भूस्खलन', en: 'Landslide' }, /पहिरो|भूस्खलन|landslide|पहाड़ गिर/i],
  ['pothole', '🕳️', { ne: 'खाल्डो', hi: 'गड्ढा', en: 'Pothole' }, /खाल्डो|खाडल|गड्ढा|pothole/i],
  ['animal', '🐄', { ne: 'बाटोमा जनावर', hi: 'सड़क पर जानवर', en: 'Animal on road' }, /जनावर|गाई|गोरु|भैंसी|जानवर|गाय|animal|cow/i],
  ['fog', '🌫️', { ne: 'हुस्सु', hi: 'कोहरा', en: 'Fog' }, /हुस्सु|कुहिरो|कोहरा|धुंध|fog/i],
  ['border', '🛂', { ne: 'नाकामा लाइन', hi: 'बॉर्डर पर लाइन', en: 'Border queue' }, /नाका|बोर्डर|बॉर्डर|border|भन्सार/i]
];
var KIND = {}; KINDS.forEach(function (k) { KIND[k[0]] = k; });

/* ---------------------------------------------------------------------
   HELPERS
--------------------------------------------------------------------- */
function $(s, r) { return (r || document).querySelector(s); }
function lang() { var l = X && X.S.lang; return l === 'hi' || l === 'en' ? l : 'ne'; }
function L(o) { return o ? (o[lang()] || o.en || '') : ''; }
function t(k, v) {
  var s = L(TX[k]) || k;
  if (v) Object.keys(v).forEach(function (n) { s = s.split('{' + n + '}').join(v[n]); });
  return s;
}
function esc(s) { return X.esc(s); }
var LSg = {
  get: function (k, d) { try { var v = localStorage.getItem(k); return v == null ? d : JSON.parse(v); } catch (e) { return d; } },
  set: function (k, v) { try { if (v == null) localStorage.removeItem(k); else localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
};
function here() {
  var S = X.S;
  if (S.me) return [S.me.lng, S.me.lat];
  if (S.map) { var c = S.map.getCenter(); return [c.lng, c.lat]; }
  return null;
}
function rand32() {
  var a = new Uint8Array(16), s = '';
  try { crypto.getRandomValues(a); } catch (e) { for (var j = 0; j < 16; j++) a[j] = Math.floor(Math.random() * 256); }
  for (var i = 0; i < 16; i++) s += ('0' + a[i].toString(16)).slice(-2);
  return s;
}
function dev() {
  var d = LSg.get('shg:nl:dev', '');
  if (!/^[a-f0-9]{32}$/.test(d)) { d = rand32(); LSg.set('shg:nl:dev', d); }
  return d;
}
function api(url, body) {
  var o = body ? { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { 'Content-Type': 'application/json', 'X-SHG-Dev': dev() }, body: JSON.stringify(body) }
               : { credentials: 'same-origin', cache: 'no-store', headers: { 'X-SHG-Dev': dev() } };
  return X.fetchT(url, o, 12000).then(function (r) {
    return r.json().catch(function () { return null; }).then(function (j) {
      if (!j || !j.ok) { var e = new Error((j && (j.message || j.error)) || 'http ' + r.status); e.status = r.status; throw e; }
      return j.data;
    });
  });
}
function ago(ms) {
  var s = Math.max(0, Math.round((Date.now() - ms) / 1000));
  if (s < 60) return s + ' ' + t('sec');
  if (s < 3600) return Math.round(s / 60) + ' ' + t('min');
  return Math.round(s / 360) / 10 + ' h';
}
function clock(ms) { var d = new Date(ms); return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }
function slow() { return !!(X.S.saver || (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches)); }
/* Google encoded polyline, precision 5 - at most ~900 points */
function enc(c) {
  var step = Math.max(1, Math.ceil(c.length / 900)), out = '', pl = 0, pg = 0;
  function num(v) { v = v < 0 ? ~(v << 1) : v << 1; var s = ''; while (v >= 0x20) { s += String.fromCharCode((0x20 | (v & 0x1f)) + 63); v >>= 5; } return s + String.fromCharCode(v + 63); }
  function add(p) { var la = Math.round(p[1] * 1e5), lg = Math.round(p[0] * 1e5); out += num(la - pl) + num(lg - pg); pl = la; pg = lg; }
  for (var i = 0; i < c.length; i += step) add(c[i]);
  if ((c.length - 1) % step) add(c[c.length - 1]);
  return out;
}
function dec(s) {
  var i = 0, la = 0, lg = 0, out = [];
  function rd() { var b, sh = 0, r = 0; do { b = s.charCodeAt(i++) - 63; r |= (b & 0x1f) << sh; sh += 5; } while (b >= 0x20 && i <= s.length); return (r & 1) ? ~(r >> 1) : (r >> 1); }
  while (i < s.length) { la += rd(); lg += rd(); out.push([lg / 1e5, la / 1e5]); }
  return out;
}
/* metres from p to segment a-b (flat earth: fine at road scale) */
function segDist(p, a, b) {
  var k = Math.cos(p[1] * Math.PI / 180) * 111320, ky = 110540;
  var ax = (a[0] - p[0]) * k, ay = (a[1] - p[1]) * ky, bx = (b[0] - p[0]) * k, by = (b[1] - p[1]) * ky;
  var dx = bx - ax, dy = by - ay, l2 = dx * dx + dy * dy, u = l2 ? Math.max(0, Math.min(1, -(ax * dx + ay * dy) / l2)) : 0;
  var x = ax + u * dx, y = ay + u * dy;
  return { d: Math.sqrt(x * x + y * y), u: u };
}
function wrapM(html, cls) { var w = document.createElement('div'), i = document.createElement('div'); i.className = cls; i.innerHTML = html; w.appendChild(i); return w; }

/* ---------------------------------------------------------------------
   LIVE CONNECTION  (WebSocket, long-poll when the network blocks it)
--------------------------------------------------------------------- */
var LC = { ws: null, h: {}, tries: 0, poll: false, since: 0 };
function chans() { return Object.keys(LC.h); }
function on(ch, fn) { LC.h[ch] = fn; resub(); }
function off(ch) { delete LC.h[ch]; resub(); }
function resub() {
  if (LC.ws && LC.ws.readyState === 1) { try { LC.ws.send(JSON.stringify({ sub: chans() })); } catch (e) {} }
  else if (!LC.ws && !LC.poll && chans().length) connect();
}
function deliver(m) { var f = m && LC.h[m.ch]; if (f) { try { f(m.d, m.t); } catch (e) { if (QS.has('debug')) console.warn('nav-live', e); } } }
function connect() {
  if (!chans().length) return;
  if (!('WebSocket' in window) || LC.tries >= 3) { startPoll(); return; }
  var ws, opened = false;
  try { ws = new WebSocket((location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + '/live/?ch=' + encodeURIComponent(chans().join(','))); }
  catch (e) { LC.tries++; startPoll(); return; }
  LC.ws = ws;
  ws.onopen = function () { opened = true; LC.tries = 0; resub(); };
  ws.onmessage = function (e) { try { deliver(JSON.parse(e.data)); } catch (x) {} };
  ws.onerror = function () {};
  ws.onclose = function () {
    if (LC.ws === ws) LC.ws = null;
    if (!opened) LC.tries++;
    if (chans().length) setTimeout(connect, opened ? 2000 : 3000 * LC.tries);
  };
}
function startPoll() { if (LC.poll) return; LC.poll = true; pollOnce(); }
function pollOnce() {
  if (!chans().length) { LC.poll = false; return; }
  var h = here(), u = '/api/nav-live.php?ch=' + encodeURIComponent(chans().join(',')) + '&since=' + LC.since +
    (h ? '&lat=' + h[1].toFixed(3) + '&lng=' + h[0].toFixed(3) : '');
  api(u).then(function (d) { LC.since = d.now; (d.ev || []).forEach(deliver); })
    .catch(function () {})
    .then(function () { setTimeout(pollOnce, document.hidden ? 15000 : 1200); });
}

/* ---------------------------------------------------------------------
   SHEET (one small bottom sheet; Android back closes it first)
--------------------------------------------------------------------- */
var SHT = { open: false, hist: false, backing: false };
function css() {
  var st = document.createElement('style');
  st.id = 'nlCss';
  st.textContent = [
    '.nl-wrap{position:fixed;inset:0;z-index:62;pointer-events:none}',
    '.nl-scrim{position:absolute;inset:0;background:rgba(3,7,16,.4);pointer-events:auto}',
    '.nl-sheet{position:absolute;left:calc(var(--sl,0px) + 8px);right:calc(var(--sr,0px) + 8px);bottom:calc(var(--sb,0px) + 8px);max-width:520px;margin:0 auto;max-height:80vh;overflow-y:auto;overscroll-behavior:contain;',
    '  border-radius:22px;pointer-events:auto;padding:14px 14px 12px;background:rgba(14,24,44,.95);-webkit-backdrop-filter:blur(20px) saturate(1.4);backdrop-filter:blur(20px) saturate(1.4);',
    '  border:1px solid rgba(255,255,255,.09);box-shadow:0 18px 60px rgba(0,0,0,.55);color:var(--text,#EEF2FA);font:14px/1.5 Inter,system-ui,sans-serif}',
    '.nl-sheet h3{margin:0 0 6px;font:600 16px Poppins,Inter,system-ui,sans-serif;display:flex;align-items:center;gap:8px}',
    '.nl-sheet p{margin:4px 0 10px;color:var(--text2,#A8B4CC);font-size:13.5px}',
    '.nl-x{position:absolute;top:8px;right:8px;width:44px;height:44px;border-radius:50%;border:none;background:rgba(255,255,255,.06);color:var(--text2,#A8B4CC);font-size:20px;cursor:pointer}',
    '.nl-acts{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}',
    '.nl-b{min-height:44px;padding:0 16px;border-radius:22px;border:1px solid rgba(255,255,255,.14);background:transparent;color:var(--text,#EEF2FA);font:500 14px Inter,system-ui,sans-serif;display:inline-flex;align-items:center;gap:8px;cursor:pointer;text-decoration:none}',
    '.nl-b.cta{background:var(--cta,#E8652E);border-color:var(--cta,#E8652E);color:#fff}.nl-b.wa{background:#1FAF54;border-color:#1FAF54;color:#fff}.nl-b.red{color:#FF7A70;border-color:rgba(255,122,112,.4)}',
    '.nl-url{width:100%;height:44px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.05);color:var(--text,#EEF2FA);padding:0 12px;font:13px Inter,system-ui,sans-serif}',
    '.nl-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:8px}',
    '.nl-k{min-height:76px;border-radius:16px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.04);color:var(--text,#EEF2FA);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;font:500 12.5px Inter,system-ui,sans-serif;cursor:pointer;text-align:center;padding:6px}',
    '.nl-k span{font-size:26px;line-height:1}',
    '.nl-fab{position:relative}.nl-fab svg{width:22px;height:22px}',
    '.nl-livepill{background:#E5352B!important;color:#fff!important;border:none!important;font:700 11px Inter,system-ui,sans-serif!important;letter-spacing:.06em}',
    '.nl-bus{cursor:pointer}.nl-bi{display:flex;align-items:center;gap:6px;padding:3px 9px 3px 3px;border-radius:22px;background:rgba(14,24,44,.92);border:2px solid #D4A843;box-shadow:0 4px 12px rgba(0,0,0,.45);color:#EEF2FA;font:600 11.5px Inter,system-ui,sans-serif;white-space:nowrap}',
    '.nl-bi img{width:34px;height:34px;border-radius:50%;object-fit:cover;background:#fff}',
    /* 22 Oct 2026: the number plate on the coach + a live pulse ("gaadi number wise") */
    '.nl-bi{position:relative}.nl-bi b{display:block;font:700 10.5px Inter,system-ui,sans-serif;letter-spacing:.04em;color:#FFD66B;line-height:1.1}.nl-bi em{font-style:normal;display:block;line-height:1.15}',
    '.nl-bi::before{content:"";position:absolute;left:3px;top:3px;width:34px;height:34px;border-radius:50%;background:rgba(52,199,89,.5);animation:nlPulse 2s ease-out infinite;pointer-events:none;z-index:-1}',
    '@keyframes nlPulse{0%{transform:scale(1);opacity:.85}100%{transform:scale(1.9);opacity:0}}',
    '@media (prefers-reduced-motion:reduce){.nl-bi::before{animation:none}}body.saver .nl-bi::before,body.lowdata .nl-bi::before{animation:none}',
    '.nl-plate{display:inline-block;margin-left:6px;padding:1px 7px;border-radius:5px;background:#FFD66B;color:#1a1a1a;font:700 12px Inter,system-ui,sans-serif;letter-spacing:.05em;vertical-align:middle}',
    '.nl-bi.nl-mine{border-color:#E8652E;background:#E8652E;box-shadow:0 0 0 6px rgba(232,101,46,.28),0 4px 12px rgba(0,0,0,.45)}',
    '.nl-rep{cursor:pointer}.nl-ri{width:36px;height:36px;border-radius:50%;display:grid;place-items:center;font-size:19px;background:#fff;border:2px solid #E8652E;box-shadow:0 3px 10px rgba(0,0,0,.4)}',
    '.nl-puck{width:26px;height:26px;border-radius:50%;background:#2F80ED;border:3px solid #fff;box-shadow:0 0 0 8px rgba(47,128,237,.25),0 3px 10px rgba(0,0,0,.4);position:relative}',
    '.nl-puck i{position:absolute;left:50%;top:-11px;margin-left:-6px;border:6px solid transparent;border-bottom:9px solid #2F80ED;transform-origin:6px 24px}',
    '.nl-dest{width:18px;height:18px;border-radius:50%;background:#E8652E;border:3px solid #fff;box-shadow:0 3px 10px rgba(0,0,0,.4)}',
    '.nl-fcard{position:fixed;z-index:41;left:calc(var(--sl,0px) + 8px);right:calc(var(--sr,0px) + 8px);bottom:calc(var(--sb,0px) + 8px);max-width:520px;margin:0 auto;border-radius:22px;padding:14px 16px;',
    '  background:rgba(14,24,44,.95);-webkit-backdrop-filter:blur(20px);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,.09);box-shadow:0 14px 40px rgba(0,0,0,.5);color:var(--text,#EEF2FA);font:14px/1.45 Inter,system-ui,sans-serif}',
    '.nl-fh{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text2,#A8B4CC)}.nl-fh b{color:#FF5A4F;letter-spacing:.06em}',
    '.nl-dot{width:9px;height:9px;border-radius:50%;background:#FF5A4F;animation:nlp 1.4s infinite}.nl-fcard.done .nl-dot{background:#3DDC84;animation:none}.nl-fcard.done .nl-fh b{color:#3DDC84}',
    '@keyframes nlp{50%{opacity:.25}}',
    '.nl-fbig{font:600 24px Poppins,Inter,system-ui,sans-serif;margin:4px 0 0}.nl-fsub{color:var(--text2,#A8B4CC);font-size:13.5px}',
    'body.saver .nl-dot{animation:none}',
    '@media (prefers-reduced-motion:reduce){.nl-dot{animation:none}}'
  ].join('\n');
  document.head.appendChild(st);
}
function sheet(html) {
  var w = $('#nlWrap');
  if (!w) {
    w = document.createElement('div'); w.className = 'nl-wrap'; w.id = 'nlWrap'; w.hidden = true;
    w.innerHTML = '<div class="nl-scrim" id="nlScrim"></div><section class="nl-sheet" role="dialog" aria-modal="true" id="nlSheet"></section>';
    document.body.appendChild(w);
    $('#nlScrim').onclick = function () { sheetClose(); };
    addEventListener('popstate', function () { SHT.backing = false; if (SHT.open && SHT.hist) { SHT.hist = false; sheetClose(true); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && SHT.open) { e.stopImmediatePropagation(); sheetClose(); } }, true);
  }
  $('#nlSheet').innerHTML = '<button class="nl-x" type="button" data-nlx aria-label="' + esc(t('close')) + '">×</button>' + html;
  $('#nlSheet [data-nlx]').onclick = function () { sheetClose(); };
  if (!SHT.open) {
    SHT.open = true; w.hidden = false;
    try { history.pushState({ lvl: X.S.level, d: X.S.depth, nl: 1 }, ''); SHT.hist = true; } catch (e) { SHT.hist = false; }
  }
  return $('#nlSheet');
}
function sheetClose(fromPop) {
  if (!SHT.open) return;
  SHT.open = false; $('#nlWrap').hidden = true;
  if (!fromPop && SHT.hist) { SHT.hist = false; SHT.backing = true; try { history.back(); } catch (e) { SHT.backing = false; } }
}

/* ---------------------------------------------------------------------
   A · LIVE TRIP SHARE
--------------------------------------------------------------------- */
var SH = LSg.get('shg:nl:share', null);       /* {token, secret, url, exp, to:{lat,lng}, dest} */
if (SH && !(SH.exp > Date.now())) SH = null;
var SHx = { N: null, route: null, last: 0, busy: false };
function leftM(N) { return N && N.route && N.route.L ? Math.max(0, N.route.L.len - (N.along || 0)) : null; }
function etaMs(N) {
  var r = N && N.route, left = leftM(N);
  if (!r || left == null || !r.dist) return null;
  return Date.now() + r.dur * (left / r.dist) * 1000;
}
function shareSave() { LSg.set('shg:nl:share', SH); pill(); }
function shareStart() {
  var S = X.S, N = S.nav;
  if (!N || N.preview || !S.to || (N.sim && !QS.has('debug'))) { X.toast(t('needNav')); return; }
  if (SH) { shareSheet(); return; }
  api('/api/nav-share.php', {
    action: 'start', dest: { name: S.to.name || '', lat: S.to.lat, lng: S.to.lng }, mode: S.mode,
    route: enc(N.route.coords), left: leftM(N), eta: etaMs(N)
  }).then(function (d) {
    /* the follow link opens the page that shared it (the beta shares beta links) */
    var url = /^\/navigate(-beta)?\.html$/.test(location.pathname) ? location.origin + location.pathname + '?follow=' + d.token : d.url;
    SH = { token: d.token, secret: d.secret, url: url, exp: d.expires, to: { lat: S.to.lat, lng: S.to.lng }, dest: S.to.name || '' };
    SHx.N = N; SHx.route = N.route; SHx.last = 0;
    shareSave(); X.vib(20);
    sharePos(N, S.me ? { lat: S.me.lat, lng: S.me.lng, speed: S.me.speed, heading: S.me.heading } : null, false);
    shareSheet();
  }).catch(function () { X.toast(t('failed')); });
}
function shareText() { var N = X.S.nav, e = etaMs(N); return t('shareMsg', { d: SH.dest || '', e: e ? clock(e) : '—' }); }
function shareSheet() {
  if (!SH) return;
  var el = sheet('<h3><span class="nl-dot"></span>' + esc(t('sharing')) + '</h3><p>' + esc(t('shareOn')) + '</p>' +
    '<input class="nl-url" readonly value="' + esc(SH.url) + '" aria-label="link">' +
    '<div class="nl-acts"><a class="nl-b wa" target="_blank" rel="noopener" href="https://wa.me/?text=' + encodeURIComponent(shareText() + '\n' + SH.url) + '">' + esc(t('wa')) + '</a>' +
    (navigator.share ? '<button class="nl-b" type="button" data-s="more">' + esc(t('more')) + '</button>' : '') +
    '<button class="nl-b" type="button" data-s="copy">' + esc(t('copy')) + '</button>' +
    '<button class="nl-b red" type="button" data-s="stop">' + esc(t('stop')) + '</button></div>');
  el.querySelectorAll('[data-s]').forEach(function (b) {
    b.onclick = function () {
      var k = b.dataset.s;
      if (k === 'more') navigator.share({ title: 'SHARI NAV', text: shareText(), url: SH.url }).catch(function () {});
      else if (k === 'copy') { try { navigator.clipboard.writeText(SH.url).then(function () { X.toast(t('copied')); }); } catch (e) { el.querySelector('.nl-url').select(); } }
      else if (k === 'stop') { shareEnd('stop'); sheetClose(); }
    };
  });
}
function sharePos(N, f, arrived) {
  if (!SH || (SHx.busy && !arrived)) return;          /* the arrival is never skipped */
  var now = Date.now();
  if (!arrived && now - SHx.last < 3000) return;
  if (!f) return;
  SHx.last = now; SHx.busy = true;
  var body = { action: 'pos', token: SH.token, secret: SH.secret, lat: f.lat, lng: f.lng,
               kmh: f.speed > 0 ? f.speed * 3.6 : 0, hd: f.heading != null && !isNaN(f.heading) ? f.heading : null,
               left: arrived ? 0 : leftM(N), eta: arrived ? now : etaMs(N), arrived: !!arrived };
  if (N && N.route && N.route !== SHx.route) { body.route = enc(N.route.coords); SHx.route = N.route; }   /* rerouted */
  api('/api/nav-share.php', body).then(function (d) {
    if (d && d.status !== 'live') { SH = null; shareSave(); }
  }).catch(function (e) { if (e.status === 404) { SH = null; shareSave(); } })
    .then(function () { SHx.busy = false; });
}
function shareEnd(kind) {
  if (!SH) return;
  var s = SH; SH = null; shareSave();
  if (kind === 'stop') { api('/api/nav-share.php', { action: 'stop', token: s.token, secret: s.secret }).catch(function () {}); X.toast(t('stopped')); }
}
function pill() {
  var b = $('#nlLive');
  if (b) b.hidden = !SH;
}

/* the follow page: navigate.html?follow=<token> */
var FW = null;
function follow(token) {
  FW = { token: token, st: null, route: null, puck: null, dest: null, fit: false, hold: false, anim: null };
  document.body.classList.add('nl-follow');
  ['#nlRep', '#nlLive'].forEach(function (s) { var b = $(s); if (b) b.style.display = 'none'; });   /* a viewer, not a driver */
  var c = document.createElement('div'); c.className = 'nl-fcard'; c.id = 'nlFcard';
  c.innerHTML = '<div class="nl-fh"><span class="nl-dot"></span><b>LIVE</b><span>' + esc(t('fWait')) + '</span></div>';
  document.body.appendChild(c);
  c.addEventListener('click', function (e) {
    var b = e.target.closest('[data-f]'); if (!b) return;
    if (b.dataset.f === 'center') { FW.hold = false; followCam(true); }
    if (b.dataset.f === 'notify' && !FW.watching) followNotify();
  });
  api('/api/nav-share.php?token=' + token).then(followApply).catch(function () { followCard('gone'); });
  on('share:' + token, followApply);
  var m = X.S.map;
  if (m) m.on('dragstart', function () { if (FW) FW.hold = true; });
  setInterval(function () { if (FW && FW.st) followCard(); }, 5000);
}
function followApply(d) {
  if (!d || !FW) return;
  if (d.route) { FW.route = dec(d.route); followLayers(); }
  FW.st = Object.assign({}, FW.st || {}, d);
  var m = X.S.map, st = FW.st;
  if (m && window.maplibregl) {
    if (!FW.dest) FW.dest = new maplibregl.Marker({ element: wrapM('', 'nl-dest') }).setLngLat([st.dest.lng, st.dest.lat]).addTo(m);
    if (st.pos) {
      var to = [st.pos.lng, st.pos.lat];
      if (!FW.puck) { FW.puck = new maplibregl.Marker({ element: wrapM('<i></i>', 'nl-puck') }).setLngLat(to).addTo(m); }
      else moveMarker(FW.puck, to);
      var i = FW.puck.getElement().querySelector('i');
      if (i) { i.style.display = st.pos.hd == null ? 'none' : ''; i.style.transform = 'rotate(' + (st.pos.hd || 0) + 'deg)'; }
    }
    followCam(!FW.fit);
  }
  followCard();
  if (st.status !== 'live') setTimeout(function () { off('share:' + FW.token); }, 3000);
}
function followCam(first) {
  var m = X.S.map, st = FW && FW.st; if (!m || !st) return;
  if (first && st.pos) {
    FW.fit = true;
    var b = new maplibregl.LngLatBounds([st.pos.lng, st.pos.lat], [st.pos.lng, st.pos.lat]); b.extend([st.dest.lng, st.dest.lat]);
    m.fitBounds(b, { padding: { top: 90, bottom: 200, left: 50, right: 50 }, maxZoom: 15, duration: slow() ? 0 : 800 });
  } else if (st.pos && !FW.hold) m.easeTo({ center: [st.pos.lng, st.pos.lat], duration: slow() ? 0 : 900 });
}
function followLayers() {
  var m = X.S.map; if (!m || !FW || !FW.route) return;
  if (!m.getStyle() || !m.getStyle().sources) { m.once('idle', followLayers); return; }
  var data = { type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: FW.route } };
  if (m.getSource('nl-fr')) { m.getSource('nl-fr').setData(data); return; }
  m.addSource('nl-fr', { type: 'geojson', data: data });
  m.addLayer({ id: 'nl-fr-case', type: 'line', source: 'nl-fr', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#0B3D91', 'line-width': 9 } });
  m.addLayer({ id: 'nl-fr', type: 'line', source: 'nl-fr', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#4C8DFF', 'line-width': 5 } });
}
function followCard(gone) {
  var c = $('#nlFcard'); if (!c) return;
  var st = FW && FW.st;
  if (gone || !st || st.status === 'ended' || st.status === 'stopped') {
    c.classList.remove('done');
    c.innerHTML = '<div class="nl-fh"><b>SHARI NAV</b></div><div class="nl-fbig" style="font-size:18px">' + esc(t('fEnd')) + '</div>' +
      '<div class="nl-acts"><a class="nl-b" href="/navigate.html">' + esc(t('fOpen')) + '</a></div>';
    return;
  }
  if (st.status === 'arrived') {
    c.classList.add('done');
    c.innerHTML = '<div class="nl-fh"><span class="nl-dot"></span><b>LIVE</b></div><div class="nl-fbig">' + esc(t('fDone', { d: st.dest.name || '' })) + '</div>' +
      '<div class="nl-fsub">' + esc(clock(st.updated)) + '</div>';
    return;
  }
  var stale = Date.now() - (st.updated || 0) > 120000;
  c.innerHTML = '<div class="nl-fh"><span class="nl-dot"></span><b>LIVE</b><span>' + esc(t('fLive')) + ' → ' + esc(st.dest.name || '') + '</span></div>' +
    '<div class="nl-fbig">' + (st.eta ? esc(clock(st.eta)) + ' <span style="font-size:15px;font-weight:500">' + esc(t('fArr')) + '</span>' : '—') + '</div>' +
    '<div class="nl-fsub">' + (st.left != null ? esc(X.fmtDist(st.left)) + ' ' + esc(t('fLeft')) + ' · ' : '') +
      esc(stale ? t('fWeak') : t('fAgo', { n: ago(st.updated) })) + '</div>' +
    '<div class="nl-acts"><button class="nl-b" type="button" data-f="center">' + esc(t('fCenter')) + '</button>' +
      (pushOk() ? '<button class="nl-b' + (FW.watching ? '' : ' cta') + '" type="button" data-f="notify">' + esc(FW.watching ? t('notifyOff') : t('notifyMe')) + '</button>' : '') +
      '<a class="nl-b" href="/navigate.html">' + esc(t('fOpen')) + '</a></div>';
}
/* arrival notification (Phase 4): this phone subscribes to web push and the
   server pushes once when the rider arrives (api/nav-share.php action=watch) */
function pushOk() { return 'serviceWorker' in navigator && 'PushManager' in window && !!window.Notification; }
function b64ToU8(s) {
  s = s.replace(/-/g, '+').replace(/_/g, '/'); s += '==='.slice((s.length + 3) % 4);
  var raw = atob(s), a = new Uint8Array(raw.length);
  for (var i = 0; i < raw.length; i++) a[i] = raw.charCodeAt(i);
  return a;
}
function followNotify() {
  if (!pushOk()) { X.toast(t('notifyNo'), 4000); return; }
  Notification.requestPermission().then(function (p) {
    if (p !== 'granted') throw new Error('denied');
    return Promise.all([api('/api/push.php?action=key'), navigator.serviceWorker.register('/sw.js').then(function () { return navigator.serviceWorker.ready; })]);
  }).then(function (x) {
    var key = x[0] && x[0].key, reg = x[1]; if (!key) throw new Error('no key');
    return reg.pushManager.getSubscription().then(function (s) { return s || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToU8(key) }); });
  }).then(function (sub) {
    return api('/api/nav-share.php', { action: 'watch', token: FW.token, sub: sub.toJSON(), lang: lang() });
  }).then(function () { FW.watching = true; followCard(); X.toast(t('notifyOn'), 3500); })
    .catch(function () { X.toast(t('notifyNo'), 4000); });
}
/* the rider's side: arrived while sharing - one tap to tell the people waiting */
function arrivedSheet(dest) {
  var msg = t('arrivedMsg', { d: dest || '' });
  var el = sheet('<h3>' + esc(t('arrivedT', { d: dest || '' })) + '</h3><p>' + esc(t('arrivedP')) + '</p>' +
    '<div class="nl-acts"><a class="nl-b wa" target="_blank" rel="noopener" href="https://wa.me/?text=' + encodeURIComponent(msg) + '">' + esc(t('wa')) + '</a>' +
    (navigator.share ? '<button class="nl-b" type="button" data-s>' + esc(t('more')) + '</button>' : '') + '</div>');
  var b = el.querySelector('[data-s]'); if (b) b.onclick = function () { navigator.share({ title: 'SHARI NAV', text: msg }).catch(function () {}); };
}
function moveMarker(mk, to) {
  if (slow()) { mk.setLngLat(to); return; }
  var from = mk.getLngLat(), t0 = performance.now(), dur = 1200;
  (function step(n) {
    var k = Math.min(1, (n - t0) / dur);
    mk.setLngLat([from.lng + (to[0] - from.lng) * k, from.lat + (to[1] - from.lat) * k]);
    if (k < 1) requestAnimationFrame(step);
  })(t0);
}

/* ---------------------------------------------------------------------
   B · S HARI COACHES ON THE ROAD
--------------------------------------------------------------------- */
var BUS = { on: LSg.get('shg:nl:bus', true) !== false, m: {}, by: {} };
function busStart() {
  if (!BUS.on || FW) return;
  api('/api/nav-buses.php').then(busApply).catch(function () {});
  on('bus', busApply);
}
function busStop() { off('bus'); Object.keys(BUS.m).forEach(function (id) { BUS.m[id].remove(); }); BUS.m = {}; BUS.by = {}; }
function busApply(snap) {
  var m = X.S.map; if (!m || !window.maplibregl || !BUS.on) return;
  var seen = {};
  (snap && snap.buses || []).forEach(function (b) {
    seen[b.id] = true; BUS.by[b.id] = b;
    var lbl = (b.bus ? '<b>' + esc(b.bus) + '</b>' : '') + '<em>→ ' + esc(b.to) + '</em>';
    if (BUS.m[b.id]) { moveMarker(BUS.m[b.id], [b.lng, b.lat]); BUS.m[b.id].getElement().querySelector('span').innerHTML = lbl; return; }
    var el = wrapM('<img src="/assets/img/bus-shg-sm.webp" alt="S Hari Global" loading="lazy"><span>' + lbl + '</span>', 'nl-bi');
    el.className = 'nl-bus';
    el.addEventListener('click', function (e) { e.stopPropagation(); busSheet(b.id); });
    BUS.m[b.id] = new maplibregl.Marker({ element: el, anchor: 'left' }).setLngLat([b.lng, b.lat]).addTo(m);
  });
  Object.keys(BUS.m).forEach(function (id) { if (!seen[id]) { BUS.m[id].remove(); delete BUS.m[id]; delete BUS.by[id]; } });
}
function busSheet(id) {
  var b = BUS.by[id]; if (!b) return;
  sheet('<h3><img src="/assets/img/bus-shg-sm.webp" alt="" style="width:40px;height:40px;border-radius:50%;object-fit:cover;background:#fff">S Hari Global' +
    (b.bus ? '<span class="nl-plate">' + esc(b.bus) + '</span>' : '') + '</h3>' +
    (b.busName ? '<p style="font-size:12.5px;margin:-4px 0 6px">' + esc(b.busName) + '</p>' : '') +
    '<p style="color:var(--text,#EEF2FA);font-size:15px;margin-bottom:2px">' + esc(b.from) + ' → ' + esc(b.to) + ' · ' + esc(b.dep) + '</p>' +
    (b.next ? '<p>' + esc(t('busNext')) + ': <b style="color:var(--gold,#D4A843)">' + esc(b.next.name) + '</b> · ' + esc(String(b.next.eta)) + ' ' + esc(t('min')) + ' · ' + esc(String(b.next.km)) + ' km</p>' : '') +
    (b.age != null ? '<p style="font-size:12.5px">' + esc(t('busAge', { n: ago(Date.now() - b.age * 1000) })) + (b.kmh != null ? ' · ' + b.kmh + ' km/h' : '') + '</p>' : '') +
    '<div class="nl-acts"><a class="nl-b cta" href="/">' + esc(t('busBook')) + '</a><a class="nl-b" href="/#/track">' + esc(t('busTrack')) + '</a></div>');
}

/* ---------------------------------------------------------------------
   C · ROAD REPORTS
--------------------------------------------------------------------- */
var REP = { on: LSg.get('shg:nl:rep', true) !== false, m: {}, by: {}, at: null, atT: 0, said: {} };
function repLoad() {
  var h = here(); if (!h || !REP.on || FW) return;
  REP.at = h; REP.atT = Date.now();
  api('/api/nav-reports.php?lat=' + h[1].toFixed(4) + '&lng=' + h[0].toFixed(4)).then(function (d) {
    var keep = {};
    (d.reports || []).forEach(function (r) { keep[r.id] = true; repPut(r); });
    Object.keys(REP.by).forEach(function (id) { if (!keep[id]) repDel(+id); });
  }).catch(function () {});
}
function repPut(r) {
  if (r.status !== 'active') { repDel(r.id); return; }
  var m = X.S.map, k = KIND[r.kind]; if (!m || !k || !window.maplibregl) return;
  REP.by[r.id] = r;
  if (REP.m[r.id]) return;
  var el = wrapM('<span>' + k[1] + '</span>', 'nl-ri');
  el.className = 'nl-rep';
  el.addEventListener('click', function (e) { e.stopPropagation(); repSheet(r.id); });
  REP.m[r.id] = new maplibregl.Marker({ element: el }).setLngLat([r.lng, r.lat]).addTo(m);
}
function repDel(id) { if (REP.m[id]) REP.m[id].remove(); delete REP.m[id]; delete REP.by[id]; }
function repLive(d) {
  if (!d || !d.r) return;
  if (d.op === 'del') { repDel(d.r.id); return; }
  var h = REP.at || here();
  if (h && X.hav(h, [d.r.lng, d.r.lat]) < 40000) repPut(d.r);
}
function repStop() { off('rep'); Object.keys(REP.m).forEach(function (id) { repDel(+id); }); }
function repSheet(id) {
  var r = REP.by[id], k = r && KIND[r.kind]; if (!k) return;
  var el = sheet('<h3><span style="font-size:24px">' + k[1] + '</span>' + esc(L(k[2])) + '</h3>' +
    '<p>' + esc(t('repAgo', { n: ago(r.at) })) + (r.note ? ' · ' + esc(r.note) : '') + ' · 👍 ' + r.up + ' · 👎 ' + r.down + '</p>' +
    '<div class="nl-acts"><button class="nl-b cta" type="button" data-v="up">👍 ' + esc(t('repStill')) + '</button>' +
    '<button class="nl-b" type="button" data-v="down">👎 ' + esc(t('repGone')) + '</button></div>');
  el.querySelectorAll('[data-v]').forEach(function (b) {
    b.onclick = function () {
      sheetClose();
      api('/api/nav-reports.php', { action: 'vote', id: r.id, v: b.dataset.v }).then(function (d) { repPut(d.report); X.toast(t('repVoted')); })
        .catch(function (e) { X.toast(e.status === 409 ? t('repVoted') : t('failed')); });
    };
  });
}
function repPick() {
  var el = sheet('<h3>⚠️ ' + esc(t('repQ')) + '</h3>' +
    (SR ? '<div class="nl-acts" style="margin:0 0 4px"><button class="nl-b" type="button" data-k="_voice">🎙️ ' + esc(t('repVoice')) + '</button></div>' : '') +
    '<div class="nl-grid">' + KINDS.map(function (k) {
      return '<button class="nl-k" type="button" data-k="' + k[0] + '"><span>' + k[1] + '</span>' + esc(L(k[2])) + '</button>';
    }).join('') + '</div>');
  el.querySelectorAll('[data-k]').forEach(function (b) {
    b.onclick = function () { if (b.dataset.k === '_voice') repVoice(b); else { sheetClose(); repAdd(b.dataset.k); } };
  });
}
function repAdd(kind) {
  var S = X.S, p = S.me ? [S.me.lng, S.me.lat] : (QS.has('debug') ? here() : null);
  if (!p) { X.toast(t('repLoc'), 3500); X.startGeo(); return; }
  api('/api/nav-reports.php', { action: 'add', kind: kind, lat: p[1], lng: p[0], hd: S.me && S.me.heading != null ? S.me.heading : null })
    .then(function (d) { repPut(d.report); REP.said[d.report.id] = true; X.toast(KIND[kind][1] + ' ' + t('repThanks'), 3500); X.vib(25); })
    .catch(function (e) { X.toast(e.status === 429 ? t('repMany') : t('failed')); });
}
var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
function repVoice(btn) {
  var rec; try { rec = new SR(); } catch (e) { return; }
  var tried = false;
  rec.lang = lang() === 'en' ? 'en-IN' : lang() === 'hi' ? 'hi-IN' : 'ne-NP';
  rec.interimResults = false; rec.maxAlternatives = 3;
  btn.textContent = '🎙️ ' + t('listen');
  rec.onresult = function (e) {
    var said = ''; for (var i = 0; i < e.results[0].length; i++) said += ' ' + e.results[0][i].transcript;
    var k = KINDS.filter(function (x) { return x[3].test(said); })[0];
    if (!k) { btn.textContent = '🎙️ ' + t('noHeard'); return; }
    var el = sheet('<h3><span style="font-size:24px">' + k[1] + '</span>' + esc(t('repSure', { k: L(k[2]) })) + '</h3><p>“' + esc(said.trim()) + '”</p>' +
      '<div class="nl-acts"><button class="nl-b cta" type="button" data-y>' + esc(t('yes')) + '</button></div>');
    el.querySelector('[data-y]').onclick = function () { sheetClose(); repAdd(k[0]); };
  };
  rec.onerror = function (e) { if (e.error === 'language-not-supported' && !tried) { tried = true; rec.lang = 'hi-IN'; try { rec.start(); } catch (x) {} } };
  try { rec.start(); X.voUnlock(); } catch (e) {}
}
/* on the route, 1 km ahead: say it once */
function repAhead(N) {
  if (!REP.on || !N || N.preview || N.arrived || !N.route || !N.route.L) return;
  var ids = Object.keys(REP.by); if (!ids.length) return;
  var Lr = N.route.L, c = Lr.c, cum = Lr.cum, a = N.along || 0, i0 = Math.max(0, (N.i || 0) - 1);
  ids.forEach(function (id) {
    var r = REP.by[id]; if (REP.said[id]) return;
    var p = [r.lng, r.lat];
    if (X.hav(p, c[Math.min(i0, Lr.n - 1)]) > 3000) return;            /* cheap reject */
    for (var j = i0; j < Lr.n - 1 && cum[j] - a < 1100; j++) {
      var q = segDist(p, c[j], c[j + 1]);
      if (q.d < 60) {
        var ah = cum[j] + q.u * (cum[j + 1] - cum[j]) - a;
        if (ah > 120 && ah <= 1100) { REP.said[id] = true; repSay(r, ah); }
        else if (ah <= 120) REP.said[id] = true;                        /* already on it: no late warning */
        return;
      }
    }
  });
}
function repSay(r, ah) {
  var k = KIND[r.kind], d = ah >= 850 ? t('km1') : Math.round(ah / 100) * 100 + ' ' + t('mtr');
  var s = t('ahead', { d: d, k: L(k[2]) });
  X.toast(k[1] + ' ' + s, 6000); X.vib([60, 80, 60]);
  if (X.S.sound !== 'off') { try { X.sayText(s, lang()); } catch (e) {} }
}

/* ---------------------------------------------------------------------
   D · DEVICE SYNC  (saved places, home / work, trips, parking, visits)
   Items are diffed against what was last synced (shg:nl:syncSnap): a
   change or a removal goes up with t = now, the merged document comes
   back and is written to this phone. A failed push stays a difference, so
   the next run (2 min, back online, after login) sends it - that is the
   offline queue. Trips and visits are append-only: a full "clear" goes
   up as clear:<list>, an old entry trimmed off the end is not a delete.
--------------------------------------------------------------------- */
var SY = { st: null, busy: false, at: LSg.get('shg:nl:syncAt', 0) };
var CS = { tok: null, at: 0 };
function csrf(force) {
  if (!force && CS.tok && Date.now() - CS.at < 10 * 60e3) return Promise.resolve(CS.tok);
  return api('/api/csrf.php').then(function (d) { CS.tok = d.csrf; CS.at = Date.now(); return CS.tok; });
}
function postC(url, body) {
  function once(force) {
    return csrf(force).then(function (tok) {
      return X.fetchT(url, { method: 'POST', credentials: 'same-origin', cache: 'no-store', body: JSON.stringify(body),
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': tok || '', 'X-SHG-Dev': dev() } }, 15000);
    });
  }
  return once(false).then(function (r) { return r.status === 419 || r.status === 403 ? once(true) : r; }).then(function (r) {
    return r.json().catch(function () { return null; }).then(function (j) {
      if (!j || !j.ok) { var e = new Error((j && (j.message || j.error)) || 'http ' + r.status); e.status = r.status; throw e; }
      return j.data;
    });
  });
}
function hashS(s) { var h = 0; for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) | 0; return h; }
var LISTS = { trip: 'shg:nm:trips', visit: 'shg:ng:visits' };
function localItems() {
  var out = {};
  ['home', 'work'].forEach(function (k) { var v = LSg.get('shg:nm:' + k, null); if (v && v.lat != null) out[k] = v; });
  var pk = LSg.get('shg:ng:park', null); if (pk && pk.lat != null) out.park = pk;
  (LSg.get('shg:nm:saved', []) || []).forEach(function (p) { if (p && p.lat != null) out['place:' + (+p.lat).toFixed(5) + ',' + (+p.lng).toFixed(5)] = p; });
  Object.keys(LISTS).forEach(function (k) { (LSg.get(LISTS[k], []) || []).forEach(function (x) { if (x && x.t) out[k + ':' + x.t] = x; }); });
  return out;
}
function applyItems(items) {
  ['home', 'work'].forEach(function (k) { var it = items[k]; if (it) LSg.set('shg:nm:' + k, it.del ? null : it.v); });
  if (items.park) LSg.set('shg:ng:park', items.park.del ? null : items.park.v);
  var places = [], lists = { trip: [], visit: [] };
  Object.keys(items).forEach(function (k) {
    var it = items[k], pre = k.split(':')[0];
    if (it.del || it.v == null) return;
    if (pre === 'place') places.push(it);
    else if (lists[pre]) { var cl = items['clear:' + pre]; if (!cl || it.t > cl.t) lists[pre].push(it.v); }
  });
  places.sort(function (a, b) { return b.t - a.t; });
  LSg.set('shg:nm:saved', places.slice(0, 50).map(function (it) { return it.v; }));
  Object.keys(lists).forEach(function (pre) {
    LSg.set(LISTS[pre], lists[pre].sort(function (a, b) { return b.t - a.t; }).slice(0, 100));
  });
  var snap = {}, loc = localItems();
  Object.keys(loc).forEach(function (k) { snap[k] = hashS(JSON.stringify(loc[k])); });
  LSg.set('shg:nl:syncSnap', snap);
  if (X.homeChips && X.S.level === 'home') { try { X.homeChips(); } catch (e) {} }
}
function syncRun() {
  if (!SY.st || !SY.st.signedIn || SY.busy || navigator.onLine === false) return Promise.resolve(false);
  var loc = localItems(), snap = LSg.get('shg:nl:syncSnap', {}), now = Date.now(), up = {};
  Object.keys(loc).forEach(function (k) { if (snap[k] !== hashS(JSON.stringify(loc[k]))) up[k] = { t: now, v: loc[k] }; });
  Object.keys(snap).forEach(function (k) {
    if (k in loc) return;
    var pre = k.split(':')[0];
    if (LISTS[pre]) { if (!(LSg.get(LISTS[pre], []) || []).length) up['clear:' + pre] = { t: now, v: 1 }; }
    else up[k] = { t: now, del: true };
  });
  SY.busy = true;
  return postC('/api/nav-sync.php', { items: up }).then(function (d) {
    if (d && d.signedIn === false) { SY.st = { signedIn: false }; return false; }
    applyItems(d.items || {});
    SY.at = Date.now(); LSg.set('shg:nl:syncAt', SY.at);
    return true;
  }).catch(function () { return false; }).then(function (ok) { SY.busy = false; return ok; });
}
function syncStatus() {
  return api('/api/nav-sync.php').then(function (d) { SY.st = d; return d; }).catch(function () { return SY.st; });
}
function openSync() {
  var st = SY.st;
  if (!st) { syncStatus().then(function () { openSync(); }); return; }
  if (st.signedIn) {
    var el = sheet('<h3>☁️ ' + esc(t('syncOn')) + '</h3><p style="color:var(--text,#EEF2FA)">' + esc(st.name || '') + (st.phone ? ' · ' + esc(st.phone) : '') + '</p>' +
      '<p>' + esc(t('syncWhat')) + '</p><p style="font-size:12.5px">' + esc(t('syncLast', { n: SY.at ? ago(SY.at) + ' ' + (lang() === 'en' ? 'ago' : lang() === 'hi' ? 'पहले' : 'अघि') : t('syncNever') })) + '</p>' +
      '<div class="nl-acts"><button class="nl-b cta" type="button" data-y>' + esc(t('syncNow')) + '</button></div>');
    el.querySelector('[data-y]').onclick = function () {
      syncRun().then(function (ok) { X.toast(ok ? t('synced') : t('failed')); if (ok && SHT.open) openSync(); });
    };
    return;
  }
  var cc = LSg.get('shg:nl:cc', X.S.lang === 'ne' ? 'NP' : 'IN');
  var el2 = sheet('<h3>☁️ ' + esc(t('syncT')) + '</h3><p>' + esc(t('syncWhat')) + '</p><p style="color:var(--text,#EEF2FA)">' + esc(t('syncLogin')) + '</p>' +
    '<div class="nl-acts" style="margin-top:0"><button class="nl-b' + (cc === 'IN' ? ' cta' : '') + '" type="button" data-cc="IN">' + esc(t('inIndia')) + '</button>' +
    '<button class="nl-b' + (cc === 'NP' ? ' cta' : '') + '" type="button" data-cc="NP">' + esc(t('inNepal')) + '</button></div>' +
    '<input class="nl-url" id="nlPhone" type="tel" inputmode="numeric" autocomplete="tel-national" maxlength="10" placeholder="' + esc(t('phone')) + '" style="margin-top:10px;font-size:16px">' +
    '<div class="nl-acts"><button class="nl-b wa" type="button" data-send>' + esc(t('sendOtp')) + '</button></div>' +
    '<div id="nlOtp" hidden><p id="nlOtpMsg"></p><input class="nl-url" id="nlCode" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="' + esc(t('otpCode')) + '" style="font-size:18px;letter-spacing:.3em">' +
    '<div class="nl-acts"><button class="nl-b cta" type="button" data-verify>' + esc(t('verify')) + '</button></div></div>');
  el2.querySelectorAll('[data-cc]').forEach(function (b) {
    b.onclick = function () { cc = b.dataset.cc; LSg.set('shg:nl:cc', cc); el2.querySelectorAll('[data-cc]').forEach(function (x) { x.classList.toggle('cta', x === b); }); };
  });
  var phone = '';
  el2.querySelector('[data-send]').onclick = function () {
    var btn = this; phone = ($('#nlPhone').value || '').replace(/\D/g, '');
    if (!/^\d{10}$/.test(phone)) { X.toast(t('badPhone')); return; }
    btn.disabled = true;
    postC('/api/otp.php', { action: 'request', phone: phone, purpose: 'login', country: cc }).then(function (d) {
      if (d && d.sent === false) throw new Error('not sent');
      $('#nlOtp').hidden = false; $('#nlOtpMsg').textContent = t('otpSent', { p: (cc === 'NP' ? '+977 ' : '+91 ') + phone });
      try { $('#nlCode').focus(); } catch (e) {}
      setTimeout(function () { btn.disabled = false; }, 30000);
    }).catch(function (e) { btn.disabled = false; X.toast(e && e.message && !/^http|not sent/.test(e.message) ? e.message : t('otpFail'), 4000); });
  };
  el2.querySelector('[data-verify]').onclick = function () {
    var btn = this, code = ($('#nlCode').value || '').replace(/\D/g, '');
    if (code.length < 4) return;
    btn.disabled = true;
    postC('/api/otp.php', { action: 'verify', phone: phone, code: code, purpose: 'login', country: cc }).then(function () {
      CS.tok = null;                                   /* a fresh session: a fresh token */
      return syncStatus();
    }).then(function () {
      return syncRun();
    }).then(function (ok) {
      X.toast(ok ? t('synced') : t('failed')); openSync();
      if (MB.want) { var w = MB.want; MB.want = null; myBus(w.pnr, w.k); }
    }).catch(function (e) { btn.disabled = false; X.toast((e && e.message && !/^http/.test(e.message)) ? e.message : t('failed'), 4000); });
  };
}

/* ---------------------------------------------------------------------
   E · MY BUS (PNR) - the same door as track.php (WhereIs::authorised):
   the track link's key, or a login that owns the booking
--------------------------------------------------------------------- */
var MB = { pnr: '', k: '', timer: 0, m: null, st: null, want: null };
function openMyBus() {
  var saved = LSg.get('shg:nl:mybus', null), st = MB.st;
  var el = sheet('<h3><img src="/assets/img/bus-shg-sm.webp" alt="" style="width:40px;height:40px;border-radius:50%;object-fit:cover;background:#fff">' + esc(t('myBusT')) + '</h3>' +
    (st ? myBusHtml(st) : '') +
    '<input class="nl-url" id="nlPnr" type="text" autocapitalize="characters" spellcheck="false" maxlength="40" placeholder="' + esc(t('pnrPh')) + '" value="' + esc(MB.pnr || (saved && saved.pnr) || '') + '" style="margin-top:8px;font-size:16px">' +
    '<div class="nl-acts"><button class="nl-b cta" type="button" data-go>' + esc(t('pnrGo')) + '</button>' +
    (MB.pnr ? '<a class="nl-b" href="/track.php?pnr=' + encodeURIComponent(MB.pnr) + (MB.k ? '&k=' + encodeURIComponent(MB.k) : '') + '">' + esc(t('trackPage')) + '</a><button class="nl-b red" type="button" data-off>' + esc(t('pnrOff')) + '</button>' : '') + '</div>');
  el.querySelector('[data-go]').onclick = function () {
    var p = ($('#nlPnr').value || '').trim().toUpperCase();
    if (p) myBus(p, p === MB.pnr ? MB.k : (saved && saved.pnr === p ? saved.k : ''));
  };
  var off2 = el.querySelector('[data-off]'); if (off2) off2.onclick = function () { myBusOff(); sheetClose(); };
}
function myBusHtml(d) {
  var line = d.live && d.etaMin != null ? '🟢 ' + t('pnrLive') + ' · ' + t('pnrStop', { s: d.stop || '' }) + ' ' + d.etaMin + ' ' + t('min') + (d.km != null ? ' · ' + d.km + ' km' : '')
           : d.lastKnown && d.fixAge != null ? t('pnrLast', { n: ago(Date.now() - d.fixAge * 1000) })
           : d.stop ? t('pnrYour') + ': ' + d.stop + (d.stopTime ? ' · ' + d.stopTime : '') : '';
  return '<p style="color:var(--text,#EEF2FA);font-size:15px;margin-bottom:2px">' + esc(d.pnr || MB.pnr) + (d.label ? ' · ' + esc(d.label) : '') + '</p>' + (line ? '<p>' + esc(line) + '</p>' : '');
}
function myBus(pnr, k) {
  MB.pnr = pnr; MB.k = k || ''; LSg.set('shg:nl:mybus', { pnr: MB.pnr, k: MB.k });
  clearInterval(MB.timer);
  myBusTick(true);
  MB.timer = setInterval(function () { myBusTick(false); }, 20000);
}
function myBusOff() {
  clearInterval(MB.timer); MB.timer = 0; MB.pnr = ''; MB.k = ''; MB.st = null; LSg.set('shg:nl:mybus', null);
  if (MB.m) { MB.m.remove(); MB.m = null; }
}
function myBusTick(first) {
  if (!MB.pnr) return;
  X.fetchT('/api/whereis.php?pnr=' + encodeURIComponent(MB.pnr) + '&k=' + encodeURIComponent(MB.k), { credentials: 'same-origin', cache: 'no-store' }, 12000)
    .then(function (r) { return r.json().then(function (j) { return { s: r.status, j: j }; }); })
    .then(function (x) {
      if (!x.j || !x.j.ok) {
        if (first) {
          var keep = { pnr: MB.pnr, k: MB.k };
          myBusOff();
          var el = sheet('<h3>' + esc(t('myBusT')) + '</h3><p>' + esc(t('pnrNo')) + '</p><div class="nl-acts"><button class="nl-b wa" type="button" data-l>' + esc(t('syncLogin')) + '</button></div>');
          el.querySelector('[data-l]').onclick = function () { MB.want = keep; SY.st = { signedIn: false }; openSync(); };
        } else if (x.s === 404) myBusOff();                /* no longer this phone's to see: stop asking */
        return;
      }
      var d = MB.st = x.j.data, m = X.S.map;
      if (m && window.maplibregl && d.lat != null && d.lng != null) {
        if (!MB.m) {
          var el2 = wrapM('<img src="/assets/img/bus-shg-sm.webp" alt="S Hari Global"><span>' + esc(t('myBusT')) + '</span>', 'nl-bi nl-mine');
          el2.className = 'nl-bus';
          el2.addEventListener('click', function (e) { e.stopPropagation(); openMyBus(); });
          MB.m = new maplibregl.Marker({ element: el2, anchor: 'left' }).setLngLat([d.lng, d.lat]).addTo(m);
        } else moveMarker(MB.m, [d.lng, d.lat]);
        if (first) m.flyTo({ center: [d.lng, d.lat], zoom: Math.max(m.getZoom(), 11), duration: slow() ? 0 : 1200 });
      }
      if (first || (SHT.open && $('#nlPnr'))) openMyBus();
    }).catch(function () { if (first) X.toast(t('failed')); });
}

/* ---------------------------------------------------------------------
   PAGE WIRING
--------------------------------------------------------------------- */
function toggle(k) {
  if (k === 'bus') { BUS.on = !BUS.on; LSg.set('shg:nl:bus', BUS.on); if (BUS.on) busStart(); else busStop(); }
  if (k === 'rep') { REP.on = !REP.on; LSg.set('shg:nl:rep', REP.on); if (REP.on) { on('rep', repLive); repLoad(); } else repStop(); }
}
function tg(k, onv, label) {
  return '<button class="tg" type="button" data-nl="' + k + '" style="width:100%;text-align:left"><span>' + esc(label) + '</span><i class="sw' + (onv ? ' on' : '') + '"></i></button>';
}
function hooks() {
  var H = X.hook; if (!H) return;
  H('navFix', function (f, N) {
    if (SH && !SHx.N) {                                     /* a share from before a reload: same destination? */
      var to = X.S.to; if (to && SH.to && X.hav([to.lng, to.lat], [SH.to.lng, SH.to.lat]) < 300) { SHx.N = N; SHx.route = null; } else shareEnd('stop');
    }
    if (SH && SHx.N === N) sharePos(N, f, false);
    repAhead(N);
  });
  H('arrive', function (N) {
    if (SH && SHx.N === N) {
      var me = X.S.me, dest = SH.dest;
      sharePos(N, me ? { lat: me.lat, lng: me.lng, speed: 0, heading: null } : { lat: SH.to.lat, lng: SH.to.lng }, true);
      SH = null; shareSave();
      setTimeout(function () { arrivedSheet(dest); }, 1500);       /* after the page's own arrival card */
    }
  });
  H('navStart', function (N) { REP.said = {}; if (SH && SHx.N && SHx.N !== N) shareEnd('stop'); });
  H('navStop', function (N) { if (SH && SHx.N === N && !N.arrived) shareEnd('stop'); SHx.N = null; });
  H('navMoreHtml', function (N) {
    if (!N || N.preview) return '';
    return '<h3 style="margin-top:20px">' + esc(t('liveH')) + '</h3>' +
      '<button class="btn ghost" type="button" id="nlShareB" style="width:100%;height:52px">' + (SH ? '🔴 ' + esc(t('sharing')) : '📍 ' + esc(t('shareTrip'))) + '</button>' +
      '<button class="btn ghost" type="button" id="nlRepB" style="width:100%;height:52px;margin-top:8px">⚠️ ' + esc(t('report')) + '</button>';
  });
  H('navMoreBind', function () {
    var a = $('#nlShareB'), b = $('#nlRepB');
    if (a) a.onclick = function () { if (X.closeModal) X.closeModal(); if (SH) shareSheet(); else shareStart(); };
    if (b) b.onclick = function () { if (X.closeModal) X.closeModal(); repPick(); };
  });
  H('layersHtml', function () {
    return '<h3 style="margin-top:20px">' + esc(t('liveH')) + '</h3>' + tg('bus', BUS.on, t('busL')) + tg('rep', REP.on, t('repL')) +
      '<button class="tg" type="button" data-nlo="bus" style="width:100%;text-align:left"><span>🚌 ' + esc(t('myBusT')) + ' (PNR)</span></button>' +
      '<button class="tg" type="button" data-nlo="sync" style="width:100%;text-align:left"><span>☁️ ' + esc(t('syncT')) + '</span></button>';
  });
  H('layersBind', function (rerender) {
    document.querySelectorAll('#modalCard [data-nl]').forEach(function (b) { b.onclick = function () { toggle(b.dataset.nl); if (rerender) rerender(); }; });
    document.querySelectorAll('#modalCard [data-nlo]').forEach(function (b) {
      b.onclick = function () { if (X.closeModal) X.closeModal(); if (b.dataset.nlo === 'bus') openMyBus(); else openSync(); };
    });
  });
  H('styleLoad', function () { if (FW) setTimeout(followLayers, 0); });
}
function build() {
  css();
  var ctr = $('#ctrls');
  if (ctr) {
    var rb = document.createElement('button');
    rb.type = 'button'; rb.className = 'fab nl-fab'; rb.id = 'nlRep'; rb.setAttribute('aria-label', 'Report on the road');
    rb.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3.5L2.8 19.5h18.4z"/><path d="M12 9.5v4.5M12 16.8v.2"/></svg>';
    rb.onclick = repPick;
    var lp = document.createElement('button');
    lp.type = 'button'; lp.className = 'fab nl-livepill'; lp.id = 'nlLive'; lp.textContent = 'LIVE'; lp.hidden = true;
    lp.setAttribute('aria-label', 'Live trip share');
    lp.onclick = shareSheet;
    var after = $('#sgFab');
    ctr.insertBefore(rb, after ? after.nextSibling : ctr.firstChild);
    ctr.insertBefore(lp, rb);
    if (FW) rb.hidden = true;
  }
  pill();
}
function start() {
  if (X || !window.SHGNAV_X) return;
  X = window.SHGNAV_X;
  var ft = QS.get('follow');
  build();
  hooks();
  var ready = function () {
    if (ft && /^[a-f0-9]{32}$/.test(ft)) follow(ft);
    busStart();
    if (REP.on && !FW) { on('rep', repLive); repLoad(); }
    /* my bus: a track link (?pnr=&k=) opens it; a PNR asked for before keeps showing quietly */
    var qp = QS.get('pnr'), mb = LSg.get('shg:nl:mybus', null);
    if (qp && !FW) myBus(qp.trim().toUpperCase(), QS.get('k') || '');
    else if (mb && mb.pnr && !FW) { MB.pnr = mb.pnr; MB.k = mb.k || ''; myBusTick(false); MB.timer = setInterval(function () { myBusTick(false); }, 20000); }
  };
  if (!ft) {                                           /* a follow page is someone else's trip: no sync there */
    syncStatus().then(function (st) { if (st && st.signedIn) syncRun(); });
    setInterval(syncRun, 120000);
    addEventListener('online', function () { syncRun(); });
    document.addEventListener('visibilitychange', function () { if (document.hidden) syncRun(); });
  }
  window.SHGNAV_LIVE = { openSync: openSync, openMyBus: openMyBus };
  (function waitMap(n) { if (X.S.map || n > 60) ready(); else setTimeout(function () { waitMap(n + 1); }, 300); })(0);   /* DOM markers need the map, not its style */
  /* reports follow the rider: a new list after 10 km or 5 minutes */
  setInterval(function () {
    if (!REP.on || FW) return;
    var h = here();
    if (h && (!REP.at || X.hav(h, REP.at) > 10000 || Date.now() - REP.atT > 300000)) repLoad();
  }, 30000);
  if (QS.has('debug')) window.SHGLIVE = { LC: LC, SH: function () { return SH; }, BUS: BUS, REP: REP, FW: function () { return FW; }, repAdd: repAdd, shareStart: shareStart, enc: enc, dec: dec,
    SY: SY, MB: MB, syncRun: syncRun, localItems: localItems, myBus: myBus };
}
if (window.SHGNAV_X) start(); else document.addEventListener('shgnav:ready', start, { once: true });
})();
