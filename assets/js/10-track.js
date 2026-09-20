
/* ================================================================
   [JS] 10e. TRACK MY BUS — honest route map + schedule data.
   No GPS hardware on the bus, so we never fake a live bus position —
   the map shows the real road route, real stops, and the passenger's
   own device location (see [JS] 14B UNIFIED LIVE MAP).
================================================================ */
/* "30h 45m" → minutes (fallback 20h: the owner's timetable says 18–22 h) */
function durationMinutes(r) {
  const m = String((r && r.duration) || '').match(/(\d+)\s*h(?:\s*(\d+)\s*m)?/i);
  return m ? (parseInt(m[1], 10) * 60 + (parseInt(m[2], 10) || 0)) : 1200;
}
/* Route waypoints with SVG positions and real distances (km).
   Additive fields (trackMapSVG only reads name/flag/x/y/km):
   m    = minutes offset from departure (schedule estimate incl. halts, ~30h30m corridor)
   amen = amenity flags at that halt {meal,fuel,wash,hotel,prayer,hosp,police,border} */
const ROUTE_STOPS = [
  { name: 'Surat',        flag: '🇮🇳', x: 24,  y: 296, km: 0,    m: 0,    lat: 21.1700, lng: 72.8310, amen: {} },
  /* town-level coordinates (ETA estimates only, never directions); m = the owner's 19 Sep 2026 timetable */
  { name: 'Kamrej',       flag: '',     x: 39,  y: 287, km: 20,   m: 30,   lat: 21.2710, lng: 72.9580, amen: {} },
  { name: 'Ankleshwar',   flag: '',     x: 53,  y: 278, km: 60,   m: 120,  lat: 21.6260, lng: 73.0150, amen: {} },
  { name: 'Bharuch',      flag: '',     x: 68,  y: 269, km: 75,   m: 180,  lat: 21.7050, lng: 72.9960, amen: {} },
  { name: 'Vadodara',     flag: '',     x: 82,  y: 259, km: 150,  m: 240,  lat: 22.3070, lng: 73.1810, amen: {} },
  { name: 'Anand',        flag: '',     x: 97,  y: 250, km: 190,  m: 330,  lat: 22.5650, lng: 72.9290, amen: {} },
  { name: 'Nadiad',       flag: '',     x: 111, y: 241, km: 210,  m: 420,  lat: 22.6920, lng: 72.8630, amen: {} },
  /* 'Emli Bhupal' (21:00, Taj Hotel) has no coordinates yet — deliberately
     skipped here; add it once real lat/lng are known. */
  { name: 'S Hari Parking, Nana Chiloda', flag: '', x: 126, y: 232, km: 260, m: 600, lat: 23.1710, lng: 72.6230, amen: {} },
  { name: 'Rupaidiha',    flag: '🛃',   x: 440, y: 78,  km: 1600, m: 1200, lat: 28.0600, lng: 81.6170, amen: { border: true, police: true, wash: true } }
];
function trackMapSVG(pct, reverse) {
  const p = Math.max(0, Math.min(100, pct == null ? 0 : pct));
  const stops = ROUTE_STOPS;
  const pathD = 'M' + stops.map(s => s.x + ',' + s.y).join(' L');
  let dotsSVG = '';
  stops.forEach((s, i) => {
    const isEnd = i === 0 || i === stops.length - 1;
    const isBorder = s.name === 'Rupaidiha';
    const isLunch = !!(s.amen && s.amen.lunch);
    const r = isEnd ? 6 : (isBorder ? 5 : (isLunch ? 5 : 3.5));
    const fill = isEnd ? (i === 0 ? 'var(--orange)' : '#5FA8E8') : (isBorder ? '#E91E63' : (isLunch ? '#FF9800' : 'var(--muted)'));
    const sw = isEnd ? 2 : 1.2;
    dotsSVG += '<circle cx="' + s.x + '" cy="' + s.y + '" r="' + r + '" fill="' + fill + '" stroke="#fff" stroke-width="' + sw + '" class="trk-stop-dot" data-stop="' + esc(s.name) + '"' + (isLunch ? ' data-lunch="1"' : '') + '/>';
    const anchor = i === 0 ? 'start' : (i === stops.length - 1 ? 'end' : 'middle');
    const lx = s.x + (i === 0 ? -2 : (i === stops.length - 1 ? 2 : 0));
    const ly = (i % 2 === 0) ? s.y - 14 : s.y + 20;
    const fs = isEnd ? '11' : '9';
    const fw = isEnd ? '700' : '600';
    dotsSVG += '<text x="' + lx + '" y="' + ly + '" text-anchor="' + anchor + '" fill="var(--ink)" font-size="' + fs + '" font-weight="' + fw + '" class="trk-stop-label" data-stop="' + esc(s.name) + '" style="cursor:pointer">'
      + (isLunch ? '🍽️ ' : '') + (s.flag ? s.flag + ' ' : '') + s.name + '</text>';
    if (i > 0) {
      const prev = stops[i - 1];
      const mx = (prev.x + s.x) / 2, my = (prev.y + s.y) / 2 - 5;
      const segKm = s.km - prev.km;
      dotsSVG += '<text x="' + mx + '" y="' + my + '" text-anchor="middle" fill="var(--muted)" font-size="7.5" opacity=".7">' + segKm + ' km</text>';
    }
  });
  return `<svg viewBox="0 0 540 310" role="img" aria-label="Route progress map" class="trk-map" style="max-width:100%;height:auto">
    <defs><linearGradient id="trkGrad" x1="0" y1="0" x2="1" y2="0"><stop offset="0%" stop-color="var(--orange)"/><stop offset="100%" stop-color="#5FA8E8"/></linearGradient></defs>
    <path d="M141,12 L125,32 L108,58 L88,78 L64,102 L40,128 L25,142 L47,150 L38,163 L62,172 L82,162 L96,175 L104,192 L118,226 L138,258 L163,290 L184,301 L206,278 L228,252 L246,232 L270,208 L300,190 L332,172 L352,166 L364,158 L372,146 L370,130 L380,120 L396,116 L424,120 L425,142 L440,134 L448,110 L468,120 L500,102 L516,92 L472,84 L436,98 L400,104 L378,106 L366,116 L330,114 L296,106 L261,101 L229,92 L219,76 L200,58 L172,38 Z"
          fill="var(--blue-50)" stroke="var(--line)" stroke-width="1.5" stroke-linejoin="round"/>
    <path d="M229,92 L246,76 L272,80 L302,86 L334,94 L368,100 L366,116 L330,114 L296,106 L261,101 Z"
          fill="rgba(220,20,60,.12)" stroke="var(--muted)" stroke-width="1.2" stroke-linejoin="round"/>
    <text x="135" y="220" text-anchor="middle" fill="var(--muted)" font-size="22" font-weight="800" letter-spacing="8" opacity=".15">INDIA</text>
    <text x="330" y="68" text-anchor="middle" fill="var(--muted)" font-size="11" font-weight="800" letter-spacing="4" opacity=".25">NEPAL</text>
    <path id="trkRoutePath" d="${pathD}" fill="none" stroke="url(#trkGrad)" stroke-width="3" stroke-dasharray="8 6" stroke-linecap="round"/>
    ${dotsSVG}
    <rect x="160" y="282" width="220" height="22" rx="11" fill="var(--card)" stroke="var(--line)" stroke-width="1"/>
    <text x="270" y="297" text-anchor="middle" fill="var(--ink)" font-size="10" font-weight="600">🚌 Total: ≈ 1,600 km · ~30 hrs · ${stops.length} stops</text>
    <g id="trkBusMarker" data-pct="${p}" data-rev="${reverse ? 1 : 0}">
      <circle r="14" fill="var(--card)" stroke="var(--orange)" stroke-width="2.5"/>
      <text y="5" text-anchor="middle" font-size="14">🚌</text>
    </g>
  </svg>`;
}
/* Place the bus marker at N% along the path (after the SVG is in the DOM). */
function positionTrackMarker() {
  const path = $('#trkRoutePath'), g = $('#trkBusMarker');
  if (!path || !g) return;
  const pct = parseFloat(g.getAttribute('data-pct')) || 0;
  const rev = g.getAttribute('data-rev') === '1';
  const L = path.getTotalLength();
  const pt = path.getPointAtLength((rev ? (100 - pct) : pct) / 100 * L);
  g.setAttribute('transform', 'translate(' + pt.x + ',' + pt.y + ')');
}
function fmtMinutes(min) {
  const h = Math.floor(min / 60), m = Math.round(min % 60);
  return (h ? h + 'h ' : '') + m + 'm';
}

