<?php
/**
 * 16 Nov 2026 — v2.0 P3-6 "inline edit everywhere" (unit Y3).
 *
 * Over HTTP, as a temporary super-admin, every new inline-save whitelist entry:
 *   schedule.dep_time / schedule.fare   (Bus Calendar, via schedule-action.php's own functions)
 *   setting.extra_seats_per_bus          (Extra seats page, super-admin only)
 *   coupon.discount_value                (Offers, the offers-form rules)
 *   staff.full_name                      (Staff list)
 * saves, writes its audit row, refuses without the CSRF token, refuses a login
 * without the permission (temporary 'support' and 'operations' accounts), and
 * refuses bad values (time format, price 0 / absurd, percent > 100, a percent
 * offer with no maximum, 41 seats). The old schedule-action.php set_dep_time /
 * set_price answers are checked byte for byte. The pages carry the markers and
 * the script. Every row it makes is removed and every value put back.
 *
 *   SHG_TEST_BASE=http://localhost:8983 php -c <ini> tests/u11-Y3-inline-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/schedulemaker.php';
require_once INCLUDE_PATH . '/quickunlock.php';

if (!function_exists('curl_init')) { echo "curl extension not loaded — re-run with -d extension=php_curl.dll\n"; exit(1); }
define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8983'), '/'));
const TD       = '2099-07-23';
const BOSS     = 'u11y3_boss';
const SUPPORT  = 'u11y3_support';
const OPS      = 'u11y3_ops';
const TARGET   = 'u11y3_target';
const PW       = 'U11Y3Inline!77';
const C_FLAT   = 'U11Y3FLAT';
const C_PCT    = 'U11Y3PCT';
const C_NOCAP  = 'U11Y3NOCAP';

/* node: argv = inline-edit js, calendar page script, schedule id, its date, another date.
   Prints a JSON list of [label, ok, extra]. The fake DOM parses no HTML: a string
   given to innerHTML stays a string, nodes are built by hand where a test needs them. */
const Y3_DOM_HARNESS = <<<'JS'
'use strict';
const vm = require('node:vm');
const fs = require('node:fs');
const [, , ieFile, calFile, sidArg, TD, TD2] = process.argv;
const SID = parseInt(sidArg, 10);
const rows = [];
const ok = (label, cond, extra) => rows.push([label, !!cond, extra === undefined ? '' : String(extra)]);

