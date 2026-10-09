/* =====================================================================
   PREMIUM FEEL — 24 Sep 2026 (owner: "attractive, subtle sounds and
   vibration on important actions … professional and enjoyable rather
   than loud or distracting … provide an option to disable").

   WHAT WAS THERE BEFORE
   13-admin-routes.js already synthesised sounds (const SFX) and
   02-config.js already buzzed (function shgHaptic). Both were wired to
   real call sites — a seat tap, a failed validation, a confirmed booking
   — so the plumbing was right. What was wrong was the behaviour:

     • No off switch anywhere in the app. The owner asked for one, and a
       counter clerk issuing forty tickets in a shift needs it.
     • SFX played on EVERY .btn click and on EVERY toast, including
       errors. That is the "loud and distracting" the brief rules out.
     • Every tone started at full gain (g.gain.value = …) with no attack,
       so each one began with a click — the artefact that makes synthesised
       UI sound feel cheap.
     • The AudioContext was built on first use. A context created before
       the browser has seen a user gesture starts `suspended`, so on a lot
       of phones the sounds simply never played and nobody could tell.
     • Nothing throttled it: a fast double-tap stacked oscillators.

   This file does not delete any of that — it takes it over. SFX's methods
   and window.shgHaptic are re-pointed at the engine below, so all nine
   existing call sites keep working and immediately become quieter,
   click-free and switchable. Reverting is removing this one <script>.

   Silent mode: on iOS, Web Audio follows the hardware ring/silent switch,
   and on Android it follows the media volume — so "respect silent mode"
   is honoured by the platform as long as we never route through an
   <audio> element, which this does not. There are no sound files to
   download: every tone is generated, so this costs 0 bytes of network.

   Also here, because they are the same "how the shell feels" pass:
     • the compact language pill for the phone header (premium.css §2)
     • the Sound / Vibration switches, rendered into the mobile menu
     • the spoken Voice guide (8 Oct 2026) - its own switch, OFF by default
   ===================================================================== */