/* ================================================================
   ROUTE OVERVIEW CARD (5 Sep 2026) — the "3D" map the owner asked for.
   Real geography: India and Nepal outlines are lat/lng polygons projected
   into the SVG, the two ends are the booking's own boarding town and
   destination (coordinates from the same CITY_COORDS the distance widget
   uses), distance / hours come from ROUTE_STOPS. A bus rides the dashed
   arc on an SVG animateMotion loop; the card itself tilts in perspective
   and floats. Everything is transform / opacity only, and every motion
   stops under prefers-reduced-motion (CSS). Shown first on the ticket and
   under the home search card; NOT a live position (the trip page has that).
================================================================ */
/* Accurate outlines (20 Sep 2026, owner: "map original jasto, pickup points saji sajhi"):
   India (Natural Earth 10m, India's official-boundary view) and Nepal, clipped to the
   Gujarat -> Nepal corridor, simplified and pre-projected to the 720x430 card with a
   25 deg N aspect: x = 20 + (lng - 67.3) * 30.22, y = 52 + (30.8 - lat) * 33.33.
   The route follows the road corridor (the Gujarat pickups, then Mehsana, Palanpur,
   Udaipur, Jaipur, Agra, Lucknow, Bahraich - the navigator's OSRM waypoints). */
const ROV_INDIA_D = 'M371 -34 377 -31 387 -30 385 -25 386 -22 384 -21 386 -20 392 -12 387 -6 383 -8 382 -6 377 -4 376 0 372 0 367 -3 366 -9 355 -6 357 -2 357 4 366 13 364 20 368 25 365 28 366 30 366 35 371 36 376 30 378 31 381 34 386 45 391 47 396 46 399 47 406 53 409 52 411 54 409 56 409 60 422 63 434 72 432 73 430 72 425 78 421 80 418 85 415 87 415 92 410 98 412 102 412 105 410 105 410 108 408 108 405 117 411 121 414 124 416 124 419 127 419 123 421 123 431 131 439 133 443 141 446 140 448 143 460 150 465 148 476 156 485 156 487 162 499 164 503 168 505 166 506 163 512 163 520 167 520 164 525 164 528 162 533 166 543 168 545 172 544 178 549 178 555 184 560 184 561 187 564 187 573 184 576 185 576 190 580 193 585 190 591 193 594 192 607 198 610 197 617 193 619 199 622 198 624 200 628 198 633 199 642 197 646 200 651 187 650 183 645 175 647 168 646 162 650 153 649 147 657 146 664 142 670 146 671 151 668 161 669 165 673 168 669 171 668 174 671 175 671 180 673 179 674 181 676 182 679 185 686 184 694 186 694 188 700 189 701 188 710 187 711 184 717 182 724 186 728 186 738 185 745 186 749 183 752 185 757 185 761 183 763 185 763 183 766 184 769 182 769 179 766 175 769 169 766 163 758 163 755 161 754 158 755 153 753 150 761 152 762 154 765 155 774 150 776 152 778 150 779 151 784 150 789 146 786 143 787 141 796 136 803 134 802 131 806 125 812 123 812 280 811 280 807 276 806 277 808 287 810 289 808 308 805 312 802 310 801 311 802 316 799 320 799 322 802 337 800 338 801 339 797 339 796 346 794 345 793 347 792 344 787 340 786 345 784 346 781 322 777 314 777 300 774 292 774 288 772 290 771 288 767 291 764 288 765 295 761 299 759 303 760 309 754 314 748 303 747 305 747 310 746 309 745 300 740 291 742 290 740 288 743 284 743 282 745 279 747 279 747 275 754 275 755 271 758 274 758 271 763 273 763 267 766 268 765 266 770 265 770 262 774 253 773 249 778 251 781 250 781 248 776 243 768 239 758 240 756 241 743 239 731 241 717 240 703 235 701 236 701 224 699 218 701 213 699 211 698 207 696 207 696 205 693 207 694 210 692 213 690 212 686 212 679 207 678 202 679 201 677 199 674 197 673 199 676 202 676 204 672 203 671 204 668 202 666 203 667 201 665 198 658 194 657 191 655 196 660 197 661 200 659 200 656 203 655 205 651 207 648 215 648 218 649 220 653 219 662 229 666 230 669 229 671 234 675 236 674 240 659 239 658 244 655 250 653 249 652 247 649 248 650 251 647 254 646 257 649 262 660 268 665 269 666 267 668 269 668 272 666 274 667 281 663 283 662 290 667 296 669 296 667 304 675 305 671 309 671 313 674 317 673 321 675 326 674 327 675 328 678 340 677 342 678 347 676 349 673 346 676 350 678 358 676 359 673 357 672 353 672 357 670 358 670 356 669 360 667 359 667 351 669 345 667 343 669 341 667 343 668 344 666 347 665 347 666 338 663 350 663 353 665 354 663 360 662 359 661 361 660 360 662 352 661 347 659 358 657 359 657 355 656 355 656 357 655 358 654 352 655 360 653 360 651 356 652 352 650 347 652 343 652 340 646 337 643 331 645 337 650 339 651 342 647 345 645 350 641 354 637 357 621 360 613 368 610 373 611 376 614 385 612 386 615 387 615 388 614 389 615 390 617 389 608 396 607 400 610 398 606 402 608 402 600 406 597 413 593 410 591 410 590 407 590 410 596 413 588 417 569 423 568 422 572 420 572 416 568 415 563 419 558 428 559 429 562 427 561 426 562 423 564 424 567 422 566 425 564 425 565 426 572 422 560 429 549 441 547 440 547 443 548 442 547 444 538 457 532 461 533 462 535 460 529 466 528 469 518 474 509 482 508 481 505 488 502 488 504 490 501 491 502 492 479 505 473 512 200 512 200 507 198 502 200 503 196 493 198 492 196 491 196 487 192 478 194 476 193 477 192 476 190 471 190 469 194 472 194 470 192 469 190 467 189 461 193 463 191 460 190 460 188 456 188 452 190 451 192 454 192 450 189 449 194 445 192 445 191 440 190 444 188 445 187 449 186 447 185 447 187 444 187 440 186 440 187 437 185 439 186 435 191 436 194 438 192 435 186 434 185 433 185 429 189 428 186 428 184 426 185 428 184 427 183 421 184 422 184 419 183 420 182 417 185 403 189 394 189 388 191 387 190 385 189 385 189 383 187 384 187 381 189 379 187 380 185 378 188 377 187 374 183 376 184 373 182 375 181 375 184 372 182 370 181 370 180 367 182 367 179 366 185 364 184 363 180 365 187 357 196 353 190 356 179 356 179 350 184 346 178 348 177 346 179 339 185 340 187 338 190 338 190 336 185 337 179 336 176 338 176 337 174 337 174 333 172 337 169 335 167 336 168 337 169 336 169 337 171 338 171 343 167 346 165 345 165 347 163 347 167 348 167 351 164 349 165 351 162 350 162 352 167 352 171 358 169 364 165 368 165 372 136 386 131 388 124 388 112 381 103 374 96 363 84 353 83 349 82 350 77 346 70 335 71 331 74 329 74 332 77 332 77 334 78 336 86 334 87 330 90 333 94 329 95 331 101 327 107 327 113 315 117 313 117 309 116 308 114 310 113 314 111 314 109 312 107 314 106 312 106 314 97 317 93 320 89 320 77 317 67 311 61 307 59 304 60 303 58 303 58 301 59 301 57 300 57 298 56 299 54 297 56 295 54 294 54 296 54 291 56 291 55 290 66 283 60 284 54 288 51 292 45 292 49 290 47 289 47 286 46 287 49 281 50 282 52 280 52 281 63 280 64 268 65 268 67 271 68 268 70 270 73 269 76 270 88 269 93 273 101 273 103 272 105 269 114 265 119 265 119 270 124 271 127 270 127 268 129 267 132 267 134 265 131 263 130 259 134 256 127 240 121 232 121 223 119 222 112 223 109 222 104 213 106 205 106 194 103 192 96 193 87 187 85 185 88 174 101 160 105 152 112 145 118 145 121 148 123 154 127 155 139 150 149 150 158 147 159 141 167 134 170 124 173 120 190 110 199 94 203 81 215 77 221 72 218 66 224 61 225 58 227 57 232 49 235 49 242 43 239 41 238 39 241 30 237 22 239 18 244 14 246 14 248 11 251 11 254 9 258 9 262 7 264 3 260 -1 253 -4 243 -4 242 -8 243 -16 240 -13 233 -14 232 -15 221 -22 217 -22 212 -25 212 -27 210 -29 210 -34 211 -40 210 -42 210 -52 208 -55 207 -64 204 -67 205 -71 395 -71 396 -70 395 -69 389 -68 390 -62 388 -61 389 -58 386 -55 371 -54 374 -42 370 -42 371 -35ZM672 359 673 361 671 361 671 358ZM647 355 650 350 651 355 650 358 647 357 647 356ZM665 348 666 348 665 352 663 351 664 349ZM648 350 649 347 649 350Z';
const ROV_NEPAL_D = 'M649 150 651 150 650 153 646 162 647 168 645 175 650 183 651 187 646 200 642 197 633 199 628 198 624 200 622 198 619 199 617 193 610 197 607 198 594 192 591 193 585 190 580 193 576 190 576 185 573 184 564 187 561 187 560 184 555 184 549 178 544 178 545 172 543 168 533 166 528 162 525 164 520 164 520 167 512 163 506 163 505 166 503 168 499 164 487 162 485 156 476 156 465 148 460 150 448 143 446 140 443 141 439 133 431 131 421 123 419 123 419 127 416 124 414 124 411 121 405 117 408 108 410 108 410 105 412 105 412 102 410 98 415 92 415 87 418 85 421 80 425 78 431 72 432 73 434 72 437 78 440 79 442 74 445 73 446 66 449 68 453 65 458 67 466 67 467 71 469 73 469 77 474 78 481 81 490 90 494 90 496 93 500 91 502 94 502 96 505 97 510 106 514 107 516 104 522 102 527 103 530 106 529 107 530 110 532 111 531 114 536 117 538 121 543 122 545 124 545 125 549 127 553 127 557 124 560 126 557 131 558 135 566 137 568 135 573 137 576 134 577 138 581 141 585 149 589 148 587 143 590 140 591 145 598 148 601 147 602 142 605 142 607 143 608 145 611 145 613 147 616 147 620 151 624 151 626 150 627 152 631 151 637 152 641 148 648 150Z';
const ROV_ROUTE_D = 'M187.2,373.0 L191.0,369.6 L192.7,357.8 L192.1,355.2 L197.7,335.1 L190.1,326.5 L188.1,322.3 L180.9,306.3 L173.2,292.4 L175.3,272.8 L213.8,259.2 L276.5,181.6 L343.6,172.8 L432.4,183.8 L452.1,159.6 L452.7,143.3';
const ROV_VIA = {"Udaipur":[213.8,259.2],"Jaipur":[276.5,181.6],"Agra":[343.6,172.8],"Lucknow":[432.4,183.8]};
function rovProject(lat, lng) { return [Math.round((20 + (lng - 67.3) * 30.222) * 10) / 10, Math.round((52 + (30.8 - lat) * 33.333) * 10) / 10]; }
function rovCoords(name) {
  const n = String(name || '').toLowerCase();
  const key = Object.keys(CITY_COORDS).find(c => n.indexOf(c) >= 0) || (n.indexOf('vadodara') >= 0 ? 'barauda' : (n.indexOf('bhupal') >= 0 || n.indexOf('emli') >= 0 ? 'ahmedabad' : (n.indexOf('hari parking') >= 0 || n.indexOf('nana') >= 0 ? 'chiloda' : null)));
  return key ? { lat: CITY_COORDS[key][0], lng: CITY_COORDS[key][1] } : null;
}
function rovCode(name) {
  return ({ Surat: 'STV', Rupaidiha: 'RPD', Ahmedabad: 'AMD', Nepalgunj: 'NPJ', Mehsana: 'MSN', Baroda: 'BRC', Vadodara: 'BRC', Kamrej: 'KMJ', Ankleshwar: 'AKV', Bharuch: 'BRH', Anand: 'ANA', Nadiad: 'NAD' })[String(name || '').trim()]
    || String(name || '---').replace(/[^A-Za-z]/g, '').slice(0, 3).toUpperCase();
}
function rovStopKm(name) {
  const n = String(name || '').toLowerCase();
  const s = ROUTE_STOPS.find(x => n.indexOf(x.name.toLowerCase().split(',')[0]) >= 0 || x.name.toLowerCase().indexOf(n.split(/[ ·(]/)[0]) === 0);
  return s ? { km: s.km, m: s.m } : null;
}
/* opts: { from, to, reverse?, compact?, title? } — from/to are town names. */
/* The pickups (or, coming back, the drops) of the one daily coach, read from the
   same route lines the pickers use: "Kamrej · Shiv Shakti Hotel @ 13:30 [lat,lng]". */
function rovStopList(back) {
  const nep = (typeof isNepalPoint === 'function') ? isNepalPoint : (n => /rupaidiha/i.test(n));
  const r = (DB.routes || []).find(x => x && x.active && (back ? nep(x.from) : nep(x.to)));
  const lines = r ? (back ? r.drop : r.boarding) : [];
  return (lines || []).map(function (ln) {
    const raw = String(ln);
    /* The optional [lat,lng] suffix is the SURVEYED boarding pin - the same
       route_stops row /api/timetable.php serves. A stop without one gets no
       📍 rather than a guessed town centre (20 Sep 2026). */
    const geo = raw.match(/\[\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*\]\s*$/);
    const s = raw.replace(/\s*\[[^\]]*\]\s*$/, '');
    const at = s.split(' @ '), left = at[0].split(' · ');
    return { town: left[0].trim(), mark: (left[1] || '').trim(), time: (at[1] || '').trim(),
             lat: geo ? +geo[1] : null, lng: geo ? +geo[2] : null };
  }).filter(x => x.town && !nep(x.town));
}
/* A pin only where the town's coordinates are known (CITY_COORDS) — never a guess. */
function rovPin(name) {
  const n = String(name || '').toLowerCase();
  const key = Object.keys(CITY_COORDS).find(c => n.indexOf(c) >= 0);
  return key ? rovProject(CITY_COORDS[key][0], CITY_COORDS[key][1]) : null;
}
/* opts: { from, to, compact?, stops? } — from/to are town names; stops adds the
   pickup / drop list under the card (home only). */
