/* ================================================================
   [JS] 22. VIP PRIVATE SLEEPER + THE ADVANCE-BOOKING OFFER.
   26 Sep 2026.

   THREE SMALL JOBS, and deliberately nothing else:

   1. THE OFFER CARD IS THE SERVER'S WORDS, NOT OURS.
      Admin -> Fares & offers decides whether the offer runs, the
      hours, the percentage, the end date and the sentence. All of
      that rides in SHG_BOOT.pricing.offer (index.php). This file
      fills the card from it and reveals the card ONLY when the
      server says the offer is live today. No offer running -> the
      card stays hidden and the page makes no claim. There is no
      copy of the rule here to drift out of step.

   2. THE VIP BUTTONS LEAD INTO THE PRIVATE FLOW.
      A "Book VIP" button that dropped the customer on the ordinary
      sharing seat map would be a lie. The two buttons carry
      data-bkmode + data-scroll (so the existing router unfolds and
      scrolls to the search card), and this file sets the one extra
      thing: window.SHG_VIP_INTENT = 'private'. 06-results.js reads
      it once when the seat view opens, instead of defaulting to
      sharing. The intent is consumed, so a later ordinary search is
      unaffected.

   3. NOTHING ANIMATES UNTIL IT IS ON SCREEN.
      vip.css keeps every keyframe under .is-live; this file adds
      that class from an IntersectionObserver and removes it again
      when the card scrolls away, so a visitor who never reaches the
      block pays for no animation, and a phone does not composite a
      cabin that is three screens down. No observer support -> the
      class is simply added, which is the old behaviour of a plain
      CSS animation.

   Self-contained, no dependencies, safe to load with `defer`.
================================================================ */
(function () {
  'use strict';
  if (window.SHG_VIP) { return; }

  var doc = document;
  var $id = function (id) { return doc.getElementById(id); };

  function boot() {
    try { return window.SHG_BOOT || {}; } catch (e) { return {}; }
  }
  function pricing() {
    var b = boot();
    return (b && b.pricing) || {};
  }

  /* Rupees the way the rest of the app writes them. inr() lives in
     02-config.js; this falls back so the card is never blank if the
     load order ever changes. */
  function money(n) {
    var v = Math.round(Number(n) || 0);
    if (typeof window.inr === 'function') {
      try { return window.inr(v); } catch (e) { /* fall through */ }
    }
    try { return '₹' + v.toLocaleString('en-IN'); } catch (e) { return '₹' + v; }
  }

  /* "10" -> "10%", "12.50" -> "12.5%" */
  function pct(n) {
    var v = Number(n) || 0;
    var s = (Math.round(v * 100) / 100).toString();
    return s + '%';
  }

  /* "2026-10-30" -> "30 Oct". Never throws on a blank or odd value. */
  function shortDate(iso) {
    if (!iso) { return ''; }
    var t = Date.parse(String(iso) + 'T00:00:00');
    if (isNaN(t)) { return String(iso); }
    try {
      return new Date(t).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
    } catch (e) { return String(iso); }
  }

  /* ---------------------------------------------------------------- *
   *  1. The offer card
   * ---------------------------------------------------------------- */
  function paintOffer() {
    var sec = $id('advOffer');
    if (!sec) { return; }

    var o = pricing().offer || {};
    /* `live` already folds in the ON switch, a positive percentage and
       today's date against the window (Fare::advanceOffer). The card asks
       nothing else, so the page and the checkout can never disagree about
       whether an offer is running. */
    if (!o.live || !(Number(o.percent) > 0)) {
      sec.hidden = true;
      return;
    }

    var el;
    if ((el = $id('aoPct')))   { el.textContent = pct(o.percent); }
    if ((el = $id('aoTitle'))) { el.textContent = String(o.title || ''); }

    el = $id('advOfferTitle');
    if (el && o.title) { el.textContent = String(o.title); }

    el = $id('aoText');
    if (el) {
      /* The office's own sentence, whatever language they typed it in. */
      el.textContent = String(o.text || '');
    }

    el = $id('aoUntil');
    if (el) {
      var bits = [];
      if (Number(o.hours) > 0) { bits.push(o.hours + 'h+'); }
      if (o.until) { bits.push(shortDate(o.until)); }
      if (Number(o.max) > 0) { bits.push('max ' + money(o.max)); }
      if (String(o.modes || 'all') === 'private') { bits.push('VIP private only'); }
      if (String(o.modes || 'all') === 'sharing') { bits.push('sharing only'); }
      if (bits.length) { el.textContent = bits.join(' · '); el.hidden = false; }
      else { el.hidden = true; }
    }

    sec.hidden = false;
  }

  /* ---------------------------------------------------------------- *
   *  2. "from X per cabin" on the VIP card
   * ---------------------------------------------------------------- */
  function paintVipPrice() {
    var el = $id('vipPrice');
    if (!el) { return; }
    var vip = pricing().vip || {};
    var single = Number(vip.single) || 0;
    if (single <= 0) { el.hidden = true; return; }   // no figure -> no claim
    el.innerHTML = 'from <b>' + money(single) + '</b> <small>per cabin</small>';
    el.hidden = false;
  }

  /* ---------------------------------------------------------------- *
   *  3. The private intent
   * ---------------------------------------------------------------- */
  function wantPrivate() {
    window.SHG_VIP_INTENT = 'private';
    /* When the customer is already past the search (Flow exists and has a
       route), set it directly too so the next seat render honours it. */
    try {
      if (window.Flow && typeof window.Flow === 'object') { window.Flow.preferMode = 'private'; }
    } catch (e) { /* not loaded yet: SHG_VIP_INTENT is enough */ }
  }

  function wireButtons() {
    var book = $id('vipBookBtn');
    var chk  = $id('vipCheckBtn');

    if (book) {
      book.addEventListener('click', function () { wantPrivate(); });
    }
    if (chk) {
      chk.addEventListener('click', function () {
        wantPrivate();
        /* If the traveller has already chosen where and when, run the search
           for them — "Check availability" should show seats, not a form. The
           router's data-scroll has already brought the card into view. */
        setTimeout(function () {
          var form = $id('searchForm');
          var date = $id('dateInput');
          if (!form || !date || !date.value) {
            if (date && typeof date.focus === 'function') { try { date.focus(); } catch (e) {} }
            return;
          }
          try {
            if (typeof form.requestSubmit === 'function') { form.requestSubmit(); }
            else { form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })); }
          } catch (e) { /* leave them on the filled form */ }
        }, 420);
      });
    }
  }

  /* ---------------------------------------------------------------- *
   *  4. Animate only what is on screen
   * ---------------------------------------------------------------- */
  function wireLiveness() {
    var targets = [];
    var vip = doc.querySelector('#vipHighlight .vip-card');
    var ao  = doc.querySelector('#advOffer .ao-card');
    if (vip) { targets.push(vip); }
    if (ao)  { targets.push(ao); }
    if (!targets.length) { return; }

    if (typeof window.IntersectionObserver !== 'function') {
      targets.forEach(function (el) { el.classList.add('is-live'); });
      return;
    }

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        en.target.classList.toggle('is-live', en.isIntersecting);
      });
    }, { rootMargin: '80px 0px', threshold: 0.12 });

    targets.forEach(function (el) { io.observe(el); });
  }

  function init() {
    paintOffer();
    paintVipPrice();
    wireButtons();
    wireLiveness();
  }

  if (doc.readyState === 'loading') { doc.addEventListener('DOMContentLoaded', init); }
  else { init(); }

  window.SHG_VIP = { init: init, paintOffer: paintOffer, wantPrivate: wantPrivate };
})();
