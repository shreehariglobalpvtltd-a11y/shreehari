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
   ===================================================================== */

(function () {
  'use strict';

  var LS_SOUND = 'shg:sound';
  var LS_HAPTIC = 'shg:haptics';
  var LS_MANTRA = 'shg:mantra';       /* the opening blessing (26 Sep 2026) */

  function read(key) {
    /* Default ON — both were already on before this file existed, so a
       default of off would be a silent regression for anyone who liked
       them. '0' is the only value that means off. */
    try { return localStorage.getItem(key) !== '0'; } catch (e) { return true; }
  }
  function write(key, on) {
    try { localStorage.setItem(key, on ? '1' : '0'); } catch (e) {}
  }
  function reducedMotion() {
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
  var ac = null, master = null, lastAt = 0;

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

  /* A voice with its own attack. voice() above is fixed at 6ms, which is
     right for every UI sound and wrong for a swell: the temple bell needs
     no attack at all, the conch needs half a second. */
  function swell(freq, at, attack, dur, vol, type) {
    var c = ctx();
    if (!c || !master) return;
    try {
      var t0 = c.currentTime + at;
      var o = c.createOscillator(), g = c.createGain();
      o.type = type || 'sine';
      o.frequency.setValueAtTime(freq, t0);
      g.gain.setValueAtTime(0.0001, t0);
      g.gain.exponentialRampToValueAtTime(Math.max(vol, 0.0002), t0 + Math.max(0.004, attack));
      g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
      o.connect(g); g.connect(master);
      o.start(t0);
      o.stop(t0 + dur + 0.02);
    } catch (e) {}
  }

  /* A very short filtered noise burst — the only non-tonal texture here.
     It is what makes the ticket sound like a ticket being torn off the
     book rather than one more chime: 70ms of band-passed noise under the
     bells. The buffer is built once and reused, so repeated tickets at a
     busy counter cost one allocation, not forty. */
  var noiseBuf = null;
  function noise(at, dur, vol, centre) {
    var c = ctx();
    if (!c || !master) return;
    try {
      if (!noiseBuf) {
        var n = Math.floor(c.sampleRate * 0.4);
        noiseBuf = c.createBuffer(1, n, c.sampleRate);
        var d = noiseBuf.getChannelData(0);
        for (var i = 0; i < n; i++) d[i] = Math.random() * 2 - 1;
      }
      var t0 = c.currentTime + at;
      var s = c.createBufferSource(); s.buffer = noiseBuf;
      var bp = c.createBiquadFilter(); bp.type = 'bandpass';
      bp.frequency.value = centre || 2600; bp.Q.value = 0.9;
      var g = c.createGain();
      g.gain.setValueAtTime(0.0001, t0);
      g.gain.exponentialRampToValueAtTime(Math.max(vol, 0.0002), t0 + 0.008);
      g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
      s.connect(bp); bp.connect(g); g.connect(master);
      s.start(t0); s.stop(t0 + dur + 0.02);
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
    /* The ticket itself exists. Success, plus one held bell above it.
       25 Sep 2026 (owner: "ticket katne bela ma") — a 70ms tear of
       band-passed noise now opens it, so the counter hears the ticket
       come off the book and then the confirmation bells. The tear is
       quiet (0.03) and lands BEFORE the first bell, which is why the
       arpeggio is pushed back by 60ms rather than played on top of it. */
    ticket: function () {
      noise(0, 0.07, 0.030, 3000);
      voice(523.25, 0.06, 0.16, 0.06, 'sine');
      voice(659.25, 0.135, 0.16, 0.055, 'sine');
      voice(783.99, 0.21, 0.30, 0.06, 'sine');
      voice(1567.98, 0.22, 0.34, 0.018, 'sine');
      voice(1046.5, 0.36, 0.42, 0.030, 'triangle');
    },
    /* THE FOUR FACILITY STORIES (owner, 26 Sep 2026: "animation …
       sound pani hos … manche lai attractive lagos"). Each scene gets
       its own signature rather than one generic chime, because the
       whole point of the panel is that the four facilities feel like
       four different things. They are all quiet and all under 500ms —
       this plays when a finger opens a card, not in the background. */
    /* GPS: a radar sweep. Two pings, the second higher, like a
       contact returning. */
    storyGps: function () {
      voice(1318.51, 0, 0.09, 0.040, 'sine');
      voice(1760.00, 0.10, 0.13, 0.030, 'sine');
      voice(2637.02, 0.20, 0.20, 0.012, 'sine');
    },
    /* CHARGING: current arriving. A low surge under a bright spark. */
    storyCharge: function () {
      voice(110, 0, 0.26, 0.045, 'sawtooth');
      voice(880, 0.06, 0.09, 0.030, 'square');
      voice(1760, 0.14, 0.22, 0.022, 'triangle');
    },
    /* AC SLEEPER: the softest of the four — a warm fifth, no attack
       edge at all. It should feel like lying down. */
    storyAc: function () {
      voice(329.63, 0, 0.42, 0.034, 'sine');
      voice(493.88, 0.10, 0.44, 0.026, 'sine');
      voice(659.25, 0.22, 0.40, 0.016, 'sine');
    },
    /* SAFE TRAVEL: a calm two-note confirmation, the shape a hospital
       or a help desk uses — reassurance, not alarm. */
    storySafe: function () {
      voice(587.33, 0, 0.16, 0.045, 'triangle');
      voice(783.99, 0.13, 0.30, 0.038, 'sine');
    },
    /* THE APP OPENING (owner, 25 Sep 2026: "khulne bela ma"). A warm
       low-to-high fifth with a soft bell over it — a doorway, not a
       fanfare. It plays at most once per browser session (see WELCOME
       below), so re-entering a view never repeats it, and it is the
       quietest of the set because it arrives unasked. */
    welcome: function () {
      voice(261.63, 0, 0.55, 0.030, 'sine');
      voice(392.00, 0.09, 0.50, 0.026, 'sine');
      voice(783.99, 0.20, 0.55, 0.026, 'triangle');
      voice(1174.66, 0.30, 0.60, 0.012, 'sine');
    },
    /* THE BLESSING (owner, 26 Sep 2026: "boot ma Bishnu Bhagwan ko naam
       mantra, bhagwan ko sound"). One strike of a temple bell — the
       inharmonic partials a real ghanta rings with, fading over two and a
       half seconds — over a conch-like swell a fifth below. The mantra is
       spoken over it (MANTRA, further down). Once per visit, on the first
       touch, and only while Sound and Mantra are both on. */
    blessing: function () {
      swell(528, 0, 0.004, 2.6, 0.050, 'sine');
      swell(1056, 0, 0.004, 1.9, 0.026, 'sine');
      swell(1457, 0, 0.004, 1.3, 0.016, 'sine');
      swell(2150, 0, 0.004, 0.8, 0.008, 'sine');
      swell(196, 0.15, 0.55, 2.4, 0.034, 'triangle');
      swell(294, 0.25, 0.60, 2.2, 0.016, 'sine');
    }
  };

  /* Vibration patterns. Android Chrome only — iOS Safari has no vibrate
     API at all, and calling it there is a silent no-op, not an error. */
  var BUZZ = {
    tap: 5,
    select: 9,
    deselect: 6,
    notify: [8, 30, 8],
    error: [26, 40, 26],
    success: [10, 34, 16],
    ticket: [12, 28, 12, 28, 26],
    welcome: [6, 40, 10],
    storyGps: [6, 24, 6],
    storyCharge: [14, 18, 8],
    storyAc: 8,
    storySafe: [8, 26, 8]
  };

  var Feel = {
    get sound() { return read(LS_SOUND); },
    get haptics() { return read(LS_HAPTIC); },
    setSound: function (on) { write(LS_SOUND, on); if (on) Feel.play('tap'); },
    setHaptics: function (on) { write(LS_HAPTIC, on); if (on) Feel.buzz('select'); },

    /* One entry point for both channels, so a call site never has to ask
       whether the device does sound, vibration, both or neither. */
    fire: function (kind) { Feel.play(kind); Feel.buzz(kind); },

    play: function (kind) {
      if (!VOICES[kind] || !read(LS_SOUND)) return;
      if (document.hidden) return;                  /* never from a background tab */
      /* Rate limit. Two sounds inside 45ms are one mis-tap, not two
         events, and stacking them is what makes UI audio sound broken. */
      var now = (window.performance && performance.now) ? performance.now() : Date.now();
      if (now - lastAt < 45) return;
      lastAt = now;
      try { VOICES[kind](); } catch (e) {}
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

  /* -------------------------------------------------------------------
     THE MUSIC BED  (26 Sep 2026 — the brand film)
     -----------------------------------------------------------------
     "Light cinematic background music" with no audio file: a pad of
     three detuned sines an octave apart, a slow breathing LFO on its
     gain, and a filtered-noise whoosh for each scene change. It is
     deliberately faint (peak ~0.03) — a bed, not a track — and it is
     the one sound here that is long-lived, so it has its own start/stop
     rather than going through play(), and stop() is what close() calls.
     Nothing here plays if the Sound switch is off. */
  var bed = null;
  Feel.bed = {
    start: function () {
      if (bed || !read(LS_SOUND)) return;
      var c = ctx();
      if (!c || !master) return;
      try {
        var g = c.createGain();
        g.gain.setValueAtTime(0.0001, c.currentTime);
        g.gain.exponentialRampToValueAtTime(0.028, c.currentTime + 2.2);
        var lfo = c.createOscillator(), lg = c.createGain();
        lfo.type = 'sine'; lfo.frequency.value = 0.11; lg.gain.value = 0.009;
        lfo.connect(lg); lg.connect(g.gain);
        var oscs = [[110, 'sine', 1], [164.81, 'sine', .55], [220.4, 'triangle', .22]].map(function (v) {
          var o = c.createOscillator(), og = c.createGain();
          o.type = v[1]; o.frequency.value = v[0]; og.gain.value = v[2];
          o.connect(og); og.connect(g); o.start(); return o;
        });
        var lp = c.createBiquadFilter(); lp.type = 'lowpass'; lp.frequency.value = 900;
        g.connect(lp); lp.connect(master);
        lfo.start();
        bed = { g: g, oscs: oscs, lfo: lfo };
      } catch (e) { bed = null; }
    },
    stop: function () {
      if (!bed) return;
      var b = bed; bed = null;
      try {
        var c = ctx(); var t = c ? c.currentTime : 0;
        b.g.gain.cancelScheduledValues(t);
        b.g.gain.setValueAtTime(Math.max(b.g.gain.value, 0.0001), t);
        b.g.gain.exponentialRampToValueAtTime(0.0001, t + 0.9);
        setTimeout(function () { try { b.oscs.forEach(function (o) { o.stop(); }); b.lfo.stop(); } catch (e) {} }, 1000);
      } catch (e) {}
    }
  };
  /* A scene change: a short, soft sweep. */
  VOICES.whoosh = function () { noise(0, 0.42, 0.024, 900); noise(0.05, 0.3, 0.014, 2400); };
  BUZZ.whoosh = 5;

  window.SHGFeel = Feel;

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
  try {
    if (typeof SFX === 'object' && SFX) {
      SFX.click = function () { Feel.play('tap'); };
      SFX.select = function () { Feel.play('select'); };
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

  /* The AudioContext can only be built once the browser has seen a real
     gesture. Building it on the FIRST one (rather than on the first sound)
     means the first sound is on time instead of being the one that gets
     swallowed. */
  ['pointerdown', 'keydown', 'touchstart'].forEach(function (ev) {
    window.addEventListener(ev, function () { ctx(); welcome(); }, { passive: true, once: false });
  });

  /* -------------------------------------------------------------------
     THE OPENING SOUND  (owner, 25 Sep 2026)
     -----------------------------------------------------------------
     A browser will not let a page make a noise before the visitor has
     touched it, so "play a sound when the app opens" cannot literally
     mean document load — that sound is discarded by the autoplay policy
     and the owner would hear silence. It is therefore armed at load and
     released on the first real gesture (the same gesture that builds the
     AudioContext, one line above), which is the first moment the app is
     allowed to speak. sessionStorage keeps it to once per visit: a tab
     left open all day at the counter greets once, not on every reload of
     a view. The Sound switch in the menu silences it like everything
     else, because it goes through Feel.play(). */
  var WELCOME_KEY = 'shg:welcomed';
  var welcomed = false;
  try { welcomed = sessionStorage.getItem(WELCOME_KEY) === '1'; } catch (e) {}
  function welcome() {
    if (welcomed) return;
    welcomed = true;
    try { sessionStorage.setItem(WELCOME_KEY, '1'); } catch (e) {}
    /* One frame later: the gesture that released it is usually also a tap
       on a control that plays its own 'tap', and two voices in the same
       millisecond hit the 45ms rate limit — the greeting would lose. */
    setTimeout(function () {
      if (mantraOn()) {
        Feel.play('blessing'); Feel.buzz('welcome');
        setTimeout(sayMantra, 380);
      } else {
        Feel.fire('welcome');
      }
    }, 90);
  }
  Feel.welcome = welcome;

  /* -------------------------------------------------------------------
     THE MANTRA  (owner, 26 Sep 2026: "boot ma Bishnu Bhagwan ko naam mantra")
     -----------------------------------------------------------------
     "ॐ नमो भगवते वासुदेवाय", spoken by the handset's own Nepali or Hindi
     voice over the bell. No audio file, nothing downloaded. Three things
     silence it: the Sound switch, the Mantra switch beside it in the menu
     (remembered per device), and the office switch app_mantra_on, which
     the admin turns off for everyone from Settings → Site. */
  function mantraOn() {
    try {
      var st = window.SHG_BOOT && window.SHG_BOOT.settings;
      if (st && Object.prototype.hasOwnProperty.call(st, 'app_mantra_on')
          && (st.app_mantra_on === false || String(st.app_mantra_on) === '0')) return false;
    } catch (e) {}
    return read(LS_SOUND) && read(LS_MANTRA);
  }
  function sayMantra() {
    if (!mantraOn() || document.hidden) return;
    try {
      var ss = window.speechSynthesis;
      if (!ss || !window.SpeechSynthesisUtterance) return;
      var u = new SpeechSynthesisUtterance('ॐ नमो भगवते वासुदेवाय');
      u.lang = 'hi-IN'; u.rate = 0.78; u.pitch = 0.85; u.volume = 0.9;
      var vs = ss.getVoices ? ss.getVoices() : [], pick = null;
      for (var i = 0; i < vs.length; i++) {
        var l = String(vs[i].lang || '').toLowerCase();
        if (l.indexOf('ne') === 0) { pick = vs[i]; break; }
        if (!pick && (l.indexOf('hi') === 0 || l.indexOf('mr') === 0)) pick = vs[i];
      }
      if (pick) u.voice = pick;
      ss.speak(u);
    } catch (e) {}
  }
  Feel.mantra = mantraOn;
  Feel.setMantra = function (on) { write(LS_MANTRA, on); if (on) { Feel.play('blessing'); setTimeout(sayMantra, 380); } };

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
  var LANG_LABEL = { en: 'EN', hi: 'हिं', ne: 'ने' };
  var LANG_NAME = { en: 'English', hi: 'हिन्दी', ne: 'नेपाली' };

  function currentLang() {
    try { return localStorage.getItem('shg:lang') || document.documentElement.lang || 'en'; }
    catch (e) { return document.documentElement.lang || 'en'; }
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

    function row(labelText, isOn, onToggle) {
      var r = document.createElement('div');
      r.className = 'feel-row';
      var l = document.createElement('span');
      l.className = 'feel-lbl';
      l.textContent = labelText;
      var sw = document.createElement('button');
      sw.type = 'button';
      sw.className = 'feel-sw' + (isOn ? ' on' : '');
      sw.setAttribute('role', 'switch');
      sw.setAttribute('aria-checked', isOn ? 'true' : 'false');
      sw.setAttribute('aria-label', labelText);
      sw.addEventListener('click', function (e) {
        e.stopPropagation();                      /* the menu closes on any other tap */
        var on = !sw.classList.contains('on');
        sw.classList.toggle('on', on);
        sw.setAttribute('aria-checked', on ? 'true' : 'false');
        onToggle(on);
      });
      r.appendChild(l);
      r.appendChild(sw);
      return r;
    }

    menu.appendChild(row('🔊 आवाज · Sound', Feel.sound, Feel.setSound));
    menu.appendChild(row('📳 कम्पन · Vibration', Feel.haptics, Feel.setHaptics));
    menu.appendChild(row('🙏 मन्त्र · Mantra', read(LS_MANTRA), Feel.setMantra));
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
      if (isTicket(h) && !isTicket(lastHash)) Feel.fire('ticket');
      lastHash = h;
    });
  }

  function boot() {
    try { if (window.matchMedia && window.matchMedia('(max-width:760px)').matches) buildLangMini(); } catch (e) {}
    try { buildFeelSwitches(); } catch (e) {}
    try { watchTicket(); } catch (e) {}
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
