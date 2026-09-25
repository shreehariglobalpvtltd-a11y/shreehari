/**
 * tools/build.mjs — one JS file and one CSS file for the phone.
 *
 *  Run it on a development machine (or in CI) after touching anything
 *  under assets/js or assets/css, and commit what it writes:
 *
 *      node tools/build.mjs            # build, then print the saving
 *      node tools/build.mjs --check    # verify the committed bundle is
 *                                      # current; exit 1 if it is stale
 *
 *  Why (25 Sep 2026). The app shell loads fourteen classic scripts and two
 *  stylesheets, about 1.6 MB of JavaScript and 456 KB of CSS, as fourteen
 *  plus two separate requests. On a 3G phone at a Gujarat counter that is
 *  the slowest part of the first open. This script concatenates them in
 *  exactly the order app.template.html lists them and minifies the result
 *  with esbuild, which leaves top-level names alone in a classic script —
 *  and that matters here, because these files talk to each other through
 *  globals (t(), CONFIG, toast(), inr() and the rest), so renaming a
 *  top-level function would break the app. Nothing is tree-shaken, nothing
 *  is turned into a module, the execution order is unchanged: the bundle is
 *  the same program with the whitespace and comments taken out.
 *
 *  The order comes from app.template.html itself, never from a list kept
 *  here, so a script added to the template cannot be silently left out of
 *  the bundle. includes/assetbundle.php makes the same comparison at serve
 *  time and refuses to use a bundle that does not match the template.
 *
 *  Two chunks stay separate on purpose: 15-nav.js and 16-lazy.js are
 *  fetched by URL when they are first needed, and terms-data.js on the
 *  terms screen. They are not in the template's head and not in the bundle.
 */

import { readFile, writeFile, mkdir, rm } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const run = promisify(execFile);
const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const DIST = join(ROOT, 'assets/dist');
const CHECK_ONLY = process.argv.includes('--check');

const sha256 = (buf) => createHash('sha256').update(buf).digest('hex');
const kb = (n) => (n / 1024).toFixed(1) + ' KB';

function esbuildBinary() {
    for (const p of ['node_modules/.bin/esbuild', 'node_modules/esbuild/bin/esbuild']) {
        if (existsSync(join(ROOT, p))) {
            return join(ROOT, p);
        }
    }
    return null;
}

/** The ordered asset lists, read out of the template's own head. */
async function assetOrder() {
    const html = await readFile(join(ROOT, 'app.template.html'), 'utf8');
    const head = html.slice(0, html.search(/<\/head>/i));
    const js = [...head.matchAll(/<script[^>]*\bsrc="\/assets\/js\/([^"?]+)(?:\?[^"]*)?"/g)].map((m) => m[1]);
    const css = [...head.matchAll(/<link[^>]*\bhref="\/assets\/css\/([^"?]+)(?:\?[^"]*)?"/g)].map((m) => m[1]);
    if (js.length === 0 || css.length === 0) {
        throw new Error('could not read the script or stylesheet order out of app.template.html');
    }
    return { js, css };
}

async function concat(kind, files) {
    const parts = [];
    const sources = [];
    for (const name of files) {
        const path = join(ROOT, 'assets', kind, name);
        const body = await readFile(path, 'utf8');
        sources.push({ file: `assets/${kind}/${name}`, bytes: Buffer.byteLength(body), sha256: sha256(body) });
        parts.push(`/* ${name} */\n${body}`);
    }
    // A semicolon between files: each file is a complete program, and a
    // stray empty statement is harmless, but a file that ends inside an
    // expression would otherwise swallow the next file's first line.
    const joiner = kind === 'js' ? '\n;\n' : '\n';
    return { text: parts.join(joiner), sources };
}

/**
 * esbuild reads the concatenated source from a temporary file rather than
 * stdin: execFile() cannot write to a child's stdin (it has no `input`
 * option, unlike execFileSync), so piping there waits forever for an EOF
 * that never comes. Caught while writing this file.
 */
async function minify(bin, text, loader) {
    await mkdir(DIST, { recursive: true });
    const inPath = join(DIST, `.build-in.${loader}`);
    const outPath = join(DIST, `.build-out.${loader}`);
    await writeFile(inPath, text);
    const args = [inPath, '--minify', '--charset=utf8', `--outfile=${outPath}`, '--log-level=warning'];
    if (loader === 'js') {
        args.push('--target=es2019');
    }
    try {
        await run(bin, args, { maxBuffer: 64 * 1024 * 1024 });
        return await readFile(outPath, 'utf8');
    } finally {
        await rm(inPath, { force: true });
        await rm(outPath, { force: true });
    }
}

const bin = esbuildBinary();
if (bin === null) {
    console.error('esbuild is not installed. Run:  npm install --no-save esbuild');
    process.exit(CHECK_ONLY ? 0 : 1);   // --check must not fail a machine without esbuild
}

const order = await assetOrder();
const js = await concat('js', order.js);
const css = await concat('css', order.css);

const minJs = await minify(bin, js.text, 'js');
const minCss = await minify(bin, css.text, 'css');

const manifest = {
    note: 'Written by tools/build.mjs. Do not edit by hand. includes/assetbundle.php reads it at serve time.',
    builtFor: 'app.template.html',
    js: {
        output: 'assets/dist/app.min.js',
        bytes: Buffer.byteLength(minJs),
        sha256: sha256(minJs),
        rawBytes: Buffer.byteLength(js.text),
        sources: js.sources,
    },
    css: {
        output: 'assets/dist/app.min.css',
        bytes: Buffer.byteLength(minCss),
        sha256: sha256(minCss),
        rawBytes: Buffer.byteLength(css.text),
        sources: css.sources,
    },
};

if (CHECK_ONLY) {
    const onDisk = existsSync(join(DIST, 'manifest.json'))
        ? JSON.parse(await readFile(join(DIST, 'manifest.json'), 'utf8'))
        : null;
    const same = onDisk !== null
        && JSON.stringify(onDisk.js.sources) === JSON.stringify(manifest.js.sources)
        && JSON.stringify(onDisk.css.sources) === JSON.stringify(manifest.css.sources);
    if (!same) {
        console.error('The committed bundle is stale. Run:  node tools/build.mjs');
        process.exit(1);
    }
    console.log('bundle is current:', manifest.js.sources.length, 'scripts,', manifest.css.sources.length, 'stylesheets');
    process.exit(0);
}

await mkdir(DIST, { recursive: true });
await writeFile(join(DIST, 'app.min.js'), minJs);
await writeFile(join(DIST, 'app.min.css'), minCss);
await writeFile(join(DIST, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n');

console.log('JS :', order.js.length, 'files', kb(manifest.js.rawBytes), '->', kb(manifest.js.bytes),
    `(${Math.round((1 - manifest.js.bytes / manifest.js.rawBytes) * 100)}% smaller, ${order.js.length - 1} fewer requests)`);
console.log('CSS:', order.css.length, 'files', kb(manifest.css.rawBytes), '->', kb(manifest.css.bytes),
    `(${Math.round((1 - manifest.css.bytes / manifest.css.rawBytes) * 100)}% smaller)`);
console.log('Wrote assets/dist/app.min.js, assets/dist/app.min.css, assets/dist/manifest.json');
console.log('Remember: bump ASSET_VER in sw.js and the ?v= stamps, or installed phones keep the old bundle.');
