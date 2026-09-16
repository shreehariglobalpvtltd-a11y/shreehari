/* =====================================================================
 *  OFFLINE TICKET — behavioural test for the service worker
 *
 *      node tests/offline-ticket-test.js
 *
 *  WHY NOT A BROWSER TEST
 *  The dev server is `php -S`, which is single-threaded, and Windows has
 *  no PHP_CLI_SERVER_WORKERS (it needs fork). A service-worker install
 *  precaches ~15 assets in parallel, which deadlocks that server and makes
 *  registration fail outright — so the SW can never be exercised in the
 *  local browser at all. Rather than ship the border-crossing feature on a
 *  manual "looked fine on my phone", sw.js is loaded into a stubbed worker
 *  global here and its real handlers are driven directly.
 *
 *  This is the file that decides whether a passenger standing at Rupaidiha
 *  with no signal can show the ticket the office issued, so the assertions
 *  are about behaviour, not the presence of source strings.
 * ===================================================================== */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

let PASS = 0;
let FAIL = 0;

function check(label, ok, extra) {
  if (ok) {
    PASS++;
    console.log('  \x1b[32mPASS\x1b[0m  ' + label + (extra ? ' — ' + extra : ''));
  } else {
    FAIL++;
    console.log('  \x1b[31mFAIL\x1b[0m  ' + label + (extra ? ' — ' + extra : ''));
  }
}

/* ---------------------------------------------------------------------
 *  A very small Cache / CacheStorage, enough for what sw.js uses.
 *  Insertion order is preserved, because trimCache() relies on it.
 * ------------------------------------------------------------------- */

class FakeCache {
  constructor() { this.map = new Map(); }
  _key(req) { return typeof req === 'string' ? req : req.url; }
  async put(req, res) { this.map.set(this._key(req), res); }
  async match(req) { return this.map.get(this._key(req)) || undefined; }
  async keys() { return [...this.map.keys()].map(u => ({ url: u })); }
  async delete(req) { return this.map.delete(this._key(req)); }
  async addAll(list) { for (const u of list) { this.map.set(u, mkRes('', 200)); } }
}

class FakeCaches {
  constructor() { this.stores = new Map(); }
  async open(name) {
    if (!this.stores.has(name)) this.stores.set(name, new FakeCache());
    return this.stores.get(name);
  }
  async keys() { return [...this.stores.keys()]; }
  async delete(name) { return this.stores.delete(name); }
  async match() { return undefined; }
}

function mkRes(body, status, contentType) {
  return {
    ok: status >= 200 && status < 300,
    status,
    _body: body,
    headers: { get: h => (h.toLowerCase() === 'content-type' ? (contentType || 'text/plain') : null) },
    clone() { return mkRes(this._body, this.status, contentType); }
  };
}

/* ---------------------------------------------------------------------
 *  Worker global
 * ------------------------------------------------------------------- */

function loadWorker(fetchImpl) {
  const listeners = {};
  const posted = [];

  const self_ = {
    location: new URL('http://localhost:8899/sw.js'),
    addEventListener: (t, fn) => { listeners[t] = fn; },
    skipWaiting: () => {},
    clients: {
      claim: async () => {},
      matchAll: async () => [{ postMessage: m => posted.push(m) }]
    },
    registration: {}
  };

  const sandbox = {
    self: self_,
    caches: new FakeCaches(),
    fetch: fetchImpl,
    Response: function (body, init) { return mkRes(body, (init && init.status) || 200); },
    Request: function (url) { return { url: String(url) }; },
    URL,
    console,
    setTimeout,
    clearTimeout
  };
  sandbox.globalThis = sandbox;

  const src = fs.readFileSync(path.join(__dirname, '..', 'sw.js'), 'utf8');
  vm.createContext(sandbox);
  vm.runInContext(src, sandbox, { filename: 'sw.js' });

  return { sandbox, listeners, posted, self_ };
}

/** Drive the fetch handler and return whatever it responded with. */
async function doFetch(w, url) {
  let responded = null;
  const waits = [];
  const evt = {
    request: { url, method: 'GET' },
    respondWith: p => { responded = p; },
    waitUntil: p => { waits.push(p); }
  };
  w.listeners.fetch(evt);
  const res = responded ? await responded : null;
  await Promise.all(waits.map(p => p.catch(() => {})));
  return { res, intercepted: responded !== null };
}

async function doMessage(w, data) {
  const waits = [];
  w.listeners.message({ data, waitUntil: p => waits.push(p) });
  await Promise.all(waits.map(p => p.catch(() => {})));
}

const PNG = 'image/png';
const TICKET = 'http://localhost:8899/download-ticket.php?pnr=SHG-2026-01074&img=1&view=1&k=5a306ab3ba10e59301a9';

