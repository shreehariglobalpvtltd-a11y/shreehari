/**
 * motion-check.js — the motion guardrails of the client, checked structurally
 * (27 Sep 2026: the signature opening, one enter motion, stillness at rest,
 * the loading bar).
 *
 * There is no browser on the VPS and no jsdom here, so this reads the CSS,
 * the template and the scripts the way a reviewer would, and refuses what
 * docs/UPGRADE-2026-09-23-ui-ux-v3.md forbids and what the 27 Sep pass
 * promised:
 *
 *   a) No NEW animation or transition of left / top / width / height /
 *      box-shadow / filter / background-position outside a
 *      prefers-reduced-motion:reduce block. Every keyframe is parsed for the
 *      properties it sets, every rule for the keyframes it plays and the
 *      properties it transitions. What the tree already carried is listed in
 *      tests/motion-baseline.json and tolerated; anything not in that list
 *      fails. (`node tests/motion-check.js --write-baseline` rewrites the
 *      list from the tree — only after a human has looked at the diff.)
 *   b) The dead splash motion stays dead: no intro7, splashPortal,
 *      introScenes, splash-road, float-hero-wrap or splashSound in the
 *      template; no armPortal / runScenes / chime in the splash script.
 *   c) The new i18n keys (splashTitle, splashTagline) are defined in en, hi
 *      and ne and referenced by the template or a script.
 *   d) The loading bar exists: html.net-busy::after in premium.css, its
 *      keyframe transform-only, the counter in shgApi._fetch.
 *   e) One enter motion: @keyframes shgEnter is opacity + transform only,
 *      .view.active plays it, #view-seats / #view-checkout keep flowViewIn.
 *   f) Stillness: the chip icons, hero rays and particles, the orange-button
 *      gleam and the fabs are paused unless awake + on screen; the sheens are
 *      switched off at rest; the awake window and the splash event exist.
 *   g) The working tick is the quietest voice (peak <= 0.03) and is gated by
 *      app_load_sound_on; the motion switch is read by shgSwitchOn.
 *
 *   node tests/motion-check.js
 *
 * Exit 0 = every check passed, 1 = at least one failed. The last line reads
 * "N passed, M failed" for tests/run-all.php.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..');
const BASELINE = path.join(__dirname, 'motion-baseline.json');
const WRITE = process.argv.includes('--write-baseline');

const read = (rel) => fs.readFileSync(path.join(ROOT, rel), 'utf8');

let passed = 0, failed = 0;
const pass = (what, detail) => { passed++; console.log(`  PASS  ${what}${detail ? '  —  ' + detail : ''}`); };
const fail = (what, detail) => { failed++; console.log(`  FAIL  ${what}${detail ? '  —  ' + detail : ''}`); };
const check = (ok, what, detail) => (ok ? pass(what, detail) : fail(what, detail));

/* =====================================================================
   A small CSS walker. Enough for this stylesheet: nested @media blocks,
   @keyframes anywhere, ordinary rules. Comments are stripped first.
   ===================================================================== */