function routeOverviewSVG(opts) {
  opts = opts || {};
  const fromName = String(opts.from || 'Surat'), toName = String(opts.to || 'Rupaidiha');
  const isNep = n => /rupaidiha|nepalgunj|kohalpur/i.test(n);
  const back = isNep(fromName);
  const town = back ? toName : fromName;
  const T = rovPin(town) || rovProject(21.17, 72.831), R = rovProject(28.06, 81.617);
  const ka = rovStopKm(fromName), kb = rovStopKm(toName);
  const km = (ka && kb) ? Math.abs(kb.km - ka.km) : 1600, hrs = (ka && kb) ? Math.round(Math.abs(kb.m - ka.m) / 60) : 20;
  const uid = 'rov' + Math.floor(Math.random() * 1e6);
  const list = rovStopList(back);
  const dots = list.map(s => (s.lat != null ? rovProject(s.lat, s.lng) : rovPin(s.town))).filter(Boolean)
    .map(p => '<circle cx="' + p[0] + '" cy="' + p[1] + '" r="3.2" class="rov-pick"/>').join('');
  const via = Object.keys(ROV_VIA).map(k => '<circle cx="' + ROV_VIA[k][0] + '" cy="' + ROV_VIA[k][1] + '" r="2.6" fill="#9fc4f0"/>'
    + '<text x="' + (ROV_VIA[k][0] + 7) + '" y="' + (ROV_VIA[k][1] - 7) + '" class="rov-via">' + k + '</text>').join('');
  const dur = Math.max(8, Math.min(14, 6 + km / 250)) + 's';
  const townLbl = town.split(',').pop().replace(/\(.*\)/, '').trim() || town;
  let listHTML = '';
  if (opts.stops && list.length) {
    const same = s => { const a = s.town.toLowerCase(), b = town.toLowerCase(); return a.indexOf(b) === 0 || b.indexOf(a) === 0; };
    const f12 = tm => (tm && typeof fmt12h === 'function') ? fmt12h(tm) : tm;
    const tt = (k, en) => { const v = (typeof t === 'function') ? t(k) : ''; return v && v !== k ? v : en; };
    /* 📍 only where the stop carries a surveyed pin - it opens the app's own
       map on the exact boarding spot (see the data-navto listener at the end
       of this file). A stop still waiting for its coordinates shows no 📍. */
    const pinT = tt('waPin', 'Show on the map');
    const pin = s => s.lat == null ? '' : '<button type="button" class="rov-pin" data-navto="' + s.lat + ',' + s.lng
      + '" data-navto-label="' + esc(s.town + (s.mark ? ' · ' + s.mark : '')) + '" title="' + esc(pinT)
      + '" aria-label="' + esc(pinT) + '">📍</button>';
    const rows = list.map(s => '<li' + (same(s) ? ' class="on"' : '') + '><b>' + esc(f12(s.time)) + '</b><span>' + esc(s.town)
      + pin(s) + (s.mark ? '<small>' + esc(s.mark) + '</small>' : '') + '</span></li>');
    const border = '<li class="end"><b>' + (back ? esc(f12('18:00')) : '🛃') + '</b><span>Rupaidiha<small>' + esc(tt('rovLast', 'India–Nepal border')) + '</small></span></li>';
    listHTML = '<div class="rov-stops"><div class="rov-stops-h">📍 ' + esc(back ? tt('rovDrops', 'Drop points') : tt('rovPickups', 'Pickup points'))
      + '</div><ol>' + (back ? border + rows.join('') : rows.join('') + border) + '</ol></div>';
  }
  return `<div class="rov rov-anim${opts.compact ? ' rov-compact' : ''}"><div class="rov-3d">
  <svg viewBox="0 0 720 430" class="rov-svg" role="img" aria-label="Route overview ${esc(fromName)} to ${esc(toName)}">
    <defs>
      <radialGradient id="${uid}g" cx="18%" cy="8%" r="70%"><stop offset="0" stop-color="#1c4aa0" stop-opacity=".55"/><stop offset=".55" stop-color="#0c306c" stop-opacity="0"/></radialGradient>
      <pattern id="${uid}d" width="26" height="26" patternUnits="userSpaceOnUse"><circle cx="1.5" cy="1.5" r="1.1" fill="#fff" opacity=".07"/></pattern>
      <filter id="${uid}s" x="-30%" y="-60%" width="160%" height="220%"><feDropShadow dx="0" dy="3" stdDeviation="3" flood-color="#000" flood-opacity=".45"/></filter>
    </defs>
    <rect width="720" height="430" rx="26" fill="#0c1d44"/><rect width="720" height="430" rx="26" fill="url(#${uid}g)"/><rect width="720" height="430" rx="26" fill="url(#${uid}d)"/>
    <g class="rov-land">
      <path pathLength="1" d="${ROV_INDIA_D}" fill="rgba(255,255,255,.08)" stroke="rgba(255,255,255,.34)" stroke-width="1.2" stroke-linejoin="round"/>
      <path pathLength="1" d="${ROV_NEPAL_D}" fill="rgba(220,20,60,.30)" stroke="rgba(255,255,255,.6)" stroke-width="1.2" stroke-linejoin="round"/>
      <text x="360" y="300" class="rov-country">INDIA</text>
      <text x="548" y="140" class="rov-country rov-country-sm">NEPAL</text>
    </g>
    <path d="${ROV_ROUTE_D}" class="rov-glow"/>
    <path d="${ROV_ROUTE_D}" pathLength="1" class="rov-trace"/>
    <path d="${ROV_ROUTE_D}" class="rov-line"/>
    ${via}${dots}
    <g class="rov-end rov-from" transform="translate(${T[0]},${T[1]})"><circle r="16" class="rov-pulse"/><circle r="8" fill="#F07800" stroke="#fff" stroke-width="3"/></g>
    <g class="rov-end rov-to" transform="translate(${R[0]},${R[1]})"><circle r="16" class="rov-pulse rov-pulse-b"/><circle r="8" fill="#5FA8E8" stroke="#fff" stroke-width="3"/></g>
    <text x="${T[0] - 16}" y="${T[1] + 6}" text-anchor="end" class="rov-city rov-from-lbl"><tspan class="rov-flag">IN</tspan> ${esc(townLbl)}</text>
    <text x="${T[0] - 16}" y="${T[1] + 26}" text-anchor="end" class="rov-sub rov-from-lbl">GUJARAT · IST</text>
    <text x="${R[0] + 16}" y="${R[1] + 30}" class="rov-city rov-to-lbl"><tspan class="rov-flag">NP</tspan> Rupaidiha</text>
    <text x="${R[0] + 16}" y="${R[1] + 50}" class="rov-sub rov-to-lbl">INDIA–NEPAL BORDER</text>
    <g class="rov-pill" transform="translate(560,332)"><rect x="-128" y="-22" width="256" height="36" rx="18"/><text y="3" text-anchor="middle" class="rov-dist">≈ ${km.toLocaleString('en-IN')} km · ~${hrs} h</text></g>
    <g class="rov-bus" filter="url(#${uid}s)"><image href="/assets/img/bus-side.svg" x="-27" y="-17" width="54" height="17"/>
      <animateMotion dur="${dur}" repeatCount="indefinite" path="${ROV_ROUTE_D}" calcMode="linear" keyPoints="${back ? '1;0' : '0;1'}" keyTimes="0;1"/></g>
    <text x="34" y="40" class="rov-eyebrow">ROUTE OVERVIEW</text>
    <text x="686" y="40" class="rov-eyebrow" text-anchor="end">${esc(rovCode(fromName))} → ${esc(rovCode(toName))}</text>
    <text x="686" y="414" class="rov-foot rov-foot-r" text-anchor="end">NOT LIVE POSITION</text>
  </svg></div>${listHTML}</div>`;
}

