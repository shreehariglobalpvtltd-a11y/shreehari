/* =====================================================================
   wa-direct.js — every WhatsApp button opens WhatsApp in ONE tap
   (22 Oct 2026, owner: "button click 2 choti garnu parx, direct khulne
   banau, teha help ni jodeko xa").

   A https://wa.me/… (or api.whatsapp.com/send) link first lands on
   WhatsApp's own "Continue to chat" page (the one with Help / Download at
   the top), so people tapped twice. This turns every such link, wherever
   it is on the page and whoever drew it, into a direct open:
     phone    whatsapp://send?phone=…&text=…   (the app itself)
     computer https://web.whatsapp.com/send?phone=…&text=…   (WhatsApp Web)
   On a phone where WhatsApp did not open within ~1.6 s, a small bar offers
   the old link. Ctrl/Cmd/middle clicks are left alone. Also exposes
   window.ShgWa = { parse(url), direct(phone, text), open(phone, text, fallbackUrl) }.
   ===================================================================== */
(function () {
  'use strict';
  if (window.ShgWa) return;
  var ua = navigator.userAgent || '';
  var MOBILE = /Android|iPhone|iPad|iPod|Mobile/i.test(ua) || (navigator.maxTouchPoints > 1 && /Macintosh/.test(ua));

  /** {phone, text} from a wa.me / api.whatsapp.com link, or null. */
  function parse(href) {
    var u;
    try { u = new URL(href, location.href); } catch (e) { return null; }
    var host = u.hostname.toLowerCase();
    if (host === 'wa.me' || host === 'www.wa.me') {
      return { phone: u.pathname.replace(/\D/g, ''), text: u.searchParams.get('text') || '' };
    }
    if ((host === 'api.whatsapp.com' || host === 'wa.link') && /^\/send\/?$/.test(u.pathname)) {
      return { phone: (u.searchParams.get('phone') || '').replace(/\D/g, ''), text: u.searchParams.get('text') || '' };
    }
    return null;
  }

  function direct(phone, text) {
    var q = (phone ? 'phone=' + String(phone).replace(/\D/g, '') + '&' : '') + 'text=' + encodeURIComponent(text || '');
    return (MOBILE ? 'whatsapp://send?' : 'https://web.whatsapp.com/send?') + q;
  }

  function fallbackBar(url) {
    var old = document.getElementById('shgWaFallback');
    if (old) old.parentNode.removeChild(old);
    var bar = document.createElement('div');
    bar.id = 'shgWaFallback';
    bar.style.cssText = 'position:fixed;left:12px;right:12px;bottom:calc(14px + env(safe-area-inset-bottom));z-index:2147483000;display:flex;gap:10px;align-items:center;justify-content:space-between;padding:10px 12px;border-radius:14px;background:#0d1b3e;color:#fff;font:600 14px/1.35 system-ui,sans-serif;box-shadow:0 10px 30px rgba(0,0,0,.35)';
    var t = document.createElement('span'); t.textContent = 'WhatsApp खुलेन? · WhatsApp did not open?';
    var a = document.createElement('a'); a.href = url; a.target = '_blank'; a.rel = 'noopener'; a.textContent = 'यहाँ थिच्नुहोस् · Open';
    a.style.cssText = 'flex:0 0 auto;min-height:40px;padding:0 12px;border-radius:10px;display:grid;place-items:center;background:#1faa59;color:#fff;text-decoration:none';
    a.setAttribute('data-shg-wa-plain', '1');
    bar.appendChild(t); bar.appendChild(a); document.body.appendChild(bar);
    setTimeout(function () { if (bar.parentNode) bar.parentNode.removeChild(bar); }, 9000);
  }

  /** Open WhatsApp now (call inside the tap). */
  function open(phone, text, fallbackUrl) {
    var url = direct(phone, text);
    if (!MOBILE) {
      // never leave this page (a half-filled form would be lost): a blocked pop-up gets the bar instead
      var w = null;
      try { w = (window.__shgWinOpen || window.open).call(window, url, '_blank'); } catch (e) {}
      if (w) { try { w.opener = null; } catch (e) {} } else { fallbackBar(url); }
      return;
    }
    var gone = false;
    var mark = function () { if (document.hidden) gone = true; };
    document.addEventListener('visibilitychange', mark);
    window.addEventListener('pagehide', mark);
    window.addEventListener('blur', mark);
    location.href = url;
    setTimeout(function () {
      document.removeEventListener('visibilitychange', mark);
      window.removeEventListener('pagehide', mark);
      window.removeEventListener('blur', mark);
      if (!gone && !document.hidden && fallbackUrl) fallbackBar(fallbackUrl);
    }, 1600);
  }

  // every wa.me link on the page, now and later (lists are drawn after load)
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    if (!a || a.hasAttribute('data-shg-wa-plain')) return;
    var p = parse(a.getAttribute('href'));
    if (!p) return;
    e.preventDefault();
    open(p.phone, p.text, a.href);
  });

  // code that opens a wa.me link itself: on a computer go straight to WhatsApp Web
  window.__shgWinOpen = window.open;
  if (!MOBILE) {
    window.open = function (url) {
      var p = typeof url === 'string' ? parse(url) : null;
      var args = Array.prototype.slice.call(arguments);
      if (p) args[0] = direct(p.phone, p.text);
      return window.__shgWinOpen.apply(window, args);
    };
  }

  window.ShgWa = { parse: parse, direct: direct, open: open, mobile: MOBILE };
})();