function stripComments(css) { return css.replace(/\/\*[\s\S]*?\*\//g, ''); }

/* split on `sep` at paren depth 0 */
function splitTop(s, sep) {
  const out = []; let depth = 0, cur = '';
  for (const ch of s) {
    if (ch === '(') depth++;
    else if (ch === ')') depth--;
    if (ch === sep && depth === 0) { out.push(cur); cur = ''; } else cur += ch;
  }
  if (cur.trim() !== '') out.push(cur);
  return out;
}
/* split on whitespace at paren depth 0 */
function splitWs(s) {
  const out = []; let depth = 0, cur = '';
  for (const ch of s) {
    if (ch === '(') depth++;
    else if (ch === ')') depth--;
    if (/\s/.test(ch) && depth === 0) { if (cur) out.push(cur); cur = ''; } else cur += ch;
  }
  if (cur) out.push(cur);
  return out;
}

function parseCss(css) {
  css = stripComments(css);
  const rules = [];        // { selector, body, media[] }
  const keyframes = {};    // name -> Set(property)
  const stack = [];
  let buf = '';
  for (let i = 0; i < css.length; i++) {
    const c = css[i];
    if (c === '{') {
      const prelude = buf.trim(); buf = '';
      const parent = stack[stack.length - 1];
      const media = parent ? parent.media.slice() : [];
      if (parent && parent.type === 'kf') stack.push({ type: 'kfstep', kf: parent.name, media });
      else if (/^@(media|supports|container|layer)\b/.test(prelude)) stack.push({ type: 'at', media: media.concat([prelude]) });
      else if (/^@(-webkit-)?keyframes\s+/.test(prelude)) {
        const name = prelude.replace(/^@(-webkit-)?keyframes\s+/, '').trim();
        keyframes[name] = keyframes[name] || new Set();
        stack.push({ type: 'kf', name, media });
      } else if (/^@/.test(prelude)) stack.push({ type: 'at-other', media });
      else stack.push({ type: 'rule', selector: prelude.replace(/\s+/g, ' '), media });
    } else if (c === '}') {
      const ctx = stack.pop(); const body = buf.trim(); buf = '';
      if (!ctx) continue;
      if (ctx.type === 'rule') rules.push({ selector: ctx.selector, body, media: ctx.media });
      else if (ctx.type === 'kfstep') {
        for (const decl of splitTop(body, ';')) {
          const idx = decl.indexOf(':');
          if (idx > 0) keyframes[ctx.kf].add(decl.slice(0, idx).trim().toLowerCase());
        }
      }
    } else if (c === ';' && (stack.length === 0 || stack[stack.length - 1].type === 'at')) {
      buf = '';                                   // @import / @charset — no block
    } else buf += c;
  }
  return { rules, keyframes };
}

const TIME = /^-?[\d.]+m?s$/;
const NUMBER = /^-?[\d.]+$/;
const EASING = /^(ease|ease-in|ease-out|ease-in-out|linear|step-start|step-end|cubic-bezier\(.*\)|steps\(.*\)|var\(.*\)|!important)$/;
const ANIM_KEYWORDS = new Set(['infinite', 'normal', 'reverse', 'alternate', 'alternate-reverse', 'none', 'forwards', 'backwards', 'both', 'running', 'paused', 'initial', 'inherit', 'unset']);

function declarations(body) {
  return splitTop(body, ';').map((d) => {
    const idx = d.indexOf(':');
    return idx > 0 ? [d.slice(0, idx).trim().toLowerCase(), d.slice(idx + 1).trim()] : null;
  }).filter(Boolean);
}
function animationNames(body) {
  const names = [];
  for (const [prop, val] of declarations(body)) {
    if (prop === 'animation-name') splitTop(val, ',').forEach((v) => { v = v.trim(); if (v && v !== 'none') names.push(v); });
    else if (prop === 'animation') {
      for (const seg of splitTop(val, ',')) {
        for (const tok of splitWs(seg.trim())) {
          if (TIME.test(tok) || NUMBER.test(tok) || EASING.test(tok) || ANIM_KEYWORDS.has(tok)) continue;
          names.push(tok); break;
        }
      }
    }
  }
  return names;
}
function transitionProps(body) {
  const props = [];
  for (const [prop, val] of declarations(body)) {
    if (prop === 'transition-property') splitTop(val, ',').forEach((v) => props.push(v.trim().toLowerCase()));
    else if (prop === 'transition') {
      for (const seg of splitTop(val, ',')) {
        for (const tok of splitWs(seg.trim())) {
          if (TIME.test(tok) || EASING.test(tok)) continue;
          if (tok !== 'none') props.push(tok.toLowerCase());
          break;
        }
      }
    }
  }
  return props;
}

const BAD = new Set(['left', 'top', 'width', 'height', 'box-shadow', 'filter', 'background-position']);
const reduceBlock = (media) => media.some((m) => /prefers-reduced-motion\s*:\s*reduce/.test(m));

/* ---- every stylesheet, plus the <style> blocks of the template ------- */
const cssFiles = fs.readdirSync(path.join(ROOT, 'assets', 'css')).filter((f) => f.endsWith('.css')).sort()
  .map((f) => ['assets/css/' + f, read('assets/css/' + f)]);
const template = read('app.template.html');
const inlineCss = [...template.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)].map((m) => m[1]).join('\n');
cssFiles.push(['app.template.html<style>', inlineCss]);

const parsed = cssFiles.map(([file, css]) => [file, parseCss(css)]);
const allKeyframes = {};
for (const [, p] of parsed) {
  for (const [name, props] of Object.entries(p.keyframes)) {
    allKeyframes[name] = allKeyframes[name] || new Set();
    props.forEach((x) => allKeyframes[name].add(x));
  }
}

console.log('\n=== motion check ===\n');

/* ---- a) nothing new animates layout or paint -------------------------- */
const offences = new Set();
for (const [file, p] of parsed) {
  for (const r of p.rules) {
    if (reduceBlock(r.media)) continue;
    for (const name of animationNames(r.body)) {
      const props = allKeyframes[name];
      if (!props) continue;                         // a name no stylesheet defines: a browser plays nothing
      for (const prop of props) if (BAD.has(prop)) offences.add(`${file}|${r.selector}|${name}|${prop}`);
    }
    for (const prop of transitionProps(r.body)) if (BAD.has(prop)) offences.add(`${file}|${r.selector}|transition|${prop}`);
  }
}
const found = [...offences].sort();
if (WRITE) {
  fs.writeFileSync(BASELINE, JSON.stringify(found, null, 2) + '\n');
  console.log(`  wrote ${found.length} baseline entries to tests/motion-baseline.json\n`);
}
let baseline = [];
try { baseline = JSON.parse(read('tests/motion-baseline.json')); } catch (e) { fail('tests/motion-baseline.json is readable', e.message); }
const baseSet = new Set(baseline);
const fresh = found.filter((k) => !baseSet.has(k));
const stale = baseline.filter((k) => !offences.has(k));
check(fresh.length === 0, 'no new animation / transition of left, top, width, height, box-shadow, filter or background-position',
  fresh.length ? 'new: ' + fresh.join(' ; ') : `${found.length} known (tests/motion-baseline.json), 0 new`);
if (stale.length) console.log(`  note  ${stale.length} baseline entr${stale.length === 1 ? 'y' : 'ies'} no longer in the tree (safe to prune): ${stale.join(' ; ')}`);

const compositorOnly = (name) => {
  const props = allKeyframes[name];
  if (!props) return `@keyframes ${name} is missing`;
  const extra = [...props].filter((p) => p !== 'opacity' && p !== 'transform');
  return extra.length ? `@keyframes ${name} also sets ${extra.join(', ')}` : '';
};
for (const name of ['shgEnter', 'spLineIn', 'spLineOut', 'netBusy', 'bbHint']) {
  const why = compositorOnly(name);
  check(!why, `@keyframes ${name} is opacity + transform only`, why);
}

/* ---- b) the dead splash motion stays dead ----------------------------- */
const splashStart = template.indexOf('<div class="splash" id="splash">');
const splashEnd = template.indexOf('\n</div>', splashStart);
check(splashStart >= 0 && splashEnd > splashStart, 'the splash markup is found');
const splashHtml = template.slice(splashStart, splashEnd);
for (const dead of ['intro7', 'splashPortal', 'introScenes', 'splash-road', 'float-hero-wrap', 'splashSound', 'intro-scene', 'sp-feats']) {
  check(!template.includes(dead), `no "${dead}" anywhere in app.template.html`);
}
const splashJs = read('assets/js/13-admin-routes.js');
for (const dead of ['armPortal', 'stopPortal', 'runScenes', 'ENABLE_INTRO_TRAILER', 'splashSound', 'this.chime(', '_note(', 'shg:introSeen']) {
  check(!splashJs.includes(dead), `no "${dead}" left in 13-admin-routes.js`);
}
check(/\bminDuration:\s*1400\b/.test(splashJs) && !/this\.minDuration\s*=\s*0\s*;\s*\r?\n\s*if \(this\.el\.classList/.test(splashJs),
  'the splash floor is 1400 ms and is no longer forced to 0');
check(/REPEAT_MS:\s*6 \* 60 \* 60 \* 1000/.test(splashJs) && template.includes("localStorage.getItem('shg:splashAt')") && template.includes('21600000'),
  'the six-hour repeat rule lives in both the script and the inline pre-paint check');
check(/id="splashPromise"[^>]*>\s*<span class="sp-line in">/.test(splashHtml) && !/id="splashPromise"[^>]*data-i18n/.test(splashHtml),
  'the promise line is the three-line rotator seed, without data-i18n');
check(splashJs.includes("['heroTitle', 'bbTag', 'premiumPromise']") && /ROTATE_MS:\s*450/.test(splashJs),
  'the rotator is fed from heroTitle, bbTag and premiumPromise, 450 ms apart');
check(splashJs.includes("dispatchEvent(new CustomEvent('shg:splashdone'))"), 'Splash.finish() announces shg:splashdone');

/* ---- c) the new i18n keys ---------------------------------------------- */
const i18nSrc = read('assets/js/04-i18n.js');
const start = i18nSrc.indexOf('const I18N = {');
const braceStart = i18nSrc.indexOf('{', start);
let depth = 0, end = -1, inStr = null;
for (let i = braceStart; i < i18nSrc.length; i++) {
  const ch = i18nSrc[i], prev = i18nSrc[i - 1];
  if (inStr) { if (ch === inStr && prev !== '\\') inStr = null; continue; }
  if (ch === "'" || ch === '"' || ch === '`') { inStr = ch; continue; }
  if (ch === '{') depth++;
  else if (ch === '}') { depth--; if (depth === 0) { end = i; break; } }
}
let I18N = null;
try { I18N = vm.runInNewContext('(' + i18nSrc.slice(braceStart, end + 1) + ')'); } catch (e) { fail('I18N evaluates', e.message); }
const jsSources = fs.readdirSync(path.join(ROOT, 'assets', 'js')).filter((f) => f.endsWith('.js'))
  .map((f) => read('assets/js/' + f)).join('\n');
for (const key of ['splashTitle', 'splashTagline']) {
  const defined = I18N && ['en', 'hi', 'ne'].every((l) => typeof I18N[l][key] === 'string' && I18N[l][key].trim() !== '');
  check(defined, `${key} is defined in en, hi and ne`);
  const used = template.includes(`data-i18n="${key}"`) || jsSources.includes(`'${key}'`);
  check(used, `${key} is referenced by the template or a script`);
}
check(I18N && /[ऀ-ॿ]/.test(I18N.ne.splashTagline) && /[ऀ-ॿ]/.test(I18N.hi.splashTagline),
  'the Nepali and Hindi taglines are written in Devanagari');

/* ---- d) the loading bar ------------------------------------------------- */
const premium = read('assets/css/premium.css');
const config = read('assets/js/02-config.js');
check(/html\.net-busy::after\s*\{[^}]*animation:\s*netBusy/.test(stripComments(premium)), 'html.net-busy::after draws the loading bar with @keyframes netBusy');
check(config.includes("classList.add('net-busy')") && config.includes("classList.remove('net-busy')") && /_netEnd\(net\)/.test(config) && /finally\s*\{[^}]*_netEnd/.test(config),
  'shgApi._fetch counts requests and clears net-busy in finally');
check(/_quiet\(url\)/.test(config) && config.includes('(seats|track|kv|') && config.includes('const net = shgApi._quiet(url) ? null : shgApi._netStart();') && config.includes('if (net) shgApi._netEnd(net);'),
  'background polls (/seats.php, /track.php, /kv.php) never light the bar or tick');
check(/}, 250\);/.test(config) && /}, 600\);/.test(config) && config.includes("new CustomEvent('shg:netslow')"),
  'the bar waits 250 ms and the slow event 600 ms');