/* Home: the card follows the chosen direction + town. */
function renderHomeRouteMap() {
  const box = document.getElementById('homeRouteMap'); if (!box) return;
  const townSel = document.getElementById('pointSel');
  const town = (townSel && townSel.value) || 'Surat';
  const dir = (typeof searchDir !== 'undefined' && searchDir === 'back') ? 'back' : 'go';
  const hub = (typeof SHG_NEP_HUB !== 'undefined') ? SHG_NEP_HUB : 'Rupaidiha';
  const key = dir + '|' + town;
  if (box.getAttribute('data-key') === key) return;
  box.setAttribute('data-key', key);
  box.innerHTML = routeOverviewSVG(dir === 'go' ? { from: town, to: hub, stops: true } : { from: hub, to: town, stops: true });
  rovAttachTilt(box);
}
/* Pointer tilt for the 3D route card (17 Sep 2026) — desktop, hover-capable
   pointers only: the card follows the cursor a few degrees while hovered and
   hands back to its float animation on leave. Delegated to the container
   (the card is re-rendered on every direction/town change), rAF-throttled,
   transform-only. */
function rovAttachTilt(box) {
  if (!box || box._rovTilt) return;
  var mq = window.matchMedia ? window.matchMedia('(hover:hover) and (pointer:fine)') : null;
  if (!mq || !mq.matches) return;
  box._rovTilt = true;
  var raf = 0, px = 0, py = 0;
  box.addEventListener('pointermove', function (e) {
    var rov = box.querySelector('.rov'), card = rov && rov.querySelector('.rov-3d');
    if (!card) return;
    var r = rov.getBoundingClientRect();
    px = (e.clientX - r.left) / Math.max(1, r.width) - .5;
    py = (e.clientY - r.top) / Math.max(1, r.height) - .5;
    rov.classList.add('rov-hover');
    if (raf) return;
    raf = requestAnimationFrame(function () {
      raf = 0;
      card.style.transform = 'rotateX(' + (10 - py * 10).toFixed(2) + 'deg) rotateY(' + (px * 12).toFixed(2) + 'deg) scale(.985)';
    });
  });
  box.addEventListener('pointerleave', function () {
    var rov = box.querySelector('.rov'), card = rov && rov.querySelector('.rov-3d');
    if (!rov) return;
    rov.classList.remove('rov-hover');
    if (card) card.style.transform = '';
  });
}
/* Navigator loader (17 Sep 2026): while MapLibre boots, the same route card
   draws itself inside #snLoad instead of a bare spinner. Idempotent. */