function parseSel(s) {
  s = s.trim();
  const m = /^([a-zA-Z][\w-]*)?/.exec(s);
  const out = { tag: m[1] ? m[1].toUpperCase() : null, cls: [], attrs: [], nots: [] };
  let rest = s.slice(m[0].length), mm;
  while (rest.length) {
    if ((mm = /^\.([\w-]+)/.exec(rest))) out.cls.push(mm[1]);
    else if ((mm = /^\[([\w-]+)(?:="([^"]*)")?\]/.exec(rest))) out.attrs.push([mm[1], mm[2]]);
    else if ((mm = /^:not\(\[([\w-]+)\]\)/.exec(rest))) out.nots.push(mm[1]);
    else throw new Error('selector not in the test DOM: ' + s);
    rest = rest.slice(mm[0].length);
  }
  return out;
}
function matches(el, sel) {
  if (el.isDoc) return false;
  return sel.split(',').some(function (part) {
    const p = parseSel(part);
    if (p.tag && el.tagName !== p.tag) return false;
    if (!p.cls.every(c => el.classList.contains(c))) return false;
    if (!p.attrs.every(([n, v]) => el.hasAttribute(n) && (v === undefined || el.getAttribute(n) === v))) return false;
    return !p.nots.some(n => el.hasAttribute(n));
  });
}
let ACTIVE = null;
class El {
  constructor(tag, attrs, html) {
    this.tagName = String(tag || 'div').toUpperCase();
    this.attrs = {}; this.children = []; this.parentNode = null; this.listeners = {};
    this.html = html || ''; this.style = {}; this.dataset = {}; this.hidden = false; this.value = '';
    this._cls = new Set();
    const self = this;
    this.classList = { add: c => { self._cls.add(c); }, remove: c => { self._cls.delete(c); }, contains: c => self._cls.has(c) };
    Object.keys(attrs || {}).forEach(k => this.setAttribute(k, attrs[k]));
  }
  get className() { return Array.from(this._cls).join(' '); }
  set className(v) { this._cls = new Set(String(v).split(/\s+/).filter(Boolean)); }
  get id() { return this.attrs.id || ''; }
  set id(v) { this.attrs.id = String(v); }
  get title() { return this.attrs.title || ''; }
  set title(v) { this.attrs.title = String(v); }
  getAttribute(n) { return n === 'class' ? (this._cls.size ? this.className : null) : (Object.prototype.hasOwnProperty.call(this.attrs, n) ? this.attrs[n] : null); }
  setAttribute(n, v) { if (n === 'class') this.className = v; else this.attrs[n] = String(v); }
  hasAttribute(n) { return this.getAttribute(n) !== null; }
  removeAttribute(n) { delete this.attrs[n]; }
  appendChild(c) { if (c.parentNode) c.parentNode.removeChild(c); c.parentNode = this; this.children.push(c); return c; }
  insertBefore(c, ref) { if (c.parentNode) c.parentNode.removeChild(c); c.parentNode = this; const i = this.children.indexOf(ref); this.children.splice(i < 0 ? this.children.length : i, 0, c); return c; }
  removeChild(c) { const i = this.children.indexOf(c); if (i >= 0) this.children.splice(i, 1); c.parentNode = null; return c; }
  get firstChild() { return this.children[0] || null; }
  set innerHTML(v) { this.children.forEach(c => { c.parentNode = null; }); this.children = []; this.html = String(v); }
  get innerHTML() { return this.html + this.children.map(c => c.outerHTML).join(''); }
  get outerHTML() {
    const a = Object.keys(this.attrs).map(k => ' ' + k + '="' + this.attrs[k] + '"').join('') + (this._cls.size ? ' class="' + this.className + '"' : '');
    const t = this.tagName.toLowerCase();
    return '<' + t + a + '>' + this.innerHTML + '</' + t + '>';
  }
  set textContent(v) { this.innerHTML = String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;'); }
  get textContent() { return this.html.replace(/<[^>]*>/g, '').replace(/&lt;/g, '<').replace(/&amp;/g, '&') + this.children.map(c => c.textContent).join(''); }
  all() { const out = []; const walk = n => n.children.forEach(c => { out.push(c); walk(c); }); walk(this); return out; }
  querySelectorAll(sel) { return this.all().filter(e => matches(e, sel)); }
  querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
  contains(n) { for (let x = n; x; x = x.parentNode) { if (x === this) return true; } return false; }
  closest(sel) { for (let x = this; x && !x.isDoc; x = x.parentNode) { if (matches(x, sel)) return x; } return null; }
  addEventListener(t, fn) { (this.listeners[t] = this.listeners[t] || []).push(fn); }
  removeEventListener(t, fn) { this.listeners[t] = (this.listeners[t] || []).filter(f => f !== fn); }
  dispatchEvent(ev) {
    if (!ev.target) ev.target = this;
    for (let x = this; x; x = ev.bubbles ? x.parentNode : null) {
      ev.currentTarget = x;
      (x.listeners[ev.type] || []).slice().forEach(fn => fn.call(x, ev));
    }
    return true;
  }
  focus() { ACTIVE = this; }
  blur() {}
  select() {}
  scrollIntoView() {}
}
class CE { constructor(type, init) { this.type = type; this.bubbles = !!(init && init.bubbles); this.detail = init ? init.detail : undefined; this.target = null; } }
function makeDoc() {
  const d = new El('#document');
  d.isDoc = true;
  const html = d.appendChild(new El('html'));
  d.documentElement = html;
  d.head = html.appendChild(new El('head'));
  d.body = html.appendChild(new El('body'));
  d.readyState = 'complete';
  Object.defineProperty(d, 'activeElement', { get: () => ACTIVE || d.body });
  d.createElement = t => new El(t);
  d.getElementById = id => d.all().find(e => e.attrs.id === id) || null;
  return d;
}
const later = (fn, ms) => (ms > 50 ? 0 : setTimeout(fn, ms));   // the 3 s toast timer must not hold node open
const flush = () => new Promise(r => setImmediate(r));

(async function () {
  /* ---------- A: admin-inline-edit.js ---------- */
  const A = makeDoc();
  A.head.appendChild(new El('meta', { name: 'shg-admin-csrf', content: 'tok' }));
  const panelA = A.body.appendChild(new El('div', { id: 'dpBody' }));
  const pending = [];
  const ctxA = vm.createContext({
    document: A, console, CustomEvent: CE, setTimeout: later, clearTimeout: () => {},
    fetch: (url, opt) => new Promise(res => pending.push({ url, body: JSON.parse(opt.body), res }))
  });
  ctxA.window = ctxA;
  ctxA.confirm = () => true;
  vm.runInContext(fs.readFileSync(ieFile, 'utf8'), ctxA, { filename: 'admin-inline-edit.js' });
  const heard = [];
  A.addEventListener('shg:inline-saved', e => heard.push({ target: e.target, d: e.detail || {} }));
  const mkVal = id => panelA.appendChild(new El('span', { 'data-ie-entity': 'schedule', 'data-ie-field': 'fare', 'data-ie-id': String(id),
    'data-ie-value': '2500', 'data-ie-type': 'money', 'data-ie-confirm': '1' }, '₹2,500'));
  async function tapTypeEnter(el, typed, reply, redrawFirst) {
    (A.listeners.click || []).forEach(fn => fn({ target: el, preventDefault() {} }));
    const inp = el.querySelector('input');
    if (!inp) return 'no input box opened';
    inp.value = typed;
    (inp.listeners.keydown || []).forEach(fn => fn({ key: 'Enter', preventDefault() {} }));
    const p = pending.shift();
    if (!p) return 'nothing sent';
    if (redrawFirst) panelA.innerHTML = '<p>21 Nov</p>';    // the office opened another day: the panel is rebuilt mid-save
    p.res({ status: 200, json: () => Promise.resolve(reply) });
    for (let i = 0; i < 5; i++) await flush();
    return p.body;
  }
  const reply = { ok: true, old: 2500, 'new': 2600, data: { fare: 2600 }, message: 'saved' };
  let el = mkVal(SID);
  let sent = await tapTypeEnter(el, '2600', reply, true);
  ok('inline-edit: the save is sent (schedule.fare, typed value)', sent && sent.entity === 'schedule' && sent.field === 'fare' && sent.value === '2600', JSON.stringify(sent));
  ok('inline-edit: value left the page mid-save - the event still reaches document, once', heard.length === 1, heard.length + ' heard');
  const h = heard[0] || { d: {} };
  ok('inline-edit: ...fired on document, detail says what was saved (entity, field, id, reply)', h.target === A && h.d.entity === 'schedule' && h.d.field === 'fare'
    && h.d.id === String(SID) && h.d.res && h.d.res.data && h.d.res.data.fare === 2600 && h.d.el === el && h.d['new'] === 2600);
  heard.length = 0;
  el = mkVal(SID + 1);
  sent = await tapTypeEnter(el, '2700', { ok: true, old: 2500, 'new': 2700, data: { fare: 2700 }, message: 'saved' }, false);
  ok('inline-edit: value still on the page - fired on it, bubbles to document exactly once', heard.length === 1 && heard[0].target === el && heard[0].d.id === String(SID + 1), heard.length + ' heard');
  ok('inline-edit: ...and the value shows the new price', el.getAttribute('data-ie-value') === '2700' && el.textContent === '₹2,700', el.textContent);
  heard.length = 0;
  el = mkVal(SID + 2);
  await tapTypeEnter(el, '0.004', { ok: false, message: 'The price must be more than ₹0' }, false);
  ok('inline-edit: a refused save fires nothing and keeps the old value', heard.length === 0 && el.getAttribute('data-ie-value') === '2500');

  /* ---------- B: the Bus Calendar page script ---------- */
  const B = makeDoc();
  const main = B.body.appendChild(new El('main'));
  main.appendChild(new El('div', { class: 'cal-head' }));
  const cell = main.appendChild(new El('div', { class: 'cal-cell', 'data-date': TD }));
  const chip = cell.appendChild(new El('div', { class: 'chip', 'data-sid': String(SID), title: 'A → B · 06:30 · no bus · 0/36' }));
  chip.appendChild(new El('b', {}, 'AB'));
  const chipT = chip.appendChild(new El('span', { class: 't' }, '06:30 '));
  main.appendChild(new El('div', { class: 'cal-cell', 'data-date': TD2 }));
  const aside = main.appendChild(new El('aside', { id: 'dayPanel', class: 'day-panel' }));
  aside.hidden = true;
  aside.appendChild(new El('h3')).appendChild(new El('span', { id: 'dpTitle' }, 'Day'));
  const dpBody = aside.appendChild(new El('div', { id: 'dpBody', class: 'day-body' }));
  const ctxB = vm.createContext({
    document: B, console, CustomEvent: CE, URL, setTimeout: later, clearTimeout: () => {},
    FormData: class { append() {} }, fetch: () => new Promise(() => {}),
    sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    location: { href: 'http://localhost/admin/calendar.php' },
    confirm: () => false, prompt: () => null, matchMedia: () => ({ matches: false })
  });
  ctxB.window = ctxB;
  vm.runInContext(fs.readFileSync(calFile, 'utf8'), ctxB, { filename: 'calendar-page.js' });
  const C = ctxB.CAL;
  const trip = () => (C.days[TD] || []).find(t => t.id === SID);
  ok('calendar: the page data has the test bus on its day, and that day opened', !!trip() && C.openDate === TD, C.openDate);
  const realOpen = ctxB.calOpen;
  let opens = 0;
  ctxB.calOpen = function (d) { opens++; return realOpen(d); };
  const fire = (target, detail) => target.dispatchEvent(new CE('shg:inline-saved', { bubbles: true, detail }));

  // B1: price saved, the office already on another day when the reply lands
  ctxB.calOpen(TD2);
  const before = dpBody.innerHTML;
  opens = 0;
  fire(B, { entity: 'schedule', field: 'fare', id: String(SID), old: null, 'new': 2600, res: { ok: true, 'new': 2600, data: { fare: 2600 } } });
  ok('calendar: a save landing after another day opened updates that bus\'s own day (C.days)', trip() && trip().fare === 2600, trip() && trip().fare);
  ok('calendar: ...without redrawing the open day or switching to it', opens === 0 && C.openDate === TD2 && dpBody.innerHTML === before, 'opens ' + opens + ', open ' + C.openDate);
  ctxB.calOpen(TD);
  ok('calendar: reopening the day shows the saved price, in the value and on the 💰 Price button', dpBody.innerHTML.includes('data-ie-field="fare" data-ie-id="' + SID + '" data-ie-value="2600"')
    && dpBody.innerHTML.includes('₹2,600') && dpBody.innerHTML.includes('data-act="price" data-sid="' + SID + '" data-fare="2600"'));

  // B2: time saved while another day is open - the month chip moves too
  ctxB.calOpen(TD2);
  fire(B, { entity: 'schedule', field: 'dep_time', id: String(SID), res: { ok: true, data: { time: '07:45', retimed: true, routeTime: '06:30' } } });
  ok('calendar: a time saved off-day updates C.days and the month chip (time, ⏰, title)', trip() && trip().time === '07:45' && trip().retimed === true
    && chipT.textContent.indexOf('07:45') === 0 && chipT.textContent.includes('⏰') && chip.title.includes(' · 07:45 · '), chipT.textContent + ' | ' + chip.title);
  fire(B, { entity: 'coupon', field: 'discount_value', id: String(SID), res: { ok: true, data: { fare: 1 } } });
  ok('calendar: another page\'s entity with the same id is ignored', trip().fare === 2600 && trip().time === '07:45');

  // B3: the open day - only that trip's two lines are redrawn; forms on other trips stay
  ctxB.calOpen(TD);
  dpBody.innerHTML = '';
  const other = dpBody.appendChild(new El('div', { class: 'trip', 'data-sid': '999999' }));
  const otherSub = other.appendChild(new El('div', { class: 'sub', id: 'sub999999' }));
  const halfForm = otherSub.appendChild(new El('select', { id: 'bus999999' }));
  const box = dpBody.appendChild(new El('div', { class: 'trip', 'data-sid': String(SID) }));
  const t1 = box.appendChild(new El('div', { class: 't1' }, '<span class="time">07:45</span>'));
  const meta = box.appendChild(new El('div', { class: 'meta' }, '<span>old meta</span>'));
  const acts = box.appendChild(new El('div', { class: 'acts' }));
  const bPrice = acts.appendChild(new El('button', { 'data-act': 'price', 'data-sid': String(SID), 'data-fare': '2600' }));
  const bTime = acts.appendChild(new El('button', { 'data-act': 'retime', 'data-sid': String(SID), 'data-time': '07:45' }));
  const bExtra = acts.appendChild(new El('button', { 'data-act': 'extra', 'data-sid': String(SID), 'data-time': '07:45' }));
  const ownSub = box.appendChild(new El('div', { class: 'sub', id: 'sub' + SID }));
  ownSub.appendChild(new El('input', { id: 'xt' + SID }));
  const val = meta.appendChild(new El('span', { 'data-ie-entity': 'schedule', 'data-ie-field': 'fare', 'data-ie-id': String(SID) }));
  opens = 0;
  fire(val, { entity: 'schedule', field: 'fare', id: String(SID), res: { ok: true, data: { fare: 2750 } } });
  ok('calendar: on the open day the panel is NOT redrawn (a half-filled form on another trip stays)', opens === 0 && other.parentNode === dpBody
    && halfForm.parentNode === otherSub && ownSub.parentNode === box && ownSub.children.length === 1, 'opens ' + opens);
  ok('calendar: ...the trip\'s price line is redrawn with the new price', meta.innerHTML.includes('data-ie-value="2750"') && meta.innerHTML.includes('₹2,750') && trip().fare === 2750);
  ok('calendar: ...and the 💰 Price button carries it', bPrice.getAttribute('data-fare') === '2750');

  // B4: a line with a value still being typed keeps its box; the other line and the buttons move
  const typing = meta.appendChild(new El('span', { class: 'ie-open' }));
  fire(B, { entity: 'schedule', field: 'dep_time', id: String(SID), res: { ok: true, data: { time: '08:05', retimed: true, routeTime: '06:30' } } });
  ok('calendar: a line with a box still open is left alone', typing.parentNode === meta);
  ok('calendar: ...the time line and the ⏰ Time / ➕ Extra bus buttons move to 08:05', t1.innerHTML.includes('data-ie-value="08:05"') && bTime.getAttribute('data-time') === '08:05'
    && bExtra.getAttribute('data-time') === '08:05' && opens === 0, t1.innerHTML.slice(0, 120));
  fire(B, { entity: 'schedule', field: 'fare', id: String(SID), res: { ok: true, data: { fare: 0 } } });
  ok('calendar: price cleared - C.days and the button go back to the normal fare', trip().fare === 0 && bPrice.getAttribute('data-fare') === '');
  process.stdout.write(JSON.stringify(rows));
})().catch(function (e) {
  rows.push(['harness crashed', false, String(e && e.stack || e).slice(0, 400)]);
  process.stdout.write(JSON.stringify(rows));
});
JS;

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
$JARS = [];
function jar(string $who): string {
    global $JARS;
    return $JARS[$who] ??= sys_get_temp_dir() . '/shg_u11y3_' . $who . '_' . getmypid() . '.txt';
}
/** @param array{form?:array<string,mixed>, json?:array<string,mixed>, headers?:list<string>} $opt */
function http(string $who, string $url, array $opt = []): array {
    $ch = curl_init(BASE . $url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => jar($who), CURLOPT_COOKIEFILE => jar($who), CURLOPT_TIMEOUT => 60]);
    $h = $opt['headers'] ?? [];
    if (isset($opt['form'])) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['form'])); }
    if (isset($opt['json'])) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opt['json'], JSON_UNESCAPED_UNICODE));
        $h[] = 'Content-Type: application/json';
        $h[] = 'Accept: application/json';
    }
    if ($h !== []) { curl_setopt($ch, CURLOPT_HTTPHEADER, $h); }
    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $body = substr($raw, $hlen);
    return ['code' => $code, 'body' => $body, 'headers' => substr($raw, 0, $hlen), 'json' => json_decode($body, true)];
}
function loginCsrf(string $h): string {
    if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $h, $m) === 1) { return html_entity_decode($m[1]); }
    return preg_match('/name="' . preg_quote(CSRF_TOKEN_NAME, '/') . '"\s+value="([^"]+)"/', $h, $m) ? $m[1] : '';
}
function signIn(string $who, string $user): bool {
    $login = http($who, '/admin/login.php?classic=1&other=1');
    $r = http($who, '/admin/login.php', ['form' => [CSRF_TOKEN_NAME => loginCsrf($login['body']), 'classic' => '1', 'username' => $user, 'password' => PW]]);
    return $r['code'] === 302 && !str_contains($r['headers'], 'login.php');
}
/** the staff token from the first page that opens (meta shg-admin-csrf, admin_header) */
function staffCsrf(string $who, array $paths): string {
    foreach ($paths as $p) {
        $r = http($who, $p);
        if ($r['code'] === 200 && preg_match('/<meta name="shg-admin-csrf" content="([^"]+)"/', $r['body'], $m) === 1) {
            return html_entity_decode($m[1]);
        }
    }
    return '';
}
function ie(string $who, string $csrf, string $entity, string $field, int $id, string $value): array {
    return http($who, '/admin/api/inline-save.php', ['json' => ['entity' => $entity, 'field' => $field, 'id' => $id, 'value' => $value],
        'headers' => $csrf !== '' ? ['X-CSRF-Token: ' . $csrf] : []]);
}
function lastAuditId(): int {
    return (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);
}
/** @return array<string,mixed>|null the newest matching audit row written after $since */
function auditAfter(int $since, string $action, string $entityId): ?array {
    return Database::fetch('SELECT * FROM audit_logs WHERE id > :s AND action = :a AND entity_id = :e ORDER BY id DESC LIMIT 1',
        ['s' => $since, 'a' => $action, 'e' => $entityId]);
}
/** every admin id this suite has made in this run (kept after the rows go, for the "nothing left" check) */
$MADE = [];
function cleanup(): void {
    global $MADE;
    $ids = array_map(static fn(array $r): int => (int) $r['id'],
        Database::fetchAll('SELECT id FROM admins WHERE username IN (:a, :b, :c, :d)', ['a' => BOSS, 'b' => SUPPORT, 'c' => OPS, 'd' => TARGET]));
    $ids = array_values(array_unique(array_merge($ids, $MADE)));
    if ($ids !== []) {
        $in = implode(',', $ids);
        Database::run("DELETE FROM audit_logs WHERE actor_type = 'admin' AND actor_id IN ($in)");
        // each sign-in opens an admin_sessions row (AdminSessions::open) and writes an
        // admin_login_events row (LoginLog::record); neither has a foreign key, so
        // deleting the admin leaves them behind unless they go here
        try { Database::run("DELETE FROM admin_sessions WHERE admin_id IN ($in)"); } catch (Throwable $e) {}
        try { Database::run("DELETE FROM admin_login_events WHERE admin_id IN ($in)"); } catch (Throwable $e) {}
        try { Database::run("DELETE FROM rate_limits WHERE identifier IN ('admin:" . implode("','admin:", $ids) . "')"); } catch (Throwable $e) {}
    }
    /* By the suite's own usernames: a refused attempt (admin_id NULL), and what
       earlier runs left before this clean-up existed - their sessions are found
       through their sign-in events (admin ids are never reused; a live account
       that is not one of ours is never touched). */
    $u = ['a' => BOSS, 'b' => SUPPORT, 'c' => OPS, 'd' => TARGET];
    try {
        Database::run('DELETE s FROM admin_sessions s JOIN admin_login_events e ON e.admin_id = s.admin_id
                        WHERE e.username IN (:a, :b, :c, :d)
                          AND NOT EXISTS (SELECT 1 FROM admins x WHERE x.id = s.admin_id AND x.username NOT IN (:a2, :b2, :c2, :d2))',
            $u + ['a2' => BOSS, 'b2' => SUPPORT, 'c2' => OPS, 'd2' => TARGET]);
    } catch (Throwable $e) {}
    try {
        Database::run('DELETE e FROM admin_login_events e WHERE e.username IN (:a, :b, :c, :d)
                          AND NOT EXISTS (SELECT 1 FROM admins x WHERE x.id = e.admin_id AND x.username NOT IN (:a2, :b2, :c2, :d2))',
            $u + ['a2' => BOSS, 'b2' => SUPPORT, 'c2' => OPS, 'd2' => TARGET]);
    } catch (Throwable $e) {}
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => TD]) as $s) {
        try { Database::run('DELETE FROM seat_locks WHERE schedule_id = :s', ['s' => (int) $s['id']]); } catch (Throwable $e) {}
    }
    Database::delete('schedules', 'travel_date = :d', ['d' => TD]);
    Database::run("DELETE FROM coupons WHERE code IN ('" . C_FLAT . "', '" . C_PCT . "', '" . C_NOCAP . "')");
    Database::run('DELETE FROM admins WHERE username IN (:a, :b, :c, :d)', ['a' => BOSS, 'b' => SUPPORT, 'c' => OPS, 'd' => TARGET]);
}
$mkAdmin = static function (string $user, string $role, string $name): int {
    global $MADE;
    $id = (int) Database::insert('admins', ['username' => $user, 'password_hash' => password_hash(PW, PASSWORD_DEFAULT),
        'full_name' => $name, 'role' => $role, 'permissions' => json_encode([]), 'is_active' => 1, 'must_change_pw' => 0]);
    $MADE[] = $id;
    QuickUnlock::set($id, 'pin', '583941');   // the code-first door sends a PIN-less account to pin-setup first
    return $id;
};

