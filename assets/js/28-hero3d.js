/* =====================================================================
   28-hero3d.js — the premium 3D hero band (#hero3d), 17 Oct 2026.

   One job: pointer parallax. On a fine pointer (mouse / trackpad) the coach,
   the Gujarat skyline and the Himalaya drift a few pixels with the cursor;
   premium3d.css reads --p3x / --p3y (−1..1) and does the moving. Nothing
   else here: the animations (float, route bus, light rays) are pure CSS,
   so the band is complete without this file.

   Off on touch screens, for prefers-reduced-motion, for the owner's
   data-motion="reduced" choice and on a low-tier device (01-boot.js).
   ===================================================================== */
(function () {
  'use strict';
  var hero = document.getElementById('hero3d');
  if (!hero) return;
  var d = document.documentElement;
  function still() {
    if (d.getAttribute('data-motion') === 'reduced' || d.getAttribute('data-tier') === 'low') return true;
    if (!window.matchMedia) return true;
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) return true;
    return !matchMedia('(hover: hover) and (pointer: fine)').matches;
  }
  if (still()) return;
  var raf = 0, tx = 0, ty = 0;
  function paint() {
    raf = 0;
    hero.style.setProperty('--p3x', tx.toFixed(3));
    hero.style.setProperty('--p3y', ty.toFixed(3));
  }
  hero.addEventListener('pointermove', function (e) {
    var r = hero.getBoundingClientRect();
    if (!r.width || !r.height) return;
    tx = ((e.clientX - r.left) / r.width - 0.5) * 2;
    ty = ((e.clientY - r.top) / r.height - 0.5) * 2;
    if (!raf) raf = requestAnimationFrame(paint);
  }, { passive: true });
  hero.addEventListener('pointerleave', function () {
    tx = 0; ty = 0;
    if (!raf) raf = requestAnimationFrame(paint);
  });
  hero.classList.add('p3-live');
})();