function snLoadMapInit() {
  try {
    var m = document.getElementById('snLoadMap');
    if (m && !m.firstChild) m.innerHTML = routeOverviewSVG({ from: 'Surat', to: 'Rupaidiha', compact: true });
  } catch (e) {}
}
/* ================================================================
   [JS] 10f. DISTANCE TO BOARDING POINT — the PASSENGER's own phone
   location vs the fixed boarding-point coordinates (NOT bus GPS —
   that's section 10e). Pure client-side: navigator.geolocation +
   Haversine + an admin-configured assumed average speed. Deliberately
   kept separate from booking/commission/loyalty logic.
================================================================ */
/* Fallback coordinates for known corridor cities (used when a stored
   route predates the [lat,lng] suffix in its boarding points). */
const CITY_COORDS = {
  'surat': [21.170, 72.831], 'barauda': [22.307, 73.181], 'vadodara': [22.307, 73.181],
  'kamrej': [21.2729662, 72.9555969], 'ankleshwar': [21.626, 73.015], 'bharuch': [21.705, 72.996],
  'anand': [22.565, 72.929], 'nadiad': [22.692, 72.863],
  'ahmedabad': [23.022, 72.571], 'chiloda': [23.157, 72.655],
  'mehsana': [23.6008959, 72.3807235], 'unjha': [23.804, 72.391], 'sidhpur': [23.917, 72.373],
  'palanpur': [24.171, 72.438], 'visnagar': [23.700, 72.554],
  'nepalgunj': [28.050, 81.616], 'rupaidiha': [28.060, 81.617], 'kohalpur': [28.196, 81.700]
};
function bpCoords(bpRaw) {
  const p = parseBP(bpRaw);
  if (p.lat != null && p.lng != null) return { lat: p.lat, lng: p.lng, name: p.name };
  const key = Object.keys(CITY_COORDS).find(c => p.name.toLowerCase().indexOf(c) === 0);
  return key ? { lat: CITY_COORDS[key][0], lng: CITY_COORDS[key][1], name: p.name } : null;
}
/* One shared button+result widget (ticket page & My Bookings). */
function distanceWidgetHTML(b) {
  const p = parseBP(b.boarding);
  return '<div class="dist-box"><button class="btn btn-ghost btn-sm" type="button" data-dist="' + esc(b.id) + '">'
    + tf('distBtn', { c: esc(p.name) }) + '</button></div>';
}
function runDistanceCheck(bookingId, box) {
  const b = DB.bookings.find(x => x.id === bookingId); if (!b || !box) return;
  const p = parseBP(b.boarding);
  const label = esc(p.name + (p.landmark ? ' (' + p.landmark + ')' : ''));
  const target = bpCoords(b.boarding);
  const note = '<br><small style="color:var(--muted)">' + t('distNote') + '</small>';
  if (!target || !navigator.geolocation) {
    box.innerHTML = '<div class="verify-note">📍 <span>' + tf('distNoGeo', { c: label }) + '</span></div>';
    return;
  }
  box.innerHTML = '<div class="verify-note">⏳ <span>' + t('distLocating') + '</span></div>';
  navigator.geolocation.getCurrentPosition(pos => {
    const km = haversineKm(pos.coords.latitude, pos.coords.longitude, target.lat, target.lng);
    const v = S().assumedTravelSpeedKmh;
    const mins = km / v * 60;
    box.innerHTML = '<div class="verify-note" style="background:var(--blue-50);border-color:var(--blue-100)">📍 <span>'
      + tf('distResult', { km: km < 10 ? km.toFixed(1) : Math.round(km), c: label, v: v, t: fmtMinutes(mins) })
      + note + '</span></div>';
  }, () => {
    box.innerHTML = '<div class="verify-note">📍 <span>' + tf('distDenied', { c: label }) + '</span></div>';
  }, { timeout: 12000, maximumAge: 120000 });
}
function runGpsDistanceAll() {
  var resultBox = $('#gpsDistResult');
  var cells = document.querySelectorAll('.gps-col');
  var headers = document.querySelectorAll('.gps-col-h');
  if (!navigator.geolocation) {
    if (resultBox) { resultBox.style.display = 'block'; resultBox.innerHTML = '<div class="verify-note">📡 GPS not available on this device</div>'; }
    return;
  }
  if (resultBox) { resultBox.style.display = 'block'; resultBox.innerHTML = '<div class="verify-note" style="background:var(--blue-50);border-color:var(--blue-100)">⏳ <span>Locating you... · तपाईंको स्थान खोज्दै... · आपकी लोकेशन खोज रहे हैं...</span></div>'; }
  headers.forEach(function(h) { h.style.display = 'table-cell'; });
  cells.forEach(function(c) { c.style.display = 'table-cell'; c.textContent = '...'; });
  navigator.geolocation.getCurrentPosition(function(pos) {
    var lat = pos.coords.latitude, lng = pos.coords.longitude;
    var minD = Infinity, nearest = null, nearIdx = -1;
    cells.forEach(function(c, i) {
      var sLat = parseFloat(c.getAttribute('data-lat'));
      var sLng = parseFloat(c.getAttribute('data-lng'));
      if (isNaN(sLat) || isNaN(sLng)) { c.textContent = '—'; return; }
      var d = haversineKm(lat, lng, sLat, sLng);
      if (d < minD) { minD = d; nearest = ROUTE_STOPS[i]; nearIdx = i; }
      var mins = Math.round(d / 40 * 60);
      c.innerHTML = '<b style="color:var(--blue)">' + (d < 1 ? (d * 1000).toFixed(0) + ' m' : d.toFixed(1) + ' km') + '</b><br><small style="color:var(--muted)">~' + mins + ' min</small>';
    });
    if (nearest && resultBox) {
      var tr = document.querySelectorAll('#routeDistTbody tr')[nearIdx];
      if (tr) { tr.style.background = 'var(--blue-50)'; tr.style.fontWeight = '700'; }
      resultBox.innerHTML = '<div class="verify-note" style="background:linear-gradient(135deg,var(--blue-50),rgba(255,152,0,.08));border-color:var(--blue-100);border-left:4px solid var(--orange)">'
        + '<b>📍 You are nearest to: ' + nearest.name + '</b>'
        + (nearest.flag ? ' ' + nearest.flag : '')
        + '<br><span style="font-size:14px">Distance: <b>' + (minD < 1 ? (minD * 1000).toFixed(0) + ' m' : minD.toFixed(1) + ' km') + '</b>'
        + ' · ~' + Math.round(minD / 40 * 60) + ' min by road</span>'
        + '<br><small style="color:var(--muted)">Your location: ' + lat.toFixed(4) + '°N, ' + lng.toFixed(4) + '°E · Haversine straight-line distance · Actual road distance may differ</small>'
        + '</div>';
    }
  }, function() {
    if (resultBox) { resultBox.innerHTML = '<div class="verify-note">📡 Location access denied. Enable GPS in your browser settings.</div>'; }
    cells.forEach(function(c) { c.textContent = '—'; });
  }, { timeout: 15000, maximumAge: 120000 });
}
document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-dist]'); if (!btn) return;
  runDistanceCheck(btn.getAttribute('data-dist'), btn.closest('.dist-box'));
});

