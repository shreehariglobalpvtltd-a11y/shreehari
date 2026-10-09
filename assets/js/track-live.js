/* track-live.js — 9 Oct 2026: the live screen of the keyed track.php page
   (owner ask #12 "bus chalne live time ekdam attractive"). Owner: ROUTE.

   track.php paints the headline itself (server first, then paint() every
   15 s from /api/whereis.php) and hands this file window.SHG_TRACK =
   {T: strings, D: the WhereIs answer, fill}. This draws everything else:
   the chips, the progress bar, the two ETA tiles, the whole run as a rail,
   and the ticking IST clock / pickup countdown / time on the road.

   Honest by construction: the bar is solid only for a live GPS fix
   (progress.source = 'gps'), striped and labelled "as per timetable" when
   the trip has left without one, and absent before departure. ETAs carry
   their source. Nothing here invents a position. */
(function (S) {
  'use strict';
  if (!S || !S.T) return;
  var T = S.T, fill = S.fill;
  var $ = function (id) { return document.getElementById(id); };
  function esc(s) { return String(s == null ? '' : s).replace(/[<>&"]/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c]; }); }
  function dur(m) {
    m = Math.max(0, Math.round(m));
    var h = Math.floor(m / 60), r = m % 60;
    if (h >= 36) return fill(T.dd, [Math.floor(h / 24)]) + (h % 24 ? ' ' + fill(T.hrs, [h % 24]) : '');   // "3 दिन 9 घण्टा", not "81 घण्टा"
    return h ? (r ? fill(T.hm, [h, r]) : fill(T.hrs, [h])) : fill(T.mins, [r]);
  }
  function kmf(v) { return Math.round(v).toLocaleString('en-IN'); }
  function p2(n) { return (n < 10 ? '0' : '') + n; }
  function hm(ms) { try { return new Date(ms).toLocaleTimeString('en-GB', { timeZone: 'Asia/Kolkata', hour: '2-digit', minute: '2-digit' }); } catch (e) { return ''; } }
  var HI = { breakfast: '🍳', lunch: '🍛', dinner: '🍽️', meal: '🍽️', tea: '☕', fuel: '⛽', rest: '🛏️', border: '🛂' };
  var FL = '<svg class="fl" aria-hidden="true"><use href="#fIN"/></svg><svg class="fl np" aria-hidden="true"><use href="#fNP"/></svg>';

  /* One row of the run: time (≈ when approximate, +N on a later day), the dot, name, km / halt length / live ETA. */
  function row(r) {
    var isHalt = r.kind === 'halt' || !!r.halt;
    var t = r.time ? (r.approx ? '≈' : '') + esc(r.time) + (r.dayPlus > 0 ? '<sup>+' + (r.dayPlus | 0) + '</sup>' : '') : '—';
    var nm = isHalt
      ? (HI[r.halt] || '•') + ' ' + esc((T.hk || {})[r.halt] || '') + ' · ' + esc(fill(T.near, [r.name]))
      : esc(r.name) + (r.border ? FL : '');
    var m = [];
    if (r.km != null) m.push(kmf(r.km) + ' km');
    if (isHalt && r.haltMin) m.push(fill(T.mins, [r.haltMin]));
    if (r.eta != null) m.push('~' + dur(r.eta) + ' · GPS');
    if (r.border) m.push(T.border);
    var em = r.mine ? '<em>' + esc(r.mine === 'board' ? T.you : T.youOff) + '</em>'
      : (r.next ? '<em class="n">' + esc(T.next) + '</em>' : '');
    return '<li class="r' + (isHalt ? ' h' : '') + (r.passed ? ' ps' : '') + (r.next ? ' nx' : '') + '"' + (r.next ? ' aria-current="step"' : '') + '>'
      + '<time>' + t + '</time><i></i><div><b>' + nm + '</b>' + em + (m.length ? '<small>' + esc(m.join(' · ')) + '</small>' : '') + '</div></li>';
  }

  function extra(d) {
    if (!d) return;
    var pr = d.progress || {}, src = pr.source, live = !!d.live, c = '';
    if (live) c += '<span class="chip live">● ' + esc(T.liveB) + (d.fixAge != null ? ' · ' + (d.fixAge < 60 ? d.fixAge + ' s' : Math.round(d.fixAge / 60) + ' min') : '') + '</span>';
    else if (src === 'timetable') c += '<span class="chip tt">🕒 ' + esc(T.tt) + '</span>';
    if (d.delayMin > 0) c += '<span class="chip warn">⏰ +' + esc(fill(T.mins, [d.delayMin])) + '</span>';
    if (live && d.kmh != null) c += '<span class="chip">' + (d.kmh | 0) + ' km/h</span>';
    $('chips').innerHTML = c;

    var tot = d.totalKm, bar = $('bar'), dot = $('busDot'), on = src === 'gps' || src === 'timetable' || src === 'done';
    var pct = on && pr.pct != null ? Math.max(0, Math.min(100, pr.pct)) : 0;
    bar.className = 'bar' + (src === 'timetable' ? ' tt' : '');
    $('fill').style.width = pct + '%';
    dot.classList.toggle('hidden', !on || pr.pct == null);
    dot.style.left = pct + '%';
    $('pKm').innerHTML = tot ? '<b>' + esc(on && pr.km != null ? fill(T.kmOf, [kmf(pr.km), kmf(tot)]) : kmf(tot) + ' km') + '</b>' : '';
    $('pTag').textContent = src === 'gps' ? T.gps : (src === 'timetable' ? T.tt : '');
    $('ttNote').classList.toggle('hidden', src !== 'timetable');

    var tl = d.timeline || [], dl = (d.delayMin || 0) * 60000;
    var mine = tl.filter(function (r) { return r.mine === 'board'; })[0] || {};
    var s = '—', se = '';
    if (d.pickupPassed || mine.passed) s = '✓ ' + T.done;
    else if (live && d.etaMin != null) { s = '~' + dur(d.etaMin); se = T.gps; }
    else if (d.pickupTs) { s = hm(d.pickupTs * 1000 + dl); se = d.stop || ''; }
    $('tS').textContent = s; $('tSe').textContent = se;
    var de = d.destEta, done = d.state === 'arrived' || d.state === 'completed';
    if (de && de.name) $('tDl').textContent = fill(T.toDest, [de.name]);
    $('tD').textContent = done ? '✓' : (de ? (de.source === 'gps' ? '~' : '≈ ') + dur(de.min) : '—');
    $('tDe').textContent = done ? '' : (de ? (de.source === 'gps' ? T.gps : T.tt) : T.arrive);

    var h = '', ns = 0, nh = 0;
    tl.forEach(function (r) { if (r.kind === 'halt' || r.halt) nh++; else ns++; h += row(r); });
    $('rail').innerHTML = h;
    $('jrCnt').textContent = fill(T.places, [ns, nh]) + (tot ? ' · ' + kmf(tot) + ' km' : '');
    $('jr').classList.toggle('hidden', !tl.length);
    clock();
  }

  /* The second hand: IST clock, the countdown to THIS passenger's pickup, time on the road. */
  function clock() {
    var now = Date.now(), d = S.D || {}, L = '', V = '', X = '';
    try { $('clk').textContent = new Date(now).toLocaleTimeString('en-GB', { timeZone: 'Asia/Kolkata' }) + ' IST'; } catch (e) {}
    var on = d.bookingStatus === 'confirmed' && ['cancelled', 'arrived', 'completed'].indexOf(d.state) < 0;
    var dl = (d.delayMin || 0) * 60000, pk = d.pickupTs ? d.pickupTs * 1000 + dl : 0;
    var run = d.runningSinceMin != null && d.depTs ? (now - d.depTs * 1000 - dl) / 60000 : null;
    if (on && pk > now) {
      var x = Math.floor((pk - now) / 1000), dd = Math.floor(x / 86400);
      L = T.pickIn;
      V = (dd ? fill(T.dd, [dd]) + ' ' : '') + p2(Math.floor(x % 86400 / 3600)) + ':' + p2(Math.floor(x % 3600 / 60)) + ':' + p2(x % 60);
      X = run != null ? fill(T.running, [dur(run)]) : fill(T.pickAt, [d.stop || '', hm(pk)]);
    } else if (on && run != null && run >= 0) {
      L = T.onRoad; V = dur(run); X = (d.progress && d.progress.source === 'gps') ? T.gps : T.tt;
    }
    $('big').classList.toggle('hidden', !V);
    if (V) { $('bigL').textContent = L; $('bigV').textContent = V; $('bigS').textContent = X; }
  }

  S.extra = extra;
  S.clock = clock;
  extra(S.D);
  setInterval(function () { if (!document.hidden) clock(); }, 1000);
})(window.SHG_TRACK);