(function () {
  'use strict';

  var LS_SOUND = 'shg:sound';
  var LS_HAPTIC = 'shg:haptics';

  function read(key) {
    /* 5 Oct 2026 (owner: "app ekdam smooth, sound sahit"): sound and
       vibration are ON until the passenger switches them off. A saved '0'
       is respected for ever, so anyone who turned them off keeps silence. */
    try { var v = localStorage.getItem(key); return v === null ? true : v === '1'; } catch (e) { return true; }
  }
  function write(key, on) {
    try { localStorage.setItem(key, on ? '1' : '0'); } catch (e) {}
  }
  function reducedMotion() {
    /* 9 Oct 2026: the Appearance sheet's "Motion: reduced" counts too */
    if (document.documentElement.getAttribute('data-motion') === 'reduced') return true;
    try { return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); }
    catch (e) { return false; }
  }

  /* -------------------------------------------------------------------
     THE ENGINE
     -----------------------------------------------------------------
     One AudioContext, created on the first real user gesture and resumed
     on every later one (a backgrounded tab suspends it again). One master
     gain so the whole app can be ducked or muted in a single place, and a
     tiny lowpass so nothing is ever shrill on a phone speaker.
     ----------------------------------------------------------------- */
  var ac = null, master = null, lastAt = 0, lastSuccessAt = -1e9;

  function ctx() {
    if (ac) {
      /* Resume is a promise on some engines and returns undefined on
         others; either way a rejection here must not reach a caller. */
      if (ac.state === 'suspended') { try { var p = ac.resume(); if (p && p.catch) p.catch(function () {}); } catch (e) {} }
      return ac;
    }
    try {
      var AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) return null;
      ac = new AC();
      master = ac.createGain();
      master.gain.value = 0.9;
      var lp = ac.createBiquadFilter();
      lp.type = 'lowpass';
      lp.frequency.value = 5200;          /* takes the glassy edge off a phone speaker */
      master.connect(lp);
      lp.connect(ac.destination);
    } catch (e) { ac = null; }
    return ac;
  }

  /* One voice. `type` picks the timbre, and the gain envelope is a real
     attack/decay — 6ms in, exponential out — which is the whole
     difference between "a chime" and "a click followed by a chime". */
  function voice(freq, at, dur, vol, type) {
    var c = ctx();
    if (!c || !master) return;
    try {
      var t0 = c.currentTime + at;
      var o = c.createOscillator(), g = c.createGain();
      o.type = type || 'sine';
      o.frequency.setValueAtTime(freq, t0);
      g.gain.setValueAtTime(0.0001, t0);
      g.gain.exponentialRampToValueAtTime(Math.max(vol, 0.0002), t0 + 0.006);
      g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
      o.connect(g); g.connect(master);
      o.start(t0);
      o.stop(t0 + dur + 0.02);
    } catch (e) {}
  }

  /* The palette. Kept deliberately narrow: a company app is not a game,
     and five recognisable sounds are easier to live with than fifteen. */
  var VOICES = {
    /* Barely there. This one fires on ordinary buttons, so it has to be
       felt more than heard — 25ms, low gain, no tail. */
    tap: function () { voice(1180, 0, 0.025, 0.020, 'sine'); },
    /* A seat is picked: a warm two-note pluck, up. */
    select: function () { voice(659.25, 0, 0.055, 0.055, 'sine'); voice(987.77, 0.035, 0.07, 0.035, 'sine'); },
    /* A seat is released: the same two notes, down. */
    deselect: function () { voice(880, 0, 0.05, 0.04, 'sine'); voice(587.33, 0.035, 0.06, 0.03, 'sine'); },
    /* Something to read. One soft bell, not a fanfare. */
    notify: function () { voice(783.99, 0, 0.11, 0.05, 'triangle'); voice(1174.66, 0.02, 0.16, 0.022, 'sine'); },
    /* Refused. Two notes DOWN, quiet — a sawtooth buzz reads as a fault
       in the app rather than as "that field needs you". */
    error: function () { voice(392, 0, 0.1, 0.055, 'triangle'); voice(311.13, 0.09, 0.18, 0.045, 'triangle'); },
    /* Booked. A C-major arpeggio with a fifth on top, bell-like. This is
       the one people will hear at a counter forty times a day, so it is
       short (400ms all in) and ends clean. */
    success: function () {
      voice(523.25, 0, 0.16, 0.06, 'sine');
      voice(659.25, 0.075, 0.16, 0.055, 'sine');
      voice(783.99, 0.15, 0.30, 0.06, 'sine');
      voice(1567.98, 0.16, 0.34, 0.018, 'sine');   /* air, an octave + fifth up */
    },
    /* The ticket itself exists. Success, plus one held bell above it. */
    ticket: function () {
      VOICES.success();
      voice(1046.5, 0.30, 0.42, 0.030, 'triangle');
    },
    /* 9 Oct 2026: the names the Pixel pack added, in this pack's own voice */
    toggleOn: function () { voice(659.25, 0, 0.05, 0.04, 'sine'); voice(987.77, 0.04, 0.06, 0.03, 'sine'); },
    toggleOff: function () { voice(987.77, 0, 0.05, 0.035, 'sine'); voice(659.25, 0.04, 0.06, 0.028, 'sine'); },
    nav: function () { voice(1046.5, 0, 0.03, 0.022, 'sine'); },
    sheetOpen: function () { voice(523.25, 0, 0.06, 0.025, 'sine'); voice(783.99, 0.035, 0.08, 0.02, 'sine'); },
    sheetClose: function () { voice(783.99, 0, 0.06, 0.022, 'sine'); voice(523.25, 0.035, 0.08, 0.018, 'sine'); }
  };

  /* -------------------------------------------------------------------
     THE PIXEL PACK  (9 Oct 2026, owner: "sound haru pani Android Pixel
     jasto"). Still 0 bytes of audio: every sound is synthesised here.
     Three extra building blocks on top of voice():
       tone()  - a voice with a pitch glide (the "droplet" / tick shape)
       bar()   - a marimba-like bar: fundamental + the bar's inharmonic
                 2.76x / 5.4x partials, which die away first
       bell()  - a softer, longer bell into a tiny generated room
                 (a ConvolverNode on 0.45 s of decaying noise, mixed low)
       hiss()  - band-passed noise: a 10 ms tick, or a swept "whoosh"
     Everything ends in the same master gain + lowpass, the same 45 ms
     limiter and the same hidden-tab guard as the classic pack.
     ----------------------------------------------------------------- */
  var noiseBuf = null, roomIn = null;
  function noiseBuffer(c) {
    if (noiseBuf) return noiseBuf;
    var len = Math.floor(c.sampleRate), b = c.createBuffer(1, len, c.sampleRate), ch = b.getChannelData(0);
    for (var i = 0; i < len; i++) ch[i] = Math.random() * 2 - 1;
    return (noiseBuf = b);
  }
  function room(c) {
    if (roomIn) return roomIn;
    try {
      var len = Math.floor(c.sampleRate * 0.45), ir = c.createBuffer(2, len, c.sampleRate);
      for (var k = 0; k < 2; k++) {
        var data = ir.getChannelData(k);
        for (var i = 0; i < len; i++) data[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / len, 3.2);
      }
      var conv = c.createConvolver(), wet = c.createGain();
      conv.buffer = ir;
      wet.gain.value = 0.22;
      roomIn = c.createGain();
      roomIn.connect(conv); conv.connect(wet); wet.connect(master);
    } catch (e) { roomIn = null; }
    return roomIn;
  }
  function tone(f0, f1, at, dur, vol, type, wet) {
    var c = ctx();
    if (!c || !master) return;
    try {
      var t0 = c.currentTime + at, o = c.createOscillator(), g = c.createGain();
      o.type = type || 'sine';
      o.frequency.setValueAtTime(f0, t0);
      if (f1 && f1 !== f0) o.frequency.exponentialRampToValueAtTime(f1, t0 + dur * 0.6);
      g.gain.setValueAtTime(0.0001, t0);
      g.gain.exponentialRampToValueAtTime(Math.max(vol, 0.0002), t0 + 0.005);
      g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
      o.connect(g); g.connect(master);
      if (wet) { var r = room(c); if (r) g.connect(r); }
      o.start(t0);
      o.stop(t0 + dur + 0.03);
    } catch (e) {}
  }
  function bar(f, at, dur, vol, wet) {
    tone(f, 0, at, dur, vol, 'sine', wet);
    tone(f * 2.76, 0, at, dur * 0.35, vol * 0.28, 'sine', wet);
    tone(f * 5.4, 0, at, dur * 0.18, vol * 0.1, 'sine', wet);
  }
  function bell(f, at, dur, vol) {
    tone(f, 0, at, dur, vol, 'sine', true);
    tone(f * 2, 0, at, dur * 0.6, vol * 0.32, 'sine', true);
    tone(f * 2.76, 0, at, dur * 0.4, vol * 0.14, 'sine', true);
    tone(f * 4.07, 0, at, dur * 0.25, vol * 0.06, 'sine', true);
  }
  function hiss(at, dur, vol, fA, fB, q) {
    var c = ctx();
    if (!c || !master) return;
    try {
      var t0 = c.currentTime + at, src = c.createBufferSource(), f = c.createBiquadFilter(), g = c.createGain();
      src.buffer = noiseBuffer(c);
      f.type = 'bandpass';
      f.Q.value = q || 1;
      f.frequency.setValueAtTime(fA, t0);
      if (fB && fB !== fA) f.frequency.exponentialRampToValueAtTime(fB, t0 + dur);
      g.gain.setValueAtTime(0.0001, t0);
      g.gain.exponentialRampToValueAtTime(Math.max(vol, 0.0002), t0 + Math.min(0.04, dur * 0.4));
      g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
      src.connect(f); f.connect(g); g.connect(master);
      src.start(t0, Math.random() * 0.5);
      src.stop(t0 + dur + 0.03);
    } catch (e) {}
  }
  var PIXEL = {
    /* a glass tick: a fast downward glide plus 10 ms of filtered air */
    tap: function () { tone(1700, 1150, 0, 0.032, 0.022, 'sine'); hiss(0, 0.012, 0.010, 3400, 0, 2.5); },
    /* marimba, two bars up / down */
    select: function () { bar(783.99, 0, 0.22, 0.05); bar(1174.66, 0.05, 0.26, 0.036); },
    deselect: function () { bar(1046.5, 0, 0.18, 0.036); bar(698.46, 0.045, 0.22, 0.032); },
    /* switches: two short blips, rising for ON, falling for OFF */
    toggleOn: function () { tone(880, 0, 0, 0.06, 0.04, 'sine'); tone(1318.51, 0, 0.055, 0.09, 0.04, 'sine'); },
    toggleOff: function () { tone(1318.51, 0, 0, 0.06, 0.032, 'sine'); tone(880, 0, 0.055, 0.09, 0.032, 'sine'); },
    /* changing tab: a water droplet */
    nav: function () { tone(900, 1380, 0, 0.07, 0.034, 'sine'); tone(2760, 0, 0.012, 0.03, 0.006, 'sine'); },
    /* a sheet slides up / down: a soft filtered whoosh */
    sheetOpen: function () { hiss(0, 0.17, 0.05, 380, 2600, 0.9); tone(330, 520, 0.02, 0.14, 0.008, 'sine'); },
    sheetClose: function () { hiss(0, 0.15, 0.042, 2400, 420, 0.9); },
    /* something to read: two bells */
    notify: function () { bell(783.99, 0, 0.55, 0.04); bell(1174.66, 0.09, 0.7, 0.03); },
    /* refused: a soft low "bonk", never an alarm */
    error: function () { tone(330, 262, 0, 0.14, 0.05, 'triangle'); tone(220, 0, 0.11, 0.22, 0.042, 'triangle'); },
    /* booked: a bell arpeggio in the little room */
    success: function () { bell(1046.5, 0, 0.6, 0.042); bell(1318.51, 0.07, 0.6, 0.038); bell(1567.98, 0.14, 0.9, 0.04); },
    /* the ticket exists: success and one high bell above it */
    ticket: function () { PIXEL.success(); bell(2093, 0.27, 1.0, 0.022); }
  };
  var PACKS = { pixel: PIXEL, classic: VOICES };
  var LS_PACK = 'shg:soundpack';
  /* the passenger's pick, else the owner's default (Admin -> Settings ->
     app_sound_pack), else Pixel - whitelisted, so a junk value is ignored */
  function readPack() {
    var v = '';
    try { v = localStorage.getItem(LS_PACK) || ''; } catch (e) {}
    if (Object.prototype.hasOwnProperty.call(PACKS, v)) return v;
    try {
      var o = window.SHG_BOOT && window.SHG_BOOT.settings && window.SHG_BOOT.settings.app_sound_pack;
      if (o && Object.prototype.hasOwnProperty.call(PACKS, o)) return o;
    } catch (e) {}
    return 'pixel';
  }

  /* Vibration patterns. Android Chrome only — iOS Safari has no vibrate
     API at all, and calling it there is a silent no-op, not an error. */
  var BUZZ = {
    tap: 12,          /* 5 Oct 2026: 5 ms could not be felt on most phones */
    select: 18,
    deselect: 12,
    notify: [8, 30, 8],
    error: [26, 40, 26],
    success: [10, 34, 16],
    ticket: [12, 28, 12, 28, 26],
    toggleOn: 10,
    toggleOff: 8,
    nav: 8,
    sheetOpen: 6,
    sheetClose: 6
  };

  var Feel = {
    get sound() { return read(LS_SOUND); },
    get haptics() { return read(LS_HAPTIC); },
    /* 9 Oct 2026: which sound pack plays ('pixel' | 'classic') */
    get pack() { return readPack(); },
    packs: ['pixel', 'classic'],
    setPack: function (p) {
      if (!Object.prototype.hasOwnProperty.call(PACKS, p)) return;
      try { localStorage.setItem(LS_PACK, p); } catch (e) {}
      Feel.fire('select');                          /* a sample of the pack just chosen */
    },
    setSound: function (on) { write(LS_SOUND, on); if (on) Feel.play('toggleOn'); },
    setHaptics: function (on) { write(LS_HAPTIC, on); if (on) Feel.buzz('select'); },

    /* One entry point for both channels, so a call site never has to ask
       whether the device does sound, vibration, both or neither. */
    fire: function (kind) { Feel.play(kind); Feel.buzz(kind); },

    play: function (kind) {
      var V = PACKS[readPack()] || VOICES;
      if (!V[kind] || !read(LS_SOUND)) return;
      if (document.hidden) return;                  /* never from a background tab */
      /* Rate limit. Two sounds inside 45ms are one mis-tap, not two
         events, and stacking them is what makes UI audio sound broken. */
      var now = (window.performance && performance.now) ? performance.now() : Date.now();
      if (now - lastAt < 45) return;
      lastAt = now;
      if (kind === 'success') lastSuccessAt = now;
      try { V[kind](); } catch (e) {}
    },

    buzz: function (kind) {
      if (!read(LS_HAPTIC)) return;
      if (reducedMotion()) return;                  /* a buzz is motion too */
      try {
        if (!navigator || typeof navigator.vibrate !== 'function') return;
        navigator.vibrate(BUZZ[kind] != null ? BUZZ[kind] : BUZZ.tap);
      } catch (e) {}
    }
  };

  window.SHGFeel = Feel;

  /* -------------------------------------------------------------------
     VOICE GUIDE  (8 Oct 2026, D7-09 / Phase 8)
     -----------------------------------------------------------------
     For passengers who do not read easily: one short spoken line per
     booking step, in the app's language (ne / hi / en). OFF by default and
     separate from Sound - nothing speaks until the passenger turns on the
     menu's "🗣️ बोलेर बताउने · Voice guide" row, and that row only exists
     when the phone has speech (speechSynthesis).
       • The words are fixed t('vg_*') strings that repeat what the screen
         already says, so voice is never the only channel - and it never
         speaks a name, a phone number or a PNR (nothing is filled in).
       • Voice by language: en-IN / hi-IN / ne-NP, then any voice of that
         language; Nepali falls back to a Hindi (Devanagari) voice; with no
         match the browser's default reads it with the language tag set.
       • Rate 0.95. Stopped on every route change and when the tab is hidden.
       • A ticket that is not confirmed is never called "ready".
     ----------------------------------------------------------------- */
  var LS_VOICE = 'shg:voice';
  var VOICE_OK = (function () {
    try { return !!window.speechSynthesis && typeof window.SpeechSynthesisUtterance === 'function'; } catch (e) { return false; }
  })();
  var VG_TAG = { en: 'en-IN', hi: 'hi-IN', ne: 'ne-NP' };
  var vgVoices = [], vgSpeaking = false, vgLastSig = null, vgT = 0;

  function readVoice() {
    try { return localStorage.getItem(LS_VOICE) === '1'; } catch (e) { return false; }   /* default OFF */
  }
  function vgLang() { var l = currentLang(); return VG_TAG[l] ? l : 'en'; }
  function vgVoiceList() {
    try { var v = window.speechSynthesis.getVoices() || []; if (v.length) vgVoices = v; } catch (e) {}
    return vgVoices;
  }
  if (VOICE_OK) {
    try { window.speechSynthesis.addEventListener('voiceschanged', vgVoiceList); } catch (e) {}
  }
  function vgPick(lang) {
    var vs = vgVoiceList(), want = (VG_TAG[lang] || 'en-IN').toLowerCase(), pre = want.split('-')[0];
    function find(test) {
      for (var i = 0; i < vs.length; i++) { var l = String(vs[i].lang || '').replace('_', '-').toLowerCase(); if (test(l)) return vs[i]; }
      return null;
    }
    return find(function (l) { return l === want; })
      || find(function (l) { return l.split('-')[0] === pre; })
      || (lang === 'ne' ? (find(function (l) { return l === 'hi-in'; }) || find(function (l) { return l.split('-')[0] === 'hi'; })) : null);
  }
  function vgCancel() {
    if (!VOICE_OK || !vgSpeaking) return;           /* never cuts the navigator's own turn-by-turn voice */
    vgSpeaking = false;
    try { window.speechSynthesis.cancel(); } catch (e) {}
  }
  /* Feel.say('home') speaks t('vg_home'). Returns true when it spoke. */
  Feel.say = function (key) {
    if (!VOICE_OK || !readVoice() || document.hidden) return false;
    var vgKey = 'vg_' + key;                        /* vg_home, vg_seats ... (04-i18n.js) */
    var txt = (typeof t === 'function') ? t(vgKey) : '';
    if (!txt || txt === vgKey) return false;
    var lang = vgLang();
    try {
      window.speechSynthesis.cancel();
      var u = new SpeechSynthesisUtterance(txt);
      u.lang = VG_TAG[lang];
      u.rate = 0.95;
      var v = vgPick(lang);
      if (v) { try { u.voice = v; u.lang = v.lang || u.lang; } catch (e) {} }
      u.onend = u.onerror = function () { vgSpeaking = false; };
      vgSpeaking = true;
      window.speechSynthesis.speak(u);
      return true;
    } catch (e) { vgSpeaking = false; return false; }
  };
  Object.defineProperty(Feel, 'voice', { get: readVoice, enumerable: true });
  Feel.setVoice = function (on) {
    try { localStorage.setItem(LS_VOICE, on ? '1' : '0'); } catch (e) {}
    if (!on) { vgCancel(); return; }
    /* the switch tap is a user gesture, so the first line can play now */
    vgLastSig = vgSig();
    if (!Feel.say(vgKeyForView() || 'on')) Feel.say('on');
  };

  /* Which line belongs to the screen that is showing. Read from the view the
     router actually painted (a #/results without a search lands on home). */
  function vgKeyForView() {
    var v = document.querySelector('.view.active');
    if (!v) return null;
    switch (v.id) {
      case 'view-home': return 'home';
      case 'view-results': return 'results';
      case 'view-seats': return 'seats';
      case 'view-checkout': return 'checkout';
      case 'view-status': {
        var pnr = ((location.hash || '').split('/')[2] || '').split('?')[0], b = null;
        try { b = (typeof DB === 'object' && DB && DB.bookings || []).find(function (x) { return x.id === pnr; }); } catch (e) {}
        if (!b) return null;                        /* unknown: say nothing rather than guess */
        if (b.status === 'confirmed') return 'ticket';
        return b.status === 'pending' ? 'ticketWait' : null;   /* cancelled / expired / completed: silence */
      }
    }
    return null;
  }
  function vgSig() {
    var v = document.querySelector('.view.active');
    var id = v ? v.id : '';
    return id + (id === 'view-status' ? '|' + location.hash : '');
  }
  function watchVoiceGuide() {
    if (!VOICE_OK) return;
    window.addEventListener('hashchange', function () {
      vgCancel();
      clearTimeout(vgT);
      /* after the router (and any redirect it makes) has painted the view */
      vgT = setTimeout(function () {
        var sig = vgSig();
        if (sig === vgLastSig) return;              /* same screen: once per route */
        vgLastSig = sig;
        var k = vgKeyForView();
        if (k) Feel.say(k);
      }, 450);
    });
    document.addEventListener('visibilitychange', function () { if (document.hidden) vgCancel(); });
    window.addEventListener('pagehide', vgCancel);
    /* the first screen is the one already showing - no speech without a tap */
    setTimeout(function () { if (vgLastSig === null) vgLastSig = vgSig(); }, 1200);
  }

  /* -------------------------------------------------------------------
     TAKE OVER THE EXISTING CALL SITES
     -----------------------------------------------------------------
     `SFX` is a top-level `const` in 13-admin-routes.js, so it is a global
     lexical binding rather than a window property — it can be read by
     name from here (this script loads after it) and its methods replaced
     in place, which is what the nine bare `SFX.x()` call sites resolve
     through. `shgHaptic` is a top-level function DECLARATION, which does
     become a window property, so that one is replaced on window.
     ----------------------------------------------------------------- */
  /* 9 Oct 2026: a control that plays its own sound carries data-sfx="own";
     the generic .btn -> SFX.click tap (13-admin-routes.js) then stays quiet,
     so the 45 ms limiter never drops the sound that was meant. A capture
     listener notes the tap before that bubbling delegation runs. */
  var ownSfxAt = -1e9;
  var nowMs = function () { return (window.performance && performance.now) ? performance.now() : Date.now(); };
  document.addEventListener('click', function (e) {
    var t = e.target && e.target.closest ? e.target.closest('[data-sfx="own"]') : null;
    if (t) ownSfxAt = nowMs();
  }, true);
  try {
    if (typeof SFX === 'object' && SFX) {
      SFX.click = function () { if (nowMs() - ownSfxAt < 80) return; Feel.play('tap'); };
      SFX.select = function () { Feel.play('select'); };
      SFX.deselect = function () { Feel.play('deselect'); };
      SFX.success = function () { Feel.play('success'); };
      SFX.error = function () { Feel.play('error'); };
      SFX.notify = function () { Feel.play('notify'); };
      SFX.pop = function () { Feel.play('tap'); };
      SFX.ticket = function () { Feel.play('ticket'); };
    }
  } catch (e) { /* SFX not defined (a partial bundle) — the engine still stands */ }

  window.shgHaptic = function (kind) {
    Feel.buzz(kind === 'tap' ? 'tap' : kind);
  };

  /* 5 Oct 2026 (owner: "har chiz touch garda halka vibration, kei setting
     garnai naparos"): one light buzz for EVERY touch of something tappable,
     not only the handful of call sites that asked for one. On by default
     (read() above); the menu's Vibration switch still turns it off. Touch
     only - a mouse has nothing to buzz - and never for a disabled control. */
  var TAPPABLE = 'button,a[href],[role="button"],[role="tab"],[role="option"],[role="menuitem"],summary,select,label,'
    + 'input[type="checkbox"],input[type="radio"],input[type="submit"],input[type="button"],'
    + '.btn,.seat,.chip,.card[href],[onclick],[data-nav],[data-lang],[data-snstyle]';
  var lastTapBuzz = 0;
  document.addEventListener('pointerdown', function (e) {
    if (e.pointerType === 'mouse') return;
    var t = e.target && e.target.closest ? e.target.closest(TAPPABLE) : null;
    if (!t || t.disabled || t.getAttribute('aria-disabled') === 'true') return;
    var now = Date.now();
    if (now - lastTapBuzz < 70) return;
    lastTapBuzz = now;
    Feel.buzz('tap');
  }, { passive: true, capture: true });

  /* The AudioContext can only be built once the browser has seen a real
     gesture. Building it on the FIRST one (rather than on the first sound)
     means the first sound is on time instead of being the one that gets
     swallowed. */
  ['pointerdown', 'keydown', 'touchstart'].forEach(function (ev) {
    /* 5 Oct 2026: building the AudioContext took ~60 ms INSIDE the first tap
       (a quarter of a second on a slow phone) - the tap now returns at once
       and the engine is built right after it; later taps only resume it. */
    window.addEventListener(ev, function () {
      if (ac) { ctx(); return; }
      if (ctx._soon) return;
      ctx._soon = setTimeout(function () { ctx._soon = 0; ctx(); }, 0);
    }, { passive: true, once: false });
  });

  /* -------------------------------------------------------------------
     THE COMPACT LANGUAGE PILL  (phones — see premium.css §2)
     -----------------------------------------------------------------
     The header's EN / हिं / ने group is 38px tall and ~110px wide, which
     is why the topbar needed its own 50px row. On a phone it becomes one
     pill in the nav row showing the current language. The buttons inside
     carry class="lang-btn" and data-lang, so 04-i18n.js's existing
     delegated handler and applyLang()'s .on bookkeeping drive them with
     no new language code at all.
     ----------------------------------------------------------------- */
  var LANG_LABEL = { en: 'EN', hi: 'हिं', ne: 'ने', gu: 'GU' };
  var LANG_NAME = { en: 'English', hi: 'हिन्दी', ne: 'नेपाली' };

  function currentLang() {
    /* 8 Oct 2026 (D2-06 / D3-20): the language the page IS in (04-i18n.js's
       LANG) first - a first-time visitor reads Nepali, and the pill said EN. */
    try { if (typeof LANG === 'string' && LANG) return LANG; } catch (e) {}
    try { return localStorage.getItem('shg:lang') || document.documentElement.lang || 'ne'; }
    catch (e) { return document.documentElement.lang || 'ne'; }
  }

  function buildLangMini() {
    var nav = document.querySelector('.nav-inner');
    var burger = document.getElementById('hamburger');
    if (!nav || !burger || document.querySelector('.lang-mini')) return;

    var wrap = document.createElement('div');
    wrap.className = 'lang-mini';

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'lang-mini-btn';
    btn.setAttribute('aria-haspopup', 'true');
    btn.setAttribute('aria-expanded', 'false');
    btn.setAttribute('aria-label', 'Language · भाषा');

    var pop = document.createElement('div');
    pop.className = 'lang-mini-pop';
    pop.setAttribute('role', 'menu');

    ['en', 'hi', 'ne'].forEach(function (code) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'lang-btn';               /* 04-i18n.js listens for exactly this */
      b.setAttribute('data-lang', code);
      b.setAttribute('role', 'menuitem');
      b.textContent = LANG_NAME[code];
      pop.appendChild(b);
    });

    wrap.appendChild(btn);
    wrap.appendChild(pop);
    nav.insertBefore(wrap, burger);

    function label() {
      var l = currentLang();
      btn.textContent = LANG_LABEL[l] || LANG_LABEL.en;
    }
    label();
    /* applyStaticLang() (04-i18n.js) calls this on every language change,
       and <html lang> is watched as a second channel. */
    window.shgLangMiniLabel = label;
    try { new MutationObserver(label).observe(document.documentElement, { attributes: true, attributeFilter: ['lang'] }); } catch (e) {}

    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = wrap.classList.toggle('open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      Feel.fire('tap');
    });
    /* The pop's own buttons are handled by i18n's delegated listener; this
       only closes the menu and re-labels the pill afterwards. */
    pop.addEventListener('click', function () {
      wrap.classList.remove('open');
      btn.setAttribute('aria-expanded', 'false');
      setTimeout(label, 0);
    });
    document.addEventListener('click', function (e) {
      if (!wrap.contains(e.target)) {
        wrap.classList.remove('open');
        btn.setAttribute('aria-expanded', 'false');
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { wrap.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); }
    });
  }

  /* -------------------------------------------------------------------
     THE SWITCHES
     -----------------------------------------------------------------
     In the mobile menu, which is the one surface every visitor can reach
     without an account and without leaving the booking flow.
     ----------------------------------------------------------------- */
  function buildFeelSwitches() {
    var menu = document.getElementById('mobileMenu');
    if (!menu || menu.querySelector('.feel-row')) return;

    function row(labelText, isOn, onToggle, name) {
      var r = document.createElement('div');
      r.className = 'feel-row';
      if (name) r.setAttribute('data-feel', name);
      var l = document.createElement('span');
      l.className = 'feel-lbl';
      l.textContent = labelText;
      if (name) l.id = 'feelLbl-' + name;
      var sw = document.createElement('button');
      sw.type = 'button';
      sw.className = 'feel-sw' + (isOn ? ' on' : '');
      sw.setAttribute('role', 'switch');
      sw.setAttribute('aria-checked', isOn ? 'true' : 'false');
      if (name) sw.setAttribute('aria-labelledby', l.id); else sw.setAttribute('aria-label', labelText);
      sw.addEventListener('click', function (e) {
        e.stopPropagation();                      /* the menu closes on any other tap */
        var on = !sw.classList.contains('on');
        sw.classList.toggle('on', on);
        sw.setAttribute('aria-checked', on ? 'true' : 'false');
        onToggle(on);
        /* 9 Oct 2026: a switch answers with its own blip (Sound plays it itself) */
        if (name !== 'sound') Feel.play(on ? 'toggleOn' : 'toggleOff');
      });
      r.appendChild(l);
      r.appendChild(sw);
      return r;
    }

    menu.appendChild(row('🔊 आवाज · Sound', Feel.sound, Feel.setSound, 'sound'));
    menu.appendChild(row('📳 कम्पन · Vibration', Feel.haptics, Feel.setHaptics, 'haptics'));
    /* D7-09: the spoken step guide - its own switch, OFF until turned on,
       and absent on a phone that cannot speak. */
    if (VOICE_OK) menu.appendChild(row('🗣️ बोलेर बताउने · Voice guide', readVoice(), Feel.setVoice, 'voice'));

    /* A computer never sees the phone menu: one 🔊/🔇 button in the top bar. */
    var nav = document.querySelector('.nav-links');
    if (nav && !nav.querySelector('.feel-nav')) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'nav-bell feel-nav';
      var paint = function () {
        var on = read(LS_SOUND);
        b.textContent = on ? '🔊' : '🔇';
        b.setAttribute('aria-label', on ? 'Sound on — tap to mute' : 'Sound off — tap to turn on');
        b.title = on ? 'आवाज खुला · Sound on' : 'आवाज बन्द · Sound off';
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
      };
      b.addEventListener('click', function () { Feel.setSound(!read(LS_SOUND)); paint(); });
      paint();
      var sep = nav.querySelector('.nav-sep');
      nav.insertBefore(b, sep || null);
    }
  }

  /* -------------------------------------------------------------------
     TICKET ISSUED — the one moment that earns its own sound.
     -----------------------------------------------------------------
     A confirmed sale ends with `location.hash = '#/ticket/<pnr>'`
     (confirmSale in 05-router.js); the seat-map flow lands on #/status.
     Firing off the hash rather than from inside the booking code keeps
     this file out of the money path entirely: if anything here throws, a
     ticket has still been issued and the passenger still has it.
     ----------------------------------------------------------------- */
  function watchTicket() {
    var isTicket = function (h) { return h.indexOf('#/ticket/') === 0 || h.indexOf('#/status') === 0; };
    var lastHash = location.hash || '';
    window.addEventListener('hashchange', function () {
      var h = location.hash || '';
      /* one chime per booking: checkout already played "success" a moment ago */
      var t = (window.performance && performance.now) ? performance.now() : Date.now();
      if (isTicket(h) && !isTicket(lastHash) && t - lastSuccessAt > 4000) Feel.fire('ticket');
      lastHash = h;
    });
  }

  /* -------------------------------------------------------------------
     SHELL SOUNDS  (9 Oct 2026) - the phone menu sliding open / shut and a
     bottom-bar tab change. Observed, not wired into 05-router.js: the menu
     is opened and closed in several places, and its class is the truth.
     ----------------------------------------------------------------- */
  function watchShell() {
    var menu = document.getElementById('mobileMenu');
    if (menu && window.MutationObserver) {
      var was = menu.classList.contains('open');
      new MutationObserver(function () {
        var open = menu.classList.contains('open');
        if (open === was) return;
        was = open;
        Feel.play(open ? 'sheetOpen' : 'sheetClose');
      }).observe(menu, { attributes: true, attributeFilter: ['class'] });
    }
    document.addEventListener('click', function (e) {
      var b = e.target && e.target.closest ? e.target.closest('.bottom-nav .bn-item') : null;
      if (b) Feel.play('nav');
    });
  }

  function boot() {
    try { if (window.matchMedia && window.matchMedia('(max-width:760px)').matches) buildLangMini(); } catch (e) {}
    try { buildFeelSwitches(); } catch (e) {}
    try { watchTicket(); } catch (e) {}
    try { watchVoiceGuide(); } catch (e) {}
    try { watchShell(); } catch (e) {}
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