/* ================================================================
   [JS] 10g. TICKET EXTRAS — add-to-calendar (.ics, pure JS),
   destination weather (one isolated fetch, graceful fallback),
   travel checklist (content from CONFIG). None of these touch
   booking/payment state.
================================================================ */
/* Downloadable .ics for the departure, reminder 2 hours before. */
function downloadICS(b) {
  const r = routeById(b.routeId) || {};
  const dep = (r.depTime || '09:00').split(':').map(Number);
  const d = b.date.split('-').map(Number);
  const pad = (n) => String(n).padStart(2, '0');
  const dtStart = d[0] + pad(d[1]) + pad(d[2]) + 'T' + pad(dep[0]) + pad(dep[1]) + '00';
  const durMin = durationMinutes(r);
  const end = new Date(d[0], d[1] - 1, d[2], dep[0], dep[1] + durMin);
  const dtEnd = end.getFullYear() + pad(end.getMonth() + 1) + pad(end.getDate()) + 'T' + pad(end.getHours()) + pad(end.getMinutes()) + '00';
  const escIcs = (s) => String(s || '').replace(/\\/g, '\\\\').replace(/;/g, '\\;').replace(/,/g, '\\,').replace(/\n/g, '\\n');
  const ics = [
    'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//S Hari Global//Bus Ticket//EN',
    'BEGIN:VEVENT',
    'UID:' + b.id + '@sharihariglobal',
    'DTSTAMP:' + dtStart,
    'DTSTART:' + dtStart,
    'DTEND:' + dtEnd,
    'SUMMARY:' + escIcs('🚌 Bus ' + (r.from || '') + ' → ' + (r.to || '') + ' (' + b.id + ')'),
    'LOCATION:' + escIcs(bpLabel(b.boarding)),
    'DESCRIPTION:' + escIcs('Seats ' + seatLabelJoin(b.seats, r.type, b.bookingType) + ' · ' + (r.busName || '') + ' ' + (r.busNo || '') + ' · Carry original photo ID + ticket. Support ' + S().phone),
    'BEGIN:VALARM', 'TRIGGER:-PT2H', 'ACTION:DISPLAY',
    'DESCRIPTION:' + escIcs('Leave for the bus — departs ' + (r.depTime || '') + ' from ' + parseBP(b.boarding).name),
    'END:VALARM',
    'END:VEVENT', 'END:VCALENDAR'
  ].join('\r\n');
  const blob = new Blob([ics], { type: 'text/calendar;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = b.id + '.ics';
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 2000);
  toast(t('calDone'));
}

/* Destination weather via Open-Meteo (free, no key). Isolated fetch —
   if it fails or we're offline the ticket renders exactly the same. */
const WX_CODES = { 0: '☀️ Clear', 1: '🌤 Mostly clear', 2: '⛅ Partly cloudy', 3: '☁️ Overcast', 45: '🌫 Fog', 48: '🌫 Fog', 51: '🌦 Drizzle', 53: '🌦 Drizzle', 55: '🌧 Drizzle', 61: '🌧 Light rain', 63: '🌧 Rain', 65: '🌧 Heavy rain', 71: '🌨 Snow', 73: '🌨 Snow', 75: '❄️ Heavy snow', 80: '🌦 Showers', 81: '🌧 Showers', 82: '⛈ Heavy showers', 95: '⛈ Thunderstorm', 96: '⛈ Thunderstorm', 99: '⛈ Hailstorm' };
function loadDestinationWeather(b) {
  const box = $('#wxBox'); if (!box) return;
  const r = routeById(b.routeId) || {};
  const dest = bpCoords(b.drop) || (r.to ? bpCoords(r.to) : null);
  const daysAhead = Math.round((depTimestamp(b) - Date.now()) / 86400000);
  if (!dest || typeof fetch !== 'function' || daysAhead > 15) { box.remove(); return; }
  const url = 'https://api.open-meteo.com/v1/forecast?latitude=' + dest.lat + '&longitude=' + dest.lng
    + '&daily=weather_code,temperature_2m_max,temperature_2m_min&timezone=auto'
    + '&start_date=' + b.date + '&end_date=' + b.date;
  fetch(url).then(res => res.ok ? res.json() : Promise.reject())
    .then(j => {
      const dly = j && j.daily;
      if (!dly || !dly.time || !dly.time.length) { box.remove(); return; }
      const label = WX_CODES[dly.weather_code[0]] || '🌡';
      box.innerHTML = '<small>' + tf('wxTitle', { c: esc(parseBP(b.drop).name || r.to || '') }) + '</small>'
        + '<b>' + label + ' · ' + Math.round(dly.temperature_2m_min[0]) + '–' + Math.round(dly.temperature_2m_max[0]) + '°C</b>';
    })
    .catch(() => { box.innerHTML = '<small>' + t('wxNA') + '</small>'; });
}
/* Travel checklist — static content from CONFIG.booking.checklist. */
function checklistHTML() {
  const items = CONFIG.booking.checklist || [];
  if (!items.length) return '';
  return '<details class="cl-box"><summary>🧳 ' + t('clTitle') + '</summary><ul>'
    + items.map(i => '<li>' + esc(i) + '</li>').join('') + '</ul></details>';
}
document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-ics]'); if (!btn) return;
  const b = DB.bookings.find(x => x.id === btn.getAttribute('data-ics'));
  if (b) downloadICS(b);
});
/* ================================================================
   📍 OPEN A PLACE IN OUR OWN MAP (20 Sep 2026)
   Owner: "yo location pin point banayera afno map ma direct open hune, ya
   Google Map bata ni open hune ... click garexi open hos". Any element with
   data-navto="lat,lng" opens #/nav with that point pinned and set as the
   destination; the marker's popup there already carries Call, WhatsApp and
   the Google Maps link, so both halves of the ask are one tap apart.
   The coordinates live in the markup, next to the office they belong to —
   an office whose pin nobody has surveyed simply has no 📍 to tap.
================================================================ */
document.addEventListener('click', function (e) {
  var b = e.target.closest && e.target.closest('[data-navto]');
  if (!b) return;
  var p = String(b.getAttribute('data-navto') || '').split(',');
  var lat = parseFloat(p[0]), lng = parseFloat(p[1]);
  if (!isFinite(lat) || !isFinite(lng)) return;
  e.preventDefault();
  var label = b.getAttribute('data-navto-label') || 'S Hari Global';
  if (typeof window.snGoToPlace === 'function') {
    location.hash = '#/nav';
    setTimeout(function () { window.snGoToPlace(lat, lng, label); }, 60);
  } else {
    window.SHG_NAVTO = { lat: lat, lng: lng, label: label };
    location.hash = '#/nav';
  }
});
