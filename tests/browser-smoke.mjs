/* =====================================================================
 *  tests/browser-smoke.mjs — the browser smoke test (25 Sep 2026).
 *
 *  Every other suite in this folder talks to PHP: it calls a service
 *  class directly, or it fetches a URL with curl and reads the HTML
 *  that came back. None of them ever runs the front end. That leaves a
 *  real hole, because the public site is a single-page shell: index.php
 *  hands the browser app.template.html plus fourteen deferred classic
 *  scripts, and those scripts share one global scope. A renamed
 *  function, a script tag dropped from index.php, a stray "const" that
 *  now collides with an earlier file — none of that shows up in a curl
 *  test. The HTML is byte-perfect and the phone shows an empty screen.
 *
 *  So this file opens a real Chromium on a phone-sized viewport and
 *  walks the road a passenger walks: the home page paints, the globals
 *  the deferred scripts define are all there, the header switches the
 *  copy to Nepali and remembers it, the search form finds a coach, the
 *  coach opens its seat map, and the server-rendered /bus page that
 *  search engines read still has its heading and its way back into the
 *  app. Throughout, every console error, every uncaught exception and
 *  every failed request from our own origin is collected and reported,
 *  because those are the failures a passenger sees and nobody else
 *  does.
 *
 *  It never pays and never books. It reads; it lets the app write only
 *  what the app itself writes when a passenger taps the same controls
 *  (a search POST, the language preference, the release of seat holds
 *  this brand-new browser session does not hold).
 *
 *  Run it after the dev server is up:
 *
 *      php -S 127.0.0.1:8899 -t . tests/dev-router.php &
 *      node tests/browser-smoke.mjs
 *
 *  SHG_TEST_BASE overrides the base URL, exactly as the PHP suites use
 *  it. SHG_SMOKE_SHOTS overrides where the two screenshots are written.
 *  A machine with no Chromium is not a failure: the run prints one SKIP
 *  line and exits 0, so CI on a bare container stays green.
 * ===================================================================== */

