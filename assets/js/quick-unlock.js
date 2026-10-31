/*
 * quick-unlock.js — Android-style 3x3 pattern pad + PIN tab (1 Nov 2026).
 *
 * Used by admin/login.php (draw once, sign in) and admin/quick-unlock.php
 * (draw twice to set). Nodes are numbered 0-8, left to right, top to bottom;
 * the drawing is sent as "0-1-2-5" and only its bcrypt hash is stored
 * (includes/quickunlock.php). Passing over a dot joins it, like Android.
 *
 *   <div data-qk>                       tabs: [data-qk-tab="pattern|pin"]
 *     <form data-qk-form="pattern">     input[name=qk_val] receives the pattern
 *       <div data-qk-pad data-qk-mode="login|set"></div>
 *       <p data-qk-msg></p>             (set mode) prompts
 *     </form>
 *     <form data-qk-form="pin" hidden>…</form>
 *   </div>
 */
(function () {
  'use strict';

  var css = ''
    + '.qk-tabs{display:flex;gap:8px;justify-content:center;margin:12px 0 6px}'
    + '.qk-tabs button{flex:1;min-height:44px;border:1px solid #c9d3e4;background:#fff;border-radius:12px;font:700 14px/1 inherit;color:#33415c;cursor:pointer}'
    + '.qk-tabs button.on{background:#1d4ed8;border-color:#1d4ed8;color:#fff}'
    + '.qk-pad{position:relative;width:240px;height:240px;max-width:100%;margin:10px auto 0;touch-action:none;user-select:none;-webkit-user-select:none;border-radius:18px;background:#f3f6fb}'
    + '.qk-pad svg{position:absolute;inset:0;width:100%;height:100%;pointer-events:none}'
    + '.qk-dot{position:absolute;width:22px;height:22px;margin:-11px 0 0 -11px;border-radius:50%;background:#9fb0cc;transition:transform .12s,background .12s}'
    + '.qk-dot.on{background:#1d4ed8;transform:scale(1.35)}'
    + '.qk-pad.bad .qk-dot.on{background:#d33}'
    + '.qk-pad.good .qk-dot.on{background:#16a34a}'
    + '.qk-pin{letter-spacing:.5em;text-align:center;font-size:20px}'
    + '[data-qk-msg]{text-align:center;font-weight:700;min-height:1.2em}'
    + ':root[data-theme="dark"] .qk-pad{background:#1b2436}'
    + ':root[data-theme="dark"] .qk-tabs button{background:#1b2436;color:#dbe4f3;border-color:#33415c}';
  var st = document.createElement('style');
  st.textContent = css;
  document.head.appendChild(st);

  // ---- tabs ---------------------------------------------------------
  document.querySelectorAll('[data-qk]').forEach(function (box) {
    var tabs = box.querySelectorAll('[data-qk-tab]');
    tabs.forEach(function (t) {
      t.addEventListener('click', function () {
        tabs.forEach(function (x) { x.classList.toggle('on', x === t); });
        box.querySelectorAll('[data-qk-form]').forEach(function (f) {
          f.hidden = f.getAttribute('data-qk-form') !== t.getAttribute('data-qk-tab');
        });
        var pin = box.querySelector('[data-qk-form="pin"] input[name="qk_val"]');
        if (t.getAttribute('data-qk-tab') === 'pin' && pin) { pin.focus(); }
      });
    });
  });

  // ---- pattern pad --------------------------------------------------
  var SVGNS = 'http://www.w3.org/2000/svg';

  document.querySelectorAll('[data-qk-pad]').forEach(function (pad) {
    var form  = pad.closest('form');
    var out   = form ? form.querySelector('input[name="qk_val"]') : null;
    var msg   = form ? form.querySelector('[data-qk-msg]') : null;
    var mode  = pad.getAttribute('data-qk-mode') || 'login';
    var first = null;              // set mode: the first drawing, waiting for the confirm
    var dots = [], path = [], drawing = false, cur = null;

    var svg = document.createElementNS(SVGNS, 'svg');
    var line = document.createElementNS(SVGNS, 'polyline');
    line.setAttribute('fill', 'none');
    line.setAttribute('stroke', '#1d4ed8');
    line.setAttribute('stroke-width', '6');
    line.setAttribute('stroke-linecap', 'round');
    line.setAttribute('stroke-linejoin', 'round');
    line.setAttribute('opacity', '.55');
    svg.appendChild(line);
    pad.appendChild(svg);

    for (var i = 0; i < 9; i++) {
      var d = document.createElement('div');
      d.className = 'qk-dot';
      d.style.left = (16.67 + (i % 3) * 33.33) + '%';
      d.style.top  = (16.67 + Math.floor(i / 3) * 33.33) + '%';
      pad.appendChild(d);
      dots.push(d);
    }

    function centre(i) {
      var w = pad.clientWidth, h = pad.clientHeight;
      return [w * (0.1667 + (i % 3) * 0.3333), h * (0.1667 + Math.floor(i / 3) * 0.3333)];
    }
    function hit(x, y) {
      var r = pad.clientWidth * 0.13;
      for (var i = 0; i < 9; i++) {
        var c = centre(i);
        if ((x - c[0]) * (x - c[0]) + (y - c[1]) * (y - c[1]) <= r * r) { return i; }
      }
      return -1;
    }
    function add(i) {
      if (path.indexOf(i) !== -1) { return; }
      if (path.length) {
        // passing straight over a dot joins it (0 -> 2 takes 1), like Android
        var a = path[path.length - 1];
        var ar = Math.floor(a / 3), ac = a % 3, br = Math.floor(i / 3), bc = i % 3;
        if ((ar + br) % 2 === 0 && (ac + bc) % 2 === 0) {
          var mid = ((ar + br) / 2) * 3 + (ac + bc) / 2;
          if (mid !== a && mid !== i && path.indexOf(mid) === -1) { path.push(mid); dots[mid].classList.add('on'); }
        }
      }
      path.push(i);
      dots[i].classList.add('on');
      var ua = navigator.userActivation;
      if (navigator.vibrate && (!ua || ua.hasBeenActive)) { try { navigator.vibrate(8); } catch (e) {} }
    }
    function redraw() {
      var pts = path.map(function (i) { return centre(i).join(','); });
      if (drawing && cur) { pts.push(cur.join(',')); }
      line.setAttribute('points', pts.join(' '));
    }
    function reset(cls) {
      pad.classList.remove('bad', 'good');
      if (cls) { pad.classList.add(cls); }
      setTimeout(function () {
        path = []; cur = null;
        dots.forEach(function (d) { d.classList.remove('on'); });
        pad.classList.remove('bad', 'good');
        line.setAttribute('stroke', '#1d4ed8');
        redraw();
      }, cls ? 650 : 0);
    }
    function say(t) { if (msg) { msg.textContent = t; } }
    function pos(e) {
      var r = pad.getBoundingClientRect();
      return [e.clientX - r.left, e.clientY - r.top];
    }

    pad.addEventListener('pointerdown', function (e) {
      e.preventDefault();
      reset();
      drawing = true;
      try { pad.setPointerCapture(e.pointerId); } catch (x) {}
      var p = pos(e), i = hit(p[0], p[1]);
      if (i >= 0) { add(i); }
      cur = p; redraw();
    });
    pad.addEventListener('pointermove', function (e) {
      if (!drawing) { return; }
      var p = pos(e), i = hit(p[0], p[1]);
      if (i >= 0) { add(i); }
      cur = p; redraw();
    });
    function finish() {
      if (!drawing) { return; }
      drawing = false; cur = null; redraw();
      if (path.length < 4) {
        line.setAttribute('stroke', '#d33');
        say('कम्तीमा ४ थोप्ला जोड्नुहोस् · Join at least 4 dots.');
        reset('bad');
        return;
      }
      var val = path.join('-');
      if (mode === 'set') {
        if (first === null) {
          first = val;
          say('राम्रो! पक्का गर्न फेरि उही कोर्नुहोस् · Good — draw it once more to confirm.');
          reset('good');
          return;
        }
        if (first !== val) {
          first = null;
          line.setAttribute('stroke', '#d33');
          say('मिलेन — सुरुदेखि फेरि कोर्नुहोस् · They did not match, start again.');
          reset('bad');
          return;
        }
      }
      if (out) { out.value = val; }
      line.setAttribute('stroke', '#16a34a');
      pad.classList.add('good');
      say('…');
      if (form) { form.submit(); }
    }
    pad.addEventListener('pointerup', finish);
    pad.addEventListener('pointercancel', finish);
    window.addEventListener('resize', redraw);
  });
})();
