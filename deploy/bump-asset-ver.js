/* Bump the ?v= asset stamp across the tree, byte-for-byte.
   Git Bash's `sed -i` rewrites CRLF files as LF, which turns a one-line
   change into a whole-file diff; this only touches the stamp.

   Usage: node bump-asset-ver.js <old> <new> [swVersion] [note] */
const fs = require('fs');
const path = require('path');

const [OLD, NEW, SW_VER, NOTE] = process.argv.slice(2);
if (!OLD || !NEW) { console.error('usage: bump-asset-ver.js <old> <new> [swVersion] [note]'); process.exit(1); }

const ROOT = process.cwd();
const EXT = new Set(['.html', '.js', '.php', '.webmanifest', '.css']);
const SKIP = new Set(['.git', 'node_modules', 'assets/generated', 'tmp', 'output']);

const touched = [];
(function walk(dir) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, e.name);
    const rel = path.relative(ROOT, full).replace(/\\/g, '/');
    if (SKIP.has(e.name) || SKIP.has(rel)) continue;
    if (e.isDirectory()) { walk(full); continue; }
    if (!EXT.has(path.extname(e.name).toLowerCase())) continue;
    const buf = fs.readFileSync(full);
    const txt = buf.toString('binary');
    if (!txt.includes(OLD)) continue;
    const n = txt.split(OLD).length - 1;
    fs.writeFileSync(full, Buffer.from(txt.split(OLD).join(NEW), 'binary'));
    touched.push(rel + ' (' + n + ')');
  }
})(ROOT);

if (SW_VER) {
  const p = path.join(ROOT, 'sw.js');
  const txt = fs.readFileSync(p).toString('binary');
  const m = txt.match(/var VERSION = '([^']+)';   \/\/ /);
  if (!m) { console.error('sw.js VERSION line not found'); process.exit(1); }
  const head = "var VERSION = '" + SW_VER + "';   // " + (NOTE || '') +
    '   // ' + m[1] + ' and earlier: ';
  const at = txt.indexOf(m[0]);
  fs.writeFileSync(p, Buffer.from(txt.slice(0, at) + head + txt.slice(at + m[0].length), 'binary'));
  console.log('sw VERSION ' + m[1] + ' -> ' + SW_VER);
}

console.log('stamped ' + OLD + ' -> ' + NEW + ' in ' + touched.length + ' file(s):');
touched.forEach(t => console.log('  ' + t));