import { mkdir } from 'node:fs/promises';
import { existsSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';

/* ------------------------------------------------------------------
 *  Settings.
 * ---------------------------------------------------------------- */

const BASE = String(process.env.SHG_TEST_BASE || 'http://127.0.0.1:8899').replace(/\/+$/, '');
const ORIGIN = (() => {
    try { return new URL(BASE).origin; } catch (e) { return BASE; }
})();

/* Where the two screenshots go. The default is this session's
   scratchpad; any other machine can point it somewhere real. */
const SHOT_DIR = String(process.env.SHG_SMOKE_SHOTS
    || '/tmp/claude-0/-home-user/ddc999ef-8395-5da2-92e3-138ca803d7d8/scratchpad');

/* The browsers Playwright was given on this image. Never run
   "playwright install" from a test — the binary is provisioned. */
const BROWSERS_ROOT = String(process.env.PLAYWRIGHT_BROWSERS_PATH || '/opt/pw-browsers');

/* ------------------------------------------------------------------
 *  Reporting, in the shape every PHP suite in tests/ prints.
 * ---------------------------------------------------------------- */

const GREEN = '\u001b[32m';
const RED = '\u001b[31m';
const YELLOW = '\u001b[33m';
const OFF = '\u001b[0m';

let PASS = 0;
let FAIL = 0;
let SKIPPED = 0;

function line(colour, word, label, detail) {
    const tail = detail ? ' — ' + String(detail).replace(/\s+/g, ' ').trim() : '';
    console.log('  ' + colour + word + OFF + '  ' + label + tail);
}
function pass(label, detail) { PASS++; line(GREEN, 'PASS', label, detail); }
function fail(label, detail) { FAIL++; line(RED, 'FAIL', label, detail); }
function skip(label, detail) { SKIPPED++; line(YELLOW, 'SKIP', label, detail); }
function check(label, ok, detail) { if (ok) { pass(label, detail); } else { fail(label, detail); } }
function note(text) { console.log('  ' + text); }

function finish() {
    console.log('');
    console.log(PASS + ' passed, ' + FAIL + ' failed'
        + (SKIPPED ? ', ' + SKIPPED + ' skipped' : ''));
    process.exit(FAIL === 0 ? 0 : 1);
}

/* ------------------------------------------------------------------
 *  Playwright: the package, then the browser binary.
 * ---------------------------------------------------------------- */

/* "playwright" ships the browsers; "playwright-core" does not, and that
   is the one this image installs against the pre-provisioned Chromium.
   Either is fine, so try both before giving up. */
async function loadChromium() {
    const tried = [];
    for (const name of ['playwright', 'playwright-core']) {
        try {
            const mod = await import(name);
            if (mod && mod.chromium) return { chromium: mod.chromium, from: name };
            tried.push(name + ' (no chromium export)');
        } catch (e) {
            tried.push(name + ' (' + (e && e.code ? e.code : 'import failed') + ')');
        }
    }
    return { chromium: null, from: tried.join(', ') };
}

/* Playwright looks for a browser build whose revision matches the
   package version, and this image carries a different revision, so the
   default launch fails with "Executable doesn't exist at ...". Rather
   than pin a path that the next image bump would break, walk the
   browsers directory and take whatever real binary is there. Full
   chrome first (it renders and screenshots exactly like a phone's
   browser), the headless shell second. */
function findChromiumBinary(root) {
    /* A machine that already has a browser is used as it is. GitHub's
       Ubuntu runners ship Google Chrome and Chromium, so CI needs no
       200 MB download, and a developer can point CHROME_PATH at whatever
       they have. Checked before the bundled builds because it is the
       cheapest answer and always the right one when it is set. */
    for (const candidate of [
        process.env.SHG_CHROME,
        process.env.CHROME_PATH,
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/chromium-browser',
        '/usr/bin/chromium',
    ]) {
        if (candidate && existsSync(candidate)) {
            try { if (statSync(candidate).mode & 0o111) return candidate; } catch (e) { /* keep looking */ }
        }
    }
    if (!root || !existsSync(root)) return '';
    const wanted = ['chrome', 'chrome-headless-shell', 'headless_shell'];
    const found = [];
    const walk = (dir, depth) => {
        if (depth > 4 || found.length > 40) return;
        let entries = [];
        try { entries = readdirSync(dir, { withFileTypes: true }); } catch (e) { return; }
        for (const entry of entries) {
            const full = join(dir, entry.name);
            if (entry.isDirectory()) { walk(full, depth + 1); continue; }
            if (!wanted.includes(entry.name)) continue;
            try { if (!(statSync(full).mode & 0o111)) continue; } catch (e) { continue; }
            found.push(full);
        }
    };
    walk(root, 0);
    found.sort((a, b) => wanted.indexOf(a.split('/').pop()) - wanted.indexOf(b.split('/').pop()));
    return found[0] || '';
}

/* ------------------------------------------------------------------
 *  What went wrong in the browser, collected for the whole run.
 * ---------------------------------------------------------------- */

const consoleErrors = [];
const pageErrors = [];
const failedRequests = [];

/* Only this site's own problems count. A Google font that the sandbox
   cannot reach, or a missing /favicon.ico, tells us nothing about the
   booking app. */
function ours(url) {
    if (!url || url.startsWith('data:') || url.startsWith('blob:')) return false;
    try { return new URL(url).origin === ORIGIN; } catch (e) { return false; }
}
function ignorable(url) {
    try { return new URL(url).pathname === '/favicon.ico'; } catch (e) { return false; }
}

/* watch() is called explicitly for the pages this file opens AND from
   context.on('page'), which also fires for those same pages — so guard
   against attaching the listeners twice and counting every error twice. */
const watched = new WeakSet();

function watch(page) {
    if (watched.has(page)) return;
    watched.add(page);

    page.on('console', (msg) => {
        if (msg.type() !== 'error') return;
        const text = msg.text().replace(/\s+/g, ' ').trim();
        /* Drop the ones that are only about something we already ignore:
           an error raised by a script on another host, an error whose
           text names nothing but external URLs (a Google font, a map
           tile provider), and the favicon. Anything from this site —
           including a message with no URL in it at all, which is what a
           genuine "x is not a function" looks like — is kept. */
        const where = (msg.location() && msg.location().url) || '';
        if (where && /^https?:\/\//.test(where) && !ours(where)) return;
        if (/favicon\.ico/.test(text)) return;
        const urls = text.match(/https?:\/\/[^\s'")]+/g) || [];
        if (urls.length && urls.every((u) => !ours(u))) return;
        consoleErrors.push(text.slice(0, 300));
    });
    page.on('pageerror', (err) => {
        pageErrors.push(String((err && err.message) || err).replace(/\s+/g, ' ').slice(0, 300));
    });
    page.on('requestfailed', (req) => {
        const url = req.url();
        if (!ours(url) || ignorable(url)) return;
        const why = req.failure() ? req.failure().errorText : 'unknown';
        /* ERR_ABORTED is what the browser reports when WE navigated away
           mid-flight (or a service worker answered instead); it is not a
           broken endpoint. */
        if (why === 'net::ERR_ABORTED') return;
        failedRequests.push(req.method() + ' ' + url + ' → ' + why);
    });
    page.on('response', (res) => {
        const url = res.url();
        if (!ours(url) || ignorable(url)) return;
        if (res.status() < 400) return;
        failedRequests.push(res.request().method() + ' ' + url + ' → HTTP ' + res.status());
    });
}

function mark() {
    return { c: consoleErrors.length, p: pageErrors.length, r: failedRequests.length };
}
function problemsSince(m) {
    return consoleErrors.slice(m.c).map((s) => 'console: ' + s)
        .concat(pageErrors.slice(m.p).map((s) => 'pageerror: ' + s))
        .concat(failedRequests.slice(m.r).map((s) => 'request: ' + s));
}
/* One check, plus one printed line per problem so the detail is never
   truncated away by the label column. */
function checkClean(label, m) {
    const found = problemsSince(m);
    check(label, found.length === 0, found.length ? found.length + ' problem(s)' : '');
    found.forEach((f) => note('      · ' + f));
    return found.length === 0;
}

/* ------------------------------------------------------------------
 *  Small browser helpers.
 * ---------------------------------------------------------------- */

/* "Wait until the network is idle" cannot be a hard requirement on this
   app: the tracking and seat-event pollers keep a trickle going, so a
   strict networkidle would hang. Load, then give idle a bounded chance. */
async function open(page, url) {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
    try { await page.waitForLoadState('networkidle', { timeout: 20000 }); } catch (e) { /* pollers */ }
}

/* The brand screen (#splash, Splash in 13-admin-routes.js) is fixed at
   inset 0 with z-index 9999 and holds the first paint of a brand-new
   browser for up to seven seconds, so every click below would land on
   it instead of on the app. "Tap to skip" (#splashSkip) appears after
   600 ms; if the markup ever loses it, waiting for the .done class (or
   for the element to stop taking pointers) still gets us through. */
async function dismissSplash(page) {
    if (!(await page.locator('#splash').count())) return;
    /* On the second load of a session the splash is already display:none,
       so ask before clicking rather than burning the click timeout. */
    const skip = page.locator('#splashSkip');
    if (await skip.isVisible().catch(() => false)) {
        await skip.click({ timeout: 4000 }).catch(() => null);
    }
    try {
        await page.waitForFunction(() => {
            const el = document.getElementById('splash');
            if (!el) return true;
            if (el.classList.contains('done')) return true;
            const cs = getComputedStyle(el);
            return cs.display === 'none' || cs.visibility === 'hidden' || cs.pointerEvents === 'none';
        }, null, { timeout: 20000 });
    } catch (e) { /* the checks below will say what it blocked */ }
}

/* The first locator in the list that actually matches something. The
   markup of this app is rewritten often, so every selector below comes
   with alternatives and a comment saying what it is looking for. */
async function firstMatch(page, selectors) {
    for (const sel of selectors) {
        const loc = page.locator(sel).first();
        try { if (await loc.count()) return { loc, sel }; } catch (e) { /* bad selector */ }
    }
    return { loc: null, sel: '' };
}

/* Devanagari, the script all three Nepali/Hindi translations are in. */
const DEVANAGARI = /[ऀ-ॿ]/;

/* Every element the translator touches, as key -> first text. */
async function i18nTexts(page) {
    const rows = await page.evaluate(() => Array.from(document.querySelectorAll('[data-i18n]'))
        .map((el) => [el.getAttribute('data-i18n') || '', (el.textContent || '').trim()]));
    const map = new Map();
    for (const [key, text] of rows) { if (key && !map.has(key)) map.set(key, text); }
    return map;
}

/* ------------------------------------------------------------------
 *  The run.
 * ---------------------------------------------------------------- */

console.log('');
console.log('=== Browser smoke (' + BASE + ') ===');
console.log('');

const { chromium, from } = await loadChromium();
if (!chromium) {
    skip('Playwright is not installed', from + ' — a missing browser is not a test failure');
    finish();
}
note('playwright module: ' + from);

let browser = null;
try {
    try {
        browser = await chromium.launch({ headless: true });
        note('chromium: default executable');
    } catch (e) {
        const exe = findChromiumBinary(BROWSERS_ROOT);
        if (!exe) {
            skip('Chromium is not available', 'no browser in CHROME_PATH, /usr/bin or ' + BROWSERS_ROOT);
            finish();
        }
        browser = await chromium.launch({ headless: true, executablePath: exe });
        note('chromium: ' + exe);
    }
} catch (e) {
    skip('Chromium could not be launched', String((e && e.message) || e).split('\n')[0]);
    finish();
}

try {
    /* A phone. This coach is booked from phones in Surat and Nepalgunj,
       almost never from a desktop, so the smoke test must be a phone.
       reducedMotion also shortens the brand hold and stills the hero
       animation, which keeps the screenshots comparable run to run. */
    const context = await browser.newContext({
        viewport: { width: 390, height: 844 },
        deviceScaleFactor: 2,
        isMobile: true,
        hasTouch: true,
        reducedMotion: 'reduce',
        locale: 'en-IN',
    });
    context.on('page', watch);

    const page = await context.newPage();
    watch(page);

    await mkdir(SHOT_DIR, { recursive: true }).catch(() => null);
    const shotDir = existsSync(SHOT_DIR) ? SHOT_DIR : tmpdir();
    const homeShot = join(shotDir, 'smoke-home.png');
    const seatShot = join(shotDir, 'smoke-seats.png');
    /* Tracked rather than tested with existsSync at the end, so a file
       left behind by an earlier run is never reported as this run's. */
    let homeShotOk = false;
    let seatShotOk = false;

    /* ---------------------------------------------------------------
     *  1. The home page.
     * ------------------------------------------------------------- */
    console.log('-- home page --');
    const homeMark = mark();
    await open(page, BASE + '/');
    await dismissSplash(page);

    const title = await page.title();
    check('the document title names a bus and Nepal',
        /bus/i.test(title) && /nepal/i.test(title), title);

    /* The hero headline is <p class="hero-slogan" data-i18n="heroTitle">
       today. The fallbacks cover it being promoted to a heading or the
       class being renamed; the last one is any first-level heading. */
    const hero = await firstMatch(page, [
        '.hero [data-i18n="heroTitle"]',
        '.hero .hero-slogan',
        '.hero-copy h1',
        '.hero h1',
        'main h1',
        'h1',
    ]);
    if (!hero.loc) {
        fail('the hero headline rendered', 'no element matched any hero selector');
    } else {
        /* "Visible" here means rendered and not hidden — a real box, not
           display:none, not visibility:hidden, not transparent. It does
           NOT mean inside the viewport: on a phone the Quick Ticket card
           is ordered above the hero, so the headline legitimately sits
           some 1800px down the home page. */
        const shown = await hero.loc.evaluate((el) => {
            const box = el.getBoundingClientRect();
            const cs = getComputedStyle(el);
            return box.width > 0 && box.height > 0
                && cs.display !== 'none' && cs.visibility !== 'hidden'
                && parseFloat(cs.opacity || '1') > 0;
        }).catch(() => false);
        const text = ((await hero.loc.textContent().catch(() => '')) || '').trim();
        check('the hero headline rendered, is visible and has text',
            shown && text.length > 0,
            hero.sel + ' → "' + text.slice(0, 60) + '"' + (shown ? '' : ' (hidden)'));
    }

    const boot = await page.evaluate(() => {
        const b = window.SHG_BOOT;
        if (!b || typeof b !== 'object') return null;
        /* Booleans only. The CSRF token never leaves the browser. */
        return {
            apiBase: typeof b.apiBase === 'string' && b.apiBase !== '' ? b.apiBase : '',
            csrf: typeof b.csrf === 'string' && b.csrf.length > 0,
            settings: !!b.settings && typeof b.settings === 'object',
        };
    });
    if (!boot) {
        fail('window.SHG_BOOT carries apiBase, csrf and settings', 'SHG_BOOT is missing');
    } else {
        const missing = [];
        if (!boot.apiBase) missing.push('apiBase');
        if (!boot.csrf) missing.push('csrf');
        if (!boot.settings) missing.push('settings');
        check('window.SHG_BOOT carries apiBase, csrf and settings',
            missing.length === 0,
            missing.length ? 'missing ' + missing.join(', ') : 'apiBase=' + boot.apiBase);
    }

    /* The load order held. These five names are declared at the top
       level of five different deferred files (02-config.js defines
       CONFIG, inr, fmtDate and toast; 04-i18n.js defines t), so if any
       one of them is missing, a script never ran or threw on the way
       in. They are `const` declarations, which live in the global
       LEXICAL scope and are therefore NOT properties of window — so
       they must be read as bare identifiers, which is exactly what
       `typeof` inside page.evaluate does, safely, even when undeclared. */
    const globals = await page.evaluate(() => ({
        t: typeof t,
        toast: typeof toast,
        inr: typeof inr,
        fmtDate: typeof fmtDate,
        CONFIG: typeof CONFIG,
        CONFIGbooking: (typeof CONFIG === 'object' && CONFIG !== null)
            ? (CONFIG.booking && typeof CONFIG.booking === 'object' ? 'object' : typeof CONFIG.booking)
            : 'n/a',
    }));
    const badGlobals = ['t', 'toast', 'inr', 'fmtDate']
        .filter((n) => globals[n] !== 'function')
        .map((n) => n + ' is ' + globals[n]);
    check('the deferred scripts defined t, toast, inr and fmtDate',
        badGlobals.length === 0,
        badGlobals.length ? badGlobals.join('; ') : 'all four are functions');
    check('CONFIG is an object with a booking section',
        globals.CONFIG === 'object' && globals.CONFIGbooking === 'object',
        'CONFIG is ' + globals.CONFIG + ', CONFIG.booking is ' + globals.CONFIGbooking);

    /* Back to the top first: renderDateChips() centres the 30-day strip
       with scrollIntoView while the page settles, so without this the
       "home screen" picture starts halfway down the page. */
    await page.evaluate(() => window.scrollTo(0, 0)).catch(() => null);
    await page.waitForTimeout(400);
    homeShotOk = await page.screenshot({ path: homeShot }).then(() => true).catch(() => false);

    checkClean('the home page logged no errors and no failed requests', homeMark);

    /* The trust card. Its whole promise is that it never shows a number we
       cannot stand behind, so both outcomes are worth asserting: with the
       switch on it appears when the register has something to show, and
       stays away when every figure would be zero. */
    const trust = await page.evaluate(() => {
        const b = window.SHG_BOOT || {};
        const on = (b.settings || {}).trust_card_on;
        const n = (b.trust || {}).numbers || {};
        const el = document.querySelector('#trustLive');
        return {
            switchOn: on === true || on === 1 || on === '1',
            hasNumbers: (n.trips > 0) || (n.pax > 0) || (n.womenSeats > 0) || (n.ratingCount >= 3 && n.rating > 0),
            shown: !!el && !el.classList.contains('hide'),
            text: el ? el.innerText.replace(/\s+/g, ' ').trim().slice(0, 140) : '',
        };
    });
    if (!trust.switchOn) {
        skip('the trust card on the home page', 'trust_card_on is off');
    } else if (trust.hasNumbers) {
        check('the trust card shows the register\'s own numbers', trust.shown && trust.text.length > 0, trust.text);
    } else {
        check('the trust card stays away rather than showing an empty claim', !trust.shown, trust.text || 'hidden, as it should be');
    }

    /* ---------------------------------------------------------------
     *  2. Language.
     * ------------------------------------------------------------- */
    console.log('');
    console.log('-- language --');
    const langMark = mark();

    /* The header control for Nepali. Today it is a .lang-btn carrying
       data-lang="ne" and the short label "ने"; the text variants cover a
       redesign that spells the language out, in a <button> or a link. */
    const ne = await firstMatch(page, [
        'header [data-lang="ne"]',
        '[data-lang="ne"]',
        'button:has-text("नेपाली")',
        'a:has-text("नेपाली")',
        '.lang-btn:has-text("ने")',
    ]);

    if (!ne.loc) {
        fail('the header offers a Nepali switch',
            'nothing matched data-lang="ne" or a control labelled नेपाली');
        skip('the visible copy switched to Devanagari', 'no switch to click');
        skip('the Nepali choice survives a page reload', 'no switch to click');
    } else {
        pass('the header offers a Nepali switch', ne.sel);

        /* 04-i18n.js defaults a first-time visitor to Nepali already, so
           switching TO Nepali from Nepali would change nothing and prove
           nothing. Go to English first, then make the real switch. */
        const langNow = await page.evaluate(() => (typeof LANG === 'string' ? LANG : ''));
        let leftNepali = langNow !== 'ne';
        if (langNow !== 'en') {
            const en = await firstMatch(page, ['header [data-lang="en"]', '[data-lang="en"]', '.lang-btn:has-text("EN")']);
            if (en.loc) {
                await en.loc.click({ timeout: 8000 }).catch(() => null);
                leftNepali = await page.waitForFunction(() => (typeof LANG === 'string' ? LANG : '') === 'en',
                    null, { timeout: 8000 }).then(() => true).catch(() => false);
            }
            note('app default language is "' + langNow + '"; switched to English first: ' + (leftNepali ? 'yes' : 'no'));
        }

        const before = await i18nTexts(page);
        await ne.loc.click({ timeout: 8000 });
        await page.waitForFunction(() => (typeof LANG === 'string' ? LANG : '') === 'ne',
            null, { timeout: 10000 }).catch(() => null);
        const after = await i18nTexts(page);

        const changed = [];
        for (const [key, text] of after) {
            if (before.has(key) && before.get(key) !== text && DEVANAGARI.test(text)) {
                changed.push(key);
            }
        }
        /* The assertion is that the copy CHANGED, not merely that some
           Devanagari is on the page: this home page carries Nepali
           sentences in its English markup too, so "the page contains
           Devanagari" would pass even with the switch wired to nothing. */
        const heroSubNow = after.get('heroSub') || '';
        check('the visible copy switched to Devanagari',
            changed.length > 0,
            changed.length
                ? changed.length + ' translated element(s), e.g. ' + changed.slice(0, 4).join(', ')
                : (!leftNepali
                    ? 'nothing changed — the app was already Nepali and no English control was found to switch away from first'
                    : 'no [data-i18n] element changed to Devanagari; heroSub now reads "' + heroSubNow.slice(0, 60) + '"'));

        /* And the choice is remembered. 04-i18n.js writes localStorage
           "shg:lang"; read every key so a rename still reports honestly. */
        await open(page, BASE + '/');
        await dismissSplash(page);
        const remembered = await page.evaluate(() => {
            const out = { lang: typeof LANG === 'string' ? LANG : '', keys: {} };
            try {
                for (let i = 0; i < localStorage.length; i++) {
                    const k = localStorage.key(i);
                    if (k && /lang/i.test(k)) out.keys[k] = localStorage.getItem(k);
                }
            } catch (e) { /* storage blocked */ }
            return out;
        });
        const stored = Object.entries(remembered.keys).map(([k, v]) => k + '=' + v).join(', ');
        check('the Nepali choice survives a page reload',
            remembered.lang === 'ne' && Object.values(remembered.keys).includes('ne'),
            'LANG=' + (remembered.lang || '(none)') + (stored ? '; localStorage ' + stored : '; nothing in localStorage'));
    }

    checkClean('the language switch logged no errors and no failed requests', langMark);

    /* ---------------------------------------------------------------
     *  3. The booking funnel, as far as it goes without paying.
     * ------------------------------------------------------------- */
    console.log('');
    console.log('-- booking funnel (no payment, no booking) --');
    const funnelMark = mark();
    let seatsReached = false;

    /* Direction: #dirGo is "Going to Nepal" (Gujarat -> Rupaidiha) and
       is the default, but click it anyway so the test states the
       direction it means. The fallbacks find the same pill by its
       translated label or by being the first pill in the group. */
    const dir = await firstMatch(page, [
        '#dirGo',
        '.dir-toggle [data-i18n="dirGo"]',
        '.dir-toggle .dir-pill',
    ]);
    if (dir.loc) {
        await dir.loc.scrollIntoViewIfNeeded().catch(() => null);
        await dir.loc.click({ timeout: 8000 }).catch(() => null);
        note('direction control: ' + dir.sel);
    } else {
        note('no direction control found; using whatever the form defaults to');
    }

    /* The earliest date the form will actually sell. The 30-day strip
       (#dateStrip30) disables any day before the booking window opens
       and any day already sold out, so its first enabled button IS the
       earliest selectable date. Without the strip, fall back to the
       date input's own min, and only then to today — never to a date
       written into this file, which would expire. */
    /* The strip is painted by renderDateChips() during boot; give it a
       moment before reading it, so a slow boot does not push this on to
       the weaker #dateInput fallback. */
    await page.waitForFunction(
        () => !!document.querySelector('#dateStrip30 button[data-iso], #dateChips button[data-iso]'),
        null, { timeout: 10000 }).catch(() => null);

    const chosen = await page.evaluate(() => {
        const iso = (d) => d.getFullYear() + '-'
            + String(d.getMonth() + 1).padStart(2, '0') + '-'
            + String(d.getDate()).padStart(2, '0');
        const strip = document.querySelector('#dateStrip30, #dateChips');
        if (strip) {
            const btn = Array.from(strip.querySelectorAll('button[data-iso]'))
                .find((b) => !b.disabled && b.getAttribute('aria-disabled') !== 'true');
            if (btn) { btn.click(); return { date: btn.getAttribute('data-iso'), how: 'date strip' }; }
            if (strip.querySelectorAll('button[data-iso]').length) {
                return { date: '', how: 'date strip: every day is disabled' };
            }
        }
        const input = document.querySelector('#dateInput, input[type="date"]');
        if (!input) return { date: '', how: 'no date control in the form' };
        const value = input.getAttribute('min') || input.value || iso(new Date());
        input.value = value;
        try { input.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) { /* older engines */ }
        return { date: value, how: input.getAttribute('min') ? 'date input min' : 'today' };
    });

    /* The boarding town. 05-router.js fills #pointSel and pre-selects
       the first town, so normally there is nothing to do; pick the
       first real option if it ever comes up empty. */
    const town = await page.evaluate(() => {
        const sel = document.querySelector('#pointSel, select[required]');
        if (!sel) return '';
        if (!sel.value) {
            const opt = Array.from(sel.options).find((o) => o.value);
            if (opt) {
                sel.value = opt.value;
                try { sel.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) { /* noop */ }
            }
        }
        return sel.value || '';
    });

    if (!chosen.date) {
        skip('the search form accepts a sellable travel date', chosen.how);
        skip('the results board lists at least one coach', 'no date to search');
        skip('the seat map renders at least one seat', 'no date to search');
    } else {
        pass('the search form accepts a sellable travel date',
            chosen.date + ', taken from the ' + chosen.how
            + ', boarding ' + (town || '(the form default)'));

        const submit = await firstMatch(page, [
            '#searchForm button[type="submit"]',
            '#searchForm .btn-search',
            'form .btn-search',
            'button[type="submit"]',
        ]);
        let submitted = false;
        if (submit.loc) {
            await submit.loc.scrollIntoViewIfNeeded().catch(() => null);
            submitted = await submit.loc.click({ timeout: 10000 }).then(() => true).catch(() => false);
        }
        if (!submitted) {
            fail('the search form could be submitted',
                submit.loc ? 'the submit button refused the click' : 'nothing matched a submit control');
            skip('the results board lists at least one coach', 'the search was never submitted');
            skip('the seat map renders at least one seat', 'the search was never submitted');
        } else {
            /* One bookable coach on the board sends the app straight to
               the seat map (Flow.autoSkip in 06-results.js), so accept
               either hash. #resultsList keeps its cards in the DOM
               either way, which is what the next check reads. */
            const landed = await page.waitForFunction(
                () => (location.hash === '#/results' || location.hash === '#/seats') ? location.hash : false,
                null, { timeout: 25000 }).then((h) => h.jsonValue()).catch(() => '');
            /* `location` here would be Node's, not the browser's, so the
               fallback detail has to be read out of the page. */
            const hashNow = landed || await page.evaluate(() => location.hash).catch(() => '(unreadable)');
            check('submitting the search opens the results flow', landed !== '', 'hash is ' + (hashNow || '(empty)'));

            /* Cards paint from the local catalogue within ~280 ms and
               are re-rendered when /api/search.php answers. */
            await page.waitForFunction(() => {
                const list = document.getElementById('resultsList');
                if (!list) return false;
                return list.querySelectorAll('.bus-card:not(.skel-card)').length > 0
                    || !!list.querySelector('.empty-state');
            }, null, { timeout: 25000 }).catch(() => null);

            const board = await page.evaluate(() => {
                const list = document.getElementById('resultsList');
                if (!list) return { cards: 0, empty: 'there is no #resultsList' };
                return {
                    cards: list.querySelectorAll('.bus-card:not(.skel-card)').length,
                    empty: list.querySelector('.empty-state')
                        ? (list.querySelector('.empty-state').textContent || '').trim().slice(0, 140)
                        : '',
                };
            });

            if (board.cards > 0) {
                pass('the results board lists at least one coach', board.cards + ' card(s)');

                if ((await page.evaluate(() => location.hash)) !== '#/seats') {
                    /* The whole card is the button; [data-sel] is the
                       "choose seats" control inside it. */
                    const openCard = await firstMatch(page, [
                        '#resultsList .bus-card:not(.skel-card) [data-sel]',
                        '#resultsList .bus-card:not(.skel-card)',
                    ]);
                    if (openCard.loc) {
                        await openCard.loc.scrollIntoViewIfNeeded().catch(() => null);
                        await openCard.loc.click({ timeout: 10000 }).catch(() => null);
                    }
                }
                await page.waitForFunction(() => location.hash === '#/seats',
                    null, { timeout: 20000 }).catch(() => null);

                /* Seats are rendered as .seat[data-id] inside #seatGrid.
                   NOTHING is clicked here: a seat tap posts a hold to
                   /api/lock.php, and this test must not write. */
                await page.waitForFunction(() => {
                    const g = document.querySelector('#seatGrid, .seat-rows');
                    return !!g && g.querySelectorAll('.seat').length > 0;
                }, null, { timeout: 20000 }).catch(() => null);

                const seats = await page.evaluate(() => {
                    const grid = document.querySelector('#seatGrid, .seat-rows');
                    if (!grid) return { n: -1, how: '' };
                    /* .seat is what 06-results.js paints and reads back
                       (grid.querySelector('.seat[data-id=...]')). The
                       data-id fallback catches a rename of the class. */
                    const exact = grid.querySelectorAll('.seat').length;
                    if (exact) return { n: exact, how: '.seat' };
                    return { n: grid.querySelectorAll('[data-id]').length, how: '[data-id]' };
                });
                if (seats.n > 0) {
                    seatsReached = true;
                    pass('the seat map renders at least one seat', seats.n + ' × ' + seats.how);
                    seatShotOk = await page.screenshot({ path: seatShot }).then(() => true).catch(() => false);

                    /* No floating button may sit on top of a seat. A
                       screenshot on 25 Sep 2026 caught the contact dial
                       over seats LA6 and E/F: a berth under a fixed button
                       cannot be tapped at all, so it was unsellable on a
                       phone without anyone seeing an error. This asks the
                       browser what is actually on top at the centre of
                       every seat, which is the only way to catch it.

                       The page is scrolled to the very bottom first. A
                       sticky action bar covering a seat mid-scroll is not a
                       fault — the seat moves out from under it. What must
                       never happen is a seat still covered once there is
                       nowhere left to scroll, because then it can never be
                       reached, and that is exactly what too little padding
                       under the last row, or a fixed button, produces. */
                    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
                    await page.waitForTimeout(400);
                    const covered = await page.evaluate(() => {
                        const out = [];
                        const grid = document.querySelector('#seatGrid');
                        if (!grid) return out;
                        for (const seat of grid.querySelectorAll('.seat, [data-id]')) {
                            const r = seat.getBoundingClientRect();
                            if (r.width === 0 || r.height === 0) continue;
                            const cx = r.left + r.width / 2;
                            const cy = r.top + r.height / 2;
                            if (cy < 0 || cy > window.innerHeight || cx < 0 || cx > window.innerWidth) continue;
                            const top = document.elementFromPoint(cx, cy);
                            if (top && !seat.contains(top) && !top.contains(seat)) {
                                const id = seat.getAttribute('data-id') || seat.textContent.trim().slice(0, 8);
                                const who = top.id ? '#' + top.id : (top.className && typeof top.className === 'string' ? '.' + top.className.trim().split(/\s+/)[0] : top.tagName);
                                out.push(id + ' covered by ' + who);
                            }
                        }
                        return out;
                    });
                    check('every seat can be reached once the list is scrolled to its end', covered.length === 0, covered.slice(0, 4).join(', '));
                    await page.evaluate(() => window.scrollTo(0, 0));
                    await page.waitForTimeout(250);

                    /* And nothing that floats may sit on a seat at all. A
                       docked bar the full width of the screen is fine — the
                       grid scrolls clear of it — but a round button parked
                       over the grid invites a tap on "call the office" when
                       the passenger meant berth LA6. The repository already
                       hides the WhatsApp and assistant buttons during seat
                       pick for exactly this reason; this check is what keeps
                       the next floating button honest. */
                    const floaters = await page.evaluate(() => {
                        const bad = [];
                        const grid = document.querySelector('#seatGrid');
                        if (!grid) return bad;
                        const seats = [...grid.querySelectorAll('.seat, [data-id]')]
                            .map((s) => ({ id: s.getAttribute('data-id') || '?', r: s.getBoundingClientRect() }))
                            .filter((s) => s.r.width > 0 && s.r.height > 0);
                        for (const el of document.querySelectorAll('body *')) {
                            const cs = getComputedStyle(el);
                            if (cs.position !== 'fixed' || cs.display === 'none' || cs.visibility === 'hidden') continue;
                            if (parseFloat(cs.opacity || '1') < 0.05 || parseInt(cs.zIndex || '0', 10) < 1) continue;
                            const r = el.getBoundingClientRect();
                            if (r.width === 0 || r.height === 0) continue;
                            if (r.width >= window.innerWidth * 0.9) continue;   // a docked bar, not a floating button
                            if (el.closest('.flow-actions')) continue;          // part of that bar
                            const hit = seats.find((s) => r.left < s.r.right && r.right > s.r.left && r.top < s.r.bottom && r.bottom > s.r.top);
                            if (hit) {
                                const who = el.id ? '#' + el.id : (typeof el.className === 'string' && el.className.trim() ? '.' + el.className.trim().split(/\s+/)[0] : el.tagName);
                                if (!bad.some((b) => b.startsWith(who))) bad.push(who + ' over seat ' + hit.id);
                            }
                        }
                        return bad;
                    });
                    check('no floating button sits on top of a seat', floaters.length === 0, floaters.join(', '));

                    /* The women-safety line, when the office has turned it
                       on. It is drawn by 06-results.js as the seat grid
                       paints, so this is the only place it can be seen. */
                    const women = await page.evaluate(() => {
                        const on = (window.SHG_BOOT && window.SHG_BOOT.settings || {}).women_layer_on;
                        const el = document.querySelector('#womenLine');
                        return {
                            switchOn: on === true || on === 1 || on === '1',
                            exists: !!el,
                            shown: !!el && !el.classList.contains('hide'),
                            text: el ? el.innerText.replace(/\s+/g, ' ').trim().slice(0, 120) : '',
                        };
                    });
                    if (!women.switchOn) {
                        skip('the women-safety line on the seat map', 'women_layer_on is off');
                    } else {
                        check('the women-safety line is shown on the seat map',
                            women.exists && women.shown && women.text.length > 0, women.text || 'nothing rendered');
                    }
                } else if (seats.n === 0) {
                    fail('the seat map renders at least one seat', 'the seat grid is empty');
                } else {
                    fail('the seat map renders at least one seat', 'no #seatGrid in the DOM');
                }
            } else {
                /* Not a bug: the office can switch the daily service off,
                   or the searched day can fall outside the horizon. */
                skip('the results board lists at least one coach',
                    board.empty || 'the board painted no cards and no empty state');
                skip('the seat map renders at least one seat', 'no coach to open');
            }
        }
    }

    checkClean('the booking funnel logged no errors and no failed requests', funnelMark);

    /* ---------------------------------------------------------------
     *  4. /bus — the server-rendered page search engines read.
     * ------------------------------------------------------------- */
    console.log('');
    console.log('-- /bus (server-rendered SEO page) --');
    const seoMark = mark();
    const seo = await context.newPage();
    watch(seo);
    const res = await seo.goto(BASE + '/bus', { waitUntil: 'domcontentloaded', timeout: 30000 });
    check('/bus answers 200 with HTML',
        !!res && res.status() === 200 && /text\/html/i.test(res.headers()['content-type'] || ''),
        'HTTP ' + (res ? res.status() : '?') + ' ' + ((res && res.headers()['content-type']) || ''));

    const h1 = await firstMatch(seo, ['main h1', 'h1', '[role="heading"]']);
    const h1text = h1.loc ? (((await h1.loc.textContent().catch(() => '')) || '').trim()) : '';
    check('/bus has a heading', h1text.length > 0, h1text.slice(0, 70) || 'no heading element');

    /* A way back into the single-page app: the brand link to "/" or any
       link into a hash route. An absolute link to the same origin
       counts too, which is what the route cards use. */
    const backIn = await seo.evaluate((origin) => Array.from(document.querySelectorAll('a[href]'))
        .map((a) => a.getAttribute('href') || '')
        .filter((h) => h === '/' || h.startsWith('/#') || h.includes('/#/')
            || h === origin + '/' || h.startsWith(origin + '/#'))
        .slice(0, 3), ORIGIN);
    check('/bus links back into the app', backIn.length > 0, backIn.join(' ') || 'no link to / or to a hash route');

    checkClean('/bus logged no errors and no failed requests', seoMark);

    /* ---------------------------------------------------------------
     *  5. Where the pictures went.
     * ------------------------------------------------------------- */
    console.log('');
    console.log('-- screenshots --');
    note('home:  ' + homeShot + (homeShotOk ? '' : '  (not written)'));
    note('seats: ' + seatShot
        + (seatShotOk ? '' : (seatsReached ? '  (not written)' : '  (not written — the seat map was not reached)')));
} catch (e) {
    fail('the smoke run completed', String((e && e.stack) || e).split('\n').slice(0, 3).join(' | '));
} finally {
    /* Never leave a Chromium behind, whatever went wrong above. */
    if (browser) { try { await browser.close(); } catch (e) { /* already gone */ } }
}

finish();
