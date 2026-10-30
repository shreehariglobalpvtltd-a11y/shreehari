/* =====================================================================
   agentpro-shrink-test.js — assets/agentpro/shrink.js, the loop that
   brings every phone photo under the server's limits (30 Oct 2026,
   owner: "That is too large to send"). No browser needed: the encoder
   is faked with a JPEG-like size model (bytes ∝ pixels × quality).

     node tests/agentpro-shrink-test.js
   ===================================================================== */
'use strict';
var S = require('../assets/agentpro/shrink.js');
var pass = 0, fail = 0;
function check(label, ok, extra) {
  if (ok) { pass++; console.log('  \x1b[32mPASS\x1b[0m  ' + label + (extra ? ' — ' + extra : '')); }
  else { fail++; console.log('  \x1b[31mFAIL\x1b[0m  ' + label + (extra ? ' — ' + extra : '')); }
}
/** bytes ≈ w·h·density·q² (noise-like photos have a high density) */
function encoder(density, log) {
  return function (w, h, q) { var b = { size: Math.round(w * h * density * q * q), w: w, h: h, q: q }; if (log) log.push(b); return Promise.resolve(b); };
}

(async function () {
  // a 12 MP phone photo (4000 × 3000), ~10 MB as the phone saved it
  var log = [];
  var b = await S.fit(encoder(1.0, log), 4000, 3000, { maxEdge: 2000, maxBytes: 1500000 });
  check('12 MP photo → ≤ 2000 px', Math.max(b.w, b.h) <= 2000, b.w + 'x' + b.h);
  check('…and ≤ 1.5 MB (the ID budget)', b.size <= 1500000, b.size + ' bytes after ' + log.length + ' tries');
  check('…quality is lowered before the size', log[0].w === 2000 && log[1].w === 2000 && log[1].q < log[0].q);

  log = [];
  b = await S.fit(encoder(1.0, log), 4000, 3000, { maxEdge: 900, maxBytes: 300000 });
  check('old form passport photo: ≤ 900 px and ≤ 300 KB', Math.max(b.w, b.h) <= 900 && b.size <= 300000, b.w + 'x' + b.h + ' ' + b.size);

  log = [];
  b = await S.fit(encoder(1.0, log), 4000, 3000, { maxEdge: 1600, maxBytes: 600000 });
  check('old form ID: ≤ 1600 px and ≤ 600 KB', Math.max(b.w, b.h) <= 1600 && b.size <= 600000, b.w + 'x' + b.h + ' ' + b.size);

  log = [];
  b = await S.fit(encoder(0.2, log), 800, 600, { maxEdge: 2000, maxBytes: 1500000 });
  check('a small photo is not blown up and goes in one try', b.w === 800 && b.h === 600 && log.length === 1);

  log = [];
  b = await S.fit(encoder(50, log), 4000, 3000, { maxEdge: 2000, maxBytes: 100000 });
  check('an impossible budget stops (never below ' + S.MIN_EDGE + ' px, never loops)', log.length <= 20 && Math.max(b.w, b.h) >= S.MIN_EDGE * 0.8, log.length + ' tries, ' + b.w + 'x' + b.h);
  check('…and returns the smallest try', log.every(function (x) { return x.size >= b.size; }));

  check('dataUrlBytes: 4 base64 chars = 3 bytes', S.dataUrlBytes('data:image/jpeg;base64,QUJD') === 3 && S.dataUrlBytes('data:image/jpeg;base64,QUI=') === 2);
  check('dataUrlBytes of nothing is 0', S.dataUrlBytes('') === 0);

  console.log('\nPASSED: ' + pass + '   FAILED: ' + fail);
  process.exit(fail ? 1 : 0);
})();