/* the one setting this test changes, put back exactly as found */
$keepSeats = Database::fetch("SELECT svalue, stype, sgroup, is_public FROM settings WHERE skey = 'extra_seats_per_bus'");

echo "\n=== Y3 inline edit: calendar time / price, extra seats, offer value, staff name ===\n\n";
cleanup();
try {
    /* ---------------- seed ---------------- */
    $route   = Database::fetch("SELECT * FROM routes WHERE is_active = 1 AND dep_time IS NOT NULL ORDER BY sort_order, id LIMIT 1");
    $routeId = (int) $route['id'];
    $routeHm = substr((string) $route['dep_time'], 0, 5);
    $sched   = Seats::schedule($routeId, TD);
    $sid     = (int) $sched['id'];
    Database::update('schedules', ['is_blocked' => 0, 'status' => 'scheduled', 'dep_time_override' => null, 'fare_override' => null], 'id = :i', ['i' => $sid]);

    $bossId   = $mkAdmin(BOSS, 'superadmin', 'U11 Y3 Boss');
    $supId    = $mkAdmin(SUPPORT, 'support', 'U11 Y3 Support');      // no schedules / coupons / staff permission
    $opsId    = $mkAdmin(OPS, 'operations', 'U11 Y3 Ops');           // schedules.manage + staff.manage, NOT coupons.edit, not super
    $targetId = $mkAdmin(TARGET, 'counter', 'U11 Y3 Target');
    $cFlat  = (int) Database::insert('coupons', ['code' => C_FLAT, 'title' => 'U11 flat', 'discount_type' => 'flat', 'discount_value' => 200, 'max_discount' => null, 'is_active' => 0]);
    $cPct   = (int) Database::insert('coupons', ['code' => C_PCT, 'title' => 'U11 pct', 'discount_type' => 'percent', 'discount_value' => 10, 'max_discount' => 500, 'is_active' => 0]);
    $cNoCap = (int) Database::insert('coupons', ['code' => C_NOCAP, 'title' => 'U11 nocap', 'discount_type' => 'percent', 'discount_value' => 10, 'max_discount' => null, 'is_active' => 0]);
    check('seeded: a far-future bus, 4 temporary logins, 3 offers', $sid > 0 && $bossId > 0 && $supId > 0 && $opsId > 0 && $targetId > 0 && $cFlat > 0 && $cPct > 0 && $cNoCap > 0,
        'schedule #' . $sid . ' on route ' . $route['route_code']);

    try { Database::run("DELETE FROM rate_limits WHERE identifier IN ('127.0.0.1', '::1') AND bucket IN ('admin_login', 'code_login_enter', 'quick_unlock', 'code_login_try')"); } catch (Throwable $e) {}
    check('super-admin signs in', signIn('boss', BOSS));
    $csrf = staffCsrf('boss', ['/admin/calendar.php?m=2099-07&d=' . TD]);
    check('staff CSRF token read from the page', $csrf !== '');

    /* ---------------- pages carry the markers ---------------- */
    $cal = http('boss', '/admin/calendar.php?m=2099-07&d=' . TD);
    check('calendar: loads the inline editor', $cal['code'] === 200 && (bool) preg_match('#<script src="/assets/js/admin-inline-edit\.js\?v=\d+" defer></script>#', $cal['body']));
    check('calendar: day panel marks time + price (schedule entity, price asks old → new)', str_contains($cal['body'], 'data-ie-entity="schedule" data-ie-field="dep_time"')
        && str_contains($cal['body'], 'data-ie-entity="schedule" data-ie-field="fare"') && str_contains($cal['body'], 'data-ie-type="money" data-ie-confirm="1"'));
    check('calendar: month chips carry the schedule id', str_contains($cal['body'], 'data-sid="' . $sid . '"'));
    check('calendar: the ⏰ Time / 💰 Price prompt buttons stay (fallback)', str_contains($cal['body'], 'data-act="retime"') && str_contains($cal['body'], 'data-act="price"'));
    if (preg_match('#<script>\s*(window\.CAL = .*?)</script>#s', $cal['body'], $sm) === 1) {
        $node = function_exists('shell_exec') && function_exists('exec') ? trim((string) @shell_exec('node --version 2>&1')) : '';
        if (str_starts_with($node, 'v')) {
            $tmp = sys_get_temp_dir() . '/shg_u11y3_cal_' . getmypid() . '.js';
            file_put_contents($tmp, $sm[1]);
            exec('node --check ' . escapeshellarg($tmp) . ' 2>&1', $nOut, $nRc);
            @unlink($tmp);
            check('calendar: the page script parses (node --check)', $nRc === 0, implode(' ', $nOut));
            /* the real admin-inline-edit.js and the real calendar page script, run
               in node on a small fake DOM: a save whose value left the page while
               it ran still reaches the page, and the calendar keeps its own copy
               of every day right without redrawing the whole panel */
            $calJs = sys_get_temp_dir() . '/shg_u11y3_calpage_' . getmypid() . '.js';
            $domJs = sys_get_temp_dir() . '/shg_u11y3_dom_' . getmypid() . '.js';
            file_put_contents($calJs, $sm[1]);
            file_put_contents($domJs, Y3_DOM_HARNESS);
            $cmd = 'node ' . escapeshellarg($domJs) . ' ' . escapeshellarg(dirname(__DIR__) . '/assets/js/admin-inline-edit.js') . ' '
                 . escapeshellarg($calJs) . ' ' . $sid . ' ' . TD . ' 2099-07-24 2>&1';
            $dOut = (string) shell_exec($cmd);
            @unlink($calJs); @unlink($domJs);
            $dRows = json_decode(trim($dOut), true);
            if (!is_array($dRows)) {
                check('DOM harness ran', false, substr($dOut, 0, 400));
            } else {
                foreach ($dRows as $dr) { check('dom: ' . (string) $dr[0], (bool) $dr[1], (string) ($dr[2] ?? '')); }
            }
        } else {
            echo "  (node not on PATH — calendar script parse check and DOM checks skipped)\n";
        }
    }
    $xs = http('boss', '/admin/extra-seats.php');
    check('extra seats: KPI + heading are tap-to-edit for the super-admin', $xs['code'] === 200 && substr_count($xs['body'], 'data-ie-entity="setting" data-ie-field="extra_seats_per_bus"') === 2
        && str_contains($xs['body'], 'admin-inline-edit.js'));
    check('extra seats: the old form stays', str_contains($xs['body'], 'name="extra_seats_per_bus" min="0" max="40"'));
    // the save event may come on document (value redrawn mid-save): the page reads detail.field, never e.target
    check('extra seats: the form follows a save by detail.field (not e.target)', str_contains($xs['body'], "d.field === 'extra_seats_per_bus'")
        && !preg_match('/shg:inline-saved[^<]*e\.target/s', $xs['body']));
    $of = http('boss', '/admin/offers.php');
    check('offers: flat + capped percent values are tap-to-edit with the confirm', $of['code'] === 200
        && str_contains($of['body'], 'data-ie-entity="coupon" data-ie-field="discount_value" data-ie-id="' . $cFlat . '"')
        && str_contains($of['body'], 'data-ie-entity="coupon" data-ie-field="discount_value" data-ie-id="' . $cPct . '" data-ie-value="10" data-ie-type="percent" data-ie-confirm="1"'));
    check('offers: a percent offer with NO maximum is not offered inline (Edit sets the cap)', !str_contains($of['body'], 'data-ie-id="' . $cNoCap . '"')
        && str_contains($of['body'], 'offers.php?edit=' . $cNoCap));
    $st = http('boss', '/admin/staff.php?q=' . TARGET);
    check('staff: the name is tap-to-edit', $st['code'] === 200 && str_contains($st['body'], 'data-ie-entity="staff" data-ie-field="full_name" data-ie-id="' . $targetId . '"')
        && str_contains($st['body'], 'admin-inline-edit.js'));
    $js = http('boss', '/assets/js/admin-inline-edit.js');
    check('script: asks "पुरानो → नयाँ ? · old → new?" and knows percent / int', $js['code'] === 200 && str_contains($js['body'], 'पुरानो → नयाँ ? · old → new?')
        && str_contains($js['body'], "type === 'percent'") && str_contains($js['body'], "type === 'int'"));

    /* ---------------- schedule.dep_time ---------------- */
    $a0 = lastAuditId();
    $r  = ie('boss', $csrf, 'schedule', 'dep_time', $sid, '07:45');
    $ov = Database::scalar('SELECT dep_time_override FROM schedules WHERE id = :i', ['i' => $sid]);
    check('time: 07:45 saves', $r['code'] === 200 && ($r['json']['ok'] ?? false) === true && ($r['json']['new'] ?? '') === '07:45' && $ov === '07:45:00',
        'HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 160));
    $au = auditAfter($a0, 'trip.set_dep_time', (string) $sid);
    check('time: audit trip.set_dep_time (old → new, marked inline)', $au !== null && (int) $au['actor_id'] === $bossId
        && str_contains((string) $au['new_value'], '07:45:00') && str_contains((string) $au['detail'], 'inline edit'));
    foreach (['25:99', '7.30', 'abc', '24:00'] as $bad) {
        $r = ie('boss', $csrf, 'schedule', 'dep_time', $sid, $bad);
        check('time: "' . $bad . '" refused (422)', $r['code'] === 422 && ($r['json']['ok'] ?? true) === false
            && Database::scalar('SELECT dep_time_override FROM schedules WHERE id = :i', ['i' => $sid]) === '07:45:00', (string) ($r['json']['message'] ?? ''));
    }
    $r = ie('boss', $csrf, 'schedule', 'dep_time', $sid, '07:45');
    check('time: the same time again is "no change", no audit row', $r['code'] === 200 && ($r['json']['ok'] ?? false) === true
        && str_contains((string) ($r['json']['message'] ?? ''), 'No change') && (auditAfter($a0, 'trip.set_dep_time', (string) $sid)['id'] ?? null) === ($au['id'] ?? 'x'));
    $r = ie('boss', $csrf, 'schedule', 'dep_time', $sid, '');
    check('time: empty = back to the route time (shown as ' . $routeHm . ')', $r['code'] === 200 && array_key_exists('new', (array) $r['json']) && $r['json']['new'] === null
        && ($r['json']['raw'] ?? '') === $routeHm && Database::scalar('SELECT dep_time_override FROM schedules WHERE id = :i', ['i' => $sid]) === null);

    /* ---------------- schedule.fare ---------------- */
    $a1 = lastAuditId();
    $r  = ie('boss', $csrf, 'schedule', 'fare', $sid, '2500');
    check('price: 2500 saves', $r['code'] === 200 && ($r['json']['ok'] ?? false) === true && (float) ($r['json']['new'] ?? 0) === 2500.0
        && (float) Database::scalar('SELECT fare_override FROM schedules WHERE id = :i', ['i' => $sid]) === 2500.0, substr($r['body'], 0, 160));
    $au = auditAfter($a1, 'trip.set_price', (string) $sid);
    check('price: audit trip.set_price (old null → 2500, marked inline)', $au !== null && str_contains((string) $au['old_value'], 'null')
        && str_contains((string) $au['new_value'], '2500') && str_contains((string) $au['detail'], 'inline edit'));
    /* 0.004 / 0.001 / .004 / 1e-3 are more than 0 as typed but round to ₹0.00,
       which the price reader turns into "clear" — they must be refused like 0,
       never quietly put the bus back on the normal fare */
    foreach (['0', '-5', '200000', 'abc', '0.00', '0.004', '0.001', '.004', '1e-3', '-0', '₹0.004'] as $bad) {
        $a1b = lastAuditId();
        $r = ie('boss', $csrf, 'schedule', 'fare', $sid, $bad);
        check('price: "' . $bad . '" refused (422), price unchanged, no audit row', $r['code'] === 422 && ($r['json']['ok'] ?? true) === false
            && (float) Database::scalar('SELECT fare_override FROM schedules WHERE id = :i', ['i' => $sid]) === 2500.0
            && auditAfter($a1b, 'trip.set_price', (string) $sid) === null, 'HTTP ' . $r['code'] . ' ' . (string) ($r['json']['message'] ?? ''));
    }
    $r = ie('boss', $csrf, 'schedule', 'fare', $sid, '0.004');
    check('price: the 0.004 refusal says "more than ₹0 — leave it blank"', str_contains((string) ($r['json']['message'] ?? ''), 'more than ₹0'), (string) ($r['json']['message'] ?? ''));
    $r = ie('boss', $csrf, 'schedule', 'fare', $sid, '₹2,600');
    check('price: "₹2,600" reads as 2600', $r['code'] === 200 && (float) Database::scalar('SELECT fare_override FROM schedules WHERE id = :i', ['i' => $sid]) === 2600.0);
    $r = ie('boss', $csrf, 'schedule', 'fare', $sid, '');
    check('price: empty = the normal fare again', $r['code'] === 200 && ($r['json']['ok'] ?? false) === true
        && Database::scalar('SELECT fare_override FROM schedules WHERE id = :i', ['i' => $sid]) === null);

    /* ---------------- setting.extra_seats_per_bus ---------------- */
    $seats0 = Seats::extraSeatCount();
    foreach (['41', '-1', 'abc', '2.5', '', '100'] as $bad) {
        $r = ie('boss', $csrf, 'setting', 'extra_seats_per_bus', 1, $bad);
        Settings::flush();
        check('extra seats: "' . $bad . '" refused (422), unchanged', $r['code'] === 422 && Seats::extraSeatCount() === $seats0, (string) ($r['json']['message'] ?? ''));
    }
    $want = $seats0 === 12 ? 13 : 12;
    $a2 = lastAuditId();
    $r  = ie('boss', $csrf, 'setting', 'extra_seats_per_bus', 0, (string) $want);
    Settings::flush();
    check('extra seats: ' . $want . ' saves (the id is ignored for a setting)', $r['code'] === 200 && ($r['json']['ok'] ?? false) === true && Seats::extraSeatCount() === $want,
        substr($r['body'], 0, 160));
    $au = auditAfter($a2, 'settings.extra_seats', 'extra_seats_per_bus');
    check('extra seats: audit settings.extra_seats (n ' . $seats0 . ' → ' . $want . ')', $au !== null && str_contains((string) $au['old_value'], (string) $seats0)
        && str_contains((string) $au['new_value'], (string) $want));

    /* ---------------- coupon.discount_value ---------------- */
    $dv = static fn(int $id): float => (float) Database::scalar('SELECT discount_value FROM coupons WHERE id = :i', ['i' => $id]);
    $a3 = lastAuditId();
    $r  = ie('boss', $csrf, 'coupon', 'discount_value', $cFlat, '250');
    check('offer: flat ₹200 → ₹250 saves', $r['code'] === 200 && ($r['json']['ok'] ?? false) === true && $dv($cFlat) === 250.0, substr($r['body'], 0, 160));
    $au = auditAfter($a3, 'offer.update', C_FLAT);
    check('offer: audit offer.update (the form save\'s row) with old → new', $au !== null && str_contains((string) $au['old_value'], '200')
        && str_contains((string) $au['new_value'], '250') && str_contains((string) $au['detail'], 'inline edit'));
    foreach (['0', '-10', 'abc', ''] as $bad) {
        $r = ie('boss', $csrf, 'coupon', 'discount_value', $cFlat, $bad);
        check('offer: flat "' . $bad . '" refused (422)', $r['code'] === 422 && $dv($cFlat) === 250.0, (string) ($r['json']['message'] ?? ''));
    }
    $r = ie('boss', $csrf, 'coupon', 'discount_value', $cPct, '101');
    check('offer: percent 101 refused (over 100%)', $r['code'] === 422 && $dv($cPct) === 10.0, (string) ($r['json']['message'] ?? ''));
    $r = ie('boss', $csrf, 'coupon', 'discount_value', $cPct, '15');
    check('offer: capped percent 10 → 15 saves', $r['code'] === 200 && $dv($cPct) === 15.0);
    $r = ie('boss', $csrf, 'coupon', 'discount_value', $cNoCap, '12');
    check('offer: a percent offer with no maximum refused', $r['code'] === 422 && $dv($cNoCap) === 10.0
        && str_contains((string) ($r['json']['message'] ?? ''), 'maximum'), (string) ($r['json']['message'] ?? ''));
    $r = ie('boss', $csrf, 'coupon', 'discount_value', 999999999, '10');
    check('offer: an unknown offer is 404', $r['code'] === 404);

    /* ---------------- staff.full_name ---------------- */
    $nm = static fn(int $id): string => (string) Database::scalar('SELECT full_name FROM admins WHERE id = :i', ['i' => $id]);
    $a4 = lastAuditId();
    $r  = ie('boss', $csrf, 'staff', 'full_name', $targetId, '  U11   Y3 Renamed ');
    check('staff: name saves (spaces tidied)', $r['code'] === 200 && ($r['json']['ok'] ?? false) === true && $nm($targetId) === 'U11 Y3 Renamed', substr($r['body'], 0, 160));
    $au = auditAfter($a4, 'staff.setname', TARGET);
    check('staff: audit staff.setname (old → new)', $au !== null && str_contains((string) $au['old_value'], 'U11 Y3 Target') && str_contains((string) $au['new_value'], 'U11 Y3 Renamed'));
    foreach (['', 'A', '   '] as $bad) {
        $r = ie('boss', $csrf, 'staff', 'full_name', $targetId, $bad);
        check('staff: "' . $bad . '" refused (422)', $r['code'] === 422 && $nm($targetId) === 'U11 Y3 Renamed');
    }

    /* ---------------- no CSRF token / not on the whitelist ---------------- */
    foreach ([['schedule', 'dep_time', $sid, '06:00'], ['schedule', 'fare', $sid, '3000'], ['setting', 'extra_seats_per_bus', 1, '5'],
              ['coupon', 'discount_value', $cFlat, '300'], ['staff', 'full_name', $targetId, 'No Token']] as [$e, $f, $i, $v]) {
        $r = ie('boss', '', $e, $f, $i, $v);
        $r2 = ie('boss', 'not-the-token', $e, $f, $i, $v);
        check('no CSRF: ' . $e . '.' . $f . ' refused (419, also with a wrong token)', $r['code'] === 419 && $r2['code'] === 419);
    }
    Settings::flush();
    check('no CSRF: nothing changed', Database::scalar('SELECT dep_time_override FROM schedules WHERE id = :i', ['i' => $sid]) === null
        && Database::scalar('SELECT fare_override FROM schedules WHERE id = :i', ['i' => $sid]) === null
        && Seats::extraSeatCount() === $want && $dv($cFlat) === 250.0 && $nm($targetId) === 'U11 Y3 Renamed');
    $r = ie('boss', $csrf, 'schedule', 'status', $sid, 'cancelled');
    $r2 = ie('boss', $csrf, 'setting', 'staff_seats', 1, '[]');
    $r3 = ie('boss', $csrf, 'coupon', 'max_discount', $cPct, '0');
    check('whitelist: schedule.status / setting.staff_seats / coupon.max_discount refused (400)', $r['code'] === 400 && $r2['code'] === 400 && $r3['code'] === 400);

    /* ---------------- a login without the permission ---------------- */
    check('support login signs in', signIn('sup', SUPPORT));
    $sCsrf = staffCsrf('sup', ['/admin/bookings.php', '/admin/index.php', '/admin/home.php']);
    check('support login: staff CSRF token read', $sCsrf !== '');
    foreach ([['schedule', 'dep_time', $sid, '06:00'], ['schedule', 'fare', $sid, '3000'], ['setting', 'extra_seats_per_bus', 1, '5'],
              ['coupon', 'discount_value', $cFlat, '300'], ['staff', 'full_name', $targetId, 'By Support'], ['route_stop', 'landmark', 1, 'x']] as [$e, $f, $i, $v]) {
        $r = ie('sup', $sCsrf, $e, $f, $i, $v);
        check('support (no permission): ' . $e . '.' . $f . ' refused (403)', $r['code'] === 403, 'HTTP ' . $r['code']);
    }
    check('operations login signs in', signIn('ops', OPS));
    $oCsrf = staffCsrf('ops', ['/admin/calendar.php?m=2099-07']);
    check('operations login: staff CSRF token read', $oCsrf !== '');
    $r = ie('ops', $oCsrf, 'coupon', 'discount_value', $cFlat, '300');
    check('operations (no coupons.edit): offer value refused (403)', $r['code'] === 403);
    $r = ie('ops', $oCsrf, 'setting', 'extra_seats_per_bus', 1, '5');
    check('operations (not super-admin): extra seats refused (403)', $r['code'] === 403);
    $r = ie('ops', $oCsrf, 'staff', 'full_name', $bossId, 'Hijack');
    check('operations: a super-admin\'s name refused (403)', $r['code'] === 403 && $nm($bossId) === 'U11 Y3 Boss');
    $r = ie('ops', $oCsrf, 'staff', 'full_name', $opsId, 'Myself');
    check('operations: own name refused (403, office renames you)', $r['code'] === 403 && $nm($opsId) === 'U11 Y3 Ops');
    $r = ie('ops', $oCsrf, 'schedule', 'dep_time', $sid, '06:15');
    check('operations (schedules.manage): bus time saves', $r['code'] === 200
        && Database::scalar('SELECT dep_time_override FROM schedules WHERE id = :i', ['i' => $sid]) === '06:15:00');
    $r = ie('ops', $oCsrf, 'staff', 'full_name', $targetId, 'U11 Y3 By Ops');
    check('operations (staff.manage): a counter\'s name saves', $r['code'] === 200 && $nm($targetId) === 'U11 Y3 By Ops');
    Settings::flush();
    check('refusals changed nothing', $dv($cFlat) === 250.0 && Seats::extraSeatCount() === $want);

    /* ---------------- the old schedule-action.php endpoint, byte for byte ---------------- */
    Database::update('schedules', ['dep_time_override' => null, 'fare_override' => null], 'id = :i', ['i' => $sid]);
    $sa = static fn(array $f): array => http('boss', '/admin/api/schedule-action.php', ['form' => [CSRF_TOKEN_NAME => $csrf, 'schedule_id' => $sid] + $f]);
    $exp = static fn(array $p): string => json_encode($p, JSON_UNESCAPED_UNICODE);
    $a5 = lastAuditId();
    $r  = $sa(['action' => 'set_dep_time', 'dep_time' => '8:10']);
    check('old set_dep_time: same answer, byte for byte', $r['body'] === $exp(['ok' => true, 'action' => 'set_dep_time', 'message' => 'Departure time set to 08:10.',
        'schedule_id' => $sid, 'extra' => ['depTimeOverride' => '08:10']]), $r['body']);
    $au = auditAfter($a5, 'trip.set_dep_time', (string) $sid);
    check('old set_dep_time: audit detail unchanged ("by admin #id")', $au !== null && (string) $au['detail'] === 'by admin #' . $bossId, (string) ($au['detail'] ?? ''));
    $r = $sa(['action' => 'set_dep_time', 'dep_time' => '08:10']);
    check('old set_dep_time: "already" error unchanged', $r['body'] === $exp(['ok' => false, 'action' => 'set_dep_time', 'error' => 'That is already the current departure time.']), $r['body']);
    $r = $sa(['action' => 'set_dep_time', 'dep_time' => '99:00']);
    check('old set_dep_time: format error unchanged', $r['body'] === $exp(['ok' => false, 'action' => 'set_dep_time', 'error' => 'Time must be HH:MM (24-hour), e.g. 07:30 or 18:15.']), $r['body']);
    $r = $sa(['action' => 'set_dep_time', 'dep_time' => '']);
    check('old set_dep_time: clear unchanged', $r['body'] === $exp(['ok' => true, 'action' => 'set_dep_time', 'message' => 'Departure time reset to route default.',
        'schedule_id' => $sid, 'extra' => ['depTimeOverride' => null]]), $r['body']);
    $r = $sa(['action' => 'set_price', 'fare' => '2700']);
    check('old set_price: same answer, byte for byte', $r['body'] === $exp(['ok' => true, 'action' => 'set_price',
        'message' => 'Price set to ' . inr(2700.0) . ' per seat for this bus. Tickets already sold are unchanged.', 'schedule_id' => $sid, 'extra' => ['fare' => 2700.0]]), $r['body']);
    $au = auditAfter($a5, 'trip.set_price', (string) $sid);
    check('old set_price: audit detail unchanged', $au !== null && (string) $au['detail'] === 'price set to ' . inr(2700.0) . ' per seat by admin #' . $bossId, (string) ($au['detail'] ?? ''));
    $r = $sa(['action' => 'set_price', 'fare' => '0']);
    check('old set_price: 0 still clears there (old meaning kept)', $r['body'] === $exp(['ok' => true, 'action' => 'set_price',
        'message' => 'Price cleared — this bus is back on the normal fare. Tickets already sold keep the amount they were sold at.', 'schedule_id' => $sid, 'extra' => ['fare' => null]]), $r['body']);
    $r = $sa(['action' => 'set_price', 'fare' => '']);
    check('old set_price: "already normal" error unchanged', $r['body'] === $exp(['ok' => false, 'action' => 'set_price', 'error' => 'That trip already runs at the normal fare.']), $r['body']);
    $r = $sa(['action' => 'set_price', 'fare' => '500000']);
    check('old set_price: absurd price error unchanged', $r['body'] === $exp(['ok' => false, 'action' => 'set_price', 'error' => 'That price looks wrong — the maximum is ₹1,00,000 per seat.']), $r['body']);
    $r = http('boss', '/admin/api/schedule-action.php', ['form' => ['action' => 'set_price', 'schedule_id' => $sid, 'fare' => '999']]);
    check('old endpoint: still refuses without CSRF', ($r['json']['ok'] ?? true) === false && str_contains((string) ($r['json']['error'] ?? ''), 'Session expired'));
    $r = http('sup', '/admin/api/schedule-action.php', ['form' => [CSRF_TOKEN_NAME => $sCsrf, 'action' => 'set_price', 'schedule_id' => $sid, 'fare' => '999']]);
    check('old endpoint: still refuses a login without schedules.manage', $r['code'] === 403
        && Database::scalar('SELECT fare_override FROM schedules WHERE id = :i', ['i' => $sid]) === null, 'HTTP ' . $r['code']);
} catch (Throwable $e) {
    $FAIL++;
    echo "  ERROR  " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
}

/* put back what was changed */
if ($keepSeats === null) {
    Database::delete('settings', "skey = 'extra_seats_per_bus'");
} else {
    Database::run("UPDATE settings SET svalue = :v, stype = :t, sgroup = :g, is_public = :p WHERE skey = 'extra_seats_per_bus'",
        ['v' => $keepSeats['svalue'], 't' => $keepSeats['stype'], 'g' => $keepSeats['sgroup'], 'p' => $keepSeats['is_public']]);
}
Settings::flush();
cleanup();
$after = Database::fetch("SELECT svalue FROM settings WHERE skey = 'extra_seats_per_bus'");
check('restored: extra_seats_per_bus as found', ($keepSeats === null && $after === null) || ($keepSeats !== null && $after !== null && $after['svalue'] === $keepSeats['svalue']));
check('restored: no test rows left', (int) Database::scalar('SELECT COUNT(*) FROM schedules WHERE travel_date = :d', ['d' => TD], 0) === 0
    && (int) Database::scalar("SELECT COUNT(*) FROM coupons WHERE code LIKE 'U11Y3%'", [], 0) === 0
    && (int) Database::scalar("SELECT COUNT(*) FROM admins WHERE username LIKE 'u11y3\\_%'", [], 0) === 0);
if ($MADE !== []) {
    $in = implode(',', array_map('intval', $MADE));
    $sess = (int) Database::scalar("SELECT COUNT(*) FROM admin_sessions WHERE admin_id IN ($in)", [], 0);
    $evts = (int) Database::scalar("SELECT COUNT(*) FROM admin_login_events WHERE admin_id IN ($in) OR username LIKE 'u11y3\\_%'", [], 0);
    $auds = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE actor_type = 'admin' AND actor_id IN ($in)", [], 0);
    check('restored: the temporary logins left no sessions, sign-in events or audit rows', $sess === 0 && $evts === 0 && $auds === 0,
        'sessions ' . $sess . ', sign-in events ' . $evts . ', audit ' . $auds . ' for admins #' . $in);
}
foreach ($JARS as $j) { @unlink($j); }
echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