(async () => {
  console.log('\n=== Offline ticket (service worker) ===\n');

  /* ---- 1. the cache survives a deploy ------------------------------ */
  {
    const w = loadWorker(async () => mkRes('x', 200, PNG));
    const cs = w.sandbox.caches;
    // pretend a previous version left caches behind, plus a stored ticket
    await cs.open('shg-shell-shg-v99');
    await cs.open('shg-assets-shg-v99');
    const tc = await cs.open('shg-tickets-v1');
    await tc.put('http://x/t', mkRes('png', 200, PNG));

    const waits = [];
    w.listeners.activate({ waitUntil: p => waits.push(p) });
    await Promise.all(waits.map(p => p.catch(() => {})));

    const left = await cs.keys();
    check('a deploy drops old versioned caches', !left.includes('shg-shell-shg-v99'),
      'remaining: ' + left.join(', '));
    check('  ...but the passenger keeps their offline ticket', left.includes('shg-tickets-v1'));
    const still = await (await cs.open('shg-tickets-v1')).match('http://x/t');
    check('  ...and the stored ticket is still readable', !!still);
  }

  /* ---- 2. only the ticket IMAGE is intercepted --------------------- */
  {
    const w = loadWorker(async () => mkRes('png', 200, PNG));
    const img = await doFetch(w, TICKET);
    check('the ticket image is intercepted', img.intercepted);

    const pdf = await doFetch(w, 'http://localhost:8899/download-ticket.php?pnr=SHG-2026-01074');
    check('the ticket PDF is NOT cached (still passthrough)', !pdf.intercepted);

    const inv = await doFetch(w, 'http://localhost:8899/download-ticket.php?pnr=X&invoice=1');
    check('the invoice is NOT cached', !inv.intercepted);

    const api = await doFetch(w, 'http://localhost:8899/api/my-bookings.php');
    check('the API is still never cached', !api.intercepted);

    const up = await doFetch(w, 'http://localhost:8899/uploads/2026/09/proof.jpg');
    check('payment proofs are still never cached', !up.intercepted);
  }

  /* ---- 3. THE POINT: it works with no network ---------------------- */
  {
    let online = true;
    const w = loadWorker(async () => {
      if (!online) throw new Error('offline');
      return mkRes('REAL-PNG-BYTES', 200, PNG);
    });

    const first = await doFetch(w, TICKET);
    check('online: the ticket is fetched and stored', first.res && first.res.ok);

    online = false;
    const offline = await doFetch(w, TICKET);
    check('OFFLINE: the ticket is still served', !!(offline.res && offline.res.ok),
      offline.res ? 'status ' + offline.res.status : 'no response');
    check('  ...and it is the real server PNG, not a fallback',
      offline.res && offline.res._body === 'REAL-PNG-BYTES');

    const never = await doFetch(w, 'http://localhost:8899/download-ticket.php?pnr=NEVER-SEEN&img=1');
    check('a ticket never cached fails cleanly offline rather than hanging',
      never.res && never.res.status === 504);
  }

  /* ---- 4. the key is not part of the cache identity ---------------- */
  {
    const w = loadWorker(async () => mkRes('PNG', 200, PNG));
    await doFetch(w, TICKET);
    // same ticket, different token (APP_KEY rotated) and no &view
    const rotated = 'http://localhost:8899/download-ticket.php?pnr=SHG-2026-01074&img=1&k=ffffffffffffffffffff';
    const store = await w.sandbox.caches.open('shg-tickets-v1');
    const hit = await store.match({ url: 'http://localhost:8899/download-ticket.php?pnr=SHG-2026-01074&img=1' });
    check('the cache key drops the HMAC token', !!hit,
      'so a key rotation does not orphan the stored ticket');
    const again = await doFetch(w, rotated);
    check('  ...so the same ticket under a new token still hits', !!(again.res && again.res.ok));
  }

  /* ---- 5. population is message-driven, and picky ------------------ */
  {
    const w = loadWorker(async () => mkRes('PNG', 200, PNG));
    await doMessage(w, { type: 'shg-cache-ticket', pnr: 'SHG-2026-01074' });
    const store = await w.sandbox.caches.open('shg-tickets-v1');
    check('a page can ask for one ticket to be kept', (await store.keys()).length === 1);
    check('  ...and the page is told it worked',
      w.posted.some(m => m.type === 'shg-ticket-cached' && m.ok === true));
  }
  {
    // the keyless 302 into #/my, or any HTML error page, must NOT be stored
    const w = loadWorker(async () => mkRes('<html>sign in</html>', 200, 'text/html'));
    await doMessage(w, { type: 'shg-cache-ticket', pnr: 'SHG-2026-01074' });
    const store = await w.sandbox.caches.open('shg-tickets-v1');
    check('an HTML page is never stored as if it were the ticket',
      (await store.keys()).length === 0);
    check('  ...and the page is told it failed',
      w.posted.some(m => m.type === 'shg-ticket-cached' && m.ok === false));
  }

  /* ---- 6. shared devices ------------------------------------------- */
  {
    const w = loadWorker(async () => mkRes('PNG', 200, PNG));
    await doMessage(w, { type: 'shg-cache-ticket', pnr: 'SHG-2026-01074' });
    check('a ticket is cached before sign-out',
      (await (await w.sandbox.caches.open('shg-tickets-v1')).keys()).length === 1);
    await doMessage(w, { type: 'shg-clear-tickets' });
    check('signing out wipes it (counter machines are shared)',
      !(await w.sandbox.caches.keys()).includes('shg-tickets-v1'));
  }

  /* ---- 7. the store is bounded ------------------------------------- */
  {
    const w = loadWorker(async () => mkRes('PNG', 200, PNG));
    for (let i = 1; i <= 14; i++) {
      await doMessage(w, { type: 'shg-cache-ticket', pnr: 'SHG-2026-' + String(10000 + i) });
    }
    const n = (await (await w.sandbox.caches.open('shg-tickets-v1')).keys()).length;
    check('the ticket store is capped, oldest evicted', n === 10, n + ' entries kept');
  }

  console.log('\n----------------------------------------');
  console.log('PASSED: ' + PASS + '   FAILED: ' + FAIL);
  console.log('----------------------------------------\n');
  process.exit(FAIL === 0 ? 0 : 1);
})().catch(e => {
  console.error('harness error:', e);
  process.exit(1);
});