/* ---- e) one enter motion ------------------------------------------------ */
const premiumRules = parseCss(premium).rules;
const viewRule = premiumRules.find((r) => r.selector === '.view.active');
check(!!viewRule && animationNames(viewRule.body).includes('shgEnter'), '.view.active plays shgEnter (premium.css)');
const appCss = read('assets/css/app.css');
check(/#view-seats\.view\.active,\s*#view-checkout\.view\.active\{animation-name:flowViewIn\}/.test(appCss),
  '#view-seats / #view-checkout keep flowViewIn (the fixed action bar stays pinned)');
for (const sel of ['.shg-modal.open', '.mobile-menu.open', '.wa-sheet-card', '.ct-card']) {
  const r = premiumRules.find((x) => x.selector.split(',').map((s) => s.trim()).includes(sel));
  check(!!r && animationNames(r.body).includes('shgEnter'), `${sel} enters with shgEnter`);
}
check(/html\.no-motion \*,html\.no-motion \*::before,html\.no-motion \*::after\{[^}]*animation-duration:1ms!important/.test(stripComments(premium))
  && config.includes("classList.add('no-motion')") && config.includes("shgSwitchOn('app_motion_on')"),
  'app_motion_on = 0 stamps html.no-motion and every entrance becomes instant');

/* ---- f) stillness at rest ---------------------------------------------- */
const paused = premiumRules.filter((r) => /animation-play-state:\s*paused/.test(r.body)).map((r) => r.selector).join(',');
for (const sel of ['.bb-anim .bb-ic', '.hero-fx .hero-ray', '.hero-fx .hero-pt', '.btn-orange::after', '.whatsapp-fab', '.sos-fab.show', '.is-live .vip-curtain']) {
  check(paused.split(',').map((s) => s.trim()).includes(sel), `${sel} is paused at rest`);
}
const parked = premiumRules.filter((r) => /animation-name:\s*none/.test(r.body)).map((r) => r.selector).join(',');
for (const sel of ['.txt-gold', '.hero .grad', '.ra3-bus']) {
  check(parked.split(',').map((s) => s.trim()).includes(sel), `${sel} is switched off and parked at rest (frame 0 would hide it)`);
}
const running = premiumRules.filter((r) => /animation-play-state:\s*running/.test(r.body)).map((r) => r.selector).join(',');
check(/html\.shg-awake .*\.is-live/.test(running), 'loops run only with html.shg-awake AND .is-live');
const vipJs = read('assets/js/22-vip.js');
check(vipJs.includes("'shg-awake'") && vipJs.includes("'shg:splashdone'") && /AWAKE_MS\s*=\s*6000/.test(vipJs),
  '22-vip.js keeps a six-second awake window from the splash and from every gesture');
