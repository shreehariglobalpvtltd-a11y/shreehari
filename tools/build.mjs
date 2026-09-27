/**
 * tools/build.mjs — one script and one stylesheet for the phone.
 *
 *  Run it on a development machine after touching anything under assets/js
 *  or assets/css that app.template.html loads in its <head>, and commit
 *  what it writes:
 *
 *      node tools/build.mjs            # build, then print the saving
 *      node tools/build.mjs --check    # is the committed bundle current?
 *                                      # exit 1 if a source moved on
 *
 *  Why (25 Sep 2026, re-done 27 Sep). The app shell loads eighteen classic
 *  scripts and four stylesheets from its head, twenty-two requests before
 *  the first screen. This writes them as ONE script and ONE stylesheet, in
 *  exactly the order the template lists them — the order is read out of
 *  app.template.html here and compared again at serve time by
 *  includes/assetbundle.php, so a script added to the template can never
 *  be silently left out: the page keeps its separate files until the
 *  bundle is rebuilt.
 *
 *  WHAT THIS IS NOT. It is not a minifier. The 25 Sep version leaned on
 *  esbuild; this tree has no node_modules and the rule for it is "vendor
 *  nothing", so:
 *
 *    · JavaScript is CONCATENATED, byte for byte, with a `;` between files.
 *      Comments and whitespace stay. Each file is a complete classic script
 *      that talks to the others through globals (t(), CONFIG, toast(), the
 *      SFX object 19-premium.js takes over in place), and every 'use strict'
 *      in them sits inside its own IIFE, so joining them changes nothing
 *      about how they run. The build refuses a file whose directive
 *      prologue is a top-level 'use strict' — joined, that would either
 *      turn the whole bundle strict or become a no-op, both of which change
 *      behaviour. The win is 18 requests -> 1 and one compression context
 *      instead of eighteen; the byte count before compression is the same.
 *    · CSS gets a conservative pass: comments out, whitespace runs to one
 *      space, no space around { } ; , and the > combinator, no ; before }.
 *      Strings and url(...) are copied untouched, `:` is never touched (a
 *      descendant-plus-pseudo `a :hover` is not `a:hover`), and nothing
 *      near + or - is touched (calc()). The result is then normalised on
 *      both sides — comments and all whitespace removed — and must be
 *      byte-identical to the normalised source, or the build falls back to
 *      plain concatenation for the stylesheet and says so in the manifest.
 *
 *  Want the real 40 % from a minifier? Install esbuild on a dev machine
 *  (never on the VPS) and run it over assets/dist/app.min.js; the manifest
 *  records sha256 + bytes of the OUTPUT too, so re-run this build after and
 *  the test suite (tests/bundle-test.php) will tell you if the two drift.
 *
 *  Two chunks stay separate on purpose: 15-nav.js and 16-lazy.js are
 *  fetched by URL when first needed, journey.css / feature-story.css /
 *  terms-data.js the same. They are not in the template's head and not in
 *  the bundle.
 *
 *  The ?v= stamp is NOT written here. includes/assetbundle.php copies the
 *  stamp off the tags it replaces, so the bundle URL always carries the
 *  release stamp the rest of the tree has. After deploy/bump-asset-ver.js
 *  runs, the sources' embedded stamps change, so run this build again and
 *  commit — tests/bundle-test.php fails until you do.
 */

import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const DIST = join(ROOT, 'assets/dist');
const CHECK_ONLY = process.argv.includes('--check');

const sha256 = (buf) => createHash('sha256').update(buf).digest('hex');
const kb = (n) => (n / 1024).toFixed(1) + ' KB';

/* The same two patterns includes/assetbundle.php uses at serve time. A
   stylesheet is a <link> that says rel="stylesheet" (wherever in the tag)
   and points at /assets/css/; a script is any <script src="/assets/js/…">.
   Both patterns must stay in step with the PHP, or the serve-time guard
   will refuse a bundle this build thought was current. */