check(vipJs.includes("'#brandBand .brand-band'") && vipJs.includes("'.hero'"), 'the brand band and the hero are observed for .is-live');
const vipCss = stripComments(read('assets/css/vip.css'));
check(/@keyframes bbBolt\s*\{\s*0%,\s*100%\s*\{\s*opacity:\s*1;\s*transform:\s*scale\(1\)/.test(vipCss) && /@keyframes bbSway\s*\{\s*0%,\s*100%\s*\{\s*transform:\s*rotate\(0\)/.test(vipCss),
  'the chip keyframes rest at frame 0 (a paused icon looks unmoved)');
const filmCss = stripComments(read('assets/css/feature-story.css'));
check(/\.brand-band\.is-live \.bb-feats li > \.bb-open::after\{animation:bbHint/.test(filmCss) && !/bbHint[^;]*infinite/.test(filmCss),
  'the chip play mark appears on scroll-into-view, once, no loop');

/* ---- g) the working tick and the switches ------------------------------ */
const premiumJs = read('assets/js/19-premium.js');
const tick = /VOICES\.working\s*=\s*function\s*\(\)\s*\{\s*voice\([^,]+,[^,]+,[^,]+,\s*([\d.]+)/.exec(premiumJs);
check(!!tick && parseFloat(tick[1]) <= 0.03, 'the working tick peaks at 0.03 or below', tick ? 'peak ' + tick[1] : 'no VOICES.working');
check(premiumJs.includes("shgSwitchOn('app_load_sound_on')") && premiumJs.includes("'shg:netslow'") && /if \(!gestured/.test(premiumJs),
  'the tick is gated by app_load_sound_on, the Sound switch path and the first gesture');
check(/function shgSwitchOn\(key\)/.test(config) && /return true;\s*\r?\n\}/.test(config.slice(config.indexOf('function shgSwitchOn'))),
  'shgSwitchOn() treats an absent row as ON');

/* ---- h) the switches ship OFF, public, idempotent, with help text ------- */
let sql = '';
try { sql = read('database/upgrade-2026-09-27-motion-switches.sql'); } catch (e) { fail('database/upgrade-2026-09-27-motion-switches.sql exists', e.message); }
check(/INSERT IGNORE INTO settings/.test(sql), 'the motion SQL is INSERT IGNORE (safe to re-run)');
for (const key of ['app_motion_on', 'app_load_sound_on']) {
  const row = new RegExp(`\\('${key}',\\s*'0',\\s*'bool',\\s*'site',\\s*1,\\s*\\r?\\n?\\s*'([^']*)'`).exec(sql);
  check(!!row, `${key} ships as bool 0, public, group site`);
  check(!!row && /[ऀ-ॿ]/.test(row[1]) && /[A-Za-z]/.test(row[1]) && !row[1].includes(';'),
    `${key} label is Nepali + English and carries no semicolon (apply-sql splits on them)`);
  check(read('admin/settings.php').includes(`'${key}'`), `${key} has help text on Admin -> Settings`);
}

console.log(`\n${passed} passed, ${failed} failed\n`);
process.exit(failed === 0 ? 0 : 1);