const JS_TAG  = /<script[^>]*\bsrc="\/assets\/js\/([^"?]+)(?:\?v=([^"]*))?"[^>]*><\/script>/g;
const CSS_TAG = /<link(?=[^>]*\brel="stylesheet")[^>]*\bhref="\/assets\/css\/([^"?]+)(?:\?v=([^"]*))?"[^>]*>/g;

/** The ordered asset lists, read out of the template's own head. */
async function assetOrder() {
    const html = (await readFile(join(ROOT, 'app.template.html'), 'utf8')).replace(/^﻿/, '');
    const headEnd = html.search(/<\/head>/i);
    const head = headEnd === -1 ? html : html.slice(0, headEnd);
    const js  = [...head.matchAll(JS_TAG)].map((m) => ({ name: m[1], stamp: m[2] || '' }));
    const css = [...head.matchAll(CSS_TAG)].map((m) => ({ name: m[1], stamp: m[2] || '' }));
    if (js.length === 0 || css.length === 0) {
        throw new Error('could not read the script or stylesheet order out of app.template.html');
    }
    const stamps = new Set([...js, ...css].map((t) => t.stamp));
    if (stamps.size !== 1) {
        throw new Error('the head tags carry more than one ?v= stamp: ' + [...stamps].join(', ') + ' — run deploy/bump-asset-ver.js first');
    }
    return { js: js.map((t) => t.name), css: css.map((t) => t.name), stamp: [...stamps][0] };
}

/* A file whose directive prologue is 'use strict' at the TOP LEVEL. The
   prologue is the run of string-literal statements at the very start of the
   program, comments allowed before it; anything else ends it. */
const TOP_LEVEL_STRICT = /^(?:\s|\/\/[^\n]*\n|\/\*[\s\S]*?\*\/)*(?:(['"])[^'"]*\1\s*;?\s*)*?(['"])use strict\2\s*;?/;

async function read(kind, files) {
    const out = [];
    for (const name of files) {
        const buf = await readFile(join(ROOT, 'assets', kind, name));
        out.push({
            file: `assets/${kind}/${name}`,
            bytes: buf.length,
            sha256: sha256(buf),
            text: buf.toString('utf8').replace(/^﻿/, ''),
        });
    }
    return out;
}

/** JavaScript: the same program, joined. */
function joinJs(sources) {
    const parts = [];
    for (const s of sources) {
        if (TOP_LEVEL_STRICT.test(s.text)) {
            throw new Error(`${s.file} has a top-level 'use strict' — joining it would change how the other files run. Wrap it in an IIFE first.`);
        }
        parts.push(`/* ${s.file} */\n${s.text}`);
    }
    // A semicolon between files: each is a complete program, a stray empty
    // statement is harmless, and a file that ends inside an expression can
    // then never swallow the next file's first line.
    return parts.join('\n;\n') + '\n';
}

/**
 * CSS, conservatively. One pass over the characters: strings and url(...)
 * are copied verbatim, comments become one space, whitespace runs become
 * one space. Then the only removals: a space next to { } ; , or >, a ;
 * right before }, and the edges of the file.
 */
function stripCss(css) {
    let out = '';
    const n = css.length;
    let i = 0;
    while (i < n) {
        const c = css[i];
        if (c === '"' || c === "'") {                       // string: verbatim
            let j = i + 1;
            while (j < n && css[j] !== c) { if (css[j] === '\\') j++; j++; }
            out += css.slice(i, Math.min(j + 1, n)); i = j + 1; continue;
        }
        if (c === '/' && css[i + 1] === '*') {              // comment: one space
            const end = css.indexOf('*/', i + 2);
            i = end === -1 ? n : end + 2; out += ' '; continue;
        }
        if ((c === 'u' || c === 'U') && /^url\(/i.test(css.slice(i, i + 4))) {   // url(...): verbatim
            let j = i + 4; let q = '';
            while (j < n) {
                const d = css[j];
                if (q) { if (d === '\\') j++; else if (d === q) q = ''; }
                else if (d === '"' || d === "'") q = d;
                else if (d === '\\') j++;
                else if (d === ')') break;
                j++;
            }
            out += css.slice(i, Math.min(j + 1, n)); i = j + 1; continue;
        }
        if (/\s/.test(c)) {                                 // whitespace run: one space
            while (i < n && /\s/.test(css[i])) i++;
            out += ' '; continue;
        }
        out += c; i++;
    }
    // Removals, again outside strings/url() — walk once more with the same
    // string/url awareness rather than a regex that could reach inside one.
    let res = '';
    i = 0; const m = out.length;
    while (i < m) {
        const c = out[i];
        if (c === '"' || c === "'") {
            let j = i + 1;
            while (j < m && out[j] !== c) { if (out[j] === '\\') j++; j++; }
            res += out.slice(i, Math.min(j + 1, m)); i = j + 1; continue;
        }
        if ((c === 'u' || c === 'U') && /^url\(/i.test(out.slice(i, i + 4))) {
            let j = i + 4; let q = '';
            while (j < m) {
                const d = out[j];
                if (q) { if (d === '\\') j++; else if (d === q) q = ''; }
                else if (d === '"' || d === "'") q = d;
                else if (d === '\\') j++;
                else if (d === ')') break;
                j++;
            }
            res += out.slice(i, Math.min(j + 1, m)); i = j + 1; continue;
        }
        if (c === ' ') {
            const prev = res[res.length - 1] ?? '';
            const next = out[i + 1] ?? '';
            if ('{};,>'.includes(prev) || '{};,>'.includes(next) || next === '' || prev === '') { i++; continue; }
            res += ' '; i++; continue;
        }
        if (c === ';' && (out[i + 1] === '}' || (out[i + 1] === ' ' && out[i + 2] === '}'))) { i++; continue; }
        res += c; i++;
    }
    return res.trim() + '\n';
}

/* Comments and every whitespace character gone, `;}` folded to `}` — applied
   to the source and to the stripped result alike. The two MUST be equal:
   that is the proof the strip removed nothing but comments and whitespace. */
const normaliseCss = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\s+/g, '').replace(/;}/g, '}');

function joinCss(sources) {
    const raw = sources.map((s) => `/* ${s.file} */\n${s.text}`).join('\n') + '\n';
    const stripped = stripCss(raw);
    if (normaliseCss(stripped) === normaliseCss(raw)) {
        return { text: stripped, minifier: 'strip-comments-whitespace' };
    }
    console.error('!! the CSS strip did not round-trip — writing the stylesheets joined as they are');
    return { text: raw, minifier: 'concat' };
}

const order = await assetOrder();
const jsSrc = await read('js', order.js);
const cssSrc = await read('css', order.css);
const js = joinJs(jsSrc);
const css = joinCss(cssSrc);
const strip = (s) => ({ file: s.file, bytes: s.bytes, sha256: s.sha256 });
const sum = (list) => list.reduce((n, s) => n + s.bytes, 0);

const manifest = {
    note: 'Written by tools/build.mjs. Do not edit by hand. includes/assetbundle.php reads it at serve time; tests/bundle-test.php checks it.',
    builtFor: 'app.template.html',
    builtAt: new Date().toISOString(),
    stamp: order.stamp,
    minifier: { js: 'concat', css: css.minifier },
    js: {
        output: 'assets/dist/app.min.js',
        bytes: Buffer.byteLength(js),
        sha256: sha256(js),
        rawBytes: sum(jsSrc),
        sources: jsSrc.map(strip),
    },
    css: {
        output: 'assets/dist/app.min.css',
        bytes: Buffer.byteLength(css.text),
        sha256: sha256(css.text),
        rawBytes: sum(cssSrc),
        sources: cssSrc.map(strip),
    },
};

if (CHECK_ONLY) {
    const mf = join(DIST, 'manifest.json');
    const onDisk = existsSync(mf) ? JSON.parse(await readFile(mf, 'utf8')) : null;
    const why = [];
    if (onDisk === null) {
        why.push('assets/dist/manifest.json is missing');
    } else {
        if (JSON.stringify(onDisk.js.sources) !== JSON.stringify(manifest.js.sources)) why.push('a script changed or the template order changed');
        if (JSON.stringify(onDisk.css.sources) !== JSON.stringify(manifest.css.sources)) why.push('a stylesheet changed or the template order changed');
        if (onDisk.js.sha256 !== manifest.js.sha256) why.push('app.min.js would come out different');
        if (onDisk.css.sha256 !== manifest.css.sha256) why.push('app.min.css would come out different');
        for (const k of ['js', 'css']) {
            const p = join(ROOT, onDisk[k].output);
            if (!existsSync(p) || sha256(await readFile(p)) !== onDisk[k].sha256) why.push(`${onDisk[k].output} on disk is not what the manifest recorded`);
        }
    }
    if (why.length > 0) {
        console.error('The committed bundle is stale:\n  - ' + why.join('\n  - ') + '\nRun:  node tools/build.mjs   and commit assets/dist/');
        process.exit(1);
    }
    console.log('bundle is current:', manifest.js.sources.length, 'scripts,', manifest.css.sources.length, 'stylesheets, stamp', order.stamp);
    process.exit(0);
}

await mkdir(DIST, { recursive: true });
await writeFile(join(DIST, 'app.min.js'), js);
await writeFile(join(DIST, 'app.min.css'), css.text);
await writeFile(join(DIST, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n');

const pct = (a, b) => Math.round((1 - a / b) * 100);
console.log('JS :', order.js.length, 'files', kb(manifest.js.rawBytes), '->', kb(manifest.js.bytes),
    `(joined, not minified: ${order.js.length - 1} fewer requests, ${pct(manifest.js.bytes, manifest.js.rawBytes)}% size change)`);
console.log('CSS:', order.css.length, 'files', kb(manifest.css.rawBytes), '->', kb(manifest.css.bytes),
    `(${css.minifier}: ${pct(manifest.css.bytes, manifest.css.rawBytes)}% smaller, ${order.css.length - 1} fewer requests)`);
console.log('Wrote assets/dist/app.min.js, assets/dist/app.min.css, assets/dist/manifest.json — commit all three.');
console.log('Serving them is behind the bundle_assets_on switch (off = the separate files, exactly as before).');
